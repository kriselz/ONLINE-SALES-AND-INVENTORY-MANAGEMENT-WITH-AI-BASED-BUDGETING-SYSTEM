<?php 
include 'config.php'; 
include 'functions.php';
session_start();

// 🔒 ADMIN ONLY
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit();
}
 

ensureLowStockNotificationSchema($conn);

// FILTERS
$search = trim((string)($_GET['search'] ?? ''));
$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = '';
}

$conditions = [];
$params = [];
$types = '';
if ($search !== '') {
    $conditions[] = 'product_name LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}
if ($month !== '') {
    $conditions[] = "DATE_FORMAT(created_at, '%Y-%m') = ?";
    $params[] = $month;
    $types .= 's';
}
$sql = 'SELECT * FROM products'
    . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '')
    . ' ORDER BY id DESC';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Query Error: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}
if ($params && !$stmt->bind_param($types, ...$params)) {
    die('Query Error: ' . htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8'));
}
if (!$stmt->execute()) {
    die('Query Error: ' . htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8'));
}
$result = $stmt->get_result();

$pieces_per_box = 12;
?>

<!DOCTYPE html>
<html>
<head>
<title>Product Monitoring</title>

<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-2">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafb; color: #111827; }
.content { margin-left: 260px; padding: 32px; width: calc(100% - 260px); min-height:100vh; }
.filters { background: #ffffff; padding: 20px; margin-bottom: 24px; display: flex; flex-wrap: wrap; gap: 14px; border-radius: 22px; box-shadow: 0 18px 36px rgba(15, 23, 42, 0.05); }
.filters input, .filters select, .filters button { padding: 12px 14px; border: 1px solid #e5e7eb; border-radius: 14px; font-size: 14px; }
.filters button { background: #dc2626; color: #ffffff; border: none; cursor: pointer; }
table { width: 100%; border-collapse: collapse; background: #ffffff; border-radius: 22px; overflow: hidden; box-shadow: 0 18px 36px rgba(15, 23, 42, 0.05); }
th, td { padding: 16px; text-align: left; border-bottom: 1px solid #e5e7eb; }
th { background: #f8fafc; color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; }
img { width: 60px; height: 60px; object-fit: cover; border-radius: 14px; display: block; margin: 0 auto; }
.out { color: #6b7280; font-weight: 700; }
.low { color: #b91c1c; font-weight: 700; }
.status-active { color: #16a34a; font-weight: 700; }
.status-inactive { color: #dc2626; font-weight: 700; }
.edit-btn { padding: 10px 14px; background: #dc2626; color: #ffffff; text-decoration: none; border-radius: 14px; display: inline-block; }
.card { background: #ffffff; border-radius: 24px; padding: 24px; box-shadow: 0 20px 44px rgba(15, 23, 42, 0.06); margin-bottom: 24px; }
@media (max-width: 1080px) { .content { padding: 24px; } }
@media (max-width: 720px) { .content { margin-left: 0; padding: 20px; } }
</style>
</head>

<body>

<?php include 'sidebar_owner.php'; ?>

<div class="content">

<h2>📦 Product Monitoring</h2>

<form method="GET" class="filters">
    <input type="text" name="search" placeholder="Search product..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="month" name="month" value="<?= htmlspecialchars($month); ?>">
    <button type="submit">Filter</button>
</form>

<table>
<tr>
    <th>ID</th>
    <th>Name</th>
    <th>Image</th>
    <th>Box</th>
    <th>Pieces</th>
    <th>Total Stock</th>
    <th>Status</th>
    <th>Action</th>
</tr>

<?php
if ($result->num_rows > 0) {

    while ($row = $result->fetch_assoc()) {

        $box = (int)($row['box'] ?? 0);
        $pieces = (int)($row['pieces'] ?? 0);

        // ✅ REAL STOCK
        $total_stock = ($box * $pieces_per_box) + $pieces;

        // ✅ SYNC quantity (para di magulo)
        $conn->query("UPDATE products SET quantity=$total_stock WHERE id={$row['id']}");

        // STATUS
        $status = ($total_stock <= 0) ? 'inactive' : 'active';
        $conn->query("UPDATE products SET status='$status' WHERE id={$row['id']}");

        checkAndSendLowStockAlert($conn, (int)$row['id']);

        $image = (strpos($row['image'], "http") === 0) 
            ? $row['image'] 
            : "uploads/" . $row['image'];

        echo "<tr>
            <td>{$row['id']}</td>
            <td>{$row['product_name']}</td>
            <td><img src='$image' onerror=\"this.src='default.png'\"></td>

            <td>".($box <= 0 ? "<span class='out'>0</span>" : $box)."</td>
            <td>".($pieces <= 0 ? "<span class='out'>0</span>" : $pieces)."</td>

            <td class='".($total_stock <= 50 ? "low" : "")."'>
                $total_stock pcs
            </td>

            <td class='".($status === 'active' ? 'status-active' : 'status-inactive')."'>
                ".ucfirst($status)."
            </td>

            <td>
                <a class='edit-btn' href='edit_product.php?id={$row['id']}'>Edit</a>
            </td>
        </tr>";
    }

} else {
    echo "<tr><td colspan='8'>No products found</td></tr>";
}
?>

</table>

</div>
</body>
</html>