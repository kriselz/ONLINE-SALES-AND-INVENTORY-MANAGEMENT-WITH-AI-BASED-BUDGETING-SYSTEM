<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login_a.php');
    exit();
}
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings | Admin</title>
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-3">
<style>
*{box-sizing:border-box}body{margin:0;background:#f7f8fa;color:#20232a;font-family:'Segoe UI',Tahoma,sans-serif}.content{margin-left:244px;padding:32px;min-height:100vh}.head{margin-bottom:24px}.head h1{margin:0 0 6px;font-size:24px}.head p{margin:0;color:#7b8189;font-size:12px}.card{display:block;width:min(420px,100%);padding:22px;background:#fff;border:1px solid #ebedf0;border-radius:10px;color:#20232a;text-decoration:none;box-shadow:0 3px 12px rgba(25,30,38,.05);transition:transform .2s,box-shadow .2s}.card:hover{transform:translateY(-3px);box-shadow:0 8px 22px rgba(25,30,38,.1)}.icon{width:46px;height:46px;display:grid;place-items:center;margin-bottom:18px;border-radius:50%;background:#eaf8ef;color:#278658;font-size:22px}.card h2{margin:0 0 7px;font-size:16px}.card p{margin:0;color:#727984;font-size:11px;line-height:1.5}.open{display:inline-block;margin-top:18px;color:#278658;font-size:11px;font-weight:700}@media(max-width:720px){.content{margin-left:0;padding:22px}}
</style>
</head>
<body>
<?php include 'sidebar_owner.php'; ?>
<main class="content">
<header class="head"><h1>Settings</h1><p>Manage your administrator account settings.</p></header>
<a class="card" href="profile.php"><span class="icon">⚙</span><h2>Admin Profile</h2><p>Update your profile information, account details, and administrator settings.</p><span class="open">Open profile &rarr;</span></a>
</main>
</body>
</html>
