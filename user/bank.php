<?php session_start(); ?>

<!DOCTYPE html>
<html>
<head>
<title>Bank Payment</title>
<style>
body { font-family:Arial; background:#f4f6f8; display:flex; justify-content:center; padding:50px; }
.box { background:white; padding:20px; border-radius:10px; width:400px; }
button { width:100%; padding:10px; }
</style>
</head>

<body>

<div class="box">

<h2>🏦 Bank Payment</h2>

<p><b>Bank:</b> BDO</p>
<p><b>Account Name:</b> Juan Dela Cruz</p>
<p><b>Account Number:</b> 1234567890</p>

<form action="place_order.php" method="POST">
    <button type="submit">I have paid</button>
</form>

</div>

</body>
</html>