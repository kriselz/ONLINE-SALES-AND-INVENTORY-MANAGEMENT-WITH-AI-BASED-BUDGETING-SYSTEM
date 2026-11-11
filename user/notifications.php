<?php
include 'config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ✅ CHECK LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$notification_bulk_csrf_token = $_SESSION['notification_bulk_csrf_token'] ?? '';
if (!is_string($notification_bulk_csrf_token) || strlen($notification_bulk_csrf_token) !== 64) {
    $notification_bulk_csrf_token = bin2hex(random_bytes(32));
    $_SESSION['notification_bulk_csrf_token'] = $notification_bulk_csrf_token;
}
$type_filter = (string) ($_GET['type'] ?? 'all');
$read_filter = (string) ($_GET['read'] ?? 'all');
$date_filter = (string) ($_GET['date_filter'] ?? 'all');
$custom_start = (string) ($_GET['start_date'] ?? '');
$custom_end = (string) ($_GET['end_date'] ?? '');
$allowed_types = ['all', 'orders', 'prescriptions', 'payments', 'inventory', 'system'];
$allowed_read_filters = ['all', 'read', 'unread'];
$allowed_date_filters = ['all', 'today', 'yesterday', 'this_week', 'this_month', 'older_1_month', 'custom'];

if (!in_array($type_filter, $allowed_types, true)) {
    $type_filter = 'all';
}
if (!in_array($read_filter, $allowed_read_filters, true)) {
    $read_filter = 'all';
}
if (!in_array($date_filter, $allowed_date_filters, true)) {
    $date_filter = 'all';
}

