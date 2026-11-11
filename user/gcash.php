<?php session_start(); ?>

<!DOCTYPE html>
<html>
<head>
<title>GCash</title>
<style>
body { font-family:Arial; background:#f4f6f8; display:flex; justify-content:center; padding:50px; }
.box { background:white; padding:20px; border-radius:10px; width:400px; text-align:center; }
img { width:200px; margin:10px 0; }
button { width:100%; padding:10px; }
</style>
</head>

<body>

<div class="box">

<h2>💳 GCash Payment</h2>

<p><b>Name:</b> Juan Dela Cruz</p>
<p><b>GCash Number:</b> 09123456789</p>

<!-- QR CODE IMAGE -->
<img src="images/gcash_qr.png" alt="GCash QR">

<form action="place_order.php" method="POST">
    <button type="submit">I have paid</button>
</form>

</div>

</body>
</html>