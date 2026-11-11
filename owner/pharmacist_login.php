<?php
session_start();
include 'config.php';
$passwordChangeSchema = $conn->query(
    'ALTER TABLE users ADD COLUMN IF NOT EXISTS password_change_required TINYINT(1) NOT NULL DEFAULT 0'
);
$error = '';
if (!$passwordChangeSchema) {
    error_log('Pharmacist login blocked because the one-time temporary password schema is unavailable: ' . $conn->error);
    $error = 'Login is temporarily unavailable. Please contact an administrator.';
}

function verifyPharmacistPassword($password, $hash) {
    return password_verify($password, $hash) || (strlen($hash) === 32 && md5($password) === $hash);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $passwordChangeSchema) {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare("SELECT u.id, u.username, u.email, u.password, u.password_change_required, s.active FROM users u JOIN pharmacy_staff s ON s.user_id = u.id WHERE (u.username = ? OR u.email = ?) AND u.role = 'pharmacist' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ss', $login, $login);
        $stmt->execute();
        $result = $stmt->get_result();
        $staff = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($staff
            && verifyPharmacistPassword($password, $staff['password'])
            && (int)$staff['active'] === 1) {
            if ((int)$staff['password_change_required'] !== 1 && strlen($staff['password']) === 32) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upgrade = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                if ($upgrade) {
                    $upgrade->bind_param('si', $newHash, $staff['id']);
                    $upgrade->execute();
                    $upgrade->close();
                }
            }
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$staff['id'];
            $_SESSION['username'] = $staff['username'];
            $_SESSION['email'] = $staff['email'];
            $_SESSION['role'] = 'pharmacist';
            $_SESSION['pharmacist_id'] = (int)$staff['id'];
            $_SESSION['password_change_required'] = (int)$staff['password_change_required'] === 1;
            writeAuditLog($conn, 'login', 'user', (int)$staff['id'], ['method' => 'password']);
            header('Location: ' . ($_SESSION['password_change_required'] ? 'profile.php' : 'products.php'));
            exit();
        }
    }
    $error = 'Invalid username/email, password, or disabled staff account.';
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pharmacist Login | Josefina Farmacia</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>*{box-sizing:border-box;margin:0;padding:0;transition:all .25s ease}body{min-height:100vh;display:flex;justify-content:center;align-items:center;padding:20px;background:#f3f4f6;color:#30343a;font-family:'Segoe UI',Tahoma,sans-serif}.overlay{width:100%;display:flex;justify-content:center;align-items:center;padding:16px}.login-box{width:100%;max-width:450px;padding:40px 35px;border-radius:18px;background:#fff;text-align:center;box-shadow:0 15px 35px rgba(0,0,0,.1)}.logo{width:90px;height:90px;object-fit:contain;margin-bottom:15px}h1{margin:0 0 5px;font-size:24px}.tagline{margin-bottom:28px;color:#b52238;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase}.field{position:relative;margin-bottom:16px}.field>i{position:absolute;left:15px;top:50%;transform:translateY(-50%);color:#9299a0}.field input{width:100%;height:50px;padding:10px 46px;border:1px solid #e2e5e8;border-radius:10px;background:#fdfdfd;font-size:15px}.field input:focus{outline:none;border-color:#c83448;box-shadow:0 0 0 3px #c8344818}.toggle{position:absolute;right:15px;top:50%;transform:translateY(-50%);color:#9299a0;cursor:pointer}.button{width:100%;height:50px;margin-top:8px;border:0;border-radius:10px;background:linear-gradient(90deg,#e53935,#c62836);color:#fff;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 5px 15px #d32f2f40}.button:hover{transform:translateY(-1px)}.error{margin-bottom:16px;padding:11px 12px;border:1px solid #ffcdd2;border-radius:8px;background:#ffebee;color:#b42335;text-align:left;font-size:13px}.owner-link{display:inline-block;margin-top:18px;color:#8b1e2d;font-size:13px;text-decoration:none}.owner-link:hover{text-decoration:underline}@media(max-width:480px){.login-box{padding:32px 22px}}</style></head>
<body><div class="overlay"><main class="login-box"><img src="images/logo.png" class="logo" alt="Josefina Farmacia logo"><h1>Pharmacist Login</h1><p class="tagline">Josefina Farmacia Staff Portal</p>
<?php if ($error !== ''): ?><div class="error"><i class="fa fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post"><div class="field"><i class="fa fa-user"></i><input name="login" placeholder="Username or email" autocomplete="username" required></div><div class="field"><i class="fa fa-lock"></i><input id="password" name="password" type="password" placeholder="Password" autocomplete="current-password" required><span class="toggle" onclick="togglePassword()" aria-label="Show or hide password"><i id="eye" class="fa fa-eye"></i></span></div><button class="button" type="submit">Sign in</button></form><a class="owner-link" href="login_a.php">Owner portal</a></main></div>
<script>function togglePassword(){const input=document.getElementById('password');const icon=document.getElementById('eye');const reveal=input.type==='password';input.type=reveal?'text':'password';icon.classList.toggle('fa-eye',!reveal);icon.classList.toggle('fa-eye-slash',reveal)}</script></body></html>