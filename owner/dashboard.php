<?php
session_start();
include 'config.php';
include 'functions.php';

// ADMIN ONLY
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login_a.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Metrics and sales overview use the same selected reporting period.
$salesRange = $_GET['sales_range'] ?? 'weekly';
if (!in_array($salesRange, ['weekly', 'monthly', 'yearly', 'custom'], true)) { $salesRange = 'weekly'; }
$dateFromInput = trim($_GET['date_from'] ?? '');
$dateToInput = trim($_GET['date_to'] ?? '');
$dateFrom = DateTime::createFromFormat('!Y-m-d', $dateFromInput);
$dateTo = DateTime::createFromFormat('!Y-m-d', $dateToInput);
$dateFromValid = $dateFrom && $dateFrom->format('Y-m-d') === $dateFromInput;
$dateToValid = $dateTo && $dateTo->format('Y-m-d') === $dateToInput;
$salesReferenceDate = date('Y-m-d');
$latestSaleResult = $conn->query("SELECT MAX(o.order_date) AS latest_sale FROM orders o JOIN order_items oi ON o.id = oi.order_id");
if ($latestSaleResult) {
    $latestSale = $latestSaleResult->fetch_assoc()['latest_sale'] ?? null;
    if ($latestSale && strtotime($latestSale) < strtotime($salesReferenceDate)) {
        $salesReferenceDate = date('Y-m-d', strtotime($latestSale));
    }
}
$hasCustomDateRange = $salesRange === 'custom' && ($dateFromValid || $dateToValid);
$customRangeLabel = 'Custom Range';
if ($hasCustomDateRange) {
    if ($dateFromValid && $dateToValid) {
        $customRangeLabel = date('M j, Y', strtotime($dateFromInput)) . ' - ' . date('M j, Y', strtotime($dateToInput));
    } elseif ($dateFromValid) {
        $customRangeLabel = 'From ' . date('M j, Y', strtotime($dateFromInput));
    } else {
        $customRangeLabel = 'Through ' . date('M j, Y', strtotime($dateToInput));
    }
}
if ($salesRange === 'custom') {
    if ($hasCustomDateRange) {
        $salesStartDate = $dateFromValid ? $dateFromInput : $dateToInput;
        $salesEndDate = $dateToValid ? $dateToInput : $salesStartDate;
    } else {
        $salesStartDate = $salesReferenceDate;
        $salesEndDate = $salesReferenceDate;
    }
    if ($salesStartDate > $salesEndDate) {
        [$salesStartDate, $salesEndDate] = [$salesEndDate, $salesStartDate];
    }
} elseif ($dateFromValid || $dateToValid) {
    $salesStartDate = $dateFromValid ? $dateFromInput : $dateToInput;
    $salesEndDate = $dateToValid ? $dateToInput : $salesStartDate;
    if ($salesStartDate > $salesEndDate) {
        [$salesStartDate, $salesEndDate] = [$salesEndDate, $salesStartDate];
    }
} else {
    $salesStartDate = $salesRange === 'yearly' ? date('Y-01-01', strtotime($salesReferenceDate)) : ($salesRange === 'monthly' ? date('Y-m-01', strtotime($salesReferenceDate)) : date('Y-m-d', strtotime($salesReferenceDate . ' -6 days')));
    $salesEndDate = $salesReferenceDate;
}
$periodWhere = "DATE(o.order_date) BETWEEN '$salesStartDate' AND '$salesEndDate'";

$totalSalesResult = $conn->query("SELECT SUM(oi.price) AS total_sales FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $periodWhere");
$totalSales = $totalSalesResult ? (float)$totalSalesResult->fetch_assoc()['total_sales'] : 0;

$totalOrdersResult = $conn->query("SELECT COUNT(*) AS total_orders FROM orders o WHERE $periodWhere");
$totalOrders = $totalOrdersResult ? (int)$totalOrdersResult->fetch_assoc()['total_orders'] : 0;

$totalCustomersResult = $conn->query("SELECT COUNT(DISTINCT o.user_id) AS total_customers FROM orders o WHERE $periodWhere");
$totalCustomers = $totalCustomersResult ? (int)$totalCustomersResult->fetch_assoc()['total_customers'] : 0;

