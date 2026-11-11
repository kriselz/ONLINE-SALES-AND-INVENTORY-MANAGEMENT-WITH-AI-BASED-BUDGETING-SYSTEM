<?php
session_start();
include 'config.php';
$passwordChangeSchema = $conn->query(
    'ALTER TABLE users ADD COLUMN IF NOT EXISTS password_change_required TINYINT(1) NOT NULL DEFAULT 0'
);
$error = '';
$selectedRole = (string)($_POST['role'] ?? '');
if (!in_array($selectedRole, ['admin', 'pharmacist'], true)) {
    $selectedRole = '';
}

function verify_user_password($password, $hash) {
    if (password_verify($password, $hash)) {
        return true;
    }

    if (strlen($hash) === 32 && md5($password) === $hash) {
        return true;
    }

    return false;
}

if (!$passwordChangeSchema) {
    error_log('Login blocked because the one-time temporary password schema is unavailable: ' . $conn->error);
    $error = 'Login is temporarily unavailable. Please contact an administrator.';
} elseif (isset($_POST['login']) && !in_array($selectedRole, ['admin', 'pharmacist'], true)) {
    $error = 'Please select a user role.';
} elseif (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if ($selectedRole === 'pharmacist') {
        $stmt = $conn->prepare("SELECT u.*, s.active AS staff_active FROM users u JOIN pharmacy_staff s ON s.user_id = u.id WHERE (u.username = ? OR u.email = ?) AND u.role = 'pharmacist' LIMIT 1");
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND role='admin' LIMIT 1");
    }
    if (!$stmt) {
        die("SQL Error: " . $conn->error);
    }

    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();

        $isActivePharmacist = $selectedRole !== 'pharmacist' || (int)($row['staff_active'] ?? 0) === 1;
        $passwordChangeRequired = $selectedRole === 'pharmacist'
            && (int)($row['password_change_required'] ?? 0) === 1;
        if ($isActivePharmacist && verify_user_password($password, $row['password'])) {
            if (!$passwordChangeRequired && strlen($row['password']) === 32) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                if ($update) {
                    $update->bind_param("si", $newHash, $row['id']);
                    $update->execute();
                    $update->close();
                }
            }

            if ($selectedRole === 'pharmacist') {
                $_SESSION = [];
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['email'] = $row['email'] ?? '';
            $_SESSION['role'] = $selectedRole;
            $_SESSION['password_change_required'] = $passwordChangeRequired;
            if ($selectedRole === 'pharmacist') {
                $_SESSION['pharmacist_id'] = (int)$row['id'];
                $_SESSION['success_message'] = 'Pharmacist login successful. Welcome back!';
            } else {
                $_SESSION['success_message'] = 'Admin login successful. Welcome back!';
            }
            writeAuditLog($conn, 'login', 'user', (int)$row['id'], ['method' => 'password']);

            header($_SESSION['password_change_required']
                ? 'Location: ' . capstoneUrl('owner/profile.php')
                : ($selectedRole === 'pharmacist'
                    ? 'Location: ' . capstoneUrl('owner/products.php')
                    : 'Location: ' . capstoneUrl('owner/dashboard.php')));
            exit();
        }
    }

    $error = $selectedRole === 'pharmacist'
        ? 'Invalid username or password, or pharmacist access is disabled.'
        : 'Invalid username or password.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Josefina Farmacia</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            transition: all 0.3s ease;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(rgba(27, 20, 20, 0.28), rgba(27, 20, 20, 0.28)), url("<?php echo htmlspecialchars(capstoneUrl('owner/images/bg_admin.jpg'), ENT_QUOTES, 'UTF-8'); ?>") center / cover fixed;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .overlay {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-box {
            background: rgba(255, 255, 255, 0.68);
            border: 1px solid rgba(255, 255, 255, 0.72);
            padding: 40px 35px;
            border-radius: 20px;
            width: 100%;
            max-width: 450px;
            text-align: center;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .logo {
            width: 90px;
            height: 90px;
            object-fit: contain;
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
            color: #d32f2f;
            margin-bottom: 30px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .input-group {
            position: relative;
            margin-bottom: 18px;
            width: 100%;
            text-align: left;
        }

        .input-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            z-index: 5;
        }

        .input-group input,
        .input-group select {
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

        .input-group select {
            cursor: pointer;
        }

        .input-group input:hover,
        .input-group select:hover {
            border-color: #ddd;
        }

        .input-group input:focus,
        .input-group select:focus {
            border-color: #d32f2f;
            background: #fff;
            box-shadow: 0 0 10px rgba(211, 47, 47, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 45px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
            z-index: 10;
        }

        .password-toggle:hover {
            color: #333;
        }

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

        .error {
            background: #ffebee;
            color: #c62828;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
            border: 1px solid #ffcdd2;
            text-align: left;
        }
    </style>
</head>
<body>

<div class="overlay">
    <div class="login-box">

        <img src="<?php echo htmlspecialchars(capstoneUrl('owner/images/logo.png'), ENT_QUOTES, 'UTF-8'); ?>" class="logo" alt="Josefina Farmacia logo">

        <h2>Staff Login</h2>
        <p class="tagline">Josefina Farmacia</p>

        <?php if ($error): ?>
            <div class="error">
                <i class="fa fa-circle-exclamation"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars(capstoneUrl('owner/login_a.php'), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="input-group">
                <i class="fa fa-user-shield"></i>
                <select name="role" aria-label="User role" required>
                    <option value="" disabled <?php echo $selectedRole === '' ? 'selected' : ''; ?>>Select your role</option>
                    <option value="admin" <?php echo $selectedRole === 'admin' ? 'selected' : ''; ?>>Owner/Admin</option>
                    <option value="pharmacist" <?php echo $selectedRole === 'pharmacist' ? 'selected' : ''; ?>>Pharmacist</option>
                </select>
            </div>
            <div class="input-group">
                <i class="fa fa-user"></i>
                <input type="text" name="username" placeholder="Username or Email" required>
            </div>
            <div class="input-group">
                <i class="fa fa-lock"></i>
                <input type="password" id="password" name="password" placeholder="Password" required>
                <span class="password-toggle" onclick="togglePassword()">
                    <i class="fa fa-eye" id="eyeIcon"></i>
                </span>
            </div>
            <button name="login" class="login-btn">Login</button>
        </form>

    </div>
</div>

<script>
    function togglePassword() {
        var passwordField = document.getElementById('password');
        var icon = document.getElementById('eyeIcon');

        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordField.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
</script>