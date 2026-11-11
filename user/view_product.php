<?php
session_start();
include 'config.php';
require_once __DIR__ . '/functions.php';

// Check if user is logged in
if (!isset($_SESSION['role']) || !isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit();
}

// Check if ID is provided in the URL
if (!isset($_GET['id'])) {
    header("Location: product_screen.php");
    exit();
}

$back_url = 'product_screen.php';
$return_to = trim((string) ($_GET['return_to'] ?? ''));
$return_parts = parse_url($return_to);
if (is_array($return_parts)
    && ($return_parts['path'] ?? '') === 'product_screen.php'
    && !isset($return_parts['scheme'])
    && !isset($return_parts['host'])
    && !isset($return_parts['user'])
    && !isset($return_parts['pass'])) {
    $return_params = [];
    parse_str($return_parts['query'] ?? '', $parsed_return_params);
    foreach (['search', 'category', 'sort', 'page', 'ai_search', 'budget_search', 'budget_medicine', 'budget_amount'] as $return_param) {
        if (isset($parsed_return_params[$return_param]) && is_scalar($parsed_return_params[$return_param])) {
            $return_params[$return_param] = (string) $parsed_return_params[$return_param];
        }
    }
    if (!empty($return_params)) {
        $back_url .= '?' . http_build_query($return_params);
    }
}

$id = intval($_GET['id']);

// Prepare and execute query for main product
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

// Check if product exists
if ($result->num_rows == 0) {
    echo "Product not found";
    exit();
}

$row = $result->fetch_assoc();
$productRatingSchema = $conn->query("CREATE TABLE IF NOT EXISTS product_ratings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    score TINYINT UNSIGNED NOT NULL,
    liked_aspects TEXT NULL,
    comment VARCHAR(500) NULL,
    is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_order_product_rating (order_id, product_id, user_id),
    INDEX idx_product_rating_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!$productRatingSchema) {
    error_log('Unable to prepare product rating storage: ' . $conn->error);
    http_response_code(503);
    exit('Product ratings are temporarily unavailable.');
}
$productRatingAspectsColumn = $conn->query("SHOW COLUMNS FROM product_ratings LIKE 'liked_aspects'");
if (!$productRatingAspectsColumn) {
    error_log('Unable to inspect product rating feedback fields: ' . $conn->error);
    http_response_code(503);
    exit('Product ratings are temporarily unavailable.');
}
if ($productRatingAspectsColumn->num_rows === 0 && !$conn->query('ALTER TABLE product_ratings ADD COLUMN liked_aspects TEXT NULL AFTER score')) {
    error_log('Unable to add product rating feedback options: ' . $conn->error);
    http_response_code(503);
    exit('Product ratings are temporarily unavailable.');
}
$productRatingAnonymousColumn = $conn->query("SHOW COLUMNS FROM product_ratings LIKE 'is_anonymous'");
if (!$productRatingAnonymousColumn) {
    error_log('Unable to inspect product rating anonymity field: ' . $conn->error);
    http_response_code(503);
    exit('Product ratings are temporarily unavailable.');
}
if ($productRatingAnonymousColumn->num_rows === 0 && !$conn->query('ALTER TABLE product_ratings ADD COLUMN is_anonymous TINYINT(1) NOT NULL DEFAULT 0 AFTER comment')) {
    error_log('Unable to add product rating anonymity field: ' . $conn->error);
    http_response_code(503);
    exit('Product ratings are temporarily unavailable.');
}
$ratingStmt = $conn->prepare(
    "SELECT AVG(pr.score) AS average_rating, COUNT(*) AS review_count
     FROM product_ratings pr
     JOIN orders o ON o.id = pr.order_id
     WHERE pr.product_id = ?
       AND pr.score BETWEEN 1 AND 5
       AND LOWER(TRIM(COALESCE(o.status, ''))) IN ('delivered', 'completed')"
);
if (!$ratingStmt) {
    error_log('Product rating summary preparation failed: ' . $conn->error);
    http_response_code(503);
    exit('Product ratings are temporarily unavailable.');
}
$ratingStmt->bind_param('i', $id);
if (!$ratingStmt->execute()) {
    error_log('Product rating summary query failed: ' . $ratingStmt->error);
    $ratingStmt->close();
    http_response_code(503);
    exit('Product ratings are temporarily unavailable.');
}
$ratingSummary = $ratingStmt->get_result()->fetch_assoc();
$ratingStmt->close();
$averageRating = (int)($ratingSummary['review_count'] ?? 0) > 0
    ? (float)$ratingSummary['average_rating']
    : 0.0;
