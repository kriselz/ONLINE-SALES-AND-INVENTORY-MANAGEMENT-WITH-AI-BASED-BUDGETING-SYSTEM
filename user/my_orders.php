<?php
include 'config.php';
date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Siguraduhing naka-login ang user
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$user_id = (int)$_SESSION['user_id'];
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date_filter'] ?? 'all';
$custom_start = $_GET['start_date'] ?? '';
$custom_end = $_GET['end_date'] ?? '';
$allowed_statuses = ['pending', 'processing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled'];
$allowed_date_filters = ['all', 'yesterday', 'week', 'month', 'custom'];

if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = '';
}
if (!in_array($date_filter, $allowed_date_filters, true)) {
    $date_filter = 'all';
}
if (($custom_start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_start))
    || ($custom_end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_end))
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

$filter_conditions = ['user_id = ?'];
$filter_types = 'i';
$filter_params = [$user_id];
if ($status_filter !== '') {
    $filter_conditions[] = 'LOWER(TRIM(status)) = ?';
    $filter_types .= 's';
    $filter_params[] = $status_filter;
}
if ($date_filter === 'yesterday') {
    $filter_conditions[] = 'DATE(order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)';
} elseif ($date_filter === 'week') {
    $filter_conditions[] = 'order_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
} elseif ($date_filter === 'month') {
    $filter_conditions[] = 'order_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)';
} elseif ($date_filter === 'custom') {
    if ($custom_start !== '') {
        $filter_conditions[] = 'order_date >= ?';
        $filter_types .= 's';
        $filter_params[] = $custom_start;
    }
    if ($custom_end !== '') {
        $filter_conditions[] = 'order_date < DATE_ADD(?, INTERVAL 1 DAY)';
        $filter_types .= 's';
        $filter_params[] = $custom_end;
    }
}
$where_clause = implode(' AND ', $filter_conditions);

$per_page = 10;
$order_current_page = max(1, (int)($_GET['page'] ?? 1));

$count_query = "SELECT COUNT(*) as total FROM orders WHERE $where_clause";

$count_stmt = $conn->prepare($count_query);
$count_bind_params = [$filter_types];
foreach ($filter_params as $key => $value) {
    $count_bind_params[] = &$filter_params[$key];
}
call_user_func_array([$count_stmt, 'bind_param'], $count_bind_params);
$count_stmt->execute();
$total_orders = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$order_total_pages = max(1, (int)ceil((int)$total_orders / $per_page));
if ($order_current_page > $order_total_pages) {
    $order_current_page = $order_total_pages;
}
$order_pagination_items = [];
if ($order_total_pages <= 5) {
    $order_pagination_items = range(1, $order_total_pages);
} elseif ($order_current_page <= 3) {
    $order_pagination_items = [1, 2, 3, '...', $order_total_pages];
} elseif ($order_current_page >= $order_total_pages - 2) {
    $order_pagination_items = [1, '...', $order_total_pages - 2, $order_total_pages - 1, $order_total_pages];
} else {
    $order_pagination_items = [1, '...', $order_current_page - 1, $order_current_page, $order_current_page + 1, '...', $order_total_pages];
}
$offset = ($order_current_page - 1) * $per_page;

// Kunin ang mga orders ng user kasama ang unang item preview
$orders_query = "SELECT o.*,
                          (SELECT COUNT(*) FROM order_items oi_count WHERE oi_count.order_id = o.id) AS item_count
                 FROM orders o
                 WHERE $where_clause";
$orders_query .= " ORDER BY id DESC LIMIT ? OFFSET ?";

$orders_stmt = $conn->prepare($orders_query);
$orders_bind_params = [$filter_types . 'ii'];
foreach ($filter_params as $key => $value) {
    $orders_bind_params[] = &$filter_params[$key];
}
$orders_bind_params[] = &$per_page;
$orders_bind_params[] = &$offset;
call_user_func_array([$orders_stmt, 'bind_param'], $orders_bind_params);
$orders_stmt->execute();
$orders = $orders_stmt->get_result();

