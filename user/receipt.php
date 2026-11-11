<?php
session_start();
include 'config.php';
require_once __DIR__ . '/delivery_pricing.php';
require_once __DIR__ . '/discount_cooldown.php';

if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
if (empty($_SESSION['voucher_csrf_token'])) {
    $_SESSION['voucher_csrf_token'] = bin2hex(random_bytes(32));
}
$voucherCsrfToken = $_SESSION['voucher_csrf_token'];
$selectedVoucherCode = trim((string)($_SESSION['checkout_voucher_code'] ?? ''));
try {
    ensureDiscountCooldownSchema($conn);
    $discountCooldownExpiry = getDiscountCooldownExpiry($conn, $user_id);
} catch (RuntimeException $exception) {
    error_log('Checkout discount eligibility check failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to check discount eligibility.');
}
$discountCooldownActive = $discountCooldownExpiry !== null;
$buy_now_product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$buy_now_quantity = max(1, (int)($_GET['quantity'] ?? 1));
$buy_now_unit = ($_GET['unit'] ?? 'piece') === 'bottle' ? 'bottle' : 'piece';
$selectedCartProductIds = [];
if ($buy_now_product_id <= 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedProductIds = $_POST['product_ids'] ?? [];
    if (!is_array($submittedProductIds)) {
        $submittedProductIds = [];
    }
    foreach ($submittedProductIds as $submittedProductId) {
        if (is_scalar($submittedProductId) && ctype_digit((string)$submittedProductId)) {
            $productId = (int)$submittedProductId;
            if ($productId > 0) {
                $selectedCartProductIds[] = $productId;
            }
        }
    }
    $selectedCartProductIds = array_values(array_unique($selectedCartProductIds));
    if ($selectedCartProductIds === []) {
        $_SESSION['checkout_error'] = 'Select at least one product from your cart to continue.';
        header('Location: cart_screen.php');
        exit();
    }
    $selectedProductIdList = implode(',', $selectedCartProductIds);
    $selectedCartCheck = $conn->query(
        "SELECT COUNT(*) AS selected_count FROM cart
         WHERE user_id = $user_id AND product_id IN ($selectedProductIdList)
           AND (box > 0 OR pieces > 0)"
    );
    $selectedCartCount = $selectedCartCheck
        ? (int)($selectedCartCheck->fetch_assoc()['selected_count'] ?? 0)
        : 0;
    if ($selectedCartCount !== count($selectedCartProductIds)) {
        $_SESSION['checkout_error'] = 'One or more selected products are no longer in your cart. Please review your cart.';
        header('Location: cart_screen.php');
        exit();
    }
    $_SESSION['checkout_cart_product_ids'] = $selectedCartProductIds;
    unset($_SESSION['checkout_voucher_code']);
    header('Location: receipt.php');
    exit();
}
if ($buy_now_product_id <= 0) {
    $storedProductIds = $_SESSION['checkout_cart_product_ids'] ?? [];
    if (is_array($storedProductIds)) {
        foreach ($storedProductIds as $storedProductId) {
            $productId = (int)$storedProductId;
            if ($productId > 0) {
                $selectedCartProductIds[] = $productId;
            }
        }
    }
    $selectedCartProductIds = array_values(array_unique($selectedCartProductIds));
    if ($selectedCartProductIds === []) {
        $_SESSION['checkout_error'] = 'Select the products you want to order from your cart.';
        header('Location: cart_screen.php');
        exit();
    }
    $selectedProductIdList = implode(',', $selectedCartProductIds);
    $selectedCartCheck = $conn->query(
        "SELECT COUNT(*) AS selected_count FROM cart
         WHERE user_id = $user_id AND product_id IN ($selectedProductIdList)
           AND (box > 0 OR pieces > 0)"
    );
    $selectedCartCount = $selectedCartCheck
        ? (int)($selectedCartCheck->fetch_assoc()['selected_count'] ?? 0)
        : 0;
    if ($selectedCartCount !== count($selectedCartProductIds)) {
        unset($_SESSION['checkout_cart_product_ids']);
        $_SESSION['checkout_error'] = 'Some selected products are no longer in your cart. Please select your items again.';
        header('Location: cart_screen.php');
        exit();
    }
}
$selected_payment = in_array($_GET['payment'] ?? '', ['cod', 'gcash', 'bank'], true)
    ? $_GET['payment']
    : '';
$selected_delivery = in_array($_GET['delivery'] ?? '', ['lalamove', 'pickup', 'self_pickup', 'self-pickup'], true)
    ? $_GET['delivery']
    : '';
$checkoutError = (string)($_SESSION['checkout_error'] ?? '');
unset($_SESSION['checkout_error']);

