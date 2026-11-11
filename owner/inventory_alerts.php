<?php
session_start();
date_default_timezone_set('Asia/Manila');
include 'config.php';
include 'functions.php';
require_once __DIR__ . '/access_control.php';

requireOwnerPageAccess($conn, true);
$isPharmacist = ($_SESSION['role'] ?? '') === 'pharmacist';

$message = '';
$error = '';
$minimumExpectedDelivery = date('Y-m-d\TH:i', time() + 120);
$csrfToken = $_SESSION['inventory_alerts_csrf'] ?? '';
if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['inventory_alerts_csrf'] = $csrfToken;
}

$reorderTableResult = $conn->query("SHOW TABLES LIKE 'inventory_reorders'");
$reorderTableAvailable = $reorderTableResult && $reorderTableResult->num_rows > 0;
if ($reorderTableResult === false) {
    error_log('Inventory reorder table lookup failed: ' . $conn->error);
    $error = 'Unable to prepare inventory reorders.';
} elseif ($reorderTableResult->num_rows > 0) {
    $expectedDeliveryColumn = $conn->query("SHOW COLUMNS FROM inventory_reorders LIKE 'expected_delivery'");
    if ($expectedDeliveryColumn === false) {
        error_log('Inventory reorder expected-delivery schema setup failed: ' . $conn->error);
        $error = 'Unable to prepare inventory reorders.';
    } elseif ($expectedDeliveryColumn->num_rows === 0) {
        if (!$conn->query("ALTER TABLE inventory_reorders ADD COLUMN expected_delivery DATETIME NULL")) {
            error_log('Inventory reorder expected-delivery schema setup failed: ' . $conn->error);
            $error = 'Unable to prepare inventory reorders.';
        }
    } else {
        $expectedDeliveryDefinition = $expectedDeliveryColumn->fetch_assoc();
        if (stripos((string)($expectedDeliveryDefinition['Type'] ?? ''), 'datetime') !== 0
            && !$conn->query("ALTER TABLE inventory_reorders MODIFY expected_delivery DATETIME NULL")) {
            error_log('Inventory reorder expected-delivery schema setup failed: ' . $conn->error);
            $error = 'Unable to prepare inventory reorders.';
        }
    }
    $notesColumn = $conn->query("SHOW COLUMNS FROM inventory_reorders LIKE 'notes'");
    if ($notesColumn === false
        || ($notesColumn->num_rows === 0 && !$conn->query("ALTER TABLE inventory_reorders ADD COLUMN notes TEXT NULL"))) {
        error_log('Inventory reorder notes schema setup failed: ' . $conn->error);
        $error = 'Unable to prepare inventory reorders.';
    }
    if (!$conn->query("ALTER TABLE inventory_reorders ADD COLUMN IF NOT EXISTS received_quantity INT UNSIGNED NOT NULL DEFAULT 0")) {
        error_log('Inventory reorder received-quantity schema setup failed: ' . $conn->error);
        $error = 'Unable to prepare inventory reorders.';
    }
    if (!$conn->query("ALTER TABLE inventory_reorders ADD COLUMN IF NOT EXISTS order_group_id VARCHAR(40) NULL")) {
        error_log('Inventory reorder group schema setup failed: ' . $conn->error);
        $error = 'Unable to prepare inventory reorders.';
    }
} else {
    $error = 'Inventory reorders are not configured. Please run the database setup.';
}

