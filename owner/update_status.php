<?php
session_start();
include 'config.php';
include 'functions.php';
require_once __DIR__ . '/access_control.php';

$isPharmacist = requireOwnerPageAccess($conn, true);
$salesOwnerId = $isPharmacist ? (int)$_SESSION['user_id'] : null;
$salesOwnerSchema = $conn->query('ALTER TABLE orders ADD COLUMN IF NOT EXISTS seller_id INT NULL');
if (!$salesOwnerSchema) {
    error_log('Order sales attribution schema preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to update the order.');
}
$deliveryDateSchema = $conn->query('ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_date DATETIME NULL');
if (!$deliveryDateSchema) {
    error_log('Order completion timestamp schema preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to update the order.');
}
try {
    ensureReadyForPickupOrderStatus($conn);
} catch (RuntimeException $exception) {
    error_log('Order pickup status schema preparation failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to prepare order pickup status.');
}
$isPickupCompletion = $_SERVER['REQUEST_METHOD'] === 'POST';
$id = (int)($isPickupCompletion ? ($_POST['id'] ?? 0) : ($_GET['id'] ?? 0));
$status = strtolower(trim($isPickupCompletion ? ($_POST['status'] ?? '') : ($_GET['status'] ?? '')));
$status = $status === 'completed' ? 'delivered' : $status;
$allowedStatuses = ['pending', 'processing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    header("Location: admin_orders.php");
    exit();
}
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid order.');
}
if ($isPickupCompletion) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['status_update_token'] ?? '');
    if ($status !== 'delivered' || $submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid order completion request.');
    }
}
if ($isPickupCompletion && (
    !$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'")
    || !$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100) NULL")
    || !$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_verified_at DATETIME NULL")
    || !$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_verified_by INT NULL")
)) {
    error_log('Pickup completion payment schema preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to complete the pickup order.');
}

// Get order details including user_id
$stmt = $conn->prepare("SELECT user_id, status, delivery_type, payment_method, payment_status, discount_type, discount_status FROM orders WHERE id=?");
if (!$stmt) {
    error_log('Order status query preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load the order.');
}
$stmt->bind_param("i", $id);
$stmt->execute();
$orderResult = $stmt->get_result();
$order = $orderResult->fetch_assoc();
$stmt->close();

if ($order && $isPharmacist && !pharmacistCanAccessOrder($conn, $id)) {
    http_response_code(403);
    exit('You are not allowed to update this order.');
}