$orders_for_display = [];
while ($row = $orders->fetch_assoc()) {
    $orders_for_display[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders</title>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: Arial, 'Segoe UI', sans-serif;
            display: flex;
            background: #f4f6f8;
            overflow: hidden;
        }

        .my-orders-page {
            display: block !important;
            overflow-x: hidden;
            overflow-y: auto;
            padding-left: 292px;
        }

        body.sidebar-collapsed.my-orders-page {
            padding-left: 116px;
        }

        .my-orders-page .content {
            height: auto !important;
            min-height: calc(100vh - 72px);
            overflow: visible !important;
            padding: 36px 42px 32px !important;
        }

        /* CONTENT AREA - Adjusted for sidebar width */
        .content {
            min-height: 100vh;
            height: calc(100vh - 0px);
            overflow-y: auto !important;
            overflow-x: hidden;
        }

        .my-orders-page > .content {
            box-sizing: border-box;
            display: block;
            min-width: 0;
            flex: none;
            margin-left: 0 !important;
            width: auto !important;
            padding: 36px 42px 32px !important;
        }

        html body.my-orders-page.sidebar-collapsed > .content {
            margin-left: 0 !important;
            width: auto !important;
        }

        .orders-page-heading {
            display: flex;
            align-items: center;
            gap: 16px;
            width: calc(100% - 56px);
            max-width: 1120px;
            margin: 0 auto 24px;
        }

        .orders-page-icon {
            width: 52px;
            height: 52px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: #f1e5e2;
            color: #a52838;
            font-size: 26px;
            box-shadow: 0 8px 18px rgba(120, 20, 35, 0.18);
        }

        .orders-page-heading h1 {
            margin: 0;
            color: #1a202c;
            font-size: 35px;
            line-height: 1.1;
        }

        .orders-page-heading p {
            margin: 4px 0 0;
            color: #9ca3af;
            font-size: 15px;
        }

        .orders-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: calc(100% - 56px);
            max-width: 1120px;
            margin: 0 auto 18px;
            position: relative;
            z-index: 5;
        }

        .date-group-label {
            width: calc(100% - 56px);
            max-width: 1120px;
            margin: 20px auto 10px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .status-filter {
            min-width: 170px;
            padding: 10px 34px 10px 12px;
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
        }

        .orders-filters {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }

        .date-filter {
            min-width: 170px;
        }

        .custom-date-fields {
            display: none;
            gap: 10px;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid #e1e5eb;
            border-radius: 8px;
            background: #fff;
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            z-index: 10;
            box-shadow: 0 8px 20px rgba(52, 72, 96, 0.12);
            white-space: nowrap;
        }

        .custom-date-fields.visible {
            display: flex;
        }

        .custom-date-fields input {
            width: 135px;
            padding: 9px 10px;
            border: 1px solid #dfe3e8;
            border-radius: 8px;
            color: #1f2937;
            font: inherit;
        }

        .date-input-wrap {
            position: relative;
        }

        .date-input-wrap input {
            color: transparent;
        }

        .date-input-wrap input:valid {
            color: #1f2937;
        }

        .date-input-wrap span {
            position: absolute;
            top: 50%;
            left: 10px;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 13px;
            pointer-events: none;
        }

        .date-input-wrap input:valid + span {
            display: none;
        }

        .custom-date-fields label {
            display: none;
        }

        .custom-date-fields .apply-date-filter {
            padding: 9px 16px;
            border: 0;
            border-radius: 8px;
            color: #fff;
            background: #8B1E2D;
            font-weight: 600;
            cursor: pointer;
        }

        .custom-date-fields .apply-date-filter:disabled {
            color: #9ca3af;
            background: #eef0f2;
            cursor: not-allowed;
        }

        .status-filter:hover {
            border-color: rgba(211, 47, 47, 0.35);
            background-color: #fffafa;
            box-shadow: 0 8px 20px rgba(211, 47, 47, 0.08);
            transform: translateY(-1px);
        }

        .status-filter:focus {
            outline: none;
            border-color: #d32f2f;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.12);
            background-color: #fff;
        }

        .status-filter option {
            color: #1f2937;
        }

        .order-item-preview {
            margin: 8px 0 6px;
        }

        .order-meta {
            color: #718096;
            font-size: 12px;
        }

        /* ORDER BOX */
        .order-box {
            position: relative;
            width: calc(100% - 56px);
            max-width: 1120px;
            margin: 0 auto 14px;
            padding: 12px 18px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(52, 72, 96, 0.07);
            transition: all 0.2s ease;
            display: block;
        }

        .order-card-link {
            display: block;
            width: calc(100% - 56px);
            max-width: 1120px;
            margin: 0 auto 14px;
            text-decoration: none;
            color: inherit;
        }

        .order-card-status {
            display: flex;
            align-items: center;
            position: absolute;
            top: 50%;
            right: 18px;
            transform: translateY(-50%);
            z-index: 1;
        }

        .order-card-link:hover .order-box {
            border-color: rgba(211, 47, 47, 0.35);
            background: #fffafa;
            box-shadow: 0 12px 26px rgba(52, 72, 96, 0.13);
            transform: translateY(-1px);
        }

        .empty-state-card {
            width: calc(100% - 56px);
            max-width: 1120px;
            margin: 0 auto;
            padding: 60px 20px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(52, 72, 96, 0.07);
            text-align: center;
        }

        .order-box:hover {
            border-color: rgba(211, 47, 47, 0.35);
            background: #fffafa;
            box-shadow: 0 12px 26px rgba(52, 72, 96, 0.13);
            transform: translateY(-1px);
        }

        /* HEADER */
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }

        .order-link {
            text-decoration: none;
            color: #1a2333;
            font-size: 1rem;
            font-weight: 700;
        }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
            width: calc(100% - 56px);
            max-width: 1120px;
            margin: 20px auto 10px;
            padding-top: 8px;
        }

        .pagination-btn {
            min-width: 43px;
            height: 42px;
            padding: 0 10px;
            border: 1px solid #e0e6f0;
            border-radius: 10px;
            background: #fff;
            color: #4b5563;
            text-decoration: none;
            font-size: 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .pagination-btn:hover {
            border-color: #d32f2f;
            color: #d32f2f;
            background: #fff5f5;
        }

        .pagination-btn.active {
            background: #d32f2f;
            border-color: #d32f2f;
            color: #fff;
            box-shadow: 0 4px 12px rgba(211, 47, 47, 0.2);
        }

        .pagination-btn.disabled {
            border: 0;
            background: none;
            color: #010813;
            cursor: default;
            pointer-events: none;
        }

        .my-orders-page .orders-page-heading,
        .my-orders-page .orders-toolbar,
        .my-orders-page .date-group-label,
        .my-orders-page .order-card-link,
        .my-orders-page .order-box,
        .my-orders-page .empty-state-card,
        .my-orders-page .pagination {
            width: 100% !important;
            max-width: none !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }

        .order-link:hover {
            color: #d32f2f;
        }

        /* STATUS BADGE */
        .badge {
            padding: 6px 14px;
            border-radius: 50px;
            color: white;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .order-card-status .badge {
            padding: 5px 11px;
            color: #344a66;
            background: #f0f3f7;
        }

        .order-card-status .badge.pending {
            background: #fff3d3;
            color: #b37a0b;
        }

        .order-card-status .badge.processing {
            background: #e5f1ff;
            color: #2672bd;
        }

        .order-card-status .badge.shipped {
            background: #ffedd5;
            color: #c2410c;
        }

        .order-card-status .badge.ready_for_pickup {
            background: #eee8ff;
            color: #7550bd;
        }

        .order-card-status .badge.delivered {
            background: #e5f7ed;
            color: #278c58;
        }

        .order-card-status .badge.cancelled {
            background: #ffe8ee;
            color: #c83b5a;
        }

        @media (max-width: 1024px) {
            .content { margin-left: 0; }
            .orders-page-heading,
            .orders-toolbar,
            .order-box { width: 100%; }
            .orders-page-heading h1 { font-size: 32px; }
            .orders-filters { flex-wrap: wrap; }
            .custom-date-fields { flex-wrap: wrap; }
        }

        @media (max-width: 900px) {
            .my-orders-page {
                padding-left: 116px;
            }

            .my-orders-page > .content {
                flex: none;
                width: auto !important;
                padding: 24px 18px 20px !important;
            }

        }

        @media (max-width: 700px) {
            .my-orders-page > .content {
                flex-basis: 0;
                padding: 24px 18px 20px !important;
            }
        }
    </style>
</head>

<body class="my-orders-page">

<?php include 'sidebar_user.php'; ?>

<div class="content">

    <div class="orders-page-heading">
        <div>
            <h1>My Orders</h1>
            <p>Track and manage your orders.</p>
        </div>
    </div>

    <div class="orders-toolbar">
        <form method="get" class="orders-filters">
            <label for="date-filter" style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0);">Filter orders by date</label>
            <select id="date-filter" name="date_filter" class="status-filter date-filter" onchange="if (toggleCustomDates(this)) this.form.submit();">
                <option value="all" <?= $date_filter === 'all' ? 'selected' : '' ?>>All time</option>
                <option value="yesterday" <?= $date_filter === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                <option value="week" <?= $date_filter === 'week' ? 'selected' : '' ?>>This week</option>
                <option value="month" <?= $date_filter === 'month' ? 'selected' : '' ?>>This month</option>
                <option value="custom" <?= $date_filter === 'custom' ? 'selected' : '' ?>><?= htmlspecialchars($custom_date_label) ?></option>
            </select>
            <div class="custom-date-fields <?= ($date_filter === 'custom' && $custom_start === '' && $custom_end === '') ? 'visible' : '' ?>" id="custom-date-fields">
                <div class="date-input-wrap">
                    <input id="start-date" type="date" name="start_date" value="<?= htmlspecialchars($custom_start) ?>" aria-label="Start date">
                    <span>Start date</span>
                </div>
                <div class="date-input-wrap">
                    <input id="end-date" type="date" name="end_date" value="<?= htmlspecialchars($custom_end) ?>" aria-label="End date">
                    <span>End date</span>
                </div>
                <button type="submit" class="apply-date-filter" id="apply-date-filter" <?= ($custom_start !== '' || $custom_end !== '') && ($custom_start === '' || $custom_end === '' || $custom_start <= $custom_end) ? '' : 'disabled' ?>>Apply</button>
            </div>
            <label for="status-filter" style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0);">Filter orders by status</label>
            <select id="status-filter" name="status" class="status-filter" onchange="this.form.submit()">
                <option value="" <?= $status_filter === '' ? 'selected' : '' ?>>All Orders</option>
                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="processing" <?= $status_filter === 'processing' ? 'selected' : '' ?>>Processing</option>
                <option value="ready_for_pickup" <?= $status_filter === 'ready_for_pickup' ? 'selected' : '' ?>>Ready for Pickup</option>
                <option value="shipped" <?= $status_filter === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                <option value="delivered" <?= $status_filter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </form>
    </div>

    <?php if ($orders && $orders->num_rows > 0): ?>

        <?php
        $today = new DateTime('today');
        $yesterday = (clone $today)->modify('-1 day');
        $grouped_orders = [
            'Today' => [],
            'Yesterday' => [],
            'Older' => [],
        ];

        foreach ($orders_for_display as $order) {
            $order_date = isset($order['order_date']) ? new DateTime($order['order_date']) : null;
            if ($order_date) {
                $order_day = $order_date->format('Y-m-d');
                if ($order_day === $today->format('Y-m-d')) {
                    $grouped_orders['Today'][] = $order;
                } elseif ($order_day === $yesterday->format('Y-m-d')) {
                    $grouped_orders['Yesterday'][] = $order;
                } else {
                    $grouped_orders['Older'][] = $order;
                }
            } else {
                $grouped_orders['Older'][] = $order;
            }
        }

        foreach ($grouped_orders as $group_name => $group_orders):
            if (empty($group_orders)) {
                continue;
            }
        ?>
            <?php foreach ($group_orders as $order): ?>
                <?php $status_class = strtolower(trim((string)$order['status'])); ?>

                <a class="order-card-link" href="order_details.php?id=<?= $order['id'] ?>">
                    <div class="order-box">
                        <div class="order-header">
                            <h3 class="order-link">
                                <?= htmlspecialchars(formatOrderNumber($conn, (int)$order['id']), ENT_QUOTES, 'UTF-8') ?>
                            </h3>
                            <div class="order-card-status">
                                <span class="badge <?= $status_class ?>">
                                    <?= htmlspecialchars($status_class === 'ready_for_pickup' ? 'Ready for Pickup' : ucfirst(str_replace('_', ' ', $status_class)), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>

                        <p class="order-meta">
                            <?php if (isset($order['order_date'])): ?>
                                <?= date('M d, Y', strtotime($order['order_date'])) ?>
                                &nbsp;•&nbsp;
                                <?= date('h:i A', strtotime($order['order_date'])) ?>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </p>

                        <div class="order-item-preview">
                            <div class="order-meta">
                                <?= (int) $order['item_count'] ?> <?= (int) $order['item_count'] === 1 ? 'item' : 'items' ?>
                                &nbsp;•&nbsp; ₱<?= number_format((float) $order['total_amount'], 2) ?>
                            </div>
                        </div>

                    </div>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>

    <?php else: ?>
        <div class="empty-state-card">
            <?php if ($status_filter !== '' || $date_filter !== 'all'): ?>
                <p style="color: #718096;">No orders match these filters.</p>
            <?php else: ?>
                <p style="color: #718096;">You haven't placed any orders yet.</p>
                <a href="product_screen.php" style="color: #d32f2f; text-decoration: none; font-weight: 600; margin-top: 10px; display: block;">Start Shopping →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ((int)$total_orders > (int)$per_page): ?>
        <?php
        $order_pagination_params = ['date_filter' => $date_filter];
        if ($status_filter !== '') {
            $order_pagination_params['status'] = $status_filter;
        }
        if ($date_filter === 'custom' && $custom_start !== '') {
            $order_pagination_params['start_date'] = $custom_start;
        }
        if ($date_filter === 'custom' && $custom_end !== '') {
            $order_pagination_params['end_date'] = $custom_end;
        }
        $order_pagination_base_url = 'my_orders.php?' . http_build_query($order_pagination_params) . '&page=';
        ?>
        <nav class="pagination" aria-label="Order pages">
            <?php if ($order_current_page > 1): ?><a class="pagination-btn" href="<?= htmlspecialchars($order_pagination_base_url . ($order_current_page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?>
            <?php foreach ($order_pagination_items as $order_page_item): ?>
                <?php if ($order_page_item === '...'): ?>
                    <span class="pagination-btn disabled" aria-hidden="true">...</span>
                <?php else: ?>
                    <a class="pagination-btn <?= (int)$order_page_item === $order_current_page ? 'active' : '' ?>" href="<?= htmlspecialchars($order_pagination_base_url . $order_page_item, ENT_QUOTES, 'UTF-8') ?>" <?= (int)$order_page_item === $order_current_page ? 'aria-current="page"' : '' ?>><?= $order_page_item ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($order_current_page < $order_total_pages): ?><a class="pagination-btn" href="<?= htmlspecialchars($order_pagination_base_url . ($order_current_page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?>
        </nav>
    <?php endif; ?>

</div>

<script>
    function toggleCustomDates(select) {
        const fields = document.getElementById('custom-date-fields');
        const start = document.getElementById('start-date');
        const end = document.getElementById('end-date');
        const isCustom = select.value === 'custom';
        if (fields) {
            fields.classList.toggle('visible', isCustom);
        }
        if (!isCustom) {
            start.value = '';
            end.value = '';
        }
        updateCustomDateApplyState();
        return !isCustom;
    }

    function updateCustomDateApplyState() {
        const start = document.getElementById('start-date');
        const end = document.getElementById('end-date');
        const apply = document.getElementById('apply-date-filter');
        if (start && end && apply) {
            const hasDate = start.value !== '' || end.value !== '';
            const invalidRange = start.value !== '' && end.value !== '' && start.value > end.value;
            apply.disabled = !hasDate || invalidRange;
        }
    }

    document.querySelectorAll('#start-date, #end-date').forEach(function (input) {
        input.addEventListener('change', updateCustomDateApplyState);
    });

    const savedScrollPosition = sessionStorage.getItem('my-orders-scroll-position');
    if (savedScrollPosition !== null) {
        sessionStorage.removeItem('my-orders-scroll-position');
        window.scrollTo(0, Number(savedScrollPosition) || 0);
    }
    window.setInterval(function () {
        const focusedField = document.activeElement && /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName);
        if (document.hidden || focusedField) {
            return;
        }
        sessionStorage.setItem('my-orders-scroll-position', String(window.scrollY));
        window.location.reload();
    }, 30000);
</script>
</body>
</html>