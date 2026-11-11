<?php
session_start();
include '../owner/config.php';
require_once __DIR__ . '/delivery_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login_a.php');
    exit();
}

$conn->query("ALTER TABLE users MODIFY role ENUM('user', 'admin', 'owner', 'rider', 'pharmacist') NOT NULL DEFAULT 'user'");
$conn->query("CREATE TABLE IF NOT EXISTS rider_profiles (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, phone VARCHAR(30) NULL, vehicle_type VARCHAR(50) NULL, plate_number VARCHAR(30) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
ensureRiderDeliverySchema($conn);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $vehicleType = trim($_POST['vehicle_type'] ?? '');
    $plateNumber = trim($_POST['plate_number'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($fullName === '' || $phone === '' || $vehicleType === '' || $plateNumber === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        $error = 'Complete all rider information and use a password with at least 6 characters.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, full_name) VALUES (?, ?, ?, 'rider', ?)");
        if ($stmt) {
            $stmt->bind_param('ssss', $username, $email, $passwordHash, $fullName);
            if ($stmt->execute()) {
                $riderId = $stmt->insert_id;
                $profile = $conn->prepare('INSERT INTO rider_profiles (user_id, phone, vehicle_type, plate_number) VALUES (?, ?, ?, ?)');
                if ($profile) {
                    $profile->bind_param('isss', $riderId, $phone, $vehicleType, $plateNumber);
                    $profile->execute();
                    $profile->close();
                }
                $message = 'Rider account created successfully.';
            } else {
                $error = $conn->errno === 1062 ? 'Username or email already exists.' : 'Unable to create rider account.';
            }
            $stmt->close();
        } else {
            $error = 'Unable to prepare rider account creation.';
        }
    }
}

$riders = $conn->query("SELECT u.id, u.full_name, u.username, u.email, rp.phone, rp.vehicle_type, rp.plate_number, rp.is_available, u.created_at FROM users u LEFT JOIN rider_profiles rp ON rp.user_id = u.id WHERE u.role = 'rider' ORDER BY u.id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rider Accounts</title>
<link rel="stylesheet" href="../owner/css/sidebar_owner.css?v=20260905-4">
<style>
*{box-sizing:border-box}body{margin:0;background:#f8f9fb;color:#000;font-family:Segoe UI,Tahoma,sans-serif}.content{margin-left:244px;padding:25px;min-height:100vh}.layout{max-width:900px;margin:auto}.panel{background:#fff;border:1px solid #edf0f2;border-radius:10px;padding:20px;margin-bottom:16px;box-shadow:0 2px 10px rgba(25,30,38,.04)}h1{margin:0 0 5px;font-size:24px}h2{font-size:15px;margin:0 0 14px}p{margin:0 0 18px;color:#7b8189;font-size:12px}.notice{padding:10px 12px;border-radius:7px;margin-bottom:14px;font-size:12px}.success{background:#e9f8ef;color:#278658}.error{background:#ffe8ee;color:#b91c1c}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}.form-grid label{display:flex;flex-direction:column;gap:5px;color:#68727c;font-size:10px;font-weight:700}.form-grid input{height:35px;border:1px solid #dfe4e9;border-radius:6px;padding:0 9px;font-size:12px}.form-grid label:first-child{grid-column:1/-1}.button{height:35px;margin-top:13px;border:0;border-radius:6px;background:#ed1b3b;color:#fff;padding:0 14px;font-size:11px;font-weight:700;cursor:pointer}table{width:100%;border-collapse:collapse}th,td{padding:10px 8px;border-bottom:1px solid #f0f1f3;text-align:left;font-size:11px}th{color:#68707a;font-size:10px;text-transform:uppercase}@media(max-width:720px){.content{margin-left:0;padding:18px}.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include '../owner/sidebar_owner.php'; ?>
<main class="content"><div class="layout">
    <section class="panel"><h1>Rider Accounts</h1><p>Create login accounts for delivery riders.</p>
        <?php if ($message): ?><div class="notice success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="post"><div class="form-grid">
            <label>Full name<input name="full_name" required></label>
            <label>Contact number<input name="phone" required></label>
            <label>Vehicle type<input name="vehicle_type" placeholder="Motorcycle, bicycle, van" required></label>
            <label>Plate number<input name="plate_number" required></label>
            <label>Username<input name="username" required autocomplete="off"></label>
            <label>Email<input name="email" type="email" required></label>
            <label>Password<input name="password" type="password" minlength="6" required autocomplete="new-password"></label>
        </div><button class="button" type="submit">Create Rider Account</button></form>
    </section>
    <section class="panel"><h2>Existing Riders</h2><table><thead><tr><th>Name</th><th>Contact</th><th>Vehicle</th><th>Plate</th><th>Username</th><th>Availability</th></tr></thead><tbody>
        <?php if ($riders && $riders->num_rows): while ($rider = $riders->fetch_assoc()): ?><tr><td><?php echo htmlspecialchars($rider['full_name']); ?></td><td><?php echo htmlspecialchars($rider['phone'] ?? ''); ?></td><td><?php echo htmlspecialchars($rider['vehicle_type'] ?? ''); ?></td><td><?php echo htmlspecialchars($rider['plate_number'] ?? ''); ?></td><td><?php echo htmlspecialchars($rider['username']); ?></td><td><?php echo !empty($rider['is_available']) ? 'Available' : 'Offline'; ?></td></tr><?php endwhile; else: ?><tr><td colspan="6">No rider accounts yet.</td></tr><?php endif; ?>
    </tbody></table></section>
</div></main>
</body>
</html>
