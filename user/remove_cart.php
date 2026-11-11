<?php
session_start();
include 'config.php';

$user_id = $_SESSION['user_id'] ?? 1;
$product_id = (int) $_GET['id'];

$conn->query("DELETE FROM cart WHERE user_id=$user_id AND product_id=$product_id");

header("Location: cart_screen.php");
exit();
?>