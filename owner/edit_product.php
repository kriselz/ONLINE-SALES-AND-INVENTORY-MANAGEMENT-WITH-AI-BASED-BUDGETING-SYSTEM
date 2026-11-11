<?php 
include 'config.php';
include 'functions.php';
session_start();
include 'access_control.php';
$isPharmacist = requireOwnerPageAccess($conn, true);

$categoryColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'category'");
if ($categoryColumnResult && $categoryColumnResult->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN category VARCHAR(100) NULL AFTER product_name");
}
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS min_pieces INT UNSIGNED NOT NULL DEFAULT 1");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS min_boxes INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS benefits TEXT NULL");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS prescription_required TINYINT(1) NOT NULL DEFAULT 0");
ensureLowStockNotificationSchema($conn);

$id = $_GET['id'];

$conn->query("CREATE TABLE IF NOT EXISTS product_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    price_piece DECIMAL(10,2) DEFAULT 0,
    price_box DECIMAL(10,2) DEFAULT 0,
    quantity INT DEFAULT 0,
    pieces INT DEFAULT 0,
    box INT DEFAULT 0,
    image VARCHAR(255) NULL,
    pieces_per_box INT DEFAULT 12,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// GET PRODUCT
$product = $conn->query("SELECT * FROM products WHERE id=$id")->fetch_assoc();

// SAFE VALUES
$product['pieces'] = $product['pieces'] ?? 0;
$product['box'] = $product['box'] ?? 0;
$product['price_piece'] = $product['price_piece'] ?? $product['price'] ?? 0;
$product['price_box'] = $product['price_box'] ?? 0;
$product['pieces_per_box'] = $product['pieces_per_box'] ?? 12;
$product['quantity'] = $product['quantity'] ?? ((int)$product['pieces'] + ((int)$product['box'] * (int)$product['pieces_per_box']));
$product['low_stock_threshold'] = $product['low_stock_threshold'] ?? 20;
$product['image'] = $product['image'] ?? '';
$productImageSrc = preg_match('/^https?:\/\//i', $product['image']) ? $product['image'] : 'uploads/' . ltrim($product['image'], '/');
$product['sku'] = $product['sku'] ?? 'MED-' . $product['id'];
$product['description'] = $product['description'] ?? '';
$product['ingredients'] = $product['ingredients'] ?? '';
$product['benefits'] = $product['benefits'] ?? '';
$product['usage_instructions'] = $product['usage_instructions'] ?? '';
$product['brand'] = $product['brand'] ?? '';
$product['product_form'] = $product['product_form'] ?? '';
$product['serving_size'] = $product['serving_size'] ?? '';
$product['category'] = $product['category'] ?? '';
$product['barcode'] = $product['barcode'] ?? '';
$product['status'] = $product['status'] ?? 'active';
$product['min_pieces'] = $product['min_pieces'] ?? 1;
$product['min_boxes'] = $product['min_boxes'] ?? 0;
$product['prescription_required'] = $product['prescription_required'] ?? 0;
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
    image_path VARCHAR(255) NULL,
    image_url VARCHAR(500) NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_product_images_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE product_images ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL");
$conn->query("ALTER TABLE product_images ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NULL");
$batchDetailsResult = $conn->query("SELECT id, lot_number, expiration_date, manufacturing_date FROM product_batches WHERE product_id = " . (int)$id . " ORDER BY expiration_date ASC, id ASC LIMIT 1");
$batchDetails = $batchDetailsResult ? $batchDetailsResult->fetch_assoc() : [];

// UPDATE
if (isset($_POST['update'])) {
    $name = (string)($_POST['product_name'] ?? '');
    $price_piece = (float)($_POST['price_piece'] ?? 0);
    $pieces_per_box = max(1, (int)($_POST['pieces_per_box'] ?? 12));
    $quantity = max(
        0,
        (int)($_POST['quantity_display'] ?? (
            (int)($_POST['pieces'] ?? 0) + ((int)($_POST['box'] ?? 0) * $pieces_per_box)
        ))
    );
    $box = intdiv($quantity, $pieces_per_box);
    $pieces = $quantity % $pieces_per_box;
    $price_box = round($price_piece * $pieces_per_box * 0.98, 2);
    $min_pieces = max(0, (int)($_POST['min_pieces'] ?? 1));
    $min_boxes = max(0, (int)($_POST['min_boxes'] ?? 0));
    $low_stock_threshold = max(0, (int)($_POST['low_stock_threshold'] ?? 20));
    $sku = trim($_POST['sku'] ?? $product['sku']);
    $barcode = trim($_POST['barcode'] ?? ($product['barcode'] ?? ''));
    $category = trim($_POST['category'] ?? ($product['category'] ?? ''));
    $description = (string)($_POST['description'] ?? '');
    $ingredients = (string)($_POST['ingredients'] ?? '');
    $benefits = (string)($_POST['benefits'] ?? '');
    $usage_instructions = (string)($_POST['usage_instructions'] ?? '');
    $brand = (string)($_POST['brand'] ?? '');
    $product_form = (string)($_POST['product_form'] ?? '');
    $serving_size = (string)($_POST['serving_size'] ?? '');
    $prescriptionRequired = isset($_POST['prescription_required']) || strtolower(trim($category)) === 'prescription medicine' ? 1 : 0;
    if ($prescriptionRequired) {
        $category = 'Prescription Medicine';
    }
    if ($category === '' || strlen($category) > 100) {
        http_response_code(422);
        exit('Select a product category or enter a category under Other (up to 100 characters).');
    }
    $lotNumber = trim($_POST['lot_number'] ?? '');
    $expirationDate = trim($_POST['expiration_date'] ?? '');
    $manufacturingDate = trim($_POST['manufacturing_date'] ?? '');

    $qty = $quantity;
    $status = $qty > 0 ? 'active' : 'inactive';
    $hasExistingBatch = !empty($batchDetails['lot_number']) && !empty($batchDetails['expiration_date']);
    $shouldSaveBatch = !$hasExistingBatch || $lotNumber !== '' || $expirationDate !== '' || $manufacturingDate !== '';
    if ($hasExistingBatch) {
        $lotNumber = $lotNumber !== '' ? $lotNumber : (string)$batchDetails['lot_number'];
        $expirationDate = $expirationDate !== '' ? $expirationDate : (string)$batchDetails['expiration_date'];
    }
    if ($shouldSaveBatch) {
        if ($lotNumber === '' || $expirationDate === '') {
            http_response_code(422);
            exit('Lot number and expiration date are both required to save product expiry details.');
        }
        $expiration = DateTime::createFromFormat('Y-m-d', $expirationDate);
        $validExpiration = $expiration && $expiration->format('Y-m-d') === $expirationDate;
        $batchExpiryChanged = !$hasExistingBatch
            || $expirationDate !== (string)$batchDetails['expiration_date'];
        $minimumBatchExpiration = date('Y-m-d', strtotime('+30 days'));
        if (!$validExpiration || ($batchExpiryChanged && $expirationDate < $minimumBatchExpiration)) {
            http_response_code(422);
            exit('Enter a valid expiration date. New or changed batch expiry dates must be at least 30 days from today.');
        }
        if ($manufacturingDate !== '') {
            $manufacturing = DateTime::createFromFormat('Y-m-d', $manufacturingDate);
            if (
                !$manufacturing
                || $manufacturing->format('Y-m-d') !== $manufacturingDate
                || $manufacturingDate > date('Y-m-d')
                || $manufacturingDate > $expirationDate
            ) {
                http_response_code(422);
                exit('Enter a valid manufacturing date that is not in the future or after the expiration date.');
            }
        }
    }

    try {
        ensureUniqueInventoryIdentifierIndexes($conn);
        if (inventoryIdentifierInUse($conn, 'sku', $sku, (int)$id)) {
            throw new RuntimeException('This SKU is already assigned to another product.');
        }
        if (inventoryIdentifierInUse($conn, 'barcode', $barcode, (int)$id)) {
            throw new RuntimeException('This barcode is already assigned to another product.');
        }
        if ($lotNumber !== '' && inventoryIdentifierInUse($conn, 'lot_number', $lotNumber, (int)($batchDetails['id'] ?? 0))) {
            throw new RuntimeException('This lot number is already assigned to another batch.');
        }
    } catch (RuntimeException $exception) {
        http_response_code(422);
        exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    $sku = $sku !== '' ? $sku : null;
    $barcode = $barcode !== '' ? $barcode : null;

    $productUpdates = [
        'product_name' => $name,
        'sku' => $sku,
        'barcode' => $barcode,
        'description' => $description,
        'category' => $category,
        'ingredients' => $ingredients,
        'benefits' => $benefits,
        'usage_instructions' => $usage_instructions,
        'brand' => $brand,
        'product_form' => $product_form,
        'serving_size' => $serving_size,
        'price' => $price_piece,
        'price_piece' => $price_piece,
        'price_box' => $price_box,
        'quantity' => $qty,
        'pieces' => $pieces,
        'box' => $box,
        'pieces_per_box' => $pieces_per_box,
        'min_pieces' => $min_pieces,
        'min_boxes' => $min_boxes,
        'low_stock_threshold' => $low_stock_threshold,
        'prescription_required' => $prescriptionRequired,
        'status' => $status
    ];

    $productImages = [];
    foreach (['image_url_1', 'image_url_2', 'image_url_3', 'image_url_4'] as $imageUrlField) {
        $imageUrl = trim((string)($_POST[$imageUrlField] ?? ''));
        if ($imageUrl === '') {
            continue;
        }
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            http_response_code(422);
            exit('Enter a valid URL for each product image.');
        }
        $productImages[] = $imageUrl;
    }
    if (!$productImages) {
        $legacyImageUrl = trim((string)($_POST['image_url'] ?? ''));
        if ($legacyImageUrl !== '' && filter_var($legacyImageUrl, FILTER_VALIDATE_URL)) {
            $productImages[] = $legacyImageUrl;
        }
    }

    $imageFiles = $_FILES['images'] ?? null;
    if (is_array($imageFiles) && is_array($imageFiles['name'] ?? null)) {
        $fileCount = count($imageFiles['name']);
        if ($fileCount > 4) {
            http_response_code(422);
            exit('Select no more than four product images.');
        }
        $allowedImageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        for ($imageIndex = 0; $imageIndex < $fileCount; $imageIndex++) {
            $uploadError = (int)($imageFiles['error'][$imageIndex] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($imageFiles['tmp_name'][$imageIndex] ?? '')) {
                http_response_code(422);
                exit('A product image could not be uploaded. Please choose the image again and retry.');
            }
            $temporaryPath = $imageFiles['tmp_name'][$imageIndex];
            $mime = function_exists('mime_content_type') ? mime_content_type($temporaryPath) : '';
            if (
                !isset($allowedImageTypes[$mime])
                || (int)($imageFiles['size'][$imageIndex] ?? 0) > 5 * 1024 * 1024
                || @getimagesize($temporaryPath) === false
            ) {
                http_response_code(422);
                exit('Product images must be valid JPG, PNG, or WebP files no larger than 5 MB each.');
            }
            $imageName = 'product_' . bin2hex(random_bytes(8)) . '.' . $allowedImageTypes[$mime];
            if (!move_uploaded_file($temporaryPath, __DIR__ . '/uploads/' . $imageName)) {
                http_response_code(500);
                exit('Unable to save a product image. Please try again.');
            }
            $productImages[] = $imageName;
        }
    }
    $productImages = array_values(array_unique($productImages));
    if ($productImages) {
        $productUpdates['image'] = $conn->real_escape_string($productImages[0]);
    }

    $availableColumns = [];
    $columnsResult = $conn->query("SHOW COLUMNS FROM products");
    if ($columnsResult) {
        while ($column = $columnsResult->fetch_assoc()) { $availableColumns[$column['Field']] = true; }
    }
    $setClauses = [];
    foreach ($productUpdates as $field => $value) {
        if (isset($availableColumns[$field])) {
            $setClauses[] = $value === null
                ? "`$field`=NULL"
                : "`$field`='" . $conn->real_escape_string((string)$value) . "'";
        }
    }
    $sql = "UPDATE products SET " . implode(', ', $setClauses) . " WHERE id=" . (int)$id;
    $conn->begin_transaction();
    $historySaved = $conn->query("
        INSERT INTO product_history
        (product_id, product_name, price_piece, price_box, quantity, pieces, box, image, pieces_per_box)
        VALUES (
            '{$product['id']}',
            '{$product['product_name']}',
            '{$product['price_piece']}',
            '{$product['price_box']}',
            '{$product['quantity']}',
            '{$product['pieces']}',
            '{$product['box']}',
            '{$product['image']}',
            '{$product['pieces_per_box']}'
        )
    ");
    if (!$historySaved || !$conn->query($sql)) {
        $databaseError = $conn->error;
        $conn->rollback();
        http_response_code(500);
        exit('Unable to save product changes.' . ($databaseError !== '' ? ' ' . htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8') : ''));
    }

    if ($lotNumber !== '' && $expirationDate !== '') {
        $batchCheck = $conn->query("SELECT id FROM product_batches WHERE product_id = " . (int)$id . " ORDER BY expiration_date ASC, id ASC LIMIT 1");
        if (!$batchCheck) {
            $databaseError = $conn->error;
            $conn->rollback();
            http_response_code(500);
            exit('Unable to check existing product batches. ' . htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'));
        }
        $existingBatch = $batchCheck->fetch_assoc();
        if ($existingBatch) {
            $batchStatement = $conn->prepare('UPDATE product_batches SET lot_number = ?, expiration_date = ?, manufacturing_date = COALESCE(?, manufacturing_date), received_quantity = ?, current_quantity = ? WHERE id = ?');
            $batchId = (int)$existingBatch['id'];
        } else {
            $batchStatement = $conn->prepare('INSERT INTO product_batches (product_id, lot_number, manufacturing_date, expiration_date, received_quantity, current_quantity, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $createdBy = (int)($_SESSION['user_id'] ?? 0);
        }
        if (!$batchStatement) {
            $databaseError = $conn->error;
            $conn->rollback();
            http_response_code(500);
            exit('Unable to prepare product lot changes. ' . htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'));
        }
        $manufacturingDateForSave = $manufacturingDate !== '' ? $manufacturingDate : null;
        if ($existingBatch) {
            $batchStatement->bind_param('sssiii', $lotNumber, $expirationDate, $manufacturingDateForSave, $qty, $qty, $batchId);
        } else {
            $batchStatement->bind_param('isssiii', $id, $lotNumber, $manufacturingDateForSave, $expirationDate, $qty, $qty, $createdBy);
        }
        if (!$batchStatement->execute()) {
            $databaseError = $batchStatement->error;
            $batchStatement->close();
            $conn->rollback();
            http_response_code(422);
            exit('Unable to save lot number. It may already be assigned to another batch. ' . htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'));
        }
        $batchStatement->close();
    }

    if ($productImages) {
        if (!$conn->query('DELETE FROM product_images WHERE product_id = ' . (int)$id)) {
            $databaseError = $conn->error;
            $conn->rollback();
            http_response_code(500);
            exit('Unable to update product images. ' . htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'));
        }
        $imageInsert = $conn->prepare('INSERT INTO product_images (product_id, image_path, image_url, sort_order) VALUES (?, ?, ?, ?)');
        if (!$imageInsert) {
            $databaseError = $conn->error;
            $conn->rollback();
            http_response_code(500);
            exit('Unable to prepare product image updates. ' . htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'));
        }
        foreach ($productImages as $sortOrder => $productImage) {
            $imagePath = filter_var($productImage, FILTER_VALIDATE_URL) ? '' : $productImage;
            $imageUrl = $productImage;
            $imageInsert->bind_param('issi', $id, $imagePath, $imageUrl, $sortOrder);
            if (!$imageInsert->execute()) {
                $databaseError = $imageInsert->error;
                $imageInsert->close();
                $conn->rollback();
                http_response_code(500);
                exit('Unable to save product images. ' . htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'));
            }
        }
        $imageInsert->close();
    }

    writeAuditLog($conn, 'product_updated', 'product', (int)$id, ['name' => $name]);
    $conn->commit();
    checkAndSendLowStockAlert($conn, (int)$id);

    // 🔔 ADD NOTIFICATION
    $message = $conn->real_escape_string("📦 Product Updated: $name | New Stock: $qty pieces");
    $conn->query("INSERT INTO notifications (message, created_at) VALUES ('$message', NOW())");

    echo "<script>alert('Updated!'); window.location='products.php';</script>";
}

// REVERT
if (isset($_GET['revert'])) {

    $hist_id = $_GET['revert'];
    $old = $conn->query("SELECT * FROM product_history WHERE id=$hist_id")->fetch_assoc();

    $old['pieces'] = $old['pieces'] ?? 0;
    $old['box'] = $old['box'] ?? 0;

    $conn->query("
        UPDATE products SET 
        product_name='{$old['product_name']}',
        price_piece='{$old['price_piece']} ',
        price_box='{$old['price_box']}',
        quantity='{$old['quantity']}',
        pieces='{$old['pieces']}',
        box='{$old['box']}',
        image='{$old['image']}',
        pieces_per_box='{$old['pieces_per_box']}'
        WHERE id='{$old['product_id']}'
    ");

    // 🔔 ADD NOTIFICATION
    $message = "🔄 Product Reverted: {$old['product_name']} to previous version";
    $conn->query("INSERT INTO notifications (message, created_at) VALUES ('$message', NOW())");

    echo "<script>alert('Reverted!'); window.location='products.php';</script>";
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Edit Product</title>

<style>
body {
    font-family:Arial, sans-serif;
    background:#f4f6f8;
    display:flex;
    gap:20px;
    justify-content:center;
    align-items:flex-start;
    padding:32px 20px;
}

.box, .history {
    background:white;
    padding:20px;
    border-radius:10px;
    width:min(760px, 100%);
    box-shadow:0 3px 10px rgba(0,0,0,0.1);
}

.history { width:300px; }

.form-grid {
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:14px;
}

.field { min-width:0; }
.field.full { grid-column:1 / -1; }
.field label { display:block; margin-bottom:6px; color:#374151; font-size:13px; font-weight:600; }
.field input, .field textarea, .field select {
    width:100%;
    padding:10px 12px;
    border:1px solid #ccc;
    border-radius:6px;
    font:inherit;
}
.field textarea { min-height:90px; resize:vertical; }
.current-image { display:flex; align-items:center; gap:12px; margin:8px 0 16px; }
.current-image img { width:72px; height:72px; object-fit:contain; border:1px solid #ddd; border-radius:6px; }
.checkbox-field { display:flex; align-items:center; gap:8px; min-height:42px; }
.checkbox-field input { width:auto; margin:0; }
.form-actions { margin-top:18px; }

@media (max-width: 900px) {
    body { flex-direction:column; }
    .box, .history { width:100%; }
}

@media (max-width: 560px) {
    body { padding:16px 12px; }
    .form-grid { grid-template-columns:1fr; }
    .field.full { grid-column:auto; }
}

button {
    width:100%;
    padding:10px;
    background:#2c3e50;
    color:white;
    border:none;
    border-radius:5px;
}

button:hover {
    background:#34495e;
}

.history-item {
    padding:10px;
    border-bottom:1px solid #ddd;
}

.revert-btn {
    display:inline-block;
    margin-top:5px;
    padding:5px 8px;
    background:#e74c3c;
    color:white;
    text-decoration:none;
    border-radius:5px;
    font-size:12px;
}

.back-btn {
    display: inline-block;
    margin-bottom: 10px;
    padding: 8px 12px;
    background: #7f8c8d;
    color: white;
    text-decoration: none;
    border-radius: 5px;
    font-size: 14px;
}

.back-btn:hover {
    background: #636e72;
}

</style>
</head>

<body>
<a href="products.php" class="back-btn">← Back</a>
<!-- FORM -->
<div class="box">

<h2>Edit Product</h2>

<form method="POST" enctype="multipart/form-data">
    <div class="form-grid">
        <div class="field">
            <label for="product_name">Product Name</label>
            <input id="product_name" type="text" name="product_name" value="<?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="field">
            <label for="category">Category</label>
            <input id="category" type="text" name="category" value="<?= htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="field">
            <label for="price_piece">Price per Piece</label>
            <input id="price_piece" type="number" step="0.01" name="price_piece" value="<?= htmlspecialchars((string)$product['price_piece'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="field">
            <label for="brand">Brand</label>
            <input id="brand" type="text" name="brand" value="<?= htmlspecialchars($product['brand'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="field">
            <label for="product_form">Form</label>
            <input id="product_form" type="text" name="product_form" value="<?= htmlspecialchars($product['product_form'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Tablet, capsule, syrup">
        </div>
        <div class="field">
            <label for="serving_size">Volume</label>
            <input id="serving_size" type="text" name="serving_size" value="<?= htmlspecialchars($product['serving_size'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="field full">
            <label for="description">Description</label>
            <textarea id="description" name="description"><?= htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <div class="field full">
            <label for="ingredients">Ingredients</label>
            <textarea id="ingredients" name="ingredients"><?= htmlspecialchars($product['ingredients'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <div class="field full">
            <label for="usage_instructions">Usage</label>
            <textarea id="usage_instructions" name="usage_instructions"><?= htmlspecialchars($product['usage_instructions'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <div class="field full">
            <label for="benefits">Key Benefits</label>
            <textarea id="benefits" name="benefits"><?= htmlspecialchars($product['benefits'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <div class="field">
            <label for="pieces_per_box">Pieces per Box</label>
            <input id="pieces_per_box" type="number" min="1" name="pieces_per_box" value="<?= (int)$product['pieces_per_box'] ?>" required>
            <small>2% off for full-box purchases.</small>
        </div>
        <input id="pieces" type="hidden" name="pieces" value="<?= (int)$product['pieces'] ?>">
        <input id="box" type="hidden" name="box" value="<?= (int)$product['box'] ?>">
        <div class="field">
            <label for="quantity">Total Stock in Pieces</label>
            <input id="quantity" type="number" min="0" name="quantity_display" value="<?= (int)$product['quantity'] ?>" required>
        </div>
        <div class="field">
            <label for="low_stock_threshold">Low Stock Threshold</label>
            <input id="low_stock_threshold" type="number" min="0" name="low_stock_threshold" value="<?= (int)$product['low_stock_threshold'] ?>" required>
        </div>
        <div class="field">
            <label for="min_pieces">Minimum Piece Order</label>
            <input id="min_pieces" type="number" min="0" name="min_pieces" value="<?= (int)$product['min_pieces'] ?>">
        </div>
        <div class="field">
            <label for="min_boxes">Minimum Box Order</label>
            <input id="min_boxes" type="number" min="0" name="min_boxes" value="<?= (int)$product['min_boxes'] ?>">
        </div>
        <div class="field">
            <label for="sku">SKU / Item ID</label>
            <input id="sku" type="text" name="sku" value="<?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="field">
            <label for="barcode">Barcode</label>
            <input id="barcode" type="text" name="barcode" value="<?= htmlspecialchars($product['barcode'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="field">
            <label for="lot_number">Lot Number</label>
            <input id="lot_number" type="text" name="lot_number" value="<?= htmlspecialchars($batchDetails['lot_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="field">
            <label for="expiration_date">Expiration Date</label>
            <input id="expiration_date" type="date" name="expiration_date" value="<?= htmlspecialchars($batchDetails['expiration_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" min="<?= date('Y-m-d') ?>">
        </div>
        <div class="field checkbox-field">
            <input id="prescription_required" type="checkbox" name="prescription_required" value="1" <?= !empty($product['prescription_required']) ? 'checked' : '' ?>>
            <label for="prescription_required">Requires prescription</label>
        </div>
        <div class="field full">
            <label>Current Image</label>
            <?php if (!empty($product['image'])): ?>
                <div class="current-image"><img src="<?= htmlspecialchars($productImageSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Current product image"></div>
            <?php else: ?>
                <p>No product image set.</p>
            <?php endif; ?>
            <label for="image">Upload a replacement image</label>
            <input id="image" type="file" name="image" accept="image/*">
        </div>
    </div>
    <div class="form-actions"><button type="submit" name="update">Save Product</button></div>
</form>

</div>

<!-- HISTORY -->
<div class="history">

<h3>🔄 Edit History</h3>

<?php
$history = $conn->query("SELECT * FROM product_history WHERE product_id=$id ORDER BY id DESC");

while ($h = $history->fetch_assoc()) {

    $h['pieces'] = $h['pieces'] ?? 0;
    $h['box'] = $h['box'] ?? 0;

    echo "
    <div class='history-item'>
        <b>{$h['product_name']}</b><br>
        ₱{$h['price_piece']} /pc | ₱{$h['price_box']} /box<br>
        Box: {$h['box']} | Pieces: {$h['pieces']}<br>

        <a class='revert-btn' href='edit_product.php?id=$id&revert={$h['id']}'>
            Revert
        </a>
    </div>";
}
?>

</div>

<!-- JS -->
<script>
let piecesPerBoxInput = document.getElementById("pieces_per_box");
let pieces = document.getElementById("pieces");
let box = document.getElementById("box");
let quantity = document.getElementById("quantity");

function computeFromQuantity(){
    let q = Math.max(0, parseInt(quantity.value) || 0);
    let ppb = parseInt(piecesPerBoxInput.value) || 12;
    let b = Math.floor(q / ppb);
    let p = q % ppb;
    box.value = b;
    pieces.value = p;
}

piecesPerBoxInput.addEventListener("input", computeFromQuantity);
quantity.addEventListener("input", computeFromQuantity);

computeFromQuantity();
</script>

</body>
</html>