<?php
session_start();
include 'config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

function updateCartWithinPrescriptionLimit(mysqli $conn, int $userId, int $productId, int $pieces, int $boxes): bool {
    if (!$conn->begin_transaction()) {
        error_log('Unable to start cart quantity validation transaction: ' . $conn->error);
        return false;
    }
    $cartState = $conn->prepare(
        'SELECT c.pieces, c.box, p.prescription_required, p.category, p.pieces_per_box
         FROM cart c JOIN products p ON p.id = c.product_id
         WHERE c.user_id = ? AND c.product_id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$cartState) {
        $conn->rollback();
        error_log('Cart quantity validation prepare failed: ' . $conn->error);
        return false;
    }
    $cartState->bind_param('ii', $userId, $productId);
    if (!$cartState->execute()) {
        error_log('Cart quantity validation failed: ' . $cartState->error);
        $cartState->close();
        $conn->rollback();
        return false;
    }
    $current = $cartState->get_result()->fetch_assoc();
    $cartState->close();
    if (!$current) {
        $conn->rollback();
        return false;
    }

    $piecesPerBox = max(1, (int)($current['pieces_per_box'] ?? 12));
    $currentTotal = (int)$current['pieces'] + ((int)$current['box'] * $piecesPerBox);
    $newTotal = max(0, $pieces) + (max(0, $boxes) * $piecesPerBox);
    $isPrescription = !empty($current['prescription_required'])
        || strtolower(trim((string)($current['category'] ?? ''))) === 'prescription medicine';

    if ($isPrescription && $newTotal > $currentTotal) {
        $accessStmt = $conn->prepare(
            'SELECT GREATEST(quantity_limit - quantity_purchased, 0) AS remaining
             FROM prescription_product_access
             WHERE product_id = ? AND user_id = ?
               AND (expires_at IS NULL OR expires_at >= NOW())
             ORDER BY granted_at DESC LIMIT 1 FOR UPDATE'
        );
        if (!$accessStmt) {
            $conn->rollback();
            error_log('Cart prescription-limit query prepare failed: ' . $conn->error);
            return false;
        }
        $accessStmt->bind_param('ii', $productId, $userId);
        if (!$accessStmt->execute()) {
            error_log('Cart prescription-limit query failed: ' . $accessStmt->error);
            $accessStmt->close();
            $conn->rollback();
            return false;
        }
        $access = $accessStmt->get_result()->fetch_assoc();
        $accessStmt->close();
        if (!$access || $newTotal > (int)$access['remaining']) {
            $conn->rollback();
            return false;
        }
    }

    $update = $conn->prepare('UPDATE cart SET pieces = ?, box = ? WHERE user_id = ? AND product_id = ?');
    if (!$update) {
        $conn->rollback();
        error_log('Cart quantity update prepare failed: ' . $conn->error);
        return false;
    }
    $update->bind_param('iiii', $pieces, $boxes, $userId, $productId);
    $updated = $update->execute();
    if (!$updated) {
        error_log('Cart quantity update failed: ' . $update->error);
    }
    $update->close();
    if (!$updated || !$conn->commit()) {
        if (!$updated) {
            $conn->rollback();
        } else {
            error_log('Cart quantity update commit failed: ' . $conn->error);
            $conn->rollback();
        }
        return false;
    }
    return true;
}