ensureLowStockNotificationSchema($conn);
$lowStockResult = $conn->query("SELECT COUNT(*) AS low_stock_products FROM products WHERE (COALESCE(pieces, 0) + (COALESCE(box, 0) * COALESCE(NULLIF(pieces_per_box, 0), 12))) <= COALESCE(low_stock_threshold, 20)");
$lowStockCount = $lowStockResult ? (int)$lowStockResult->fetch_assoc()['low_stock_products'] : 0;
$lowStockAlerts = $conn->query("SELECT p.id, p.product_name, p.sku,
    (COALESCE(p.pieces, 0) + (COALESCE(p.box, 0) * COALESCE(NULLIF(p.pieces_per_box, 0), 12))) AS current_stock,
    COALESCE(p.low_stock_threshold, 20) AS low_stock_threshold, p.low_stock_alert_sent,
    p.low_stock_last_alert_at, p.low_stock_email_status, p.low_stock_email_error
    FROM products p
    WHERE (COALESCE(p.pieces, 0) + (COALESCE(p.box, 0) * COALESCE(NULLIF(p.pieces_per_box, 0), 12))) <= COALESCE(p.low_stock_threshold, 20)
    ORDER BY current_stock ASC LIMIT 10");

$recentOrders = $conn->query("SELECT o.id, o.status, o.order_date, o.user_id, u.username, SUM(oi.price) AS total_price FROM orders o JOIN users u ON o.user_id = u.id JOIN order_items oi ON o.id = oi.order_id GROUP BY o.id ORDER BY o.order_date DESC LIMIT 5");

$salesOverviewResult = $conn->query("SELECT DATE(o.order_date) AS sale_date, SUM(oi.price) AS daily_sales FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE DATE(o.order_date) BETWEEN '$salesStartDate' AND '$salesEndDate' GROUP BY DATE(o.order_date) ORDER BY sale_date ASC");
$salesByDate = [];
if ($salesOverviewResult) {
    while ($row = $salesOverviewResult->fetch_assoc()) {
        $salesByDate[$row['sale_date']] = (float)$row['daily_sales'];
    }
}
$salesLabels = [];
$salesValues = [];
$salesDate = new DateTime($salesStartDate);
$salesLastDate = new DateTime($salesEndDate);
while ($salesDate <= $salesLastDate) {
    $date = $salesDate->format('Y-m-d');
    $salesLabels[] = date('M j', strtotime($date));
    $salesValues[] = $salesByDate[$date] ?? 0;
    $salesDate->modify('+1 day');
}

function badgeClass($status) {
    $status = strtolower($status);
    switch ($status) {
        case 'pending': return 'status-pending';
        case 'processing': return 'status-processing';
        case 'ready_for_pickup': return 'status-shipped';
        case 'shipped': return 'status-shipped';
        case 'completed': return 'status-completed';
        case 'cancelled': return 'status-cancelled';
        default: return 'status-default';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Owner Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8f9fb; color: #20232a; min-height: 100vh; overflow-x: hidden; font-size: 16px; }
.user-info { display: flex; flex-direction: column; }
.user-name { font-size: 14px; font-weight: 700; }
.user-email { font-size: 12px; color: #d1d5db; }
.content { position: relative; margin-left: 260px; padding: 18px 28px 24px; width: calc(100% - 260px); min-height: 100vh; }
.header { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 14px; min-height: 42px; height: auto; }
.header-left h1 { font-size: 30px; color: #20232a; margin-bottom: 5px; }
.header-left p { color: #737981; font-size: 16px; }
.header-right { display: flex; align-items: flex-start; gap: 14px; }
.period-control { position: relative; display: flex; flex-direction: column; align-items: flex-end; }
.period-selector { display: flex; gap: 6px; }
.period-btn { padding: 8px 14px; border: 1px solid #e5e7eb; background: #fff; color: #4e5660; border-radius: 6px; font-size: 14px; font-weight: 700; text-decoration: none; }
.period-btn:hover, .period-btn.active { background: #ed1740; color: #fff; border-color: #ed1740; }
.dashboard-links { display:flex; gap:7px; margin-bottom:12px; }
.dashboard-link { padding:8px 13px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; color:#626970; font-size:12px; font-weight:700; text-decoration:none; }
.dashboard-link:hover { border-color:#ed1740; color:#ed1740; background:#fff1f3; }
.header-right .user-pill { background: #ffffff; border: 1px solid #eceef1; border-radius: 18px; padding: 10px 16px; color: #20232a; font-size: 14px; font-weight: 700; }
.metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; min-height: 92px; height: auto; margin-bottom: 18px; }
.metric-card { height: 114px; background: #ffffff; border-radius: 10px; padding: 12px 16px; box-shadow: 0 2px 10px rgba(28, 32, 38, 0.05); border: 1px solid #f0f1f3; border-left-width: 3px; }
.metrics-grid > .metric-card.revenue { border-color: #f0f1f3 !important; border-left-color: #0ea5e9 !important; }
.metric-card.orders { border-left-color: #f59e0b; }
.metric-card.customers { border-left-color: #46b68a; }
.metric-card.lowstock { border-left-color: #f2b832; }
.metric-label { font-size: 16px; line-height: 18px; color: #767b83; margin-bottom: 5px; }
.metric-value { font-size: 32px; line-height: 34px; color: #272b31; font-weight: 800; }
.metric-note { color: #54a581; font-size: 14px; line-height: 16px; margin-top: 4px; }
.metric-card.lowstock .metric-note { color: #b78416; }
.grid-two { display: grid; grid-template-columns: 1.25fr 1fr; gap: 14px; min-height: 320px; height: auto; margin-bottom: 16px; }
.card { background: #ffffff; border-radius: 10px; padding: 15px; box-shadow: 0 2px 10px rgba(28, 32, 38, 0.05); border: 1px solid #f0f1f3; overflow: hidden; }
.card h2 { font-size: 19px; margin-bottom: 12px; color: #24282e; }
.card-heading { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
.card-heading h2 { margin-bottom:0; }
.card-actions { display:flex; align-items:center; gap:6px; }
.report-link, .filter-link { border-radius:6px; font-size:10px; font-weight:700; padding:5px 8px; text-decoration:none; white-space:nowrap; }
.report-link { color:#ed1b3b; background:#fff1f3; }
.filter-link { color:#626970; background:#f4f5f6; }
.report-link:hover { background:#ffe2e7; }
.filter-link:hover { background:#e9ebee; }
.sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
.range-select { border:1px solid #e5e7eb; border-radius:7px; background:#ffffff; color:#4b5563; font-size:15px; padding:7px 10px; }
.date-filter { position:absolute; top:100%; right:0; z-index:10; display:flex; align-items:end; gap:8px; margin-top:8px; padding:10px 12px; background:#fff; border:1px solid #e1e5ea; border-radius:8px; box-shadow:0 8px 18px rgba(28,32,38,.12); }
.date-filter label { display:flex; flex-direction:column; gap:4px; color:#767b83; font-size:12px; font-weight:700; }
.date-filter input { border:1px solid #e5e7eb; border-radius:6px; color:#4b5563; font-size:14px; padding:7px 9px; }
.date-filter button, .date-filter a { border:0; border-radius:6px; font-size:13px; font-weight:700; padding:8px 11px; text-decoration:none; cursor:pointer; }
.date-filter button { background:#ed1b3b; color:#fff; }
.date-filter a { background:#f4f5f6; color:#626970; }
.view-all { color:#ed1b3b; background:#fff1f3; border-radius:6px; font-size:15px; font-weight:700; padding:7px 10px; text-decoration:none; white-space:nowrap; }
.view-all:hover { background:#ffe2e7; }
.small-card { background: #ffffff; border-radius: 10px; padding: 15px; box-shadow: 0 2px 10px rgba(28, 32, 38, 0.05); border: 1px solid #f0f1f3; overflow: hidden; }
.table-responsive { overflow-x: auto; }
.grid-two .table-responsive { max-height: none; }
table { width: 100%; min-width: 0; border-collapse: separate; border-spacing: 0 6px; }
th, td { padding: 14px 12px; text-align: left; border-bottom: 0; font-size: 15px; line-height: 1.5; white-space: nowrap; }
th { color: #767b83; font-size: 14px; font-weight: 700; }
.grid-two .table-responsive thead th,
.alert-table .table-responsive thead th { background: #e9ecef; color: #495057; }
tbody tr td { background: #fafbfc; }
tbody tr:hover td { background: #fff4f6; }
tbody tr td:first-child { border-radius: 8px 0 0 8px; }
tbody tr td:last-child { border-radius: 0 8px 8px 0; }
.status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 9px; border-radius: 999px; font-size: 14px; font-weight: 700; }
.status-pending { background: #ffedd5; color: #b45309; }
.status-processing { background: #dbeafe; color: #1d4ed8; }
.status-shipped { background: #dcfce7; color: #166534; }
.status-completed { background: #d1fae5; color: #047857; }
.status-cancelled { background: #fee2e2; color: #b91c1c; }
.status-default { background: #e5e7eb; color: #334155; }
.stat-card { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; }
.stat-item { background: #fff7f8; border-radius: 7px; padding: 10px; }
.stat-item strong { display: block; font-size: 18px; margin-bottom: 3px; color: #ed1b3b; }
.stat-item span { color: #767b83; font-size: 12px; }
.analytics-chart { height: 145px; }
.chart-container { height: 305px; }
.alert-table { margin-top: 16px; }
.alert-table table { border-collapse: collapse; border-spacing: 0; }
.alert-table th, .alert-table td { padding: 11px 10px; border-bottom: 1px solid #f0f1f3; font-size: 14px; }
.warning-badge { color: #a16207; background: #fef3c7; border-radius: 999px; padding: 4px 8px; font-weight: 700; }
.email-failed { color: #b91c1c; font-weight: 700; }
@media (max-width: 1080px) { .grid-two { min-height: 0; height: auto; grid-template-columns: 1fr; } }
@media (max-width: 720px) { .content { margin-left: 0; padding: 14px; width: 100%; } .header { flex-direction: column; align-items: flex-start; height: auto; } .header-right { width: 100%; } .date-filter { right: auto; left: 0; width:max-content; max-width:calc(100vw - 28px); flex-wrap: wrap; } .metrics-grid { height: auto; grid-template-columns: 1fr; } .grid-two { grid-template-columns: minmax(0, 1fr); } .card-heading { flex-wrap: wrap; } }
</style>
</head>
<body>

<?php include 'appbar_owner.php'; include 'sidebar_owner.php'; ?>

<div class="content">
    <div class="header">
        <div class="header-left">
            <h1>Welcome back, Admin! 👋</h1>
            <p>Here’s what’s happening with your store today.</p>
        </div>
        <div class="header-right">
            <div class="period-control">
                <form class="period-selector" method="get" aria-label="Dashboard reporting period">
                    <label for="headerSalesRange" class="sr-only">Sales period</label>
                    <select id="headerSalesRange" name="sales_range" class="range-select" onchange="this.form.submit()">
                        <option value="weekly" <?php echo $salesRange === 'weekly' ? 'selected' : ''; ?>>This Week</option>
                        <option value="monthly" <?php echo $salesRange === 'monthly' ? 'selected' : ''; ?>>This Month</option>
                        <option value="yearly" <?php echo $salesRange === 'yearly' ? 'selected' : ''; ?>>This Year</option>
                        <option value="custom" <?php echo $salesRange === 'custom' ? 'selected' : ''; ?>><?php echo htmlspecialchars($customRangeLabel); ?></option>
                    </select>
                </form>
                <?php if ($salesRange === 'custom' && !$hasCustomDateRange): ?>
                    <form class="date-filter" method="get" action="dashboard.php">
                        <input type="hidden" name="sales_range" value="custom">
                        <label aria-label="Start date"><input type="date" name="date_from" value="<?php echo $dateFromValid ? htmlspecialchars($dateFromInput) : ''; ?>"></label>
                        <label aria-label="End date"><input type="date" name="date_to" value="<?php echo $dateToValid ? htmlspecialchars($dateToInput) : ''; ?>"></label>
                        <button type="submit">Apply</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <div class="metrics-grid">
        <div class="metric-card revenue">
            <div class="metric-label">Total Sales</div>
            <div class="metric-value">₱<?php echo number_format($totalSales, 2); ?></div>
            <div class="metric-note"><?php echo $totalOrders; ?> orders processed</div>
        </div>
        <div class="metric-card orders">
            <div class="metric-label">Total Orders</div>
            <div class="metric-value"><?php echo number_format($totalOrders); ?></div>
            <div class="metric-note">Order volume this quarter</div>
        </div>
        <div class="metric-card customers">
            <div class="metric-label">Total Customers</div>
            <div class="metric-value"><?php echo number_format($totalCustomers); ?></div>
            <div class="metric-note">Active registered users</div>
        </div>
        <div class="metric-card lowstock">
            <div class="metric-label">Low Stock Products</div>
            <div class="metric-value"><?php echo number_format($lowStockCount); ?></div>
            <div class="metric-note">Needs attention soon</div>
        </div>
    </div>


    <div class="grid-two">
        <div class="card">
            <div class="card-heading">
                <h2>Sales Overview</h2>
            </div>
            <div class="chart-container">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
        <div class="card">
            <div class="card-heading">
                <h2>Recent Orders</h2>
                <a class="view-all" href="admin_orders.php">View All Orders</a>
            </div>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Order ID</th><th>Customer</th><th>Total</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php if ($recentOrders && $recentOrders->num_rows): ?>
                            <?php while ($order = $recentOrders->fetch_assoc()): ?>
                                <tr><td><?php echo htmlspecialchars(formatOrderNumber($conn, (int)$order['id']), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($order['username']); ?></td><td>₱<?php echo number_format($order['total_price'], 2); ?></td><td><span class="status-badge <?php echo badgeClass($order['status']); ?>"><?php echo htmlspecialchars(strtolower($order['status']) === 'ready_for_pickup' ? 'Ready for Pickup' : ucfirst(str_replace('_', ' ', $order['status']))); ?></span></td><td><?php echo date('M j', strtotime($order['order_date'])); ?></td></tr>
                            <?php endwhile; ?>
                        <?php else: ?><tr><td colspan="5">No recent orders available.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card alert-table">
        <div class="card-heading"><h2>Low Stock Alerts</h2><a class="view-all" href="inventory_alerts.php">Manage Alerts</a></div>
        <div class="table-responsive">
            <table>
                <thead><tr><th>Product</th><th>Current Stock</th><th>Threshold</th><th>Status</th><th>Date/Time</th></tr></thead>
                <tbody>
                    <?php if ($lowStockAlerts && $lowStockAlerts->num_rows): ?>
                        <?php while ($alert = $lowStockAlerts->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($alert['product_name']); ?></td>
                                <td><span class="warning-badge">⚠ <?php echo (int)$alert['current_stock']; ?></span></td>
                                <td><?php echo (int)$alert['low_stock_threshold']; ?></td>
                                <td><?php echo $alert['low_stock_email_status'] === 'failed' ? '<span class="email-failed">Email failed</span>' : 'Alert active'; ?></td>
                                <td><?php echo $alert['low_stock_last_alert_at'] ? date('M j, Y g:i A', strtotime($alert['low_stock_last_alert_at'])) : 'Pending'; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?><tr><td colspan="5">No products are currently below their thresholds.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
const salesLabels = <?php echo json_encode($salesLabels); ?>;
const salesValues = <?php echo json_encode($salesValues); ?>;

const ctxSales = document.getElementById('salesChart').getContext('2d');
new Chart(ctxSales, {
    type: 'line',
    data: {
        labels: salesLabels,
        datasets: [{ label: 'Sales', data: salesValues, borderColor: '#ed1b3b', backgroundColor: 'rgba(237, 27, 59, .12)', fill: true, tension: .4, pointBackgroundColor: '#ed1b3b', pointRadius: 3, borderWidth: 2 }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, grid: { color: '#f0f1f3' }, ticks: { font: { size: 11 }, color: '#8a9098' } }, x: { grid: { display: false }, ticks: { autoSkip: salesLabels.length > 10, maxTicksLimit: 12, font: { size: 11 }, color: '#8a9098' } } },
        maintainAspectRatio: false
    }
});

</script>
</body>
</html>