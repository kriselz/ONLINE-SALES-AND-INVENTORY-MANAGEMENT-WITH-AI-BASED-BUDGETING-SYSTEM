<?php
session_start();
require_once 'config.php';
$isAjaxRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjaxRequest) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Mag-login muna para magdagdag sa cart.']);
        exit();
    }
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

$user_id = (int)$_SESSION['user_id'];
$product_id = (int)($_POST['product_id'] ?? 0);
$quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
$unit = ($_POST['unit'] ?? 'piece') === 'bottle' ? 'bottle' : 'piece';
if ($product_id <= 0 || $quantity === false || $quantity === null || $quantity < 1) {
    http_response_code(400);
    exit('Please choose a valid product quantity.');
}

function returnToProductWithStockError(int $productId, string $message): void {
    global $isAjaxRequest;
    if ($isAjaxRequest) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message]);
        exit();
    }
    $_SESSION['product_stock_error_product_id'] = $productId;
    $_SESSION['product_stock_error'] = $message;
    header('Location: view_product.php?id=' . $productId);
    exit();
}

$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS prescription_required TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("CREATE TABLE IF NOT EXISTS prescription_product_access (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    granted_by INT NULL,
    prescription_id INT NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    quantity_limit INT UNSIGNED NOT NULL DEFAULT 0,
    quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_prescription_product_user (product_id, user_id),
    KEY idx_prescription_access_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS prescription_id INT NULL");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_limit INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("UPDATE prescription_product_access ppa
    JOIN products p ON p.id = ppa.product_id
    SET ppa.quantity_limit = GREATEST(COALESCE(p.quantity, 0), 0), ppa.quantity_purchased = 0, ppa.expires_at = NULL
    WHERE ppa.quantity_limit = 0 AND ppa.expires_at IS NOT NULL");

if (!$conn->begin_transaction()) {
    error_log('Add-to-cart transaction could not start: ' . $conn->error);
    http_response_code(503);
    exit('Unable to check current stock. Please try again.');
}

$productStmt = $conn->prepare(
    'SELECT status, pieces, box, quantity, pieces_per_box, prescription_required, category
     FROM products WHERE id = ? FOR UPDATE'
);
if (!$productStmt) {
    $conn->rollback();
    error_log('Add-to-cart product query preparation failed: ' . $conn->error);
    http_response_code(503);
    exit('Unable to check current stock. Please try again.');
}
$productStmt->bind_param('i', $product_id);
if (!$productStmt->execute()) {
    $error = $productStmt->error;
    $productStmt->close();
    $conn->rollback();
    error_log('Add-to-cart product query failed: ' . $error);
    http_response_code(503);
    exit('Unable to check current stock. Please try again.');
}
$product = $productStmt->get_result()->fetch_assoc();
$productStmt->close();
if (!$product) {
    $conn->rollback();
    http_response_code(404);
    exit('Product not found.');
}
if (strtolower(trim((string)$product['status'])) !== 'active') {
    $conn->rollback();
    returnToProductWithStockError($product_id, 'Hindi available ang produktong ito para i-order ngayon.');
}

$isPrescriptionProduct = !empty($product['prescription_required'])
    || strtolower(trim((string)($product['category'] ?? ''))) === 'prescription medicine';
$prescriptionQuantityRemaining = PHP_INT_MAX;
if ($isPrescriptionProduct) {
    $accessStmt = $conn->prepare(
        'SELECT quantity_limit, quantity_purchased FROM prescription_product_access
         WHERE product_id = ? AND user_id = ? AND quantity_limit > quantity_purchased
           AND (expires_at IS NULL OR expires_at >= NOW())
         ORDER BY granted_at DESC LIMIT 1 FOR UPDATE'
    );
    if (!$accessStmt) {
        $conn->rollback();
        error_log('Add-to-cart prescription query preparation failed: ' . $conn->error);
        http_response_code(503);
        exit('Unable to verify prescription access. Please try again.');
    }
    $accessStmt->bind_param('ii', $product_id, $user_id);
    if (!$accessStmt->execute()) {
        $error = $accessStmt->error;
        $accessStmt->close();
        $conn->rollback();
        error_log('Add-to-cart prescription query failed: ' . $error);
        http_response_code(503);
        exit('Unable to verify prescription access. Please try again.');
    }
    $prescriptionAccess = $accessStmt->get_result()->fetch_assoc();
    $accessStmt->close();
    if (!$prescriptionAccess) {
        $conn->rollback();
        returnToProductWithStockError($product_id, 'Nag-expire na ang access o naabot na ang quantity limit para sa prescription product na ito. Makipag-ugnayan sa pharmacy.');
    }
    $prescriptionQuantityRemaining = max(0, (int)$prescriptionAccess['quantity_limit'] - (int)$prescriptionAccess['quantity_purchased']);
}

$piecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
$availableStock = max(
    0,
    ((int)($product['box'] ?? 0) * $piecesPerBox) + (int)($product['pieces'] ?? 0)
);
$batchStmt = $conn->prepare(
    'SELECT current_quantity, expiration_date, recalled
     FROM product_batches WHERE product_id = ? ORDER BY expiration_date ASC, id ASC FOR UPDATE'
);
if (!$batchStmt) {
    $conn->rollback();
    error_log('Add-to-cart batch query preparation failed: ' . $conn->error);
    http_response_code(503);
    exit('Unable to verify current batch stock. Please try again.');
}
$batchStmt->bind_param('i', $product_id);
if (!$batchStmt->execute()) {
    $error = $batchStmt->error;
    $batchStmt->close();
    $conn->rollback();
    error_log('Add-to-cart batch query failed: ' . $error);
    http_response_code(503);
    exit('Unable to verify current batch stock. Please try again.');
}
$batchResult = $batchStmt->get_result();
$hasBatches = false;
$sellableBatchStock = 0;
while ($batch = $batchResult->fetch_assoc()) {
    $hasBatches = true;
    if ((int)$batch['recalled'] === 0
        && $batch['expiration_date'] >= date('Y-m-d')
        && (int)$batch['current_quantity'] > 0) {
        $sellableBatchStock += (int)$batch['current_quantity'];
    }
}
$batchStmt->close();
if ($hasBatches) {
    $availableStock = min($availableStock, $sellableBatchStock);
}

$cartStmt = $conn->prepare('SELECT box, pieces FROM cart WHERE user_id = ? AND product_id = ? FOR UPDATE');
if (!$cartStmt) {
    $conn->rollback();
    error_log('Add-to-cart cart query preparation failed: ' . $conn->error);
    http_response_code(503);
    exit('Unable to check your cart. Please try again.');
}
$cartStmt->bind_param('ii', $user_id, $product_id);
if (!$cartStmt->execute()) {
    $error = $cartStmt->error;
    $cartStmt->close();
    $conn->rollback();
    error_log('Add-to-cart cart query failed: ' . $error);
    http_response_code(503);
    exit('Unable to check your cart. Please try again.');
}
$existingCartItem = $cartStmt->get_result()->fetch_assoc();
$cartStmt->close();
$cartQuantity = $existingCartItem
    ? ((int)($existingCartItem['box'] ?? 0) * $piecesPerBox) + (int)($existingCartItem['pieces'] ?? 0)
    : 0;
$requestedStock = $quantity * ($unit === 'bottle' ? $piecesPerBox : 1);
$remainingStock = max(0, $availableStock - $cartQuantity);
if ($availableStock < 1 || $requestedStock > $remainingStock) {
    $conn->rollback();
    $remainingUnits = $unit === 'bottle'
        ? intdiv($remainingStock, $piecesPerBox)
        : $remainingStock;
    $unitLabel = $unit === 'bottle' ? 'kahon' : 'piraso';
    returnToProductWithStockError(
        $product_id,
        $remainingUnits > 0
            ? 'Kulang ang stock para sa quantity na pinili. ' . $remainingUnits . ' ' . $unitLabel . ' na lang ang puwedeng idagdag sa cart.'
            : 'Ubos na ang natitirang stock para sa produktong ito. Bawasan o alisin ito sa cart bago magpatuloy.'
    );
}
if ($isPrescriptionProduct && ($cartQuantity + $requestedStock) > $prescriptionQuantityRemaining) {
    $conn->rollback();
    $remainingPrescriptionQuantity = max(0, $prescriptionQuantityRemaining - $cartQuantity);
    returnToProductWithStockError(
        $product_id,
        $remainingPrescriptionQuantity > 0
            ? 'Ang natitirang quantity para sa prescription mo ay ' . $remainingPrescriptionQuantity . ' piraso lamang.'
            : 'Naabot na ang quantity limit na itinakda ng pharmacist para sa prescription na ito.'
    );
}

if ($existingCartItem) {
    $column = $unit === 'bottle' ? 'box' : 'pieces';
    $update = $conn->prepare("UPDATE cart SET $column = $column + ? WHERE user_id = ? AND product_id = ?");
    if (!$update) {
        $conn->rollback();
        error_log('Add-to-cart update preparation failed: ' . $conn->error);
        http_response_code(503);
        exit('Unable to update your cart. Please try again.');
    }
    $update->bind_param('iii', $quantity, $user_id, $product_id);
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        $conn->rollback();
        error_log('Add-to-cart update failed: ' . $error);
        http_response_code(503);
        exit('Unable to update your cart. Please try again.');
    }
    $update->close();
} else {
    $boxes = $unit === 'bottle' ? $quantity : 0;
    $pieces = $unit === 'piece' ? $quantity : 0;
    $insert = $conn->prepare('INSERT INTO cart (user_id, product_id, box, pieces) VALUES (?, ?, ?, ?)');
    if (!$insert) {
        $conn->rollback();
        error_log('Add-to-cart insert preparation failed: ' . $conn->error);
        http_response_code(503);
        exit('Unable to add this product to your cart. Please try again.');
    }
    $insert->bind_param('iiii', $user_id, $product_id, $boxes, $pieces);
    if (!$insert->execute()) {
        $error = $insert->error;
        $insert->close();
        $conn->rollback();
        error_log('Add-to-cart insert failed: ' . $error);
        http_response_code(503);
        exit('Unable to add this product to your cart. Please try again.');
    }
    $insert->close();
}

if (!$conn->commit()) {
    $conn->rollback();
    error_log('Add-to-cart transaction commit failed: ' . $conn->error);
    http_response_code(503);
    exit('Unable to save your cart. Please try again.');
}

if ($isAjaxRequest) {
    $cartCountResult = $conn->query("SELECT COALESCE(SUM(COALESCE(box, 0) + COALESCE(pieces, 0)), 0) AS total_items FROM cart WHERE user_id = $user_id");
    if (!$cartCountResult) {
        error_log('Add-to-cart count query failed: ' . $conn->error);
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Naidagdag ang produkto, pero hindi ma-refresh ang cart count. I-refresh ang page para makita ang update.']);
        exit();
    }
    $cartCount = (int)$cartCountResult->fetch_assoc()['total_items'];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'cart_count' => $cartCount]);
    exit();
}

header('Location: cart_screen.php');
exit();
?>
