<?php
include 'config.php';
session_start();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? 0;

if ($user_id === 0) {
    echo json_encode(['notifications' => []]);
    exit();
}

// Get unread notifications for this user
$result = $conn->query("
    SELECT id, message, created_at 
    FROM notifications 
    WHERE user_id = $user_id AND status = 'unread' 
    ORDER BY id DESC 
    LIMIT 10
");

$notifications = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
}

echo json_encode(['notifications' => $notifications]);
?>
