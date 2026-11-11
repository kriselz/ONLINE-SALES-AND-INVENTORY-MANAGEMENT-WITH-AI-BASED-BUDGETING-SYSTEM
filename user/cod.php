<?php session_start(); ?>

<!DOCTYPE html>
<html>
<head>
<title>COD</title>
<style>
body { font-family:Arial; background:#f4f6f8; display:flex; justify-content:center; padding:50px; }
.box { background:white; padding:20px; border-radius:10px; width:400px; }
button { width:100%; padding:10px; margin-top:10px; }
</style>
</head>

<body>

<div class="box">
<h2>🚚 Cash on Delivery</h2>

<form action="place_order.php" method="POST">
<input type="hidden" name="payment_method" value="cod">

<label>Delivery Option</label>
<select name="delivery_type" required>
    <option value="lalamove">Lalamove Delivery</option>
    <option value="pickup">Pickup (Go to Store)</option>
</select>

<button type="submit">Confirm Order</button>

</form>
</div>

</body>
</html>