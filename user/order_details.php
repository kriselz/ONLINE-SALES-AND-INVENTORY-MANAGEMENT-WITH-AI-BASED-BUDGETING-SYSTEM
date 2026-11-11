<?php
include 'config.php';
date_default_timezone_set('Asia/Manila');
session_start();

// ✅ 1. SECURITY CHECK: DAPAT NAKA-LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ✅ 2. ID CHECK
if (!isset($_GET['id'])) {
    header("Location: my_orders.php");
    exit();
}

$order_id = intval($_GET['id']);
$user_id = (int)$_SESSION['user_id'];

// ✅ 3. FETCH ORDER DATA (Security: user_id is required)
$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    die("<div style='padding:50px; text-align:center;'><h3>❌ Order not found or access denied.</h3><a href='my_orders.php'>Go Back</a></div>");
}
$order_status = strtolower($order['status'] ?? 'pending');
$is_self_pickup = in_array(strtolower(trim($order['delivery_type'] ?? '')), ['pickup', 'self_pickup', 'self-pickup'], true);
$order_status_label = $order_status === 'ready_for_pickup' ? 'Ready for Pickup' : ucfirst(str_replace('_', ' ', $order_status));
$payment_method = strtolower(trim((string)($order['payment_method'] ?? '')));
$payment_method_label = in_array($payment_method, ['cod', 'cash'], true)
    ? 'Cash'
    : strtoupper($order['payment_method'] ?? 'Not specified');
$payment_status = strtolower(trim((string)($order['payment_status'] ?? 'unpaid')));
$delivery_status = strtolower(trim((string)($order['delivery_status'] ?? '')));
$is_online_payment = in_array($payment_method, ['gcash', 'bank', 'bpi', 'bank transfer'], true);
$is_cod_payment = in_array($payment_method, ['cod', 'cash'], true);
$order_completed = in_array($order_status, ['completed', 'delivered'], true) || $delivery_status === 'delivered';
$canRateProducts = in_array(trim($order_status), ['delivered', 'completed'], true);
$receipt_available = $order_status !== 'cancelled'
    && $payment_status === 'paid'
    && (
        $is_online_payment
        || ($is_cod_payment && $order_completed)
        || (!$is_online_payment && !$is_cod_payment && $order_completed)
    );
$receipt_unavailable_message = $order_status === 'cancelled'
    ? 'A receipt is unavailable for a cancelled order.'
    : ($payment_status !== 'paid'
        ? ($is_online_payment
            ? 'Your receipt will be available after the admin approves your payment.'
            : 'Your receipt will be available after payment is completed.')
        : 'Your COD receipt will be available after the order is completed and paid.');

if (!$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_proof_photo VARCHAR(255) NULL")) {
    error_log('Unable to prepare delivery proof storage: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load delivery proof.');
}
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
    error_log('Unable to prepare verified product rating storage: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare product reviews.');
}
$productRatingAspectsColumn = $conn->query("SHOW COLUMNS FROM product_ratings LIKE 'liked_aspects'");
if (!$productRatingAspectsColumn) {
    error_log('Unable to inspect product rating feedback fields: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare product rating feedback.');
}
if ($productRatingAspectsColumn->num_rows === 0 && !$conn->query('ALTER TABLE product_ratings ADD COLUMN liked_aspects TEXT NULL AFTER score')) {
    error_log('Unable to add product rating feedback options: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare product rating feedback.');
}
$productRatingAnonymousColumn = $conn->query("SHOW COLUMNS FROM product_ratings LIKE 'is_anonymous'");
if (!$productRatingAnonymousColumn) {
    error_log('Unable to inspect product rating anonymity field: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare product rating privacy.');
}
if ($productRatingAnonymousColumn->num_rows === 0 && !$conn->query('ALTER TABLE product_ratings ADD COLUMN is_anonymous TINYINT(1) NOT NULL DEFAULT 0 AFTER comment')) {
    error_log('Unable to add product rating anonymity field: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare product rating privacy.');
}

