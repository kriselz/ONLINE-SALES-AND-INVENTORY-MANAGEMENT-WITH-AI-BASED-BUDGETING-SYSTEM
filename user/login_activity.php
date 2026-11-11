<?php
function ensureUserLoginActivityTable($conn) {
    static $tableReady = false;
    if ($tableReady) {
        return true;
    }

    $created = $conn->query("CREATE TABLE IF NOT EXISTS user_login_activity (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        session_token_hash CHAR(64) NOT NULL,
        login_method ENUM('password', 'google') NOT NULL,
        user_agent VARCHAR(512) NOT NULL DEFAULT '',
        logged_in_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL DEFAULT NULL,
        INDEX idx_user_login_activity_user_time (user_id, logged_in_at),
        INDEX idx_user_login_activity_session (session_token_hash),
        CONSTRAINT fk_user_login_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!$created) {
        return false;
    }

    $revokedColumn = $conn->query("SHOW COLUMNS FROM user_login_activity LIKE 'revoked_at'");
    if (!$revokedColumn) {
        return false;
    }
    if ($revokedColumn->num_rows === 0 && !$conn->query('ALTER TABLE user_login_activity ADD COLUMN revoked_at DATETIME NULL DEFAULT NULL')) {
        return false;
    }

    $tableReady = true;
    return $tableReady;
}

function recordUserLoginActivity($conn, $userId, $loginMethod) {
    if (!ensureUserLoginActivityTable($conn) || session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $sessionToken = bin2hex(random_bytes(32));
    $sessionTokenHash = hash('sha256', $sessionToken);
    $loginMethod = $loginMethod === 'google' ? 'google' : 'password';
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
    $stmt = $conn->prepare('INSERT INTO user_login_activity (user_id, session_token_hash, login_method, user_agent) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('isss', $userId, $sessionTokenHash, $loginMethod, $userAgent);
    $recorded = $stmt->execute();
    $stmt->close();

    if ($recorded) {
        $_SESSION['login_activity_token'] = $sessionToken;
    }

    return $recorded;
}

function getLoginActivityDeviceLabel($userAgent) {
    $userAgent = (string) $userAgent;
    $browser = 'Browser';
    if (preg_match('/Edg(?:e|A|iOS)?\//i', $userAgent)) {
        $browser = 'Edge';
    } elseif (preg_match('/OPR\//i', $userAgent)) {
        $browser = 'Opera';
    } elseif (preg_match('/CriOS\//i', $userAgent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Chrome\//i', $userAgent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/FxiOS\//i', $userAgent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Firefox\//i', $userAgent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Safari\//i', $userAgent)) {
        $browser = 'Safari';
    }

    if (preg_match('/Android/i', $userAgent)) {
        $platform = 'Android';
    } elseif (preg_match('/iPhone|iPad|iPod/i', $userAgent)) {
        $platform = 'iOS';
    } elseif (preg_match('/Windows NT/i', $userAgent)) {
        $platform = 'Windows';
    } elseif (preg_match('/Macintosh|Mac OS X/i', $userAgent)) {
        $platform = 'macOS';
    } elseif (preg_match('/Linux/i', $userAgent)) {
        $platform = 'Linux';
    } else {
        $platform = 'Unknown device';
    }

    return $browser . ' · ' . $platform;
}

function getLoginActivityTimeLabel($loggedInAt) {
    $timestamp = strtotime((string) $loggedInAt);
    if ($timestamp === false) {
        return 'Date unavailable';
    }

    if (date('Y-m-d', $timestamp) === date('Y-m-d')) {
        return 'Today, ' . date('g:i A', $timestamp);
    }
    if (date('Y-m-d', $timestamp) === date('Y-m-d', strtotime('yesterday'))) {
        return 'Yesterday, ' . date('g:i A', $timestamp);
    }

    return date('M j, Y', $timestamp);
}