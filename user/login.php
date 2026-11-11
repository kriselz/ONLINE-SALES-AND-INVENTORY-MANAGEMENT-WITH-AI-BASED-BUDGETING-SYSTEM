<?php
// --- CONFIGURATION AT SESSION ---
error_reporting(E_ALL); 
ini_set('display_errors', 1);

session_start(); 
session_regenerate_id(true); 

include 'config.php'; // Siguraduhing tama ang database connection mo dito
require_once 'login_activity.php';

function verify_user_password($password, $hash) {
    if (password_verify($password, $hash)) {
        return true;
    }

    if (strlen($hash) === 32 && md5($password) === $hash) {
        return true;
    }

    return false;
}

$error = ""; 
$success = "";

if (isset($_GET['reset']) && $_GET['reset'] == 'success') {
    $success = "Password reset successful! Please log in.";
}
if (isset($_POST['login'])) { 

    $username = trim($_POST['username']); 
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND role='user' LIMIT 1");

    if (!$stmt) {
        die("SQL Error: " . $conn->error);
    }

    $stmt->bind_param("ss", $username, $username); 
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();

        if (verify_user_password($password, $row['password'])) {
            if (isset($row['is_active']) && (int) $row['is_active'] !== 1) {
                $error = "Please verify your email before logging in.";
            } else {
            if (strlen($row['password']) === 32) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                if ($update) {
                    $update->bind_param("si", $newHash, $row['id']);
                    $update->execute();
                }
            }

            $last_login_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            if ($last_login_stmt) {
                $last_login_stmt->bind_param("i", $row['id']);
                $last_login_stmt->execute();
                $last_login_stmt->close();
            }

            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['email'] = $row['email'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['auth_provider'] = 'password';
            recordUserLoginActivity($conn, (int) $row['id'], 'password');
            writeAuditLog($conn, 'login', 'user', (int)$row['id'], ['method' => 'password']);
            $_SESSION['success_message'] = 'Login successful! Welcome back, ' . $row['username'] . '!';

            if ($row['role'] == 'admin') {
                header("Location: ../owner/dashboard.php");
            } else {
                header("Location: product_screen.php");
            }
            exit();
            }
        } else {
            $error = "Invalid username or password!";
        }
    } else {
        $error = "Invalid username or password!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Josefina Farmacia</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        /* --- GLOBAL STYLES --- */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            transition: all 0.3s ease; /* Para sa swabe na hover effects */
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(rgba(27, 20, 20, 0.28), rgba(27, 20, 20, 0.28)), url('images/background.jpg') center / cover fixed;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .overlay {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        /* --- LOGIN CARD (Pinalaki at Responsive) --- */
        .login-box {
            background: rgba(255, 255, 255, 0.68);
            border: 1px solid rgba(255, 255, 255, 0.72);
            padding: 40px 35px;
            border-radius: 20px;
            width: 100%;
            max-width: 450px; /* Sukat ng white box */
            text-align: center;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        /* Responsive adjustment para sa maliliit na phone */
        @media (max-width: 480px) {
            .login-box {
                padding: 30px 20px;
            }
        }

        .logo {
            width: 90px;
            margin-bottom: 15px;
            filter: drop-shadow(0px 4px 6px rgba(0,0,0,0.1));
        }

        h2 {
            margin-bottom: 5px;
            font-size: 24px;
            color: #333;
            font-weight: 700;
        }

        .tagline {
            font-size: 11px;
            font-weight: 700;
            color: #d32f2f; /* Red branding */
            margin-bottom: 30px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        /* --- INPUT FIELDS --- */
        .input-group {
            position: relative;
            margin-bottom: 18px;
            width: 100%;
            text-align: left;
        }

        .input-group i.fa-user, 
        .input-group i.fa-lock {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            z-index: 5;
        }

        .input-group input {
            width: 100%;
            height: 50px;
            padding: 10px 45px 10px 45px;
            border-radius: 12px;
            border: 1.5px solid #eee;
            outline: none;
            font-size: 15px;
            background: #fdfdfd;
        }

        .input-group input[type="password"] {
            padding-right: 52px;
        }

        .input-group input::-ms-reveal,
        .input-group input::-ms-clear,
        .input-group input::-webkit-credentials-auto-fill-button,
        .input-group input::-webkit-textfield-decoration-container {
            display: none !important;
            -webkit-appearance: none !important;
            appearance: none !important;
        }

        /* Hover & Focus Effects */
        .input-group input:hover {
            border-color: #ddd;
        }

        .input-group input:focus {
            border-color: #d32f2f;
            background: #fff;
            box-shadow: 0 0 10px rgba(211, 47, 47, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
            z-index: 10;
        }

        .password-toggle:hover {
            color: #333;
        }

        /* --- BUTTONS --- */
        .login-btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(to right, #e53935, #d32f2f);
            color: white;
            font-weight: bold;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
            box-shadow: 0 5px 15px rgba(211, 47, 47, 0.3);
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(211, 47, 47, 0.4);
            filter: brightness(1.1);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            margin: 15px 0;
            color: #666;
        }

        .options a {
            text-decoration: none;
            color: #d32f2f;
            font-weight: 600;
        }

        .options a:hover {
            text-decoration: underline;
        }

        .divider {
            margin: 25px 0;
            font-size: 12px;
            color: #bbb;
            display: flex;
            align-items: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .divider::before, .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #eee;
        }
        .divider::before { margin-right: 10px; }
        .divider::after { margin-left: 10px; }

        .register-btn {
            width: 100%;
            padding: 13px;
            background: #34a853;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: bold;
            cursor: pointer;
            text-align: center;
            display: block;
        }

        .register-btn:hover {
            background: #2d9247;
            transform: scale(1.02);
        }

        .google-btn {
            width: 100%;
            padding: 13px;
            background: white;
            border: 1.5px solid #eee;
            border-radius: 12px;
            margin-top: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-weight: 600;
            color: #555;
        }

        .google-btn:hover {
            background: #f9f9f9;
            border-color: #ddd;
        }

        .error {
            background: #ffebee;
            color: #c62828;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
            border: 1px solid #ffcdd2;
        }

        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
            border: 1px solid #c8e6c9;
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="overlay">
    <div class="login-box">
        <img src="<?php echo htmlspecialchars(capstoneUrl('user/images/logo.png'), ENT_QUOTES, 'UTF-8'); ?>" class="logo" alt="Josefina Farmacia Logo">
        <h2>Josefina Farmacia</h2>
        <p class="tagline">Quality and Affordable Branded & Generic Medicines</p>

        <?php if ($error != "") echo "<div class='error'><i class='fa fa-circle-exclamation'></i> $error</div>"; ?>
        <?php if ($success != "") echo "<div class='success'><i class='fa fa-check-circle'></i> $success</div>"; ?>

        <form method="POST" action="<?php echo htmlspecialchars(capstoneUrl('user/login.php'), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="input-group">
                <i class="fa fa-user"></i>
                <input type="text" name="username" placeholder="Username" required>
            </div>

            <div class="input-group">
                <i class="fa fa-lock"></i>
                <input type="password" id="password" name="password" placeholder="Password" required>
                <span class="password-toggle" onclick="togglePassword()">
                    <i class="fa fa-eye" id="eyeIcon"></i>
                </span>
            </div>

            <div class="options">
                <label style="cursor: pointer;"><input type="checkbox"> Remember Me</label>
                <a href="<?php echo htmlspecialchars(capstoneUrl('user/forgot_password.php'), ENT_QUOTES, 'UTF-8'); ?>">Forgot Password?</a>
            </div>

            <button name="login" class="login-btn">Login</button>

            <div class="divider">Or create an account</div>

            <a href="<?php echo htmlspecialchars(capstoneUrl('user/register.php'), ENT_QUOTES, 'UTF-8'); ?>" class="register-btn" style="text-decoration: none;">Register New Account</a>

            <a href="<?php echo htmlspecialchars(capstoneUrl('user/google_login.php'), ENT_QUOTES, 'UTF-8'); ?>" class="google-btn">
                <img src="https://www.svgrepo.com/show/355037/google.svg" width="18" alt="Google">
                Continue with Google
            </a>
        </form>
    </div>
</div>

<script>
    // --- TOGGLE PASSWORD VISIBILITY ---
    function togglePassword() {
        var passwordField = document.getElementById("password");
        var icon = document.getElementById("eyeIcon");

        if (passwordField.type === "password") {
            passwordField.type = "text";
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
        } else {
            passwordField.type = "password";
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
        }
    }
</script>

</body>
</html>