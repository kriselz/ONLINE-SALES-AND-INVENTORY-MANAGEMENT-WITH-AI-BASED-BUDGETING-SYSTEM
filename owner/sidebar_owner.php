<?php
$adminName = $_SESSION['username'] ?? 'Admin';
$adminEmail = $_SESSION['email'] ?? 'admin@example.com';
$adminProfilePic = '';
$adminInitials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$adminName), 0, 2)) ?: 'A';
$adminId = (int)($_SESSION['user_id'] ?? 0);
if ($adminId > 0 && isset($conn) && $conn instanceof mysqli) {
    $adminProfileStmt = $conn->prepare('SELECT username, full_name, email, profile_pic FROM users WHERE id = ? LIMIT 1');
    if ($adminProfileStmt) {
        $adminProfileStmt->bind_param('i', $adminId);
        if ($adminProfileStmt->execute()) {
            $adminProfileResult = $adminProfileStmt->get_result();
            $adminProfile = $adminProfileResult ? $adminProfileResult->fetch_assoc() : null;
            if ($adminProfile) {
                $adminName = trim((string)($adminProfile['full_name'] ?? ''));
                if ($adminName === '') {
                    $adminName = trim((string)($adminProfile['username'] ?? '')) ?: 'Admin';
                }
                $adminEmail = trim((string)($adminProfile['email'] ?? ''));
                $adminProfilePic = trim((string)($adminProfile['profile_pic'] ?? ''));
                $adminInitials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $adminName), 0, 2)) ?: 'A';
            }
        } else {
            error_log('Admin sidebar profile query failed: ' . $adminProfileStmt->error);
        }
        $adminProfileStmt->close();
    } else {
        error_log('Admin sidebar profile query preparation failed: ' . $conn->error);
    }
}
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);
$isPharmacist = ($_SESSION['role'] ?? '') === 'pharmacist';
$reportPages = ['inventory_alerts.php', 'analytics.php', 'reports_menu.php', 'staff_sales_report.php'];
$customerPages = ['user_orders.php', 'loyalty_management.php'];
$settingsPages = ['settings.php', 'settings_menu.php', 'profile.php'];
$reportsActive = in_array($currentPage, $reportPages, true);
$customersActive = in_array($currentPage, $customerPages, true);
$settingsOpen = in_array($currentPage, $settingsPages, true);
?>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
<style>
    .owner-topbar {
        position: fixed;
        top: 0;
        right: 0;
        left: 244px;
        height: 72px;
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 10px 28px;
        background: #ffffff;
        border-bottom: 1px solid #e5e7eb;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .06);
        box-sizing: border-box;
        z-index: 110;
        transition: left .25s ease;
    }
    .owner-topbar-logo { width: 56px; height: 56px; object-fit: contain; flex: 0 0 56px; }
    .owner-topbar-brand { display: flex; flex-direction: column; min-width: 0; line-height: 1.15; }
    .owner-topbar-title { color: #4a1018; font-size: 18px; font-weight: 700; white-space: nowrap; }
    .owner-topbar-tagline { margin-top: 3px; color: #6b7280; font-size: 10px; font-weight: 600; letter-spacing: .04em; white-space: nowrap; }
    .owner-topbar-account { display:flex; align-items:center; gap:10px; flex:0 1 260px; min-width:0; margin-left:auto; padding:6px 10px; border:1px solid #edf0f4; border-radius:10px; background:#f8fafc; }
    .owner-topbar-avatar { display:flex; align-items:center; justify-content:center; width:36px; height:36px; flex:0 0 36px; overflow:hidden; border-radius:50%; background:#f3dfe3; color:#7d2636; font-size:13px; font-weight:700; }
    .owner-topbar-avatar img { display:block; width:100%; height:100%; object-fit:cover; }
    .owner-topbar-user-info { display:flex; flex-direction:column; min-width:0; }
    .owner-topbar-user-name { overflow:hidden; color:#344054; font-size:12px; font-weight:600; text-overflow:ellipsis; white-space:nowrap; }
    .owner-topbar-user-email { overflow:hidden; margin-top:2px; color:#7b8491; font-size:10px; text-overflow:ellipsis; white-space:nowrap; }
    .owner-sidebar-toggle {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-left: auto;
        padding: 0;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: #bca0a4;
        cursor: pointer;
    }
    .owner-sidebar-toggle:hover { background: rgba(255, 255, 255, .08); color: #ffffff; }
    .owner-sidebar-toggle:focus-visible { outline: 2px solid #ffffff; outline-offset: 2px; }
    .owner-sidebar-toggle .material-icons-outlined { font-size: 23px; }
    .content,
    .admin-page,
    .main-content,
    body > .container { transition: margin-left .25s ease, width .25s ease; }
    .sidebar {
        width: 244px !important;
        background: #4a1018 !important;
        color: #ffffff !important;
        font-family: "Segoe UI", Arial, sans-serif !important;
        padding: 0 20px 20px !important;
        transition: width .25s ease, padding .25s ease;
    }
    .sidebar .sidebar-header {
        display: flex !important;
        align-items: center !important;
        gap: 15px !important;
        padding: 10px 10px 16px !important;
        margin: 0 0 30px !important;
        border-bottom: 1px solid rgba(255, 255, 255, .15) !important;
    }
    .sidebar .owner-admin-icon { color: #ffffff; font-size: 30px; }
    .sidebar .logo-box {
        width: 45px !important;
        height: 45px !important;
        min-width: 45px !important;
        min-height: 45px !important;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        overflow: hidden !important;
        background: #6F1724 !important;
    }
    .sidebar .logo-box img {
        width: 45px !important;
        height: 45px !important;
        max-width: 45px !important;
        max-height: 45px !important;
        display: block !important;
        object-fit: cover !important;
    }
    .sidebar .brand-name { color: #ffffff !important; font-size: 20px !important; line-height: 1.2 !important; font-weight: 700 !important; }
    .sidebar .sidebar-nav { gap: 5px !important; }
    .sidebar a, .sidebar .nav-menu-toggle { color: #bca0a4 !important; font-family: inherit !important; gap: 15px !important; font-size: 15px !important; }
    .sidebar a { padding: 14px 20px !important; border-radius: 12px !important; font-weight: 500 !important; }
    .sidebar .nav-menu-toggle { padding: 14px 20px !important; border-radius: 12px !important; }
    .sidebar a.active, .sidebar .nav-menu-toggle.expanded { background: #8B1E2D !important; color: #ffffff !important; box-shadow: none !important; }
    .sidebar .nav-icon { color: #bca0a4 !important; }
    .sidebar a.active .nav-icon, .sidebar a:hover .nav-icon { color: #ffffff !important; }

    @media (min-width: 1200px) {
        .sidebar {
            width: 260px !important;
            padding: 0 22px 22px !important;
        }
        .sidebar .sidebar-header {
            gap: 16px !important;
            padding: 12px 10px 11px !important;
            margin-bottom: 32px !important;
        }
        .sidebar .logo-box,
        .sidebar .logo-box img {
            width: 48px !important;
            height: 48px !important;
            max-width: 48px !important;
            max-height: 48px !important;
        }
        .sidebar .brand-name { font-size: 21px !important; }
        .sidebar a,
        .sidebar .nav-menu-toggle {
            gap: 16px !important;
            padding: 15px 18px !important;
            font-size: 16px !important;
        }
        .sidebar a.sub-link {
            padding: 8px 14px 8px 50px !important;
            font-size: 13px !important;
        }
        .sidebar .nav-icon { width: 20px !important; font-size: 18px !important; }
        .sidebar .nav-section-label { font-size: 12px !important; }

        .owner-topbar { left: 260px; }
        .content,
        .admin-page,
        .main-content,
        body > .container {
            margin-left: 320px !important;
            margin-right: 60px !important;
            width: calc(100% - 380px) !important;
            margin-top: 0 !important;
            padding: 104px 0 32px !important;
        }
    }

    @media (min-width: 1200px) {
        body.owner-sidebar-collapsed .sidebar { width: 84px !important; padding: 0 12px 20px !important; }
        body.owner-sidebar-collapsed .owner-topbar { left: 84px; }
        body.owner-sidebar-collapsed .sidebar .sidebar-header { height: 72px; min-height: 72px; flex: 0 0 72px; justify-content: center; gap: 0; margin: 0 0 32px !important; padding: 0 !important; }
        body.owner-sidebar-collapsed .sidebar .logo-box,
        body.owner-sidebar-collapsed .sidebar .brand-name { display: none !important; }
        body.owner-sidebar-collapsed .sidebar .sidebar-nav { gap: 5px !important; }
        body.owner-sidebar-collapsed .sidebar a,
        body.owner-sidebar-collapsed .sidebar .nav-menu-toggle { position: relative; justify-content: center; gap: 0; padding: 15px 10px !important; font-size: 0 !important; }
        body.owner-sidebar-collapsed .sidebar .nav-icon { width: 20px !important; font-size: 22px !important; }
        body.owner-sidebar-collapsed .sidebar .nav-chevron,
        body.owner-sidebar-collapsed .sidebar .nav-section-label,
        body.owner-sidebar-collapsed .sidebar .nav-divider,
        body.owner-sidebar-collapsed .sidebar .nav-submenu,
        body.owner-sidebar-collapsed .sidebar .sidebar-footer { display: none !important; }
        body.owner-sidebar-collapsed .sidebar .nav-badge { position: absolute; top: 2px; right: 2px; min-width: 16px; height: 16px; margin: 0; padding: 0; font-size: 9px; }
        body.owner-sidebar-collapsed .content,
        body.owner-sidebar-collapsed .admin-page,
        body.owner-sidebar-collapsed .main-content,
        body.owner-sidebar-collapsed > .container { margin-left: 144px !important; margin-right: 60px !important; width: calc(100% - 204px) !important; }
    }

    /* Shared owner layout rules for smaller screens. */
    @media (max-width: 1199px) and (min-width: 721px) {
        .owner-topbar { left:260px; }
        .content,
        .admin-page,
        .main-content,
        body > .container { padding: 104px 0 32px !important; }
        .content,
        .admin-page,
        .main-content,
        body > .container {
            margin-left: 276px !important;
            margin-right: 24px !important;
            width: calc(100% - 300px) !important;
            max-width: none !important;
            margin-top: 0 !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }
    }

    @media (max-width: 1199px) and (min-width: 721px) {
        body.owner-sidebar-collapsed .sidebar { width: 84px !important; padding: 0 12px 20px !important; }
        body.owner-sidebar-collapsed .owner-topbar { left: 84px; }
        body.owner-sidebar-collapsed .sidebar .sidebar-header { height: 72px; min-height: 72px; flex: 0 0 72px; justify-content: center; gap: 0; margin: 0 0 30px !important; padding: 0 !important; }
        body.owner-sidebar-collapsed .sidebar .logo-box,
        body.owner-sidebar-collapsed .sidebar .brand-name { display: none !important; }
        body.owner-sidebar-collapsed .sidebar a,
        body.owner-sidebar-collapsed .sidebar .nav-menu-toggle { position: relative; justify-content: center; gap: 0; padding: 14px 10px !important; font-size: 0 !important; }
        body.owner-sidebar-collapsed .sidebar .nav-icon { width: 20px !important; font-size: 22px !important; }
        body.owner-sidebar-collapsed .sidebar .nav-chevron,
        body.owner-sidebar-collapsed .sidebar .nav-section-label,
        body.owner-sidebar-collapsed .sidebar .nav-divider,
        body.owner-sidebar-collapsed .sidebar .nav-submenu,
        body.owner-sidebar-collapsed .sidebar .sidebar-footer { display: none !important; }
        body.owner-sidebar-collapsed .sidebar .nav-badge { position: absolute; top: 2px; right: 2px; min-width: 16px; height: 16px; margin: 0; padding: 0; font-size: 9px; }
        body.owner-sidebar-collapsed .content,
        body.owner-sidebar-collapsed .admin-page,
        body.owner-sidebar-collapsed .main-content,
        body.owner-sidebar-collapsed > .container { margin-left: 100px !important; margin-right: 24px !important; width: calc(100% - 124px) !important; }
    }

    @media (max-width: 720px) {
        .owner-topbar { left: 0; padding: 10px 16px; }
        .owner-topbar-title { overflow:hidden; font-size: 16px; text-overflow:ellipsis; }
        .owner-topbar-brand { flex:1 1 auto; }
        .owner-topbar-account { flex:0 1 38%; gap:7px; margin-left:0; padding:5px 7px; }
        .owner-topbar-user-email { display:none; }
        .owner-topbar-user-name { font-size:11px; }
        html,
        body {
            width: 100%;
            min-width: 0;
            overflow-x: hidden !important;
            overflow-y: auto !important;
        }

        .sidebar {
            position: sticky !important;
            top: 72px !important;
            left: 0 !important;
            width: 100% !important;
            height: auto !important;
            min-height: 0 !important;
            max-height: 82px !important;
            flex-direction: row !important;
            align-items: center !important;
            gap: 10px !important;
            padding: 10px 12px !important;
            margin-top: 72px !important;
            overflow: hidden !important;
        }

        .sidebar .sidebar-header {
            flex: 0 0 auto !important;
            width: auto !important;
            margin: 0 !important;
            padding: 0 10px 0 0 !important;
            border: 0 !important;
        }

        .sidebar .logo-box,
        .sidebar .logo-box img {
            width: 38px !important;
            height: 38px !important;
            max-width: 38px !important;
            max-height: 38px !important;
        }

        .sidebar .brand-name {
            display: none !important;
        }

        .sidebar .sidebar-nav {
            flex: 1 1 auto !important;
            min-width: 0 !important;
            width: auto !important;
            flex-direction: row !important;
            align-items: center !important;
            gap: 4px !important;
            overflow-x: auto !important;
            overflow-y: hidden !important;
            scrollbar-width: none;
        }

        .sidebar .sidebar-details {
            flex: 0 0 auto !important;
            width: auto !important;
        }

        .sidebar .sidebar-nav::-webkit-scrollbar {
            display: none;
        }

        .sidebar .nav-section-label,
        .sidebar .nav-divider,
        .sidebar .nav-submenu {
            display: none !important;
        }

        .sidebar a,
        .sidebar .nav-menu-toggle {
            flex: 0 0 auto !important;
            width: auto !important;
            min-width: max-content !important;
            padding: 10px 12px !important;
            border-radius: 8px !important;
            font-size: 13px !important;
            white-space: nowrap !important;
        }

        .sidebar .nav-icon {
            width: auto !important;
        }

        .sidebar .nav-label,
        .sidebar .nav-section-label {
            display: none !important;
        }

        .sidebar .sidebar-nav > a,
        .sidebar .sidebar-nav > .sidebar-details > summary,
        .sidebar .nav-menu-toggle {
            justify-content: center !important;
            gap: 0 !important;
            width: 44px !important;
            min-width: 44px !important;
            padding: 10px !important;
            font-size: 0 !important;
        }

        .sidebar .nav-icon {
            font-size: 21px !important;
        }

        .sidebar .nav-chevron {
            display: none !important;
        }

        .sidebar .nav-badge {
            display: none !important;
        }

        .content,
        .admin-page,
        .main-content,
        body > .container {
            margin: 0 24px !important;
            width: calc(100% - 48px) !important;
            max-width: none !important;
            min-width: 0 !important;
            min-height: calc(100vh - 82px) !important;
            height: auto !important;
            padding: 16px 0 !important;
            margin-top: 0 !important;
            overflow: visible !important;
        }

        body.owner-sidebar-collapsed .sidebar { max-height: 56px !important; height: 56px !important; }
        body.owner-sidebar-collapsed .sidebar .sidebar-header { width: 100% !important; justify-content: flex-end !important; padding: 0 !important; }
        body.owner-sidebar-collapsed .sidebar .logo-box,
        body.owner-sidebar-collapsed .sidebar .brand-name,
        body.owner-sidebar-collapsed .sidebar .sidebar-nav { display: none !important; }
        body.owner-sidebar-collapsed .content,
        body.owner-sidebar-collapsed .admin-page,
        body.owner-sidebar-collapsed .main-content,
        body.owner-sidebar-collapsed > .container {
            margin: 0 24px !important;
            width: calc(100% - 48px) !important;
        }

        .header,
        .page-header,
        .topbar,
        .card-heading {
            align-items: flex-start !important;
            flex-wrap: wrap !important;
            gap: 10px !important;
        }

        .header-right,
        .period-selector,
        .dashboard-links,
        .date-filter,
        .form-row,
        .form-actions {
            flex-wrap: wrap !important;
        }

        .metrics-grid,
        .summary-grid,
        .grid-two,
        .trust-strip,
        .columns {
            grid-template-columns: minmax(0, 1fr) !important;
        }

        .table-responsive {
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive table {
            min-width: 620px;
        }

        .content,
        .admin-page,
        .main-content,
        body > .container {
            min-width: 0 !important;
            box-sizing: border-box !important;
        }

        .content > *,
        .admin-page > *,
        .main-content > *,
        body > .container > * {
            min-width: 0;
            max-width: 100%;
        }

        .table-scroll,
        .table-responsive,
        .table-wrap,
        .table-wrapper,
        .table-container,
        .content-card {
            display: block;
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
        }

        .content table,
        .admin-page table,
        .main-content table,
        body > .container table {
            display: block;
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
        }

        .page-heading,
        .page-head,
        .head,
        .heading,
        .toolbar,
        .table-toolbar,
        .filter-form,
        .filters,
        .actions,
        .form-actions,
        .header-actions {
            max-width: 100%;
            flex-wrap: wrap !important;
        }

        .grid,
        .grid-two,
        .grid-layout,
        .grid-cards,
        .dashboard-grid,
        .stats-grid,
        .summary-grid,
        .metrics-grid,
        .metrics {
            grid-template-columns: minmax(0, 1fr) !important;
        }

        .content input,
        .content select,
        .content textarea,
        .admin-page input,
        .admin-page select,
        .admin-page textarea,
        .main-content input,
        .main-content select,
        .main-content textarea {
            min-width: 0;
            max-width: 100%;
        }

        img,
        input,
        select,
        textarea,
        button {
            max-width: 100%;
        }
    }

    @media (max-width: 420px) {
        .owner-topbar-tagline { display: none; }
        .sidebar {
            max-height: 68px !important;
            padding: 8px !important;
        }

        .sidebar .sidebar-header {
            padding-right: 4px !important;
        }

        .sidebar .logo-box,
        .sidebar .logo-box img {
            width: 32px !important;
            height: 32px !important;
            max-width: 32px !important;
            max-height: 32px !important;
        }

        .sidebar a,
        .sidebar .nav-menu-toggle {
            padding: 8px 10px !important;
            font-size: 12px !important;
        }

        .content,
        .admin-page,
        .main-content {
            min-height: calc(100vh - 68px) !important;
            padding: 12px 0 !important;
        }

        .content,
        .admin-page,
        .main-content,
        body > .container {
            margin-left: 12px !important;
            margin-right: 12px !important;
            width: calc(100% - 24px) !important;
        }
    }

    @media (max-width: 900px) and (min-width: 721px) {
        .owner-topbar { left:84px !important; }
        .sidebar {
            position:fixed !important;
            top:72px !important;
            left:0 !important;
            bottom:0 !important;
            width:84px !important;
            height:calc(100vh - 72px) !important;
            min-height:0 !important;
            max-height:none !important;
            flex-direction:column !important;
            align-items:stretch !important;
            padding:8px 10px 12px !important;
            margin:0 !important;
            overflow-x:hidden !important;
            overflow-y:auto !important;
        }
        .sidebar .sidebar-header { width:100% !important; height:48px !important; min-height:48px !important; flex:0 0 48px !important; justify-content:center !important; margin:0 0 8px !important; padding:0 !important; }
        .sidebar .logo-box,
        .sidebar .brand-name { display:none !important; }
        .sidebar .owner-sidebar-toggle { position:fixed !important; top:19px !important; left:25px !important; z-index:120 !important; display:inline-flex !important; margin:0 !important; }
        .sidebar .sidebar-nav { display:flex !important; width:100% !important; min-width:0 !important; flex:1 1 auto !important; flex-direction:column !important; align-items:stretch !important; gap:4px !important; overflow-x:hidden !important; overflow-y:auto !important; }
        .sidebar .nav-section-label,
        .sidebar .nav-divider,
        .sidebar .nav-submenu,
        .sidebar .nav-chevron { display:none !important; }
        .sidebar .sidebar-details[open] .nav-submenu { position:fixed !important; left:90px !important; bottom:16px !important; z-index:120 !important; display:flex !important; width:min(220px, calc(100vw - 104px)) !important; max-height:none !important; padding:8px !important; border:1px solid rgba(255,255,255,.16); border-radius:8px; background:#4a1018; box-shadow:0 8px 24px rgba(0,0,0,.22); }
        .sidebar .sidebar-details[open] .nav-submenu a { width:100% !important; height:auto !important; min-height:38px !important; flex:0 0 auto !important; justify-content:flex-start !important; gap:8px !important; padding:10px 12px !important; font-size:12px !important; white-space:normal !important; }
        body.owner-sidebar-collapsed .sidebar .sidebar-details[open] .nav-submenu { display:flex !important; }
        .sidebar a,
        .sidebar .nav-menu-toggle { position:relative !important; display:flex !important; width:100% !important; height:44px !important; min-height:44px !important; flex:0 0 44px !important; justify-content:center !important; gap:0 !important; padding:10px !important; font-size:0 !important; }
        .sidebar .nav-label { display:none !important; }
        .sidebar .sidebar-nav > a,
        .sidebar .sidebar-nav > .sidebar-details > summary,
        .sidebar .nav-menu-toggle { box-sizing:border-box !important; width:44px !important; min-width:44px !important; max-width:44px !important; align-self:center !important; overflow:hidden !important; }
        .sidebar .nav-icon { width:24px !important; font-size:22px !important; }
        .sidebar .nav-badge { position:absolute !important; top:2px !important; right:2px !important; min-width:16px !important; height:16px !important; margin:0 !important; padding:0 !important; font-size:9px !important; }
        body.owner-sidebar-collapsed .sidebar { width:84px !important; height:calc(100vh - 72px) !important; min-height:0 !important; max-height:none !important; padding:8px 10px 12px !important; }
        body.owner-sidebar-collapsed .sidebar .sidebar-header { width:100% !important; height:48px !important; min-height:48px !important; flex:0 0 48px !important; justify-content:center !important; margin:0 0 8px !important; padding:0 !important; }
        body.owner-sidebar-collapsed .sidebar .sidebar-nav { display:flex !important; }
        .content,
        .admin-page,
        .main-content,
        .settings-page,
        body > .container { box-sizing:border-box !important; width:calc(100% - 96px) !important; max-width:none !important; min-width:0 !important; margin:0 12px 0 84px !important; padding:72px 12px 24px !important; height:auto !important; min-height:calc(100vh - 72px) !important; overflow:visible !important; }
        body.owner-sidebar-collapsed .content,
        body.owner-sidebar-collapsed .admin-page,
        body.owner-sidebar-collapsed .main-content,
        body.owner-sidebar-collapsed .settings-page,
        body.owner-sidebar-collapsed > .container { width:calc(100% - 96px) !important; margin:0 12px 0 84px !important; }
        .table-scroll,
        .table-responsive,
        .table-wrap,
        .table-wrapper,
        .table-container,
        .content-card { max-width:100% !important; overflow-x:auto !important; -webkit-overflow-scrolling:touch; }
    }

    @media (max-width: 720px) {
        .owner-topbar { left:84px !important; padding:10px 12px !important; }
        .sidebar {
            position:fixed !important;
            top:72px !important;
            left:0 !important;
            bottom:0 !important;
            width:84px !important;
            height:calc(100vh - 72px) !important;
            min-height:0 !important;
            max-height:none !important;
            flex-direction:column !important;
            align-items:stretch !important;
            padding:8px 10px 12px !important;
            margin:0 !important;
            overflow-x:hidden !important;
            overflow-y:auto !important;
        }
        .sidebar .sidebar-header { width:100% !important; height:48px !important; min-height:48px !important; flex:0 0 48px !important; justify-content:center !important; margin:0 0 8px !important; padding:0 !important; }
        .sidebar .logo-box,
        .sidebar .brand-name { display:none !important; }
        .sidebar .owner-sidebar-toggle { position:fixed !important; top:19px !important; left:25px !important; z-index:120 !important; display:inline-flex !important; margin:0 !important; }
        .sidebar .sidebar-nav { display:flex !important; width:100% !important; min-width:0 !important; flex:1 1 auto !important; flex-direction:column !important; align-items:stretch !important; gap:4px !important; overflow-x:hidden !important; overflow-y:auto !important; }
        .sidebar .nav-section-label,
        .sidebar .nav-divider,
        .sidebar .nav-submenu,
        .sidebar .nav-chevron { display:none !important; }
        .sidebar .sidebar-details[open] .nav-submenu { position:fixed !important; left:90px !important; bottom:16px !important; z-index:120 !important; display:flex !important; width:min(220px, calc(100vw - 104px)) !important; max-height:none !important; padding:8px !important; border:1px solid rgba(255,255,255,.16); border-radius:8px; background:#4a1018; box-shadow:0 8px 24px rgba(0,0,0,.22); }
        .sidebar .sidebar-details[open] .nav-submenu a { width:100% !important; height:auto !important; min-height:38px !important; flex:0 0 auto !important; justify-content:flex-start !important; gap:8px !important; padding:10px 12px !important; font-size:12px !important; white-space:normal !important; }
        body.owner-sidebar-collapsed .sidebar .sidebar-details[open] .nav-submenu { display:flex !important; }
        .sidebar a,
        .sidebar .nav-menu-toggle { position:relative !important; display:flex !important; width:100% !important; height:44px !important; min-height:44px !important; flex:0 0 44px !important; justify-content:center !important; gap:0 !important; padding:10px !important; font-size:0 !important; }
        .sidebar .nav-label { display:none !important; }
        .sidebar .sidebar-nav > a,
        .sidebar .sidebar-nav > .sidebar-details > summary,
        .sidebar .nav-menu-toggle { box-sizing:border-box !important; width:44px !important; min-width:44px !important; max-width:44px !important; align-self:center !important; overflow:hidden !important; }
        .sidebar .nav-icon { width:24px !important; font-size:22px !important; }
        .sidebar .nav-badge { position:absolute !important; top:2px !important; right:2px !important; min-width:16px !important; height:16px !important; margin:0 !important; padding:0 !important; font-size:9px !important; }
        body.owner-sidebar-collapsed .sidebar .sidebar-nav { display:flex !important; }
        .content,
        .admin-page,
        .main-content,
        .settings-page,
        body > .container { box-sizing:border-box !important; width:calc(100% - 96px) !important; max-width:none !important; min-width:0 !important; margin:0 12px 0 84px !important; padding:72px 12px 24px !important; height:auto !important; min-height:calc(100vh - 72px) !important; overflow:visible !important; }
        body.owner-sidebar-collapsed .content,
        body.owner-sidebar-collapsed .admin-page,
        body.owner-sidebar-collapsed .main-content,
        body.owner-sidebar-collapsed .settings-page,
        body.owner-sidebar-collapsed > .container { width:calc(100% - 96px) !important; margin:0 12px 0 84px !important; }
        .page-heading,
        .page-head,
        .head,
        .heading,
        .toolbar,
        .table-toolbar,
        .filter-form,
        .filters,
        .actions,
        .form-actions,
        .header-actions { max-width:100%; flex-wrap:wrap !important; }
        .grid,
        .grid-two,
        .grid-layout,
        .grid-cards,
        .dashboard-grid,
        .stats-grid,
        .summary-grid,
        .metrics-grid,
        .metrics { grid-template-columns:minmax(0,1fr) !important; }
        .table-scroll,
        .table-responsive,
        .table-wrap,
        .table-wrapper,
        .table-container,
        .content-card { display:block; width:100% !important; max-width:100% !important; overflow-x:auto !important; -webkit-overflow-scrolling:touch; }
        .content table,
        .admin-page table,
        .main-content table,
        .settings-page table,
        body > .container table { display:block; width:100%; max-width:100%; overflow-x:auto; }
        .content input,
        .content select,
        .content textarea,
        .admin-page input,
        .admin-page select,
        .admin-page textarea,
        .main-content input,
        .main-content select,
        .main-content textarea { min-width:0; max-width:100%; }
    }

    @media (max-width: 420px) {
        .owner-topbar-tagline { display:none !important; }
        .owner-topbar-logo { width:36px !important; height:36px !important; flex-basis:36px !important; }
        .owner-topbar-account { flex-basis:34%; padding:4px; }
        .owner-topbar-avatar { width:30px; height:30px; flex-basis:30px; }
        .owner-topbar-user-name { font-size:10px; }
        .sidebar { width:72px !important; padding-left:8px !important; padding-right:8px !important; }
        body.owner-sidebar-collapsed .sidebar { width:72px !important; height:calc(100vh - 72px) !important; max-height:none !important; padding:8px !important; }
        .sidebar .owner-sidebar-toggle { left:19px !important; }
        .owner-topbar { left:72px !important; }
        .content,
        .admin-page,
        .main-content,
        .settings-page,
        body > .container { width:calc(100% - 82px) !important; margin-left:72px !important; margin-right:10px !important; padding-left:10px !important; padding-right:10px !important; }
        body.owner-sidebar-collapsed .content,
        body.owner-sidebar-collapsed .admin-page,
        body.owner-sidebar-collapsed .main-content,
        body.owner-sidebar-collapsed .settings-page,
        body.owner-sidebar-collapsed > .container { width:calc(100% - 82px) !important; margin-left:72px !important; margin-right:10px !important; }
    }
    @media (max-width: 900px) {
        .sidebar { top:0 !important; height:100vh !important; max-height:none !important; }
        .sidebar .sidebar-header { height:72px !important; min-height:72px !important; flex-basis:72px !important; }
        .sidebar .owner-sidebar-toggle { position:fixed !important; top:19px !important; left:25px !important; z-index:120 !important; margin:0 !important; }
        .owner-topbar { left:84px !important; }
        body.owner-sidebar-collapsed .sidebar { top:0 !important; height:100vh !important; max-height:none !important; }
        body.owner-sidebar-collapsed .sidebar .sidebar-header { height:72px !important; min-height:72px !important; flex-basis:72px !important; }
    }
    @media (max-width:420px) {
        .sidebar { width:72px !important; }
        .sidebar .owner-sidebar-toggle { left:19px !important; }
        .owner-topbar { left:72px !important; }
        body.owner-sidebar-collapsed .sidebar { width:72px !important; }
    }
    .sidebar .nav-icon.material-icons-outlined { font-size: 22px !important; }
    body.owner-sidebar-collapsed .sidebar .nav-label { display: none !important; }

    @media (min-width: 721px) {
        body.owner-sidebar-collapsed .sidebar .sidebar-header {
            height: 88px !important;
            min-height: 88px !important;
            flex: 0 0 88px !important;
            flex-direction: column !important;
            justify-content: center !important;
            gap: 4px !important;
            padding: 4px 0 !important;
        }
        body.owner-sidebar-collapsed .sidebar .logo-box { display: none !important; }
        body.owner-sidebar-collapsed .sidebar .brand-name { display: none !important; }
        body.owner-sidebar-collapsed .sidebar .owner-sidebar-toggle {
            position: fixed !important;
            top: 19px !important;
            left: 25px !important;
            width: 32px !important;
            height: 32px !important;
            flex: 0 0 32px !important;
            margin: 0 !important;
            z-index: 120 !important;
        }
    }

    @media (max-width: 720px) {
        body.owner-sidebar-collapsed .sidebar .sidebar-header {
            justify-content: flex-start !important;
            gap: 8px !important;
        }
        body.owner-sidebar-collapsed .sidebar .logo-box { display: none !important; }
        body.owner-sidebar-collapsed .sidebar .brand-name { display: none !important; }
        body.owner-sidebar-collapsed .sidebar .sidebar-nav { display: flex !important; }
        body.owner-sidebar-collapsed .sidebar .owner-sidebar-toggle {
            position: fixed !important;
            top: 19px !important;
            left: 25px !important;
            z-index: 120 !important;
            margin: 0 !important;
        }
    }

    @media (max-width: 420px) {
        body.owner-sidebar-collapsed .sidebar .sidebar-header {
            width: 100% !important;
            height: auto !important;
            min-height: 0 !important;
            flex-direction: column !important;
            justify-content: center !important;
            gap: 4px !important;
        }
        body.owner-sidebar-collapsed .sidebar .logo-box { display: none !important; }
    }
</style>
<div class="owner-topbar">
    <img class="owner-topbar-logo" src="../user/images/logo.png" alt="Josefina Farmacia logo">
    <div class="owner-topbar-brand">
        <span class="owner-topbar-title">Josefina Farmacia</span>
        <span class="owner-topbar-tagline">Quality and Affordable Branded &amp; Generic Medicines</span>
    </div>
    <div class="owner-topbar-account" aria-label="Signed-in admin account">
        <div class="owner-topbar-avatar">
            <?php if ($adminProfilePic !== '' && strtolower(pathinfo($adminProfilePic, PATHINFO_EXTENSION)) !== 'gif'): ?>
                <img src="uploads/<?php echo rawurlencode(basename($adminProfilePic)); ?>" alt="<?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.remove(); this.parentElement.textContent='<?php echo htmlspecialchars($adminInitials, ENT_QUOTES, 'UTF-8'); ?>';">
            <?php else: ?>
                <?php echo htmlspecialchars($adminInitials, ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </div>
        <div class="owner-topbar-user-info">
            <span class="owner-topbar-user-name"><?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="owner-topbar-user-email"><?php echo htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>
</div>
<div class="sidebar">
    <div class="sidebar-header">
        <div class="logo-box">
            <span class="material-icons-outlined owner-admin-icon" aria-hidden="true">admin_panel_settings</span>
        </div>
        <strong class="brand-name"><?php echo $isPharmacist ? 'Pharmacist' : 'Admin'; ?></strong>
        <button type="button" class="owner-sidebar-toggle" id="ownerSidebarToggle" aria-label="Collapse sidebar" aria-expanded="true" title="Collapse sidebar">
            <span class="material-icons-outlined" aria-hidden="true">menu</span>
        </button>
    </div>

    <nav class="sidebar-nav">
        <?php if (!$isPharmacist): ?>
            <a href="dashboard.php" class="<?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">
                <span class="material-icons-outlined nav-icon" aria-hidden="true">space_dashboard</span><span class="nav-label">Dashboard</span>
            </a>
        <?php endif; ?>
        <a href="products.php" class="<?php echo $currentPage === 'products.php' ? 'active' : ''; ?>">
            <span class="material-icons-outlined nav-icon" aria-hidden="true">medication</span><span class="nav-label">Products</span>
        </a>
        <a href="admin_orders.php" class="<?php echo $currentPage === 'admin_orders.php' ? 'active' : ''; ?>">
            <span class="material-icons-outlined nav-icon" aria-hidden="true">receipt_long</span><span class="nav-label">Orders</span>
            <span class="nav-badge">12</span>
        </a>
        <?php if (!in_array($_SESSION['role'] ?? '', ['owner', 'admin'], true)): ?>
            <a href="prescriptions.php" class="<?php echo $currentPage === 'prescriptions.php' ? 'active' : ''; ?>">
                <span class="material-icons-outlined nav-icon" aria-hidden="true">medication</span><span class="nav-label">Prescriptions</span>
            </a>
        <?php endif; ?>
        <a href="user_orders.php" class="<?php echo $customersActive ? 'active' : ''; ?>">
            <span class="material-icons-outlined nav-icon" aria-hidden="true">group</span><span class="nav-label">Customers</span>
        </a>
        <a href="supplier_management.php" class="<?php echo $currentPage === 'supplier_management.php' ? 'active' : ''; ?>">
            <span class="material-icons-outlined nav-icon" aria-hidden="true">local_shipping</span><span class="nav-label">Suppliers</span>
        </a>

        <span class="nav-divider supplier-divider"></span>
        <span class="nav-section-label">Inventory</span>
        <a href="stock_levels.php" class="<?php echo $currentPage === 'stock_levels.php' ? 'active' : ''; ?>">
            <span class="material-icons-outlined nav-icon" aria-hidden="true">inventory_2</span><span class="nav-label">Stock Levels</span>
        </a>
        <a href="inventory_batches.php" class="<?php echo $currentPage === 'inventory_batches.php' ? 'active' : ''; ?>">
            <span class="material-icons-outlined nav-icon" aria-hidden="true">event</span><span class="nav-label">Batches &amp; Expiry</span>
        </a>

        <a href="<?php echo $isPharmacist ? 'staff_sales_report.php' : 'analytics.php'; ?>" class="<?php echo $reportsActive ? 'active' : ''; ?>">
            <span class="material-icons-outlined nav-icon" aria-hidden="true">analytics</span><span class="nav-label">Reports</span>
        </a>

        <a href="notification.php" class="<?php echo $currentPage === 'notification.php' ? 'active' : ''; ?>">
            <span class="material-icons-outlined nav-icon" aria-hidden="true">notifications</span><span class="nav-label">Notifications</span>
            <span class="nav-badge">7</span>
        </a>
        <span class="nav-divider"></span>
        <a href="settings.php" class="<?php echo $settingsOpen ? 'active' : ''; ?>">
            <span class="material-icons-outlined nav-icon" aria-hidden="true">settings</span><span class="nav-label">Settings</span>
        </a>
        <a href="log_out.php">
            <span class="material-icons-outlined nav-icon" aria-hidden="true">logout</span><span class="nav-label">Logout</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="user-avatar"><?php echo strtoupper(substr($adminName, 0, 2)); ?></div>
        <div class="user-info">
            <span class="user-name"><?php echo htmlspecialchars($adminName); ?></span>
            <span class="user-email"><?php echo htmlspecialchars($adminEmail); ?></span>
        </div>
    </div>
</div>
<link rel="stylesheet" href="../user/appearance.css">
<script src="../user/appearance.js"></script>
<script>
(function () {
    const toggle = document.getElementById('ownerSidebarToggle');
    if (!toggle) return;

    const setCollapsed = (collapsed) => {
        document.body.classList.toggle('owner-sidebar-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        toggle.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        localStorage.setItem('ownerSidebarCollapsed', collapsed ? 'on' : 'off');
    };

    setCollapsed(localStorage.getItem('ownerSidebarCollapsed') === 'on');
    toggle.addEventListener('click', () => {
        setCollapsed(!document.body.classList.contains('owner-sidebar-collapsed'));
    });
}());
</script>

<script>
(function () {
    const searchSelector = 'input[name="q"]:not([type="hidden"]), input[name="search"]:not([type="hidden"]), input[name="barcode"]:not([type="hidden"]), input[name="transaction_search"]:not([type="hidden"])';
    const timers = new WeakMap();

    document.addEventListener('DOMContentLoaded', function () {
        const restoreState = sessionStorage.getItem('ownerLiveSearchState');
        if (restoreState !== null) {
            sessionStorage.removeItem('ownerLiveSearchState');
            const state = JSON.parse(restoreState);
            if (state.path === window.location.pathname) {
                const restoredInput = Array.from(document.querySelectorAll(searchSelector))
                    .find(function (input) {
                        return input.name === state.name && input.value.trim() === state.value;
                    });
                if (restoredInput) {
                    restoredInput.focus({ preventScroll: true });
                    const caret = Math.min(state.caret, restoredInput.value.length);
                    restoredInput.setSelectionRange(caret, caret);
                }
            }
        }

        document.querySelectorAll(searchSelector).forEach(function (input) {
            const form = input.form;
            if (!form || form.id === 'filterPanel' || form.method.toLowerCase() !== 'get') return;

            input.addEventListener('input', function () {
                window.clearTimeout(timers.get(input));
                timers.set(input, window.setTimeout(function () {
                    form.querySelectorAll('input[type="hidden"]').forEach(function (hiddenInput) {
                        if (hiddenInput.name === 'page' || /_page$/.test(hiddenInput.name)) {
                            hiddenInput.value = '1';
                        }
                    });

                    const searchValue = input.value.trim();
                    sessionStorage.setItem('ownerLiveSearchState', JSON.stringify({
                        path: window.location.pathname,
                        name: input.name,
                        value: searchValue,
                        caret: input.selectionStart === null ? searchValue.length : input.selectionStart
                    }));
                    const target = new URL(form.getAttribute('action') || window.location.href, window.location.href);
                    target.search = new URLSearchParams(new FormData(form)).toString();
                    window.location.replace(target.toString());
                }, 300));
            });
        });
    });
}());
</script>

<?php if (in_array($currentPage, ['dashboard.php', 'products.php', 'stock_levels.php', 'inventory_alerts.php', 'admin_orders.php', 'notification.php'], true)): ?>
<script>
(function () {
    const refreshInterval = 15000;
    let refreshTimer = null;

    function pageIsSafeToRefresh() {
        const activeElement = document.activeElement;
        const modalOpen = document.querySelector('.open, .modal[style*="display: flex"]');
        const editingField = activeElement && ['INPUT', 'SELECT', 'TEXTAREA'].includes(activeElement.tagName);
        return !modalOpen && !editingField;
    }

    refreshTimer = window.setInterval(function () {
        if (document.visibilityState === 'visible' && pageIsSafeToRefresh()) {
            window.location.reload();
        }
    }, refreshInterval);

    window.addEventListener('beforeunload', function () {
        window.clearInterval(refreshTimer);
    });
}());
</script>
<?php endif; ?>
