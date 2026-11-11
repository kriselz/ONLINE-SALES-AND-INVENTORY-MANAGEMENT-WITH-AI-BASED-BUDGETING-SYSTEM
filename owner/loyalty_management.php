<?php
session_start();
include 'config.php';
date_default_timezone_set('Asia/Manila');
include 'access_control.php';
require_once __DIR__ . '/customer_voucher_helpers.php';
requireOwnerPageAccess($conn, true);

if (!ensureCustomerLoyaltyVoucherSchema($conn)) {
    http_response_code(500);
    exit('Unable to prepare customer voucher history.');
}
$voucherExpiryBackfill = $conn->query("UPDATE coupons c
    JOIN customer_loyalty_vouchers clv ON clv.coupon_id = c.id
    SET c.expiry_date = DATE_ADD(clv.sent_at, INTERVAL 7 DAY)
    WHERE clv.status = 'sent' AND clv.sent_at IS NOT NULL AND c.expiry_date IS NULL");
if (!$voucherExpiryBackfill) {
    error_log('Customer voucher history expiry backfill failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare customer voucher history.');
}

$conn->query("CREATE TABLE IF NOT EXISTS loyalty_accounts (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, points_balance INT NOT NULL DEFAULT 0, lifetime_points INT NOT NULL DEFAULT 0, tier VARCHAR(20) NOT NULL DEFAULT 'Bronze', updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
$conn->query("CREATE TABLE IF NOT EXISTS loyalty_transactions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, order_id INT NULL, points INT NOT NULL, transaction_type VARCHAR(30) NOT NULL, description VARCHAR(255) NULL, created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_loyalty_user (user_id))");
$conn->query("CREATE TABLE IF NOT EXISTS loyalty_vouchers (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, discount_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed', discount_value DECIMAL(10,2) NOT NULL DEFAULT 0, min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0, usage_limit INT UNSIGNED NOT NULL DEFAULT 1, used_count INT UNSIGNED NOT NULL DEFAULT 0, expires_at DATE NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");

$customerVoucherHistory = $conn->query(
    "SELECT clv.qualifying_month, clv.qualifying_spend, clv.discount_value,
            clv.status, clv.sent_at, clv.used_at, clv.order_id,
            c.code, c.discount_type, c.expiry_date, u.username
     FROM customer_loyalty_vouchers clv
     JOIN users u ON u.id = clv.user_id
     JOIN coupons c ON c.id = clv.coupon_id
     ORDER BY clv.qualifying_month DESC, clv.created_at DESC"
);
if (!$customerVoucherHistory) {
    error_log('Customer voucher history query failed: ' . $conn->error);
}
$historyUser = (int)($_GET['user_id'] ?? 0);
$history = $historyUser ? $conn->query("SELECT lt.*, u.username FROM loyalty_transactions lt JOIN users u ON u.id=lt.user_id WHERE lt.user_id=$historyUser ORDER BY lt.created_at DESC LIMIT 50") : false;
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Customer Voucher</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"><link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4"><style>
*{box-sizing:border-box}body{margin:0;background:#f7f8fa;color:#20232a;font-family:'Segoe UI',Tahoma,sans-serif}.content{margin-left:244px;padding:28px;min-height:100vh}.head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.head h1{margin:0 0 5px;font-size:22px}.head p{margin:0;color:#7b8189;font-size:11px}.customer-tabs{display:flex;flex-wrap:nowrap;gap:6px;width:max-content;max-width:100%;overflow-x:auto;scrollbar-width:none;margin:0 0 18px;padding:5px;background:#fff;border:1px solid #ebedf0;border-radius:9px;box-shadow:0 2px 8px rgba(25,30,38,.04)}.customer-tabs::-webkit-scrollbar{display:none}.customer-tab{display:inline-flex;flex:0 0 auto;align-items:center;justify-content:center;min-height:34px;padding:6px 13px;border-bottom:3px solid transparent;border-radius:0;color:#68707a;font-size:11px;font-weight:700;line-height:1.25;text-align:center;text-decoration:none;white-space:nowrap}.customer-tab:hover{background:#fff1f3;color:#ed1740}.customer-tab.active{border-bottom-color:#ed1740;background:#fff;color:#a51c38}.panel{background:#fff;border:1px solid #ebedf0;border-radius:9px;padding:16px;margin-bottom:16px;box-shadow:0 2px 10px rgba(25,30,38,.04)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.form{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.form label{display:flex;flex-direction:column;gap:5px;color:#626a74;font-size:10px;font-weight:600}.form input,.form select{height:31px;padding:0 9px;border:1px solid #dfe4e9;border-radius:6px;font-size:11px}.button{height:31px;padding:0 12px;border:0;border-radius:6px;background:#ed1740;color:#fff;font-size:10px;font-weight:700;cursor:pointer}.table-scroll{overflow:auto}table{width:100%;min-width:700px;border-collapse:collapse}th,td{padding:9px 8px;border-bottom:1px solid #f0f1f3;text-align:left;font-size:10px;white-space:nowrap}th{background:#fbfcfd;color:#68707a;font-size:9px}.tier{padding:4px 7px;border-radius:5px;font-size:9px}.Bronze{background:#f1e4d5;color:#8a5d32}.Silver{background:#edf0f4;color:#59636d}.Gold{background:#fff2c7;color:#a17100}.notice{padding:10px 12px;border-radius:7px;margin-bottom:14px;font-size:11px}.success{background:#e9f8ef;color:#278658}.error{background:#ffe8ed;color:#d33b59}.muted{color:#7b8189;font-size:10px}h2{font-size:15px;margin:0 0 12px}@media(max-width:850px){.content{margin-left:0;padding:20px 14px}.customer-tabs{width:100%}.grid{grid-template-columns:1fr}.form{grid-template-columns:1fr 1fr}}@media(max-width:500px){.form{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include 'appbar_owner.php'; include 'sidebar_owner.php'; ?>
<main class="content">
<nav class="customer-tabs" aria-label="Customer sections">
<a class="customer-tab" href="user_orders.php">Customers</a>
<a class="customer-tab active" href="loyalty_management.php" aria-current="page">Customer Voucher</a>
</nav>
<section class="panel">
<h2>Customer Voucher History</h2>
<?php if (!$customerVoucherHistory): ?>
<p class="muted">Voucher history could not be loaded. Please check the server log.</p>
<?php elseif ($customerVoucherHistory->num_rows === 0): ?>
<p class="muted">No customer vouchers have been issued yet.</p>
<?php else: ?>
<div class="table-scroll">
<table>
<tr>
<th>Month</th>
<th>Customer</th>
<th>Monthly Spend</th>
<th>Voucher</th>
<th>Discount</th>
<th>Status</th>
<th>Sent</th>
<th>Expires</th>
<th>Redeemed</th>
</tr>
<?php while ($voucherHistory = $customerVoucherHistory->fetch_assoc()): ?>
<?php
$historyStatus = (string)$voucherHistory['status'];
if ($historyStatus === 'sent' && $voucherHistory['expiry_date'] !== null
    && strtotime((string)$voucherHistory['expiry_date']) <= time()) {
    $historyStatus = 'expired';
}
$historyDiscount = $voucherHistory['discount_type'] === 'percent'
    ? number_format((float)$voucherHistory['discount_value'], 0) . '%'
    : '₱' . number_format((float)$voucherHistory['discount_value'], 2);
?>
<tr>
<td><?php echo date('F Y', strtotime((string)$voucherHistory['qualifying_month'])); ?></td>
<td><?php echo htmlspecialchars($voucherHistory['username'], ENT_QUOTES, 'UTF-8'); ?></td>
<td>₱<?php echo number_format((float)$voucherHistory['qualifying_spend'], 2); ?></td>
<td><?php echo htmlspecialchars($voucherHistory['code'], ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars($historyDiscount, ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars(ucfirst($historyStatus), ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo $voucherHistory['sent_at'] ? date('M d, Y', strtotime($voucherHistory['sent_at'])) : '—'; ?></td>
<td><?php echo $voucherHistory['expiry_date'] ? date('M d, Y', strtotime($voucherHistory['expiry_date'])) : '—'; ?></td>
<td><?php echo $voucherHistory['used_at'] ? date('M d, Y', strtotime($voucherHistory['used_at'])) : '—'; ?></td>
</tr>
<?php endwhile; ?>
</table>
</div>
<?php endif; ?>
</section>
<?php if($history):?>
<section class="panel">
<h2>Points History</h2>
<div class="table-scroll">
<table>
<tr>
<th>Date</th>
<th>Customer</th>
<th>Order</th>
<th>Points</th>
<th>Description</th>
</tr>
<?php while($item=$history->fetch_assoc()):?>
<tr>
<td>
<?php echo date('M d, Y h:i A',strtotime($item['created_at']));?>
</td>
<td>
<?php echo htmlspecialchars($item['username']);?>
</td>
<td>
<?php echo $item['order_id'] ? htmlspecialchars(formatOrderNumber($conn, (int)$item['order_id']), ENT_QUOTES, 'UTF-8') : '—';?>
</td>
<td>
<?php echo ((int)$item['points'] > 0 ? '+' : '').number_format((int)$item['points']);?>
</td>
<td>
<?php echo htmlspecialchars($item['description'] ?? '');?>
</td>
</tr>
<?php endwhile;?>
</table>
</div>
</section>
<?php endif;?>
</main>
</body>
</html>
