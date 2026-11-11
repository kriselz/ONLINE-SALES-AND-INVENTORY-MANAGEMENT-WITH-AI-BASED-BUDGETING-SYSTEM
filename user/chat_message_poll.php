<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    http_response_code(401);
    echo json_encode(['latestAdminMessageId' => 0]);
    exit();
}

require_once 'config.php';
$userId = (int)$_SESSION['user_id'];
$latest = $conn->prepare("SELECT COALESCE(MAX(id), 0) AS latest_id
    FROM customer_chat_messages WHERE user_id = ? AND sender_role = 'admin'");
if (!$latest) {
    http_response_code(500);
    echo json_encode(['latestAdminMessageId' => 0, 'error' => 'Unable to check chat updates.']);
    exit();
}
$latest->bind_param('i', $userId);
$latest->execute();
$latestAdminMessageId = (int)($latest->get_result()->fetch_assoc()['latest_id'] ?? 0);
$latest->close();

echo json_encode(['latestAdminMessageId' => $latestAdminMessageId]);
