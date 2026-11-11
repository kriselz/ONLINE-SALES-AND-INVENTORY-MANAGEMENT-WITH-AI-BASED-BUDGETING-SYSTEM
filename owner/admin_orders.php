<?php
session_start();
include 'config.php';
date_default_timezone_set('Asia/Manila');
include 'functions.php';
require_once __DIR__ . '/../rider/delivery_helpers.php';
require_once __DIR__ . '/../user/discount_cooldown.php';
require_once __DIR__ . '/access_control.php';

$isPharmacist = requireOwnerPageAccess($conn, true);
if (empty($_SESSION['status_update_token'])) {
    $_SESSION['status_update_token'] = bin2hex(random_bytes(32));
}
$statusUpdateToken = $_SESSION['status_update_token'];
$paymentReviewNotice = $_SESSION['payment_review_notice'] ?? '';
unset($_SESSION['payment_review_notice']);

// Keep prescription review fields available on existing installations.
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS prescription_image VARCHAR(255) NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS prescription_status VARCHAR(20) NOT NULL DEFAULT 'none'");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS prescription_reviewed_at DATETIME NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS prescription_reviewed_by INT NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS schedule_type VARCHAR(20) NOT NULL DEFAULT 'delivery'");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS schedule_date DATE NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS schedule_time TIME NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_url VARCHAR(500) NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS courier_name VARCHAR(100) NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(30) NOT NULL DEFAULT 'in_transit'");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_failure_reason VARCHAR(100) NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_action VARCHAR(100) NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_transaction_id VARCHAR(100) NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100) NULL");
$paymentReferenceSchemaReady = $conn->query("ALTER TABLE orders MODIFY COLUMN payment_reference VARCHAR(500) NULL");
if (!$paymentReferenceSchemaReady) {
    error_log('Unable to expand payment rejection details: ' . $conn->error);
}
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_verified_at DATETIME NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_verified_by INT NULL");
$discountSchemaReady = $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_type VARCHAR(30) NULL")
    && $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_id_proof VARCHAR(255) NULL")
    && $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_status VARCHAR(30) NOT NULL DEFAULT 'not_applicable'")
    && $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00")
    && $conn->query(
        "CREATE TABLE IF NOT EXISTS order_discount_ids (
            order_id INT NOT NULL PRIMARY KEY,
            image_data LONGBLOB NOT NULL,
            image_mime VARCHAR(50) NOT NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_order_discount_ids_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
if (!$discountSchemaReady) {
    error_log('Unable to prepare discount review fields: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare discount review.');
}
try {
    ensureDiscountCooldownSchema($conn);
} catch (RuntimeException $exception) {
    error_log('Unable to prepare discount usage cooldown: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to prepare discount review.');
}
$pendingDiscountOrders = $conn->query(
    "UPDATE orders SET status = 'pending'
     WHERE discount_type IN ('senior_citizen', 'pwd')
       AND LOWER(COALESCE(discount_status, 'pending_verification')) = 'pending_verification'
       AND rider_id IS NULL
       AND LOWER(COALESCE(status, '')) IN ('processing', 'shipped')"
);
if (!$pendingDiscountOrders) {
    error_log('Unable to hold orders with pending discount verification: ' . $conn->error);
    http_response_code(500);
    exit('Unable to hold orders pending discount verification.');
}
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS seller_id INT NULL");
$staleOnlineOrders = $conn->query("UPDATE orders SET status = 'pending' WHERE LOWER(COALESCE(payment_method, '')) IN ('gcash', 'bank', 'bpi', 'bank transfer') AND LOWER(COALESCE(payment_status, 'unpaid')) <> 'paid' AND rider_id IS NULL AND LOWER(COALESCE(status, '')) IN ('processing', 'shipped')");
if (!$staleOnlineOrders) {
    error_log('Unable to normalize unapproved online-payment order statuses: ' . $conn->error);
}
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00");
$voucherSchemaReady = $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(50) NULL")
    && $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00");
if (!$voucherSchemaReady) {
    error_log('Unable to prepare order voucher fields: ' . $conn->error);
    http_response_code(500);
    exit('Unable to prepare order voucher details.');
}
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS rider_id INT NULL");
$conn->query("CREATE TABLE IF NOT EXISTS rider_profiles (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, phone VARCHAR(30) NULL, vehicle_type VARCHAR(50) NULL, plate_number VARCHAR(30) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
ensureRiderDeliverySchema($conn);
try {
    ensureReadyForPickupOrderStatus($conn);
} catch (RuntimeException $exception) {
    error_log('Order pickup status schema preparation failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to prepare order pickup status.');
}
expireSelfPickupOrders($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['order'])) {
    $openedOrderId = (int)$_GET['order'];
    $startPickupFromAction = ($_GET['start_pickup'] ?? '') === '1';
    $canOpenOrderAction = $openedOrderId > 0
        && (in_array($_SESSION['role'] ?? '', ['admin', 'owner'], true)
            || ($isPharmacist && pharmacistCanAccessOrder($conn, $openedOrderId)));
    if ($canOpenOrderAction && $startPickupFromAction) {
        $pickupOrderStarted = false;
        if ($isPharmacist) {
            $markPickupProcessing = $conn->prepare(
                "UPDATE orders
                 SET status = 'processing', seller_id = COALESCE(seller_id, ?)
                 WHERE id = ?
                   AND LOWER(TRIM(COALESCE(delivery_type, ''))) IN ('pickup', 'self_pickup', 'self-pickup')
                   AND LOWER(TRIM(COALESCE(status, ''))) = 'pending'
                   AND (
                       LOWER(TRIM(COALESCE(payment_method, ''))) IN ('cod', 'cash')
                       OR (LOWER(TRIM(COALESCE(payment_method, ''))) IN ('gcash', 'bank', 'bpi', 'bank transfer')
                           AND LOWER(TRIM(COALESCE(payment_status, 'unpaid'))) = 'paid')
                   )
                   AND (
                       COALESCE(discount_type, '') NOT IN ('senior_citizen', 'pwd')
                       OR LOWER(TRIM(COALESCE(discount_status, 'pending_verification'))) IN ('approved', 'rejected')
                   )"
            );
            if (!$markPickupProcessing) {
                error_log('Self-pickup order processing preparation failed: ' . $conn->error);
                http_response_code(500);
                exit('Unable to process the self-pickup order.');
            }
            $operatorId = (int)$_SESSION['user_id'];
            $markPickupProcessing->bind_param('ii', $operatorId, $openedOrderId);
        } else {
            $markPickupProcessing = $conn->prepare(
                "UPDATE orders
                 SET status = 'processing'
                 WHERE id = ?
                   AND LOWER(TRIM(COALESCE(delivery_type, ''))) IN ('pickup', 'self_pickup', 'self-pickup')
                   AND LOWER(TRIM(COALESCE(status, ''))) = 'pending'
                   AND (
                       LOWER(TRIM(COALESCE(payment_method, ''))) IN ('cod', 'cash')
                       OR (LOWER(TRIM(COALESCE(payment_method, ''))) IN ('gcash', 'bank', 'bpi', 'bank transfer')
                           AND LOWER(TRIM(COALESCE(payment_status, 'unpaid'))) = 'paid')
                   )
                   AND (
                       COALESCE(discount_type, '') NOT IN ('senior_citizen', 'pwd')
                       OR LOWER(TRIM(COALESCE(discount_status, 'pending_verification'))) IN ('approved', 'rejected')
                   )"
            );
            if (!$markPickupProcessing) {
                error_log('Self-pickup order processing preparation failed: ' . $conn->error);
                http_response_code(500);
                exit('Unable to process the self-pickup order.');
            }
            $markPickupProcessing->bind_param('i', $openedOrderId);
        }
        if (!$markPickupProcessing->execute()) {
            error_log('Self-pickup order processing failed: ' . $markPickupProcessing->error);
            $markPickupProcessing->close();
            http_response_code(500);
            exit('Unable to process the self-pickup order.');
        }
        $pickupOrderStarted = $markPickupProcessing->affected_rows > 0;
        $markPickupProcessing->close();
    }

    if ($canOpenOrderAction && $startPickupFromAction) {
        $salesOwnerUpdate = $isPharmacist ? ', seller_id = COALESCE(seller_id, ?)' : '';
        $markCodProcessing = $conn->prepare(
            "UPDATE orders
             SET status = 'processing'$salesOwnerUpdate
             WHERE id = ?
               AND rider_id IS NULL
               AND LOWER(TRIM(COALESCE(payment_method, ''))) IN ('cod', 'cash')
               AND LOWER(TRIM(COALESCE(delivery_type, ''))) NOT IN ('pickup', 'self_pickup', 'self-pickup', 'onsite', 'private')
               AND LOWER(TRIM(COALESCE(status, ''))) = 'pending'
               AND (
                   COALESCE(discount_type, '') NOT IN ('senior_citizen', 'pwd')
                   OR LOWER(TRIM(COALESCE(discount_status, ''))) IN ('approved', 'rejected')
               )"
        );
        if (!$markCodProcessing) {
            error_log('COD order processing update preparation failed: ' . $conn->error);
            http_response_code(500);
            exit('Unable to process the COD order.');
        }
        if ($isPharmacist) {
            $pharmacistId = (int)$_SESSION['user_id'];
            $markCodProcessing->bind_param('ii', $pharmacistId, $openedOrderId);
        } else {
            $markCodProcessing->bind_param('i', $openedOrderId);
        }
        if (!$markCodProcessing->execute()) {
            error_log('COD order processing update failed: ' . $markCodProcessing->error);
            $markCodProcessing->close();
            http_response_code(500);
            exit('Unable to process the COD order.');
        }
        $pickupOrderStarted = $pickupOrderStarted || $markCodProcessing->affected_rows > 0;
        $markCodProcessing->close();
        if ($pickupOrderStarted) {
            writeAuditLog($conn, 'order_processing_started', 'order', $openedOrderId, [
                'from_status' => 'pending',
                'to_status' => 'processing',
            ]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_payment') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $isPaymentReviewer = in_array($_SESSION['role'] ?? '', ['admin', 'owner'], true)
        || ($isPharmacist && $orderId > 0 && pharmacistCanAccessOrder($conn, $orderId));
    if (!$isPaymentReviewer || $submittedToken === '' || !hash_equals($statusUpdateToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid payment review request.');
    }
    if ($orderId < 1 || !in_array($decision, ['approved', 'rejected'], true)) {
        http_response_code(400);
        exit('Invalid payment review decision.');
    }

    $allowedRejectionReasons = [
        'Incorrect Amount',
        'Unverified Payment',
        'Invalid Reference Number',
        'Duplicate Receipt',
        'Incomplete Receipt',
        'Blurry or Unclear Image',
        'Payment Not Received',
        'Wrong Recipient Account',
        'Other',
    ];
    $selectedRejectionReason = trim((string)($_POST['rejection_reason'] ?? ''));
    $additionalRemarks = trim((string)($_POST['rejection_remarks'] ?? ''));
    $remarksLength = function_exists('mb_strlen')
        ? mb_strlen($additionalRemarks, 'UTF-8')
        : strlen($additionalRemarks);
    $rejectionReason = $selectedRejectionReason;
    if ($additionalRemarks !== '') {
        $rejectionReason .= ' - Additional remarks: ' . $additionalRemarks;
    }
    if ($decision === 'rejected'
        && (!in_array($selectedRejectionReason, $allowedRejectionReasons, true)
            || $remarksLength > 300
            || (!$paymentReferenceSchemaReady && $additionalRemarks !== ''))) {
        $_SESSION['payment_review_notice'] = !$paymentReferenceSchemaReady
            ? 'Unable to prepare payment rejection details. Please contact an administrator.'
            : 'Select a valid rejection reason and keep additional remarks under 300 characters.';
    } else {
        $paymentStatus = $decision === 'approved' ? 'paid' : 'rejected';
        $paymentReference = $decision === 'rejected' ? $rejectionReason : '';
        $verifiedAt = date('Y-m-d H:i:s');
        $reviewedBy = (int)$_SESSION['user_id'];
        $salesOwnerId = $decision === 'approved' && $isPharmacist ? (int)$_SESSION['user_id'] : null;
        $orderStatus = $decision === 'approved' ? 'processing' : 'cancelled';
        $review = $conn->prepare("UPDATE orders SET payment_status = ?, payment_reference = ?, payment_verified_at = ?, payment_verified_by = ?, status = CASE WHEN ? = 'processing' AND discount_type IN ('senior_citizen', 'pwd') AND LOWER(COALESCE(discount_status, 'pending_verification')) = 'pending_verification' AND LOWER(COALESCE(payment_method, '')) NOT IN ('gcash', 'bank', 'bpi', 'bank transfer') THEN 'pending' ELSE ? END, seller_id = COALESCE(seller_id, ?) WHERE id = ? AND LOWER(COALESCE(payment_method, '')) IN ('gcash', 'bank', 'bpi', 'bank transfer') AND LOWER(COALESCE(payment_status, 'pending')) NOT IN ('paid', 'rejected') AND rider_id IS NULL AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'delivered')");
        if (!$review) {
            error_log('Payment review update preparation failed: ' . $conn->error);
            $_SESSION['payment_review_notice'] = 'Unable to prepare payment review.';
        } else {
            $review->bind_param('sssissii', $paymentStatus, $paymentReference, $verifiedAt, $reviewedBy, $orderStatus, $orderStatus, $salesOwnerId, $orderId);
            if (!$review->execute()) {
                error_log('Payment review update failed: ' . $review->error);
                $_SESSION['payment_review_notice'] = 'Unable to update payment status.';
            } elseif ($review->affected_rows > 0) {
                writeAuditLog($conn, 'payment_' . $decision, 'order', $orderId, [
                    'decision' => $decision,
                    'payment_status' => $paymentStatus,
                    'order_status' => $orderStatus,
                    'reason' => $decision === 'rejected' ? $rejectionReason : null,
                ]);
                if ($decision === 'rejected' && !restoreInventoryForCancelledOrder($conn, $orderId)) {
                    error_log('Inventory restoration failed after payment rejection for order #' . $orderId);
                }
                $_SESSION['payment_review_notice'] = $decision === 'approved'
                    ? ($orderStatus === 'processing'
                        ? 'Payment approved. This order is now available to riders.'
                        : 'Payment approved. The order will be processed after the discount ID is reviewed.')
                    : 'Payment rejected. The order was cancelled and will not be offered to riders.';
                $emailResult = sendCustomerOrderReviewEmail($conn, $orderId, 'payment', $decision, $rejectionReason);
                $_SESSION['payment_review_notice'] .= !empty($emailResult['success'])
                    ? ' Customer email sent.'
                    : ' Customer email could not be sent; check the customer account email and SMTP settings.';
                $userResult = $conn->prepare('SELECT user_id FROM orders WHERE id = ? LIMIT 1');
                if ($userResult) {
                    $userResult->bind_param('i', $orderId);
                    if ($userResult->execute()) {
                        $userRow = $userResult->get_result()->fetch_assoc();
                        if (!empty($userRow['user_id'])) {
                            $noticeText = $decision === 'approved'
                                ? 'Payment for order ' . formatOrderNumber($conn, $orderId) . ' was approved. ' . ($orderStatus === 'processing' ? 'Your order is now processing.' : 'Your order will be processed after the discount ID is reviewed.')
                                : 'Payment for order ' . formatOrderNumber($conn, $orderId) . ' was rejected and the order was cancelled. Reason: ' . $rejectionReason;
                            $notice = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())');
                            if ($notice) {
                                $notice->bind_param('is', $userRow['user_id'], $noticeText);
                                if (!$notice->execute()) {
                                    error_log('Payment review notification insert failed: ' . $notice->error);
                                }
                                $notice->close();
                            } else {
                                error_log('Payment review notification preparation failed: ' . $conn->error);
                            }
                        }
                    } else {
                        error_log('Payment review customer lookup failed: ' . $userResult->error);
                    }
                    $userResult->close();
                } else {
                    error_log('Payment review customer lookup preparation failed: ' . $conn->error);
                }
            } else {
                $_SESSION['payment_review_notice'] = 'Payment was not changed. Check that the order is GCash/BPI, not assigned, and still active.';
            }
            $review->close();
        }
    }
    header('Location: admin_orders.php?order=' . $orderId);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_discount') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'owner'], true)
        && !($isPharmacist && pharmacistCanAccessOrder($conn, $orderId))) {
        http_response_code(403);
        exit('You are not allowed to review this discount.');
    }
    if ($submittedToken === ''
        || !hash_equals($statusUpdateToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid discount review request.');
    }
    if ($orderId < 1 || !in_array($decision, ['approved', 'rejected'], true)) {
        http_response_code(400);
        exit('Invalid discount review decision.');
    }

    $notice = '';
    if (!$conn->begin_transaction()) {
        error_log('Unable to start discount review transaction: ' . $conn->error);
        $notice = 'Unable to review this discount right now.';
    } else {
        try {
            $orderStmt = $conn->prepare(
                "SELECT user_id, discount_type, discount_status, discount_id_proof, shipping_fee, payment_method, payment_status, status
                 FROM orders WHERE id = ? FOR UPDATE"
            );
            if (!$orderStmt) {
                throw new RuntimeException('Unable to prepare discount order lookup.');
            }
            $orderStmt->bind_param('i', $orderId);
            if (!$orderStmt->execute()) {
                throw new RuntimeException('Unable to load the discount order.');
            }
            $discountOrder = $orderStmt->get_result()->fetch_assoc();
            $orderStmt->close();
            if (!$discountOrder
                || !in_array(strtolower(trim((string)($discountOrder['payment_method'] ?? ''))), ['cod', 'cash'], true)
                || !in_array($discountOrder['discount_type'] ?? '', ['senior_citizen', 'pwd'], true)
                || trim((string)($discountOrder['discount_id_proof'] ?? '')) === ''
                || strtolower(trim((string)($discountOrder['discount_status'] ?? ''))) !== 'pending_verification') {
                throw new RuntimeException('Discount review is only available for COD orders with a pending ID verification.');
            }

            if ($decision === 'approved') {
                $userLockStmt = $conn->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
                if (!$userLockStmt) {
                    throw new RuntimeException('Unable to lock the customer discount record.');
                }
                $discountUserId = (int)$discountOrder['user_id'];
                $userLockStmt->bind_param('i', $discountUserId);
                if (!$userLockStmt->execute()) {
                    throw new RuntimeException('Unable to lock the customer discount record.');
                }
                $lockedUser = $userLockStmt->get_result()->fetch_assoc();
                $userLockStmt->close();
                if (!$lockedUser) {
                    throw new RuntimeException('The customer account for this order is unavailable.');
                }
                $cooldownExpiry = getDiscountCooldownExpiry($conn, $discountUserId);
                if ($cooldownExpiry !== null) {
                    throw new RuntimeException('This customer already used a discount. It will be available again on ' . $cooldownExpiry . '.');
                }
            }

            $subtotalStmt = $conn->prepare('SELECT COALESCE(SUM(total_price), 0) AS subtotal FROM order_items WHERE order_id = ?');
            if (!$subtotalStmt) {
                throw new RuntimeException('Unable to prepare discount total calculation.');
            }
            $subtotalStmt->bind_param('i', $orderId);
            if (!$subtotalStmt->execute()) {
                throw new RuntimeException('Unable to calculate the order subtotal.');
            }
            $subtotal = (float)($subtotalStmt->get_result()->fetch_assoc()['subtotal'] ?? 0);
            $subtotalStmt->close();
            if ($subtotal <= 0) {
                throw new RuntimeException('This order has no valid item subtotal to discount.');
            }
            $discountAmount = $decision === 'approved' ? round($subtotal * 0.20, 2) : 0.0;
            $shippingFee = (float)($discountOrder['shipping_fee'] ?? 0);
            $newTotal = max(0, $subtotal - $discountAmount + $shippingFee);
            $discountStatus = $decision;
            $canStartProcessing = in_array(strtolower(trim((string)($discountOrder['payment_method'] ?? ''))), ['cod', 'cash'], true)
                || (
                    in_array(strtolower(trim((string)($discountOrder['payment_method'] ?? ''))), ['gcash', 'bank', 'bpi', 'bank transfer'], true)
                    && strtolower(trim((string)($discountOrder['payment_status'] ?? ''))) === 'paid'
                );
            $nextOrderStatus = strtolower(trim((string)($discountOrder['status'] ?? ''))) === 'pending'
                && $canStartProcessing
                ? 'processing'
                : (string)$discountOrder['status'];
            $updateStmt = $conn->prepare(
                "UPDATE orders
                 SET discount_status = ?, discount_amount = ?, total_amount = ?, status = ?,
                     discount_used_at = CASE WHEN ? = 'approved' THEN NOW() ELSE discount_used_at END
                 WHERE id = ? AND discount_status = 'pending_verification'"
            );
            if (!$updateStmt) {
                throw new RuntimeException('Unable to prepare discount review update.');
            }
            $updateStmt->bind_param('sddssi', $discountStatus, $discountAmount, $newTotal, $nextOrderStatus, $discountStatus, $orderId);
            if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
                throw new RuntimeException('The discount could not be updated. Refresh and try again.');
            }
            $updateStmt->close();
            if (!$conn->commit()) {
                throw new RuntimeException('Unable to save the discount decision.');
            }
            writeAuditLog($conn, 'discount_' . $decision, 'order', $orderId, [
                'decision' => $decision,
                'discount_type' => (string)$discountOrder['discount_type'],
                'discount_amount' => $discountAmount,
                'order_status' => $nextOrderStatus,
            ]);
            $notice = $decision === 'approved'
                ? 'Discount approved. A 20% discount was applied to the order subtotal.'
                    . ($nextOrderStatus === 'processing' ? ' The order is now processing.' : ' The order will start processing after payment approval.')
                : 'Discount rejected. No discount was applied.'
                    . ($nextOrderStatus === 'processing' ? ' The order is now processing.' : ' The order will start processing after payment approval.');
            $emailResult = sendCustomerOrderReviewEmail($conn, $orderId, 'discount', $decision);
            $notice .= !empty($emailResult['success'])
                ? ' Customer email sent.'
                : ' Customer email could not be sent; check the customer account email and SMTP settings.';
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Discount review failed for order #' . $orderId . ': ' . $exception->getMessage());
            $notice = $exception->getMessage();
        }
    }
    $_SESSION['payment_review_notice'] = $notice;
    header('Location: admin_orders.php?order=' . $orderId);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_prescription') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $decision = strtolower(trim($_POST['decision'] ?? ''));
    if ($orderId > 0 && in_array($decision, ['accepted', 'rejected'], true)) {
        $review = $conn->prepare("UPDATE orders SET prescription_status = ?, prescription_reviewed_at = NOW(), prescription_reviewed_by = ? WHERE id = ? AND prescription_image IS NOT NULL");
        if ($review) {
            $adminId = (int)$_SESSION['user_id'];
            $review->bind_param('sii', $decision, $adminId, $orderId);
            $prescriptionReviewed = $review->execute() && $review->affected_rows > 0;
            $review->close();

            if ($prescriptionReviewed) {
                writeAuditLog($conn, 'order_prescription_' . $decision, 'order', $orderId, [
                    'decision' => $decision,
                ]);
            }
            $orderUser = $conn->query("SELECT user_id FROM orders WHERE id = $orderId LIMIT 1");
            $userRow = $orderUser ? $orderUser->fetch_assoc() : null;
            if (!empty($userRow['user_id'])) {
                $notice = 'Your prescription for order ' . formatOrderNumber($conn, $orderId) . ' was ' . $decision . '.';
                $noticeStmt = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())');
                if ($noticeStmt) {
                    $noticeStmt->bind_param('is', $userRow['user_id'], $notice);
                    $noticeStmt->execute();
                    $noticeStmt->close();
                }
            }
        }
    }
    header('Location: admin_orders.php?order=' . $orderId);
    exit();
}

// Handle AJAX status update
if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $orderId = (int)$_POST['order_id'];
    $newStatus = $conn->real_escape_string(trim($_POST['status']));
    $allowedStatuses = ['pending', 'processing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled', 'viewing'];

    if (in_array($newStatus, $allowedStatuses)) {
        $trackingUrl = trim($_POST['tracking_url'] ?? '');
        if ($trackingUrl !== '' && !filter_var($trackingUrl, FILTER_VALIDATE_URL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid tracking URL.']);
            exit();
        }
        $previousStatusResult = $conn->query("SELECT status, payment_method, payment_status, delivery_type, discount_type, discount_status FROM orders WHERE id = $orderId LIMIT 1");
        $previousStatusRow = $previousStatusResult ? $previousStatusResult->fetch_assoc() : null;
        $isSelfPickupStatusUpdate = in_array(strtolower(trim($previousStatusRow['delivery_type'] ?? '')), ['pickup', 'self_pickup', 'self-pickup'], true);
        $previousStatus = strtolower(trim((string)($previousStatusRow['status'] ?? '')));
        $needsDiscountReview = in_array($previousStatusRow['discount_type'] ?? '', ['senior_citizen', 'pwd'], true)
            && strtolower(trim((string)($previousStatusRow['discount_status'] ?? 'pending_verification'))) === 'pending_verification';
        $requiresOnlinePaymentApproval = in_array(strtolower(trim($previousStatusRow['payment_method'] ?? '')), ['gcash', 'bank', 'bpi', 'bank transfer'], true)
            && strtolower(trim($previousStatusRow['payment_status'] ?? 'pending')) !== 'paid';
        if (($newStatus === 'ready_for_pickup' && (!$isSelfPickupStatusUpdate || $previousStatus !== 'processing'))
            || ($newStatus === 'shipped' && $isSelfPickupStatusUpdate)
            || ($newStatus === 'delivered' && $isSelfPickupStatusUpdate)) {
            echo json_encode(['success' => false, 'message' => 'Self-pickup orders must be marked Ready for Pickup before completion.']);
            exit();
        }
        if (($requiresOnlinePaymentApproval || $needsDiscountReview) && in_array($newStatus, ['processing', 'ready_for_pickup', 'shipped', 'delivered'], true)) {
            echo json_encode([
                'success' => false,
                'message' => $requiresOnlinePaymentApproval
                    ? 'GCash and bank orders must be approved before processing or delivery.'
                    : 'The Senior Citizen/PWD ID must be approved or rejected before processing or delivery.'
            ]);
            exit();
        }
        $wasAlreadyCancelled = strtolower(trim($previousStatusRow['status'] ?? '')) === 'cancelled';
        $wasAlreadyShipped = strtolower(trim($previousStatusRow['status'] ?? '')) === 'shipped';
        $cashCollectedUpdate = $newStatus === 'delivered'
            && in_array(strtolower(trim($previousStatusRow['payment_method'] ?? '')), ['cod', 'cash'], true)
            ? ", payment_status = 'paid', payment_reference = COALESCE(NULLIF(payment_reference, ''), 'Cash collected at delivery'), payment_verified_at = COALESCE(payment_verified_at, NOW())"
            : '';
        $pickupDeadlineUpdate = $newStatus === 'ready_for_pickup'
            ? ", pickup_deadline = DATE_ADD(NOW(), INTERVAL 24 HOUR)"
            : '';
        $safeTrackingUrl = $conn->real_escape_string($trackingUrl);
        $trackingUpdate = $newStatus === 'shipped' ? ", tracking_url = '$safeTrackingUrl'" : '';
        $salesOwnerUpdate = $isPharmacist && in_array($newStatus, ['processing', 'shipped', 'delivered'], true)
            ? ', seller_id = COALESCE(seller_id, ' . (int)$_SESSION['user_id'] . ')'
            : '';
        if ($conn->query("UPDATE orders SET status = '$newStatus'$pickupDeadlineUpdate$trackingUpdate$cashCollectedUpdate$salesOwnerUpdate WHERE id = $orderId")) {
            if ($newStatus === 'cancelled' && !$wasAlreadyCancelled) {
                restoreInventoryForCancelledOrder($conn, $orderId);
            }
            // Notify the user if status is "viewing"
            if ($newStatus === 'viewing') {
                $orderResult = $conn->query("SELECT user_id FROM orders WHERE id = $orderId");
                if ($orderResult) {
                    $order = $orderResult->fetch_assoc();
                    $userId = $order['user_id'];
                    $message = "🔍 Owner viewed your order " . formatOrderNumber($conn, $orderId);
                    $conn->query("INSERT INTO notifications (user_id, message, created_at) VALUES ($userId, '" . $conn->real_escape_string($message) . "', NOW())");
                }
            }
            if ($newStatus === 'shipped' && !$wasAlreadyShipped) {
                sendOrderShippedEmail($conn, $orderId);
            }
            if ($newStatus === 'delivered') {
                awardLoyaltyPointsForOrder($conn, $orderId, $_SESSION['user_id'] ?? null);
            }
            echo json_encode(['success' => true, 'status' => ucfirst($newStatus)]);
            exit();
        }
    }
    echo json_encode(['success' => false, 'message' => 'Update failed']);
    exit();
}

$dateRange = $_GET['date_range'] ?? 'weekly';
if (!in_array($dateRange, ['weekly', 'monthly', 'yearly', 'custom'], true)) {
    $dateRange = 'weekly';
}
$dateFromInput = trim($_GET['date_from'] ?? '');
$dateToInput = trim($_GET['date_to'] ?? '');
$dateFrom = DateTime::createFromFormat('!Y-m-d', $dateFromInput);
$dateTo = DateTime::createFromFormat('!Y-m-d', $dateToInput);
$dateFromValid = $dateFrom && $dateFrom->format('Y-m-d') === $dateFromInput;
$dateToValid = $dateTo && $dateTo->format('Y-m-d') === $dateToInput;
$customRangeApplied = $dateRange === 'custom' && ($dateFromValid || $dateToValid);
$showCustomDateInputs = $dateRange === 'custom' && !$customRangeApplied;
$customDateRangeLabel = 'Custom Range';
$orderDateFrom = '';
$orderDateTo = '';
if ($dateRange === 'custom') {
    if ($dateFromValid || $dateToValid) {
        $orderDateFrom = $dateFromValid ? $dateFromInput : '';
        $orderDateTo = $dateToValid ? $dateToInput : '';
    }
} else {
    $orderDateTo = date('Y-m-d');
    if ($dateRange === 'weekly') {
        $orderDateFrom = date('Y-m-d', strtotime('-6 days'));
    } elseif ($dateRange === 'monthly') {
        $orderDateFrom = date('Y-m-01');
    } else {
        $orderDateFrom = date('Y-01-01');
    }
}
if ($orderDateFrom !== '' && $orderDateTo !== '' && $orderDateFrom > $orderDateTo) {
    [$orderDateFrom, $orderDateTo] = [$orderDateTo, $orderDateFrom];
}
if ($dateRange === 'custom' && $dateFromValid && $dateToValid) {
    $customDateRangeLabel = date('M j, Y', strtotime($orderDateFrom)) . ' - ' . date('M j, Y', strtotime($orderDateTo));
} elseif ($dateRange === 'custom' && $dateFromValid) {
    $customDateRangeLabel = date('M j, Y', strtotime($dateFromInput));
} elseif ($dateRange === 'custom' && $dateToValid) {
    $customDateRangeLabel = 'Through ' . date('M j, Y', strtotime($dateToInput));
}
$orderDateWhere = '';
if ($dateRange === 'custom' && $dateFromValid && !$dateToValid) {
    $orderDateWhere = "DATE(o.order_date) = '$dateFromInput'";
} elseif ($dateRange === 'custom' && $dateToValid && !$dateFromValid) {
    $orderDateWhere = "DATE(o.order_date) <= '$dateToInput'";
} elseif ($orderDateFrom !== '' && $orderDateTo !== '') {
    $orderDateWhere = "DATE(o.order_date) BETWEEN '$orderDateFrom' AND '$orderDateTo'";
}

$paymentFilter = strtolower(trim($_GET['payment_method'] ?? ''));
$allowedPaymentFilters = ['cash', 'gcash', 'bpi'];
$excludeOnsiteOrders = "LOWER(TRIM(COALESCE(o.payment_method, ''))) <> 'onsite'
    AND LOWER(TRIM(COALESCE(o.delivery_type, ''))) <> 'onsite'";

// Handle export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $search = trim($_GET['q'] ?? '');
    $statusFilter = strtolower(trim($_GET['status'] ?? ''));
    $allowedStatuses = ['pending', 'processing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled'];
    $where = [$excludeOnsiteOrders];

    if ($search !== '') {
        $formattedSearchOrderId = resolveOrderNumber($conn, strtoupper(ltrim($search, '#')));
        if ($formattedSearchOrderId > 0) {
            $where[] = 'o.id = ' . $formattedSearchOrderId;
        } else {
            $safeSearch = $conn->real_escape_string($search);
            $where[] = "(o.id LIKE '%$safeSearch%' OR o.fullname LIKE '%$safeSearch%' OR p.product_name LIKE '%$safeSearch%')";
        }
    }
    if (in_array($statusFilter, $allowedStatuses, true)) {
        $where[] = "LOWER(TRIM(o.status)) = '" . $conn->real_escape_string($statusFilter) . "'";
    }
    if (in_array($paymentFilter, $allowedPaymentFilters, true)) {
        $paymentMethods = $paymentFilter === 'cash' ? ['cod', 'cash'] : ($paymentFilter === 'bpi' ? ['bank', 'bpi', 'bank transfer'] : ['gcash']);
        $where[] = "LOWER(TRIM(COALESCE(o.payment_method, ''))) IN ('" . implode("','", $paymentMethods) . "')";
    }
    if ($orderDateWhere !== '') {
        $where[] = $orderDateWhere;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $exportOrders = $conn->query("
        SELECT o.*, 
               COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(o.fullname), ''), u.username) AS customer_name, 
               u.email, 
               COUNT(oi.id) AS item_count
        FROM orders o 
        LEFT JOIN users u ON u.id = o.user_id 
        LEFT JOIN order_items oi ON oi.order_id = o.id 
        LEFT JOIN products p ON p.id = oi.product_id 
        $whereSql 
        GROUP BY o.id 
        ORDER BY o.id DESC
    ");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders_export_' . date('Y-m-d_H-i-s') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Order ID', 'Customer Name', 'Email', 'Items', 'Total Amount', 'Payment Method', 'Status', 'Order Date']);

    while ($row = $exportOrders->fetch_assoc()) {
        fputcsv($output, [
            formatOrderNumber($conn, (int)$row['id']),
            $row['customer_name'],
            $row['email'],
            'x' . (int)$row['item_count'],
            number_format((float)($row['total_amount'] ?? 0), 2),
            strtoupper($row['payment_method'] ?? 'COD'),
            ucfirst($row['status']),
            date('M d, Y h:i A', strtotime($row['order_date']))
        ]);
    }
    fclose($output);
    exit();
}

function isNewOrderToday(array $order): bool
{
    $orderTimestamp = strtotime((string)($order['order_date'] ?? ''));
    if ($orderTimestamp === false || date('Y-m-d', $orderTimestamp) !== date('Y-m-d')) {
        return false;
    }

    $status = strtolower(trim((string)($order['status'] ?? '')));
    $prescriptionStatus = strtolower(trim((string)($order['prescription_status'] ?? '')));

    return !in_array($status, ['approved', 'completed', 'delivered', 'cancelled'], true)
        && !in_array($prescriptionStatus, ['approved', 'accepted'], true);
}

$currentPage = basename($_SERVER['PHP_SELF']);
$search = trim($_GET['q'] ?? '');
$statusFilter = strtolower(trim($_GET['status'] ?? ''));
$allowedStatuses = ['pending', 'processing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled'];
$paymentFilter = strtolower(trim($_GET['payment_method'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$where = [$excludeOnsiteOrders];

if ($search !== '') {
    $formattedSearchOrderId = resolveOrderNumber($conn, strtoupper(ltrim($search, '#')));
    if ($formattedSearchOrderId > 0) {
        $where[] = 'o.id = ' . $formattedSearchOrderId;
    } else {
        $safeSearch = $conn->real_escape_string($search);
        $where[] = "(o.id LIKE '%$safeSearch%' OR o.fullname LIKE '%$safeSearch%' OR p.product_name LIKE '%$safeSearch%')";
    }
}
if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = "LOWER(TRIM(o.status)) = '" . $conn->real_escape_string($statusFilter) . "'";
}
if (in_array($paymentFilter, $allowedPaymentFilters, true)) {
    $paymentMethods = $paymentFilter === 'cash' ? ['cod', 'cash'] : ($paymentFilter === 'bpi' ? ['bank', 'bpi', 'bank transfer'] : ['gcash']);
    $where[] = "LOWER(TRIM(COALESCE(o.payment_method, ''))) IN ('" . implode("','", $paymentMethods) . "')";
}
if ($orderDateWhere !== '') {
    $where[] = $orderDateWhere;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countResult = $conn->query("
    SELECT COUNT(DISTINCT o.id) AS total 
    FROM orders o 
    LEFT JOIN order_items oi ON oi.order_id = o.id 
    LEFT JOIN products p ON p.id = oi.product_id 
    $whereSql
");
$totalOrders = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
$totalPages = max(1, (int)ceil($totalOrders / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$orders = $conn->query("
    SELECT o.*, 
           COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(o.fullname), ''), u.username) AS customer_name, 
           u.email, 
           GROUP_CONCAT(DISTINCT p.product_name ORDER BY oi.id SEPARATOR ', ') AS product_names, 
           COUNT(oi.id) AS item_count,
           CASE
               WHEN COUNT(oi.id) > 0 THEN COALESCE(
                   NULLIF(o.total_amount, 0),
                   SUM(COALESCE(NULLIF(oi.total_price, 0), oi.price)) + COALESCE(o.shipping_fee, 0)
               )
               ELSE NULLIF(o.total_amount, 0)
           END AS items_total
    FROM orders o 
    LEFT JOIN users u ON u.id = o.user_id 
    LEFT JOIN order_items oi ON oi.order_id = o.id 
    LEFT JOIN products p ON p.id = oi.product_id 
    $whereSql 
    GROUP BY o.id 
    ORDER BY o.id DESC 
    LIMIT $perPage OFFSET $offset
");
$orderRows = [];
if ($orders) {
    while ($order = $orders->fetch_assoc()) {
        $orderRows[] = $order;
    }
}

$statusCounts = [];
$summaryConditions = [$excludeOnsiteOrders];
if ($orderDateWhere !== '') {
    $summaryConditions[] = $orderDateWhere;
}
$summaryDateWhere = 'WHERE ' . implode(' AND ', $summaryConditions);
$statusResult = $conn->query("SELECT LOWER(TRIM(o.status)) AS status, COUNT(*) AS total FROM orders o $summaryDateWhere GROUP BY LOWER(TRIM(o.status))");
if ($statusResult) {
    while ($status = $statusResult->fetch_assoc()) {
        $statusCounts[strtolower($status['status'])] = (int)$status['total'];
    }
}

$totalOrdersResult = $conn->query("SELECT COUNT(*) AS total FROM orders o $summaryDateWhere");
$totalAll = $totalOrdersResult ? (int)$totalOrdersResult->fetch_assoc()['total'] : array_sum($statusCounts);
$comparisonToday = new DateTimeImmutable('today');
$comparisonMonthStart = $comparisonToday->modify('first day of this month');
$comparisonPreviousMonthStart = $comparisonMonthStart->modify('-1 month');
$comparisonPreviousMonthDays = (int)$comparisonPreviousMonthStart->format('t');
$comparisonEndDay = min((int)$comparisonToday->format('j'), $comparisonPreviousMonthDays);
$comparisonPreviousMonthEnd = $comparisonPreviousMonthStart->modify('+' . ($comparisonEndDay - 1) . ' days');
$comparisonTodayValue = $comparisonToday->format('Y-m-d');
$comparisonMonthStartValue = $comparisonMonthStart->format('Y-m-d');
$comparisonPreviousMonthStartValue = $comparisonPreviousMonthStart->format('Y-m-d');
$comparisonPreviousMonthEndValue = $comparisonPreviousMonthEnd->format('Y-m-d');
$monthlyComparisonResult = $conn->query("
    SELECT
        SUM(CASE WHEN DATE(o.order_date) BETWEEN '$comparisonMonthStartValue' AND '$comparisonTodayValue' THEN 1 ELSE 0 END) AS current_total,
        SUM(CASE WHEN DATE(o.order_date) BETWEEN '$comparisonPreviousMonthStartValue' AND '$comparisonPreviousMonthEndValue' THEN 1 ELSE 0 END) AS previous_total,
        SUM(CASE WHEN DATE(o.order_date) BETWEEN '$comparisonMonthStartValue' AND '$comparisonTodayValue' AND LOWER(TRIM(o.status)) = 'pending' THEN 1 ELSE 0 END) AS current_pending,
        SUM(CASE WHEN DATE(o.order_date) BETWEEN '$comparisonPreviousMonthStartValue' AND '$comparisonPreviousMonthEndValue' AND LOWER(TRIM(o.status)) = 'pending' THEN 1 ELSE 0 END) AS previous_pending,
        SUM(CASE WHEN DATE(o.order_date) BETWEEN '$comparisonMonthStartValue' AND '$comparisonTodayValue' AND LOWER(TRIM(o.status)) = 'processing' THEN 1 ELSE 0 END) AS current_processing,
        SUM(CASE WHEN DATE(o.order_date) BETWEEN '$comparisonPreviousMonthStartValue' AND '$comparisonPreviousMonthEndValue' AND LOWER(TRIM(o.status)) = 'processing' THEN 1 ELSE 0 END) AS previous_processing,
        SUM(CASE WHEN DATE(o.order_date) BETWEEN '$comparisonMonthStartValue' AND '$comparisonTodayValue' AND LOWER(TRIM(o.status)) = 'delivered' THEN 1 ELSE 0 END) AS current_delivered,
        SUM(CASE WHEN DATE(o.order_date) BETWEEN '$comparisonPreviousMonthStartValue' AND '$comparisonPreviousMonthEndValue' AND LOWER(TRIM(o.status)) = 'delivered' THEN 1 ELSE 0 END) AS previous_delivered,
        SUM(CASE WHEN DATE(o.order_date) BETWEEN '$comparisonMonthStartValue' AND '$comparisonTodayValue' AND LOWER(TRIM(o.status)) = 'cancelled' THEN 1 ELSE 0 END) AS current_cancelled,
        SUM(CASE WHEN DATE(o.order_date) BETWEEN '$comparisonPreviousMonthStartValue' AND '$comparisonPreviousMonthEndValue' AND LOWER(TRIM(o.status)) = 'cancelled' THEN 1 ELSE 0 END) AS previous_cancelled
    FROM orders o
    WHERE $excludeOnsiteOrders
");
if (!$monthlyComparisonResult) {
    error_log('Monthly order comparison query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load monthly order comparisons.');
}
$monthlyComparison = $monthlyComparisonResult->fetch_assoc();
$selectedId = (int)($_GET['order'] ?? ($orderRows[0]['id'] ?? 0));
$detailsOpen = isset($_GET['order']) && $selectedId > 0;
$selectedOrder = null;
$riderAcceptedAt = null;
if ($selectedId) {
    $selectedResult = $conn->query("
        SELECT o.*, 
               COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(o.fullname), ''), u.username) AS customer_name, 
         u.email, u.phone,
         COALESCE(NULLIF(TRIM(ru.full_name), ''), ru.username) AS assigned_rider_name,
               rp.phone AS assigned_rider_phone,
         rp.vehicle_type AS assigned_vehicle_type,
         rp.plate_number AS assigned_plate_number
        FROM orders o 
        LEFT JOIN users u ON u.id = o.user_id 
     LEFT JOIN users ru ON ru.id = o.rider_id AND ru.role = 'rider'
     LEFT JOIN rider_profiles rp ON rp.user_id = ru.id
     WHERE o.id = $selectedId
       AND $excludeOnsiteOrders
     LIMIT 1
    ");
    $selectedOrder = $selectedResult ? $selectedResult->fetch_assoc() : null;
    if ($selectedOrder && !empty($selectedOrder['rider_id'])) {
        $acceptedStmt = $conn->prepare("SELECT created_at FROM delivery_events WHERE order_id = ? AND rider_id = ? AND event_type = 'rider_assigned' ORDER BY created_at ASC LIMIT 1");
        if ($acceptedStmt) {
            $selectedRiderId = (int)$selectedOrder['rider_id'];
            $acceptedStmt->bind_param('ii', $selectedId, $selectedRiderId);
            if ($acceptedStmt->execute()) {
                $acceptedEvent = $acceptedStmt->get_result()->fetch_assoc();
                $riderAcceptedAt = $acceptedEvent['created_at'] ?? null;
            } else {
                error_log('Admin order rider acceptance lookup failed: ' . $acceptedStmt->error);
            }
            $acceptedStmt->close();
        } else {
            error_log('Admin order rider acceptance lookup preparation failed: ' . $conn->error);
        }
    }
}

$selectedItems = [];
$itemsTotal = 0.0;
if ($selectedOrder) {
    $itemsResult = $conn->query("
        SELECT oi.*, 
             p.product_name,
             p.image AS product_image
        FROM order_items oi 
        LEFT JOIN products p ON p.id = oi.product_id 
        WHERE oi.order_id = $selectedId 
        ORDER BY oi.id ASC
    ");
    if ($itemsResult) {
        while ($item = $itemsResult->fetch_assoc()) {
            $selectedItems[] = $item;
            $itemTotal = (float)($item['total_price'] ?? 0);
            $itemsTotal += $itemTotal > 0 ? $itemTotal : (float)($item['price'] ?? 0);
        }
    }
}

function orderStatusClass($status) {
    $normalizedStatus = strtolower(trim((string)$status));
    return 'status-' . preg_replace('/[^a-z]/', '', $normalizedStatus);
}

function orderStatusLabel($status) {
    $status = strtolower(trim((string)$status));
    return $status === 'ready_for_pickup' ? 'Ready for Pickup' : ucfirst(str_replace('_', ' ', $status));
}

function deliveryTypeLabel($deliveryType) {
    $deliveryType = strtolower(trim((string)$deliveryType));
    if (in_array($deliveryType, ['delivery', 'rider', 'lalamove'], true)) {
        return 'Rider';
    }
    if (in_array($deliveryType, ['pickup', 'self_pickup', 'self-pickup', 'onsite'], true)) {
        return 'Self Pick Up';
    }
    return $deliveryType !== '' ? ucwords(str_replace(['_', '-'], ' ', $deliveryType)) : 'Not set';
}

function orderQuery($page, $order = null, $startPickup = false) {
    global $search, $statusFilter, $paymentFilter, $dateRange, $dateFromInput, $dateToInput;
    $params = ['page' => $page, 'date_range' => $dateRange];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($statusFilter !== '') {
        $params['status'] = $statusFilter;
    }
    if ($paymentFilter !== '') {
        $params['payment_method'] = $paymentFilter;
    }
    if ($dateFromInput !== '') {
        $params['date_from'] = $dateFromInput;
    }
    if ($dateToInput !== '') {
        $params['date_to'] = $dateToInput;
    }
    if ($order !== null) {
        $params['order'] = $order;
    }
    if ($startPickup) {
        $params['start_pickup'] = '1';
    }
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Orders</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined">
<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, sans-serif;
    background: #f8f9fb;
    color: #252a30;
}

.content {
    margin-left: 244px;
    width: calc(100% - 244px);
    min-height: 100vh;
    padding: 30px 38px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
}

.orders-header-controls { position:relative; z-index:5; display:flex; align-items:center; justify-content:flex-end; gap:8px; }
.orders-date-filter select { min-width:160px; height:34px; padding:0 10px; border:1px solid #e5e7eb; border-radius:7px; background:#fff; color:#4e5660; font-size:14px; font-weight:600; }
.orders-custom-date-filter { position:absolute; top:calc(100% + 8px); right:0; z-index:20; display:flex; align-items:center; gap:8px; width:max-content; max-width:calc(100vw - 36px); margin:0; padding:10px; border:1px solid #e1e5e9; border-radius:8px; background:#fff; box-shadow:0 8px 18px rgba(28,32,38,.14); }
.orders-custom-date-filter input { width:142px; height:34px; padding:0 8px; border:1px solid #e5e7eb; border-radius:6px; color:#4e5660; font-size:12px; }
.orders-custom-date-filter button { height:34px; padding:0 12px; border:1px solid #ed1b3b; border-radius:6px; background:#ed1b3b; color:#fff; font-size:12px; font-weight:700; cursor:pointer; }

.page-header h1 {
    margin: 0 0 4px;
    font-size: 30px;
    font-weight: 800;
}

.page-header p {
    margin: 0;
    color: #7b8189;
    font-size: 14px;
    font-weight: 600;
}

.page-search {
    width: 220px;
    height: 32px;
    border: 1px solid #e8ebee;
    border-radius: 7px;
    background: #fff;
    padding: 0 10px;
    display: flex;
    align-items: center;
    gap: 7px;
    color: #89919a;
}

.page-search input {
    border: 0;
    outline: 0;
    width: 100%;
    font-size: 11px;
    font-weight: 600;
}

.page-search button,
.toolbar .search-clear-btn {
    width: auto;
    padding: 0;
    border: 0;
    background: transparent;
    color: #89919a;
    cursor: pointer;
    line-height: 1;
}

.page-search button:hover,
.toolbar .search-clear-btn:hover {
    color: #ed1b3b;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.summary-card {
    min-height: 112px;
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 15px;
    background: #fff;
    border: 1px solid #f0f1f3;
    border-radius: 9px;
    box-shadow: 0 2px 9px #1c20260d;
}

.summary-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    text-align: center;
    padding: 0;
    font-size: 19px;
    background: #fff0f3;
    color: #ed1b3b;
}

.summary-icon.material-icons-outlined {
    display: grid;
    place-items: center;
    text-align: center;
    padding: 0;
    font-family: 'Material Icons Outlined';
    font-weight: normal;
    font-style: normal;
    line-height: 1;
    letter-spacing: normal;
    text-transform: none;
    white-space: nowrap;
    direction: ltr;
    -webkit-font-feature-settings: 'liga';
    -webkit-font-smoothing: antialiased;
}

.summary-card.pending .summary-icon {
    background: #fff4d7;
    color: #e0a31b;
}

.summary-card.processing .summary-icon {
    background: #eaf2ff;
    color: #3b8be6;
}

.summary-card.shipped .summary-icon {
    background: #f0eaff;
    color: #8051d8;
}

.summary-card.delivered .summary-icon {
    background: #eaf8ef;
    color: #32a465;
}

.summary-label {
    color: #6f7780;
    font-size: 16px;
    line-height: 18px;
    font-weight: 700;
}

.summary-value {
    color: #ed1b3b;
    font-size: 32px;
    line-height: 32px;
    font-weight: 800;
    margin-top: 4px;
}

.summary-card.cancelled .summary-icon,
.summary-card.cancelled .summary-value {
    color: #ed1b3b;
}

.summary-card.pending .summary-value {
    color: #e0a31b;
}

.summary-card.processing .summary-value {
    color: #3b8be6;
}

.summary-card.shipped .summary-value {
    color: #8051d8;
}

.summary-card.delivered .summary-value {
    color: #32a465;
}

.summary-note {
    color: #6f7780;
    font-size: 14px;
    line-height: 16px;
    font-weight: 600;
    margin-top: 4px;
}

.orders-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 18px;
}

.orders-panel,
.details-panel {
    background: #fff;
    border: 1px solid #f0f1f3;
    border-radius: 9px;
    box-shadow: 0 2px 9px #1c20260d;
    overflow: hidden;
}

.toolbar {
    display: flex;
    gap: 9px;
    padding: 15px;
    border-bottom: 1px solid #f0f1f3;
}

.toolbar form {
    display: flex;
    gap: 8px;
    flex: 1;
}

.toolbar .search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    flex: 1;
    gap: 8px;
    min-width: 120px;
    height: 34px;
    padding: 0 10px;
    border: 1px solid #e0e5e9;
    border-radius: 6px;
    background: #fff;
    color: #89919a;
}

.toolbar .search-input-wrapper > i {
    flex: 0 0 auto;
    font-size: 14px;
}

.toolbar .search-input-wrapper input {
    width: 100%;
    height: 32px;
    padding: 0 28px 0 0;
    border: 0;
    background: transparent;
    outline: 0;
    color: #252a30;
}

.toolbar .search-clear-btn {
    position: absolute;
    right: 9px;
    display: <?php echo $search !== '' ? 'block' : 'none'; ?>;
}

.toolbar input,
.toolbar select,
.toolbar button {
    height: 34px;
    border: 1px solid #e0e5e9;
    border-radius: 6px;
    background: #fff;
    padding: 0 9px;
    font-size: 12px;
    font-weight: 600;
    color: #545d67;
}

.toolbar input {
    flex: 1;
}

.toolbar button {
    cursor: pointer;
}

.clear-filters {
    display: inline-flex;
    align-items: center;
    height: 29px;
    padding: 0 9px;
    border: 1px solid #e0e5e9;
    border-radius: 6px;
    color: #7b8189;
    font-size: 10px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}

.clear-filters:hover {
    color: #ed1b3b;
    border-color: #ed1b3b;
}

.toolbar .export {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 7px;
    height: 34px;
    padding: 0 12px;
    border: 1px solid #ed1b3b;
    border-radius: 6px;
    background: #ed1b3b;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
}

.toolbar .export:hover {
    border-color: #d91f3e;
    background: #d91f3e;
}

.toolbar .export i { font-size: 13px; line-height: 1; }

.orders-table {
    width: calc(100% - 32px);
    min-width: 0;
    margin: 0 16px;
    border-collapse: collapse;
    table-layout: fixed;
}

.orders-table th:nth-child(1), .orders-table td:nth-child(1) { width: 14%; }
.orders-table th:nth-child(2), .orders-table td:nth-child(2) { width: 16%; }
.orders-table th:nth-child(3), .orders-table td:nth-child(3) { width: 8%; }
.orders-table th:nth-child(4), .orders-table td:nth-child(4) { width: 9%; }
.orders-table th:nth-child(5), .orders-table td:nth-child(5) { width: 9%; }
.orders-table th:nth-child(6), .orders-table td:nth-child(6) { width: 12%; }
.orders-table th:nth-child(7), .orders-table td:nth-child(7) { width: 15%; }
.orders-table th:nth-child(8), .orders-table td:nth-child(8) { width: 9%; }
.orders-table th:nth-child(9), .orders-table td:nth-child(9) { width: 8%; }

.orders-table th {
    background: #e9ecef;
    color: #495057;
    font-size: 16px;
    line-height: 18px;
    font-weight: 800;
    padding: 12px 16px;
    text-align: left;
}

.orders-table th:not(:first-child),
.orders-table td:not(:first-child) {
    text-align: center;
}

.orders-table th:nth-child(9) {
    padding-left: 16px;
    padding-right: 16px;
}

.orders-table td {
    border-top: 1px solid #f0f1f3;
    padding: 14px 16px;
    font-size: 16px;
    line-height: 18px;
    font-weight: 400;
    vertical-align: middle;
    white-space: normal;
    overflow-wrap: anywhere;
}

.orders-table tr.selected {
    background: #fff8f9;
}

.orders-table tr.new-order,
.orders-table tr.new-order td { background:#fff2f4; }
.orders-table tr.new-order td { font-weight:700; }
.orders-table tr.new-order td:first-child { border-left:3px solid #ed1b3b; }
.orders-table tr:not(.new-order) .order-id-link,
.orders-table tr:not(.new-order) .customer-cell strong { font-weight:400; }
.orders-table tr.new-order .order-id-link,
.orders-table tr.new-order .customer-cell strong { font-weight:800; }
.new-order-badge { display:inline-flex; align-items:center; margin-left:7px; padding:3px 8px; border-radius:999px; background:#dcf5e7; color:#168246; font-size:12px; line-height:1; font-weight:800; vertical-align:middle; }
.orders-table td:first-child { white-space:nowrap; }

.customer-cell strong,
.customer-cell span {
    display: block;
}

.customer-cell span {
    color: #8a929a;
    font-size: 14px;
    margin-top: 2px;
}

.product-cell {
    max-width: none;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
}

.payment {
    color: #68727c;
}

.status-pill {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 5px;
    font-size: 12px;
    font-weight: 700;
}

.status-pending {
    background: #fff3d3;
    color: #b37a0b;
}

.status-processing {
    background: #e5f1ff;
    color: #2672bd;
}

.status-shipped {
    background: #ffedd5;
    color: #c2410c;
}

.status-readyforpickup {
    background: #eee8ff;
    color: #7550bd;
}

.status-delivered {
    background: #e5f7ed;
    color: #278c58;
}

.status-cancelled {
    background: #ffe8ee;
    color: #c83b5a;
}

.row-actions {
    display: flex;
    justify-content: center;
    gap: 4px;
}

.row-actions a,
.row-actions span {
    width: 27px;
    height: 27px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e2e6ea;
    border-radius: 5px;
    color: #68727c;
    text-decoration: none;
}

.row-actions span {
    align-items: center;
    justify-content: center;
    border: 1px solid #e2e6ea;
    border-radius: 5px;
    color: #b0b6bd;
    cursor: not-allowed;
}

.table-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 11px 12px;
    color: #7b8189;
    font-size: 9px;
    border-top: 1px solid #f0f1f3;
}

.pagination {
    display: flex;
    align-items: center;
    gap: 8px;
}

.pagination a {
    min-width: 40px;
    height: 40px;
    padding: 0 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #d8dee6;
    border-radius: 8px;
    background: #fff;
    color: #59636d;
    text-decoration: none;
    font-size: 16px;
    font-weight: 700;
    transition: background .2s ease, border-color .2s ease, color .2s ease;
}

.pagination a:hover {
    border-color: #ed1b3b;
    color: #ed1b3b;
    background: #fff5f6;
}

.pagination a.current {
    background: #ed1b3b;
    color: #fff;
    border-color: #ed1b3b;
    box-shadow: 0 3px 8px rgba(237, 27, 59, .2);
}

.details-panel {
    padding: 15px;
}

.details-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #f0f1f3;
    padding-bottom: 12px;
    margin-bottom: 12px;
}

.details-header h2 {
    font-size: 14px;
    margin: 0;
}

.details-header a {
    color: #78818b;
    text-decoration: none;
    font-size: 18px;
}

.details-order {
    font-weight: 800;
    font-size: 13px;
}

.details-date {
    font-size: 9px;
    color: #7b8189;
    margin-top: 4px;
}

.detail-section {
    border-top: 1px solid #f0f1f3;
    margin-top: 13px;
    padding-top: 11px;
}

.detail-section h3 {
    font-size: 10px;
    margin: 0 0 8px;
}

.self-pickup-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 12px;
    color: #2d4564;
    font-size: 18px;
    font-weight: 800;
}

.detail-section h3.self-pickup-heading {
    font-size: 18px;
}

.self-pickup-section {
    margin: 0;
    padding: 14px 16px;
    border: 1px solid #e2eaf4;
    border-radius: 9px;
    background: #fff;
}

.self-pickup-section .customer-detail {
    color: #596b80;
    font-size: 16px;
    line-height: 1.6;
}

.self-pickup-outside-action {
    justify-content: flex-end;
    margin-top: 12px;
}

.self-pickup-section .detail-actions {
    justify-content: flex-start;
}

.self-pickup-section .detail-actions form {
    flex: 0 1 auto;
}

.self-pickup-section .pickup-countdown {
    font-size: 16px;
}

.customer-detail {
    font-size: 9px;
    line-height: 1.6;
}

.pickup-countdown {
    font-size: 12px;
}

.item-line {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 9px;
    padding: 6px 0;
    border-bottom: 1px solid #f0f1f3;
}

.item-line span:last-child {
    color: #7b8189;
}

.detail-total {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    font-weight: 700;
    margin-top: 9px;
}

.detail-total strong {
    color: #ed1b3b;
}

.payment-detail {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 9px;
}

.delivery-outcome {
    background: #fbfcfd;
    border: 1px solid #e5e9ed;
    border-radius: 8px;
    padding: 10px;
}

.rider-controlled {
    color: #7b8189;
    font-size: 10px;
    font-weight: 600;
}

.delivery-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 6px 0;
    border-bottom: 1px solid #edf0f2;
    font-size: 14px;
}

.delivery-row:last-child { border-bottom: 0; padding-bottom: 0; }
.delivery-row span:first-child { color: #7b8189; }
.delivery-row strong { text-align: right; }
.delivery-failed { color: #c83b5a; }
.delivery-paid { color: #278c58; }

.payment-icon {
    width: 23px;
    height: 23px;
    border-radius: 6px;
    background: #eaf8ef;
    color: #32a465;
    display: flex;
    align-items: center;
    justify-content: center;
}

.timeline {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    align-items: start;
    width: 100%;
    padding: 6px 0 2px;
}

.timeline-step {
    position: relative;
    z-index: 0;
    display: flex;
    min-width: 0;
    flex-direction: column;
    align-items: center;
    gap: 7px;
    color: #68727c;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.25;
    text-align: center;
    text-decoration: none;
}

.timeline-step:not(:last-child)::before {
    content: '';
    position: absolute;
    z-index: -1;
    top: 21px;
    left: calc(50% + 22px);
    width: calc(100% - 44px);
    height: 2px;
    background: #dfe4e9;
}

.timeline-dot {
    display: grid;
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    place-items: center;
    border: 2px solid #cbd2d9;
    border-radius: 50%;
    background: #aeb8c6;
    color: #fff;
    font-size: 16px;
    font-weight: 500;
    z-index: 1;
}

.timeline-dot::before {
    content: attr(data-step);
}

.timeline-step.done .timeline-dot {
    border-color: #861522;
    background: #861522;
}

.timeline-step.done .timeline-dot::before {
    content: '✓';
    font-size: 20px;
}

.timeline-step.done {
    color: #263d5b;
}

.timeline-date {
    color: #8a929a;
    font-size: 12px;
    font-weight: 500;
}

.timeline-step.next:hover {
    color: #ed1b3b;
}

.timeline-step.next:hover .timeline-dot {
    border-color: #861522;
}

@media (max-width: 600px) {
    .timeline-step { gap:6px; font-size:12px; }
    .timeline-step:not(:last-child)::before { top:17px; left:calc(50% + 18px); width:calc(100% - 36px); }
    .timeline-dot { width:36px; height:36px; flex-basis:36px; font-size:13px; }
    .timeline-step.done .timeline-dot::before { font-size:17px; }
}

.detail-actions {
    display: flex;
    gap: 7px;
    margin-top: 16px;
}

.detail-actions form {
    flex: 1;
    width: auto !important;
}

.detail-actions button {
    border: 0;
    border-radius: 6px;
    background: #ed1b3b;
    color: #fff;
    cursor: pointer;
}

.detail-actions a {
    flex: 1;
    text-align: center;
    border: 1px solid #dfe4e9;
    border-radius: 6px;
    padding: 8px 5px;
    color: #59636d;
    text-decoration: none;
    font-size: 9px;
}

.detail-actions a.primary {
    background: #ed1b3b;
    border-color: #ed1b3b;
    color: #fff;
}

.detail-actions button.complete-pickup-link {
    display: inline-flex;
    flex: 0 1 auto;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 11px 16px;
    font-size: 16px;
    font-weight: 700;
}

.detail-actions a.tracker-link {
    flex: 0 0 auto;
    padding: 8px 12px;
    font-size: 15px;
    white-space: nowrap;
}

.empty-details {
    color: #7b8189;
    font-size: 14px;
    line-height: 1.5;
}

@media (max-width: 1100px) {
    .summary-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .orders-layout {
        grid-template-columns: 1fr;
    }
    .details-panel {
        display: none;
    }
    .orders-table th:nth-child(3), .orders-table td:nth-child(3),
    .orders-table th:nth-child(5), .orders-table td:nth-child(5),
    .orders-table th:nth-child(6), .orders-table td:nth-child(6) { display: none; }
    .orders-table th:nth-child(1), .orders-table td:nth-child(1) { width: 20%; }
    .orders-table th:nth-child(2), .orders-table td:nth-child(2) { width: 24%; }
    .orders-table th:nth-child(4), .orders-table td:nth-child(4) { width: 14%; }
    .orders-table th:nth-child(7), .orders-table td:nth-child(7) { width: 16%; }
    .orders-table th:nth-child(8), .orders-table td:nth-child(8) { width: 14%; }
    .orders-table th:nth-child(9), .orders-table td:nth-child(9) { width: 16%; }
    .orders-table th { padding: 12px 6px; font-size: 12px; }
    .orders-table td { padding: 10px 4px; font-size: 11px; }
}

@media (max-width: 720px) {
    .content {
        margin-left: 0;
        width: 100%;
        padding: 18px;
    }
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .page-header {
        align-items: flex-start;
        gap: 10px;
        flex-direction: column;
    }
    .orders-header-controls { width:100%; align-items:stretch; flex-wrap:wrap; }
    .orders-date-filter,
    .orders-date-filter select { width:100%; }
    .orders-custom-date-filter { flex-wrap:wrap; width:max-content; max-width:calc(100vw - 36px); }
    .orders-custom-date-filter input { min-width:0; flex:1 1 120px; }
    .page-search {
        width: 100%;
    }
    .toolbar {
        flex-wrap: wrap;
    }
    .orders-table th:nth-child(8), .orders-table td:nth-child(8) { display: none; }
    .orders-table th:nth-child(1), .orders-table td:nth-child(1) { width: 26%; }
    .orders-table th:nth-child(2), .orders-table td:nth-child(2) { width: 25%; }
    .orders-table th:nth-child(4), .orders-table td:nth-child(4) { width: 14%; }
    .orders-table th:nth-child(7), .orders-table td:nth-child(7) { width: 17%; }
    .orders-table th:nth-child(9), .orders-table td:nth-child(9) { width: 18%; }
    .orders-table th { padding: 10px 6px; font-size: 10px; }
    .orders-table td { padding: 10px 4px; font-size: 10px; }
    .orders-panel {
        overflow: auto;
    }
}

.details-panel:not(.open) {
    display: none;
}

.details-panel.open {
    position: fixed;
    display: block;
    top: 50%;
    left: 50%;
    right: auto;
    bottom: auto;
    transform: translate(-50%, -50%);
    width: min(540px, calc(100vw - 40px));
    max-height: calc(100vh - 40px);
    z-index: 1001;
    overflow-y: auto;
}

.details-panel.open { width:min(760px, calc(100vw - 40px)); max-height:90vh; padding:0; border-radius:12px; }
.details-panel .details-header { display:flex; align-items:center; gap:14px; padding:18px 22px; margin:0; border:0; }
.details-title-icon { display:inline-flex; width:48px; height:48px; flex:0 0 48px; align-items:center; justify-content:center; border-radius:12px; background:#eaf2ff; color:#2876c4; font-size:22px; }
.details-heading-copy { flex:1 1 auto; }
.details-panel .details-header h2 { margin:0; color:#243b59; font-size:21px; font-weight:800; }
.details-subtitle { margin:3px 0 0; color:#718198; font-size:15px; }
.details-panel .details-header a { display:inline-flex; width:34px; height:34px; align-items:center; justify-content:center; border-radius:6px; color:#8593a8; font-size:24px; }
.details-panel .details-header a:hover { background:#f1f4f8; color:#354a66; }
.details-content { padding:0 22px 18px; }
.order-summary-card { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; padding:14px 16px; border-radius:9px; background:#f3f7fc; }
.order-summary-main { min-width:0; }
.order-summary-flags { display:flex; flex-wrap:wrap; align-items:center; gap:7px; margin-bottom:6px; }
.details-new-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 9px; border-radius:999px; background:#dcecff; color:#2374c7; font-size:14px; font-weight:800; }
.order-summary-card .details-order { color:#263d5b; font-size:20px; }
.order-summary-card .details-date { margin-top:4px; color:#718198; font-size:14px; }
.order-status-pill { display:inline-flex; flex:0 0 auto; align-items:center; gap:6px; padding:7px 11px; border-radius:999px; font-size:14px; font-weight:700; }
.detail-section.details-card { margin:12px 0 0; padding:14px 16px; border:1px solid #e2eaf4; border-radius:9px; background:#fff; }
.details-section-group { margin-top:16px; }
.details-section-group .detail-section.details-card { margin-top:0; }
.details-card-heading { display:flex; align-items:center; gap:10px; margin:0 0 12px; color:#2d4564; }
.details-card-heading .delivery-proof-link { display:inline-flex; align-items:center; gap:7px; margin-left:auto; padding:9px 13px; border-radius:8px; background:#e8f1ff; color:#2563a8; font-size:14px; font-weight:700; text-decoration:none; white-space:nowrap; }
.details-card-heading .delivery-proof-link:hover { background:#dceaff; }
.details-card-heading > i { display:inline-flex; width:38px; height:38px; flex:0 0 38px; align-items:center; justify-content:center; border-radius:9px; background:#eaf2ff; color:#3479bd; font-size:18px; }
.details-card-heading h3 { margin:0; font-size:18px; font-weight:800; }
.self-pickup-heading { font-size:18px; }
.details-customer-name { display:block; margin-bottom:6px; color:#263d5b; font-size:18px; font-weight:800; }
.details-contact-line { display:flex; align-items:center; gap:10px; margin-top:7px; color:#718198; font-size:16px; overflow-wrap:anywhere; }
.details-contact-line i { width:18px; color:#7188a5; text-align:center; }
.details-address { color:#718198; font-size:16px; line-height:1.5; }
.details-items-table { width:100%; border-collapse:collapse; table-layout:fixed; }
.details-items-table th { padding:9px 10px; background:#f3f7fc; color:#4a607c; font-size:14px; font-weight:800; text-align:left; }
.details-items-table th:nth-child(2), .details-items-table td:nth-child(2) { width:18%; text-align:center; }
.details-items-table th:last-child, .details-items-table td:last-child { width:23%; text-align:right; }
.details-items-table td { padding:10px; border-bottom:1px solid #edf1f5; color:#344a66; font-size:15px; vertical-align:middle; }
.item-product { display:flex; align-items:center; gap:10px; min-width:0; }
.item-product img, .item-product-placeholder { display:flex; width:52px; height:52px; flex:0 0 52px; align-items:center; justify-content:center; border-radius:7px; background:#f3f6fa; object-fit:contain; }
.item-product-placeholder { color:#8a9bb0; font-size:20px; }
.item-product-name { min-width:0; overflow-wrap:anywhere; }
.order-totals { display:grid; gap:9px; margin-top:12px; padding:14px 16px; border-radius:9px; background:#f3f7fc; }
.order-total-line { display:flex; justify-content:space-between; gap:12px; color:#647891; font-size:15px; }
.order-total-line strong { color:#344a66; }
.order-total-grand { display:flex; justify-content:space-between; gap:12px; margin-top:2px; padding:12px 16px; border-radius:8px; background:#fff2f4; color:#344a66; font-size:19px; font-weight:800; }
.order-total-grand strong { color:#ed1b3b; }
.details-payment-section { margin-top:16px; }
.details-payment-heading { display:flex; align-items:center; gap:10px; margin-bottom:10px; color:#2d4564; }
.details-payment-heading h3 { margin:0; font-size:18px; font-weight:800; }
.details-payment-box { padding:13px 16px; border:1px solid #e2eaf4; border-radius:9px; background:#fff; }
.details-payment-icon { display:inline-flex; width:38px; height:38px; flex:0 0 38px; align-items:center; justify-content:center; border-radius:9px; background:#eaf2ff; color:#3479bd; font-size:18px; }
.details-payment-copy strong { color:#344a66; font-size:16px; }
.details-payment-copy span { display:block; margin-top:2px; color:#8795a8; font-size:15px; }
.details-payment-transaction { margin-top:10px; color:#647891; font-size:15px; overflow-wrap:anywhere; }
.details-payment-transaction strong { color:#344a66; }
.details-payment-status { margin-top:10px; color:#647891; font-size:15px; }
.details-payment-status strong { color:#c83b5a; }
.details-payment-status strong.is-paid { color:#278c58; }
.details-payment-proof { display:inline-flex; align-items:center; gap:6px; margin:0 0 0 auto; padding:8px 12px; border:0; border-radius:7px; background:#e8f1ff; color:#2563a8; font-size:14px; font-weight:700; text-decoration:none; }
.details-payment-proof:hover { background:#dceaff; }
.details-payment-main { display:flex; align-items:center; justify-content:space-between; gap:16px; }
.details-payment-copy { min-width:0; }
@media (max-width:480px) { .details-payment-main { align-items:flex-start; } .details-payment-proof { flex:0 0 auto; } }
.payment-review-actions { display:grid; grid-template-columns:auto minmax(0,1fr); gap:10px; align-items:start; }
.payment-review-approve { margin:0; }
.payment-rejection-trigger { justify-self:start; border:0; border-radius:6px; padding:10px 14px; background:#db294b; color:#fff; cursor:pointer; font-size:14px; font-weight:700; }
.payment-rejection-trigger:hover { background:#c82343; }
.payment-rejection-dialog[hidden] { display:none; }
.payment-rejection-dialog { position:fixed; inset:0; z-index:1100; display:grid; place-items:center; padding:20px; background:rgba(23,29,38,.5); }
.payment-rejection-dialog-card { width:min(460px,100%); padding:22px; border:1px solid #e2eaf4; border-radius:12px; background:#fff; box-shadow:0 18px 50px rgba(20,35,55,.25); }
.payment-rejection-dialog-card h3 { margin:0 0 6px; color:#243b59; font-size:20px; }
.payment-rejection-dialog-card > p { margin:0 0 18px; color:#718198; font-size:14px; }
.payment-rejection-form { display:grid; min-width:0; gap:12px; }
.payment-rejection-form label { display:grid; gap:5px; color:#344a66; font-size:13px; font-weight:700; }
.payment-rejection-form select,
.payment-rejection-form textarea { width:100%; min-width:0; padding:9px 10px; border:1px solid #d9e0e8; border-radius:7px; background:#fff; color:#344a66; font:inherit; font-size:14px; }
.payment-rejection-form select { min-height:40px; }
.payment-rejection-form textarea { min-height:72px; resize:vertical; }
.payment-rejection-dialog-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:4px; }
.payment-rejection-cancel,
.payment-rejection-submit { border:0; border-radius:6px; padding:10px 14px; cursor:pointer; font-size:14px; font-weight:700; }
.payment-rejection-cancel { background:#f0f3f7; color:#5d7089; }
.payment-rejection-submit { background:#db294b; color:#fff; }
.payment-rejection-submit:hover { background:#c82343; }
@media (max-width:600px) { .payment-review-actions { grid-template-columns:minmax(0,1fr); } .payment-review-approve button { width:100%; } }
.details-footer { display:flex; justify-content:flex-end; margin-top:16px; padding-top:12px; border-top:1px solid #edf1f5; }
.details-footer a { display:inline-flex; min-width:84px; height:40px; align-items:center; justify-content:center; border-radius:8px; background:#f0f3f7; color:#5d7089; font-size:15px; font-weight:700; text-decoration:none; }
.details-footer a:hover { background:#e5eaf0; }
@media (max-width:600px) { .details-panel.open { width:calc(100vw - 20px); max-height:94vh; } .details-panel .details-header { padding:14px; } .details-content { padding:0 14px 14px; } .details-items-table th, .details-items-table td { padding:8px 6px; } .item-product img, .item-product-placeholder { width:42px; height:42px; flex-basis:42px; } }

.details-overlay {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(23, 29, 38, 0.28);
}

.row-actions a:first-child {
    font-size: 0;
}

.row-actions a:first-child::after {
    content: '✎';
    font-size: 12px;
}

.row-actions a:not(:first-child) {
    display: none;
}

.search-clear-btn {
    position: absolute;
    right: 32px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #89919a;
    cursor: pointer;
    font-size: 14px;
    padding: 0;
    display: none;
}

.search-clear-btn.show {
    display: block;
}

.search-input-wrapper {
    position: relative;
    flex: 1;
}

.status-viewing {
    background: #f0eaff;
    color: #7550bd;
}

.timeline-step.viewing .timeline-dot {
    background: #7550bd;
    border-color: #7550bd;
}
</style>
</head>
<body>
<?php include 'appbar_owner.php'; include 'sidebar_owner.php'; ?>

<main class="content">
    <header class="page-header">
        <div>
            <h1>Orders</h1>
            <p>Manage and track all customer orders.</p>
        </div>
        <div class="orders-header-controls">
        <form method="get" class="orders-date-filter">
            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="payment_method" value="<?php echo htmlspecialchars($paymentFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($customRangeApplied): ?>
            <?php if ($dateFromValid): ?><input type="hidden" name="date_from" value="<?php echo htmlspecialchars($dateFromInput, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <?php if ($dateToValid): ?><input type="hidden" name="date_to" value="<?php echo htmlspecialchars($dateToInput, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <?php endif; ?>
            <select name="date_range" aria-label="Filter orders by date range">
                <option value="weekly" <?php echo $dateRange === 'weekly' ? 'selected' : ''; ?>>Last 7 Days</option>
                <option value="monthly" <?php echo $dateRange === 'monthly' ? 'selected' : ''; ?>>This Month</option>
                <option value="yearly" <?php echo $dateRange === 'yearly' ? 'selected' : ''; ?>>This Year</option>
                <option value="custom" <?php echo $dateRange === 'custom' ? 'selected' : ''; ?>><?php echo htmlspecialchars($customDateRangeLabel, ENT_QUOTES, 'UTF-8'); ?></option>
            </select>
        </form>
        <?php if ($showCustomDateInputs): ?>
        <form method="get" class="orders-custom-date-filter">
            <input type="hidden" name="date_range" value="custom">
            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="payment_method" value="<?php echo htmlspecialchars($paymentFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="date" name="date_from" value="<?php echo $dateFromValid ? htmlspecialchars($dateFromInput, ENT_QUOTES, 'UTF-8') : ''; ?>" aria-label="Start date">
            <input type="date" name="date_to" value="<?php echo $dateToValid ? htmlspecialchars($dateToInput, ENT_QUOTES, 'UTF-8') : ''; ?>" aria-label="End date">
            <button type="submit">Apply</button>
        </form>
        <?php endif; ?>
        </div>
    </header>

    <section class="summary-grid">
        <?php
        $cards = [
            ['total', 'Total Orders', $totalAll, 'receipt_long', 'total'],
            ['pending', 'Pending', $statusCounts['pending'] ?? 0, 'schedule', 'pending'],
            ['processing', 'Processing', $statusCounts['processing'] ?? 0, 'sync', 'processing'],
            ['delivered', 'Delivered', $statusCounts['delivered'] ?? 0, 'task_alt', 'delivered'],
            ['cancelled', 'Cancelled', $statusCounts['cancelled'] ?? 0, 'cancel', 'cancelled']
        ];
        foreach ($cards as $card):
            $currentComparisonCount = (int)($monthlyComparison['current_' . $card[4]] ?? 0);
            $previousComparisonCount = (int)($monthlyComparison['previous_' . $card[4]] ?? 0);
            if ($previousComparisonCount === 0) {
                $trendArrow = $currentComparisonCount > 0 ? '↑' : '→';
                $trendValue = $currentComparisonCount > 0 ? 'New' : '0.0%';
            } else {
                $trendPercent = (($currentComparisonCount - $previousComparisonCount) / $previousComparisonCount) * 100;
                $trendArrow = $trendPercent > 0 ? '↑' : ($trendPercent < 0 ? '↓' : '→');
                $trendValue = number_format(abs($trendPercent), 1) . '%';
            }
        ?>
        <div class="summary-card <?php echo $card[0]; ?>">
            <div class="summary-icon material-icons-outlined" aria-hidden="true"><?php echo $card[3]; ?></div>
            <div>
                <div class="summary-label"><?php echo $card[1]; ?></div>
                <div class="summary-value"><?php echo number_format($card[2]); ?></div>
                <div class="summary-note"><?php echo $trendArrow . ' ' . $trendValue; ?> vs same period last month</div>
            </div>
        </div>
        <?php endforeach; ?>
    </section>

    <div class="orders-layout">
        <section class="orders-panel">
            <div class="toolbar">
                <form method="get">
                    <input type="hidden" name="date_range" value="<?php echo htmlspecialchars($dateRange, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($dateFromInput, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($dateToInput, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="page" value="1">
                    <div class="search-input-wrapper">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by Order ID, customer or product..." aria-label="Search orders">
                        <button type="button" class="search-clear-btn" id="clearToolbarSearch" title="Clear search" aria-label="Clear search">&times;</button>
                    </div>
                    <select name="status" aria-label="Filter orders by status">
                        <option value="">All Statuses</option>
                        <?php foreach ($allowedStatuses as $option): ?>
                        <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $option ? 'selected' : ''; ?>><?php echo htmlspecialchars(orderStatusLabel($option), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="payment_method" aria-label="Filter orders by payment method">
                        <option value="">All Payments</option>
                        <option value="cash" <?php echo $paymentFilter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="gcash" <?php echo $paymentFilter === 'gcash' ? 'selected' : ''; ?>>GCash</option>
                        <option value="bpi" <?php echo $paymentFilter === 'bpi' ? 'selected' : ''; ?>>BPI</option>
                    </select>
                </form>
                <a class="export" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" title="Export to CSV">
                    <i class="bi bi-box-arrow-up" aria-hidden="true"></i> Export
                </a>
            </div>

            <div style="overflow:auto">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Type of Delivery</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($orderRows): ?>
                            <?php foreach ($orderRows as $order): ?>
                            <?php $isNewOrder = isNewOrderToday($order); ?>
                            <tr class="<?php echo $isNewOrder ? 'new-order' : ''; ?><?php echo $selectedId === (int)$order['id'] ? ' selected' : ''; ?>">
                                <td>
                                    <a class="order-id-link" href="<?php echo orderQuery($page, $order['id']); ?>" style="color:#252a30;text-decoration:none">
                                        <?php echo htmlspecialchars(formatOrderNumber($conn, (int)$order['id']), ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                    <?php if ($isNewOrder): ?><span class="new-order-badge">NEW</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="customer-cell">
                                        <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong>
                                        <span><?php echo htmlspecialchars($order['email'] ?? ''); ?></span>
                                    </div>
                                </td>
                                <td>x<?php echo number_format((int)$order['item_count']); ?></td>
                                <td><?php echo $order['items_total'] !== null ? '₱' . number_format((float)$order['items_total'], 2) : 'Unavailable'; ?>                                </td>
                                <td class="payment">
                                    <?php echo strtoupper($order['payment_method'] ?? 'COD'); ?>
                                </td>
                                <td><?php echo htmlspecialchars(deliveryTypeLabel($order['delivery_type'] ?? '')); ?></td>
                                <td>
                                    <span class="status-pill <?php echo orderStatusClass($order['status']); ?>">
                                        <?php echo htmlspecialchars(orderStatusLabel($order['status']), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($order['order_date'])); ?><br>
                                    <span style="color:#8a929a;font-size:12px">
                                        <?php echo date('h:i A', strtotime($order['order_date'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a href="<?php echo orderQuery($page, $order['id'], true); ?>"
                                           title="Open order details">◉</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align:center;padding:35px;color:#7b8189">
                                    No orders found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-footer">
                <span>
                    Showing <?php echo $totalOrders ? $offset + 1 : 0; ?> to <?php echo min($offset + $perPage, $totalOrders); ?> of <?php echo $totalOrders; ?> orders
                </span>
                <?php if ($totalPages > 1): ?>
                <nav class="pagination">
                    <a href="<?php echo orderQuery(max(1, $page - 1)); ?>">‹</a>
                    <?php for ($p = 1; $p <= min(3, $totalPages); $p++): ?>
                    <a class="<?php echo $p === $page ? 'current' : ''; ?>" href="<?php echo orderQuery($p); ?>">
                        <?php echo $p; ?>
                    </a>
                    <?php endfor; ?>
                    <?php if ($totalPages > 4): ?>
                    <span>…</span>
                    <a href="<?php echo orderQuery($totalPages); ?>"><?php echo $totalPages; ?></a>
                    <?php endif; ?>
                    <a href="<?php echo orderQuery(min($totalPages, $page + 1)); ?>">›</a>
                </nav>
                <?php endif; ?>
            </div>
        </section>

        <aside class="details-panel <?php echo $detailsOpen ? 'open' : ''; ?>">
            <div class="details-header">
                <span class="details-title-icon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
                <div class="details-heading-copy"><h2>Order Details</h2><p class="details-subtitle">View detailed information about this order.</p></div>
                <a href="<?php echo orderQuery($page); ?>" aria-label="Close order details">&times;</a>
            </div>

            <?php if ($selectedOrder): ?>
            <?php $selectedIsNewOrder = isNewOrderToday($selectedOrder); ?>
            <div class="details-content">
                <?php if ($paymentReviewNotice !== ''): ?>
                <div role="status" style="margin-bottom:12px;padding:10px 12px;border-radius:7px;background:#eef7ff;color:#24527a;font-size:14px"><?php echo htmlspecialchars($paymentReviewNotice, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <div class="order-summary-card">
                    <div class="order-summary-main">
                        <div class="order-summary-flags">
                            <?php if ($selectedIsNewOrder): ?><span class="details-new-badge"><i class="bi bi-stars" aria-hidden="true"></i> NEW</span><?php endif; ?>
                        </div>
                        <div class="details-order"><?php echo htmlspecialchars(formatOrderNumber($conn, (int)$selectedOrder['id']), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="details-date"><?php echo date('M d, Y h:i A', strtotime($selectedOrder['order_date'])); ?></div>
                    </div>
                    <span class="order-status-pill <?php echo orderStatusClass($selectedOrder['status']); ?>"><i class="bi bi-circle-fill" aria-hidden="true"></i><?php echo htmlspecialchars(orderStatusLabel($selectedOrder['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>

                <div class="detail-section order-timeline-section">
                    <div class="details-card-heading"><i class="bi bi-clock-history" aria-hidden="true"></i><h3>Order Timeline</h3></div>
                    <?php
                    $isSelfPickupOrder = in_array(strtolower(trim($selectedOrder['delivery_type'] ?? '')), ['pickup', 'self_pickup', 'self-pickup'], true);
                    $timeline = $isSelfPickupOrder
                        ? [
                            ['Order Placed', 'pending'],
                            ['Processing', 'processing'],
                            ['Ready for Pickup', 'ready_for_pickup'],
                            ['Delivered', 'delivered']
                        ]
                        : [
                            ['Order Placed', 'pending'],
                            ['Processing', 'processing'],
                            ['Shipped', 'shipped'],
                            ['Delivered', 'delivered']
                        ];
                    $timelineRank = array_flip(array_column($timeline, 1));
                    $currentRank = $timelineRank[strtolower($selectedOrder['status'])] ?? 0;
                    ?>
                    <div class="timeline">
                        <?php foreach ($timeline as $stepIndex => $step): ?>
                        <?php $done = $currentRank >= $timelineRank[$step[1]]; ?>
                        <?php
                        if ($done) {
                            $stepHref = $step[1] === 'shipped' ? '#delivery-outcome' : '#';
                        } elseif ($step[1] === 'shipped' || ($isSelfPickupOrder && $step[1] === 'delivered')) {
                            $stepHref = '#';
                        } else {
                            $stepHref = 'update_status.php?id=' . $selectedId . '&status=' . $step[1];
                        }
                        ?>
                        <a class="timeline-step <?php echo $done ? 'done' : 'next'; ?>"
                           href="<?php echo htmlspecialchars($stepHref, ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="timeline-dot" data-step="<?php echo $stepIndex + 1; ?>" aria-hidden="true"></span>
                            <span><?php echo $step[0]; ?></span>
                            <?php if ($done && $step[1] === 'pending'): ?>
                            <span class="timeline-date"><?php echo date('M d, Y h:i A', strtotime($selectedOrder['order_date'])); ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="details-section-group">
                    <div class="details-card-heading"><i class="bi bi-person" aria-hidden="true"></i><h3>Customer Information</h3></div>
                    <div class="detail-section details-card">
                        <strong class="details-customer-name"><?php echo htmlspecialchars($selectedOrder['customer_name']); ?></strong>
                        <?php if (!$isSelfPickupOrder): ?>
                            <div class="details-contact-line"><i class="bi bi-geo-alt" aria-hidden="true"></i><span><?php echo nl2br(htmlspecialchars($selectedOrder['address'] ?? 'Not provided')); ?></span></div>
                        <?php endif; ?>
                        <div class="details-contact-line"><i class="bi bi-telephone" aria-hidden="true"></i><span><?php echo htmlspecialchars($selectedOrder['phone'] ?? 'Not provided'); ?></span></div>
                    </div>
                </div>

                <div class="details-section-group">
                    <div class="details-card-heading"><i class="bi bi-box-seam" aria-hidden="true"></i><h3>Order Items</h3></div>
                    <div class="detail-section details-card">
                    <table class="details-items-table">
                        <thead><tr><th>Product</th><th>Quantity</th><th>Price</th></tr></thead>
                        <tbody>
                        <?php foreach ($selectedItems as $item): ?>
                            <?php
                            $itemImage = trim($item['product_image'] ?? '');
                            $itemImageSrc = $itemImage === '' ? '' : (strpos($itemImage, 'http') === 0 ? $itemImage : 'uploads/' . rawurlencode(basename($itemImage)));
                            ?>
                            <tr>
                                <td><div class="item-product">
                                    <?php if ($itemImageSrc !== ''): ?><img src="<?php echo htmlspecialchars($itemImageSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.hidden=false"><?php endif; ?>
                                    <span class="item-product-placeholder" <?php echo $itemImageSrc !== '' ? 'hidden' : ''; ?>><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                                    <span class="item-product-name"><?php echo htmlspecialchars($item['product_name'] ?? 'Unknown product'); ?></span>
                                </div></td>
                                <td>x<?php echo (int)($item['quantity'] ?? 0); ?></td>
                                <td>₱<?php echo number_format((float)($item['price'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$selectedItems): ?>
                            <tr><td colspan="3" class="empty-state">No product details were saved for this order.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <?php
                    $orderShippingFee = (float)($selectedOrder['shipping_fee'] ?? 0);
                    $orderRecordedTotal = (float)($selectedOrder['total_amount'] ?? 0);
                    $hasSavedOrderItems = count($selectedItems) > 0;
                    $orderGrandTotal = $hasSavedOrderItems
                        ? ($orderRecordedTotal > 0 ? $orderRecordedTotal : $itemsTotal + $orderShippingFee)
                        : $orderRecordedTotal;
                    ?>
                    <div class="order-totals">
                        <div class="order-total-line"><span>Items Total</span><strong><?php echo $hasSavedOrderItems ? '₱' . number_format($itemsTotal, 2) : 'Unavailable'; ?></strong></div>
                        <?php if ((float)($selectedOrder['discount_amount'] ?? 0) > 0): ?>
                            <div class="order-total-line"><span><?php echo ($selectedOrder['discount_type'] ?? '') === 'pwd' ? 'PWD Discount (20%)' : 'Senior Citizen Discount (20%)'; ?></span><strong>-₱<?php echo number_format((float)$selectedOrder['discount_amount'], 2); ?></strong></div>
                        <?php endif; ?>
                        <?php if ((float)($selectedOrder['coupon_discount_amount'] ?? 0) > 0): ?>
                            <div class="order-total-line">
                                <span>Voucher<?php echo trim((string)($selectedOrder['coupon_code'] ?? '')) !== '' ? ' (' . htmlspecialchars((string)$selectedOrder['coupon_code'], ENT_QUOTES, 'UTF-8') . ')' : ''; ?></span>
                                <strong>-₱<?php echo number_format((float)$selectedOrder['coupon_discount_amount'], 2); ?></strong>
                            </div>
                        <?php endif; ?>
                        <div class="order-total-line"><span>Shipping Fee</span><strong><?php echo $hasSavedOrderItems ? '₱' . number_format($orderShippingFee, 2) : 'Unavailable'; ?></strong></div>
                        <?php if (!$isSelfPickupOrder): ?>
                            <div class="order-total-line"><span>Rider Collection</span><strong><?php echo $hasSavedOrderItems || $orderRecordedTotal > 0 ? '₱' . number_format($orderGrandTotal, 2) : 'Unavailable'; ?></strong></div>
                        <?php endif; ?>
                    </div>
                    <div class="order-total-grand"><span>Total</span><strong><?php echo $hasSavedOrderItems || $orderRecordedTotal > 0 ? '₱' . number_format($orderGrandTotal, 2) : 'Unavailable'; ?></strong></div>
                    </div>
                </div>

                <div class="details-payment-section">
                    <div class="details-payment-heading"><span class="details-payment-icon"><i class="bi bi-credit-card" aria-hidden="true"></i></span><h3>Payment Information</h3></div>
                    <div class="details-payment-box">
                        <div class="details-payment-main">
                            <div class="details-payment-copy">
                                <strong><?php echo ucfirst($selectedOrder['payment_method'] ?? 'Cash on Delivery'); ?></strong>
                                <span><?php echo htmlspecialchars(deliveryTypeLabel($selectedOrder['delivery_type'] ?? '')); ?></span>
                            </div>
                            <?php
                            $paymentProofName = basename(trim((string)($selectedOrder['payment_proof'] ?? '')));
                            $paymentProofPath = __DIR__ . '/../user/uploads/payment_proofs/' . $paymentProofName;
                            ?>
                            <?php if (in_array(strtolower(trim($selectedOrder['payment_method'] ?? '')), ['gcash', 'bank', 'bpi', 'bank transfer'], true) && $paymentProofName !== '' && is_file($paymentProofPath)): ?>
                                <a class="details-payment-proof" href="payment_proof.php?order_id=<?php echo (int)$selectedOrder['id']; ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-image" aria-hidden="true"></i> View proof</a>
                            <?php endif; ?>
                        </div>
                        <div class="details-payment-status">
                            <strong>Payment Status:</strong>
                            <strong class="<?php echo strtolower($selectedOrder['payment_status'] ?? 'unpaid') === 'paid' ? 'is-paid' : ''; ?>">
                                <?php echo htmlspecialchars(strtoupper($selectedOrder['payment_status'] ?? 'unpaid'), ENT_QUOTES, 'UTF-8'); ?>
                            </strong>
                        </div>
                        <?php if (in_array(strtolower(trim($selectedOrder['payment_method'] ?? '')), ['gcash', 'bank', 'bpi', 'bank transfer'], true)): ?>
                            <div class="details-payment-transaction"><strong>Transaction ID:</strong> <?php echo htmlspecialchars(trim($selectedOrder['payment_transaction_id'] ?? '') ?: 'Not provided', ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if ($paymentProofName === '' || !is_file($paymentProofPath)): ?>
                                <div class="details-payment-transaction">Payment proof: Not available</div>
                            <?php endif; ?>
                            <?php if ((in_array($_SESSION['role'] ?? '', ['admin', 'owner'], true)
                                || ($isPharmacist && pharmacistCanAccessOrder($conn, (int)$selectedOrder['id'])))
                                && empty($selectedOrder['rider_id'])
                                && !in_array(strtolower(trim($selectedOrder['status'] ?? '')), ['cancelled', 'delivered'], true)): ?>
                                <?php if (in_array(strtolower(trim($selectedOrder['payment_status'] ?? '')), ['pending', 'unpaid', ''], true)): ?>
                                <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e5eaf0">
                                    <strong style="display:block;margin-bottom:8px;color:#243b59">Review online payment</strong>
                                    <div class="payment-review-actions">
                                        <form method="post" class="payment-review-approve">
                                            <input type="hidden" name="action" value="review_payment">
                                            <input type="hidden" name="order_id" value="<?php echo (int)$selectedOrder['id']; ?>">
                                            <input type="hidden" name="decision" value="approved">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($statusUpdateToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" style="border:0;border-radius:6px;padding:10px 14px;background:#15955c;color:#fff;cursor:pointer;font-size:14px;font-weight:700">Approve payment</button>
                                        </form>
                                        <button type="button" class="payment-rejection-trigger" data-open-payment-rejection>Reject payment</button>
                                    </div>
                                </div>
                                <?php elseif (strtolower(trim($selectedOrder['payment_status'] ?? '')) === 'paid'): ?>
                                <div style="margin-top:10px;color:#278658;font-size:14px;font-weight:700">Approved — this order is eligible for rider assignment.</div>
                                <?php elseif (strtolower(trim($selectedOrder['payment_status'] ?? '')) === 'rejected'): ?>
                                <div style="margin-top:10px;color:#b4233c;font-size:14px;font-weight:700">Payment rejected — this order was cancelled.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (in_array(strtolower(trim((string)($selectedOrder['payment_method'] ?? ''))), ['cod', 'cash'], true)
                    && in_array($selectedOrder['discount_type'] ?? '', ['senior_citizen', 'pwd'], true)): ?>
                    <?php
                    $discountStatus = strtolower(trim((string)($selectedOrder['discount_status'] ?? 'pending_verification')));
                    $discountIdName = basename(trim((string)($selectedOrder['discount_id_proof'] ?? '')));
                    $legacyDiscountIdPath = __DIR__ . '/../user/uploads/discount_ids/' . $discountIdName;
                    $hasLegacyDiscountId = preg_match('/^discount_id_[0-9]+_[a-f0-9]{32}\.(?:jpg|png)$/', $discountIdName)
                        && is_file($legacyDiscountIdPath);
                    $discountIdStmt = $conn->prepare('SELECT 1 FROM order_discount_ids WHERE order_id = ? LIMIT 1');
                    $hasDatabaseDiscountId = false;
                    if ($discountIdStmt) {
                        $discountIdStmt->bind_param('i', $selectedOrder['id']);
                        if ($discountIdStmt->execute()) {
                            $discountIdResult = $discountIdStmt->get_result();
                            $hasDatabaseDiscountId = $discountIdResult && $discountIdResult->num_rows === 1;
                        }
                        $discountIdStmt->close();
                    } else {
                        error_log('Discount ID lookup preparation failed: ' . $conn->error);
                    }
                    $hasDiscountIdData = $hasDatabaseDiscountId || $hasLegacyDiscountId;
                    $canManageDiscount = in_array($_SESSION['role'] ?? '', ['admin', 'owner'], true);
                    $canReviewDiscount = ($canManageDiscount
                        || ($isPharmacist && pharmacistCanAccessOrder($conn, (int)$selectedOrder['id'])))
                        && $discountStatus === 'pending_verification'
                        && $hasDiscountIdData;
                    $canViewDiscountId = $canManageDiscount
                        || ($isPharmacist && pharmacistCanAccessOrder($conn, (int)$selectedOrder['id']));
                    ?>
                    <div class="details-payment-section">
                        <div class="details-payment-heading"><span class="details-payment-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span><h3><?php echo ($selectedOrder['discount_type'] ?? '') === 'pwd' ? 'PWD' : 'Senior Citizen'; ?> Discount ID</h3></div>
                        <div class="details-payment-box">
                            <div class="details-payment-status">
                                <strong>ID Status:</strong>
                                <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $discountStatus)), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <?php if ($canViewDiscountId && $hasDiscountIdData): ?>
                                <a class="details-payment-proof" href="discount_id_proof.php?order_id=<?php echo (int)$selectedOrder['id']; ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-image" aria-hidden="true"></i> View uploaded ID</a>
                            <?php else: ?>
                                <div class="details-payment-transaction">Uploaded ID: Not available</div>
                            <?php endif; ?>
                            <?php if ($canReviewDiscount): ?>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
                                    <?php foreach (['approved' => 'Approve', 'rejected' => 'Reject'] as $decision => $label): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="review_discount">
                                            <input type="hidden" name="order_id" value="<?php echo (int)$selectedOrder['id']; ?>">
                                            <input type="hidden" name="decision" value="<?php echo $decision; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($statusUpdateToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" style="border:0;border-radius:6px;padding:10px 14px;background:<?php echo $decision === 'approved' ? '#15955c' : '#db294b'; ?>;color:#fff;cursor:pointer;font-size:14px;font-weight:700"><?php echo $label; ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ($discountStatus === 'approved'): ?>
                                <div style="margin-top:10px;color:#278658;font-size:14px;font-weight:700">20% discount applied: ₱<?php echo number_format((float)($selectedOrder['discount_amount'] ?? 0), 2); ?></div>
                            <?php elseif ($discountStatus === 'rejected'): ?>
                                <div style="margin-top:10px;color:#b4233c;font-size:14px;font-weight:700">Rejected — no discount applied.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php if (in_array(strtolower(trim($selectedOrder['delivery_type'] ?? 'delivery')), ['pickup', 'self_pickup', 'self-pickup'], true) && strtolower(trim($selectedOrder['status'] ?? '')) !== 'cancelled'): ?>
            <div class="detail-section self-pickup-container">
                <h3 class="details-card-heading self-pickup-heading"><span class="details-payment-icon"><i class="bi bi-bag-check" aria-hidden="true"></i></span><span>Self Pick Up</span></h3>
                <div class="self-pickup-section">
                <?php
                $pickupStatus = strtolower(trim((string)($selectedOrder['status'] ?? '')));
                $pickupDiscountPending = in_array($selectedOrder['discount_type'] ?? '', ['senior_citizen', 'pwd'], true)
                    && strtolower(trim((string)($selectedOrder['discount_status'] ?? 'pending_verification'))) === 'pending_verification';
                $pickupOnlinePaymentPending = in_array(strtolower(trim((string)($selectedOrder['payment_method'] ?? ''))), ['gcash', 'bank', 'bpi', 'bank transfer'], true)
                    && strtolower(trim((string)($selectedOrder['payment_status'] ?? 'unpaid'))) !== 'paid';
                $canMarkPickupReady = $pickupStatus === 'processing' && !$pickupDiscountPending && !$pickupOnlinePaymentPending;
                $canStartPickupProcessing = $pickupStatus === 'pending' && !$pickupDiscountPending && !$pickupOnlinePaymentPending;
                ?>
                <?php if ($pickupStatus === 'ready_for_pickup'): ?>
                    <div class="customer-detail pickup-countdown" data-deadline="<?php echo htmlspecialchars($selectedOrder['pickup_deadline'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">Ready for Pickup — time remaining: <strong>Calculating...</strong></div>
                <?php elseif ($canMarkPickupReady): ?>
                    <div class="customer-detail">Order is processing. Click below when it is ready for customer pickup.</div>
                <?php elseif ($canStartPickupProcessing): ?>
                    <div class="customer-detail">This eligible pickup order is still Pending. Move it to Processing to enable the Ready for Pickup action.</div>
                    <form class="detail-actions" method="get" action="update_status.php">
                        <input type="hidden" name="id" value="<?php echo $selectedId; ?>">
                        <input type="hidden" name="status" value="processing">
                        <button class="complete-pickup-link" type="submit"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i> Start Processing</button>
                    </form>
                <?php elseif ($pickupDiscountPending): ?>
                    <div class="customer-detail">The discount ID must be approved or rejected before preparing this pickup order.</div>
                <?php elseif ($pickupOnlinePaymentPending): ?>
                    <div class="customer-detail">Online payment must be approved before preparing this pickup order.</div>
                <?php elseif ($pickupStatus === 'pending'): ?>
                    <div class="customer-detail">This order will move to Processing after the required payment and discount reviews are complete.</div>
                <?php else: ?>
                    <div class="customer-detail">
                        Pickup status: <?php echo htmlspecialchars(orderStatusLabel($pickupStatus), ENT_QUOTES, 'UTF-8'); ?><?php
                        $pickupCompletedAt = trim((string)($selectedOrder['delivery_date'] ?? ''));
                        if ($pickupStatus === 'delivered' && $pickupCompletedAt !== '' && ($pickupCompletedTimestamp = strtotime($pickupCompletedAt)) !== false):
                        ?> — <?php echo date('M d, Y h:i A', $pickupCompletedTimestamp); ?><?php endif; ?>.
                    </div>
                <?php endif; ?>
                </div>
                <?php if ($canMarkPickupReady): ?>
                    <form class="detail-actions self-pickup-outside-action" method="get" action="update_status.php">
                        <input type="hidden" name="id" value="<?php echo $selectedId; ?>">
                        <input type="hidden" name="status" value="ready_for_pickup">
                        <button class="complete-pickup-link" type="submit"><i class="bi bi-bag-check" aria-hidden="true"></i> Ready for Pickup</button>
                    </form>
                <?php elseif ($pickupStatus === 'ready_for_pickup'): ?>
                    <form class="detail-actions self-pickup-outside-action"
                          method="post"
                          action="update_status.php"
                          onsubmit="return confirm('Confirm that the customer has picked up this order and any COD cash has been collected?');">
                        <input type="hidden" name="id" value="<?php echo $selectedId; ?>">
                        <input type="hidden" name="status" value="delivered">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($statusUpdateToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <button class="complete-pickup-link" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i> Mark as Delivered</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php
            $selectedPaymentMethod = strtolower(trim($selectedOrder['payment_method'] ?? ''));
            $selectedPaymentApproved = !in_array($selectedPaymentMethod, ['gcash', 'bank', 'bpi', 'bank transfer'], true)
                || strtolower(trim($selectedOrder['payment_status'] ?? 'unpaid')) === 'paid';
            ?>
            <?php if ($selectedPaymentApproved
                && !empty($selectedOrder['rider_id'])
                && !in_array(strtolower(trim($selectedOrder['delivery_type'] ?? 'delivery')), ['onsite', 'pickup', 'self_pickup', 'self-pickup'], true)): ?>
            <div class="detail-section" id="delivery-outcome">
                <?php
                $deliveryProofFilename = basename(trim((string)($selectedOrder['delivery_proof_photo'] ?? '')));
                $deliveryProofPath = __DIR__ . '/../user/uploads/delivery_proofs/' . $deliveryProofFilename;
                $riderDeliveryComplete = strtolower(trim((string)($selectedOrder['delivery_status'] ?? ''))) === 'delivered'
                    || strtolower(trim((string)($selectedOrder['status'] ?? ''))) === 'delivered';
                $hasRiderDeliveryProof = $riderDeliveryComplete && $deliveryProofFilename !== '' && is_file($deliveryProofPath);
                ?>
                <div class="details-card-heading">
                    <i class="bi bi-truck" aria-hidden="true"></i>
                    <h3>Rider Assignment</h3>
                    <?php if ($hasRiderDeliveryProof): ?>
                    <a class="delivery-proof-link" href="../user/delivery_proof.php?order_id=<?php echo (int)$selectedOrder['id']; ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-image" aria-hidden="true"></i> View proof</a>
                    <?php endif; ?>
                </div>
                <div class="delivery-outcome">
                    <div class="delivery-row">
                        <span>Assigned Rider</span>
                        <strong><?php echo htmlspecialchars($selectedOrder['assigned_rider_name'] ?? 'Waiting for rider acceptance'); ?></strong>
                    </div>
                    <div class="delivery-row">
                        <span>Number</span>
                        <strong><?php echo htmlspecialchars($selectedOrder['assigned_rider_phone'] ?? 'No phone provided'); ?></strong>
                    </div>
                    <div class="delivery-row">
                        <span>Status</span>
                        <strong><?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $selectedOrder['delivery_status'] ?? 'in_transit'))); ?></strong>
                    </div>
                    <div class="delivery-row">
                        <span>Accepted</span>
                        <strong><?php echo $riderAcceptedAt ? htmlspecialchars(date('M j, Y', strtotime($riderAcceptedAt)) . ' • ' . date('g:i A', strtotime($riderAcceptedAt))) : 'Acceptance time unavailable'; ?></strong>
                    </div>
                    <div class="delivery-row">
                        <span>Pickup</span>
                        <strong>454 Rizal Avenue Extension, Caloocan, Metro Manila</strong>
                    </div>
                    <div class="delivery-row"><span>Delivery</span><strong><?php echo htmlspecialchars($selectedOrder['address'] ?? 'Address not provided'); ?></strong></div>
                    <?php if (!empty($selectedOrder['assigned_rider_name'])): ?>
                    <?php if (!in_array(strtolower(trim($selectedOrder['status'] ?? '')), ['cancelled', 'delivered'], true)): ?>
                        <a class="primary tracker-link" href="delivery_tracker.php?order=<?php echo (int)$selectedOrder['id']; ?>" target="_blank" rel="noopener">Track Rider</a>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($selectedOrder['delivery_failure_reason']) || !empty($selectedOrder['delivery_action'])): ?>
                    <div class="delivery-row">
                        <span>Reason</span>
                        <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $selectedOrder['delivery_failure_reason'] ?? 'Not provided'))); ?></strong>
                    </div>
                    <div class="delivery-row">
                        <span>Action</span>
                        <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $selectedOrder['delivery_action'] ?? 'Not provided'))); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($selectedOrder['prescription_image'])): ?>
            <div class="detail-section details-card">
                <div class="details-card-heading"><i class="bi bi-prescription2" aria-hidden="true"></i><h3>Prescription</h3></div>
                <a href="uploads/<?php echo rawurlencode(basename($selectedOrder['prescription_image'])); ?>" target="_blank" rel="noopener">
                    <img src="uploads/<?php echo rawurlencode(basename($selectedOrder['prescription_image'])); ?>" alt="Uploaded prescription" style="display:block;width:100%;max-height:260px;object-fit:contain;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc;padding:6px">
                </a>
                <p style="margin:8px 0;color:#7b8189;font-size:15px">Status: <?php echo htmlspecialchars(ucfirst($selectedOrder['prescription_status'] ?? 'pending')); ?></p>
                <?php if (($selectedOrder['prescription_status'] ?? 'pending') === 'pending'): ?>
                <div style="display:flex;gap:8px">
            <form method="post"><input type="hidden" name="action" value="review_prescription"><input type="hidden" name="order_id" value="<?php echo $selectedId; ?>"><input type="hidden" name="decision" value="accepted"><button type="submit" style="border:0;border-radius:6px;padding:10px 14px;background:#15955c;color:#fff;cursor:pointer;font-size:14px;font-weight:700">Accept</button></form>
            <form method="post"><input type="hidden" name="action" value="review_prescription"><input type="hidden" name="order_id" value="<?php echo $selectedId; ?>"><input type="hidden" name="decision" value="rejected"><button type="submit" style="border:0;border-radius:6px;padding:10px 14px;background:#db294b;color:#fff;cursor:pointer;font-size:14px;font-weight:700">Reject</button></form>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php endif; ?>

                <div class="details-footer"><a href="<?php echo orderQuery($page); ?>">Close</a></div>
            </div>
            <?php else: ?>
            <p class="empty-details">Select an order to view details.</p>
            <?php endif; ?>
        </aside>

        <?php if ($detailsOpen
            && in_array(strtolower(trim($selectedOrder['payment_method'] ?? '')), ['gcash', 'bank', 'bpi', 'bank transfer'], true)
            && in_array(strtolower(trim($selectedOrder['payment_status'] ?? '')), ['pending', 'unpaid', ''], true)
            && empty($selectedOrder['rider_id'])
            && !in_array(strtolower(trim($selectedOrder['status'] ?? '')), ['cancelled', 'delivered'], true)
            && (in_array($_SESSION['role'] ?? '', ['admin', 'owner'], true)
                || ($isPharmacist && pharmacistCanAccessOrder($conn, (int)$selectedOrder['id'])))): ?>
        <div class="payment-rejection-dialog" data-payment-rejection-dialog hidden>
            <div class="payment-rejection-dialog-card" role="dialog" aria-modal="true" aria-labelledby="payment-rejection-title">
                <h3 id="payment-rejection-title">Reject online payment</h3>
                <p>Select a reason to share with the customer. Add remarks if more detail is needed.</p>
                <form method="post" class="payment-rejection-form">
                    <input type="hidden" name="action" value="review_payment">
                    <input type="hidden" name="order_id" value="<?php echo (int)$selectedOrder['id']; ?>">
                    <input type="hidden" name="decision" value="rejected">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($statusUpdateToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <label for="payment-rejection-reason">Rejection Reason
                        <select id="payment-rejection-reason" name="rejection_reason" required>
                            <option value="" selected disabled>Select a reason</option>
                            <option value="Incorrect Amount">Incorrect Amount</option>
                            <option value="Unverified Payment">Unverified Payment</option>
                            <option value="Invalid Reference Number">Invalid Reference Number</option>
                            <option value="Duplicate Receipt">Duplicate Receipt</option>
                            <option value="Incomplete Receipt">Incomplete Receipt</option>
                            <option value="Blurry or Unclear Image">Blurry or Unclear Image</option>
                            <option value="Payment Not Received">Payment Not Received</option>
                            <option value="Wrong Recipient Account">Wrong Recipient Account</option>
                            <option value="Other">Other</option>
                        </select>
                    </label>
                    <label for="payment-rejection-remarks">Additional Remarks (Optional)
                        <textarea id="payment-rejection-remarks" name="rejection_remarks" maxlength="300" placeholder="Add any details the customer should know"></textarea>
                    </label>
                    <div class="payment-rejection-dialog-actions">
                        <button type="button" class="payment-rejection-cancel" data-close-payment-rejection>Cancel</button>
                        <button type="submit" class="payment-rejection-submit">Confirm rejection</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($detailsOpen): ?>
        <a class="details-overlay" href="<?php echo orderQuery($page); ?>" aria-label="Close order details"></a>
        <?php endif; ?>
    </div>
</main>
<script>
function submitShippingForm(event, form) {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Saving...';
    fetch('admin_orders.php', { method: 'POST', body: new FormData(form) })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.message || 'Unable to mark order as shipped.');
            }
            window.location.reload();
        })
        .catch(error => {
            alert(error.message);
            button.disabled = false;
            button.textContent = 'Mark as Shipped';
        });
    return false;
}

document.addEventListener('DOMContentLoaded', function() {
    const paymentRejectionDialog = document.querySelector('[data-payment-rejection-dialog]');
    const paymentRejectionTrigger = document.querySelector('[data-open-payment-rejection]');
    const paymentRejectionCancel = paymentRejectionDialog
        ? paymentRejectionDialog.querySelector('[data-close-payment-rejection]')
        : null;
    const closePaymentRejectionDialog = function() {
        if (!paymentRejectionDialog) {
            return;
        }
        paymentRejectionDialog.hidden = true;
        document.body.style.overflow = '';
        if (paymentRejectionTrigger) {
            paymentRejectionTrigger.focus();
        }
    };
    if (paymentRejectionTrigger && paymentRejectionDialog) {
        paymentRejectionTrigger.addEventListener('click', function() {
            paymentRejectionDialog.hidden = false;
            document.body.style.overflow = 'hidden';
            const reasonSelect = paymentRejectionDialog.querySelector('[name="rejection_reason"]');
            if (reasonSelect) {
                reasonSelect.focus();
            }
        });
        paymentRejectionDialog.addEventListener('click', function(event) {
            if (event.target === paymentRejectionDialog) {
                closePaymentRejectionDialog();
            }
        });
        if (paymentRejectionCancel) {
            paymentRejectionCancel.addEventListener('click', closePaymentRejectionDialog);
        }
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && !paymentRejectionDialog.hidden) {
                closePaymentRejectionDialog();
            }
        });
    }

    document.querySelectorAll('.pickup-countdown').forEach(function(timer) {
        const deadline = new Date(timer.dataset.deadline.replace(' ', 'T')).getTime();
        const updateTimer = function() {
            const remaining = deadline - Date.now();
            if (remaining <= 0) {
                timer.innerHTML = 'Pickup window expired. Refreshing order status...';
                window.setTimeout(function() { window.location.reload(); }, 1000);
                return;
            }
            const totalMinutes = Math.floor(remaining / 60000);
            const hours = Math.floor(totalMinutes / 60);
            const minutes = totalMinutes % 60;
            const seconds = Math.floor((remaining % 60000) / 1000);
            timer.innerHTML = 'Time remaining: <strong>' + hours + 'h ' + String(minutes).padStart(2, '0') + 'm ' + String(seconds).padStart(2, '0') + 's</strong>';
        };
        updateTimer();
        window.setInterval(updateTimer, 1000);
    });
    document.querySelectorAll('.summary-grid .summary-card').forEach(function(card, index) {
        if (index === 0) return;
        const statuses = ['pending', 'processing', 'delivered', 'cancelled'];
        card.style.cursor = 'pointer';
        card.addEventListener('click', function() {
            const params = new URLSearchParams(window.location.search);
            params.set('status', statuses[index - 1]);
            params.delete('page');
            params.delete('order');
            window.location.search = params.toString();
        });
    });
    const toolbarSearchInput = document.querySelector('.search-input-wrapper input[name="q"]');
    const toolbarStatusSelect = document.querySelector('.toolbar select[name="status"]');
    const toolbarPaymentSelect = document.querySelector('.toolbar select[name="payment_method"]');
    const ordersDateRangeSelect = document.querySelector('.orders-date-filter select[name="date_range"]');
    const toolbarClearBtn = document.getElementById('clearToolbarSearch');
    if (toolbarSearchInput && toolbarClearBtn) {
        toolbarSearchInput.addEventListener('input', function() {
            toolbarClearBtn.style.display = this.value ? 'block' : 'none';
        });

        toolbarClearBtn.addEventListener('click', function() {
            toolbarSearchInput.value = '';
            toolbarSearchInput.form.submit();
        });
    }
    if (toolbarStatusSelect) {
        toolbarStatusSelect.addEventListener('change', function() {
            this.form.requestSubmit();
        });
    }
    if (toolbarPaymentSelect) {
        toolbarPaymentSelect.addEventListener('change', function() {
            this.form.requestSubmit();
        });
    }
    if (ordersDateRangeSelect) {
        ordersDateRangeSelect.addEventListener('change', function() {
            this.form.requestSubmit();
        });
    }

});
</script>