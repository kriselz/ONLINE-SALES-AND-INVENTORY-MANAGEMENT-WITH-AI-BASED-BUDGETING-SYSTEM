<?php
session_start();
$riderId = ($_SESSION['role'] ?? '') === 'rider' ? (int)($_SESSION['user_id'] ?? 0) : 0;
if ($riderId > 0) {
    require_once __DIR__ . '/../owner/config.php';
    $availabilityStmt = $conn->prepare('UPDATE rider_profiles SET is_available = 0 WHERE user_id = ?');
    if (!$availabilityStmt) {
        error_log('Unable to mark rider offline during logout: ' . $conn->error);
    } else {
        $availabilityStmt->bind_param('i', $riderId);
        if (!$availabilityStmt->execute()) {
            error_log('Unable to mark rider offline during logout: ' . $availabilityStmt->error);
        }
        $availabilityStmt->close();
    }
}
$_SESSION = [];
session_destroy();
header('Location: rider_login.php');
exit();
