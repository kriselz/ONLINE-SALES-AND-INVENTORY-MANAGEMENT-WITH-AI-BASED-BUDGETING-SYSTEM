<?php
session_start();
include '../owner/config.php';

$riderBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/rider/rider_login.php')), '/');
$riderLoginUrl = $riderBasePath . '/rider_login.php';
$riderDashboardUrl = $riderBasePath . '/rider_dashboard.php';
$temporaryPasswordUrl = $riderBasePath . '/rider_profile.php';

$conn->query("ALTER TABLE users MODIFY role ENUM('user', 'admin', 'owner', 'rider', 'pharmacist') NOT NULL DEFAULT 'user'");
$passwordChangeSchema = $conn->query(
    'ALTER TABLE users ADD COLUMN IF NOT EXISTS password_change_required TINYINT(1) NOT NULL DEFAULT 0'
);
$error = $passwordChangeSchema ? '' : 'Login is temporarily unavailable. Please contact an administrator.';
if (!$passwordChangeSchema) {
    error_log('Rider login blocked because the one-time temporary password schema is unavailable: ' . $conn->error);
}

function verify_rider_password($password, $hash) {
    return password_verify($password, $hash) || (strlen($hash) === 32 && md5($password) === $hash);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $passwordChangeSchema) {
    $login = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare("SELECT id, username, email, password, role, password_change_required FROM users WHERE (username = ? OR email = ?) AND role = 'rider' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ss', $login, $login);
        $stmt->execute();
        $result = $stmt->get_result();
        $rider = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($rider && verify_rider_password($password, $rider['password'])) {
            if ((int)$rider['password_change_required'] !== 1 && strlen($rider['password']) === 32) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upgrade = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                if ($upgrade) {
                    $upgrade->bind_param('si', $newHash, $rider['id']);
                    $upgrade->execute();
                    $upgrade->close();
                }
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$rider['id'];
            $_SESSION['username'] = $rider['username'];
            $_SESSION['role'] = 'rider';
            $_SESSION['password_change_required'] = (int)$rider['password_change_required'] === 1;
            header('Location: ' . ($_SESSION['password_change_required'] ? $temporaryPasswordUrl : $riderDashboardUrl));
            exit();
        }
    }
    $error = 'Invalid rider username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rider Login</title>
<style>
*{box-sizing:border-box}
:root{color-scheme:light;--forest:#174c4a;--forest-deep:#103d3b;--teal:#267873;--mint:#d9eee5;--coral:#e7684b;--gold:#f0c766;--ink:#202c2b;--muted:#73817e;--line:#dce6e2}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:32px;background-color:#edf3f0;background-image:linear-gradient(rgba(23,76,74,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(23,76,74,.035) 1px,transparent 1px);background-size:28px 28px;color:var(--ink);font-family:'Trebuchet MS',Tahoma,sans-serif}
.login-shell{width:min(1000px,100%);min-height:580px;display:grid;grid-template-columns:1.05fr .95fr;overflow:hidden;border:1px solid rgba(16,61,59,.08);border-radius:8px;background:#fff;box-shadow:0 28px 80px rgba(27,57,51,.14);animation:arrive .55s ease-out both}
.brand-panel{position:relative;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden;padding:38px 42px;background:var(--forest);color:#fff}
.brand-panel:before{position:absolute;inset:0;content:'';background:linear-gradient(135deg,rgba(255,255,255,.045),transparent 55%),repeating-linear-gradient(135deg,transparent 0 23px,rgba(255,255,255,.025) 23px 24px);pointer-events:none}
.brand-row,.brand-copy,.route-art,.brand-foot{position:relative;z-index:1}
.brand-row{display:flex;align-items:center;gap:12px}
.brand-mark{display:grid;width:42px;height:42px;place-items:center;border:1px solid rgba(255,255,255,.38);border-radius:8px;color:#f0c766;font-family:Georgia,serif;font-size:16px;font-weight:700}
.brand-name{font-size:14px;font-weight:700}.brand-subtitle{margin-top:4px;color:#b9d5ce;font-size:10px}
.brand-copy{margin:72px 0 34px;max-width:370px}
.eyebrow{margin:0 0 14px;color:#f0c766;font-size:10px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase}
.brand-copy h1{max-width:360px;margin:0;color:#fff;font-family:Georgia,'Times New Roman',serif;font-size:42px;font-weight:400;line-height:1.12}
.brand-copy p{max-width:310px;margin:16px 0 0;color:#c3d9d3;font-size:13px;line-height:1.7}
.route-art{height:112px;margin-top:12px;border:1px solid rgba(255,255,255,.14);border-radius:8px;background-color:rgba(10,49,47,.3);background-image:linear-gradient(30deg,transparent 49.5%,rgba(255,255,255,.08) 50%,transparent 50.5%),linear-gradient(145deg,transparent 49.5%,rgba(255,255,255,.07) 50%,transparent 50.5%);background-size:78px 72px,96px 88px}
.route-line{position:absolute;top:57px;left:13%;width:72%;height:2px;background:linear-gradient(90deg,var(--gold),#f5e6bd,var(--coral));transform:rotate(-9deg)}
.route-stop{position:absolute;top:46px;width:22px;height:22px;border:5px solid #fff;border-radius:50%;background:var(--teal);box-shadow:0 0 0 4px rgba(255,255,255,.12)}
.route-stop.start{left:12%;background:var(--gold)}.route-stop.middle{left:48%;top:39px;background:var(--teal)}.route-stop.end{right:12%;top:31px;background:var(--coral)}
.brand-foot{display:flex;align-items:center;gap:8px;color:#b9d5ce;font-size:10px}.live-dot{width:7px;height:7px;border-radius:50%;background:#96d6a0;box-shadow:0 0 0 4px rgba(150,214,160,.13)}
.form-panel{display:flex;flex-direction:column;justify-content:center;padding:52px clamp(28px,6vw,68px);background:#fff}
.form-kicker{margin:0 0 10px;color:var(--teal);font-size:10px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase}
.form-panel h2{margin:0;color:var(--ink);font-family:Georgia,'Times New Roman',serif;font-size:32px;font-weight:400}
.form-intro{margin:9px 0 28px;color:var(--muted);font-size:13px;line-height:1.6}
.error{display:flex;align-items:flex-start;gap:9px;margin-bottom:18px;padding:12px;border:1px solid #f2c7bc;border-radius:6px;background:#fff4f0;color:#a63c2d;font-size:12px;line-height:1.5}.error-mark{display:grid;width:18px;height:18px;flex:0 0 18px;place-items:center;border-radius:50%;background:#f9ded5;font-size:12px;font-weight:800}
label{display:block;margin:17px 0 7px;color:#445451;font-size:11px;font-weight:700}
input{width:100%;height:48px;border:1px solid var(--line);border-radius:6px;padding:0 13px;background:#fbfdfc;color:var(--ink);font:inherit;font-size:13px;transition:border-color .18s,box-shadow .18s,background .18s}
input::placeholder{color:#9ba9a5}
input:focus{outline:none;border-color:var(--teal);background:#fff;box-shadow:0 0 0 3px rgba(38,120,115,.13)}
button{width:100%;height:48px;margin-top:25px;border:0;border-radius:6px;background:var(--forest);color:#fff;font:inherit;font-size:12px;font-weight:800;cursor:pointer;transition:background .18s,transform .18s,box-shadow .18s}
button:hover{background:var(--teal);box-shadow:0 8px 18px rgba(23,76,74,.18);transform:translateY(-1px)}button:focus-visible{outline:3px solid var(--gold);outline-offset:3px}
.form-foot{margin:24px 0 0;padding-top:17px;border-top:1px solid #edf1ef;color:#83908d;font-size:10px;line-height:1.6}
@keyframes arrive{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:720px){body{padding:16px}.login-shell{max-width:440px;min-height:0;grid-template-columns:1fr}.brand-panel{min-height:330px;padding:24px}.brand-copy{margin:35px 0 22px}.brand-copy h1{font-size:34px}.brand-copy p{margin-top:10px}.route-art{height:70px;margin-top:16px}.route-line{top:36px}.route-stop{top:25px;width:20px;height:20px}.route-stop.middle{top:18px}.route-stop.end{top:10px}.brand-foot{display:none}.form-panel{padding:32px 24px 28px}.form-intro{margin-bottom:18px}}
@media(max-width:360px){body{padding:10px}.brand-panel{padding:20px}.form-panel{padding:28px 20px}.brand-copy h1{font-size:30px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
</style>
</head>
<body>
<main class="login-shell">
    <section class="brand-panel" aria-label="Josefina Farmacia rider workspace">
        <div class="brand-row">
            <div class="brand-mark" aria-hidden="true">JF</div>
            <div><div class="brand-name">Josefina Farmacia</div><div class="brand-subtitle">Rider workspace</div></div>
        </div>
        <div class="brand-copy">
            <p class="eyebrow">Rider operations</p>
            <h1>Good routes.<br>Careful delivery.</h1>
            <p>Your delivery workspace, ready when you are.</p>
            <div class="route-art" aria-hidden="true"><span class="route-line"></span><span class="route-stop start"></span><span class="route-stop middle"></span><span class="route-stop end"></span></div>
        </div>
        <div class="brand-foot"><span class="live-dot"></span> Rider access portal</div>
    </section>
    <section class="form-panel">
        <p class="form-kicker">Secure sign-in</p>
        <h2>Welcome back</h2>
        <p class="form-intro">Sign in to view your assigned deliveries.</p>
        <?php if ($error): ?><div class="error" role="alert"><span class="error-mark" aria-hidden="true">!</span><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars($riderLoginUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="username">Username or email</label>
            <input id="username" name="username" required autocomplete="username" placeholder="Enter your username or email">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password">
            <button type="submit">Sign in to rider workspace</button>
        </form>
        <p class="form-foot">Josefina Farmacia · Rider access only</p>
    </section>
</main>
</body>
</html>
