<?php
session_start();
require_once __DIR__ . '/../user/config.php';
require_once __DIR__ . '/access_control.php';

$isPharmacist = requireOwnerPageAccess($conn, true);

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId < 1) {
    http_response_code(400);
    exit('Invalid order.');
}
if ($isPharmacist && !pharmacistCanAccessOrder($conn, $orderId)) {
    http_response_code(403);
    exit('You do not have access to this order.');
}

$stmt = $conn->prepare(
    "SELECT o.discount_id_proof, d.image_data, d.image_mime
     FROM orders o
     LEFT JOIN order_discount_ids d ON d.order_id = o.id
     WHERE o.id = ? AND o.discount_type IN ('senior_citizen', 'pwd')
     LIMIT 1"
);
if (!$stmt) {
    error_log('Discount ID lookup preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load the discount ID.');
}
$stmt->bind_param('i', $orderId);
if (!$stmt->execute()) {
    error_log('Discount ID lookup failed: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    exit('Unable to load the discount ID.');
}
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

$imageData = $order['image_data'] ?? null;
$mimeType = (string)($order['image_mime'] ?? '');
if (!is_string($imageData) || $imageData === '') {
    $relativePath = (string)($order['discount_id_proof'] ?? '');
    if (!preg_match('/^uploads\/discount_ids\/discount_id_[0-9]+_[a-f0-9]{32}\.(?:jpg|png)$/', $relativePath)) {
        http_response_code(404);
        exit('Discount ID not found.');
    }

    $filePath = __DIR__ . '/../user/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($filePath) || filesize($filePath) > 5 * 1024 * 1024) {
        http_response_code(404);
        exit('Discount ID not found.');
    }
    $mimeType = mime_content_type($filePath);
    $imageData = file_get_contents($filePath);
    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true) || !is_string($imageData)) {
        http_response_code(415);
        exit('Unsupported discount ID image.');
    }

    $saveStmt = $conn->prepare(
        'INSERT INTO order_discount_ids (order_id, image_data, image_mime) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE order_id = VALUES(order_id)'
    );
    if (!$saveStmt) {
        error_log('Legacy discount ID migration preparation failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to save the discount ID.');
    }
    $emptyBlob = null;
    $saveStmt->bind_param('ibs', $orderId, $emptyBlob, $mimeType);
    $saveStmt->send_long_data(1, $imageData);
    if (!$saveStmt->execute()) {
        error_log('Legacy discount ID migration failed: ' . $saveStmt->error);
        $saveStmt->close();
        http_response_code(500);
        exit('Unable to save the discount ID.');
    }
    $saveStmt->close();
}

if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('Unsupported discount ID image.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . strlen($imageData));
header('Content-Disposition: inline; filename="discount-id-order-' . $orderId . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
echo $imageData;
