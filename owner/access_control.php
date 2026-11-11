<?php
require_once __DIR__ . '/temporary_password_helpers.php';

function isActivePharmacist(mysqli $conn): bool
{
    if (($_SESSION['role'] ?? '') !== 'pharmacist'
        || (int)($_SESSION['user_id'] ?? 0) < 1
        || (int)($_SESSION['pharmacist_id'] ?? 0) !== (int)$_SESSION['user_id']) {
        return false;
    }

    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT 1 FROM users u JOIN pharmacy_staff s ON s.user_id = u.id WHERE u.id = ? AND u.role = 'pharmacist' AND s.active = 1 LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $active = $result && $result->num_rows === 1;
    $stmt->close();
    return $active;
}

function requireOwnerPageAccess(mysqli $conn, bool $allowPharmacist = false): bool
{
    if (($_SESSION['role'] ?? '') === 'admin' && (int)($_SESSION['user_id'] ?? 0) > 0) {
        return false;
    }

    if (($_SESSION['role'] ?? '') === 'pharmacist'
        && basename($_SERVER['PHP_SELF'] ?? '') !== 'temporary_password.php') {
        try {
            $passwordChangeRequired = hasPendingTemporaryPasswordChange($conn, (int)$_SESSION['user_id']);
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            http_response_code(500);
            exit('Unable to verify the account password status.');
        }
        if ($passwordChangeRequired && basename($_SERVER['PHP_SELF'] ?? '') !== 'profile.php') {
            $_SESSION['password_change_required'] = true;
            header('Location: profile.php');
            exit();
        }
        if (!$passwordChangeRequired) {
            unset($_SESSION['password_change_required']);
        }
    }

    if (isActivePharmacist($conn)) {
        if ($allowPharmacist) {
            return true;
        }

        http_response_code(403);
        exit('This page is available to the owner only.');
    }

    $loginPage = ($_SESSION['role'] ?? '') === 'pharmacist' ? 'pharmacist_login.php' : 'login_a.php';
    header('Location: ' . $loginPage);
    exit();
}

function pharmacistCanAccessOrder(mysqli $conn, int $orderId): bool
{
    if (($_SESSION['role'] ?? '') !== 'pharmacist') {
        return true;
    }

    $stmt = $conn->prepare("SELECT 1 FROM orders WHERE id = ? AND LOWER(TRIM(COALESCE(delivery_type, ''))) NOT IN ('onsite', 'private') LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $canAccess = $result && $result->num_rows === 1;
    $stmt->close();
    return $canAccess;
}