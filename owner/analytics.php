<?php
session_start();
include 'config.php';
include 'functions.php';

// CHECK LOGIN AND ADMIN ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: login_a.php");
    exit();
}

// Get analytics data
$period = $_GET['period'] ?? 'weekly';
$page = $_GET['page'] ?? 'overview';
$allowedPeriods = ['weekly', 'monthly', 'yearly', 'custom'];
$allowedPages = ['overview', 'products', 'customers', 'payment'];
if (!in_array($period, $allowedPeriods)) $period = 'weekly';
if (!in_array($page, $allowedPages)) $page = 'overview';
if (empty($_SESSION['ai_revenue_forecast_csrf'])) {
    $_SESSION['ai_revenue_forecast_csrf'] = bin2hex(random_bytes(32));
}
$aiRevenueForecastCsrf = $_SESSION['ai_revenue_forecast_csrf'];

$daysByPeriod = ['weekly' => 7, 'monthly' => 30, 'yearly' => 365];
$days = $daysByPeriod[$period] ?? 7;
$dateFromInput = trim($_GET['date_from'] ?? '');
$dateToInput = trim($_GET['date_to'] ?? '');
$dateFrom = DateTime::createFromFormat('!Y-m-d', $dateFromInput);
$dateTo = DateTime::createFromFormat('!Y-m-d', $dateToInput);
$dateFromValid = $dateFrom && $dateFrom->format('Y-m-d') === $dateFromInput;
$dateToValid = $dateTo && $dateTo->format('Y-m-d') === $dateToInput;
$rangeEndDate = date('Y-m-d');
$rangeStartDate = date('Y-m-d', strtotime($rangeEndDate . ' -' . ($days - 1) . ' days'));
if ($period === 'custom') {
    $rangeStartDate = $dateFromValid ? $dateFromInput : ($dateToValid ? $dateToInput : $rangeEndDate);
    $rangeEndDate = $dateToValid ? $dateToInput : $rangeStartDate;
    if ($rangeStartDate > $rangeEndDate) {
        [$rangeStartDate, $rangeEndDate] = [$rangeEndDate, $rangeStartDate];
    }
}
$customDateRangeLabel = 'Custom Range';
if ($period === 'custom' && $dateFromValid && $dateToValid) {
    $customDateRangeLabel = date('M j, Y', strtotime($rangeStartDate)) . ' - ' . date('M j, Y', strtotime($rangeEndDate));
} elseif ($period === 'custom' && $dateFromValid) {
    $customDateRangeLabel = date('M j, Y', strtotime($dateFromInput));
} elseif ($period === 'custom' && $dateToValid) {
    $customDateRangeLabel = 'Through ' . date('M j, Y', strtotime($dateToInput));
}
$payment_methods = [
    'cod' => 'COD',
    'gcash' => 'GCash',
    'bank' => 'Bank Transfer',
    'onsite' => 'Onsite Pharmacy',
    'unknown' => 'Other / Unknown'
];
$periodQuery = ['period' => $period];
if ($period === 'custom') {
    $periodQuery['date_from'] = $rangeStartDate;
    $periodQuery['date_to'] = $rangeEndDate;
}
$periodQueryString = http_build_query($periodQuery);

$sales_data = getSalesAnalytics($conn, $days, $rangeStartDate, $rangeEndDate);
$top_products = getTopProducts($conn, 10, $rangeStartDate, $rangeEndDate);
$order_stats = getOrderStats($conn, $rangeStartDate, $rangeEndDate);
$revenue_data = getRevenueByDate($conn, $rangeStartDate, $rangeEndDate . ' 23:59:59');
$top_customers = getTopCustomers($conn, 10, $rangeStartDate, $rangeEndDate);
$registered_users = getUsersCount($conn);
$orderItemRevenue = getOrderItemRevenueExpression();
$uniqueCustomersResult = $conn->query("
    SELECT COUNT(DISTINCT o.user_id) AS total_customers
    FROM orders o
    WHERE o.user_id IS NOT NULL
      AND LOWER(TRIM(COALESCE(o.status, ''))) <> 'cancelled'
      AND o.order_date >= '" . $conn->real_escape_string($rangeStartDate) . "'
      AND o.order_date < DATE_ADD('" . $conn->real_escape_string($rangeEndDate) . "', INTERVAL 1 DAY)
      AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id)
");
$totalUniqueCustomers = $uniqueCustomersResult
    ? (int)($uniqueCustomersResult->fetch_assoc()['total_customers'] ?? 0)
    : 0;

$payment_summary = $conn->query("SELECT
    LOWER(COALESCE(o.payment_method, 'unknown')) AS payment_method,
    COUNT(DISTINCT o.id) AS total_orders,
    COALESCE(SUM($orderItemRevenue), 0) AS total_amount
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
        WHERE o.order_date >= '" . $conn->real_escape_string($rangeStartDate) . "'
            AND o.order_date < DATE_ADD('" . $conn->real_escape_string($rangeEndDate) . "', INTERVAL 1 DAY)
      AND LOWER(COALESCE(o.status, '')) <> 'cancelled'
    GROUP BY LOWER(COALESCE(o.payment_method, 'unknown'))");
$payment_totals = [];
if ($payment_summary) {
    while ($payment_row = $payment_summary->fetch_assoc()) {
        $payment_totals[$payment_row['payment_method']] = $payment_row;
    }
}

$paymentStatusColumnReady = $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
$transactionColumnReady = $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_transaction_id VARCHAR(100) NULL");
$verifiedTransactionError = !$paymentStatusColumnReady || !$transactionColumnReady ? $conn->error : '';
$verifiedTransactions = $conn->query("
    SELECT o.id, o.order_date, o.payment_method, o.payment_transaction_id,
           COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(o.fullname), ''), u.username) AS customer_name,
           COALESCE(SUM($orderItemRevenue), 0) AS total_amount
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE LOWER(TRIM(COALESCE(o.payment_status, 'unpaid'))) = 'paid'
      AND LOWER(COALESCE(o.payment_method, '')) IN ('gcash', 'bank')
      AND LOWER(TRIM(COALESCE(o.status, ''))) <> 'cancelled'
      AND o.payment_transaction_id IS NOT NULL
      AND TRIM(o.payment_transaction_id) <> ''
      AND o.order_date >= '" . $conn->real_escape_string($rangeStartDate) . "'
      AND o.order_date < DATE_ADD('" . $conn->real_escape_string($rangeEndDate) . "', INTERVAL 1 DAY)
    GROUP BY o.id, o.order_date, o.payment_method, o.payment_transaction_id, customer_name
    ORDER BY o.order_date DESC, o.id DESC
