<?php
require_once __DIR__ . '/../inventory_stock_notifications.php';
// ==========================================
// EXISTING FUNCTIONS
// ==========================================

function ensureUniqueInventoryIdentifierIndexes(mysqli $conn): void
{
    foreach (['sku', 'barcode'] as $column) {
        $columnResult = $conn->query("SHOW COLUMNS FROM products LIKE '$column'");
        if (!$columnResult) {
            throw new RuntimeException('Unable to inspect product identifier columns: ' . $conn->error);
        }
        if ($columnResult->num_rows === 0 && !$conn->query("ALTER TABLE products ADD COLUMN `$column` VARCHAR(100) NULL")) {
            throw new RuntimeException('Unable to prepare product identifier storage: ' . $conn->error);
        }
        $columnDetails = $columnResult->num_rows > 0 ? $columnResult->fetch_assoc() : ['Null' => 'YES'];
        if (($columnDetails['Null'] ?? 'YES') !== 'YES'
            && !$conn->query("ALTER TABLE products MODIFY COLUMN `$column` VARCHAR(100) NULL")) {
            throw new RuntimeException('Unable to allow an empty product identifier: ' . $conn->error);
        }
        if (!$conn->query("UPDATE products SET `$column` = NULL WHERE TRIM(`$column`) = ''")) {
            throw new RuntimeException('Unable to normalize empty product identifiers: ' . $conn->error);
        }
    }

    $indexes = [
        ['products', 'sku', 'uq_products_sku'],
        ['products', 'barcode', 'uq_products_barcode'],
        ['product_batches', 'lot_number', 'uq_product_batches_lot'],
    ];
    foreach ($indexes as [$table, $column, $indexName]) {
        $indexResult = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
        if (!$indexResult) {
            throw new RuntimeException('Unable to inspect unique identifier indexes: ' . $conn->error);
        }
        if ($indexResult->num_rows > 0) {
            continue;
        }

        $nonEmpty = $column === 'lot_number' ? '' : " AND TRIM(`$column`) <> ''";
        $duplicateResult = $conn->query(
            "SELECT LOWER(TRIM(`$column`)) AS normalized_value
             FROM `$table`
             WHERE `$column` IS NOT NULL$nonEmpty
             GROUP BY LOWER(TRIM(`$column`))
             HAVING COUNT(*) > 1
             LIMIT 1"
        );
        if (!$duplicateResult) {
            throw new RuntimeException('Unable to check existing identifier values: ' . $conn->error);
        }
        if ($duplicateResult->num_rows > 0) {
            throw new RuntimeException(
                "Cannot enable unique $column values because duplicate $column records already exist. Resolve the existing duplicates and try again."
            );
        }

        if (!$conn->query("ALTER TABLE `$table` ADD UNIQUE KEY `$indexName` (`$column`)")) {
            error_log("Unable to add unique index $indexName: " . $conn->error);
            throw new RuntimeException("Unable to enforce unique $column values. Check the database schema and try again.");
        }
    }
}

function inventoryIdentifierInUse(mysqli $conn, string $type, string $value, int $excludeId = 0): bool
{
    $columns = [
        'sku' => ['products', 'sku'],
        'barcode' => ['products', 'barcode'],
        'lot_number' => ['product_batches', 'lot_number'],
    ];
    if (!isset($columns[$type]) || trim($value) === '') {
        return false;
    }

    [$table, $column] = $columns[$type];
    $statement = $conn->prepare(
        "SELECT id FROM `$table`
         WHERE LOWER(TRIM(`$column`)) = LOWER(TRIM(?)) AND id <> ?
         LIMIT 1"
    );
    if (!$statement) {
        throw new RuntimeException('Unable to validate identifier uniqueness: ' . $conn->error);
    }
    $statement->bind_param('si', $value, $excludeId);
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        throw new RuntimeException('Unable to validate identifier uniqueness: ' . $error);
    }
    $result = $statement->get_result();
    $inUse = $result && $result->num_rows > 0;
    $statement->close();
    return $inUse;
}

function sendPrescriptionApprovalEmail(string $email, string $customerName, int $prescriptionId, string $productName, int $quantityLimit, string $accessExpiresAt, string $pharmacyNotes = ''): array
{
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $prescriptionId < 1 || $productName === '' || $quantityLimit < 1) {
        error_log('Prescription approval email skipped: invalid customer or product details.');
        return ['success' => false, 'error' => 'The customer email or approval details are invalid.'];
    }

    $smtpHost = getenv('SMTP_HOST');
    $smtpUsername = getenv('SMTP_USERNAME');
    $smtpPassword = getenv('SMTP_PASSWORD');
    $smtpFrom = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
    if (!$smtpHost || !$smtpUsername || !$smtpPassword || !$smtpFrom) {
        error_log('Prescription approval email failed: SMTP settings are not configured.');
        return ['success' => false, 'error' => 'SMTP settings are not configured.'];
    }

    $customerName = trim($customerName) !== '' ? trim($customerName) : 'Customer';
    $prescriptionNumber = 'P-' . str_pad((string)$prescriptionId, 4, '0', STR_PAD_LEFT);
    $validUntil = date('M d, Y', strtotime($accessExpiresAt));
    $subject = 'Prescription Approved - #' . $prescriptionNumber;
    $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
    $safePrescriptionNumber = htmlspecialchars($prescriptionNumber, ENT_QUOTES, 'UTF-8');
    $safeProductName = htmlspecialchars($productName, ENT_QUOTES, 'UTF-8');
    $safeValidUntil = htmlspecialchars($validUntil, ENT_QUOTES, 'UTF-8');
    $safePharmacyNotes = trim($pharmacyNotes) !== ''
        ? '<p>Pharmacy notes:<br>' . nl2br(htmlspecialchars(trim($pharmacyNotes), ENT_QUOTES, 'UTF-8')) . '</p>'
        : '';
    $plainPharmacyNotes = trim($pharmacyNotes) !== '' ? "\n\nPharmacy notes:\n" . trim($pharmacyNotes) : '';

    try {
        require_once __DIR__ . '/vendor/autoload.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($smtpFrom, getenv('SMTP_FROM_NAME') ?: 'Josefina Farmacia');
        $mail->addAddress($email, $customerName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = '<h2>Your prescription has been approved</h2>'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Your prescription <strong>#' . $safePrescriptionNumber . '</strong> was approved by the pharmacy.</p>'
            . '<p>Medicine: <strong>' . $safeProductName . '</strong><br>'
            . 'Approved quantity limit: <strong>' . number_format($quantityLimit) . '</strong><br>'
            . 'Available until: <strong>' . $safeValidUntil . '</strong></p>'
            . $safePharmacyNotes
            . '<p>Sign in to your Josefina Farmacia account and open this prescription to view the product and continue your order.</p>'
            . '<p>Thank you,<br>Josefina Farmacia</p>';
        $mail->AltBody = "Hello $customerName,\n\nYour prescription #$prescriptionNumber was approved by the pharmacy.\n"
            . "Medicine: $productName\nApproved quantity limit: " . number_format($quantityLimit)
            . "\nAvailable until: $validUntil" . $plainPharmacyNotes . "\n\nSign in to your Josefina Farmacia account and open this prescription to view the product and continue your order.\n\nJosefina Farmacia";
        $mail->send();
        return ['success' => true];
    } catch (\Throwable $exception) {
        error_log('Prescription approval email failed for #' . $prescriptionNumber . ': ' . $exception->getMessage());
        return ['success' => false, 'error' => 'The approval email could not be sent.'];
    }
}

