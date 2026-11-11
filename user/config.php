<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_script_directory = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
$current_script_name = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
if ($current_script_directory === __DIR__
    && ($_SESSION['role'] ?? '') === 'user'
    && !empty($_SESSION['complete_google_profile'])
    && !in_array($current_script_name, ['settings.php', 'google_callback.php', 'logout.php'], true)) {
    header('Location: settings.php?tab=account');
    exit();
}

require 'vendor/autoload.php';
require_once __DIR__ . '/../order_number.php';
require_once __DIR__ . '/../app_config.php';
capstoneHardenSessionCookie();

try {
    $conn = capstoneConnectDatabase();
} catch (Throwable $exception) {
    error_log('Customer database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('The customer service is temporarily unavailable. Please try again later.');
}
require_once __DIR__ . '/../audit_log.php';
ensureAuditLogTable($conn);

$ai_api_key = getenv('GEMINI_API_KEY') ?: '';

if (!empty($_SESSION['user_id']) && !empty($_SESSION['login_activity_token'])) {
    require_once __DIR__ . '/login_activity.php';
    if (!ensureUserLoginActivityTable($conn)) {
        error_log('Unable to verify customer login activity session.');
        http_response_code(503);
        exit('Your session could not be verified right now. Please try again later.');
    }

    $sessionTokenHash = hash('sha256', (string) $_SESSION['login_activity_token']);
    $sessionCheck = $conn->prepare('SELECT revoked_at FROM user_login_activity WHERE user_id = ? AND session_token_hash = ? LIMIT 1');
    if (!$sessionCheck) {
        error_log('Customer login activity session check preparation failed: ' . $conn->error);
        http_response_code(503);
        exit('Your session could not be verified right now. Please try again later.');
    }
    $sessionUserId = (int) $_SESSION['user_id'];
    $sessionCheck->bind_param('is', $sessionUserId, $sessionTokenHash);
    if (!$sessionCheck->execute()) {
        error_log('Customer login activity session check failed: ' . $sessionCheck->error);
        $sessionCheck->close();
        http_response_code(503);
        exit('Your session could not be verified right now. Please try again later.');
    }
    $activeSession = $sessionCheck->get_result()->fetch_assoc();
    $sessionCheck->close();

    if (!$activeSession || $activeSession['revoked_at'] !== null) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookieParams = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookieParams['path'], $cookieParams['domain'], $cookieParams['secure'], $cookieParams['httponly']);
        }
        session_destroy();
        header('Location: login.php');
        exit();
    }
}

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

$mailEnvironmentFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'capstone-mail.env';
if (is_file($mailEnvironmentFile) && is_readable($mailEnvironmentFile)) {
    $mailEnvironmentLines = file($mailEnvironmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($mailEnvironmentLines === false) {
        error_log('Unable to read the private SMTP configuration file.');
    } else {
        $mailEnvironmentVariables = [
            'SMTP_HOST',
            'SMTP_USERNAME',
            'SMTP_PASSWORD',
            'SMTP_FROM_EMAIL',
            'SMTP_FROM_NAME',
            'SMTP_ENCRYPTION',
            'SMTP_PORT',
        ];
        foreach ($mailEnvironmentLines as $mailEnvironmentLine) {
            $mailEnvironmentLine = trim($mailEnvironmentLine);
            if ($mailEnvironmentLine === '' || $mailEnvironmentLine[0] === '#' || !str_contains($mailEnvironmentLine, '=')) {
                continue;
            }
            [$mailEnvironmentName, $mailEnvironmentValue] = explode('=', $mailEnvironmentLine, 2);
            $mailEnvironmentName = trim($mailEnvironmentName);
            $mailEnvironmentValue = trim($mailEnvironmentValue);
            if (!in_array($mailEnvironmentName, $mailEnvironmentVariables, true)) {
                continue;
            }
            if (strlen($mailEnvironmentValue) >= 2
                && (($mailEnvironmentValue[0] === '"' && substr($mailEnvironmentValue, -1) === '"')
                    || ($mailEnvironmentValue[0] === "'" && substr($mailEnvironmentValue, -1) === "'"))) {
                $mailEnvironmentValue = substr($mailEnvironmentValue, 1, -1);
            }
            if ($mailEnvironmentName === 'SMTP_PASSWORD') {
                $mailEnvironmentValue = preg_replace('/\s+/', '', $mailEnvironmentValue);
            }
            if ($mailEnvironmentValue !== '') {
                putenv($mailEnvironmentName . '=' . $mailEnvironmentValue);
                $_ENV[$mailEnvironmentName] = $mailEnvironmentValue;
                $_SERVER[$mailEnvironmentName] = $mailEnvironmentValue;
            }
        }
    }
}

$mailHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$mailSMTPAuth = true;
$mailUsername = getenv('SMTP_USERNAME') ?: '';
$mailPassword = preg_replace('/\s+/', '', getenv('SMTP_PASSWORD') ?: '');
$mailSMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
$mailPort = (int)(getenv('SMTP_PORT') ?: 587);
$mailFromEmail = getenv('SMTP_FROM_EMAIL') ?: $mailUsername;
$mailFromName = getenv('SMTP_FROM_NAME') ?: 'Pharmacy OTP System';

function sendOtpEmail($toEmail, $otp, &$errorInfo = '') {
    global $mailHost, $mailSMTPAuth, $mailUsername, $mailPassword, $mailSMTPSecure, $mailPort, $mailFromEmail, $mailFromName;

    if ($mailUsername === '' || $mailPassword === '' || $mailFromEmail === '') {
        $errorInfo = 'SMTP credentials are not configured.';
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $mailHost;
        $mail->SMTPAuth = $mailSMTPAuth;
        $mail->Username = $mailUsername;
        $mail->Password = $mailPassword;
        $mail->SMTPSecure = $mailSMTPSecure;
        $mail->Port = $mailPort;
        $mail->SMTPAutoTLS = true;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($mailFromEmail, $mailFromName);
        $mail->addAddress($toEmail);
        $mail->Subject = 'Your OTP Code';
        $mail->Body = "Your OTP is: $otp";

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $errorInfo = $e->getMessage();
        error_log('OTP email failed: ' . $errorInfo);
        return false;
    }
}

function sendWelcomeEmail($toEmail, $name, &$errorInfo = '') {
    global $mailHost, $mailSMTPAuth, $mailUsername, $mailPassword, $mailSMTPSecure, $mailPort, $mailFromEmail, $mailFromName;

    if ($mailUsername === '' || $mailPassword === '' || $mailFromEmail === '') {
        $errorInfo = 'SMTP credentials are not configured.';
        error_log('Welcome email failed: SMTP credentials are not configured.');
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $mailHost;
        $mail->SMTPAuth = $mailSMTPAuth;
        $mail->Username = $mailUsername;
        $mail->Password = $mailPassword;
        $mail->SMTPSecure = $mailSMTPSecure;
        $mail->Port = $mailPort;
        $mail->SMTPAutoTLS = true;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($mailFromEmail, $mailFromName);
        $mail->addAddress($toEmail);
        $mail->Subject = 'Welcome to Our Platform! 🎉';
        $mail->isHTML(true);

        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 20px; color: #333; line-height: 1.6; }
                .footer { background-color: #f8f9fa; padding: 15px; text-align: center; border-radius: 0 0 10px 10px; color: #666; font-size: 12px; }
                .button { display: inline-block; background-color: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Welcome to Our Platform!</h1>
                </div>
                <div class='content'>
                    <h2>Hello {$name}!</h2>
                    <p>Thank you for signing in with your Google account. Your account has been successfully created and you're now ready to explore our platform.</p>
                    <p>You can now:</p>
                    <ul>
                        <li>Browse our products</li>
                        <li>Place orders</li>
                        <li>Track your order history</li>
                        <li>Update your profile</li>
                    </ul>
                    <p>If you have any questions, feel free to contact our support team.</p>
                    <p>Happy shopping!</p>
                    <br>
                    <a href='" . htmlspecialchars(capstoneUrl('user/product_screen.php'), ENT_QUOTES, 'UTF-8') . "' class='button'>Start Shopping</a>
                </div>
                <div class='footer'>
                    <p>This email was sent automatically. Please do not reply to this message.</p>
                    <p>&copy; 2024 Our E-commerce Platform. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $errorInfo = $e->getMessage();
        return false;
    }
}
?>