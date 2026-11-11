<?php

function ensureRiderDeliverySchema($conn) {
    $queries = [
        "CREATE TABLE IF NOT EXISTS rider_profiles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            phone VARCHAR(30) NULL,
            vehicle_type VARCHAR(50) NULL,
            plate_number VARCHAR(30) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_latitude DECIMAL(10,7) NULL",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_longitude DECIMAL(10,7) NULL",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_proof_photo VARCHAR(255) NULL",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS rider_earnings DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_shared_at DATETIME NULL",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_shared_by INT NULL",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_share_token VARCHAR(64) NULL",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS pickup_deadline DATETIME NULL",
        "ALTER TABLE rider_profiles ADD COLUMN IF NOT EXISTS is_available TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE rider_profiles ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL",
        "ALTER TABLE rider_profiles ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,7) NULL",
        "ALTER TABLE rider_profiles ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) NULL",
        "ALTER TABLE rider_profiles ADD COLUMN IF NOT EXISTS profile_image VARCHAR(255) NULL",
        "CREATE TABLE IF NOT EXISTS delivery_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            rider_id INT NULL,
            event_type VARCHAR(40) NOT NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (order_id), INDEX (rider_id)
        )",
    ];
    foreach ($queries as $query) {
        $conn->query($query);
    }

    foreach (['delivery_otp_hash', 'delivery_otp_verified_at'] as $otpColumn) {
        $columnResult = $conn->query("SHOW COLUMNS FROM orders LIKE '" . $conn->real_escape_string($otpColumn) . "'");
        if (!$columnResult) {
            error_log('Unable to check for obsolete rider OTP column: ' . $conn->error);
            throw new RuntimeException('Unable to update rider delivery database schema.');
        }
        if ($columnResult->num_rows > 0 && !$conn->query('ALTER TABLE orders DROP COLUMN `' . $otpColumn . '`')) {
            error_log('Unable to remove obsolete rider OTP column ' . $otpColumn . ': ' . $conn->error);
            throw new RuntimeException('Unable to remove obsolete rider OTP database fields.');
        }
    }

    $notificationsTable = $conn->query("SHOW TABLES LIKE 'notifications'");
    if (!$notificationsTable) {
        error_log('Unable to check for obsolete rider OTP notifications: ' . $conn->error);
        throw new RuntimeException('Unable to update rider delivery notifications.');
    }
    if ($notificationsTable->num_rows > 0) {
        $notificationsCleanup = $conn->query("DELETE FROM notifications WHERE message LIKE 'Your delivery OTP for order %'");
        if (!$notificationsCleanup) {
            error_log('Unable to remove obsolete rider OTP notifications: ' . $conn->error);
            throw new RuntimeException('Unable to remove obsolete rider OTP notifications.');
        }
    }
}

function riderNotify($conn, $userId, $message) {
    $userId = (int)$userId;
    if ($userId <= 0 || trim($message) === '') {
        return false;
    }
    $stmt = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('is', $userId, $message);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function recordDeliveryEvent($conn, $orderId, $riderId, $eventType, $note = '') {
    $stmt = $conn->prepare('INSERT INTO delivery_events (order_id, rider_id, event_type, note) VALUES (?, NULLIF(?, 0), ?, NULLIF(?, \'\'))');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiss', $orderId, $riderId, $eventType, $note);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function deliveryEventLabel($eventType) {
    return ucwords(str_replace('_', ' ', $eventType));
}
