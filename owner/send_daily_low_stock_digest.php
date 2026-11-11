<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$expiryResult = capstoneNotifyExpiringBatches($conn, 30);
if (!$expiryResult['success']) {
    fwrite(STDERR, 'Expiring-batch notifications were incomplete: '
        . implode(' ', $expiryResult['errors'] ?? [$expiryResult['error'] ?? 'Unknown error'])
        . "\n");
}

$result = sendDailyLowStockDigest($conn);
if (!empty($result['sent'])) {
    echo 'Daily low-stock email sent for ' . (int)$result['count'] . " product(s).\n";
    exit($expiryResult['success'] ? 0 : 1);
}
if (!empty($result['no_alerts'])) {
    echo "No products are at or below their low-stock thresholds today.\n";
    exit($expiryResult['success'] ? 0 : 1);
}
if (!empty($result['already_sent'])) {
    echo "Today's low-stock email has already been sent.\n";
    exit($expiryResult['success'] ? 0 : 1);
}
if (!empty($result['already_processing'])) {
    echo "Today's low-stock email is already being processed.\n";
    exit($expiryResult['success'] ? 0 : 1);
}

fwrite(STDERR, 'Daily low-stock email failed: ' . ($result['error'] ?? 'Unknown error') . "\n");
exit(1);
