<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$user_check = $conn->query("SELECT is_active FROM users WHERE id = $user_id");
if (!$user_check || $user_check->num_rows === 0) {
    session_destroy();
    header('Location: login.php');
    exit();
}

if ((int)($user_check->fetch_assoc()['is_active'] ?? 0) !== 1) {
    $_SESSION['error_message'] = 'Your account is inactive. Please contact support.';
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // 1. SESSION CHECK
    if (!isset($_SESSION['user_id'])) {
        die("User not logged in!");
    }

    $user_id = (int) $_SESSION['user_id'];
    $product_id = (int) $_POST['product_id'];

    // 2. INPUT SANITIZATION
    $box = 0; // Defaulting to 0 as per your previous logic
    $pieces = (int)($_POST['quantity'] ?? 1);
    $fullname = $conn->real_escape_string($_POST['fullname']);
    $address  = $conn->real_escape_string($_POST['address']);
    $coupon_code = trim($_POST['coupon_code'] ?? '');

    // 3. GET PRODUCT DETAILS
    $productQuery = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $productQuery->bind_param("i", $product_id);
    $productQuery->execute();
    $productResult = $productQuery->get_result();

    if (!$productResult || $productResult->num_rows == 0) {
        die("Product not found! Check if ID $product_id exists in the database.");
    }

    $product = $productResult->fetch_assoc();
    $product_name = $product['product_name'];
    $price_piece  = (float)($product['price_piece'] ?? $product['price']); // Fallback to 'price' if price_piece is missing
    $pieces_per_box = (int)($product['pieces_per_box'] ?? 12);
    $price_box = round($price_piece * max(1, $pieces_per_box) * 0.98, 2);

    // 4. CALCULATE TOTALS
    $qty = ($box * $pieces_per_box) + $pieces;
    $subtotal = ($box * $price_box) + ($pieces * $price_piece);

    $discount_amount = 0.00;
    $final_total = $subtotal;
    $coupon = null;

    // 5. COUPON LOGIC
    if (!empty($coupon_code)) {
        $couponQuery = $conn->prepare("SELECT *
            FROM coupons
            WHERE code = ? AND (expiry_date IS NULL OR expiry_date > NOW())
                AND (usage_limit IS NULL OR used_count < usage_limit)");
        $couponQuery->bind_param('s', $coupon_code);
        $couponQuery->execute();
        $couponResult = $couponQuery->get_result();

        if ($couponResult && $couponResult->num_rows > 0) {
            $coupon = $couponResult->fetch_assoc();
            if ($subtotal >= $coupon['min_order']) {
                if ($coupon['discount_type'] == 'percent') {
                    $discount_amount = $subtotal * ($coupon['discount_value'] / 100);
                } else {
                    $discount_amount = min($coupon['discount_value'], $subtotal);
                }
                $final_total = $subtotal - $discount_amount;
            }
        }
    }

    // ✅ 6. CREATE ORDER (Simplified to standard columns)
    $order_sql = "INSERT INTO orders (user_id, fullname, address, order_date, status, total_amount)
                VALUES ($user_id, '$fullname', '$address', NOW(), 'Pending', $final_total)";

    if ($conn->query($order_sql)) {
        $order_id = $conn->insert_id; // This generates the ID needed for the next step
    } else {
        die("Order Creation Failed: " . $conn->error);
    }

    // ✅ 7. INSERT ORDER ITEM
    // Check if your order_items table has columns: order_id, product_id, price, quantity
    $item_sql = "INSERT INTO order_items (order_id, product_id, price, quantity)
                VALUES ($order_id, $product_id, $price_piece, $qty)";

    if (!$conn->query($item_sql)) {
        die("Order Item Insert Failed: " . $conn->error . " | SQL: " . $item_sql);
    }

    if ($coupon) {
        $couponUse = $conn->prepare("UPDATE coupons SET used_count = used_count + 1
            WHERE id = ? AND (usage_limit IS NULL OR used_count < usage_limit)");
        if ($couponUse) {
            $couponId = (int)$coupon['id'];
            $couponUse->bind_param('i', $couponId);
            if (!$couponUse->execute() || $couponUse->affected_rows !== 1) {
                error_log('Coupon use count could not be updated for order #' . $order_id . ': ' . $conn->error);
            }
            $couponUse->close();
        } else {
            error_log('Coupon use count could not be updated for order #' . $order_id . ': ' . $conn->error);
        }
    }

    // 8. STOCK UPDATE (The likely crash point)
    $stockResult = $conn->query("SELECT box, pieces FROM products WHERE id = $product_id");
    if ($stockResult && $stockResult->num_rows > 0) {
        $stock = $stockResult->fetch_assoc();
        
        $current_total_stock = ((int)$stock['box'] * $pieces_per_box) + (int)$stock['pieces'];
        $new_total_stock = max(0, $current_total_stock - $qty);

        $new_box = intdiv($new_total_stock, $pieces_per_box);
        $new_pieces = $new_total_stock % $pieces_per_box;

        $update_stock = "UPDATE products SET box=$new_box, pieces=$new_pieces, quantity=$new_total_stock WHERE id=$product_id";
        if ($conn->query($update_stock)) {
            try {
                $stockAlert = capstoneCheckAndNotifyStockState($conn, $product_id);
                if (!$stockAlert['success']) {
                    error_log('Post-order inventory alert failed for product #' . $product_id . ': ' . $stockAlert['error']);
                }
            } catch (Throwable $exception) {
                error_log('Post-order inventory alert failed for product #' . $product_id . ': ' . $exception->getMessage());
            }
        }
    } else {
        // If query fails, it might mean 'box' or 'pieces' columns are missing
        error_log("Stock update skipped: " . $conn->error);
    }

    // 9. USER NOTIFICATIONS
    $userResult = $conn->query("SELECT username, email FROM users WHERE id = $user_id");
    if ($userResult && $userResult->num_rows > 0) {
        $userData = $userResult->fetch_assoc();
        $username = $userData['username'];
        $user_email = $userData['email'];

        // Admin Notification
        $admin_msg = "🛒 New Order: $username ordered $product_name - ₱" . number_format($final_total, 2);
        addNotification($conn, $admin_msg);

        // User Notification
        $user_msg = "✅ Your order " . formatOrderNumber($conn, (int)$order_id) . " has been placed successfully!";
        addNotification($conn, $user_msg, $user_id);

        // Email (Wrap in try-catch if function exists)
        if (function_exists('sendOrderConfirmationEmail')) {
            sendOrderConfirmationEmail($conn, $order_id, $user_email, $fullname);
        }
    }

    // 10. SUCCESS REDIRECT
    header("Location: success.php?order_id=" . $order_id);
    exit();
}