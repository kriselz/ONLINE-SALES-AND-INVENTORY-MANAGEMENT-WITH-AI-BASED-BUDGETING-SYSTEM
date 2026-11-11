<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

$user_id_sidebar = $_SESSION['user_id'] ?? 0;
$cart_count = 0;
$noti_unread = 0;
$user_name = 'User';
$user_email = 'user@example.com';
$user_initials = 'U';
$user_profile_pic = '';

if ($user_id_sidebar > 0 && isset($conn)) {
    $user_name = $_SESSION['username'] ?? $user_name;
    $user_email = $_SESSION['email'] ?? $user_email;

    // Account identity is required; keep it independent from optional profile fields.
    $stmt_user = $conn->prepare("SELECT username, full_name, email FROM users WHERE id = ?");
    if ($stmt_user) {
        $stmt_user->bind_param("i", $user_id_sidebar);
        $stmt_user->execute();
        $user_result = $stmt_user->get_result();
        if ($user_result && $user_result->num_rows > 0) {
            $user_data = $user_result->fetch_assoc();
            $user_email = $user_data['email'] ?? 'user@example.com';
            $display_name = trim((string)($user_data['full_name'] ?? ''));
            if ($display_name === '') {
                $display_name = trim((string)($user_data['username'] ?? ''));
            }
            $user_name = $display_name !== '' ? $display_name : 'User';
            $user_initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $user_name), 0, 2));
            if ($user_initials === '') {
                $user_initials = 'U';
            }
        }
        $stmt_user->close();
    }

    $stmt_profile = $conn->prepare("SELECT profile_pic FROM users WHERE id = ?");
    if ($stmt_profile) {
        $stmt_profile->bind_param("i", $user_id_sidebar);
        $stmt_profile->execute();
        $profile_result = $stmt_profile->get_result()->fetch_assoc();
        $user_profile_pic = $profile_result['profile_pic'] ?? '';
        $stmt_profile->close();
    }

    $stmt_cart = $conn->prepare("SELECT COALESCE(SUM(COALESCE(box, 0) + COALESCE(pieces, 0)), 0) as total_items FROM cart WHERE user_id = ?");
    if ($stmt_cart) {
        $stmt_cart->bind_param("i", $user_id_sidebar);
        $stmt_cart->execute();
        $cart_query = $stmt_cart->get_result();
        $cart_data = $cart_query->fetch_assoc();
        $cart_count = $cart_data['total_items'] ?? 0;
        $stmt_cart->close();
    }

    $stmt_notif = $conn->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND status = 'unread'");
    if ($stmt_notif) {
        $stmt_notif->bind_param("i", $user_id_sidebar);
        $stmt_notif->execute();
        $notif_query = $stmt_notif->get_result();
        $notif_data = $notif_query->fetch_assoc();
        $noti_unread = $notif_data['total'] ?? 0;
        $stmt_notif->close();
    }
}
?>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
<style>
    body:not(.dark-mode) {
        background: #f4f6f8 !important;
    }
    .sidebar { 
        width: 260px; 
        background: #4a1018; 
        color: white; 
        font-family: "Segoe UI", Arial, sans-serif;
        height: 100vh; 
        position: fixed; 
        top: 0;
        left: 0;
        bottom: 0;
        display: flex; 
        flex-direction: column; 
        padding: 0 20px 20px;
        z-index: 100; 
        box-sizing: border-box;
        transition: width 0.25s ease;
    }
    .sidebar-header {
        display: flex;
        align-items: center;
        gap: 5px;
        height: 72px;
        padding: 10px 6px;
        margin-bottom: 30px;
        box-sizing: border-box;
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    }
    .sidebar-header h2 {
        flex: 0 1 auto;
        min-width: max-content;
        white-space: nowrap;
    }
    .sidebar-header .sidebar-toggle {
        margin-left: auto;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .sidebar-toggle {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border: 0;
        border-radius: 8px;
        color: #bca0a4;
        background: transparent;
        cursor: pointer;
        position: relative;
        z-index: 1;
    }
    .sidebar-toggle i {
        font-size: 24px;
    }
    .sidebar-toggle:hover {
        transform: scale(1.08);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.18);
    }
    .logo-box {
        width: 45px;
        height: 45px;
        flex: 0 0 45px;
        border-radius: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #6F1724;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    .logo-box:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 12px rgba(0, 0, 0, 0.2);
    }
    .logo-box img {
        width: 100%;
        height: 100%;
        display: block;
        object-fit: cover;
    }
    .sidebar h2 { 
        font-size: 20px; 
        font-weight: 700; 
        color: white;
        margin: 0;
    }
    .sidebar-nav {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .sidebar a { 
        display: flex; 
        align-items: center;
        gap: 15px;
        padding: 14px 20px; 
        color: #bca0a4; 
        text-decoration: none; 
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.3s ease, color 0.3s ease; 
        font-size: 15px; 
        font-weight: 500;
        border-radius: 12px;
    }
    .nav-label {
        white-space: nowrap;
        overflow: hidden;
    }
    
    /* Icon Styling */
    .sidebar a i {
        font-size: 22px;
        width: 24px;
        text-align: center;
    }

    .sidebar a.active { 
        background: #8B1E2D;
        color: white; 
        box-shadow: 0 5px 12px rgba(0, 0, 0, 0.18);
    }
    .sidebar a.active i { color: white; }

    .sidebar a:hover:not(.active) { 
        background: rgba(255, 255, 255, 0.05); 
        color: white; 
        transform: translateX(4px);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12);
    }
    .badge {
        background: #8B1E2D;
        color: white;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 20px;
        margin-left: auto;
    }
    .sidebar-footer {
        margin-top: auto;
        background: rgba(255, 255, 255, 0.05);
        padding: 15px;
        border-radius: 15px;
        display: flex;
        align-items: center;
        gap: 12px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .sidebar-footer:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 12px rgba(0, 0, 0, 0.16);
    }
    .user-avatar {
        background: #8B1E2D;
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        overflow: hidden;
        flex: 0 0 40px;
    }
    .user-avatar img {
        width: 100%;
        height: 100%;
        display: block;
        object-fit: cover;
    }
    .user-info { display: flex; flex: 1; min-width: 0; flex-direction: column; }
    .user-name { max-width: 100%; overflow: hidden; color: white; font-size: 13px; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
    .user-email { max-width: 100%; overflow: hidden; color: #bca0a4; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
    .customer-topbar-account { display:flex; align-items:center; gap:10px; max-width:260px; min-width:0; margin-left:auto; padding:6px 10px; border:1px solid #edf0f4; border-radius:10px; background:#f8fafc; }
    .customer-topbar-account .user-avatar { width:36px; height:36px; flex-basis:36px; border-radius:50%; background:#f3dfe3; color:#7d2636; }
    .customer-topbar-account .user-info { min-width:0; }
    .customer-topbar-account .user-name { color:#344054; }
    .customer-topbar-account .user-email { color:#7b8491; }
    .settings-menu { display: block; }
    .settings-toggle { width: 100%; display: flex; align-items: center; gap: 15px; padding: 14px 20px; border: 0; border-radius: 12px; color: #bca0a4; background: transparent; font: inherit; text-align: left; cursor: pointer; }
    .settings-toggle { transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.3s ease, color 0.3s ease; }
    .settings-toggle:hover, .settings-toggle.active { color: white; background: #6F1724; }
    .settings-toggle:hover { transform: translateX(4px); box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12); }
    .settings-chevron { margin-left: auto; font-size: 19px; transition: transform .2s ease; }
    .settings-menu.open .settings-chevron { transform: rotate(180deg); }
    .settings-submenu { display: none; padding: 3px 0 5px 39px; }
    .settings-menu.open .settings-submenu { display: block; }
    .settings-submenu a { display: flex; align-items: center; gap: 8px; padding: 9px 12px; border-radius: 8px; font-size: 12px; color: #bca0a4; text-decoration: none; }
    .settings-submenu a:hover { color: white; background: rgba(255,255,255,0.05); }
    .settings-submenu a.active { color: white; background: rgba(211, 47, 47, .75); }
    body.dark-mode { background: #25282d !important; color: #e8e5df !important; }
    body.dark-mode .content,
    body.dark-mode .main-content,
    body.dark-mode .settings-main,
    body.dark-mode .main-wrapper { background: #25282d !important; color: #e8e5df !important; }
    body.dark-mode .order-box,
    body.dark-mode .panel,
    body.dark-mode .card,
    body.dark-mode .notification-list,
    body.dark-mode .notif-card,
    body.dark-mode .settings-section,
    body.dark-mode .appearance-card { background: #30343a !important; color: #e8e5df !important; border-color: #484d55 !important; }
    body.dark-mode h1,
    body.dark-mode h2,
    body.dark-mode h3,
    body.dark-mode p,
    body.dark-mode label,
    body.dark-mode .order-link,
    body.dark-mode .notif-title,
    body.dark-mode .appearance-label { color: #e8e5df !important; }
    body.dark-mode input,
    body.dark-mode select,
    body.dark-mode textarea { background: #30343a !important; color: #e8e5df !important; border-color: #484d55 !important; }
    body.dark-mode .notif-message,
    body.dark-mode .notif-time,
    body.dark-mode .order-meta,
    body.dark-mode .section-subtitle { color: #aab2c0 !important; }

    body.sidebar-collapsed .sidebar {
        width: 84px;
        padding-left: 12px;
        padding-right: 12px;
    }
    body.sidebar-collapsed .sidebar-header {
        justify-content: center;
        gap: 0;
        padding-left: 0;
        padding-right: 0;
    }
    body.sidebar-collapsed .sidebar-header .logo-box {
        display: none;
    }
    body.sidebar-collapsed .sidebar-header h2,
    body.sidebar-collapsed .nav-label,
    body.sidebar-collapsed .user-info,
    body.sidebar-collapsed .settings-chevron {
        display: none;
    }
    body.sidebar-collapsed .sidebar-toggle {
        margin-left: 0;
    }
    body.sidebar-collapsed .sidebar a,
    body.sidebar-collapsed .settings-toggle {
        justify-content: center;
        gap: 0;
        padding-left: 10px;
        padding-right: 10px;
    }
    body.sidebar-collapsed .sidebar a i,
    body.sidebar-collapsed .settings-toggle i {
        margin: 0;
    }
    body.sidebar-collapsed .badge {
        position: absolute;
        top: 4px;
        right: 4px;
        margin: 0;
        padding: 1px 5px;
        font-size: 10px;
    }
    body.sidebar-collapsed .sidebar a,
    body.sidebar-collapsed .settings-toggle {
        position: relative;
    }
    body.sidebar-collapsed .settings-submenu {
        display: none !important;
    }
    body.sidebar-collapsed .sidebar-footer {
        justify-content: center;
        padding: 10px;
    }
    body.sidebar-collapsed .sidebar-footer > .material-icons-outlined {
        display: none;
    }
    body.sidebar-collapsed .content,
    body.sidebar-collapsed .main-content,
    body.sidebar-collapsed .main-wrapper {
        margin-left: 116px !important;
        width: calc(100% - 116px) !important;
    }
    .content,
    .main-content,
    .main-wrapper {
        margin-left: 292px !important;
        width: calc(100% - 292px) !important;
        margin-top: 72px !important;
        padding: 36px 42px 32px !important;
    }
    .orders-page-heading,
    .orders-toolbar,
    .date-group-label,
    .order-box,
    .order-card-link,
    .empty-state-card,
    .cart-container,
    .page-header,
    .main-content > .panel,
    .top-controls,
    .notification-group-list,
    .notification-pager,
    .profile-wrapper,
    .settings-main > .settings-overview,
    .settings-main > .settings-section,
    .settings-main > .account-detail-heading,
    .settings-main > .settings-header,
    .settings-main > .custom-alert {
        width: 100% !important;
        max-width: none !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
    }
    .settings-main {
        padding: 0 !important;
    }
    .content {
        height: calc(100vh - 72px) !important;
    }
    .customer-topbar {
        position: fixed;
        top: 0;
        right: 0;
        left: 260px;
        height: 72px;
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 10px 28px;
        background: #ffffff;
        border-bottom: 1px solid #e5e7eb;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        box-sizing: border-box;
        z-index: 90;
        transition: left 0.25s ease, box-shadow 0.25s ease;
    }
    .customer-topbar:hover {
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
    }
    .customer-topbar-logo {
            width: 58px;
            height: 58px;
        object-fit: contain;
            flex: 0 0 58px;
            padding: 5px;
            background: #fff;
            border: 1px solid #e8e5d9;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(74, 16, 24, 0.08);
            transition: transform 0.25s ease;
        }
    .customer-topbar-logo:hover {
        transform: scale(1.06);
    }
    .customer-topbar-brand {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-width: 0;
        line-height: 1.15;
        transition: transform 0.25s ease;
    }
    .customer-topbar-brand:hover {
        transform: translateX(2px);
    }
    .customer-topbar-title {
        color: #4a1018;
        font-size: 18px;
        font-weight: 700;
        white-space: nowrap;
    }
    .customer-topbar-tagline {
        margin-top: 3px;
        color: #6b7280;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.04em;
        white-space: nowrap;
    }
    body.sidebar-collapsed .customer-topbar {
        left: 84px;
    }
    @media (max-width: 700px) {
        .customer-topbar {
            left: 0;
            padding: 10px 16px;
        }
        body.sidebar-collapsed .customer-topbar {
            left: 0;
        }
        .customer-topbar-title {
            font-size: 16px;
        }
        .customer-topbar-tagline {
            font-size: 8px;
        }
        .customer-topbar-account { max-width:42vw; padding:5px 7px; gap:7px; }
        .customer-topbar-account .user-info { display:flex; }
        .customer-topbar-account { flex:0 0 auto; }
        .customer-topbar-brand { flex:1 1 0; }
        .customer-topbar-title { overflow:hidden; text-overflow:ellipsis; }
        body.sidebar-collapsed .content,
        body.sidebar-collapsed .main-content,
        body.sidebar-collapsed .main-wrapper {
            margin-left: 0 !important;
            width: 100% !important;
        }
        .content,
        .main-content,
        .main-wrapper {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 24px 18px 20px !important;
        }
        .settings-main {
            padding: 0 !important;
        }
        .content {
            height: auto !important;
        }
    }
    @media (max-width: 900px) {
        .sidebar {
            width: 84px;
            padding: 0 12px 20px;
        }
        .sidebar-header {
            justify-content: center;
            gap: 0;
            padding-left: 0;
            padding-right: 0;
        }
        .sidebar-header .logo-box,
        body.sidebar-collapsed .sidebar-header .logo-box {
            display: none;
        }
        .sidebar-header h2,
        .nav-label,
        .user-info,
        .settings-chevron {
            display: none;
        }
        .sidebar-header .sidebar-toggle {
            display: inline-flex;
            margin: 0;
        }
        .sidebar a,
        .settings-toggle {
            justify-content: center;
            gap: 0;
            padding-left: 10px;
            padding-right: 10px;
        }
        .sidebar a i,
        .settings-toggle i {
            margin: 0;
        }
        .sidebar a,
        .settings-toggle {
            position: relative;
        }
        .badge {
            position: absolute;
            top: 4px;
            right: 4px;
            margin: 0;
            padding: 1px 5px;
            font-size: 10px;
        }
        .settings-submenu {
            display: none !important;
        }
        .sidebar-footer {
            justify-content: center;
            padding: 10px;
        }
        .sidebar-footer > .material-icons-outlined {
            display: none;
        }
        body.sidebar-collapsed .content,
        body.sidebar-collapsed .main-content,
        body.sidebar-collapsed .main-wrapper,
        .content,
        .main-content,
        .main-wrapper {
            box-sizing: border-box !important;
            margin-left: 116px !important;
            width: calc(100% - 116px) !important;
            padding: 24px 18px 20px !important;
        }
        .customer-topbar,
        body.sidebar-collapsed .customer-topbar {
            left: 84px;
            padding: 10px 16px;
        }
        .customer-topbar-account .user-info { display:flex; }
    }
    @media (max-width: 480px) {
        .customer-topbar-tagline {
            display: none;
        }
        .customer-topbar-account { max-width:40vw; padding:4px 6px; }
        .customer-topbar-account { max-width:120px; flex:0 0 auto; }
        .customer-topbar-account .user-avatar { width:32px; height:32px; flex-basis:32px; }
        .customer-topbar-account .user-email { display:none; }
        .customer-topbar-account .user-name { font-size:11px; }
    }
</style>

<div class="sidebar">
    <div class="sidebar-header">
        <div class="logo-box">
            <i class="material-icons-outlined" style="color: white; font-size: 24px;">shopping_bag</i>
        </div>
        <h2>Customer</h2>
        <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Hide sidebar text" aria-expanded="true">
            <i class="material-icons-outlined">menu</i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
        
        <a href="product_screen.php" class="<?php echo ($current_page == 'product_screen.php' || $current_page == 'view_product.php') ? 'active' : ''; ?>">
            <i class="material-icons-outlined">home</i><span class="nav-label">Home</span>
        </a>
        
        <a href="support.php" class="<?php echo ($current_page == 'support.php') ? 'active' : ''; ?>">
            <i class="material-icons-outlined">medication</i><span class="nav-label">Prescriptions</span>
        </a>

        <a href="cart_screen.php" class="<?php echo ($current_page == 'cart_screen.php') ? 'active' : ''; ?>">
            <i class="material-icons-outlined">shopping_cart</i><span class="nav-label">Cart</span>
            <span id="cartCountBadge" class="badge" style="<?php echo $cart_count > 0 ? '' : 'display:none;'; ?>"><?php echo (int)$cart_count; ?></span>
        </a>
        
        <a href="my_orders.php" class="<?php echo ($current_page == 'my_orders.php') ? 'active' : ''; ?>">
            <i class="material-icons-outlined">inventory_2</i><span class="nav-label">Orders</span>
        </a>

        <a href="notifications.php" class="<?php echo ($current_page == 'notifications.php') ? 'active' : ''; ?>">
            <i class="material-icons-outlined">notifications</i><span class="nav-label">Notifications</span>
            <?php if ($noti_unread > 0): ?> 
                <span class="badge"><?php echo $noti_unread; ?></span> 
            <?php endif; ?>
        </a>

        <a href="profile.php" class="<?php echo ($current_page == 'profile.php') ? 'active' : ''; ?>">
            <i class="material-icons-outlined">person_outline</i><span class="nav-label">Profile</span>
        </a>

        <a href="settings.php" class="<?= $current_page === 'settings.php' ? 'active' : ''; ?>">
            <i class="material-icons-outlined">settings</i><span class="nav-label">Settings</span>
        </a>

        <a href="logout.php">
            <i class="material-icons-outlined">logout</i><span class="nav-label">Logout</span>
        </a>
    </nav>

</div>
<div class="customer-topbar">
    <img class="customer-topbar-logo" src="images/logo.png" alt="Josefina Farmacia logo">
    <div class="customer-topbar-brand">
        <span class="customer-topbar-title">Josefina Farmacia</span>
        <span class="customer-topbar-tagline">Quality and Affordable Branded &amp; Generic Medicines</span>
    </div>
    <div class="customer-topbar-account" aria-label="Signed-in account">
        <div class="user-avatar">
            <?php if (!empty($user_profile_pic)): ?>
                <img src="uploads/<?= htmlspecialchars($user_profile_pic, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>" onerror="this.onerror=null; this.parentElement.textContent='<?= htmlspecialchars($user_initials, ENT_QUOTES, 'UTF-8') ?>';">
            <?php else: ?>
                <?= htmlspecialchars($user_initials) ?>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
            <span class="user-email"><?php echo htmlspecialchars($user_email); ?></span>
        </div>
    </div>
</div>
<link rel="stylesheet" href="appearance.css">
<script src="appearance.js"></script>
<script>
    (function () {
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.getElementById('sidebar-toggle');
        if (!sidebar || !toggle) return;

        const setCollapsed = (collapsed) => {
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', collapsed ? 'Show sidebar text' : 'Hide sidebar text');
            localStorage.setItem('sidebarCollapsed', collapsed ? 'on' : 'off');
        };

        setCollapsed(localStorage.getItem('sidebarCollapsed') === 'on');
        toggle.addEventListener('click', () => {
            setCollapsed(!document.body.classList.contains('sidebar-collapsed'));
        });
    })();
</script>
<script src="back-navigation.js"></script>