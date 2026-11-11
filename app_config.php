<?php
function capstoneConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'APP_ENV' => 'development',
        'APP_BASE_URL' => '',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_NAME' => 'admin_dashboard_system',
        'DB_USER' => 'root',
        'DB_PASSWORD' => '',
        'SMTP_HOST' => '',
        'SMTP_PORT' => '',
        'SMTP_ENCRYPTION' => '',
        'SMTP_USERNAME' => '',
        'SMTP_PASSWORD' => '',
        'SMTP_FROM_EMAIL' => '',
        'SMTP_FROM_NAME' => '',
        'GEMINI_API_KEY' => '',
    ];

    $deploymentConfigFile = __DIR__ . DIRECTORY_SEPARATOR . 'deployment-config.php';
    if (is_file($deploymentConfigFile)) {
        $deploymentConfig = require $deploymentConfigFile;
        if (!is_array($deploymentConfig)) {
            throw new RuntimeException('deployment-config.php must return an array.');
        }
        foreach ($config as $key => $value) {
            if (isset($deploymentConfig[$key]) && is_scalar($deploymentConfig[$key])) {
                $config[$key] = (string)$deploymentConfig[$key];
            }
        }
    }

    foreach ($config as $key => $value) {
        $environmentValue = getenv($key);
        if ($environmentValue !== false) {
            $config[$key] = $environmentValue;
        }
    }

    if (strtolower($config['APP_ENV']) === 'production'
        && ($config['DB_HOST'] === '127.0.0.1'
            || $config['DB_NAME'] === 'admin_dashboard_system'
            || $config['DB_USER'] === 'root'
            || $config['DB_PASSWORD'] === '')) {
        throw new RuntimeException('Production database settings are incomplete.');
    }

    foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_ENCRYPTION', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME', 'GEMINI_API_KEY'] as $key) {
        if ($config[$key] !== '') {
            putenv($key . '=' . $config[$key]);
            $_ENV[$key] = $config[$key];
            $_SERVER[$key] = $config[$key];
        }
    }

    return $config;
}

function capstoneBaseUrl(): string
{
    $configuredBaseUrl = rtrim(capstoneConfig()['APP_BASE_URL'], '/');
    if ($configuredBaseUrl !== '') {
        return $configuredBaseUrl;
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/\A(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(?::\d{1,5})?\z/', $host)) {
        return '';
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $appPath = dirname(dirname($scriptName));
    $appPath = $appPath === '/' || $appPath === '.' ? '' : '/' . trim($appPath, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host . $appPath;
}

function capstoneUrl(string $path): string
{
    return capstoneBaseUrl() . '/' . ltrim($path, '/');
}

function capstoneHardenSessionCookie(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    $cookie = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => 0,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function capstoneConnectDatabase(): mysqli
{
    $config = capstoneConfig();
    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = mysqli_init();
    $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $connection->options(MYSQLI_OPT_READ_TIMEOUT, 5);

    if (!@$connection->real_connect(
        $config['DB_HOST'],
        $config['DB_USER'],
        $config['DB_PASSWORD'],
        $config['DB_NAME'],
        (int)$config['DB_PORT']
    )) {
        throw new RuntimeException('Database connection failed: ' . $connection->connect_error);
    }

    $connection->set_charset('utf8mb4');
    return $connection;
}
?>
