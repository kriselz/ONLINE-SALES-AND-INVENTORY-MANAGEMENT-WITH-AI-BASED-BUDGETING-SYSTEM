<?php
function getGoogleRedirectUri(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/\A[a-z0-9.-]+(?::\d{1,5})?\z/i', $host)) {
        throw new RuntimeException('Unable to determine the Google sign-in host.');
    }

    $forwardedProtocol = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || $forwardedProtocol === 'https';
    $hostName = strtolower((string) preg_replace('/:\d+\z/', '', $host));
    $isLocalHost = in_array($hostName, ['localhost', '127.0.0.1'], true);
    $scheme = $isLocalHost && !$isHttps ? 'http' : 'https';
    $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/google_login.php')));
    $basePath = rtrim($scriptDirectory, '/');

    return $scheme . '://' . $host . $basePath . '/google_callback.php';
}