<?php
function hasPendingTemporaryPasswordChange(mysqli $conn, int $userId): bool
{
    $stmt = $conn->prepare('SELECT password_change_required FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare temporary password status query: ' . $conn->error);
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to check temporary password status: ' . $error);
    }
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$user) {
        throw new RuntimeException('Unable to find the signed-in user while checking temporary password status.');
    }

    return (int)$user['password_change_required'] === 1;
}
