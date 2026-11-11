<?php
include 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Login required!");
}

$user_id = $_SESSION['user_id'];

if (!isset($_GET['product_id'])) {
    header("Location: product_screen.php");
    exit();
}

$product_id = (int)$_GET['product_id'];

$product = $conn->query("SELECT * FROM products WHERE id=$product_id")->fetch_assoc();

$user = $conn->query("SELECT username, email, address, phone FROM users WHERE id=$user_id")->fetch_assoc();

$username = $user['username'] ?? "";
$email = $user['email'] ?? "";
$address = $user['address'] ?? "";
$phone = $user['phone'] ?? "";
?>

<!DOCTYPE html>
<html>
<head>
<title>Checkout</title>

<style>
body {
    margin:0;
    font-family:Arial;
    display:flex;
    background:#f4f6f8;
}

/* SIDEBAR */
.sidebar {
    width:220px;
    background:#2c3e50;
    color:white;
    height:100vh;
    position:fixed;
}

.sidebar h2 {
    text-align:center;
    padding:15px 0;
}

.sidebar a {
    display:block;
    padding:12px;
    color:white;
    text-decoration:none;
}

.sidebar a:hover {
    background:#34495e;
}

/* CONTENT */
.content {
    margin-left:220px;
    padding:20px;
    width:100%;
}

/* CARD */
.card {
    background:white;
    padding:20px;
    border-radius:10px;
    width:420px;
    box-shadow:0 2px 8px rgba(0,0,0,0.1);
}

/* INPUT */
input, select {
    width:100%;
    padding:10px;
    margin-bottom:10px;
    border:1px solid #ccc;
    border-radius:5px;
    background:#f9f9f9;
}

/* PAYMENT BOX */
.box {
    background:#f1f1f1;
    padding:10px;
    border-radius:5px;
    margin-bottom:10px;
}

.hidden {
    display:none;
}

/* BUTTON */
button {
    width:100%;
    padding:12px;
    background:#27ae60;
    color:white;
    border:none;
    border-radius:5px;
    cursor:pointer;
}

button:hover {
    background:#219150;
}

.total {
    font-weight:bold;
    color:green;
}
</style>

</head>

<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <h2>Customer</h2>
    <a href="product_screen.php">🏠 Home</a>
    <a href="cart_screen.php">🛒 Cart</a>
    <a href="my_orders.php">📦 Orders</a>
    <a href="notifications.php">🔔 Notifications</a>
    <a href="logout.php">🚪 Logout</a>
</div>

<!-- CONTENT -->
<div class="content">

<h2>Checkout</h2>

<div class="card">

<h3><?= $product['product_name'] ?></h3>

<p>Price: ₱<span id="price"><?= number_format($product['price'],2) ?></span></p>
<p class="total">Total: ₱<span id="total"><?= number_format($product['price'],2) ?></span></p>

<form method="POST" action="buy_now_process.php">

    <input type="hidden" name="product_id" value="<?= $product_id ?>">

    <input type="text" name="fullname" value="<?= htmlspecialchars($username) ?>" readonly>
    <input type="text" name="email" value="<?= htmlspecialchars($email) ?>" readonly>
    <input type="text" name="phone" value="<?= htmlspecialchars($phone) ?>" readonly>
    <input type="text" name="address" value="<?= htmlspecialchars($address) ?>" readonly>

    <input type="number" id="qty" name="quantity" value="1" min="1">

    <!-- 🔽 PAYMENT METHOD -->
    <label>Payment Method</label>
    <select name="payment_method" id="payment" onchange="showPayment()" required>
        <option value="">Select Payment</option>
        <option value="cod">Cash</option>
        <option value="gcash">GCash</option>
        <option value="bank">Bank Payment</option>
    </select>

    <!-- 🚚 COD -->
    <div id="codBox" class="box hidden">
        <b>Delivery Option:</b>
        <select name="delivery_type">
            <option value="lalamove">Lalamove</option>
            <option value="pickup">Pickup (Go to Store)</option>
        </select>
    </div>

    <!-- 💳 GCASH -->
    <div id="gcashBox" class="box hidden">
        <p><b>Name:</b> J*** R** S.</p>
        <p><b>GCash No:</b> 09123456789</p>
        <p>Scan QR below:</p>
        <img src="images/qr.png" width="200">
    </div>

    <!-- 🏦 BANK -->
    <div id="bankBox" class="box hidden">
        <p><b>Bank:</b> BDO</p>
        <p><b>Account Name:</b> J*** R** S.</p>
        <p><b>Account No:</b> 1234567890</p>
        <img src="images/qr.png" width="200">
    </div>

    <button type="submit">Confirm Order</button>

</form>

</div>

</div>

<!-- JS -->
<script>
let price = <?= $product['price'] ?>;

document.getElementById("qty").addEventListener("input", function() {
    let qty = this.value;
    if(qty < 1) qty = 1;

    let total = price * qty;
    document.getElementById("total").innerHTML =
        total.toLocaleString(undefined, {minimumFractionDigits:2});
});

function showPayment() {
    let val = document.getElementById("payment").value;

    document.getElementById("codBox").classList.add("hidden");
    document.getElementById("gcashBox").classList.add("hidden");
    document.getElementById("bankBox").classList.add("hidden");

    if(val === "cod") {
        document.getElementById("codBox").classList.remove("hidden");
    }
    if(val === "gcash") {
        document.getElementById("gcashBox").classList.remove("hidden");
    }
    if(val === "bank") {
        document.getElementById("bankBox").classList.remove("hidden");
    }
}
</script>

</body>
</html>