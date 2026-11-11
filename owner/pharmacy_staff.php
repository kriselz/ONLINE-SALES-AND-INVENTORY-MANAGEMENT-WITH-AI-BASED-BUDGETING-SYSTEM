<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login_a.php');
    exit();
}

$conn->query("ALTER TABLE users MODIFY role ENUM('user', 'admin', 'owner', 'rider', 'pharmacist') NOT NULL DEFAULT 'user'");
$conn->query("CREATE TABLE IF NOT EXISTS pharmacy_staff (user_id INT NOT NULL PRIMARY KEY, active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$csrfToken = $_SESSION['pharmacy_staff_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['pharmacy_staff_csrf'] = $csrfToken;
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $error = 'Session expired. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'create_staff') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $countResult = $conn->query("SELECT COUNT(*) AS total FROM pharmacy_staff s JOIN users u ON u.id = s.user_id WHERE u.role = 'pharmacist'");
        $staffCount = $countResult ? (int)$countResult->fetch_assoc()['total'] : 3;

        if ($staffCount >= 3) {
            $error = 'The maximum of 3 pharmacist accounts has been reached.';
        } elseif ($fullName === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $error = 'Enter a name, valid email, username (3-50 characters), and password of at least 8 characters.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $conn->begin_transaction();
            $create = $conn->prepare("INSERT INTO users (username, email, password, role, full_name) VALUES (?, ?, ?, 'pharmacist', ?)");
            if ($create) {
                $create->bind_param('ssss', $username, $email, $passwordHash, $fullName);
                if ($create->execute()) {
                    $staffId = (int)$create->insert_id;
                    $create->close();
                    $membership = $conn->prepare('INSERT INTO pharmacy_staff (user_id, active, created_by) VALUES (?, 1, ?)');
                    if ($membership) {
                        $ownerId = (int)$_SESSION['user_id'];
                        $membership->bind_param('ii', $staffId, $ownerId);
                        if ($membership->execute()) {
                            $membership->close();
                            $conn->commit();
                            $message = 'Pharmacist account created successfully.';
                        } else {
                            $membership->close();
                            $conn->rollback();
                            $error = 'Unable to enable pharmacist access.';
                        }
                    } else {
                        $conn->rollback();
                        $error = 'Unable to prepare pharmacist access.';
                    }
                } else {
                    $create->close();
                    $conn->rollback();
                    $error = $conn->errno === 1062 ? 'Username or email is already in use.' : 'Unable to create pharmacist account.';
                }
            } else {
                $conn->rollback();
                $error = 'Unable to prepare pharmacist account.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'toggle_staff') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $update = $conn->prepare('UPDATE pharmacy_staff SET active = IF(active = 1, 0, 1) WHERE user_id = ?');
        if ($update) {
            $update->bind_param('i', $staffId);
            $update->execute();
            $message = $update->affected_rows ? 'Pharmacist access updated.' : 'No pharmacist account was changed.';
            $update->close();
        }
    }
}

$staffList = $conn->query("SELECT s.user_id, s.active, s.created_at, u.full_name, u.username, u.email FROM pharmacy_staff s JOIN users u ON u.id = s.user_id WHERE u.role = 'pharmacist' ORDER BY s.created_at ASC");
$staffCountResult = $conn->query("SELECT COUNT(*) AS total FROM pharmacy_staff s JOIN users u ON u.id = s.user_id WHERE u.role = 'pharmacist'");
$staffCount = $staffCountResult ? (int)$staffCountResult->fetch_assoc()['total'] : 0;
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pharmacist Accounts</title><link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
<style>*{box-sizing:border-box}body{margin:0;background:#f7f8fa;color:#252a30;font-family:'Segoe UI',Tahoma,sans-serif}.content{margin-left:244px;padding:28px;min-height:100vh}.wrap{max-width:1050px;margin:auto}.head{margin-bottom:18px}.head h1{margin:0 0 5px;font-size:24px}.head p{margin:0;color:#77818a;font-size:12px}.panel{background:#fff;border:1px solid #e8ebee;border-radius:8px;padding:18px;margin-bottom:15px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.grid label{display:grid;gap:5px;color:#626b73;font-size:11px;font-weight:700}.grid input{height:36px;padding:0 9px;border:1px solid #dfe4e8;border-radius:6px;font-size:13px}.button{height:35px;margin-top:12px;border:0;border-radius:6px;background:#a51f35;color:#fff;padding:0 13px;font-size:12px;font-weight:700;cursor:pointer}.button.secondary{margin:0;background:#fff;color:#565f67;border:1px solid #dfe4e8}.notice{padding:10px 12px;margin-bottom:12px;border-radius:6px;font-size:12px}.success{background:#e8f6ed;color:#267949}.error{background:#fff0f0;color:#a52532}table{width:100%;border-collapse:collapse}th,td{padding:10px 8px;border-bottom:1px solid #edf0f2;text-align:left;font-size:12px}th{color:#6c757d;font-size:10px;text-transform:uppercase}.enabled{color:#278457;font-weight:700}.disabled{color:#b53b4d;font-weight:700}.quota{margin-left:8px;color:#69747c;font-size:12px}@media(max-width:720px){.content{margin:0;padding:18px}.grid{grid-template-columns:1fr}.panel{overflow:auto}table{min-width:620px}}</style></head><body>
<?php include 'appbar_owner.php'; include 'sidebar_owner.php'; ?>
<main class="content"><div class="wrap"><header class="head"><h1>Pharmacist Accounts</h1><p>Create and manage up to three separate pharmacist logins.</p></header>
<?php if ($message !== ''): ?><div class="notice success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($staffCount < 3): ?><section class="panel"><h2>Create Pharmacist Account</h2><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="create_staff"><div class="grid"><label>Full name<input name="full_name" maxlength="255" required></label><label>Username<input name="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]+" required autocomplete="off"></label><label>Email<input name="email" type="email" maxlength="100" required></label><label>Password<input name="password" type="password" minlength="8" required autocomplete="new-password"></label></div><button class="button" type="submit">Create account</button></form></section><?php endif; ?>
<section class="panel"><h2>Staff Access <span class="quota"><?php echo $staffCount; ?> / 3</span></h2><div style="overflow:auto"><table><thead><tr><th>Pharmacist</th><th>Username</th><th>Email</th><th>Access</th><th>Created</th><th></th></tr></thead><tbody>
<?php if ($staffList && $staffList->num_rows): while ($staff = $staffList->fetch_assoc()): ?><tr><td><?php echo htmlspecialchars($staff['full_name'] ?: $staff['username']); ?></td><td><?php echo htmlspecialchars($staff['username']); ?></td><td><?php echo htmlspecialchars($staff['email']); ?></td><td class="<?php echo $staff['active'] ? 'enabled' : 'disabled'; ?>"><?php echo $staff['active'] ? 'Enabled' : 'Disabled'; ?></td><td><?php echo htmlspecialchars(date('M d, Y', strtotime($staff['created_at']))); ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="toggle_staff"><input type="hidden" name="staff_id" value="<?php echo (int)$staff['user_id']; ?>"><button class="button secondary" type="submit"><?php echo $staff['active'] ? 'Disable' : 'Enable'; ?></button></form></td></tr><?php endwhile; else: ?><tr><td colspan="6">No pharmacist accounts yet.</td></tr><?php endif; ?>
</tbody></table></div></section></div></main></body></html>