");
if (!$verifiedTransactions && $verifiedTransactionError === '') {
    $verifiedTransactionError = $conn->error;
}

?>

<!DOCTYPE html>
<html>
<head>
<title>Dashboard Analytics</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f7f8fa; color: #20232a; min-height: 100vh; }
    .content { margin-left: 244px; padding: 28px 32px 42px; width: calc(100% - 244px); min-height: 100vh; }
    
    .container { max-width: none; margin: 0 auto; padding: 0; }
    
    .header { margin-bottom: 20px; }
    .header h1 { color: #20232a; font-size: 27px; letter-spacing: -0.2px; font-weight: 700; }
    .header p { color: #7b8189; font-size: 12px; margin-top: 4px; }

    .header-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 16px;
    }
    
    .header-actions {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        min-width: 0;
        margin-left: auto;
    }

    .analytics-header-controls {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
        min-width: 0;
        margin-left: 0;
    }

    .analytics-header-controls > form,
    .analytics-period-control {
        min-width: 0;
    }

    .analytics-header-controls select {
        width: 100%;
    }

    .analytics-period-control {
        position: relative;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .period-selector {
        display: flex;
        gap: 6px;
    }

    .period-selector select {
        min-width: 144px;
        height: 34px;
        padding: 0 10px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #fff;
        color: #4e5660;
        font-size: 12px;
        font-weight: 600;
    }

    .custom-date-range {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        z-index: 20;
        display: flex;
        width: max-content;
        max-width: calc(100vw - 28px);
        align-items: center;
        gap: 8px;
        padding: 10px 12px;
        background: #fff;
        border: 1px solid #e1e5ea;
        border-radius: 8px;
        box-shadow: 0 8px 18px rgba(28,32,38,.12);
    }
    .custom-date-range label { display:block; min-width:0; font-size:0; }
    .custom-date-range input { width:176px; min-width:0; height:42px; padding:0 10px; border:1px solid #dfe4e8; border-radius:7px; color:#4e5660; font:500 12px 'Segoe UI',Tahoma,sans-serif; }
    .custom-date-range button { flex:0 0 auto; height:42px; padding:0 16px; border:0; border-radius:7px; background:#ed1740; color:#fff; font-size:12px; font-weight:700; cursor:pointer; }
    
    .period-btn {
        padding: 8px 14px;
        border: 1px solid #e5e7eb;
        background: #fff;
        color: #4e5660;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .period-btn:hover { background: #f8fafc; }
    .period-btn.active { background: #ed1740; color: #fff; border-color: #ed1740; }
    
    .page-tabs {
        display: flex;
        gap: 6px;
        background: #fff;
        border: 1px solid #ebedf0;
        border-radius: 9px;
        padding: 5px;
        width: max-content;
        box-shadow: 0 2px 8px rgba(25,30,38,.04);
    }
    
    .page-tab {
        padding: 9px 13px;
        color: #68707a;
        text-decoration: none;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        border: none;
        background: none;
        transition: all 0.2s ease;
    }
    
    .page-tab:hover, .page-tab.active { background: #ed1740; color: #fff; }

    .page-tabs,
    .reports-tabs {
        display: flex;
        flex-wrap: nowrap;
        align-items: center;
        gap: 6px;
        width: max-content;
        max-width: 100%;
        overflow-x: auto;
        scrollbar-width: none;
        margin: 0 0 16px;
        padding: 5px;
        background: #fff;
        border: 1px solid #ebedf0;
        border-radius: 9px;
        box-shadow: 0 2px 8px rgba(25,30,38,.04);
    }

    .page-tabs::-webkit-scrollbar,
    .reports-tabs::-webkit-scrollbar { display: none; }

    .page-tabs .page-tab,
    .reports-tabs .reports-tab {
        display: inline-flex;
        flex: 0 0 auto;
        min-height: 34px;
        align-items: center;
        justify-content: center;
        padding: 6px 13px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.25;
        text-align: center;
        text-decoration: none;
        white-space: nowrap;
    }

    .reports-tabs > a.reports-tab:hover {
        background: #fff1f3;
        color: #ed1740;
    }

    .reports-tabs > a.reports-tab.active,
    .reports-tabs > a.reports-tab.active:hover,
    .reports-tabs > a.reports-tab.active:focus {
        border: 0 !important;
        border-bottom: 3px solid #ed1740 !important;
        border-radius: 0 !important;
        background: #fff !important;
        color: #a51c38 !important;
        box-shadow: none !important;
    }

    .page-tab-dropdown { display: flex; align-items: center; min-width: 0; }
    .page-tab-dropdown select {
        max-width: 100%;
        min-height: 34px;
        padding: 0 28px 0 10px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #fff;
        color: #68707a;
        font: 700 11px 'Segoe UI',Tahoma,sans-serif;
        cursor: pointer;
    }

    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
    
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }
    
    .metric-card {
        background: #fff;
        padding: 18px;
        border-radius: 10px;
        border: 1px solid #ebedf0;
        box-shadow: 0 2px 8px rgba(25,30,38,.04);
        transition: all 0.2s ease;
    }
    
    .metric-card:hover { box-shadow: 0 4px 16px rgba(25,30,38,.08); }
    
    body.analytics-page .metric-card { border: 1px solid #ebedf0 !important; }
    body.analytics-page .metric-card.revenue { border-top: 3px solid #ed1740 !important; }
    body.analytics-page .metric-card.orders { border-top: 3px solid #e9a316 !important; }
    body.analytics-page .metric-card.customers { border-top: 3px solid #3b82f6 !important; }
    body.analytics-page .metric-card.average { border-top: 3px solid #15955c !important; }
    body.analytics-page .metric-card.registered { border-top: 3px solid #eab308 !important; }
    
    .metric-label { color: #7b8189; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; }
    .metric-value { font-size: 27px; font-weight: 700; color: #20232a; margin-top: 8px; }
    .metric-change { font-size: 11px; color: #7b8189; margin-top: 6px; }

    .product-category-filter {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 14px;
        color: #68707a;
        font-size: 11px;
        font-weight: 700;
    }
    .product-category-filter select {
        min-height: 34px;
        padding: 0 28px 0 10px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #fff;
        color: #4e5660;
        font: 600 12px 'Segoe UI',Tahoma,sans-serif;
    }
    
    .revenue-trend-card {
        position: relative;
        overflow: hidden;
        margin-bottom: 24px;
        border-color: #e6e2f5;
        background: linear-gradient(135deg, #fff 0%, #fbfaff 100%);
    }

    .ai-forecast-heading {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:14px;
        margin-bottom:16px;
    }

    .ai-forecast-title { display:flex; align-items:center; gap:11px; min-width:0; }
    .ai-forecast-icon {
        display:grid;
        width:38px;
        height:38px;
        flex:0 0 38px;
        place-items:center;
        border:1px solid #e5dcff;
        border-radius:12px;
        background:linear-gradient(145deg,#f5f0ff,#e9e3ff);
        color:#7045c5;
    }
    .ai-forecast-icon svg { width:20px; height:20px; }
    .ai-forecast-title h3 { margin:0; color:#20232a; font-size:15px; font-weight:750; }
    .ai-forecast-title p { margin-top:4px; color:#7b8189; font-size:11px; line-height:1.4; }
    .ai-forecast-provider {
        flex:0 0 auto;
        padding:6px 10px;
        border:1px solid #e7e1f4;
        border-radius:999px;
        background:#f7f4ff;
        color:#6741a5;
        font-size:10px;
        font-weight:750;
        letter-spacing:.25px;
    }

    .ai-forecast-result {
        display:flex;
        align-items:flex-start;
        gap:9px;
        margin-top:14px;
        padding:12px 14px;
        border:1px solid #e9e5f2;
        border-radius:9px;
        background:rgba(255,255,255,.82);
    }
    .ai-forecast-indicator {
        width:9px;
        height:9px;
        flex:0 0 9px;
        margin-top:4px;
        border-radius:50%;
        background:#9a8ab8;
    }
    .ai-forecast-result.is-loading .ai-forecast-indicator {
        background:#7952c7;
        box-shadow:0 0 0 4px rgba(121,82,199,.12);
        animation:ai-forecast-pulse 1.4s ease-in-out infinite;
    }
    .ai-forecast-result.is-ready { border-color:#d6eadf; background:#fbfefc; }
    .ai-forecast-result.is-ready .ai-forecast-indicator { background:#15955c; }
    .ai-forecast-result.is-error { border-color:#f1d8dc; background:#fffafb; }
    .ai-forecast-result.is-error .ai-forecast-indicator { background:#c2414b; }
    .ai-forecast-copy { min-width:0; }
    .ai-forecast-label { display:block; margin-bottom:3px; color:#47404f; font-size:10px; font-weight:750; letter-spacing:.45px; text-transform:uppercase; }
    .forecast-note { margin:0; color:#70717a; font-size:12px; line-height:1.55; }
    .forecast-note.forecast-error { color:#b42318; }
    @keyframes ai-forecast-pulse {
        50% { box-shadow:0 0 0 7px rgba(121,82,199,.04); opacity:.65; }
    }

    .status-overview-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }

    .status-overview-grid .table-section { margin-bottom: 0; }
    
    .chart-card {
        background: #fff;
        padding: 18px;
        border-radius: 10px;
        border: 1px solid #ebedf0;
        box-shadow: 0 2px 8px rgba(25,30,38,.04);
    }
    
    .chart-card h3 {
        color: #20232a;
        margin-bottom: 16px;
        font-size: 14px;
        font-weight: 700;
    }
    
    .chart-container { position: relative; height: 280px; }

    .forecast-note {
        margin-top: 10px;
        color: #7b8189;
        font-size: 11px;
        line-height: 1.5;
    }

    .forecast-note.forecast-error { color: #b42318; }

    .table-section {
        background: #fff;
        padding: 18px;
        border-radius: 10px;
        border: 1px solid #ebedf0;
        box-shadow: 0 2px 8px rgba(25,30,38,.04);
        margin-bottom: 14px;
    }
    
    .table-section h3 {
        color: #20232a;
        margin-bottom: 14px;
        font-size: 14px;
        font-weight: 700;
    }

    .restock-section { margin-bottom: 24px; }
    .restock-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
    .restock-heading h3 { margin-bottom:4px; }
    .restock-heading p,.restock-empty { color:#7b8189; font-size:11px; line-height:1.5; }
    .restock-heading a { flex:0 0 auto; color:#a51c38; font-size:11px; font-weight:700; text-decoration:none; }
    .restock-heading a:hover { text-decoration:underline; }
    .restock-ai-badge { display:inline-flex; align-items:center; gap:5px; margin-left:7px; padding:4px 8px; border:1px solid #e7e1f4; border-radius:999px; background:#f7f4ff; color:#6741a5; font-size:9px; font-weight:750; vertical-align:2px; }
    .restock-method-note { margin:5px 0 14px; color:#81818a; font-size:10px; line-height:1.5; }
    .restock-product-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(245px,1fr)); gap:11px; }
    .restock-product-card { padding:14px; border:1px solid #eceaf1; border-radius:9px; background:#fff; }
    .restock-product-top { display:flex; align-items:flex-start; justify-content:space-between; gap:9px; }
    .restock-product-name { color:#292633; font-size:13px; font-weight:750; line-height:1.35; overflow-wrap:anywhere; }
    .restock-product-status { flex:0 0 auto; padding:4px 7px; border-radius:999px; background:#fff2df; color:#9a5c0c; font-size:9px; font-weight:750; }
    .restock-product-status.is-out { background:#ffe8ed; color:#b4233d; }
    .restock-product-demand { margin:13px 0 10px; }
    .restock-product-demand strong { color:#24212c; font-size:20px; letter-spacing:-.3px; }
    .restock-product-demand span { margin-left:5px; color:#7b8189; font-size:10px; }
    .restock-product-metrics { display:flex; flex-wrap:wrap; gap:6px; }
    .restock-product-metric { padding:5px 7px; border-radius:5px; background:#f6f5f8; color:#5f5b66; font-size:10px; }
    .restock-product-change { font-weight:750; }
    .restock-product-change.is-up { color:#a16207; }
    .restock-product-change.is-down { color:#168052; }
    .restock-product-insight { margin-top:11px; padding-top:10px; border-top:1px solid #f0eef3; color:#66636d; font-size:11px; line-height:1.55; }
    .restock-product-insight strong { color:#45414c; }
    .restock-forecast-status { padding:15px; border:1px dashed #e4e0ea; border-radius:8px; color:#77717f; font-size:11px; line-height:1.5; }
    .restock-forecast-status.is-error { border-color:#f1d8dc; background:#fffafb; color:#b42318; }
    .restock-forecast-status.is-empty { border-color:#d6eadf; background:#fbfefc; color:#46715a; }
    .restock-forecast-dates { margin:8px 0 10px; color:#726a7e; font-size:10px; font-weight:650; }
    
    .table-wrapper { overflow-x: auto; }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th {
        background: #fafbfc;
        padding: 10px 8px;
        text-align: left;
        font-weight: 700;
        color: #68707a;
        font-size: 9px;
        text-transform: uppercase;
        border-bottom: 1px solid #f0f1f3;
    }
    
    td {
        padding: 10px 8px;
        border-bottom: 1px solid #f0f1f3;
        font-size: 11px;
    }
    
    tr:hover { background: #f9fafc; }
    
    .status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 10px;
        font-weight: 700;
    }
    
    .status-pending { background: #fff5e0; color: #e9a316; }
    .status-seen { background: #f0f1f3; color: #4e5660; }
    .status-processing { background: #e8f4f8; color: #3b82f6; }
    .status-completed { background: #e8f7ef; color: #15955c; }
    
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #7b8189;
    }
    
    .empty-state-icon { font-size: 32px; margin-bottom: 10px; opacity: 0.5; }
    .empty-state-text { font-size: 12px; }
    
    @media (max-width: 1100px) {
        .status-overview-grid { grid-template-columns: 1fr; }
    }
    
    @media (max-width: 900px) {
        .analytics-header-controls { justify-content: flex-start; }
    }

    @media (max-width: 768px) {
        .metrics-grid { grid-template-columns: repeat(2, 1fr); }
        .header-toolbar { align-items: flex-start; flex-direction: column; }
        .restock-heading { flex-direction:column; }
        .header-actions { flex-direction: column; align-items: flex-start; }
        .page-tabs,
        .reports-tabs { width: 100%; }
        .analytics-header-controls { width: 100%; }
        .analytics-header-controls > form,
        .analytics-period-control { width: 100%; }
        .analytics-header-controls select,
        .period-selector select { width: 100%; min-width: 0; }
        .analytics-period-control { width: 100%; }
        .custom-date-range { right:auto; left:0; gap:6px; }
        .custom-date-range input { width:clamp(112px, 27vw, 176px); padding:0 6px; font-size:11px; }
        .custom-date-range button { height:40px; padding:0 12px; }
        .table-wrapper { min-width: 0; }
        table { min-width: 500px; }
    }

    body.analytics-page .content {
        box-sizing: border-box !important;
        margin-left: 320px !important;
        margin-right: 60px !important;
        width: calc(100% - 380px) !important;
        margin-top: 0 !important;
        padding: 104px 0 32px !important;
        min-width: 0 !important;
    }

    body.analytics-page.owner-sidebar-collapsed .content {
        margin-left: 144px !important;
        margin-right: 60px !important;
        width: calc(100% - 204px) !important;
    }

    @media (max-width: 1199px) and (min-width: 901px) {
        body.analytics-page .content {
            margin-left: 276px !important;
            margin-right: 24px !important;
            width: calc(100% - 300px) !important;
        }
        body.analytics-page.owner-sidebar-collapsed .content {
            margin-left: 100px !important;
            margin-right: 24px !important;
            width: calc(100% - 124px) !important;
        }
    }

    @media (max-width: 900px) {
        body.analytics-page .content {
            margin: 0 12px 0 84px !important;
            width: calc(100% - 96px) !important;
            padding: 72px 12px 24px !important;
        }
        body.analytics-page.owner-sidebar-collapsed .content {
            margin: 0 12px 0 84px !important;
            width: calc(100% - 96px) !important;
            padding: 72px 12px 24px !important;
        }
    }

    @media (max-width: 420px) {
        body.analytics-page .content,
        body.analytics-page.owner-sidebar-collapsed .content {
            margin: 0 10px 0 72px !important;
            width: calc(100% - 82px) !important;
            padding-left: 10px !important;
            padding-right: 10px !important;
        }
    }

    @media (min-width: 421px) and (max-width: 900px) {
        .header-toolbar,
        .header-actions,
        .analytics-header-controls { min-width: 0; }
    }

    @media (max-width: 420px) {
        .metrics-grid { grid-template-columns: minmax(0, 1fr); }
        .page-tabs,
        .reports-tabs { width: 100%; }
    }
</style>
</head>
<body class="analytics-page">

<?php include 'appbar_owner.php'; include 'sidebar_owner.php'; ?>

<div class="content">
<div class="container">
    <div class="header">
        <div class="header-toolbar">
        <nav class="reports-tabs" aria-label="Reports navigation">
            <a href="?page=overview&amp;<?php echo htmlspecialchars($periodQueryString, ENT_QUOTES); ?>" class="reports-tab active" aria-current="page">📈 Overview</a>
            <a href="staff_sales_report.php" class="reports-tab">💊 Pharma Sales</a>
            <a href="inventory_alerts.php?tab=alerts" class="reports-tab">⚠ Inventory Alerts</a>
            <a href="inventory_alerts.php?tab=reorders" class="reports-tab">📦 Reorders</a>
        </nav>
        <div class="header-actions">
            <div class="analytics-header-controls">
                <form class="page-tab-dropdown" method="get" aria-label="Analytics section">
                    <input type="hidden" name="period" value="<?php echo htmlspecialchars($period, ENT_QUOTES); ?>">
                    <?php if ($period === 'custom'): ?>
                    <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($rangeStartDate, ENT_QUOTES); ?>">
                    <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($rangeEndDate, ENT_QUOTES); ?>">
                    <?php endif; ?>
                    <select id="analyticsSection" name="page" onchange="this.form.submit()">
                        <option value="overview" <?php echo $page === 'overview' ? 'selected' : ''; ?>>🔥 All Categories</option>
                        <option value="products" <?php echo $page === 'products' ? 'selected' : ''; ?>>🔥 Products</option>
                        <option value="customers" <?php echo $page === 'customers' ? 'selected' : ''; ?>>👥 Customers</option>
                        <option value="payment" <?php echo $page === 'payment' ? 'selected' : ''; ?>>💳 Payments</option>
                    </select>
                </form>
                <div class="analytics-period-control">
                <form class="period-selector" method="get" aria-label="Analytics date range">
                    <input type="hidden" name="page" value="<?php echo htmlspecialchars($page, ENT_QUOTES); ?>">
                    <?php if ($period === 'custom'): ?>
                    <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($rangeStartDate, ENT_QUOTES); ?>">
                    <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($rangeEndDate, ENT_QUOTES); ?>">
                    <?php endif; ?>
                    <select id="analyticsPeriod" name="period" onchange="this.form.submit()">
                        <option value="weekly" <?php echo $period === 'weekly' ? 'selected' : ''; ?>>This Week</option>
                        <option value="monthly" <?php echo $period === 'monthly' ? 'selected' : ''; ?>>This Month</option>
                        <option value="yearly" <?php echo $period === 'yearly' ? 'selected' : ''; ?>>This Year</option>
                        <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>><?php echo htmlspecialchars($customDateRangeLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                    </select>
                </form>
                <?php if ($period === 'custom' && !$dateFromValid && !$dateToValid): ?>
                <form class="custom-date-range" method="get" aria-label="Custom analytics date range">
                    <input type="hidden" name="page" value="<?php echo htmlspecialchars($page, ENT_QUOTES); ?>">
                    <input type="hidden" name="period" value="custom">
                    <label for="analyticsDateFrom">From<input id="analyticsDateFrom" type="date" name="date_from" value="<?php echo $dateFromValid ? htmlspecialchars($dateFromInput, ENT_QUOTES) : ''; ?>"></label>
                    <label for="analyticsDateTo">To<input id="analyticsDateTo" type="date" name="date_to" value="<?php echo $dateToValid ? htmlspecialchars($dateToInput, ENT_QUOTES) : ''; ?>"></label>
                    <button type="submit">Apply</button>
                </form>
                <?php endif; ?>
                </div>
            </div>
        </div>
        </div>
        <h1>📊 Sales Analytics Dashboard</h1>
        <p>Performance metrics and business insights</p>
    </div>
    
    <!-- OVERVIEW PAGE -->
    <?php if ($page === 'overview'): ?>
    <div class="metrics-grid">
        <?php
        $total_orders = 0;
        $total_sales = 0;
        $total_customers = $totalUniqueCustomers;
        $avg_order_value = 0;
        
        if ($sales_data) {
            while ($row = $sales_data->fetch_assoc()) {
                $total_orders += $row['total_orders'];
                $total_sales += $row['total_sales'];
            }
        }
        $avg_order_value = $total_orders > 0 ? $total_sales / $total_orders : 0;
        ?>
        
        <div class="metric-card revenue">
            <div class="metric-label">Total Revenue</div>
            <div class="metric-value">₱<?php echo number_format($total_sales, 2); ?></div>
            <div class="metric-change">From <?php echo ucfirst($period); ?> sales</div>
        </div>
        
        <div class="metric-card orders">
            <div class="metric-label">Total Orders</div>
            <div class="metric-value"><?php echo number_format($total_orders); ?></div>
            <div class="metric-change"><?php echo ucfirst($period); ?> period</div>
        </div>
        
        <div class="metric-card customers">
            <div class="metric-label">Unique Customers</div>
            <div class="metric-value"><?php echo $total_customers; ?></div>
            <div class="metric-change">Active buyers</div>
        </div>

        <div class="metric-card average">
            <div class="metric-label">Avg Order Value</div>
            <div class="metric-value">₱<?php echo number_format($avg_order_value, 2); ?></div>
            <div class="metric-change"><?php echo ucfirst($period); ?> average</div>
        </div>

        <div class="metric-card registered">
            <div class="metric-label">Registered Users</div>
            <div class="metric-value"><?php echo $registered_users; ?></div>
            <div class="metric-change">Total accounts</div>
        </div>
    </div>
    
    <div class="chart-card revenue-trend-card">
        <div class="ai-forecast-heading">
            <div class="ai-forecast-title">
                <span class="ai-forecast-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2.8 13.9 9l6.3 2-6.3 1.9-1.9 6.3-2-6.3-6.2-1.9L10 9l2-6.2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                        <path d="m19 15 .9 2.7 2.6.8-2.6.9L19 22l-.8-2.6-2.7-.9 2.7-.8L19 15Z" fill="currentColor"/>
                    </svg>
                </span>
                <div>
                    <h3>AI Revenue Forecast</h3>
                    <p>Includes orders, onsite inventory sales, and Pharma Sales POS for the <?php echo htmlspecialchars($period, ENT_QUOTES, 'UTF-8'); ?> period</p>
                </div>
            </div>
            <span class="ai-forecast-provider">GEMINI AI</span>
        </div>
        <div class="chart-container">
            <canvas id="revenueTrendChart"></canvas>
        </div>
        <div class="ai-forecast-result is-loading" id="forecastResult" role="status" aria-live="polite" aria-busy="true">
            <span class="ai-forecast-indicator" aria-hidden="true"></span>
            <div class="ai-forecast-copy">
                <span class="ai-forecast-label" id="forecastLabel">Analyzing revenue history</span>
                <p class="forecast-note" id="forecastStatus">Requesting a forecast from Gemini AI…</p>
            </div>
        </div>
    </div>

    <div class="table-section restock-section">
        <div class="restock-heading">
            <div>
                <h3>📦 AI Restock Forecast <span class="restock-ai-badge">✦ GEMINI AI</span></h3>
                <p>Predicted product demand based on real sales and current inventory.</p>
            </div>
            <a href="stock_levels.php">View stock levels</a>
        </div>
        <p class="restock-method-note">Includes online orders, onsite inventory sales, and Pharma Sales POS transactions. The forecast covers the next period of the same length as the selected dates. Percent change compares AI-estimated demand with actual units sold in that selected period.</p>
        <p class="restock-forecast-dates" id="restockForecastDates"></p>
        <div class="restock-forecast-status" id="restockForecastStatus" role="status" aria-live="polite" aria-busy="true">Waiting for the revenue forecast request to finish…</div>
        <div class="restock-product-grid" id="restockProductGrid"></div>
    </div>

    <div class="status-overview-grid">
        <div class="chart-card">
            <h3>📦 Order Status Distribution</h3>
            <div class="chart-container">
                <canvas id="orderStatusChart"></canvas>
            </div>
        </div>

        <div class="table-section">
            <h3>📊 Order Status Summary</h3>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total_status_orders = array_sum($order_stats);
                        foreach ($order_stats as $status => $count) {
                            $percentage = $total_status_orders > 0 ? ($count / $total_status_orders) * 100 : 0;
                            $status_lower = strtolower($status);
                            echo "<tr>";
                            echo "<td><span class='status-badge status-$status_lower'>$status</span></td>";
                            echo "<td>" . number_format($count) . "</td>";
                            echo "<td>" . number_format($percentage, 1) . "%</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PAYMENT METHODS PAGE -->
    <?php elseif ($page === 'payment'): ?>
    <?php
    $payment_order_count = 0;
    $payment_revenue = 0;
    foreach ($payment_totals as $payment_total) {
        $payment_order_count += (int)$payment_total['total_orders'];
        $payment_revenue += (float)$payment_total['total_amount'];
    }
    ?>
    <div class="metrics-grid">
        <div class="metric-card orders">
            <div class="metric-label">Payment Orders</div>
            <div class="metric-value"><?php echo number_format($payment_order_count); ?></div>
            <div class="metric-change">Non-cancelled orders</div>
        </div>
        <div class="metric-card revenue">
            <div class="metric-label">Payment Revenue</div>
            <div class="metric-value">₱<?php echo number_format($payment_revenue, 2); ?></div>
            <div class="metric-change"><?php echo ucfirst($period); ?> period</div>
        </div>
    </div>

    <div class="table-section">
        <h3>💳 Revenue by Payment Method (<?php echo ucfirst($period); ?>)</h3>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th>Orders</th>
                        <th>Total Amount</th>
                        <th>Share of Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payment_methods as $method_key => $method_label):
                        $method_total = $payment_totals[$method_key] ?? ['total_orders' => 0, 'total_amount' => 0];
                        $method_amount = (float)$method_total['total_amount'];
                        $method_share = $payment_revenue > 0 ? ($method_amount / $payment_revenue) * 100 : 0;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($method_label); ?></td>
                        <td><?php echo number_format((int)$method_total['total_orders']); ?></td>
                        <td>₱<?php echo number_format($method_amount, 2); ?></td>
                        <td><?php echo number_format($method_share, 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-section">
        <h3>Verified Payment Transaction IDs</h3>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Payment Method</th>
                        <th>Transaction ID</th>
                        <th>Amount</th>
                        <th>Order Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($verifiedTransactionError !== ''): ?>
                        <tr><td colspan="6">Unable to load verified payment transaction IDs: <?php echo htmlspecialchars($verifiedTransactionError, ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    <?php elseif ($verifiedTransactions && $verifiedTransactions->num_rows > 0): ?>
                        <?php while ($transaction = $verifiedTransactions->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(formatOrderNumber($conn, (int)$transaction['id']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($transaction['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(strtoupper((string)$transaction['payment_method']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($transaction['payment_transaction_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>₱<?php echo number_format((float)$transaction['total_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars(date('M j, Y', strtotime($transaction['order_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6">No verified payments with a transaction ID in this date range.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PRODUCTS PAGE -->
    <?php elseif ($page === 'products'): ?>
    <div class="metrics-grid">
        <div class="metric-card orders">
            <div class="metric-label">Top Products</div>
            <div class="metric-value"><?php echo $top_products ? $top_products->num_rows : 0; ?></div>
            <div class="metric-change">Best sellers</div>
        </div>
        
        <?php
        $top_prod_total = 0;
        $top_prod_revenue = 0;
        if ($top_products) {
            $top_products->data_seek(0);
            while ($prod = $top_products->fetch_assoc()) {
                $top_prod_total += $prod['total_sold'];
                $top_prod_revenue += $prod['revenue'];
            }
        }
        ?>
        
        <div class="metric-card revenue">
            <div class="metric-label">Product Revenue</div>
            <div class="metric-value">₱<?php echo number_format($top_prod_revenue, 0); ?></div>
            <div class="metric-change">From top 10</div>
        </div>
        
        <div class="metric-card customers">
            <div class="metric-label">Units Sold</div>
            <div class="metric-value"><?php echo number_format($top_prod_total); ?></div>
            <div class="metric-change">Top products</div>
        </div>
    </div>

    <div class="table-section">
        <h3>🔥 Top 10 Best-Selling Products (<?php echo ucfirst($period); ?>)</h3>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Product Name</th>
                        <th>Units Sold</th>
                        <th>Revenue</th>
                        <th>Avg Price/Unit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($top_products) {
                        $rank = 1;
                        $top_products->data_seek(0);
                        while ($product = $top_products->fetch_assoc()) {
                            $avg_price = $product['total_sold'] > 0 ? $product['revenue'] / $product['total_sold'] : 0;
                            echo "<tr>";
                            echo "<td style='font-weight: 700; color: #ed1740;'>#$rank</td>";
                            echo "<td>" . htmlspecialchars($product['product_name']) . "</td>";
                            echo "<td>" . number_format($product['total_sold']) . "</td>";
                            echo "<td>₱" . number_format($product['revenue'], 2) . "</td>";
                            echo "<td>₱" . number_format($avg_price, 2) . "</td>";
                            echo "</tr>";
                            $rank++;
                        }
                    } else {
                        echo "<tr><td colspan='5' class='empty-state' style='text-align: center; padding: 40px;'>No sales data available</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- CUSTOMERS PAGE -->
    <?php elseif ($page === 'customers'): ?>
    <div class="metrics-grid">
        <div class="metric-card customers">
            <div class="metric-label">Top Customers</div>
            <div class="metric-value"><?php echo $top_customers ? $top_customers->num_rows : 0; ?></div>
            <div class="metric-change">Best spenders</div>
        </div>
        
        <?php
        $top_cust_orders = 0;
        $top_cust_spending = 0;
        if ($top_customers) {
            $top_customers->data_seek(0);
            while ($cust = $top_customers->fetch_assoc()) {
                $top_cust_orders += $cust['order_count'];
                $top_cust_spending += $cust['total_spent'];
            }
        }
        ?>
        
        <div class="metric-card revenue">
            <div class="metric-label">Customer Spending</div>
            <div class="metric-value">₱<?php echo number_format($top_cust_spending, 0); ?></div>
            <div class="metric-change">Total spent</div>
        </div>
        
        <div class="metric-card orders">
            <div class="metric-label">Total Orders</div>
            <div class="metric-value"><?php echo number_format($top_cust_orders); ?></div>
            <div class="metric-change">From top customers</div>
        </div>
        
        <div class="metric-card average">
            <div class="metric-label">Avg Customer Value</div>
            <div class="metric-value">₱<?php echo $top_cust_spending > 0 && $top_customers->num_rows > 0 ? number_format($top_cust_spending / $top_customers->num_rows, 0) : '0'; ?></div>
            <div class="metric-change">Per customer</div>
        </div>
    </div>

    <div class="table-section">
        <h3>👥 Top Customers by Spending</h3>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Customer Name</th>
                        <th>Orders</th>
                        <th>Total Spent</th>
                        <th>Avg Per Order</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($top_customers) {
                        $rank = 1;
                        $top_customers->data_seek(0);
                        while ($customer = $top_customers->fetch_assoc()) {
                            $avg_order = $customer['order_count'] > 0 ? $customer['total_spent'] / $customer['order_count'] : 0;
                            echo "<tr>";
                            echo "<td style='font-weight: 700; color: #ed1740;'>#$rank</td>";
                            echo "<td>" . htmlspecialchars($customer['username']) . "</td>";
                            echo "<td>" . number_format($customer['order_count']) . "</td>";
                            echo "<td>₱" . number_format($customer['total_spent'], 2) . "</td>";
                            echo "<td>₱" . number_format($avg_order, 2) . "</td>";
                            echo "</tr>";
                            $rank++;
                        }
                    } else {
                        echo "<tr><td colspan='5' class='empty-state' style='text-align: center; padding: 40px;'>No customer data available</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
// Only load charts if on overview page
<?php if ($page === 'overview'): ?>
// Prepare data for Order Status Chart
const orderStatusData = <?php echo json_encode($order_stats); ?>;
const statusLabels = Object.keys(orderStatusData);
const statusValues = Object.values(orderStatusData);
const statusColors = {
    pending: '#f59e0b',
    processing: '#2563eb',
    completed: '#16a34a',
    cancelled: '#dc2626'
};
const doughnutColors = statusLabels.map((label) => statusColors[label.toLowerCase()] || '#64748b');

const statusCtx = document.getElementById('orderStatusChart');
if (statusCtx) {
    new Chart(statusCtx.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusValues,
                backgroundColor: doughnutColors,
                borderColor: '#ffffff',
                borderWidth: 3,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { padding: 15, font: { size: 12, weight: 'bold' }, usePointStyle: true, pointStyle: 'circle' }
                }
            }
        }
    });
}

// Prepare data for Revenue Trend Chart
<?php
$dailyRevenue = [];
if ($revenue_data) {
    $revenue_data->data_seek(0);
    while ($row = $revenue_data->fetch_assoc()) {
        $dailyRevenue[$row['date']] = (float)$row['revenue'];
    }
}

$historyStart = new DateTimeImmutable($rangeStartDate);
$historyEnd = new DateTimeImmutable($rangeEndDate);
$historyDays = (int)$historyStart->diff($historyEnd)->format('%a') + 1;
$forecastGranularity = 'day';
$forecastSteps = $historyDays;
if ($period === 'weekly') {
    $forecastSteps = 7;
} elseif ($period === 'monthly') {
    $forecastSteps = 30;
} elseif ($period === 'yearly') {
    $forecastGranularity = 'month';
    $forecastSteps = 12;
} elseif ($historyDays > 180) {
    $forecastGranularity = 'month';
    $forecastSteps = (int)ceil($historyDays / 30);
} elseif ($historyDays > 31) {
    $forecastGranularity = 'week';
    $forecastSteps = (int)ceil($historyDays / 7);
}

$getRevenueBucket = static function (DateTimeImmutable $date) use ($forecastGranularity): array {
    if ($forecastGranularity === 'month') {
        $bucketDate = $date->modify('first day of this month');
        return [$bucketDate->format('Y-m'), $bucketDate->format('M Y')];
    }
    if ($forecastGranularity === 'week') {
        $bucketDate = $date->modify('monday this week');
        return [$bucketDate->format('Y-m-d'), $bucketDate->format('M j')];
    }
    return [$date->format('Y-m-d'), $date->format('M j')];
};

$historyBuckets = [];
for ($date = $historyStart; $date <= $historyEnd; $date = $date->modify('+1 day')) {
    [$bucketKey, $bucketLabel] = $getRevenueBucket($date);
    if (!isset($historyBuckets[$bucketKey])) {
        $historyBuckets[$bucketKey] = ['label' => $bucketLabel, 'revenue' => 0.0];
    }
    $historyBuckets[$bucketKey]['revenue'] += $dailyRevenue[$date->format('Y-m-d')] ?? 0.0;
}

$revenueChartLabels = [];
$actualRevenue = [];
foreach ($historyBuckets as $bucket) {
    $revenueChartLabels[] = $bucket['label'];
    $actualRevenue[] = round($bucket['revenue'], 2);
}

$historicalCount = count($actualRevenue);

if ($forecastGranularity === 'month') {
    $forecastDate = $historyEnd->modify('first day of next month');
    $forecastInterval = 'first day of next month';
} elseif ($forecastGranularity === 'week') {
    $forecastDate = $historyEnd->modify('monday next week');
    $forecastInterval = '+1 week';
} else {
    $forecastDate = $historyEnd->modify('+1 day');
    $forecastInterval = '+1 day';
}

for ($step = 0; $step < $forecastSteps; $step++) {
    [, $bucketLabel] = $getRevenueBucket($forecastDate);
    $revenueChartLabels[] = $bucketLabel;
    $forecastDate = $forecastDate->modify($forecastInterval);
}
?>

const revenueForecastRequest = {
    csrf_token: <?php echo json_encode($aiRevenueForecastCsrf); ?>,
    period: <?php echo json_encode($period); ?>,
    date_from: <?php echo json_encode($rangeStartDate); ?>,
    date_to: <?php echo json_encode($rangeEndDate); ?>
};
const revenueCtx = document.getElementById('revenueTrendChart');
if (revenueCtx) {
    const revenueChart = new Chart(revenueCtx.getContext('2d'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($revenueChartLabels); ?>,
            datasets: [{
                label: 'Actual Revenue (₱)',
                data: <?php echo json_encode(array_merge($actualRevenue, array_fill(0, $forecastSteps, null))); ?>,
                borderColor: '#d91f43',
                backgroundColor: 'rgba(217, 31, 67, 0.16)',
                tension: 0.25,
                fill: true,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#d91f43',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                borderWidth: 3
            }, {
                label: 'AI Forecast (₱)',
                data: Array(<?php echo (int)$historicalCount + (int)$forecastSteps; ?>).fill(null),
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.08)',
                borderDash: [6, 5],
                tension: 0.25,
                fill: false,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#2563eb',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    labels: { padding: 15, font: { size: 12, weight: 'bold' } }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#4b5563', font: { size: 11, weight: '600' }, maxTicksLimit: 12 },
                    grid: { color: 'rgba(148, 163, 184, 0.18)' }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(148, 163, 184, 0.24)' },
                    ticks: {
                        color: '#4b5563',
                        font: { size: 11, weight: '600' },
                        callback: function(value) {
                            return '₱' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });

    const forecastStatus = document.getElementById('forecastStatus');
    const forecastResult = document.getElementById('forecastResult');
    const forecastLabel = document.getElementById('forecastLabel');
    fetch('ai_revenue_forecast.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(revenueForecastRequest)
    })
        .then(async response => {
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to generate the AI forecast.');
            }
            return result;
        })
        .then(result => {
            const forecastData = revenueChart.data.datasets[1].data;
            const lastActualIndex = <?php echo max(0, (int)$historicalCount - 1); ?>;
            <?php if ($historicalCount > 0): ?>
            forecastData[lastActualIndex] = revenueChart.data.datasets[0].data[lastActualIndex];
            <?php endif; ?>
            result.forecast.forEach((amount, index) => {
                forecastData[<?php echo (int)$historicalCount; ?> + index] = amount;
            });
            revenueChart.update();
            forecastStatus.textContent = result.summary;
            forecastLabel.textContent = 'AI forecast generated';
            forecastResult.classList.remove('is-loading', 'is-error');
            forecastResult.classList.add('is-ready');
            forecastResult.setAttribute('aria-busy', 'false');
        })
        .catch(error => {
            forecastStatus.textContent = error.message;
            forecastStatus.classList.add('forecast-error');
            forecastLabel.textContent = 'AI forecast unavailable';
            forecastResult.classList.remove('is-loading', 'is-ready');
            forecastResult.classList.add('is-error');
            forecastResult.setAttribute('aria-busy', 'false');
        })
        .finally(generateRestockForecast);
}

const restockForecastStatus = document.getElementById('restockForecastStatus');
const restockForecastDates = document.getElementById('restockForecastDates');
const restockProductGrid = document.getElementById('restockProductGrid');
function generateRestockForecast() {
    if (!restockForecastStatus || !restockProductGrid) {
        return;
    }
    restockForecastStatus.textContent = 'Analyzing product sales and inventory with Gemini AI…';
    fetch('ai_restock_forecast.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(revenueForecastRequest)
    })
        .then(async response => {
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to generate the AI restock forecast.');
            }
            return result;
        })
        .then(result => {
            restockForecastStatus.setAttribute('aria-busy', 'false');
            if (result.forecast_start && result.forecast_end) {
                restockForecastDates.textContent = `Predicted demand: ${result.forecast_start} – ${result.forecast_end}`;
            }
            if (!Array.isArray(result.products) || result.products.length === 0) {
                restockForecastStatus.textContent = 'No restock alerts from the AI forecast. Current stock is above its threshold and covers the estimated demand for the next period.';
                restockForecastStatus.classList.add('is-empty');
                return;
            }

            restockForecastStatus.textContent = `Gemini AI analyzed sales for ${result.selected_start} – ${result.selected_end} and current stock. These are estimates, not guaranteed sales.`;
            result.products.forEach(product => {
                const card = document.createElement('article');
                card.className = 'restock-product-card';

                const top = document.createElement('div');
                top.className = 'restock-product-top';
                const name = document.createElement('h4');
                name.className = 'restock-product-name';
                name.textContent = product.name;
                const status = document.createElement('span');
                status.className = 'restock-product-status';
                status.textContent = product.status;
                if (product.status === 'Out of stock') {
                    status.classList.add('is-out');
                }
                top.append(name, status);

                const demand = document.createElement('div');
                demand.className = 'restock-product-demand';
                const demandValue = document.createElement('strong');
                demandValue.textContent = `~${Number(product.predicted_units).toLocaleString()}`;
                const demandLabel = document.createElement('span');
                demandLabel.textContent = 'units predicted';
                demand.append(demandValue, demandLabel);

                const metrics = document.createElement('div');
                metrics.className = 'restock-product-metrics';
                const stockMetric = document.createElement('span');
                stockMetric.className = 'restock-product-metric';
                stockMetric.textContent = `In stock: ${Number(product.current_stock).toLocaleString()}`;
                const thresholdMetric = document.createElement('span');
                thresholdMetric.className = 'restock-product-metric';
                thresholdMetric.textContent = `Low-stock level: ${Number(product.low_stock_threshold).toLocaleString()}`;
                const soldMetric = document.createElement('span');
                soldMetric.className = 'restock-product-metric';
                soldMetric.textContent = `Sold in selected dates: ${Number(product.selected_period_units).toLocaleString()}`;
                const changeMetric = document.createElement('span');
                changeMetric.className = 'restock-product-metric restock-product-change';
                if (product.demand_change_percent === null) {
                    changeMetric.textContent = 'Demand change: no baseline';
                } else {
                    const change = Number(product.demand_change_percent);
                    changeMetric.textContent = `${change > 0 ? '+' : ''}${change.toLocaleString()}% vs selected dates`;
                    changeMetric.classList.add(change > 0 ? 'is-up' : 'is-down');
                }
                metrics.append(stockMetric, thresholdMetric, soldMetric, changeMetric);

                const insight = document.createElement('p');
                insight.className = 'restock-product-insight';
                const insightLabel = document.createElement('strong');
                insightLabel.textContent = 'AI sales insight: ';
                insight.append(insightLabel, document.createTextNode(product.insight));

                card.append(top, demand, metrics, insight);
                restockProductGrid.append(card);
            });
        })
        .catch(error => {
            restockForecastStatus.setAttribute('aria-busy', 'false');
            restockForecastStatus.textContent = `AI restock forecast unavailable: ${error.message}`;
            restockForecastStatus.classList.add('is-error');
        });
}
<?php endif; ?>
</script>

</body>
</html>
