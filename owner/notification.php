<?php
session_start();
include 'config.php';

// Ensure read_at column exists
$checkColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'read_at'");
if (!$checkColumn || $checkColumn->num_rows === 0) {
    $conn->query("ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL DEFAULT NULL");
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $user_id = (int)$_SESSION['user_id'];
    
    if ($action === 'mark_read' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $conn->query("UPDATE notifications SET status = 'read', read_at = NOW() WHERE id = $id AND user_id = $user_id");
        exit('OK');
    } elseif ($action === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM notifications WHERE id = $id AND user_id = $user_id");
        exit('OK');
    } elseif ($action === 'mark_all_read') {
        $conn->query("UPDATE notifications SET status = 'read', read_at = NOW() WHERE (status = 'unread' OR read_at IS NULL) AND user_id = $user_id");
        exit('OK');
    } elseif ($action === 'clear_all') {
        $conn->query("DELETE FROM notifications WHERE user_id = $user_id");
        exit('OK');
    }
}

// 🔒 LOGIN CHECK
if (!isset($_SESSION['user_id'])) {
    die("Login required!");
}

$user_id = (int)$_SESSION['user_id'];

// ✅ CHECK IF ADMIN OR USER
$user = $conn->query("SELECT role FROM users WHERE id=$user_id")->fetch_assoc();

if (!$user) {
    die("User not found!");
}

$type_filter = (string)($_GET['type'] ?? 'all');
$read_filter = (string)($_GET['read'] ?? 'all');
$date_filter = (string)($_GET['date_filter'] ?? 'all');
$custom_start = (string)($_GET['start_date'] ?? '');
$custom_end = (string)($_GET['end_date'] ?? '');
$allowed_types = ['all', 'orders', 'prescriptions', 'payments', 'inventory', 'system'];
$allowed_read_filters = ['all', 'read', 'unread'];
$allowed_date_filters = ['all', 'yesterday', 'this_week', 'this_month', 'custom'];

if (!in_array($type_filter, $allowed_types, true)) $type_filter = 'all';
if (!in_array($read_filter, $allowed_read_filters, true)) $read_filter = 'all';
if (!in_array($date_filter, $allowed_date_filters, true)) $date_filter = 'all';

function is_valid_owner_notification_date($date) {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

if (($custom_start !== '' && !is_valid_owner_notification_date($custom_start))
    || ($custom_end !== '' && !is_valid_owner_notification_date($custom_end))
    || ($date_filter === 'custom' && $custom_start !== '' && $custom_end !== '' && $custom_start > $custom_end)) {
    $date_filter = 'all';
    $custom_start = '';
    $custom_end = '';
}

$custom_date_label = 'Custom range';
if ($date_filter === 'custom' && $custom_start !== '' && $custom_end !== '') {
    $custom_date_label = date('M j, Y', strtotime($custom_start)) . ' - ' . date('M j, Y', strtotime($custom_end));
} elseif ($date_filter === 'custom' && $custom_start !== '') {
    $custom_date_label = 'From ' . date('M j, Y', strtotime($custom_start)) . ' onward';
} elseif ($date_filter === 'custom' && $custom_end !== '') {
    $custom_date_label = 'Through ' . date('M j, Y', strtotime($custom_end));
}

$notification_conditions = [];
$notification_filter_types = '';
$notification_filter_params = [];
$notification_conditions[] = 'user_id = ?';
$notification_filter_types .= 'i';
$notification_filter_params[] = $user_id;

if ($read_filter === 'read') {
    $notification_conditions[] = "(status = 'read' OR read_at IS NOT NULL)";
} elseif ($read_filter === 'unread') {
    $notification_conditions[] = "(status = 'unread' AND read_at IS NULL)";
}

if ($type_filter !== 'all') {
    $notification_type_sql = "CASE
        WHEN LOWER(message) REGEXP 'order|purchase|shipment|delivery|tracking|checkout' THEN 'orders'
        WHEN LOWER(message) REGEXP 'prescription|doctor|medicine|rx|medication' THEN 'prescriptions'
        WHEN LOWER(message) REGEXP 'payment|paid|invoice|checkout|bank|gcash|cash on delivery|cod' THEN 'payments'
        WHEN LOWER(message) REGEXP 'stock|inventory|restock|product|low stock|out of stock' THEN 'inventory'
        ELSE 'system'
    END";
    $notification_conditions[] = "$notification_type_sql = ?";
    $notification_filter_types .= 's';
    $notification_filter_params[] = $type_filter;
}

if ($date_filter === 'yesterday') {
    $notification_conditions[] = 'DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)';
} elseif ($date_filter === 'this_week') {
    $notification_conditions[] = 'created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
    $notification_conditions[] = 'created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
} elseif ($date_filter === 'this_month') {
    $notification_conditions[] = 'created_at >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')';
    $notification_conditions[] = 'created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
} elseif ($date_filter === 'custom') {
    if ($custom_start !== '') {
        $notification_conditions[] = 'created_at >= ?';
        $notification_filter_types .= 's';
        $notification_filter_params[] = $custom_start;
    }
    if ($custom_end !== '') {
        $notification_conditions[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $notification_filter_types .= 's';
        $notification_filter_params[] = $custom_end;
    }
}

$notification_where_sql = $notification_conditions ? implode(' AND ', $notification_conditions) : '1 = 1';
$per_page = 10;
$notification_page = max(1, (int)($_GET['page'] ?? 1));
$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE $notification_where_sql");
if ($notification_filter_types !== '') {
    $count_stmt->bind_param($notification_filter_types, ...$notification_filter_params);
}
$count_stmt->execute();
$total_filtered_notifications = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_filtered_notifications / $per_page));
$notification_page = min($notification_page, $total_pages);
$offset = ($notification_page - 1) * $per_page;