function receiveInventoryReorder(
    mysqli $conn,
    int $reorderId,
    int $receivedQuantity,
    string $lotNumber,
    ?string $manufacturingDate,
    string $expirationDate,
    int $userId
): array {
    $batchSchema = "CREATE TABLE IF NOT EXISTS product_batches (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        lot_number VARCHAR(100) NOT NULL,
        expiration_date DATE NOT NULL,
        manufacturing_date DATE NULL,
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $movementSchema = "CREATE TABLE IF NOT EXISTS batch_movements (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conn->query($batchSchema)
        || !$conn->query("ALTER TABLE product_batches ADD COLUMN IF NOT EXISTS manufacturing_date DATE NULL")
        || !$conn->query($movementSchema)) {
        error_log('Inventory receive schema setup failed: ' . $conn->error);
        return ['success' => false, 'error' => 'Unable to prepare batch inventory for receiving stock.'];
    }

    $transactionStarted = false;
    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Unable to begin stock-receipt transaction: ' . $conn->error);
        }
        $transactionStarted = true;

        $reorderStatement = $conn->prepare("SELECT product_id, reorder_quantity, received_quantity, status, expected_delivery
            FROM inventory_reorders WHERE id = ? FOR UPDATE");
        if (!$reorderStatement) {
            throw new RuntimeException('Unable to prepare reorder lookup: ' . $conn->error);
        }
        $reorderStatement->bind_param('i', $reorderId);
        if (!$reorderStatement->execute()) {
            throw new RuntimeException('Unable to load reorder: ' . $reorderStatement->error);
        }
        $reorder = $reorderStatement->get_result()->fetch_assoc();
        $reorderStatement->close();
        if (!$reorder || !in_array(strtolower((string)$reorder['status']), ['pending', 'ordered'], true)) {
            throw new DomainException('This reorder is no longer available to receive.');
        }
        $remainingQuantity = max(0, (int)$reorder['reorder_quantity'] - (int)$reorder['received_quantity']);
        if ($remainingQuantity === 0 || $receivedQuantity > $remainingQuantity) {
            throw new DomainException('Received quantity cannot exceed the remaining reorder quantity of ' . $remainingQuantity . ' units.');
        }
        if (empty($reorder['expected_delivery'])) {
            throw new DomainException('Set an expected delivery date and time before receiving stock.');
        }
        try {
            $manilaTimezone = new DateTimeZone('Asia/Manila');
            $expectedDelivery = new DateTimeImmutable((string)$reorder['expected_delivery'], $manilaTimezone);
        } catch (Throwable $exception) {
            throw new DomainException('This reorder has an invalid expected delivery date and time.');
        }
        if (new DateTimeImmutable('now', $manilaTimezone) < $expectedDelivery) {
            throw new DomainException('Stock can only be received at or after the expected delivery date and time.');
        }

        $productId = (int)$reorder['product_id'];
        $productStatement = $conn->prepare('SELECT product_name, pieces, box, pieces_per_box FROM products WHERE id = ? FOR UPDATE');
        if (!$productStatement) {
            throw new RuntimeException('Unable to prepare product lookup: ' . $conn->error);
        }
        $productStatement->bind_param('i', $productId);
        if (!$productStatement->execute()) {
            throw new RuntimeException('Unable to load product stock: ' . $productStatement->error);
        }
        $product = $productStatement->get_result()->fetch_assoc();
        $productStatement->close();
        if (!$product) {
            throw new DomainException('The product for this reorder was not found.');
        }

        $lotStatement = $conn->prepare("SELECT id, product_id, expiration_date, manufacturing_date, recalled
            FROM product_batches WHERE LOWER(TRIM(lot_number)) = LOWER(TRIM(?)) ORDER BY id FOR UPDATE");
        if (!$lotStatement) {
            throw new RuntimeException('Unable to prepare lot lookup: ' . $conn->error);
        }
        $lotStatement->bind_param('s', $lotNumber);
        if (!$lotStatement->execute()) {
            throw new RuntimeException('Unable to check lot number: ' . $lotStatement->error);
        }
        $lotResults = $lotStatement->get_result();
        $batch = null;
        while ($lot = $lotResults->fetch_assoc()) {
            if ((int)$lot['product_id'] !== $productId) {
                $lotStatement->close();
                throw new DomainException('That lot number is already assigned to a different product.');
            }
            if ($batch !== null) {
                $lotStatement->close();
                throw new DomainException('This lot number already has duplicate batch records. Resolve those records before receiving it.');
            }
            $batch = $lot;
        }
        $lotStatement->close();

        if ($batch !== null) {
            if ((string)$batch['expiration_date'] !== $expirationDate) {
                throw new DomainException('The expiration date does not match the existing batch with this lot number.');
            }
            if ((int)$batch['recalled'] === 1) {
                throw new DomainException('Stock cannot be received into a recalled batch.');
            }
            if ($manufacturingDate !== null && !empty($batch['manufacturing_date'])
                && (string)$batch['manufacturing_date'] !== $manufacturingDate) {
                throw new DomainException('The manufacturing date does not match the existing batch with this lot number.');
            }
            $batchId = (int)$batch['id'];
            $batchUpdate = $conn->prepare("UPDATE product_batches
                SET received_quantity = received_quantity + ?, current_quantity = current_quantity + ?,
                    manufacturing_date = COALESCE(manufacturing_date, ?), updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND recalled = 0");
            if (!$batchUpdate) {
                throw new RuntimeException('Unable to prepare existing batch update: ' . $conn->error);
            }
            $batchUpdate->bind_param('iisi', $receivedQuantity, $receivedQuantity, $manufacturingDate, $batchId);
            if (!$batchUpdate->execute() || $batchUpdate->affected_rows !== 1) {
                throw new RuntimeException('Unable to update existing batch: ' . $batchUpdate->error);
            }
            $batchUpdate->close();
        } else {
            $batchInsert = $conn->prepare("INSERT INTO product_batches
                (product_id, lot_number, manufacturing_date, expiration_date, received_quantity, current_quantity, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$batchInsert) {
                throw new RuntimeException('Unable to prepare new batch insert: ' . $conn->error);
            }
            $batchInsert->bind_param(
                'isssiii',
                $productId,
                $lotNumber,
                $manufacturingDate,
                $expirationDate,
                $receivedQuantity,
                $receivedQuantity,
                $userId
            );
            if (!$batchInsert->execute()) {
                throw new RuntimeException('Unable to create received batch: ' . $batchInsert->error);
            }
            $batchId = (int)$batchInsert->insert_id;
            $batchInsert->close();
        }

        $piecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
        $currentStock = (int)($product['pieces'] ?? 0) + ((int)($product['box'] ?? 0) * $piecesPerBox);
        $updatedStock = $currentStock + $receivedQuantity;
        $productUpdate = $conn->prepare('UPDATE products SET quantity = ?, box = ?, pieces = ? WHERE id = ?');
        if (!$productUpdate) {
            throw new RuntimeException('Unable to prepare product stock update: ' . $conn->error);
        }
        $boxes = intdiv($updatedStock, $piecesPerBox);
        $pieces = $updatedStock % $piecesPerBox;
        $productUpdate->bind_param('iiii', $updatedStock, $boxes, $pieces, $productId);
        if (!$productUpdate->execute() || $productUpdate->affected_rows !== 1) {
            throw new RuntimeException('Unable to update product stock: ' . $productUpdate->error);
        }
        $productUpdate->close();

        $movement = $conn->prepare("INSERT INTO batch_movements
            (batch_id, product_id, quantity, movement_type, reference, created_by)
            VALUES (?, ?, ?, 'received', ?, ?)");
        if (!$movement) {
            throw new RuntimeException('Unable to prepare stock movement: ' . $conn->error);
        }
        $reference = 'Inventory reorder #' . $reorderId;
        $movement->bind_param('iiisi', $batchId, $productId, $receivedQuantity, $reference, $userId);
        if (!$movement->execute()) {
            throw new RuntimeException('Unable to record stock movement: ' . $movement->error);
        }
        $movement->close();

        $totalReceived = (int)$reorder['received_quantity'] + $receivedQuantity;
        $reorderQuantity = (int)$reorder['reorder_quantity'];
        $newStatus = $totalReceived >= $reorderQuantity ? 'Received' : (string)$reorder['status'];
        $reorderUpdate = $conn->prepare('UPDATE inventory_reorders SET received_quantity = ?, status = ? WHERE id = ?');
        if (!$reorderUpdate) {
            throw new RuntimeException('Unable to prepare reorder update: ' . $conn->error);
        }
        $reorderUpdate->bind_param('isi', $totalReceived, $newStatus, $reorderId);
        if (!$reorderUpdate->execute()) {
            throw new RuntimeException('Unable to update reorder receipt status: ' . $reorderUpdate->error);
        }
        $reorderUpdate->close();

        if (!$conn->commit()) {
            throw new RuntimeException('Unable to commit received stock: ' . $conn->error);
        }
        $transactionStarted = false;
        checkAndSendLowStockAlert($conn, $productId);
        $receiptMessage = $newStatus === 'Received'
            ? 'Delivery received and reorder marked Received.'
            : 'Partial delivery recorded. Receive the remaining quantity when it arrives.';
        return ['success' => true, 'message' => $receiptMessage];
    } catch (DomainException $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        return ['success' => false, 'error' => $exception->getMessage()];
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        error_log('Inventory reorder receipt failed: ' . $exception->getMessage());
        return ['success' => false, 'error' => 'Unable to receive stock. No inventory changes were saved.'];
    }
}

function cancelDuplicateInventoryReorder(mysqli $conn, int $reorderId): array {
    $transactionStarted = false;
    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Unable to begin duplicate reorder cancellation: ' . $conn->error);
        }
        $transactionStarted = true;

        $reorderStatement = $conn->prepare("SELECT product_id, received_quantity, status
            FROM inventory_reorders WHERE id = ? FOR UPDATE");
        if (!$reorderStatement) {
            throw new RuntimeException('Unable to prepare duplicate reorder lookup: ' . $conn->error);
        }
        $reorderStatement->bind_param('i', $reorderId);
        if (!$reorderStatement->execute()) {
            throw new RuntimeException('Unable to load duplicate reorder: ' . $reorderStatement->error);
        }
        $reorder = $reorderStatement->get_result()->fetch_assoc();
        $reorderStatement->close();
        if (!$reorder || !in_array(strtolower((string)$reorder['status']), ['pending', 'ordered'], true)
            || (int)$reorder['received_quantity'] !== 0) {
            throw new DomainException('Only an unreceived pending duplicate can be cancelled.');
        }

        $productId = (int)$reorder['product_id'];
        $productStatement = $conn->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
        if (!$productStatement) {
            throw new RuntimeException('Unable to prepare product lock: ' . $conn->error);
        }
        $productStatement->bind_param('i', $productId);
        if (!$productStatement->execute()) {
            throw new RuntimeException('Unable to lock product: ' . $productStatement->error);
        }
        $productExists = $productStatement->get_result()->fetch_assoc();
        $productStatement->close();
        if (!$productExists) {
            throw new DomainException('The product for this reorder was not found.');
        }

        $duplicateStatement = $conn->prepare("SELECT COUNT(*) AS active_count FROM inventory_reorders
            WHERE product_id = ? AND status IN ('Pending', 'Ordered')");
        if (!$duplicateStatement) {
            throw new RuntimeException('Unable to prepare duplicate count: ' . $conn->error);
        }
        $duplicateStatement->bind_param('i', $productId);
        if (!$duplicateStatement->execute()) {
            throw new RuntimeException('Unable to count active reorders: ' . $duplicateStatement->error);
        }
        $activeCount = (int)$duplicateStatement->get_result()->fetch_assoc()['active_count'];
        $duplicateStatement->close();
        if ($activeCount < 2) {
            throw new DomainException('This reorder is no longer a duplicate.');
        }

        $cancelStatement = $conn->prepare("UPDATE inventory_reorders
            SET status = 'Cancelled' WHERE id = ? AND status IN ('Pending', 'Ordered') AND received_quantity = 0");
        if (!$cancelStatement) {
            throw new RuntimeException('Unable to prepare duplicate cancellation: ' . $conn->error);
        }
        $cancelStatement->bind_param('i', $reorderId);
        if (!$cancelStatement->execute() || $cancelStatement->affected_rows !== 1) {
            throw new RuntimeException('Unable to cancel duplicate reorder: ' . $cancelStatement->error);
        }
        $cancelStatement->close();

        if (!$conn->commit()) {
            throw new RuntimeException('Unable to commit duplicate cancellation: ' . $conn->error);
        }
        return ['success' => true];
    } catch (DomainException $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        return ['success' => false, 'error' => $exception->getMessage()];
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        error_log('Duplicate inventory reorder cancellation failed: ' . $exception->getMessage());
        return ['success' => false, 'error' => 'Unable to cancel the duplicate reorder. No changes were saved.'];
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid inventory alert request.');
    }
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_reorder') {
            $product_id = (int)$_POST['product_id'];
            $supplier_id = (int)$_POST['supplier_id'];
            $reorder_qty = (int)$_POST['reorder_qty'];
            $expected_delivery = trim($_POST['expected_delivery'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            $reorderResult = createReorderRequest($conn, $product_id, $supplier_id, $reorder_qty, $_SESSION['user_id'], $expected_delivery, $notes);
            if ($reorderResult['success']) {
                $message = "✅ Reorder request created. The supplier email was sent when SMTP is configured.";
            } else {
                $error = $reorderResult['error'] ?? 'Unable to create reorder request.';
            }
        } elseif ($_POST['action'] === 'create_bulk_reorder') {
            $supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
            $expectedDelivery = trim((string)($_POST['expected_delivery'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $submittedProductIds = $_POST['product_ids'] ?? [];
            $submittedQuantities = $_POST['reorder_quantities'] ?? [];
            $items = [];
            if (!$supplierId || $supplierId < 1) {
                $error = 'Choose an active supplier for the bulk order.';
            } elseif (!is_array($submittedProductIds) || count($submittedProductIds) < 2) {
                $error = 'Select at least two products for a bulk order.';
            } elseif (!is_array($submittedQuantities)) {
                $error = 'Enter a reorder quantity for each selected product.';
            } else {
                $manilaTimezone = new DateTimeZone('Asia/Manila');
                $expectedDate = DateTime::createFromFormat('!Y-m-d\TH:i', $expectedDelivery, $manilaTimezone);
                $expectedDateErrors = DateTime::getLastErrors();
                if (!$expectedDate
                    || ($expectedDateErrors !== false && ($expectedDateErrors['warning_count'] > 0 || $expectedDateErrors['error_count'] > 0))
                    || $expectedDate->format('Y-m-d\TH:i') !== $expectedDelivery) {
                    $error = 'Enter a valid expected delivery date and time.';
                } elseif ($expectedDate <= new DateTime('now', $manilaTimezone)) {
                    $error = 'Expected delivery date and time must be later than the current Manila time.';
                }
            }
            if ($error === '') {
                foreach ($submittedProductIds as $submittedProductId) {
                    $productId = filter_var($submittedProductId, FILTER_VALIDATE_INT);
                    $quantity = $productId ? filter_var($submittedQuantities[$productId] ?? null, FILTER_VALIDATE_INT) : false;
                    if (!$productId || $productId < 1 || !$quantity || $quantity < 1 || isset($items[$productId])) {
                        $error = 'Each selected product must have a valid reorder quantity.';
                        break;
                    }
                    $items[(int)$productId] = (int)$quantity;
                }
                if ($error === '') {
                    $bulkResult = createBulkReorderRequests(
                        $conn,
                        $items,
                        (int)$supplierId,
                        (int)$_SESSION['user_id'],
                        $expectedDelivery,
                        $notes
                    );
                    if ($bulkResult['success']) {
                        $message = 'Bulk order ' . htmlspecialchars($bulkResult['group_id'], ENT_QUOTES, 'UTF-8')
                            . ' created for ' . (int)$bulkResult['item_count'] . ' products. '
                            . ($bulkResult['notification_sent']
                                ? 'A combined supplier notification was sent.'
                                : 'No supplier email or SMS was sent; contact the supplier directly.');
                    } else {
                        $error = $bulkResult['error'];
                    }
                }
            }
        } elseif ($_POST['action'] === 'receive_reorder') {
            $reorderId = filter_var($_POST['reorder_id'] ?? null, FILTER_VALIDATE_INT);
            $receivedQuantity = filter_var($_POST['received_quantity'] ?? null, FILTER_VALIDATE_INT);
            $lotNumber = trim((string)($_POST['lot_number'] ?? ''));
            $manufacturingDate = trim((string)($_POST['manufacturing_date'] ?? ''));
            $expirationDate = trim((string)($_POST['expiration_date'] ?? ''));
            $manufacturingDateValue = null;
            $expiration = DateTime::createFromFormat('!Y-m-d', $expirationDate);
            $manufacturing = $manufacturingDate !== '' ? DateTime::createFromFormat('!Y-m-d', $manufacturingDate) : null;

            if (!$reorderId || !$receivedQuantity || $receivedQuantity < 1 || $lotNumber === ''
                || !$expiration || $expiration->format('Y-m-d') !== $expirationDate || $expirationDate < date('Y-m-d')
                || ($manufacturingDate !== '' && (!$manufacturing || $manufacturing->format('Y-m-d') !== $manufacturingDate
                    || $manufacturingDate > date('Y-m-d') || $manufacturingDate > $expirationDate))) {
                $error = 'Enter a valid reorder, received quantity, lot number, and non-expired expiration date.';
            } else {
                $manufacturingDateValue = $manufacturingDate !== '' ? $manufacturingDate : null;
                $receiveResult = receiveInventoryReorder(
                    $conn,
                    (int)$reorderId,
                    (int)$receivedQuantity,
                    $lotNumber,
                    $manufacturingDateValue,
                    $expirationDate,
                    (int)$_SESSION['user_id']
                );
                if ($receiveResult['success']) {
                    $message = $receiveResult['message'];
                } else {
                    $error = $receiveResult['error'];
                }
            }
        } elseif ($_POST['action'] === 'cancel_duplicate_reorder') {
            $reorderId = filter_var($_POST['reorder_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$reorderId || $reorderId < 1) {
                $error = 'Select a valid duplicate reorder to cancel.';
            } else {
                $cancelResult = cancelDuplicateInventoryReorder($conn, (int)$reorderId);
                if ($cancelResult['success']) {
                    $message = 'Duplicate reorder cancelled. Any stock already received was left unchanged.';
                } else {
                    $error = $cancelResult['error'];
                }
            }
        } else {
            http_response_code(400);
            exit('Invalid inventory alert action.');
        }
    }
}

$low_stock = getLowStockProducts($conn);
$lowStockProducts = $low_stock ? $low_stock->fetch_all(MYSQLI_ASSOC) : [];
$suppliers = getAllSuppliers($conn, true);
$supplierOptions = $suppliers ? $suppliers->fetch_all(MYSQLI_ASSOC) : [];
$reorders = $reorderTableAvailable ? $conn->query("SELECT ir.id, ir.product_id, ir.current_stock, ir.reorder_quantity,
        COALESCE(ir.received_quantity, 0) AS received_quantity, ir.supplier_id, ir.expected_delivery,
        ir.notes, ir.status, ir.created_at, ir.order_group_id, p.product_name, s.name AS supplier_name
    FROM inventory_reorders ir
    JOIN products p ON p.id = ir.product_id
    LEFT JOIN suppliers s ON s.id = ir.supplier_id
    ORDER BY CASE WHEN ir.status IN ('Pending', 'Ordered') THEN 0 WHEN ir.status = 'Received' THEN 1 ELSE 2 END,
        COALESCE(ir.expected_delivery, DATE(ir.created_at)) ASC, ir.created_at DESC") : null;
if ($reorderTableAvailable && $reorders === false) {
    error_log('Inventory reorders query failed: ' . $conn->error);
    $error = 'Unable to load inventory reorder requests.';
}
$activeReorderProducts = [];
$activeReorderCounts = [];
if ($reorderTableAvailable) {
    $activeReorderProductsResult = $conn->query("SELECT product_id, COUNT(*) AS active_count FROM inventory_reorders
        WHERE status IN ('Pending', 'Ordered') GROUP BY product_id");
    if ($activeReorderProductsResult === false) {
        error_log('Active inventory reorder lookup failed: ' . $conn->error);
        $error = 'Unable to check for existing reorder requests.';
    } else {
        while ($activeReorder = $activeReorderProductsResult->fetch_assoc()) {
            $productId = (int)$activeReorder['product_id'];
            $activeReorderProducts[$productId] = true;
            $activeReorderCounts[$productId] = (int)$activeReorder['active_count'];
        }
    }
}
$availableLowStockProducts = array_values(array_filter(
    $lowStockProducts,
    static function ($product) use ($activeReorderProducts): bool {
        return !isset($activeReorderProducts[(int)$product['id']]);
    }
));
$inventoryTab = ($_GET['tab'] ?? '') === 'reorders' ? 'reorders' : 'alerts';
?>

<!DOCTYPE html>
<html>
<head>
<title>Inventory Alerts</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f7f8fa; color: #20232a; }
    
    .container { max-width: none; margin: 0 0 0 260px; min-height: 100vh; padding: 32px 36px; }
    .back-button { display: none; }
    
    .header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:22px; }
    .header h1 { color: #20232a; font-size: 24px; font-weight: 700; margin-bottom: 4px; }
    .header-description { color:#7b8189; font-size:11px; margin-top:6px; }
    .reports-tabs { display:flex; flex-wrap:nowrap; gap:6px; width:max-content; max-width:100%; overflow-x:auto; scrollbar-width:none; margin:0 0 16px; padding:5px; background:#fff; border:1px solid #ebedf0; border-radius:9px; box-shadow:0 2px 8px rgba(25,30,38,.04); }
    .reports-tabs::-webkit-scrollbar { display:none; }
    .reports-tab { display:inline-flex; flex:0 0 auto; align-items:center; justify-content:center; min-height:34px; padding:6px 13px; border-radius:6px; color:#68707a; font-size:11px; font-weight:700; line-height:1.25; text-align:center; text-decoration:none; white-space:nowrap; }
    .reports-tab:hover { background:#fff1f3; color:#ed1740; }
    .reports-tab.active { border-radius:0; border-bottom:3px solid #ed1740; background:transparent; color:#a51c38; }
    .inventory-tab-panel[hidden] { display:none !important; }

    .page-tabs,
    .reports-tabs {
        display: flex;
        flex-wrap: nowrap;
        align-items: center;
        gap: 6px;
        width: max-content;
        max-width: 100%;
        overflow-x: auto;
        scrollbar-width: none;
        margin: 0 0 16px;
        padding: 5px;
        background: #fff;
        border: 1px solid #ebedf0;
        border-radius: 9px;
        box-shadow: 0 2px 8px rgba(25,30,38,.04);
    }

    .page-tabs::-webkit-scrollbar,
    .reports-tabs::-webkit-scrollbar { display: none; }

    .page-tabs .page-tab,
    .reports-tabs .reports-tab {
        display: inline-flex;
        flex: 0 0 auto;
        min-height: 34px;
        align-items: center;
        justify-content: center;
        padding: 6px 13px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.25;
        text-align: center;
        text-decoration: none;
        white-space: nowrap;
    }
    .reports-tabs > a.reports-tab.active,
    .reports-tabs > a.reports-tab.active:hover,
    .reports-tabs > a.reports-tab.active:focus {
        border:0 !important;
        border-bottom:3px solid #ed1740 !important;
        border-radius:0 !important;
        background:#fff !important;
        color:#a51c38 !important;
        box-shadow:none !important;
    }
    
    .message {
        border-radius: 8px;
        padding: 12px 14px;
        margin-bottom: 16px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .message.success { background: #e8f7ef; color: #15955c; border: 1px solid #b8e7d7; }
    .message.error { background: #ffe8ed; color: #c91437; border: 1px solid #ffc5d5; }
    
    .grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 16px;
        margin-bottom: 16px;
    }
    
    .card {
        background: #fff;
        border: 1px solid #ebedf0;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(25,30,38,.04);
        padding: 18px;
    }
    .low-stock-products-card {
        padding: 18px;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
    }
    
    .card h3 {
        color: #20232a;
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 14px;
    }
    .low-stock-card-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
    .low-stock-card-heading h3 { margin:0; }
    .low-stock-card-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 0 14px; padding-bottom:12px; border-bottom:1px solid #edf0f2; }
    .low-stock-select-button { width:auto; padding:8px 12px; border:1px solid #d7dce2; background:#fff; color:#344054; font-size:11px; }
    .bulk-order-open { width:auto; padding:8px 12px; font-size:11px; white-space:nowrap; }
    .bulk-order-count { margin-left:6px; opacity:.8; }
    .low-stock-item-row { display:flex; align-items:center; gap:9px; margin-bottom:12px; }
    .low-stock-item-row > .bulk-item-select { flex:0 0 18px; display:flex; align-items:center; justify-content:center; margin:0; cursor:pointer; }
    .low-stock-item-row > .bulk-item-select[hidden] { display:none !important; }
    .low-stock-item-row > .bulk-item-select { position:relative; z-index:2; min-height:32px; }
    .low-stock-products-card.bulk-selection-enabled .reorder-single-button { display:none; }
    .low-stock-item-row > .bulk-item-select input[type="checkbox"] {
        appearance: auto;
        flex: 0 0 16px;
        width: 16px;
        height: 16px;
        margin: 0;
        padding: 0;
        border: 1px solid #98a2b3;
        border-radius: 3px;
        background: #fff;
        accent-color: #ed1740;
        cursor: pointer;
    }
    .low-stock-item-row > .bulk-item-select input[type="checkbox"]:focus-visible {
        outline: 3px solid rgba(237, 23, 64, .28);
        outline-offset: 2px;
    }
    .low-stock-item-row > .alert-card { flex:1 1 auto; min-width:0; margin:0; }
    
    .alert-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 14px;
        border-radius: 8px;
        margin-bottom: 12px;
        border: 1px solid #f3cf78;
        background: #fff8e7;
        transition: all 0.2s ease;
    }
    
    .alert-card:hover { box-shadow: 0 2px 8px rgba(233,163,22,.15); }
    .alert-card.out-of-stock { border-color:#efb5bf; background:#fff0f2; }
    .alert-card.out-of-stock strong,
    .alert-card.out-of-stock span { color:#b4233b; }
    .product-identifier { margin-top:4px; color:#69727c; font-size:11px; }
    .stock-state { display:inline-flex; margin-right:6px; padding:3px 7px; border-radius:999px; background:#fde2e7; color:#b4233b; font-size:10px; font-weight:700; }
    
    .alert-card strong {
        color: #8b5d00;
        font-size: 13px;
        font-weight: 700;
    }
    
    .alert-card span { color: #8b5d00; font-size: 12px; }
    .alert-card-main { display:flex; align-items:flex-start; gap:10px; flex:1 1 auto; min-width:0; }
    .alert-card-main > div { flex:1 1 auto; min-width:0; }
    .alert-card-stock { display:flex; flex-wrap:wrap; gap:8px 20px; margin-top:7px; color:#303740; font-size:11px; }
    .alert-card-stock .stock-minimum { margin-left:auto; color:#626a74; }
    .reorder-single-button.selected-for-bulk { display:none; }
    .alert-card-actions { display:flex; flex:0 0 auto; align-items:flex-start; }
    .reorder-single-button.selected-for-bulk { display:none; }
    .bulk-modal-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding-bottom:12px; margin-bottom:14px; border-bottom:1px solid #e8eaed; }
    .bulk-modal-heading h2 { margin:0 0 4px; }
    .bulk-modal-heading p { color:#727a84; font-size:11px; }
    .bulk-modal-count { flex:0 0 auto; padding:4px 8px; border-radius:999px; background:#f0f1f3; color:#424a53; font-size:10px; font-weight:700; }
    .bulk-order-layout { display:grid; grid-template-columns:minmax(0, 1fr); gap:0; align-items:start; }
    .bulk-order-products, .bulk-order-details { min-width:0; }
    .bulk-order-table-wrap { overflow-x:auto; margin:4px 0 12px; border:1px solid #e5e7eb; border-radius:7px; }
    .bulk-order-table { width:100%; border-collapse:collapse; font-size:11px; }
    .bulk-order-table th { padding:9px 10px; background:#f4f5f6; color:#555e68; font-size:9px; text-align:left; text-transform:uppercase; }
    .bulk-order-table th:nth-child(2), .bulk-order-table td:nth-child(2) { text-align:right; }
    .bulk-order-table td { padding:9px 10px; border-top:1px solid #edf0f2; vertical-align:middle; }
    .bulk-order-table td:first-child strong { display:block; color:#303740; font-size:11px; }
    .bulk-order-table td:first-child small { display:block; margin-top:2px; color:#828993; font-size:9px; }
    .bulk-order-table .bulk-order-stock { color:#d92d45; font-weight:700; }
    .bulk-order-table .bulk-order-quantity { width:86px; padding:7px 8px; text-align:center; }
    .bulk-order-summary { display:grid; gap:7px; margin-bottom:14px; padding:11px 12px; border-radius:7px; background:#f6f7f8; color:#515963; font-size:10px; }
    .bulk-order-summary div { display:flex; justify-content:space-between; gap:12px; }
    .bulk-order-footer { display:flex; justify-content:flex-end; gap:8px; padding-top:12px; border-top:1px solid #e8eaed; }
    .bulk-order-footer button { width:auto; min-width:130px; }
    .bulk-order-cancel { border:1px solid #d6d9dd; background:#fff; color:#515963; }
    .bulk-order-cancel:hover { background:#f5f6f7; }
    @media (max-width: 700px) {
        .low-stock-card-heading { align-items:flex-start; flex-direction:column; }
        .low-stock-item-row { gap:6px; }
        .alert-card { align-items:flex-start; }
        .alert-card-actions { align-items:flex-start; }
    }
    @media (max-width: 540px) {
        .alert-card { gap:8px; padding:11px; }
        .alert-card-stock .stock-minimum { margin-left:0; }
        .bulk-order-footer { flex-direction:column-reverse; }
        .bulk-order-footer button { width:100%; }
    }
    
    .stock-critical { color: #db294b; font-weight: 700; }
    .stock-warning { color: #e9a316; font-weight: 700; }
    .stock-ok { color: #15955c; font-weight: 700; }
    
    .progress-bar {
        width: 100%;
        height: 6px;
        background: #e5e7eb;
        border-radius: 3px;
        overflow: hidden;
        margin: 9px 0 0;
    }
    
    .progress-fill {
        height: 100%;
        background: #e9a316;
        transition: width 0.3s ease;
    }
    
    .form-group {
        margin-bottom: 14px;
    }
    
    label {
        display: block;
        margin-bottom: 6px;
        color: #4e5660;
        font-weight: 600;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    input, select, textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #fff;
        color: #20232a;
        font-family: inherit;
        font-size: 12px;
        transition: all 0.2s ease;
    }
    
    input:focus, select:focus, textarea:focus {
        outline: none;
        border-color: #e32248;
        box-shadow: 0 0 0 3px rgba(227,34,72,.1);
    }
    
    textarea { resize: vertical; min-height: 80px; }
    
    button {
        padding: 10px 18px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 700;
        transition: all 0.2s ease;
    }
    
    .btn-primary {
        background: #ed1740;
        color: #fff;
        width: 100%;
    }
    
    .btn-primary:hover { background: #c91437; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(237,23,64,.25); }
    
    .btn-danger {
        background: #ed1740;
        color: #fff;
        padding: 8px 14px;
        font-size: 11px;
    }
    
    .btn-danger:hover { background: #c91437; }
    .btn-danger:disabled { background: #9aa2ac; cursor: not-allowed; }

    .btn-secondary {
        width: auto;
        padding: 8px 12px;
        border: 1px solid #d7dce2;
        background: #fff;
        color: #344054;
        white-space: nowrap;
    }

    .receive-stock-trigger[hidden] { display: none; }
    .reorder-action-note { color: #69727c; font-size: 10px; line-height: 1.4; text-align: center; }
    .btn-secondary:hover { border-color: #ed1740; color: #c91437; }
    .reorder-view-link { display:inline-flex; align-items:center; justify-content:center; min-height:34px; text-decoration:none; }
    .receive-product { margin: -8px 0 16px; color: #69727c; font-size: 12px; }
    
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.2s ease;
    }
    
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    
    .modal-content {
        background-color: #fff;
        padding: 24px;
        border-radius: 10px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        width: 100%;
        max-width: 450px;
        max-height: 85vh;
        overflow-y: auto;
        animation: slideUp 0.3s ease;
    }

    .modal-content.bulk-modal-content {
        width: calc(100% - 40px);
        max-width: 620px;
        max-height: calc(100vh - 32px);
        padding: 24px 28px;
    }

    @media (max-width: 540px) {
        .modal-content.bulk-modal-content {
            width: calc(100% - 20px);
            max-height: calc(100vh - 20px);
            padding: 16px;
        }
    }
    
    @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    
    .modal-content h2 {
        color: #20232a;
        font-size: 18px;
        margin-bottom: 18px;
        font-weight: 700;
    }
    
    .close {
        color: #aaa;
        float: right;
        font-size: 24px;
        font-weight: bold;
        cursor: pointer;
        line-height: 20px;
        transition: color 0.2s ease;
    }
    
    .close:hover { color: #20232a; }
    
    .supplier-contact {
        margin-top: 8px;
        padding: 10px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #f8fafc;
        color: #68707a;
        font-size: 11px;
        line-height: 1.6;
    }
    .supplier-contact-line + .supplier-contact-line { margin-top: 3px; }
    .supplier-contact-line strong { color: #303740; }
    
    .reorder-table-wrap {
        width: 100%;
        min-width: 0;
    }

    .reorder-table {
        width: 100%;
        min-width: 0;
        table-layout: fixed;
        border-collapse: collapse;
        margin-top: 12px;
    }
    
    .reorder-table th {
        padding: 10px 6px;
        text-align: left;
        color: #495057;
        font-size: 10px;
        font-weight: 800;
        line-height: 1.3;
        text-transform: uppercase;
        border-bottom: 1px solid #dfe3e7;
        background: #e9ecef;
        white-space: normal;
        overflow-wrap: anywhere;
    }
    
    .reorder-table td {
        padding: 10px 6px;
        font-size: 11px;
        line-height: 1.45;
        vertical-align: middle;
        border-bottom: 1px solid #f0f1f3;
    }
    
    .reorder-table th:not(:first-child),
    .reorder-table td:not(:first-child) { text-align: center; }
    .reorder-table td:first-child { overflow-wrap: anywhere; }
    .reorder-table td:first-child strong { display: block; margin-bottom: 3px; }
    .reorder-table td small { color: #7b8189; font-size: 10px; }
    .reorder-status { display:inline-flex; align-items:center; justify-content:center; padding:4px 10px; border-radius:999px; font-size:10px; font-weight:700; line-height:1.2; white-space:nowrap; }
    .reorder-status.pending { background:#eef0f3; color:#59616b; }
    .reorder-status.ordered { background:#eaf1ff; color:#315d9b; }
    .reorder-status.partial { background:#fff2cc; color:#9a6700; }
    .reorder-status.completed { background:#dcf5e8; color:#16834d; }
    .reorder-status.cancelled { background:#ffe8ed; color:#b4233f; }
    .reorder-toolbar { display:flex; align-items:center; gap:14px; min-height:36px; margin:0 0 12px; }
    .reorder-toolbar h3 { display:flex; flex:0 0 auto; align-items:center; height:36px; margin:0; line-height:1; white-space:nowrap; }
    .reorder-controls { display:flex; flex:1 1 auto; min-width:0; align-items:center; gap:8px; }
    .reorder-toolbar input, .reorder-toolbar select { box-sizing:border-box; height:40px; min-height:40px; border:1px solid #dfe3e8; border-radius:8px; background:#fff; color:#303740; font:inherit; font-size:13px; }
    .reorder-toolbar input:focus, .reorder-toolbar select:focus { border-color:#aeb6c0; box-shadow:0 0 0 2px rgba(100,116,139,.1); }
    .reorder-toolbar input::placeholder { color:#6b7280; opacity:1; }
    .reorder-search-field { position:relative; flex:1 1 auto; min-width:0; }
    .reorder-search-field svg { position:absolute; z-index:1; top:50%; left:11px; width:16px; height:16px; transform:translateY(-50%); color:#6b7280; pointer-events:none; }
    .reorder-toolbar .reorder-search-field input { display:block; width:100%; min-width:0; padding:7px 12px 7px 36px; color:#303740; -webkit-text-fill-color:#303740; line-height:20px; opacity:1; }
    .reorder-controls select { flex:0 0 180px; width:180px; padding:0 30px 0 12px; color:#303740; }
    .reorder-table tr[hidden] { display:none; }
    .reorder-pagination { display:flex; align-items:center; justify-content:flex-end; gap:6px; margin-top:12px; }
    .reorder-pagination[hidden] { display:none; }
    .reorder-page-button { min-width:32px; height:32px; padding:0 9px; border:1px solid #dfe4e9; border-radius:6px; background:#fff; color:#59616b; font-size:12px; font-weight:600; cursor:pointer; }
    .reorder-page-button:hover:not(:disabled), .reorder-page-button[aria-current="page"] { border-color:#ed1740; background:#fff1f3; color:#a51c38; }
    .reorder-page-button:disabled { opacity:.45; cursor:not-allowed; }
    .reorder-page-summary { margin-right:auto; color:#737b85; font-size:11px; }
    @media (max-width:720px) {
        .reorder-toolbar { align-items:center; flex-wrap:wrap; gap:10px; }
        .reorder-controls { flex:1 1 100%; }
        .reorder-controls select { flex:0 0 150px; width:150px; }
    }
    @media (max-width:420px) { .reorder-controls { gap:6px; } .reorder-controls select { flex-basis:135px; width:135px; } }
    .completed-details-button { display:inline-flex; width:34px; height:34px; align-items:center; justify-content:center; padding:0; border:1px solid #d9e7df; border-radius:50%; background:#f0faf4; color:#16834d; cursor:pointer; }
    .completed-details-button:hover { border-color:#16834d; background:#dcf5e8; }
    .completed-details-button svg { width:17px; height:17px; }
    .completed-details-close { padding:0; border:0; background:transparent; }
    .completed-details-list { display:grid; gap:10px; margin-top:16px; }
    .completed-details-row { display:grid; grid-template-columns:minmax(120px, .7fr) minmax(0, 1.3fr); gap:12px; padding-bottom:9px; border-bottom:1px solid #edf0f2; font-size:12px; }
    .completed-details-row span { color:#737b85; }
    .completed-details-row strong { color:#303740; overflow-wrap:anywhere; }
    .reorder-table .btn-secondary { min-width: 0; max-width: 100%; min-height: 40px; padding: 8px 6px; white-space: normal; }
    .reorder-table .reorder-actions { display: flex; flex-direction: column; align-items: center; gap: 6px; }
    .reorder-table .cancel-duplicate-button { min-width: 0; max-width: 100%; min-height: 34px; padding: 6px; font-size: 10px; white-space: normal; }
    .reorder-table tbody tr:hover { background: #f9fafc; }
    
    @media (max-width: 900px) {
        .container { margin-left: 0; padding: 20px; }
        .header { align-items:flex-start; flex-wrap:wrap; }
        .grid { grid-template-columns: 1fr; }
        .card { padding: 16px; }
        .alert-card { flex-direction: column; align-items: flex-start; gap: 10px; }
        .btn-danger { width: 100%; margin-top: 10px; }
    }
    .header h1 { color:#252a30; font-family:'Segoe UI', Tahoma, sans-serif; font-size:30px; font-weight:800; }

    body.inventory-alerts-page > .container {
        box-sizing: border-box;
        margin: 0 60px 0 320px !important;
        width: calc(100% - 380px) !important;
        padding: 104px 0 32px !important;
        min-width: 0;
    }

    body.inventory-alerts-page.owner-sidebar-collapsed > .container {
        margin-left: 144px !important;
        margin-right: 60px !important;
        width: calc(100% - 204px) !important;
    }

    @media (max-width: 1199px) and (min-width: 901px) {
        body.inventory-alerts-page > .container {
            margin-left: 276px !important;
            margin-right: 24px !important;
            width: calc(100% - 300px) !important;
        }

        body.inventory-alerts-page.owner-sidebar-collapsed > .container {
            margin-left: 100px !important;
            margin-right: 24px !important;
            width: calc(100% - 124px) !important;
        }
    }

    @media (max-width: 900px) {
        body.inventory-alerts-page > .container,
        body.inventory-alerts-page.owner-sidebar-collapsed > .container {
            margin: 0 12px 0 84px !important;
            width: calc(100% - 96px) !important;
            padding: 72px 12px 24px !important;
        }

        .page-tabs,
        .reports-tabs { width: 100%; }
    }

    @media (max-width: 420px) {
        body.inventory-alerts-page > .container,
        body.inventory-alerts-page.owner-sidebar-collapsed > .container {
            margin: 0 10px 0 72px !important;
            width: calc(100% - 82px) !important;
            padding-left: 10px !important;
            padding-right: 10px !important;
        }
    }
</style>
</head>
<body class="inventory-alerts-page">

<?php include 'appbar_owner.php'; include 'sidebar_owner.php'; ?>

<div class="container">
    <a href="dashboard.php" class="back-button">← Back to Dashboard</a>
    
    <nav class="reports-tabs" aria-label="Reports navigation">
        <?php if (!$isPharmacist): ?><a class="reports-tab" href="analytics.php?page=overview">📈 Overview</a><?php endif; ?>
        <a class="reports-tab" href="staff_sales_report.php">💊 Pharma Sales</a>
        <a class="reports-tab <?php echo $inventoryTab === 'alerts' ? 'active' : ''; ?>" href="inventory_alerts.php?tab=alerts" <?php echo $inventoryTab === 'alerts' ? 'aria-current="page"' : ''; ?>>⚠ Inventory Alerts</a>
        <a class="reports-tab <?php echo $inventoryTab === 'reorders' ? 'active' : ''; ?>" href="inventory_alerts.php?tab=reorders" <?php echo $inventoryTab === 'reorders' ? 'aria-current="page"' : ''; ?>>📦 Reorders</a>
    </nav>

    <?php if ($message): ?>
        <div class="message success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="message error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="card reorder-section-card inventory-tab-panel" id="reorderSection" <?php echo $inventoryTab === 'reorders' ? '' : 'hidden'; ?>>
        <div class="reorder-toolbar">
            <h3>📦 Reorders</h3>
            <div class="reorder-controls">
                <div class="reorder-search-field">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                    <input type="search" id="reorderSearch" placeholder="Search product, supplier, reorder ID..." aria-label="Search reorders">
                </div>
                <select id="reorderStatusFilter" aria-label="Filter reorders by status">
                    <option value="all">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="partial">Partial</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
        </div>
        <div class="reorder-table-wrap">
            <table class="reorder-table" id="reorderTable">
                <colgroup>
                    <col style="width:26%">
                    <col style="width:15%">
                    <col style="width:9%">
                    <col style="width:13%">
                    <col style="width:14%">
                    <col style="width:10%">
                    <col style="width:13%">
                </colgroup>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Supplier</th>
                        <th>Ordered</th>
                        <th>Received</th>
                        <th>Expected Delivery Date &amp; Time</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reorders && $reorders->num_rows > 0): ?>
                        <?php while ($reorder = $reorders->fetch_assoc()):
                            $orderedQuantity = (int)($reorder['reorder_quantity'] ?? 0);
                            $receivedQuantity = (int)($reorder['received_quantity'] ?? 0);
                            $remainingQuantity = max(0, $orderedQuantity - $receivedQuantity);
                            $storedStatus = strtolower(trim((string)$reorder['status']));
                            $isPartial = $receivedQuantity > 0 && $receivedQuantity < $orderedQuantity
                                && in_array($storedStatus, ['pending', 'ordered'], true);
                            $statusLabel = $isPartial ? 'Partial' : ($storedStatus === 'received' ? 'Completed' : ucfirst($storedStatus));
                            $statusFilterValue = $isPartial ? 'partial' : ($storedStatus === 'received' ? 'completed' : (in_array($storedStatus, ['pending', 'ordered'], true) ? 'pending' : $storedStatus));
                            $statusClass = $isPartial ? 'partial' : ($storedStatus === 'received' ? 'completed' : ($storedStatus === 'cancelled' ? 'cancelled' : ($storedStatus === 'ordered' ? 'ordered' : 'pending')));
                        ?>
                            <tr data-reorder-status="<?php echo htmlspecialchars($statusFilterValue, ENT_QUOTES, 'UTF-8'); ?>">
                                <td><strong><?php echo htmlspecialchars($reorder['product_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br><small>Reorder #<?php echo (int)$reorder['id']; ?><?php if (!empty($reorder['order_group_id'])): ?> · <?php echo htmlspecialchars($reorder['order_group_id'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></small></td>
                                <td><?php echo htmlspecialchars($reorder['supplier_name'] ?: 'Supplier unavailable', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format($orderedQuantity); ?> units</td>
                                <td><?php echo number_format($receivedQuantity); ?> received<br><small><?php echo number_format($remainingQuantity); ?> remaining</small></td>
                                <?php
                                    $expectedDeliveryAt = !empty($reorder['expected_delivery'])
                                        ? new DateTimeImmutable((string)$reorder['expected_delivery'], new DateTimeZone('Asia/Manila'))
                                        : null;
                                    $expectedDeliveryReady = $expectedDeliveryAt !== null
                                        && $expectedDeliveryAt <= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
                                ?>
                                <td><?php echo $expectedDeliveryAt ? $expectedDeliveryAt->format('M d, Y h:i A') : '—'; ?></td>
                                <td><span class="reorder-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td>
                                    <div class="reorder-actions">
                                        <?php if (in_array($storedStatus, ['pending', 'ordered'], true)): ?>
                                            <?php if ($expectedDeliveryAt): ?>
                                            <button type="button" class="btn-secondary receive-stock-trigger"
                                                data-reorder-id="<?php echo (int)$reorder['id']; ?>"
                                                data-product-name="<?php echo htmlspecialchars($reorder['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-remaining="<?php echo $remainingQuantity; ?>"
                                                <?php if (!$expectedDeliveryReady): ?>data-available-at="<?php echo $expectedDeliveryAt->getTimestamp() * 1000; ?>" hidden<?php endif; ?>>Receive Stock</button>
                                            <?php else: ?>
                                                <span class="reorder-action-note">Expected delivery time not set</span>
                                            <?php endif; ?>
                                            <?php if (($activeReorderCounts[(int)$reorder['product_id']] ?? 0) > 1 && $receivedQuantity === 0): ?>
                                                <form method="POST" onsubmit="return confirm('Cancel this duplicate reorder? No stock will be changed.');">
                                                    <input type="hidden" name="action" value="cancel_duplicate_reorder">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="reorder_id" value="<?php echo (int)$reorder['id']; ?>">
                                                    <button type="submit" class="btn-danger cancel-duplicate-button">Cancel Duplicate</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php elseif ($storedStatus === 'received'): ?>
                                            <button type="button" class="completed-details-button"
                                                data-reorder-id="<?php echo (int)$reorder['id']; ?>"
                                                data-order-group="<?php echo htmlspecialchars((string)($reorder['order_group_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-product="<?php echo htmlspecialchars($reorder['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-supplier="<?php echo htmlspecialchars($reorder['supplier_name'] ?: 'Supplier unavailable', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-ordered="<?php echo number_format($orderedQuantity); ?> units"
                                                data-received="<?php echo number_format($receivedQuantity); ?> units"
                                                data-expected="<?php echo $expectedDeliveryAt ? $expectedDeliveryAt->format('M d, Y h:i A') : '—'; ?>"
                                                data-created="<?php echo !empty($reorder['created_at']) ? date('M d, Y h:i A', strtotime($reorder['created_at'])) : '—'; ?>"
                                                data-notes="<?php echo htmlspecialchars((string)($reorder['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                aria-label="View completed reorder details for <?php echo htmlspecialchars($reorder['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                title="View details">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                                                    <circle cx="12" cy="12" r="3"></circle>
                                                </svg>
                                            </button>
                                        <?php else: ?>—<?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="reorder-empty-row"><td colspan="7" class="empty">No reorder requests found.</td></tr>
                    <?php endif; ?>
                    <tr id="reorderNoMatches" hidden><td colspan="7" class="empty">No reorders match your search and status.</td></tr>
                </tbody>
            </table>
        </div>
        <nav class="reorder-pagination" id="reorderPagination" aria-label="Reorder pages" hidden>
            <span class="reorder-page-summary" id="reorderPageSummary"></span>
            <button type="button" class="reorder-page-button" id="reorderPreviousPage">Previous</button>
            <div id="reorderPageButtons" style="display:flex;gap:6px"></div>
            <button type="button" class="reorder-page-button" id="reorderNextPage">Next</button>
        </nav>
    </div>

    <!-- LOW STOCK ALERTS -->
    <div class="grid inventory-tab-panel" id="lowStockProductsSection" <?php echo $inventoryTab === 'alerts' ? '' : 'hidden'; ?>>
        <div class="card low-stock-products-card">
            <div class="low-stock-card-heading">
                <h3>📉 Low Stock Products</h3>
            </div>
            <p style="margin:-6px 0 12px; color:#69727c; font-size:11px;">Low-stock thresholds are managed with each product. Out-of-stock items are listed first.</p>
            <div class="low-stock-card-toolbar">
                <button type="button" class="low-stock-select-button" id="toggleBulkSelection" aria-pressed="false">Select Products</button>
                <button type="button" class="btn-danger bulk-order-open" id="openBulkOrderButton">
                    Create Bulk Orders <span class="bulk-order-count" id="bulkOrderCount">(0)</span>
                </button>
            </div>
            <p class="bulk-order-status" id="bulkOrderStatus" role="status" aria-live="polite"><?php
                if (count($availableLowStockProducts) < 2) {
                    if (count($availableLowStockProducts) === 1) {
                        echo 'Bulk ordering requires at least two products without an active reorder. Use Reorder on the product to order it individually.';
                    } elseif ($lowStockProducts) {
                        echo 'Select Products to choose low-stock items, including those with active reorders. Creating another bulk order will add a new reorder request.';
                    } else {
                        echo 'No low-stock products are available for bulk ordering right now.';
                    }
                } else {
                    echo 'Select at least two products from the same supplier to create a bulk order.';
                }
            ?></p>
            <?php
            if ($lowStockProducts) {
                foreach ($lowStockProducts as $product) {
                    $alert_level = (int)($product['alert_threshold'] ?? 20);
                    $current_stock = (int)($product['current_stock'] ?? $product['pieces'] ?? 0);
                    $stock_class = $current_stock <= 10 ? 'stock-critical' : 'stock-warning';
                    $productName = (string)$product['product_name'];
                    $productSku = trim((string)($product['sku'] ?? ''));
                    $isOutOfStock = $current_stock <= 0;
                    $productId = (int)$product['id'];
                    $supplierId = (int)($product['supplier_id'] ?? 0);
                    $hasActiveReorder = isset($activeReorderProducts[$productId]);
                    
                    echo "<div class='low-stock-item-row'>";
                    echo "<label class='bulk-item-select' title='Select for bulk reorder' hidden><input type='checkbox' class='bulk-product-checkbox' form='bulkOrderForm' name='product_ids[]' value='" . $productId . "' data-supplier-id='" . $supplierId . "' data-supplier-name='" . htmlspecialchars((string)($product['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8') . "' data-product-name='" . htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') . "' data-stock='" . $current_stock . "' data-threshold='" . $alert_level . "' data-active-reorder='" . ($hasActiveReorder ? 'true' : 'false') . "'></label>";
                    echo "<div class='alert-card" . ($isOutOfStock ? ' out-of-stock' : '') . "'>";
                    echo "<div class='alert-card-main'><div>";
                    echo "<strong>" . htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') . "</strong>";
                    echo "<div class='alert-card-stock'>";
                    if ($isOutOfStock) {
                        echo "<span class='stock-state'>OUT OF STOCK</span>";
                    }
                    echo "<span>Stock: <span class='$stock_class'>{$current_stock} units</span></span>";
                    echo "<span class='stock-minimum'>Min: " . number_format($alert_level) . "</span>";
                    echo "</div></div></div>";
                    echo "<div class='alert-card-actions'>";
                    echo "<button type='button' class='btn-danger reorder-single-button' data-product-id='" . $productId . "' data-product-name='" . htmlspecialchars($productName . ($productSku !== '' ? ' (' . $productSku . ')' : ''), ENT_QUOTES, 'UTF-8') . "' onclick='openReorderModal(this.dataset.productId, this.dataset.productName)'>Reorder</button>";
                    echo "</div>";
                    echo "</div>";
                    echo "</div>";
                }
            } else {
                echo "<div style='text-align: center; padding: 28px 20px; color: #15955c;'><div style='font-size: 28px; margin-bottom: 10px;'>✅</div><strong style='display: block; font-size: 13px; margin-bottom: 5px;'>All products are well-stocked!</strong><span style='font-size: 11px; color: #7b8189;'>No inventory alerts at this time.</span></div>";
            }
            ?>
        </div>
        
    </div>

</div>

<div id="completedReorderDetailsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="completedDetailsTitle">
    <div class="modal-content">
        <button type="button" class="close" id="closeCompletedDetails" aria-label="Close">&times;</button>
        <h2 id="completedDetailsTitle">Completed Reorder Details</h2>
        <div class="completed-details-list">
            <div class="completed-details-row"><span>Product</span><strong id="completedDetailsProduct"></strong></div>
            <div class="completed-details-row"><span>Reorder</span><strong id="completedDetailsId"></strong></div>
            <div class="completed-details-row"><span>Order Group</span><strong id="completedDetailsGroup"></strong></div>
            <div class="completed-details-row"><span>Supplier</span><strong id="completedDetailsSupplier"></strong></div>
            <div class="completed-details-row"><span>Ordered Quantity</span><strong id="completedDetailsOrdered"></strong></div>
            <div class="completed-details-row"><span>Received Quantity</span><strong id="completedDetailsReceived"></strong></div>
            <div class="completed-details-row"><span>Expected Delivery Date &amp; Time</span><strong id="completedDetailsExpected"></strong></div>
            <div class="completed-details-row"><span>Created</span><strong id="completedDetailsCreated"></strong></div>
            <div class="completed-details-row"><span>Notes</span><strong id="completedDetailsNotes"></strong></div>
        </div>
    </div>
</div>

<div id="receiveModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeReceiveModal()">&times;</span>
        <h2>Receive Supplier Delivery</h2>
        <p class="receive-product" id="receiveProductName"></p>
        <form method="POST">
            <input type="hidden" name="action" value="receive_reorder">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" id="receiveReorderId" name="reorder_id" value="">
            <div class="form-group">
                <label for="receiveQuantity">Actual Quantity Received:</label>
                <input id="receiveQuantity" name="received_quantity" type="number" min="1" required>
                <small id="receiveQuantityHelp">Enter the quantity that physically arrived. You can receive the remaining quantity later.</small>
            </div>
            <div class="form-group">
                <label for="receiveLotNumber">Lot Number from package:</label>
                <input id="receiveLotNumber" name="lot_number" type="text" maxlength="100" required>
            </div>
            <div class="form-group">
                <label for="receiveManufacturingDate">Manufacturing Date (optional):</label>
                <input id="receiveManufacturingDate" name="manufacturing_date" type="date" max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
                <label for="receiveExpirationDate">Expiration Date:</label>
                <input id="receiveExpirationDate" name="expiration_date" type="date" min="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <button type="submit" class="btn-primary">Save Received Stock</button>
        </form>
    </div>
</div>

<!-- REORDER MODAL -->
<div id="bulkOrderModal" class="modal">
    <div class="modal-content bulk-modal-content">
        <span class="close" onclick="closeBulkOrderModal()" aria-label="Close">&times;</span>
        <div class="bulk-modal-heading">
            <div>
                <h2>Bulk Reorder</h2>
                <p>Review and prepare your stock replenishment request.</p>
            </div>
            <span class="bulk-modal-count" id="bulkOrderSupplier"></span>
        </div>
        <form method="POST" id="bulkOrderForm">
            <input type="hidden" name="action" value="create_bulk_reorder">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="bulk-order-layout">
                <div class="bulk-order-products">
                    <div class="bulk-order-table-wrap">
                        <table class="bulk-order-table">
                            <thead><tr><th>Selected Products</th><th>Stock</th><th>Order Qty</th></tr></thead>
                            <tbody id="bulkOrderProductRows"></tbody>
                        </table>
                    </div>
                    <div class="bulk-order-summary">
                        <div><span>Total products</span><strong id="bulkSummaryProducts">0</strong></div>
                        <div><span>Total units to order</span><strong id="bulkSummaryUnits">0 units</strong></div>
                    </div>
                </div>
                <div class="bulk-order-details">
                    <div class="form-group">
                        <label for="bulkSupplier">Supplier</label>
                        <select id="bulkSupplier" name="supplier_id" required>
                            <option value="">-- Select Supplier --</option>
                            <?php foreach ($supplierOptions as $supplier): ?>
                                <option value="<?php echo (int)$supplier['id']; ?>"><?php echo htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="bulkExpectedDelivery">Expected Delivery Date &amp; Time</label>
                        <input id="bulkExpectedDelivery" type="datetime-local" name="expected_delivery" min="<?php echo htmlspecialchars($minimumExpectedDelivery, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="bulkOrderNotes">Order Notes (optional)</label>
                        <textarea id="bulkOrderNotes" name="notes" rows="3" maxlength="2000" placeholder="Delivery instructions or other details"></textarea>
                    </div>
                </div>
            </div>
            <div class="bulk-order-footer">
                <button type="button" class="bulk-order-cancel" onclick="closeBulkOrderModal()">Cancel</button>
                <button type="submit" class="btn-primary">Create Bulk Order</button>
            </div>
        </form>
    </div>
</div>

<div id="reorderModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeReorderModal()">&times;</span>
        <h2>Create Reorder Request</h2>
        
        <form method="POST" id="reorderRequestForm">
            <input type="hidden" name="action" value="create_reorder">
            <input type="hidden" id="reorderProductId" name="product_id" value="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            
            <div class="form-group">
                <label>Product: <strong id="productName"></strong></label>
            </div>
            
            <div class="form-group">
                <label for="supplier">Select Supplier:</label>
                <select id="supplier" name="supplier_id" required onchange="showSupplierContact(this)">
                    <option value="">-- Select Supplier --</option>
                    <?php foreach ($supplierOptions as $supplier): ?>
                        <option value="<?php echo (int)$supplier['id']; ?>"
                            data-email="<?php echo htmlspecialchars($supplier['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-phone="<?php echo htmlspecialchars($supplier['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-contact="<?php echo htmlspecialchars($supplier['contact_person'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="supplierContact" class="supplier-contact">Supplier contact details will appear here.</div>
            </div>
            
            <div class="form-group">
                <label for="reorder_qty">Reorder Quantity:</label>
                <input type="number" id="reorder_qty" name="reorder_qty" min="1" value="50" required>
            </div>

            <div class="form-group">
                <label for="expected_delivery">Expected Delivery Date &amp; Time:</label>
                <input type="datetime-local" id="expected_delivery" name="expected_delivery" min="<?php echo htmlspecialchars($minimumExpectedDelivery, ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>

            <div class="form-group">
                <label for="reorder_notes">Order Notes:</label>
                <textarea id="reorder_notes" name="notes" rows="3" placeholder="Add delivery instructions or special requests..."></textarea>
            </div>
            
            <button type="submit" class="btn-primary" style="margin-top: 20px;">Create Reorder Request</button>
        </form>
    </div>
</div>

<script>
const reorderTable = document.getElementById('reorderTable');
const reorderSearch = document.getElementById('reorderSearch');
const reorderStatusFilter = document.getElementById('reorderStatusFilter');
const reorderNoMatches = document.getElementById('reorderNoMatches');
if (reorderTable && reorderSearch && reorderStatusFilter && reorderNoMatches) {
    const reorderPagination = document.getElementById('reorderPagination');
    const reorderPageSummary = document.getElementById('reorderPageSummary');
    const reorderPageButtons = document.getElementById('reorderPageButtons');
    const reorderPreviousPage = document.getElementById('reorderPreviousPage');
    const reorderNextPage = document.getElementById('reorderNextPage');
    const reorderRows = Array.from(reorderTable.querySelectorAll('tbody tr[data-reorder-status]'));
    const reorderPageSize = 10;
    let reorderCurrentPage = 1;
    const filterReorders = () => {
        const searchTerm = reorderSearch.value.trim().toLocaleLowerCase();
        const selectedStatus = reorderStatusFilter.value;
        const matchingRows = reorderRows.filter(row => {
            return row.textContent.toLocaleLowerCase().includes(searchTerm)
                && (selectedStatus === 'all' || row.dataset.reorderStatus === selectedStatus);
        });
        const totalPages = Math.max(1, Math.ceil(matchingRows.length / reorderPageSize));
        reorderCurrentPage = Math.min(reorderCurrentPage, totalPages);
        const firstVisibleIndex = (reorderCurrentPage - 1) * reorderPageSize;
        const visibleRows = new Set(matchingRows.slice(firstVisibleIndex, firstVisibleIndex + reorderPageSize));
        reorderRows.forEach(row => { row.hidden = !visibleRows.has(row); });
        reorderNoMatches.hidden = matchingRows.length > 0 || reorderRows.length === 0;
        reorderPagination.hidden = matchingRows.length <= reorderPageSize;
        reorderPageSummary.textContent = matchingRows.length
            ? `Showing ${firstVisibleIndex + 1}–${Math.min(firstVisibleIndex + reorderPageSize, matchingRows.length)} of ${matchingRows.length}`
            : '';
        reorderPreviousPage.disabled = reorderCurrentPage === 1;
        reorderNextPage.disabled = reorderCurrentPage === totalPages;
        reorderPageButtons.replaceChildren();
        for (let page = 1; page <= totalPages; page++) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'reorder-page-button';
            button.textContent = String(page);
            if (page === reorderCurrentPage) button.setAttribute('aria-current', 'page');
            button.addEventListener('click', () => {
                reorderCurrentPage = page;
                filterReorders();
            });
            reorderPageButtons.appendChild(button);
        }
    };
    reorderSearch.addEventListener('input', () => {
        reorderCurrentPage = 1;
        filterReorders();
    });
    reorderStatusFilter.addEventListener('change', () => {
        reorderCurrentPage = 1;
        filterReorders();
    });
    reorderPreviousPage.addEventListener('click', () => {
        if (reorderCurrentPage > 1) {
            reorderCurrentPage--;
            filterReorders();
        }
    });
    reorderNextPage.addEventListener('click', () => {
        reorderCurrentPage++;
        filterReorders();
    });
    filterReorders();
}

function openReorderModal(productId, productName) {
    document.getElementById('reorderProductId').value = productId;
    document.getElementById('productName').textContent = productName;
    document.getElementById('reorderModal').style.display = 'flex';
}

function showSupplierContact(select) {
    const option = select.options[select.selectedIndex];
    const contact = document.getElementById('supplierContact');
    contact.replaceChildren();
    if (!option || !option.value) {
        contact.textContent = 'Supplier contact details will appear here.';
        return;
    }
    [
        ['Contact', option.dataset.contact],
        ['Email', option.dataset.email],
        ['Phone', option.dataset.phone]
    ].forEach(function ([label, value]) {
        const line = document.createElement('div');
        line.className = 'supplier-contact-line';
        const heading = document.createElement('strong');
        heading.textContent = label + ': ';
        line.append(heading, document.createTextNode(value || 'Not provided'));
        contact.appendChild(line);
    });
}

function closeReorderModal() {
    document.getElementById('reorderModal').style.display = 'none';
}

function closeReceiveModal() {
    document.getElementById('receiveModal').style.display = 'none';
}

function closeBulkOrderModal() {
    document.getElementById('bulkOrderModal').style.display = 'none';
}

const completedDetailsModal = document.getElementById('completedReorderDetailsModal');
document.querySelectorAll('.completed-details-button').forEach(function (button) {
    button.addEventListener('click', function () {
        const details = {
            completedDetailsProduct: button.dataset.product,
            completedDetailsId: '#' + button.dataset.reorderId,
            completedDetailsGroup: button.dataset.orderGroup || '—',
            completedDetailsSupplier: button.dataset.supplier,
            completedDetailsOrdered: button.dataset.ordered,
            completedDetailsReceived: button.dataset.received,
            completedDetailsExpected: button.dataset.expected,
            completedDetailsCreated: button.dataset.created,
            completedDetailsNotes: button.dataset.notes || 'No notes'
        };
        Object.keys(details).forEach(function (id) {
            document.getElementById(id).textContent = details[id];
        });
        completedDetailsModal.style.display = 'flex';
    });
});
document.getElementById('closeCompletedDetails').addEventListener('click', function () {
    completedDetailsModal.style.display = 'none';
});
completedDetailsModal.addEventListener('click', function (event) {
    if (event.target === completedDetailsModal) {
        completedDetailsModal.style.display = 'none';
    }
});
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        completedDetailsModal.style.display = 'none';
    }
});

document.querySelectorAll('.receive-stock-trigger').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('receiveReorderId').value = button.dataset.reorderId;
        document.getElementById('receiveProductName').textContent =
            button.dataset.productName + ' — quantity remaining: ' + button.dataset.remaining;
        document.getElementById('receiveQuantity').value = button.dataset.remaining || 1;
        document.getElementById('receiveQuantity').max = button.dataset.remaining || 1;
        document.getElementById('receiveQuantityHelp').textContent =
            'Enter the quantity that arrived. Up to ' + button.dataset.remaining + ' units remain on this reorder; the rest can be received later.';
        document.getElementById('receiveLotNumber').value = '';
        document.getElementById('receiveManufacturingDate').value = '';
        document.getElementById('receiveExpirationDate').value = '';
        document.getElementById('receiveModal').style.display = 'flex';
    });
});

const waitingReceiveButtons = Array.from(document.querySelectorAll('.receive-stock-trigger[hidden][data-available-at]'));
if (waitingReceiveButtons.length) {
    const revealAvailableReceiveButtons = function () {
        const now = Date.now();
        waitingReceiveButtons.forEach(function (button) {
            if (Number(button.dataset.availableAt) <= now) {
                button.hidden = false;
            }
        });
        if (waitingReceiveButtons.every(function (button) { return !button.hidden; })) {
            window.clearInterval(receiveAvailabilityTimer);
        }
    };
    const receiveAvailabilityTimer = window.setInterval(revealAvailableReceiveButtons, 15000);
    revealAvailableReceiveButtons();
}

const bulkOrderButton = document.getElementById('openBulkOrderButton');
if (bulkOrderButton) {
    const bulkCheckboxes = Array.from(document.querySelectorAll('.bulk-product-checkbox:not(:disabled)'));
    const bulkCount = document.getElementById('bulkOrderCount');
    const bulkOrderStatus = document.getElementById('bulkOrderStatus');
    const bulkModal = document.getElementById('bulkOrderModal');
    const bulkForm = document.getElementById('bulkOrderForm');
    const toggleBulkSelection = document.getElementById('toggleBulkSelection');
    const lowStockProductsCard = document.querySelector('.low-stock-products-card');
    const bulkSupplierSelect = document.getElementById('bulkSupplier');
    const bulkProductRows = document.getElementById('bulkOrderProductRows');
    const originalSupplierOptions = Array.from(bulkSupplierSelect.options).map(function (option) {
        return { value: option.value, text: option.textContent };
    });

    function updateBulkOrderSelection() {
        const selected = bulkCheckboxes.filter(function (checkbox) { return checkbox.checked; });
        const assignedSupplierIds = Array.from(new Set(selected
            .map(function (checkbox) { return checkbox.dataset.supplierId; })
            .filter(function (supplierId) { return supplierId !== '0'; })));
        bulkCheckboxes.forEach(function (checkbox) {
            const singleReorderButton = checkbox.closest('.low-stock-item-row').querySelector('.reorder-single-button');
            if (singleReorderButton) {
                singleReorderButton.classList.toggle('selected-for-bulk', checkbox.checked);
            }
        });
        bulkCount.textContent = '(' + selected.length + ')';
        bulkOrderButton.disabled = selected.length < 2 || assignedSupplierIds.length > 1;
        if (selected.length >= 2 && assignedSupplierIds.length > 1) {
            bulkOrderStatus.textContent = 'Selected products must use the same supplier. Change your selection to continue.';
        } else if (selected.length >= 2) {
            const includesActiveReorder = selected.some(function (checkbox) {
                return checkbox.dataset.activeReorder === 'true';
            });
            bulkOrderStatus.textContent = includesActiveReorder
                ? 'Ready to create a new bulk order for ' + selected.length + ' products. Existing reorder requests will remain active.'
                : 'Ready to create a bulk order for ' + selected.length + ' products.';
        } else if (selected.length === 1) {
            bulkOrderStatus.textContent = 'Select at least one more product from the same supplier, or use Reorder to order this product individually.';
        } else if (bulkCheckboxes.length === 0) {
            bulkOrderStatus.textContent = 'All low-stock products already have an active reorder. View them in the Reorders tab before creating another order.';
        } else {
            bulkOrderStatus.textContent = 'Select at least two products from the same supplier to create a bulk order.';
        }
    }

    bulkCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateBulkOrderSelection);
    });
    toggleBulkSelection.addEventListener('click', function () {
        const selectionEnabled = toggleBulkSelection.getAttribute('aria-pressed') !== 'true';
        toggleBulkSelection.setAttribute('aria-pressed', String(selectionEnabled));
        toggleBulkSelection.textContent = selectionEnabled ? 'Cancel Selection' : 'Select Products';
        lowStockProductsCard.classList.toggle('bulk-selection-enabled', selectionEnabled);
        lowStockProductsCard.querySelectorAll('.bulk-item-select').forEach(function (label) {
            label.hidden = !selectionEnabled;
        });
        if (!selectionEnabled) {
            bulkCheckboxes.forEach(function (checkbox) {
                checkbox.checked = false;
            });
        }
        updateBulkOrderSelection();
    });
    updateBulkOrderSelection();
    bulkOrderButton.addEventListener('click', function () {
        const selected = bulkCheckboxes.filter(function (checkbox) { return checkbox.checked; });
        if (selected.length < 2) {
            bulkOrderStatus.textContent = selected.length === 1
                ? 'Select at least one more product from the same supplier, or use Reorder to order this product individually.'
                : 'Select at least two products from the same supplier before creating a bulk order.';
            return;
        }
        const assignedSupplierIds = Array.from(new Set(selected
            .map(function (checkbox) { return checkbox.dataset.supplierId; })
            .filter(function (supplierId) { return supplierId !== '0'; })));
        const assignedSupplierId = assignedSupplierIds.length === 1 ? assignedSupplierIds[0] : '';
        if (assignedSupplierIds.length > 1) {
            bulkOrderStatus.textContent = 'Selected products must use the same supplier. Change your selection to continue.';
            return;
        }
        bulkSupplierSelect.replaceChildren();
        originalSupplierOptions.forEach(function (item) {
            if (!assignedSupplierId || item.value === '' || item.value === assignedSupplierId) {
                const option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.text;
                bulkSupplierSelect.appendChild(option);
            }
        });
        bulkSupplierSelect.value = assignedSupplierId;
        document.getElementById('bulkOrderSupplier').textContent = selected.length + ' Products';
        bulkProductRows.replaceChildren();
        selected.forEach(function (checkbox) {
            const row = document.createElement('tr');
            const productCell = document.createElement('td');
            const name = document.createElement('strong');
            name.textContent = checkbox.dataset.productName;
            const stockNote = document.createElement('small');
            stockNote.textContent = 'Low stock';
            productCell.append(name, stockNote);

            const stockCell = document.createElement('td');
            stockCell.className = 'bulk-order-stock';
            stockCell.textContent = checkbox.dataset.stock;

            const quantityCell = document.createElement('td');
            const quantityInput = document.createElement('input');
            quantityInput.className = 'bulk-order-quantity';
            quantityInput.type = 'number';
            quantityInput.name = 'reorder_quantities[' + checkbox.value + ']';
            quantityInput.min = '1';
            quantityInput.value = '50';
            quantityInput.required = true;
            quantityInput.setAttribute('aria-label', 'Order quantity for ' + checkbox.dataset.productName);
            quantityInput.addEventListener('input', updateBulkSummary);
            quantityCell.appendChild(quantityInput);

            row.append(productCell, stockCell, quantityCell);
            bulkProductRows.appendChild(row);
        });
        updateBulkSummary();
        bulkModal.style.display = 'flex';
    });

    function updateBulkSummary() {
        const quantityInputs = Array.from(bulkProductRows.querySelectorAll('input[name^="reorder_quantities["]'));
        const totalUnits = quantityInputs.reduce(function (total, input) {
            return total + (Number(input.value) || 0);
        }, 0);
        document.getElementById('bulkSummaryProducts').textContent = quantityInputs.length;
        document.getElementById('bulkSummaryUnits').textContent = totalUnits.toLocaleString() + ' units';
    }

    bulkForm.addEventListener('submit', function (event) {
        const selected = bulkCheckboxes.filter(function (checkbox) { return checkbox.checked; });
        const assignedSupplierIds = Array.from(new Set(selected
            .map(function (checkbox) { return checkbox.dataset.supplierId; })
            .filter(function (supplierId) { return supplierId !== '0'; })));
        if (selected.length < 2 || !bulkSupplierSelect.value
            || assignedSupplierIds.length > 1
            || (assignedSupplierIds.length === 1 && assignedSupplierIds[0] !== bulkSupplierSelect.value)) {
            event.preventDefault();
            alert('Select at least two products and choose their shared supplier.');
            return;
        }
        const submitButton = bulkForm.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Creating bulk order...';
        }
    });
    updateBulkOrderSelection();
}

const reorderRequestForm = document.getElementById('reorderRequestForm');
if (reorderRequestForm) {
    reorderRequestForm.addEventListener('submit', function () {
        const submitButton = reorderRequestForm.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Submitting...';
        }
    });
}

</script>

</body>
</html>
