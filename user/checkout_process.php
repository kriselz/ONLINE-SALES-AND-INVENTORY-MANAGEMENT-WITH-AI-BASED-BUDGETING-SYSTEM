<?php
session_start();
include 'config.php';

$user_id = $_SESSION['user_id'];

$fullname = $conn->real_escape_string($_POST['fullname']);
$address = $conn->real_escape_string($_POST['address']);

// INSERT ORDER
$conn->query("INSERT INTO orders (user_id, fullname, address, order_date)
              VALUES ($user_id, '$fullname', '$address', NOW())");

$order_id = $conn->insert_id;

// GET CART
$cart = $conn->query("
    SELECT * FROM cart WHERE user_id = $user_id
");

while ($item = $cart->fetch_assoc()) {

    $product_id = $item['product_id'];
    $qty = $item['quantity'];

    // GET PRICE
    $product = $conn->query("SELECT price FROM products WHERE id=$product_id")->fetch_assoc();
    $price = $product['price'];

    // INSERT ORDER ITEM
    $conn->query("INSERT INTO order_items (order_id, product_id, quantity, price)
                  VALUES ($order_id, $product_id, $qty, $price)");

    // UPDATE STOCK
    $conn->query("UPDATE products SET quantity = quantity - $qty WHERE id=$product_id");
}

// CLEAR CART
$conn->query("DELETE FROM cart WHERE user_id=$user_id");

// Show success message
?>
<!DOCTYPE html>
<html>
<head>
<title>Order Processed</title>
<style>
body {
    font-family: Arial, sans-serif;
    text-align: center;
    padding: 50px;
    background: #f4f6f8;
}
.success {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: inline-block;
}
</style>
</head>
<body>
<div class="success">
<h2>Order Placed Successfully!</h2>
<p>Your order has been processed. Redirecting to your orders...</p>
</div>
<script>
setTimeout(function() {
    window.location.href = 'my_orders.php';
}, 3000);
</script>
</body>
</html>
<?php
exit();
?>