$notification_stmt = $conn->prepare("SELECT * FROM notifications WHERE $notification_where_sql ORDER BY id DESC LIMIT ? OFFSET ?");
$notification_query_types = $notification_filter_types . 'ii';
$notification_query_params = array_merge($notification_filter_params, [$per_page, $offset]);
$notification_stmt->bind_param($notification_query_types, ...$notification_query_params);
$notification_stmt->execute();
$result = $notification_stmt->get_result();
$notifications = [];
while ($notification = $result->fetch_assoc()) {
    $notifications[] = $notification;
}
$notification_stmt->close();

$visibility_for_counts = "user_id = $user_id";
$total_result = $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE $visibility_for_counts");
$total_notifications = $total_result ? (int)$total_result->fetch_assoc()['total'] : 0;
$unread_result = $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE $visibility_for_counts AND status = 'unread' AND read_at IS NULL");
$unread_notification_count = $unread_result ? (int)$unread_result->fetch_assoc()['total'] : 0;
$filter_query_params = ['date_filter' => $date_filter, 'type' => $type_filter, 'read' => $read_filter];
if ($date_filter === 'custom' && $custom_start !== '') $filter_query_params['start_date'] = $custom_start;
if ($date_filter === 'custom' && $custom_end !== '') $filter_query_params['end_date'] = $custom_end;
$pagination_base_url = 'notification.php?' . http_build_query($filter_query_params) . '&page=';
$page_items = [];
if ($total_pages <= 5) {
    $page_items = range(1, $total_pages);
} elseif ($notification_page <= 3) {
    $page_items = [1, 2, 3, '...', $total_pages];
} elseif ($notification_page >= $total_pages - 2) {
    $page_items = [1, '...', $total_pages - 2, $total_pages - 1, $total_pages];
} else {
    $page_items = [1, '...', $notification_page - 1, $notification_page, $notification_page + 1, '...', $total_pages];
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🔔 Notifications</title>
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-2">
<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { height: 100%; overflow: hidden; }
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f7f8fa; color: #20232a; min-height: 100vh; }

.content { margin-left: 244px; padding: 28px 32px 42px; width: calc(100% - 244px); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

.page-header { margin-bottom: 24px; }
.page-header h1 { font-size: 27px; color: #20232a; margin: 0 0 6px; font-weight: 700; letter-spacing: -0.2px; }
.page-header p { color: #7b8189; font-size: 12px; margin: 0; }

.header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.actions {
    display: flex;
    gap: 8px;
}

.btn {
    padding: 9px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    font-size: 11px;
    cursor: pointer;
    font-weight: 700;
    background: #fff;
    color: #4e5660;
    transition: all 0.2s ease;
}

.btn:hover { background: #f8fafc; border-color: #d1d5db; }

.btn-mark-read { background: #e8f7ef; color: #15955c; border-color: #b8e7d7; }
.btn-mark-read:hover { background: #d1eddf; border-color: #a0dcc8; }

.btn-clear { background: #ffe8ed; color: #c91437; border-color: #ffc5d5; }
.btn-clear:hover { background: #ffd5dd; border-color: #ffadc4; }

.stats {
    display: flex;
    gap: 16px;
    font-size: 12px;
    color: #7b8189;
}

.notifications-container {
    flex: 1;
    min-height: 0;
    background: #fff;
    border: 1px solid #ebedf0;
    border-radius: 10px;
    overflow-y: auto;
    overflow-x: hidden;
    box-shadow: 0 2px 10px rgba(25,30,38,.04);
}

.notification-item {
    display: flex;
    gap: 14px;
    padding: 16px;
    border-bottom: 1px solid #f0f1f3;
    align-items: flex-start;
    transition: all 0.2s ease;
    cursor: pointer;
}

.notification-item:last-child { border-bottom: none; }
.notification-item:hover { background: #f9fafc; }
.notification-item.unread { background: #fffbf0; border-left: 4px solid #e9a316; }

.notification-icon {
    width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    flex-shrink: 0;
    font-size: 20px;
}

.icon-order { background: #e8f4f8; }
.icon-product { background: #e8f7ef; }
.icon-profile { background: #ffe8ed; }
.icon-general { background: #f0f1f3; }

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 6px;
}

.notification-title {
    font-size: 13px;
    font-weight: 700;
    color: #20232a;
    margin: 0;
}

.notification-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-order { background: #e8f4f8; color: #0284c7; }
.badge-product { background: #e8f7ef; color: #16a34a; }
.badge-profile { background: #ffe8ed; color: #c91437; }
.badge-general { background: #f0f1f3; color: #6b7280; }

.notification-message {
    font-size: 12px;
    color: #6b7280;
    line-height: 1.5;
    margin-bottom: 8px;
    word-wrap: break-word;
}

.notification-time {
    font-size: 11px;
    color: #9ca3af;
}

.notification-actions {
    display: flex;
    gap: 6px;
    align-items: center;
    margin-left: auto;
    flex-shrink: 0;
}

.btn-action {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    background: #fff;
    color: #6b7280;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s ease;
}

.btn-action:hover { background: #f8fafc; color: #ed1740; border-color: #ed1740; }

.btn-view { background: #e8f4f8; color: #0284c7; border-color: #bfdbfe; }
.btn-view:hover { background: #d1eddf; color: #0284c7; }

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    padding: 48px 20px;
    color: #9ca3af;
    text-align: center;
}

.empty-state-icon { font-size: 64px; margin-bottom: 16px; opacity: 0.4; }
.empty-state-text { font-size: 14px; color: #6b7280; margin: 0; }

.footer-info {
    margin-top: 20px;
    padding: 12px 14px;
    background: #e8f4f8;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    color: #0284c7;
    font-size: 12px;
    line-height: 1.5;
}

.footer-info strong { display: block; margin-bottom: 4px; }

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.modal.show { display: flex; }

.modal-content {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    width: 100%;
    max-width: 600px;
    max-height: 80vh;
    overflow-y: auto;
    animation: slideUp 0.3s ease;
}

@keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #ebedf0;
}

.modal-header h2 {
    font-size: 18px;
    font-weight: 700;
    color: #20232a;
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 6px;
    background: #f0f1f3;
    color: #6b7280;
    cursor: pointer;
    font-size: 18px;
    transition: all 0.2s ease;
}

.modal-close:hover { background: #e5e7eb; color: #20232a; }

.modal-body {
    padding: 24px;
}

.modal-notification {
    display: flex;
    gap: 16px;
    margin-bottom: 24px;
}

.modal-icon {
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    font-size: 28px;
    flex-shrink: 0;
}

.modal-notification.type-order .modal-icon { background: #e8f4f8; }
.modal-notification.type-product .modal-icon { background: #e8f7ef; }
.modal-notification.type-profile .modal-icon { background: #ffe8ed; }

.modal-info {
    flex: 1;
}

.modal-title {
    font-size: 16px;
    font-weight: 700;
    color: #20232a;
    margin-bottom: 8px;
}

.modal-message {
    font-size: 14px;
    color: #6b7280;
    line-height: 1.6;
    margin-bottom: 12px;
}

.modal-time {
    font-size: 12px;
    color: #9ca3af;
}

.modal-actions {
    display: flex;
    gap: 10px;
    padding-top: 20px;
    border-top: 1px solid #ebedf0;
}

.modal-btn {
    flex: 1;
    padding: 10px 16px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}

.modal-btn-primary {
    background: #ed1740;
    color: #fff;
    border-color: #ed1740;
}

.modal-btn-primary:hover { background: #c91437; border-color: #c91437; }

.modal-btn-secondary {
    background: #fff;
    color: #4e5660;
}

.modal-btn-secondary:hover { background: #f8fafc; }

@media (max-width: 768px) {
    .content { margin-left: 0; padding: 20px 16px 30px; }
    .page-header { margin-bottom: 18px; }
    .page-header h1 { font-size: 22px; }
    .header-bar { flex-direction: column; }
    .actions { width: 100%; flex-wrap: wrap; }
    .btn { flex: 1; min-width: 120px; }
    .notification-item { flex-direction: column; }
    .notification-actions { margin-left: 0; width: 100%; }
    .modal-content { max-width: 90%; max-height: 90vh; }
    .footer-info { margin-top: 16px; }
}

/* Match the customer notification experience while keeping owner actions. */
body { background: #fff; color: #101828; font-family: Arial, sans-serif; overflow: auto; }
.content { margin-left: 260px; width: calc(100% - 260px); min-height: 100vh; height: auto; padding: 32px 40px; display: block; overflow: visible; }
.page-header { display: flex; align-items: center; justify-content: space-between; gap:16px; margin-bottom: 32px; }
.title-wrap { display: flex; align-items: center; gap: 14px; }
.title-icon { width: 54px; height: 54px; border-radius: 50%; background: #fff0f0; color: #ed1c24; display: grid; place-items: center; flex: 0 0 54px; }
.title-icon i { font-size: 28px; }
.page-header h1 { font-size: 22px; color: #101828; margin: 0 0 5px; letter-spacing: 0; }
.page-header p { color: #667085; font-size: 13px; }
.header-bar { margin-bottom: 0; justify-content: flex-end; }
.page-header .actions { display:flex; align-items:center; gap:8px; }
.actions { gap: 8px; }
.btn { padding: 11px 16px; border-radius: 7px; font-size: 12px; }
.btn-mark-read, .btn-clear { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:1px solid #dfe3e8; background:#fff; color:#344054; white-space:nowrap; }
.btn-mark-read:hover, .btn-clear:hover { background:#fff; border-color:#ed1c24; color:#ed1c24; }
.page-header .btn i { font-size:18px; line-height:1; }
.stats { display: none; }
.tabs { display: flex; border-bottom: 1px solid #e4e7ec; margin: 0 0 21px; }
.tab { width: 100px; border: 1px solid #e4e7ec; border-bottom: 0; background: #fff; padding: 10px; font-weight: 600; color: #344054; cursor: pointer; }
.tab:first-child { border-radius: 7px 0 0 0; }
.tab:last-child { border-radius: 0 7px 0 0; }
.tab.active { color: #ed1c24; border-bottom: 2px solid #ed1c24; }
.notifications-container { background: transparent; border: 0; border-radius: 8px; overflow-y: auto; overflow-x: hidden; box-shadow: none; }
.notification-item { min-height: 98px; display: grid; grid-template-columns: 10px 56px minmax(0, 1fr); align-items: center; gap: 12px; padding: 16px 20px 16px 14px; border:1px solid #e5e7eb; border-radius:12px; background:rgba(255,255,255,.72); box-shadow:0 8px 22px rgba(52,72,96,.07); color:inherit; text-decoration:none; cursor: pointer; transition:border-color .2s ease,box-shadow .2s ease,transform .2s ease,background .2s ease; }
.notification-item:hover { position:relative; z-index:1; background:#fff8f8; border-color:rgba(211,47,47,.35); box-shadow:0 8px 20px rgba(211,47,47,.12); transform:translateY(-1px); }
.notification-item.unread { background:#fff; border-left:1px solid rgba(237,28,36,.26); }
.notification-item.unread:hover { background:#fffafa !important; border-color:rgba(211,47,47,.35) !important; box-shadow:0 12px 26px rgba(52,72,96,.13) !important; transform:translateY(-1px) !important; }
.notification-item.hidden { display: none; }
.notification-icon { width: 56px; height: 56px; border-radius: 50%; background: #fff0f0; color: #ed1c24; display: grid; place-items: center; font-size: 28px; }
.status-dot { width: 10px; height: 10px; border-radius: 50%; background: #c5cad2; }
.notification-item.unread .status-dot { background: #ed1c24; }
.icon-order, .icon-product, .icon-profile, .icon-general { background: #fff0f0; }
.notification-content { min-width: 0; }
.notification-header { display: block; margin-bottom: 8px; }
.notification-title { font-size: 14px; color: #101828; margin: 0; }
.notification-badge { display: none; }
.notification-message { color: #475467; font-size: 13px; margin: 0 0 4px; line-height: 1.5; }
.notification-time { color: #667085; font-size: 12px; }
.notification-actions { display: flex; gap: 6px; align-items: center; margin-left: auto; }
.btn-action { width: 32px; height: 32px; border-radius: 6px; background: #fff; color: #6b7280; }
.btn-action i { font-size: 18px; line-height: 1; }
.btn-action:hover { background: #fff; color: #ed1740; border-color: #ed1740; }
.empty-state { min-height: 260px; height: auto; }
.footer-info { display: none; }
.top-controls { margin: 0 0 20px; }
.filter-bar { position:relative; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.filter-select, .filter-input { min-width:170px; padding:10px 34px 10px 12px; border:1px solid #dfe3e8; border-radius:8px; background-color:#fff; color:#1f2937; font-size:13px; }
.filter-select { cursor:pointer; }
.filter-select:focus, .filter-input:focus { outline:none; border-color:#d32f2f; box-shadow:0 0 0 3px rgba(211,47,47,.12); }
.custom-range { display:none; align-items:center; gap:10px; padding:10px 12px; border:1px solid #e1e5eb; border-radius:8px; background:#fff; box-shadow:0 8px 20px rgba(52,72,96,.12); position:absolute; top:calc(100% + 8px); left:0; z-index:10; white-space:nowrap; }
.custom-range.is-visible { display:flex; }
.date-input-wrap { position:relative; }
.date-input-wrap input { width:145px; padding:9px 10px; border:1px solid #dfe3e8; border-radius:8px; background:#fff; color:transparent; font:inherit; }
.date-input-wrap.has-value input { color:#334155; }
.date-input-wrap span { position:absolute; top:50%; left:10px; transform:translateY(-50%); color:#64748b; font-size:13px; pointer-events:none; }
.date-input-wrap.has-value span { display:none; }
.apply-date-filter { padding:9px 16px; border:0; border-radius:8px; background:#8B1E2D; color:#fff; font-weight:600; cursor:pointer; }
.apply-date-filter:disabled { background:#eef0f2; color:#9ca3af; cursor:not-allowed; }
.notification-pager { display:flex; align-items:center; justify-content:center; gap:8px; margin:20px auto 10px; padding-top:8px; flex-wrap:wrap; }
.pager-link { min-width:38px; height:38px; padding:0 8px; border:1px solid #e0e6f0; border-radius:8px; background:#fff; color:#4b5563; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; font-size:14px; font-weight:600; }
.pager-link:hover { border-color:#d32f2f; color:#d32f2f; background:#fff5f5; }
.pager-link.active { border-color:#d32f2f; background:#d32f2f; color:#fff; }
.pager-link.disabled { border:0; background:none; color:#010813; pointer-events:none; }
@media (max-width: 768px) {
    .content { margin-left: 0; width: 100%; padding: 24px 16px; }
    .page-header { align-items: flex-start; flex-direction:column; }
    .page-header .actions { width:100%; flex-wrap:wrap; }
    .page-header .actions .btn { flex:1; min-width:145px; }
    .title-icon { width: 45px; height: 45px; flex-basis: 45px; }
    .title-icon i { font-size: 23px; }
    .page-header h1 { font-size: 22px; }
    .actions { width: 100%; }
    .notifications-container { gap:14px; }
    .notification-item { grid-template-columns:20px 45px minmax(0,1fr); gap:10px; padding:14px 10px; }
    .notification-icon { width:45px; height:45px; font-size:23px; }
    .notification-icon i { font-size:23px; }
    .notification-time { grid-column:3; text-align:left; }
    .notification-actions { grid-column: 3; justify-content: flex-start; margin-top: 8px; }
    .filter-bar { width:100%; }
    .filter-select { flex:1 1 170px; }
    .custom-range { max-width:calc(100vw - 32px); flex-wrap:wrap; white-space:normal; }
}
.page-header h1 { color:#252a30; font-family:'Segoe UI', Tahoma, sans-serif; font-size:30px; font-weight:800; }
.notifications-container { display:grid; gap:14px; }
.notification-item { min-height:98px; display:grid; grid-template-columns:24px 56px minmax(0,1fr) 125px; align-items:center; gap:12px; padding:16px 20px 16px 14px; border:1px solid #e5e7eb; border-radius:14px; background:#fff; box-shadow:0 8px 22px rgba(52,72,96,.07); color:inherit; text-decoration:none; cursor:pointer; transition:all .2s ease; }
.notification-item:hover { position:relative; z-index:1; background:#fffafa; border-color:rgba(211,47,47,.35); box-shadow:0 12px 26px rgba(52,72,96,.13); transform:translateY(-1px); }
.notification-item:last-child { border-bottom:1px solid #e5e7eb; }
.notification-item.unread { border-color:#e5e7eb; background:#fff; }
.notification-item.unread .status-dot { background:#ed1c24; box-shadow:0 0 0 4px rgba(237,28,36,.08); }
.status-dot { width:10px; height:10px; border-radius:50%; background:#c5cad2; }
.notification-icon { width:56px; height:56px; border-radius:50%; background:#fff0f0; color:#ed1c24; display:grid; place-items:center; font-size:29px; }
.notification-icon i { font-size:29px; }
.notification-content { min-width:0; }
.notification-header { display:block; margin:0 0 8px; }
.notification-title { margin:0; color:#141821; font-size:14px; font-weight:700; overflow-wrap:anywhere; }
.notification-message { margin:0; color:#475467; font-size:13px; line-height:1.45; overflow-wrap:anywhere; }
.notification-time { color:#667085; font-size:12px; line-height:1.5; text-align:right; white-space:nowrap; }
</style>
</head>
<body>
<?php include 'appbar_owner.php'; include 'sidebar_owner.php'; ?>

<div class="content">
    <div class="page-header">
        <div class="title-wrap">
            <div class="title-icon"><i class="material-icons-outlined">notifications_none</i></div>
            <div>
                <h1>Notifications</h1>
                <p>Stay updated with your latest activity.</p>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-mark-read" onclick="markAllAsRead()"><i class="material-icons-outlined" aria-hidden="true">done</i> Mark as read</button>
            <button class="btn btn-clear" onclick="clearAllNotifications()"><i class="material-icons-outlined" aria-hidden="true">clear_all</i> Clear</button>
        </div>
    </div>

    <div class="top-controls">
        <form method="get" class="filter-bar" id="notificationFilters" aria-label="Notification filters">
            <input type="hidden" name="page" value="1">
            <select id="timeFilter" name="date_filter" class="filter-select" aria-label="Filter by time">
                <option value="all" <?= $date_filter === 'all' ? 'selected' : '' ?>>All time</option>
                <option value="yesterday" <?= $date_filter === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                <option value="this_week" <?= $date_filter === 'this_week' ? 'selected' : '' ?>>This week</option>
                <option value="this_month" <?= $date_filter === 'this_month' ? 'selected' : '' ?>>This month</option>
                <option value="custom" <?= $date_filter === 'custom' ? 'selected' : '' ?>><?= htmlspecialchars($custom_date_label, ENT_QUOTES, 'UTF-8') ?></option>
            </select>
            <select id="typeFilter" name="type" class="filter-select" aria-label="Filter by type">
                <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Notifications</option>
                <option value="orders" <?= $type_filter === 'orders' ? 'selected' : '' ?>>Orders</option>
                <option value="prescriptions" <?= $type_filter === 'prescriptions' ? 'selected' : '' ?>>Prescriptions</option>
                <option value="payments" <?= $type_filter === 'payments' ? 'selected' : '' ?>>Payments</option>
                <option value="inventory" <?= $type_filter === 'inventory' ? 'selected' : '' ?>>Inventory/Stock</option>
                <option value="system" <?= $type_filter === 'system' ? 'selected' : '' ?>>System</option>
            </select>
            <select id="readFilter" name="read" class="filter-select" aria-label="Filter by read status">
                <option value="all" <?= $read_filter === 'all' ? 'selected' : '' ?>>All statuses</option>
                <option value="read" <?= $read_filter === 'read' ? 'selected' : '' ?>>Read</option>
                <option value="unread" <?= $read_filter === 'unread' ? 'selected' : '' ?>>Unread</option>
            </select>
            <div id="customRange" class="custom-range <?= $date_filter === 'custom' && $custom_start === '' && $custom_end === '' ? 'is-visible' : '' ?>">
                <div class="date-input-wrap"><input id="customStart" name="start_date" class="filter-input" type="date" aria-label="Start date" value="<?= htmlspecialchars($custom_start, ENT_QUOTES, 'UTF-8') ?>"><span>Start date</span></div>
                <div class="date-input-wrap"><input id="customEnd" name="end_date" class="filter-input" type="date" aria-label="End date" value="<?= htmlspecialchars($custom_end, ENT_QUOTES, 'UTF-8') ?>"><span>End date</span></div>
                <button type="submit" class="apply-date-filter" id="applyCustomRange" <?= ($custom_start !== '' || $custom_end !== '') ? '' : 'disabled' ?>>Apply</button>
            </div>
        </form>
    </div>

    <div class="notifications-container" id="notification-list">
        <?php if ($total_filtered_notifications > 0): ?>
            <?php foreach ($notifications as $notif):
                                $isRead = ($notif['status'] ?? 'unread') === 'read' || !empty($notif['read_at']);
                                $message = $notif['message'];
                                $lowerMessage = strtolower($message);
                                if (strpos($lowerMessage, 'order') !== false) {
                                    $type = 'order';
                                    $icon = 'shopping_cart';
                                } elseif (strpos($lowerMessage, 'product') !== false || strpos($lowerMessage, 'stock') !== false) {
                                    $type = 'product';
                                    $icon = 'inventory_2';
                                } elseif (strpos($lowerMessage, 'profile') !== false) {
                                    $type = 'profile';
                                    $icon = 'person';
                                } else {
                                    $type = 'general';
                                    $icon = 'notifications';
                                }
                                if (preg_match('/order|purchase|shipment|delivery|tracking|checkout/i', $message)) {
                                    $filterType = 'orders';
                                } elseif (preg_match('/prescription|doctor|medicine|rx|medication/i', $message)) {
                                    $filterType = 'prescriptions';
                                } elseif (preg_match('/payment|paid|invoice|checkout|bank|gcash|cash on delivery|cod/i', $message)) {
                                    $filterType = 'payments';
                                } elseif (preg_match('/stock|inventory|restock|product|low stock|out of stock/i', $message)) {
                                    $filterType = 'inventory';
                                } elseif (preg_match('/profile|account/i', $message)) {
                                    $filterType = 'profile';
                                } else {
                                    $filterType = 'system';
                                }
                                $notificationOrderId = 0;
                                if (preg_match('/ORD-\d{6,8}-\d{3,}/i', $message, $orderNumberMatch)) {
                                    $notificationOrderId = resolveOrderNumber($conn, strtoupper($orderNumberMatch[0]));
                                } elseif (preg_match('/\border\s*#?\s*(\d+)\b/i', $message, $legacyOrderMatch)) {
                                    $notificationOrderId = (int)$legacyOrderMatch[1];
                                }
                                $notificationUrl = 'dashboard.php';
                                if ($filterType === 'orders' || $filterType === 'payments') {
                                    $notificationUrl = 'admin_orders.php' . ($notificationOrderId > 0 ? '?order=' . $notificationOrderId : '');
                                } elseif ($filterType === 'prescriptions') {
                                    $notificationUrl = 'prescriptions.php';
                                } elseif ($filterType === 'inventory') {
                                    $notificationUrl = preg_match('/expiry|expired|stock|inventory|restock/i', $message)
                                        ? 'inventory_alerts.php'
                                        : 'products.php';
                                } elseif ($filterType === 'profile') {
                                    $notificationUrl = 'profile.php';
                                }
                                if (preg_match('/Product (?:Updated:\s*|Reverted:\s*|deleted:\s*)(.+?)(?:\s*\|\s*New Stock:.*|\s+to previous version)?$/i', trim($message), $productMatch)) {
                                    $productName = trim($productMatch[1]);
                                    $productStmt = $conn->prepare('SELECT id FROM products WHERE product_name = ? LIMIT 1');
                                    if ($productStmt) {
                                        $productStmt->bind_param('s', $productName);
                                        if ($productStmt->execute()) {
                                            $product = $productStmt->get_result()->fetch_assoc();
                                            $notificationUrl = $product
                                                ? 'products.php?edit_product_id=' . (int)$product['id']
                                                : 'products.php?search=' . rawurlencode($productName);
                                        } else {
                                            error_log('Unable to find product for owner notification: ' . $productStmt->error);
                                        }
                                        $productStmt->close();
                                    } else {
                                        error_log('Unable to prepare product lookup for owner notification: ' . $conn->error);
                                    }
                                }
                                $created = strtotime($notif['created_at']);
                            ?>
                            <a class="notification-item <?= $isRead ? '' : 'unread' ?>" href="<?= htmlspecialchars($notificationUrl, ENT_QUOTES, 'UTF-8') ?>" data-notification-id="<?= (int)$notif['id'] ?>" data-status="<?= $isRead ? 'read' : 'unread' ?>" data-type="<?= htmlspecialchars($filterType, ENT_QUOTES, 'UTF-8') ?>" data-created-at="<?= htmlspecialchars(date('Y-m-d H:i:s', $created), ENT_QUOTES, 'UTF-8') ?>" onclick="openNotificationDestination(event, this)">
                                <span class="status-dot" aria-hidden="true"></span>
                                <div class="notification-icon icon-<?= $type ?>"><i class="material-icons-outlined" aria-hidden="true"><?= $icon ?></i></div>
                                <div class="notification-content">
                                    <div class="notification-header"><h3 class="notification-title"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></h3></div>
                                    <div class="notification-message"><?= $isRead ? 'Your notification has been viewed.' : 'You have a new notification.' ?></div>
                                </div>
                                <time class="notification-time"><?= date('M d, Y', $created) ?><br><?= date('h:i A', $created) ?></time>
                            </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state"><div class="empty-state-icon">🔔</div><p class="empty-state-text"><?= $total_notifications > 0 ? 'No notifications found for these filters.' : 'No notifications yet.' ?></p></div>
        <?php endif; ?>
    </div>

    <?php if ($total_filtered_notifications > $per_page): ?>
        <nav class="notification-pager" aria-label="Notification pages">
            <?php if ($notification_page > 1): ?><a class="pager-link" href="<?= htmlspecialchars($pagination_base_url . ($notification_page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?>
            <?php foreach ($page_items as $page_item): ?>
                <?php if ($page_item === '...'): ?><span class="pager-link disabled" aria-hidden="true">...</span>
                <?php else: ?><a class="pager-link <?= $page_item === $notification_page ? 'active' : '' ?>" href="<?= htmlspecialchars($pagination_base_url . $page_item, ENT_QUOTES, 'UTF-8') ?>"><?= $page_item ?></a><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($notification_page < $total_pages): ?><a class="pager-link" href="<?= htmlspecialchars($pagination_base_url . ($notification_page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?>
        </nav>
    <?php endif; ?>

    <div class="footer-info">
        <strong>💡 Tips:</strong> You'll receive notifications for new orders, product alerts, and profile changes. Click any notification to view details.
    </div>
</div>

<script>
async function openNotificationDestination(event, notificationLink) {
    event.preventDefault();
    const destination = notificationLink.href;
    try {
        const response = await fetch('notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_read&id=' + encodeURIComponent(notificationLink.dataset.notificationId)
        });
        if (!response.ok) {
            throw new Error('Unable to mark notification as read.');
        }
    } catch (error) {
        console.error('Unable to mark notification as read:', error);
    }
    window.location.href = destination;
}

function markAllAsRead() {
    if (confirm('Mark all notifications as read?')) {
        fetch('notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_all_read'
        }).then(r => location.reload());
    }
}

function clearAllNotifications() {
    if (confirm('Clear all notifications? This cannot be undone.')) {
        fetch('notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=clear_all'
        }).then(r => location.reload());
    }
}

const timeFilter = document.getElementById('timeFilter');
const typeFilter = document.getElementById('typeFilter');
const readFilter = document.getElementById('readFilter');
const customRange = document.getElementById('customRange');
const customStart = document.getElementById('customStart');
const customEnd = document.getElementById('customEnd');
const applyCustomRange = document.getElementById('applyCustomRange');
const notificationFilters = document.getElementById('notificationFilters');

function updateCustomApplyState() {
    const hasDate = customStart.value || customEnd.value;
    const invalidRange = customStart.value && customEnd.value && customStart.value > customEnd.value;
    applyCustomRange.disabled = !hasDate || invalidRange;
}

function updateCustomDateLabels() {
    [customStart, customEnd].forEach(input => {
        input.closest('.date-input-wrap').classList.toggle('has-value', input.value !== '');
    });
}

function updateCustomRangeState() {
    const isCustom = timeFilter.value === 'custom';
    customRange.classList.toggle('is-visible', isCustom);
    updateCustomDateLabels();
    updateCustomApplyState();
}

timeFilter.addEventListener('change', () => {
    if (timeFilter.value === 'custom') {
        updateCustomRangeState();
        return;
    }
    customStart.value = '';
    customEnd.value = '';
    notificationFilters.submit();
});

[typeFilter, readFilter].forEach(select => select.addEventListener('change', () => notificationFilters.submit()));
[customStart, customEnd].forEach(input => {
    input.addEventListener('input', updateCustomRangeState);
    input.addEventListener('change', updateCustomRangeState);
});
updateCustomRangeState();

</script>
</body>
</html>