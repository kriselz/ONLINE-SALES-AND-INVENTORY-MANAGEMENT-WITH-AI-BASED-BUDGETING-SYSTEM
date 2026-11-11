<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$processed = processDueInventoryReorders($conn);
if ($processed < 0) {
    fwrite(STDERR, "Unable to process due inventory reorders. Check the PHP error log.\n");
    exit(1);
}

if ($processed > 0) {
    fwrite(STDOUT, date('Y-m-d H:i:s') . " Stocked in $processed due reorder(s).\n");
}