$reviewCount = (int)($ratingSummary['review_count'] ?? 0);
$filledRatingStars = $reviewCount > 0 ? (int)round($averageRating) : 0;
$productReviewsStmt = $conn->prepare(
    "SELECT pr.score, pr.liked_aspects, pr.comment, pr.created_at,
            CASE WHEN pr.is_anonymous = 1 THEN 'Anonymous'
                 ELSE COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(u.username), ''), 'Customer')
            END AS reviewer_name
     FROM product_ratings pr
     JOIN orders o ON o.id = pr.order_id
     LEFT JOIN users u ON u.id = pr.user_id
     WHERE pr.product_id = ?
       AND pr.score BETWEEN 1 AND 5
       AND LOWER(TRIM(COALESCE(o.status, ''))) IN ('delivered', 'completed')
     ORDER BY pr.created_at DESC
     LIMIT 20"
);
if (!$productReviewsStmt) {
    error_log('Product review list preparation failed: ' . $conn->error);
    http_response_code(503);
    exit('Product reviews are temporarily unavailable.');
}
$productReviewsStmt->bind_param('i', $id);
if (!$productReviewsStmt->execute()) {
    error_log('Product review list query failed: ' . $productReviewsStmt->error);
    $productReviewsStmt->close();
    http_response_code(503);
    exit('Product reviews are temporarily unavailable.');
}
$productReviews = $productReviewsStmt->get_result();
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_limit INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("UPDATE prescription_product_access ppa
    JOIN products p ON p.id = ppa.product_id
    SET ppa.quantity_limit = GREATEST(COALESCE(p.quantity, 0), 0), ppa.quantity_purchased = 0, ppa.expires_at = NULL
    WHERE ppa.quantity_limit = 0 AND ppa.expires_at IS NOT NULL");
$prescriptionQuantityRemaining = PHP_INT_MAX;
if (!empty($row['prescription_required'])
    || strtolower(trim((string)($row['category'] ?? ''))) === 'prescription medicine') {
    $accessStmt = $conn->prepare(
        'SELECT quantity_limit, quantity_purchased FROM prescription_product_access
         WHERE product_id = ? AND user_id = ? AND quantity_limit > quantity_purchased
           AND (expires_at IS NULL OR expires_at >= NOW())
         ORDER BY granted_at DESC LIMIT 1'
    );
    if (!$accessStmt) {
        error_log('Prescription product access query preparation failed: ' . $conn->error);
        http_response_code(503);
        exit('This product is temporarily unavailable.');
    }
    $accessStmt->bind_param('ii', $id, $_SESSION['user_id']);
    if (!$accessStmt->execute()) {
        error_log('Prescription product access query failed: ' . $accessStmt->error);
        $accessStmt->close();
        http_response_code(503);
        exit('This product is temporarily unavailable.');
    }
    $prescriptionAccess = $accessStmt->get_result()->fetch_assoc();
    $accessStmt->close();
    if (!$prescriptionAccess) {
        http_response_code(404);
        exit('Product not found.');
    }
    $prescriptionQuantityRemaining = max(
        0,
        (int)$prescriptionAccess['quantity_limit'] - (int)$prescriptionAccess['quantity_purchased']
    );
}

$galleryImages = [];
$galleryStmt = $conn->prepare("SELECT COALESCE(NULLIF(image_path, ''), NULLIF(image_url, '')) AS image FROM product_images WHERE product_id = ? ORDER BY sort_order ASC");
if ($galleryStmt) {
    $galleryStmt->bind_param("i", $id);
    if ($galleryStmt->execute()) {
        $galleryResult = $galleryStmt->get_result();
        while ($galleryRow = $galleryResult->fetch_assoc()) {
            if (!empty($galleryRow['image'])) {
                $galleryImages[] = $galleryRow['image'];
            }
        }
    } else {
        error_log('Unable to load product gallery: ' . $galleryStmt->error);
    }
    $galleryStmt->close();
} else {
    error_log('Unable to prepare product gallery query: ' . $conn->error);
}
$primaryProductImage = trim((string)($row['image'] ?? ''));
if ($primaryProductImage !== '') {
    $galleryImages[] = $primaryProductImage;
}
$galleryImages = array_values(array_unique(array_filter(array_map('trim', $galleryImages))));
$imageSources = array_map('getProductImageUrl', $galleryImages);
$primaryImage = $imageSources[0] ?? 'default.png';

// Get correct price column
$price = $row['price_piece'] ?? $row['price'] ?? 0;
$productBrand = $row['brand'] ?? 'Not specified';
$productForm = $row['product_form'] ?? 'Not specified';
$productServingSize = $row['serving_size'] ?? 'Not specified';
$piecesPerBox = max(1, (int)($row['pieces_per_box'] ?? 12));
$priceBox = round((float)$price * $piecesPerBox * 0.98, 2);
$productQuantity = max(
    0,
    (int)($row['quantity'] ?? (((int)($row['pieces'] ?? 0)) + ((int)($row['box'] ?? 0) * $piecesPerBox)))
);
$batchStockStmt = $conn->prepare(
    'SELECT COUNT(*) AS batch_count,
            COALESCE(SUM(CASE
                WHEN recalled = 0 AND expiration_date >= CURDATE() THEN current_quantity
                ELSE 0
            END), 0) AS sellable_stock
     FROM product_batches
     WHERE product_id = ?'
);
if (!$batchStockStmt) {
    error_log('Product detail stock query preparation failed: ' . $conn->error);
    http_response_code(503);
    exit('Product stock is temporarily unavailable.');
}
$batchStockStmt->bind_param('i', $id);
if (!$batchStockStmt->execute()) {
    error_log('Product detail stock query failed: ' . $batchStockStmt->error);
    $batchStockStmt->close();
    http_response_code(503);
    exit('Product stock is temporarily unavailable.');
}
$batchStock = $batchStockStmt->get_result()->fetch_assoc();
$batchStockStmt->close();
if ((int)($batchStock['batch_count'] ?? 0) > 0) {
    $productQuantity = min($productQuantity, max(0, (int)$batchStock['sellable_stock']));
}
$productQuantity = min($productQuantity, $prescriptionQuantityRemaining);
$cartQuantityStmt = $conn->prepare('SELECT box, pieces FROM cart WHERE user_id = ? AND product_id = ? LIMIT 1');
if (!$cartQuantityStmt) {
    error_log('Product detail cart quantity query preparation failed: ' . $conn->error);
    http_response_code(503);
    exit('Product availability is temporarily unavailable.');
}
$cartQuantityStmt->bind_param('ii', $_SESSION['user_id'], $id);
if (!$cartQuantityStmt->execute()) {
    error_log('Product detail cart quantity query failed: ' . $cartQuantityStmt->error);
    $cartQuantityStmt->close();
    http_response_code(503);
    exit('Product availability is temporarily unavailable.');
}
$existingCartQuantity = $cartQuantityStmt->get_result()->fetch_assoc();
$cartQuantityStmt->close();
$alreadyInCartQuantity = $existingCartQuantity
    ? ((int)($existingCartQuantity['box'] ?? 0) * $piecesPerBox) + (int)($existingCartQuantity['pieces'] ?? 0)
    : 0;
$productQuantityAvailableToAdd = max(0, $productQuantity - $alreadyInCartQuantity);
$stockError = trim((string)($_SESSION['product_stock_error'] ?? ''));
$stockErrorProductId = (int)($_SESSION['product_stock_error_product_id'] ?? 0);
unset($_SESSION['product_stock_error'], $_SESSION['product_stock_error_product_id']);
if ($stockErrorProductId !== $id) {
    $stockError = '';
}
$normalizeProductText = static function ($value): string {
    $text = (string)$value;
    $text = preg_replace('~\\\\+r\\\\+n~i', "\n", $text) ?? $text;
    $text = preg_replace('~\\\\+n~i', "\n", $text) ?? $text;
    $text = preg_replace('~\\\\+r~i', "\n", $text) ?? $text;
    return preg_replace('~\\\\+([\'"])~', '$1', $text) ?? $text;
};
$productDescription = $normalizeProductText($row['description'] ?? 'No description available.');
$productIngredients = $normalizeProductText($row['ingredients'] ?? 'No ingredients listed.');
$productUsage = $normalizeProductText($row['usage_instructions'] ?? 'Use as directed by your healthcare professional.');
$productBenefits = $normalizeProductText($row['benefits'] ?? '');
if (trim($productBenefits) === '') {
    $productBenefits = implode("\n", [
        'Supports energy production and metabolism',
        'Promotes healthy nervous system function',
        'Helps maintain healthy skin, hair, and eyes',
        'Essential for red blood cell formation'
    ]);
}

// Query for dynamic "You May Also Like" items
$related_stmt = $conn->prepare(
    "SELECT p.*, COALESCE(r.average_rating, 0) AS average_rating, COALESCE(r.review_count, 0) AS review_count
     FROM products p
     LEFT JOIN (
         SELECT pr.product_id, AVG(pr.score) AS average_rating, COUNT(*) AS review_count
         FROM product_ratings pr
         JOIN orders o ON o.id = pr.order_id
         WHERE pr.score BETWEEN 1 AND 5
           AND LOWER(TRIM(COALESCE(o.status, ''))) IN ('delivered', 'completed')
         GROUP BY pr.product_id
     ) r ON r.product_id = p.id
     WHERE p.id != ? ORDER BY RAND() LIMIT 10"
);
$related_stmt->bind_param("i", $id);
$related_stmt->execute();
$related_result = $related_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($row['product_name']); ?> - Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        /* =========================
           RESET
        ========================= */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            overflow-x: hidden;
            overflow-y: auto;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",
                Roboto, Helvetica, Arial, sans-serif;
            background: #f4f6f8;
            color: #1a2333;
        }

        /* =========================
           MAIN CONTENT
        ========================= */

        .main-content {
            margin-left: 292px;
            width: calc(100% - 292px);
            min-height: calc(100vh - 72px);
            margin-top: 72px;
            padding: 36px 42px 32px;
            box-sizing: border-box;
            overflow: visible;

            display: flex;
            flex-direction: column;

            background: #f4f6f8;
        }

        /* =========================
           HEADER
        ========================= */

        .header-bar {
            height: 48px;
            min-height: 48px;

            width: 100%;
            margin: 0;

            background: #f4f6f8;

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 0;

            border-bottom: none;
        }

        .back-link {
            color: #4b5563;
            text-decoration: none;

            font-size: 15px;
            font-weight: 600;
        }

        .back-link:hover {
            color: #374151;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .icon-btn {
            width: 30px;
            height: 30px;

            background: transparent;
            border: none;

            color: #7c8ca3;

            font-size: 18px;

            cursor: pointer;

            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* =========================
           PRODUCT TOP SECTION
        ========================= */

        .product-top-section {
            width: 100%;
            height: 470px;
            min-height: 410px;
            max-height: 470px;

            margin: 10px 0 0;

            display: grid;
            grid-template-columns: minmax(280px, 0.75fr) minmax(0, 1.25fr);

            gap: 18px;

            overflow: hidden;
        }

        /* =========================
           IMAGE GALLERY
        ========================= */

        .image-gallery {
            height: 100%;
            min-height: 0;

            position: relative;
        }

        .main-image {
            width: 100%;
            max-width: 520px;
            height: 100%;
            max-height: 430px;
            margin: 0 auto;

            background: #FBEAEC;

            border: 1px solid #f1e5e2;
            border-radius: 0;

            position: relative;

            display: flex;
            align-items: center;
            justify-content: center;

            overflow: visible;
        }

        .main-image::after {
            display: none;
        }

        .main-image img {
            width: 84%;
            height: 84%;

            object-fit: contain;

            display: block;
        }

        /* =========================
           THUMBNAILS
        ========================= */

        .thumbnail-box {
            position: absolute;

            bottom: 8px;
            left: 50%;

            transform: translateX(-50%);

            z-index: 5;
        }

        .thumbnail-gallery {
            display: flex;
            align-items: center;
            justify-content: center;

            gap: 8px;
        }

        .thumbnail {
            width: 58px;
            height: 58px;
            flex: 0 0 58px;

            padding: 4px;

            background: #ffffff;

            border: 1px solid #eeeeee;
            border-radius: 8px;

            overflow: hidden;

            cursor: pointer;

            transition: 0.2s ease;
        }

        .thumbnail-gallery {
            max-width: min(520px, calc(100vw - 48px));
            overflow-x: auto;
            padding: 2px;
        }

        .thumbnail:focus-visible {
            outline: 2px solid #8B1E2D;
            outline-offset: 2px;
        }

        .thumbnail.active {
            border-color: #8B1E2D;

            box-shadow: 0 2px 8px rgba(229, 35, 50, 0.15);
        }

        .thumbnail:hover {
            border-color: #8B1E2D;
        }

        .thumbnail img {
            width: 100%;
            height: 100%;

            object-fit: contain;
        }

        /* =========================
           PRODUCT INFORMATION
        ========================= */

        .product-info {
            height: 100%;
            min-height: 0;
            padding-left: 0;
            padding-right: 24px;

            display: flex;
            flex-direction: column;

            justify-content: center;

            gap: 4px;

            overflow: hidden;
        }

        .category-badge {
            color: #8B1E2D;

            font-size: 15px;
            font-weight: 600;

            margin-bottom: 1px;
        }

        .product-title {
            font-size: 36px;
            line-height: 1.1;

            font-weight: 700;

            color: #1a2333;

            margin-bottom: 1px;
        }

        .rating-row {
            display: flex;
            align-items: center;
            gap: 7px;

            height: 20px;
        }

        .rating-stars {
            color: #ffb400;

            font-size: 17px;
            letter-spacing: 1px;
        }

        .rating-average {
            color: #263247;
            font-size: 14px;
        }

        .review-count {
            color: #7c8ca3;
            font-size: 15px;
        }
        .product-review-list { display: grid; gap: 10px; margin-top: 14px; }
        .product-review { padding: 12px; border: 1px solid #e3e8ef; border-radius: 8px; background: #fff; }
        .product-review-header { display: grid; gap: 4px; }
        .product-review-header strong { color: #263247; font-size: 13px; }
        .product-review-header span { color: #f2aa22; letter-spacing: 1px; white-space: nowrap; }
        .product-review-meta { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 5px; }
        .product-reviewer-info { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 8px; min-width: 0; }
        .product-review-aspects { display: flex; flex-wrap: wrap; gap: 4px; }
        .product-review-aspect { padding: 2px 7px; border-radius: 10px; background: #edf4ff; color: #315b91; font-size: 10px; line-height: 1.4; }
        .product-review small { color: #7c8ca3; font-size: 11px; white-space: nowrap; }
        .product-review p { margin: 8px 0 0; color: #39475c; font-size: 13px; white-space: pre-wrap; }

        .price {
            color: #8B1E2D;

            font-size: 36px;
            font-weight: 700;

            line-height: 1.1;

            margin: 3px 0;
        }

        .box-price {
            color: #7c8ca3;
            font-size: 14px;
            margin-bottom: 6px;
        }

        .status-row {
            display: flex;
            align-items: center;
            gap: 9px;

            padding-bottom: 7px;

            border-bottom: 1px solid #eeeeee;

            margin-bottom: 2px;
        }

        .stock-badge {
            color: #21a45a;

            font-size: 15px;
            font-weight: 600;

            display: flex;
            align-items: center;

            gap: 5px;
        }

        .stock-quantity {
            color: #7c8ca3;
            font-size: 14px;
        }

        .stock-warning {
            display: none;
            margin: 8px 0 2px;
            padding: 10px 12px;
            border: 1px solid #f0c5ca;
            border-radius: 8px;
            background: #fff4f5;
            color: #8b1e2d;
            font-size: 13px;
            line-height: 1.45;
        }

        .stock-warning.is-visible {
            display: block;
        }

        /* =========================
           BENEFITS
        ========================= */

        .benefits-list {
            display: flex;
            flex-direction: column;

            gap: 4px;

            margin: 2px 0 5px;
        }

        .benefit-item {
            display: flex;
            align-items: center;

            gap: 7px;

            color: #4b5563;

            font-size: 14px;

            line-height: 1.2;
        }

        .benefit-icon {
            width: 14px;
            height: 14px;

            flex-shrink: 0;

            color: #8B1E2D;
        }

        .product-benefits-card {
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: #eff8f5;
            border-color: #e3f0eb;
        }

        .product-benefits-title {
            color: #1a2333;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2;
        }

        .product-benefits-card .benefits-list {
            gap: 7px;
            margin: 0;
        }

        .product-benefits-card .benefit-item {
            gap: 8px;
            color: #536b7d;
            font-size: 14px;
            line-height: 1.25;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .product-benefits-card .benefit-icon {
            width: 16px;
            height: 16px;
            color: #15945e;
        }

        /* =========================
           QUANTITY
        ========================= */

        .quantity-section {
            display: flex;
            flex-wrap: wrap;
            align-items: center;

            gap: 12px;

            margin-top: 2px;
        }

        .quantity-label {
            color: #1a2333;

            font-size: 15px;
            font-weight: 600;
        }

        .quantity-control {
            height: 34px;
            flex: 0 0 auto;

            display: flex;
            align-items: center;

            border: 1px solid #e5e5e5;
            border-radius: 7px;

            overflow: hidden;

            background: #ffffff;
        }

        .quantity-btn {
            width: 34px;
            height: 34px;

            border: none;

            background: #fafafa;

            color: #8B1E2D;

            font-size: 17px;

            cursor: pointer;
        }

        .quantity-btn:hover {
            background: #fff1f2;
        }

        .quantity-btn:disabled,
        .btn:disabled,
        .btn:disabled:hover {
            background: #d8dadd;
            border-color: #d8dadd;
            color: #6b7280;
            cursor: not-allowed;
            opacity: .8;
            transform: none;
        }

        .quantity-input {
            width: 40px;
            height: 34px;

            border: none;

            background: #ffffff;

            text-align: center;

            font-size: 13px;
            font-weight: 600;

            outline: none;
        }

        /* Remove number input arrows */
        .quantity-input::-webkit-inner-spin-button,
        .quantity-input::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .quantity-input {
            -moz-appearance: textfield;
        }

        .unit-select {
            height: 34px;
            padding: 0 10px;
            border: 1px solid #e5e5e5;
            border-radius: 7px;
            background: #ffffff;
            color: #1a2333;
            font-size: 13px;
            font-weight: 600;
            outline: none;
            cursor: pointer;
        }

        .unit-select:focus {
            border-color: #8B1E2D;
        }

        /* =========================
           ACTION BUTTONS
        ========================= */

        .action-buttons {
            width: 72%;
            max-width: 560px;
            position: relative;

            display: flex;

            gap: 10px;

            margin-top: 5px;
        }

        .action-buttons form {
            flex: 1;
        }

        .purchase-unit-select {
            flex: 0 1 180px;
            min-width: 0;
            max-width: 100%;
        }

        .btn {
            width: 100%;

            height: 48px;

            border-radius: 8px;

            font-size: 15px;
            font-weight: 700;

            cursor: pointer;

            display: flex;
            align-items: center;
            justify-content: center;

            gap: 7px;

            transition: 0.2s ease;
        }

        .cart-btn {
            background: #ffffff;

            color: #8B1E2D;

            border: 1.5px solid #8B1E2D;
        }

        .cart-btn:hover {
            background: #fff5f5;
        }

        .buy-btn {
            background: #8B1E2D;

            color: #ffffff;

            border: 1.5px solid #8B1E2D;
        }

        .buy-btn:hover {
            background: #bd1d2a;
        }

        @media (max-width: 720px) {
            .action-buttons {
                width: 100%;
            }
        }

        /* =========================
           DESCRIPTION / SPECS
        ========================= */

        .tabs-and-specs-container {
            width: 100%;

            height: 300px;
            min-height: 300px;
            max-height: 300px;

            margin: 16px 0 0;

            background: #ffffff;

            border: 1px solid #eeeeee;

            border-radius: 14px;

            padding: 6px 16px 8px;

            display: flex;

            gap: 4px;

            overflow: hidden;
        }

        .tabs-left-side {
            flex: 2;

            min-width: 0;

            overflow: hidden;
        }

        .tabs-header {
            min-height: 31px;
            height: auto;

            display: flex;
            align-items: center;
            flex-wrap: wrap;

            gap: 4px 20px;

            border-bottom: 1px solid #eeeeee;

            margin-bottom: 5px;
        }

        .tab-btn {
            height: 31px;

            background: none;
            border: none;

            color: #7c8ca3;

            font-size: 16px;
            font-weight: 600;

            cursor: pointer;

            position: relative;
        }

        .tab-btn.active {
            color: #8B1E2D;
        }

        .tab-btn.active::after {
            content: "";

            position: absolute;

            left: 0;
            right: 0;

            bottom: -1px;

            height: 2px;

            background: #8B1E2D;

            border-radius: 5px;
        }

        .tab-content {
            display: none;

            height: 130px;

            overflow: hidden;

            color: #687386;

            font-size: 14px;

            line-height: 1.35;
        }

        .tab-content.active {
            display: block;
        }

        .tab-content p {
            margin: 0;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        /* =========================
           SPECIFICATIONS
        ========================= */

        .specs-right-card {
            flex: 0 0 32%;

            height: 100%;

            background: #eee0e2; /* bg ng pink */

            border: 1px solid #ffebee;

            border-radius: 12px;

            padding: 14px 18px;

            overflow: hidden;
        }

        .product-info .specs-right-card {
            flex: 0 1 auto;
            width: min(100%, 420px);
            height: auto;
            margin-bottom: 6px;
            padding: 0;
            background: transparent;
            border: 0;
            border-radius: 0;
            overflow: visible;
        }

        .product-info .spec-row {
            grid-template-columns: 26px minmax(0, 1fr);
            align-items: start;
            gap: 10px;
            margin-bottom: 12px;
        }

        .product-info .spec-icon {
            display: block;
            width: 22px;
            height: 22px;
            margin-top: 1px;
            color: #60788b;
            stroke: currentColor;
            stroke-width: 1.8;
            fill: none;
        }

        .product-info .spec-details {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .product-info .spec-label {
            color: #536b7d;
            font-weight: 600;
            font-size: 14px;
            line-height: 1.2;
        }

        .product-info .spec-value {
            color: #60788b;
            font-weight: 400;
            font-size: 14px;
            line-height: 1.2;
        }

        .specs-right-card .benefits-list {
            margin: 0;
        }

        .specs-right-card .benefit-item {
            font-size: 14px;
        }

        .spec-row {
            display: grid;
            grid-template-columns: 185px minmax(0, 1fr);
            align-items: center;

            gap: 10px;

            margin-bottom: 10px;

            font-size: 16px;

            line-height: 1.35;
        }

        .spec-row:last-child {
            margin-bottom: 0;
        }

        .spec-label {
            color: #7c8ca3;

            white-space: nowrap;
        }

        .spec-value {
            color: #1a2333;

            font-weight: 600;

            text-align: left;

            white-space: nowrap;
        }

        /* =========================
           RELATED PRODUCTS
        ========================= */

        .related-section {
            width: 100%;

            height: 240px;
            min-height: 240px;
            max-height: none;

            margin: 6px 0 0;

            background: #ffffff;

            border: 1px solid #eeeeee;

            border-radius: 14px;

            padding: 9px 14px;

            overflow: hidden;
        }

        .related-title {
            font-size: 16px;

            font-weight: 700;

            color: #1a2333;

            height: 20px;

            line-height: 20px;
        }

        .related-wrapper {
            position: relative;

            height: 190px;

            margin-top: 4px;

            display: flex;
            align-items: center;
        }

        .related-grid {
            width: 100%;
            height: 100%;

            display: flex;

            gap: 9px;

            overflow-x: auto;
            overflow-y: hidden;

            scroll-behavior: smooth;

            padding: 2px 20px;

            scrollbar-width: none;
        }

        .related-grid::-webkit-scrollbar {
            display: none;
        }

        .related-card {
            width: 170px;
            min-width: 170px;

            height: 176px;

            background: #ffffff;

            border: 1px solid #eeeeee;

            border-radius: 9px;

            padding: 7px 9px;

            display: flex;
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;

            gap: 5px;

            text-decoration: none;

            overflow: hidden;
            text-align: left;

            transition: 0.2s ease;
        }

        .related-card:hover {
            border-color: #8B1E2D;

            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.06);
        }

        .related-card img {
            width: 100%;
            height: 76px;

            flex-shrink: 0;

            object-fit: contain;
        }

        .related-info {
            min-width: 0;
            width: 100%;
        }

        .related-info h4 {
            color: #1a2333;

            font-size: 12px;
            font-weight: 700;

            line-height: 1.25;

            margin-bottom: 3px;

            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;

            overflow: hidden;
        }

        .related-category {
            color: #7c8ca3;
            font-size: 11px;
            line-height: 1.2;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .related-price {
            color: #8B1E2D;

            font-size: 12px;
            font-weight: 700;

            margin-bottom: 2px;
        }

        .related-rating {
            color: #ffb400;

            font-size: 11px;
        }

        /* =========================
           SLIDER BUTTONS
        ========================= */

        .slider-btn {
            position: absolute;

            top: 50%;

            transform: translateY(-50%);

            z-index: 10;

            width: 28px;
            height: 28px;

            border-radius: 50%;

            border: 1px solid #eeeeee;

            background: #ffffff;

            color: #8B1E2D;

            cursor: pointer;

            display: flex;
            align-items: center;
            justify-content: center;

            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .prev-btn {
            left: 0;
        }

        .next-btn {
            right: 0;
        }

        /* =========================
           DESKTOP HEIGHT ADJUSTMENTS
        ========================= */

        @media (min-height: 850px) {
            .main-image {
                width: 100%;
                max-width: 504px;
                height: 100%;
                max-height: none;
                margin: 0 auto;
            }

            .product-top-section {
                height: 478px;
                min-height: 478px;
                max-height: 478px;
            }

            .tabs-and-specs-container {
                height: 320px;
                min-height: 320px;
                max-height: 320px;
            }

        }

        @media (min-height: 850px) and (max-height: 999px) {
            .product-top-section {
                height: calc(100vh - 460px);
                min-height: 390px;
                max-height: 450px;
            }

            .tabs-and-specs-container {
                height: 250px;
                min-height: 250px;
                max-height: 250px;
            }

        }

        /* =========================
           SMALL DESKTOP SCREENS
        ========================= */

        @media (max-height: 700px) {
            .main-image {
                width: 100%;
                max-width: 304px;
                height: 100%;
                margin: 0 auto;
            }

            .header-bar {
                height: 44px;
                min-height: 44px;
            }

            .product-top-section {
                height: calc(100vh - 395px);
                min-height: 300px;
                max-height: 350px;

                margin-top: 6px;
                margin-bottom: 6px;
            }

            .tabs-and-specs-container {
                height: 185px;
                min-height: 185px;
                max-height: 185px;
            }

            .related-section {
                margin-top: 6px;
            }

            .product-title {
                font-size: 32px;
            }

            .price {
                font-size: 32px;
            }

            .thumbnail {
                width: 52px;
                height: 52px;
            }

            .btn {
                height: 34px;
            }
        }

        @media (min-height: 701px) and (max-height: 849px) {
            .product-top-section {
                height: calc(100vh - 430px);
                min-height: 330px;
                max-height: 410px;
            }

            .tabs-and-specs-container {
                height: 215px;
                min-height: 215px;
                max-height: 215px;
            }

        }

        /* =========================
           MOBILE / TABLET
        ========================= */

        @media (max-width: 900px) {
            html,
            body {
                overflow: auto;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                height: auto;
                min-height: 100vh;
                margin-top: 72px;
                padding: 24px 18px 20px;
                overflow: visible;
            }

            .header-bar,
            .product-top-section, 
            .tabs-and-specs-container,
            .related-section {
                width: calc(100% - 4px);
            }

            .product-top-section {
                height: auto;
                min-height: 0;
                max-height: none;

                grid-template-columns: 1fr;
                gap: 22px;

                overflow: visible;
            }

            .image-gallery {
                height: 350px;
            }

            .main-image {
                width: 100%;
                height: 100%;
                max-height: none;
                margin: 0 auto;
            }

            .product-info {
                height: auto;
                overflow: visible;
                padding-left: 0;
                transform: none;
            }

            .product-info > .quantity-section {
                order: 1;
                flex-shrink: 0;
            }

            .product-info > .action-buttons {
                order: 2;
                width: 100%;
                flex-shrink: 0;
            }

            .product-info > .specs-right-card {
                order: 3;
            }

            .tabs-and-specs-container {
                height: auto;
                min-height: 180px;
                max-height: none;

                flex-direction: column;

                margin: 16px auto 0;
            }

            .specs-right-card {
                flex: none;
                height: auto;
                transform: none;
            }

            .spec-row {
                display: flex;
                justify-content: space-between;
                gap: 10px;
            }

            .related-section {
                height: 240px;
                min-height: 240px;
                max-height: none;

                margin: 8px auto 0;
            }
        }

        @media (min-width: 901px) {
            .product-top-section {
                height: auto;
                min-height: 0;
                max-height: none;
                overflow: visible;
                align-items: stretch;
            }

            .image-gallery {
                height: auto;
                min-height: 0;
                align-self: stretch;
                display: flex;
            }

            .main-image {
                flex: 1;
                height: auto;
                max-height: none;
            }

            .product-info {
                height: auto;
                min-height: 0;
                justify-content: flex-start;
                overflow: visible;
            }

            .product-info > .quantity-section {
                order: 1;
                flex-shrink: 0;
            }

            .product-info > .action-buttons {
                order: 2;
                width: 100%;
                flex-shrink: 0;
            }

            .product-info > .specs-right-card {
                order: 3;
            }

            .tabs-and-specs-container {
                height: auto;
                min-height: 220px;
                max-height: none;
                overflow: visible;
            }

            .tabs-left-side {
                overflow: visible;
            }

            .tab-content {
                height: auto;
                min-height: 130px;
                overflow: visible;
            }

            .specs-right-card.product-benefits-card {
                height: auto;
                min-height: 0;
                overflow: visible;
            }
        }
    </style>
</head>

<body>

    <?php include 'sidebar_user.php'; ?>

    <main class="main-content">

        <!-- =========================
             HEADER
        ========================= -->
        <header class="header-bar">

            <a href="<?= htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8') ?>" class="back-link">← Back to Products</a>

        </header>

        <?php if (($_GET['prescription_access'] ?? '') === 'expired'): ?>
            <div role="alert" style="margin:12px 0;padding:12px 14px;border:1px solid #f0b7c0;border-radius:7px;background:#fff0f2;color:#9f1d35;font-size:13px;">The pharmacist-set quantity for this prescription may have been reached. Please check the remaining quantity or contact the pharmacy in Customer Chat.</div>
        <?php endif; ?>

        <!-- =========================
             PRODUCT SECTION
        ========================= -->
        <section class="product-top-section">

            <!-- PRODUCT IMAGE -->
            <div class="image-gallery">

                <div class="main-image">

                    <img id="mainImage" src="<?php echo htmlspecialchars($primaryImage, ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($row['product_name']); ?>">

                    <!-- THUMBNAILS -->
                    <div class="thumbnail-box">

                        <div class="thumbnail-gallery">
                            <?php foreach ($imageSources as $imageIndex => $imageSource): ?>
                                <button type="button" class="thumbnail<?php echo $imageIndex === 0 ? ' active' : ''; ?>" data-image-src="<?php echo htmlspecialchars($imageSource, ENT_QUOTES, 'UTF-8'); ?>" aria-label="View product image <?php echo $imageIndex + 1; ?>" aria-pressed="<?php echo $imageIndex === 0 ? 'true' : 'false'; ?>">
                                    <img src="<?php echo htmlspecialchars($imageSource, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                </button>
                            <?php endforeach; ?>

                        </div>

                    </div>

                </div>

            </div>


            <!-- PRODUCT INFORMATION -->
            <div class="product-info">

                <div class="category-badge">
                    <?php
                    echo htmlspecialchars(
                        $row['category'] ?? 'Vitamins'
                    );
                    ?>
                </div>

                <h1 class="product-title">
                    <?php
                    echo htmlspecialchars(
                        $row['product_name']
                    );
                    ?>
                </h1>

                <!-- RATING -->
                <div class="rating-row">

                    <?php if ($reviewCount > 0): ?>
                        <div class="rating-stars" role="img" aria-label="<?php echo number_format($averageRating, 1); ?> out of 5 stars">
                            <?php echo str_repeat('★', $filledRatingStars) . str_repeat('☆', 5 - $filledRatingStars); ?>
                        </div>
                        <strong class="rating-average"><?php echo number_format($averageRating, 1); ?></strong>
                        <span class="review-count">(<?php echo number_format($reviewCount); ?> <?php echo $reviewCount === 1 ? 'review' : 'reviews'; ?>)</span>
                    <?php else: ?>
                        <div class="rating-stars" role="img" aria-label="No ratings yet">☆☆☆☆☆</div>
                        <span class="review-count">No reviews yet</span>
                    <?php endif; ?>

                </div>

                <!-- PRICE -->
                <div
                    class="price"
                    id="displayPrice"
                    data-piece-price="<?php echo htmlspecialchars((string)$price, ENT_QUOTES); ?>"
                    data-bottle-price="<?php echo htmlspecialchars((string)$priceBox, ENT_QUOTES); ?>"
                >
                    ₱<?php echo number_format($price, 2); ?>
                </div>
                <?php if ((float)$priceBox > 0): ?>
                    <div class="box-price" id="priceUnitLabel">Per box: ₱<?php echo number_format($priceBox, 2); ?></div>
                <?php endif; ?>

                <!-- STOCK -->
                <div class="status-row">

                    <span class="stock-badge" style="<?php echo $productQuantity > 0 ? '' : 'color:#b42332;'; ?>">
                        <?php echo $productQuantity > 0 ? '✓ In Stock' : '✕ Out of Stock'; ?>
                    </span>

                    <span class="stock-quantity">
                        | <?php echo number_format((int)$productQuantity); ?> in stock
                    </span>

                </div>
                <div class="stock-warning<?php echo ($stockError !== '' || $productQuantity < 1) ? ' is-visible' : ''; ?>" id="stockWarning" role="alert" aria-live="polite" tabindex="-1">
                    <?php echo htmlspecialchars($stockError !== '' ? $stockError : ($productQuantity < 1 ? 'Walang available na stock ngayon. Hindi makakapagpatuloy sa pag-order ng produktong ito.' : ''), ENT_QUOTES, 'UTF-8'); ?>
                </div>


                <!-- PRODUCT SPECIFICATIONS -->
                <div class="specs-right-card">
                    <div class="spec-row">
                        <svg class="spec-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 5.5h11l5 5v8H4z"></path>
                            <circle cx="9" cy="10" r="1.4"></circle>
                            <path d="m15 5.5 5 5"></path>
                        </svg>
                        <span class="spec-details">
                            <span class="spec-label">Brand</span>
                            <span class="spec-value"><?php echo htmlspecialchars($productBrand); ?></span>
                        </span>
                    </div>
                    <div class="spec-row">
                        <svg class="spec-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M8.2 5.2a4.2 4.2 0 0 1 5.9 0l4.7 4.7a4.2 4.2 0 0 1-5.9 5.9l-4.7-4.7a4.2 4.2 0 0 1 0-5.9Z"></path>
                            <path d="m10 13 6-6"></path>
                        </svg>
                        <span class="spec-details">
                            <span class="spec-label">Form</span>
                            <span class="spec-value"><?php echo htmlspecialchars($productForm); ?></span>
                        </span>
                    </div>
                    <div class="spec-row">
                        <svg class="spec-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M8 3h8"></path>
                            <path d="M9 3v4l-3.5 10.2A2.8 2.8 0 0 0 8.2 21h7.6a2.8 2.8 0 0 0 2.7-3.8L15 7V3"></path>
                            <path d="M7.2 15h9.6"></path>
                        </svg>
                        <span class="spec-details">
                            <span class="spec-label">Volume</span>
                            <span class="spec-value"><?php echo htmlspecialchars($productServingSize); ?></span>
                        </span>
                    </div>
                </div>


                <!-- QUANTITY -->
                <div class="quantity-section">

                    <label class="quantity-label">
                        Quantity:
                    </label>

                    <div class="quantity-control">

                        <button
                            type="button"
                            class="quantity-btn"
                            aria-label="Decrease quantity"
                            <?php echo $productQuantityAvailableToAdd < 1 ? 'disabled' : ''; ?>
                        >
                            −
                        </button>

                        <input
                            type="number"
                            class="quantity-input"
                            value="1"
                            min="1"
                            max="<?php echo (int)$productQuantityAvailableToAdd; ?>"
                            data-available-stock="<?php echo (int)$productQuantityAvailableToAdd; ?>"
                            data-pieces-per-box="<?php echo (int)$piecesPerBox; ?>"
                            data-server-stock-error="<?php echo htmlspecialchars($stockError, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $productQuantityAvailableToAdd < 1 ? 'disabled' : ''; ?>
                        >

                        <button
                            type="button"
                            class="quantity-btn"
                            aria-label="Increase quantity"
                            <?php echo $productQuantityAvailableToAdd < 1 ? 'disabled' : ''; ?>
                        >
                            +
                        </button>

                    </div>

                    <select class="unit-select purchase-unit-select" id="unitSelect" aria-label="Select purchase unit">
                        <option value="piece">Per Piece</option>
                        <option value="bottle" <?php echo $productQuantityAvailableToAdd < $piecesPerBox ? 'disabled title="Not enough sellable stock remaining for one full box"' : ''; ?>>Per Box (<?php echo $piecesPerBox; ?> pcs, 2% off)</option>
                    </select>

                </div>


                <!-- ACTION BUTTONS -->
                <div class="action-buttons">

                    <!-- ADD TO CART -->
                    <form
                        id="cartForm"
                        method="POST"
                        action="add_to_cart.php"
                    >

                        <input
                            type="hidden"
                            name="product_id"
                            value="<?php echo $row['id']; ?>"
                        >

                        <input
                            type="hidden"
                            name="quantity"
                            class="hidden-qty"
                            value="1"
                        >

                        <input type="hidden" name="unit" class="hidden-unit" value="piece">

                        <button
                            type="submit"
                            class="btn cart-btn"
                            <?php echo $productQuantityAvailableToAdd < 1 ? 'disabled' : ''; ?>
                        >
                            🛒 Add to Cart
                        </button>

                    </form>


                    <!-- BUY NOW -->
                    <form
                        id="buyForm"
                        method="GET"
                        action="receipt.php"
                    >

                        <input
                            type="hidden"
                            name="product_id"
                            value="<?php echo $row['id']; ?>"
                        >

                        <input
                            type="hidden"
                            name="quantity"
                            class="hidden-qty"
                            value="1"
                        >

                        <input type="hidden" name="unit" class="hidden-unit" value="piece">

                        <button
                            type="submit"
                            class="btn buy-btn"
                            <?php echo $productQuantityAvailableToAdd < 1 ? 'disabled' : ''; ?>
                        >
                            ⚡ Buy Now
                        </button>

                    </form>

                </div>

            </div>

        </section>


        <!-- =========================
             DESCRIPTION + SPECS
        ========================= -->
        <section class="tabs-and-specs-container">

            <div class="tabs-left-side">

                <div class="tabs-header">

                    <button
                        class="tab-btn active"
                        onclick="switchTab(event, 'description')"
                    >
                        Description
                    </button>

                    <button
                        class="tab-btn"
                        onclick="switchTab(event, 'ingredients')"
                    >
                        Ingredients
                    </button>

                    <button
                        class="tab-btn"
                        onclick="switchTab(event, 'usage')"
                    >
                        Usage
                    </button>

                    <button
                        class="tab-btn"
                        onclick="switchTab(event, 'reviews')"
                    >
                        Reviews (<?php echo number_format($reviewCount); ?>)
                    </button>

                </div>


                <!-- DESCRIPTION -->
                <div
                    id="description"
                    class="tab-content active"
                >
                    <p><?php echo htmlspecialchars($productDescription, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>


                <!-- INGREDIENTS -->
                <div
                    id="ingredients"
                    class="tab-content"
                >
                    <p><?php echo htmlspecialchars($productIngredients, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>


                <!-- USAGE -->
                <div
                    id="usage"
                    class="tab-content"
                >
                    <p><?php echo htmlspecialchars($productUsage, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>


                <!-- REVIEWS -->
                <div
                    id="reviews"
                    class="tab-content"
                >
                    <?php if ($reviewCount > 0): ?>
                        <p>Average Rating: <strong>⭐ <?php echo number_format($averageRating, 1); ?>/5</strong> based on <?php echo number_format($reviewCount); ?> verified <?php echo $reviewCount === 1 ? 'review' : 'reviews'; ?>.</p>
                        <div class="product-review-list">
                            <?php while ($productReview = $productReviews->fetch_assoc()): ?>
                                <?php
                                    $reviewerName = trim((string) $productReview['reviewer_name']);
                                    $reviewAspectLabels = [
                                        'good_quality' => 'Good quality',
                                        'works_well' => 'Works well',
                                        'good_packaging' => 'Good packaging',
                                        'as_described' => 'As described'
                                    ];
                                    $reviewAspects = array_intersect_key(
                                        array_flip(explode(',', (string) ($productReview['liked_aspects'] ?? ''))),
                                        $reviewAspectLabels
                                    );
                                    if ($reviewerName !== '' && strcasecmp($reviewerName, 'Anonymous') !== 0) {
                                        $reviewerName = implode(' ', array_map(
                                            static function ($part) {
                                                $characters = preg_split('//u', $part, -1, PREG_SPLIT_NO_EMPTY);
                                                return count($characters) > 1 ? $characters[0] . str_repeat('*', count($characters) - 1) : $part;
                                            },
                                            preg_split('/\s+/u', $reviewerName, -1, PREG_SPLIT_NO_EMPTY)
                                        ));
                                    }
                                ?>
                                <article class="product-review">
                                    <div class="product-review-header">
                                        <span><?php echo str_repeat('★', (int)$productReview['score']) . str_repeat('☆', 5 - (int)$productReview['score']); ?></span>
                                        <div class="product-review-meta">
                                            <div class="product-reviewer-info">
                                                <strong><?php echo htmlspecialchars($reviewerName, ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <?php if ($reviewAspects): ?>
                                                    <div class="product-review-aspects" aria-label="Selected feedback">
                                                        <?php foreach (array_keys($reviewAspects) as $reviewAspect): ?>
                                                            <span class="product-review-aspect"><?php echo htmlspecialchars($reviewAspectLabels[$reviewAspect], ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <small><?php echo htmlspecialchars(date('M j, Y', strtotime($productReview['created_at'])), ENT_QUOTES, 'UTF-8'); ?></small>
                                        </div>
                                    </div>
                                    <?php if (trim((string)($productReview['comment'] ?? '')) !== ''): ?>
                                        <p><?php echo nl2br(htmlspecialchars($productReview['comment'], ENT_QUOTES, 'UTF-8')); ?></p>
                                    <?php endif; ?>
                                </article>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p>No verified product reviews yet. Be the first to rate this product after your order is delivered.</p>
                    <?php endif; ?>
                </div>

            </div>


            <!-- KEY BENEFITS -->
            <div class="specs-right-card product-benefits-card">
                <h3 class="product-benefits-title">Key Benefits</h3>
                <div class="benefits-list">
                    <div class="benefit-item"><span><?php echo htmlspecialchars($productBenefits, ENT_QUOTES, 'UTF-8'); ?></span></div>
                </div>
            </div>

        </section>


        <!-- =========================
             YOU MAY ALSO LIKE
        ========================= -->
        <section class="related-section">

            <h3 class="related-title">
                You May Also Like
            </h3>

            <div class="related-wrapper">

                <button
                    class="slider-btn prev-btn"
                    onclick="slide('left')"
                    type="button"
                >
                    ❮
                </button>


                <div
                    class="related-grid"
                    id="relatedSlider"
                >

                    <?php

                    if ($related_result->num_rows > 0) {

                        while ($rel = $related_result->fetch_assoc()) {

                            $rel_price =
                                $rel['price_piece']
                                ?? $rel['price']
                                ?? 0;

                            $rel_img =
                                (strpos($rel['image'], 'http') === 0)
                                ? $rel['image']
                                : 'uploads/' . $rel['image'];

                    ?>

                            <a
                                href="view_product.php?id=<?php echo $rel['id']; ?>"
                                class="related-card"
                            >

                                <img
                                    src="<?php echo $rel_img; ?>"
                                    alt="<?php echo htmlspecialchars($rel['product_name']); ?>"
                                >

                                <div class="related-info">

                                    <h4>
                                        <?php
                                        echo htmlspecialchars(
                                            $rel['product_name']
                                        );
                                        ?>
                                    </h4>

                                    <div class="related-category">
                                        <?php echo htmlspecialchars($rel['category'] ?? ''); ?>
                                    </div>

                                    <div class="related-price">
                                        ₱<?php
                                        echo number_format(
                                            $rel_price,
                                            2
                                        );
                                        ?>
                                    </div>

                                    <div class="related-rating">
                                        <?php if ((int)$rel['review_count'] > 0): ?>
                                            ★ <?php echo number_format((float)$rel['average_rating'], 1); ?> (<?php echo number_format((int)$rel['review_count']); ?>)
                                        <?php else: ?>
                                            No reviews yet
                                        <?php endif; ?>
                                    </div>

                                </div>

                            </a>

                    <?php

                        }

                    }

                    ?>

                </div>


                <button
                    class="slider-btn next-btn"
                    onclick="slide('right')"
                    type="button"
                >
                    ❯
                </button>

            </div>

        </section>

    </main>


    <!-- =========================
         JAVASCRIPT
    ========================= -->

    <script>

        /* =========================
           CHANGE PRODUCT IMAGE
        ========================= */

        function changeImage(thumbnail) {

            document
                .querySelectorAll(".thumbnail")
                .forEach(function (item) {

                    item.classList.remove("active");
                    item.setAttribute("aria-pressed", "false");

                });

            thumbnail.classList.add("active");
            thumbnail.setAttribute("aria-pressed", "true");
            document.getElementById("mainImage").src =
                thumbnail.dataset.imageSrc;
        }

        document.querySelectorAll(".thumbnail").forEach(function (thumbnail) {
            thumbnail.addEventListener("click", function () {
                changeImage(thumbnail);
            });
        });


        /* =========================
           PRODUCT TABS
        ========================= */

        function switchTab(event, tabName) {

            document
                .querySelectorAll(".tab-content")
                .forEach(function (tab) {

                    tab.classList.remove("active");

                });

            document
                .querySelectorAll(".tab-btn")
                .forEach(function (button) {

                    button.classList.remove("active");

                });

            document
                .getElementById(tabName)
                .classList.add("active");

            event.currentTarget.classList.add("active");
        }


        /* =========================
           RELATED PRODUCT SLIDER
        ========================= */

        function slide(direction) {

            const slider =
                document.getElementById("relatedSlider");

            const scrollAmount = 210;

            if (direction === "left") {

                slider.scrollLeft -= scrollAmount;

            } else {

                slider.scrollLeft += scrollAmount;

            }
        }


        /* =========================
           QUANTITY CONTROL
        ========================= */

        const quantityInput = document.querySelector(".quantity-input");
        const quantityWarning = document.getElementById("stockWarning");
        const purchaseButtons = document.querySelectorAll("#cartForm button[type='submit'], #buyForm button[type='submit']");
        const quantityButtons = document.querySelectorAll(".quantity-btn");
        const unitSelect = document.querySelector("#unitSelect");
        const availableStock = parseInt(quantityInput.dataset.availableStock, 10) || 0;
        const piecesPerBox = Math.max(1, parseInt(quantityInput.dataset.piecesPerBox, 10) || 1);
        const serverStockError = quantityInput.dataset.serverStockError || "";
        let quantityWasChanged = false;

        function getAvailableUnits() {
            return unitSelect.value === "bottle"
                ? Math.floor(availableStock / piecesPerBox)
                : availableStock;
        }

        function updatePurchaseState(showLimitWarning) {
            const value = Number.parseInt(quantityInput.value, 10);
            const maxAvailable = getAvailableUnits();
            const validQuantity = Number.isInteger(value) && value >= 1 && value <= maxAvailable;
            let warning = "";

            if (maxAvailable < 1) {
                warning = unitSelect.value === "bottle"
                    ? "Walang buong kahon na available sa stock. Pumili ng ibang unit o produkto."
                    : "Walang available na stock ngayon. Hindi makakapagpatuloy sa pag-order ng produktong ito.";
            } else if (!Number.isInteger(value) || value < 1) {
                warning = "Maglagay ng quantity na hindi bababa sa 1.";
            } else if (value > maxAvailable) {
                warning = "Kulang ang stock para sa quantity na pinili. " + maxAvailable + " " + (unitSelect.value === "bottle" ? "kahon" : "piraso") + " lang ang available.";
            } else if (showLimitWarning && value >= maxAvailable) {
                warning = "Hanggang " + maxAvailable + " " + (unitSelect.value === "bottle" ? "kahon" : "piraso") + " lang ang available na stock.";
            } else if (!quantityWasChanged && serverStockError) {
                warning = serverStockError;
            }

            quantityWarning.textContent = warning;
            quantityWarning.classList.toggle("is-visible", warning !== "");
            purchaseButtons.forEach(function (button) {
                button.disabled = !validQuantity || (!quantityWasChanged && serverStockError !== "");
            });
            quantityInput.setAttribute("max", String(maxAvailable));
            quantityButtons.forEach(function (button) {
                button.disabled = availableStock < 1;
            });
            document.querySelectorAll(".hidden-qty").forEach(function (hidden) {
                hidden.value = Number.isInteger(value) && value > 0 ? value : 1;
            });
        }

        document
            .querySelectorAll(".quantity-btn")
            .forEach(function (button) {

                button.addEventListener(
                    "click",
                    function (event) {

                        event.preventDefault();

                        const input =
                            this.parentElement
                                .querySelector(".quantity-input");

                        let value = Number.parseInt(input.value, 10) || 1;
                        const maxAvailable = getAvailableUnits();
                        quantityWasChanged = true;

                        if (this.textContent.trim() === "+") {
                            if (value < maxAvailable) {
                                value++;
                            } else {
                                updatePurchaseState(true);
                                return;
                            }

                        } else {
                            if (value > 1) {
                                value--;
                            }
                        }

                        input.value = value;
                        updatePurchaseState(false);

                    }
                );

            });


        /* =========================
           DIRECT QUANTITY INPUT
        ========================= */

        document
            .querySelector(".quantity-input")
            .addEventListener(
                "input",
                function () {
                    quantityWasChanged = true;
                    updatePurchaseState(false);
                }
            );

        document.querySelectorAll("#cartForm, #buyForm").forEach(function (form) {
            form.addEventListener("submit", function (event) {
                quantityWasChanged = true;
                updatePurchaseState(false);
                const value = Number.parseInt(quantityInput.value, 10);
                if (!Number.isInteger(value) || value < 1 || value > getAvailableUnits()) {
                    event.preventDefault();
                    quantityWarning.focus();
                    return;
                }

                if (form.id === "cartForm") {
                    event.preventDefault();
                    const submitButton = form.querySelector("button[type='submit']");
                    const originalButtonText = submitButton.textContent;
                    submitButton.disabled = true;
                    submitButton.textContent = "Adding...";

                    fetch(form.action, {
                        method: "POST",
                        body: new FormData(form),
                        headers: { "X-Requested-With": "XMLHttpRequest" }
                    })
                        .then(async function (response) {
                            const contentType = response.headers.get("content-type") || "";
                            const result = contentType.includes("application/json")
                                ? await response.json()
                                : { success: false, message: await response.text() };
                            if (!response.ok || !result.success) {
                                throw new Error(result.message || "Hindi maidagdag sa cart. Subukan ulit.");
                            }
                            return result;
                        })
                        .then(function (result) {
                            const cartBadge = document.getElementById("cartCountBadge");
                            if (cartBadge) {
                                cartBadge.textContent = String(result.cart_count);
                                cartBadge.style.display = result.cart_count > 0 ? "" : "none";
                            }
                            quantityWarning.textContent = "";
                            quantityWarning.classList.remove("is-visible");
                            submitButton.textContent = originalButtonText;
                            submitButton.disabled = false;
                        })
                        .catch(function (error) {
                            quantityWarning.textContent = error.message;
                            quantityWarning.classList.add("is-visible");
                            quantityWarning.focus();
                            submitButton.textContent = originalButtonText;
                            submitButton.disabled = false;
                        });
                }
            });
        });

        document
            .querySelector("#unitSelect")
            .addEventListener("change", function () {
                quantityWasChanged = true;
                const priceElement = document.querySelector("#displayPrice");
                const priceUnitLabel = document.querySelector("#priceUnitLabel");
                const piecePrice = parseFloat(priceElement.dataset.piecePrice) || 0;
                const boxPrice = parseFloat(priceElement.dataset.bottlePrice) || 0;
                const selectedPrice = this.value === "bottle" && boxPrice > 0 ? boxPrice : piecePrice;

                priceElement.textContent = "₱" + selectedPrice.toFixed(2);
                if (priceUnitLabel) {
                    priceUnitLabel.textContent = this.value === "bottle"
                        ? "Per box: ₱" + boxPrice.toFixed(2)
                        : "Per piece: ₱" + piecePrice.toFixed(2);
                }

                document
                    .querySelectorAll(".hidden-unit")
                    .forEach(function (hidden) {
                        hidden.value = this.value;
                    }, this);
                updatePurchaseState(false);
            });

        updatePurchaseState(false);

    </script>

</body>

</html>