function is_valid_notification_date($date) {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

if (($custom_start !== '' && !is_valid_notification_date($custom_start))
    || ($custom_end !== '' && !is_valid_notification_date($custom_end))
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

function notification_type_from_message($message) {
    $text = strtolower($message ?? '');

    if (preg_match('/order|purchase|shipment|delivery|tracking|checkout/i', $text)) {
        return 'orders';
    }

    if (preg_match('/prescription|doctor|medicine|rx|medication/i', $text)) {
        return 'prescriptions';
    }

    if (preg_match('/payment|paid|invoice|checkout|bank|gcash|cash on delivery|cod/i', $text)) {
        return 'payments';
    }

    if (preg_match('/stock|inventory|restock|product|low stock|out of stock/i', $text)) {
        return 'inventory';
    }

    return 'system';
}

if (isset($_GET['notification_id'])) {
    $notification_id = (int) $_GET['notification_id'];
    $notification_stmt = $conn->prepare(
        "SELECT message FROM notifications WHERE id = ? AND user_id = ?"
    );
    $notification_stmt->bind_param('ii', $notification_id, $user_id);
    $notification_stmt->execute();
    $notification = $notification_stmt->get_result()->fetch_assoc();
    $notification_stmt->close();

    if ($notification) {
        $mark_stmt = $conn->prepare("UPDATE notifications SET status = 'read' WHERE id = ? AND user_id = ?");
        $mark_stmt->bind_param('ii', $notification_id, $user_id);
        $mark_stmt->execute();
        $mark_stmt->close();

        if (preg_match('/\border\s+#?(ORD-\d{8}-\d{3,})\b/i', $notification['message'], $order_match)) {
            $order_id = resolveOrderNumber($conn, strtoupper($order_match[1]));
        } elseif (preg_match('/\border\s*#?\s*(\d+)\b/i', $notification['message'], $order_match)) {
            $order_id = (int)$order_match[1];
        } else {
            $order_id = 0;
        }
        if ($order_id > 0) {
            $order_stmt = $conn->prepare('SELECT id FROM orders WHERE id = ? AND user_id = ?');
            $order_stmt->bind_param('ii', $order_id, $user_id);
            $order_stmt->execute();
            $order_exists = $order_stmt->get_result()->fetch_assoc();
            $order_stmt->close();

            if ($order_exists) {
                header('Location: order_details.php?id=' . $order_id);
                exit();
            }
        }

        if (preg_match('/Product Updated:\s*(.+?)\s*\|\s*New Stock:/i', $notification['message'], $product_match)) {
            $product_name = trim($product_match[1]);
            $product_stmt = $conn->prepare('SELECT id FROM products WHERE product_name = ? LIMIT 1');
            $product_stmt->bind_param('s', $product_name);
            $product_stmt->execute();
            $product = $product_stmt->get_result()->fetch_assoc();
            $product_stmt->close();

            if ($product) {
                header('Location: view_product.php?id=' . (int) $product['id']);
                exit();
            }
        }

        if (stripos($notification['message'], 'password') !== false) {
            header('Location: settings.php?tab=password');
            exit();
        }

        if (stripos($notification['message'], 'account information') !== false) {
            header('Location: settings.php?tab=account');
            exit();
        }
    }

    header('Location: notifications.php#notification-' . $notification_id);
    exit();
}

if (isset($_POST['notification_action'])) {
    $submitted_bulk_csrf_token = (string) ($_POST['notification_bulk_csrf_token'] ?? '');
    if (!hash_equals($notification_bulk_csrf_token, $submitted_bulk_csrf_token)) {
        http_response_code(403);
        exit('Unable to verify this request. Refresh the page and try again.');
    }

    $action = (string) $_POST['notification_action'];
    if ($action === 'mark_all_read') {
        $bulk_stmt = $conn->prepare("UPDATE notifications SET status = 'read' WHERE user_id = ? AND status = 'unread'");
    } elseif ($action === 'delete_all') {
        $bulk_stmt = $conn->prepare('DELETE FROM notifications WHERE user_id = ?');
    } elseif ($action === 'delete_one') {
        $notification_to_delete = max(0, (int) ($_POST['notification_id'] ?? 0));
        if ($notification_to_delete === 0) {
            http_response_code(400);
            exit('Invalid notification.');
        }
        $bulk_stmt = $conn->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
    } else {
        http_response_code(400);
        exit('Invalid notification action.');
    }

    if (!$bulk_stmt) {
        error_log('Unable to prepare notification bulk action: ' . $conn->error);
        http_response_code(500);
        exit('Unable to update notifications.');
    }
    if ($action === 'delete_one') {
        $bulk_stmt->bind_param('ii', $notification_to_delete, $user_id);
    } else {
        $bulk_stmt->bind_param('i', $user_id);
    }
    if (!$bulk_stmt->execute()) {
        error_log('Notification bulk action failed: ' . $bulk_stmt->error);
        $bulk_stmt->close();
        http_response_code(500);
        exit('Unable to update notifications.');
    }
    $bulk_stmt->close();

    header('Location: notifications.php');
    exit();
}

$notification_conditions = ['user_id = ?'];
$notification_filter_types = 'i';
$notification_filter_params = [$user_id];

if ($read_filter !== 'all') {
    $notification_conditions[] = 'status = ?';
    $notification_filter_types .= 's';
    $notification_filter_params[] = $read_filter;
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

if ($date_filter === 'today') {
    $notification_conditions[] = 'DATE(created_at) = CURDATE()';
} elseif ($date_filter === 'yesterday') {
    $notification_conditions[] = 'DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)';
} elseif ($date_filter === 'this_week') {
    $notification_conditions[] = 'created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
    $notification_conditions[] = 'created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
} elseif ($date_filter === 'this_month') {
    $notification_conditions[] = 'created_at >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')';
    $notification_conditions[] = 'created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
} elseif ($date_filter === 'older_1_month') {
    $notification_conditions[] = 'created_at < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)';
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

$notification_where_sql = implode(' AND ', $notification_conditions);
$per_page = 10;
$notification_page = max(1, (int) ($_GET['page'] ?? 1));
$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE $notification_where_sql");
$count_stmt->bind_param($notification_filter_types, ...$notification_filter_params);
$count_stmt->execute();
$total_filtered_notifications = (int) ($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();
$total_pages = max(1, (int) ceil($total_filtered_notifications / $per_page));
$notification_page = min($notification_page, $total_pages);
$offset = ($notification_page - 1) * $per_page;

$notification_query = "SELECT * FROM notifications WHERE $notification_where_sql ORDER BY id DESC LIMIT ? OFFSET ?";
$notification_stmt = $conn->prepare($notification_query);
$notification_query_types = $notification_filter_types . 'ii';
$notification_query_params = array_merge($notification_filter_params, [$per_page, $offset]);
$notification_stmt->bind_param($notification_query_types, ...$notification_query_params);
$notification_stmt->execute();
$result = $notification_stmt->get_result();
$notification_groups = [
    'Earlier' => [],
    'Today' => [],
    'Yesterday' => []
];
if ($result) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    while ($notification_row = $result->fetch_assoc()) {
        $notification_date = date('Y-m-d', strtotime($notification_row['created_at']));
        $group_name = $notification_date === $today ? 'Today' : ($notification_date === $yesterday ? 'Yesterday' : 'Earlier');
        $notification_groups[$group_name][] = $notification_row;
    }
}
$notification_stmt->close();
$total_result = $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE user_id = $user_id");
$total_notifications = $total_result ? (int) $total_result->fetch_assoc()['total'] : 0;
$latest_result = $conn->query("SELECT id FROM notifications WHERE user_id = $user_id ORDER BY id DESC LIMIT 1");
$latest_notification_id = $latest_result ? (int) ($latest_result->fetch_assoc()['id'] ?? 0) : 0;
$unread_result = $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE user_id = $user_id AND status = 'unread'");
$unread_notification_count = $unread_result ? (int) $unread_result->fetch_assoc()['total'] : 0;
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
$filter_query_params = [
    'date_filter' => $date_filter,
    'type' => $type_filter,
    'read' => $read_filter,
];
if ($date_filter === 'custom' && $custom_start !== '') {
    $filter_query_params['start_date'] = $custom_start;
}
if ($date_filter === 'custom' && $custom_end !== '') {
    $filter_query_params['end_date'] = $custom_end;
}
$pagination_base_url = 'notifications.php?' . http_build_query($filter_query_params) . '&page=';
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'latest_id' => $latest_notification_id,
        'unread_count' => $unread_notification_count,
        'total' => $total_notifications
    ]);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications - Shop</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #fff; color: #101828; display: flex; }
        .main-content { margin-left: 260px; width: calc(100% - 260px); min-height: 100vh; padding: 32px 40px; }
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; }
        .title-wrap { display: flex; align-items: center; gap: 14px; }
        .title-icon { width: 54px; height: 54px; border-radius: 14px; background: #fff0f0; color: #ed1c24; display: grid; place-items: center; }
        .subtitle { color: #667085; font-size: 13px; }
        .mark-read { border: 1px solid #dfe3e8; background: #fff; border-radius: 7px; padding: 11px 16px; color: #344054; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .mark-read:hover { border-color: #ed1c24; color: #ed1c24; }
        .header-actions { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; }
        .top-controls { display: flex; position: relative; z-index: 100; overflow: visible; align-items: center; justify-content: flex-start; gap: 12px; margin: 0 auto 18px; width: calc(100% - 56px); max-width: 1120px; }
        .filter-bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: flex-start; position: relative; z-index: 101; overflow: visible; }
        .title-wrap h1 { font-size: 35px; }
        .filter-select, .filter-input {
            min-width: 170px;
            padding: 10px 12px;
            border: 1px solid #dfe3e8;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.7);
            color: #1f2937;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 8px 22px rgba(52, 72, 96, 0.04);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: linear-gradient(45deg, transparent 50%, #667085 50%), linear-gradient(135deg, #667085 50%, transparent 50%);
            background-position: calc(100% - 16px) calc(50% - 2px), calc(100% - 11px) calc(50% - 2px);
            background-size: 5px 5px, 5px 5px;
            background-repeat: no-repeat;
            padding-right: 34px;
        }
        .filter-select:hover, .filter-input:hover {
            border-color: rgba(211, 47, 47, 0.35);
            background-color: #fffafa;
            box-shadow: 0 8px 20px rgba(211, 47, 47, 0.08);
            transform: translateY(-1px);
        }
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: #d32f2f;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.12);
            background-color: #fff;
        }
        .filter-select option { color: #1f2937; }
        .custom-range { display: none; align-items: center; gap: 10px; margin-left: 0; padding: 10px 12px; border: 1px solid #e1e5eb; border-radius: 8px; background: #fff; box-shadow: 0 8px 20px rgba(52, 72, 96, 0.12); position: absolute; top: calc(100% + 8px); left: 0; z-index: 10; white-space: nowrap; }
        .custom-range.is-visible { display: flex; }
        .custom-range .date-input-wrap { position: relative; }
        .custom-range .date-input-wrap input { width: 135px; padding: 9px 10px; border: 1px solid #dfe3e8; border-radius: 8px; background: #fff; color: transparent; font: inherit; -webkit-appearance: auto; -moz-appearance: auto; appearance: auto; }
        .custom-range .date-input-wrap.has-value input { color: #1f2937; }
        .custom-range .date-input-wrap span { position: absolute; top: 50%; left: 10px; transform: translateY(-50%); color: #64748b; font-size: 13px; pointer-events: none; }
        .custom-range .date-input-wrap.has-value span { display: none; }
        .custom-range .apply-date-filter { padding: 9px 16px; border: 0; border-radius: 8px; color: #fff; background: #8B1E2D; font-weight: 600; cursor: pointer; }
        .custom-range .apply-date-filter:disabled { color: #9ca3af; background: #eef0f2; cursor: not-allowed; }
        .notification-list { border: 0; overflow: visible; }
        .notif-card { min-height: 98px; display: grid; grid-template-columns: 24px 56px 1fr 125px; align-items: center; gap: 12px; padding: 16px 20px 16px 14px; border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; box-shadow: 0 8px 22px rgba(52, 72, 96, 0.07); transition: all 0.2s ease; }
        .notif-link { display: grid; color: inherit; text-decoration: none; cursor: pointer; }
        .notif-card.notif-link:hover,
        .notif-card.notif-link:focus-visible { border-color: rgba(211, 47, 47, 0.35); background: #fffafa; box-shadow: 0 12px 26px rgba(52, 72, 96, 0.13); transform: translateY(-1px); position: relative; z-index: 1; }
        .notif-card.hidden { display: none; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; background: #c5cad2; }
        .notif-card.unread { border-color: #e5e7eb; }
        .notif-card.unread .status-dot { background: #ed1c24; box-shadow: 0 0 0 4px rgba(237, 28, 36, 0.08); }
        .notif-card.unread .notif-title { color: #141821; }
        .notif-card.unread .notif-message { color: #6b7280; }
        .notif-icon { width: 56px; height: 56px; border-radius: 50%; background: #fff0f0; color: #ed1c24; display: grid; place-items: center; }
        .notif-icon i { font-size: 29px; }
        .notif-title { font-size: 14px; font-weight: 700; margin-bottom: 8px; }
        .notif-message { color: #475467; font-size: 13px; }
        .notif-time { color: #667085; font-size: 12px; text-align: right; line-height: 1.5; }
        .empty-state { text-align: center; padding: 60px 20px; color: #98a2b3; }
        .list-end { display: flex; align-items: center; justify-content: center; gap: 14px; padding: 22px; color: #98a2b3; font-size: 12px; }
        .list-end::before, .list-end::after { content: ''; width: 36px; height: 1px; background: #e4e7ec; }
        .notification-group { margin-bottom: 24px; }
        .notification-group-title { margin: 0 0 10px; color: #344054; font-size: 14px; }
        .notification-group-list { display: grid; gap: 14px; width: calc(100% - 56px); max-width: 1120px; margin: 0 auto; background: transparent; }
        .notification-swipe-row { position: relative; overflow: hidden; border-radius: 14px; touch-action: pan-y; }
        .notification-delete-button { position: absolute; top: 0; right: 0; bottom: 0; z-index: 0; width: 82px; display: inline-flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; border: 0; color: #fff; background: #b42318; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; opacity: 0; pointer-events: none; transition: opacity 0.18s ease; }
        .notification-delete-button .material-icons-outlined { font-size: 21px; }
        .notification-swipe-row.is-swiped .notification-delete-button { opacity: 1; pointer-events: auto; }
        .notification-swipe-row .notif-card { position: relative; z-index: 1; transform: translateX(0); transition: transform 0.22s ease, border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease; }
        .notification-swipe-row.is-swiped .notif-card { transform: translateX(-82px); }
        .bulk-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
        .bulk-button { border: 1px solid #dfe3e8; background: #fff; border-radius: 7px; padding: 9px 12px; color: #344054; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .bulk-button:hover { border-color: #ed1c24; color: #ed1c24; }
        .bulk-button.delete:hover { border-color: #b42318; color: #b42318; }
        .notif-content { min-width: 0; color: inherit; text-decoration: none; }
        .notification-group.is-hidden { display: none; }
        .empty-filter { display: none; text-align: center; padding: 36px 20px; color: #98a2b3; }
        .empty-filter.is-visible { display: block; }
        .notification-pager { display: flex; align-items: center; justify-content: center; gap: 8px; width: calc(100% - 56px); max-width: 1120px; margin: 20px auto 10px; padding-top: 8px; flex-wrap: wrap; }
        .pager-link { min-width: 38px; height: 38px; padding: 0 8px; border: 1px solid #e0e6f0; border-radius: 8px; background: #fff; color: #4b5563; display: inline-flex; align-items: center; justify-content: center; text-align: center; text-decoration: none; font-size: 14px; font-weight: 600; transition: all 0.2s ease; }
        .pager-link:hover { border-color: #d32f2f; color: #d32f2f; background: #fff5f5; }
        .pager-link.active { background: #d32f2f; color: #fff; border-color: #d32f2f; box-shadow: 0 4px 12px rgba(211, 47, 47, 0.2); }
        .pager-link.disabled { border: 0; background: none; color: #010813; cursor: default; pointer-events: none; }
        @media (max-width: 700px) { .main-content { margin-left: 0; width: 100%; padding: 24px 16px; } .page-header { align-items: flex-start; gap: 15px; flex-direction: column; } .header-actions { width: 100%; justify-content: flex-start; } .notification-group-list { width: 100%; } .notif-card { grid-template-columns: 20px 45px 1fr; padding: 14px 10px; } .notif-icon { width: 45px; height: 45px; } .notif-icon i { font-size: 23px; } .notif-time { grid-column: 3; text-align: left; } .custom-range { max-width: calc(100vw - 32px); flex-wrap: wrap; white-space: normal; } }
    </style>
</head>
<body>

    <form id="notificationBulkForm" method="POST" onsubmit="return confirmNotificationBulkAction(event)">
        <input type="hidden" name="notification_bulk_csrf_token" value="<?= htmlspecialchars($notification_bulk_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
    </form>
    <form id="notificationDeleteForm" method="POST">
        <input type="hidden" name="notification_bulk_csrf_token" value="<?= htmlspecialchars($notification_bulk_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="notification_action" value="delete_one">
        <input type="hidden" name="notification_id" id="notificationToDelete" value="">
    </form>
    <?php include 'sidebar_user.php'; ?>

    <div class="main-content">
        
        <div class="page-header">
            <div class="title-wrap"><div><h1>Notifications</h1><p class="subtitle">Stay updated with your latest activity.</p></div></div>
            <div class="header-actions">
                <?php if ($total_notifications > 0): ?>
                    <div class="bulk-actions">
                        <button type="submit" form="notificationBulkForm" name="notification_action" value="mark_all_read" class="bulk-button">
                            <i class="material-icons-outlined" aria-hidden="true">done</i> Mark all as read
                        </button>
                        <button type="submit" form="notificationBulkForm" name="notification_action" value="delete_all" class="bulk-button delete">
                            <i class="material-icons-outlined" aria-hidden="true">delete</i> Delete all
                        </button>
                    </div>
                <?php endif; ?>
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
                    <div class="date-input-wrap">
                        <input id="customStart" name="start_date" class="filter-input" type="date" aria-label="Start date" value="<?= htmlspecialchars($custom_start, ENT_QUOTES, 'UTF-8') ?>">
                        <span>Start date</span>
                    </div>
                    <div class="date-input-wrap">
                        <input id="customEnd" name="end_date" class="filter-input" type="date" aria-label="End date" value="<?= htmlspecialchars($custom_end, ENT_QUOTES, 'UTF-8') ?>">
                        <span>End date</span>
                    </div>
                    <button type="submit" class="apply-date-filter" id="applyCustomRange" <?= ($custom_start !== '' || $custom_end !== '') ? '' : 'disabled' ?>>Apply</button>
                </div>
            </form>
        </div>

        <?php if($total_filtered_notifications > 0): ?>
            <div class="notification-list" id="notification-list">
                <?php foreach ($notification_groups as $group_name => $group_rows): ?>
                    <?php if ($group_rows): ?>
                        <section class="notification-group" data-group="<?= strtolower($group_name) ?>">
                            <div class="notification-group-list">
                            <?php foreach ($group_rows as $row): ?>
                                <?php
                                    $status = $row['status'] ?? 'read';
                                    $message = htmlspecialchars($row['message']);
                                    $icon = stripos($row['message'], 'order') !== false ? 'shopping_cart' : (stripos($row['message'], 'stock') !== false || stripos($row['message'], 'product') !== false ? 'inventory_2' : 'notifications');
                                    $created = strtotime($row['created_at']);
                                ?>
                                <?php $notification_type = notification_type_from_message($row['message']); ?>
                                <div class="notification-swipe-row">
                                    <button type="button" class="notification-delete-button" data-notification-id="<?= (int) $row['id'] ?>" aria-label="Delete notification">
                                        <i class="material-icons-outlined" aria-hidden="true">delete</i>
                                        <span>Delete</span>
                                    </button>
                                    <a id="notification-<?= (int) $row['id'] ?>" class="notif-card notif-link <?= htmlspecialchars($status) ?>" data-status="<?= htmlspecialchars($status) ?>" data-type="<?= htmlspecialchars($notification_type) ?>" data-created-at="<?= htmlspecialchars(date('Y-m-d H:i:s', $created)) ?>" href="notifications.php?notification_id=<?= (int) $row['id'] ?>">
                                        <span class="status-dot" aria-label="Unread notification"></span>
                                        <div class="notif-icon"><i class="material-icons-outlined"><?= $icon ?></i></div>
                                        <div class="notif-content">
                                            <p class="notif-title"><?= $message ?></p>
                                            <p class="notif-message"><?= $status === 'unread' ? 'You have a new notification.' : 'Your notification has been viewed.' ?></p>
                                        </div>
                                        <time class="notif-time"><?= date('M d, Y', $created) ?><br><?= date('h:i A', $created) ?></time>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php if ($total_filtered_notifications > $per_page): ?>
                <nav class="notification-pager" aria-label="Notification pages">
                    <?php if ($notification_page > 1): ?><a class="pager-link" href="<?= htmlspecialchars($pagination_base_url . ($notification_page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?>
                    <?php foreach ($page_items as $page_item): ?>
                        <?php if ($page_item === '...'): ?>
                            <span class="pager-link disabled" aria-hidden="true">...</span>
                        <?php else: ?>
                            <a class="pager-link <?= $page_item === $notification_page ? 'active' : '' ?>" href="<?= htmlspecialchars($pagination_base_url . $page_item, ENT_QUOTES, 'UTF-8') ?>" <?= $page_item === $notification_page ? 'aria-current="page"' : '' ?>><?= $page_item ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($notification_page < $total_pages): ?><a class="pager-link" href="<?= htmlspecialchars($pagination_base_url . ($notification_page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php else: ?><div class="notification-list"><div class="empty-state"><i class="material-icons-outlined" style="font-size:42px">notifications_off</i><p><?= $total_notifications > 0 ? 'No notifications found for these filters.' : 'No notifications yet.' ?></p></div></div><?php endif; ?>

    </div>

<script>
    const timeFilter = document.getElementById('timeFilter');
    const typeFilter = document.getElementById('typeFilter');
    const readFilter = document.getElementById('readFilter');
    const customRange = document.getElementById('customRange');
    const customStart = document.getElementById('customStart');
    const customEnd = document.getElementById('customEnd');
    const applyCustomRange = document.getElementById('applyCustomRange');

    function updateCustomApplyState() {
        const hasDate = customStart.value || customEnd.value;
        const invalidRange = customStart.value && customEnd.value && customStart.value > customEnd.value;
        applyCustomRange.disabled = !hasDate || invalidRange;
    }

    function updateCustomDateLabels() {
        [customStart, customEnd].forEach(function (input) {
            input.closest('.date-input-wrap').classList.toggle('has-value', input.value !== '');
        });
    }

    function updateCustomRangeState() {
        const isCustom = timeFilter.value === 'custom';
        customRange.classList.toggle('is-visible', isCustom);
        customStart.required = false;
        customEnd.required = false;
        updateCustomDateLabels();
        updateCustomApplyState();
    }

    timeFilter.addEventListener('change', function () {
        if (timeFilter.value === 'custom') {
            customRange.classList.add('is-visible');
            updateCustomApplyState();
            return;
        }

        customStart.value = '';
        customEnd.value = '';
        updateCustomDateLabels();
        document.getElementById('notificationFilters').submit();
    });

    [typeFilter, readFilter].forEach(function (select) {
        select.addEventListener('change', function () {
            document.getElementById('notificationFilters').submit();
        });
    });

    [customStart, customEnd].forEach(function (input) {
        input.addEventListener('input', function () {
            updateCustomDateLabels();
            updateCustomApplyState();
        });
        input.addEventListener('change', function () {
            updateCustomDateLabels();
            updateCustomApplyState();
        });
    });

    updateCustomRangeState();

    function confirmNotificationBulkAction(event) {
        if (event.submitter && event.submitter.value === 'delete_all') {
            return window.confirm('Delete all notifications? This cannot be undone.');
        }
        return true;
    }

    const notificationState = {
        latestId: <?= $latest_notification_id ?>,
        unreadCount: <?= $unread_notification_count ?>,
        total: <?= $total_notifications ?>
    };

    async function refreshNotificationState() {
        try {
            const response = await fetch('notifications.php?format=json', { cache: 'no-store' });
            if (!response.ok) return;
            const state = await response.json();
            if (state.latest_id !== notificationState.latestId || state.unread_count !== notificationState.unreadCount || state.total !== notificationState.total) {
                window.location.reload();
            }
        } catch (error) {
            // A failed background refresh should not interrupt notification use.
        }
    }

    window.setInterval(refreshNotificationState, 10000);

</script>
</body>
</html>