$productRatingMessage = '';
$productRatingError = '';
$allowedProductAspects = [
    'good_quality' => 'Good quality',
    'works_well' => 'Works well',
    'good_packaging' => 'Good packaging',
    'as_described' => 'As described'
];
$ratingToken = $_SESSION['order_rating_csrf'] ?? '';
if ($ratingToken === '') {
    $ratingToken = bin2hex(random_bytes(32));
    $_SESSION['order_rating_csrf'] = $ratingToken;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_product_rating') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $scoreInput = $_POST['product_score'] ?? null;
    $score = is_scalar($scoreInput) ? filter_var($scoreInput, FILTER_VALIDATE_INT) : false;
    $submittedAspects = $_POST['liked_aspects'] ?? [];
    if (!is_array($submittedAspects)) {
        $submittedAspects = [];
    }
    $likedProductAspects = [];
    foreach ($submittedAspects as $aspect) {
        if (is_scalar($aspect) && isset($allowedProductAspects[(string)$aspect])) {
            $likedProductAspects[] = (string)$aspect;
        }
    }
    $likedProductAspects = array_values(array_unique($likedProductAspects));
    $likedProductAspectsValue = implode(',', $likedProductAspects);
    $commentInput = $_POST['product_comment'] ?? '';
    $productComment = is_scalar($commentInput) ? trim((string)$commentInput) : '';
    $isAnonymous = isset($_POST['is_anonymous']) && $_POST['is_anonymous'] === '1' ? 1 : 0;
    $commentLength = preg_match_all('/./us', $productComment, $commentCharacters);
    if (!hash_equals($ratingToken, $submittedToken)) {
        $productRatingError = 'Your session expired. Refresh the page and submit your product rating again.';
    } elseif (!$canRateProducts) {
        $productRatingError = 'You can rate purchased products after the order has been delivered.';
    }
    if ($productRatingError === '' && ($score === false || $score < 1 || $score > 5)) {
        $productRatingError = 'Choose a product rating from 1 to 5 stars.';
    } elseif ($productRatingError === '' && ($commentLength === false || $commentLength > 500)) {
        $productRatingError = 'Product review comments must be 500 characters or fewer.';
    }
    if ($productRatingError === '' && !$conn->begin_transaction()) {
        error_log('Verified product ratings transaction start failed: ' . $conn->error);
        $productRatingError = 'Unable to save your product rating right now.';
    }
    if ($productRatingError === '') {
        $purchasedProductsStmt = $conn->prepare(
            "SELECT DISTINCT oi.product_id
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.order_id = ? AND o.user_id = ?
               AND LOWER(TRIM(COALESCE(o.status, ''))) IN ('delivered', 'completed')"
        );
        if (!$purchasedProductsStmt) {
            error_log('Verified product purchase validation preparation failed: ' . $conn->error);
            $productRatingError = 'Unable to verify this purchase right now.';
        } else {
            $purchasedProductsStmt->bind_param('ii', $order_id, $user_id);
            if (!$purchasedProductsStmt->execute()) {
                error_log('Verified product purchase validation failed: ' . $purchasedProductsStmt->error);
                $productRatingError = 'Unable to verify this purchase right now.';
            } else {
                $purchasedProducts = [];
                $purchasedProductsResult = $purchasedProductsStmt->get_result();
                while ($purchasedProduct = $purchasedProductsResult->fetch_assoc()) {
                    $purchasedProducts[] = (int)$purchasedProduct['product_id'];
                }
                if (!$purchasedProducts) {
                    $productRatingError = 'You can only rate products included in this delivered order.';
                }
            }
            $purchasedProductsStmt->close();
        }

        if ($productRatingError === '') {
            $existingProductRatingsStmt = $conn->prepare(
                'SELECT product_id FROM product_ratings WHERE order_id = ? AND user_id = ?'
            );
            if (!$existingProductRatingsStmt) {
                error_log('Verified product rating lookup preparation failed: ' . $conn->error);
                $productRatingError = 'Unable to verify your existing product ratings.';
            } else {
                $existingProductRatingsStmt->bind_param('ii', $order_id, $user_id);
                if (!$existingProductRatingsStmt->execute()) {
                    error_log('Verified product rating lookup failed: ' . $existingProductRatingsStmt->error);
                    $productRatingError = 'Unable to verify your existing product ratings.';
                } else {
                    $alreadyRatedIds = [];
                    $existingProductRatings = $existingProductRatingsStmt->get_result();
                    while ($existingProductRating = $existingProductRatings->fetch_assoc()) {
                        $alreadyRatedIds[(int)$existingProductRating['product_id']] = true;
                    }
                    $unratedProductIds = array_values(array_filter(
                        $purchasedProducts,
                        static function ($productId) use ($alreadyRatedIds) {
                            return !isset($alreadyRatedIds[$productId]);
                        }
                    ));
                    if (!$unratedProductIds) {
                        $productRatingError = 'All products in this order have already been rated.';
                    }
                }
                $existingProductRatingsStmt->close();
            }
        }

        if ($productRatingError === '') {
            $productRatingStmt = $conn->prepare(
                "INSERT INTO product_ratings (order_id, product_id, user_id, score, liked_aspects, comment, is_anonymous)
                 VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?)"
            );
            if (!$productRatingStmt) {
                error_log('Verified product rating preparation failed: ' . $conn->error);
                $productRatingError = 'Unable to save your product rating right now.';
            } else {
                foreach ($unratedProductIds as $productId) {
                    $productRatingStmt->bind_param('iiiissi', $order_id, $productId, $user_id, $score, $likedProductAspectsValue, $productComment, $isAnonymous);
                    if (!$productRatingStmt->execute()) {
                        if ($productRatingStmt->errno === 1062) {
                            $productRatingError = 'A product rating was submitted at the same time. Refresh to see the saved ratings.';
                        } else {
                            error_log('Verified product rating save failed: ' . $productRatingStmt->error);
                            $productRatingError = 'Unable to save your product rating right now.';
                        }
                        break;
                    }
                }
                $productRatingStmt->close();
            }
        }

        if ($productRatingError === '') {
            if (!$conn->commit()) {
                error_log('Verified product ratings transaction commit failed: ' . $conn->error);
                $productRatingError = 'Unable to save your product rating right now.';
            } else {
                $_SESSION['product_rating_message'] = 'Thank you. Your rating has been saved for all remaining products in this order.';
                header('Location: order_details.php?id=' . $order_id . '#product-ratings');
                exit();
            }
        }
        $conn->rollback();
    }
}
if (isset($_SESSION['product_rating_message'])) {
    $productRatingMessage = (string)$_SESSION['product_rating_message'];
    unset($_SESSION['product_rating_message']);
}

$deliveredAt = trim((string)($order['delivery_date'] ?? '')) ?: null;
$deliveryEventStmt = $conn->prepare("SELECT created_at FROM delivery_events WHERE order_id = ? AND event_type = 'delivered' ORDER BY created_at DESC LIMIT 1");
if ($deliveryEventStmt) {
    $deliveryEventStmt->bind_param('i', $order_id);
    if ($deliveryEventStmt->execute()) {
        $deliveryEvent = $deliveryEventStmt->get_result()->fetch_assoc();
        $deliveredAt = trim((string)($deliveryEvent['created_at'] ?? '')) ?: $deliveredAt;
    } else {
        error_log('Customer delivered timestamp lookup failed: ' . $deliveryEventStmt->error);
    }
    $deliveryEventStmt->close();
} else {
    error_log('Customer delivered timestamp query preparation failed: ' . $conn->error);
}

$proofFilename = basename(trim((string)($order['delivery_proof_photo'] ?? '')));
$proofFilePath = $proofFilename !== '' ? __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'delivery_proofs' . DIRECTORY_SEPARATOR . $proofFilename : '';
$proofAvailable = $proofFilename !== '' && is_file($proofFilePath);
if (!$proofAvailable && !$is_self_pickup) {
    $legacyProofFiles = glob(
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'delivery_proofs' . DIRECTORY_SEPARATOR
            . 'delivery_' . (int)$order_id . '_*.*'
    );
    $legacyProofFiles = array_values(array_filter(
        $legacyProofFiles ?: [],
        static fn($file) => preg_match('/\Adelivery_' . (int)$order_id . '_[a-f0-9]{12}\.(?:jpg|png)\z/i', basename($file))
    ));
    if ($legacyProofFiles) {
        usort($legacyProofFiles, static fn($first, $second) => filemtime($second) <=> filemtime($first));
        $proofAvailable = is_file($legacyProofFiles[0]);
    }
}
$proofImageUrl = $proofAvailable ? 'delivery_proof.php?order_id=' . (int)$order_id : '';

