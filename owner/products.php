<?php 
include 'config.php'; 
include 'functions.php';
session_start();
include 'access_control.php';

$isPharmacist = requireOwnerPageAccess($conn, true);
if ($isPharmacist
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (($_POST['share_product'] ?? '') !== '1')
    && (($_POST['add_product'] ?? '') !== '1')
    && !isset($_POST['delete'])) {
    http_response_code(403);
    exit('This product action is not available to pharmacists.');
}

$currentPage = basename($_SERVER['PHP_SELF']);

$categoryColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'category'");
if ($categoryColumnResult && $categoryColumnResult->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN category VARCHAR(100) NULL AFTER product_name");
}
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS min_pieces INT UNSIGNED NOT NULL DEFAULT 1");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS min_boxes INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS benefits TEXT NULL");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS prescription_required TINYINT(1) NOT NULL DEFAULT 0");
ensureLowStockNotificationSchema($conn);
$conn->query("UPDATE products SET prescription_required = 1 WHERE LOWER(TRIM(category)) = 'prescription medicine'");
$conn->query("UPDATE products SET category = 'Prescription Medicine' WHERE prescription_required = 1 AND LOWER(TRIM(category)) <> 'prescription medicine'");
$conn->query("CREATE TABLE IF NOT EXISTS product_batches (
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
    UNIQUE KEY uq_product_lot (product_id, lot_number),
    KEY idx_batch_expiration (expiration_date),
    KEY idx_batch_recalled (recalled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$manufacturingDateColumn = $conn->query("SHOW COLUMNS FROM product_batches LIKE 'manufacturing_date'");
if ($manufacturingDateColumn && $manufacturingDateColumn->num_rows === 0) {
    $conn->query("ALTER TABLE product_batches ADD COLUMN manufacturing_date DATE NULL AFTER expiration_date");
}
$conn->query("CREATE TABLE IF NOT EXISTS product_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_product_images_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE product_images ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NULL");
$conn->query("ALTER TABLE product_images ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL");
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
$prescriptionAccessColumnResult = $conn->query("SHOW COLUMNS FROM prescription_product_access LIKE 'expires_at'");
if ($prescriptionAccessColumnResult && $prescriptionAccessColumnResult->num_rows === 0) {
    $conn->query("ALTER TABLE prescription_product_access ADD COLUMN expires_at DATETIME NULL");
}
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS prescription_id INT NULL");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_limit INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("UPDATE prescription_product_access ppa
    JOIN products p ON p.id = ppa.product_id
    SET ppa.quantity_limit = GREATEST(COALESCE(p.quantity, 0), 0), ppa.quantity_purchased = 0, ppa.expires_at = NULL
    WHERE ppa.quantity_limit = 0 AND ppa.expires_at IS NOT NULL");
$conn->query("CREATE TABLE IF NOT EXISTS customer_chat_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sender_role VARCHAR(10) NOT NULL DEFAULT 'user',
    message TEXT NULL,
    medicine_name VARCHAR(255) NULL,
    prescription_image VARCHAR(255) NULL,
    prescription_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    prescription_reviewed_at DATETIME NULL,
    prescription_reviewed_by INT NULL,
    product_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_chat_user (user_id),
    KEY idx_chat_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS product_id INT NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS prescription_id INT NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS product_access_expires_at DATETIME NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS shared_product_id INT NULL");
$customerChatTableResult = $conn->query("SHOW TABLES LIKE 'customer_chat_messages'");
$hasCustomerChatMessages = $customerChatTableResult && $customerChatTableResult->num_rows > 0;
$prescriptionTableResult = $conn->query("SHOW TABLES LIKE 'prescriptions'");
$hasPrescriptions = $prescriptionTableResult && $prescriptionTableResult->num_rows > 0;
if ($hasPrescriptions) {
    $conn->query("ALTER TABLE prescriptions MODIFY status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending'");
    $conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS shared_product_id INT NULL");
    $conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS product_access_expires_at DATETIME NULL");
}
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS prescription_status VARCHAR(20) NOT NULL DEFAULT 'none'");
$orderPrescriptionColumnResult = $conn->query("SHOW COLUMNS FROM orders LIKE 'prescription_status'");
$hasOrderPrescriptionStatus = $orderPrescriptionColumnResult && $orderPrescriptionColumnResult->num_rows > 0;

if (isset($_POST['share_product']) && $_POST['share_product'] === '1') {
    if (!$isPharmacist) {
        http_response_code(403);
        exit('Only pharmacists can share prescription products with customers.');
    }
    $sharedProductId = (int)($_POST['product_id'] ?? 0);
    $sharedUserId = (int)($_POST['user_id'] ?? 0);
    $shareSucceeded = false;
    $shareError = 'Select an eligible customer with an approved prescription.';
    $quantityLimit = filter_var($_POST['quantity_limit'] ?? null, FILTER_VALIDATE_INT);
    $restrictedProduct = $conn->query("SELECT id, product_name, status, box, pieces, pieces_per_box,
        (SELECT COUNT(*) FROM product_batches pb WHERE pb.product_id = products.id) AS batch_count,
        (SELECT COALESCE(SUM(CASE WHEN pb.recalled = 0 AND pb.expiration_date >= CURDATE() THEN pb.current_quantity ELSE 0 END), 0)
         FROM product_batches pb WHERE pb.product_id = products.id) AS sellable_batch_stock
        FROM products WHERE id = $sharedProductId AND prescription_required = 1 LIMIT 1");
    $restrictedProductRow = $restrictedProduct ? $restrictedProduct->fetch_assoc() : null;
    $productName = $restrictedProductRow['product_name'] ?? '';
    $approvedPrescriptionId = 0;
    $approvedChatId = 0;
    if ($hasPrescriptions && $sharedUserId > 0) {
        $approvedPrescriptionStmt = $conn->prepare("SELECT id FROM prescriptions WHERE user_id = ? AND status = 'approved' ORDER BY reviewed_at DESC, submitted_at DESC LIMIT 1");
        if ($approvedPrescriptionStmt) {
            $approvedPrescriptionStmt->bind_param('i', $sharedUserId);
            $approvedPrescriptionStmt->execute();
            $approvedPrescriptionId = (int)($approvedPrescriptionStmt->get_result()->fetch_assoc()['id'] ?? 0);
            $approvedPrescriptionStmt->close();
        }
    }
    if ($approvedPrescriptionId < 1 && $hasCustomerChatMessages && $sharedUserId > 0) {
        $approvedChatStmt = $conn->prepare("SELECT id FROM customer_chat_messages
            WHERE user_id = ? AND prescription_status IN ('accepted', 'approved')
              AND prescription_image IS NOT NULL AND (product_id = ? OR LOWER(TRIM(medicine_name)) = LOWER(?))
            ORDER BY created_at DESC LIMIT 1");
        if ($approvedChatStmt) {
            $approvedChatStmt->bind_param('iis', $sharedUserId, $sharedProductId, $productName);
            $approvedChatStmt->execute();
            $approvedChatId = (int)($approvedChatStmt->get_result()->fetch_assoc()['id'] ?? 0);
            $approvedChatStmt->close();
        }
    }
    $productPiecesPerBox = max(1, (int)($restrictedProductRow['pieces_per_box'] ?? 12));
    $availableProductStock = $restrictedProductRow
        ? max(0, ((int)($restrictedProductRow['box'] ?? 0) * $productPiecesPerBox) + (int)($restrictedProductRow['pieces'] ?? 0))
        : 0;
    if ($restrictedProductRow && (int)($restrictedProductRow['batch_count'] ?? 0) > 0) {
        $availableProductStock = min($availableProductStock, max(0, (int)($restrictedProductRow['sellable_batch_stock'] ?? 0)));
    }
    if ($sharedProductId < 1 || $sharedUserId < 1 || ($approvedPrescriptionId < 1 && $approvedChatId < 1)) {
        $shareError = 'Select an eligible customer with an approved prescription.';
    } elseif (!$restrictedProductRow) {
        $shareError = 'The selected product is not marked as prescription-required.';
    } elseif (strtolower((string)$restrictedProductRow['status']) !== 'active') {
        $shareError = 'The selected product is inactive and cannot be shared.';
    } elseif ($availableProductStock < 1) {
        $shareError = 'The selected product has no available, unexpired stock.';
    } elseif ($quantityLimit === false || $quantityLimit === null || $quantityLimit < 1 || $quantityLimit > $availableProductStock) {
        $shareError = 'Quantity limit must be between 1 and ' . number_format($availableProductStock) . ' available pieces.';
    } elseif (!$hasCustomerChatMessages) {
        $shareError = 'Customer Chat is unavailable, so the product could not be sent.';
    } else {
        $adminId = (int)$_SESSION['user_id'];
        
        $shareSaved = $conn->begin_transaction();
        if (!$shareSaved) {
            $shareError = 'Unable to start the product-sharing transaction.';
            error_log('Prescription product sharing transaction failed: ' . $conn->error);
        }
        $share = $shareSaved ? $conn->prepare('INSERT INTO prescription_product_access (product_id, user_id, granted_by, prescription_id, expires_at, quantity_limit, quantity_purchased) VALUES (?, ?, ?, NULLIF(?, 0), NULL, ?, 0) ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by), prescription_id = VALUES(prescription_id), granted_at = CURRENT_TIMESTAMP, expires_at = NULL, quantity_limit = VALUES(quantity_limit), quantity_purchased = 0') : false;
        if ($share) {
            $share->bind_param('iiiii', $sharedProductId, $sharedUserId, $adminId, $approvedPrescriptionId, $quantityLimit);
            $shareSaved = $share->execute();
            if (!$shareSaved) {
                $shareError = 'Unable to grant prescription product access.';
                error_log('Prescription product access insert failed: ' . $share->error);
            }
            $share->close();
        } elseif ($shareSaved) {
            $shareSaved = false;
            $shareError = 'Unable to prepare prescription product access.';
            error_log('Prescription product access prepare failed: ' . $conn->error);
        }
        if ($shareSaved && $approvedPrescriptionId > 0) {
            $shareUpdate = $conn->prepare("UPDATE prescriptions SET shared_product_id = ?, product_access_expires_at = NULL WHERE id = ? AND user_id = ? AND status = 'approved'");
            if ($shareUpdate) {
                $shareUpdate->bind_param('iii', $sharedProductId, $approvedPrescriptionId, $sharedUserId);
                $shareUpdate->execute();
                $shareUpdate->close();
            }
        } elseif ($shareSaved && $approvedChatId > 0) {
            $shareUpdate = $conn->prepare("UPDATE customer_chat_messages SET shared_product_id = ?, product_access_expires_at = NULL WHERE id = ? AND user_id = ?");
            if ($shareUpdate) {
                $shareUpdate->bind_param('iii', $sharedProductId, $approvedChatId, $sharedUserId);
                $shareUpdate->execute();
                $shareUpdate->close();
            }
        }
        
        // Send message to customer chat with the product
        $chatInsert = $shareSaved ? $conn->prepare('INSERT INTO customer_chat_messages (user_id, sender_role, message, medicine_name, prescription_status, product_id, prescription_id, product_access_expires_at, shared_product_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, NOW())') : false;
        if ($chatInsert) {
            $senderRole = 'pharmacist';
            $chatMessage = 'The pharmacy set a purchase quantity for this medicine under your approved prescription.';
            $prescriptionStatus = 'accepted';
            $linkedPrescriptionId = $approvedPrescriptionId;
            $noExpiry = null;
            $chatInsert->bind_param('issssiisi', $sharedUserId, $senderRole, $chatMessage, $productName, $prescriptionStatus, $sharedProductId, $linkedPrescriptionId, $noExpiry, $sharedProductId);
            $shareSucceeded = $chatInsert->execute();
            if (!$shareSucceeded) {
                $shareError = 'Prescription access was not sent to Customer Chat. Please try again.';
                error_log('Prescription product chat message insert failed: ' . $chatInsert->error);
            }
            $chatInsert->close();
        } elseif ($shareSaved) {
            $shareSucceeded = false;
            $shareError = 'Unable to prepare the Customer Chat message.';
            error_log('Prescription product chat message prepare failed: ' . $conn->error);
        }
        if ($shareSucceeded && !$conn->commit()) {
            $shareSucceeded = false;
            $shareError = 'Unable to finish sending the product to Customer Chat.';
            error_log('Prescription product sharing commit failed: ' . $conn->error);
        }
        if (!$shareSucceeded) {
            $conn->rollback();
        }
        
        // Send notification
        if ($shareSucceeded) {
            $notice = 'A product with a purchase quantity limit has been shared for your approved prescription. Check Customer Chat.';
            $noticeStmt = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())');
            if ($noticeStmt) {
                $noticeStmt->bind_param('is', $sharedUserId, $notice);
                $noticeStmt->execute();
                $noticeStmt->close();
            }
        }
    }
    if (!$shareSucceeded) {
        $_SESSION['share_error'] = $shareError;
    }
    if ($shareSucceeded) {
        header('Location: customer_chat.php?user_id=' . $sharedUserId);
    } else {
        header('Location: products.php?shared=0');
    }
    exit();
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $result = $conn->query("SELECT * FROM products ORDER BY id DESC");
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products_export_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Product Name', 'SKU', 'Category', 'Price (Piece)', 'Price (Box)', 'Pieces', 'Boxes', 'Total Stock', 'Status']);
    
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['product_name'],
            $row['sku'] ?? 'MED-' . $row['id'],
            $row['category'] ?? '',
            number_format((float)($row['price_piece'] ?? 0), 2),
            number_format((float)($row['price_box'] ?? 0), 2),
            $row['pieces'] ?? 0,
            $row['box'] ?? 0,
            ($row['pieces'] ?? 0) + (($row['box'] ?? 0) * ($row['pieces_per_box'] ?? 12)),
            $row['status'] ?? 'Active'
        ]);
    }
    fclose($output);
    exit();
}

if (isset($_POST['add_product']) && $_POST['add_product'] === '1') {
    $name = trim($_POST['product_name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $barcode = trim($_POST['barcode'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $prescriptionRequired = isset($_POST['prescription_required'])
        || strtolower(trim($category)) === 'prescription medicine';
    if ($prescriptionRequired) {
        $category = 'Prescription Medicine';
    }
    if ($category === '' || strlen($category) > 100) {
        header('Location: products.php?product_error=' . urlencode('Select a product category or enter a category under Other (up to 100 characters).'));
        exit();
    }
    $description = (string)($_POST['description'] ?? '');
    $ingredients = (string)($_POST['ingredients'] ?? '');
    $benefits = (string)($_POST['benefits'] ?? '');
    $usageInstructions = (string)($_POST['usage_instructions'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $productForm = trim($_POST['product_form'] ?? '');
    $servingSize = trim($_POST['serving_size'] ?? '');
    $pricePiece = (float)($_POST['price_piece'] ?? 0);
    $piecesPerBox = max(1, (int)($_POST['pieces_per_box'] ?? 12));
    $quantity = max(0, (int)($_POST['quantity_display'] ?? 0));
    $boxes = intdiv($quantity, $piecesPerBox);
    $pieces = $quantity % $piecesPerBox;
    $priceBox = round($pricePiece * $piecesPerBox * 0.98, 2);
    $minPieces = max(0, (int)($_POST['min_pieces'] ?? 1));
    $minBoxes = max(0, (int)($_POST['min_boxes'] ?? 0));
    $lowStockThreshold = max(0, (int)($_POST['low_stock_threshold'] ?? 20));
    $lotNumber = trim($_POST['lot_number'] ?? '');
    $expirationDate = trim($_POST['expiration_date'] ?? '');
    $manufacturingDate = trim($_POST['manufacturing_date'] ?? '');
    $expiration = DateTime::createFromFormat('Y-m-d', $expirationDate);
    $manufacturing = $manufacturingDate !== '' ? DateTime::createFromFormat('Y-m-d', $manufacturingDate) : false;
    $minimumBatchExpiration = date('Y-m-d', strtotime('+30 days'));
    if (
        $lotNumber === ''
        || !$expiration
        || $expiration->format('Y-m-d') !== $expirationDate
        || $expirationDate < $minimumBatchExpiration
        || !$manufacturing
        || $manufacturing->format('Y-m-d') !== $manufacturingDate
        || $manufacturingDate > date('Y-m-d')
        || $manufacturingDate > $expirationDate
    ) {
        header('Location: products.php?product_error=' . urlencode('Lot number and a valid manufacturing date are required. The manufacturing date cannot be in the future or after expiration, and new products must have at least 30 days remaining before expiration.'));
        exit();
    }
    try {
        ensureUniqueInventoryIdentifierIndexes($conn);
        foreach (['SKU' => ['sku', $sku], 'barcode' => ['barcode', $barcode], 'lot number' => ['lot_number', $lotNumber]] as $label => [$type, $value]) {
            if (inventoryIdentifierInUse($conn, $type, $value)) {
                header('Location: products.php?product_error=' . urlencode('This ' . $label . ' is already assigned to another record. Each SKU, barcode, and lot number must be unique.'));
                exit();
            }
        }
    } catch (RuntimeException $exception) {
        header('Location: products.php?product_error=' . urlencode($exception->getMessage()));
        exit();
    }
    if ($name !== '' && (int)($_POST['duplicate_confirmed'] ?? 0) !== 1) {
        $duplicateCheck = $conn->prepare('SELECT product_name FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) LIMIT 1');
        if (!$duplicateCheck) {
            error_log('Unable to prepare product duplicate check: ' . $conn->error);
            header('Location: products.php?product_error=' . urlencode('Unable to validate the product name. Please try again.'));
            exit();
        }
        $duplicateCheck->bind_param('s', $name);
        if (!$duplicateCheck->execute()) {
            error_log('Unable to check for duplicate product: ' . $duplicateCheck->error);
            $duplicateCheck->close();
            header('Location: products.php?product_error=' . urlencode('Unable to validate the product name. Please try again.'));
            exit();
        }
        $duplicateResult = $duplicateCheck->get_result();
        if ($duplicateResult && $duplicateResult->num_rows > 0) {
            $duplicateRow = $duplicateResult->fetch_assoc();
            $duplicateCheck->close();
            header('Location: products.php?product_error=' . urlencode('A product with the same name already exists: ' . ($duplicateRow['product_name'] ?? 'Existing product') . '. Review the duplicate warning before saving.'));
            exit();
        }
        $duplicateCheck->close();
    }
    $imageName = '';
    $uploadedImages = [];
    $imageUrls = [];
    foreach (['image_url_1', 'image_url_2', 'image_url_3', 'image_url_4'] as $imageUrlField) {
        $imageUrlValue = trim($_POST[$imageUrlField] ?? '');
        if ($imageUrlValue !== '' && filter_var($imageUrlValue, FILTER_VALIDATE_URL)) {
            $imageUrls[] = $imageUrlValue;
        }
    }
    if (!$imageUrls) {
        $legacyImageUrl = trim($_POST['image_url'] ?? '');
        if ($legacyImageUrl !== '' && filter_var($legacyImageUrl, FILTER_VALIDATE_URL)) {
            $imageUrls[] = $legacyImageUrl;
        }
    }
    $imageFiles = $_FILES['images'] ?? null;
    if ($imageFiles && is_array($imageFiles['name'] ?? null)) {
        $fileCount = min(4, count($imageFiles['name']));
        $allowedImageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        for ($imageIndex = 0; $imageIndex < $fileCount; $imageIndex++) {
            if (($imageFiles['error'][$imageIndex] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($imageFiles['tmp_name'][$imageIndex])) {
                continue;
            }
            $mime = function_exists('mime_content_type') ? mime_content_type($imageFiles['tmp_name'][$imageIndex]) : '';
            if (!isset($allowedImageTypes[$mime]) || (int)$imageFiles['size'][$imageIndex] > 5 * 1024 * 1024 || @getimagesize($imageFiles['tmp_name'][$imageIndex]) === false) {
                continue;
            }
            $imageNameForUpload = 'product_' . bin2hex(random_bytes(8)) . '.' . $allowedImageTypes[$mime];
            if (move_uploaded_file($imageFiles['tmp_name'][$imageIndex], 'uploads/' . $imageNameForUpload)) {
                $uploadedImages[] = $imageNameForUpload;
            }
        }
        $imageName = $uploadedImages[0] ?? '';
    } elseif (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $imageName = time() . '_' . basename($_FILES['image']['name']);
        move_uploaded_file($_FILES['image']['tmp_name'], 'uploads/' . $imageName);
    }
    $productImages = array_values(array_unique(array_merge($uploadedImages, $imageUrls)));
    $imageName = $productImages[0] ?? '';

    $productValues = [
        'product_name' => [$name, 's'],
        'sku' => [$sku !== '' ? $sku : null, 's'],
        'barcode' => [$barcode !== '' ? $barcode : null, 's'],
        'description' => [$description, 's'],
        'ingredients' => [$ingredients, 's'],
        'benefits' => [$benefits, 's'],
        'usage_instructions' => [$usageInstructions, 's'],
        'price' => [$pricePiece, 'd'],
        'price_piece' => [$pricePiece, 'd'],
        'price_box' => [$priceBox, 'd'],
        'box' => [$boxes, 'i'],
        'pieces' => [$pieces, 'i'],
        'quantity' => [$quantity, 'i'],
        'pieces_per_box' => [$piecesPerBox, 'i'],
        'min_pieces' => [$minPieces, 'i'],
        'min_boxes' => [$minBoxes, 'i'],
        'low_stock_threshold' => [$lowStockThreshold, 'i'],
        'image' => [$imageName, 's'],
        'category' => [$category, 's'],
        'brand' => [$brand, 's'],
        'product_form' => [$productForm, 's'],
        'serving_size' => [$servingSize, 's'],
        'prescription_required' => [$prescriptionRequired ? 1 : 0, 'i'],
        'status' => [in_array(strtolower(trim($_POST['status'] ?? 'active')), ['active', 'inactive'], true) ? strtolower(trim($_POST['status'])) : 'active', 's']
    ];
    $availableColumns = [];
    $columnsResult = $conn->query("SHOW COLUMNS FROM products");
    if ($columnsResult) {
        while ($column = $columnsResult->fetch_assoc()) { $availableColumns[$column['Field']] = true; }
    }
    $insertFields = [];
    $insertPlaceholders = [];
    $insertTypes = '';
    $insertValues = [];
    foreach ($productValues as $field => [$value, $type]) {
        if (isset($availableColumns[$field])) {
            $insertFields[] = "`$field`";
            $insertPlaceholders[] = '?';
            $insertTypes .= $type;
            $insertValues[] = $value;
        }
    }
    $insert = $conn->prepare('INSERT INTO products (' . implode(', ', $insertFields) . ') VALUES (' . implode(', ', $insertPlaceholders) . ')');
    if (!$insert) {
        error_log('Unable to prepare product insert: ' . $conn->error);
        header('Location: products.php?product_error=' . urlencode('Unable to save the product. Please try again.'));
        exit();
    }
    $bindValues = [$insertTypes];
    foreach ($insertValues as $key => &$value) { $bindValues[] = &$value; }
    call_user_func_array([$insert, 'bind_param'], $bindValues);
    $conn->begin_transaction();
    if (!$insert->execute()) {
        $insertError = $insert->error;
        $insert->close();
        $conn->rollback();
        error_log('Unable to save product: ' . $insertError);
        header('Location: products.php?product_error=' . urlencode('Unable to save the product. Check that its SKU and barcode are unique.'));
        exit();
    }
    $newProductId = $insert->insert_id;
    $insert->close();
    if ($newProductId <= 0) {
        $conn->rollback();
        header('Location: products.php?product_error=' . urlencode('Unable to save the product. Please try again.'));
        exit();
    }

    $batch = $conn->prepare('INSERT INTO product_batches (product_id, lot_number, manufacturing_date, expiration_date, received_quantity, current_quantity, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$batch) {
        $conn->rollback();
        error_log('Unable to prepare initial product batch: ' . $conn->error);
        header('Location: products.php?product_error=' . urlencode('Unable to save the product lot. Please try again.'));
        exit();
    }
    $createdBy = (int)$_SESSION['user_id'];
    $batch->bind_param('isssiii', $newProductId, $lotNumber, $manufacturingDate, $expirationDate, $quantity, $quantity, $createdBy);
    if (!$batch->execute()) {
        $batchError = $batch->error;
        $batch->close();
        $conn->rollback();
        error_log('Unable to save initial product batch: ' . $batchError);
        header('Location: products.php?product_error=' . urlencode('Unable to save the lot number. It may already be assigned to another batch.'));
        exit();
    }
    $batch->close();

    if ($productImages) {
        $imageInsert = $conn->prepare('INSERT INTO product_images (product_id, image_path, image_url, sort_order) VALUES (?, ?, ?, ?)');
        if (!$imageInsert) {
            $conn->rollback();
            error_log('Unable to prepare product image insert: ' . $conn->error);
            header('Location: products.php?product_error=' . urlencode('Unable to save product images. Please try again.'));
            exit();
        }
        foreach ($productImages as $sortOrder => $productImage) {
            $imagePath = filter_var($productImage, FILTER_VALIDATE_URL) ? '' : $productImage;
            $imageUrl = $productImage;
            $imageInsert->bind_param('issi', $newProductId, $imagePath, $imageUrl, $sortOrder);
            if (!$imageInsert->execute()) {
                $imageError = $imageInsert->error;
                $imageInsert->close();
                $conn->rollback();
                error_log('Unable to save product image: ' . $imageError);
                header('Location: products.php?product_error=' . urlencode('Unable to save product images. Please try again.'));
                exit();
            }
        }
        $imageInsert->close();
    }
    writeAuditLog($conn, 'product_created', 'product', (int)$newProductId, ['name' => $name]);
    $conn->commit();

    checkAndSendLowStockAlert($conn, $newProductId);
    header('Location: products.php');
    exit();
}

// HANDLE DELETE from products listing
if (isset($_POST['delete'])) {
    $del_id = intval($_POST['delete_id']);

    // fetch product name safely
    $g = $conn->query("SELECT product_name FROM products WHERE id={$del_id}");
    if ($g) {
        $rowp = $g->fetch_assoc();
        $pname = $rowp['product_name'] ?? 'Unknown';
    } else {
        error_log('DB error fetching product for delete: ' . $conn->error);
        $pname = 'Unknown';
    }

    // delete
    if ($conn->query("DELETE FROM products WHERE id={$del_id}")) {
        writeAuditLog($conn, 'product_deleted', 'product', (int)$del_id, ['name' => (string)$pname]);
        $msg = "🗑 Product deleted: " . $pname;
        $conn->query("INSERT INTO notifications (message, created_at) VALUES ('" . $conn->real_escape_string($msg) . "', NOW())");
        header('Location: products.php');
        exit();
    } else {
        error_log('DB error deleting product: ' . $conn->error);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Products</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
<style>
body { margin:0; font-family:"Segoe UI", Arial, sans-serif; background:#f4f6f8; min-height:100vh; font-size:14px; }

.content { margin-left:260px; width:calc(100% - 260px); padding:32px; min-height:100vh; }

.form-box {
    background:white;
    padding:20px;
    border-radius:10px;
    width:400px;
}

input {
    width:100%;
    padding:10px;
    margin-bottom:10px;
}

.content button {
    width:100%;
    padding:10px;
    background:#2c3e50;
    color:white;
    border:none;
}

#preview {
    width:100%;
    margin-top:10px;
    border-radius:10px;
    display:none;
}
.content { background:#f8f9fb; padding:30px 36px; box-sizing:border-box; display:flex; flex-direction:column; }
html, body { min-height:100%; overflow-x:hidden; overflow-y:auto; }
.content { min-height:100vh; height:auto; overflow:visible; }
.page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
.page-header h1 { font-size:30px; color:#20232a; margin:0 0 7px; font-weight:800; }
.page-header p { color:#7b8189; font-size:14px; margin:0; font-weight:600; }
.page-header p span { padding:0 5px; color:#b1b6bc; }
.content .page-header > .export-button,
.content .toolbar-actions > .add-button { background:#fff !important; border-color:#e4e7eb !important; color:#111827 !important; }
.content .page-header > .export-button:hover,
.content .toolbar-actions > .add-button:hover { background:#f8fafc !important; border-color:#d1d5db !important; color:#111827 !important; }
.product-search { display:flex; align-items:center; gap:8px; width:220px; height:34px; background:#fff; border:1px solid #eceef1; border-radius:8px; padding:0 10px; color:#969ca4; }
.product-search input { border:0; outline:0; width:100%; padding:0; margin:0; font-size:13px; color:#333; font-weight:600; }
.product-search button { width:auto; padding:0; border:0; background:transparent; color:#969ca4; font-size:16px; line-height:1; cursor:pointer; display:none; }
.product-search button:hover { color:#ed1b3b; }
.product-search #productBarcodeScanTrigger { display:inline-flex; width:24px; min-width:24px; height:28px; align-items:center; justify-content:center; border-left:1px solid #eceef1; color:#68717c; font-size:16px; }
.product-search #productBarcodeScanTrigger:hover { color:#ed1b3b; }
.summary-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:12px; }
.summary-card { display:flex; align-items:center; gap:11px; height:112px; min-height:112px; background:#fff; border:1px solid #f0f1f3; border-radius:10px; padding:10px 16px; box-shadow:0 2px 10px rgba(28,32,38,.05); }
.summary-card-link { color:inherit; text-decoration:none; transition:transform .15s ease, box-shadow .15s ease; }
.summary-card-link:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(28,32,38,.1); }
.summary-icon { width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:#fff0f3; color:#ed1b3b; font-size:19px; flex:0 0 auto; }
.summary-card.active-products .summary-icon { background:#eaf8ef; color:#35a96a; }
.summary-card.inactive-products .summary-icon { background:#fff5df; color:#e6a51d; }
.summary-card.categories .summary-icon { background:#eef0ff; color:#4d63d8; }
.summary-label { color:#737981; font-size:16px; line-height:18px; margin-bottom:4px; }
.summary-value { color:#272b31; font-size:32px; font-weight:800; line-height:32px; }
.summary-card.total .summary-value { color:#ed1b3b; }
.summary-card.active-products .summary-value { color:#35a96a; }
.summary-card.inactive-products .summary-value { color:#e6a51d; }
.summary-card.categories .summary-value { color:#4d63d8; }
.summary-note { color:#7b8189; font-size:14px; line-height:16px; margin-top:4px; }
.products-panel { display:flex; flex:1 1 auto; flex-direction:column; height:auto; min-height:0; background:#fff; border:1px solid #f0f1f3; border-radius:10px; box-shadow:0 2px 10px rgba(28,32,38,.05); overflow:hidden; }
.table-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:7px; padding:9px 11px; border-bottom:1px solid #f0f1f3; }
.toolbar-actions { display:flex; flex:0 0 auto; align-items:center; gap:7px; }
.table-toolbar .product-search { flex:1 1 140px; min-width:120px; max-width:none; }
.filter-button, .export-button, .add-button { display:inline-flex; align-items:center; gap:7px; height:34px; padding:0 12px; border:1px solid #e4e7eb; border-radius:6px; background:#fff; color:#4e5660; font-size:12px; font-weight:700; text-decoration:none; }
.content .toolbar-actions > .export-button { background:#ed1b3b !important; border-color:#ed1b3b !important; color:#fff !important; }
.content .toolbar-actions > .export-button:hover { background:#d91635 !important; border-color:#d91635 !important; color:#fff !important; }
.add-button { background:#ed1b3b; border-color:#ed1b3b; color:#fff; }
.filter-button { width:auto; cursor:pointer; }
.filter-button[aria-expanded="true"] { border-color:#ed1b3b; color:#ed1b3b; background:#fff7f8; }
.filter-panel { display:none; flex:1 1 auto; min-width:0; align-items:center; gap:6px; padding:0; border:0; background:transparent; }
.filter-panel.open { display:flex; }
.category-quick-filters { display:flex; align-items:center; gap:7px; flex-wrap:wrap; padding:11px 14px; border-bottom:1px solid #f0f1f3; background:#fff; }
.category-quick-label { color:#737981; font-size:10px; font-weight:600; margin-right:3px; }
.category-quick-filter { display:inline-flex; align-items:center; min-height:27px; padding:0 10px; border:1px solid #dfe5eb; border-radius:6px; background:#fff; color:#58616b; font-size:10px; text-decoration:none; }
.category-quick-filter:hover, .category-quick-filter.selected { border-color:#ed1b3b; background:#fff0f3; color:#d91f43; }
.filter-panel select { width:124px; min-width:0; height:34px; padding:0 9px; border:1px solid #e1e5e9; border-radius:6px; background:#fff; color:#4e5660; font-size:12px; font-weight:600; }
.filter-panel select[name="stock_status"] { width:158px; }
.apply-filter-button, .clear-filter-button { display:inline-flex; align-items:center; justify-content:center; gap:5px; height:34px; padding:0 12px; border-radius:6px; font-size:12px; font-weight:700; text-decoration:none; cursor:pointer; }
.apply-filter-button { width:auto; border:1px solid #ed1b3b; background:#ed1b3b; color:#fff; }
.clear-filter-button { border:1px solid #e1e5e9; color:#66707a; background:#fff; }
.table-responsive { flex:0 0 auto; min-height:0; overflow:auto; }
.product-table { width:calc(100% - 32px); border-collapse:collapse; margin:0 16px; }
.product-table { min-width:1040px; }
.product-table th { background:#e9ecef; color:#495057; font-size:16px; line-height:18px; font-weight:800; text-align:left; padding:12px 12px; border-bottom:1px solid #f0f1f3; }
.product-table td { color:#343a40; font-size:16px; line-height:18px; font-weight:600; padding:8px 12px; border-bottom:1px solid #f0f1f3; vertical-align:middle; }
.product-table th:not(:first-child), .product-table td:not(:first-child) { text-align:center !important; }
.product-table .text-end { text-align:center !important; }
.product-table tbody tr:last-child td { border-bottom:0; }
.stock-warning { margin-left:7px; color:#f59e0b; font-size:14px; vertical-align:middle; }
.stock-warning.out { color:#dc2626; }
.product-cell { display:flex; align-items:center; gap:8px; min-width:220px; }
.prod-thumb { width:36px; height:36px; border-radius:7px; background:#f5f7f9; display:flex; align-items:center; justify-content:center; flex:0 0 auto; overflow:hidden; }
.prod-thumb img { max-width:100%; max-height:100%; object-fit:contain; display:block; }
.product-cell strong, .product-cell span { display:block; }
.product-cell strong { color:#252a30; font-size:16px; line-height:18px; font-weight:800; }
.product-cell span { color:#838991; font-size:14px; line-height:15px; font-weight:600; margin-top:3px; }
.category-pill, .product-status { display:inline-flex; align-items:center; border-radius:5px; padding:5px 8px; font-size:12px; font-weight:700; white-space:nowrap; }
.category-pill { background:#edf6ff; color:#3180c7; }
.product-status.active { background:#e9f8ef; color:#2e9c61; }
.product-status.inactive { background:#ffe9ee; color:#d33b59; }
.product-status.low { background:#fff5df; color:#b98619; }
.product-status.out { background:#ffe9ee; color:#d33b59; }
.low-stock-alarm { display:flex; align-items:flex-start; gap:11px; margin-bottom:16px; padding:12px 14px; border:1px solid #f3cf78; border-radius:8px; background:#fff8e7; color:#8b5d00; animation:lowStockPulse 1.8s ease-in-out infinite; }
.low-stock-alarm i { font-size:18px; line-height:1; }
.low-stock-alarm strong { display:block; font-size:11px; }
.low-stock-alarm span { display:block; margin-top:3px; font-size:10px; }
@keyframes lowStockPulse { 0%, 100% { box-shadow:0 0 0 0 rgba(233,163,22,.18); } 50% { box-shadow:0 0 0 5px rgba(233,163,22,0); } }
.action-button { width:27px; height:27px; display:inline-flex; align-items:center; justify-content:center; border:1px solid #e1e5e9; border-radius:6px; background:#fff; color:#66707a; text-decoration:none; margin-left:5px; font-size:11px; }
.content .action-button { width:27px; height:27px; padding:0; border:1px solid #e1e5e9; background:#fff; color:#66707a; }
.action-button:hover { color:#ed1b3b; border-color:#ed1b3b; }
.empty-state { text-align:center; padding:28px !important; color:#7b8189 !important; }
.table-footer { display:flex; flex:0 0 auto; align-items:center; justify-content:space-between; padding:11px 14px; border-top:1px solid #f0f1f3; background:#fff; color:#7b8189; font-size:14px; font-weight:600; }
.pagination { display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
.pagination a { min-width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; border:1px solid #e1e5e9; border-radius:5px; color:#4e5660; text-decoration:none; font-size:12px; font-weight:700; }
.pagination a.current { background:#ed1b3b; border-color:#ed1b3b; color:#fff; }
.pagination-dots { display:inline-flex; align-items:center; justify-content:center; width:18px; height:25px; color:#7b8189; font-size:11px; }
.edit-modal { display:none; position:fixed; inset:0; z-index:1000; align-items:center; justify-content:center; background:rgba(23,29,38,.48); padding:20px; }
.edit-modal.open { display:flex; }
.share-modal { display:none; position:fixed; inset:0; z-index:1000; align-items:center; justify-content:center; background:rgba(23,29,38,.48); padding:20px; }
.share-modal.open { display:flex; }
.share-dialog { width:min(430px, 100%); background:#fff; border-radius:10px; box-shadow:0 20px 60px rgba(15,23,42,.22); }
.share-form { display:grid; gap:10px; padding:18px 20px 20px; }
.share-form label { color:#626a74; font-size:10px; font-weight:600; }
.share-form select { width:100%; height:34px; padding:0 9px; border:1px solid #dfe4e9; border-radius:6px; color:#303740; font-size:11px; }
.share-trigger { color:#3180c7; }
.barcode-scanner-modal { display:none; position:fixed; inset:0; z-index:1200; align-items:center; justify-content:center; padding:20px; background:rgba(23,29,38,.64); }
.barcode-scanner-modal.open { display:flex; }
.barcode-scanner-dialog { width:min(480px,100%); padding:18px; border-radius:10px; background:#fff; box-shadow:0 20px 60px rgba(15,23,42,.28); }
.barcode-scanner-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.barcode-scanner-header h2 { margin:0; color:#20232a; font-size:18px; }
.barcode-scanner-status { display:flex; min-height:20px; align-items:center; gap:8px; margin:0 0 12px; color:#626a74; font-size:13px; }
.barcode-scanner-status[data-state="starting"]::before, .barcode-scanner-status[data-state="scanning"]::before { width:13px; height:13px; flex:0 0 13px; border:2px solid #dfe4e9; border-top-color:#ed1b3b; border-radius:50%; content:""; animation:barcode-scan-spin .8s linear infinite; }
.barcode-scanner-status[data-state="error"] { color:#c43149; }
.barcode-scanner-status[data-state="waiting"] { color:#95620a; }
@keyframes barcode-scan-spin { to { transform:rotate(360deg); } }
.barcode-camera-select { width:100%; height:36px; margin:0 0 10px; padding:0 9px; border:1px solid #dfe4e9; border-radius:6px; background:#fff; color:#303740; font-size:13px; }
.barcode-camera-frame { position:relative; overflow:hidden; border-radius:7px; background:#101820; }
.barcode-camera-frame video { display:block; width:100%; max-height:55vh; aspect-ratio:4 / 3; object-fit:contain; }
.barcode-scan-guide { position:absolute; top:50%; left:50%; width:82%; height:30%; transform:translate(-50%,-50%); border:2px solid rgba(255,255,255,.9); border-radius:8px; box-shadow:0 0 0 999px rgba(0,0,0,.12); pointer-events:none; }
.content .barcode-scanner-modal button { width:auto; height:36px; padding:0 12px; border:1px solid #dfe4e9; border-radius:6px; background:#fff; color:#4e5660; font-size:13px; }
.content .barcode-scanner-modal .barcode-scanner-close { width:32px; height:32px; padding:0; border:0; background:transparent; color:#87909a; }
.barcode-scanner-actions { display:flex; justify-content:flex-end; margin-top:12px; }
.edit-dialog { width:min(800px, 100%); max-height:calc(100vh - 40px); overflow:auto; background:#fff; border-radius:10px; box-shadow:0 20px 60px rgba(15,23,42,.22); }
.edit-modal-header { display:flex; align-items:center; justify-content:space-between; padding:14px 22px; border-bottom:1px solid #f0f1f3; }
.edit-modal-header h2 { margin:0; color:#20232a; font-size:22px; }
.edit-modal .modal-close { width:28px; height:28px; padding:0; border:0; background:transparent; color:#87909a; }
.modal-close { border:0; background:transparent; color:#87909a; font-size:22px; cursor:pointer; line-height:1; }
.edit-form { padding:0 18px 12px; }
.modal-stepper { display:flex; align-items:center; gap:12px; padding:10px 22px 12px; }
.modal-step { display:flex; flex:1 1 0; min-width:0; align-items:center; gap:8px; color:#8793a5; font-size:13px; font-weight:600; }
.modal-step:not(:last-child)::after { content:""; height:1px; flex:1 1 auto; margin-left:4px; background:#d9e1eb; }
.step-number { display:inline-flex; width:24px; height:24px; flex:0 0 24px; align-items:center; justify-content:center; border-radius:50%; background:#e5eaf0; color:#607086; font-size:11px; font-weight:700; }
.modal-step.active { color:#253a57; }
.modal-step.active .step-number, .modal-step.complete .step-number { background:#ed1b3b; color:#fff; }
.modal-step.complete::after { background:#ed1b3b; }
.edit-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:13px 14px; }
.batch-expiry-hint { grid-column:1 / -1; margin-top:-7px; color:#9a5b00; font-size:12px; }
.batch-expiry-warning { display:block; margin-top:5px; padding:7px 9px; border:1px solid #f3cf78; border-radius:6px; background:#fff8e7; color:#8b5d00; font-size:12px; line-height:1.4; }
.batch-expiry-warning[hidden] { display:none !important; }
.form-page { display:none; }
.form-page.active { display:block; }
.form-page-title { display:none; }
.form-section { margin-bottom:8px; overflow:hidden; border:1px solid #e2eaf4; border-radius:8px; background:#fff; }
.form-section-heading { display:flex; align-items:center; gap:10px; padding:7px 12px; background:#f4f8fc; }
.form-section-icon { display:inline-flex; width:28px; height:28px; flex:0 0 28px; align-items:center; justify-content:center; color:#263e5d; font-size:17px; }
.form-section-heading strong { display:block; color:#283b55; font-size:16px; }
.form-section-heading small { display:block; margin-top:2px; color:#8190a4; font-size:13px; }
.form-section-body { padding:8px 13px 9px; }
.form-section-body .edit-form-grid { gap:8px 14px; }
#editModal .additional-details-grid { grid-template-columns:repeat(3, minmax(0, 1fr)) !important; }
.inventory-pricing-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); }
.order-limits-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); }
.form-page[data-form-page="0"] .form-section:last-of-type .edit-form-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
.prescription-check { padding-top:22px; }
.prescription-check label { display:flex; min-height:32px; align-items:center; gap:9px; margin:0; color:#303c4d; font-size:11px; }
.edit-field.prescription-check input { width:16px; height:16px; flex:0 0 16px; margin:0; }
.ai-category-row { display:flex; gap:7px; align-items:stretch; }
.ai-category-row input { flex:1; min-width:0; }
.ai-scan-button { display:inline-flex; align-items:center; justify-content:center; gap:5px; padding:0 10px; border:1px solid #ed1b3b; border-radius:6px; background:#fff0f3; color:#d51d40; font-size:11px; font-weight:700; white-space:nowrap; cursor:pointer; }
.content .toolbar-actions .ai-scan-button,
.content .toolbar-actions .add-button { box-sizing:border-box; width:96px; min-width:96px; height:34px; flex:0 0 96px; justify-content:center; gap:5px; padding:0 6px; border:1px solid #e4e7eb; border-radius:6px; background:#fff; color:#4e5660; font-size:12px; line-height:1.1; white-space:nowrap; }
.content .toolbar-actions .add-button { width:110px; min-width:110px; flex-basis:110px; }
.ai-scan-button:hover { background:#ffe0e7; }
.ai-scan-button:disabled { opacity:.6; cursor:wait; }
.ai-scan-status { display:block; margin-top:5px; color:#7b8189; font-size:10px; font-weight:600; }
.edit-modal:not(.add-mode) .ai-scan-button,
.edit-modal:not(.add-mode) .ai-scan-status,
.edit-modal:not(.add-mode) #aiScanInput { display:none; }
.edit-field label { display:block; color:#626a74; font-size:14px; font-weight:600; margin-bottom:4px; }
.edit-field input, .edit-field select, .edit-field textarea { width:100%; border:1px solid #dfe4e9; border-radius:6px; padding:5px 10px; color:#303740; font-size:15px; outline:0; }
.edit-field input, .edit-field select { height:32px; }
.barcode-input-row { display:flex; align-items:stretch; }
.edit-field .barcode-input-row input { min-width:0; flex:1 1 auto; border-right:0; border-radius:6px 0 0 6px; }
.edit-modal .barcode-scan-trigger { display:inline-flex; width:38px; min-width:38px; height:32px; flex:0 0 38px; align-items:center; justify-content:center; padding:0; border:1px solid #dfe4e9; border-radius:0 6px 6px 0; background:#f4f8fc; color:#304766; font-size:16px; cursor:pointer; }
.edit-modal .barcode-scan-trigger:hover { background:#e9f0f8; }
.edit-field textarea { min-height:62px; resize:vertical; }
.edit-field input:focus, .edit-field select:focus, .edit-field textarea:focus { border-color:#ed1b3b; box-shadow:0 0 0 2px rgba(237,27,59,.1); }
.edit-field.full { grid-column:1 / -1; }
.edit-image-row { display:flex; align-items:center; gap:12px; }
.edit-image-preview { width:78px; height:68px; object-fit:contain; border:1px solid #e5e8ec; border-radius:7px; background:#f7f8fa; }
.edit-upload { flex:1; border:1px dashed #d5dbe1; border-radius:7px; padding:15px; text-align:center; color:#7b838d; font-size:10px; }
.edit-modal-footer { display:flex; justify-content:flex-end; gap:9px; margin-top:10px; }
.edit-modal-footer .modal-button:first-child { margin-right:auto; }
.edit-modal-footer .modal-button[hidden] { display:none; }
.edit-modal .modal-button { width:auto; height:34px; padding:0 16px; border:1px solid #dfe4e9; background:#fff; color:#555e68; border-radius:6px; font-size:13px; cursor:pointer; }
.edit-modal .modal-button.save { border-color:#ed1b3b; background:#ed1b3b; color:#fff; font-weight:700; }
@media (max-width:900px) { .summary-grid { grid-template-columns:repeat(2,1fr); } .product-search { width:180px; } }
@media (min-width:1200px) { .products-panel { min-height:540px; } .table-toolbar { padding:12px 14px; } .product-search { width:280px; } }
@media (max-width:720px) { .edit-modal { padding:10px; } .edit-dialog { max-height:calc(100vh - 20px); } .edit-modal-header { padding:14px 16px; } .modal-stepper { gap:6px; padding:12px 14px; } .modal-step { flex-direction:column; align-items:flex-start; gap:5px; font-size:9px; } .modal-step:not(:last-child)::after { display:none; } .edit-form { padding:0 12px 14px; } .edit-form-grid, .form-page[data-form-page="0"] .form-section:last-of-type .edit-form-grid { grid-template-columns:1fr; } .inventory-pricing-grid, .order-limits-grid { grid-template-columns:1fr; } #editModal .additional-details-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); gap:8px; } .form-section-body { padding:10px; } .prescription-check { padding-top:0; } }
@media (max-width:720px) { .content { margin-left:0; padding:20px; } .page-header { align-items:flex-start; flex-direction:column; gap:12px; } .summary-grid { grid-template-columns:1fr; } .product-search { width:100%; } .table-footer { align-items:flex-start; flex-direction:column; gap:10px; } }
@media (max-width:720px) { html, body { overflow-x:hidden; overflow-y:auto; } .content { min-height:100vh; height:auto; overflow:visible; } .products-panel { height:auto; min-height:0; overflow:hidden; } .table-responsive { overflow:auto; } }
</style>
</head>

<body>

<?php include 'sidebar_owner.php'; ?>

<div class="content">


    <?php
    // Prepare product listing data
    $totalProducts = 0; $activeProducts = 0; $inactiveProducts = 0; $categoryCount = 0;
    $categoryFilter = trim($_GET['category'] ?? '');
    $searchFilter = substr(trim((string)($_GET['search'] ?? '')), 0, 100);
    $editProductId = max(0, (int)($_GET['edit_product_id'] ?? 0));
    $stockFilter = $_GET['stock_status'] ?? '';
    $productStatusFilter = strtolower(trim($_GET['product_status'] ?? ''));
    $allowedStockFilters = ['active', 'low', 'out'];
    if (!in_array($stockFilter, $allowedStockFilters, true)) { $stockFilter = ''; }
    if (!in_array($productStatusFilter, ['active', 'inactive'], true)) { $productStatusFilter = ''; }
    $hasCategoryRes = $conn->query("SHOW COLUMNS FROM products LIKE 'category'");
    $hasCategory = ($hasCategoryRes && $hasCategoryRes->num_rows > 0);
    if (!$hasCategory) { $categoryFilter = ''; }
    $searchableProductColumns = ['product_name'];
    if ($hasCategory) {
        $searchableProductColumns[] = 'category';
    }
    foreach (['sku', 'barcode'] as $searchableColumn) {
        $columnResult = $conn->query("SHOW COLUMNS FROM products LIKE '$searchableColumn'");
        if ($columnResult && $columnResult->num_rows > 0) {
            $searchableProductColumns[] = $searchableColumn;
        }
    }
    $productWhere = '1=1';
    $stockExpression = "CASE
        WHEN EXISTS (SELECT 1 FROM product_batches tracked_batch WHERE tracked_batch.product_id = products.id)
        THEN LEAST(
            COALESCE(products.pieces, 0) + (COALESCE(products.box, 0) * COALESCE(NULLIF(products.pieces_per_box, 0), 12)),
            COALESCE((
                SELECT SUM(sellable_batch.current_quantity)
                FROM product_batches sellable_batch
                WHERE sellable_batch.product_id = products.id
                  AND sellable_batch.recalled = 0
                  AND sellable_batch.expiration_date >= CURDATE()
            ), 0)
        )
        ELSE COALESCE(products.pieces, 0) + (COALESCE(products.box, 0) * COALESCE(NULLIF(products.pieces_per_box, 0), 12))
    END";
    if (!$conn->query("UPDATE products SET status = IF($stockExpression > 0, 'active', 'inactive') WHERE LOWER(TRIM(status)) <> IF($stockExpression > 0, 'active', 'inactive')")) {
        error_log('Unable to synchronize product status with stock: ' . $conn->error);
        http_response_code(500);
        exit('Unable to synchronize product status with available stock.');
    }
    if ($hasCategory && $categoryFilter !== '') {
        $escapedCategory = $conn->real_escape_string($categoryFilter);
        if (strcasecmp($categoryFilter, 'Prescription Medicine') === 0) {
            $productWhere .= ' AND prescription_required = 1';
        } else {
            $productWhere .= " AND LOWER(TRIM(category)) = LOWER(TRIM('$escapedCategory'))";
        }
    }
    if ($stockFilter === 'out') { $productWhere .= " AND $stockExpression <= 0"; }
    if ($stockFilter === 'low') { $productWhere .= " AND $stockExpression > 0 AND $stockExpression < 20"; }
    if ($stockFilter === 'active') { $productWhere .= " AND $stockExpression >= 20"; }
    if ($productStatusFilter !== '') { $productWhere .= " AND LOWER(status) = '" . $conn->real_escape_string($productStatusFilter) . "'"; }
    if ($searchFilter !== '') {
        $searchTerm = '%' . $conn->real_escape_string($searchFilter) . '%';
        $searchConditions = array_map(
            static function ($column) use ($searchTerm) {
                return "`$column` LIKE '$searchTerm'";
            },
            $searchableProductColumns
        );
        $productWhere .= ' AND (' . implode(' OR ', $searchConditions) . ')';
    }
    if ($editProductId > 0) {
        $productWhere .= " AND id = $editProductId";
    }

    $totalRes = $conn->query("SELECT COUNT(*) as c FROM products WHERE $productWhere");
    if ($totalRes) { $totalProducts = $totalRes->fetch_assoc()['c'] ?? 0; }
    $activeRes = $conn->query("SELECT COUNT(*) as c FROM products WHERE LOWER(status)='active'");
    if ($activeRes) { $activeProducts = $activeRes->fetch_assoc()['c'] ?? 0; }

    $inactiveRes = $conn->query("SELECT COUNT(*) as c FROM products WHERE LOWER(status)!='active'");
    if ($inactiveRes) { $inactiveProducts = $inactiveRes->fetch_assoc()['c'] ?? 0; }

    if ($hasCategory) {
        $catRes = $conn->query("SELECT COUNT(DISTINCT category) as c FROM products");
        if ($catRes) $categoryCount = $catRes->fetch_assoc()['c'] ?? 0;
    }

    $categoryOptions = [
        ['value' => 'OTC Medicine', 'label' => 'OTC Medicine'],
        ['value' => 'Prescription Medicine', 'label' => 'Prescription'],
        ['value' => 'Vitamins & Supplements', 'label' => 'Vitamins & Supplements'],
        ['value' => 'Herbal Medicine', 'label' => 'Herbal Medicine'],
        ['value' => 'Baby & Infant Products', 'label' => 'Baby & Infant Products'],
        ['value' => 'Topical & Personal Care', 'label' => 'Topical & Personal Care'],
        ['value' => 'Medical Supplies', 'label' => 'Medical Supplies'],
        ['value' => 'Hygiene & Personal Care', 'label' => 'Hygiene & Personal Care'],
    ];
    $categoryOptionsByValue = [];
    foreach ($categoryOptions as $categoryOption) {
        $categoryOptionsByValue[strtolower($categoryOption['value'])] = $categoryOption;
    }
    if ($hasCategory) {
        $actualCategories = $conn->query("SELECT DISTINCT TRIM(category) AS category FROM products WHERE category IS NOT NULL AND TRIM(category) <> '' ORDER BY category ASC");
        if ($actualCategories) {
            while ($actualCategory = $actualCategories->fetch_assoc()) {
                $categoryValue = trim((string)($actualCategory['category'] ?? ''));
                if ($categoryValue === '') {
                    continue;
                }
                $categoryKey = strtolower($categoryValue);
                $canonicalCategory = $categoryKey === 'prescription medicine' ? 'Prescription Medicine' : $categoryValue;
                $categoryOptionsByValue[strtolower($canonicalCategory)] = [
                    'value' => $canonicalCategory,
                    'label' => $canonicalCategory === 'Prescription Medicine' ? 'Prescription' : $canonicalCategory
                ];
            }
        } elseif ($categoryFilter !== '') {
            error_log('Product category options query failed: ' . $conn->error);
        }
    }
    $categoryOptions = array_values($categoryOptionsByValue);

    $perPage = 10;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $totalPages = max(1, (int)ceil($totalProducts / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $result = $conn->query("SELECT products.*, $stockExpression AS available_stock_quantity, (SELECT lot_number FROM product_batches WHERE product_batches.product_id = products.id ORDER BY product_batches.expiration_date ASC, product_batches.id ASC LIMIT 1) AS batch_lot_number, (SELECT expiration_date FROM product_batches WHERE product_batches.product_id = products.id ORDER BY product_batches.expiration_date ASC, product_batches.id ASC LIMIT 1) AS batch_expiration_date, (SELECT manufacturing_date FROM product_batches WHERE product_batches.product_id = products.id ORDER BY product_batches.expiration_date ASC, product_batches.id ASC LIMIT 1) AS batch_manufacturing_date FROM products WHERE $productWhere ORDER BY id DESC LIMIT $perPage OFFSET $offset");
    $pageQuery = function ($pageNumber) use ($categoryFilter, $stockFilter, $productStatusFilter, $searchFilter) {
        return '?' . http_build_query([
            'page' => $pageNumber,
            'category' => $categoryFilter,
            'stock_status' => $stockFilter,
            'product_status' => $productStatusFilter,
            'search' => $searchFilter
        ]);
    };

    $eligibleUserRows = [];
    if ($hasOrderPrescriptionStatus || $hasCustomerChatMessages || $hasPrescriptions) {
        $eligibilityQueries = [];
        if ($hasPrescriptions) {
            $eligibilityQueries[] = "SELECT u.id, u.username, u.email, p.id AS eligible_product_id
                FROM users u
                JOIN prescriptions pr ON pr.user_id = u.id AND pr.status = 'approved'
                CROSS JOIN products p
                WHERE u.role = 'user' AND p.prescription_required = 1";
        }
        if ($hasOrderPrescriptionStatus) {
            $eligibilityQueries[] = "SELECT u.id, u.username, u.email, oi.product_id AS eligible_product_id
                FROM users u
                JOIN orders o ON o.user_id = u.id AND o.prescription_status = 'accepted'
                JOIN order_items oi ON oi.order_id = o.id
                WHERE u.role = 'user' AND oi.product_id IS NOT NULL";
        }
        if ($hasCustomerChatMessages) {
            $eligibilityQueries[] = "SELECT u.id, u.username, u.email, p.id AS eligible_product_id
                FROM users u
                JOIN customer_chat_messages c ON c.user_id = u.id
                    AND c.prescription_status = 'accepted'
                    AND c.prescription_image IS NOT NULL
                JOIN products p ON p.prescription_required = 1
                    AND (c.product_id = p.id OR (CONVERT(LOWER(TRIM(c.medicine_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(LOWER(TRIM(p.product_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci))
                WHERE u.role = 'user'";
        }
        $eligibleUsers = $eligibilityQueries
            ? $conn->query('SELECT DISTINCT eligible.id, eligible.username, eligible.email, eligible_product_id FROM (' . implode(' UNION ', $eligibilityQueries) . ') AS eligible ORDER BY eligible.username ASC')
            : false;
        if ($eligibleUsers) {
            while ($eligibleUser = $eligibleUsers->fetch_assoc()) {
                $eligibleProductId = (int)$eligibleUser['eligible_product_id'];
                if ($eligibleProductId < 1) {
                    continue;
                }
                $eligibleUserRows[] = $eligibleUser;
            }
        }
    }
    ?>

    <div class="page-header">
        <div>
            <h1>Products</h1>
        </div>
    </div>

    <?php if (!empty($_GET['product_error'])): ?>
        <div class="low-stock-alarm" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><div><strong>Product was not added</strong><span><?php echo htmlspecialchars($_GET['product_error']); ?></span></div></div>
    <?php endif; ?>
    <?php if (isset($_GET['shared']) && $_GET['shared'] === '1'): ?>
        <div role="status" style="margin-bottom:14px;padding:11px 13px;border-radius:7px;background:#e9f8ef;color:#23784c;font-size:12px;">Product shared in Customer Chat with the quantity limit you set.</div>
    <?php elseif (isset($_GET['shared']) && $_GET['shared'] === '0'): ?>
        <div role="alert" style="margin-bottom:14px;padding:11px 13px;border-radius:7px;background:#ffe8ed;color:#b4233d;font-size:12px;"><?php echo htmlspecialchars($_SESSION['share_error'] ?? 'Product could not be shared.'); unset($_SESSION['share_error']); ?></div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="summary-grid">
        <a class="summary-card summary-card-link total" href="products.php" aria-label="Show all products">
            <div class="summary-icon"><i class="bi bi-box-seam"></i></div>
            <div><div class="summary-label">Total Products</div><div class="summary-value"><?php echo number_format($totalProducts); ?></div><div class="summary-note">All products in store</div></div>
        </a>
        <a class="summary-card summary-card-link active-products" href="?product_status=active" data-product-status="active" aria-label="Show active products">
            <div class="summary-icon"><i class="bi bi-box-seam"></i></div>
            <div><div class="summary-label">Active Products</div><div class="summary-value"><?php echo number_format($activeProducts); ?></div><div class="summary-note">Currently available</div></div>
        </a>
        <a class="summary-card summary-card-link inactive-products" href="?product_status=inactive" data-product-status="inactive" aria-label="Show inactive products">
            <div class="summary-icon"><i class="bi bi-eye-slash"></i></div>
            <div><div class="summary-label">Inactive Products</div><div class="summary-value"><?php echo number_format($inactiveProducts); ?></div><div class="summary-note">Not visible to customers</div></div>
        </a>
        <a class="summary-card summary-card-link categories" href="#categoryFilter" id="categoriesCard" aria-label="Open category filter">
            <div class="summary-icon"><i class="bi bi-tag-fill"></i></div>
            <div><div class="summary-label">Categories</div><div class="summary-value"><?php echo number_format($categoryCount); ?></div><div class="summary-note">Product categories</div></div>
        </a>
    </div>

    <div class="products-panel" id="product-table">
        <div class="table-toolbar">
            <form method="GET" class="filter-panel open" id="filterPanel">
                <div class="product-search"><i class="bi bi-search"></i><input id="productSearch" name="search" type="text" value="<?php echo htmlspecialchars($searchFilter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by product name, SKU, or barcode..." aria-label="Search by product name, SKU, or barcode"><button type="button" id="clearProductSearch" aria-label="Clear search" title="Clear search" style="<?php echo $searchFilter !== '' ? 'display:inline-flex;' : 'none'; ?>">&times;</button><button type="button" id="productBarcodeScanTrigger" aria-label="Search by barcode" title="Scan barcode to search"><i class="bi bi-upc-scan"></i></button></div>
                <select id="categoryFilter" name="category" aria-label="Category" <?php echo !$hasCategory ? 'disabled' : ''; ?>>
                    <option value="">All Categories</option>
                    <?php foreach ($categoryOptions as $categoryOption): ?>
                        <option value="<?php echo htmlspecialchars($categoryOption['value']); ?>" <?php echo $categoryFilter === $categoryOption['value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($categoryOption['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="stock_status" aria-label="Stock status">
                    <option value="">All stock statuses</option>
                    <option value="active" <?php echo $stockFilter === 'active' ? 'selected' : ''; ?>>In Stock</option>
                    <option value="low" <?php echo $stockFilter === 'low' ? 'selected' : ''; ?>>Low Stock</option>
                    <option value="out" <?php echo $stockFilter === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                </select>
                <input type="hidden" name="page" value="1">
                <input type="hidden" name="product_status" value="<?php echo htmlspecialchars($productStatusFilter, ENT_QUOTES, 'UTF-8'); ?>">
            </form>
            <div class="toolbar-actions"><button type="button" class="ai-scan-button" id="aiScanButton" title="Take a product photo and generate details"><i class="bi bi-camera"></i> Scan AI</button><button type="button" class="add-button" id="addProductTrigger"><i class="bi bi-plus-lg"></i> Add Product</button><a href="?export=csv" class="export-button" title="Export to CSV"><i class="bi bi-box-arrow-up"></i> Export</a></div>
        </div>
        <div class="table-responsive">
            <table class="product-table">
                <thead>
                    <tr><th>Product</th><th>Category</th><th>Price</th><th>Pieces per Box</th><th>Pieces</th><th>Stock</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php if ($result && $result !== false && $result->num_rows > 0):
                    while($row = $result->fetch_assoc()):
                        $image = $row['image'] ?? '';
                        $img = ($image && strpos($image, 'http') === 0) ? $image : ($image ? 'uploads/'.$image : 'default.png');
                        $pricePiece = $row['price_piece'] ?? 0;
                        $piecesPerBox = max(1, (int)($row['pieces_per_box'] ?? 12));
                        $discountedBoxPrice = round((float)($pricePiece > 0 ? $pricePiece : ($row['price'] ?? 0)) * $piecesPerBox * 0.98, 2);
                        $storedStock = (int)($row['pieces'] ?? 0) + ((int)($row['box'] ?? 0) * $piecesPerBox);
                        $stock = isset($row['available_stock_quantity'])
                            ? (int)$row['available_stock_quantity']
                            : $storedStock;
                        $pieces = $stock % max(1, $piecesPerBox);
                        $status = strtolower($row['status'] ?? '');
                        $stockStatus = $stock <= 0 ? 'out' : ($stock < 20 ? 'low' : 'active');
                ?>
                    <tr>
                        <td><div class="product-cell"><div class="prod-thumb"><img src="<?php echo $img; ?>" onerror="this.src='default.png'" alt="<?php echo htmlspecialchars($row['product_name']); ?>"></div><div><strong><?php echo htmlspecialchars($row['product_name']); ?></strong><span>SKU: <?php echo isset($row['sku']) ? htmlspecialchars($row['sku']) : 'MED-'.$row['id']; ?></span></div></div></td>
                        <td><?php if(!empty($row['category'])): ?><span class="category-pill"><?php echo htmlspecialchars($row['category']); ?></span><?php else: ?>—<?php endif; ?></td>
                        <td>₱<?php echo number_format($pricePiece > 0 ? $pricePiece : ($row['price'] ?? 0), 2); ?></td>
                        <td><?php echo number_format($piecesPerBox); ?></td>
                        <td><?php echo number_format($pieces); ?></td>
                        <td><?php echo number_format($stock); ?><?php if ($stockStatus !== 'active'): ?> <i class="bi bi-exclamation-triangle-fill stock-warning <?php echo $stockStatus; ?>" role="img" aria-label="<?php echo $stockStatus === 'out' ? 'Out of stock' : 'Low stock'; ?>" title="<?php echo $stockStatus === 'out' ? 'Out of stock' : 'Low stock'; ?>"></i><?php endif; ?></td>
                        <td><span class="product-status <?php echo $status === 'active' ? 'active' : 'inactive'; ?>"><?php echo $status === 'active' ? 'Active' : 'Inactive'; ?></span></td>
                        <td class="text-end">
                            <button type="button" class="action-button edit-trigger" title="Edit" aria-label="Edit product" data-id="<?php echo (int)$row['id']; ?>" data-name="<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>" data-sku="<?php echo htmlspecialchars($row['sku'] ?? 'MED-'.$row['id'], ENT_QUOTES); ?>" data-barcode="<?php echo htmlspecialchars($row['barcode'] ?? '', ENT_QUOTES); ?>" data-category="<?php echo htmlspecialchars($row['category'] ?? '', ENT_QUOTES); ?>" data-lot-number="<?php echo htmlspecialchars($row['batch_lot_number'] ?? '', ENT_QUOTES); ?>" data-expiration-date="<?php echo htmlspecialchars($row['batch_expiration_date'] ?? '', ENT_QUOTES); ?>" data-manufacturing-date="<?php echo htmlspecialchars($row['batch_manufacturing_date'] ?? '', ENT_QUOTES); ?>" data-has-batch="<?php echo !empty($row['batch_lot_number']) && !empty($row['batch_expiration_date']) ? '1' : '0'; ?>" data-prescription-required="<?php echo (int)($row['prescription_required'] ?? 0); ?>" data-status="<?php echo htmlspecialchars(strtolower($row['status'] ?? 'active'), ENT_QUOTES); ?>" data-price-piece="<?php echo htmlspecialchars($pricePiece > 0 ? $pricePiece : ($row['price'] ?? 0), ENT_QUOTES); ?>" data-price-box="<?php echo htmlspecialchars($discountedBoxPrice, ENT_QUOTES); ?>" data-pieces="<?php echo (int)($row['pieces'] ?? 0); ?>" data-box="<?php echo (int)($row['box'] ?? 0); ?>" data-pieces-per-box="<?php echo (int)($row['pieces_per_box'] ?? 12); ?>" data-min-pieces="<?php echo (int)($row['min_pieces'] ?? 1); ?>" data-min-boxes="<?php echo (int)($row['min_boxes'] ?? 0); ?>" data-quantity="<?php echo $storedStock; ?>" data-description="<?php echo htmlspecialchars($row['description'] ?? '', ENT_QUOTES); ?>" data-ingredients="<?php echo htmlspecialchars($row['ingredients'] ?? '', ENT_QUOTES); ?>" data-benefits="<?php echo htmlspecialchars($row['benefits'] ?? '', ENT_QUOTES); ?>" data-usage="<?php echo htmlspecialchars($row['usage_instructions'] ?? '', ENT_QUOTES); ?>" data-brand="<?php echo htmlspecialchars($row['brand'] ?? '', ENT_QUOTES); ?>" data-form="<?php echo htmlspecialchars($row['product_form'] ?? '', ENT_QUOTES); ?>" data-serving-size="<?php echo htmlspecialchars($row['serving_size'] ?? '', ENT_QUOTES); ?>" data-image="<?php echo htmlspecialchars($img, ENT_QUOTES); ?>"><i class="bi bi-pencil"></i></button>
                            <?php if ($isPharmacist && !empty($row['prescription_required'])): ?>
                                <button type="button" class="action-button share-trigger" title="Share with customer" aria-label="Share prescription product with customer" data-id="<?php echo (int)$row['id']; ?>"><i class="bi bi-share"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; else: ?><tr><td colspan="8" class="empty-state">No products found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer"><span>Showing <?php echo $totalProducts ? $offset + 1 : 0; ?> to <?php echo min($offset + $perPage, $totalProducts); ?> of <?php echo $totalProducts; ?> products</span><?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Product pages"><a href="<?php echo $pageQuery(max(1, $page - 1)); ?>">‹</a><?php $paginationStart = max(1, min($page - 1, $totalPages - 2)); $paginationEnd = min($totalPages, $paginationStart + 2); for ($pageNumber = $paginationStart; $pageNumber <= $paginationEnd; $pageNumber++): ?><a class="<?php echo $pageNumber === $page ? 'current' : ''; ?>" href="<?php echo $pageQuery($pageNumber); ?>"><?php echo $pageNumber; ?></a><?php endfor; ?><?php if ($totalPages > $paginationEnd): ?><span class="pagination-dots">…</span><a href="<?php echo $pageQuery($totalPages); ?>"><?php echo $totalPages; ?></a><?php endif; ?><a href="<?php echo $pageQuery(min($totalPages, $page + 1)); ?>">›</a></nav><?php endif; ?></div>
    </div>

    </div>

    <div class="share-modal" id="shareModal" role="dialog" aria-modal="true" aria-labelledby="shareModalTitle">
        <div class="share-dialog"><div class="edit-modal-header"><h2 id="shareModalTitle">Send Prescription Product</h2><button type="button" class="modal-close" id="closeShareModal" aria-label="Close">&times;</button></div><form method="post" class="share-form"><input type="hidden" name="share_product" value="1"><input type="hidden" name="product_id" id="shareProductId"><label for="shareUserId">Send to customer</label><select name="user_id" id="shareUserId" required><option value="">Select customer with approved prescription</option><?php foreach ($eligibleUserRows as $eligibleUser): ?><option value="<?php echo (int)$eligibleUser['id']; ?>" data-product-id="<?php echo (int)$eligibleUser['eligible_product_id']; ?>"><?php echo htmlspecialchars($eligibleUser['username'] . ' - ' . $eligibleUser['email']); ?></option><?php endforeach; ?></select><label for="shareQuantityLimit">Maximum quantity available to this customer</label><input type="number" name="quantity_limit" id="shareQuantityLimit" min="1" required placeholder="Enter allowed quantity"><p>Product access stays available until the quantity you set has been purchased.</p><button type="submit" class="modal-button save">Send to Customer Chat</button></form></div>
    </div>

    <div class="edit-modal" id="editModal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="edit-dialog">
            <div class="edit-modal-header"><h2 id="editModalTitle">Edit Product</h2><button type="button" class="modal-close" id="closeEditModal" aria-label="Close">&times;</button></div>
            <form class="edit-form" id="editProductForm" method="POST" enctype="multipart/form-data">
                <nav class="modal-stepper" aria-label="Product form steps">
                    <div class="modal-step active" data-step-indicator="0" aria-current="step"><span class="step-number">1</span><span>Product &amp; Stock Details</span></div>
                    <div class="modal-step" data-step-indicator="1"><span class="step-number">2</span><span>Inventory &amp; Pricing</span></div>
                    <div class="modal-step" data-step-indicator="2"><span class="step-number">3</span><span>Additional Information</span></div>
                </nav>
                <div class="form-page active" data-form-page="0">
                    <h3 class="form-page-title">Product &amp; Stock Details</h3>
                    <section class="form-section">
                        <div class="form-section-heading"><span class="form-section-icon"><i class="bi bi-box-seam"></i></span><div><strong>Product Information</strong><small>Basic details about the product</small></div></div>
                        <div class="form-section-body"><div class="edit-form-grid order-limits-grid">
                            <div class="edit-field"><label for="editName">Product Name <b>*</b></label><input id="editName" name="product_name" required></div>
                            <div class="edit-field"><label for="editCategory">Category <b>*</b></label><input id="editCategory" name="category" list="productCategoryOptions" maxlength="100" placeholder="Choose or type a category" required><datalist id="productCategoryOptions"><?php foreach ($categoryOptions as $categoryOption): ?><option value="<?php echo htmlspecialchars($categoryOption['value'], ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?></datalist><input type="file" id="aiScanInput" accept="image/jpeg,image/png,image/webp" multiple hidden><span class="ai-scan-status" id="aiScanStatus" aria-live="polite"></span></div>
                            <div class="edit-field"><label for="editSku">SKU / Item ID</label><input id="editSku" name="sku" placeholder="e.g., MED-001"></div>
                            <div class="edit-field"><label for="editBarcode">Barcode</label><div class="barcode-input-row"><input id="editBarcode" name="barcode" inputmode="numeric" placeholder="Scan or enter barcode"><button class="barcode-scan-trigger" type="button" id="barcodeScanTrigger" title="Scan barcode with camera" aria-label="Scan barcode with camera"><i class="bi bi-camera"></i></button></div></div>
                            <div class="edit-field"><label for="editLotNumber">Lot Number <b>*</b></label><input id="editLotNumber" name="lot_number" placeholder="LOT-2026-001"></div>
                            <div class="edit-field"><label for="editManufacturingDate">MFG Date <b>*</b></label><input id="editManufacturingDate" name="manufacturing_date" type="date" max="<?php echo date('Y-m-d'); ?>"></div>
                            <div class="edit-field"><label for="editExpirationDate">Expiration Date <b>*</b></label><input id="editExpirationDate" name="expiration_date" type="date" min="<?php echo date('Y-m-d'); ?>"><small class="batch-expiry-warning" id="editBatchExpiryWarning" role="alert" hidden></small></div>
                            <small class="batch-expiry-hint" id="editBatchExpiryHint" hidden>No batch expiry is saved for this product yet. Enter its lot number and expiration date to add it.</small>
                            <div class="edit-field prescription-check"><label><input type="checkbox" name="prescription_required" value="1"><span>Requires prescription</span></label></div>
                        </div></div>
                    </section>
                </div>
                <div class="form-page" data-form-page="1">
                    <h3 class="form-page-title">Inventory &amp; Pricing</h3>
                    <section class="form-section">
                        <div class="form-section-heading"><span class="form-section-icon"><i class="bi bi-boxes"></i></span><div><strong>Inventory &amp; Pricing</strong><small>Stock quantity, piece price and box price (2% off)</small></div></div>
                        <div class="form-section-body"><div class="edit-form-grid inventory-pricing-grid">
                            <div class="edit-field"><label for="editQuantity">Total Stock (pieces) <b>*</b></label><input id="editQuantity" name="quantity_display" type="number" min="0" step="1" value="0" required><small class="stock-quantity-hint">Enter the total number of individual pieces in stock.</small></div>
                            <div class="edit-field"><label for="editPiecesPerBox">Pieces per Box <b>*</b></label><input id="editPiecesPerBox" name="pieces_per_box" type="number" min="1" value="12" required><small>2% off for full-box purchases.</small></div>
                            <div class="edit-field"><label for="editPrice">Price (per piece) <b>*</b></label><input id="editPrice" name="price_piece" type="number" min="0" step="0.01" required></div>
                            <input id="editPieces" name="pieces" type="hidden" value="0">
                            <input id="editBox" name="box" type="hidden" value="0">
                        </div></div>
                    </section>
                    <section class="form-section">
                        <div class="form-section-heading"><span class="form-section-icon"><i class="bi bi-shield-check"></i></span><div><strong>Order Limits / Stock Alert</strong><small>Minimum order quantities and low stock threshold</small></div></div>
                        <div class="form-section-body"><div class="edit-form-grid">
                            <div class="edit-field"><label for="editMinPieces">Min Pieces per Order</label><input id="editMinPieces" name="min_pieces" type="number" min="0" value="1"></div>
                            <div class="edit-field"><label for="editMinBoxes">Min Boxes per Order</label><input id="editMinBoxes" name="min_boxes" type="number" min="0" value="0"></div>
                            <div class="edit-field"><label for="editLowStockThreshold">Low Stock Threshold</label><input id="editLowStockThreshold" name="low_stock_threshold" type="number" min="0" value="20" required></div>
                        </div></div>
                    </section>
                </div>
                <div class="form-page" data-form-page="2">
                    <h3 class="form-page-title">Additional Information</h3>
                    <section class="form-section">
                        <div class="form-section-heading"><span class="form-section-icon"><i class="bi bi-card-text"></i></span><div><strong>Product Details</strong><small>Brand, description, ingredients and usage</small></div></div>
                        <div class="form-section-body"><div class="edit-form-grid additional-details-grid">
                            <div class="edit-field"><label for="editBrand">Brand</label><input id="editBrand" name="brand"></div>
                            <div class="edit-field"><label for="editFormType">Form</label><input id="editFormType" name="product_form" placeholder="Tablet, capsule, syrup"></div>
                            <div class="edit-field"><label for="editServingSize">Volume</label><input id="editServingSize" name="serving_size"></div>
                            <div class="edit-field full"><label for="editDescription">Main Product Text</label><textarea id="editDescription" name="description" placeholder="Product description shown beside the product"></textarea></div>
                            <div class="edit-field full"><label for="editIngredients">Ingredients</label><textarea id="editIngredients" name="ingredients"></textarea></div>
                            <div class="edit-field full"><label for="editBenefits">Product Benefits</label><textarea id="editBenefits" name="benefits" placeholder="List the product benefits"></textarea></div>
                            <div class="edit-field full"><label for="editUsage">Usage</label><textarea id="editUsage" name="usage_instructions"></textarea></div>
                        </div></div>
                    </section>
                    <section class="form-section">
                        <div class="form-section-heading"><span class="form-section-icon"><i class="bi bi-images"></i></span><div><strong>Product Images</strong><small>Add product photos or image URLs</small></div></div>
                        <div class="form-section-body"><div class="edit-form-grid">
                            <div class="edit-field full"><label for="editImages">Upload Images (up to 4)</label><div class="edit-image-row"><img id="editImagePreview" class="edit-image-preview" alt="Current product"><div class="edit-upload"><input id="editImages" name="images[]" type="file" accept="image/png,image/jpeg,image/webp" multiple><small>Select up to 4 images, maximum 5 MB each.</small></div></div></div>
                            <div class="edit-field"><label for="editImageUrl1">Image URL 1</label><input id="editImageUrl1" name="image_url_1" type="url" placeholder="https://example.com/image-1.jpg"></div>
                            <div class="edit-field"><label for="editImageUrl2">Image URL 2</label><input id="editImageUrl2" name="image_url_2" type="url" placeholder="https://example.com/image-2.jpg"></div>
                            <div class="edit-field"><label for="editImageUrl3">Image URL 3</label><input id="editImageUrl3" name="image_url_3" type="url" placeholder="https://example.com/image-3.jpg"></div>
                            <div class="edit-field"><label for="editImageUrl4">Image URL 4</label><input id="editImageUrl4" name="image_url_4" type="url" placeholder="https://example.com/image-4.jpg"></div>
                        </div></div>
                    </section>
                </div>
                <input type="hidden" name="add_product" id="addProductFlag" value="0">
                <input type="hidden" name="duplicate_confirmed" id="duplicateConfirmed" value="0">
                <div class="edit-modal-footer"><button type="button" class="modal-button" id="cancelEditModal">Cancel</button><button type="button" class="modal-button" id="editBackButton" hidden>Back</button><button type="button" class="modal-button save" id="editNextButton">Next</button><button type="submit" name="update" id="editSubmitButton" class="modal-button save" hidden><i class="bi bi-save"></i> Save Changes</button></div>
            </form>
        </div>
    </div>

<div class="barcode-scanner-modal" id="barcodeScannerModal" role="dialog" aria-modal="true" aria-labelledby="barcodeScannerTitle">
    <div class="barcode-scanner-dialog">
        <div class="barcode-scanner-header"><h2 id="barcodeScannerTitle">Scan product barcode</h2><button type="button" class="barcode-scanner-close" id="closeBarcodeScanner" aria-label="Close scanner"><i class="bi bi-x-lg"></i></button></div>
        <p class="barcode-scanner-status" id="barcodeScannerStatus" role="status" aria-live="polite" data-state="idle">Center the barcode, hold steady, and avoid glare.</p>
        <select class="barcode-camera-select" id="barcodeCameraSelect" aria-label="Select camera"></select>
        <div class="barcode-camera-frame"><video id="barcodeScannerVideo" playsinline muted></video><div class="barcode-scan-guide" aria-hidden="true"></div></div>
        <div class="barcode-scanner-actions"><button type="button" id="cancelBarcodeScan">Cancel</button></div>
    </div>
</div>

<script src="https://unpkg.com/@zxing/browser@0.1.5"></script>
<script>
if (document.getElementById("pieces_per_box")) {
let piecesPerBoxInput = document.getElementById("pieces_per_box");
let pieces = document.getElementById("pieces");
let box = document.getElementById("box");
let quantity = document.getElementById("quantity");

function computeStock(){
    let p = parseInt(pieces.value) || 0;
    let b = parseInt(box.value) || 0;
    let ppb = parseInt(piecesPerBoxInput.value) || 12;
    quantity.value = p + (b * ppb);
}

function computeFromQuantity(){
    let q = parseInt(quantity.value) || 0;
    let ppb = parseInt(piecesPerBoxInput.value) || 12;
    let b = Math.floor(q / ppb);
    let p = q % ppb;
    box.value = b;
    pieces.value = p;
}

pieces.addEventListener("input", computeStock);
box.addEventListener("input", computeStock);
piecesPerBoxInput.addEventListener("input", computeStock);
quantity.addEventListener("input", computeFromQuantity);

// IMAGE PREVIEW
let input = document.getElementById("image_link");
let preview = document.getElementById("preview");

input.addEventListener("input", function() {
    let url = this.value.trim();

    if (url !== "") {
        preview.src = url;
        preview.style.display = "block";
    } else {
        preview.style.display = "none";
    }
});

preview.onerror = function() {
    preview.style.display = "none";
};
}

const editModal = document.getElementById('editModal');
const editForm = document.getElementById('editProductForm');
const expirationDateInput = document.getElementById('editExpirationDate');
const expirationWarning = document.getElementById('editBatchExpiryWarning');
const minimumExpirationDate = '<?php echo date('Y-m-d', strtotime('+30 days')); ?>';
const minimumExpirationMessage = 'Ang expiration date ay dapat 30 days pataas mula sa araw ngayon.';
const updateExpirationWarning = () => {
    const selectedDate = expirationDateInput.value;
    const belowMinimum = selectedDate !== '' && selectedDate < minimumExpirationDate;
    expirationWarning.hidden = !belowMinimum;
    expirationWarning.textContent = belowMinimum ? minimumExpirationMessage : '';
    return belowMinimum;
};
const showMinimumExpirationNote = () => {
    expirationWarning.textContent = minimumExpirationMessage;
    expirationWarning.hidden = false;
};
expirationDateInput.setAttribute('min', minimumExpirationDate);
expirationDateInput.addEventListener('focus', showMinimumExpirationNote);
expirationDateInput.addEventListener('click', showMinimumExpirationNote);
expirationDateInput.addEventListener('input', updateExpirationWarning);
expirationDateInput.addEventListener('change', updateExpirationWarning);
expirationDateInput.addEventListener('blur', updateExpirationWarning);
editForm.addEventListener('submit', (event) => {
    const expiryIsBelowMinimum = updateExpirationWarning();
    const dateWasChanged = expirationDateInput.value !== expirationDateInput.dataset.originalDate;
    if (expiryIsBelowMinimum && (addProductFlag.value === '1' || dateWasChanged)) {
        event.preventDefault();
        expirationDateInput.focus();
    }
});
const barcodeScannerModal = document.getElementById('barcodeScannerModal');
const barcodeScannerVideo = document.getElementById('barcodeScannerVideo');
const barcodeScannerStatus = document.getElementById('barcodeScannerStatus');
const barcodeCameraSelect = document.getElementById('barcodeCameraSelect');
const barcodeField = document.getElementById('editBarcode');
let barcodeScanTargetField = barcodeField;
let barcodeScannerStream = null;
let barcodeAudioContext = null;
let barcodeScanSession = 0;
let barcodeScanHelpTimer = null;
let barcodeCameraStartTimer = null;
let barcodeFrameTimer = null;
const setBarcodeScannerStatus = (message, state) => {
    barcodeScannerStatus.textContent = message;
    barcodeScannerStatus.dataset.state = state;
};
const playBarcodeScanBeep = async () => {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) {
        console.warn('Barcode scan succeeded, but Web Audio is not supported in this browser.');
        return;
    }
    try {
        if (!barcodeAudioContext) barcodeAudioContext = new AudioContextClass();
        if (barcodeAudioContext.state === 'suspended') await barcodeAudioContext.resume();
        const startTime = barcodeAudioContext.currentTime;
        const oscillator = barcodeAudioContext.createOscillator();
        const gain = barcodeAudioContext.createGain();
        oscillator.type = 'triangle';
        oscillator.frequency.setValueAtTime(620, startTime);
        gain.gain.setValueAtTime(0.001, startTime);
        gain.gain.exponentialRampToValueAtTime(0.8, startTime + 0.02);
        gain.gain.setValueAtTime(0.8, startTime + 0.24);
        gain.gain.exponentialRampToValueAtTime(0.001, startTime + 0.38);
        oscillator.connect(gain);
        gain.connect(barcodeAudioContext.destination);
        oscillator.start(startTime);
        oscillator.stop(startTime + 0.39);
    } catch (error) {
        console.warn('Barcode scan succeeded, but the confirmation beep could not play:', error);
    }
};
const stopBarcodeScanner = () => {
    barcodeScanSession++;
    if (barcodeCameraStartTimer !== null) {
        window.clearTimeout(barcodeCameraStartTimer);
        barcodeCameraStartTimer = null;
    }
    if (barcodeScanHelpTimer !== null) {
        window.clearTimeout(barcodeScanHelpTimer);
        barcodeScanHelpTimer = null;
    }
    if (barcodeFrameTimer !== null) {
        window.clearTimeout(barcodeFrameTimer);
        barcodeFrameTimer = null;
    }
    if (barcodeScannerStream) {
        barcodeScannerStream.getTracks().forEach((track) => track.stop());
        barcodeScannerStream = null;
    }
    barcodeScannerVideo.pause();
    barcodeScannerVideo.srcObject = null;
};
const closeBarcodeScanner = () => {
    stopBarcodeScanner();
    barcodeScannerModal.classList.remove('open');
};
const startBarcodeScanner = async (requestedDeviceId = '') => {
    stopBarcodeScanner();
    const session = ++barcodeScanSession;
    barcodeScannerModal.classList.add('open');
    setBarcodeScannerStatus('Starting camera...', 'starting');
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (AudioContextClass && !barcodeAudioContext) {
        try {
            barcodeAudioContext = new AudioContextClass();
            barcodeAudioContext.resume().catch((error) => {
                console.warn('Unable to enable barcode confirmation sound:', error);
            });
        } catch (error) {
            console.warn('Unable to enable barcode confirmation sound:', error);
        }
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setBarcodeScannerStatus('Camera access requires HTTPS or localhost.', 'error');
        return;
    }
    if (!window.ZXingBrowser || !window.ZXingBrowser.BrowserMultiFormatReader) {
        setBarcodeScannerStatus('The barcode scanner did not load. Check your internet connection and reload the page.', 'error');
        return;
    }

    barcodeCameraStartTimer = window.setTimeout(() => {
        if (session !== barcodeScanSession || barcodeScannerStream) return;
        stopBarcodeScanner();
        setBarcodeScannerStatus('The camera did not respond. Allow camera access in your browser and Windows settings, then try again.', 'error');
    }, 12000);

    try {
        const permissionStream = await navigator.mediaDevices.getUserMedia({ audio: false, video: true });
        if (barcodeCameraStartTimer !== null) {
            window.clearTimeout(barcodeCameraStartTimer);
            barcodeCameraStartTimer = null;
        }
        if (session !== barcodeScanSession) {
            permissionStream.getTracks().forEach((track) => track.stop());
            return;
        }
        let cameras;
        try {
            cameras = (await navigator.mediaDevices.enumerateDevices())
                .filter((device) => device.kind === 'videoinput');
        } finally {
            permissionStream.getTracks().forEach((track) => track.stop());
        }
        if (session !== barcodeScanSession) return;
        if (!cameras.length) throw new Error('No camera was found. Open Camo Studio and enable the Camo Camera.');

        barcodeCameraSelect.replaceChildren();
        cameras.forEach((camera, index) => {
            const option = document.createElement('option');
            option.value = camera.deviceId;
            option.textContent = camera.label || `Camera ${index + 1}`;
            barcodeCameraSelect.appendChild(option);
        });
        const camoCamera = cameras.find((camera) => /camo/i.test(camera.label));
        const selectedCamera = cameras.find((camera) => camera.deviceId === requestedDeviceId)
            || camoCamera
            || cameras[0];
        barcodeCameraSelect.value = selectedCamera.deviceId;

        const reader = new ZXingBrowser.BrowserMultiFormatReader(undefined, {
            delayBetweenScanAttempts: 250,
            delayBetweenScanSuccess: 300
        });
        const productBarcodeFormats = [
            ZXingBrowser.BarcodeFormat.UPC_A,
            ZXingBrowser.BarcodeFormat.UPC_E,
            ZXingBrowser.BarcodeFormat.EAN_13,
            ZXingBrowser.BarcodeFormat.EAN_8
        ];
        // ZXing DecodeHintType.POSSIBLE_FORMATS = 2; TRY_HARDER = 3.
        reader.setHints(new Map([[2, productBarcodeFormats], [3, true]]));
        barcodeScannerStream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                deviceId: { exact: selectedCamera.deviceId },
                width: { ideal: 1920 },
                height: { ideal: 1080 }
            }
        });
        if (barcodeCameraStartTimer !== null) {
            window.clearTimeout(barcodeCameraStartTimer);
            barcodeCameraStartTimer = null;
        }
        if (session !== barcodeScanSession) {
            barcodeScannerStream.getTracks().forEach((track) => track.stop());
            barcodeScannerStream = null;
            return;
        }
        barcodeScannerVideo.srcObject = barcodeScannerStream;
        await barcodeScannerVideo.play();
        if (!barcodeScannerVideo.videoWidth || !barcodeScannerVideo.videoHeight) {
            throw new Error('The camera started but provided no video frames. Check whether another app is using it.');
        }
        const scanCanvas = document.createElement('canvas');
        const scanContext = scanCanvas.getContext('2d', { willReadFrequently: true });
        const fullFrameCanvas = document.createElement('canvas');
        const fullFrameContext = fullFrameCanvas.getContext('2d', { willReadFrequently: true });
        if (!scanContext || !fullFrameContext) throw new Error('Barcode scanning could not start in this browser.');
        let scanRotation = 0;
        const scanFrame = () => {
            if (session !== barcodeScanSession) return;
            const videoWidth = barcodeScannerVideo.videoWidth;
            const videoHeight = barcodeScannerVideo.videoHeight;
            if (videoWidth && videoHeight) {
                const cropWidth = Math.floor(videoWidth * 0.92);
                const cropHeight = Math.floor(videoHeight * 0.65);
                const cropX = Math.floor((videoWidth - cropWidth) / 2);
                const cropY = Math.floor((videoHeight - cropHeight) / 2);
                const baseWidth = Math.min(1920, cropWidth * 2);
                const baseHeight = Math.min(1080, cropHeight * 2);
                const isQuarterTurn = scanRotation === 90 || scanRotation === 270;
                const outputWidth = isQuarterTurn ? baseHeight : baseWidth;
                const outputHeight = isQuarterTurn ? baseWidth : baseHeight;
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
                    barcodeScannerVideo,
                    cropX, cropY, cropWidth, cropHeight,
                    -baseWidth / 2, -baseHeight / 2, baseWidth, baseHeight
                );
                scanContext.setTransform(1, 0, 0, 1, 0, 0);
                fullFrameContext.drawImage(barcodeScannerVideo, 0, 0, videoWidth, videoHeight);
                try {
                    let result;
                    try {
                        result = reader.decodeFromCanvas(scanCanvas);
                    } catch (cropError) {
                        if (!['NotFoundException', 'ChecksumException', 'FormatException'].includes(cropError.name)) throw cropError;
                        if (scanRotation !== 0) throw cropError;
                        result = reader.decodeFromCanvas(fullFrameCanvas);
                    }
                    const barcode = result.getText().trim();
                    if (barcode) {
                        barcodeScanTargetField.value = barcode;
                        barcodeScanTargetField.dispatchEvent(new Event('input', { bubbles: true }));
                        barcodeScanTargetField.dispatchEvent(new Event('change', { bubbles: true }));
                        playBarcodeScanBeep();
                        closeBarcodeScanner();
                        barcodeScanTargetField.focus();
                        return;
                    }
                } catch (error) {
                    if (!['NotFoundException', 'ChecksumException', 'FormatException'].includes(error.name)) {
                        console.error('Barcode frame decoding failed:', error);
                    }
                }
                scanRotation = (scanRotation + 90) % 360;
            }
            barcodeFrameTimer = window.setTimeout(scanFrame, 180);
        };
        barcodeFrameTimer = window.setTimeout(scanFrame, 100);
        const cameraName = selectedCamera.label || 'selected camera';
        setBarcodeScannerStatus(
            camoCamera || requestedDeviceId
                ? `Using ${cameraName}. Scanning barcode...`
                : `Camo camera not found; using ${cameraName}. Select Camo from the list if available.`,
            'scanning'
        );
        barcodeScanHelpTimer = window.setTimeout(() => {
            if (session !== barcodeScanSession) return;
            setBarcodeScannerStatus('Camera is on, but no barcode has been detected yet. Straighten it and move closer until the bars fill the box. If blurry, move slightly farther away. Scanning will continue.', 'waiting');
            barcodeScanHelpTimer = null;
        }, 8000);
    } catch (error) {
        if (barcodeCameraStartTimer !== null) {
            window.clearTimeout(barcodeCameraStartTimer);
            barcodeCameraStartTimer = null;
        }
        const cameraErrorMessages = {
            NotAllowedError: 'Camera access was denied. Allow camera permission in your browser and try again.',
            NotFoundError: 'No camera was found. Open Camo Studio and enable the Camo Camera.',
            NotReadableError: 'The camera could not be opened. Another app may be using it; close that app and try again.',
            OverconstrainedError: 'The camera does not support the requested resolution. Try again or select another camera.',
            SecurityError: 'The browser blocked camera access. Open this page on localhost or HTTPS.'
        };
        setBarcodeScannerStatus(cameraErrorMessages[error.name] || error.message || 'The camera could not be opened.', 'error');
        if (session === barcodeScanSession) stopBarcodeScanner();
    }
};
const closeEditModal = () => {
    closeBarcodeScanner();
    editModal.classList.remove('open');
};
const addProductTrigger = document.getElementById('addProductTrigger');
const addProductFlag = document.getElementById('addProductFlag');
const editSubmitButton = document.getElementById('editSubmitButton');
const editNextButton = document.getElementById('editNextButton');
const editBackButton = document.getElementById('editBackButton');
const stockQuantityInput = document.getElementById('editQuantity');
const stockPiecesInput = document.getElementById('editPieces');
const stockBoxesInput = document.getElementById('editBox');
const stockPiecesPerBoxInput = document.getElementById('editPiecesPerBox');
const formPages = Array.from(document.querySelectorAll('[data-form-page]'));
const stepIndicators = Array.from(document.querySelectorAll('[data-step-indicator]'));
let currentFormPage = 0;
const showFormPage = (pageIndex) => {
    currentFormPage = Math.max(0, Math.min(pageIndex, formPages.length - 1));
    formPages.forEach((page, index) => page.classList.toggle('active', index === currentFormPage));
    stepIndicators.forEach((step, index) => {
        step.classList.toggle('active', index === currentFormPage);
        step.classList.toggle('complete', index < currentFormPage);
        if (index === currentFormPage) step.setAttribute('aria-current', 'step');
        else step.removeAttribute('aria-current');
    });
    editBackButton.hidden = currentFormPage === 0;
    editNextButton.hidden = currentFormPage === formPages.length - 1;
    editSubmitButton.hidden = currentFormPage !== formPages.length - 1;
};
const validateCurrentFormPage = () => {
    const fields = formPages[currentFormPage].querySelectorAll('input, select, textarea');
    for (const field of fields) {
        if (!field.reportValidity()) return false;
    }
    return true;
};
let syncingStockQuantity = false;
const updatePackagesFromStockQuantity = () => {
    if (syncingStockQuantity) return;
    syncingStockQuantity = true;
    const quantity = Math.max(0, parseInt(stockQuantityInput.value, 10) || 0);
    const piecesPerBox = Math.max(1, parseInt(stockPiecesPerBoxInput.value, 10) || 12);
    stockBoxesInput.value = String(Math.floor(quantity / piecesPerBox));
    stockPiecesInput.value = String(quantity % piecesPerBox);
    syncingStockQuantity = false;
};
stockQuantityInput.addEventListener('input', updatePackagesFromStockQuantity);
stockPiecesPerBoxInput.addEventListener('input', updatePackagesFromStockQuantity);
const aiScanButton = document.getElementById('aiScanButton');
const aiScanInput = document.getElementById('aiScanInput');
const aiScanStatus = document.getElementById('aiScanStatus');
const duplicateConfirmed = document.getElementById('duplicateConfirmed');
let aiDuplicates = [];
let aiMissingFields = [];
const setAiScanStatus = (message, isError = false) => {
    aiScanStatus.textContent = message;
    aiScanStatus.style.color = isError ? '#d33b59' : '#7b8189';
};
const updateAiReviewStatus = () => {
    const duplicateNames = aiDuplicates.map((product) => product.product_name).filter(Boolean);
    const missingLabels = aiMissingFields.filter((field) => field);
    const warnings = [];
    if (duplicateNames.length) warnings.push(`Possible duplicate: ${duplicateNames.join(', ')}`);
    if (missingLabels.length) warnings.push(`Missing AI details: ${missingLabels.join(', ')}`);
    if (warnings.length) setAiScanStatus(warnings.join(' | '), true);
};
const applyAiProductDetails = (details) => {
    const fieldMap = {
        product_name: 'editName',
        sku: 'editSku',
        barcode: 'editBarcode',
        description: 'editDescription',
        ingredients: 'editIngredients',
        benefits: 'editBenefits',
        usage_instructions: 'editUsage',
        brand: 'editBrand',
        product_form: 'editFormType',
        serving_size: 'editServingSize'
    };
    Object.entries(fieldMap).forEach(([key, id]) => {
        if (details[key]) document.getElementById(id).value = details[key];
    });
    if (details.category) setProductCategory(details.category);
    document.querySelector('[name="prescription_required"]').checked = String(details.prescription_required) === '1';
    if (document.querySelector('[name="prescription_required"]').checked) {
        setProductCategory('Prescription Medicine');
    }
};
if (aiScanButton && aiScanInput) {
aiScanButton.addEventListener('click', () => {
    addProductTrigger.click();
    aiScanInput.click();
});
aiScanInput.addEventListener('change', async () => {
    const files = Array.from(aiScanInput.files).slice(0, 4);
    if (!files.length) return;
    const oversizedFile = files.find((file) => file.size > 5 * 1024 * 1024);
    if (oversizedFile) {
        setAiScanStatus(`${oversizedFile.name} is larger than 5 MB.`, true);
        aiScanInput.value = '';
        return;
    }
    aiScanButton.disabled = true;
    setAiScanStatus(`Reading ${files.length} product image${files.length === 1 ? '' : 's'} with AI...`);
    try {
        const formData = new FormData();
        files.forEach((file) => formData.append('images[]', file, file.name));
        const response = await fetch('ai_product_scan.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'AI scan failed.');
        applyAiProductDetails(result.details);
        aiDuplicates = Array.isArray(result.duplicates) ? result.duplicates : [];
        aiMissingFields = Array.isArray(result.missing_fields) ? result.missing_fields : [];
        duplicateConfirmed.value = '0';
        const imageInput = document.getElementById('editImages');
        if (window.DataTransfer && imageInput) {
            const transfer = new DataTransfer();
            files.forEach((file) => transfer.items.add(file));
            imageInput.files = transfer.files;
        }
        document.getElementById('editImagePreview').src = URL.createObjectURL(files[0]);
        document.getElementById('editImageUrl1').value = '';
        document.getElementById('editImageUrl2').value = '';
        document.getElementById('editImageUrl3').value = '';
        document.getElementById('editImageUrl4').value = '';
        setAiScanStatus('Details generated. Please review them before saving.');
        updateAiReviewStatus();
    } catch (error) {
        setAiScanStatus(error.message, true);
    } finally {
        aiScanButton.disabled = false;
        aiScanInput.value = '';
    }
});
}
const shareUserSelect = document.getElementById('shareUserId');
const prescriptionRequiredInput = editForm.querySelector('[name="prescription_required"]');
const productCategoryInput = document.getElementById('editCategory');
const setProductCategory = (category) => {
    productCategoryInput.value = String(category || '').trim();
};
prescriptionRequiredInput.addEventListener('change', () => {
    if (prescriptionRequiredInput.checked) {
        setProductCategory('Prescription Medicine');
    } else if (productCategoryInput.value.trim().toLowerCase() === 'prescription medicine') {
        setProductCategory('');
    }
});
const filterShareUsers = (productId) => {
    if (!shareUserSelect) return;
    shareUserSelect.value = '';
    shareUserSelect.querySelectorAll('option[data-product-id]').forEach((option) => {
        option.hidden = option.dataset.productId !== String(productId);
    });
};
const resetAddFields = () => {
    editForm.reset();
    setProductCategory('');
    showFormPage(0);
    setAiScanStatus('');
    aiDuplicates = [];
    aiMissingFields = [];
    duplicateConfirmed.value = '0';
    document.getElementById('editLotNumber').required = true;
    document.getElementById('editManufacturingDate').required = true;
    document.getElementById('editExpirationDate').required = true;
    document.getElementById('editExpirationDate').setAttribute('min', '<?php echo date('Y-m-d', strtotime('+30 days')); ?>');
    document.getElementById('editBatchExpiryHint').textContent = 'New stock must have at least 30 days remaining before expiration.';
    document.getElementById('editBatchExpiryHint').hidden = false;
    stockQuantityInput.value = '0';
    stockPiecesInput.value = '0';
    stockBoxesInput.value = '0';
    stockPiecesPerBoxInput.value = '12';
    document.getElementById('editLotNumber').value = '';
    document.getElementById('editManufacturingDate').value = '';
    document.getElementById('editExpirationDate').value = '';
    expirationDateInput.dataset.originalDate = '';
    updateExpirationWarning();
    document.getElementById('editImagePreview').src = 'default.png';
    document.getElementById('editImageUrl1').value = '';
    document.getElementById('editImageUrl2').value = '';
    document.getElementById('editImageUrl3').value = '';
    document.getElementById('editImageUrl4').value = '';
};
addProductTrigger.addEventListener('click', () => {
    resetAddFields();
    editModal.classList.add('add-mode');
    document.getElementById('editModalTitle').textContent = 'Add Product';
    editSubmitButton.innerHTML = '<i class="bi bi-plus-lg"></i> Add Product';
    addProductFlag.value = '1';
    editForm.action = 'products.php';
    editModal.classList.add('open');
});
editForm.addEventListener('submit', (event) => {
    if (addProductFlag.value !== '1') return;

    const requiredFields = [
        ['editName', 'Product name'],
        ['editCategory', 'Category'],
        ['editLotNumber', 'Lot number'],
        ['editManufacturingDate', 'Manufacturing date'],
        ['editExpirationDate', 'Expiration date'],
        ['editPrice', 'Price'],
        ['editPiecesPerBox', 'Pieces per box']
    ];
    const missingFields = requiredFields
        .filter(([id]) => !String(document.getElementById(id).value || '').trim())
        .map(([, label]) => label);
    if (missingFields.length) {
        event.preventDefault();
        setAiScanStatus(`Complete required fields before saving: ${missingFields.join(', ')}.`, true);
        document.getElementById(requiredFields.find(([id]) => !String(document.getElementById(id).value || '').trim())[0]).focus();
        return;
    }

    if (aiDuplicates.length && duplicateConfirmed.value !== '1') {
        const duplicateNames = aiDuplicates.map((product) => product.product_name).filter(Boolean).join(', ');
        const shouldContinue = window.confirm(`Possible duplicate product found: ${duplicateNames}. Save anyway?`);
        if (!shouldContinue) {
            event.preventDefault();
            setAiScanStatus('Save cancelled. Review the duplicate product warning.', true);
            return;
        }
        duplicateConfirmed.value = '1';
    }
});
editNextButton.addEventListener('click', () => {
    if (validateCurrentFormPage()) showFormPage(currentFormPage + 1);
});
editBackButton.addEventListener('click', () => showFormPage(currentFormPage - 1));
document.addEventListener('click', (event) => {
    const button = event.target.closest('.share-trigger');
    if (!button) return;
    document.getElementById('shareProductId').value = button.dataset.id;
    filterShareUsers(button.dataset.id);
    document.getElementById('shareModal').classList.add('open');
});
document.addEventListener('click', (event) => {
    const button = event.target.closest('.edit-trigger');
    if (button) {
        showFormPage(0);
        editModal.classList.remove('add-mode');
        document.getElementById('editModalTitle').textContent = 'Edit Product';
        editSubmitButton.innerHTML = '<i class="bi bi-save"></i> Save Changes';
        addProductFlag.value = '0';
        const hasBatch = button.dataset.hasBatch === '1';
        document.getElementById('editLotNumber').required = !hasBatch;
        document.getElementById('editManufacturingDate').required = false;
        document.getElementById('editExpirationDate').required = !hasBatch;
        document.getElementById('editBatchExpiryHint').textContent = 'No batch expiry is saved for this product yet. Enter its lot number and expiration date to add it.';
        document.getElementById('editBatchExpiryHint').hidden = hasBatch;
        document.getElementById('editName').value = button.dataset.name || '';
        document.getElementById('editSku').value = button.dataset.sku || 'MED-' + button.dataset.id;
        document.getElementById('editBarcode').value = button.dataset.barcode || '';
        setProductCategory(button.dataset.category || '');
        document.getElementById('editLotNumber').value = button.dataset.lotNumber || '';
        document.getElementById('editManufacturingDate').value = button.dataset.manufacturingDate || '';
        document.getElementById('editExpirationDate').value = button.dataset.expirationDate || '';
        expirationDateInput.dataset.originalDate = button.dataset.expirationDate || '';
        document.getElementById('editExpirationDate').setAttribute('min', minimumExpirationDate);
        updateExpirationWarning();
        editForm.querySelector('[name="prescription_required"]').checked = button.dataset.prescriptionRequired === '1';
        if (editForm.querySelector('[name="prescription_required"]').checked) {
            setProductCategory('Prescription Medicine');
        }
        document.getElementById('editPrice').value = button.dataset.pricePiece || 0;
        document.getElementById('editPiecesPerBox').value = button.dataset.piecesPerBox || 12;
        document.getElementById('editMinPieces').value = button.dataset.minPieces || 1;
        document.getElementById('editMinBoxes').value = button.dataset.minBoxes || 0;
        document.getElementById('editQuantity').value = button.dataset.quantity || 0;
        updatePackagesFromStockQuantity();
        document.getElementById('editDescription').value = button.dataset.description || '';
        document.getElementById('editIngredients').value = button.dataset.ingredients || '';
        document.getElementById('editBenefits').value = button.dataset.benefits || '';
        document.getElementById('editUsage').value = button.dataset.usage || '';
        document.getElementById('editBrand').value = button.dataset.brand || '';
        document.getElementById('editFormType').value = button.dataset.form || '';
        document.getElementById('editServingSize').value = button.dataset.servingSize || '';
        document.getElementById('editImagePreview').src = button.dataset.image || 'default.png';
        document.getElementById('editImageUrl1').value = (button.dataset.image || '').indexOf('http') === 0 ? button.dataset.image : '';
        editForm.action = 'edit_product.php?id=' + button.dataset.id;
        editModal.classList.add('open');
    }
});
const requestedEditProductId = new URLSearchParams(window.location.search).get('edit_product_id');
if (requestedEditProductId && /^\d+$/.test(requestedEditProductId)) {
    const requestedEditButton = Array.from(document.querySelectorAll('.edit-trigger'))
        .find((button) => button.dataset.id === requestedEditProductId);
    if (requestedEditButton) {
        requestedEditButton.click();
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('edit_product_id');
        window.history.replaceState({}, '', cleanUrl);
    } else {
        console.error('Requested product edit button is not available.');
    }
}
document.getElementById('closeShareModal').addEventListener('click', () => document.getElementById('shareModal').classList.remove('open'));
document.getElementById('shareModal').addEventListener('click', (event) => { if (event.target.id === 'shareModal') event.target.classList.remove('open'); });
document.getElementById('barcodeScanTrigger').addEventListener('click', () => {
    barcodeScanTargetField = barcodeField;
    startBarcodeScanner();
});
document.getElementById('productBarcodeScanTrigger').addEventListener('click', () => {
    barcodeScanTargetField = document.getElementById('productSearch');
    startBarcodeScanner();
});
barcodeCameraSelect.addEventListener('change', () => {
    if (barcodeScannerModal.classList.contains('open') && barcodeCameraSelect.value) {
        startBarcodeScanner(barcodeCameraSelect.value);
    }
});
document.getElementById('closeBarcodeScanner').addEventListener('click', closeBarcodeScanner);
document.getElementById('cancelBarcodeScan').addEventListener('click', closeBarcodeScanner);
barcodeScannerModal.addEventListener('click', (event) => { if (event.target === barcodeScannerModal) closeBarcodeScanner(); });
document.getElementById('closeEditModal').addEventListener('click', closeEditModal);
document.getElementById('cancelEditModal').addEventListener('click', closeEditModal);
editModal.addEventListener('click', (event) => { if (event.target === editModal) closeEditModal(); });
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (barcodeScannerModal.classList.contains('open')) closeBarcodeScanner();
    else closeEditModal();
});
document.getElementById('editImageUrl1').addEventListener('input', (event) => {
    document.getElementById('editImagePreview').src = event.target.value.trim() || 'default.png';
});

const filterPanel = document.getElementById('filterPanel');
const categoriesCard = document.getElementById('categoriesCard');
const productSearch = document.getElementById('productSearch');
const clearProductSearch = document.getElementById('clearProductSearch');
if (categoriesCard) {
    categoriesCard.addEventListener('click', (event) => {
        event.preventDefault();
        const categoryFilter = document.getElementById('categoryFilter');
        const productsPanel = document.getElementById('product-table');
        if (productsPanel) productsPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (categoryFilter) categoryFilter.focus({ preventScroll: true });
    });
}
document.querySelectorAll('[data-product-status]').forEach((statusCard) => {
    statusCard.addEventListener('click', (event) => {
        event.preventDefault();
        if (!filterPanel) return;
        const statusFilter = filterPanel.elements.namedItem('product_status');
        if (statusFilter) statusFilter.value = statusCard.dataset.productStatus;
        const pageInput = filterPanel.querySelector('[name="page"]');
        if (pageInput) pageInput.value = '1';
        refreshProducts(true);
        const productsPanel = document.getElementById('product-table');
        if (productsPanel) productsPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
if (filterPanel) {
    filterPanel.querySelectorAll('select').forEach((select) => {
        select.addEventListener('change', () => {
            const pageInput = filterPanel.querySelector('[name="page"]');
            if (pageInput) pageInput.value = '1';
            refreshProducts();
        });
    });
}

let activeProductRequest;
async function refreshProducts(pushHistory = false) {
    if (!filterPanel) return;
    if (activeProductRequest) activeProductRequest.abort();
    const requestController = new AbortController();
    activeProductRequest = requestController;
    const query = new URLSearchParams(new FormData(filterPanel));
    const requestUrl = new URL(window.location.href);
    requestUrl.search = query.toString();
    requestUrl.hash = '';
    if (pushHistory) {
        window.history.pushState(null, '', requestUrl);
    } else {
        window.history.replaceState(null, '', requestUrl);
    }

    try {
        const response = await fetch(requestUrl, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: requestController.signal
        });
        if (!response.ok) throw new Error('Products could not be refreshed. Please try again.');
        const html = await response.text();
        const refreshedDocument = new DOMParser().parseFromString(html, 'text/html');
        const refreshedBody = refreshedDocument.querySelector('.product-table tbody');
        const refreshedFooter = refreshedDocument.querySelector('.table-footer');
        const currentBody = document.querySelector('.product-table tbody');
        const currentFooter = document.querySelector('.table-footer');
        if (!refreshedBody || !refreshedFooter || !currentBody || !currentFooter) {
            throw new Error('The refreshed product list was incomplete. Please try again.');
        }
        currentBody.replaceChildren(...Array.from(refreshedBody.children));
        currentFooter.replaceWith(refreshedFooter);
    } catch (error) {
        if (error.name !== 'AbortError') {
            const currentFooter = document.querySelector('.table-footer');
            if (currentFooter) currentFooter.textContent = error.message;
        }
    } finally {
        if (activeProductRequest === requestController) activeProductRequest = null;
    }
}

if (filterPanel) {
    filterPanel.addEventListener('submit', (event) => {
        event.preventDefault();
        refreshProducts();
    });
}

if (productSearch && filterPanel) {
    productSearch.addEventListener('input', () => {
        if (clearProductSearch) {
            clearProductSearch.style.display = productSearch.value.trim() ? 'inline-flex' : 'none';
        }
        const pageInput = filterPanel.querySelector('[name="page"]');
        if (pageInput) pageInput.value = '1';
        refreshProducts();
    });
}

if (clearProductSearch && productSearch && filterPanel) {
    clearProductSearch.addEventListener('click', () => {
        productSearch.value = '';
        clearProductSearch.style.display = 'none';
        const pageInput = filterPanel.querySelector('[name="page"]');
        if (pageInput) pageInput.value = '1';
        refreshProducts();
    });
}

document.addEventListener('click', (event) => {
    const pageLink = event.target.closest('.pagination a');
    if (!pageLink || !filterPanel) return;
    event.preventDefault();
    const pageUrl = new URL(pageLink.href, window.location.href);
    const pageInput = filterPanel.querySelector('[name="page"]');
    if (pageInput) pageInput.value = pageUrl.searchParams.get('page') || '1';
    refreshProducts(true);
});

window.addEventListener('popstate', () => {
    const url = new URL(window.location.href);
    for (const key of ['search', 'category', 'stock_status', 'product_status', 'page']) {
        const field = filterPanel && filterPanel.elements.namedItem(key);
        if (field) field.value = url.searchParams.get(key) || (key === 'page' ? '1' : '');
    }
    if (clearProductSearch) {
        clearProductSearch.style.display = productSearch && productSearch.value.trim() ? 'inline-flex' : 'none';
    }
    refreshProducts();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>