if ($order) {
    $user_id = $order['user_id'];
    $wasAlreadyCancelled = strtolower(trim($order['status'] ?? '')) === 'cancelled';
    $wasAlreadyShipped = strtolower(trim($order['status'] ?? '')) === 'shipped';
    $currentStatus = strtolower(trim((string)($order['status'] ?? '')));
    $isSelfPickup = in_array(strtolower(trim($order['delivery_type'] ?? '')), ['pickup', 'self_pickup', 'self-pickup'], true);
    $needsPaymentApproval = in_array(strtolower(trim($order['payment_method'] ?? '')), ['gcash', 'bank', 'bpi', 'bank transfer'], true)
        && strtolower(trim($order['payment_status'] ?? 'pending')) !== 'paid';
    $needsDiscountReview = in_array($order['discount_type'] ?? '', ['senior_citizen', 'pwd'], true)
        && strtolower(trim($order['discount_status'] ?? 'pending_verification')) === 'pending_verification';
    if (($needsPaymentApproval || $needsDiscountReview) && in_array($status, ['processing', 'ready_for_pickup', 'shipped', 'delivered'], true)) {
        http_response_code(409);
        exit($needsPaymentApproval
            ? 'GCash and bank payments must be approved before processing or delivery.'
            : 'The Senior Citizen/PWD ID must be approved or rejected before processing or delivery.');
    }
    if ($status === 'ready_for_pickup' && (!$isSelfPickup || $currentStatus !== 'processing')) {
        http_response_code(409);
        exit('Only a processing self-pickup order can be marked ready for pickup.');
    }
    if ($status === 'shipped' && $isSelfPickup) {
        http_response_code(409);
        exit('Self-pickup orders cannot be marked shipped.');
    }
    if ($status === 'delivered' && $isSelfPickup && !$isPickupCompletion) {
        http_response_code(405);
        exit('Use the confirmed pickup completion action.');
    }
    if ($isPickupCompletion && (
        !$isSelfPickup
        || $currentStatus !== 'ready_for_pickup'
        || in_array(strtolower(trim($order['status'] ?? '')), ['delivered', 'cancelled'], true)
    )) {
        http_response_code(409);
        exit('This pickup order must be Ready for Pickup before it can be completed.');
    }
    $isCodSelfPickupCompletion = $isPickupCompletion
        && $status === 'delivered'
        && $isSelfPickup
        && strtolower(trim($order['payment_method'] ?? '')) === 'cod';
    $cashCollectedUpdate = $status === 'delivered'
        && in_array(strtolower(trim($order['payment_method'] ?? '')), ['cod', 'cash'], true)
        ? ", payment_status = 'paid', payment_reference = COALESCE(NULLIF(payment_reference, ''), 'Cash collected at delivery'), payment_verified_at = COALESCE(payment_verified_at, NOW())"
        : '';
    $pickupDeadlineUpdate = $status === 'ready_for_pickup'
        ? ", pickup_deadline = DATE_ADD(NOW(), INTERVAL 24 HOUR)"
        : '';
    $completionTimestampUpdate = $status === 'delivered'
        ? ", delivery_date = COALESCE(delivery_date, NOW())"
        : '';
    $requiredCurrentStatus = $status === 'ready_for_pickup'
        ? " AND LOWER(TRIM(status)) = 'processing'"
        : ($isPickupCompletion ? " AND LOWER(TRIM(status)) = 'ready_for_pickup'" : '');

    if ($isCodSelfPickupCompletion) {
        $stmt = $conn->prepare(
            "UPDATE orders
             SET status = ?$completionTimestampUpdate, payment_status = 'paid',
                 payment_reference = 'COD Self Pick Up Cash Collected',
                 payment_verified_at = NOW(), payment_verified_by = ?,
                 seller_id = COALESCE(seller_id, ?)
             WHERE id = ?$requiredCurrentStatus"
        );
        if (!$stmt) {
            error_log('COD pickup completion update preparation failed: ' . $conn->error);
            http_response_code(500);
            exit('Unable to complete the pickup order.');
        }
        $adminId = (int)$_SESSION['user_id'];
        $stmt->bind_param('siii', $status, $adminId, $salesOwnerId, $id);
    } else {
        $attributeSales = $isPharmacist && in_array($status, ['processing', 'shipped', 'delivered'], true);
        $stmt = $conn->prepare(
            $attributeSales
                ? "UPDATE orders SET status = ?$pickupDeadlineUpdate$completionTimestampUpdate$cashCollectedUpdate, seller_id = COALESCE(seller_id, ?) WHERE id = ?$requiredCurrentStatus"
                : "UPDATE orders SET status = ?$pickupDeadlineUpdate$completionTimestampUpdate$cashCollectedUpdate WHERE id = ?$requiredCurrentStatus"
        );
        if (!$stmt) {
            error_log('Order status update preparation failed: ' . $conn->error);
            http_response_code(500);
            exit('Unable to update the order.');
        }
        if ($attributeSales) {
            $stmt->bind_param('sii', $status, $salesOwnerId, $id);
        } else {
            $stmt->bind_param('si', $status, $id);
        }
    }
    if (!$stmt->execute()) {
        error_log('Order status update failed: ' . $stmt->error);
        $stmt->close();
        http_response_code(500);
        exit('Unable to update the order.');
    }
    if (($isPickupCompletion || $status === 'ready_for_pickup') && $stmt->affected_rows !== 1) {
        $stmt->close();
        http_response_code(409);
        exit('The order status changed. Refresh and try again.');
    }
    $stmt->close();
    if ($currentStatus !== $status) {
        writeAuditLog($conn, 'order_status_updated', 'order', (int)$id, [
            'from_status' => $currentStatus,
            'to_status' => $status,
        ]);
    }

    if ($status === 'cancelled' && !$wasAlreadyCancelled) {
        restoreInventoryForCancelledOrder($conn, $id);
    }
    
    // Create notification for user
    $statusMessage = $status === 'ready_for_pickup' ? 'ready for pickup' : ucfirst(strtolower($status));
    $message = $status === 'ready_for_pickup'
        ? 'Your order ' . formatOrderNumber($conn, (int)$id) . ' is ' . $statusMessage . '.'
        : 'Your order ' . formatOrderNumber($conn, (int)$id) . ' has been ' . $statusMessage;
    $now = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $message, $now);
    $stmt->execute();

    if ($status === 'shipped' && !$wasAlreadyShipped) {
        sendOrderShippedEmail($conn, $id);
    }
    if ($status === 'delivered') {
        awardLoyaltyPointsForOrder($conn, $id, $_SESSION['user_id'] ?? null);
    }
}

header("Location: admin_orders.php?order=" . $id);
exit();
?>