function restoreInventoryForCancelledOrder($conn, $orderId) {
    $orderId = (int)$orderId;
    if ($orderId <= 0) {
        return false;
    }

    $productIds = [];
    $started = false;
    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Unable to start inventory restoration: ' . $conn->error);
        }
        $started = true;

        $lock = $conn->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE');
        if (!$lock) {
            throw new RuntimeException('Unable to prepare order lock: ' . $conn->error);
        }
        $lock->bind_param('i', $orderId);
        if (!$lock->execute() || !$lock->get_result()->fetch_assoc()) {
            throw new RuntimeException('Unable to lock cancelled order #' . $orderId . ': ' . $lock->error);
        }
        $lock->close();

        $itemsStmt = $conn->prepare(
            'SELECT oi.product_id,
                    COALESCE(NULLIF(oi.quantity, 0),
                        COALESCE(oi.box, 0) * COALESCE(NULLIF(p.pieces_per_box, 0), 12)
                        + COALESCE(oi.pieces, 0)) AS quantity
             FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?'
        );
        if (!$itemsStmt) {
            throw new RuntimeException('Unable to prepare cancelled order items: ' . $conn->error);
        }
        $itemsStmt->bind_param('i', $orderId);
        if (!$itemsStmt->execute()) {
            throw new RuntimeException('Unable to load cancelled order items: ' . $itemsStmt->error);
        }
        $items = [];
        $result = $itemsStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $productId = (int)$row['product_id'];
            $items[$productId] = ($items[$productId] ?? 0) + max(0, (int)$row['quantity']);
        }
        $itemsStmt->close();
        if (!$items) {
            throw new RuntimeException('Cancelled order #' . $orderId . ' has no inventory items.');
        }

        $movementTable = $conn->query("SHOW TABLES LIKE 'batch_movements'");
        if (!$movementTable || $movementTable->num_rows === 0) {
            throw new RuntimeException('Batch movement history is unavailable; stock was not restored.');
        }
        $movementStmt = $conn->prepare(
            "SELECT product_id, batch_id, movement_type, quantity
             FROM batch_movements
             WHERE order_id = ? AND movement_type IN ('sold', 'returned')
             FOR UPDATE"
        );
        if (!$movementStmt) {
            throw new RuntimeException('Unable to prepare batch movement lookup: ' . $conn->error);
        }
        $movementStmt->bind_param('i', $orderId);
        if (!$movementStmt->execute()) {
            throw new RuntimeException('Unable to load batch movements: ' . $movementStmt->error);
        }
        $sold = [];
        $returnedBatch = [];
        $returnedUntracked = [];
        $returnedTotals = [];
        $result = $movementStmt->get_result();
        while ($movement = $result->fetch_assoc()) {
            $productId = (int)$movement['product_id'];
            $batchId = $movement['batch_id'] === null ? 0 : (int)$movement['batch_id'];
            $quantity = max(0, (int)$movement['quantity']);
            if ($movement['movement_type'] === 'sold') {
                if ($batchId > 0) {
                    $sold[$productId][$batchId] = ($sold[$productId][$batchId] ?? 0) + $quantity;
                }
            } else {
                $returnedTotals[$productId] = ($returnedTotals[$productId] ?? 0) + $quantity;
                if ($batchId > 0) {
                    $returnedBatch[$productId][$batchId] = ($returnedBatch[$productId][$batchId] ?? 0) + $quantity;
                } else {
                    $returnedUntracked[$productId] = ($returnedUntracked[$productId] ?? 0) + $quantity;
                }
            }
        }
        $movementStmt->close();

        $batchUpdate = $conn->prepare(
            'UPDATE product_batches SET current_quantity = current_quantity + ? WHERE id = ? AND product_id = ?'
        );
        $returnStmt = $conn->prepare(
            "INSERT INTO batch_movements
                (batch_id, product_id, order_id, quantity, movement_type, reference, created_by)
             VALUES (?, ?, ?, ?, 'returned', ?, NULL)"
        );
        $productLock = $conn->prepare('SELECT quantity, pieces_per_box FROM products WHERE id = ? FOR UPDATE');
        $productUpdate = $conn->prepare(
            'UPDATE products SET quantity = ?, box = ?, pieces = ? WHERE id = ?'
        );
        if (!$batchUpdate || !$returnStmt || !$productLock || !$productUpdate) {
            throw new RuntimeException('Unable to prepare inventory restoration statements: ' . $conn->error);
        }

        foreach ($items as $productId => $orderedQuantity) {
            $batchSoldQuantity = 0;
            foreach ($sold[$productId] ?? [] as $batchId => $soldQuantity) {
                $batchSoldQuantity += $soldQuantity;
                $alreadyReturned = (int)($returnedBatch[$productId][$batchId] ?? 0);
                if ($alreadyReturned > $soldQuantity) {
                    throw new RuntimeException('Returned batch quantity exceeds the sold quantity.');
                }
                $restoreQuantity = $soldQuantity - $alreadyReturned;
                if ($restoreQuantity === 0) {
                    continue;
                }
                $batchUpdate->bind_param('iii', $restoreQuantity, $batchId, $productId);
                if (!$batchUpdate->execute() || $batchUpdate->affected_rows !== 1) {
                    throw new RuntimeException('Unable to restore batch #' . $batchId . ': ' . $batchUpdate->error);
                }
                $reference = 'Cancelled customer order #' . $orderId;
                $returnStmt->bind_param('iiiis', $batchId, $productId, $orderId, $restoreQuantity, $reference);
                if (!$returnStmt->execute()) {
                    throw new RuntimeException('Unable to record returned batch stock: ' . $returnStmt->error);
                }
            }

            if ($batchSoldQuantity > $orderedQuantity) {
                throw new RuntimeException('Sold batch quantity exceeds the order item quantity.');
            }
            $untrackedQuantity = max(0, $orderedQuantity - $batchSoldQuantity);
            $untrackedAlreadyReturned = (int)($returnedUntracked[$productId] ?? 0);
            if ($untrackedAlreadyReturned > $untrackedQuantity) {
                throw new RuntimeException('Returned quantity exceeds the untracked order quantity.');
            }
            $untrackedToReturn = $untrackedQuantity - $untrackedAlreadyReturned;
            if ($untrackedToReturn > 0) {
                $nullBatchId = null;
                $reference = 'Cancelled customer order #' . $orderId . ' (untracked batch)';
                $returnStmt->bind_param('iiiis', $nullBatchId, $productId, $orderId, $untrackedToReturn, $reference);
                if (!$returnStmt->execute()) {
                    throw new RuntimeException('Unable to record returned untracked stock: ' . $returnStmt->error);
                }
            }

            $alreadyReturned = (int)($returnedTotals[$productId] ?? 0);
            if ($alreadyReturned > $orderedQuantity) {
                throw new RuntimeException('Returned stock exceeds the cancelled order quantity.');
            }
            $restoreProductQuantity = $orderedQuantity - $alreadyReturned;
            if ($restoreProductQuantity <= 0) {
                continue;
            }
            $productLock->bind_param('i', $productId);
            if (!$productLock->execute()) {
                throw new RuntimeException('Unable to lock product stock: ' . $productLock->error);
            }
            $product = $productLock->get_result()->fetch_assoc();
            if (!$product) {
                throw new RuntimeException('Product #' . $productId . ' no longer exists.');
            }
            $stock = max(0, (int)$product['quantity']) + $restoreProductQuantity;
            $piecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
            $boxes = intdiv($stock, $piecesPerBox);
            $pieces = $stock % $piecesPerBox;
            $productUpdate->bind_param('iiii', $stock, $boxes, $pieces, $productId);
            if (!$productUpdate->execute()) {
                throw new RuntimeException('Unable to restore product stock: ' . $productUpdate->error);
            }
            $productIds[] = $productId;
        }

        $batchUpdate->close();
        $returnStmt->close();
        $productLock->close();
        $productUpdate->close();
        if (!$conn->commit()) {
            throw new RuntimeException('Unable to commit inventory restoration: ' . $conn->error);
        }
        $started = false;
    } catch (\Throwable $exception) {
        if ($started) {
            $conn->rollback();
        }
        error_log('Cancelled order inventory restoration failed for order #' . $orderId . ': ' . $exception->getMessage());
        return false;
    }

    foreach (array_unique($productIds) as $productId) {
        checkAndSendLowStockAlert($conn, $productId);
    }
    return true;
}

function expireSelfPickupOrders($conn) {
    $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS pickup_deadline DATETIME NULL");
    $orders = $conn->query("SELECT id, user_id FROM orders WHERE LOWER(TRIM(delivery_type)) IN ('pickup', 'self_pickup', 'self-pickup') AND LOWER(TRIM(status)) = 'ready_for_pickup' AND pickup_deadline IS NOT NULL AND pickup_deadline <= NOW()");
    if (!$orders) {
        return 0;
    }

    $expiredCount = 0;
    while ($order = $orders->fetch_assoc()) {
        $orderId = (int)$order['id'];
        $cancel = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND LOWER(TRIM(status)) = 'ready_for_pickup' AND pickup_deadline <= NOW()");
        if (!$cancel) {
            continue;
        }
        $cancel->bind_param('i', $orderId);
        $cancel->execute();
        $wasCancelled = $cancel->affected_rows > 0;
        $cancel->close();

        if (!$wasCancelled) {
            continue;
        }

        restoreInventoryForCancelledOrder($conn, $orderId);
        $userId = (int)($order['user_id'] ?? 0);
        if ($userId > 0) {
            $message = 'Your self pick-up order ' . formatOrderNumber($conn, $orderId) . ' was automatically cancelled because it was not collected within 24 hours.';
            $notice = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())');
            if ($notice) {
                $notice->bind_param('is', $userId, $message);
                $notice->execute();
                $notice->close();
            }
        }
        $expiredCount++;
    }

    return $expiredCount;
}

function ensureReadyForPickupOrderStatus(mysqli $conn): void {
    $statusColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'status'");
    if (!$statusColumn) {
        throw new RuntimeException('Unable to inspect order status schema: ' . $conn->error);
    }
    $column = $statusColumn->fetch_assoc();
    if (!$column) {
        throw new RuntimeException('The order status column is unavailable.');
    }

    $columnType = strtolower((string)($column['Type'] ?? ''));
    if (strpos($columnType, 'enum(') !== 0 || strpos($columnType, "'ready_for_pickup'") !== false) {
        return;
    }
    if (!preg_match_all("/'([^']*)'/", (string)$column['Type'], $matches)) {
        throw new RuntimeException('Unable to read the order status values.');
    }
    $values = $matches[1];
    $values[] = 'ready_for_pickup';
    $values = array_values(array_unique($values));
    $enumValues = array_map(
        static function (string $value) use ($conn): string {
            return "'" . $conn->real_escape_string($value) . "'";
        },
        $values
    );
    $nullability = strtoupper((string)($column['Null'] ?? 'NO')) === 'YES' ? 'NULL' : 'NOT NULL';
    $default = $column['Default'] === null
        ? ($nullability === 'NULL' ? ' DEFAULT NULL' : '')
        : " DEFAULT '" . $conn->real_escape_string((string)$column['Default']) . "'";
    if (!$conn->query('ALTER TABLE orders MODIFY status ENUM(' . implode(',', $enumValues) . ') ' . $nullability . $default)) {
        throw new RuntimeException('Unable to add the Ready for Pickup order status: ' . $conn->error);
    }
}