// USER INFO
$user_stmt = $conn->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
if (!$user_stmt) {
    error_log('Checkout user query preparation failed: ' . $conn->error);
    header('Location: login.php');
    exit();
}
$user_stmt->bind_param('i', $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();
if (!$user) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit();
}
$riderAvailability = getRiderAvailability($conn);
$selected_delivery_active = $selected_delivery === 'lalamove' && $riderAvailability['available'];
if (($_GET['check_rider_availability'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($riderAvailability);
    exit();
}
$username = trim((string)($user['full_name'] ?? '')) ?: ($user['username'] ?? "");
$email    = $user['email'] ?? "";
$address  = $user['address'] ?? "";
$phone    = $user['phone'] ?? "";
$order_phone_check = $conn->query("SHOW COLUMNS FROM orders LIKE 'contact_number'");
if ($order_phone_check && $order_phone_check->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN contact_number VARCHAR(20) NULL AFTER fullname");
}
$address_name_check = $conn->query("SHOW COLUMNS FROM addresses LIKE 'recipient_name'");
if ($address_name_check && $address_name_check->num_rows === 0) {
    $conn->query("ALTER TABLE addresses ADD COLUMN recipient_name VARCHAR(255) NULL AFTER user_id");
}
$address_phone_check = $conn->query("SHOW COLUMNS FROM addresses LIKE 'phone'");
if ($address_phone_check && $address_phone_check->num_rows === 0) {
    $conn->query("ALTER TABLE addresses ADD COLUMN phone VARCHAR(20) NULL AFTER recipient_name");
}
foreach (['latitude', 'longitude'] as $address_coordinate_column) {
    $coordinate_check = $conn->query("SHOW COLUMNS FROM addresses LIKE '$address_coordinate_column'");
    if ($coordinate_check && $coordinate_check->num_rows === 0) {
        $conn->query("ALTER TABLE addresses ADD COLUMN $address_coordinate_column DECIMAL(10,7) NULL");
    }
}
$saved_addresses_result = $conn->query("SELECT recipient_name, phone, address, latitude, longitude FROM addresses WHERE user_id=$user_id ORDER BY is_default DESC, id DESC");
$saved_addresses = [];
if ($saved_addresses_result) {
    while ($saved_address = $saved_addresses_result->fetch_assoc()) {
        $saved_addresses[] = $saved_address;
    }
}

$selected_name = $username;
$selected_phone = $phone;
$selected_address = trim((string) $address);
if (!empty($saved_addresses)) {
    $selected_name = trim((string) ($saved_addresses[0]['recipient_name'] ?? '')) ?: $username;
    $selected_phone = trim((string) ($saved_addresses[0]['phone'] ?? '')) ?: $phone;
    $selected_address = trim((string)$saved_addresses[0]['address']);
}
$checkoutState = $_SESSION['checkout_state'] ?? [];
unset($_SESSION['checkout_state']);
if (!empty($checkoutState['address'])) {
    $selected_address = trim((string)$checkoutState['address']);
}
$selected_delivery_latitude = trim((string)($checkoutState['delivery_latitude'] ?? ''));
$selected_delivery_longitude = trim((string)($checkoutState['delivery_longitude'] ?? ''));
if ($selected_delivery_latitude === '' || $selected_delivery_longitude === '') {
    foreach ($saved_addresses as $saved_address) {
        if (trim((string)($saved_address['address'] ?? '')) === $selected_address
            && is_numeric($saved_address['latitude'] ?? null)
            && is_numeric($saved_address['longitude'] ?? null)) {
            $selected_delivery_latitude = (string)$saved_address['latitude'];
            $selected_delivery_longitude = (string)$saved_address['longitude'];
            break;
        }
    }
}

// Fetch the Buy Now product or only the cart products selected for checkout.
$query = $buy_now_product_id > 0
    ? "SELECT products.id AS product_id, products.product_name, products.image,
              COALESCE(NULLIF(products.price_piece, 0), products.price, 0) AS price_piece,
              ROUND(COALESCE(NULLIF(products.price_piece, 0), products.price, 0) * COALESCE(NULLIF(products.pieces_per_box, 0), 12) * 0.98, 2) AS price_box,
              COALESCE(NULLIF(products.pieces_per_box, 0), 12) AS pieces_per_box,
              " . ($buy_now_unit === 'bottle' ? "$buy_now_quantity AS box, 0 AS pieces" : "0 AS box, $buy_now_quantity AS pieces") . "
    FROM products
    WHERE products.id = $buy_now_product_id"
    : "SELECT products.id AS product_id, products.product_name, products.image,
        COALESCE(NULLIF(products.price_piece, 0), products.price, 0) AS price_piece,
        ROUND(COALESCE(NULLIF(products.price_piece, 0), products.price, 0) * COALESCE(NULLIF(products.pieces_per_box, 0), 12) * 0.98, 2) AS price_box,
        COALESCE(NULLIF(products.pieces_per_box, 0), 12) AS pieces_per_box,
        cart.box, cart.pieces
    FROM cart
    JOIN products ON cart.product_id = products.id
    WHERE cart.user_id = $user_id
      AND cart.product_id IN (" . implode(',', $selectedCartProductIds) . ')';
$result = $conn->query($query);
$checkoutRows = [];
$checkoutStockIssues = [];
$batchAvailability = $conn->prepare(
    'SELECT COUNT(*) AS batch_count,
            COALESCE(SUM(CASE
                WHEN expiration_date >= CURDATE() AND recalled = 0 AND current_quantity > 0
                THEN current_quantity ELSE 0
            END), 0) AS available_quantity
     FROM product_batches
     WHERE product_id = ?'
);
if (!$batchAvailability) {
    error_log('Checkout batch availability query preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to check product availability.');
}
if ($result) {
    while ($checkoutRow = $result->fetch_assoc()) {
        $checkoutRows[] = $checkoutRow;
        $productId = $buy_now_product_id > 0 ? $buy_now_product_id : (int)$checkoutRow['product_id'];
        $requiredQuantity = ((int)$checkoutRow['box'] * max(1, (int)$checkoutRow['pieces_per_box']))
            + (int)$checkoutRow['pieces'];
        $batchAvailability->bind_param('i', $productId);
        if (!$batchAvailability->execute()) {
            error_log('Checkout batch availability query failed: ' . $batchAvailability->error);
            http_response_code(500);
            exit('Unable to check product availability.');
        }
        $batchStock = $batchAvailability->get_result()->fetch_assoc();
        $batchCount = (int)($batchStock['batch_count'] ?? 0);
        $availableQuantity = (int)($batchStock['available_quantity'] ?? 0);
        if ($batchCount > 0 && $availableQuantity < $requiredQuantity) {
            $checkoutStockIssues[] = [
                'product_name' => (string)$checkoutRow['product_name'],
                'required_quantity' => $requiredQuantity,
                'available_quantity' => $availableQuantity,
            ];
        }
    }
}
$batchAvailability->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Checkout Summary</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --primary: #4a1018; /* Your Maroon Theme */
            --secondary: #27ae60;
            --bg: #f4f7f6;
            --text: #333;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #eef7f6 0%, #f8fbfa 100%);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 42px 20px;
            color: var(--text);
        }

        .receipt-card {
            background: white;
            width: 100%;
            max-width: 570px;
            border-radius: 17px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            overflow: hidden;
            border: 1px solid #e4e8e7;
        }

        .header {
            background: var(--primary);
            color: white;
            padding: 22px 28px;
            text-align: center;
        }

        .header h2 { margin: 0; font-size: 23px; letter-spacing: .3px; }
        .header h2::before { content: '✚'; display: inline-flex; align-items: center; justify-content: center; margin-right: 16px; font-size: 22px; }

        .content { padding: 22px 28px 24px; }

        .info-section { margin-bottom: 20px; }
        .info-section > label { 
            display: block; 
            font-size: 11px; 
            font-weight: bold; 
            color: #888; 
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .info-data { 
            font-size: 15px; 
            color: #333; 
            margin-bottom: 12px; 
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 5px;
        }

        .order-table {
            width: 100%;
            margin-top: 10px;
            border-collapse: collapse;
            font-size: 15px;
        }

        .order-table th {
            padding: 8px 6px;
            color: #555;
            text-align: left;
            font-weight: 700;
        }

        .order-table th:nth-child(n+2),
        .order-table td:nth-child(n+2) {
            text-align: right;
        }

        .item-row td {
            padding: 10px 6px;
            border-bottom: 1px solid #f1f1f1;
            vertical-align: middle;
        }

        .item-product {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .item-image {
            width: 34px;
            height: 44px;
            flex-shrink: 0;
            object-fit: contain;
        }

        .item-name { font-weight: 700; color: #222; }
        .item-details { font-size: 14px; color: #777; margin-top: 3px; }
        .item-price, .item-qty, .item-subtotal { white-space: nowrap; }
        .item-subtotal { color: #e52332; font-weight: 700; }

        .summary {
            background: #effaf6;
            padding: 19px 20px;
            border-radius: 10px;
            margin-top: 20px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 18px;
            font-weight: 800;
        }

        .checkout-total-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            color: #68758a;
            font-size: 14px;
        }

        .total-row > span:first-child { display: flex; align-items: center; gap: 12px; }
        .total-icon { display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; border-radius: 50%; background: #27ae60; color: #fff; font-size: 20px; }
        .total-amount { color: var(--secondary); font-size: 22px; }
        .voucher-section { margin: 16px 0 0; padding: 0; border: 0; border-radius: 0; background: transparent; }
        .voucher-section h3 { margin: 0; color: #16263e; font-size: 14px; line-height: 1.4; }
        .voucher-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .voucher-toggle-row h3 { margin: 0; }
        .voucher-toggle { position: relative; display: inline-flex; flex: 0 0 46px; width: 46px; height: 26px; }
        .voucher-toggle input { width: 0; height: 0; opacity: 0; }
        .voucher-toggle-track { position: absolute; inset: 0; border-radius: 999px; background: #cbd5e1; cursor: pointer; transition: background .2s; }
        .voucher-toggle-track::before { position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; content: ''; box-shadow: 0 1px 3px rgba(0,0,0,.2); transition: transform .2s; }
        .voucher-toggle input:checked + .voucher-toggle-track { background: #ed1740; }
        .voucher-toggle input:checked + .voucher-toggle-track::before { transform: translateX(20px); }
        .voucher-toggle input:focus-visible + .voucher-toggle-track { outline: 2px solid #16263e; outline-offset: 2px; }
        .voucher-section p { margin: 0 0 12px; color: #68758a; font-size: 13px; }
        .voucher-entry { display: grid; grid-template-columns: minmax(0, 1fr) max-content; column-gap: 14px; align-items: center; width: 100%; }
        .voucher-entry[hidden] { display: none !important; }
        .voucher-entry input { box-sizing: border-box; width: 100%; min-width: 0; height: 38px; margin: 0; transform: translateY(4px); }
        .voucher-entry button { box-sizing: border-box; min-height: 38px; padding: 0 12px; border: 0; border-radius: 8px; background: #ed1740; color: #fff; font: inherit; font-size: 13px; font-weight: 700; line-height: 1; white-space: nowrap; cursor: pointer; }
        .voucher-actions { display: flex; justify-content: flex-end; gap: 8px; transform: translateY(4px); }
        .voucher-entry button:disabled { opacity: .65; cursor: wait; }
        .voucher-remove-button { background: #64748b !important; }
        .voucher-status { margin: 6px 0 0 !important; font-size: 12px !important; line-height: 1.4; }
        .voucher-status:empty { display: none; }
        .voucher-status.is-error { color: #9b1c31; }
        .voucher-status.is-success { color: #16804a; }
        .voucher-discount-line strong { color: #16804a; }

        .payment-box {
            background: #fff8f8;
            border: 1px dashed var(--primary);
            padding: 15px;
            border-radius: 10px;
            margin-top: 15px;
            font-size: 14px;
        }

        select, input[type="text"] {
            width: 100%;
            padding: 12px;
            margin-top: 8px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        .voucher-entry input { margin-top: 0; }
        .address-picker { position: relative; z-index: 1100; margin-top: 8px; }
        .selected-address { width: 100%; display: grid; grid-template-columns: 23px 1fr 28px; gap: 10px; align-items: start; padding: 13px 14px; border: 1px solid #d8aeb5; border-radius: 9px; background: #fff5f6; color: #222; text-align: left; box-shadow: 0 0 0 2px rgba(121, 20, 29, .07); }
        .address-options { display: none; position: absolute; z-index: 1101; top: calc(100% + 7px); left: 0; right: 0; padding: 8px; gap: 8px; border: 1px solid #eadfe1; border-radius: 10px; background: #fff; box-shadow: 0 10px 24px rgba(74,16,24,0.14); }
        .address-options.is-open { display: grid; }
        .address-option { width: 100%; display: grid; grid-template-columns: 18px 1fr 18px; gap: 8px; align-items: start; padding: 10px 12px; border: 1px solid #eee; border-radius: 10px; background: #fff; color: #222; text-align: left; cursor: pointer; }
        .address-option:hover, .address-option.selected { border-color: #4a1018; background: #fffafa; }
        .address-pin { color: #76152a; font-size: 18px; line-height: 1.2; }
        .selected-address > .address-pin { font-size: 17px; }
        .address-details { min-width: 0; font-size: 12px; line-height: 1.35; }
        .address-label { display: block; color: #68758a; font-size: 10px; font-weight: 800; letter-spacing: .04em; margin-bottom: 8px; }
        .address-recipient { font-weight: 700; font-size: 13px; }
        .address-phone { color: #98a2b3; margin-left: 4px; }
        .address-text { display: block; color: #344054; margin-top: 3px; }
        .selected-address .address-text { width: fit-content; max-width: 100%; padding: 0; border: 0; background: transparent; color: #0d6efd; font: inherit; text-align: left; text-decoration: underline; text-decoration-thickness: 1px; text-underline-offset: 2px; cursor: pointer; }
        .selected-address .address-text:hover, .selected-address .address-text:focus-visible { color: #0a58ca; text-decoration-thickness: 2px; outline: none; }
        .address-chevron { padding: 0; border: 0; background: transparent; color: #98a2b3; font-size: 22px; line-height: 1; cursor: pointer; }
        .address-chevron:focus-visible { color: #79141d; outline: 2px solid #79141d; outline-offset: 2px; border-radius: 4px; }
        .add-address-link { display: block; color: #4a1018; font-size: 12px; font-weight: 700; text-decoration: underline; padding: 4px 2px 2px; }
        .address-required-notice { margin-top: 8px; color: #9b1c31; font-size: 12px; line-height: 1.5; }
        .address-required-notice a { color: #4a1018; font-weight: 700; }
        .delivery-location-picker { margin-top: 16px; padding: 14px; border: 1px solid #e7e9ed; border-radius: 10px; background: #fff; }
        .delivery-location-picker h3 { margin: 0 0 6px; color: #344054; font-size: 14px; }
        .delivery-location-picker p { margin: 0 0 10px; color: #68758a; font-size: 12px; line-height: 1.45; }
        .delivery-location-picker[hidden] { display: none; }
        #delivery-location-map { height: 280px; border: 1px solid #dfe4e7; border-radius: 8px; background: #eef3f1; }
        .delivery-distance-note { margin-top: 9px !important; font-weight: 700; }
        .delivery-distance-note.is-error { color: #9b1c31; }
        .delivery-route-note { margin-top: 7px !important; color: #68758a; font-size: 11px !important; }
        .delivery-route-note.is-error { color: #9b1c31; }
        .delivery-address-note { margin-top: 7px !important; }
        .delivery-address-note.is-error { color: #9b1c31; }

        .payment-options { display: grid; gap: 8px; margin-top: 8px; }
        .payment-option { width: 100%; display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid #dfe4e7; border-radius: 10px; background: #fff; color: #16263e; font-size: 14px; font-weight: 700; text-align: left; cursor: pointer; }
        .payment-option:hover, .payment-option.selected { border-color: var(--primary); background: #fffafa; }
        .payment-option::before { content: ''; width: 16px; height: 16px; border: 2px solid #b8b8b8; border-radius: 50%; flex-shrink: 0; }
        .payment-option.selected::before { border: 5px solid var(--primary); }
        .delivery-options { display: grid; gap: 8px; margin-top: 8px; }
        .delivery-option { width: 100%; display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid #dfe4e7; border-radius: 10px; background: #fff; color: #16263e; font-size: 14px; font-weight: 700; text-align: left; cursor: pointer; }
        .delivery-option:hover:not(:disabled), .delivery-option.selected { border-color: var(--primary); background: #fffafa; }
        .delivery-option:disabled { background: #f2f4f7; color: #7b8492; cursor: not-allowed; opacity: .72; }
        .delivery-option::before { content: ''; width: 16px; height: 16px; border: 2px solid #b8b8b8; border-radius: 50%; flex-shrink: 0; }
        .delivery-option.selected::before { border: 5px solid var(--primary); }
        .delivery-option::after, .payment-option::after { order: 1; width: 34px; text-align: center; font-size: 25px; line-height: 1; }
        .delivery-option > span, .payment-option > span { order: 2; }
        .delivery-option[data-delivery="lalamove"]::after { content: '🛵'; }
        .delivery-option[data-delivery="pickup"]::after { content: '🏪'; }
        .payment-option[data-payment="cod"]::after { content: '💵'; }
        .payment-option[data-payment="gcash"]::after { content: '🅶'; color: #0878e8; }
        .payment-option[data-payment="bank"]::after { content: '🏛'; color: #76152a; }
        .discount-section { margin-top: 18px; }
        .discount-section[hidden] { display: none !important; }
        .discount-options { display: grid; gap: 9px; margin-top: 8px; }
        .discount-option { display: flex; align-items: center; gap: 10px; padding: 13px 14px; border: 1px solid #dfe4e7; border-radius: 9px; background: #fff; color: #16263e; font-size: 14px; font-weight: 700; cursor: pointer; }
        .discount-option.selected { border-color: var(--primary); background: #fffafa; }
        .discount-option input { accent-color: var(--primary); }
        .discount-id-panel { margin-top: 12px; padding: 15px; border: 1px solid #ead9dc; border-radius: 10px; background: #fffafb; }
        .discount-id-panel h3 { margin: 0 0 7px; color: var(--primary); font-size: 15px; }
        .discount-id-panel p { margin: 0 0 12px; color: #555; font-size: 13px; }
        .discount-id-file { display: block; max-width: 100%; margin-top: 8px; color: #333; font-size: 13px; font-weight: 400; }
        .gcash-payment { text-align: center; background: #f1f9ff; border: 1px solid #cfe8ff; padding: 14px 12px; }
        .gcash-title { display: flex; align-items: center; justify-content: space-between; color: #0878e8; font-size: 18px; font-weight: 800; }
        .gcash-title::before { content: '◉'; font-size: 22px; }
        .gcash-secure { color: #0878e8; border: 1px solid #cfe8ff; border-radius: 12px; padding: 4px 8px; font-size: 9px; font-weight: 700; }
        .gcash-payment img { display: block; width: 170px; height: 170px; object-fit: contain; margin: 12px auto 14px; border: 1px solid #cfe8ff; border-radius: 8px; background: white; }
        .gcash-or { display: flex; align-items: center; gap: 10px; margin: 4px 0 10px; color: #66809a; font-size: 10px; }
        .gcash-or::before, .gcash-or::after { content: ''; height: 1px; background: #d5e6f2; flex: 1; }
        .payment-upload { display: block; margin-top: 0; padding: 11px 12px; border: 1px dashed #b7cce1; border-radius: 8px; background: white; text-align: left; font-size: 12px; color: #16436b; font-weight: 700; }
        .payment-upload input { width: 100%; margin-top: 7px; font-weight: 400; }

        .btn-confirm {
            width: 100%;
            padding: 15px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 18px;
            transition: 0.3s;
        }

        .btn-confirm:hover { background: #350a11; transform: translateY(-2px); }
        .btn-confirm:disabled { background: #a9a2a3; cursor: not-allowed; transform: none; }
        .btn-confirm::after { content: '→'; margin-left: 14px; font-size: 23px; vertical-align: -2px; }
        .checkout-section { margin-top: 18px; }
        .checkout-section > label { display: block; margin-bottom: 8px; color: #68758a; font-size: 11px; font-weight: 700; letter-spacing: .04em; }

        .hidden { display: none; }
        .checkout-address-section[hidden], .checkout-total-line[hidden] { display: none !important; }
        .badge-qty { background: #eee; padding: 2px 6px; border-radius: 4px; font-weight: bold; }
    </style>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const orderForm = document.getElementById('order-form');
            const orderTotalInput = document.getElementById('checkout-order-total');
            const totalAmountDisplay = document.getElementById('checkout-total-amount');
            const itemsSubtotal = Number(orderTotalInput.value);
            const checkoutStockAvailable = <?= $checkoutStockIssues ? 'false' : 'true' ?>;
            let voucherDiscount = 0;
            let voucherRequestPending = false;
            const voucherCodeInput = document.getElementById('voucher-code-input');
            const orderVoucherCode = document.getElementById('checkout-voucher-code');
            const voucherStatus = document.getElementById('voucher-status');
            const voucherApplyButton = document.getElementById('voucher-apply-button');
            const voucherRemoveButton = document.getElementById('voucher-remove-button');
            const voucherDiscountRow = document.getElementById('voucher-discount-row');
            const voucherDiscountValue = document.getElementById('voucher-discount-value');
            const voucherToggle = document.getElementById('voucher-toggle-input');
            const voucherEntry = document.getElementById('voucher-entry');
            const storeLocation = [<?= PHARMACY_STORE_LATITUDE ?>, <?= PHARMACY_STORE_LONGITUDE ?>];
            const latitudeField = document.getElementById('delivery_latitude');
            const longitudeField = document.getElementById('delivery_longitude');
            const distanceNote = document.getElementById('delivery-distance-note');
            const routeNote = document.getElementById('delivery-route-note');
            const addressLookupNote = document.getElementById('delivery-address-note');
            const deliveryLocationPicker = document.querySelector('.delivery-location-picker');
            const placeOrderButton = document.getElementById('place-order-button');
            const addressField = document.getElementById('checkoutAddress');
            const addressRequiredNotice = document.getElementById('address-required-notice');
            const riderDeliveryOption = document.querySelector('.delivery-option[data-delivery="lalamove"]');
            const riderAvailabilityNote = document.getElementById('rider-availability-note');
            let riderDeliveryAvailable = <?= json_encode($riderAvailability['available']) ?>;
            let riderAvailabilityError = <?= json_encode($riderAvailability['error']) ?>;
            let destinationMarker = null;
            let deliveryRouteLine = null;
            let deliveryRouteOutline = null;
            let deliveryRouteRequestSequence = 0;
            let deliveryRoadDistanceKm = null;
            let deliveryRoutePending = false;
            let addressLookupSequence = 0;
            let addressLookupTimer = null;
            let addressLookupPending = false;
            let addressLookupFailed = false;
            let nominatimQueue = Promise.resolve();
            let lastNominatimRequestAt = 0;
            const mapViewRadiusKm = 15;
            const latitudeDelta = mapViewRadiusKm / 111.32;
            const longitudeDelta = mapViewRadiusKm / (111.32 * Math.cos(storeLocation[0] * Math.PI / 180));
            const serviceAreaBounds = L.latLngBounds(
                [storeLocation[0] - latitudeDelta, storeLocation[1] - longitudeDelta],
                [storeLocation[0] + latitudeDelta, storeLocation[1] + longitudeDelta]
            );
            const deliveryMap = L.map('delivery-location-map', {
                maxBounds: serviceAreaBounds,
                maxBoundsViscosity: 1
            }).setView(storeLocation, 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(deliveryMap);
            const storeMarker = L.marker(storeLocation).addTo(deliveryMap)
                .bindPopup('Store: <?= htmlspecialchars(PHARMACY_STORE_ADDRESS, ENT_QUOTES, 'UTF-8') ?>');

            function nominatimRequest(url) {
                const request = async function () {
                    const wait = Math.max(0, 1000 - (Date.now() - lastNominatimRequestAt));
                    if (wait > 0) {
                        await new Promise(function (resolve) { window.setTimeout(resolve, wait); });
                    }
                    lastNominatimRequestAt = Date.now();
                    const response = await fetch(url, {headers: {Accept: 'application/json'}});
                    if (!response.ok) {
                        throw new Error('Address lookup failed with status ' + response.status);
                    }
                    return response.json();
                };
                const result = nominatimQueue.then(request, request);
                nominatimQueue = result.catch(function () {});
                return result;
            }

            function updateAddressLookupNote(message, isError) {
                addressLookupNote.textContent = message;
                addressLookupNote.classList.toggle('is-error', isError);
            }

            function selectDeliveryOption(deliveryType) {
                document.querySelectorAll('.delivery-option').forEach(function (option) {
                    option.classList.toggle('selected', option.dataset.delivery === deliveryType);
                });
                document.getElementById('delivery_type').value = deliveryType;
            }

            async function locateAddressOnMap(address) {
                const lookupSequence = ++addressLookupSequence;
                window.clearTimeout(addressLookupTimer);
                addressLookupPending = true;
                addressLookupFailed = false;
                updateAddressLookupNote('Finding this address on the map…', false);
                updateDeliveryEstimate();

                try {
                    const params = new URLSearchParams({
                        format: 'jsonv2',
                        limit: '1',
                        countrycodes: 'ph',
                        q: address
                    });
                    const results = await nominatimRequest('https://nominatim.openstreetmap.org/search?' + params.toString());
                    if (lookupSequence !== addressLookupSequence) return;
                    const match = Array.isArray(results) ? results[0] : null;
                    if (!match || !Number.isFinite(Number(match.lat)) || !Number.isFinite(Number(match.lon))) {
                        throw new Error('No matching map location was found for this address.');
                    }

                    const latitude = Number(match.lat);
                    const longitude = Number(match.lon);
                    deliveryMap.setView([latitude, longitude], 16);
                    setDeliveryPoint(latitude, longitude, false);
                    addressLookupPending = false;
                    addressLookupFailed = false;
                    updateAddressLookupNote('Map pin set to the selected shipping address. Drag it to adjust.', false);
                    updateDeliveryEstimate();
                } catch (error) {
                    if (lookupSequence !== addressLookupSequence) return;
                    addressLookupPending = false;
                    addressLookupFailed = true;
                    updateAddressLookupNote('Could not locate this address. Check the address or choose a location on the map.', true);
                    updateDeliveryEstimate();
                    console.error('Unable to locate the checkout address.', error);
                }
            }

            async function reverseGeocodeDeliveryPoint(latitude, longitude, lookupSequence) {
                addressLookupPending = true;
                addressLookupFailed = false;
                updateAddressLookupNote('Updating the shipping address from the map pin…', false);
                updateDeliveryEstimate();

                try {
                    const params = new URLSearchParams({
                        format: 'jsonv2',
                        addressdetails: '1',
                        zoom: '18',
                        lat: String(latitude),
                        lon: String(longitude)
                    });
                    const result = await nominatimRequest('https://nominatim.openstreetmap.org/reverse?' + params.toString());
                    if (lookupSequence !== addressLookupSequence) return;
                    if (!result || typeof result.display_name !== 'string' || result.display_name.trim() === '') {
                        throw new Error('No street address was returned for the selected map location.');
                    }

                    addressField.value = result.display_name;
                    selectedAddressCard.querySelector('.address-text').textContent = result.display_name;
                    addressLookupPending = false;
                    addressLookupFailed = false;
                    updateAddressLookupNote('Shipping address updated from the map pin.', false);
                    updateAddressRequirement();
                    updateDeliveryEstimate();
                } catch (error) {
                    if (lookupSequence !== addressLookupSequence) return;
                    addressLookupPending = false;
                    addressLookupFailed = true;
                    updateAddressLookupNote('Could not update the shipping address. Move the pin and try again.', true);
                    updateDeliveryEstimate();
                    console.error('Unable to update the checkout address from the map pin.', error);
                }
            }

            function openDeliveryLocation(address, latitude, longitude) {
                deliveryLocationPicker.hidden = false;
                window.setTimeout(function () {
                    deliveryMap.invalidateSize();
                    const minimumMapZoom = deliveryMap.getBoundsZoom(serviceAreaBounds);
                    deliveryMap.setMinZoom(minimumMapZoom);
                    if (deliveryMap.getZoom() < minimumMapZoom) {
                        deliveryMap.setZoom(minimumMapZoom);
                    }
                }, 0);

                if (!document.getElementById('delivery_type').value && riderDeliveryAvailable) {
                    selectDeliveryOption('lalamove');
                }

                const selectedAddress = (address || addressField.value).trim();
                if (Number.isFinite(Number(latitude)) && Number.isFinite(Number(longitude))
                    && latitude !== '' && longitude !== '') {
                    const savedLatitude = Number(latitude);
                    const savedLongitude = Number(longitude);
                    deliveryMap.setView([savedLatitude, savedLongitude], 16);
                    setDeliveryPoint(savedLatitude, savedLongitude, false);
                    addressLookupPending = false;
                    addressLookupFailed = false;
                    updateAddressLookupNote('Saved address pin loaded. Drag it to adjust.', false);
                    updateDeliveryEstimate();
                } else if (latitudeField.value && longitudeField.value && !address) {
                    deliveryMap.setView([Number(latitudeField.value), Number(longitudeField.value)], 16);
                    updateDeliveryEstimate();
                } else if (selectedAddress) {
                    locateAddressOnMap(selectedAddress);
                } else {
                    deliveryMap.setView(storeLocation, 12);
                    updateAddressLookupNote('Choose a location on the map to set the shipping address.', false);
                    updateDeliveryEstimate();
                }
            }

            function deliveryFeeForDistance(distance) {
                if (distance > 10) return null;
                const tierDistance = Math.round(distance * 10) / 10;
                if (tierDistance <= 3) return 50;
                if (tierDistance <= 5) return 70;
                if (tierDistance <= 7) return 90;
                return 120;
            }

            function updateCheckoutTotal(deliveryFee) {
                const payable = Math.max(0, itemsSubtotal - voucherDiscount + deliveryFee);
                orderTotalInput.value = payable.toFixed(2);
                totalAmountDisplay.textContent = '₱' + payable.toFixed(2);
            }

            function updateDeliveryEstimate() {
                const deliveryType = document.getElementById('delivery_type').value;
                if (deliveryType !== 'lalamove') {
                    distanceNote.textContent = deliveryType === 'pickup'
                        ? 'Self-pickup has no delivery fee.'
                        : 'Select Rider delivery and pin your delivery location to see the fee.';
                    distanceNote.classList.remove('is-error');
                    document.getElementById('checkout-delivery-fee').textContent = '₱0.00';
                    updateCheckoutTotal(0);
                    placeOrderButton.disabled = !checkoutStockAvailable
                        || (deliveryType !== 'pickup' && !addressField.value.trim());
                    return;
                }
                if (!latitudeField.value || !longitudeField.value) {
                    distanceNote.textContent = 'Pin your delivery location on the map to calculate the Rider fee.';
                    distanceNote.classList.remove('is-error');
                    document.getElementById('checkout-delivery-fee').textContent = '—';
                    placeOrderButton.disabled = true;
                    return;
                }
                if (deliveryRoutePending) {
                    distanceNote.textContent = 'Calculating road distance and Rider fee…';
                    distanceNote.classList.remove('is-error');
                    document.getElementById('checkout-delivery-fee').textContent = '—';
                    updateCheckoutTotal(0);
                    placeOrderButton.disabled = true;
                    return;
                }
                if (deliveryRoadDistanceKm === null) {
                    distanceNote.textContent = 'Road distance is not available. Move the pin or try again.';
                    distanceNote.classList.add('is-error');
                    document.getElementById('checkout-delivery-fee').textContent = 'Unavailable';
                    updateCheckoutTotal(0);
                    placeOrderButton.disabled = true;
                    return;
                }
                const distance = deliveryRoadDistanceKm;
                const fee = deliveryFeeForDistance(distance);
                if (fee === null) {
                    distanceNote.textContent = 'Delivery is unavailable beyond 10 km by road. Choose self-pickup or update your location.';
                    distanceNote.classList.add('is-error');
                    document.getElementById('checkout-delivery-fee').textContent = 'Unavailable';
                    updateCheckoutTotal(0);
                    placeOrderButton.disabled = true;
                    return;
                }
                distanceNote.textContent = distance.toFixed(1) + ' km by road · Rider fee ₱' + fee.toFixed(2);
                distanceNote.classList.remove('is-error');
                document.getElementById('checkout-delivery-fee').textContent = '₱' + fee.toFixed(2);
                updateCheckoutTotal(fee);
                placeOrderButton.disabled = !addressField.value.trim()
                    || addressLookupPending || addressLookupFailed || deliveryRoutePending
                    || !checkoutStockAvailable;
            }

            async function applyVoucher(code) {
                voucherRequestPending = true;
                voucherApplyButton.disabled = true;
                voucherRemoveButton.disabled = true;
                voucherStatus.textContent = 'Checking voucher…';
                voucherStatus.classList.remove('is-error', 'is-success');
                const body = new FormData();
                body.set('csrf_token', <?= json_encode($voucherCsrfToken) ?>);
                body.set('code', code);
                const buyNowProduct = document.querySelector('[name="buy_now_product_id"]');
                const buyNowQuantity = document.querySelector('[name="buy_now_quantity"]');
                const buyNowUnit = document.querySelector('[name="buy_now_unit"]');
                if (buyNowProduct) {
                    body.set('buy_now_product_id', buyNowProduct.value);
                    body.set('buy_now_quantity', buyNowQuantity.value);
                    body.set('buy_now_unit', buyNowUnit.value);
                }

                try {
                    const response = await fetch('apply_voucher.php', {method: 'POST', body: body});
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Unable to apply this voucher.');
                    }
                    voucherDiscount = result.removed ? 0 : Number(result.discount_amount);
                    orderVoucherCode.value = result.removed ? '' : result.code;
                    voucherDiscountRow.hidden = result.removed;
                    voucherDiscountValue.textContent = '−₱' + voucherDiscount.toFixed(2);
                    voucherRemoveButton.hidden = result.removed;
                    if (result.removed) voucherCodeInput.value = '';
                    voucherStatus.textContent = result.message;
                    voucherStatus.classList.add('is-success');
                    updateDiscountAvailability();
                    updateDeliveryEstimate();
                } catch (error) {
                    voucherDiscount = 0;
                    orderVoucherCode.value = '';
                    voucherDiscountRow.hidden = true;
                    voucherRemoveButton.hidden = true;
                    voucherStatus.textContent = error instanceof Error ? error.message : 'Unable to apply this voucher.';
                    voucherStatus.classList.add('is-error');
                    updateDiscountAvailability();
                    updateDeliveryEstimate();
                } finally {
                    voucherRequestPending = false;
                    voucherApplyButton.disabled = false;
                    voucherRemoveButton.disabled = false;
                }
            }

            voucherApplyButton.addEventListener('click', function () {
                if (document.querySelector('input[name="discount_type"]:checked')) {
                    voucherStatus.textContent = 'Only one discount can be used per order. Remove the Senior Citizen/PWD discount first.';
                    voucherStatus.classList.remove('is-success');
                    voucherStatus.classList.add('is-error');
                    return;
                }
                applyVoucher(voucherCodeInput.value.trim());
            });
            voucherToggle.addEventListener('change', function () {
                voucherEntry.hidden = !voucherToggle.checked;
                if (!voucherToggle.checked) {
                    voucherCodeInput.value = '';
                    applyVoucher('');
                } else {
                    voucherCodeInput.focus();
                }
            });
            voucherCodeInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    voucherApplyButton.click();
                }
            });
            voucherRemoveButton.addEventListener('click', function () {
                voucherCodeInput.value = '';
                applyVoucher('');
            });
            voucherCodeInput.addEventListener('input', function () {
                if (orderVoucherCode.value) {
                    voucherDiscount = 0;
                    orderVoucherCode.value = '';
                    voucherDiscountRow.hidden = true;
                    voucherRemoveButton.hidden = true;
                    voucherStatus.textContent = 'Apply the edited code to use it.';
                    voucherStatus.classList.remove('is-error', 'is-success');
                    updateDiscountAvailability();
                    updateDeliveryEstimate();
                    const clearBody = new FormData();
                    clearBody.set('csrf_token', <?= json_encode($voucherCsrfToken) ?>);
                    clearBody.set('code', '');
                    fetch('apply_voucher.php', {method: 'POST', body: clearBody})
                        .catch(function (error) { console.error('Unable to clear the previous voucher.', error); });
                }
            });

            function applyDeliveryMethod(deliveryType) {
                if (deliveryType === 'lalamove' && !riderDeliveryAvailable) {
                    deliveryType = '';
                    document.getElementById('delivery_type').value = '';
                    riderDeliveryOption.classList.remove('selected');
                }
                const isRiderDelivery = deliveryType === 'lalamove';
                document.getElementById('checkout-address-section').hidden = !isRiderDelivery;
                deliveryLocationPicker.hidden = true;
                document.getElementById('checkout-delivery-fee-row').hidden = !isRiderDelivery;
                updateDeliveryEstimate();
                if (isRiderDelivery) {
                    if (latitudeField.value && longitudeField.value) {
                        setDeliveryPoint(Number(latitudeField.value), Number(longitudeField.value), false);
                    } else if (addressField.value.trim()) {
                        locateAddressOnMap(addressField.value.trim());
                    }
                }
            }

            function updateRiderAvailability(availability) {
                riderDeliveryAvailable = availability.available === true;
                riderAvailabilityError = availability.error || null;
                riderDeliveryOption.disabled = !riderDeliveryAvailable;
                riderDeliveryOption.title = riderDeliveryAvailable ? '' : 'Rider delivery is currently unavailable.';
                riderAvailabilityNote.hidden = riderDeliveryAvailable;
                riderAvailabilityNote.textContent = riderAvailabilityError
                    ? 'Unable to confirm rider availability right now. Please choose Self-Pickup or try again.'
                    : 'No rider is online right now. Please choose Self-Pickup.';

                if (!riderDeliveryAvailable && document.getElementById('delivery_type').value === 'lalamove') {
                    document.getElementById('delivery_type').value = '';
                    riderDeliveryOption.classList.remove('selected');
                    applyDeliveryMethod('');
                }
            }

            async function refreshRiderAvailability() {
                try {
                    const response = await fetch('receipt.php?check_rider_availability=1', {
                        cache: 'no-store',
                        credentials: 'same-origin',
                        headers: {Accept: 'application/json'}
                    });
                    if (!response.ok) {
                        throw new Error('Rider availability check failed with status ' + response.status);
                    }
                    updateRiderAvailability(await response.json());
                } catch (error) {
                    console.error('Unable to refresh rider availability.', error);
                    updateRiderAvailability({available: false, error: 'Unable to verify rider availability.'});
                }
            }

            updateRiderAvailability({available: riderDeliveryAvailable, error: riderAvailabilityError});
            window.setInterval(refreshRiderAvailability, 30000);

            function updateDeliveryRoute(point) {
                const requestSequence = ++deliveryRouteRequestSequence;
                const removeRouteLayers = function () {
                    if (deliveryRouteOutline) {
                        deliveryMap.removeLayer(deliveryRouteOutline);
                        deliveryRouteOutline = null;
                    }
                    if (deliveryRouteLine) {
                        deliveryMap.removeLayer(deliveryRouteLine);
                        deliveryRouteLine = null;
                    }
                };

                removeRouteLayers();
                deliveryRoadDistanceKm = null;
                deliveryRoutePending = true;
                routeNote.textContent = 'Finding a road route…';
                routeNote.classList.remove('is-error');
                updateDeliveryEstimate();

                const coordinates = storeLocation[1] + ',' + storeLocation[0]
                    + ';' + point[1] + ',' + point[0];
                const routeEndpoints = [
                    'https://router.project-osrm.org/route/v1/driving/',
                    'https://routing.openstreetmap.de/routed-car/route/v1/driving/'
                ];

                async function requestRoadRoute() {
                    let lastError = null;

                    for (const endpoint of routeEndpoints) {
                        if (requestSequence !== deliveryRouteRequestSequence) return;

                        try {
                            const response = await fetch(
                                endpoint + coordinates + '?overview=full&geometries=geojson',
                                {headers: {Accept: 'application/json'}}
                            );
                            const data = await response.json();
                            if (!response.ok) {
                                throw new Error('Routing service returned HTTP ' + response.status + '.');
                            }
                            if (!data || data.code !== 'Ok' || !Array.isArray(data.routes) || !data.routes[0]) {
                                throw new Error(data && data.message ? data.message : 'No driving route was found.');
                            }

                            const routeDistanceMeters = Number(data.routes[0].distance);
                            if (!Number.isFinite(routeDistanceMeters) || routeDistanceMeters < 0) {
                                throw new Error('The routing service returned an invalid road distance.');
                            }
                            const routeCoordinates = data.routes[0].geometry
                                && Array.isArray(data.routes[0].geometry.coordinates)
                                ? data.routes[0].geometry.coordinates
                                : [];
                            if (routeCoordinates.length < 2) {
                                throw new Error('The routing service returned an empty road route.');
                            }

                            const routePoints = routeCoordinates.map(function (coordinate) {
                                if (!Array.isArray(coordinate) || coordinate.length < 2
                                    || !Number.isFinite(Number(coordinate[0]))
                                    || !Number.isFinite(Number(coordinate[1]))) {
                                    throw new Error('The routing service returned invalid route coordinates.');
                                }
                                return [Number(coordinate[1]), Number(coordinate[0])];
                            });

                            if (requestSequence !== deliveryRouteRequestSequence) return;
                            deliveryRouteOutline = L.polyline(routePoints, {
                                color: '#ffffff',
                                weight: 12,
                                opacity: 1,
                                lineCap: 'round',
                                lineJoin: 'round'
                            }).addTo(deliveryMap);
                            deliveryRouteLine = L.polyline(routePoints, {
                                color: '#5b4ce6',
                                weight: 6,
                                opacity: 1,
                                lineCap: 'round',
                                lineJoin: 'round'
                            }).addTo(deliveryMap);
                            deliveryRoadDistanceKm = routeDistanceMeters / 1000;
                            deliveryRoutePending = false;
                            routeNote.textContent = 'Road route shown.';
                            routeNote.classList.remove('is-error');
                            updateDeliveryEstimate();
                            return;
                        } catch (error) {
                            lastError = error;
                        }
                    }

                    if (requestSequence !== deliveryRouteRequestSequence) return;
                    deliveryRoadDistanceKm = null;
                    deliveryRoutePending = false;
                    const reason = lastError instanceof Error ? lastError.message : 'Routing services are unavailable.';
                    routeNote.textContent = 'Could not load a road route: ' + reason;
                    routeNote.classList.add('is-error');
                    updateDeliveryEstimate();
                    console.error('Unable to load the road route.', lastError);
                }

                requestRoadRoute();
            }

            function setDeliveryPoint(latitude, longitude, shouldReverseGeocode) {
                const point = [latitude, longitude];
                latitudeField.value = latitude.toFixed(7);
                longitudeField.value = longitude.toFixed(7);

                updateDeliveryRoute(point);

                if (!destinationMarker) {
                    destinationMarker = L.marker(point, {draggable: true}).addTo(deliveryMap)
                        .bindPopup('Delivery location');
                    destinationMarker.on('dragend', function (event) {
                        const position = event.target.getLatLng();
                        setDeliveryPoint(position.lat, position.lng, true);
                    });
                } else {
                    destinationMarker.setLatLng(point);
                }
                updateDeliveryEstimate();
                if (shouldReverseGeocode) {
                    addressLookupPending = true;
                    addressLookupFailed = false;
                    updateAddressLookupNote('Updating the shipping address from the map pin…', false);
                    updateDeliveryEstimate();
                    const lookupSequence = ++addressLookupSequence;
                    window.clearTimeout(addressLookupTimer);
                    addressLookupTimer = window.setTimeout(function () {
                        reverseGeocodeDeliveryPoint(latitude, longitude, lookupSequence);
                    }, 1000);
                }
            }

            deliveryMap.on('click', function (event) {
                setDeliveryPoint(event.latlng.lat, event.latlng.lng, true);
            });

            document.querySelectorAll('.payment-option').forEach(function (option) {
                option.addEventListener('click', function () {
                    const paymentMethod = option.dataset.payment;
                    document.querySelectorAll('.payment-option').forEach(function (item) {
                        item.classList.remove('selected');
                    });
                    option.classList.add('selected');
                    document.getElementById('payment').value = option.dataset.payment;
                    updateDiscountAvailability();
                });
            });

            orderForm.addEventListener('submit', function (event) {
                if (!checkoutStockAvailable) {
                    event.preventDefault();
                    alert('Please update or remove items with insufficient unexpired stock before placing your order.');
                    return;
                }
                if (voucherRequestPending) {
                    event.preventDefault();
                    alert('Please wait for the voucher check to finish.');
                    return;
                }
                if (voucherCodeInput.value.trim()
                    && voucherCodeInput.value.trim().toUpperCase() !== orderVoucherCode.value) {
                    event.preventDefault();
                    voucherStatus.textContent = 'Apply the voucher code before placing your order.';
                    voucherStatus.classList.remove('is-success');
                    voucherStatus.classList.add('is-error');
                    voucherCodeInput.focus();
                    return;
                }
                if (document.getElementById('delivery_type').value === 'lalamove' && !addressField.value.trim()) {
                    event.preventDefault();
                    alert('Please set or select a delivery address before placing your order.');
                    return;
                }

                if (document.getElementById('delivery_type').value === 'lalamove'
                    && (addressLookupPending || addressLookupFailed || !latitudeField.value || !longitudeField.value
                    || deliveryRoutePending || deliveryRoadDistanceKm === null
                    || deliveryFeeForDistance(deliveryRoadDistanceKm) === null)) {
                    event.preventDefault();
                    distanceNote.textContent = addressLookupPending || addressLookupFailed
                        ? 'Wait for the shipping address to finish updating from the map pin.'
                        : !latitudeField.value || !longitudeField.value
                            ? 'Pin your delivery location on the map before placing the order.'
                            : deliveryRoutePending || deliveryRoadDistanceKm === null
                                ? 'Wait for the road distance and delivery fee to finish calculating.'
                                : 'Delivery is unavailable beyond 10 km by road.';
                    distanceNote.classList.add('is-error');
                    return;
                }

                const selectedDiscount = document.querySelector('input[name="discount_type"]:checked');
                const discountIdUpload = document.getElementById('discount-id-file');
                const paymentMethod = document.getElementById('payment').value;
                if (selectedDiscount && paymentMethod !== 'cod') {
                    event.preventDefault();
                    alert('Senior Citizen and PWD discounts are only available with Cash on Delivery.');
                    return;
                }
                if (selectedDiscount && !discountIdUpload.files.length) {
                    event.preventDefault();
                    alert('Please upload your valid ' + (selectedDiscount.value === 'pwd' ? 'PWD' : 'Senior Citizen') + ' ID.');
                    discountIdUpload.focus();
                    return;
                }
                if (selectedDiscount && orderVoucherCode.value) {
                    event.preventDefault();
                    alert('Only one discount can be used per order.');
                    return;
                }

                if (!paymentMethod) {
                    event.preventDefault();
                    alert('Please choose a payment method first.');
                    return;
                }

                if (paymentMethod === 'gcash' || paymentMethod === 'bank') {
                    event.preventDefault();
                    const deliveryType = document.getElementById('delivery_type');
                    if (!deliveryType.value) {
                        alert('Please choose a delivery method first.');
                        return;
                    }

                    const paymentPage = paymentMethod === 'gcash' ? 'gcash_payment.php' : 'bank_payment.php';
                    const paymentForm = document.createElement('form');
                    paymentForm.method = 'POST';
                    paymentForm.action = paymentPage;
                    paymentForm.enctype = 'multipart/form-data';
                    paymentForm.style.display = 'none';

                    document.querySelectorAll('#order-form input[type="hidden"]').forEach(function (field) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = field.name;
                        input.value = field.value;
                        paymentForm.appendChild(input);
                    });

                    const deliveryInput = document.createElement('input');
                    deliveryInput.type = 'hidden';
                    deliveryInput.name = 'delivery_type';
                    deliveryInput.value = deliveryType.value;
                    paymentForm.appendChild(deliveryInput);

                    const paymentInput = document.createElement('input');
                    paymentInput.type = 'hidden';
                    paymentInput.name = 'payment_method';
                    paymentInput.value = paymentMethod;
                    paymentForm.appendChild(paymentInput);

                    const discountInput = document.createElement('input');
                    discountInput.type = 'hidden';
                    discountInput.name = 'discount_type';
                    discountInput.value = selectedDiscount ? selectedDiscount.value : '';
                    paymentForm.appendChild(discountInput);

                    if (selectedDiscount) {
                        if (typeof DataTransfer === 'undefined') {
                            alert('Your browser cannot forward the uploaded ID. Please use a current browser or choose Cash on Delivery.');
                            return;
                        }
                        const discountFileInput = document.createElement('input');
                        discountFileInput.type = 'file';
                        discountFileInput.name = 'discount_id_proof';
                        const fileTransfer = new DataTransfer();
                        fileTransfer.items.add(discountIdUpload.files[0]);
                        discountFileInput.files = fileTransfer.files;
                        paymentForm.appendChild(discountFileInput);
                    }

                    document.body.appendChild(paymentForm);
                    paymentForm.submit();
                }
            });

            const selectedPayment = <?= json_encode($selected_payment) ?>;
            if (selectedPayment) {
                const selectedOption = document.querySelector('.payment-option[data-payment="' + selectedPayment + '"]');
                if (selectedOption) {
                    selectedOption.classList.add('selected');
                    document.getElementById('payment').value = selectedPayment;
                }
            }

            const selectedDelivery = <?= json_encode($selected_delivery) ?>;
            if (selectedDelivery) {
                const selectedOption = document.querySelector('.delivery-option[data-delivery="' + selectedDelivery + '"]');
                if (selectedOption && !selectedOption.disabled) {
                    selectedOption.classList.add('selected');
                    document.getElementById('delivery_type').value = selectedDelivery;
                }
            }

            document.querySelectorAll('.delivery-option').forEach(function (option) {
                option.addEventListener('click', function () {
                    if (option.disabled) return;
                    document.querySelectorAll('.delivery-option').forEach(function (item) {
                        item.classList.remove('selected');
                    });
                    option.classList.add('selected');
                    document.getElementById('delivery_type').value = option.dataset.delivery;
                    applyDeliveryMethod(option.dataset.delivery);
                });
            });

            const discountIdPanel = document.getElementById('discount-id-panel');
            const discountTitle = document.getElementById('discount-id-title');
            const discountPrompt = document.getElementById('discount-id-prompt');
            const discountIdInput = document.getElementById('discount-id-file');
            const discountSection = document.querySelector('.discount-section');
            const discountRadioOptions = document.querySelectorAll('input[name="discount_type"]');
            function updateDiscountAvailability() {
                const discountAvailable = document.getElementById('payment').value === 'cod'
                    && !orderVoucherCode.value;
                discountSection.hidden = !discountAvailable;
                discountRadioOptions.forEach(function (option) {
                    option.disabled = !discountAvailable;
                    if (!discountAvailable) {
                        option.checked = false;
                        option.closest('.discount-option').classList.remove('selected');
                    }
                });
                if (!discountAvailable) {
                    discountIdInput.required = false;
                    discountIdInput.disabled = true;
                    discountIdInput.value = '';
                    discountIdPanel.hidden = true;
                } else {
                    discountIdInput.disabled = !document.querySelector('input[name="discount_type"]:checked');
                }
            }
            document.querySelectorAll('input[name="discount_type"]').forEach(function (option) {
                option.addEventListener('change', function () {
                    const selectedType = document.querySelector('input[name="discount_type"]:checked');
                    if (selectedType && orderVoucherCode.value) {
                        selectedType.checked = false;
                        voucherStatus.textContent = 'Remove the promo voucher before choosing a Senior Citizen/PWD discount.';
                        voucherStatus.classList.remove('is-success');
                        voucherStatus.classList.add('is-error');
                        updateDiscountAvailability();
                        return;
                    }
                    discountIdPanel.hidden = !selectedType;
                    if (!selectedType) {
                        discountIdInput.required = false;
                        discountIdInput.disabled = true;
                        return;
                    }
                    const isPwd = selectedType.value === 'pwd';
                    discountTitle.textContent = isPwd ? 'PWD DISCOUNT' : 'SENIOR CITIZEN DISCOUNT';
                    discountPrompt.textContent = isPwd
                        ? 'Please upload your valid PWD ID.'
                        : 'Please upload your valid Senior Citizen ID.';
                    discountIdInput.value = '';
                    discountIdInput.required = true;
                    discountIdInput.disabled = false;
                    document.querySelectorAll('.discount-option').forEach(function (label) {
                        label.classList.toggle('selected', label.contains(selectedType));
                    });
                });
            });
            updateDiscountAvailability();
            if (voucherCodeInput.value.trim()) {
                applyVoucher(voucherCodeInput.value.trim());
            }

            const savedAddress = document.getElementById('savedAddress');
            const nameField = document.getElementById('checkoutFullName');
            const phoneField = document.getElementById('checkoutPhone');
            const selectedAddressCard = document.getElementById('selectedAddressCard');
            const selectedAddressChevron = document.getElementById('selectedAddressChevron');
            const addressOptions = document.getElementById('savedAddress');
            const deliveryMethodInput = document.getElementById('delivery_type');
            function updateAddressRequirement() {
                const hasAddress = addressField.value.trim() !== '';
                addressRequiredNotice.hidden = hasAddress;
                if (!hasAddress) {
                    placeOrderButton.disabled = true;
                }
            }
            updateAddressRequirement();
            applyDeliveryMethod(deliveryMethodInput.value);
            selectedAddressCard.addEventListener('click', function (event) {
                if (event.target.closest('.address-chevron')) {
                    addressOptions.classList.toggle('is-open');
                    selectedAddressChevron.setAttribute('aria-expanded', addressOptions.classList.contains('is-open') ? 'true' : 'false');
                    return;
                }
                if (!event.target.closest('.address-text')) {
                    return;
                }
                addressOptions.classList.remove('is-open');
                selectedAddressChevron.setAttribute('aria-expanded', 'false');
                openDeliveryLocation(addressField.value, latitudeField.value, longitudeField.value);
                deliveryLocationPicker.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            });

            savedAddress.querySelectorAll('.address-option').forEach(function (option) {
                option.addEventListener('click', function () {
                    addressField.value = option.dataset.address;
                    latitudeField.value = '';
                    longitudeField.value = '';
                    if (destinationMarker) {
                        deliveryMap.removeLayer(destinationMarker);
                        destinationMarker = null;
                    }
                    updateAddressRequirement();
                    nameField.value = option.dataset.name || nameField.value;
                    phoneField.value = option.dataset.phone || phoneField.value;
                    selectedAddressCard.querySelector('.address-recipient').textContent = option.dataset.name || nameField.value;
                    selectedAddressCard.querySelector('.address-phone').textContent = option.dataset.phone || phoneField.value;
                    selectedAddressCard.querySelector('.address-text').textContent = option.dataset.address;
                    addressOptions.classList.remove('is-open');
                    selectedAddressChevron.setAttribute('aria-expanded', 'false');
                    deliveryLocationPicker.hidden = false;
                    if (!deliveryMethodInput.value) {
                        selectDeliveryOption('lalamove');
                    }
                    openDeliveryLocation(
                        option.dataset.address,
                        option.dataset.latitude,
                        option.dataset.longitude
                    );
                });

            });

            document.addEventListener('click', function (event) {
                if (!event.target.closest('.address-picker')) {
                    addressOptions.classList.remove('is-open');
                    selectedAddressChevron.setAttribute('aria-expanded', 'false');
                }
            });

        });
    </script>
</head>
<body>

<div class="receipt-card">
    <div class="header">
        <h2>ORDER SUMMARY</h2>
    </div>

    <div class="content">
        <?php if ($checkoutError !== ''): ?>
            <div role="alert" style="margin:0 0 18px;padding:12px 14px;border:1px solid #f1b8c0;border-radius:8px;background:#fff1f2;color:#8b1e2d;font-size:13px;line-height:1.5;">
                <?= htmlspecialchars($checkoutError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($checkoutStockIssues): ?>
            <div role="alert" style="margin:0 0 18px;padding:12px 14px;border:1px solid #f1b8c0;border-radius:8px;background:#fff1f2;color:#8b1e2d;font-size:13px;line-height:1.5;">
                <strong>Some items are unavailable:</strong>
                <ul style="margin:7px 0 0;padding-left:20px;">
                    <?php foreach ($checkoutStockIssues as $issue): ?>
                        <li><?= htmlspecialchars($issue['product_name'], ENT_QUOTES, 'UTF-8') ?> — requested <?= (int)$issue['required_quantity'] ?>, but only <?= (int)$issue['available_quantity'] ?> available from unexpired, non-recalled stock. Update the quantity or remove this item to continue.</li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="POST" action="place_order.php" enctype="multipart/form-data" id="order-form">
            <input type="hidden" name="fullname" id="checkoutFullName" value="<?= htmlspecialchars($selected_name) ?>">
            <input type="hidden" name="contact_number" id="checkoutPhone" value="<?= htmlspecialchars($selected_phone) ?>">
            <input type="hidden" name="coupon_code" id="checkout-voucher-code" value="">
                <input type="hidden" name="delivery_latitude" id="delivery_latitude" value="<?= htmlspecialchars($selected_delivery_latitude, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="delivery_longitude" id="delivery_longitude" value="<?= htmlspecialchars($selected_delivery_longitude, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($buy_now_product_id > 0): ?>
                <input type="hidden" name="buy_now_product_id" value="<?= $buy_now_product_id ?>">
                <input type="hidden" name="buy_now_quantity" value="<?= $buy_now_quantity ?>">
                <input type="hidden" name="buy_now_unit" value="<?= htmlspecialchars($buy_now_unit) ?>">
            <?php endif; ?>
            
            <div class="info-section checkout-address-section" id="checkout-address-section" <?= $selected_delivery === 'lalamove' ? '' : 'hidden' ?>>
                <div class="address-picker">
                    <div class="selected-address" id="selectedAddressCard">
                        <span class="address-pin">📍</span>
                        <span class="address-details">
                            <span class="address-label">SHIPPING ADDRESS</span>
                            <span class="address-recipient"><?= htmlspecialchars($selected_name) ?></span>
                            <span class="address-phone"><?= htmlspecialchars($selected_phone) ?></span>
                            <button type="button" class="address-text"><?= htmlspecialchars($selected_address ?: 'No delivery address selected') ?></button>
                        </span>
                        <button type="button" class="address-chevron" id="selectedAddressChevron" aria-expanded="false" aria-controls="savedAddress" aria-label="Choose a saved address">›</button>
                    </div>

                    <div class="address-options" id="savedAddress" aria-label="Choose delivery address">
                    <?php if (trim((string) $address) !== ''): ?>
                        <button type="button" class="address-option" data-address="<?= htmlspecialchars($address, ENT_QUOTES) ?>" data-name="<?= htmlspecialchars($username, ENT_QUOTES) ?>" data-phone="<?= htmlspecialchars($phone, ENT_QUOTES) ?>">
                            <span class="address-pin">📍</span><span class="address-details"><span class="address-recipient"><?= htmlspecialchars($username) ?></span><span class="address-phone"><?= htmlspecialchars($phone) ?></span><span class="address-text"><?= htmlspecialchars($address) ?></span></span><span class="address-chevron">›</span>
                        </button>
                    <?php endif; ?>
                    <?php foreach ($saved_addresses as $saved): ?>
                        <?php $saved_name = trim((string) ($saved['recipient_name'] ?? '')) ?: $username; $saved_phone = trim((string) ($saved['phone'] ?? '')) ?: $phone; ?>
                        <button type="button" class="address-option" data-address="<?= htmlspecialchars($saved['address'], ENT_QUOTES, 'UTF-8') ?>" data-name="<?= htmlspecialchars($saved_name, ENT_QUOTES, 'UTF-8') ?>" data-phone="<?= htmlspecialchars($saved_phone, ENT_QUOTES, 'UTF-8') ?>" data-latitude="<?= htmlspecialchars((string) ($saved['latitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-longitude="<?= htmlspecialchars((string) ($saved['longitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <span class="address-pin">📍</span><span class="address-details"><span class="address-recipient"><?= htmlspecialchars($saved_name) ?></span><span class="address-phone"><?= htmlspecialchars($saved_phone) ?></span><span class="address-text"><?= htmlspecialchars($saved['address']) ?></span></span><span class="address-chevron">›</span>
                        </button>
                    <?php endforeach; ?>
                        <a href="settings.php?tab=addresses" class="add-address-link">+ Add another delivery address</a>
                    </div>
                </div>
                <input type="hidden" name="address" id="checkoutAddress" value="<?= htmlspecialchars($selected_address) ?>">
                <p class="address-required-notice" id="address-required-notice" role="alert" <?= trim((string)$selected_address) !== '' ? 'hidden' : '' ?>>
                    Please set a delivery address before placing your order.
                    <a href="settings.php?tab=addresses">Add an address in your account settings</a>, then return to checkout.
                </p>
            </div>
            <div class="delivery-location-picker" id="delivery-location-picker" <?= $selected_delivery === 'lalamove' ? '' : 'hidden' ?>>
                <h3>Delivery location</h3>
                <p>Store: <?= htmlspecialchars(PHARMACY_STORE_ADDRESS, ENT_QUOTES, 'UTF-8') ?>. Click the map or drag the pin to set your destination.</p>
                <div id="delivery-location-map" aria-label="Set delivery location on the map"></div>
                <p class="delivery-distance-note" id="delivery-distance-note" aria-live="polite">Choose Rider delivery, then pin your delivery location.</p>
                <p class="delivery-route-note" id="delivery-route-note" aria-live="polite"></p>
                <p class="delivery-address-note" id="delivery-address-note" aria-live="polite"></p>
            </div>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">

            <table class="order-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Qty</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $total = 0;
                if ($checkoutRows) {
                    foreach ($checkoutRows as $row) {
                        $box = (int)$row['box'];
                        $pieces = (int)$row['pieces'];
                        $price_box = (float)$row['price_box'];
                        $price_piece = (float)$row['price_piece'];

                        $box_total = $box * $price_box;
                        $piece_total = $pieces * $price_piece;
                        $sub = $box_total + $piece_total;
                        $total += $sub;

                        $image = $row['image'] ?? '';
                        $image = (strpos($image, 'http') === 0)
                            ? $image
                            : 'uploads/' . ltrim($image, '/');
                        $quantity = $box + $pieces;
                        $name = htmlspecialchars($row['product_name']);
                        $image = htmlspecialchars($image);
                        $selected_unit_price = $buy_now_unit === 'bottle' ? $price_box : $price_piece;
                        $price = number_format($selected_unit_price, 2);
                        $subtotal = number_format($sub, 2);

                        echo "<tr class='item-row'>
                                <td>
                                    <div class='item-product'>
                                        <img class='item-image' src='$image' alt='$name'>
                                        <div>
                                            <div class='item-name'>$name</div>
                                            <div class='item-details'>";
                        if ($box > 0) {
                            echo "Box: $box";
                        }
                        if ($box > 0 && $pieces > 0) {
                            echo " · ";
                        }
                        if ($pieces > 0) {
                            echo "Pieces: $pieces";
                        }
                        echo "</div>
                                        </div>
                                    </div>
                                </td>
                                <td class='item-price'>₱$price</td>
                                <td class='item-qty'>× $quantity</td>
                                <td class='item-subtotal'>₱$subtotal</td>
                              </tr>";
                    }
                }
                ?>
                </tbody>
            </table>

            <div class="summary">
                <div class="checkout-total-line">
                    <span>Items Subtotal</span>
                    <strong>₱<?= number_format($total, 2) ?></strong>
                </div>
                <div class="checkout-total-line" id="checkout-delivery-fee-row" <?= $selected_delivery_active ? '' : 'hidden' ?>>
                    <span>Delivery Fee</span>
                    <strong id="checkout-delivery-fee"><?= $selected_delivery_active ? '—' : '₱0.00' ?></strong>
                </div>
                <div class="checkout-total-line voucher-discount-line" id="voucher-discount-row" hidden>
                    <span>Voucher Discount</span>
                    <strong id="voucher-discount-value">−₱0.00</strong>
                </div>
                <div class="total-row">
                    <span><span class="total-icon">₱</span>Total Amount Payable</span>
                    <span class="total-amount" id="checkout-total-amount">₱<?= number_format($total, 2) ?></span>
                </div>
            </div>
            <input type="hidden" name="order_total" id="checkout-order-total" value="<?= number_format($total, 2, '.', '') ?>">

            <section class="voucher-section" aria-labelledby="voucher-heading">
                <div class="voucher-toggle-row">
                    <h3 id="voucher-heading">Add voucher code</h3>
                    <label class="voucher-toggle">
                        <input type="checkbox" id="voucher-toggle-input" aria-label="Toggle voucher code" <?= $selectedVoucherCode !== '' ? 'checked' : '' ?>>
                        <span class="voucher-toggle-track"></span>
                    </label>
                </div>
                <div class="voucher-entry" id="voucher-entry" <?= $selectedVoucherCode === '' ? 'hidden' : '' ?>>
                    <input type="text" id="voucher-code-input" value="<?= htmlspecialchars($selectedVoucherCode, ENT_QUOTES, 'UTF-8') ?>" maxlength="50" placeholder="Enter code" autocomplete="off" aria-label="Voucher code">
                    <div class="voucher-actions">
                        <button type="button" id="voucher-apply-button">Apply</button>
                        <button type="button" id="voucher-remove-button" class="voucher-remove-button" hidden>Remove</button>
                    </div>
                </div>
                <p class="voucher-status" id="voucher-status" role="status" aria-live="polite"></p>
            </section>

            <div class="checkout-section">
                <label>DELIVERY METHOD</label>
                <input type="hidden" name="delivery_type" id="delivery_type" value="" required>
                <div class="delivery-options" aria-label="Choose delivery method">
                    <button type="button" class="delivery-option" data-delivery="lalamove" <?= $riderAvailability['available'] ? '' : 'disabled title="Rider delivery is currently unavailable."' ?>><span>Rider</span></button>
                    <button type="button" class="delivery-option" data-delivery="pickup"><span>Self-Pickup</span></button>
                </div>
                <p id="rider-availability-note" class="delivery-distance-note is-error" role="status" aria-live="polite" <?= $riderAvailability['available'] ? 'hidden' : '' ?>>
                    <?= $riderAvailability['error'] !== null
                        ? 'Unable to confirm rider availability right now. Please choose Self-Pickup or try again.'
                        : 'No rider is online right now. Please choose Self-Pickup.' ?>
                </p>
            </div>

            <div class="checkout-section">
                <label>PAYMENT METHOD</label>
                <input type="hidden" name="payment_method" id="payment" value="" required>
                <div class="payment-options" id="paymentOptions" aria-label="Choose payment method">
                    <button type="button" class="payment-option" data-payment="cod"><span>Cash</span></button>
                    <button type="button" class="payment-option" data-payment="gcash"><span>GCash Transfer</span></button>
                    <button type="button" class="payment-option" data-payment="bank"><span>Bank Transfer (BPI)</span></button>
                </div>
            </div>

            <div class="discount-section" hidden>
                <label style="font-size: 11px; font-weight: bold; color: #888;">DO YOU WANT TO APPLY A DISCOUNT? (OPTIONAL)</label>
                <?php if ($discountCooldownActive): ?>
                    <p class="discount-id-note">You have already used a discount. It will be available again on <?= htmlspecialchars($discountCooldownExpiry, ENT_QUOTES, 'UTF-8') ?>.</p>
                <?php else: ?>
                    <div class="discount-options" role="radiogroup" aria-label="Optional discount">
                        <label class="discount-option">
                            <input type="radio" name="discount_type" value="senior_citizen">
                            <span>Senior Citizen</span>
                        </label>
                        <label class="discount-option">
                            <input type="radio" name="discount_type" value="pwd">
                            <span>PWD</span>
                        </label>
                    </div>
                <?php endif; ?>
                <section class="discount-id-panel" id="discount-id-panel" hidden aria-live="polite">
                    <h3 id="discount-id-title"></h3>
                    <p id="discount-id-prompt"></p>
                    <input class="discount-id-file" type="file" id="discount-id-file" name="discount_id_proof" accept="image/jpeg,image/png" aria-label="Choose a valid Senior Citizen or PWD ID image">
                </section>
            </div>

            <button type="submit" class="btn-confirm" id="place-order-button" <?= trim((string)$selected_address) === '' || $checkoutStockIssues ? 'disabled' : '' ?>>PLACE ORDER NOW</button>
        </form>
    </div>
</div>

</body>
</html>