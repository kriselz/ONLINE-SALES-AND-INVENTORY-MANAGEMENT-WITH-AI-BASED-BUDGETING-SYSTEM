<?php
session_start();
include 'config.php';
require 'vendor/autoload.php';
require_once 'login_activity.php';
require_once __DIR__ . '/google_profile.php';
require_once __DIR__ . '/google_oauth.php';

foreach ([
    'full_name' => 'VARCHAR(255) NULL',
    'dob' => 'DATE NULL',
    'gender' => 'VARCHAR(20) NULL'
] as $profile_column => $profile_definition) {
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE '$profile_column'");
    if ($column_check && $column_check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN $profile_column $profile_definition");
    }
}

$client = new Google_Client();
$client->setClientId('');
$client->setClientSecret('');
$client->setRedirectUri(getGoogleRedirectUri());

if (isset($_GET['code'])) {
    try {
        // 1. Palitan ang fetchAccessTokenWithAuthCode() ng authenticate() para sa lumang version
        $client->authenticate($_GET['code']);
        $token = $client->getAccessToken();

        if (!$token) {
            throw new Exception("Failed to get access token");
        }

        // Kunin ang access token string (depende kung array o string ang returned token)
        $accessTokenString = is_array($token) ? $token['access_token'] : json_decode($token)->access_token;

        // 2. MANUAL API CALL gamit ang cURL (Bypasses verifyIdToken BigInteger Error)
        $userInfoUrl = "https://www.googleapis.com/oauth2/v1/userinfo?access_token=" . $accessTokenString;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (!isset($data['email'])) {
            throw new Exception("Failed to get user info from Google API.");
        }

        $email = $data['email'];
        $name = $data['name'] ?? 'Google User';

        // 3. DATABASE LOGIC (Existing User Check)
        $stmt = $conn->prepare("SELECT id, full_name, username, email, phone, dob, gender FROM users WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $user_id = (int) $user['id'];
            if (trim((string) ($user['full_name'] ?? '')) === '' && trim($name) !== '') {
                $update_name_stmt = $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?");
                if ($update_name_stmt) {
                    $update_name_stmt->bind_param("si", $name, $user_id);
                    $update_name_stmt->execute();
                    $update_name_stmt->close();
                    $user['full_name'] = $name;
                }
            }
        } else {
            // New User Insert
            $username = 'google_' . bin2hex(random_bytes(6));
            $insert_stmt = $conn->prepare("INSERT INTO users (full_name, username, email, password, role, address, phone) VALUES (?, ?, ?, '', 'user', '', NULL)");
            if (!$insert_stmt) {
                error_log('Google user insert preparation failed: ' . $conn->error);
                $_SESSION['error_message'] = 'Google sign-in could not create your account. Please try again.';
                header('Location: login.php');
                exit();
            }
            $insert_stmt->bind_param("sss", $name, $username, $email);
            if (!$insert_stmt->execute()) {
                error_log('Google user insert failed: ' . $insert_stmt->error);
                $insert_stmt->close();
                $_SESSION['error_message'] = 'Google sign-in could not create your account. Please try again.';
                header('Location: login.php');
                exit();
            }
            $user_id = (int)$insert_stmt->insert_id;
            $insert_stmt->close();
            $user = [
                'full_name' => $name,
                'username' => $username,
                'email' => $email,
                'phone' => '',
                'dob' => '',
                'gender' => ''
            ];
        }

        $last_login_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        if ($last_login_stmt) {
            $last_login_stmt->bind_param("i", $user_id);
            $last_login_stmt->execute();
            $last_login_stmt->close();
        }

        // 4. SESSIONS
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = (string) ($user['username'] ?? '');
        $_SESSION['email'] = $email;
        $_SESSION['role'] = 'user';
        $_SESSION['auth_provider'] = 'google';
        recordUserLoginActivity($conn, (int) $user_id, 'google');
        writeAuditLog($conn, 'login', 'user', (int)$user_id, ['method' => 'google']);
        $_SESSION['success_message'] = 'Google login successful!';

        // 5. REDIRECT
        $profileComplete = isGoogleProfileComplete($user);
        $hasPinnedDefaultAddress = hasPinnedDefaultDeliveryAddress($conn, $user_id);
        if (!$profileComplete || !$hasPinnedDefaultAddress) {
            $_SESSION['complete_google_profile'] = true;
            unset($_SESSION['success_message']);
            header('Location: settings.php?tab=' . ($profileComplete ? 'addresses' : 'account'));
        } else {
            unset($_SESSION['complete_google_profile']);
            header("Location: product_screen.php");
        }
        exit();

    } catch (Exception $e) {
        $_SESSION['error_message'] = "Google login error: " . $e->getMessage();
        header("Location: login.php?google_error=" . urlencode($e->getMessage()));
        exit();
    }
} else {
    header("Location: login.php");
    exit();
}