function sendOrderShippedEmail($conn, $orderId) {
    $smtpHost = getenv('SMTP_HOST');
    $smtpUsername = getenv('SMTP_USERNAME');
    $smtpPassword = getenv('SMTP_PASSWORD');
    $smtpFrom = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
    $smtpFromName = getenv('SMTP_FROM_NAME') ?: 'Josefina Farmacia';

    if (!$smtpHost || !$smtpUsername || !$smtpPassword || !$smtpFrom) {
        error_log('Shipped order email skipped: SMTP environment variables are not configured.');
        return false;
    }

    $orderId = (int)$orderId;
    $orderResult = $conn->query("SELECT o.id, o.order_date, u.email, COALESCE(u.username, o.fullname) AS customer_name FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = $orderId LIMIT 1");
    $order = $orderResult ? $orderResult->fetch_assoc() : null;
    if (!$order || !filter_var($order['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        error_log("Shipped order email skipped: no valid email for order #$orderId.");
        return false;
    }

    require_once __DIR__ . '/vendor/autoload.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->setFrom($smtpFrom, $smtpFromName);
        $mail->addAddress($order['email'], $order['customer_name'] ?: 'Customer');
        $mail->isHTML(true);
        $orderNumber = formatOrderNumber($conn, $orderId);
        $mail->Subject = "Order {$orderNumber} has been shipped";
        $mail->Body = '<h2>Your order is on the way!</h2>'
            . '<p>Hello ' . htmlspecialchars($order['customer_name'] ?: 'Customer', ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your order <strong>' . htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8') . '</strong> has been shipped.</p>'
            . '<p>Thank you for shopping with Josefina Farmacia.</p>';
        $mail->AltBody = "Your order {$orderNumber} has been shipped.";
        $mail->send();
        return true;
    } catch (\Throwable $exception) {
        error_log("Shipped order email failed for order #$orderId: " . $exception->getMessage());
        return false;
    }
}

function sendCustomerOrderReviewEmail($conn, $orderId, $reviewType, $decision, $reason = '') {
    $orderId = (int)$orderId;
    $reviewType = strtolower(trim((string)$reviewType));
    $decision = strtolower(trim((string)$decision));
    if ($orderId <= 0 || !in_array($reviewType, ['payment', 'discount'], true)
        || !in_array($decision, ['approved', 'rejected'], true)) {
        error_log('Customer review email skipped: invalid order review details.');
        return ['success' => false, 'error' => 'Invalid review details.'];
    }

    $stmt = $conn->prepare(
        "SELECT o.id, o.user_id, o.fullname, o.payment_method, o.payment_status,
                o.discount_type, o.discount_status, o.discount_amount, o.total_amount,
                u.email, u.username
         FROM orders o
         LEFT JOIN users u ON u.id = o.user_id
         WHERE o.id = ? LIMIT 1"
    );
    if (!$stmt) {
        error_log('Customer review email lookup preparation failed: ' . $conn->error);
        return ['success' => false, 'error' => 'Unable to load the customer order.'];
    }
    $stmt->bind_param('i', $orderId);
    if (!$stmt->execute()) {
        error_log('Customer review email lookup failed: ' . $stmt->error);
        $stmt->close();
        return ['success' => false, 'error' => 'Unable to load the customer order.'];
    }
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $customerEmail = trim((string)($order['email'] ?? ''));
    if (!$order || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('Customer review email skipped: order #' . $orderId . ' has no valid customer email.');
        return ['success' => false, 'error' => 'The customer account has no valid email address.'];
    }

    $smtpHost = getenv('SMTP_HOST');
    $smtpUsername = getenv('SMTP_USERNAME');
    $smtpPassword = getenv('SMTP_PASSWORD');
    $smtpFrom = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
    if (!$smtpHost || !$smtpUsername || !$smtpPassword || !$smtpFrom) {
        error_log('Customer review email failed: SMTP settings are not configured.');
        return ['success' => false, 'error' => 'SMTP settings are not configured.'];
    }

    $customerName = trim((string)($order['username'] ?? '')) ?: trim((string)($order['fullname'] ?? 'Customer'));
    $customerName = $customerName !== '' ? $customerName : 'Customer';
    $orderNumber = formatOrderNumber($conn, $orderId);
    $safeCustomerName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
    $safeOrderNumber = htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8');

    if ($reviewType === 'discount') {
        $discountType = ($order['discount_type'] ?? '') === 'pwd' ? 'PWD' : 'Senior Citizen';
        $subject = $decision === 'approved' ? 'Discount Approved' : 'Discount Request Rejected';
        $resultText = $decision === 'approved'
            ? 'Your ' . $discountType . ' discount was approved. A 20% discount has been applied to your order.'
            : 'Your ' . $discountType . ' discount request was rejected. No discount was applied.';
        $detail = $decision === 'rejected' && trim($reason) !== ''
            ? '<p>Reason: ' . htmlspecialchars(trim($reason), ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
        $plainDetail = $decision === 'rejected' && trim($reason) !== '' ? "\nReason: " . trim($reason) : '';
        $subject .= ' - Order ' . $orderNumber;
    } else {
        $paymentMethod = strtolower(trim((string)($order['payment_method'] ?? '')));
        $methodLabel = in_array($paymentMethod, ['bank', 'bpi', 'bank transfer'], true)
            ? 'BPI bank transfer'
            : 'GCash';
        $subject = 'Online Payment ' . ucfirst($decision) . ' - Order ' . $orderNumber;
        $discountReviewPending = in_array($order['discount_type'] ?? '', ['senior_citizen', 'pwd'], true)
            && strtolower(trim((string)($order['discount_status'] ?? ''))) === 'pending_verification';
        $resultText = $decision === 'approved'
            ? 'Your ' . $methodLabel . ' payment was approved. ' . ($discountReviewPending
                ? 'Your order will be processed after the discount ID is reviewed.'
                : 'Your order is now being processed.')
            : 'Your ' . $methodLabel . ' payment was rejected and your order was cancelled.';
        $detail = $decision === 'rejected' && trim($reason) !== ''
            ? '<p>Reason: ' . htmlspecialchars(trim($reason), ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
        $plainDetail = $decision === 'rejected' && trim($reason) !== '' ? "\nReason: " . trim($reason) : '';
    }

    try {
        require_once __DIR__ . '/vendor/autoload.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->setFrom($smtpFrom, getenv('SMTP_FROM_NAME') ?: 'Josefina Farmacia');
        $mail->addAddress($customerEmail, $customerName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = '<h2>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<p>Hello ' . $safeCustomerName . ',</p><p>' . htmlspecialchars($resultText, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Order: <strong>' . $safeOrderNumber . '</strong><br>Updated total: <strong>₱'
            . number_format((float)($order['total_amount'] ?? 0), 2) . '</strong></p>'
            . $detail . '<p>Thank you,<br>Josefina Farmacia</p>';
        $mail->AltBody = $subject . "\n\nHello $customerName,\n$resultText\nOrder: $orderNumber\nUpdated total: ₱"
            . number_format((float)($order['total_amount'] ?? 0), 2) . $plainDetail . "\n\nJosefina Farmacia";
        $mail->send();
        return ['success' => true];
    } catch (\Throwable $exception) {
        error_log('Customer ' . $reviewType . ' review email failed for order #' . $orderId . ': ' . $exception->getMessage());
        return ['success' => false, 'error' => 'The customer email could not be sent.'];
    }
}

function sendCustomerLoyaltyVoucherEmail(string $recipient, string $customerName, string $code, float $discountPercent, string $issuedDate, string $expiryDate): array {
    $smtpHost = getenv('SMTP_HOST');
    $smtpUsername = getenv('SMTP_USERNAME');
    $smtpPassword = getenv('SMTP_PASSWORD');
    $smtpFrom = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
    $smtpFromName = getenv('SMTP_FROM_NAME') ?: 'Josefina Farmacia';

    if (!$smtpHost || !$smtpUsername || !$smtpPassword || !$smtpFrom || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        error_log('Loyalty voucher email skipped: SMTP settings or customer email are not configured.');
        return ['success' => false, 'error' => 'SMTP settings or customer email are not configured.'];
    }

    require_once __DIR__ . '/vendor/autoload.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $discountLabel = number_format($discountPercent, 0) . '% OFF';
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->setFrom($smtpFrom, $smtpFromName);
        $mail->addAddress($recipient, $customerName);
        $mail->isHTML(true);
        $mail->Subject = 'Your Josefina Farmacia loyalty voucher';
        $mail->Body = '<h2>Your loyalty reward is here!</h2><p>Hello ' . $safeName . ',</p>'
            . '<p>Thank you for shopping with Josefina Farmacia. Your loyalty voucher is worth <strong>' . $discountLabel . '</strong>:</p>'
            . '<p style="font-size:22px;font-weight:bold;letter-spacing:2px">' . $safeCode . '</p>'
            . '<p>Issued: ' . htmlspecialchars($issuedDate, ENT_QUOTES, 'UTF-8') . '<br>Valid until: ' . htmlspecialchars($expiryDate, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Enter this code in the Voucher Code section at checkout. This voucher is tied to your account and may be used once before it expires.</p>';
        $mail->AltBody = "Hello $customerName,\n\nThank you for shopping with Josefina Farmacia. Your loyalty voucher is $discountLabel.\nVoucher code: $code\nIssued: $issuedDate\nValid until: $expiryDate\n\nEnter this code in the Voucher Code section at checkout. This voucher is tied to your account and may be used once before it expires.";
        $mail->send();
        return ['success' => true];
    } catch (\Throwable $exception) {
        error_log('Customer loyalty voucher email failed for ' . $recipient . ': ' . $exception->getMessage());
        return ['success' => false, 'error' => 'The voucher was generated, but the email could not be sent.'];
    }
}

function sendInventoryAlertEmail(array $product, $recipient = null) {
    $smtpHost = getenv('SMTP_HOST');
    $smtpUsername = getenv('SMTP_USERNAME');
    $smtpPassword = getenv('SMTP_PASSWORD');
    $smtpFrom = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
    $smtpFromName = getenv('SMTP_FROM_NAME') ?: 'Josefina Farmacia';
    $recipient = $recipient ?: getenv('SMTP_NOTIFY_EMAIL');

    if (!$smtpHost || !$smtpUsername || !$smtpPassword || !$smtpFrom || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        error_log('Inventory alert email skipped: SMTP environment variables are not configured.');
        return ['success' => false, 'error' => 'SMTP settings or recipient email are not configured.'];
    }

    require_once __DIR__ . '/vendor/autoload.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $productName = (string)($product['product_name'] ?? 'Product');
        $productId = (int)($product['id'] ?? 0);
        $sku = (string)($product['sku'] ?? ('MED-' . $productId));
        $currentStock = (int)($product['current_stock'] ?? 0);
        $threshold = (int)($product['low_stock_threshold'] ?? 0);
        $category = (string)($product['category'] ?? 'Not specified');
        $expirationDate = $product['expiration_date'] ?? null;
        $expirationLabel = $expirationDate ? date('F Y', strtotime($expirationDate)) : 'Not available';
        $alertDate = date('F j, Y g:i A');
        $safeProductName = htmlspecialchars($productName, ENT_QUOTES, 'UTF-8');
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->setFrom($smtpFrom, $smtpFromName);
        $mail->addAddress($recipient, 'Owner');
        $mail->isHTML(true);
        $mail->Subject = 'LOW STOCK ALERT - ' . $productName;
        $mail->Body = '<h2>Inventory Alert</h2><p><strong>' . $safeProductName . '</strong> is running low.</p>'
            . '<p>Product ID/SKU: ' . htmlspecialchars($productId . ' / ' . $sku, ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Current Stock: <strong>' . $currentStock . '</strong><br>'
            . 'Low Stock Threshold: <strong>' . $threshold . '</strong><br>'
            . 'Category: ' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Expiration Date: ' . htmlspecialchars($expirationLabel, ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Alert Date and Time: ' . htmlspecialchars($alertDate, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Please restock this item as soon as possible.</p>';
        $mail->AltBody = "Inventory Alert:\n$productName is running low.\n\nCurrent Stock: $currentStock\nLow Stock Threshold: $threshold\nCategory: $category\nExpiration Date: $expirationLabel\nAlert Date and Time: $alertDate\n\nPlease restock this item as soon as possible.";
        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
        error_log('Inventory alert email failed: ' . $error);
        return ['success' => false, 'error' => $error];
    }
}

function addNotification($conn, $message, $user_id = NULL) {
    $message = $conn->real_escape_string($message);

    if ($user_id !== NULL) {
        $user_id = (int)$user_id;
        $sql = "INSERT INTO notifications (user_id, message, created_at)
                VALUES ($user_id, '$message', NOW())";
    } else {
        $sql = "INSERT INTO notifications (user_id, message, created_at)
                VALUES (NULL, '$message', NOW())";
    }

    if (!$conn->query($sql)) {
        die("❌ Notification Error: " . $conn->error);
    }
}

function notifySupplierOfPurchaseOrder($conn, $purchaseOrderId, $supplierId, $poNumber, $items, $totalAmount) {
    $supplier = getSupplierById($conn, $supplierId);
    if (!$supplier) {
        return ['email' => false, 'sms' => false];
    }

    $itemLines = [];
    foreach ($items as $item) {
        $itemLines[] = ($item['product_name'] ?? 'Product') . ' x' . (int)$item['quantity'];
    }
    $itemSummary = implode(', ', $itemLines);
    $emailSent = false;
    $smsSent = false;

    $smtpHost = getenv('SMTP_HOST');
    $smtpUsername = getenv('SMTP_USERNAME');
    $smtpPassword = getenv('SMTP_PASSWORD');
    $smtpFrom = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
    if ($smtpHost && $smtpUsername && $smtpPassword && $smtpFrom && filter_var($supplier['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        require_once __DIR__ . '/vendor/autoload.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUsername;
            $mail->Password = $smtpPassword;
            $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
            $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
            $mail->setFrom($smtpFrom, getenv('SMTP_FROM_NAME') ?: 'Josefina Farmacia');
            $mail->addAddress($supplier['email'], $supplier['name'] ?: 'Supplier');
            $ownerCopy = getenv('SMTP_NOTIFY_EMAIL');
            if ($ownerCopy && filter_var($ownerCopy, FILTER_VALIDATE_EMAIL) && strcasecmp($ownerCopy, $supplier['email']) !== 0) {
                $mail->addCC($ownerCopy);
            }
            $mail->isHTML(true);
            $mail->Subject = "New purchase order $poNumber";
            $mail->Body = '<h2>New Purchase Order</h2><p>Hello ' . htmlspecialchars($supplier['name'] ?: 'Supplier', ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>Please process purchase order <strong>' . htmlspecialchars($poNumber, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                . '<p>Items: ' . htmlspecialchars($itemSummary, ENT_QUOTES, 'UTF-8') . '<br>Total: ' . number_format((float)$totalAmount, 2) . '</p>';
            $mail->AltBody = "New purchase order $poNumber. Items: $itemSummary. Total: " . number_format((float)$totalAmount, 2);
            $mail->send();
            $emailSent = true;
        } catch (\Throwable $exception) {
            error_log("Supplier email failed for $poNumber: " . $exception->getMessage());
        }
    }

    $twilioSid = getenv('TWILIO_ACCOUNT_SID');
    $twilioToken = getenv('TWILIO_AUTH_TOKEN');
    $twilioFrom = getenv('TWILIO_FROM_NUMBER');
    $supplierPhone = trim($supplier['phone'] ?? '');
    if ($twilioSid && $twilioToken && $twilioFrom && $supplierPhone && function_exists('curl_init')) {
        $smsBody = "New purchase order $poNumber from Josefina Farmacia. Items: $itemSummary. Total: " . number_format((float)$totalAmount, 2);
        $curl = curl_init("https://api.twilio.com/2010-04-01/Accounts/$twilioSid/Messages.json");
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['To' => $supplierPhone, 'From' => $twilioFrom, 'Body' => $smsBody]),
            CURLOPT_USERPWD => "$twilioSid:$twilioToken",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $response = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            error_log("Supplier SMS failed for $poNumber: " . ($response ?: curl_error($curl)));
        } else {
            $smsSent = true;
        }
        curl_close($curl);
    }

    return ['email' => $emailSent, 'sms' => $smsSent];
}


// INVENTORY ALERT FUNCTIONS
// ==========================================

function ensureLowStockNotificationSchema($conn) {
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 20");
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_alert_sent TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS stock_alert_state VARCHAR(20) NULL");
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_last_alert_at DATETIME NULL");
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_email_status VARCHAR(20) NULL");
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_email_error TEXT NULL");
    return $conn->query("CREATE TABLE IF NOT EXISTS low_stock_alert_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        current_stock INT NOT NULL,
        low_stock_threshold INT NOT NULL,
        status ENUM('sent', 'failed') NOT NULL,
        error_message TEXT NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_low_stock_product (product_id),
        KEY idx_low_stock_attempted (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function sendDailyLowStockDigest($conn) {
    $tableReady = $conn->query("CREATE TABLE IF NOT EXISTS low_stock_daily_digests (
        digest_date DATE PRIMARY KEY,
        status ENUM('sending', 'sent', 'failed') NOT NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        error_message TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$tableReady) {
        error_log('Daily low-stock digest schema failed: ' . $conn->error);
        return ['success' => false, 'error' => 'Unable to prepare daily low-stock digest tracking.'];
    }

    $recipientResult = $conn->query("SELECT username, email FROM users
        WHERE LOWER(TRIM(role)) IN ('admin', 'pharmacist')
          AND email IS NOT NULL AND TRIM(email) <> ''
        ORDER BY CASE WHEN LOWER(TRIM(role)) = 'admin' THEN 0 ELSE 1 END, id ASC");
    if (!$recipientResult) {
        error_log('Daily low-stock digest recipient query failed: ' . $conn->error);
        return ['success' => false, 'error' => 'Unable to load admin and pharmacist email addresses.'];
    }
    $recipients = [];
    while ($account = $recipientResult->fetch_assoc()) {
        $email = trim((string)$account['email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[strtolower($email)] = [
                'email' => $email,
                'name' => trim((string)($account['username'] ?? '')),
            ];
        }
    }
    if (!$recipients) {
        error_log('Daily low-stock digest skipped: no valid admin or pharmacist account emails are configured.');
        return ['success' => false, 'error' => 'No valid admin or pharmacist email addresses are configured.'];
    }

    $today = date('Y-m-d');
    $claim = $conn->prepare("INSERT IGNORE INTO low_stock_daily_digests (digest_date, status, attempted_at) VALUES (?, 'sending', NOW())");
    if (!$claim) {
        error_log('Daily low-stock digest claim failed: ' . $conn->error);
        return ['success' => false, 'error' => 'Unable to claim today’s daily email.'];
    }
    $claim->bind_param('s', $today);
    if (!$claim->execute()) {
        $error = $claim->error;
        $claim->close();
        error_log('Daily low-stock digest claim failed: ' . $error);
        return ['success' => false, 'error' => 'Unable to claim today’s daily email.'];
    }
    $claimed = $claim->affected_rows === 1;
    $claim->close();

    if (!$claimed) {
        $retry = $conn->prepare("UPDATE low_stock_daily_digests
            SET status = 'sending', attempted_at = NOW(), sent_at = NULL, error_message = NULL
            WHERE digest_date = ? AND (status = 'failed' OR (status = 'sending' AND attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)))");
        if (!$retry) {
            error_log('Daily low-stock digest retry check failed: ' . $conn->error);
            return ['success' => false, 'error' => 'Unable to check today’s email status.'];
        }
        $retry->bind_param('s', $today);
        if (!$retry->execute()) {
            $error = $retry->error;
            $retry->close();
            error_log('Daily low-stock digest retry failed: ' . $error);
            return ['success' => false, 'error' => 'Unable to retry today’s email.'];
        }
        $claimed = $retry->affected_rows === 1;
        $retry->close();
        if (!$claimed) {
            $statusQuery = $conn->prepare('SELECT status FROM low_stock_daily_digests WHERE digest_date = ?');
            if (!$statusQuery) {
                error_log('Daily low-stock digest status check failed: ' . $conn->error);
                return ['success' => false, 'error' => 'Unable to check today’s email status.'];
            }
            $statusQuery->bind_param('s', $today);
            if (!$statusQuery->execute()) {
                $error = $statusQuery->error;
                $statusQuery->close();
                error_log('Daily low-stock digest status check failed: ' . $error);
                return ['success' => false, 'error' => 'Unable to check today’s email status.'];
            }
            $statusResult = $statusQuery->get_result();
            $existing = $statusResult ? $statusResult->fetch_assoc() : null;
            $statusQuery->close();
            if (($existing['status'] ?? '') === 'sent') {
                return ['success' => true, 'already_sent' => true, 'count' => 0];
            }
            return ['success' => true, 'already_processing' => true, 'count' => 0];
        }
    }

    $products = $conn->query("SELECT p.id, p.product_name, p.sku, p.category,
        (COALESCE(p.pieces, 0) + COALESCE(p.box, 0) * COALESCE(NULLIF(p.pieces_per_box, 0), 12)) AS current_stock,
        COALESCE(p.low_stock_threshold, 20) AS low_stock_threshold
        FROM products p
        WHERE (COALESCE(p.pieces, 0) + COALESCE(p.box, 0) * COALESCE(NULLIF(p.pieces_per_box, 0), 12)) <= COALESCE(p.low_stock_threshold, 20)
        ORDER BY current_stock ASC, p.product_name ASC");
    if (!$products) {
        $error = 'Unable to load low-stock products: ' . $conn->error;
        error_log('Daily low-stock digest query failed: ' . $conn->error);
        recordDailyLowStockDigestResult($conn, $today, false, $error);
        return ['success' => false, 'error' => $error];
    }

    $rows = [];
    while ($product = $products->fetch_assoc()) {
        $rows[] = $product;
    }
    if (!$rows) {
        recordDailyLowStockDigestResult($conn, $today, true, null);
        return ['success' => true, 'no_alerts' => true, 'count' => 0];
    }

    $smtpHost = getenv('SMTP_HOST');
    $smtpUsername = getenv('SMTP_USERNAME');
    $smtpPassword = getenv('SMTP_PASSWORD');
    $smtpFrom = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
    if (!$smtpHost || !$smtpUsername || !$smtpPassword || !$smtpFrom) {
        $error = 'SMTP settings are not configured.';
        error_log('Daily low-stock digest failed: SMTP settings are not configured.');
        recordDailyLowStockDigestResult($conn, $today, false, $error);
        return ['success' => false, 'error' => $error];
    }

    require_once __DIR__ . '/vendor/autoload.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $safeRows = '';
    $textRows = [];
    foreach ($rows as $product) {
        $name = (string)$product['product_name'];
        $sku = (string)($product['sku'] ?: ('MED-' . (int)$product['id']));
        $stock = (int)$product['current_stock'];
        $threshold = (int)$product['low_stock_threshold'];
        $category = (string)($product['category'] ?: 'Not specified');
        $safeRows .= '<tr><td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td><td>'
            . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') . '</td><td>'
            . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '</td><td>'
            . number_format($stock) . '</td><td>' . number_format($threshold) . '</td></tr>';
        $textRows[] = "$name (SKU: $sku, Category: $category) - stock $stock, threshold $threshold";
    }

    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->setFrom($smtpFrom, getenv('SMTP_FROM_NAME') ?: 'Josefina Farmacia');
        $firstRecipient = array_shift($recipients);
        $mail->addAddress($firstRecipient['email'], $firstRecipient['name'] ?: 'Admin');
        foreach ($recipients as $account) {
            $mail->addBCC($account['email'], $account['name'] ?: 'Pharmacist');
        }
        $mail->isHTML(true);
        $mail->Subject = 'Daily Low Stock Summary - ' . count($rows) . ' product' . (count($rows) === 1 ? '' : 's');
        $mail->Body = '<h2>Daily Low Stock Summary</h2><p>As of ' . htmlspecialchars(date('F j, Y'), ENT_QUOTES, 'UTF-8')
            . ', ' . count($rows) . ' product' . (count($rows) === 1 ? ' has' : 's have')
            . ' reached or fallen below its low-stock threshold.</p><table border="1" cellpadding="8" cellspacing="0">'
            . '<thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Current Stock</th><th>Threshold</th></tr></thead><tbody>'
            . $safeRows . '</tbody></table><p>Please review and restock these products.</p>';
        $mail->AltBody = "Daily Low Stock Summary - " . date('F j, Y') . "\n\n" . implode("\n", $textRows);
        $mail->send();
        recordDailyLowStockDigestResult($conn, $today, true, null);
        return ['success' => true, 'sent' => true, 'count' => count($rows)];
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
        error_log('Daily low-stock digest email failed: ' . $error);
        recordDailyLowStockDigestResult($conn, $today, false, $error);
        return ['success' => false, 'error' => $error, 'count' => count($rows)];
    }
}

function recordDailyLowStockDigestResult($conn, $date, $success, $error) {
    $status = $success ? 'sent' : 'failed';
    $sentAt = $success ? 'NOW()' : 'NULL';
    $update = $conn->prepare("UPDATE low_stock_daily_digests SET status = ?, sent_at = $sentAt, error_message = ? WHERE digest_date = ?");
    if (!$update) {
        error_log('Unable to save daily low-stock digest result: ' . $conn->error);
        return false;
    }
    $update->bind_param('sss', $status, $error, $date);
    $saved = $update->execute();
    if (!$saved) {
        error_log('Unable to save daily low-stock digest result: ' . $update->error);
    }
    $update->close();
    return $saved;
}

function checkAndSendLowStockAlert($conn, $productId) {
    if (!ensureLowStockNotificationSchema($conn)) {
        return ['triggered' => false, 'success' => false, 'error' => 'Low-stock notification schema is unavailable.'];
    }
    return capstoneCheckAndNotifyStockState($conn, (int)$productId);
}

function ensureInventoryAlertsTable($conn) {
    return $conn->query("CREATE TABLE IF NOT EXISTS inventory_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        min_stock_level INT DEFAULT 10,
        alert_threshold INT DEFAULT 20,
        is_active TINYINT(1) DEFAULT 1,
        last_alert_sent DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_product_alert (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getLowStockProducts($conn) {
    $skuColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'sku'");
    if ($skuColumn === false) {
        error_log('Inventory alert SKU lookup failed: ' . $conn->error);
        return null;
    }
    $skuSelect = $skuColumn->num_rows > 0 ? 'p.sku' : "''";
    $batchTable = $conn->query("SHOW TABLES LIKE 'product_batches'");
    if ($batchTable === false) {
        error_log('Inventory alert batch table lookup failed: ' . $conn->error);
        return null;
    }
    $batchJoin = '';
    $batchSelect = "'' AS lot_details";
    if ($batchTable->num_rows > 0) {
        $batchJoin = "LEFT JOIN (
                SELECT product_id,
                       GROUP_CONCAT(
                           CONCAT(lot_number, ' (', current_quantity, ' units, exp ', DATE_FORMAT(expiration_date, '%b %e, %Y'), ')')
                           ORDER BY expiration_date ASC, lot_number ASC SEPARATOR ', '
                       ) AS lot_details
                FROM product_batches
                WHERE current_quantity > 0 AND recalled = 0
                GROUP BY product_id
            ) batches ON batches.product_id = p.id";
        $batchSelect = "COALESCE(batches.lot_details, '') AS lot_details";
    }
    $stockExpression = '(COALESCE(p.pieces, 0) + (COALESCE(p.box, 0) * COALESCE(NULLIF(p.pieces_per_box, 0), 12)))';
    $sql = "SELECT p.id, p.product_name, p.supplier_id, s.name AS supplier_name, $skuSelect AS sku,
                   $batchSelect, $stockExpression AS current_stock,
                   $stockExpression AS pieces,
                   COALESCE(p.low_stock_threshold, 20) AS alert_threshold
            FROM products p
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            $batchJoin
            WHERE $stockExpression <= COALESCE(p.low_stock_threshold, 20)
            ORDER BY (current_stock = 0) DESC, current_stock ASC, p.product_name ASC";
    $result = $conn->query($sql);
    if ($result === false) {
        error_log('Inventory low-stock products query failed: ' . $conn->error);
        return null;
    }
    return ($result && $result->num_rows > 0) ? $result : null;
}

function createInventoryAlert($conn, $product_id, $min_level = 10, $threshold = 20, $user_id = null) {
    if (!ensureInventoryAlertsTable($conn)) return false;
    $product_id = (int)$product_id;
    $min_level = (int)$min_level;
    $threshold = (int)$threshold;
    $user_id = $user_id !== null ? (int)$user_id : null;
    
        $sql = "INSERT INTO inventory_alerts (product_id, min_stock_level, alert_threshold, created_at)
            VALUES ($product_id, $min_level, $threshold, NOW())
            ON DUPLICATE KEY UPDATE min_stock_level = $min_level, alert_threshold = $threshold, updated_at = NOW()";
    return $conn->query($sql);
}

function getAlertRules($conn) {
    if (!ensureInventoryAlertsTable($conn)) return null;
    $skuColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'sku'");
    if ($skuColumn === false) {
        error_log('Inventory alert rule SKU lookup failed: ' . $conn->error);
        return null;
    }
    $skuSelect = $skuColumn->num_rows > 0 ? 'p.sku' : "''";
    $sql = "SELECT ia.id, p.id AS product_id, p.product_name, $skuSelect AS sku, ia.min_stock_level, ia.alert_threshold, ia.last_alert_sent, NULL as owner_name, ia.created_at
            FROM inventory_alerts ia
            JOIN products p ON ia.product_id = p.id
            ORDER BY ia.created_at DESC";
    $result = $conn->query($sql);
    if ($result === false) {
        error_log('Inventory alert rules query failed: ' . $conn->error);
        return null;
    }
    return ($result && $result->num_rows > 0) ? $result : null;
}

function checkAndSendInventoryAlerts($conn) {
    $products = $conn->query('SELECT id FROM products');
    if (!$products) return;
    while ($product = $products->fetch_assoc()) {
        checkAndSendLowStockAlert($conn, (int)$product['id']);
    }
}

function createReorderRequest($conn, $product_id, $supplier_id, $reorder_qty, $user_id = null, $expected_delivery = '', $notes = '') {
    $product_id = (int)$product_id;
    $supplier_id = (int)$supplier_id;
    $reorder_qty = (int)$reorder_qty;
    $userId = $user_id !== null ? (int)$user_id : null;
    if ($product_id < 1 || $supplier_id < 1 || $reorder_qty < 1) {
        return ['success' => false, 'error' => 'Select a valid product, supplier, and reorder quantity.'];
    }
    $manilaTimezone = new DateTimeZone('Asia/Manila');
    $expectedDate = DateTime::createFromFormat('!Y-m-d\TH:i', $expected_delivery, $manilaTimezone);
    $expectedDateErrors = DateTime::getLastErrors();
    if (!$expectedDate
        || ($expectedDateErrors !== false && ($expectedDateErrors['warning_count'] > 0 || $expectedDateErrors['error_count'] > 0))
        || $expectedDate->format('Y-m-d\TH:i') !== $expected_delivery
        || $expectedDate <= new DateTime('now', $manilaTimezone)) {
        return ['success' => false, 'error' => 'Enter a valid expected delivery date and time in the future.'];
    }

    $transactionStarted = false;
    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Unable to begin reorder transaction: ' . $conn->error);
        }
        $transactionStarted = true;
        $productStatement = $conn->prepare('SELECT product_name, pieces, box, pieces_per_box FROM products WHERE id = ? FOR UPDATE');
        if (!$productStatement) {
            throw new RuntimeException('Unable to prepare product lookup: ' . $conn->error);
        }
        $productStatement->bind_param('i', $product_id);
        if (!$productStatement->execute()) {
            throw new RuntimeException('Unable to load product for reorder: ' . $productStatement->error);
        }
        $product = $productStatement->get_result()->fetch_assoc();
        $productStatement->close();
        if (!$product) {
            $conn->rollback();
            return ['success' => false, 'error' => 'The selected product was not found.'];
        }

        $existingStatement = $conn->prepare("SELECT id FROM inventory_reorders
            WHERE product_id = ? AND status IN ('Pending', 'Ordered') LIMIT 1");
        if (!$existingStatement) {
            throw new RuntimeException('Unable to prepare duplicate reorder check: ' . $conn->error);
        }
        $existingStatement->bind_param('i', $product_id);
        if (!$existingStatement->execute()) {
            throw new RuntimeException('Unable to check for an existing reorder: ' . $existingStatement->error);
        }
        $existingReorder = $existingStatement->get_result()->fetch_assoc();
        $existingStatement->close();
        if ($existingReorder) {
            $conn->rollback();
            return [
                'success' => false,
                'error' => 'A reorder is already pending for this product (Reorder #' . (int)$existingReorder['id'] . '). Receive or finish that reorder before creating another.'
            ];
        }

        $piecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
        $currentStock = (int)($product['pieces'] ?? 0) + ((int)($product['box'] ?? 0) * $piecesPerBox);
        $reorderColumnsResult = $conn->query("SHOW COLUMNS FROM inventory_reorders");
        if ($reorderColumnsResult === false) {
            throw new RuntimeException('Unable to inspect reorder fields: ' . $conn->error);
        }
        $reorderColumns = [];
        while ($column = $reorderColumnsResult->fetch_assoc()) { $reorderColumns[$column['Field']] = true; }
        $insertFields = ['product_id', 'current_stock', 'reorder_quantity', 'supplier_id', 'status', 'created_at'];
        if (isset($reorderColumns['requested_by'])) {
            $insertFields[] = 'requested_by';
        }
        if (isset($reorderColumns['expected_delivery'])) {
            $insertFields[] = 'expected_delivery';
        }
        if (isset($reorderColumns['notes'])) {
            $insertFields[] = 'notes';
        }
        $fieldList = '`' . implode('`, `', $insertFields) . '`';
        $valueList = "$product_id, $currentStock, $reorder_qty, $supplier_id, 'Pending', NOW()";
        if (isset($reorderColumns['requested_by'])) {
            $valueList .= $userId === null ? ', NULL' : ', ' . $userId;
        }
        if (isset($reorderColumns['expected_delivery'])) {
            $valueList .= " , '" . $conn->real_escape_string($expected_delivery) . "'";
        }
        if (isset($reorderColumns['notes'])) {
            $valueList .= " , '" . $conn->real_escape_string($notes) . "'";
        }
        $sql = "INSERT INTO inventory_reorders ($fieldList) VALUES ($valueList)";
        if (!$conn->query($sql)) {
            throw new RuntimeException('Unable to create reorder request: ' . $conn->error);
        }

        $requestId = $conn->insert_id;
        if (!$conn->commit()) {
            throw new RuntimeException('Unable to save reorder request: ' . $conn->error);
        }
        $transactionStarted = false;
        $items = [[
            'product_name' => $product['product_name'],
            'quantity' => $reorder_qty
        ]];
        notifySupplierOfPurchaseOrder($conn, $requestId, $supplier_id, 'REORDER-' . $requestId, $items, 0);
        return ['success' => true];
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        error_log('Inventory reorder creation failed: ' . $exception->getMessage());
        return ['success' => false, 'error' => 'Unable to create reorder request. Please try again.'];
    }
}

function createBulkReorderRequests(mysqli $conn, array $items, int $supplierId, int $userId, string $expectedDelivery, string $notes): array {
    if (count($items) < 2 || $supplierId < 1 || $userId < 1) {
        return ['success' => false, 'error' => 'Select at least two products from the same supplier.'];
    }
    if (strlen($notes) > 2000) {
        return ['success' => false, 'error' => 'Order notes cannot exceed 2,000 characters.'];
    }
    $manilaTimezone = new DateTimeZone('Asia/Manila');
    $expectedDate = DateTime::createFromFormat('!Y-m-d\TH:i', $expectedDelivery, $manilaTimezone);
    $expectedDateErrors = DateTime::getLastErrors();
    if (!$expectedDate
        || ($expectedDateErrors !== false && ($expectedDateErrors['warning_count'] > 0 || $expectedDateErrors['error_count'] > 0))
        || $expectedDate->format('Y-m-d\TH:i') !== $expectedDelivery
        || $expectedDate <= new DateTime('now', $manilaTimezone)) {
        return ['success' => false, 'error' => 'Enter a valid expected delivery date and time in the future.'];
    }
    foreach ($items as $productId => $quantity) {
        if ((int)$productId < 1 || (int)$quantity < 1) {
            return ['success' => false, 'error' => 'Each selected product must have a reorder quantity of at least 1.'];
        }
    }
    ksort($items, SORT_NUMERIC);

    $transactionStarted = false;
    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Unable to begin bulk reorder transaction: ' . $conn->error);
        }
        $transactionStarted = true;

        $columnsResult = $conn->query('SHOW COLUMNS FROM inventory_reorders');
        if ($columnsResult === false) {
            throw new RuntimeException('Unable to inspect reorder fields: ' . $conn->error);
        }
        $columns = [];
        while ($column = $columnsResult->fetch_assoc()) {
            $columns[$column['Field']] = true;
        }
        $supplierStatement = $conn->prepare('SELECT id FROM suppliers WHERE id = ? AND is_active = 1 FOR UPDATE');
        if (!$supplierStatement) {
            throw new RuntimeException('Unable to prepare bulk supplier validation: ' . $conn->error);
        }
        $supplierStatement->bind_param('i', $supplierId);
        if (!$supplierStatement->execute()) {
            throw new RuntimeException('Unable to validate bulk supplier: ' . $supplierStatement->error);
        }
        $supplier = $supplierStatement->get_result()->fetch_assoc();
        $supplierStatement->close();
        if (!$supplier) {
            throw new DomainException('The selected supplier is unavailable. Choose an active supplier.');
        }
        $groupId = 'BULK-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $itemsForNotification = [];
        $expectedDeliverySql = "'" . $conn->real_escape_string($expectedDelivery) . "'";
        $notesSql = "'" . $conn->real_escape_string($notes) . "'";

        foreach ($items as $productId => $quantity) {
            $productId = (int)$productId;
            $quantity = (int)$quantity;
            $productStatement = $conn->prepare('SELECT product_name, supplier_id, pieces, box, pieces_per_box FROM products WHERE id = ? FOR UPDATE');
            if (!$productStatement) {
                throw new RuntimeException('Unable to prepare bulk product lookup: ' . $conn->error);
            }
            $productStatement->bind_param('i', $productId);
            if (!$productStatement->execute()) {
                throw new RuntimeException('Unable to load selected bulk product: ' . $productStatement->error);
            }
            $product = $productStatement->get_result()->fetch_assoc();
            $productStatement->close();
            $assignedSupplierId = (int)($product['supplier_id'] ?? 0);
            if (!$product || ($assignedSupplierId > 0 && $assignedSupplierId !== $supplierId)) {
                throw new DomainException('The selected supplier does not match one of the selected products.');
            }

            $piecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
            $currentStock = (int)($product['pieces'] ?? 0) + ((int)($product['box'] ?? 0) * $piecesPerBox);
            $fields = ['product_id', 'current_stock', 'reorder_quantity', 'supplier_id', 'status', 'created_at'];
            $values = [(string)$productId, (string)$currentStock, (string)$quantity, (string)$supplierId, "'Pending'", 'NOW()'];
            if (isset($columns['requested_by'])) {
                $fields[] = 'requested_by';
                $values[] = (string)$userId;
            }
            if (isset($columns['expected_delivery'])) {
                $fields[] = 'expected_delivery';
                $values[] = $expectedDeliverySql;
            }
            if (isset($columns['notes'])) {
                $fields[] = 'notes';
                $values[] = $notesSql;
            }
            if (isset($columns['order_group_id'])) {
                $fields[] = 'order_group_id';
                $values[] = "'" . $conn->real_escape_string($groupId) . "'";
            }
            $fieldList = '`' . implode('`, `', $fields) . '`';
            if (!$conn->query('INSERT INTO inventory_reorders (' . $fieldList . ') VALUES (' . implode(', ', $values) . ')')) {
                throw new RuntimeException('Unable to save a product in the bulk reorder: ' . $conn->error);
            }
            $itemsForNotification[] = ['product_name' => (string)$product['product_name'], 'quantity' => $quantity];
        }

        if (!$conn->commit()) {
            throw new RuntimeException('Unable to save bulk reorder: ' . $conn->error);
        }
        $transactionStarted = false;
        $notification = notifySupplierOfPurchaseOrder($conn, 0, $supplierId, $groupId, $itemsForNotification, 0);
        return [
            'success' => true,
            'group_id' => $groupId,
            'item_count' => count($itemsForNotification),
            'notification_sent' => !empty($notification['email']) || !empty($notification['sms'])
        ];
    } catch (DomainException $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        return ['success' => false, 'error' => $exception->getMessage()];
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        error_log('Bulk inventory reorder creation failed: ' . $exception->getMessage());
        return ['success' => false, 'error' => 'Unable to create the bulk order. No reorder requests were saved.'];
    }
}

function analyticsDateRangeCondition($conn, $column, $start_date = null, $end_date = null) {
    if (!$start_date || !$end_date) {
        return '';
    }
    $safeStartDate = $conn->real_escape_string($start_date);
    $safeEndDate = $conn->real_escape_string($end_date);
    return "$column >= '$safeStartDate' AND $column < DATE_ADD('$safeEndDate', INTERVAL 1 DAY)";
}

function getTopCustomers($conn, $limit = 10, $start_date = null, $end_date = null, $customer_id = null) {
    $limit = (int)$limit;
    $dateFilter = analyticsDateRangeCondition($conn, 'o.order_date', $start_date, $end_date);
    $conditions = array_filter([
        $dateFilter,
        "LOWER(TRIM(COALESCE(o.status, ''))) <> 'cancelled'",
        $customer_id !== null ? 'u.id = ' . (int)$customer_id : ''
    ]);
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $orderItemRevenue = getOrderItemRevenueExpression();
    $sql = "SELECT u.id, u.username, COUNT(DISTINCT o.id) as order_count, SUM($orderItemRevenue) as total_spent
            FROM users u
            JOIN orders o ON o.user_id = u.id
            JOIN order_items oi ON oi.order_id = o.id
            $where
            GROUP BY u.id
            ORDER BY total_spent DESC
            LIMIT $limit";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result : null;
}

function getUsersCount($conn) {
    $result = $conn->query("SELECT COUNT(*) as total_users FROM users");
    $row = $result ? $result->fetch_assoc() : null;
    return $row['total_users'] ?? 0;
}

function getUserById($conn, $user_id) {
    $user_id = (int)$user_id;
    $sql = "SELECT id, username, email FROM users WHERE id = $user_id";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
}

// ==========================================
// SUPPLIER MANAGEMENT FUNCTIONS
// ==========================================

function ensureSuppliersTable($conn) {
    return $conn->query("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        contact_person VARCHAR(255),
        email VARCHAR(100),
        phone VARCHAR(20),
        address TEXT,
        city VARCHAR(100),
        province VARCHAR(100),
        postal_code VARCHAR(10),
        company_name VARCHAR(255),
        tin_number VARCHAR(50),
        payment_terms VARCHAR(100),
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getAllSuppliers($conn, $active_only = false) {
    if (!ensureSuppliersTable($conn)) return null;
    $where = $active_only ? "WHERE is_active = 1" : "";
    $sql = "SELECT * FROM suppliers $where ORDER BY name ASC";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result : null;
}

function getSupplierById($conn, $supplier_id) {
    if (!ensureSuppliersTable($conn)) return null;
    $supplier_id = (int)$supplier_id;
    $sql = "SELECT * FROM suppliers WHERE id = $supplier_id";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
}

function addSupplier($conn, $data) {
    if (!ensureSuppliersTable($conn)) return false;
    $name = $conn->real_escape_string($data['name']);
    $contact = $conn->real_escape_string($data['contact_person'] ?? '');
    $email = $conn->real_escape_string($data['email'] ?? '');
    $phone = $conn->real_escape_string($data['phone'] ?? '');
    $address = $conn->real_escape_string($data['address'] ?? '');
    $city = $conn->real_escape_string($data['city'] ?? '');
    $province = $conn->real_escape_string($data['province'] ?? '');
    $postal = $conn->real_escape_string($data['postal_code'] ?? '');
    $company = $conn->real_escape_string($data['company_name'] ?? '');
    $tin = $conn->real_escape_string($data['tin_number'] ?? '');
    $terms = $conn->real_escape_string($data['payment_terms'] ?? '');
    
    $sql = "INSERT INTO suppliers (name, contact_person, email, phone, address, city, province, postal_code, company_name, tin_number, payment_terms)
            VALUES ('$name', '$contact', '$email', '$phone', '$address', '$city', '$province', '$postal', '$company', '$tin', '$terms')";
    return $conn->query($sql);
}

function updateSupplier($conn, $supplier_id, $data) {
    if (!ensureSuppliersTable($conn)) return false;
    $supplier_id = (int)$supplier_id;
    $updates = array();
    
    foreach ($data as $key => $value) {
        $value = $conn->real_escape_string($value);
        $updates[] = "`$key` = '$value'";
    }
    
    $sql = "UPDATE suppliers SET " . implode(", ", $updates) . " WHERE id = $supplier_id";
    return $conn->query($sql);
}

function deleteSupplier($conn, $supplier_id) {
    if (!ensureSuppliersTable($conn)) return false;
    $supplier_id = (int)$supplier_id;
    $sql = "UPDATE suppliers SET is_active = 0 WHERE id = $supplier_id";
    return $conn->query($sql);
}

// ==========================================
// PURCHASE ORDER FUNCTIONS
// ==========================================

function generatePONumber($conn) {
    $result = $conn->query("SELECT COUNT(*) as count FROM purchase_orders");
    $row = $result->fetch_assoc();
    return "PO-" . date('Y') . "-" . str_pad($row['count'] + 1, 5, '0', STR_PAD_LEFT);
}

function createPurchaseOrder($conn, $supplier_id, $items, $expected_delivery, $user_id) {
    $supplier_id = (int)$supplier_id;
    $user_id = (int)$user_id;
    $po_number = generatePONumber($conn);
    $total_amount = 0;
    
    foreach ($items as $item) {
        $total_amount += floatval($item['total_price']);
    }
    
    $total_amount = round($total_amount, 2);
    $expected_delivery = $conn->real_escape_string($expected_delivery);
    
    $sql = "INSERT INTO purchase_orders (po_number, supplier_id, order_date, expected_delivery, total_amount, created_by)
            VALUES ('$po_number', $supplier_id, NOW(), '$expected_delivery', $total_amount, $user_id)";
    
    if ($conn->query($sql)) {
        $po_id = $conn->insert_id;
        
        // Insert items
        $itemsWithNames = [];
        foreach ($items as $item) {
            $product_id = (int)$item['product_id'];
            $quantity = (int)$item['quantity'];
            $unit_price = round(floatval($item['unit_price']), 2);
            $total = round($quantity * $unit_price, 2);
            
            $item_sql = "INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_price, total_price)
                        VALUES ($po_id, $product_id, $quantity, $unit_price, $total)";
            $conn->query($item_sql);
            $product = getProductById($conn, $product_id);
            $item['product_name'] = $product['product_name'] ?? 'Product';
            $itemsWithNames[] = $item;
        }
        
        addNotification($conn, "📦 New Purchase Order Created: $po_number");
        notifySupplierOfPurchaseOrder($conn, $po_id, $supplier_id, $po_number, $itemsWithNames, $total_amount);
        return $po_id;
    }
    return false;
}

function getPurchaseOrders($conn, $status = null) {
    $where = $status ? "WHERE status = '$status'" : "";
    $sql = "SELECT po.*, s.name as supplier_name FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            $where ORDER BY po.created_at DESC";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result : null;
}



// EMAIL TEMPLATE FUNCTIONS
// ==========================================

function getEmailTemplates($conn, $active_only = false) {
    $where = $active_only ? "WHERE et.is_active = 1" : "";
    $sql = "SELECT et.*, u.username as creator_name
            FROM email_templates et
            LEFT JOIN users u ON et.created_by = u.id
            $where ORDER BY et.template_name ASC";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result : null;
}

function getEmailTemplateByName($conn, $template_name) {
    $template_name = $conn->real_escape_string($template_name);
    $sql = "SELECT et.*, u.username as creator_name
            FROM email_templates et
            LEFT JOIN users u ON et.created_by = u.id
            WHERE et.template_name = '$template_name'";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
}

function createEmailTemplate($conn, $template_name, $template_type, $subject, $body, $variables, $user_id) {
    $template_name = $conn->real_escape_string($template_name);
    $template_type = $conn->real_escape_string($template_type);
    $subject = $conn->real_escape_string($subject);
    $body = $conn->real_escape_string($body);
    $variables = $conn->real_escape_string($variables);
    $user_id = (int)$user_id;
    
    $sql = "INSERT INTO email_templates (template_name, template_type, subject, body, variables, created_by)
            VALUES ('$template_name', '$template_type', '$subject', '$body', '$variables', $user_id)";
    return $conn->query($sql);
}

function updateEmailTemplate($conn, $template_id, $subject, $body) {
    $template_id = (int)$template_id;
    $subject = $conn->real_escape_string($subject);
    $body = $conn->real_escape_string($body);
    
    $sql = "UPDATE email_templates SET subject = '$subject', body = '$body' WHERE id = $template_id";
    return $conn->query($sql);
}

function sendEmailFromTemplate($conn, $template_name, $recipient_email, $variables = array()) {
    $template = getEmailTemplateByName($conn, $template_name);
    if (!$template) return false;
    
    if (isset($variables['order_id']) && ctype_digit((string)$variables['order_id'])) {
        $variables['order_id'] = formatOrderNumber($conn, (int)$variables['order_id']);
    }

    $subject = $template['subject'];
    $body = $template['body'];
    
    // Replace variables in subject and body
    foreach ($variables as $key => $value) {
        $placeholder = "{{" . $key . "}}";
        $subject = str_replace($placeholder, $value, $subject);
        $body = str_replace($placeholder, $value, $body);
    }
    if (isset($variables['order_id']) && preg_match('/^ORD-\d{8}-\d{4,}$/', (string)$variables['order_id'])) {
        $subject = str_replace('#' . $variables['order_id'], $variables['order_id'], $subject);
        $body = str_replace('#' . $variables['order_id'], $variables['order_id'], $body);
    }
    
    // Send email
    $headers = "From: info@josefinafarmacia.com\r\nContent-Type: text/plain; charset=UTF-8";
    return mail($recipient_email, $subject, $body, $headers);
}

// ==========================================
// ANALYTICS FUNCTIONS
// ==========================================

function getProductPriceExpression($conn, $alias = 'p') {
    static $priceExpressions = [];
    $cacheKey = $alias . ':' . spl_object_id($conn);
    if (isset($priceExpressions[$cacheKey])) {
        return $priceExpressions[$cacheKey];
    }

    $priceColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'price'");
    $pricePieceColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'price_piece'");
    if ($priceColumn && $priceColumn->num_rows > 0) {
        $expression = "COALESCE($alias.price, 0)";
    } elseif ($pricePieceColumn && $pricePieceColumn->num_rows > 0) {
        $expression = "COALESCE($alias.price_piece, 0)";
    } else {
        $expression = '0';
    }
    $priceExpressions[$cacheKey] = $expression;
    return $expression;
}

function hasBatchMovementsTable($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'batch_movements'");
    return $result && $result->num_rows > 0;
}

function hasPosSalesTable($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'pos_sales'");
    return $result && $result->num_rows > 0;
}

function hasPosSaleItemsTable($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'pos_sale_items'");
    return $result && $result->num_rows > 0;
}

function hasBatchMovementPosSaleColumn($conn) {
    if (!hasBatchMovementsTable($conn)) {
        return false;
    }
    $result = $conn->query("SHOW COLUMNS FROM batch_movements LIKE 'pos_sale_id'");
    return $result && $result->num_rows > 0;
}

function getOrderItemRevenueExpression($itemAlias = 'oi', $orderAlias = 'o') {
    return "CASE
        WHEN COALESCE($itemAlias.total_price, 0) > 0 THEN $itemAlias.total_price
        WHEN LOWER(COALESCE($orderAlias.payment_method, '')) = 'onsite' THEN COALESCE($itemAlias.price, 0)
        ELSE COALESCE($itemAlias.price, 0) * GREATEST(COALESCE($itemAlias.quantity, 0), 1)
    END";
}

function getSalesAnalytics($conn, $days = 30, $start_date = null, $end_date = null) {
    $days = (int)$days;
    $orderDateFilter = analyticsDateRangeCondition($conn, 'o.order_date', $start_date, $end_date);
    $movementDateFilter = analyticsDateRangeCondition($conn, 'bm.created_at', $start_date, $end_date);
    if ($orderDateFilter === '') {
        $orderDateFilter = "o.order_date >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    }
    if ($movementDateFilter === '') {
        $movementDateFilter = "bm.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    }
    $posDateFilter = analyticsDateRangeCondition($conn, 'ps.created_at', $start_date, $end_date);
    if ($posDateFilter === '') {
        $posDateFilter = "ps.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    }
    $productPrice = getProductPriceExpression($conn);
    $orderItemRevenue = getOrderItemRevenueExpression();
    $posMovementCondition = hasBatchMovementPosSaleColumn($conn) ? 'AND bm.pos_sale_id IS NULL' : '';
    $manualOrderCondition = "AND (bm.order_id IS NULL OR NOT EXISTS (
        SELECT 1 FROM orders cancelled_order
        WHERE cancelled_order.id = bm.order_id
          AND LOWER(TRIM(COALESCE(cancelled_order.status, ''))) = 'cancelled'
    ))";
    $manualSales = hasBatchMovementsTable($conn) ? "
        UNION ALL
         SELECT COUNT(*) AS total_orders, COALESCE(SUM(bm.quantity * $productPrice), 0) AS total_sales,
             0 AS unique_customers, COALESCE(AVG(bm.quantity * $productPrice), 0) AS avg_order_value,
               DATE(bm.created_at) AS order_date
        FROM batch_movements bm
        JOIN products p ON p.id = bm.product_id
                WHERE bm.movement_type = 'sold' AND $movementDateFilter $manualOrderCondition
                   AND NOT EXISTS (
                       SELECT 1 FROM order_items oi
                       WHERE oi.order_id = bm.order_id AND oi.product_id = bm.product_id
                   )
                   $posMovementCondition
        GROUP BY DATE(bm.created_at)" : '';
    $posSales = hasPosSalesTable($conn) ? "
        UNION ALL
        SELECT COUNT(*) AS total_orders, COALESCE(SUM(ps.total_amount), 0) AS total_sales,
               0 AS unique_customers, COALESCE(AVG(ps.total_amount), 0) AS avg_order_value,
               DATE(ps.created_at) AS order_date
        FROM pos_sales ps
        WHERE $posDateFilter
        GROUP BY DATE(ps.created_at)" : '';
    $sql = "SELECT total_orders, total_sales, unique_customers, avg_order_value, order_date FROM (
            SELECT COUNT(*) AS total_orders, COALESCE(SUM(order_totals.order_total), 0) AS total_sales,
                   COUNT(DISTINCT order_totals.user_id) AS unique_customers,
                   COALESCE(AVG(order_totals.order_total), 0) AS avg_order_value,
                   DATE(order_totals.order_date) AS order_date
            FROM (
                SELECT o.id, o.user_id, o.order_date, SUM($orderItemRevenue) AS order_total
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE $orderDateFilter
                  AND LOWER(TRIM(COALESCE(o.status, ''))) <> 'cancelled'
                GROUP BY o.id, o.user_id, o.order_date
            ) AS order_totals
            GROUP BY DATE(order_totals.order_date)
            $manualSales
            $posSales
        ) AS sales_summary ORDER BY order_date ASC";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result : null;
}

function getTopProducts($conn, $limit = 10, $start_date = null, $end_date = null, $category = '') {
    $limit = (int)$limit;
    $category = trim((string)$category);
    $categoryFilter = $category !== '' ? "p.category = '" . $conn->real_escape_string($category) . "'" : '';
    $orderDateFilter = analyticsDateRangeCondition($conn, 'o.order_date', $start_date, $end_date);
    $movementDateFilter = analyticsDateRangeCondition($conn, 'bm.created_at', $start_date, $end_date);
    $posDateFilter = analyticsDateRangeCondition($conn, 'ps.created_at', $start_date, $end_date);
    $orderConditions = array_filter([
        $orderDateFilter,
        $categoryFilter,
        "LOWER(TRIM(COALESCE(o.status, ''))) <> 'cancelled'"
    ]);
    $orderWhere = $orderConditions ? 'WHERE ' . implode(' AND ', $orderConditions) : '';
    $movementWhere = $movementDateFilter !== '' ? "AND $movementDateFilter" : '';
    $movementCategoryWhere = $categoryFilter !== '' ? "AND $categoryFilter" : '';
    $productPrice = getProductPriceExpression($conn);
    $orderItemRevenue = getOrderItemRevenueExpression();
    $posMovementCondition = hasBatchMovementPosSaleColumn($conn) ? 'AND bm.pos_sale_id IS NULL' : '';
    $manualOrderCondition = "AND (bm.order_id IS NULL OR NOT EXISTS (
        SELECT 1 FROM orders cancelled_order
        WHERE cancelled_order.id = bm.order_id
          AND LOWER(TRIM(COALESCE(cancelled_order.status, ''))) = 'cancelled'
    ))";
    $manualSales = hasBatchMovementsTable($conn) ? "
            UNION ALL
            SELECT p.id, p.product_name, SUM(bm.quantity) AS total_sold,
                   SUM(bm.quantity * $productPrice) AS revenue
            FROM products p JOIN batch_movements bm ON p.id = bm.product_id
                 WHERE bm.movement_type = 'sold' $movementWhere $movementCategoryWhere $manualOrderCondition
                   AND NOT EXISTS (
                       SELECT 1 FROM order_items oi
                       WHERE oi.order_id = bm.order_id AND oi.product_id = bm.product_id
                   )
                   $posMovementCondition
            GROUP BY p.id, p.product_name" : '';
    $posSales = hasPosSalesTable($conn) && hasPosSaleItemsTable($conn) ? "
            UNION ALL
            SELECT p.id, p.product_name, SUM(psi.quantity) AS total_sold,
                   SUM(psi.total_price) AS revenue
            FROM pos_sales ps
            JOIN pos_sale_items psi ON psi.sale_id = ps.id
            JOIN products p ON p.id = psi.product_id
            WHERE psi.product_id IS NOT NULL
              " . ($posDateFilter !== '' ? "AND $posDateFilter" : '') . "
              $movementCategoryWhere
            GROUP BY p.id, p.product_name" : '';
    $sql = "SELECT id, product_name, SUM(total_sold) AS total_sold, SUM(revenue) AS revenue
            FROM (
                  SELECT p.id, p.product_name,
                      SUM(COALESCE(NULLIF(oi.quantity, 0), COALESCE(oi.pieces, 0) + (COALESCE(oi.box, 0) * COALESCE(NULLIF(p.pieces_per_box, 0), 12)))) AS total_sold,
                      SUM($orderItemRevenue) AS revenue
                FROM products p JOIN order_items oi ON p.id = oi.product_id
                JOIN orders o ON o.id = oi.order_id
                $orderWhere
                GROUP BY p.id, p.product_name
                $manualSales
                $posSales
            ) AS product_sales
            GROUP BY id, product_name
            ORDER BY total_sold DESC LIMIT $limit";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result : null;
}

function getOrderStats($conn, $start_date = null, $end_date = null) {
    $dateFilter = analyticsDateRangeCondition($conn, 'order_date', $start_date, $end_date);
    $where = $dateFilter !== '' ? "WHERE $dateFilter" : '';
    $sql = "SELECT 
            status,
            COUNT(*) as count
            FROM orders
            $where
            GROUP BY status";
    $result = $conn->query($sql);
    $stats = [
        'pending' => 0,
        'processing' => 0,
        'completed' => 0,
        'cancelled' => 0
    ];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $status = strtolower(trim((string)$row['status']));
            if ($status === 'shipped') {
                $status = 'processing';
            } elseif ($status === 'delivered') {
                $status = 'completed';
            }
            if (array_key_exists($status, $stats)) {
                $stats[$status] += (int)$row['count'];
            }
        }
    }
    return $stats;
}

function getRevenueByDate($conn, $start_date = null, $end_date = null) {
    $productPrice = getProductPriceExpression($conn);
    $days = is_numeric($start_date) && $end_date === null ? (int)$start_date : 0;
    $where = "WHERE LOWER(TRIM(COALESCE(o.status, ''))) <> 'cancelled'";
    if ($days > 0) {
        $where .= " AND o.order_date >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    } elseif ($start_date) {
        $start_date = $conn->real_escape_string($start_date);
        $where .= " AND o.order_date >= '$start_date'";
    }
    if ($end_date) {
        $end_date = $conn->real_escape_string($end_date);
        $where .= " AND o.order_date <= '$end_date'";
    }
    $posWhere = 'WHERE 1=1';
    if ($days > 0) {
        $posWhere .= " AND ps.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    } elseif ($start_date) {
        $posWhere .= " AND ps.created_at >= '$start_date'";
    }
    if ($end_date) {
        $posWhere .= " AND ps.created_at <= '$end_date'";
    }
    
    $manualOrderCondition = "AND (bm.order_id IS NULL OR NOT EXISTS (
        SELECT 1 FROM orders cancelled_order
        WHERE cancelled_order.id = bm.order_id
          AND LOWER(TRIM(COALESCE(cancelled_order.status, ''))) = 'cancelled'
    ))";
    $orderItemRevenue = getOrderItemRevenueExpression();
    $posMovementCondition = hasBatchMovementPosSaleColumn($conn) ? 'AND bm.pos_sale_id IS NULL' : '';
    $manualRevenue = hasBatchMovementsTable($conn) ? "
        UNION ALL
        SELECT DATE(bm.created_at) AS date,
             SUM(bm.quantity * $productPrice) AS revenue,
               COUNT(*) AS orders
        FROM batch_movements bm JOIN products p ON p.id = bm.product_id
        WHERE bm.movement_type = 'sold' $manualOrderCondition
          AND NOT EXISTS (
              SELECT 1 FROM order_items oi
              WHERE oi.order_id = bm.order_id AND oi.product_id = bm.product_id
          )" . ($days > 0 ? " AND bm.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)" : '') .
        ($start_date && $days === 0 ? " AND bm.created_at >= '$start_date'" : '') .
        ($end_date ? " AND bm.created_at <= '$end_date'" : '') .
        " $posMovementCondition" .
        " GROUP BY DATE(bm.created_at)" : '';
    $posRevenue = hasPosSalesTable($conn) ? "
        UNION ALL
        SELECT DATE(ps.created_at) AS date,
               SUM(ps.total_amount) AS revenue,
               COUNT(*) AS orders
        FROM pos_sales ps
        $posWhere
        GROUP BY DATE(ps.created_at)" : '';
    $sql = "SELECT date, SUM(revenue) AS revenue, SUM(orders) AS orders FROM (
            SELECT DATE(o.order_date) AS date, SUM($orderItemRevenue) AS revenue, COUNT(DISTINCT o.id) AS orders
            FROM orders o JOIN order_items oi ON o.id = oi.order_id
            $where GROUP BY DATE(o.order_date)
            $manualRevenue
            $posRevenue
        ) AS revenue_summary GROUP BY date ORDER BY date DESC";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result : null;
}

// Helper function (might already exist, but adding it for completeness)
function getProductById($conn, $product_id) {
    $product_id = (int)$product_id;
    $sql = "SELECT * FROM products WHERE id = $product_id";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
}

// ==========================================
// SUPPORT TICKET FUNCTIONS
// ==========================================

function getAllSupportTickets($conn, $status = null) {
    $where = "WHERE 1=1";
    if ($status) {
        $status = $conn->real_escape_string($status);
        $where = "WHERE st.status = '$status'";
    }
    
    $sql = "SELECT st.*, u.username, u.email, COUNT(sm.id) as message_count
            FROM support_tickets st
            JOIN users u ON st.user_id = u.id
            LEFT JOIN support_messages sm ON st.id = sm.ticket_id
            $where
            GROUP BY st.id
            ORDER BY st.updated_at DESC";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result : null;
}

function getSupportTicketById($conn, $ticket_id) {
    $ticket_id = (int)$ticket_id;
    $sql = "SELECT st.*, u.username, u.email
            FROM support_tickets st
            JOIN users u ON st.user_id = u.id
            WHERE st.id = $ticket_id";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
}

function getSupportMessages($conn, $ticket_id) {
    $ticket_id = (int)$ticket_id;
    $sql = "SELECT sm.*, u.username, u.email
            FROM support_messages sm
            LEFT JOIN users u ON sm.user_id = u.id
            WHERE sm.ticket_id = $ticket_id
            ORDER BY sm.created_at ASC";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result : null;
}

function addSupportMessage($conn, $ticket_id, $user_id, $message, $is_admin = false) {
    $ticket_id = (int)$ticket_id;
    $user_id = (int)$user_id;
    $is_admin = (int)$is_admin;
    $message = $conn->real_escape_string($message);
    
    $sql = "INSERT INTO support_messages (ticket_id, user_id, message, is_admin)
            VALUES ($ticket_id, $user_id, '$message', $is_admin)";
    return $conn->query($sql);
}

function updateTicketStatus($conn, $ticket_id, $status) {
    $ticket_id = (int)$ticket_id;
    $status = $conn->real_escape_string($status);
    
    $sql = "UPDATE support_tickets SET status = '$status', updated_at = NOW() WHERE id = $ticket_id";
    return $conn->query($sql);
}

function awardLoyaltyPointsForOrder($conn, $orderId, $adminId = null) {
    $orderId = (int)$orderId;
    $adminId = $adminId === null ? null : (int)$adminId;
    $orderResult = $conn->query("SELECT user_id, LOWER(status) AS order_status, (SELECT COALESCE(SUM(price), 0) FROM order_items WHERE order_id = orders.id) AS total_amount FROM orders WHERE id = $orderId LIMIT 1");
    $order = $orderResult ? $orderResult->fetch_assoc() : null;
    if (!$order || (int)$order['user_id'] < 1 || !in_array($order['order_status'], ['completed', 'delivered'], true)) return false;
    $existing = $conn->query("SELECT id FROM loyalty_transactions WHERE order_id = $orderId AND transaction_type = 'order_earned' LIMIT 1");
    if ($existing && $existing->num_rows > 0) return false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'loyalty_transactions'");
    if (!$tableCheck || $tableCheck->num_rows === 0) return false;
    $points = max(1, (int)floor((float)$order['total_amount'] / 100));
    $userId = (int)$order['user_id'];
    $description = 'Points earned from order ' . formatOrderNumber($conn, $orderId);
    $insert = $conn->prepare("INSERT INTO loyalty_transactions (user_id, order_id, points, transaction_type, description, created_by) VALUES (?, ?, ?, 'order_earned', ?, ?)");
    if (!$insert) return false;
    $insert->bind_param('iiisi', $userId, $orderId, $points, $description, $adminId);
    $saved = $insert->execute();
    $insert->close();
    if (!$saved) return false;
    $tier = $points >= 1500 ? 'Gold' : ($points >= 500 ? 'Silver' : 'Bronze');
    $account = $conn->prepare("INSERT INTO loyalty_accounts (user_id, points_balance, lifetime_points, tier) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE points_balance = points_balance + VALUES(points_balance), lifetime_points = lifetime_points + VALUES(lifetime_points), tier = IF(lifetime_points + VALUES(lifetime_points) >= 1500, 'Gold', IF(lifetime_points + VALUES(lifetime_points) >= 500, 'Silver', 'Bronze'))");
    if ($account) { $account->bind_param('iiis', $userId, $points, $points, $tier); $account->execute(); $account->close(); }
    return true;
}

?>