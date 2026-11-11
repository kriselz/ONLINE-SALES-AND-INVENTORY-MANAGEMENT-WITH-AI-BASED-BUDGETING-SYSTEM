<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/access_control.php';

requireOwnerPageAccess($conn, true);

$staffId = (int)($_SESSION['user_id'] ?? 0);
$error = '';
$csrfToken = $_SESSION['physical_pos_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['physical_pos_csrf'] = $csrfToken;

$batchSchemas = [
    "CREATE TABLE IF NOT EXISTS product_batches (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        lot_number VARCHAR(100) NOT NULL,
        expiration_date DATE NOT NULL,
        received_quantity INT UNSIGNED NOT NULL DEFAULT 0,
        current_quantity INT UNSIGNED NOT NULL DEFAULT 0,
        recalled TINYINT(1) NOT NULL DEFAULT 0,
        recall_reason VARCHAR(255) NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_batch_expiration (expiration_date),
        KEY idx_batch_recalled (recalled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS batch_movements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        batch_id INT UNSIGNED NULL,
        product_id INT NOT NULL,
        order_id INT NULL,
        quantity INT NOT NULL,
        movement_type ENUM('received', 'sold', 'adjusted', 'recalled', 'returned') NOT NULL,
        reference VARCHAR(100) NULL,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_batch_movement_batch (batch_id),
        KEY idx_batch_movement_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($batchSchemas as $batchSchema) {
    if (!$conn->query($batchSchema)) {
        error_log('Physical POS inventory schema preparation failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to prepare inventory batch records.');
    }
}
if (!$conn->query('ALTER TABLE batch_movements MODIFY batch_id INT UNSIGNED NULL')) {
    error_log('Physical POS movement schema preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare inventory movement records.');
}

$posSalesSchemas = [
    "CREATE TABLE IF NOT EXISTS pos_sales (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(100) NOT NULL DEFAULT 'Onsite Customer',
        subtotal_amount DECIMAL(10,2) NOT NULL,
        discount_type VARCHAR(30) NULL,
        discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_amount DECIMAL(10,2) NOT NULL,
        payment_reference VARCHAR(255) NOT NULL,
        cashier_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pos_sales_cashier (cashier_id),
        KEY idx_pos_sales_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS pos_sale_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_id INT UNSIGNED NOT NULL,
        product_id INT NULL,
        product_name VARCHAR(255) NOT NULL,
        category VARCHAR(100) NULL,
        quantity INT UNSIGNED NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        total_price DECIMAL(10,2) NOT NULL,
        KEY idx_pos_sale_items_sale (sale_id),
        CONSTRAINT fk_pos_sale_items_sale FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS pos_sale_discount_ids (
        sale_id INT UNSIGNED NOT NULL PRIMARY KEY,
        image_data LONGBLOB NOT NULL,
        image_mime VARCHAR(50) NOT NULL,
        uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_pos_sale_discount_ids_sale FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($posSalesSchemas as $posSalesSchema) {
    if (!$conn->query($posSalesSchema)) {
        error_log('Physical POS sale schema preparation failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to prepare POS sale records.');
    }
}
if (!$conn->query('ALTER TABLE batch_movements ADD COLUMN IF NOT EXISTS pos_sale_id INT UNSIGNED NULL')) {
    error_log('Physical POS sale movement schema preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare POS batch sales.');
}
$posSaleMovementIndex = $conn->query("SHOW INDEX FROM batch_movements WHERE Key_name = 'idx_batch_movement_pos_sale'");
if (!$posSaleMovementIndex || ($posSaleMovementIndex->num_rows === 0
    && !$conn->query('ALTER TABLE batch_movements ADD KEY idx_batch_movement_pos_sale (pos_sale_id)'))) {
    error_log('Physical POS sale movement index preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare POS batch sales.');
}

$skuColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'sku'");
if (!$skuColumnResult) {
    error_log('Physical POS SKU column inspection failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to inspect product SKU data.');
}
$hasSku = $skuColumnResult->num_rows > 0;
$barcodeColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'barcode'");
if (!$barcodeColumnResult) {
    error_log('Physical POS barcode column inspection failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to inspect product barcode data.');
}
if ($barcodeColumnResult->num_rows === 0 && !$conn->query('ALTER TABLE products ADD COLUMN barcode VARCHAR(100) NULL')) {
    error_log('Physical POS barcode column preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare product barcode data.');
}
$categoryColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'category'");
if (!$categoryColumnResult) {
    error_log('Physical POS category column inspection failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to inspect product categories.');
}
if ($categoryColumnResult->num_rows === 0 && !$conn->query('ALTER TABLE products ADD COLUMN category VARCHAR(100) NULL')) {
    error_log('Physical POS category column preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare product categories.');
}

$search = trim((string)($_GET['search'] ?? ''));
$safeSearch = '%' . $search . '%';
$stockExpression = '(COALESCE(p.pieces, 0) + (COALESCE(p.box, 0) * COALESCE(NULLIF(p.pieces_per_box, 0), 12)))';
$skuExpression = $hasSku ? "COALESCE(p.sku, '')" : "''";
$productSql = "SELECT p.id, p.product_name, COALESCE(p.category, '') AS category, $skuExpression AS sku, COALESCE(p.barcode, '') AS barcode,
        COALESCE(p.image, '') AS image, COALESCE(NULLIF(p.price_piece, 0), p.price, 0) AS unit_price,
        CASE WHEN COALESCE(batch_stock.batch_count, 0) > 0
            THEN batch_stock.available_batch_stock ELSE $stockExpression END AS available_stock,
        COALESCE(batch_stock.lot_numbers, '') AS lot_numbers
    FROM products p
    LEFT JOIN (
        SELECT product_id,
            SUM(CASE WHEN recalled = 0 AND expiration_date >= CURDATE() THEN current_quantity ELSE 0 END) AS available_batch_stock,
            GROUP_CONCAT(CASE WHEN recalled = 0 AND expiration_date >= CURDATE() AND current_quantity > 0
                THEN lot_number END ORDER BY expiration_date ASC, id ASC SEPARATOR ', ') AS lot_numbers,
            COUNT(*) AS batch_count
        FROM product_batches
        GROUP BY product_id
    ) batch_stock ON batch_stock.product_id = p.id
    WHERE LOWER(COALESCE(p.status, 'active')) = 'active'
      AND (? = '' OR p.product_name LIKE ? OR $skuExpression LIKE ? OR COALESCE(p.barcode, '') LIKE ?
          OR EXISTS (
              SELECT 1 FROM product_batches search_batch
              WHERE search_batch.product_id = p.id AND search_batch.lot_number LIKE ?
          ))
    ORDER BY p.product_name ASC
    LIMIT 200";
$productStmt = $conn->prepare($productSql);
if (!$productStmt) {
    error_log('Physical POS product list preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load the product catalog.');
}
$productStmt->bind_param('sssss', $search, $safeSearch, $safeSearch, $safeSearch, $safeSearch);
if (!$productStmt->execute()) {
    error_log('Physical POS product list failed: ' . $productStmt->error);
    http_response_code(500);
    exit('Unable to load the product catalog.');
}
$products = $productStmt->get_result();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $error = 'This checkout session expired. Refresh the page and try again.';
    } else {
        $cartInput = $_POST['cart'] ?? null;
        $cart = [];
        $discountType = strtolower(trim((string)($_POST['discount_type'] ?? '')));
        $discountIdImage = null;
        $discountIdMime = '';
        if (!in_array($discountType, ['', 'senior_citizen', 'pwd'], true)) {
            $error = 'Choose a valid Senior Citizen or PWD discount type.';
        } elseif ($discountType !== '') {
            $discountIdFile = $_FILES['discount_id_proof'] ?? null;
            if (!is_array($discountIdFile)
                || ($discountIdFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !isset($discountIdFile['tmp_name'])
                || !is_uploaded_file($discountIdFile['tmp_name'])) {
                $error = 'Upload a valid Senior Citizen or PWD ID to apply the discount.';
            } elseif ((int)($discountIdFile['size'] ?? 0) < 1 || (int)$discountIdFile['size'] > 5 * 1024 * 1024) {
                $error = 'The discount ID image must be a JPG or PNG up to 5 MB.';
            } else {
                $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                $discountIdMime = $fileInfo ? (string)finfo_file($fileInfo, $discountIdFile['tmp_name']) : '';
                if ($fileInfo) finfo_close($fileInfo);
                if (!in_array($discountIdMime, ['image/jpeg', 'image/png'], true)) {
                    $error = 'The discount ID must be a JPG or PNG image.';
                } else {
                    $discountIdImage = file_get_contents($discountIdFile['tmp_name']);
                    if (!is_string($discountIdImage) || $discountIdImage === '') {
                        $error = 'Unable to read the uploaded discount ID image.';
                    }
                }
            }
        }
        $cashReceivedInput = trim((string)($_POST['cash_received'] ?? ''));
        $cashReceived = 0.0;
        if ($error === '' && ($cashReceivedInput === '' || !is_numeric($cashReceivedInput)
            || !is_finite((float)$cashReceivedInput) || (float)$cashReceivedInput < 0)) {
            $error = 'Enter a valid cash amount received.';
        } elseif ($error === '') {
            $cashReceived = round((float)$cashReceivedInput, 2);
        }
        if (!is_array($cartInput) || count($cartInput) < 1 || count($cartInput) > 100) {
            if ($error === '') {
                $error = 'Add at least one product to the cart.';
            }
        } else {
            foreach ($cartInput as $productIdInput => $quantityInput) {
                $productIdString = (string)$productIdInput;
                $quantityString = is_scalar($quantityInput) ? trim((string)$quantityInput) : '';
                if (!ctype_digit($productIdString) || (int)$productIdString < 1
                    || !ctype_digit($quantityString) || (int)$quantityString < 1) {
                    $error = 'The cart has an invalid product or quantity.';
                    break;
                }
                $cart[(int)$productIdString] = (int)$quantityString;
            }
        }

        if ($error === '') {
            ksort($cart, SORT_NUMERIC);
            try {
                if (!$conn->begin_transaction()) {
                    throw new RuntimeException('Unable to start the checkout transaction. Please try again.');
                }
                $lockedProducts = [];
                $productLock = $conn->prepare("SELECT id, product_name, COALESCE(NULLIF(price_piece, 0), price, 0) AS unit_price,
                        COALESCE(category, '') AS category,
                        COALESCE(pieces, 0) AS pieces, COALESCE(box, 0) AS boxes,
                        COALESCE(NULLIF(pieces_per_box, 0), 12) AS pieces_per_box,
                        LOWER(COALESCE(status, 'active')) AS product_status
                    FROM products WHERE id = ? FOR UPDATE");
                if (!$productLock) {
                    throw new RuntimeException('Unable to prepare product stock checks: ' . $conn->error);
                }
                foreach ($cart as $productId => $quantity) {
                    $productLock->bind_param('i', $productId);
                    if (!$productLock->execute()) {
                        throw new RuntimeException('Unable to verify product stock: ' . $productLock->error);
                    }
                    $product = $productLock->get_result()->fetch_assoc();
                    if (!$product || $product['product_status'] !== 'active') {
                        throw new RuntimeException('A selected product is no longer available for sale.');
                    }
                    if ((float)$product['unit_price'] <= 0) {
                        throw new RuntimeException($product['product_name'] . ' does not have a valid piece price.');
                    }
                    $piecesPerBox = max(1, (int)$product['pieces_per_box']);
                    $availableStock = (int)$product['pieces'] + ((int)$product['boxes'] * $piecesPerBox);
                    if ($quantity > $availableStock) {
                        throw new RuntimeException($product['product_name'] . ' only has ' . $availableStock . ' pieces available.');
                    }
                    $product['pieces_per_box'] = $piecesPerBox;
                    $product['available_stock'] = $availableStock;
                    $product['requested_quantity'] = $quantity;
                    $lockedProducts[$productId] = $product;
                }
                $productLock->close();

                $batchLock = $conn->prepare("SELECT id, current_quantity, recalled, expiration_date
                    FROM product_batches
                    WHERE product_id = ?
                    ORDER BY expiration_date ASC, id ASC
                    FOR UPDATE");
                if (!$batchLock) {
                    throw new RuntimeException('Unable to prepare batch stock checks: ' . $conn->error);
                }
                $saleAllocations = [];
                $totalAmount = 0.0;
                $discountableSubtotal = 0.0;
                foreach ($lockedProducts as $productId => $product) {
                    $quantity = (int)$product['requested_quantity'];
                    $lineTotal = round((float)$product['unit_price'] * $quantity, 2);
                    $totalAmount += $lineTotal;
                    if (stripos((string)$product['category'], 'medicine') !== false) {
                        $discountableSubtotal += $lineTotal;
                    }
                    $batchLock->bind_param('i', $productId);
                    if (!$batchLock->execute()) {
                        throw new RuntimeException('Unable to check expiring stock: ' . $batchLock->error);
                    }
                    $batchResult = $batchLock->get_result();
                    $batches = [];
                    $hasBatchRecords = false;
                    $today = date('Y-m-d');
                    while ($batch = $batchResult->fetch_assoc()) {
                        $hasBatchRecords = true;
                        if ((int)$batch['recalled'] === 0
                            && $batch['expiration_date'] >= $today
                            && (int)$batch['current_quantity'] > 0) {
                            $batches[] = $batch;
                        }
                    }
                    $batchAllocations = [];
                    $remaining = $quantity;
                    foreach ($batches as $batch) {
                        if ($remaining === 0) {
                            break;
                        }
                        $batchQuantity = min($remaining, (int)$batch['current_quantity']);
                        $batchAllocations[] = ['batch_id' => (int)$batch['id'], 'quantity' => $batchQuantity];
                        $remaining -= $batchQuantity;
                    }
                    if ($remaining > 0 && $hasBatchRecords) {
                        throw new RuntimeException($product['product_name'] . ' does not have enough unexpired, non-recalled batch stock.');
                    }
                    if (!$hasBatchRecords && $product['available_stock'] < $quantity) {
                        throw new RuntimeException($product['product_name'] . ' no longer has enough available stock.');
                    }
                    $saleAllocations[$productId] = [
                        'quantity' => $quantity,
                        'line_total' => $lineTotal,
                        'batches' => $batchAllocations,
                    ];
                }
                $batchLock->close();
                $totalAmount = round($totalAmount, 2);
                $discountableSubtotal = round($discountableSubtotal, 2);
                if ($discountType !== '' && $discountableSubtotal <= 0) {
                    throw new RuntimeException('The cart has no eligible medicine for the Senior Citizen or PWD discount.');
                }
                $discountAmount = $discountType === '' ? 0.0 : round($discountableSubtotal * 0.20, 2);
                $subtotalAmount = $totalAmount;
                $totalAmount = round(max(0, $totalAmount - $discountAmount), 2);
                if ($cashReceived + 0.00001 < $totalAmount) {
                    throw new RuntimeException('Cash received is less than the sale total.');
                }
                $changeDue = round($cashReceived - $totalAmount, 2);
                $paymentReference = sprintf('POS cash %.2f; change %.2f', $cashReceived, $changeDue);
                if ($discountType !== '') {
                    $paymentReference .= sprintf(
                        '; %s discount %.2f',
                        $discountType === 'pwd' ? 'PWD' : 'Senior Citizen',
                        $discountAmount
                    );
                }
                $saleDiscountType = $discountType !== '' ? $discountType : null;
                $saleInsert = $conn->prepare('INSERT INTO pos_sales
                    (subtotal_amount, discount_type, discount_amount, total_amount, payment_reference, cashier_id)
                    VALUES (?, ?, ?, ?, ?, ?)');
                if (!$saleInsert) {
                    throw new RuntimeException('Unable to prepare the POS sale: ' . $conn->error);
                }
                $saleInsert->bind_param('dsddsi', $subtotalAmount, $saleDiscountType, $discountAmount, $totalAmount, $paymentReference, $staffId);
                if (!$saleInsert->execute()) {
                    throw new RuntimeException('Unable to save the POS sale: ' . $saleInsert->error);
                }
                $saleId = (int)$conn->insert_id;
                $saleInsert->close();
                if ($saleId < 1) {
                    throw new RuntimeException('The POS sale was not created. Please try again.');
                }
                if ($discountType !== '') {
                    $discountIdInsert = $conn->prepare(
                        'INSERT INTO pos_sale_discount_ids (sale_id, image_data, image_mime) VALUES (?, ?, ?)'
                    );
                    if (!$discountIdInsert) {
                        throw new RuntimeException('Unable to prepare discount ID storage: ' . $conn->error);
                    }
                    $emptyBlob = null;
                    $discountIdInsert->bind_param('ibs', $saleId, $emptyBlob, $discountIdMime);
                    $discountIdInsert->send_long_data(1, $discountIdImage);
                    if (!$discountIdInsert->execute()) {
                        throw new RuntimeException('Unable to save the discount ID: ' . $discountIdInsert->error);
                    }
                    $discountIdInsert->close();
                }

                $itemInsert = $conn->prepare('INSERT INTO pos_sale_items
                    (sale_id, product_id, product_name, category, quantity, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stockUpdate = $conn->prepare('UPDATE products SET quantity = ?, box = ?, pieces = ? WHERE id = ?');
                $batchUpdate = $conn->prepare('UPDATE product_batches
                    SET current_quantity = current_quantity - ?
                    WHERE id = ? AND current_quantity >= ? AND recalled = 0 AND expiration_date >= CURDATE()');
                $movementInsert = $conn->prepare("INSERT INTO batch_movements
                    (batch_id, product_id, order_id, pos_sale_id, quantity, movement_type, reference, created_by)
                    VALUES (?, ?, NULL, ?, ?, 'sold', ?, ?)");
                if (!$itemInsert || !$stockUpdate || !$batchUpdate || !$movementInsert) {
                    throw new RuntimeException('Unable to prepare the stock and POS sale updates: ' . $conn->error);
                }

                foreach ($saleAllocations as $productId => $allocation) {
                    $product = $lockedProducts[$productId];
                    $quantity = $allocation['quantity'];
                    $remainingStock = $product['available_stock'] - $quantity;
                    $lineTotal = $allocation['line_total'];
                    $itemInsert->bind_param(
                        'iissidd',
                        $saleId,
                        $productId,
                        $product['product_name'],
                        $product['category'],
                        $quantity,
                        $product['unit_price'],
                        $lineTotal
                    );
                    if (!$itemInsert->execute()) {
                        throw new RuntimeException('Unable to save a POS sale item: ' . $itemInsert->error);
                    }

                    $remainingBoxes = intdiv($remainingStock, $product['pieces_per_box']);
                    $remainingPieces = $remainingStock % $product['pieces_per_box'];
                    $stockUpdate->bind_param('iiii', $remainingStock, $remainingBoxes, $remainingPieces, $productId);
                    if (!$stockUpdate->execute() || $stockUpdate->affected_rows !== 1) {
                        throw new RuntimeException('Unable to update stock for ' . $product['product_name'] . '.');
                    }

                    $reference = 'Physical POS sale #' . $saleId;
                    if ($allocation['batches']) {
                        foreach ($allocation['batches'] as $batchAllocation) {
                            $batchId = $batchAllocation['batch_id'];
                            $batchQuantity = $batchAllocation['quantity'];
                            $batchUpdate->bind_param('iii', $batchQuantity, $batchId, $batchQuantity);
                            if (!$batchUpdate->execute() || $batchUpdate->affected_rows !== 1) {
                                throw new RuntimeException('Batch stock changed during checkout. No sale was recorded; please retry.');
                            }
                            $movementInsert->bind_param('iiiisi', $batchId, $productId, $saleId, $batchQuantity, $reference, $staffId);
                            if (!$movementInsert->execute()) {
                                throw new RuntimeException('Unable to save the batch stock movement: ' . $movementInsert->error);
                            }
                        }
                    } else {
                        $batchId = null;
                        $movementInsert->bind_param('iiiisi', $batchId, $productId, $saleId, $quantity, $reference, $staffId);
                        if (!$movementInsert->execute()) {
                            throw new RuntimeException('Unable to save the product stock movement: ' . $movementInsert->error);
                        }
                    }
                }

                $itemInsert->close();
                $stockUpdate->close();
                $batchUpdate->close();
                $movementInsert->close();
                if (!$conn->commit()) {
                    throw new RuntimeException('Unable to complete the checkout transaction. No sale was recorded; please try again.');
                }
                header('Location: physical_pos_receipt.php?sale_id=' . $saleId);
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                if ($exception instanceof RuntimeException) {
                    $error = $exception->getMessage();
                } else {
                    error_log('Physical POS checkout failed: ' . $exception->getMessage());
                    $error = 'Unable to complete the sale. No stock was changed; please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Physical Sale / POS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f7f8fa; color: #252a30; font-family: 'Segoe UI', Tahoma, sans-serif; }
        .content { min-height: 100vh; margin-left: 260px; padding: 104px 28px 32px; }
        .pos-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; }
        .pos-header h1 { margin: 0 0 5px; font-size: 28px; }
        .pos-header p { margin: 0; color: #737981; font-size: 13px; }
        .button { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 38px; padding: 0 14px; border: 1px solid #ed1740; border-radius: 7px; background: #ed1740; color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .button.secondary { border-color: #dfe4e9; background: #fff; color: #555e68; }
        .pos-message { margin-bottom: 14px; padding: 12px 14px; border-radius: 7px; font-size: 13px; }
        .pos-message.error { background: #ffe8ed; color: #a51e39; }
        .pos-layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; align-items: start; gap: 18px; }
        .pos-panel { min-width: 0; padding: 18px; border: 1px solid #ebedf0; border-radius: 10px; background: #fff; box-shadow: 0 2px 10px rgba(25,30,38,.04); }
        #checkoutForm { margin: 0; }
        #cartInputs { display: none; }
        .pos-search { display:flex; align-items:center; gap:8px; width:100%; height:40px; margin-bottom:14px; padding:0 10px; border:1px solid #eceef1; border-radius:8px; background:#fff; color:#969ca4; }
        .pos-search > i { flex:0 0 auto; font-size:14px; }
        .pos-search input { width:100%; min-width:0; padding:0; border:0; outline:0; background:transparent; color:#333; font:600 13px 'Segoe UI',Tahoma,sans-serif; }
        .pos-search input::placeholder { color:#969ca4; font-weight:400; }
        .pos-search button { display:none; width:auto; padding:0; border:0; background:transparent; color:#969ca4; font-size:16px; line-height:1; cursor:pointer; }
        .pos-search button:hover { color:#ed1b3b; }
        .pos-search #posSearchClear { align-items:center; justify-content:center; }
        .pos-search #posBarcodeScanTrigger { display:inline-flex; width:24px; min-width:24px; height:28px; align-items:center; justify-content:center; border-left:1px solid #eceef1; color:#68717c; }
        .pos-search #posBarcodeScanTrigger:hover { color:#ed1b3b; }
        .barcode-scanner-modal { display:none; position:fixed; inset:0; z-index:1400; align-items:center; justify-content:center; padding:20px; background:rgba(23,29,38,.64); }
        .barcode-scanner-modal.open { display:flex; }
        .barcode-scanner-dialog { width:min(480px,100%); padding:18px; border-radius:10px; background:#fff; box-shadow:0 20px 60px rgba(15,23,42,.28); }
        .barcode-scanner-header { position:relative; z-index:2; display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .barcode-scanner-header h2 { margin:0; color:#20232a; font-size:18px; }
        .barcode-scanner-header button { position:relative; z-index:3; display:grid; width:32px; height:32px; flex:0 0 32px; place-items:center; padding:0; border:0; border-radius:5px; appearance:none; background:transparent; color:#87909a; font-size:17px; cursor:pointer; pointer-events:auto; }
        .barcode-scanner-header button:hover, .barcode-scanner-header button:focus-visible { background:#f2f4f6; color:#252a30; outline:2px solid rgba(139,30,45,.18); }
        .barcode-scanner-status { min-height:20px; margin:0 0 12px; color:#626a74; font-size:13px; }
        .barcode-scanner-status[data-state="error"] { color:#c43149; }
        .barcode-camera-select { width:100%; height:36px; margin:0 0 10px; padding:0 9px; border:1px solid #dfe4e9; border-radius:6px; background:#fff; color:#303740; font-size:13px; }
        .barcode-camera-frame { position:relative; overflow:hidden; border-radius:7px; background:#101820; }
        .barcode-camera-frame video { display:block; width:100%; max-height:55vh; aspect-ratio:4 / 3; object-fit:contain; }
        .barcode-scan-guide { position:absolute; top:50%; left:50%; width:82%; height:30%; transform:translate(-50%,-50%); border:2px solid rgba(255,255,255,.9); border-radius:8px; pointer-events:none; }
        .barcode-scanner-actions { display:flex; justify-content:flex-end; margin-top:12px; }
        .barcode-scanner-actions button { min-height:36px; padding:0 12px; border:1px solid #dfe4e9; border-radius:6px; background:#fff; color:#4e5660; cursor:pointer; }
        .product-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-content: start; gap: 10px; }
        .product-card { display: flex; min-width: 0; min-height: 132px; align-items: stretch; gap: 10px; padding: 10px; border: 1px solid #e8ebef; border-radius: 8px; }
        .product-image-wrap { display: grid; width: 82px; min-width: 82px; min-height: 108px; place-items: center; overflow: hidden; border-radius: 7px; background: #f5f6f7; }
        .product-image { width: 100%; height: 100%; max-height: 145px; object-fit: contain; }
        .product-image-placeholder { color: #a9afb6; font-size: 34px; }
        .product-details { display: flex; min-width: 0; flex: 1; flex-direction: column; align-items: flex-start; }
        .product-card h2 { margin: 0 0 5px; font-size: 14px; line-height: 1.35; }
        .product-meta { color: #737981; font-size: 11px; line-height: 1.5; }
        .product-price { margin-top:7px; color: #4a1018; font-size: 16px; font-weight: 800; white-space:nowrap; }
        .product-stock { margin-top: 3px; color: #68707a; font-size: 11px; }
        .lot-number { margin-top: 5px; overflow-wrap: anywhere; color: #555e68; font-size: 11px; }
        .product-actions { display: flex; width: 100%; align-items: center; gap:8px; margin-top: auto; padding-top: 8px; }
        .quantity-control { display: inline-flex; height: 34px; align-items: center; gap: 9px; padding: 0 5px; border: 1px solid #dfe4e9; border-radius: 6px; }
        .quantity-control button { display: grid; width: 26px; height: 26px; place-items: center; border: 0; border-radius: 5px; background: #f5f6f7; cursor: pointer; }
        .quantity-control input { width: 38px; border: 0; outline: 0; text-align: center; font: inherit; font-size: 13px; }
        .product-add { width:100%; min-height: 34px; flex: 1; }
        .product-add:disabled { border-color: #dfe4e9; background: #f1f3f5; color: #8b9198; cursor: not-allowed; }
        .product-in-cart { margin-top: 7px; color: #207548; font-size: 11px; font-weight: 700; }
        .checkout-bar { position: sticky; top: 104px; z-index: 5; display: flex; flex-direction: column; align-items: stretch; gap: 16px; padding: 18px; border: 1px solid #ebedf0; border-radius: 10px; background: #fff; box-shadow: 0 2px 10px rgba(25,30,38,.04); }
        .checkout-title-row { display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .checkout-title { margin: 0; font-size: 16px; }
        .select-cart-button { min-height:30px; padding:0 10px; font-size:11px; }
        .sale-items { display: flex; max-height: 300px; flex-direction: column; gap: 9px; overflow-y: auto; }
        .sale-empty { padding: 10px 0; color: #737981; font-size: 12px; }
        .sale-item { display: flex; justify-content: space-between; gap: 10px; padding-bottom: 9px; border-bottom: 1px solid #edf0f2; font-size: 12px; }
        .sale-item-name { min-width: 0; overflow-wrap: anywhere; font-weight: 600; }
        .sale-item-actions { display:flex; min-width:82px; flex:0 0 auto; align-items:center; justify-content:flex-end; gap:8px; }
        .sale-item-quantity { min-width:30px; color: #68707a; text-align:right; white-space: nowrap; }
        .sale-item-select { width:18px; height:18px; margin:0 4px; accent-color:#ed1740; cursor:pointer; }
        .sale-item-select-label { display:inline-flex; align-items:center; cursor:pointer; }
        .sale-item-select-label[hidden] { display:none; }
        .sale-item.selected { border-radius:5px; background:#fff7f8; }
        .remove-selected-button { min-height:32px; align-self:flex-end; padding:0 10px; border-color:#f1cbd2; color:#b4233d; font-size:11px; }
        .remove-selected-button[hidden] { display:none; }
        .remove-selected-button:not(:disabled):hover, .remove-selected-button:not(:disabled):focus-visible { border-color:#b4233d; background:#fff5f6; color:#b4233d; outline:none; }
        .checkout-summary { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .checkout-summary span { display: block; color: #737981; font-size: 11px; }
        .checkout-summary strong { display: block; margin-top: 2px; font-size: 16px; }
        .cash-modal-backdrop { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 18px; background: rgba(20,24,30,.52); }
        .cash-modal-backdrop[hidden] { display: none; }
        .cash-modal { width: min(100%, 420px); padding: 22px; border-radius: 11px; background: #fff; box-shadow: 0 18px 60px rgba(0,0,0,.22); }
        .cash-modal h2 { margin: 0 0 6px; font-size: 20px; }
        .cash-modal > p { margin: 0 0 18px; color: #737981; font-size: 12px; }
        .cash-modal-total { display: flex; justify-content: space-between; margin-bottom: 16px; padding: 12px 0; border-block: 1px solid #edf0f2; font-size: 14px; font-weight: 700; }
        .cash-modal-subtotal, .cash-modal-discount { display:flex; justify-content:space-between; gap:12px; margin:0 0 8px; color:#68707a; font-size:12px; }
        .cash-modal-discount { color:#207548; }
        .cash-field { display: flex; flex-direction: column; gap: 6px; color: #68707a; font-size: 11px; font-weight: 600; }
        .cash-field[hidden], .cash-modal-discount[hidden] { display:none; }
        .cash-field input { width: 100%; height: 42px; padding: 0 11px; border: 1px solid #dfe4e9; border-radius: 6px; font: inherit; font-size: 15px; }
        .cash-field select { width:100%; height:40px; padding:0 10px; border:1px solid #dfe4e9; border-radius:6px; background:#fff; font:inherit; }
        .cash-field small { color:#737981; font-size:10px; font-weight:400; line-height:1.4; }
        .cash-field[hidden] { display:none; }
        .cash-field input:focus { border-color: #8b1e2d; outline: 2px solid rgba(139,30,45,.12); }
        .change-due { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 16px; font-size: 13px; }
        .change-due strong { color: #207548; font-size: 18px; }
        .cash-warning { min-height: 18px; margin-top: 6px; color: #b4233d; font-size: 11px; }
        .cash-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
        #confirmSaleButton:disabled { border-color:#c5c9ce; background:#c5c9ce; color:#fff; cursor:not-allowed; opacity:.75; }
        .camera-backdrop { position:fixed; inset:0; z-index:1300; display:grid; place-items:center; padding:18px; background:rgba(20,24,30,.65); }
        .camera-backdrop[hidden] { display:none; }
        .camera-dialog { width:min(100%, 520px); padding:20px; border-radius:11px; background:#fff; box-shadow:0 18px 60px rgba(0,0,0,.25); }
        .camera-dialog h2 { margin:0 0 12px; font-size:18px; }
        .camera-preview { display:block; width:100%; max-height:60vh; border-radius:8px; background:#17191d; object-fit:contain; }
        .camera-status { min-height:18px; margin-top:8px; color:#b4233d; font-size:12px; }
        .camera-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:12px; }
        .checkout-button { width: 100%; min-height: 42px; }
        .checkout-button:disabled { border-color: #c5c9ce; background: #c5c9ce; cursor: not-allowed; }
        .checkout-note { margin: 0; color: #737981; font-size: 11px; line-height: 1.5; }
        .no-products { grid-column: 1 / -1; padding: 30px; color: #737981; text-align: center; }
        @media (max-width: 1100px) and (min-width: 761px) { .pos-layout { grid-template-columns: minmax(0, 1fr) 320px; gap: 14px; } .product-grid { grid-template-columns: minmax(0, 1fr); } }
        @media (max-width: 760px) { .content { margin-left: 0; padding: 92px 14px 24px; } .pos-layout { grid-template-columns: minmax(0, 1fr); } .checkout-bar { position: static; flex-direction: row; align-items: center; } }
        @media (max-width: 520px) { .pos-header { align-items: flex-start; flex-direction: column; } .product-grid { grid-template-columns: 1fr; gap: 8px; } .product-image-wrap { width: 88px; min-width: 88px; min-height: 112px; } .product-card { min-height: 130px; gap: 10px; padding: 9px; } .checkout-bar { align-items: stretch; flex-direction: column; gap: 12px; } }
    </style>
</head>
<body>
<?php include __DIR__ . '/appbar_owner.php'; include __DIR__ . '/sidebar_owner.php'; ?>
<main class="content">
    <header class="pos-header">
        <div>
            <h1>Physical Sale / POS</h1>
            <p>Ring up in-store purchases and update product and batch stock.</p>
        </div>
        <a class="button secondary" href="inventory_batches.php?tab=batches"><i class="bi bi-arrow-left" aria-hidden="true"></i> Batch &amp; Expiry</a>
    </header>

    <?php if ($error !== ''): ?><div class="pos-message error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <div class="pos-layout">
        <section class="pos-panel" aria-label="Product catalog">
            <form class="pos-search" method="get">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="posProductSearch" type="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search products..." aria-label="Search product catalog">
                <button type="button" id="posSearchClear" aria-label="Clear search" title="Clear search" style="<?php echo $search !== '' ? 'display:inline-flex;' : ''; ?>">&times;</button>
                <button type="button" id="posBarcodeScanTrigger" aria-label="Search by barcode" title="Scan barcode to search"><i class="bi bi-upc-scan" aria-hidden="true"></i></button>
            </form>
            <form method="post" enctype="multipart/form-data" id="checkoutForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="product-grid" id="productGrid">
                <?php if ($products->num_rows > 0): ?>
                    <?php while ($product = $products->fetch_assoc()):
                        $available = max(0, (int)$product['available_stock']);
                        $image = trim((string)$product['image']);
                        $image = ltrim(str_replace('\\', '/', $image), '/');
                        $imageUrl = $image === '' ? '' : (preg_match('/^https?:\/\//i', $image)
                            ? $image
                            : (strpos($image, 'uploads/') === 0 ? $image : 'uploads/' . $image));
                    ?>
                    <article class="product-card">
                        <div class="product-image-wrap">
                            <?php if ($imageUrl !== ''): ?>
                                <img class="product-image" src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                            <?php else: ?>
                                <i class="bi bi-capsule product-image-placeholder" aria-hidden="true"></i>
                            <?php endif; ?>
                        </div>
                        <div class="product-details">
                            <h2><?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <div class="product-meta"><?php echo htmlspecialchars($product['sku'] ?: ($product['barcode'] ?: '')); ?></div>
                            <div class="lot-number"><strong>Lot:</strong> <?php echo htmlspecialchars($product['lot_numbers'] !== '' ? $product['lot_numbers'] : 'No available lot'); ?></div>
                            <div class="product-price">₱<?php echo number_format((float)$product['unit_price'], 2); ?></div>
                            <div class="product-stock"><?php echo number_format($available); ?> available</div>
                            <div class="product-actions">
                                <div class="quantity-control" aria-label="Quantity to add">
                                    <button type="button" class="quantity-step" data-step="-1" aria-label="Decrease quantity">−</button>
                                    <input class="quantity-choice" type="number" min="1" max="<?php echo $available; ?>" value="1" aria-label="Quantity to add for <?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $available < 1 ? 'disabled' : ''; ?>>
                                    <button type="button" class="quantity-step" data-step="1" aria-label="Increase quantity" <?php echo $available < 1 ? 'disabled' : ''; ?>>+</button>
                                </div>
                                <button type="button" class="button product-add" data-id="<?php echo (int)$product['id']; ?>" data-name="<?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>" data-price="<?php echo number_format((float)$product['unit_price'], 2, '.', ''); ?>" data-stock="<?php echo $available; ?>" data-medicine="<?php echo stripos((string)$product['category'], 'medicine') !== false ? '1' : '0'; ?>" <?php echo $available < 1 ? 'disabled' : ''; ?>>
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Add
                                </button>
                            </div>
                            <div class="product-in-cart" data-in-cart-for="<?php echo (int)$product['id']; ?>" hidden></div>
                        </div>
                    </article>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-products">No available products match your search.</div>
                <?php endif; ?>
            </div>
            <div id="cartInputs"></div>
            </form>
        </section>
        <aside class="checkout-bar" aria-label="Sale checkout">
            <div class="checkout-title-row">
                <h2 class="checkout-title">Current Sale</h2>
                <button type="button" class="button secondary select-cart-button" id="selectCartButton" disabled>Select</button>
            </div>
            <div class="sale-items" id="saleItems" aria-live="polite">
                <div class="sale-empty">No products added yet.</div>
            </div>
            <button type="button" class="button secondary remove-selected-button" id="removeSelectedButton" hidden disabled>Remove</button>
            <div class="checkout-summary">
                <div><span>Quantity</span><strong id="cartCount">0</strong></div>
                <div><span>Total</span><strong id="cartTotal">₱0.00</strong></div>
            </div>
            <button type="button" class="button checkout-button" id="checkoutButton" disabled>
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Complete Sale
            </button>
            <p class="checkout-note">Enter the cash amount in the confirmation to calculate change.</p>
        </aside>
    </div>
    <div class="cash-modal-backdrop" id="cashModalBackdrop" hidden>
        <section class="cash-modal" role="dialog" aria-modal="true" aria-labelledby="cashModalTitle" aria-describedby="cashModalDescription">
            <h2 id="cashModalTitle">Confirm Cash Payment</h2>
            <p id="cashModalDescription">Enter the amount the customer paid. The change will be calculated automatically.</p>
            <div class="cash-modal-subtotal"><span>Subtotal</span><span id="cashModalSubtotal">₱0.00</span></div>
            <div class="cash-modal-discount" id="cashModalDiscountRow" hidden><span id="cashModalDiscountLabel">Medicine discount (20%)</span><span id="cashModalDiscount">−₱0.00</span></div>
            <div class="cash-modal-total"><span>Sale total</span><span id="cashModalTotal">₱0.00</span></div>
            <label class="cash-field" for="discountType">
                Discount (Optional)
                <select id="discountType" name="discount_type" form="checkoutForm">
                    <option value="">No discount</option>
                    <option value="senior_citizen">Senior Citizen (20%)</option>
                    <option value="pwd">PWD (20%)</option>
                </select>
                <small>Leave as No discount if not applicable. Senior/PWD discount applies 20% to medicine-category items and requires an ID photo.</small>
            </label>
            <label class="cash-field" id="discountIdField" for="discountIdProof" hidden>
                Senior Citizen / PWD ID image
                <input id="discountIdProof" type="file" name="discount_id_proof" form="checkoutForm" accept="image/jpeg,image/png" hidden disabled>
                <button type="button" class="button secondary" id="openCameraButton" disabled><i class="bi bi-camera" aria-hidden="true"></i> Open Camera</button>
                <small id="discountIdStatus">Take a clear photo of the customer's ID.</small>
            </label>
            <div class="cash-warning" id="discountWarning" aria-live="polite"></div>
            <label class="cash-field" for="cashReceived">
                Cash received
                <input id="cashReceived" form="checkoutForm" type="number" name="cash_received" min="0" step="0.01" inputmode="decimal" placeholder="Enter amount paid" required disabled>
            </label>
            <div class="change-due"><span>Change due</span><strong id="changeDue">₱0.00</strong></div>
            <div class="cash-warning" id="cashWarning" aria-live="polite"></div>
            <div class="cash-modal-actions">
                <button type="button" class="button secondary" id="cancelCashButton">Cancel</button>
                <button type="submit" form="checkoutForm" class="button" id="confirmSaleButton" disabled>Confirm &amp; Complete Sale</button>
            </div>
        </section>
    </div>
    <div class="camera-backdrop" id="cameraBackdrop" hidden>
        <section class="camera-dialog" role="dialog" aria-modal="true" aria-labelledby="cameraTitle">
            <h2 id="cameraTitle">Capture Senior Citizen / PWD ID</h2>
            <video class="camera-preview" id="cameraPreview" autoplay playsinline muted></video>
            <div class="camera-status" id="cameraStatus" aria-live="polite"></div>
            <div class="camera-actions">
                <button type="button" class="button secondary" id="cancelCameraButton">Cancel</button>
                <button type="button" class="button" id="captureIdButton" disabled><i class="bi bi-camera" aria-hidden="true"></i> Capture ID</button>
            </div>
        </section>
    </div>
    <div class="barcode-scanner-modal" id="posBarcodeScannerModal" role="dialog" aria-modal="true" aria-labelledby="posBarcodeScannerTitle">
        <div class="barcode-scanner-dialog">
            <div class="barcode-scanner-header">
                <h2 id="posBarcodeScannerTitle">Scan product barcode</h2>
                <button type="button" id="closePosBarcodeScanner" aria-label="Close scanner"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <p class="barcode-scanner-status" id="posBarcodeScannerStatus" role="status" aria-live="polite">Center the barcode and hold steady.</p>
            <select class="barcode-camera-select" id="posBarcodeCameraSelect" aria-label="Select camera"></select>
            <div class="barcode-camera-frame">
                <video id="posBarcodeScannerVideo" playsinline muted></video>
                <div class="barcode-scan-guide" aria-hidden="true"></div>
            </div>
            <div class="barcode-scanner-actions"><button type="button" id="cancelPosBarcodeScanner">Cancel</button></div>
        </div>
    </div>
</main>
<script src="https://unpkg.com/@zxing/browser@0.1.5"></script>
<script>
const posSearchForm = document.querySelector('.pos-search');
const posProductSearch = document.getElementById('posProductSearch');
const posSearchClear = document.getElementById('posSearchClear');
const posBarcodeScanTrigger = document.getElementById('posBarcodeScanTrigger');
const posBarcodeScannerModal = document.getElementById('posBarcodeScannerModal');
const posBarcodeScannerStatus = document.getElementById('posBarcodeScannerStatus');
const posBarcodeCameraSelect = document.getElementById('posBarcodeCameraSelect');
const posBarcodeScannerVideo = document.getElementById('posBarcodeScannerVideo');
let posBarcodeScannerControls = null;
let posBarcodeScannerReader = null;
let posBarcodeScannerStream = null;
let posBarcodeScannerSession = 0;
let posBarcodeScanTimer = null;
let posBarcodeHelpTimer = null;
let posBarcodeAudioContext = null;

const preparePosBarcodeAudio = () => {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass || posBarcodeAudioContext) return;
    try {
        posBarcodeAudioContext = new AudioContextClass();
        posBarcodeAudioContext.resume().catch(error => {
            console.warn('Unable to enable POS barcode confirmation sound:', error);
        });
    } catch (error) {
        console.warn('Unable to enable POS barcode confirmation sound:', error);
    }
};

const playPosBarcodeScanBeep = async () => {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) {
        console.warn('POS barcode scan succeeded, but Web Audio is not supported in this browser.');
        return;
    }
    try {
        if (!posBarcodeAudioContext) posBarcodeAudioContext = new AudioContextClass();
        if (posBarcodeAudioContext.state === 'suspended') await posBarcodeAudioContext.resume();
        const startTime = posBarcodeAudioContext.currentTime;
        const oscillator = posBarcodeAudioContext.createOscillator();
        const gain = posBarcodeAudioContext.createGain();
        oscillator.type = 'triangle';
        oscillator.frequency.setValueAtTime(620, startTime);
        gain.gain.setValueAtTime(0.001, startTime);
        gain.gain.exponentialRampToValueAtTime(0.8, startTime + 0.02);
        gain.gain.setValueAtTime(0.8, startTime + 0.24);
        gain.gain.exponentialRampToValueAtTime(0.001, startTime + 0.38);
        oscillator.connect(gain);
        gain.connect(posBarcodeAudioContext.destination);
        oscillator.start(startTime);
        oscillator.stop(startTime + 0.39);
    } catch (error) {
        console.warn('POS barcode scan succeeded, but the confirmation beep could not play:', error);
    }
};

function setPosBarcodeStatus(message, isError = false) {
    posBarcodeScannerStatus.textContent = message;
    posBarcodeScannerStatus.dataset.state = isError ? 'error' : 'scanning';
}

function stopPosBarcodeScanner() {
    posBarcodeScannerSession += 1;
    if (posBarcodeScanTimer !== null) {
        window.clearTimeout(posBarcodeScanTimer);
        posBarcodeScanTimer = null;
    }
    if (posBarcodeHelpTimer !== null) {
        window.clearTimeout(posBarcodeHelpTimer);
        posBarcodeHelpTimer = null;
    }
    if (posBarcodeScannerControls) {
        posBarcodeScannerControls.stop();
        posBarcodeScannerControls = null;
    }
    if (posBarcodeScannerReader) {
        posBarcodeScannerReader.reset();
        posBarcodeScannerReader = null;
    }
    if (posBarcodeScannerStream) {
        posBarcodeScannerStream.getTracks().forEach(track => track.stop());
        posBarcodeScannerStream = null;
    }
    posBarcodeScannerVideo.pause();
    posBarcodeScannerVideo.srcObject = null;
}

function closePosBarcodeScanner() {
    posBarcodeScannerModal.classList.remove('open');
    posBarcodeScannerSession += 1;
    if (posBarcodeScanTimer !== null) {
        window.clearTimeout(posBarcodeScanTimer);
        posBarcodeScanTimer = null;
    }
    if (posBarcodeHelpTimer !== null) {
        window.clearTimeout(posBarcodeHelpTimer);
        posBarcodeHelpTimer = null;
    }
    if (posBarcodeScannerStream) {
        posBarcodeScannerStream.getTracks().forEach(track => track.stop());
        posBarcodeScannerStream = null;
    }
    posBarcodeScannerVideo.pause();
    posBarcodeScannerVideo.srcObject = null;

    const scannerControls = posBarcodeScannerControls;
    const scannerReader = posBarcodeScannerReader;
    posBarcodeScannerControls = null;
    posBarcodeScannerReader = null;
    window.setTimeout(() => {
        if (scannerControls) scannerControls.stop();
        if (scannerReader) scannerReader.reset();
    }, 0);
    posBarcodeScanTrigger.focus();
}

async function startPosBarcodeScanner(deviceId = '') {
    stopPosBarcodeScanner();
    const session = ++posBarcodeScannerSession;
    posBarcodeScannerModal.classList.add('open');
    setPosBarcodeStatus('Starting camera...');

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setPosBarcodeStatus('Camera access requires HTTPS or localhost.', true);
        return;
    }
    if (!window.ZXingBrowser || !window.ZXingBrowser.BrowserMultiFormatReader) {
        setPosBarcodeStatus('The barcode scanner did not load. Check your internet connection and reload the page.', true);
        return;
    }

    try {
        const permissionStream = await navigator.mediaDevices.getUserMedia({ audio: false, video: true });
        let cameras;
        try {
            cameras = (await navigator.mediaDevices.enumerateDevices())
                .filter(device => device.kind === 'videoinput');
        } finally {
            permissionStream.getTracks().forEach(track => track.stop());
        }
        if (session !== posBarcodeScannerSession) return;
        if (!cameras.length) throw new Error('No camera was found.');

        posBarcodeCameraSelect.replaceChildren();
        cameras.forEach((camera, index) => {
            const option = document.createElement('option');
            option.value = camera.deviceId;
            option.textContent = camera.label || `Camera ${index + 1}`;
            posBarcodeCameraSelect.appendChild(option);
        });
        const selectedCamera = cameras.find(camera => camera.deviceId === deviceId)
            || cameras.find(camera => /camo/i.test(camera.label))
            || cameras[0];
        posBarcodeCameraSelect.value = selectedCamera.deviceId;

        const reader = new ZXingBrowser.BrowserMultiFormatReader(undefined, {
            delayBetweenScanAttempts: 250,
            delayBetweenScanSuccess: 300
        });
        const formats = [
            ZXingBrowser.BarcodeFormat.UPC_A,
            ZXingBrowser.BarcodeFormat.UPC_E,
            ZXingBrowser.BarcodeFormat.EAN_13,
            ZXingBrowser.BarcodeFormat.EAN_8
        ];
        reader.setHints(new Map([[2, formats], [3, true]]));
        posBarcodeScannerReader = reader;
        posBarcodeScannerStream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                deviceId: { exact: selectedCamera.deviceId },
                width: { ideal: 1920 },
                height: { ideal: 1080 }
            }
        });
        if (session !== posBarcodeScannerSession) return;
        posBarcodeScannerVideo.srcObject = posBarcodeScannerStream;
        await posBarcodeScannerVideo.play();
        if (!posBarcodeScannerVideo.videoWidth || !posBarcodeScannerVideo.videoHeight) {
            throw new Error('The camera started but provided no video frames.');
        }

        const scanCanvas = document.createElement('canvas');
        const scanContext = scanCanvas.getContext('2d', { willReadFrequently: true });
        const fullFrameCanvas = document.createElement('canvas');
        const fullFrameContext = fullFrameCanvas.getContext('2d', { willReadFrequently: true });
        if (!scanContext || !fullFrameContext) throw new Error('Barcode scanning could not start in this browser.');
        let scanRotation = 0;
        const scanFrame = () => {
            if (session !== posBarcodeScannerSession) return;
            const videoWidth = posBarcodeScannerVideo.videoWidth;
            const videoHeight = posBarcodeScannerVideo.videoHeight;
            if (videoWidth && videoHeight) {
                const cropWidth = Math.floor(videoWidth * 0.92);
                const cropHeight = Math.floor(videoHeight * 0.65);
                const cropX = Math.floor((videoWidth - cropWidth) / 2);
                const cropY = Math.floor((videoHeight - cropHeight) / 2);
                const baseWidth = Math.min(1920, cropWidth * 2);
                const baseHeight = Math.min(1080, cropHeight * 2);
                const quarterTurn = scanRotation === 90 || scanRotation === 270;
                const outputWidth = quarterTurn ? baseHeight : baseWidth;
                const outputHeight = quarterTurn ? baseWidth : baseHeight;
                if (scanCanvas.width !== outputWidth || scanCanvas.height !== outputHeight) {
                    scanCanvas.width = outputWidth;
                    scanCanvas.height = outputHeight;
                }
                if (fullFrameCanvas.width !== videoWidth || fullFrameCanvas.height !== videoHeight) {
                    fullFrameCanvas.width = videoWidth;
                    fullFrameCanvas.height = videoHeight;
                }
                scanContext.imageSmoothingEnabled = true;
                scanContext.imageSmoothingQuality = 'high';
                scanContext.setTransform(1, 0, 0, 1, 0, 0);
                scanContext.clearRect(0, 0, scanCanvas.width, scanCanvas.height);
                scanContext.translate(scanCanvas.width / 2, scanCanvas.height / 2);
                scanContext.rotate(scanRotation * Math.PI / 180);
                scanContext.drawImage(
                    posBarcodeScannerVideo,
                    cropX, cropY, cropWidth, cropHeight,
                    -baseWidth / 2, -baseHeight / 2, baseWidth, baseHeight
                );
                scanContext.setTransform(1, 0, 0, 1, 0, 0);
                fullFrameContext.drawImage(posBarcodeScannerVideo, 0, 0, videoWidth, videoHeight);
                try {
                    let result;
                    try {
                        result = reader.decodeFromCanvas(scanCanvas);
                    } catch (error) {
                        if (!['NotFoundException', 'ChecksumException', 'FormatException'].includes(error.name)) {
                            throw error;
                        }
                        if (scanRotation !== 0) throw error;
                        result = reader.decodeFromCanvas(fullFrameCanvas);
                    }
                    const barcode = result.getText().trim();
                    if (barcode) {
                        posProductSearch.value = barcode;
                        posSearchClear.style.display = 'inline-flex';
                        playPosBarcodeScanBeep();
                        closePosBarcodeScanner();
                        posSearchForm.requestSubmit();
                        return;
                    }
                } catch (error) {
                    if (!['NotFoundException', 'ChecksumException', 'FormatException'].includes(error.name)) {
                        console.error('POS barcode frame decoding failed:', error);
                    }
                }
                scanRotation = (scanRotation + 90) % 360;
            }
            posBarcodeScanTimer = window.setTimeout(scanFrame, 180);
        };
        setPosBarcodeStatus(`Using ${selectedCamera.label || 'selected camera'}. Scanning barcode...`);
        posBarcodeScanTimer = window.setTimeout(scanFrame, 100);
        posBarcodeHelpTimer = window.setTimeout(() => {
            if (session !== posBarcodeScannerSession) return;
            posBarcodeScannerStatus.textContent = 'Camera is on, but no barcode has been detected yet. Straighten it and move closer until the bars fill the box. If blurry, move slightly farther away. Scanning will continue.';
            posBarcodeScannerStatus.dataset.state = 'waiting';
            posBarcodeHelpTimer = null;
        }, 8000);
    } catch (error) {
        if (session !== posBarcodeScannerSession) return;
        stopPosBarcodeScanner();
        const messages = {
            NotAllowedError: 'Camera access was denied. Allow camera permission in your browser and try again.',
            NotFoundError: 'No camera was found. Connect or enable a camera and try again.',
            NotReadableError: 'The camera could not be opened. Another app may be using it.',
            SecurityError: 'The browser blocked camera access. Open this page on localhost or HTTPS.'
        };
        setPosBarcodeStatus(messages[error.name] || error.message || 'Unable to open the barcode scanner camera.', true);
    }
}

posProductSearch.addEventListener('input', () => {
    posSearchClear.style.display = posProductSearch.value ? 'inline-flex' : 'none';
});
posSearchClear.addEventListener('click', () => {
    posProductSearch.value = '';
    posSearchClear.style.display = 'none';
    posSearchForm.requestSubmit();
});
posBarcodeScanTrigger.addEventListener('click', () => {
    preparePosBarcodeAudio();
    startPosBarcodeScanner();
});
posBarcodeCameraSelect.addEventListener('change', () => {
    if (posBarcodeScannerModal.classList.contains('open') && posBarcodeCameraSelect.value) {
        startPosBarcodeScanner(posBarcodeCameraSelect.value);
    }
});
const closePosBarcodeScannerButton = document.getElementById('closePosBarcodeScanner');
closePosBarcodeScannerButton.addEventListener('pointerdown', event => {
    event.stopPropagation();
    closePosBarcodeScanner();
}, true);
closePosBarcodeScannerButton.addEventListener('click', () => {
    if (posBarcodeScannerModal.classList.contains('open')) closePosBarcodeScanner();
});
document.getElementById('cancelPosBarcodeScanner').addEventListener('click', closePosBarcodeScanner);
posBarcodeScannerModal.addEventListener('click', event => {
    if (event.target === posBarcodeScannerModal) closePosBarcodeScanner();
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && posBarcodeScannerModal.classList.contains('open')) {
        closePosBarcodeScanner();
    }
});

const cart = new Map();
const selectedCartItems = new Set();
let selectionMode = false;
const cartInputs = document.getElementById('cartInputs');
const cartTotal = document.getElementById('cartTotal');
const cartCount = document.getElementById('cartCount');
const saleItems = document.getElementById('saleItems');
const selectCartButton = document.getElementById('selectCartButton');
const removeSelectedButton = document.getElementById('removeSelectedButton');
const checkoutButton = document.getElementById('checkoutButton');
const cashReceivedInput = document.getElementById('cashReceived');
const discountTypeInput = document.getElementById('discountType');
const discountIdField = document.getElementById('discountIdField');
const discountIdInput = document.getElementById('discountIdProof');
const openCameraButton = document.getElementById('openCameraButton');
const discountIdStatus = document.getElementById('discountIdStatus');
const discountWarning = document.getElementById('discountWarning');
const changeDue = document.getElementById('changeDue');
const cashWarning = document.getElementById('cashWarning');
const confirmSaleButton = document.getElementById('confirmSaleButton');
const cashModalBackdrop = document.getElementById('cashModalBackdrop');
const cashModalSubtotal = document.getElementById('cashModalSubtotal');
const cashModalTotal = document.getElementById('cashModalTotal');
const cashModalDiscountRow = document.getElementById('cashModalDiscountRow');
const cashModalDiscountLabel = document.getElementById('cashModalDiscountLabel');
const cashModalDiscount = document.getElementById('cashModalDiscount');
const cancelCashButton = document.getElementById('cancelCashButton');
const cameraBackdrop = document.getElementById('cameraBackdrop');
const cameraPreview = document.getElementById('cameraPreview');
const cameraStatus = document.getElementById('cameraStatus');
const captureIdButton = document.getElementById('captureIdButton');
const cancelCameraButton = document.getElementById('cancelCameraButton');
let discountCameraStream = null;

const formatMoney = value => `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function updateDiscountControls() {
    const hasDiscount = discountTypeInput.value !== '';
    discountIdField.hidden = !hasDiscount;
    discountIdInput.disabled = !hasDiscount;
    openCameraButton.disabled = !hasDiscount;
}

function stopDiscountCamera() {
    if (discountCameraStream) {
        discountCameraStream.getTracks().forEach(track => track.stop());
        discountCameraStream = null;
    }
    cameraPreview.srcObject = null;
    captureIdButton.disabled = true;
}

async function openDiscountCamera() {
    cameraStatus.textContent = '';
    cameraBackdrop.hidden = false;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        cameraStatus.textContent = 'Camera access is unavailable. Open this page on HTTPS or localhost using a supported browser.';
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: { ideal: 'environment' } }
        });
        if (cameraBackdrop.hidden) {
            stream.getTracks().forEach(track => track.stop());
            return;
        }
        discountCameraStream = stream;
        cameraPreview.srcObject = discountCameraStream;
        await cameraPreview.play();
        captureIdButton.disabled = false;
    } catch (error) {
        stopDiscountCamera();
        cameraStatus.textContent = error.name === 'NotAllowedError'
            ? 'Camera permission was denied. Allow camera access in your browser settings and try again.'
            : 'Unable to open the camera. Check that a camera is connected and not being used by another app.';
    }
}

function closeDiscountCamera() {
    stopDiscountCamera();
    cameraBackdrop.hidden = true;
    openCameraButton.focus();
}

async function captureDiscountId() {
    if (!cameraPreview.videoWidth || !cameraPreview.videoHeight) {
        cameraStatus.textContent = 'The camera is not ready yet. Please wait and try again.';
        return;
    }

    captureIdButton.disabled = true;
    const scale = Math.min(1, 1600 / Math.max(cameraPreview.videoWidth, cameraPreview.videoHeight));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(cameraPreview.videoWidth * scale);
    canvas.height = Math.round(cameraPreview.videoHeight * scale);
    canvas.getContext('2d').drawImage(cameraPreview, 0, 0, canvas.width, canvas.height);

    canvas.toBlob(blob => {
        if (!blob || blob.size < 1 || blob.size > 5 * 1024 * 1024) {
            cameraStatus.textContent = 'Could not create an ID image under 5 MB. Adjust the ID and capture again.';
            captureIdButton.disabled = false;
            return;
        }
        try {
            const file = new File([blob], 'discount-id-capture.jpg', { type: 'image/jpeg' });
            const transfer = new DataTransfer();
            transfer.items.add(file);
            discountIdInput.files = transfer.files;
            discountIdStatus.textContent = 'ID captured: discount-id-capture.jpg';
            closeDiscountCamera();
            renderCart();
        } catch (error) {
            cameraStatus.textContent = 'Unable to attach the captured ID. Please try again.';
            captureIdButton.disabled = false;
        }
    }, 'image/jpeg', 0.88);
}

function calculateCartTotals() {
    let subtotal = 0;
    let medicineSubtotal = 0;
    cart.forEach(item => {
        const lineTotal = item.price * item.quantity;
        subtotal += lineTotal;
        if (item.isMedicine) medicineSubtotal += lineTotal;
    });
    subtotal = Math.round((subtotal + Number.EPSILON) * 100) / 100;
    medicineSubtotal = Math.round((medicineSubtotal + Number.EPSILON) * 100) / 100;
    const discountAmount = discountTypeInput.value === ''
        ? 0
        : Math.round((medicineSubtotal * 0.20 + Number.EPSILON) * 100) / 100;
    return { subtotal, medicineSubtotal, discountAmount, total: Math.max(0, subtotal - discountAmount) };
}

function hasValidDiscountProof() {
    if (discountTypeInput.value === '') return true;
    const file = discountIdInput.files[0];
    return Boolean(file && file.size > 0 && file.size <= 5 * 1024 * 1024
        && ['image/jpeg', 'image/png'].includes(file.type));
}

function updateProductCard(card) {
    const addButton = card.querySelector('.product-add');
    const quantityInput = card.querySelector('.quantity-choice');
    const existing = cart.get(addButton.dataset.id);
    const remainingStock = Math.max(0, Number(addButton.dataset.stock) - (existing ? existing.quantity : 0));
    const quantity = quantityInput.valueAsNumber;
    const validQuantity = Number.isInteger(quantity) && quantity >= 1 && quantity <= remainingStock;

    quantityInput.max = String(remainingStock);
    quantityInput.disabled = remainingStock < 1;
    addButton.disabled = !validQuantity;
    card.querySelectorAll('.quantity-step').forEach(button => {
        const step = Number(button.dataset.step);
        button.disabled = remainingStock < 1
            || (step < 0 && (!Number.isFinite(quantity) || quantity <= 1))
            || (step > 0 && (!Number.isFinite(quantity) || quantity >= remainingStock));
    });
}

function renderCart() {
    updateDiscountControls();
    cartInputs.replaceChildren();
    saleItems.replaceChildren();
    selectedCartItems.forEach(id => {
        if (!cart.has(id)) selectedCartItems.delete(id);
    });
    if (cart.size === 0) {
        selectionMode = false;
        selectedCartItems.clear();
    }
    let itemCount = 0;

    if (cart.size === 0) {
        const empty = document.createElement('div');
        empty.className = 'sale-empty';
        empty.textContent = 'No products added yet.';
        saleItems.appendChild(empty);
    }

    cart.forEach(item => {
        const lineTotal = item.price * item.quantity;
        itemCount += item.quantity;
        const saleItem = document.createElement('div');
        saleItem.className = 'sale-item';
        if (selectedCartItems.has(item.id)) saleItem.classList.add('selected');
        const name = document.createElement('span');
        name.className = 'sale-item-name';
        name.textContent = item.name;
        const quantity = document.createElement('span');
        quantity.className = 'sale-item-quantity';
        quantity.textContent = `× ${item.quantity}`;
        const actions = document.createElement('div');
        actions.className = 'sale-item-actions';
        const selectLabel = document.createElement('label');
        selectLabel.className = 'sale-item-select-label';
        selectLabel.hidden = !selectionMode;
        const selectItem = document.createElement('input');
        selectItem.type = 'checkbox';
        selectItem.className = 'sale-item-select';
        selectItem.dataset.selectItem = item.id;
        selectItem.checked = selectedCartItems.has(item.id);
        selectItem.setAttribute('aria-label', `Select ${item.name} for removal`);
        selectItem.title = 'Select for removal';
        selectLabel.append(selectItem);
        actions.append(quantity, selectLabel);
        saleItem.append(name, actions);
        saleItems.appendChild(saleItem);

        const addedLabel = document.querySelector(`[data-in-cart-for="${item.id}"]`);
        if (addedLabel) {
            addedLabel.hidden = false;
            addedLabel.textContent = `Added: ${item.quantity} · ${formatMoney(lineTotal)}`;
        }

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `cart[${item.id}]`;
        input.value = String(item.quantity);
        cartInputs.appendChild(input);
    });

    document.querySelectorAll('.product-card').forEach(card => {
        updateProductCard(card);
    });

    document.querySelectorAll('[data-in-cart-for]').forEach(label => {
        if (!cart.has(label.dataset.inCartFor)) {
            label.hidden = true;
            label.textContent = '';
        }
    });

    const totals = calculateCartTotals();
    cartTotal.textContent = formatMoney(totals.total);
    cashModalSubtotal.textContent = formatMoney(totals.subtotal);
    cashModalTotal.textContent = formatMoney(totals.total);
    cashModalDiscountRow.hidden = discountTypeInput.value === '' || totals.discountAmount <= 0;
    cashModalDiscountLabel.textContent = `${discountTypeInput.value === 'pwd' ? 'PWD' : 'Senior Citizen'} medicine discount (20%)`;
    cashModalDiscount.textContent = `−${formatMoney(totals.discountAmount)}`;
    cartCount.textContent = String(itemCount);
    selectCartButton.disabled = cart.size === 0;
    selectCartButton.textContent = selectionMode ? 'Cancel' : 'Select';
    removeSelectedButton.disabled = selectedCartItems.size === 0;
    removeSelectedButton.hidden = selectedCartItems.size === 0;
    const cashReceived = Number(cashReceivedInput.value);
    const validTender = cashReceivedInput.value !== '' && Number.isFinite(cashReceived) && cashReceived >= 0;
    const hasEnoughCash = validTender && cashReceived + 0.00001 >= totals.total;
    changeDue.textContent = formatMoney(hasEnoughCash ? Math.max(0, cashReceived - totals.total) : 0);
    const hasEligibleMedicine = totals.medicineSubtotal > 0;
    discountWarning.textContent = discountTypeInput.value === ''
        ? ''
        : (!hasEligibleMedicine
            ? 'There are no medicine-category items in this sale to discount.'
            : (!hasValidDiscountProof() ? 'Upload a JPG or PNG Senior Citizen or PWD ID up to 5 MB.' : ''));
    cashWarning.textContent = cart.size > 0 && cashReceivedInput.value !== '' && Number.isFinite(cashReceived) && !hasEnoughCash
        ? 'Cash received is less than the sale total.'
        : '';
    checkoutButton.disabled = cart.size === 0;
    confirmSaleButton.disabled = cart.size === 0 || !hasEnoughCash
        || (discountTypeInput.value !== '' && (!hasEligibleMedicine || !hasValidDiscountProof()));
}

selectCartButton.addEventListener('click', () => {
    if (cart.size === 0) return;
    selectionMode = !selectionMode;
    selectedCartItems.clear();
    renderCart();
});

saleItems.addEventListener('change', event => {
    if (!selectionMode) return;
    const checkbox = event.target.closest('[data-select-item]');
    if (!checkbox) return;
    if (checkbox.checked) selectedCartItems.add(checkbox.dataset.selectItem);
    else selectedCartItems.delete(checkbox.dataset.selectItem);
    checkbox.closest('.sale-item').classList.toggle('selected', checkbox.checked);
    removeSelectedButton.disabled = selectedCartItems.size === 0;
    removeSelectedButton.hidden = selectedCartItems.size === 0;
});

removeSelectedButton.addEventListener('click', () => {
    const selectedItems = Array.from(selectedCartItems, id => cart.get(id)).filter(Boolean);
    if (selectedItems.length === 0) return;
    const itemNames = selectedItems.map(item => item.name).join(', ');
    if (!window.confirm(`Remove selected item${selectedItems.length === 1 ? '' : 's'} (${itemNames}) from the current sale?`)) return;
    selectedItems.forEach(item => cart.delete(item.id));
    selectedCartItems.clear();
    renderCart();
});

discountTypeInput.addEventListener('change', () => {
    discountIdInput.value = '';
    discountIdStatus.textContent = 'Take a clear photo of the customer\'s ID.';
    renderCart();
});
discountIdInput.addEventListener('change', renderCart);
openCameraButton.addEventListener('click', openDiscountCamera);
captureIdButton.addEventListener('click', captureDiscountId);
cancelCameraButton.addEventListener('click', closeDiscountCamera);

document.querySelectorAll('.product-card').forEach(card => {
    const quantityInput = card.querySelector('.quantity-choice');
    const addButton = card.querySelector('.product-add');
    updateProductCard(card);
    card.querySelectorAll('.quantity-step').forEach(button => {
        button.addEventListener('click', () => {
            const existing = cart.get(addButton.dataset.id);
            const remainingStock = Math.max(0, Number(addButton.dataset.stock) - (existing ? existing.quantity : 0));
            const current = quantityInput.valueAsNumber;
            if (!Number.isFinite(current) || current < 1) {
                quantityInput.value = remainingStock > 0 ? '1' : '';
            } else {
                quantityInput.value = String(Math.max(1, Math.min(remainingStock, current + Number(button.dataset.step))));
            }
            updateProductCard(card);
        });
    });

    quantityInput.addEventListener('input', () => updateProductCard(card));

    addButton.addEventListener('click', () => {
        const stock = Number(addButton.dataset.stock);
        const id = addButton.dataset.id;
        const existing = cart.get(id);
        const remainingStock = stock - (existing ? existing.quantity : 0);
        const selectedQuantity = quantityInput.valueAsNumber;
        if (!Number.isInteger(selectedQuantity) || selectedQuantity < 1 || selectedQuantity > remainingStock) {
            updateProductCard(card);
            return;
        }
        if (existing) existing.quantity = Math.min(existing.stock, existing.quantity + selectedQuantity);
        else cart.set(id, {
            id,
            name: addButton.dataset.name,
            price: Number(addButton.dataset.price),
            isMedicine: addButton.dataset.medicine === '1',
            stock,
            quantity: selectedQuantity
        });
        renderCart();
        quantityInput.value = '1';
        updateProductCard(card);
    });
});

checkoutButton.addEventListener('click', () => {
    if (cart.size === 0) return;
    cashReceivedInput.disabled = false;
    cashModalBackdrop.hidden = false;
    renderCart();
    cashReceivedInput.focus();
});

cancelCashButton.addEventListener('click', () => {
    cashModalBackdrop.hidden = true;
    cashReceivedInput.disabled = true;
    cashReceivedInput.value = '';
    discountTypeInput.value = '';
    discountIdInput.value = '';
    discountIdStatus.textContent = 'Take a clear photo of the customer\'s ID.';
    renderCart();
    checkoutButton.focus();
});

cashReceivedInput.addEventListener('input', renderCart);

document.getElementById('checkoutForm').addEventListener('submit', event => {
    const cashReceived = Number(cashReceivedInput.value);
    const totals = calculateCartTotals();
    if (cart.size === 0 || cashReceivedInput.disabled || cashReceivedInput.value === ''
        || !Number.isFinite(cashReceived) || cashReceived + 0.00001 < totals.total
        || (discountTypeInput.value !== '' && (totals.medicineSubtotal <= 0 || !hasValidDiscountProof()))) {
        event.preventDefault();
        renderCart();
    } else {
        confirmSaleButton.disabled = true;
        checkoutButton.disabled = true;
    }
});

cashModalBackdrop.addEventListener('click', event => {
    if (event.target === cashModalBackdrop) cancelCashButton.click();
});

cameraBackdrop.addEventListener('click', event => {
    if (event.target === cameraBackdrop) closeDiscountCamera();
});

document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (!cameraBackdrop.hidden) closeDiscountCamera();
    else if (!cashModalBackdrop.hidden) cancelCashButton.click();
});
</script>
</body>
</html>