// --- CHANGE UNIT (PIECE OR BOTTLE) ---
if (isset($_POST['change_unit'])) {
    $product_id = (int) $_POST['product_id'];
    $unit = $_POST['change_unit'];
    
    // Get current cart item
    $stmt = $conn->prepare("SELECT cart.pieces, cart.box, products.pieces_per_box, products.quantity,
                                   (SELECT COUNT(*) FROM product_batches WHERE product_id = products.id) AS batch_count,
                                   (SELECT COALESCE(SUM(CASE
                                       WHEN recalled = 0 AND expiration_date >= CURDATE() THEN current_quantity
                                       ELSE 0
                                   END), 0) FROM product_batches WHERE product_id = products.id) AS sellable_batch_stock
                            FROM cart JOIN products ON cart.product_id = products.id
                            WHERE cart.user_id = ? AND cart.product_id = ? LIMIT 1");
    if (!$stmt) {
        error_log('Cart unit conversion query prepare failed: ' . $conn->error);
        $_SESSION['cart_prescription_warning'] = 'Unable to verify available stock. Please try again.';
        header('Location: cart_screen.php');
        exit();
    }
    $stmt->bind_param("ii", $user_id, $product_id);
    if (!$stmt->execute()) {
        error_log('Cart unit conversion query failed: ' . $stmt->error);
        $stmt->close();
        $_SESSION['cart_prescription_warning'] = 'Unable to verify available stock. Please try again.';
        header('Location: cart_screen.php');
        exit();
    }
    $result = $stmt->get_result();
    $cart_item = $result->fetch_assoc();
    $stmt->close();
    
    if ($cart_item) {
        $pieces_per_box = max(1, (int) ($cart_item['pieces_per_box'] ?? 1));
        $current_qty = $cart_item['pieces'] + ($cart_item['box'] * $pieces_per_box);
        
        if ($unit == 'bottle') {
            // Convert to bottles using the product's package size.
            $boxes = max(1, (int) ceil($current_qty / $pieces_per_box));
            $pieces = 0;
            $availableStock = max(0, (int)($cart_item['quantity'] ?? 0));
            if ((int)($cart_item['batch_count'] ?? 0) > 0) {
                $availableStock = min($availableStock, max(0, (int)($cart_item['sellable_batch_stock'] ?? 0)));
            }
            if ($boxes * $pieces_per_box > $availableStock) {
                $_SESSION['cart_prescription_warning'] = 'Hindi sapat ang available na stock para sa isang buong kahon. Pumili ng Per Piece o bawasan ang quantity.';
                header('Location: cart_screen.php');
                exit();
            }
        } else {
            // Convert to pieces
            $boxes = 0;
            $pieces = $current_qty;
        }
        
        if (!updateCartWithinPrescriptionLimit($conn, (int)$user_id, $product_id, $pieces, $boxes)) {
            $_SESSION['cart_prescription_warning'] = 'The quantity cannot exceed the limit set by the pharmacy for this prescription.';
        }
    }
    
    header("Location: cart_screen.php");
    exit();
}

// --- PRESERVED LOGIC ---
if (isset($_POST['update_pieces'])) {
    $product_id = (int) $_POST['product_id'];
    $pieces = (int) $_POST['pieces'];
    if ($pieces < 0) $pieces = 0;
    $stmt = $conn->prepare('SELECT box FROM cart WHERE user_id = ? AND product_id = ? LIMIT 1');
    $stmt->bind_param('ii', $user_id, $product_id);
    $stmt->execute();
    $currentBox = (int)($stmt->get_result()->fetch_assoc()['box'] ?? 0);
    $stmt->close();
    if (!updateCartWithinPrescriptionLimit($conn, (int)$user_id, $product_id, $pieces, $currentBox)) {
        $_SESSION['cart_prescription_warning'] = 'The quantity cannot exceed the limit set by the pharmacy for this prescription.';
    }
    header("Location: cart_screen.php");
    exit();
}

if (isset($_POST['update_box'])) {
    $product_id = (int) $_POST['product_id'];
    $box = (int) $_POST['box'];
    if ($box < 0) $box = 0;
    $stmt = $conn->prepare('SELECT pieces FROM cart WHERE user_id = ? AND product_id = ? LIMIT 1');
    $stmt->bind_param('ii', $user_id, $product_id);
    $stmt->execute();
    $currentPieces = (int)($stmt->get_result()->fetch_assoc()['pieces'] ?? 0);
    $stmt->close();
    if (!updateCartWithinPrescriptionLimit($conn, (int)$user_id, $product_id, $currentPieces, $box)) {
        $_SESSION['cart_prescription_warning'] = 'The quantity cannot exceed the limit set by the pharmacy for this prescription.';
    }
    header("Location: cart_screen.php");
    exit();
}

$cartPrescriptionWarning = (string)($_SESSION['cart_prescription_warning'] ?? '');
unset($_SESSION['cart_prescription_warning']);
$checkoutSelectionError = (string)($_SESSION['checkout_error'] ?? '');
unset($_SESSION['checkout_error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Cart</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: Arial, 'Segoe UI', sans-serif;
            background: #f8f9fb; 
            display: flex;
        }

        .main-content {
            margin-left: 260px;
            padding: 40px;
            width: calc(100% - 260px);
            display: flex;
            gap: 24px;
        }

        .cart-container { flex: 1; min-width: 0; }

        /* HEADER */
        .cart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .cart-header h2 { font-size: 35px; font-weight: 700; color: #1a1a1a; }
        
        /* MAIN CART CARD */
        .cart-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #edf0f5;
        }

        .cart-table-header {
            display: grid;
            grid-template-columns: 38px minmax(220px, 2.2fr) minmax(94px, .85fr) minmax(112px, 1fr) minmax(80px, .8fr);
            padding: 15px 14px;
            background: #fafbfc;
            border-bottom: 1px solid #edf0f5;
            color: #7c8ca3;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }
        .text-left { text-align: left; }

        .cart-item-row {
            display: grid;
            grid-template-columns: 38px minmax(220px, 2.2fr) minmax(94px, .85fr) minmax(112px, 1fr) minmax(80px, .8fr);
            padding: 18px 14px;
            align-items: center;
            border-bottom: 1px solid #f4f6f9;
            text-align: center;
        }

        .product-col { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .product-image-link { display: block; flex: 0 0 60px; line-height: 0; }
        .product-img { width: 60px; height: 60px; flex: 0 0 60px; object-fit: contain; background: #f9f9f9; border-radius: 8px; }
        .product-info { flex: 1 1 auto; min-width: 0; }
        .product-info .name { display: block; font-weight: 700; color: #1a1a1a; font-size: 14px; line-height: 1.3; margin-bottom: 6px; overflow-wrap: break-word; word-break: normal; }
        .product-info .unit-select { 
            background: #fff; border: 1px solid #e0e5ec; padding: 4px 10px; 
            border-radius: 6px; font-size: 12px; color: #4b5563; outline: none;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            max-width: 200px;
        }
        .product-info .unit-select:hover {
            border-color: #8B1E2D;
            color: #8B1E2D;
        }

        /* PRICE STYLING */
        .price-col { font-weight: 700; color: #8B1E2D; font-size: 15px; }
        .price-col .price-subtext { display: block; color: #8B1E2D; font-size: 12px; font-weight: 500; margin-top: 4px; }

        /* DOUBLE QUANTITY CONTROLS */
        .qty-container { display: flex; flex-direction: column; gap: 10px; align-items: center; }
        .qty-row { display: flex; align-items: center; gap: 8px; }
        
        .qty-box { 
            display: flex; align-items: center; border: 1px solid #e0e5ec; 
            border-radius: 6px; background: #fff; overflow: hidden;
        }
        .qty-btn { 
            width: 28px; height: 28px; border: none; background: #fafbfc; 
            cursor: pointer; font-size: 16px; color: #8B1E2D; font-weight: bold;
        }
        .qty-btn:hover { background: #f0f0f0; }
        .qty-btn:disabled { background:#f1f3f5; color:#aab2bd; cursor:not-allowed; opacity:.65; }
        .qty-btn:disabled:hover { background:#f1f3f5; }
        .qty-val { width: 35px; text-align: center; font-weight: 700; font-size: 14px; border-left: 1px solid #e0e5ec; border-right: 1px solid #e0e5ec; }

        .total-col { font-weight: 700; color: #8B1E2D; font-size: 16px; }
        .price-col, .qty-container, .total-col { min-width: 0; }
        .item-checkbox.selection-checkbox-hidden { visibility: hidden; }
        .cart-selection-actions { display: flex; align-items: center; gap: 8px; }
        .cart-selection-actions[hidden] { display: none; }
        .clear-cart-btn {
            border: 1px solid #8B1E2D;
            background: white;
            color: #8B1E2D;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        .clear-cart-btn:disabled { opacity: .5; cursor: not-allowed; }
        .cancel-selection-btn {
            border: 0;
            background: transparent;
            color: #64748b;
            padding: 8px;
            font-size: 13px;
            cursor: pointer;
        }

        /* SELECT ALL SECTION (INSIDE CARD BOTTOM) */
        .cart-footer {
            padding: 15px 25px;
            display: flex;
            align-items: center;
            border-top: 1px solid #f4f6f9;
        }
        .select-all-label { display: flex; align-items: center; gap: 10px; color: #1a1a1a; font-weight: 600; font-size: 14px; cursor: pointer; }
        .continue-link { margin-left: auto; color: #8B1E2D; font-size: 14px; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 5px; }

        /* SUMMARY SIDEBAR */
        .summary-card {
            flex: 0 0 380px;
            width: 380px;
            background: white;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #edf0f5;
            height: fit-content;
            position: sticky;
            top: 40px;
        }
        .summary-card h3 { margin-bottom: 20px; font-size: 18px; color: #1a1a1a; }
        .summary-line { display: flex; justify-content: space-between; margin-bottom: 15px; color: #4b5563; font-size: 14px; }
        .summary-line.total { 
            margin-top: 20px; padding-top: 20px; border-top: 2px solid #f8f9fb;
            font-weight: 700; color: #1a1a1a; font-size: 16px;
        }
        .summary-line.total .price { color: #8B1E2D; font-size: 26px; }

        .btn-checkout {
            width: 100%; background: #8B1E2D; color: white; border: none;
            padding: 16px; border-radius: 10px; font-weight: 700; margin-top: 20px;
            display: flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer;
            box-shadow: 0 4px 10px rgba(211, 47, 47, 0.2);
        }
        .btn-checkout:disabled { opacity: .55; cursor: not-allowed; box-shadow: none; }
        .btn-orders {
            width: 100%; background: white; color: #8B1E2D; border: 1px solid #8B1E2D;
            padding: 12px; border-radius: 10px; font-weight: 700; margin-top: 12px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }

        .badge-list { margin-top: 25px; display: flex; flex-direction: column; gap: 12px; border-top: 1px solid #eee; padding-top: 20px; }
        .badge-item { display: flex; align-items: center; gap: 12px; font-size: 13px; color: #64748b; font-weight: 500; }
        .badge-item i { font-size: 20px; color: #94a3b8; }

        /* EMPTY CART STYLE */
        .empty-cart { text-align: center; padding: 60px 20px; width: 100%; background: white; border-radius: 12px; border: 1px solid #edf0f5; }
        .empty-cart img { width: 150px; opacity: 0.6; margin-bottom: 20px; }
        .empty-cart h3 { font-size: 20px; color: #1a1a1a; margin-bottom: 8px; }
        .empty-cart p { color: #7c8ca3; margin-bottom: 24px; }
        .btn-continue-empty { background: #f0f4f8; color: #4a5568; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }

        @media (max-width: 1100px) {
            .cart-page .main-content {
                flex-direction: column;
                gap: 20px;
            }

            .cart-page .summary-card {
                position: static;
                flex-basis: auto;
                width: 100%;
            }
        }

        @media (max-width: 900px) {
            .cart-page {
                overflow-x: hidden;
            }

            .cart-page .main-content {
                box-sizing: border-box !important;
                margin-left: 84px !important;
                width: calc(100% - 84px) !important;
                padding: 18px 14px !important;
            }

            .cart-header {
                align-items: flex-start;
                gap: 12px;
                flex-wrap: wrap;
                margin-bottom: 18px;
            }

            .cart-header h2 {
                font-size: 26px;
            }

            .clear-cart-btn {
                padding: 8px 11px;
                font-size: 12px;
            }

            .cart-table-header {
                display: none;
            }

            .cart-item-row {
                grid-template-columns: 24px minmax(0, 1fr);
                grid-template-areas:
                    "check product"
                    ". price"
                    ". quantity"
                    ". total";
                gap: 12px 8px;
                padding: 16px 12px;
                text-align: left;
            }

            .cart-item-row > .item-checkbox {
                grid-area: check;
                margin-top: 4px;
            }

            .cart-item-row > .product-col {
                grid-area: product;
                min-width: 0;
                gap: 10px;
            }

            .product-img {
                width: 54px;
                height: 54px;
                flex: 0 0 54px;
            }

            .product-image-link {
                flex-basis: 54px;
            }

            .product-info {
                min-width: 0;
            }

            .product-info .name {
                overflow-wrap: anywhere;
            }

            .cart-item-row > .price-col {
                grid-area: price;
                text-align: left;
            }

            .cart-item-row > .price-col::before,
            .cart-item-row > .qty-container::before,
            .cart-item-row > .total-col::before {
                display: block;
                margin-bottom: 5px;
                color: #7c8ca3;
                font-size: 11px;
                font-weight: 600;
            }

            .cart-item-row > .price-col::before { content: "Unit Price"; }

            .cart-item-row > .qty-container {
                grid-area: quantity;
                align-items: flex-start;
            }

            .cart-item-row > .qty-container::before { content: "Quantity"; }

            .cart-item-row > .total-col {
                grid-area: total;
                text-align: left;
            }

            .cart-item-row > .total-col::before { content: "Total"; }

            .cart-footer {
                align-items: flex-start;
                flex-wrap: wrap;
                gap: 12px;
                padding: 14px 12px;
            }

            .continue-link {
                margin-left: 0;
            }

            .cart-selection-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .summary-card {
                padding: 18px;
            }

            .summary-line.total .price {
                font-size: 22px;
            }

            .empty-cart {
                padding: 40px 14px;
            }

            .empty-cart img {
                width: 120px;
            }
        }

        @media (max-width: 420px) {
            .cart-page .main-content {
                padding: 16px 10px !important;
            }

            .cart-header h2 {
                font-size: 23px;
            }

            .cart-item-row {
                padding: 14px 10px;
            }

            .summary-card {
                padding: 15px;
            }
        }
    </style>
</head>
<body class="cart-page">

    <?php include 'sidebar_user.php'; ?>

    <div class="main-content">
        <div class="cart-container">
            <div class="cart-header">
                <?php
                $count_q = $conn->query("SELECT COUNT(*) as total FROM cart WHERE user_id = $user_id");
                $item_count = $count_q->fetch_assoc()['total'] ?? 0;
                ?>
                <h2>My Cart (<?php echo $item_count; ?>)</h2>
                <?php if ($item_count > 0): ?>
                <div class="cart-selection-actions" id="cart-selection-actions" hidden>
                    <form id="clear-cart-form" action="clear_cart.php" method="POST">
                        <button type="submit" class="clear-cart-btn" id="delete-selected-button" disabled>
                            <i class="material-icons-outlined">delete_outline</i> Delete Selected
                        </button>
                    </form>
                    <button type="button" class="cancel-selection-btn" id="cancel-selection-button">Cancel</button>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($checkoutSelectionError !== ''): ?>
                <div role="alert" style="margin-bottom:12px;padding:10px 12px;border:1px solid #f3c7cf;border-radius:7px;background:#fff0f2;color:#b4233d;font-size:13px;">
                    <?php echo htmlspecialchars($checkoutSelectionError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($item_count == 0): ?>
                <div class="empty-cart">
                    <img src="https://cdn-icons-png.flaticon.com/512/11329/11329061.png" alt="Empty Cart">
                    <h3>Your cart is empty</h3>
                    <p>Add some products to get started!</p>
                    <a href="product_screen.php" class="btn-continue-empty">
                        <i class="material-icons-outlined" style="font-size: 18px;">shopping_bag</i> Continue Shopping
                    </a>
                </div>
            <?php else: ?>
                <?php if ($cartPrescriptionWarning !== ''): ?><div role="alert" style="margin-bottom:12px;padding:10px 12px;border:1px solid #f3c7cf;border-radius:7px;background:#fff0f2;color:#b4233d;font-size:13px;"><?php echo htmlspecialchars($cartPrescriptionWarning, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                <div class="cart-card">
                    <div class="cart-table-header">
                        <div></div>
                        <div class="text-left">Product</div>
                        <div>Unit Price</div>
                        <div>Quantity</div>
                        <div>Total</div>
                    </div>

                    <?php
                    $total_sum = 0;
                    $query = "SELECT products.*, cart.pieces, cart.box FROM cart 
                              JOIN products ON cart.product_id = products.id 
                              WHERE cart.user_id = $user_id ORDER BY cart.id DESC";
                    $result = $conn->query($query);
                    $batchStockStmt = $conn->prepare(
                        'SELECT COUNT(*) AS batch_count,
                                COALESCE(SUM(CASE
                                    WHEN recalled = 0 AND expiration_date >= CURDATE() THEN current_quantity
                                    ELSE 0
                                END), 0) AS sellable_stock
                         FROM product_batches WHERE product_id = ?'
                    );
                    if (!$batchStockStmt) {
                        error_log('Cart batch stock query preparation failed: ' . $conn->error);
                    }

                    while ($p = $result->fetch_assoc()) {
                        $p_price = (float)($p['price_piece'] ?? $p['price'] ?? 0);
                        $pieces_per_box = max(1, (int)($p['pieces_per_box'] ?? 1));
                        $cartQuantity = (int)$p['pieces'] + ((int)$p['box'] * $pieces_per_box);
                        $cartProductId = (int)$p['id'];
                        $isPrescriptionCartItem = !empty($p['prescription_required'])
                            || strtolower(trim((string)($p['category'] ?? ''))) === 'prescription medicine';
                        $prescriptionCartRemaining = null;
                        if ($isPrescriptionCartItem) {
                            $accessLimitStmt = $conn->prepare(
                                'SELECT GREATEST(quantity_limit - quantity_purchased, 0) AS remaining
                                 FROM prescription_product_access
                                 WHERE product_id = ? AND user_id = ?
                                   AND (expires_at IS NULL OR expires_at >= NOW())
                                 ORDER BY granted_at DESC LIMIT 1'
                            );
                            if ($accessLimitStmt) {
                                $accessLimitStmt->bind_param('ii', $cartProductId, $user_id);
                                if ($accessLimitStmt->execute()) {
                                    $accessLimit = $accessLimitStmt->get_result()->fetch_assoc();
                                    if ($accessLimit) {
                                        $prescriptionCartRemaining = (int)$accessLimit['remaining'];
                                    }
                                } else {
                                    error_log('Cart prescription limit display query failed: ' . $accessLimitStmt->error);
                                }
                                $accessLimitStmt->close();
                            } else {
                                error_log('Cart prescription limit display prepare failed: ' . $conn->error);
                            }
                        }
                        $pieceIncrementAllowed = !$isPrescriptionCartItem
                            || ($prescriptionCartRemaining !== null && $cartQuantity + 1 <= $prescriptionCartRemaining);
                        $boxIncrementAllowed = !$isPrescriptionCartItem
                            || ($prescriptionCartRemaining !== null && $cartQuantity + $pieces_per_box <= $prescriptionCartRemaining);
                        $boxConversionAllowed = !$isPrescriptionCartItem
                            || (int)$p['box'] > 0
                            || ($prescriptionCartRemaining !== null
                                && (int)(ceil($cartQuantity / $pieces_per_box) * $pieces_per_box) <= $prescriptionCartRemaining);
                        $boxStockConversionAllowed = false;
                        if ($batchStockStmt) {
                            $batchStockStmt->bind_param('i', $cartProductId);
                            if ($batchStockStmt->execute()) {
                                $batchStock = $batchStockStmt->get_result()->fetch_assoc();
                                $sellableStock = max(0, (int)($p['quantity'] ?? 0));
                                if ((int)($batchStock['batch_count'] ?? 0) > 0) {
                                    $sellableStock = min($sellableStock, max(0, (int)($batchStock['sellable_stock'] ?? 0)));
                                }
                                $requiredBoxStock = (int)(ceil($cartQuantity / $pieces_per_box) * $pieces_per_box);
                                $boxStockConversionAllowed = (int)$p['box'] > 0 || $requiredBoxStock <= $sellableStock;
                            } else {
                                error_log('Cart batch stock query failed: ' . $batchStockStmt->error);
                            }
                        }
                        $box_price = round($p_price * $pieces_per_box * 0.98, 2);
                        $sub = ($p['pieces'] * $p_price) + ($p['box'] * $box_price);
                        $total_sum += $sub;
                        $img = getProductImageUrl($p['image'] ?? '');
                    ?>
                    <div class="cart-item-row">
                        <input type="checkbox" class="item-checkbox selection-checkbox-hidden" form="cart-checkout-form" name="product_ids[]" value="<?php echo $cartProductId; ?>" data-subtotal="<?php echo htmlspecialchars((string)$sub, ENT_QUOTES, 'UTF-8'); ?>" style="accent-color: #8B1E2D; width: 18px; height: 18px;" checked>
                        <div class="product-col">
                            <a class="product-image-link" href="view_product.php?id=<?php echo $cartProductId; ?>" aria-label="View <?php echo htmlspecialchars((string)$p['product_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <img src="<?php echo htmlspecialchars($img, ENT_QUOTES, 'UTF-8'); ?>" class="product-img" alt="<?php echo htmlspecialchars((string)$p['product_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            </a>
                            <div class="product-info text-left">
                                <span class="name"><?php echo $p['product_name']; ?></span>
                                <select class="unit-select" id="unit-select-<?php echo $p['id']; ?>" onchange="updateUnitDisplay(<?php echo $p['id']; ?>, this.value, <?php echo $p_price; ?>, <?php echo $box_price; ?>)">
                                    <option value="piece" <?php echo ($p['box'] == 0 ? 'selected' : ''); ?>>Per Piece</option>
                                    <option value="bottle" <?php echo ($p['box'] > 0 ? 'selected' : ''); ?> <?php echo (!$boxConversionAllowed || !$boxStockConversionAllowed) ? 'disabled title="Not enough sellable stock or prescription quantity for a full box"' : ''; ?>>Per Box (<?php echo $pieces_per_box; ?> pcs, 2% off)</option>
                                </select>
                            </div>
                        </div>
                        <div class="price-col" id="price-display-<?php echo $p['id']; ?>">
                            <?php 
                                $display_price = ($p['box'] > 0) ? $box_price : $p_price;
                                $display_unit = ($p['box'] > 0) ? 'box' : 'piece';
                            ?>
                            <span id="price-value-<?php echo $p['id']; ?>">₱<?php echo number_format($display_price, 2); ?></span>
                            <span class="price-subtext" id="price-unit-<?php echo $p['id']; ?>">/ <?php echo $display_unit; ?></span>
                        </div>
                        
                        <div class="qty-container" id="qty-section-<?php echo $p['id']; ?>">
                            <div class="qty-row">
                                <div class="qty-box" id="qty-controls-<?php echo $p['id']; ?>" style="<?php echo ($p['box'] == 0 ? 'display:flex' : 'display:none'); ?>">
                                    <?php if ($p['box'] == 0): ?>
                                    <form method="POST" style="display:flex">
                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="pieces" value="<?php echo $p['pieces'] - 1; ?>">
                                        <button name="update_pieces" class="qty-btn" <?php echo ($p['pieces'] <= 0 ? 'disabled' : ''); ?>>−</button>
                                    </form>
                                    <span class="qty-val" id="qty-value-<?php echo $p['id']; ?>"><?php echo $p['pieces']; ?></span>
                                    <form method="POST" style="display:flex">
                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="pieces" value="<?php echo $p['pieces'] + 1; ?>">
                                        <button name="update_pieces" class="qty-btn" <?php echo !$pieceIncrementAllowed ? 'disabled title="Pharmacy-set prescription quantity limit reached"' : ''; ?>>+</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <div class="qty-box" id="qty-box-controls-<?php echo $p['id']; ?>" style="<?php echo ($p['box'] > 0 ? 'display:flex' : 'display:none'); ?>">
                                    <?php if ($p['box'] > 0): ?>
                                    <form method="POST" style="display:flex">
                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="box" value="<?php echo $p['box'] - 1; ?>">
                                        <button name="update_box" class="qty-btn" <?php echo ($p['box'] <= 0 ? 'disabled' : ''); ?>>−</button>
                                    </form>
                                    <span class="qty-val" id="qty-box-value-<?php echo $p['id']; ?>"><?php echo $p['box']; ?></span>
                                    <form method="POST" style="display:flex">
                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="box" value="<?php echo $p['box'] + 1; ?>">
                                        <button name="update_box" class="qty-btn" <?php echo !$boxIncrementAllowed ? 'disabled title="Pharmacy-set prescription quantity limit reached"' : ''; ?>>+</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="total-col" id="total-<?php echo $p['id']; ?>">₱<?php echo number_format($sub, 2); ?></div>
                    </div>
                    <?php } ?>
                    <?php if ($batchStockStmt) $batchStockStmt->close(); ?>

                    <div class="cart-footer">
                        <label class="select-all-label">
                            <input type="checkbox" id="select-all" style="accent-color: #8B1E2D; width: 18px; height: 18px;">
                            Select All (<?php echo $item_count; ?>)
                        </label>
                        <a href="product_screen.php" class="continue-link">
                            <i class="material-icons-outlined" style="font-size: 16px;">arrow_back</i> Continue Shopping
                        </a>
                    </div>
                </div>

                <div style="background: #fff5f5; border: 1px solid #ffebeb; padding: 15px; border-radius: 10px; margin-top: 20px; display: flex; align-items: center; gap: 15px;">
                        <i class="material-icons-outlined" style="color: #8B1E2D; font-size: 32px;">inventory_2</i>
                    <div>
                        <strong style="color: #8B1E2D; display: block; font-size: 14px;">Per Box</strong>
                        <span style="color: #7c8ca3; font-size: 12px;">Prices are based on selected unit. You can change the unit per product.</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($item_count > 0): ?>
        <div class="summary-card">
            <h3>Order Summary</h3>
            <div class="summary-line">
                <span>Subtotal</span>
                <span id="selected-subtotal" style="font-weight: 700;">₱0.00</span>
            </div>
            <div class="summary-line total">
                <span>Total Amount</span>
                <span class="price" id="selected-total">₱0.00</span>
            </div>

            <form id="cart-checkout-form" method="POST" action="receipt.php">
                <button class="btn-checkout" id="checkout-selected-button" type="submit">
                    <i class="material-icons-outlined">bolt</i> Proceed to Checkout
                </button>
                <p id="checkout-selection-hint" role="status" style="margin-top:8px;color:#64748b;font-size:12px;text-align:center;">All cart products are selected. Uncheck any you don't want.</p>
            </form>
            <button class="btn-orders" onclick="location.href='my_orders.php'">
                <i class="material-icons-outlined">inventory_2</i> Go to Orders
            </button>

        </div>
        <?php endif; ?>
    </div>
    <script>
        // --- SELECT ALL CHECKBOX LOGIC ---
        const selectAll = document.getElementById('select-all');
        const itemCheckboxes = document.querySelectorAll('.item-checkbox');
        const selectedSubtotal = document.getElementById('selected-subtotal');
        const selectedTotal = document.getElementById('selected-total');
        const checkoutSelectedButton = document.getElementById('checkout-selected-button');
        const checkoutSelectionHint = document.getElementById('checkout-selection-hint');
        const clearCartForm = document.getElementById('clear-cart-form');
        const selectionActions = document.getElementById('cart-selection-actions');
        const deleteSelectedButton = document.getElementById('delete-selected-button');
        const cancelSelectionButton = document.getElementById('cancel-selection-button');
        let selectionMode = false;

        function updateCheckoutSelection() {
            const checkedItems = Array.from(itemCheckboxes).filter(checkbox => checkbox.checked);
            const subtotal = checkedItems.reduce((sum, checkbox) => {
                const itemSubtotal = Number.parseFloat(checkbox.dataset.subtotal);
                return sum + (Number.isFinite(itemSubtotal) ? itemSubtotal : 0);
            }, 0);
            const formattedSubtotal = '₱' + subtotal.toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });

            if (selectedSubtotal) selectedSubtotal.textContent = formattedSubtotal;
            if (selectedTotal) selectedTotal.textContent = formattedSubtotal;
            if (checkoutSelectedButton) checkoutSelectedButton.disabled = checkedItems.length === 0;
            if (deleteSelectedButton) deleteSelectedButton.disabled = checkedItems.length === 0;
            if (selectAll && selectionMode) {
                selectAll.checked = checkedItems.length === itemCheckboxes.length && itemCheckboxes.length > 0;
                selectAll.indeterminate = checkedItems.length > 0 && checkedItems.length < itemCheckboxes.length;
            }
            if (selectionActions) selectionActions.hidden = !selectionMode || checkedItems.length === 0;
            itemCheckboxes.forEach(checkbox => {
                checkbox.classList.toggle('selection-checkbox-hidden', !selectionMode);
            });
            if (checkoutSelectionHint) {
                checkoutSelectionHint.textContent = checkedItems.length === 0
                    ? 'Select products to proceed to checkout.'
                    : checkedItems.length + (checkedItems.length === 1 ? ' product selected.' : ' products selected.');
            }
        }

        if(selectAll) {
            selectAll.addEventListener('change', function() {
                selectionMode = true;
                itemCheckboxes.forEach(checkbox => {
                    checkbox.checked = selectAll.checked;
                });
                updateCheckoutSelection();
            });

            itemCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    selectionMode = true;
                    const allChecked = Array.from(itemCheckboxes).every(c => c.checked);
                    selectAll.checked = allChecked;
                    updateCheckoutSelection();
                });
            });
        }
        if (cancelSelectionButton) {
            cancelSelectionButton.addEventListener('click', function() {
                selectionMode = false;
                itemCheckboxes.forEach(checkbox => {
                    checkbox.checked = true;
                });
                if (selectAll) selectAll.checked = false;
                updateCheckoutSelection();
            });
        }
        updateCheckoutSelection();

        if (clearCartForm) {
            clearCartForm.addEventListener('submit', function(event) {
                const selectedItems = Array.from(itemCheckboxes)
                    .filter(checkbox => checkbox.checked);

                if (selectedItems.length === 0) {
                    event.preventDefault();
                    alert('Please select at least one product to clear.');
                    return;
                }

                selectedItems.forEach(checkbox => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'product_ids[]';
                    input.value = checkbox.value;
                    this.appendChild(input);
                });
            });
        }

        // --- UNIT SWITCHING LOGIC WITH DYNAMIC DISPLAY ---
        function updateUnitDisplay(productId, unit, piecePrice, boxPrice) {
            const piecesControl = document.getElementById(`qty-controls-${productId}`);
            const boxControl = document.getElementById(`qty-box-controls-${productId}`);
            const priceValue = document.getElementById(`price-value-${productId}`);
            const priceUnit = document.getElementById(`price-unit-${productId}`);
            
            if (unit === 'piece') {
                // Show piece controls, hide box controls
                piecesControl.style.display = 'flex';
                boxControl.style.display = 'none';
                // Update price
                priceValue.textContent = '₱' + piecePrice.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                priceUnit.textContent = '/ piece';
            } else if (unit === 'bottle') {
                // Show box controls, hide piece controls
                piecesControl.style.display = 'none';
                boxControl.style.display = 'flex';
                // Update price
                priceValue.textContent = '₱' + boxPrice.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                priceUnit.textContent = '/ box';
            }
            
            // Submit form to save unit preference to database
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="product_id" value="${productId}">
                <input type="hidden" name="change_unit" value="${unit}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>