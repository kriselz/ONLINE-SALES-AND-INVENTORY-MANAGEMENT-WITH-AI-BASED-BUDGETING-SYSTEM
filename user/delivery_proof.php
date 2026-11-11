<?php
session_start();
require_once __DIR__ . '/config.php';

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
if (!$orderId || (int)$orderId < 1) {
    http_response_code(404);
    exit('Delivery proof not found.');
}

$statement = $conn->prepare('SELECT user_id, rider_id, delivery_proof_photo FROM orders WHERE id = ? LIMIT 1');
if (!$statement) {
    error_log('Delivery proof query preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to open delivery proof.');
}
$statement->bind_param('i', $orderId);
if (!$statement->execute()) {
    error_log('Delivery proof query failed: ' . $statement->error);
    $statement->close();
    http_response_code(500);
    exit('Unable to open delivery proof.');
}
$order = $statement->get_result()->fetch_assoc();
$statement->close();

if (!$order) {
    http_response_code(404);
    exit('Delivery proof not found.');
}

if (in_array($_SESSION['role'] ?? '', ['admin', 'pharmacist'], true)) {
    require_once __DIR__ . '/../owner/access_control.php';
    requireOwnerPageAccess($conn, true);
    if (!pharmacistCanAccessOrder($conn, (int)$orderId)) {
        http_response_code(404);
        exit('Delivery proof not found.');
    }
} elseif (($_SESSION['role'] ?? '') !== 'user'
    || (int)($_SESSION['user_id'] ?? 0) !== (int)$order['user_id']) {
    http_response_code(404);
    exit('Delivery proof not found.');
}

$uploadDirectory = realpath(__DIR__ . '/uploads/delivery_proofs');
if ($uploadDirectory === false) {
    http_response_code(404);
    exit('Delivery proof not found.');
}

$storedFileName = basename(trim((string)$order['delivery_proof_photo']));
$candidateFileNames = [];
if (preg_match('/\Adelivery_(?:cancel_)?\d+_[a-f0-9]{12}\.(?:jpg|png)\z/i', $storedFileName)) {
    $candidateFileNames[] = $storedFileName;
}
$legacyProofFiles = glob(
    $uploadDirectory . DIRECTORY_SEPARATOR . 'delivery_' . (int)$orderId . '_*.*'
);
$legacyProofFiles = array_values(array_filter(
    $legacyProofFiles ?: [],
    static fn($file) => preg_match('/\Adelivery_' . (int)$orderId . '_[a-f0-9]{12}\.(?:jpg|png)\z/i', basename($file))
));
if ($legacyProofFiles) {
    usort($legacyProofFiles, static fn($first, $second) => filemtime($second) <=> filemtime($first));
    foreach ($legacyProofFiles as $legacyProofFile) {
        $candidateFileNames[] = basename($legacyProofFile);
    }
}

$filePath = false;
foreach ($candidateFileNames as $candidateFileName) {
    if (!preg_match('/\Adelivery_(?:cancel_)?' . (int)$orderId . '_[a-f0-9]{12}\.(?:jpg|png)\z/i', $candidateFileName)) {
        continue;
    }
    $candidatePath = realpath($uploadDirectory . DIRECTORY_SEPARATOR . $candidateFileName);
    if ($candidatePath !== false
        && str_starts_with($candidatePath, $uploadDirectory . DIRECTORY_SEPARATOR)
        && is_file($candidatePath)) {
        $filePath = $candidatePath;
        break;
    }
}
if ($filePath === false) {
    http_response_code(404);
    exit('Delivery proof not found.');
}

$mimeType = mime_content_type($filePath);
if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit('Delivery proof not found.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($filePath));
header('Content-Disposition: inline; filename="delivery-proof.' . ($mimeType === 'image/png' ? 'png' : 'jpg') . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
?>
