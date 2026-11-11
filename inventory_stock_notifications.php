<?php

function capstoneCheckAndNotifyStockState($conn, int $productId): array
{
    static $schemaReady = false;
    if ($productId < 1) {
        return ['triggered' => false, 'success' => false, 'error' => 'Invalid product ID.'];
    }

    if (!$schemaReady) {
        $schemaQueries = [
            'ALTER TABLE products ADD COLUMN IF NOT EXISTS stock_alert_state VARCHAR(20) NULL',
            'ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 20',
            'ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_alert_sent TINYINT(1) NOT NULL DEFAULT 0',
            'ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_last_alert_at DATETIME NULL',
            'ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_email_status VARCHAR(20) NULL',
            'ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_email_error TEXT NULL',
            "CREATE TABLE IF NOT EXISTS pharmacy_staff (
                user_id INT NOT NULL PRIMARY KEY,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS low_stock_alert_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                current_stock INT NOT NULL,
                low_stock_threshold INT NOT NULL,
                status ENUM('sent', 'failed') NOT NULL,
                error_message TEXT NULL,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_low_stock_product (product_id),
                KEY idx_low_stock_attempted (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($schemaQueries as $query) {
            if (!$conn->query($query)) {
                error_log('Inventory stock alert schema preparation failed: ' . $conn->error);
                return ['triggered' => false, 'success' => false, 'error' => 'Inventory alert schema is unavailable.'];
            }
        }
        $schemaReady = true;
    }

    $productStmt = $conn->prepare("SELECT p.id, p.product_name, p.sku, p.category,
            (COALESCE(p.pieces, 0) + COALESCE(p.box, 0) * COALESCE(NULLIF(p.pieces_per_box, 0), 12)) AS current_stock,
            COALESCE(p.low_stock_threshold, 20) AS low_stock_threshold,
            p.low_stock_alert_sent, p.stock_alert_state,
            (SELECT MIN(pb.expiration_date)
             FROM product_batches pb
             WHERE pb.product_id = p.id AND pb.current_quantity > 0 AND pb.recalled = 0) AS expiration_date
        FROM products p WHERE p.id = ? LIMIT 1");
    if (!$productStmt) {
        error_log('Inventory stock alert lookup preparation failed: ' . $conn->error);
        return ['triggered' => false, 'success' => false, 'error' => 'Unable to load product stock.'];
    }
    $productStmt->bind_param('i', $productId);
    if (!$productStmt->execute()) {
        error_log('Inventory stock alert lookup failed: ' . $productStmt->error);
        $productStmt->close();
        return ['triggered' => false, 'success' => false, 'error' => 'Unable to load product stock.'];
    }
    $product = $productStmt->get_result()->fetch_assoc();
    $productStmt->close();
    if (!$product) {
        return ['triggered' => false, 'success' => false, 'error' => 'Product not found.'];
    }

    $currentStock = (int)$product['current_stock'];
    $threshold = (int)$product['low_stock_threshold'];
    $targetState = $currentStock <= 0 ? 'out_of_stock' : ($currentStock <= $threshold ? 'low_stock' : 'in_stock');
    $previousState = $product['stock_alert_state'] === null ? null : (string)$product['stock_alert_state'];

    if ($previousState === null && $targetState === 'in_stock') {
        $initialize = $conn->prepare("UPDATE products SET stock_alert_state = 'in_stock' WHERE id = ? AND stock_alert_state IS NULL");
        if ($initialize) {
            $initialize->bind_param('i', $productId);
            if (!$initialize->execute()) {
                error_log('Inventory stock alert state initialization failed: ' . $initialize->error);
            }
            $initialize->close();
        }
        return ['triggered' => false, 'success' => true];
    }

    if ($previousState === null
        && $targetState === 'low_stock'
        && (int)$product['low_stock_alert_sent'] === 1) {
        $initialize = $conn->prepare("UPDATE products SET stock_alert_state = 'low_stock' WHERE id = ? AND stock_alert_state IS NULL");
        if ($initialize) {
            $initialize->bind_param('i', $productId);
            if (!$initialize->execute()) {
                error_log('Legacy low-stock alert state migration failed: ' . $initialize->error);
            }
            $initialize->close();
        }
        return ['triggered' => false, 'success' => true, 'duplicate' => true];
    }

    if ($previousState === $targetState) {
        return ['triggered' => false, 'success' => true, 'duplicate' => true];
    }

    $alertSentFlag = $targetState === 'in_stock' ? 0 : 1;
    $claim = $conn->prepare('UPDATE products
        SET stock_alert_state = ?, low_stock_alert_sent = ?,
            low_stock_last_alert_at = IF(? = 1, NOW(), low_stock_last_alert_at),
            low_stock_email_status = NULL, low_stock_email_error = NULL
        WHERE id = ? AND stock_alert_state <=> ?');
    if (!$claim) {
        error_log('Inventory stock alert transition preparation failed: ' . $conn->error);
        return ['triggered' => false, 'success' => false, 'error' => 'Unable to claim inventory alert transition.'];
    }
    $claim->bind_param('siiis', $targetState, $alertSentFlag, $alertSentFlag, $productId, $previousState);
    if (!$claim->execute()) {
        error_log('Inventory stock alert transition update failed: ' . $claim->error);
        $claim->close();
        return ['triggered' => false, 'success' => false, 'error' => 'Unable to update inventory alert state.'];
    }
    $claimed = $claim->affected_rows === 1;
    $claim->close();
    if (!$claimed) {
        return ['triggered' => false, 'success' => true, 'duplicate' => true];
    }
    if ($targetState === 'in_stock') {
        return ['triggered' => false, 'success' => true, 'reset' => true];
    }

    $alertLabel = $targetState === 'out_of_stock' ? 'OUT OF STOCK' : 'LOW STOCK';
    $notificationText = $alertLabel . ': ' . (string)$product['product_name']
        . ' has ' . number_format($currentStock) . ' pieces remaining'
        . ($targetState === 'low_stock' ? ' (threshold: ' . number_format($threshold) . ').' : '.');
    $staffQuery = $conn->query("SELECT DISTINCT u.id, u.username, u.full_name, u.email
        FROM users u
        WHERE COALESCE(u.is_active, 1) = 1
          AND (LOWER(TRIM(u.role)) IN ('owner', 'admin')
               OR (LOWER(TRIM(u.role)) = 'pharmacist'
                   AND EXISTS (SELECT 1 FROM pharmacy_staff ps WHERE ps.user_id = u.id AND ps.active = 1)))
        ORDER BY u.id ASC");
    if (!$staffQuery) {
        error_log('Inventory stock alert staff lookup failed: ' . $conn->error);
        return ['triggered' => true, 'success' => false, 'error' => 'Unable to load staff notification recipients.'];
    }

    $recipients = [];
    $notification = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())');
    while ($staff = $staffQuery->fetch_assoc()) {
        $staffId = (int)$staff['id'];
        if ($notification) {
            $notification->bind_param('is', $staffId, $notificationText);
            if (!$notification->execute()) {
                error_log('Inventory stock in-app notification failed for staff #' . $staffId . ': ' . $notification->error);
            }
        }
        $email = trim((string)($staff['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[strtolower($email)] = [
                'email' => $email,
                'name' => trim((string)($staff['full_name'] ?: $staff['username'])),
            ];
        }

    }
    if ($notification) {
        $notification->close();
    } else {
        error_log('Inventory stock in-app notification preparation failed: ' . $conn->error);
    }
    $staffQuery->free();

    $emailSuccess = false;
    $emailError = null;
    $smtpHost = getenv('SMTP_HOST');
    $smtpUsername = getenv('SMTP_USERNAME');
    $smtpPassword = getenv('SMTP_PASSWORD');
    $smtpFrom = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
    if (!$smtpHost || !$smtpUsername || !$smtpPassword || !$smtpFrom || !$recipients) {
        $emailError = 'SMTP settings or valid owner/pharmacist email addresses are not configured.';
        error_log('Inventory stock alert email skipped: ' . $emailError);
    } else {
        try {
            require_once __DIR__ . '/owner/vendor/autoload.php';
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUsername;
            $mail->Password = $smtpPassword;
            $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
            $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
            $mail->SMTPAutoTLS = true;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($smtpFrom, getenv('SMTP_FROM_NAME') ?: 'Josefina Farmacia');
            foreach ($recipients as $recipient) {
                $mail->addBCC($recipient['email'], $recipient['name'] ?: 'Pharmacy Staff');
            }
            $productName = (string)$product['product_name'];
            $sku = (string)($product['sku'] ?: ('MED-' . $productId));
            $category = (string)($product['category'] ?: 'Not specified');
            $expiration = !empty($product['expiration_date'])
                ? date('F Y', strtotime((string)$product['expiration_date']))
                : 'Not available';
            $safeProductName = htmlspecialchars($productName, ENT_QUOTES, 'UTF-8');
            $safeSku = htmlspecialchars($sku, ENT_QUOTES, 'UTF-8');
            $safeCategory = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');
            $safeExpiration = htmlspecialchars($expiration, ENT_QUOTES, 'UTF-8');
            $subject = $alertLabel . ' ALERT - ' . $productName;
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = '<h2>' . $alertLabel . ' ALERT</h2><p><strong>' . $safeProductName . '</strong></p>'
                . '<p>SKU: ' . $safeSku . '<br>Category: ' . $safeCategory . '<br>'
                . 'Current stock: <strong>' . number_format($currentStock) . '</strong><br>'
                . 'Low-stock threshold: ' . number_format($threshold) . '<br>'
                . 'Nearest expiration: ' . $safeExpiration . '<br>'
                . 'Alert time: ' . htmlspecialchars(date('F j, Y g:i A'), ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>Please review the inventory and restock as needed.</p>';
            $mail->AltBody = $alertLabel . " alert: $productName (SKU: $sku), stock $currentStock, threshold $threshold. Category: $category. Nearest expiration: $expiration.";
            $mail->send();
            $emailSuccess = true;
        } catch (\Throwable $exception) {
            $emailError = $exception->getMessage();
            error_log('Inventory stock alert email failed: ' . $emailError);
        }
    }

    $emailStatus = $emailSuccess ? 'sent' : 'failed';
    $emailStatusUpdate = $conn->prepare('UPDATE products SET low_stock_email_status = ?, low_stock_email_error = ? WHERE id = ?');
    if ($emailStatusUpdate) {
        $emailStatusUpdate->bind_param('ssi', $emailStatus, $emailError, $productId);
        if (!$emailStatusUpdate->execute()) {
            error_log('Inventory stock alert email status update failed: ' . $emailStatusUpdate->error);
        }
        $emailStatusUpdate->close();
    }

    $alertLog = $conn->prepare('INSERT INTO low_stock_alert_logs (product_id, current_stock, low_stock_threshold, status, error_message) VALUES (?, ?, ?, ?, ?)');
    if ($alertLog) {
        $alertLog->bind_param('iiiss', $productId, $currentStock, $threshold, $emailStatus, $emailError);
        if (!$alertLog->execute()) {
            error_log('Inventory stock alert history insert failed: ' . $alertLog->error);
        }
        $alertLog->close();
    }

    return [
        'triggered' => true,
        'success' => true,
        'email_success' => $emailSuccess,
        'error' => $emailError,
        'state' => $targetState,
        'current_stock' => $currentStock,
        'threshold' => $threshold,
    ];
}

function capstoneNotifyExpiringBatches($conn, int $days = 30): array
{
    static $schemaReady = false;
    $days = max(0, min(365, $days));
    if (!$schemaReady) {
        $schemaQueries = [
            'ALTER TABLE product_batches ADD COLUMN IF NOT EXISTS expiry_alert_sent TINYINT(1) NOT NULL DEFAULT 0',
            "CREATE TABLE IF NOT EXISTS pharmacy_staff (
                user_id INT NOT NULL PRIMARY KEY,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($schemaQueries as $query) {
            if (!$conn->query($query)) {
                error_log('Product expiry notification schema preparation failed: ' . $conn->error);
                return ['success' => false, 'sent' => 0, 'error' => 'Expiry alert schema is unavailable.'];
            }
        }
        $schemaReady = true;
    }

    $reset = $conn->prepare('UPDATE product_batches
        SET expiry_alert_sent = 0
        WHERE expiration_date > DATE_ADD(CURDATE(), INTERVAL ? DAY) AND expiry_alert_sent = 1');
    if (!$reset) {
        error_log('Product expiry alert reset could not be prepared: ' . $conn->error);
        return ['success' => false, 'sent' => 0, 'error' => 'Unable to reset expiry alert state.'];
    }
    $reset->bind_param('i', $days);
    if (!$reset->execute()) {
        error_log('Product expiry alert reset failed: ' . $reset->error);
        $reset->close();
        return ['success' => false, 'sent' => 0, 'error' => 'Unable to reset expiry alert state.'];
    }
    $reset->close();

    $batches = $conn->prepare("SELECT b.id, b.product_id, b.lot_number, b.expiration_date,
            b.current_quantity, p.product_name,
            DATEDIFF(b.expiration_date, CURDATE()) AS days_left
        FROM product_batches b
        JOIN products p ON p.id = b.product_id
        WHERE b.recalled = 0 AND b.current_quantity > 0
          AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
          AND b.expiry_alert_sent = 0
        ORDER BY b.expiration_date ASC, b.id ASC");
    if (!$batches) {
        error_log('Product expiry notification query preparation failed: ' . $conn->error);
        return ['success' => false, 'sent' => 0, 'error' => 'Unable to find expiring batches.'];
    }
    $batches->bind_param('i', $days);
    if (!$batches->execute()) {
        error_log('Product expiry notification query failed: ' . $batches->error);
        $batches->close();
        return ['success' => false, 'sent' => 0, 'error' => 'Unable to find expiring batches.'];
    }
    $expiryResult = $batches->get_result();
    $alertCount = 0;
    $emailErrors = [];

    while ($batch = $expiryResult->fetch_assoc()) {
        $batchId = (int)$batch['id'];
        $claim = $conn->prepare('UPDATE product_batches SET expiry_alert_sent = 1 WHERE id = ? AND expiry_alert_sent = 0 AND recalled = 0 AND current_quantity > 0');
        if (!$claim) {
            error_log('Product expiry alert claim could not be prepared: ' . $conn->error);
            $emailErrors[] = 'Unable to claim expiry alert for batch #' . $batchId . '.';
            continue;
        }
        $claim->bind_param('i', $batchId);
        if (!$claim->execute()) {
            error_log('Product expiry alert claim failed for batch #' . $batchId . ': ' . $claim->error);
            $emailErrors[] = 'Unable to claim expiry alert for batch #' . $batchId . '.';
            $claim->close();
            continue;
        }
        $claimed = $claim->affected_rows === 1;
        $claim->close();
        if (!$claimed) {
            continue;
        }

        $daysLeft = (int)$batch['days_left'];
        $productName = (string)$batch['product_name'];
        $lotNumber = (string)$batch['lot_number'];
        $expirationDate = (string)$batch['expiration_date'];
        $daysDescription = $daysLeft < 0
            ? abs($daysLeft) . ' days past expiration'
            : ($daysLeft === 0 ? 'expires today' : 'expires in ' . $daysLeft . ' days');
        $message = 'EXPIRING SOON: ' . $productName . ' lot ' . $lotNumber . ' '
            . $daysDescription . ' (' . $expirationDate . '); '
            . number_format((int)$batch['current_quantity']) . ' pieces remain.';

        $staffQuery = $conn->query("SELECT DISTINCT u.id, u.username, u.full_name, u.email
            FROM users u
            WHERE COALESCE(u.is_active, 1) = 1
              AND (LOWER(TRIM(u.role)) IN ('owner', 'admin')
                   OR (LOWER(TRIM(u.role)) = 'pharmacist'
                       AND EXISTS (SELECT 1 FROM pharmacy_staff ps WHERE ps.user_id = u.id AND ps.active = 1)))
            ORDER BY u.id ASC");
        if (!$staffQuery) {
            $release = $conn->prepare('UPDATE product_batches SET expiry_alert_sent = 0 WHERE id = ? AND expiry_alert_sent = 1');
            if ($release) {
                $release->bind_param('i', $batchId);
                $release->execute();
                $release->close();
            }
            error_log('Product expiry alert staff lookup failed: ' . $conn->error);
            $emailErrors[] = 'Unable to load staff for batch #' . $batchId . '.';
            continue;
        }

        $recipients = [];
        $notification = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())');
        while ($staff = $staffQuery->fetch_assoc()) {
            $staffId = (int)$staff['id'];
            if ($notification) {
                $notification->bind_param('is', $staffId, $message);
                if (!$notification->execute()) {
                    error_log('Product expiry in-app notification failed for staff #' . $staffId . ': ' . $notification->error);
                }
            }
            $email = trim((string)($staff['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($email)] = [
                    'email' => $email,
                    'name' => trim((string)($staff['full_name'] ?: $staff['username'])),
                ];
            }
        }
        if ($notification) {
            $notification->close();
        } else {
            error_log('Product expiry in-app notification preparation failed: ' . $conn->error);
        }
        $staffQuery->free();

        $smtpHost = getenv('SMTP_HOST');
        $smtpUsername = getenv('SMTP_USERNAME');
        $smtpPassword = getenv('SMTP_PASSWORD');
        $smtpFrom = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
        if (!$smtpHost || !$smtpUsername || !$smtpPassword || !$smtpFrom || !$recipients) {
            $emailErrors[] = 'Expiry email skipped for batch #' . $batchId . ': SMTP settings or staff email addresses are unavailable.';
            error_log(end($emailErrors));
            $alertCount++;
            continue;
        }

        try {
            require_once __DIR__ . '/owner/vendor/autoload.php';
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUsername;
            $mail->Password = $smtpPassword;
            $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
            $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
            $mail->SMTPAutoTLS = true;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($smtpFrom, getenv('SMTP_FROM_NAME') ?: 'Josefina Farmacia');
            foreach ($recipients as $recipient) {
                $mail->addBCC($recipient['email'], $recipient['name'] ?: 'Pharmacy Staff');
            }
            $safeProductName = htmlspecialchars($productName, ENT_QUOTES, 'UTF-8');
            $safeLotNumber = htmlspecialchars($lotNumber, ENT_QUOTES, 'UTF-8');
            $mail->isHTML(true);
            $mail->Subject = 'EXPIRING SOON - ' . $productName . ' (Lot ' . $lotNumber . ')';
            $mail->Body = '<h2>Product Expiry Alert</h2><p><strong>' . $safeProductName . '</strong> (Lot '
                . $safeLotNumber . ') expires on <strong>' . htmlspecialchars($expirationDate, ENT_QUOTES, 'UTF-8')
                . '</strong>.</p><p>Status: <strong>' . htmlspecialchars($daysDescription, ENT_QUOTES, 'UTF-8')
                . '</strong><br>Remaining stock: <strong>' . number_format((int)$batch['current_quantity'])
                . '</strong></p><p>Please review this batch and take appropriate action.</p>';
            $mail->AltBody = "Product expiry alert: $productName, lot $lotNumber, expires $expirationDate ($daysDescription). Remaining stock: " . (int)$batch['current_quantity'] . '.';
            $mail->send();
        } catch (\Throwable $exception) {
            $emailErrors[] = 'Expiry email failed for batch #' . $batchId . '.';
            error_log('Product expiry alert email failed for batch #' . $batchId . ': ' . $exception->getMessage());
        }
        $alertCount++;
    }
    $batches->close();

    return ['success' => !$emailErrors, 'sent' => $alertCount, 'errors' => $emailErrors];
}
