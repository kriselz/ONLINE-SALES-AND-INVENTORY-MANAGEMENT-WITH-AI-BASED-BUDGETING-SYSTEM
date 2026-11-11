<?php
session_start();
include '../owner/config.php';
require_once __DIR__ . '/../owner/temporary_password_helpers.php';
require_once __DIR__ . '/delivery_helpers.php';
require_once __DIR__ . '/../owner/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'rider') {
    header('Location: rider_login.php');
    exit();
}
try {
    if (hasPendingTemporaryPasswordChange($conn, (int)$_SESSION['user_id'])) {
        $_SESSION['password_change_required'] = true;
        header('Location: rider_profile.php');
        exit();
    }
    unset($_SESSION['password_change_required']);
} catch (RuntimeException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Unable to verify the account password status.');
}

$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS rider_id INT NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(30) NOT NULL DEFAULT 'in_transit'");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_failure_reason VARCHAR(100) NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_action VARCHAR(100) NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00");
$paymentSchemaReady = $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'")
    && $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100) NULL")
    && $conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_verified_at DATETIME NULL");
if (!$paymentSchemaReady) {
    error_log('Rider COD payment schema preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load delivery payments.');
}
$conn->query("CREATE TABLE IF NOT EXISTS rider_profiles (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, phone VARCHAR(30) NULL, vehicle_type VARCHAR(50) NULL, plate_number VARCHAR(30) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
ensureRiderDeliverySchema($conn);
$message = '';
$error = '';
$riderId = (int)$_SESSION['user_id'];
$view = $riderDeliveryView ?? ($_GET['view'] ?? 'available');
if (!in_array($view, ['available', 'active', 'history'], true)) {
    $view = 'available';
}
$deliveryPages = [
    'available' => 'availdel.php',
    'active' => 'activedel.php',
    'history' => 'delhisto.php',
];
$deliveryPage = $deliveryPages[$view];
if (isset($_SESSION['rider_dashboard_message'])) {
    $message = (string)$_SESSION['rider_dashboard_message'];
    unset($_SESSION['rider_dashboard_message']);
}
$profileResult = $conn->query("SELECT u.full_name, u.email, rp.phone, rp.vehicle_type, rp.plate_number, rp.profile_image, rp.is_available, rp.latitude, rp.longitude FROM users u LEFT JOIN rider_profiles rp ON rp.user_id = u.id WHERE u.id = $riderId AND u.role = 'rider' LIMIT 1");
if (!$profileResult) {
    error_log('Unable to load rider profile before delivery access: ' . $conn->error);
    http_response_code(500);
    exit('Unable to verify your rider profile.');
}
$riderProfile = $profileResult->fetch_assoc();
if (
    !$riderProfile
    || trim((string)($riderProfile['full_name'] ?? '')) === ''
    || trim((string)($riderProfile['email'] ?? '')) === ''
    || trim((string)($riderProfile['phone'] ?? '')) === ''
    || trim((string)($riderProfile['vehicle_type'] ?? '')) === ''
    || trim((string)($riderProfile['plate_number'] ?? '')) === ''
) {
    header('Location: rider_profile.php?setup=1');
    exit();
}
$riderDisplayName = trim((string)($riderProfile['full_name'] ?? '')) ?: ($_SESSION['username'] ?? 'Rider');
$riderInitials = strtoupper(substr(preg_replace('/\s+/', '', $riderDisplayName), 0, 2));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_location') {
    $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        http_response_code(400);
        exit();
    }
    $locationStmt = $conn->prepare('INSERT INTO rider_profiles (user_id, latitude, longitude, last_seen_at, is_available) VALUES (?, ?, ?, NOW(), 0) ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), last_seen_at = NOW()');
    if (!$locationStmt) {
        http_response_code(500);
        exit();
    }
    $locationStmt->bind_param('idd', $riderId, $latitude, $longitude);
    $locationSaved = $locationStmt->execute();
    $locationStmt->close();
    if (!$locationSaved) {
        http_response_code(500);
        exit();
    }
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'heartbeat') {
    $heartbeatStmt = $conn->prepare('UPDATE rider_profiles SET last_seen_at = NOW() WHERE user_id = ? AND is_available = 1');
    if (!$heartbeatStmt) {
        error_log('Rider availability heartbeat preparation failed: ' . $conn->error);
        http_response_code(500);
        exit();
    }
    $heartbeatStmt->bind_param('i', $riderId);
    if (!$heartbeatStmt->execute()) {
        error_log('Rider availability heartbeat failed: ' . $heartbeatStmt->error);
        $heartbeatStmt->close();
        http_response_code(500);
        exit();
    }
    $heartbeatStmt->close();
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_availability') {
    $availability = (int)($_POST['is_available'] ?? 0) === 1 ? 1 : 0;
    $availabilityStmt = $conn->prepare('INSERT INTO rider_profiles (user_id, is_available, last_seen_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE is_available = VALUES(is_available), last_seen_at = NOW()');
    if ($availabilityStmt) {
        $availabilityStmt->bind_param('ii', $riderId, $availability);
        $availabilityStmt->execute();
        $availabilityStmt->close();
        $message = $availability ? 'You are now available for deliveries.' : 'You are now offline.';
        $profileResult = $conn->query("SELECT phone, vehicle_type, plate_number, is_available, latitude, longitude FROM rider_profiles WHERE user_id = $riderId LIMIT 1");
        $riderProfile = $profileResult ? $profileResult->fetch_assoc() : $riderProfile;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_delivery') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $deliveryStatus = $_POST['delivery_status'] ?? '';
    $reason = trim($_POST['delivery_failure_reason'] ?? '');
    $deliveryAction = trim($_POST['delivery_action'] ?? '');
    $allowedStatuses = ['in_transit', 'failed'];
    $allowedReasons = ['', 'customer_unavailable', 'customer_refused', 'wrong_address', 'other'];
    $allowedActions = ['', 'retry_delivery', 'return_to_pharmacy', 'cancel_order'];
    $cancellationPhotoPath = '';
    $newCancellationPhotoPath = '';

    if (!in_array($deliveryStatus, $allowedStatuses, true) || !in_array($reason, $allowedReasons, true) || !in_array($deliveryAction, $allowedActions, true)) {
        $error = 'Invalid delivery response.';
    } elseif ($deliveryAction === 'cancel_order' && ($deliveryStatus !== 'failed' || $reason === '')) {
        $error = 'Select a cancellation reason before cancelling the delivery.';
    } elseif ($deliveryStatus === 'failed' && ($reason === '' || $deliveryAction === '')) {
        $error = 'Select a reason and action for failed delivery.';
    } else {
        $proofCheck = $conn->prepare('SELECT delivery_proof_photo, delivery_type, status, user_id FROM orders WHERE id = ? AND rider_id = ? LIMIT 1');
        if ($proofCheck) {
            $proofCheck->bind_param('ii', $orderId, $riderId);
            $proofCheck->execute();
            $savedProof = $proofCheck->get_result()->fetch_assoc();
            $proofCheck->close();
        } else {
            $savedProof = null;
        }

        if (!$savedProof) {
            $error = 'Order was not assigned to your rider account.';
        } elseif ($deliveryAction === 'cancel_order'
            && !in_array(strtolower(trim((string)($savedProof['delivery_type'] ?? ''))), ['lalamove', 'delivery'], true)) {
            $error = 'This delivery cannot be cancelled by the rider.';
        } elseif ($deliveryStatus === 'delivered' && empty($savedProof['delivery_proof_photo'])) {
            $error = 'Upload a proof photo before marking this delivery as delivered.';
        } else {
            $isLalamoveOrder = in_array(strtolower(trim((string)($savedProof['delivery_type'] ?? ''))), ['lalamove', 'delivery'], true);
            $isLalamoveCancellation = $isLalamoveOrder && $deliveryStatus === 'failed' && $deliveryAction === 'cancel_order';
            if ($isLalamoveCancellation && isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['proof_photo']['error'] !== UPLOAD_ERR_OK) {
                    $error = 'The cancellation proof photo upload failed. Please try again.';
                } else {
                    $mime = mime_content_type($_FILES['proof_photo']['tmp_name']);
                    $allowedProofTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
                    if (!isset($allowedProofTypes[$mime]) || (int)$_FILES['proof_photo']['size'] > 5 * 1024 * 1024) {
                        $error = 'Cancellation proof must be JPG or PNG up to 5 MB.';
                    } else {
                        $directory = __DIR__ . '/../user/uploads/delivery_proofs';
                        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                            $error = 'Unable to prepare the cancellation proof upload directory.';
                        } else {
                            $filename = 'delivery_cancel_' . $orderId . '_' . bin2hex(random_bytes(6)) . '.' . $allowedProofTypes[$mime];
                            if (move_uploaded_file($_FILES['proof_photo']['tmp_name'], $directory . '/' . $filename)) {
                                $cancellationPhotoPath = 'uploads/delivery_proofs/' . $filename;
                                $newCancellationPhotoPath = $directory . '/' . $filename;
                            } else {
                                $error = 'Unable to save the cancellation proof photo.';
                            }
                        }
                    }
                }
            }

            if ($error === '') {
                $orderStatus = $deliveryStatus === 'delivered' ? 'delivered' : ($isLalamoveCancellation ? 'cancelled' : '');
                $savedDeliveryStatus = $isLalamoveCancellation ? 'cancelled' : $deliveryStatus;
                $stmt = $conn->prepare("UPDATE orders SET delivery_status = ?, delivery_failure_reason = NULLIF(?, ''), delivery_action = NULLIF(?, ''), delivery_proof_photo = COALESCE(NULLIF(?, ''), delivery_proof_photo), status = CASE WHEN ? = 'delivered' THEN 'delivered' WHEN ? = 'cancelled' THEN 'cancelled' ELSE status END WHERE id = ? AND rider_id = ? AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'delivered') AND (? <> 'delivered' OR COALESCE(delivery_proof_photo, '') <> '')");
                if ($stmt) {
                    $stmt->bind_param('ssssssiis', $savedDeliveryStatus, $reason, $deliveryAction, $cancellationPhotoPath, $deliveryStatus, $orderStatus, $orderId, $riderId, $deliveryStatus);
                    $saved = $stmt->execute();
                    $deliveryUpdateSucceeded = $saved && $stmt->affected_rows > 0;
                    $message = $deliveryUpdateSucceeded
                        ? ($isLalamoveCancellation ? 'Lalamove delivery cancelled. The order was cancelled and the reason was recorded.' : 'Delivery response saved.')
                        : 'Unable to save delivery response.';
                    if ($deliveryUpdateSucceeded) {
                        $eventType = $deliveryStatus === 'delivered'
                            ? 'delivered'
                            : ($isLalamoveCancellation ? 'delivery_cancelled' : ($deliveryStatus === 'failed' ? 'delivery_failed' : 'in_transit'));
                        $eventNote = $isLalamoveCancellation
                            ? 'Lalamove order cancelled. Reason: ' . rider_label($reason) . '. Action: Cancel order.'
                            : ($reason !== '' ? rider_label($reason) : 'Delivery status updated');
                        recordDeliveryEvent($conn, $orderId, $riderId, $eventType, $eventNote);
                        if ($isLalamoveCancellation && !restoreInventoryForCancelledOrder($conn, $orderId)) {
                            error_log('Inventory restoration failed after rider cancelled Lalamove order #' . $orderId);
                        }
                        if (!empty($savedProof['user_id'])) {
                            $notification = $isLalamoveCancellation
                                ? 'Your Lalamove order ' . formatOrderNumber($conn, $orderId) . ' was cancelled by the rider. Reason: ' . rider_label($reason) . '. Action: Cancel order.'
                                : 'Delivery update for order ' . formatOrderNumber($conn, $orderId) . ': ' . rider_label($eventType) . '.';
                            riderNotify($conn, (int)$savedProof['user_id'], $notification);
                            if ($eventType === 'delivered') {
                                awardLoyaltyPointsForOrder($conn, $orderId, $riderId);
                            }
                        }
                    }
                    $stmt->close();
                    if (!$deliveryUpdateSucceeded && $newCancellationPhotoPath !== '') {
                        unlink($newCancellationPhotoPath);
                    }
                } else {
                    if ($newCancellationPhotoPath !== '') {
                        unlink($newCancellationPhotoPath);
                    }
                    $error = 'Unable to save delivery response.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_proof') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $proof = $conn->prepare('SELECT user_id, delivery_proof_photo, payment_method FROM orders WHERE id = ? AND rider_id = ? AND LOWER(COALESCE(status, \'\')) NOT IN (\'cancelled\', \'delivered\') AND LOWER(COALESCE(delivery_status, \'in_transit\')) <> \'delivered\' LIMIT 1');
    if ($proof) {
        $proof->bind_param('ii', $orderId, $riderId);
        $proof->execute();
        $proofOrder = $proof->get_result()->fetch_assoc();
        $proof->close();
    } else {
        $proofOrder = null;
        $error = 'Unable to verify this delivery.';
    }
    if (!$proofOrder) {
        if ($error === '') {
            $error = 'Order is not assigned to your rider account or is already completed.';
        }
    } else {
        $photoPath = trim((string)($proofOrder['delivery_proof_photo'] ?? ''));
        $hasNewPhoto = isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasNewPhoto && $_FILES['proof_photo']['error'] !== UPLOAD_ERR_OK) {
            $error = 'The proof photo upload failed. Please try again.';
        } elseif ($hasNewPhoto) {
            $mime = mime_content_type($_FILES['proof_photo']['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!isset($allowed[$mime]) || (int)$_FILES['proof_photo']['size'] > 5 * 1024 * 1024) {
                $error = 'Proof photo must be JPG or PNG up to 5 MB.';
            } else {
                $directory = __DIR__ . '/../user/uploads/delivery_proofs';
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    $error = 'Unable to prepare the delivery proof upload directory.';
                }
                if ($error === '') {
                    $filename = 'delivery_' . $orderId . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                    if (move_uploaded_file($_FILES['proof_photo']['tmp_name'], $directory . '/' . $filename)) {
                        $photoPath = 'uploads/delivery_proofs/' . $filename;
                    } else {
                        $error = 'Unable to save the proof photo.';
                    }
                }
            }
        }
        if ($error === '' && $photoPath === '') {
            $error = 'Upload a proof photo before completing this delivery.';
        }
        if ($error === '') {
            $isCashOnDelivery = in_array(strtolower(trim((string)($proofOrder['payment_method'] ?? ''))), ['cod', 'cash'], true);
            $saveProof = $conn->prepare("UPDATE orders
                SET delivery_proof_photo = ?, delivery_status = 'delivered', status = 'delivered',
                    payment_status = CASE WHEN ? = 1 THEN 'paid' ELSE payment_status END,
                    payment_reference = CASE WHEN ? = 1 THEN COALESCE(NULLIF(payment_reference, ''), 'Cash collected on delivery') ELSE payment_reference END,
                    payment_verified_at = CASE WHEN ? = 1 THEN COALESCE(payment_verified_at, NOW()) ELSE payment_verified_at END
                WHERE id = ? AND rider_id = ?
                  AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'delivered')
                  AND LOWER(COALESCE(delivery_status, 'in_transit')) <> 'delivered'");
            if ($saveProof) {
                $saveProof->bind_param('siiiii', $photoPath, $isCashOnDelivery, $isCashOnDelivery, $isCashOnDelivery, $orderId, $riderId);
                $proofSaved = $saveProof->execute() && $saveProof->affected_rows > 0;
                $saveProof->close();
                if ($proofSaved) {
                    recordDeliveryEvent($conn, $orderId, $riderId, 'delivered', 'Delivery completed and proof photo saved');
                    if (!empty($proofOrder['user_id'])) {
                        riderNotify($conn, (int)$proofOrder['user_id'], 'Your order ' . formatOrderNumber($conn, $orderId) . ' has been delivered.');
                        awardLoyaltyPointsForOrder($conn, $orderId, $riderId);
                    }
                    $_SESSION['rider_dashboard_message'] = 'Delivery completed and moved to Delivered History.';
                    header('Location: ' . $deliveryPages['history']);
                    exit();
                } else {
                    $error = 'Unable to complete this delivery. It may have already been updated.';
                }
            } else {
                $error = 'Unable to complete this delivery.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_delivery') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $claim = $conn->prepare("UPDATE orders SET rider_id = ?, status = 'shipped', delivery_status = 'in_transit' WHERE id = ? AND rider_id IS NULL AND LOWER(COALESCE(status, '')) = 'processing' AND LOWER(COALESCE(delivery_status, 'in_transit')) <> 'delivered' AND LOWER(COALESCE(delivery_type, 'delivery')) NOT IN ('pickup', 'self_pickup', 'self-pickup', 'onsite') AND (LOWER(COALESCE(discount_type, '')) NOT IN ('senior_citizen', 'pwd') OR LOWER(COALESCE(discount_status, 'pending_verification')) IN ('approved', 'rejected')) AND (LOWER(COALESCE(payment_method, '')) = 'cod' OR (LOWER(COALESCE(payment_method, '')) IN ('gcash', 'bank') AND LOWER(COALESCE(payment_status, 'unpaid')) = 'paid'))");
    if ($claim) {
        $claim->bind_param('ii', $riderId, $orderId);
        $claim->execute();
        $message = $claim->affected_rows > 0 ? 'Delivery accepted and added to your active deliveries.' : 'This delivery is not ready for rider assignment yet. The admin/pharmacist must mark the order as Processing first.';
        if ($claim->affected_rows > 0) {
            recordDeliveryEvent($conn, $orderId, $riderId, 'rider_assigned', 'Delivery accepted by rider');
            $_SESSION['rider_dashboard_message'] = 'Delivery accepted and added to your active deliveries.';
            $claim->close();
            header('Location: ' . $deliveryPages['active']);
            exit();
        }
        $claim->close();
    } else {
        $error = 'Unable to accept delivery.';
    }
}

$orderSelect = "SELECT o.id, o.user_id, o.fullname, o.contact_number, o.address, o.order_date, o.delivery_date, COALESCE((SELECT MAX(de.created_at) FROM delivery_events de WHERE de.order_id = o.id AND de.event_type = 'delivered'), o.delivery_date) AS delivered_at, o.payment_method, o.payment_status, o.shipping_fee, o.delivery_type, o.delivery_status, o.delivery_failure_reason, o.delivery_action, o.delivery_latitude, o.delivery_longitude, o.delivery_proof_photo, COALESCE(SUM(oi.price), 0) AS items_total FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id WHERE o.rider_id = $riderId AND LOWER(COALESCE(o.status, '')) <> 'cancelled'";
$availableOrders = $conn->query("SELECT o.id, o.fullname, o.contact_number, o.address, o.delivery_latitude, o.delivery_longitude, o.order_date, o.payment_method, o.payment_status, o.shipping_fee, COALESCE(SUM(oi.price), 0) AS items_total FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id WHERE o.rider_id IS NULL AND LOWER(COALESCE(o.status, '')) = 'processing' AND LOWER(COALESCE(o.delivery_status, 'in_transit')) <> 'delivered' AND LOWER(COALESCE(o.delivery_type, 'delivery')) NOT IN ('pickup', 'self_pickup', 'self-pickup', 'onsite') AND (LOWER(COALESCE(o.discount_type, '')) NOT IN ('senior_citizen', 'pwd') OR LOWER(COALESCE(o.discount_status, 'pending_verification')) IN ('approved', 'rejected')) AND (LOWER(COALESCE(o.payment_method, '')) = 'cod' OR (LOWER(COALESCE(o.payment_method, '')) IN ('gcash', 'bank') AND LOWER(COALESCE(o.payment_status, 'unpaid')) = 'paid')) GROUP BY o.id ORDER BY o.order_date ASC");
$activeOrders = $conn->query($orderSelect . " AND LOWER(COALESCE(o.delivery_status, 'in_transit')) <> 'delivered' GROUP BY o.id ORDER BY o.order_date DESC");
$historyOrders = $conn->query($orderSelect . " AND LOWER(COALESCE(o.delivery_status, 'in_transit')) = 'delivered' GROUP BY o.id ORDER BY o.order_date DESC");
$statsResult = $conn->query("SELECT
    COALESCE(SUM(CASE
        WHEN LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'delivered')
         AND LOWER(COALESCE(delivery_status, 'in_transit')) NOT IN ('cancelled', 'delivered')
        THEN 1 ELSE 0 END), 0) AS active,
    COALESCE(SUM(CASE
        WHEN LOWER(COALESCE(status, '')) = 'delivered'
          OR LOWER(COALESCE(delivery_status, 'in_transit')) = 'delivered'
        THEN 1 ELSE 0 END), 0) AS delivered,
    COALESCE(SUM(CASE
        WHEN LOWER(COALESCE(status, '')) = 'cancelled'
          OR LOWER(COALESCE(delivery_status, 'in_transit')) = 'cancelled'
        THEN 1 ELSE 0 END), 0) AS cancelled,
    COALESCE(SUM(CASE
        WHEN LOWER(COALESCE(status, '')) = 'delivered'
          OR LOWER(COALESCE(delivery_status, 'in_transit')) = 'delivered'
        THEN shipping_fee ELSE 0 END), 0) AS earnings
    FROM orders WHERE rider_id = $riderId");
$riderStats = $statsResult
    ? $statsResult->fetch_assoc()
    : ['active' => 0, 'delivered' => 0, 'cancelled' => 0, 'earnings' => 0];
$notifications = $conn->query("SELECT message, created_at FROM notifications WHERE user_id = $riderId ORDER BY created_at DESC LIMIT 5");
function rider_label($value) {
    return ucwords(str_replace('_', ' ', $value ?: 'Not set'));
}
function rider_map_destination($order) {
    $latitude = $order['delivery_latitude'] ?? null;
    $longitude = $order['delivery_longitude'] ?? null;
    if (is_numeric($latitude) && is_numeric($longitude) && $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
        return rawurlencode($latitude . ',' . $longitude);
    }
    return rawurlencode($order['address'] ?? '');
}
$riderMapOrigin = rawurlencode('454 Rizal Avenue Extension, Caloocan, Metro Manila');
?>
<!DOCTYPE html>
<html lang="en">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rider Deliveries</title>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;background:#f8f9fb;color:#252a30;font-family:Segoe UI,Tahoma,sans-serif}.page{width:min(1080px,100%);margin:0 auto;padding:24px 18px 40px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:20px}.topbar h1{margin:0;font-size:24px}.topbar p{margin:5px 0 0;color:#7b8189;font-size:12px}.logout{color:#ed1b3b;text-decoration:none;font-size:12px;font-weight:700}.notice{padding:11px 13px;border-radius:7px;margin-bottom:15px;font-size:12px}.success{background:#e9f8ef;color:#278658}.error{background:#ffe8ee;color:#b91c1c}.orders{display:grid;gap:14px}.order{background:#fff;border:1px solid #edf0f2;border-radius:10px;padding:17px;box-shadow:0 2px 10px rgba(25,30,38,.04)}.order-head{display:flex;justify-content:space-between;gap:10px;margin-bottom:12px}.order-id{font-weight:800}.status{font-size:10px;padding:5px 8px;border-radius:5px;background:#fff5df;color:#a8730d;font-weight:700}.status.delivered{background:#e9f8ef;color:#278658}.status.failed{background:#ffe8ee;color:#d33b59}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}.meta div{background:#fbfcfd;border-radius:6px;padding:9px}.meta span{display:block;color:#7b8189;font-size:10px;margin-bottom:4px}.meta strong{font-size:12px}.form-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.form-grid label{display:flex;flex-direction:column;gap:5px;color:#68727c;font-size:10px;font-weight:700}.form-grid select{height:34px;border:1px solid #dfe4e9;border-radius:6px;padding:0 7px;background:#fff;color:#424b55;font-size:11px}.form-actions{margin-top:10px;text-align:right}.save{border:0;border-radius:6px;background:#ed1b3b;color:#fff;padding:9px 13px;font-size:11px;font-weight:700;cursor:pointer}@media(max-width:700px){.meta,.form-grid{grid-template-columns:1fr}.topbar{align-items:flex-start}}
</style>
<style>
:root{--ink:#000;--muted:#6b7d82;--brand:#0f766e;--brand-dark:#115e59;--line:#dce9e7;--soft:#f1f8f6;--green:#278658}
body{background:linear-gradient(135deg,#f3f8f7 0%,#fffaf2 100%);color:var(--ink)}
.page{max-width:1180px;padding:34px 24px 48px}
.topbar{padding:22px 24px;background:rgba(255,255,255,.94);border:1px solid var(--line);border-radius:16px;box-shadow:0 12px 30px rgba(30,36,42,.07);margin-bottom:22px}
.topbar h1{font-size:28px;letter-spacing:-.3px;color:#174c4a}
.topbar p{display:inline-block;margin-top:8px;padding:7px 10px;background:#e7f5f1;border-radius:7px;color:#39736f;font-size:11px;font-weight:600}
.logout{padding:9px 13px;border:1px solid #b9dcd6;border-radius:7px;background:#fff;color:var(--brand-dark);transition:.2s}
.logout:hover{background:var(--brand);color:#fff}
.orders{grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.section-title{grid-column:1/-1;margin:4px 0 -4px;color:#174c4a;font-size:16px}
.history{margin-top:28px}
.history .order{height:100%;border-left:4px solid #278658}
.orders.history{grid-template-columns:repeat(3,minmax(0,1fr))}
.history-card .available-customer{margin:14px 0}
.history-card .available-costs{margin-bottom:0}
.history-card .available-payment strong{color:#174c4a}
.history-card .available-collection{margin-top:2px}
.available{margin-bottom:28px}
.available .order{border-left:4px solid #d29a27}
.available-card .order-date::before{content:'Placed '}
.available-customer{display:grid;gap:5px;margin:14px 0;padding:12px 14px;background:var(--soft);border:1px solid #eef1f3;border-radius:9px}
.available-customer-label,.available-payment-label{color:var(--muted);font-size:10px}
.available-customer strong{font-size:13px}
.available-customer span:not(.available-customer-label){color:var(--muted);font-size:11px;line-height:1.45}
.available-payment{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 12px;color:var(--muted);font-size:11px}
.available-costs{display:grid;gap:10px;padding:2px 0 12px;border-bottom:1px solid var(--line)}
.available-cost-row,.available-collection{display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:11px}
.available-cost-row strong{font-size:12px}
.available-collection{padding:12px 0;color:#174c4a;font-size:13px;font-weight:700}
.available-collection strong{font-size:16px;color:var(--brand)}
.available-collection-note{margin:-7px 0 10px;color:var(--muted);font-size:10px}
.orders.available{align-items:stretch}
.available-card{height:100%;display:flex;flex-direction:column}
.available-card .contact-actions{gap:8px;margin:auto 0 10px}
.available-card .contact-actions a{flex:1;text-align:center}
.available-card .form-actions{margin-top:0}
.available-card .accept{width:100%}
.active-card .available-customer{margin:14px 0}
.active-card .available-costs{margin-bottom:0}
.active-card .available-collection-note{margin-bottom:12px}
.active-card .contact-actions{gap:8px;margin:0 0 10px}
.active-card .contact-actions a{flex:1;text-align:center}
.active-card > form:not(.proof-form){margin-top:0}
.active-card > form:not(.proof-form),.active-card .proof-form{min-width:0;width:100%}
.delivery-actions{display:flex;justify-content:flex-end;align-items:center;gap:8px;margin-top:12px}
.delivery-actions .save{margin:0}
.delivery-action-form.is-cancelling .delivery-main-actions > .complete-delivery-button,
.delivery-action-form.is-cancelling .delivery-main-actions > .cancel-toggle{display:none}
.delivery-action-form:not(.is-cancelling) .delivery-main-actions > .cancel-confirm,
.delivery-action-form:not(.is-cancelling) .delivery-main-actions > .cancel-back{display:none}
.cancel-reason-panel{margin-bottom:10px}
.cancel-reason-panel label{display:flex;flex-direction:column;gap:5px;color:#59636d;font-size:10px;font-weight:700}
.cancel-reason-panel select{width:100%;height:38px;padding:0 8px;border:1px solid #d8e0e5;border-radius:6px;background:#fff;font-size:11px}
.cancel-reason-panel[hidden]{display:none}
.cancel-toggle,.cancel-submit{border:1px solid #d33b59;border-radius:6px;padding:10px 15px;background:#fff;color:#b4233c;font-size:11px;font-weight:700;cursor:pointer}
.cancel-submit{background:#b4233c;color:#fff}
.cancel-back{border:0;background:transparent;color:#59636d;font-size:11px;font-weight:700;cursor:pointer}
.active-card .proof-photo-label{display:block;width:100%;min-width:0}
.active-card .proof-form input[type=file]{display:block;width:100%;max-width:100%;height:auto;min-height:42px;margin:5px 0 8px;padding:7px 9px;box-sizing:border-box;text-align:left;line-height:1.4}
.active-card .proof-form input[type=file]::file-selector-button{margin-right:8px}
.accept{border:0;border-radius:7px;background:#d29a27;color:#fff;padding:10px 15px;font-size:11px;font-weight:700;cursor:pointer}
.accept:hover{background:#ad7917}
.booking-details{margin-top:12px;border-top:1px solid var(--line);padding-top:10px}
.booking-details summary{color:var(--brand);font-size:11px;font-weight:700;cursor:pointer;list-style:none}
.booking-details summary::-webkit-details-marker{display:none}
.booking-details summary::before{content:'+';display:inline-grid;place-items:center;width:17px;height:17px;margin-right:6px;border-radius:50%;background:#e7f5f1;color:var(--brand);font-size:13px}
.booking-details[open] summary::before{content:'-'}
.booking-details-content{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;padding:10px;background:#f8fbfa;border:1px solid #e0efec;border-radius:8px}
.booking-details-content span{display:block;color:var(--muted);font-size:9px;margin-bottom:3px}
.booking-details-content strong{font-size:10px;line-height:1.4}
.order{position:relative;padding:20px;border-radius:14px;border-color:var(--line);box-shadow:0 5px 18px rgba(25,30,38,.05);transition:transform .2s,box-shadow .2s}
.order:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(25,30,38,.09)}
.order-head{align-items:center;padding-bottom:13px;border-bottom:1px solid var(--line)}
.order-id{font-size:15px;color:#3a1820;letter-spacing:.2px}.order-date{display:block;margin-top:4px;color:var(--muted);font-size:10px;font-weight:500;letter-spacing:0}
.status{padding:6px 10px;border-radius:999px;font-size:10px;text-transform:uppercase;letter-spacing:.35px}
.meta{grid-template-columns:1fr 1.25fr;gap:8px;margin:15px 0}
.meta div{padding:11px;background:var(--soft);border:1px solid #eef1f3}
.meta div:last-child{grid-column:1/-1}
.meta strong{font-size:11px;line-height:1.4}
.form-grid{gap:10px;padding-top:14px;border-top:1px solid var(--line)}
.form-grid label{color:#59636d;font-size:10px}
.form-grid select{height:38px;border-color:#d8e0e5;font-size:11px;cursor:pointer}
.form-grid select:focus{outline:2px solid #ffd5dc;border-color:var(--brand)}
.form-actions{display:flex;justify-content:flex-end;margin-top:12px}
.save{padding:10px 15px;background:var(--brand);box-shadow:0 5px 12px rgba(15,118,110,.2);transition:.2s}
.save:hover{background:var(--brand-dark);transform:translateY(-1px)}
.rider-tools{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:16px}.stat{min-width:0;padding:11px 14px;background:#fff;border:1px solid var(--line);border-radius:9px;font-size:11px}.stat strong{display:block;color:var(--brand);font-size:18px;margin-top:3px}.availability{display:flex;align-items:center;gap:8px;margin-left:auto;font-size:11px;font-weight:700}.availability button{border:0;border-radius:6px;padding:9px 12px;background:#278658;color:#fff;font-size:11px;font-weight:700;cursor:pointer}.availability button.offline{background:#8b949e}.contact-actions{display:flex;gap:7px;margin:10px 0}.contact-actions a{padding:8px 10px;border:1px solid var(--line);border-radius:6px;color:var(--brand-dark);font-size:10px;text-decoration:none;background:#fff}.proof-form{margin-top:12px;padding-top:12px;border-top:1px solid var(--line)}.proof-form input[type=text],.proof-form input[type=file]{width:100%;height:34px;margin:5px 0 8px;border:1px solid #d8e0e5;border-radius:6px;padding:0 8px;font-size:11px}.proof-form small{display:block;color:var(--muted);font-size:9px}
@media(max-width:780px){.page{padding:20px 14px 35px}.topbar{padding:18px}.orders{grid-template-columns:1fr}.topbar h1{font-size:23px}.topbar p{max-width:100%;line-height:1.5}}
@media(max-width:1100px){.rider-tools{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:620px){.rider-tools{grid-template-columns:repeat(2,minmax(0,1fr))}}
.rider-sidebar{position:fixed;top:0;left:0;bottom:0;width:220px;padding:20px 16px;background:#174c4a;color:#fff;z-index:10;box-shadow:8px 0 24px rgba(23,76,74,.12);transition:width .25s ease}
.rider-appbar{position:fixed;top:0;left:220px;right:0;height:88px;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:12px 30px;background:#fff;border-bottom:1px solid #e8edef;box-shadow:0 3px 12px rgba(25,30,38,.05);z-index:20}
.rider-appbar-brand{display:flex;align-items:center;gap:16px;min-width:0}
.rider-appbar-logo{width:58px;height:58px;object-fit:contain;padding:4px;background:#fff;border-radius:10px;border:1px solid #e6eef0;display:block}
.rider-appbar-copy{display:grid;gap:3px}
.rider-appbar-title{color:#a82d45;font-size:21px;font-weight:700;letter-spacing:.2px}
.rider-appbar-tagline{color:#667085;font-size:11px;font-weight:600;letter-spacing:.6px}
.rider-appbar-account{display:flex;align-items:center;gap:10px;max-width:260px;padding:7px 12px;border:1px solid #edf0f2;border-radius:12px;background:#f8fafb}
.rider-appbar-avatar{width:38px;height:38px;flex:0 0 38px;display:grid;place-items:center;overflow:hidden;border-radius:50%;background:#e7f1ef;color:#174c4a;font-size:12px;font-weight:800}
.rider-appbar-avatar img{width:100%;height:100%;object-fit:cover}
.rider-appbar-user{min-width:0;display:grid;gap:2px}
.rider-appbar-user strong,.rider-appbar-user span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rider-appbar-user strong{color:#344054;font-size:12px}
.rider-appbar-user span{color:#7b8794;font-size:10px}
.rider-brand{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0 6px 18px;border-bottom:1px solid rgba(255,255,255,.16);font-size:18px;font-weight:800}
.rider-brand-title{display:flex;align-items:center;gap:9px;min-width:0}
.rider-brand-badge{display:grid;place-items:center;width:42px;height:42px;flex:0 0 42px;background:rgba(255,255,255,.08);border-radius:10px;overflow:hidden;color:#fff}
.rider-brand-badge img{display:block;width:100%;height:100%;object-fit:contain}
.rider-brand-badge .material-icons-outlined{font-size:23px}
.rider-brand-copy{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rider-brand-copy span{display:block;margin-top:3px;color:#b8d8d3;font-size:10px;font-weight:600}
.sidebar-toggle{display:grid;place-items:center;width:38px;height:38px;flex:0 0 38px;border:0;border-radius:6px;background:transparent;color:#d8efeb;cursor:pointer}
.sidebar-toggle:hover{background:rgba(255,255,255,.1);color:#fff}
.sidebar-toggle .material-icons-outlined{font-size:25px}
.rider-nav{display:grid;gap:7px;margin-top:22px}
.rider-nav a{display:flex;align-items:center;gap:13px;padding:12px 13px;border-radius:8px;color:#d8efeb;text-decoration:none;font-size:12px;font-weight:700}
.rider-nav .material-icons-outlined{width:20px;flex:0 0 20px;font-size:20px;text-align:center}
.rider-nav .nav-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rider-nav a:hover,.rider-nav a.active{background:#267873;color:#fff}
.rider-nav .logout-link{margin-top:18px;color:#ffd8d8}
.page{width:calc(100% - 220px);max-width:none;margin:0 0 0 220px;padding:140px clamp(22px,3vw,44px) 48px}
.rider-sidebar.collapsed{width:76px;padding-right:10px;padding-left:10px}
.rider-sidebar.collapsed .rider-brand{justify-content:center;padding-right:0;padding-left:0}
.rider-sidebar.collapsed .rider-brand-copy,.rider-sidebar.collapsed .nav-label{display:none}
.rider-sidebar.collapsed .rider-brand-badge{display:none}
.rider-sidebar.collapsed .rider-brand-title{gap:0}
.rider-sidebar.collapsed .rider-nav a{justify-content:center;padding-right:8px;padding-left:8px}
.rider-sidebar.collapsed .rider-nav a .material-icons-outlined{width:24px;flex-basis:24px}
body.sidebar-collapsed .rider-appbar{left:76px}
body.sidebar-collapsed .page{width:calc(100% - 76px);margin-left:76px}
.topbar{width:100%}
.rider-tools{width:100%}
.orders{width:100%}
.orders.available,.orders.active-orders,.orders.history{grid-template-columns:repeat(3,minmax(0,1fr))}
@media(max-width:1100px){.orders.available,.orders.active-orders,.orders.history{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:780px){.orders.available,.orders.active-orders,.orders.history{grid-template-columns:minmax(0,1fr)}}
@media(max-width:780px){.rider-appbar{position:relative;left:0;height:auto;min-height:76px;padding:10px 14px}.rider-appbar-brand{gap:9px}.rider-appbar-logo{width:46px;height:46px}.rider-appbar-title{font-size:15px}.rider-appbar-tagline{font-size:8px;letter-spacing:.2px}.rider-appbar-account{gap:7px;padding:5px 7px;max-width:45%}.rider-appbar-avatar{width:32px;height:32px;flex-basis:32px}.rider-appbar-user strong{font-size:10px}.rider-appbar-user span{font-size:9px}.rider-sidebar,.rider-sidebar.collapsed{position:relative;width:auto;padding:14px 16px}.rider-brand,.rider-sidebar.collapsed .rider-brand{justify-content:space-between;padding:0 6px 12px}.rider-brand-copy,.rider-sidebar.collapsed .rider-brand-copy{display:block}.rider-sidebar.collapsed .rider-brand-badge{display:grid}.rider-nav{display:flex;flex-wrap:wrap;margin-top:12px}.rider-nav a{padding:9px 10px}.rider-nav .logout-link{margin-top:0}.rider-sidebar.collapsed .rider-nav a{justify-content:flex-start}.rider-sidebar.collapsed .rider-nav a .material-icons-outlined{width:20px;flex-basis:20px}.rider-sidebar.collapsed .nav-label{display:inline}body.sidebar-collapsed .rider-nav{display:none}body.sidebar-collapsed .rider-appbar{left:0}body.sidebar-collapsed .page{width:100%;margin:0}.page{width:100%;margin:0;padding:20px 16px 35px}}
.topbar h1,.topbar p,.section-title,.order,.order-id,.meta span,.meta strong,.booking-details summary,.booking-details-content span,.booking-details-content strong,.form-grid label,.stat,.availability,.contact-actions a,.proof-form small{color:#000}
@media(max-width:780px){
    .rider-sidebar,.rider-sidebar.collapsed{position:fixed;top:0;left:0;bottom:0;width:76px;padding:14px 10px}
    .rider-brand,.rider-sidebar.collapsed .rider-brand{justify-content:center;padding:0 0 12px}
    .rider-brand-title,.rider-sidebar.collapsed .rider-brand-title{justify-content:center;gap:0}
    .rider-brand-copy,.rider-sidebar.collapsed .rider-brand-copy,.sidebar-toggle{display:none}
    .rider-sidebar .rider-brand-badge,.rider-sidebar.collapsed .rider-brand-badge{display:grid}
    .rider-nav,body.sidebar-collapsed .rider-nav,.rider-sidebar.collapsed .rider-nav{display:grid;gap:7px;margin-top:12px}
    .rider-nav a,.rider-sidebar.collapsed .rider-nav a{justify-content:center;padding:11px 8px}
    .rider-nav a .material-icons-outlined,.rider-sidebar.collapsed .rider-nav a .material-icons-outlined{width:24px;flex-basis:24px}
    .rider-nav .nav-label,.rider-sidebar.collapsed .nav-label{display:none}
    .rider-nav .logout-link{margin-top:12px}
    .rider-appbar,body.sidebar-collapsed .rider-appbar{position:fixed;top:0;left:76px;right:0;height:auto;min-height:76px;padding:10px 14px}
    .rider-appbar-brand{gap:9px}
    .rider-appbar-logo{width:46px;height:46px}
    .rider-appbar-title{font-size:15px}
    .rider-appbar-tagline{font-size:8px;letter-spacing:.2px}
    .rider-appbar-account{gap:7px;padding:5px 7px;max-width:45%}
    .rider-appbar-avatar{width:32px;height:32px;flex-basis:32px}
    .rider-appbar-user strong{font-size:10px}
    .rider-appbar-user span{font-size:9px}
    .page,body.sidebar-collapsed .page{width:calc(100% - 76px);margin:0 0 0 76px;padding:100px 16px 35px}
}
</style>
</head>
<body>
<?php
$riderLayoutProfile = $riderProfile;
$riderLayoutDisplayName = $riderDisplayName;
$riderLayoutInitials = $riderInitials;
$riderActivePage = $view;
$riderUseBrandLogo = false;
include __DIR__ . '/rider_sidebar.php';
?>
<main class="page">
    <header class="topbar">
        <div><h1>My Deliveries</h1><p>Rider: <?php echo htmlspecialchars($_SESSION['username']); ?> | <?php echo htmlspecialchars($riderProfile['phone'] ?? 'No contact'); ?> | <?php echo htmlspecialchars($riderProfile['vehicle_type'] ?? 'No vehicle'); ?> / <?php echo htmlspecialchars($riderProfile['plate_number'] ?? 'No plate'); ?></p></div>
        <form class="availability" method="post">
            <input type="hidden" name="action" value="toggle_availability">
            <input type="hidden" name="is_available" value="<?php echo !empty($riderProfile['is_available']) ? '0' : '1'; ?>">
            <span><?php echo !empty($riderProfile['is_available']) ? 'Available' : 'Offline'; ?></span>
            <button class="<?php echo empty($riderProfile['is_available']) ? 'offline' : ''; ?>" type="submit"><?php echo !empty($riderProfile['is_available']) ? 'Go Offline' : 'Go Available'; ?></button>
        </form>
    </header>
    <section class="rider-tools">
        <div class="stat">Available deliveries<strong><?php echo number_format($availableOrders ? $availableOrders->num_rows : 0); ?></strong></div>
        <div class="stat">Active<strong><?php echo number_format((int)$riderStats['active']); ?></strong></div>
        <div class="stat">Delivered<strong><?php echo number_format((int)$riderStats['delivered']); ?></strong></div>
        <div class="stat">Cancelled<strong><?php echo number_format((int)$riderStats['cancelled']); ?></strong></div>
        <div class="stat">Gross delivery fees<strong>PHP <?php echo number_format((float)$riderStats['earnings'], 2); ?></strong></div>
    </section>
    <?php if ($notifications && $notifications->num_rows): ?>
    <section class="order" style="margin-bottom:16px"><strong>Notifications</strong><?php while ($notification = $notifications->fetch_assoc()): ?><div style="padding:8px 0;border-bottom:1px solid var(--line);font-size:11px"><span><?php echo htmlspecialchars($notification['message']); ?></span><small style="display:block;color:var(--muted);margin-top:3px"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($notification['created_at']))); ?></small></div><?php endwhile; ?></section>
    <?php endif; ?>
    <?php if ($message): ?><div class="notice success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($view === 'available'): ?>
    <section class="orders available">
        <h2 class="section-title">Available Deliveries</h2>
        <?php if ($availableOrders && $availableOrders->num_rows): ?>
            <?php while ($order = $availableOrders->fetch_assoc()): ?>
            <?php
            $availablePaymentMethod = strtolower(trim((string)($order['payment_method'] ?? 'cod')));
            $cashCollectionApplies = in_array($availablePaymentMethod, ['cod', 'cash'], true);
            $onlinePaymentSettled = in_array($availablePaymentMethod, ['gcash', 'bank', 'bpi'], true)
                && strtolower(trim((string)($order['payment_status'] ?? ''))) === 'paid';
            $availableAmountToCollect = $cashCollectionApplies
                ? (float)$order['items_total'] + (float)($order['shipping_fee'] ?? 0)
                : 0;
            ?>
            <article class="order available-card">
                <div class="order-head">
                    <div class="order-id"><?php echo htmlspecialchars(formatOrderNumber($conn, (int)$order['id']), ENT_QUOTES, 'UTF-8'); ?><span class="order-date"><?php echo htmlspecialchars(date('M d, Y, g:i A', strtotime($order['order_date']))); ?></span></div>
                    <span class="status">Waiting for rider</span>
                </div>
                <div class="available-customer">
                    <span class="available-customer-label">Customer</span>
                    <strong><?php echo htmlspecialchars($order['fullname'] ?? 'Customer'); ?></strong>
                    <span><?php echo htmlspecialchars($order['contact_number'] ?? 'Not provided'); ?></span>
                    <span><?php echo htmlspecialchars($order['address'] ?? 'Not provided'); ?></span>
                </div>
                <div class="available-payment"><span class="available-payment-label">Payment status</span><strong><?php echo htmlspecialchars(strtoupper($order['payment_status'] ?? 'UNPAID')); ?> · <?php echo htmlspecialchars(strtoupper($order['payment_method'] ?? 'COD')); ?></strong>                </div>
                <div class="available-costs">
                    <?php if (!$onlinePaymentSettled): ?>
                        <div class="available-cost-row"><span>Items total</span><strong>PHP <?php echo number_format((float)$order['items_total'], 2); ?></strong></div>
                    <?php endif; ?>
                    <div class="available-cost-row"><span>Shipping fee</span><strong>PHP <?php echo number_format((float)($order['shipping_fee'] ?? 0), 2); ?></strong></div>
                </div>
                <div class="available-collection"><span>Amount to collect</span><strong>PHP <?php echo number_format($availableAmountToCollect, 2); ?></strong></div>
                <p class="available-collection-note"><?php echo $cashCollectionApplies ? 'Collect cash from the customer on delivery.' : 'Paid online · No cash collection required.'; ?></p>
                <div class="contact-actions">
                    <?php if (!empty($order['contact_number'])): ?><a href="tel:<?php echo htmlspecialchars($order['contact_number']); ?>">Call Customer</a><?php endif; ?>
                    <a target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&origin=<?php echo $riderMapOrigin; ?>&destination=<?php echo rider_map_destination($order); ?>">Navigate</a>
                </div>
                <form method="post" class="form-actions">
                    <input type="hidden" name="action" value="claim_delivery">
                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                    <button class="accept" type="submit">Accept Delivery</button>
                </form>
            </article>
            <?php endwhile; ?>
        <?php else: ?>
            <article class="order">No unassigned deliveries available.</article>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($view === 'active'): ?>
    <section class="orders active-orders" id="active-deliveries">
        <h2 class="section-title">Active Deliveries</h2>
        <?php if ($activeOrders && $activeOrders->num_rows): ?>
            <?php while ($order = $activeOrders->fetch_assoc()): ?>
            <?php
            $deliveryStatus = $order['delivery_status'] ?: 'in_transit';
            $activePaymentMethod = strtolower(trim((string)($order['payment_method'] ?? 'cod')));
            $activeCashCollectionApplies = in_array($activePaymentMethod, ['cod', 'cash'], true);
            $activeOnlinePaymentSettled = in_array($activePaymentMethod, ['gcash', 'bank', 'bpi'], true)
                && strtolower(trim((string)($order['payment_status'] ?? ''))) === 'paid';
            $activeAmountToCollect = $activeCashCollectionApplies
                ? (float)$order['items_total'] + (float)($order['shipping_fee'] ?? 0)
                : 0;
            ?>
            <article class="order active-card">
                <div class="order-head">
                    <div class="order-id"><?php echo htmlspecialchars(formatOrderNumber($conn, (int)$order['id']), ENT_QUOTES, 'UTF-8'); ?><span class="order-date"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($order['order_date']))); ?></span></div>
                    <span class="status <?php echo htmlspecialchars($deliveryStatus); ?>"><?php echo htmlspecialchars(rider_label($deliveryStatus)); ?></span>
                </div>
                <div class="available-customer">
                    <span class="available-customer-label">Customer</span>
                    <strong><?php echo htmlspecialchars($order['fullname'] ?? 'Customer'); ?></strong>
                    <span><?php echo htmlspecialchars($order['contact_number'] ?? 'Not provided'); ?></span>
                    <span><?php echo htmlspecialchars($order['address'] ?? 'Not provided'); ?></span>
                </div>
                <div class="available-payment"><span class="available-payment-label">Payment status</span><strong><?php echo htmlspecialchars(strtoupper($order['payment_status'] ?? 'UNPAID')); ?> · <?php echo htmlspecialchars(strtoupper($order['payment_method'] ?? 'COD')); ?></strong></div>
                <div class="available-costs">
                    <?php if (!$activeOnlinePaymentSettled): ?>
                        <div class="available-cost-row"><span>Items total</span><strong>PHP <?php echo number_format((float)$order['items_total'], 2); ?></strong></div>
                    <?php endif; ?>
                    <div class="available-cost-row"><span>Shipping fee</span><strong>PHP <?php echo number_format((float)($order['shipping_fee'] ?? 0), 2); ?></strong></div>
                </div>
                <div class="available-collection"><span>Amount to collect</span><strong>PHP <?php echo number_format($activeAmountToCollect, 2); ?></strong></div>
                <p class="available-collection-note"><?php echo $activeCashCollectionApplies ? 'Collect cash from the customer on delivery.' : 'Paid online · No cash collection required.'; ?></p>
                <div class="contact-actions">
                    <?php if (!empty($order['contact_number'])): ?><a href="tel:<?php echo htmlspecialchars($order['contact_number']); ?>">Call Customer</a><?php endif; ?>
                    <a target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&origin=<?php echo $riderMapOrigin; ?>&destination=<?php echo rider_map_destination($order); ?>">Navigate</a>
                </div>
                <?php $canRiderCancel = in_array(strtolower(trim((string)($order['delivery_type'] ?? ''))), ['lalamove', 'delivery'], true); ?>
                <form method="post" enctype="multipart/form-data" class="proof-form delivery-action-form">
                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                    <input type="hidden" name="delivery_status" value="failed">
                    <input type="hidden" name="delivery_action" value="cancel_order">
                    <div class="cancel-reason-panel" hidden>
                        <label>Reason
                            <select name="delivery_failure_reason" class="cancel-reason" disabled required>
                                <option value="">Select reason</option>
                                <option value="customer_unavailable">Customer unavailable</option>
                                <option value="customer_refused">Customer refused</option>
                                <option value="wrong_address">Wrong address</option>
                                <option value="other">Other</option>
                            </select>
                        </label>
                    </div>
                    <?php if (empty($order['delivery_proof_photo'])): ?>
                        <label class="proof-photo-label">Proof photo<input type="file" name="proof_photo" accept="image/jpeg,image/png"></label>
                        <small class="proof-photo-hint">Proof photo is required to complete delivery and optional when cancelling.</small>
                    <?php else: ?>
                        <p>Proof photo is saved. Complete the delivery when it has been handed to the customer.</p>
                    <?php endif; ?>
                    <div class="delivery-actions delivery-main-actions">
                        <button class="save complete-delivery-button" type="submit" name="action" value="submit_proof">Complete Delivery</button>
                        <?php if ($canRiderCancel): ?>
                            <button class="cancel-toggle" type="button" aria-expanded="false">Cancel</button>
                        <?php endif; ?>
                        <button class="cancel-submit cancel-confirm" type="submit" name="action" value="update_delivery">Confirm Cancellation</button>
                        <button class="cancel-back" type="button">Back</button>
                    </div>
                </form>
            </article>
            <?php endwhile; ?>
        <?php else: ?>
            <article class="order">No deliveries are assigned to you.</article>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($view === 'history'): ?>
    <section class="orders history" id="delivery-history">
        <h2 class="section-title">Delivery History</h2>
        <?php if ($historyOrders && $historyOrders->num_rows): ?>
            <?php while ($order = $historyOrders->fetch_assoc()): ?>
            <?php
            $historyPaymentMethod = strtolower(trim((string)($order['payment_method'] ?? 'cod')));
            $historyCashCollectionApplies = in_array($historyPaymentMethod, ['cod', 'cash'], true);
            $historyOnlinePaymentSettled = in_array($historyPaymentMethod, ['gcash', 'bank', 'bpi'], true)
                && strtolower(trim((string)($order['payment_status'] ?? ''))) === 'paid';
            $historyAmountCollected = $historyCashCollectionApplies
                ? (float)$order['items_total'] + (float)($order['shipping_fee'] ?? 0)
                : 0;
            $historyDeliveredTimestamp = strtotime((string)($order['delivered_at'] ?? ''));
            ?>
            <article class="order history-card">
                <div class="order-head">
                    <div class="order-id"><?php echo htmlspecialchars(formatOrderNumber($conn, (int)$order['id']), ENT_QUOTES, 'UTF-8'); ?><span class="order-date"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($order['order_date']))); ?></span><span class="order-date delivered-date">Delivered: <?php echo $historyDeliveredTimestamp !== false ? htmlspecialchars(date('M d, Y h:i A', $historyDeliveredTimestamp)) : 'Not recorded'; ?></span></div>
                    <span class="status delivered">Delivered</span>
                </div>
                <div class="available-customer">
                    <span class="available-customer-label">Customer</span>
                    <strong><?php echo htmlspecialchars($order['fullname'] ?? 'Customer'); ?></strong>
                    <span><?php echo htmlspecialchars($order['contact_number'] ?? 'Not provided'); ?></span>
                    <span><?php echo htmlspecialchars($order['address'] ?? 'Not provided'); ?></span>
                </div>
                <div class="available-payment"><span class="available-payment-label">Payment status</span><strong><?php echo htmlspecialchars(strtoupper($order['payment_status'] ?? 'UNPAID')); ?> · <?php echo htmlspecialchars(strtoupper($order['payment_method'] ?? 'COD')); ?></strong></div>
                <div class="available-costs">
                    <?php if (!$historyOnlinePaymentSettled): ?>
                        <div class="available-cost-row"><span>Items total</span><strong>PHP <?php echo number_format((float)$order['items_total'], 2); ?></strong></div>
                    <?php endif; ?>
                    <div class="available-cost-row"><span>Shipping fee</span><strong>PHP <?php echo number_format((float)($order['shipping_fee'] ?? 0), 2); ?></strong></div>
                </div>
                <div class="available-collection"><span>Amount collected</span><strong>PHP <?php echo number_format($historyAmountCollected, 2); ?></strong></div>
                <p class="available-collection-note"><?php echo $historyCashCollectionApplies ? 'Cash collected on delivery.' : 'Paid online · No cash collected by rider.'; ?></p>
            </article>
            <?php endwhile; ?>
        <?php else: ?>
            <article class="order">No delivered orders in your history yet.</article>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>
<script>
(function () {
    document.querySelectorAll('.delivery-action-form').forEach(function (form) {
        var cancelToggle = form.querySelector('.cancel-toggle');
        var reasonPanel = form.querySelector('.cancel-reason-panel');
        var cancelBack = form.querySelector('.cancel-back');
        var reasonSelect = form.querySelector('.cancel-reason');
        var cancelConfirm = form.querySelector('.cancel-confirm');
        if (!cancelToggle || !reasonPanel || !cancelBack || !reasonSelect || !cancelConfirm) return;

        cancelToggle.addEventListener('click', function () {
            reasonPanel.hidden = false;
            form.classList.add('is-cancelling');
            cancelToggle.setAttribute('aria-expanded', 'true');
            reasonSelect.disabled = false;
            reasonSelect.focus();
        });
        cancelBack.addEventListener('click', function () {
            reasonSelect.value = '';
            reasonSelect.disabled = true;
            reasonPanel.hidden = true;
            form.classList.remove('is-cancelling');
            cancelToggle.setAttribute('aria-expanded', 'false');
        });
        if (cancelConfirm) {
            cancelConfirm.addEventListener('click', function (event) {
                if (reasonSelect.value === '') {
                    event.preventDefault();
                    reasonSelect.focus();
                    window.alert('Select a cancellation reason first.');
                }
            });
        }
        form.addEventListener('submit', function (event) {
            if (event.submitter && event.submitter.value === 'update_delivery'
                && !window.confirm('Cancel this delivery and notify the customer?')) {
                event.preventDefault();
            }
        });
    });

    var riderIsAvailable = <?= json_encode(!empty($riderProfile['is_available'])) ?>;

    function sendRiderHeartbeat() {
        if (!riderIsAvailable || document.visibilityState === 'hidden') return;
        fetch('<?php echo htmlspecialchars($deliveryPage, ENT_QUOTES, 'UTF-8'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: new URLSearchParams({action: 'heartbeat'}),
            credentials: 'same-origin',
            keepalive: true
        }).catch(function (error) {
            console.error('Unable to refresh rider availability.', error);
        });
    }

    sendRiderHeartbeat();
    window.setInterval(sendRiderHeartbeat, 30000);
    document.addEventListener('visibilitychange', sendRiderHeartbeat);

    function showLocationError(error) {
        if (error && error.code === 1) {
            console.warn('Rider location access is blocked.');
        } else if (error && error.code === 3) {
            console.warn('Rider location request timed out.');
        } else {
            console.warn('Rider location is unavailable.');
        }
    }

    if (!navigator.geolocation) {
        console.warn('Rider location requires HTTPS or localhost.');
        return;
    }

    function updateRiderLocation(position) {
        var body = new URLSearchParams({
            action: 'update_location',
            latitude: String(position.coords.latitude),
            longitude: String(position.coords.longitude)
        });
        fetch('<?php echo htmlspecialchars($deliveryPage, ENT_QUOTES, 'UTF-8'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body,
            credentials: 'same-origin',
            keepalive: true
        }).then(function (response) {
            if (!response.ok) throw new Error('Location update failed');
        }).catch(function (error) {
            console.error('GPS is available, but the server could not save the rider location.', error);
        });
    }

    navigator.geolocation.getCurrentPosition(updateRiderLocation, showLocationError, {
        enableHighAccuracy: true,
        maximumAge: 60000,
        timeout: 10000
    });
    navigator.geolocation.watchPosition(updateRiderLocation, showLocationError, {
        enableHighAccuracy: true,
        maximumAge: 60000,
        timeout: 15000
    });
})();
</script>
</body>
</html>
