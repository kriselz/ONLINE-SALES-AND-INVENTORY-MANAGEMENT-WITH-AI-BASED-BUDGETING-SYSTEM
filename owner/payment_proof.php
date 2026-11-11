<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_control.php';

requireOwnerPageAccess($conn, true);
$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
if (!$orderId || !pharmacistCanAccessOrder($conn, (int)$orderId)) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$statement = $conn->prepare('SELECT payment_proof FROM orders WHERE id = ? LIMIT 1');
if (!$statement) {
    error_log('Payment proof query preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to open payment proof.');
}
$statement->bind_param('i', $orderId);
if (!$statement->execute()) {
    error_log('Payment proof query failed: ' . $statement->error);
    $statement->close();
    http_response_code(500);
    exit('Unable to open payment proof.');
}
$order = $statement->get_result()->fetch_assoc();
$statement->close();

$fileName = basename(trim((string)($order['payment_proof'] ?? '')));
if (!preg_match('/\Apayment_proof_(?:[a-f0-9]{32}|\d{10}_\d+)\.(?:jpg|png)\z/i', $fileName)) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$uploadDirectory = realpath(__DIR__ . '/../user/uploads/payment_proofs');
$filePath = $uploadDirectory === false ? false : realpath($uploadDirectory . DIRECTORY_SEPARATOR . $fileName);
if ($filePath === false
    || !str_starts_with($filePath, $uploadDirectory . DIRECTORY_SEPARATOR)
    || !is_file($filePath)) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$mimeType = mime_content_type($filePath);
if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit('Payment proof not found.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($filePath));
header('Content-Disposition: inline; filename="payment-proof.' . ($mimeType === 'image/png' ? 'png' : 'jpg') . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
?>
