<?php
include 'config.php';
session_start();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? 0;

if ($user_id === 0) {
    echo json_encode(['count' => 0]);
    exit();
}

// Get count of unread notifications
$result = $conn->query("
    SELECT COUNT(*) as count 
    FROM notifications 
    WHERE user_id = $user_id AND status = 'unread'
");

$row = $result->fetch_assoc();
echo json_encode(['count' => $row['count']]);
?>
