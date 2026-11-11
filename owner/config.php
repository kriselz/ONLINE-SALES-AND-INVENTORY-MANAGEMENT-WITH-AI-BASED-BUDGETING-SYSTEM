<?php
require_once __DIR__ . '/../order_number.php';
require_once __DIR__ . '/../app_config.php';
capstoneHardenSessionCookie();

function loadOwnerMailEnvironment(): void {
    $mailEnvironmentFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'capstone-mail.env';
    if (!is_file($mailEnvironmentFile) || !is_readable($mailEnvironmentFile)) {
        return;
    }

    $allowedVariables = [
        'SMTP_HOST',
        'SMTP_USERNAME',
        'SMTP_PASSWORD',
        'SMTP_FROM_EMAIL',
        'SMTP_FROM_NAME',
        'SMTP_ENCRYPTION',
        'SMTP_PORT',
    ];
    $lines = file($mailEnvironmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        error_log('Unable to read the private SMTP configuration file.');
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!in_array($name, $allowedVariables, true)) {
            continue;
        }
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"')
            || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($name === 'SMTP_PASSWORD') {
            $value = preg_replace('/\s+/', '', $value);
        }
        if ($value !== '') {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

loadOwnerMailEnvironment();

try {
    $conn = capstoneConnectDatabase();
} catch (Throwable $exception) {
    error_log('Owner database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('The owner service is temporarily unavailable. Please try again later.');
}
require_once __DIR__ . '/../audit_log.php';
ensureAuditLogTable($conn);
?>