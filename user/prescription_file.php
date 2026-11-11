<?php
session_start();
require_once 'config.php';

$role = $_SESSION['role'] ?? '';
$isPharmacist = false;
if ($role === 'pharmacist') {
    require_once __DIR__ . '/../owner/access_control.php';
    $isPharmacist = requireOwnerPageAccess($conn, true);
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(404);
    exit();
}

$recordId = (int)($_GET['id'] ?? $_GET['order_id'] ?? 0);
$type = $_GET['type'] ?? 'prescription';
if ($recordId < 1 || !in_array($type, ['prescription', 'valid_id', 'order'], true)) {
    http_response_code(404);
    exit();
}

$filePathSelect = 'NULL AS file_path';
if ($type !== 'order') {
    $filePathColumn = $conn->query("SHOW COLUMNS FROM prescriptions LIKE 'file_path'");
    if ($filePathColumn && $filePathColumn->num_rows > 0) {
        $filePathSelect = 'file_path';
    }
}
$query = $type === 'order'
    ? 'SELECT user_id, prescription_image AS file_name, NULL AS file_path, NULL AS valid_id_file_name FROM orders WHERE id = ? LIMIT 1'
    : "SELECT user_id, file_name, $filePathSelect, valid_id_file_name FROM prescriptions WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($query);
if (!$stmt) {
    http_response_code(404);
    exit();
}
$stmt->bind_param('i', $recordId);
$stmt->execute();
$prescription = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isStaff = $role === 'admin' || $isPharmacist;
if (!$prescription || (!$isStaff && (int)$prescription['user_id'] !== (int)$_SESSION['user_id'])) {
    http_response_code(404);
    exit();
}

$storedNames = $type === 'valid_id'
    ? [(string)($prescription['valid_id_file_name'] ?? '')]
    : [(string)($prescription['file_name'] ?? ''), (string)($prescription['file_path'] ?? '')];
$uploadDirectories = [__DIR__ . '/uploads/prescriptions', __DIR__ . '/uploads'];
$filePath = '';
foreach ($storedNames as $storedName) {
    $fileName = basename(str_replace('\\', '/', $storedName));
    if ($fileName === '') {
        continue;
    }
    foreach ($uploadDirectories as $uploadDirectory) {
        $candidatePath = $uploadDirectory . '/' . $fileName;
        if (is_file($candidatePath)) {
            $filePath = $candidatePath;
            break 2;
        }
    }
}
if ($filePath === '') {
    http_response_code(404);
    exit();
}

$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($filePath);
$extensionsByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($extensionsByMime[$mimeType])) {
    http_response_code(404);
    exit();
}

header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . ($type === 'valid_id' ? 'valid-id' : 'prescription') . '.' . $extensionsByMime[$mimeType] . '"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($filePath);