// ✅ 4. FETCH ITEMS
$items = $conn->query("
    SELECT order_items.product_id, order_items.product_name, products.image, products.category,
           order_items.price, order_items.total_price,
           order_items.box, order_items.pieces,
           COALESCE(products.pieces_per_box, 12) AS pieces_per_box
    FROM order_items
    JOIN products ON order_items.product_id = products.id
    WHERE order_items.order_id = $order_id
");

if (!$items) {
    die('Unable to load order items: ' . htmlspecialchars($conn->error));
}

$order_items = [];
$productNamesById = [];
$subtotal = 0;
while ($item = $items->fetch_assoc()) {
    $quantity = ((int) $item['box'] * (int) $item['pieces_per_box']) + (int) $item['pieces'];
    $item['quantity'] = max(1, $quantity);
    $item['subtotal'] = (float) $item['total_price'];
    $subtotal += $item['subtotal'];
    $order_items[] = $item;
    $productNamesById[(int)$item['product_id']] = (string)$item['product_name'];
}
$productRatingsById = [];
if ($canRateProducts) {
    $productRatingsStmt = $conn->prepare('SELECT product_id, score, liked_aspects, comment, is_anonymous FROM product_ratings WHERE order_id = ? AND user_id = ?');
    if (!$productRatingsStmt) {
        error_log('Existing verified product rating query preparation failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to load product reviews.');
    }
    $productRatingsStmt->bind_param('ii', $order_id, $user_id);
    if (!$productRatingsStmt->execute()) {
        error_log('Existing verified product rating lookup failed: ' . $productRatingsStmt->error);
        $productRatingsStmt->close();
        http_response_code(500);
        exit('Unable to load product reviews.');
    }
    $productRatingsResult = $productRatingsStmt->get_result();
    while ($productRating = $productRatingsResult->fetch_assoc()) {
        $productRatingsById[(int)$productRating['product_id']] = $productRating;
    }
    $productRatingsStmt->close();
}

$status_levels = $is_self_pickup
    ? ['pending' => 1, 'processing' => 2, 'ready_for_pickup' => 3, 'delivered' => 4]
    : ['pending' => 1, 'processing' => 2, 'shipped' => 4, 'delivered' => 5];
$status_level = $status_levels[$order_status] ?? 1;
$timeline_timestamps = $is_self_pickup
    ? [1 => $order['order_date'] ?? null, 2 => null, 3 => null, 4 => $deliveredAt]
    : [1 => $order['order_date'] ?? null, 2 => null, 3 => null, 4 => null, 5 => $deliveredAt];

$timelineEventStmt = $conn->prepare("SELECT event_type, created_at FROM delivery_events WHERE order_id = ? AND event_type IN ('rider_assigned', 'delivered') ORDER BY created_at ASC");
if ($timelineEventStmt) {
    $timelineEventStmt->bind_param('i', $order_id);
    if ($timelineEventStmt->execute()) {
        $timelineEvents = $timelineEventStmt->get_result();
        while ($timelineEvent = $timelineEvents->fetch_assoc()) {
            if ($timelineEvent['event_type'] === 'rider_assigned' && !$is_self_pickup) {
                $timeline_timestamps[3] = $timeline_timestamps[3] ?? $timelineEvent['created_at'];
                $timeline_timestamps[4] = $timeline_timestamps[4] ?? $timelineEvent['created_at'];
            } elseif ($timelineEvent['event_type'] === 'delivered') {
                $timeline_timestamps[$is_self_pickup ? 4 : 5] = $timelineEvent['created_at'];
            }
        }
    } else {
        error_log('Customer order timeline event lookup failed: ' . $timelineEventStmt->error);
    }
    $timelineEventStmt->close();
} else {
    error_log('Customer order timeline event query preparation failed: ' . $conn->error);
}

$formattedOrderNumber = formatOrderNumber($conn, $order_id);
$timelineReference = $formattedOrderNumber;
$legacyTimelineReference = '#ORD-' . str_pad((string)$order_id, 6, '0', STR_PAD_LEFT);
$legacyDatedOrderReference = '#ORD-' . date('Ymd', strtotime($order['order_date'])) . '-' . str_pad((string)$order_id, 3, '0', STR_PAD_LEFT);
$shortTimelineReference = '#' . $order_id;
$timelineNotificationStmt = $conn->prepare(
    'SELECT message, created_at FROM notifications
     WHERE user_id = ? AND (message LIKE ? OR message LIKE ? OR message LIKE ? OR message LIKE ?)
     ORDER BY created_at ASC'
);
if ($timelineNotificationStmt) {
    $orderCodePattern = '%' . $timelineReference . '%';
    $legacyOrderCodePattern = '%' . $legacyTimelineReference . '%';
    $legacyDatedOrderPattern = '%' . $legacyDatedOrderReference . '%';
    $shortOrderPattern = '%Your order ' . $shortTimelineReference . ' has been %';
    $timelineNotificationStmt->bind_param('issss', $user_id, $orderCodePattern, $legacyOrderCodePattern, $legacyDatedOrderPattern, $shortOrderPattern);
    if ($timelineNotificationStmt->execute()) {
        $timelineNotifications = $timelineNotificationStmt->get_result();
        while ($timelineNotification = $timelineNotifications->fetch_assoc()) {
            $message = strtolower((string)$timelineNotification['message']);
            if (strpos($message, 'processing') !== false) {
                $timeline_timestamps[2] = $timeline_timestamps[2] ?? $timelineNotification['created_at'];
            } elseif ($is_self_pickup && strpos($message, 'ready for pickup') !== false) {
                $timeline_timestamps[3] = $timeline_timestamps[3] ?? $timelineNotification['created_at'];
            } elseif (strpos($message, 'shipped') !== false || strpos($message, 'out for delivery') !== false) {
                if (!$is_self_pickup) {
                    $timeline_timestamps[3] = $timeline_timestamps[3] ?? $timelineNotification['created_at'];
                    $timeline_timestamps[4] = $timeline_timestamps[4] ?? $timelineNotification['created_at'];
                }
            } elseif (strpos($message, 'delivered') !== false) {
                $timeline_timestamps[$is_self_pickup ? 4 : 5] = $timeline_timestamps[$is_self_pickup ? 4 : 5] ?? $timelineNotification['created_at'];
            }
        }
    } else {
        error_log('Customer order timeline notification lookup failed: ' . $timelineNotificationStmt->error);
    }
    $timelineNotificationStmt->close();
} else {
    error_log('Customer order timeline notification query preparation failed: ' . $conn->error);
}
$is_out_for_delivery = $order_status === 'shipped'
    && !empty($order['rider_id'])
    && strtolower(trim($order['delivery_status'] ?? 'in_transit')) === 'in_transit';
$pickup_deadline = trim((string)($order['pickup_deadline'] ?? ''));
$delivery_fee = (float)($order['shipping_fee'] ?? 0);
$discount_status = strtolower(trim((string)($order['discount_status'] ?? 'not_applicable')));
$discount_amount = $discount_status === 'approved'
    ? max(0, (float)($order['discount_amount'] ?? 0))
    : 0.0;
$total_amount = (float) ($order['total_amount'] ?? 0);
if ($total_amount <= 0) {
    $total_amount = max(0, $subtotal - $discount_amount) + $delivery_fee;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order <?= htmlspecialchars($formattedOrderNumber, ENT_QUOTES, 'UTF-8') ?> Details</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f7f8fb; color: #263247; font-family: 'Segoe UI', Arial, sans-serif; }
        .main-content { margin-left: 260px; width: calc(100% - 260px); min-height: 100vh; padding: 32px; }
        .page-shell { width: 100%; max-width: 1500px; margin: 0 auto; }
        .breadcrumb { display: flex; align-items: center; margin-bottom: 8px; }
        .breadcrumb a { display: inline-flex; align-items: center; gap: 8px; margin: 0 0 12px 8px; padding: 0 0 12px; border: 0; border-radius: 8px; background: transparent; color: #4b5563; font-size: 16px; font-weight: 600; text-decoration: none; }
        .breadcrumb a:hover { color: #374151; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 18px; }
        .topbar h1 { margin: 0 0 6px; font-size: 27px; }
        .placed { color: #536177; font-size: 13px; }
        .status-badge { display: inline-block; margin-left: 8px; padding: 5px 11px; border-radius: 12px; background: #df292e; color: white; font-size: 10px; font-weight: 700; vertical-align: middle; }
        .topbar-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .receipt-button, .support-button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 40px; border: 1px solid #e3e7ee; border-radius: 7px; background: white; color: #263247; padding: 9px 14px; text-decoration: none; font-size: 12px; font-weight: 600; box-shadow: 0 2px 7px rgba(35, 49, 70, .06); cursor: pointer; }
        .receipt-button.primary, .support-button.track { background: #79141d; border-color: #79141d; color: white; }
        .receipt-disabled-wrap { position:relative; display:inline-flex; }
        .receipt-button.receipt-disabled { border-color:#d5dbe4; background:#edf0f4; color:#778396; box-shadow:none; cursor:not-allowed; opacity:1; }
        .receipt-tooltip { position:absolute; z-index:20; top:calc(100% + 8px); right:0; width:max-content; max-width:min(320px, calc(100vw - 32px)); padding:9px 12px; border-radius:6px; background:#263247; color:#fff; font-size:12px; font-weight:500; line-height:1.4; text-align:left; box-shadow:0 4px 14px rgba(35,49,70,.2); opacity:0; visibility:hidden; transform:translateY(-3px); transition:opacity .15s ease,transform .15s ease,visibility .15s ease; }
        .receipt-tooltip::before { position:absolute; right:14px; bottom:100%; border:6px solid transparent; border-bottom-color:#263247; content:""; }
        .receipt-disabled-wrap:hover .receipt-tooltip,.receipt-disabled-wrap:focus .receipt-tooltip,.receipt-disabled-wrap.is-open .receipt-tooltip { opacity:1; visibility:visible; transform:translateY(0); }
        .receipt-download-icon { display: block; width: 15px; height: 15px; flex: 0 0 15px; }
        .track-pin-icon { display: block; width: 16px; height: 16px; flex: 0 0 16px; }
        .material-icons-outlined { font-family: 'Material Icons Outlined'; font-weight: normal; font-style: normal; line-height: 1; letter-spacing: normal; text-transform: none; white-space: nowrap; word-wrap: normal; direction: ltr; -webkit-font-feature-settings: 'liga'; -webkit-font-smoothing: antialiased; }
        .receipt-download-icon, .track-pin-icon { width: 18px; height: 18px; flex: 0 0 18px; font-size: 18px; }
        .panel { background: white; border: 1px solid #e8ebf0; border-radius: 10px; box-shadow: 0 2px 8px rgba(35, 49, 70, .04); }
        .timeline-panel { padding: 18px 14px 12px; margin: 16px 0 12px; }
        .timeline { display: flex; position: relative; justify-content: space-between; isolation: isolate; }
        .timeline:before { content: ''; position: absolute; z-index: 0; top: 20px; left: 7%; right: 7%; height: 2px; background: #e7e9ed; }
        .timeline-step { position: relative; flex: 1; min-width: 0; text-align: center; font-size: 18px; }
        .timeline-step:after { content: ''; position: absolute; z-index: 1; top: 20px; left: 0; width: 100%; height: 2px; background: #79141d; transform: translateX(-50%); }
        .timeline-step:first-child:after { display: none; }
        .timeline-step.inactive:after { background: #e7e9ed; }
        .timeline-dot { position: relative; z-index: 2; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; border: 3px solid #fff; border-radius: 50%; background: #aeb8c5; color: white; box-shadow: 0 1px 3px rgba(35, 49, 70, .12); }
        .timeline-dot svg { width: 16px; height: 16px; }
        .timeline-dot .material-icons-outlined { font-size: 16px; }
        .timeline-step.active .timeline-dot { background: #79141d; }
        .timeline-step p, .timeline-step small { position: relative; z-index: 2; }
        .timeline-step p { margin: 0 0 6px; color: #263247; font-size: 18px; font-weight: 700; line-height: 1.3; }
        .timeline-step small { color: #68758a; font-size: 14px; }
        .delivery-alert { display: flex; align-items: center; gap: 10px; min-height: 56px; margin-top: 12px; padding: 8px 11px; border: 1px solid #f0d4d6; border-radius: 7px; background: #fff7f7; }
        .delivery-alert-icon { display: flex; flex: 0 0 22px; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #df292e; color: white; font-size: 14px; font-weight: 700; }
        .delivery-alert-icon .material-icons-outlined { color: #fff; font-size: 16px; }
        .delivery-alert strong { display: block; color: #8e1c24; font-size: 13px; }
        .delivery-alert span { color: #536177; font-size: 11px; }
        .delivery-alert .track-alert-link .track-pin-icon { width: 20px; height: 20px; flex-basis: 20px; color: inherit; font-size: 20px; }
        .delivery-alert .track-alert-link { margin-left: auto; margin-right: 32px; flex: 0 0 auto; min-height: 46px; padding: 12px 20px; font-size: 14px; }
        .columns { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(280px, 1fr); align-items: start; gap: 12px; margin-top: 10px; }
        .card-title { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid #edf0f3; font-size: 14px; font-weight: 700; }
        .items-card > .card-title, .side-card > .card-title { font-size: 16px; }
        .items-card .card-title { padding: 8px 12px; border-bottom: 0; }
        .card-title-icon { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; flex: 0 0 34px; border-radius: 50%; background: #79141d; color: white; font-size: 20px; }
        .items-card .card-title-icon { transform: translateY(3px); }
        .items-card { overflow: hidden; }
        .items-list { margin: 0 12px; overflow: hidden; border: 1px solid #e8ebf0; border-radius: 8px; background: #fff; }
        .items-table-head { display: grid; grid-template-columns: minmax(0, 1fr) 78px 92px; gap: 12px; padding: 9px 10px; background: #f5f6f8; color: #68758a; font-size: 14.43px; }
        .items-table-head span:nth-child(2), .item-quantity { text-align: center; }
        .items-table-head span:last-child, .item-total { text-align: right; }
        .item-row { display: grid; grid-template-columns: minmax(0, 1fr) 78px 92px; align-items: center; gap: 12px; padding: 9px 10px; border-top: 1px solid #edf0f3; }
        .item-product { display: flex; align-items: center; min-width: 0; gap: 12px; }
        .item-image { width: 50px; height: 50px; flex: 0 0 50px; object-fit: contain; border: 1px solid #e5e9ef; border-radius: 7px; background: #fff; }
        .item-name { font-size: 14.43px; font-weight: 700; }
        .item-info { color: #6d7788; font-size: 14.43px; margin-top: 3px; }
        .item-quantity, .item-total { font-size: 14.43px; font-weight: 700; }
        .totals { margin: 8px 12px 0; padding: 8px 10px; border: 1px solid #f0d4d6; border-radius: 7px; background: #fff8f8; font-size: 14.43px; }
        .total-line { display: flex; justify-content: space-between; gap: 12px; margin: 6px 0; color: #536177; }
        .total-line.final { margin: 0 -10px -8px; padding: 9px 10px; border-top: 1px solid #f0d4d6; border-radius: 0 0 7px 7px; background: #fff1f2; color: #263247; font-size: 14px; font-weight: 700; }
        .total-line.final strong { color: #79141d; font-size: 17px; }
        .payment { display: flex; align-items: center; gap: 10px; min-height: 58px; margin: 10px 12px 12px; padding: 10px 13px; border: 1px solid #f0d4d6; border-radius: 7px; background: #fff8f8; font-size: 14.43px; }
        .payment-icon { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; flex: 0 0 28px; border-radius: 50%; background: #79141d; color: white; }
        .payment-icon .material-icons-outlined { font-size: 17px; }
        .payment .payment-icon { color: #fff; }
        .payment-details { display: flex; flex-direction: column; gap: 3px; }
        .payment strong { color: #79141d; }
        .payment-details span { color: #536177; }
        .side-card { padding-bottom: 14px; }
        .side-card > .card-title { border-bottom: 0; }
        .delivery-title-icon { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; flex: 0 0 34px; border-radius: 50%; background: #79141d; color: white; }
        .delivery-title-icon .material-icons-outlined { font-size: 20px; }
        .side-body { padding: 12px 14px 0; font-size: 12px; }
        .side-body p { margin: 5px 0; color: #536177; }
        .side-body strong { color: #263247; }
        .delivery-summary, .address-card { display: flex; gap: 12px; margin: 12px 14px 0; padding: 13px; border: 1px solid #f0d4d6; border-radius: 8px; background: #fff7f7; }
        .delivery-summary-icon { display: flex; flex: 0 0 32px; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: #79141d; color: white; }
        .delivery-summary-icon .material-icons-outlined { font-size: 18px; }
        .address-card .delivery-summary-icon { border-radius: 50%; transform: none; }
        .address-card .delivery-summary-icon svg { transform: none; }
        .delivery-summary-label, .address-label { margin-bottom: 5px; color: #68758a; font-size: 13px; }
        .delivery-summary-value, .address-value { color: #263247; font-size: 15px; font-weight: 700; }
        .delivery-summary-note { margin-top: 4px; color: #536177; font-size: 13px; }
        .pickup-countdown { margin-top: 8px; color: #536177; font-size: 12px; }
        .pickup-countdown strong { color: #79141d; }
        .address-card { margin-top: 10px; }
        .address-card .side-body { padding: 0; }
        .address-card .side-body p { margin: 3px 0; }
        .delivery-proof { margin: 12px 14px 0; padding: 13px; border: 1px solid #dbe5f0; border-radius: 8px; background: #f8fafc; }
        .delivery-proof h3 { margin: 0 0 8px; color: #263247; font-size: 14px; }
        .delivery-proof p { margin: 0 0 10px; color: #536177; font-size: 12px; }
        .delivery-proof img { display: block; width: 100%; max-height: 360px; border-radius: 7px; object-fit: contain; background: #e8edf3; }
        .rating-card { margin-top: 16px; padding: 18px; }
        .rating-card h2 { margin: 0 0 6px; color: #263247; font-size: 19px; }
        .rating-intro { margin: 0 0 15px; color: #536177; font-size: 13px; }
        .rating-stars { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 4px; margin: 0 0 16px; }
        .rating-stars input { position: absolute; width: 1px; height: 1px; opacity: 0; }
        .rating-stars label { color: #cbd3df; cursor: pointer; font-size: 34px; line-height: 1; }
        .rating-stars label:hover, .rating-stars label:hover ~ label, .rating-stars input:checked ~ label { color: #f2aa22; }
        .rating-stars input:focus-visible + label { outline: 2px solid #79141d; outline-offset: 3px; border-radius: 3px; }
        .rating-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px 14px; margin: 8px 0 16px; }
        .rating-options label { display: flex; align-items: center; gap: 8px; color: #39475c; font-size: 13px; }
        .rating-card textarea { display: block; width: 100%; min-height: 90px; margin: 7px 0 14px; padding: 10px; border: 1px solid #d6deea; border-radius: 7px; font: inherit; resize: vertical; }
        .rating-submit { padding: 10px 16px; border: 0; border-radius: 7px; background: #79141d; color: #fff; cursor: pointer; font-weight: 700; }
        .rating-saved-view { padding: 12px 14px; border: 1px solid #d8eadf; border-radius: 8px; background: #f5fbf7; }
        .rating-saved-stars { color: #f2aa22; font-size: 30px; letter-spacing: 3px; line-height: 1.2; }
        .rating-saved-score { margin: 5px 0 12px; color: #39475c; font-size: 13px; font-weight: 700; }
        .rating-saved-aspects { display: flex; flex-wrap: wrap; gap: 7px; margin: 0 0 12px; }
        .rating-saved-aspect { padding: 5px 9px; border-radius: 999px; background: #e8f3ec; color: #315f42; font-size: 12px; }
        .rating-saved-comment { margin: 0; color: #39475c; font-size: 13px; white-space: pre-wrap; }
        .rating-saved-note { display: block; margin-top: 12px; color: #68758a; font-size: 12px; }
        .rating-notice { margin: 0 0 12px; padding: 10px 12px; border-radius: 7px; font-size: 13px; }
        .rating-notice.success { background: #edf8f0; color: #21623a; }
        .rating-notice.error { background: #fff1f2; color: #8e1c24; }
        .product-rating { padding: 12px 14px; border-top: 1px solid #edf0f3; background: #fafbfc; }
        form.product-rating { display: flex; flex-direction: column; }
        .product-rating-title { margin: 0 0 8px; color: #39475c; font-size: 12px; font-weight: 700; }
        .product-rating-saved { padding: 10px 12px; border: 1px solid #d8eadf; border-radius: 7px; background: #f5fbf7; }
        .product-rating-saved-stars { color: #f2aa22; font-size: 24px; letter-spacing: 2px; line-height: 1.2; }
        .product-rating-saved-score { margin: 3px 0 8px; color: #39475c; font-size: 12px; font-weight: 700; }
        .product-rating-saved-comment { margin: 0; color: #39475c; font-size: 12px; white-space: pre-wrap; }
        .product-rating-saved-note { display: block; margin-top: 8px; color: #68758a; font-size: 11px; }
        .product-rating-anonymous { display: flex; align-items: center; gap: 6px; margin: 0 0 12px; color: #39475c; font-size: 12px; }
        .product-rating-anonymous span { color: #68758a; }
        .product-rating-stars { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 4px; margin: 0 0 10px; }
        .product-rating-stars input { position: absolute; width: 1px; height: 1px; opacity: 0; }
        .product-rating-stars label { color: #cbd3df; cursor: pointer; font-size: 28px; line-height: 1; }
        .product-rating-stars label:hover, .product-rating-stars label:hover ~ label, .product-rating-stars input:checked ~ label { color: #f2aa22; }
        .product-rating-stars input:focus-visible + label { outline: 2px solid #79141d; outline-offset: 3px; border-radius: 3px; }
        .product-rating textarea { display: block; width: 100%; min-height: 65px; margin: 7px 0 10px; padding: 9px; border: 1px solid #d6deea; border-radius: 7px; font: inherit; resize: vertical; }
        .product-rating-submit { display: block; align-self: flex-end; padding: 8px 12px; border: 0; border-radius: 7px; background: #79141d; color: #fff; cursor: pointer; font-size: 12px; font-weight: 700; }
        @media (max-width: 600px) { .rating-options { grid-template-columns: 1fr; } .rating-card { padding: 14px; } }
        @media (max-width: 600px) { .product-rating { padding: 11px; } }
        @media print { .sidebar, .receipt-button, .support-button, .breadcrumb { display: none !important; } .main-content { margin: 0; padding: 0; width: 100%; } }
        @media (max-width: 900px) { .main-content { margin-left: 0; width: 100%; padding: 20px; } .columns { grid-template-columns: 1fr; } }
        @media (max-width: 700px) { .topbar { align-items: flex-start; flex-direction: column; } .topbar-actions { justify-content: flex-start; } }
        @media (max-width: 600px) { .main-content { padding: 16px 12px; } .topbar h1 { font-size: 22px; } .timeline-panel { padding: 14px 8px; overflow-x: hidden; } .timeline { min-width: 0; } .timeline:before, .timeline-step:after { top: 16px; } .timeline-dot { width: 32px; height: 32px; font-size: 12px; } .timeline-step p { font-size: 11px; overflow-wrap: anywhere; } .timeline-step small { font-size: 10px; } .delivery-alert { align-items: flex-start; } .item-row, .items-table-head { grid-template-columns: minmax(0, 1fr) 48px 74px; gap: 7px; padding-left: 10px; padding-right: 10px; } .item-product { gap: 8px; } .item-image { width: 44px; height: 44px; flex-basis: 44px; } .item-name, .item-info, .item-quantity, .item-total, .items-table-head { font-size: 14.43px; } }
    </style>
</head>
<body>
    <?php include 'sidebar_user.php'; ?>
    <main class="main-content">
        <div class="page-shell">
            <div class="breadcrumb"><a href="my_orders.php"><span class="material-icons-outlined" aria-hidden="true">arrow_back</span>Back to Orders</a></div>
            <div class="topbar">
                <div>
                    <h1>Order <?= htmlspecialchars($formattedOrderNumber, ENT_QUOTES, 'UTF-8') ?> <span class="status-badge"><?= htmlspecialchars(strtoupper($order_status === 'shipped' ? 'ON THE WAY' : $order_status_label)) ?></span></h1>
                    <div class="placed">Placed on <?= date('M d, Y', strtotime($order['order_date'])) ?> &nbsp;•&nbsp; <?= date('h:i A', strtotime($order['order_date'])) ?></div>
                </div>
                <div class="topbar-actions">
                    <?php if ($receipt_available): ?>
                        <a class="receipt-button primary" href="view_receipt.php?order_id=<?= $order_id ?>" target="_blank" rel="noopener"><span class="material-icons-outlined receipt-download-icon" aria-hidden="true">download</span>Download Receipt</a>
                    <?php else: ?>
                        <span class="receipt-disabled-wrap" tabindex="0" role="button" aria-disabled="true" aria-label="Receipt unavailable" aria-describedby="receiptUnavailableNote">
                            <span class="receipt-button receipt-disabled">Download Receipt</span>
                            <span class="receipt-tooltip" id="receiptUnavailableNote" role="tooltip"><?= htmlspecialchars($receipt_unavailable_message, ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (in_array($order['discount_type'] ?? '', ['senior_citizen', 'pwd'], true)): ?>
                <section class="panel" style="margin-bottom:12px;padding:16px;border-color:#ead9a8;background:#fffaf0;color:#765300">
                    <?php $discountStatus = strtolower(trim((string)($order['discount_status'] ?? 'pending_verification'))); ?>
                    <strong><?= ($order['discount_type'] ?? '') === 'pwd' ? 'PWD' : 'Senior Citizen' ?> ID: <?= htmlspecialchars(ucwords(str_replace('_', ' ', $discountStatus)), ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if ($discountStatus === 'approved'): ?>
                        <p style="margin:6px 0 0;color:#536177">A 20% discount of ₱<?= number_format((float)($order['discount_amount'] ?? 0), 2) ?> was applied.</p>
                    <?php elseif ($discountStatus === 'rejected'): ?>
                        <p style="margin:6px 0 0;color:#536177">The ID was rejected. No discount was applied.</p>
                    <?php else: ?>
                        <p style="margin:6px 0 0;color:#536177">Your uploaded ID is awaiting verification.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (strtolower(trim($order['payment_status'] ?? '')) === 'rejected'): ?>
                <section class="panel" style="margin-bottom:12px;padding:16px;border-color:#f0c6cc;background:#fff7f8;color:#8e1c24">
                    <strong>Payment Rejected — Order Cancelled</strong>
                    <p style="margin:6px 0 0;color:#536177">Reason: <?= htmlspecialchars(trim((string)($order['payment_reference'] ?? '')) ?: 'No reason provided.', ENT_QUOTES, 'UTF-8') ?></p>
                </section>
            <?php endif; ?>
            <?php if (strtolower(trim($order_status)) === 'cancelled'
                && in_array(strtolower(trim($order['delivery_type'] ?? '')), ['rider', 'delivery'], true)
                && strtolower(trim($order['delivery_action'] ?? '')) === 'cancel_order'): ?>
                <section class="panel" style="margin-bottom:12px;padding:16px;border-color:#f0c6cc;background:#fff7f8;color:#8e1c24">
                    <strong>Rider Delivery Cancelled by Rider</strong>
                    <p style="margin:6px 0 0;color:#536177">Reason: <?= htmlspecialchars(ucwords(str_replace('_', ' ', trim((string)($order['delivery_failure_reason'] ?? '')) ?: 'Not provided')), ENT_QUOTES, 'UTF-8') ?></p>
                    <p style="margin:6px 0 0;color:#536177">Action: <?= htmlspecialchars(ucwords(str_replace('_', ' ', trim((string)($order['delivery_action'] ?? '')) ?: 'Not provided')), ENT_QUOTES, 'UTF-8') ?></p>
                </section>
            <?php endif; ?>

            <?php if ($canRateProducts && $productNamesById): ?>
                <?php
                $unratedProductNames = array_diff_key($productNamesById, $productRatingsById);
                $productRatingPrefix = 'order-product-' . $order_id;
                ?>
                <section class="panel rating-card" id="product-ratings">
                    <h2>Product Rating</h2>
                    <p class="rating-intro">Rate the products in this order. One rating will be applied to every product that has not already been rated.</p>
                    <?php if ($productRatingMessage !== ''): ?><div class="rating-notice success"><?= htmlspecialchars($productRatingMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                    <?php if ($productRatingError !== ''): ?><div class="rating-notice error"><?= htmlspecialchars($productRatingError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                    <?php if ($productRatingsById): ?>
                        <div class="product-rating-list">
                            <?php foreach ($productRatingsById as $ratedProductId => $savedProductRating): ?>
                                <?php $productScore = (int)$savedProductRating['score']; ?>
                                <div class="product-rating product-rating-saved" aria-label="Your submitted rating for <?= htmlspecialchars($productNamesById[$ratedProductId] ?? 'product', ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="product-rating-title"><?= htmlspecialchars($productNamesById[$ratedProductId] ?? 'Product', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="product-rating-saved-stars" role="img" aria-label="<?= $productScore ?> out of 5 stars"><?= str_repeat('★', $productScore) . str_repeat('☆', 5 - $productScore) ?></div>
                                    <p class="product-rating-saved-score"><?= $productScore ?> out of 5 stars</p>
                                    <?php
                                    $savedProductAspects = array_intersect_key(
                                        array_flip(explode(',', (string)($savedProductRating['liked_aspects'] ?? ''))),
                                        $allowedProductAspects
                                    );
                                    ?>
                                    <?php if ($savedProductAspects): ?>
                                        <div class="rating-saved-aspects" aria-label="Product feedback selected">
                                            <?php foreach (array_keys($savedProductAspects) as $savedProductAspect): ?>
                                                <span class="rating-saved-aspect"><?= htmlspecialchars($allowedProductAspects[$savedProductAspect], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (trim((string)($savedProductRating['comment'] ?? '')) !== ''): ?>
                                        <p class="product-rating-saved-comment"><?= htmlspecialchars((string)$savedProductRating['comment'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($savedProductRating['is_anonymous'])): ?>
                                        <small class="product-rating-saved-note">This review was submitted anonymously.</small>
                                    <?php endif; ?>
                                    <small class="product-rating-saved-note">This rating has been submitted and cannot be changed.</small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($unratedProductNames): ?>
                        <?php if ($productRatingsById): ?><p class="rating-intro">Your rating will be saved for the <?= count($unratedProductNames) ?> remaining product<?= count($unratedProductNames) === 1 ? '' : 's' ?>.</p><?php endif; ?>
                        <form class="product-rating" method="post" action="order_details.php?id=<?= $order_id ?>#product-ratings">
                            <input type="hidden" name="action" value="submit_product_rating">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($ratingToken, ENT_QUOTES, 'UTF-8') ?>">
                            <fieldset style="padding:0;border:0">
                                <legend class="product-rating-title">Rate this order's products</legend>
                                <div class="product-rating-stars" role="radiogroup" aria-label="Rate products in this order">
                                    <?php for ($star = 1; $star <= 5; $star++): ?>
                                        <input type="radio" id="<?= $productRatingPrefix ?>-star-<?= $star ?>" name="product_score" value="<?= $star ?>" required>
                                        <label for="<?= $productRatingPrefix ?>-star-<?= $star ?>" aria-label="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>">★</label>
                                    <?php endfor; ?>
                                </div>
                            </fieldset>
                            <div class="rating-intro" style="margin-bottom:8px">What did you like? <span style="color:#68758a">(optional)</span></div>
                            <div class="rating-options">
                                <?php foreach ($allowedProductAspects as $value => $label): ?>
                                    <label><input type="checkbox" name="liked_aspects[]" value="<?= $value ?>"> <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
                                <?php endforeach; ?>
                            </div>
                            <label class="product-rating-title" for="<?= $productRatingPrefix ?>-comment">Additional comments <span style="font-weight:400;color:#68758a">(optional)</span></label>
                            <textarea id="<?= $productRatingPrefix ?>-comment" name="product_comment" maxlength="500" placeholder="Share a quick review of the products..."></textarea>
                            <label class="product-rating-anonymous"><input type="checkbox" name="is_anonymous" value="1"> Post this review anonymously <span>(your name won't appear publicly)</span></label>
                            <button class="product-rating-submit" type="submit">Submit Product Rating</button>
                        </form>
                    <?php elseif ($productRatingsById): ?>
                        <small class="product-rating-saved-note">All products in this order have been rated. Submitted ratings cannot be changed.</small>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="panel timeline-panel" id="orderTimeline">
                <div class="timeline">
                    <?php
                    $timeline = $is_self_pickup
                        ? [
                            ['label' => 'Order Placed', 'level' => 1],
                            ['label' => 'Processing', 'level' => 2],
                            ['label' => 'Ready for Pickup', 'level' => 3],
                            ['label' => 'Delivered', 'level' => 4]
                        ]
                        : [
                            ['label' => 'Order Placed', 'level' => 1],
                            ['label' => 'Preparing Order', 'level' => 2],
                            ['label' => 'Rider Assigned', 'level' => 3],
                            ['label' => 'Out for Delivery', 'level' => 4],
                            ['label' => 'Delivered', 'level' => 5]
                        ];
                    foreach ($timeline as $index => $step):
                        $active = $status_level >= $step['level'] && $order_status !== 'cancelled';
                    ?>
                        <div class="timeline-step <?= $active ? 'active' : 'inactive' ?>">
                            <div class="timeline-dot"><?= $active ? '<span class="material-icons-outlined" aria-hidden="true">check</span>' : ($index + 1) ?></div>
                            <p><?= $step['label'] ?></p>
                            <?php
                            $milestoneTime = $timeline_timestamps[$step['level']] ?? null;
                            $milestoneLabel = $active
                                ? ($milestoneTime ? date('M d, h:i A', strtotime($milestoneTime)) : 'Time unavailable')
                                : 'Pending';
                            ?>
                            <small><?= htmlspecialchars($milestoneLabel, ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($order_status !== 'delivered' && $order_status !== 'cancelled'): ?>
                    <div class="delivery-alert">
                        <div class="delivery-alert-icon"><span class="material-icons-outlined" aria-hidden="true"><?= $is_out_for_delivery ? 'priority_high' : 'info' ?></span></div>
                        <div>
                            <strong><?= $is_self_pickup
                                ? ($order_status === 'ready_for_pickup' ? 'Your order is ready for pickup!' : 'Your order is being prepared for pickup!')
                                : ($is_out_for_delivery ? 'Your order is on the way!' : 'Your order is being prepared!') ?></strong>
                            <span><?= $is_self_pickup
                                ? ($order_status === 'ready_for_pickup' ? 'Please collect your order from the store within 24 hours.' : 'We will notify you when your order is ready to collect.')
                                : ($is_out_for_delivery ? 'Track your rider’s latest location.' : 'Tracking will be available once your order is out for delivery.') ?></span>
                        </div>
                        <?php if ($is_out_for_delivery): ?>
                            <a class="support-button track track-alert-link" href="track_delivery.php?id=<?= $order_id ?>">
                                <span class="material-icons-outlined track-pin-icon" aria-hidden="true">location_on</span>
                                Track Delivery
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="columns">
                <section class="panel items-card">
                    <div class="card-title"><span class="card-title-icon material-icons-outlined" aria-hidden="true">shopping_bag</span><span>Order Items</span></div>
                    <div class="items-list">
                        <div class="items-table-head"><span>Product</span><span>Quantity</span><span>Price</span></div>
                        <?php foreach ($order_items as $item): ?>
                            <?php $item_image = $item['image'] ?? ''; $item_image_url = strpos($item_image, 'http') === 0 ? $item_image : 'uploads/' . ltrim($item_image, '/'); ?>
                            <div class="item-row">
                                <div class="item-product">
                                    <?php if ($item_image !== ''): ?><img class="item-image" src="<?= htmlspecialchars($item_image_url) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>"><?php else: ?><div class="item-image"></div><?php endif; ?>
                                    <div><div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div><div class="item-info"><?= htmlspecialchars($item['category'] ?? 'Product') ?></div></div>
                                </div>
                                <div class="item-quantity"><?= $item['quantity'] ?><div class="item-info">(<?= $item['quantity'] ?> pc)</div></div>
                                <div class="item-total">₱<?= number_format($item['subtotal'], 2) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="totals">
                        <div class="total-line"><span>Subtotal</span><span>₱<?= number_format($subtotal, 2) ?></span></div>
                        <div class="total-line"><span>Delivery Fee</span><span>₱<?= number_format($delivery_fee, 2) ?></span></div>
                        <div class="total-line final"><span>Total Amount</span><strong>₱<?= number_format($total_amount, 2) ?></strong></div>
                    </div>
                    <div class="payment">
                        <span class="payment-icon" aria-hidden="true"><span class="material-icons-outlined">credit_card</span></span>
                        <div class="payment-details">
                            <strong>Payment Method</strong>
                            <span><?= htmlspecialchars($payment_method_label, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                </section>

                <div>
                    <section class="panel side-card">
                        <div class="card-title"><span class="delivery-title-icon" aria-hidden="true"><span class="material-icons-outlined">local_shipping</span></span><span>Delivery Information</span></div>
                        <div class="delivery-summary">
                            <span class="delivery-summary-icon" aria-hidden="true"><span class="material-icons-outlined">local_shipping</span></span>
                            <div>
                                <div class="delivery-summary-label">Delivery Method</div>
                                <div class="delivery-summary-value"><?= $is_self_pickup ? 'Self Pick Up' : (strtolower(trim((string)($order['delivery_type'] ?? ''))) === 'lalamove' ? 'Rider' : htmlspecialchars(ucfirst($order['delivery_type'] ?? 'Delivery'), ENT_QUOTES, 'UTF-8')) ?></div>
                                <?php if ($is_self_pickup && $order_status === 'ready_for_pickup' && $pickup_deadline !== ''): ?>
                                    <div class="delivery-summary-note">Please collect your order within 24 hours.</div>
                                    <div class="pickup-countdown" data-deadline="<?= htmlspecialchars($pickup_deadline, ENT_QUOTES, 'UTF-8') ?>">Pickup time remaining: <strong>Calculating...</strong></div>
                                <?php elseif (!$is_self_pickup): ?>
                                    <?php if (in_array($order_status, ['pending'], true) && empty($order['rider_id'])): ?>
                                        <div class="delivery-summary-note">Rider assignment will be available once the admin/pharmacist marks the order as Processing.</div>
                                    <?php else: ?>
                                        <div class="delivery-summary-note">A rider will deliver your order to your address.</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!$is_self_pickup && !empty($order['estimated_delivery'])): ?>
                                    <div class="delivery-summary-note">Estimated arrival: <strong><?= date('M d, Y h:i A', strtotime($order['estimated_delivery'])) ?></strong></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <section class="address-card">
                            <span class="delivery-summary-icon" aria-hidden="true"><span class="material-icons-outlined">location_on</span></span>
                            <div class="side-body">
                                <div class="address-label">Delivery Address</div>
                                <div class="address-value"><?= htmlspecialchars($order['fullname']) ?></div>
                                <p><?= nl2br(htmlspecialchars($order['address'])) ?></p>
                            </div>
                        </section>
                        <?php if ($order_status === 'delivered'): ?>
                            <div class="delivery-proof">
                                <h3><?= $is_self_pickup ? 'Pickup Completed' : 'Delivery Completed' ?></h3>
                                <p><?= $is_self_pickup ? 'Picked up at:' : 'Delivered at:' ?>
                                    <strong><?= $deliveredAt ? htmlspecialchars(date('M d, Y • h:i A', strtotime($deliveredAt)), ENT_QUOTES, 'UTF-8') : 'Completion time unavailable' ?></strong>
                                </p>
                                <?php if ($proofImageUrl !== ''): ?>
                                    <img src="<?= htmlspecialchars($proofImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Photo proof of delivery">
                                <?php elseif (!$is_self_pickup): ?>
                                    <p style="margin-bottom:0">No delivery proof photo is available for this order.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </div>
    </main>
    <script>
        document.querySelectorAll('.pickup-countdown').forEach(function (timer) {
            const deadline = new Date(timer.dataset.deadline.replace(' ', 'T')).getTime();
            const remainingText = timer.querySelector('strong');
            const updateCountdown = function () {
                const remaining = deadline - Date.now();
                if (!Number.isFinite(deadline) || remaining <= 0) {
                    remainingText.textContent = 'Time expired';
                    return;
                }
                const hours = Math.floor(remaining / 3600000);
                const minutes = Math.floor((remaining % 3600000) / 60000);
                const seconds = Math.floor((remaining % 60000) / 1000);
                remainingText.textContent = hours + 'h ' + minutes + 'm ' + seconds + 's';
            };
            updateCountdown();
            window.setInterval(updateCountdown, 1000);
        });
    </script>
    <script>
        document.querySelectorAll('.receipt-disabled-wrap').forEach(function (control) {
            control.addEventListener('click', function () {
                control.classList.toggle('is-open');
            });
            control.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    control.classList.toggle('is-open');
                } else if (event.key === 'Escape') {
                    control.classList.remove('is-open');
                }
            });
        });
        document.addEventListener('click', function (event) {
            document.querySelectorAll('.receipt-disabled-wrap.is-open').forEach(function (control) {
                if (!control.contains(event.target)) control.classList.remove('is-open');
            });
        });
    </script>
</body>
</html>