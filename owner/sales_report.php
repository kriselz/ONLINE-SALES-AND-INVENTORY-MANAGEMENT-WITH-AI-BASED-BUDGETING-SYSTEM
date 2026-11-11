<?php
include 'config.php';
session_start();

// 🔒 ADMIN ONLY
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// VIEW FILTER
$view = $_GET['view'] ?? 'both';

// =======================
// 📊 SALES DATA (FIXED)
// =======================
$sales = $conn->query("
    SELECT 
        SUM(order_items.price) AS total_sales,
        COUNT(DISTINCT orders.id) AS total_orders
    FROM orders
    JOIN order_items ON orders.id = order_items.order_id
");

$salesRow = $sales->fetch_assoc();
$totalSales = $salesRow['total_sales'] ?? 0;
$totalOrders = $salesRow['total_orders'] ?? 0;

$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'");

$paymentSummary = $conn->query("
    SELECT
        LOWER(COALESCE(o.payment_method, 'unknown')) AS payment_method,
        COUNT(DISTINCT o.id) AS total_orders,
        COALESCE(SUM(CASE WHEN LOWER(COALESCE(o.status, '')) <> 'cancelled' THEN oi.price ELSE 0 END), 0) AS total_amount,
        COALESCE(SUM(CASE WHEN LOWER(COALESCE(o.status, '')) <> 'cancelled' AND LOWER(COALESCE(o.payment_status, 'unpaid')) = 'paid' THEN oi.price ELSE 0 END), 0) AS collected_amount
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    GROUP BY LOWER(COALESCE(o.payment_method, 'unknown'))
");

$paymentTotals = [];
if ($paymentSummary) {
    while ($paymentRow = $paymentSummary->fetch_assoc()) {
        $paymentTotals[$paymentRow['payment_method']] = $paymentRow;
    }
}

$paymentMethods = [
    'cod' => 'COD',
    'gcash' => 'GCash',
    'bank' => 'Bank Transfer',
    'unknown' => 'Other / Unknown'
];

// =======================
// 📦 INVENTORY DATA
// =======================
$inventory = $conn->query("SELECT * FROM products ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
<title>Sales Report</title>

<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-2">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f8; color: #111827; min-height:100vh; }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 14px; }
.btn { padding: 12px 16px; background: #dc2626; color: white; border: none; border-radius: 14px; cursor: pointer; transition: background 0.2s ease; }
.btn:hover { background: #b91c1c; }
select.btn { background: white; color: #111827; border: 1px solid #e5e7eb; }
.card, .summary { background: #ffffff; padding: 24px; border-radius: 24px; box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06); margin-bottom: 24px; }
table { width: 100%; border-collapse: collapse; background: #ffffff; border-radius: 22px; overflow: hidden; box-shadow: 0 18px 40px rgba(15, 23, 42, 0.05); }
th { background: #f8fafc; color: #475569; padding: 16px; text-align: left; text-transform: uppercase; font-size: 12px; letter-spacing: 0.08em; }
td { padding: 16px; border-bottom: 1px solid #e5e7eb; }
img { width: 50px; height: 50px; object-fit: cover; border-radius: 14px; display: block; margin: 0 auto; }
@media print { .btn { display: none; } .content { margin: 0; } }
@media (max-width: 1080px) { .content { padding: 24px; } }
@media (max-width: 720px) { .content { margin-left: 0; padding: 20px; } }
</style>
</head>

<body>

<?php include 'sidebar_owner.php'; ?>

<div class="content">

<div class="header">
    <h2>📊 Sales & Inventory Report</h2>

    <form method="GET">
        <select name="view" onchange="this.form.submit()" class="btn">
            <option value="both" <?= $view=='both'?'selected':'' ?>>All Reports</option>
            <option value="product" <?= $view=='product'?'selected':'' ?>>Product Only</option>
            <option value="sales" <?= $view=='sales'?'selected':'' ?>>Sales Only</option>
            <option value="payment" <?= $view=='payment'?'selected':'' ?>>Payment Method</option>
        </select>
    </form>

    <button class="btn" onclick="window.print()">🖨️ Print</button>
</div>

<!-- ================= PRODUCT ================= -->
<?php if ($view == 'both' || $view == 'product') { ?>

<div class="card">

<table>
<tr>
    <th>ID</th>
    <th>Product</th>
    <th>Image</th>
    <th>Price</th>
    <th>Stock (pcs)</th>
    <th>Total Value</th>
</tr>

<?php
$totalInventoryValue = 0;

while ($row = $inventory->fetch_assoc()) {

    // ✅ USE PIECE PRICE (FIXED)
    $value = $row['price_piece'] * $row['quantity'];
    $totalInventoryValue += $value;

    echo "<tr>
        <td>{$row['id']}</td>
        <td>{$row['product_name']}</td>
        <td><img src='uploads/{$row['image']}' onerror=\"this.src='default.png'\"></td>

        <td>
            Piece: ₱" . number_format($row['price_piece'],2) . "<br>
            Box: ₱" . number_format($row['price_box'],2) . "
        </td>

        <td>{$row['quantity']}</td>

        <td>₱" . number_format($value,2) . "</td>
    </tr>";
}
?>

<tr>
    <td colspan="5"><b>Total Inventory Value</b></td>
    <td><b>₱<?= number_format($totalInventoryValue,2) ?></b></td>
</tr>

</table>

</div>

<?php } ?>

<!-- ================= PAYMENT METHOD ================= -->
<?php if ($view == 'both' || $view == 'payment') { ?>

<div class="summary">

<h3>💳 Payment Method Summary</h3>

<table>
<tr>
    <th>Payment Method</th>
    <th>Orders</th>
    <th>Total Order Value</th>
    <th>Collected / Verified</th>
</tr>

<?php foreach ($paymentMethods as $methodKey => $methodLabel):
    $methodRow = $paymentTotals[$methodKey] ?? ['total_orders' => 0, 'total_amount' => 0, 'collected_amount' => 0];
?>
<tr>
    <td><?= htmlspecialchars($methodLabel) ?></td>
    <td><?= number_format((int)$methodRow['total_orders']) ?></td>
    <td>₱<?= number_format((float)$methodRow['total_amount'], 2) ?></td>
    <td>₱<?= number_format((float)$methodRow['collected_amount'], 2) ?></td>
</tr>
<?php endforeach; ?>

</table>

</div>

<?php } ?>

<!-- ================= SALES ================= -->
<?php if ($view == 'both' || $view == 'sales') { ?>

<div class="summary">

<h3>📊 Sales Summary</h3>

<table>
<tr>
    <th>Description</th>
    <th>Value</th>
</tr>

<tr>
    <td>Total Orders</td>
    <td><?= $totalOrders ?></td>
</tr>

<tr>
    <td>Total Sales</td>
    <td>₱<?= number_format($totalSales,2) ?></td>
</tr>

</table>

</div>

<?php } ?>

</div>

</body>
</html>