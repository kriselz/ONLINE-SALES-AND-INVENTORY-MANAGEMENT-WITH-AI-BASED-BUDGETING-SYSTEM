<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

$product_ids = $_POST['product_ids'] ?? [];
$product_ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));

if (!empty($product_ids)) {
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $types = 'i' . str_repeat('i', count($product_ids));
    $params = array_merge([$user_id], $product_ids);

    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id IN ($placeholders)");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

// Redirect back to cart
header('Location: cart_screen.php');
exit();
?>
