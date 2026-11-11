<?php
session_start();
include 'config.php';
include 'functions.php';
require_once __DIR__ . '/access_control.php';

requireOwnerPageAccess($conn, true);
$isPharmacist = ($_SESSION['role'] ?? '') === 'pharmacist';

$barcodeColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'barcode'");
if ($barcodeColumn && $barcodeColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN barcode VARCHAR(100) NULL");
}
$skuColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'sku'");
$hasSku = $skuColumn && $skuColumn->num_rows > 0;

$conn->query("CREATE TABLE IF NOT EXISTS product_batches (
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
    UNIQUE KEY uq_product_lot (product_id, lot_number),
    KEY idx_batch_expiration (expiration_date),
    KEY idx_batch_recalled (recalled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS batch_movements (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$posSaleSchemas = [
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
foreach ($posSaleSchemas as $posSaleSchema) {
    if (!$conn->query($posSaleSchema)) {
        error_log('Batch sales POS schema preparation failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to prepare POS batch sales.');
    }
}
$posSaleColumn = $conn->query("SHOW COLUMNS FROM batch_movements LIKE 'pos_sale_id'");
if (!$posSaleColumn || ($posSaleColumn->num_rows === 0
    && !$conn->query('ALTER TABLE batch_movements ADD COLUMN pos_sale_id INT UNSIGNED NULL'))) {
    error_log('Batch sales POS movement column preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare POS batch sales.');
}
$posSaleIndex = $conn->query("SHOW INDEX FROM batch_movements WHERE Key_name = 'idx_batch_movement_pos_sale'");
if (!$posSaleIndex || ($posSaleIndex->num_rows === 0
    && !$conn->query('ALTER TABLE batch_movements ADD KEY idx_batch_movement_pos_sale (pos_sale_id)'))) {
    error_log('Batch sales POS movement index preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare POS batch sales.');
}
$conn->query("ALTER TABLE batch_movements MODIFY batch_id INT UNSIGNED NULL");
$conn->query("ALTER TABLE product_batches ADD COLUMN IF NOT EXISTS expiry_alert_sent TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE product_batches ADD COLUMN IF NOT EXISTS source_key VARCHAR(191) NULL");
$conn->query("ALTER TABLE product_batches ADD COLUMN IF NOT EXISTS source_label VARCHAR(100) NULL");
$manufacturingDateColumn = $conn->query("SHOW COLUMNS FROM product_batches LIKE 'manufacturing_date'");
if ($manufacturingDateColumn === false) {
    error_log('Batch manufacturing date column inspection failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to inspect batch manufacturing date data.');
}
if ($manufacturingDateColumn->num_rows === 0
    && !$conn->query('ALTER TABLE product_batches ADD COLUMN manufacturing_date DATE NULL')) {
    error_log('Batch manufacturing date column setup failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare batch manufacturing date data.');
}
$batchLotIndex = $conn->query("SHOW INDEX FROM product_batches WHERE Key_name = 'uq_product_lot'");
if ($batchLotIndex && $batchLotIndex->num_rows > 0) {
    $conn->query("ALTER TABLE product_batches DROP INDEX uq_product_lot");
}
$batchSourceIndex = $conn->query("SHOW INDEX FROM product_batches WHERE Key_name = 'uq_batch_source'");
if ($batchSourceIndex && $batchSourceIndex->num_rows === 0) {
    $conn->query("ALTER TABLE product_batches ADD UNIQUE KEY uq_batch_source (source_key)");
}
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100) NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_verified_at DATETIME NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_verified_by INT NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS seller_id INT NULL");

$message = '';
$error = '';
$saleReceipt = null;
$adminId = (int)$_SESSION['user_id'];

function ensureOnsiteSalesSchema(mysqli $conn, string &$schemaError): ?string
{
    foreach (['user_id', 'payment_method', 'delivery_type', 'status'] as $columnName) {
        $columnResult = $conn->query("SHOW COLUMNS FROM orders LIKE '$columnName'");
        if (!$columnResult) {
            $schemaError = 'Unable to inspect the orders table: ' . $conn->error;
            return null;
        }
        $column = $columnResult->fetch_assoc();
        if (!$column) {
            $schemaError = "Required orders column '$columnName' is missing.";
            return null;
        }

        if ($columnName === 'user_id' && $column['Null'] !== 'YES') {
            if (!$conn->query('ALTER TABLE orders MODIFY COLUMN user_id INT NULL')) {
                $schemaError = 'Unable to allow anonymous onsite orders: ' . $conn->error;
                return null;
            }
        } elseif (in_array($columnName, ['payment_method', 'delivery_type', 'status'], true)
            && strpos(strtolower((string)$column['Type']), 'enum(') === 0) {
            $nullability = $column['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
            $default = $column['Default'] === null
                ? ($column['Null'] === 'YES' ? ' DEFAULT NULL' : '')
                : " DEFAULT '" . $conn->real_escape_string((string)$column['Default']) . "'";
            if (!$conn->query("ALTER TABLE orders MODIFY COLUMN `$columnName` VARCHAR(50) $nullability$default")) {
                $schemaError = "Unable to prepare the orders '$columnName' field for onsite sales: " . $conn->error;
                return null;
            }
        }
    }

    foreach (['total_amount', 'total_price'] as $amountColumn) {
        $columnResult = $conn->query("SHOW COLUMNS FROM orders LIKE '$amountColumn'");
        if (!$columnResult) {
            $schemaError = 'Unable to inspect the order total field: ' . $conn->error;
            return null;
        }
        if ($columnResult->num_rows > 0) {
            return $amountColumn;
        }
    }

    $schemaError = 'The orders table has no total_amount or total_price column.';
    return null;
}

$onsiteSalesSchemaError = '';
$onsiteAmountColumn = ensureOnsiteSalesSchema($conn, $onsiteSalesSchemaError);

function createOnsiteSaleOrder($conn, $productId, $quantity, $adminId, $amountColumn, &$failureReason) {
    $productId = (int)$productId;
    $quantity = (int)$quantity;
    $productResult = $conn->query("SELECT product_name, COALESCE(price_piece, price, 0) AS unit_price,
        COALESCE(NULLIF(pieces_per_box, 0), 12) AS pieces_per_box
        FROM products WHERE id = $productId LIMIT 1");
    if (!$productResult) {
        $failureReason = 'Unable to load the product for this sale: ' . $conn->error;
        return false;
    }
    $product = $productResult ? $productResult->fetch_assoc() : null;
    if (!$product || $quantity < 1) {
        $failureReason = 'The product was not found or the sale quantity is invalid.';
        return false;
    }

    $unitPrice = (float)$product['unit_price'];
    $totalAmount = round($unitPrice * $quantity, 2);
    $order = $conn->prepare("INSERT INTO orders (user_id, `$amountColumn`, fullname, address, status, payment_method, delivery_type, payment_status, payment_reference, payment_verified_at, payment_verified_by, seller_id) VALUES (NULL, ?, 'Onsite Customer', 'Onsite Pharmacy', 'completed', 'onsite', 'onsite', 'paid', 'Paid onsite', NOW(), ?, ?)");
    if (!$order) {
        $failureReason = 'Unable to prepare the onsite order: ' . $conn->error;
        return false;
    }
    $order->bind_param('dii', $totalAmount, $adminId, $adminId);
    $savedOrder = $order->execute();
    if (!$savedOrder) {
        $failureReason = 'Unable to save the onsite order: ' . $order->error;
    }
    $orderId = (int)$conn->insert_id;
    $order->close();
    if (!$savedOrder || $orderId < 1) {
        return false;
    }

    $piecesPerBox = max(1, (int)$product['pieces_per_box']);
    $boxQuantity = intdiv($quantity, $piecesPerBox);
    $pieceQuantity = $quantity % $piecesPerBox;
    $item = $conn->prepare('INSERT INTO order_items (order_id, product_id, quantity, price, box, pieces, box_qty, piece_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$item) {
        $failureReason = 'Unable to prepare the onsite order item: ' . $conn->error;
        return false;
    }
    $item->bind_param('iiidiiii', $orderId, $productId, $quantity, $totalAmount, $boxQuantity, $pieceQuantity, $boxQuantity, $pieceQuantity);
    $savedItem = $item->execute();
    if (!$savedItem) {
        $failureReason = 'Unable to save the onsite order item: ' . $item->error;
    }
    $item->close();
    return $savedItem ? $orderId : false;
}

function googleSheetAccessToken() {
    $credentials = getenv('GOOGLE_SERVICE_ACCOUNT_JSON');
    if ($credentials && is_file($credentials)) {
        $credentials = file_get_contents($credentials);
    }
    $account = $credentials ? json_decode($credentials, true) : null;
    if (!$account || empty($account['client_email']) || empty($account['private_key'])) {
        throw new RuntimeException('Google service-account credentials are not configured.');
    }
    $base64Url = static function ($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    };
    $issuedAt = time();
    $header = $base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = $base64Url(json_encode(['iss' => $account['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $issuedAt, 'exp' => $issuedAt + 3600]));
    $unsignedToken = $header . '.' . $payload;
    if (!openssl_sign($unsignedToken, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign Google service-account token.');
    }
    $tokenRequest = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($tokenRequest, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsignedToken . '.' . $base64Url($signature)]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $tokenResponse = curl_exec($tokenRequest);
    $tokenCode = (int)curl_getinfo($tokenRequest, CURLINFO_HTTP_CODE);
    curl_close($tokenRequest);
    $tokenData = json_decode((string)$tokenResponse, true);
    if ($tokenCode >= 400 || empty($tokenData['access_token'])) {
        throw new RuntimeException('Google authorization failed.');
    }
    return $tokenData['access_token'];
}

function syncBatchesFromGoogleSheet($conn, $hasSku, $adminId) {
    $sheetId = getenv('GOOGLE_SHEET_ID');
    $sheetRange = getenv('GOOGLE_SHEET_RANGE') ?: 'Batches!A:F';
    if (!$sheetId) {
        throw new RuntimeException('GOOGLE_SHEET_ID is not configured.');
    }
    $token = googleSheetAccessToken();
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sheetId) . '/values/' . rawurlencode($sheetRange) . '?majorDimension=ROWS';
    $request = curl_init($url);
    curl_setopt_array($request, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $response = curl_exec($request);
    $statusCode = (int)curl_getinfo($request, CURLINFO_HTTP_CODE);
    curl_close($request);
    $data = json_decode((string)$response, true);
    if ($statusCode >= 400 || empty($data['values'])) {
        throw new RuntimeException('Google Sheet returned no usable batch rows.');
    }

    $normalize = static function ($value) {
        return strtolower(preg_replace('/[^a-z0-9]+/', '_', trim((string)$value), -1, $count));
    };
    $headers = array_map($normalize, array_shift($data['values']));
    $column = static function ($names, $headers) {
        foreach ($names as $name) {
            $index = array_search($name, $headers, true);
            if ($index !== false) return $index;
        }
        return null;
    };
    $productIdColumn = $column(['product_id', 'id'], $headers);
    $productNameColumn = $column(['product_name', 'product'], $headers);
    $skuColumn = $column(['sku', 'item_id'], $headers);
    $lotColumn = $column(['lot_number', 'lot'], $headers);
    $manufacturingColumn = $column(['manufacturing_date', 'mfg_date', 'manufacture_date', 'mfg'], $headers);
    $expirationColumn = $column(['expiration_date', 'expiry_date', 'expiration'], $headers);
    $receivedColumn = $column(['received_quantity', 'received', 'quantity_received'], $headers);
    $currentColumn = $column(['current_quantity', 'current_stock', 'available'], $headers);
    $sourceColumn = $column(['source_key', 'stock_id', 'batch_id', 'reference'], $headers);
    $sourceLabelColumn = $column(['source_label', 'stock_type', 'stock_period', 'batch_label'], $headers);
    if ($lotColumn === null || $expirationColumn === null || $currentColumn === null || ($productIdColumn === null && $productNameColumn === null && (!$hasSku || $skuColumn === null))) {
        throw new RuntimeException('Sheet must include product_id or product_name/SKU, lot_number, expiration_date, and current_quantity columns.');
    }

    $synced = 0;
    $skipped = 0;
    $existingSourceCheck = $conn->prepare('SELECT id FROM product_batches WHERE source_key = ? LIMIT 1');
    if (!$existingSourceCheck) {
        throw new RuntimeException('Unable to check existing batch records: ' . $conn->error);
    }
    $minimumBatchExpiration = date('Y-m-d', strtotime('+30 days'));
    foreach ($data['values'] as $rowIndex => $row) {
        $value = static function ($index, $row) { return $index === null ? '' : trim((string)($row[$index] ?? '')); };
        $productId = (int)$value($productIdColumn, $row);
        $productName = $value($productNameColumn, $row);
        $sku = $value($skuColumn, $row);
        $lotNumber = $value($lotColumn, $row);
        $manufacturing = $value($manufacturingColumn, $row);
        $manufacturingDate = null;
        if ($manufacturing !== '') {
            $manufacturingDateObject = DateTime::createFromFormat('Y-m-d', $manufacturing);
            if (!$manufacturingDateObject || $manufacturingDateObject->format('Y-m-d') !== $manufacturing) {
                $manufacturingDateObject = DateTime::createFromFormat('m/d/Y', $manufacturing);
            }
            if (!$manufacturingDateObject) {
                $skipped++;
                continue;
            }
            $manufacturingDate = $manufacturingDateObject->format('Y-m-d');
        }
        $expiration = $value($expirationColumn, $row);
        $expirationDate = DateTime::createFromFormat('Y-m-d', $expiration);
        if (!$expirationDate || $expirationDate->format('Y-m-d') !== $expiration) {
            $expirationDate = DateTime::createFromFormat('m/d/Y', $expiration);
        }
        $currentQuantity = (int)$value($currentColumn, $row);
        $receivedQuantity = $receivedColumn === null ? $currentQuantity : (int)$value($receivedColumn, $row);
        $sourceLabel = $value($sourceLabelColumn, $row);
        if ($lotNumber === '' || !$expirationDate) { $skipped++; continue; }
        if ($productId < 1) {
            $safeName = $conn->real_escape_string($productName);
            $safeSku = $conn->real_escape_string($sku);
            $lookup = $productName !== '' ? "product_name = '$safeName'" : ($hasSku ? "sku = '$safeSku'" : '0');
            $productResult = $conn->query("SELECT id FROM products WHERE $lookup LIMIT 1");
            $productId = $productResult ? (int)($productResult->fetch_assoc()['id'] ?? 0) : 0;
        }
        if ($productId < 1) { $skipped++; continue; }
        $sourceValue = $value($sourceColumn, $row);
        if ($sourceValue === '') {
            $sourceValue = $sourceLabel !== ''
                ? 'google:' . $productId . ':' . $lotNumber . ':' . $sourceLabel
                : 'google:' . $rowIndex . ':' . $productId . ':' . $lotNumber;
        }
        $sourceKey = hash('sha256', $sheetId . ':' . $sourceValue);
        $expirationValue = $expirationDate->format('Y-m-d');
        $existingSourceCheck->bind_param('s', $sourceKey);
        if (!$existingSourceCheck->execute()) {
            $error = $existingSourceCheck->error;
            $existingSourceCheck->close();
            throw new RuntimeException('Unable to check existing batch records: ' . $error);
        }
        $existingBatch = $existingSourceCheck->get_result()->fetch_assoc();
        if (!$existingBatch && $expirationValue < $minimumBatchExpiration) {
            $skipped++;
            continue;
        }
        $existingBatchId = (int)($existingBatch['id'] ?? 0);
        if (inventoryIdentifierInUse($conn, 'lot_number', $lotNumber, $existingBatchId)) {
            $skipped++;
            continue;
        }
        if ($existingBatchId > 0) {
            $upsert = $conn->prepare('UPDATE product_batches SET product_id = ?, lot_number = ?, expiration_date = ?, manufacturing_date = COALESCE(?, manufacturing_date), received_quantity = ?, current_quantity = ?, source_label = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            if (!$upsert) { $skipped++; continue; }
            $upsert->bind_param('isssiisi', $productId, $lotNumber, $expirationValue, $manufacturingDate, $receivedQuantity, $currentQuantity, $sourceLabel, $existingBatchId);
        } else {
            $upsert = $conn->prepare('INSERT INTO product_batches (product_id, lot_number, expiration_date, manufacturing_date, received_quantity, current_quantity, created_by, source_key, source_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$upsert) { $skipped++; continue; }
            $upsert->bind_param('isssiiiss', $productId, $lotNumber, $expirationValue, $manufacturingDate, $receivedQuantity, $currentQuantity, $adminId, $sourceKey, $sourceLabel);
        }
        if (!$upsert->execute()) {
            $error = $upsert->error;
            $upsert->close();
            throw new RuntimeException('Unable to save imported batch: ' . $error);
        }
        $upsert->close();
        $synced++;
    }
    $existingSourceCheck->close();
    return [$synced, $skipped];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($isPharmacist && !in_array($action, ['record_sale', 'record_product_sale'], true)) {
        http_response_code(403);
        exit('This action is available to the owner only.');
    }

    if (in_array($action, ['record_sale', 'record_product_sale'], true) && !$onsiteAmountColumn) {
        http_response_code(500);
        $error = $onsiteSalesSchemaError;
    } elseif ($action === 'sync_google_sheet') {
        try {
            ensureUniqueInventoryIdentifierIndexes($conn);
            [$syncedRows, $skippedRows] = syncBatchesFromGoogleSheet($conn, $hasSku, $adminId);
            $message = "Google Sheet synced: $syncedRows batch rows updated" . ($skippedRows ? ", $skippedRows skipped." : '.');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($action === 'record_sale') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $saleQuantity = max(0, (int)($_POST['quantity'] ?? 0));
        if ($batchId < 1 || $saleQuantity < 1) {
            $error = 'Enter a valid sale quantity.';
        } else {
            $batchResult = $conn->query("SELECT b.product_id, b.current_quantity, b.recalled, b.expiration_date, p.product_name, COALESCE(p.price_piece, p.price, 0) AS unit_price FROM product_batches b JOIN products p ON p.id = b.product_id WHERE b.id = $batchId LIMIT 1");
            $batch = $batchResult ? $batchResult->fetch_assoc() : null;
            if (!$batch) {
                $error = 'Batch not found.';
            } elseif ((int)$batch['recalled'] === 1) {
                $error = 'Recalled batches cannot be sold.';
            } elseif ($batch['expiration_date'] < date('Y-m-d')) {
                $error = 'Expired batches cannot be sold.';
            } elseif ($saleQuantity > (int)$batch['current_quantity']) {
                $error = 'Sale quantity is greater than the available batch quantity.';
            } else {
                $conn->begin_transaction();
                $saleFailureReason = '';
                $onsiteOrderId = createOnsiteSaleOrder($conn, (int)$batch['product_id'], $saleQuantity, $adminId, $onsiteAmountColumn, $saleFailureReason);
                $updated = $conn->query("UPDATE product_batches SET current_quantity = current_quantity - $saleQuantity WHERE id = $batchId AND current_quantity >= $saleQuantity AND recalled = 0");
                $remainingStock = "GREATEST((COALESCE(pieces, 0) + (COALESCE(box, 0) * COALESCE(NULLIF(pieces_per_box, 0), 12))) - $saleQuantity, 0)";
                $stockUpdated = $conn->query("UPDATE products
                                              SET quantity = $remainingStock,
                                                  box = FLOOR(quantity / COALESCE(NULLIF(pieces_per_box, 0), 12)),
                                                  pieces = MOD(quantity, COALESCE(NULLIF(pieces_per_box, 0), 12))
                                              WHERE id = " . (int)$batch['product_id']);
                $movement = $conn->prepare("INSERT INTO batch_movements (batch_id, product_id, order_id, quantity, movement_type, reference, created_by) VALUES (?, ?, ?, ?, 'sold', ?, ?)");
                $reference = 'Onsite sale #' . $onsiteOrderId;
                if ($movement) {
                    $movement->bind_param('iiiisi', $batchId, $batch['product_id'], $onsiteOrderId, $saleQuantity, $reference, $adminId);
                    $movementSaved = $movement->execute();
                    $movement->close();
                } else {
                    $movementSaved = false;
                }
                if ($onsiteOrderId && $updated && $stockUpdated && $movementSaved) {
                    $conn->commit();
                    checkAndSendLowStockAlert($conn, (int)$batch['product_id']);
                    $orderNumber = formatOrderNumber($conn, $onsiteOrderId);
                    $unitPrice = (float)$batch['unit_price'];
                    $saleReceipt = [
                        'order_number' => $orderNumber,
                        'product_name' => $batch['product_name'],
                        'quantity' => $saleQuantity,
                        'unit_price' => $unitPrice,
                        'total' => $unitPrice * $saleQuantity,
                    ];
                    $message = 'Sale recorded. Order ' . $orderNumber . ' is available in Orders and Batch Sales.';
                } else {
                    $databaseError = $conn->error;
                    $conn->rollback();
                    $error = $saleFailureReason !== ''
                        ? $saleFailureReason
                        : 'Unable to record the onsite sale.' . ($databaseError !== '' ? ' ' . $databaseError : '');
                }
            }
        }
    } elseif ($action === 'record_product_sale') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $saleQuantity = max(0, (int)($_POST['quantity'] ?? 0));
        if ($productId < 1 || $saleQuantity < 1) {
            $error = 'Enter a valid sale quantity.';
        } else {
            $productResult = $conn->query("SELECT pieces, box, pieces_per_box FROM products WHERE id = $productId LIMIT 1");
            $product = $productResult ? $productResult->fetch_assoc() : null;
            $batchCheck = $conn->query("SELECT id FROM product_batches WHERE product_id = $productId LIMIT 1");
            $piecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
            $productStock = $product ? ((int)($product['pieces'] ?? 0) + ((int)($product['box'] ?? 0) * $piecesPerBox)) : 0;
            if (!$product) {
                $error = 'Product not found.';
            } elseif ($batchCheck && $batchCheck->num_rows > 0) {
                $error = 'Use the matching batch row to record this sale.';
            } elseif ($saleQuantity > $productStock) {
                $error = 'Sale quantity is greater than the available stock.';
            } else {
                $conn->begin_transaction();
                $saleFailureReason = '';
                $onsiteOrderId = createOnsiteSaleOrder($conn, $productId, $saleQuantity, $adminId, $onsiteAmountColumn, $saleFailureReason);
                $remainingStock = "GREATEST((COALESCE(pieces, 0) + (COALESCE(box, 0) * COALESCE(NULLIF(pieces_per_box, 0), 12))) - $saleQuantity, 0)";
                $stockUpdated = $conn->query("UPDATE products
                                              SET quantity = $remainingStock,
                                                  box = FLOOR(quantity / COALESCE(NULLIF(pieces_per_box, 0), 12)),
                                                  pieces = MOD(quantity, COALESCE(NULLIF(pieces_per_box, 0), 12))
                                              WHERE id = $productId");
                $movement = $conn->prepare("INSERT INTO batch_movements (batch_id, product_id, order_id, quantity, movement_type, reference, created_by) VALUES (NULL, ?, ?, ?, 'sold', ?, ?)");
                $reference = 'Onsite sale #' . $onsiteOrderId;
                if ($movement) {
                    $movement->bind_param('iiisi', $productId, $onsiteOrderId, $saleQuantity, $reference, $adminId);
                    $movementSaved = $movement->execute();
                    $movement->close();
                } else {
                    $movementSaved = false;
                }
                if ($onsiteOrderId && $stockUpdated && $movementSaved) {
                    $conn->commit();
                    checkAndSendLowStockAlert($conn, $productId);
                    $message = 'Onsite sale recorded as a paid order.';
                } else {
                    $databaseError = $conn->error;
                    $conn->rollback();
                    $error = $saleFailureReason !== ''
                        ? $saleFailureReason
                        : 'Unable to record the onsite sale.' . ($databaseError !== '' ? ' ' . $databaseError : '');
                }
            }
        }
    } elseif ($action === 'generate_barcode') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $barcode = '200' . str_pad((string)$productId, 10, '0', STR_PAD_LEFT);
        try {
            ensureUniqueInventoryIdentifierIndexes($conn);
            if (inventoryIdentifierInUse($conn, 'barcode', $barcode, $productId)) {
                $error = 'The generated barcode is already assigned to another product.';
            } else {
                $barcodeUpdate = $conn->prepare("UPDATE products SET barcode = ? WHERE id = ? AND (barcode IS NULL OR TRIM(barcode) = '')");
                if (!$barcodeUpdate) {
                    throw new RuntimeException('Unable to prepare barcode update: ' . $conn->error);
                }
                $barcodeUpdate->bind_param('si', $barcode, $productId);
                if (!$barcodeUpdate->execute()) {
                    throw new RuntimeException('Unable to save generated barcode: ' . $barcodeUpdate->error);
                }
                $barcodeUpdate->close();
                $message = 'Barcode generated for the product.';
            }
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($action === 'recall_batch') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $recall = $conn->prepare('UPDATE product_batches SET recalled = 1, recall_reason = ? WHERE id = ?');
        if ($recall) {
            $recall->bind_param('si', $reason, $batchId);
            $recall->execute();
            $recall->close();
            $message = 'Batch marked as recalled and blocked from sale.';
        }
    } elseif ($action === 'pull_out_expired_batch') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $pullOutProductId = 0;
        $pullOutSuccessMessage = '';
        if ($batchId < 1) {
            $error = 'Select a valid batch to pull out.';
        } elseif (!$conn->begin_transaction()) {
            $error = 'Unable to start the expired batch pull-out.';
        } else {
            try {
                $batchLock = $conn->prepare('SELECT product_id, current_quantity, recalled, expiration_date FROM product_batches WHERE id = ? FOR UPDATE');
                if (!$batchLock) {
                    throw new RuntimeException('Unable to check the expired batch: ' . $conn->error);
                }
                $batchLock->bind_param('i', $batchId);
                if (!$batchLock->execute()) {
                    throw new RuntimeException('Unable to check the expired batch: ' . $batchLock->error);
                }
                $batch = $batchLock->get_result()->fetch_assoc();
                $batchLock->close();
                if (!$batch) {
                    throw new DomainException('Batch not found.');
                }
                if ((int)$batch['recalled'] === 1) {
                    throw new DomainException('This batch has already been recalled or pulled out.');
                }
                if ($batch['expiration_date'] >= date('Y-m-d')) {
                    throw new DomainException('Only expired batches can be pulled out.');
                }
                $pullOutQuantity = (int)$batch['current_quantity'];
                if ($pullOutQuantity < 1) {
                    throw new DomainException('There is no remaining stock to pull out.');
                }

                $productId = (int)$batch['product_id'];
                $productLock = $conn->prepare('SELECT pieces, box, pieces_per_box FROM products WHERE id = ? FOR UPDATE');
                if (!$productLock) {
                    throw new RuntimeException('Unable to check product stock: ' . $conn->error);
                }
                $productLock->bind_param('i', $productId);
                if (!$productLock->execute()) {
                    throw new RuntimeException('Unable to check product stock: ' . $productLock->error);
                }
                $product = $productLock->get_result()->fetch_assoc();
                $productLock->close();
                if (!$product) {
                    throw new DomainException('The product for this batch could not be found.');
                }

                $piecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
                $productStock = (int)($product['pieces'] ?? 0) + ((int)($product['box'] ?? 0) * $piecesPerBox);
                $remainingStock = max(0, $productStock - $pullOutQuantity);
                $remainingBoxes = intdiv($remainingStock, $piecesPerBox);
                $remainingPieces = $remainingStock % $piecesPerBox;
                $recallReason = 'Expired - pulled out';

                $batchUpdate = $conn->prepare("UPDATE product_batches SET current_quantity = 0, recalled = 1, recall_reason = ? WHERE id = ? AND recalled = 0 AND expiration_date < CURDATE() AND current_quantity = ?");
                if (!$batchUpdate) {
                    throw new RuntimeException('Unable to update the expired batch: ' . $conn->error);
                }
                $batchUpdate->bind_param('sii', $recallReason, $batchId, $pullOutQuantity);
                if (!$batchUpdate->execute() || $batchUpdate->affected_rows !== 1) {
                    throw new RuntimeException('The batch changed before it could be pulled out. Refresh and try again.');
                }
                $batchUpdate->close();

                $productUpdate = $conn->prepare('UPDATE products SET quantity = ?, box = ?, pieces = ? WHERE id = ?');
                if (!$productUpdate) {
                    throw new RuntimeException('Unable to update product stock: ' . $conn->error);
                }
                $productUpdate->bind_param('iiii', $remainingStock, $remainingBoxes, $remainingPieces, $productId);
                if (!$productUpdate->execute()) {
                    throw new RuntimeException('Unable to update product stock: ' . $productUpdate->error);
                }
                $productUpdate->close();

                $movement = $conn->prepare("INSERT INTO batch_movements (batch_id, product_id, quantity, movement_type, reference, created_by) VALUES (?, ?, ?, 'recalled', ?, ?)");
                if (!$movement) {
                    throw new RuntimeException('Unable to record the pull-out: ' . $conn->error);
                }
                $reference = 'Expired batch pull-out #' . $batchId;
                $movement->bind_param('iiisi', $batchId, $productId, $pullOutQuantity, $reference, $adminId);
                if (!$movement->execute()) {
                    throw new RuntimeException('Unable to record the pull-out: ' . $movement->error);
                }
                $movement->close();

                if (!$conn->commit()) {
                    throw new RuntimeException('Unable to complete the expired batch pull-out.');
                }
                $pullOutProductId = $productId;
                $pullOutSuccessMessage = number_format($pullOutQuantity) . ' expired units pulled out and removed from available stock.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = $exception->getMessage();
            }
        }
        if ($error === '' && $pullOutProductId > 0) {
            checkAndSendLowStockAlert($conn, $pullOutProductId);
            $message = $pullOutSuccessMessage;
        }
    }
}

$search = trim($_GET['barcode'] ?? $_GET['search'] ?? '');
$searchSql = $conn->real_escape_string($search);
$expiryRange = (string)($_GET['expiry_range'] ?? 'all');
$inventoryPerPage = 10;
$inventoryPageParams = [
    'batches_page' => max(1, (int)($_GET['batches_page'] ?? 1)),
    'sales_page' => max(1, (int)($_GET['sales_page'] ?? 1)),
];
$days = in_array($expiryRange, ['30', '60', '90'], true) ? (int)$expiryRange : 90;
$skuSelect = $hasSku ? 'p.sku' : "'' AS sku";
$skuSearch = $hasSku ? " OR p.sku LIKE '%$searchSql%'" : '';
$searchCondition = $search !== ''
    ? "(p.product_name LIKE '%$searchSql%' OR p.barcode LIKE '%$searchSql%'$skuSearch OR b.lot_number LIKE '%$searchSql%' OR CAST(b.expiration_date AS CHAR) LIKE '%$searchSql%' OR DATE_FORMAT(b.expiration_date, '%b %d, %Y') LIKE '%$searchSql%')"
    : '';
$expiryCondition = $expiryRange === 'expired'
    ? 'b.expiration_date < CURDATE()'
    : (in_array($expiryRange, ['30', '60', '90'], true)
        ? "b.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $days DAY)"
        : '');
$whereConditions = array_filter([$searchCondition, $expiryCondition]);
$where = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
if (($_GET['action'] ?? '') === 'export_batches') {
    if ($isPharmacist) {
        http_response_code(403);
        exit('Batch export is available to the owner only.');
    }

    $exportBatches = $conn->query("SELECT b.product_id, b.lot_number, b.manufacturing_date, b.expiration_date,
            b.received_quantity, b.current_quantity, b.recalled, b.recall_reason,
            p.product_name, p.barcode, $skuSelect,
            DATEDIFF(b.expiration_date, CURDATE()) AS days_left
        FROM product_batches b
        JOIN products p ON p.id = b.product_id
        $where
        ORDER BY b.expiration_date ASC, p.product_name ASC");
    if ($exportBatches === false) {
        error_log('Batch inventory CSV export failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to export batch inventory.');
    }

    $filename = 'batch-inventory-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to create batch inventory export.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Product', 'SKU', 'Barcode', 'Lot Number', 'Manufacturing Date', 'Expiration Date', 'Days Left', 'Received Quantity', 'Available Quantity', 'Status', 'Recall / Pull Out Reason']);
    $safeCsvText = static function ($value): string {
        $text = (string)$value;
        return preg_match('/^[\t\r ]*[=+\-@]/', $text) ? "'" . $text : $text;
    };
    while ($batch = $exportBatches->fetch_assoc()) {
        $daysLeft = (int)$batch['days_left'];
        $isPulledOut = (int)$batch['recalled'] === 1 && ($batch['recall_reason'] ?? '') === 'Expired - pulled out';
        $status = (int)$batch['recalled'] === 1
            ? ($isPulledOut ? 'Pulled Out' : 'Recalled')
            : ($daysLeft < 0 ? 'Expired' : ($daysLeft <= 90 ? 'Expiring soon' : 'Good'));
        fputcsv($output, [
            $safeCsvText($batch['product_name']),
            $safeCsvText($batch['sku'] ?? ''),
            $safeCsvText($batch['barcode'] ?? ''),
            $safeCsvText($batch['lot_number']),
            $batch['manufacturing_date'] ?? '',
            $batch['expiration_date'],
            $daysLeft,
            (int)$batch['received_quantity'],
            (int)$batch['current_quantity'],
            $status,
            $safeCsvText($batch['recall_reason'] ?? ''),
        ]);
    }
    fclose($output);
    exit();
}
$batchCountResult = $conn->query("SELECT COUNT(*) AS total FROM product_batches b JOIN products p ON p.id = b.product_id $where");
if ($batchCountResult === false) {
    die('Batch inventory count error: ' . htmlspecialchars($conn->error));
}
$totalBatches = (int)$batchCountResult->fetch_assoc()['total'];
$totalBatchPages = max(1, (int)ceil($totalBatches / $inventoryPerPage));
$inventoryPageParams['batches_page'] = min($inventoryPageParams['batches_page'], $totalBatchPages);
$batchOffset = ($inventoryPageParams['batches_page'] - 1) * $inventoryPerPage;
$batches = $conn->query("SELECT b.*, p.product_name, p.barcode, COALESCE(p.price_piece, p.price, 0) AS unit_price, $skuSelect, DATEDIFF(b.expiration_date, CURDATE()) AS days_left FROM product_batches b JOIN products p ON p.id = b.product_id $where ORDER BY b.expiration_date ASC, p.product_name ASC LIMIT $inventoryPerPage OFFSET $batchOffset");
if ($batches === false) {
    die('Batch inventory query error: ' . htmlspecialchars($conn->error));
}
$stockExpression = '(COALESCE(p.pieces, 0) + (COALESCE(p.box, 0) * COALESCE(NULLIF(p.pieces_per_box, 0), 12)))';
$missingBatchProducts = $conn->query("SELECT p.id, p.product_name, $skuSelect, p.barcode, $stockExpression AS quantity FROM products p LEFT JOIN product_batches b ON b.product_id = p.id WHERE b.id IS NULL ORDER BY p.product_name ASC");
$expirySummary = $conn->query("SELECT
    SUM(expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS expiring_30,
    SUM(expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)) AS expiring_60,
    SUM(expiration_date < CURDATE()) AS expired
    FROM product_batches
    WHERE recalled = 0 AND current_quantity > 0");
if ($expirySummary === false) {
    die('Batch expiry summary error: ' . htmlspecialchars($conn->error));
}
$expirySummaryRow = $expirySummary->fetch_assoc();
$nearExpiry30Count = (int)($expirySummaryRow['expiring_30'] ?? 0);
$nearExpiry60Count = (int)($expirySummaryRow['expiring_60'] ?? 0);
$expiredCount = (int)($expirySummaryRow['expired'] ?? 0);
$movementCountResult = $conn->query("SELECT COUNT(DISTINCT ps.id) AS total
    FROM batch_movements bm
    JOIN pos_sales ps ON ps.id = bm.pos_sale_id
    WHERE bm.movement_type = 'sold'");
if ($movementCountResult === false) {
    die('Batch POS receipts count error: ' . htmlspecialchars($conn->error));
}
$totalSales = (int)$movementCountResult->fetch_assoc()['total'];
$totalSalesPages = max(1, (int)ceil($totalSales / $inventoryPerPage));
$inventoryPageParams['sales_page'] = min($inventoryPageParams['sales_page'], $totalSalesPages);
$salesOffset = ($inventoryPageParams['sales_page'] - 1) * $inventoryPerPage;
$movements = $conn->query("SELECT ps.id AS pos_sale_id, SUM(bm.quantity) AS quantity,
        COUNT(DISTINCT bm.product_id) AS product_count, ps.total_amount,
        CONCAT(
            'SALE-',
            DATE_FORMAT(ps.created_at, '%y%m%d'),
            '-',
            LPAD((
                SELECT COUNT(*)
                FROM pos_sales earlier
                WHERE earlier.created_at >= DATE(ps.created_at)
                  AND earlier.created_at < DATE(ps.created_at) + INTERVAL 1 DAY
                  AND earlier.id <= ps.id
            ), 3, '0')
        ) AS sale_number
    FROM batch_movements bm
    JOIN pos_sales ps ON ps.id = bm.pos_sale_id
    WHERE bm.movement_type = 'sold'
    GROUP BY ps.id, ps.created_at, ps.total_amount
    ORDER BY ps.created_at DESC, ps.id DESC
    LIMIT $inventoryPerPage OFFSET $salesOffset");
if ($movements === false) {
    die('Batch POS receipts query error: ' . htmlspecialchars($conn->error));
}
$movementRows = [];
$posSaleIds = [];
while ($movement = $movements->fetch_assoc()) {
    $movement['pos_sale_id'] = (int)$movement['pos_sale_id'];
    $movement['quantity'] = (int)$movement['quantity'];
    $movement['product_count'] = (int)$movement['product_count'];
    $movement['total_amount'] = (float)$movement['total_amount'];
    $movementRows[] = $movement;
    if ($movement['pos_sale_id'] > 0) {
        $posSaleIds[$movement['pos_sale_id']] = $movement['pos_sale_id'];
    }
}
$saleDetails = [];
if ($posSaleIds) {
    $posSaleIdList = implode(',', array_map('intval', array_values($posSaleIds)));
    $posSales = $conn->query("SELECT id, total_amount, payment_reference FROM pos_sales WHERE id IN ($posSaleIdList)");
    if ($posSales === false) {
        die('Batch POS sale details error: ' . htmlspecialchars($conn->error));
    }
    while ($posSale = $posSales->fetch_assoc()) {
        $saleId = (int)$posSale['id'];
        $saleDetails['pos_' . $saleId] = [
            'items' => [],
            'sale_total' => (float)$posSale['total_amount'],
            'cash_received' => null,
            'change_due' => null,
        ];
        if (preg_match('/POS cash ([0-9]+(?:\.[0-9]+)?); change ([0-9]+(?:\.[0-9]+)?)/', (string)$posSale['payment_reference'], $paymentMatch)) {
            $saleDetails['pos_' . $saleId]['cash_received'] = (float)$paymentMatch[1];
            $saleDetails['pos_' . $saleId]['change_due'] = (float)$paymentMatch[2];
        }
    }
    $posSaleItems = $conn->query("SELECT sale_id, product_name, quantity, total_price
        FROM pos_sale_items
        WHERE sale_id IN ($posSaleIdList)
        ORDER BY sale_id ASC, id ASC");
    if ($posSaleItems === false) {
        die('Batch POS sale item details error: ' . htmlspecialchars($conn->error));
    }
    while ($posSaleItem = $posSaleItems->fetch_assoc()) {
        $saleKey = 'pos_' . (int)$posSaleItem['sale_id'];
        if (!isset($saleDetails[$saleKey])) {
            continue;
        }
        $saleDetails[$saleKey]['items'][] = [
            'product_name' => (string)$posSaleItem['product_name'],
            'quantity' => (int)$posSaleItem['quantity'],
            'line_total' => (float)$posSaleItem['total_price'],
        ];
    }
}

$expiryAlerts = capstoneNotifyExpiringBatches($conn, 30);
if (!$expiryAlerts['success']) {
    error_log('Inventory batch expiry notifications were incomplete: ' . implode(' ', $expiryAlerts['errors'] ?? [$expiryAlerts['error'] ?? 'Unknown error.']));
}
$currentPage = basename($_SERVER['PHP_SELF']);

function inventoryPaginationItems($page, $totalPages) {
    if ($totalPages <= 5) return range(1, $totalPages);
    if ($page <= 3) return [1, 2, 3, '...', $totalPages];
    if ($page >= $totalPages - 2) return [1, '...', $totalPages - 2, $totalPages - 1, $totalPages];
    return [1, '...', $page - 1, $page, $page + 1, '...', $totalPages];
}

function inventoryPaginationUrl($pageParam, $targetPage, $tab, $search, $expiryRange, $pageParams) {
    $params = [
        'tab' => $tab,
        'barcode' => $search,
        'expiry_range' => $expiryRange,
    ];
    foreach ($pageParams as $param => $page) {
        $params[$param] = $param === $pageParam ? $targetPage : $page;
    }
    return 'inventory_batches.php?' . http_build_query($params);
}

function renderInventoryPager($page, $totalPages, $pageParam, $tab, $search, $expiryRange, $pageParams) {
    if ($totalPages <= 1) return '';
    $items = inventoryPaginationItems($page, $totalPages);
    $html = '<nav class="inventory-pagination" aria-label="Inventory pages">';
    if ($page > 1) {
        $previousUrl = htmlspecialchars(inventoryPaginationUrl($pageParam, $page - 1, $tab, $search, $expiryRange, $pageParams), ENT_QUOTES, 'UTF-8');
        $html .= '<a class="inventory-pager-link" href="' . $previousUrl . '" aria-label="Previous page">&lsaquo;</a>';
    }
    foreach ($items as $item) {
        if ($item === '...') {
            $html .= '<span class="inventory-pager-link disabled" aria-hidden="true">...</span>';
            continue;
        }
        $url = htmlspecialchars(inventoryPaginationUrl($pageParam, $item, $tab, $search, $expiryRange, $pageParams), ENT_QUOTES, 'UTF-8');
        $active = $item === $page;
        $html .= '<a class="inventory-pager-link' . ($active ? ' active' : '') . '" href="' . $url . '"' . ($active ? ' aria-current="page"' : '') . '>' . $item . '</a>';
    }
    if ($page < $totalPages) {
        $nextUrl = htmlspecialchars(inventoryPaginationUrl($pageParam, $page + 1, $tab, $search, $expiryRange, $pageParams), ENT_QUOTES, 'UTF-8');
        $html .= '<a class="inventory-pager-link" href="' . $nextUrl . '" aria-label="Next page">&rsaquo;</a>';
    }
    return $html . '</nav>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Batch and Expiry Tracking</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
<style>
*{box-sizing:border-box}body{margin:0;background:#f7f8fa;color:#20232a;font-family:'Segoe UI',Tahoma,sans-serif}.content{margin-left:244px;padding:28px;min-height:100vh}.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.page-header h1{margin:0 0 5px;font-size:22px}.page-header p{margin:0;color:#7b8189;font-size:11px}.panel{background:#fff;border:1px solid #ebedf0;border-radius:9px;box-shadow:0 2px 10px rgba(25,30,38,.04);padding:16px;margin-bottom:16px}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px}.metric{padding:15px;background:#fff;border:1px solid #ebedf0;border-radius:9px}.metric strong{display:block;font-size:21px}.metric span{color:#737981;font-size:10px}.metric.warning strong{color:#d18b08}.metric.danger strong{color:#d33b59}.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.toolbar form{display:flex;gap:8px;flex:1}.toolbar input,.toolbar select,.panel input,.panel select{height:32px;padding:0 9px;border:1px solid #dfe4e9;border-radius:6px;font-size:11px;background:#fff}.toolbar input{flex:1;min-width:180px}.button{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:32px;padding:0 12px;border:0;border-radius:6px;background:#ed1740;color:#fff;font-size:11px;font-weight:600;cursor:pointer;text-decoration:none}.button.secondary{background:#fff;color:#555e68;border:1px solid #dfe4e9}.button.warning{background:#e6a51d}.button.danger{background:#d33b59}.form-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.form-grid label{display:flex;flex-direction:column;gap:5px;color:#626a74;font-size:10px;font-weight:600}.form-grid .button{align-self:end}.table-scroll{overflow:auto}.batch-table{width:100%;min-width:1000px;border-collapse:collapse}.batch-table th,.batch-table td{padding:10px 8px;border-bottom:1px solid #f0f1f3;text-align:left;font-size:10px;white-space:nowrap}.batch-table th{color:#68707a;background:#fbfcfd;font-size:9px}.badge{display:inline-flex;padding:4px 7px;border-radius:5px;font-size:9px}.badge.good{background:#e9f8ef;color:#278658}.badge.warning{background:#fff5df;color:#a8730d}.badge.danger{background:#ffe8ed;color:#d33b59}.badge.recalled{background:#eee;color:#555}.inline-form{display:inline-flex;gap:4px;align-items:center}.inline-form input{width:60px;height:27px;padding:0 6px;border:1px solid #dfe4e9;border-radius:5px;font-size:10px}.inline-form .button{height:27px;padding:0 8px;font-size:9px}.empty{text-align:center;padding:25px;color:#7b8189;font-size:11px}.scanner-note{margin-top:8px;color:#7b8189;font-size:10px}.scan-box{display:flex;gap:8px;align-items:center}.scan-box input{flex:1}.video-wrap{display:none;margin-top:10px}.video-wrap.open{display:block}.video-wrap video{width:100%;max-width:360px;border-radius:7px;background:#111}.message{padding:11px 13px;border-radius:7px;margin-bottom:14px;font-size:11px}.message.success{background:#e9f8ef;color:#278658}.message.error{background:#ffe8ed;color:#d33b59}@media(max-width:800px){.content{margin-left:0;padding:20px 14px}.metrics{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr 1fr}.page-header{align-items:flex-start;flex-direction:column;gap:12px}}@media(max-width:500px){.form-grid{grid-template-columns:1fr}}
</style>
<style>
html, body { min-height: 100%; overflow-x: hidden; overflow-y: auto; }
.content { min-height: 100vh; overflow: visible; }
body { background:#f8f9fb; }
.content { padding:36px 40px; }
.metric { height:112px; display:flex; flex-direction:column; justify-content:center; }
.metric strong { font-size:32px; line-height:32px; font-weight:800; }
.metric span { font-size:14px; line-height:16px; font-weight:600; }
.batch-table th { background:#e9ecef; color:#495057; }
.batch-table th:first-child, .batch-table td:first-child { text-align:left; }
.batch-table th:not(:first-child), .batch-table td:not(:first-child) { text-align:center; }
.table-scroll { max-height: none; overflow: auto; }
@media (min-width: 1200px) {
    .panel { padding:20px; }
    .batch-table { min-width:1200px; }
    #recent-sales .batch-table { min-width:760px; }
    .batch-table th, .batch-table td { padding:15px 14px; font-size:13px; }
    .batch-table th { font-size:12px; }
    .badge { padding:6px 9px; font-size:11px; }
    .inline-form input { width:76px; height:31px; font-size:12px; }
    .inline-form .button { height:31px; padding:0 10px; font-size:11px; }
}
@media (max-width: 720px) {
    html, body { overflow-x: hidden; overflow-y: auto; }
    .content { margin-left: 0; min-height: 100vh; padding: 18px 14px; overflow: visible; }
    .table-scroll { max-height: none; }
}
.page-header h1 { color:#252a30; font-family:'Segoe UI', Tahoma, sans-serif; font-size:30px; font-weight:800; }
.inventory-tabs { display:flex; gap:0; width:max-content; max-width:100%; overflow-x:auto; margin:0 0 16px; padding:5px 6px 0; border:1px solid #e6e9ed; border-radius:12px; background:#fff; box-shadow:0 3px 12px rgba(25,30,38,.05); }
.inventory-tabbar { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:16px; }
.inventory-tabbar .inventory-tabs { margin:0; }
.inventory-tabbar .physical-pos-button { flex:0 0 auto; }
.inventory-tabs > .inventory-tab-button { appearance:none; -webkit-appearance:none; display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto; min-width:140px; padding:12px 18px 9px; border:0; border-bottom:3px solid transparent; border-radius:0; background:#fff !important; color:#626a74 !important; font:600 14px/20px 'Segoe UI',Tahoma,sans-serif; cursor:pointer; }
.inventory-tabs > .inventory-tab-button[aria-selected="true"] { border-bottom-color:#ed1740 !important; background:#fff !important; color:#a51c38 !important; }
.inventory-tabs > .inventory-tab-button[aria-selected="true"] i { color:#a51c38 !important; }
.inventory-tab-button:focus-visible { outline:2px solid #ed1740; outline-offset:-2px; }
.inventory-tab-panel[hidden] { display:none !important; }
.inventory-sales-hidden { display:none !important; }
.inventory-sales-intro-hidden { visibility:hidden; }
.inventory-list-footer { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px 0 0; color:#727984; font-size:11px; font-weight:600; }
.inventory-pagination { display:flex; align-items:center; justify-content:center; gap:8px; flex-wrap:wrap; }
.inventory-pager-link { min-width:38px; height:38px; padding:0 8px; border:1px solid #e0e6f0; border-radius:8px; background:#fff; color:#4b5563; display:inline-flex; align-items:center; justify-content:center; text-align:center; text-decoration:none; font-size:14px; font-weight:600; transition:all .2s ease; }
.inventory-pager-link:hover { border-color:#d32f2f; color:#d32f2f; background:#fff5f5; }
.inventory-pager-link.active { background:#d32f2f; color:#fff; border-color:#d32f2f; box-shadow:0 4px 12px rgba(211,47,47,.2); }
.inventory-pager-link.disabled { border:0; background:none; color:#010813; cursor:default; pointer-events:none; }
.inventory-list-footer[hidden] { display:none; }
@media(max-width:720px) { .inventory-list-footer { align-items:flex-start; flex-direction:column; } }
.inventory-toolbar { display:flex; align-items:center; gap:8px; }
.batch-inventory-panel { padding-top:12px; }
.batch-inventory-toolbar { justify-content:space-between; align-items:center; margin-bottom:12px; }
.batch-inventory-toolbar > h2 { flex:0 0 auto; white-space:nowrap; }
.batch-inventory-toolbar > .inventory-toolbar { flex:1 1 720px; min-width:0; justify-content:flex-end; }
.batch-inventory-toolbar .inventory-search-form { flex:1 1 360px; min-width:0; }
.batch-inventory-toolbar .inventory-sync-form { flex:0 0 auto; }
.batch-inventory-table { width:100%; min-width:0; table-layout:fixed; }
.batch-inventory-table th, .batch-inventory-table td { padding:10px 7px; white-space:normal; overflow-wrap:anywhere; }
.batch-inventory-table th:nth-child(1) { width:21%; }
.batch-inventory-table th:nth-child(2) { width:13%; }
.batch-inventory-table th:nth-child(3) { width:10%; }
.batch-inventory-table th:nth-child(4) { width:14%; }
.batch-inventory-table th:nth-child(5), .batch-inventory-table th:nth-child(6) { width:8%; }
.batch-inventory-table th:nth-child(7) { width:10%; }
.batch-inventory-table th:nth-child(8) { width:16%; }
.batch-inventory-table .inline-form { flex-wrap:wrap; justify-content:center; }
.batch-inventory-table .inline-form input { width:70px; max-width:100%; min-width:0; }
.inventory-search-form { display:flex; align-items:center; flex:1 1 0; min-width:0; gap:8px; margin:0; }
.inventory-search-form .scan-box { display:flex; align-items:center; flex:1 1 auto; min-width:0; gap:8px; }
.inventory-search-field { position:relative; display:flex; flex:1 1 auto; min-width:0; height:40px; align-items:center; gap:8px; padding:0 10px; border:1px solid #eceef1; border-radius:8px; background:#fff; color:#969ca4; }
.inventory-search-field > i { position:absolute; top:50%; left:10px; transform:translateY(-50%); font-size:16px; pointer-events:none; }
.inventory-search-field input { flex:1 1 auto; width:100%; min-width:0; height:100%; margin:0; padding:0 36px 0 26px; border:0; outline:0; background:transparent; box-shadow:none; color:#333; font-size:13px; font-weight:600; }
.inventory-search-field input:focus { border:0; outline:0; box-shadow:none; }
.inventory-camera-button { position:absolute; top:50%; right:8px; display:inline-flex; width:28px; height:28px; align-items:center; justify-content:center; padding:0; transform:translateY(-50%); border:0; border-radius:0; background:transparent; color:#68717c; font-size:16px; cursor:pointer; }
.inventory-camera-button:hover, .inventory-camera-button:focus-visible { color:#ed1b3b; }
.inventory-camera-button:focus-visible { outline:2px solid #ed1b3b; outline-offset:1px; border-radius:4px; }
.inventory-search-form select { flex:0 0 145px; width:145px; }
.inventory-icon-button { width:42px; height:42px; flex:0 0 42px; padding:0; }
.inventory-sync-form { display:flex; flex:0 0 auto; margin:0; }
.barcode-scanner-modal { display:none; position:fixed; inset:0; z-index:1200; align-items:center; justify-content:center; padding:20px; background:rgba(23,29,38,.64); }
.barcode-scanner-modal.open { display:flex; }
.barcode-scanner-dialog { width:min(480px,100%); padding:18px; border-radius:10px; background:#fff; box-shadow:0 20px 60px rgba(15,23,42,.28); }
.barcode-scanner-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.barcode-scanner-header h2 { margin:0; color:#20232a; font-size:18px; }
.barcode-scanner-status { display:flex; min-height:20px; align-items:center; gap:8px; margin:0 0 12px; color:#626a74; font-size:13px; }
.barcode-scanner-status[data-state="starting"]::before, .barcode-scanner-status[data-state="scanning"]::before { width:13px; height:13px; flex:0 0 13px; border:2px solid #dfe4e9; border-top-color:#ed1b3b; border-radius:50%; content:""; animation:barcode-scan-spin .8s linear infinite; }
.barcode-scanner-status[data-state="error"] { color:#c43149; }
.barcode-camera-select { width:100%; height:40px !important; margin:0 0 10px; padding:0 9px !important; border:1px solid #dfe4e9; border-radius:6px; background:#fff; color:#303740; font-size:13px; }
.barcode-camera-frame { position:relative; overflow:hidden; border-radius:7px; background:#101820; }
.barcode-camera-frame video { display:block; width:100%; max-height:55vh; aspect-ratio:4 / 3; object-fit:contain; }
.barcode-scan-guide { position:absolute; top:50%; left:50%; width:82%; height:30%; transform:translate(-50%,-50%); border:2px solid rgba(255,255,255,.9); border-radius:8px; box-shadow:0 0 0 999px rgba(0,0,0,.12); pointer-events:none; }
.barcode-scanner-header button, .barcode-scanner-actions button { width:auto; height:36px; padding:0 12px; border:1px solid #dfe4e9; border-radius:6px; background:#fff; color:#4e5660; font-size:13px; cursor:pointer; }
.barcode-scanner-header .barcode-scanner-close { width:32px; height:32px; padding:0; border:0; background:transparent; color:#87909a; }
.barcode-scanner-actions { display:flex; justify-content:flex-end; margin-top:12px; }
@keyframes barcode-scan-spin { to { transform:rotate(360deg); } }

.sale-add-button { height:30px; padding:0 10px; font-size:11px; }
.sale-add-button:hover, .sale-add-button:focus-visible { border-color:#ed1740; color:#ed1740; }
.batch-sale-dialog { width:min(420px, calc(100vw - 32px)); padding:22px; border:0; border-radius:10px; color:#252a30; box-shadow:0 18px 50px rgba(15,23,42,.25); }
.batch-sale-dialog::backdrop { background:rgba(15,23,42,.48); }
.batch-sale-dialog h2 { margin:0 0 5px; font-size:18px; }
.batch-sale-dialog-product { margin:0 0 16px; color:#737981; font-size:13px; }
.batch-sale-dialog label { display:block; margin-bottom:6px; font-size:12px; font-weight:700; }
.batch-sale-price { display:flex; justify-content:space-between; gap:12px; margin-bottom:14px; padding:10px; border-radius:6px; background:#f7f8fa; color:#626a74; font-size:12px; }
.batch-sale-price strong { color:#252a30; }
.batch-sale-quantity { display:flex; gap:8px; align-items:center; }
.batch-sale-quantity button { width:40px; height:40px; flex:0 0 40px; border:1px solid #dfe4e9; border-radius:6px; background:#fff; color:#252a30; font-size:20px; cursor:pointer; }
.batch-sale-quantity button:hover:not(:disabled) { border-color:#ed1740; color:#ed1740; }
.batch-sale-quantity button:disabled { color:#aeb4bc; cursor:not-allowed; }
.batch-sale-dialog input[type="number"] { width:100%; height:40px; padding:0 10px; border:1px solid #dfe4e9; border-radius:6px; font:inherit; text-align:center; }
.batch-sale-dialog input[type="number"]:focus { border-color:#ed1740; outline:2px solid rgba(237,23,64,.12); }
.batch-sale-available, .batch-sale-order-note { margin:8px 0 0; color:#626a74; font-size:12px; line-height:1.5; }
.batch-sale-order-note { padding:10px; border-radius:6px; background:#f7f8fa; }
.batch-sale-dialog-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:18px; }
.batch-sale-dialog-actions .button { min-height:36px; }
.sale-details-dialog { width:min(560px, calc(100vw - 32px)); max-height:calc(100vh - 32px); overflow:auto; padding:22px; border:0; border-radius:10px; color:#252a30; box-shadow:0 18px 50px rgba(15,23,42,.25); }
.sale-details-dialog::backdrop { background:rgba(15,23,42,.48); }
.sale-details-dialog h2 { margin:0 0 14px; font-size:19px; }
.sale-detail-items { width:100%; border-collapse:collapse; }
.sale-detail-items th,.sale-detail-items td { padding:9px 7px; border-bottom:1px solid #edf0f2; text-align:left; font-size:12px; }
.sale-detail-items th { color:#68707a; font-size:11px; }
.sale-detail-payment { display:grid; gap:9px; margin-top:16px; }
.sale-detail-payment div { display:flex; justify-content:space-between; gap:12px; font-size:13px; }
.sale-detail-payment div:last-child { padding-top:9px; border-top:1px solid #edf0f2; font-weight:800; }
.sale-detail-empty { padding:16px 0; color:#737981; font-size:12px; }
.sale-details-close { display:flex; justify-content:flex-end; margin-top:18px; }
.sale-details-close .button { min-height:36px; }
.batch-sale-receipt { margin-top:16px; padding:14px; border:1px solid #b8e6c8; border-radius:8px; background:#f0fbf4; }
.batch-sale-receipt h3 { margin:0 0 8px; color:#207548; font-size:14px; }
.batch-sale-receipt table { width:100%; border-collapse:collapse; }
.batch-sale-receipt th, .batch-sale-receipt td { padding:7px 8px; border-bottom:1px solid #d7efdf; text-align:left; font-size:12px; }
.batch-sale-receipt th { color:#626a74; font-weight:600; }
.batch-sale-receipt a { color:#207548; font-weight:700; text-decoration:underline; }
.inventory-sync-form .button, .inventory-toolbar > .button { white-space:nowrap; }
@media (max-width:720px) {
    .inventory-toolbar { flex-wrap:wrap; }
    .inventory-search-form { flex:1 1 100%; }
    .inventory-sync-form { margin-left:auto; }
}
@media (max-width:480px) {
    .inventory-search-form { flex-wrap:wrap; }
    .inventory-search-form .scan-box { flex:1 1 100%; }
    .inventory-search-form select { flex:1 1 auto; width:auto; }
}
body.pharmacist-sales-page .inventory-sync-form,
body.pharmacist-sales-page form:has(input[name="action"][value="generate_barcode"]) { display:none; }
body.pharmacist-sales-page .pharmacist-batch-table th:last-child,
body.pharmacist-sales-page .pharmacist-batch-table td:last-child { display:none; }
@media(max-width:720px) { body.pharmacist-sales-page .content { padding:20px 14px; } }
</style>
</head>
<body class="<?php echo $isPharmacist ? 'pharmacist-sales-page' : ''; ?>">
<?php include 'appbar_owner.php'; include 'sidebar_owner.php'; ?>
<main class="content">
<header class="page-header">
    <div id="inventoryPageIntro">
        <?php if ($isPharmacist): ?>
            <h1>Batch Inventory</h1>
            <p>Search inventory and record onsite pharmacy sales.</p>
        <?php else: ?>
            <h1>Batch Inventory</h1>
            <p>Manage lot numbers, expiration dates, barcode lookup, and batch sales.</p>
        <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <a class="button physical-pos-button" id="physicalPosButton" href="physical_pos.php"><i class="bi bi-cart3"></i> Physical Sale / POS</a>
    </div>
</header>
<?php if ($message): ?><div class="message success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="message error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<section class="metrics" id="inventoryMetrics"><div class="metric warning"><strong><?php echo number_format($nearExpiry30Count); ?></strong><span>Expiring within 30 days</span></div><div class="metric warning"><strong><?php echo number_format($nearExpiry60Count); ?></strong><span>Expiring within 60 days</span></div><div class="metric danger"><strong><?php echo number_format($expiredCount); ?></strong><span>Expired batches with stock</span></div></section>
<?php if ($missingBatchProducts && $missingBatchProducts->num_rows > 0): ?>
<section class="panel" style="border-color:#f3cf78;background:#fff8e7;color:#8b5d00"><strong style="font-size:12px">Products without batch details</strong><p style="margin:6px 0 0;font-size:10px">These older products do not yet have a Lot Number and Expiration Date. New products cannot be added without both details.</p><div style="margin-top:8px;font-size:10px"><?php while ($missingProduct = $missingBatchProducts->fetch_assoc()): ?><?php echo htmlspecialchars($missingProduct['product_name']); ?> (SKU: <?php echo htmlspecialchars($missingProduct['sku'] ?: 'No SKU'); ?>, Stock: <?php echo (int)$missingProduct['quantity']; ?>)<br><?php endwhile; ?></div></section>
<?php endif; ?>
<section class="panel batch-inventory-panel">
    <div class="toolbar batch-inventory-toolbar">
        <h2 style="margin:0;font-size:15px">Batch Inventory</h2>
        <div class="inventory-toolbar">
            <form method="get" class="inventory-search-form">
                <div class="scan-box">
                    <div class="inventory-search-field">
                        <input id="barcodeSearch" name="barcode" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search product, SKU, lot, barcode, or expiry date">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <button class="inventory-camera-button" type="button" id="cameraButton" aria-label="Search by barcode" title="Scan barcode to search" aria-pressed="false"><i class="bi bi-upc-scan"></i></button>
                    </div>
                </div>
                <select name="expiry_range" aria-label="Filter batch expiration" onchange="this.form.submit()">
                    <option value="all" <?php echo $expiryRange === 'all' ? 'selected' : ''; ?>>All expiration dates</option>
                    <option value="30" <?php echo $expiryRange === '30' ? 'selected' : ''; ?>>Next 30 days</option>
                    <option value="60" <?php echo $expiryRange === '60' ? 'selected' : ''; ?>>Next 60 days</option>
                    <option value="90" <?php echo $expiryRange === '90' ? 'selected' : ''; ?>>Next 90 days</option>
                    <option value="expired" <?php echo $expiryRange === 'expired' ? 'selected' : ''; ?>>Expired</option>
                </select>
            </form>
            <form method="post" class="inventory-sync-form">
                <input type="hidden" name="action" value="sync_google_sheet">
                <button class="button secondary" type="submit"><i class="bi bi-arrow-repeat"></i> Sync Google Sheet</button>
            </form>
            <?php if (!$isPharmacist): ?>
                <a class="button export-batches-button" href="?<?php echo htmlspecialchars(http_build_query(['action' => 'export_batches', 'barcode' => $search, 'expiry_range' => $expiryRange]), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-box-arrow-up" aria-hidden="true"></i> Export
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-scroll">
        <table class="batch-table pharmacist-batch-table batch-inventory-table">
            <thead><tr><th>Product</th><th>Lot Number</th><th>MFG Date</th><th>Expiration</th><th>Received</th><th>Available</th><th>Status</th><th>Recall / Pull Out</th></tr></thead>
            <tbody>
            <?php if($batches && $batches->num_rows): while($batch=$batches->fetch_assoc()): $daysLeft=(int)$batch['days_left']; $isPulledOut=(int)$batch['recalled']===1 && ($batch['recall_reason'] ?? '')==='Expired - pulled out'; $statusClass=$batch['recalled']?'recalled':($daysLeft<0?'danger':($daysLeft<=30?'danger':($daysLeft<=90?'warning':'good'))); $status=$batch['recalled']?($isPulledOut?'Pulled Out':'Recalled'):($daysLeft<0?'Expired':($daysLeft<=90?'Expiring soon':'Good')); ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($batch['product_name']); ?></strong><br><small>SKU: <?php echo htmlspecialchars($batch['sku'] ?: 'No SKU'); ?></small><br><small>Barcode: <?php echo htmlspecialchars($batch['barcode'] ?: 'No barcode'); ?></small></td>
                    <td><?php echo htmlspecialchars($batch['lot_number']); ?></td>
                    <td><?php echo !empty($batch['manufacturing_date']) ? date('M d, Y', strtotime($batch['manufacturing_date'])) : '—'; ?></td>
                    <td><?php echo date('M d, Y',strtotime($batch['expiration_date'])); ?><br><small><?php echo $daysLeft < 0 ? abs($daysLeft).' days overdue' : $daysLeft.' days left'; ?></small></td>
                    <td><?php echo number_format((int)$batch['received_quantity']); ?></td>
                    <td><?php echo number_format((int)$batch['current_quantity']); ?></td>
                    <td><span class="badge <?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                    <td>
                        <?php if ((int)$batch['recalled'] === 1): ?>
                            <?php echo htmlspecialchars($batch['recall_reason'] ?: 'Recalled'); ?>
                        <?php elseif ($daysLeft < 0 && (int)$batch['current_quantity'] > 0): ?>
                            <form method="post" class="inline-form" onsubmit="return confirm('Pull out all remaining expired stock and remove it from available inventory?');">
                                <input type="hidden" name="action" value="pull_out_expired_batch">
                                <input type="hidden" name="batch_id" value="<?php echo (int)$batch['id']; ?>">
                                <button class="button danger" type="submit">Pull Out</button>
                            </form>
                        <?php elseif ($daysLeft < 0): ?>
                            No stock to pull out
                        <?php else: ?>
                            <form method="post" class="inline-form" onsubmit="return confirm('Recall this batch? It will be blocked from sale.');">
                                <input type="hidden" name="action" value="recall_batch">
                                <input type="hidden" name="batch_id" value="<?php echo (int)$batch['id']; ?>">
                                <input name="reason" placeholder="Reason" required>
                                <button class="button danger" type="submit">Recall</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8" class="empty">No batches found. Receive a batch above or scan a barcode to search.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<div class="barcode-scanner-modal" id="barcodeScannerModal" role="dialog" aria-modal="true" aria-labelledby="barcodeScannerTitle" tabindex="-1">
    <div class="barcode-scanner-dialog">
        <div class="barcode-scanner-header"><h2 id="barcodeScannerTitle">Scan product barcode</h2><button type="button" class="barcode-scanner-close" id="closeBarcodeScanner" aria-label="Close scanner"><i class="bi bi-x-lg"></i></button></div>
        <p class="barcode-scanner-status" id="barcodeScannerStatus" role="status" aria-live="polite" data-state="idle">Center the barcode, hold steady, and avoid glare.</p>
        <select class="barcode-camera-select" id="barcodeCameraSelect" aria-label="Select camera"></select>
        <div class="barcode-camera-frame"><video id="barcodeVideo" playsinline muted></video><div class="barcode-scan-guide" aria-hidden="true"></div></div>
        <div class="barcode-scanner-actions"><button type="button" id="cancelBarcodeScan">Cancel</button></div>
    </div>
</div>
<?php if ($saleReceipt): ?>
<div class="batch-sale-receipt" role="status">
    <h3>Order recorded</h3>
    <table>
        <tbody>
            <tr><th>Order</th><td><a href="admin_orders.php?q=<?php echo rawurlencode($saleReceipt['order_number']); ?>"><?php echo htmlspecialchars($saleReceipt['order_number'], ENT_QUOTES, 'UTF-8'); ?></a></td></tr>
            <tr><th>Product</th><td><?php echo htmlspecialchars($saleReceipt['product_name'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Quantity</th><td><?php echo number_format($saleReceipt['quantity']); ?></td></tr>
            <tr><th>Price</th><td>₱<?php echo number_format($saleReceipt['unit_price'], 2); ?> each</td></tr>
            <tr><th>Total</th><td><strong>₱<?php echo number_format($saleReceipt['total'], 2); ?></strong></td></tr>
        </tbody>
    </table>
</div>
<?php endif; ?>
<dialog class="batch-sale-dialog" id="batchSaleDialog" aria-labelledby="batchSaleTitle">
    <h2 id="batchSaleTitle">Record batch sale</h2>
    <p class="batch-sale-dialog-product" id="batchSaleProduct"></p>
    <form method="post" id="batchSaleForm">
        <input type="hidden" name="action" value="record_sale">
        <input type="hidden" name="batch_id" id="batchSaleId">
        <div class="batch-sale-price"><span>Unit price</span><strong id="batchSaleUnitPrice"></strong></div>
        <label for="batchSaleQuantity">Quantity sold</label>
        <div class="batch-sale-quantity">
            <button type="button" id="batchSaleDecrement" aria-label="Decrease quantity">−</button>
            <input type="number" name="quantity" id="batchSaleQuantity" min="1" required>
            <button type="button" id="batchSaleIncrement" aria-label="Increase quantity">+</button>
        </div>
        <p class="batch-sale-available" id="batchSaleAvailable"></p>
        <div class="batch-sale-price"><span>Total price</span><strong id="batchSaleTotal"></strong></div>
        <p class="batch-sale-order-note">A paid onsite order number will be generated automatically and saved in Orders and Batch Sales.</p>
        <div class="batch-sale-dialog-actions">
            <button type="button" class="button secondary" id="batchSaleCancel">Cancel</button>
            <button type="submit" class="button">Record sale</button>
        </div>
    </form>
</dialog>
<dialog class="sale-details-dialog" id="saleDetailsDialog" aria-labelledby="saleDetailsTitle">
    <h2 id="saleDetailsTitle">Sale details</h2>
    <div id="saleDetailsOrder"></div>
    <div id="saleDetailsItems"></div>
    <div class="sale-detail-payment">
        <div><span>Sale total</span><strong id="saleDetailsTotal">—</strong></div>
        <div><span>Cash received</span><strong id="saleDetailsCash">—</strong></div>
        <div><span>Change</span><strong id="saleDetailsChange">—</strong></div>
    </div>
    <div class="sale-details-close"><button type="button" class="button secondary" id="saleDetailsClose">Close</button></div>
</dialog>
<section class="panel"><div class="toolbar" style="justify-content:space-between"><h2 style="margin:0;font-size:15px">Recent POS Receipts</h2></div><div class="table-scroll" id="recent-sales"><table class="batch-table"><thead><tr><th>Receipt ID</th><th>Products</th><th>Total Quantity</th><th>Receipt Total</th><th>Action</th></tr></thead><tbody><?php if($movementRows): foreach($movementRows as $movement): $movementPosSaleId = (int)$movement['pos_sale_id']; $saleKey = 'pos_' . $movementPosSaleId; $saleNumber = (string)$movement['sale_number']; ?><tr><td><?php echo htmlspecialchars($saleNumber, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo number_format((int)$movement['product_count']); ?> product<?php echo (int)$movement['product_count'] === 1 ? '' : 's'; ?></td><td><?php echo number_format((int)$movement['quantity']); ?></td><td>₱<?php echo number_format((float)$movement['total_amount'], 2); ?></td><td><button type="button" class="button secondary sale-details-button" data-sale-key="<?php echo htmlspecialchars($saleKey, ENT_QUOTES, 'UTF-8'); ?>" aria-label="View receipt details for <?php echo htmlspecialchars($saleNumber, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-eye" aria-hidden="true"></i></button></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="empty">No POS receipts recorded yet.</td></tr><?php endif; ?></tbody></table></div></section>
<script>
const batchSaleDetails = <?php echo json_encode($saleDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const saleDetailsDialog = document.getElementById('saleDetailsDialog');
const saleDetailsOrder = document.getElementById('saleDetailsOrder');
const saleDetailsItems = document.getElementById('saleDetailsItems');
const saleDetailsTotal = document.getElementById('saleDetailsTotal');
const saleDetailsCash = document.getElementById('saleDetailsCash');
const saleDetailsChange = document.getElementById('saleDetailsChange');
const formatBatchSaleMoney = value => `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

document.querySelectorAll('.sale-details-button').forEach(button => {
    button.addEventListener('click', () => {
        const saleKey = button.dataset.saleKey;
        const saleNumber = button.closest('tr').cells[0].textContent.trim();
        const details = batchSaleDetails[saleKey];
        saleDetailsOrder.textContent = `${saleNumber.startsWith('SALE-') ? 'POS Sale' : 'Order'} ${saleNumber}`;
        saleDetailsItems.replaceChildren();

        if (details && details.items.length > 0) {
            const table = document.createElement('table');
            table.className = 'sale-detail-items';
            const header = document.createElement('tr');
            ['Product', 'Quantity', 'Total'].forEach(label => {
                const cell = document.createElement('th');
                cell.textContent = label;
                header.appendChild(cell);
            });
            const thead = document.createElement('thead');
            thead.appendChild(header);
            const tbody = document.createElement('tbody');
            details.items.forEach(item => {
                const row = document.createElement('tr');
                const name = document.createElement('td');
                name.textContent = item.product_name;
                const quantity = document.createElement('td');
                quantity.textContent = Number(item.quantity).toLocaleString();
                const total = document.createElement('td');
                total.textContent = formatBatchSaleMoney(item.line_total);
                row.append(name, quantity, total);
                tbody.appendChild(row);
            });
            table.append(thead, tbody);
            saleDetailsItems.appendChild(table);
        } else {
            const empty = document.createElement('div');
            empty.className = 'sale-detail-empty';
            empty.textContent = 'No item details are available for this sale.';
            saleDetailsItems.appendChild(empty);
        }

        saleDetailsCash.textContent = details && details.cash_received !== null
            ? formatBatchSaleMoney(details.cash_received)
            : 'Not recorded for this sale';
        saleDetailsTotal.textContent = details
            ? formatBatchSaleMoney(details.sale_total)
            : 'Not recorded for this sale';
        saleDetailsChange.textContent = details && details.change_due !== null
            ? formatBatchSaleMoney(details.change_due)
            : 'Not recorded for this sale';
        saleDetailsDialog.showModal();
    });
});

document.getElementById('saleDetailsClose').addEventListener('click', () => saleDetailsDialog.close());
saleDetailsDialog.addEventListener('click', event => {
    if (event.target === saleDetailsDialog) saleDetailsDialog.close();
});
</script>
<template id="inventory-pagination-batches"><div class="inventory-list-footer"><span>Showing <?php echo $totalBatches ? (($inventoryPageParams['batches_page'] - 1) * $inventoryPerPage + 1) : 0; ?> to <?php echo min($inventoryPageParams['batches_page'] * $inventoryPerPage, $totalBatches); ?> of <?php echo number_format($totalBatches); ?> batches</span><?php echo renderInventoryPager($inventoryPageParams['batches_page'], $totalBatchPages, 'batches_page', 'batches', $search, $expiryRange, $inventoryPageParams); ?></div></template>
<template id="inventory-pagination-sales"><div class="inventory-list-footer"><span>Showing <?php echo $totalSales ? (($inventoryPageParams['sales_page'] - 1) * $inventoryPerPage + 1) : 0; ?> to <?php echo min($inventoryPageParams['sales_page'] * $inventoryPerPage, $totalSales); ?> of <?php echo number_format($totalSales); ?> receipts</span><?php echo renderInventoryPager($inventoryPageParams['sales_page'], $totalSalesPages, 'sales_page', 'sales', $search, $expiryRange, $inventoryPageParams); ?></div></template>
</main>
<script>
const batchSaleDialog = document.getElementById('batchSaleDialog');
const batchSaleProduct = document.getElementById('batchSaleProduct');
const batchSaleId = document.getElementById('batchSaleId');
const batchSaleQuantity = document.getElementById('batchSaleQuantity');
const batchSaleAvailable = document.getElementById('batchSaleAvailable');
const batchSaleUnitPrice = document.getElementById('batchSaleUnitPrice');
const batchSaleTotal = document.getElementById('batchSaleTotal');
const batchSaleDecrement = document.getElementById('batchSaleDecrement');
const batchSaleIncrement = document.getElementById('batchSaleIncrement');
let selectedBatchUnitPrice = 0;
let selectedBatchAvailable = 0;

const formatSalePrice = amount => `₱${amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const updateBatchSaleTotal = () => {
    const quantity = Math.max(0, Number(batchSaleQuantity.value) || 0);
    batchSaleTotal.textContent = formatSalePrice(selectedBatchUnitPrice * quantity);
    batchSaleDecrement.disabled = quantity <= 1;
    batchSaleIncrement.disabled = quantity >= selectedBatchAvailable;
};

document.querySelectorAll('.sale-add-button').forEach(button => {
    button.addEventListener('click', () => {
        selectedBatchAvailable = Number(button.dataset.available);
        selectedBatchUnitPrice = Number(button.dataset.unitPrice);
        batchSaleProduct.textContent = button.dataset.productName;
        batchSaleId.value = button.dataset.batchId;
        batchSaleQuantity.max = String(selectedBatchAvailable);
        batchSaleQuantity.value = '1';
        batchSaleAvailable.textContent = `Available in this batch: ${selectedBatchAvailable}`;
        batchSaleUnitPrice.textContent = formatSalePrice(selectedBatchUnitPrice);
        updateBatchSaleTotal();
        batchSaleDialog.showModal();
        batchSaleQuantity.focus();
    });
});

batchSaleQuantity.addEventListener('input', updateBatchSaleTotal);
batchSaleDecrement.addEventListener('click', () => {
    batchSaleQuantity.value = String(Math.max(1, (Number(batchSaleQuantity.value) || 1) - 1));
    updateBatchSaleTotal();
});
batchSaleIncrement.addEventListener('click', () => {
    batchSaleQuantity.value = String(Math.min(selectedBatchAvailable, (Number(batchSaleQuantity.value) || 0) + 1));
    updateBatchSaleTotal();
});

document.getElementById('batchSaleCancel').addEventListener('click', () => {
    batchSaleDialog.close();
});

batchSaleDialog.addEventListener('click', event => {
    if (event.target === batchSaleDialog) batchSaleDialog.close();
});
</script>
<script src="https://unpkg.com/@zxing/browser@0.1.5"></script>
<script>
const cameraButton = document.getElementById('cameraButton');
const barcodeScannerModal = document.getElementById('barcodeScannerModal');
const video = document.getElementById('barcodeVideo');
const barcodeSearch = document.getElementById('barcodeSearch');
const barcodeScannerStatus = document.getElementById('barcodeScannerStatus');
const barcodeCameraSelect = document.getElementById('barcodeCameraSelect');
let barcodeScannerControls = null;
let barcodeScanSession = 0;
const setBarcodeScannerStatus = (message, state) => {
    barcodeScannerStatus.textContent = message;
    barcodeScannerStatus.dataset.state = state;
};
const stopBarcodeScanner = () => {
    barcodeScanSession++;
    if (barcodeScannerControls) barcodeScannerControls.stop();
    barcodeScannerControls = null;
    video.pause();
    video.srcObject = null;
    cameraButton.setAttribute('aria-pressed', 'false');
};
const closeBarcodeScanner = () => {
    stopBarcodeScanner();
    barcodeScannerModal.classList.remove('open');
};
const startBarcodeScanner = async (requestedDeviceId = '') => {
    stopBarcodeScanner();
    const session = ++barcodeScanSession;
    barcodeScannerModal.classList.add('open');
    cameraButton.setAttribute('aria-pressed', 'true');
    setBarcodeScannerStatus('Starting camera...', 'starting');
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setBarcodeScannerStatus('Camera access requires HTTPS or localhost.', 'error');
        return;
    }
    if (!window.ZXingBrowser || !window.ZXingBrowser.BrowserMultiFormatReader) {
        setBarcodeScannerStatus('Barcode scanner failed to load. Check your internet connection and reload.', 'error');
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
        if (session !== barcodeScanSession) return;
        if (!cameras.length) throw new Error('No camera was found. Connect or enable a camera, then try again.');

        barcodeCameraSelect.replaceChildren();
        cameras.forEach((camera, index) => {
            const option = document.createElement('option');
            option.value = camera.deviceId;
            option.textContent = camera.label || `Camera ${index + 1}`;
            barcodeCameraSelect.appendChild(option);
        });
        const selectedCamera = cameras.find(camera => camera.deviceId === requestedDeviceId)
            || cameras.find(camera => /camo/i.test(camera.label))
            || cameras[0];
        barcodeCameraSelect.value = selectedCamera.deviceId;

        const reader = new ZXingBrowser.BrowserMultiFormatReader();
        const controls = await reader.decodeFromVideoDevice(
            selectedCamera.deviceId,
            video,
            result => {
                if (session !== barcodeScanSession || !result) return;
                const barcode = result.getText().trim();
                if (!barcode) return;
                barcodeSearch.value = barcode;
                barcodeSearch.dispatchEvent(new Event('input', { bubbles: true }));
                barcodeSearch.dispatchEvent(new Event('change', { bubbles: true }));
                closeBarcodeScanner();
                barcodeSearch.form.requestSubmit();
            }
        );
        if (session !== barcodeScanSession) controls.stop();
        else {
            barcodeScannerControls = controls;
            setBarcodeScannerStatus(`Using ${selectedCamera.label || 'selected camera'}. Scanning barcode...`, 'scanning');
        }
    } catch (error) {
        if (session !== barcodeScanSession) return;
        const messages = {
            NotAllowedError: 'Camera permission was denied. Allow camera access and try again.',
            NotFoundError: 'No camera was found. Connect or enable a camera, then try again.',
            NotReadableError: 'The camera could not be opened. Another app may be using it.'
        };
        setBarcodeScannerStatus(messages[error.name] || error.message || 'Unable to start the camera.', 'error');
        stopBarcodeScanner();
    }
};
cameraButton.addEventListener('click', () => startBarcodeScanner());
barcodeCameraSelect.addEventListener('change', () => {
    if (barcodeScannerModal.classList.contains('open')) startBarcodeScanner(barcodeCameraSelect.value);
});
document.getElementById('closeBarcodeScanner').addEventListener('click', closeBarcodeScanner);
document.getElementById('cancelBarcodeScan').addEventListener('click', closeBarcodeScanner);
barcodeScannerModal.addEventListener('click', event => {
    if (event.target === barcodeScannerModal) closeBarcodeScanner();
});
barcodeScannerModal.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeBarcodeScanner();
});

const inventoryTabTitles = {
    batches: 'Batch Inventory',
    sales: 'Recent POS Receipts'
};
const inventoryPanels = new Map([
    ['batches', document.querySelector('.batch-inventory-panel')],
    ['sales', document.querySelector('#recent-sales')?.closest('.panel')]
].filter(([, panel]) => panel));
inventoryPanels.forEach((panel, tab) => {
    panel.id = `inventory-panel-${tab}`;
    panel.classList.add('inventory-tab-panel');
    panel.setAttribute('role', 'tabpanel');
    panel.setAttribute('aria-labelledby', `inventory-tab-${tab}`);
});
Object.keys(inventoryTabTitles).forEach(tab => {
    const template = document.getElementById(`inventory-pagination-${tab}`);
    const panel = inventoryPanels.get(tab);
    if (template && panel) panel.append(template.content.cloneNode(true));
});

const inventoryTabs = document.createElement('div');
inventoryTabs.className = 'inventory-tabs';
inventoryTabs.setAttribute('role', 'tablist');
inventoryTabs.setAttribute('aria-label', 'Inventory sections');
Object.entries(inventoryTabTitles).forEach(([tab, title]) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'inventory-tab-button';
    button.id = `inventory-tab-${tab}`;
    button.setAttribute('role', 'tab');
    button.setAttribute('aria-controls', `inventory-panel-${tab}`);
    button.setAttribute('aria-selected', 'false');
    button.tabIndex = -1;
    button.textContent = title.replace('Recent POS Receipts', 'POS Sales');
    inventoryTabs.appendChild(button);
});

const firstInventoryPanel = inventoryPanels.values().next().value;
const inventoryPageHeader = document.querySelector('.content > .page-header');
if (firstInventoryPanel && inventoryPageHeader) {
    const inventoryTabbar = document.createElement('div');
    inventoryTabbar.className = 'inventory-tabbar';
    inventoryTabbar.append(inventoryTabs);
    const physicalPosButton = document.getElementById('physicalPosButton');
    if (physicalPosButton) inventoryTabbar.append(physicalPosButton);
    inventoryPageHeader.before(inventoryTabbar);
    const salesClearLink = inventoryPanels.get('sales')?.querySelector('a[href$="#recent-sales"]');
    if (salesClearLink) salesClearLink.href = 'inventory_batches.php?tab=sales#recent-sales';
    const activateInventoryTab = tab => {
        inventoryTabs.querySelectorAll('[role="tab"]').forEach(button => {
            const selected = button.id === `inventory-tab-${tab}`;
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
        });
        inventoryPanels.forEach((panel, panelTab) => {
            const isActive = panelTab === tab;
            panel.hidden = !isActive;
            panel.style.display = isActive ? '' : 'none';
            panel.setAttribute('aria-hidden', String(!isActive));
        });
        inventoryPageHeader.classList.toggle('inventory-sales-hidden', tab === 'sales');
        document.getElementById('inventoryMetrics')?.classList.toggle('inventory-sales-hidden', tab === 'sales');
        document.querySelectorAll('form[method="get" i]').forEach(form => {
            let tabInput = form.querySelector('input[name="tab"]');
            if (!tabInput) {
                tabInput = document.createElement('input');
                tabInput.type = 'hidden';
                tabInput.name = 'tab';
                form.appendChild(tabInput);
            }
            tabInput.value = tab;
            if (form.classList.contains('inventory-search-form')) {
                let batchPageInput = form.querySelector('input[name="batches_page"]');
                if (!batchPageInput) {
                    batchPageInput = document.createElement('input');
                    batchPageInput.type = 'hidden';
                    batchPageInput.name = 'batches_page';
                    form.appendChild(batchPageInput);
                }
                batchPageInput.value = '1';
            }
        });
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    };
    const requestedTab = new URLSearchParams(window.location.search).get('tab');
    activateInventoryTab(inventoryPanels.has(requestedTab) ? requestedTab : 'batches');
    inventoryTabs.addEventListener('click', event => {
        const button = event.target.closest('[role="tab"]');
        if (button) activateInventoryTab(button.id.replace('inventory-tab-', ''));
    });
    inventoryTabs.addEventListener('keydown', event => {
        const buttons = [...inventoryTabs.querySelectorAll('[role="tab"]')];
        const currentIndex = buttons.indexOf(document.activeElement);
        let nextIndex = currentIndex;
        if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % buttons.length;
        else if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + buttons.length) % buttons.length;
        else if (event.key === 'Home') nextIndex = 0;
        else if (event.key === 'End') nextIndex = buttons.length - 1;
        else return;
        event.preventDefault();
        buttons[nextIndex].focus();
        activateInventoryTab(buttons[nextIndex].id.replace('inventory-tab-', ''));
    });
}
</script>
</body>
</html>
