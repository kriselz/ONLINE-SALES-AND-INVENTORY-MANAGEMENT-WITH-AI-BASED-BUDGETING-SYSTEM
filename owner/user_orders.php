<?php
session_start();
include 'config.php';
date_default_timezone_set('Asia/Manila');
include 'access_control.php';
require_once 'functions.php';
require_once __DIR__ . '/customer_voucher_helpers.php';
requireOwnerPageAccess($conn, true);

$currentPage = basename($_SERVER['PHP_SELF']);
$search = trim($_GET['search'] ?? '');
$segment = $_GET['segment'] ?? 'all';
$allowedSegments = ['all', 'active', 'inactive', 'not_eligible', 'eligible', 'voucher_sent', 'voucher_used', 'expired'];
if (!in_array($segment, $allowedSegments, true)) {
    $segment = 'all';
}
$voucherMessage = $_SESSION['loyalty_voucher_message'] ?? '';
unset($_SESSION['loyalty_voucher_message']);
$getVoucherDiscountPercent = static function (float $totalSpent): int {
    if ($totalSpent >= 10000) {
        return 50;
    }
    if ($totalSpent >= 5000) {
        return 40;
    }
    if ($totalSpent >= 4000) {
        return 30;
    }
    if ($totalSpent >= 3000) {
        return 20;
    }
    if ($totalSpent >= 2000) {
        return 15;
    }
    return $totalSpent >= 1000 ? 10 : 0;
};
$currentVoucherMonth = date('Y-m-01');
if (empty($_SESSION['customer_csrf_token'])) {
    $_SESSION['customer_csrf_token'] = bin2hex(random_bytes(32));
}
$customerCsrfToken = $_SESSION['customer_csrf_token'];

$voucherSchemaReady = ensureCustomerLoyaltyVoucherSchema($conn);
if ($voucherSchemaReady) {
    $voucherExpiryBackfill = $conn->query("UPDATE coupons c
        JOIN customer_loyalty_vouchers clv ON clv.coupon_id = c.id
        SET c.expiry_date = DATE_ADD(clv.sent_at, INTERVAL 7 DAY)
        WHERE clv.status = 'sent' AND clv.sent_at IS NOT NULL AND c.expiry_date IS NULL");
    if (!$voucherExpiryBackfill) {
        error_log('Loyalty voucher expiry backfill failed: ' . $conn->error);
        $voucherSchemaReady = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_customer') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $customerName = trim($_POST['username'] ?? '');
    $customerEmail = trim($_POST['email'] ?? '');
    $customerPhone = trim($_POST['phone'] ?? '');
    if (hash_equals($customerCsrfToken, $_POST['csrf_token'] ?? '') && $customerId > 0 && $customerName !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $updateCustomer = $conn->prepare("UPDATE users SET username = ?, email = ?, phone = ? WHERE id = ? AND role = 'user'");
        if ($updateCustomer) {
            $updateCustomer->bind_param('sssi', $customerName, $customerEmail, $customerPhone, $customerId);
            $updateCustomer->execute();
            $updateCustomer->close();
        }
    }
    header('Location: ' . customerQuery(1, $search, $segment));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_loyalty_voucher') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $redirect = customerQuery(max(1, (int)($_POST['page'] ?? 1)), trim($_POST['search'] ?? ''), $_POST['segment'] ?? 'all');
    $setVoucherMessage = function (string $message) use ($redirect) {
        $_SESSION['loyalty_voucher_message'] = $message;
        header('Location: ' . $redirect);
        exit();
    };

    if (!$voucherSchemaReady || !hash_equals($customerCsrfToken, $_POST['csrf_token'] ?? '') || $customerId < 1) {
        $setVoucherMessage('Unable to process the voucher request. Please refresh the page and try again.');
    }

    $customerQuery = $conn->prepare("SELECT u.username, u.email,
            COALESCE(SUM(CASE WHEN o.order_date >= ? AND o.order_date < DATE_ADD(?, INTERVAL 1 MONTH)
                THEN o.total_amount ELSE 0 END), 0) AS total_spent
        FROM users u LEFT JOIN orders o ON o.user_id = u.id
        WHERE u.id = ? AND u.role = 'user' GROUP BY u.id");
    if (!$customerQuery) {
        error_log('Loyalty voucher customer lookup failed: ' . $conn->error);
        $setVoucherMessage('Could not load the customer account.');
    }
    $customerQuery->bind_param('ssi', $currentVoucherMonth, $currentVoucherMonth, $customerId);
    $customerQuery->execute();
    $voucherCustomer = $customerQuery->get_result()->fetch_assoc();
    $customerQuery->close();
    if (!$voucherCustomer) {
        $setVoucherMessage('Customer account was not found.');
    }

    $totalSpent = (float)$voucherCustomer['total_spent'];
    $discount = $getVoucherDiscountPercent($totalSpent);
    if ($discount <= 0) {
        $setVoucherMessage('This customer has not reached the ₱1,000 minimum for a voucher.');
    }
    if (!filter_var($voucherCustomer['email'], FILTER_VALIDATE_EMAIL)) {
        $setVoucherMessage('The customer does not have a valid email address. Update it before sending a voucher.');
    }

    $existingQuery = $conn->prepare("SELECT clv.status, clv.discount_value, clv.coupon_id, c.code, c.discount_type, c.discount_value AS coupon_discount_value
        FROM customer_loyalty_vouchers clv JOIN coupons c ON c.id = clv.coupon_id
        WHERE clv.user_id = ? AND clv.qualifying_month = ? LIMIT 1");
    if (!$existingQuery) {
        error_log('Loyalty voucher lookup failed: ' . $conn->error);
        $setVoucherMessage('Could not check the customer’s existing voucher.');
    }
    $existingQuery->bind_param('is', $customerId, $currentVoucherMonth);
    $existingQuery->execute();
    $existingVoucher = $existingQuery->get_result()->fetch_assoc();
    $existingQuery->close();
    if ($existingVoucher && in_array($existingVoucher['status'], ['sent', 'used'], true)) {
        $setVoucherMessage('This customer already has a voucher that was sent or used.');
    }

    if (!$existingVoucher) {
        try {
            $voucherCode = 'LOYALTY' . strtoupper(bin2hex(random_bytes(5)));
        } catch (Throwable $exception) {
            error_log('Loyalty voucher code generation failed: ' . $exception->getMessage());
            $setVoucherMessage('Could not generate a voucher code. Please try again.');
        }

        $conn->begin_transaction();
        $couponInsert = $conn->prepare("INSERT INTO coupons (code, discount_type, discount_value, min_order, usage_limit, used_count, expiry_date, created_by)
            VALUES (?, 'percent', ?, 0, 0, 0, NULL, ?)");
        if (!$couponInsert) {
            $conn->rollback();
            error_log('Loyalty coupon creation failed: ' . $conn->error);
            $setVoucherMessage('Could not create the voucher. Please try again.');
        }
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $couponInsert->bind_param('sdi', $voucherCode, $discount, $createdBy);
        if (!$couponInsert->execute()) {
            $couponInsert->close();
            $conn->rollback();
            error_log('Loyalty coupon insert failed: ' . $conn->error);
            $setVoucherMessage('Could not create the voucher. Please try again.');
        }
        $couponId = $conn->insert_id;
        $couponInsert->close();

        $voucherInsert = $conn->prepare("INSERT INTO customer_loyalty_vouchers (user_id, coupon_id, qualifying_month, qualifying_spend, discount_value, status, created_by)
            VALUES (?, ?, ?, ?, ?, 'pending', ?)");
        if (!$voucherInsert) {
            $conn->rollback();
            error_log('Loyalty voucher assignment failed: ' . $conn->error);
            $setVoucherMessage('Could not assign the voucher. Please try again.');
        }
        $voucherInsert->bind_param('iisddi', $customerId, $couponId, $currentVoucherMonth, $totalSpent, $discount, $createdBy);
        if (!$voucherInsert->execute()) {
            $voucherInsert->close();
            $conn->rollback();
            error_log('Loyalty voucher assignment failed: ' . $conn->error);
            $setVoucherMessage('Could not assign the voucher. Please try again.');
        }
        $voucherInsert->close();
        $conn->commit();
        $existingVoucher = ['status' => 'pending', 'discount_value' => $discount, 'code' => $voucherCode];
    } elseif ($existingVoucher['status'] === 'pending' && (
        $existingVoucher['discount_type'] !== 'percent' ||
        (float)$existingVoucher['coupon_discount_value'] !== (float)$discount
    )) {
        $refreshVoucher = $conn->prepare("UPDATE customer_loyalty_vouchers clv
            JOIN coupons c ON c.id = clv.coupon_id
            SET clv.qualifying_spend = ?, clv.discount_value = ?,
                c.discount_type = 'percent', c.discount_value = ?, c.expiry_date = NULL
            WHERE clv.user_id = ? AND clv.qualifying_month = ? AND clv.status = 'pending'");
        if (!$refreshVoucher) {
            error_log('Pending loyalty voucher refresh failed: ' . $conn->error);
            $setVoucherMessage('Could not update the pending voucher. Please try again.');
        }
        $refreshVoucher->bind_param('dddis', $totalSpent, $discount, $discount, $customerId, $currentVoucherMonth);
        if (!$refreshVoucher->execute()) {
            $refreshVoucher->close();
            error_log('Pending loyalty voucher refresh failed: ' . $conn->error);
            $setVoucherMessage('Could not update the pending voucher. Please try again.');
        }
        $refreshVoucher->close();
        $existingVoucher['discount_value'] = $discount;
    }

    $issuedAt = new DateTimeImmutable();
    $expiresAt = $issuedAt->modify('+7 days');
    $issuedAtSql = $issuedAt->format('Y-m-d H:i:s');
    $expiresAtSql = $expiresAt->format('Y-m-d H:i:s');
    $emailResult = sendCustomerLoyaltyVoucherEmail(
        $voucherCustomer['email'],
        $voucherCustomer['username'] ?: 'Customer',
        $existingVoucher['code'],
        (float)$existingVoucher['discount_value'],
        $issuedAt->format('F j, Y'),
        $expiresAt->format('F j, Y')
    );
    if (empty($emailResult['success'])) {
        $setVoucherMessage($emailResult['error'] ?? 'The voucher email could not be sent. The customer remains eligible; retry from this page.');
    }

    $sentUpdate = $conn->prepare("UPDATE customer_loyalty_vouchers clv
        JOIN coupons c ON c.id = clv.coupon_id
        SET clv.status = 'sent', clv.sent_at = ?, c.expiry_date = ?
        WHERE clv.user_id = ? AND clv.qualifying_month = ? AND clv.status = 'pending'");
    if (!$sentUpdate) {
        error_log('Loyalty voucher sent status update failed: ' . $conn->error);
        $setVoucherMessage('The email was sent, but the voucher status could not be updated. Please contact support.');
    }
    $sentUpdate->bind_param('ssis', $issuedAtSql, $expiresAtSql, $customerId, $currentVoucherMonth);
    if (!$sentUpdate->execute()) {
        $sentUpdate->close();
        error_log('Loyalty voucher sent status update failed: ' . $conn->error);
        $setVoucherMessage('The email was sent, but the voucher status could not be updated. Please contact support.');
    }
    if ($sentUpdate->affected_rows < 1) {
        $sentUpdate->close();
        error_log('Loyalty voucher status update did not affect exactly one pending voucher for customer #' . $customerId);
        $setVoucherMessage('The email was sent, but the voucher status could not be updated. Please contact support.');
    }
    $sentUpdate->close();
    $setVoucherMessage('Voucher emailed successfully to ' . $voucherCustomer['email'] . '.');
}

$page = max(1, (int)($_GET['page'] ?? 1)); 
$perPage = 10;
$searchSql = $conn->real_escape_string($search);
$where = "u.role = 'user'";
if ($search !== '') {
    $where .= " AND (u.username LIKE '%$searchSql%' OR u.email LIKE '%$searchSql%' OR u.phone LIKE '%$searchSql%')";
}
$customerTotalSpentSql = "COALESCE((SELECT SUM(voucher_orders.total_amount) FROM orders voucher_orders
    WHERE voucher_orders.user_id = u.id
      AND voucher_orders.order_date >= '$currentVoucherMonth'
      AND voucher_orders.order_date < DATE_ADD('$currentVoucherMonth', INTERVAL 1 MONTH)), 0)";
if ($segment === 'active') {
    $where .= " AND EXISTS (SELECT 1 FROM orders active_orders WHERE active_orders.user_id = u.id AND active_orders.order_date >= DATE_SUB(NOW(), INTERVAL 90 DAY))";
} elseif ($segment === 'inactive') {
    $where .= " AND NOT EXISTS (SELECT 1 FROM orders inactive_orders WHERE inactive_orders.user_id = u.id AND inactive_orders.order_date >= DATE_SUB(NOW(), INTERVAL 90 DAY))";
} elseif ($segment === 'not_eligible') {
    $where .= " AND $customerTotalSpentSql < 1000";
} elseif ($segment === 'eligible') {
    $where .= " AND $customerTotalSpentSql >= 1000";
    if ($voucherSchemaReady) {
        $where .= " AND NOT EXISTS (SELECT 1 FROM customer_loyalty_vouchers filter_voucher
            WHERE filter_voucher.user_id = u.id AND filter_voucher.qualifying_month = '$currentVoucherMonth')";
    }
} elseif (in_array($segment, ['voucher_sent', 'voucher_used', 'expired'], true)) {
    if ($voucherSchemaReady) {
        $voucherStatusCondition = $segment === 'voucher_sent'
            ? "filter_voucher.status = 'sent' AND filter_coupon.expiry_date > NOW()"
            : ($segment === 'voucher_used'
                ? "filter_voucher.status = 'used'"
                : "filter_voucher.status = 'sent' AND filter_coupon.expiry_date <= NOW()");
        $where .= " AND EXISTS (SELECT 1 FROM customer_loyalty_vouchers filter_voucher
            JOIN coupons filter_coupon ON filter_coupon.id = filter_voucher.coupon_id
            WHERE filter_voucher.user_id = u.id AND $voucherStatusCondition)";
    } else {
        $where .= " AND 1 = 0";
    }
}

$countResult = $conn->query("SELECT COUNT(*) AS total FROM users u WHERE $where");
$totalCustomers = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
$totalPages = max(1, (int)ceil($totalCustomers / $perPage));
$page = min($page, $totalPages); 
$offset = ($page - 1) * $perPage;

$newResult = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='user' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$newCustomers = $newResult ? (int)$newResult->fetch_assoc()['total'] : 0;
$returningResult = $conn->query("SELECT COUNT(*) AS total FROM (SELECT user_id FROM orders GROUP BY user_id HAVING COUNT(*) > 1) returning_users JOIN users u ON u.id = returning_users.user_id WHERE u.role='user'");
$returningCustomers = $returningResult ? (int)$returningResult->fetch_assoc()['total'] : 0;
$activeResult = $conn->query("SELECT COUNT(DISTINCT o.user_id) AS total FROM orders o JOIN users active_users ON active_users.id = o.user_id WHERE active_users.role='user' AND o.order_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)");
$activeCustomers = $activeResult ? (int)$activeResult->fetch_assoc()['total'] : 0;
$inactiveCustomers = max(0, $totalCustomers - $activeCustomers);

$conn->query("CREATE TABLE IF NOT EXISTS loyalty_accounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    points_balance INT NOT NULL DEFAULT 0,
    lifetime_points INT NOT NULL DEFAULT 0,
    tier VARCHAR(20) NOT NULL DEFAULT 'Bronze',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$customerVoucherFields = $voucherSchemaReady
    ? "CASE
            WHEN clv.status = 'used' THEN 'used'
            WHEN clv.status = 'sent' AND c.expiry_date IS NOT NULL AND c.expiry_date <= NOW() THEN 'expired'
            ELSE clv.status
        END AS voucher_status,
        clv.discount_value AS assigned_voucher_discount,
        c.discount_type AS voucher_discount_type,
        c.code AS voucher_code,
        clv.sent_at AS voucher_issued_at,
        c.expiry_date AS voucher_expires_at"
    : "NULL AS voucher_status, NULL AS assigned_voucher_discount, NULL AS voucher_discount_type,
        NULL AS voucher_code, NULL AS voucher_issued_at, NULL AS voucher_expires_at";
$customerVoucherJoins = $voucherSchemaReady
    ? "LEFT JOIN customer_loyalty_vouchers clv ON clv.user_id = u.id AND clv.qualifying_month = '$currentVoucherMonth'
        LEFT JOIN coupons c ON c.id = clv.coupon_id"
    : '';
$customers = $conn->query("SELECT u.id, u.username, u.email, u.phone, u.created_at,
    COALESCE(order_totals.total_orders, 0) AS total_orders,
    $customerTotalSpentSql AS total_spent,
    order_totals.last_order,
    $customerVoucherFields
    FROM users u
    LEFT JOIN (SELECT user_id, COUNT(*) AS total_orders, SUM(total_amount) AS total_spent, MAX(order_date) AS last_order FROM orders GROUP BY user_id) order_totals ON order_totals.user_id = u.id
    $customerVoucherJoins
    WHERE $where ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset");

function customerQuery($page, $search, $segment) {
    return '?page=' . (int)$page . '&search=' . urlencode($search) . '&segment=' . urlencode($segment);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customers | Admin</title>
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-2">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined">
<style>
* { box-sizing:border-box; }
body { margin:0; font-family:'Segoe UI',Tahoma,sans-serif; color:#20232a; background:#f7f8fa; }
.content { margin-left:244px; padding:26px 28px 34px; min-height:100vh; }
.topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
.heading h1 { margin:0 0 4px; font-size:22px; font-weight:700; }.heading p { margin:0; color:#7b8189; font-size:11px; }
.customer-tabs { display:flex; flex-wrap:nowrap; gap:6px; width:max-content; max-width:100%; overflow-x:auto; scrollbar-width:none; margin:0 0 18px; padding:5px; background:#fff; border:1px solid #ebedf0; border-radius:9px; box-shadow:0 2px 8px rgba(25,30,38,.04); }
.customer-tabs::-webkit-scrollbar { display:none; }
.customer-tab { display:inline-flex; flex:0 0 auto; align-items:center; justify-content:center; min-height:34px; padding:6px 13px; border-bottom:3px solid transparent; border-radius:0; color:#68707a; font-size:11px; font-weight:700; line-height:1.25; text-align:center; text-decoration:none; white-space:nowrap; }
.customer-tab:hover { background:#fff1f3; color:#ed1740; }
.customer-tab.active { border-bottom-color:#ed1740; background:#fff; color:#a51c38; }
.top-actions { display:none; }
.metrics { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:26px; }.metric { display:flex; align-items:center; gap:12px; min-height:88px; padding:14px 13px; background:#fff; border:1px solid #eff0f2; border-radius:9px; box-shadow:0 2px 9px rgba(25,30,38,.04); }.metric-icon { width:43px; height:43px; border-radius:50%; display:grid; place-items:center; font-size:22px; flex:0 0 auto; }.pink { background:#ffe8ed;color:#df274b }.green { background:#e8f7ef;color:#15955c }.blue { background:#e8f2ff;color:#1976d2 }.yellow { background:#fff5de;color:#e9a316 }.purple { background:#f1e8ff;color:#7a38c5 }.metric-label { color:#6e747c; font-size:10px; }.metric-value { margin-top:5px; font-size:17px; font-weight:700; }.metric-note { margin-top:3px; color:#51a47b; font-size:9px; }.metric-note.down { color:#ef5367; }
.panel { background:#fff; border:1px solid #ebedf0; border-radius:8px; box-shadow:0 2px 10px rgba(25,30,38,.04); overflow:hidden; }.toolbar { display:flex; gap:14px; padding:16px 10px 10px; }.filter-form { display:flex; gap:12px; width:100%; }.input-wrap { position:relative; flex:1; }.input-wrap input, select { width:100%; height:29px; padding:0 12px; border:1px solid #e5e7eb; border-radius:6px; color:#656b73; background:#fff; font-size:10px; }.input-wrap input { padding-right:34px; }.input-wrap button { position:absolute; top:50%; right:9px; display:flex; align-items:center; justify-content:center; width:20px; height:20px; padding:0; border:0; background:transparent; color:#8a9199; transform:translateY(-50%); cursor:pointer; font-size:14px; line-height:1; }.input-wrap button:hover { color:#ed1740; }.filter-button { height:29px; padding:0 14px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; color:#424951; font-size:10px; }.segment { width:160px; }.export,.add { border:0; border-radius:6px; padding:0 14px; font-size:10px; font-weight:600; }.export { background:#fff0f2;color:#e32248 }.add { background:#ed1740;color:#fff; }
.table-scroll { overflow-x:auto; } table { width:100%; border-collapse:collapse; min-width:850px; } th,td { padding:10px 8px; text-align:left; border-top:1px solid #f0f1f3; white-space:nowrap; font-size:10px; font-weight:600; } th { color:#68707a; font-size:9px; font-weight:700; } th:last-child,td:last-child { width:105px; }.customer { display:flex; align-items:center; gap:9px; }.avatar { width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:#f7dfe4;color:#a51c38;font-weight:700;font-size:9px; }.customer strong { display:block;font-size:10px;font-weight:700; }.customer small { color:#8b9198;font-size:8px; }.status { padding:5px 9px;border-radius:5px;background:#e5f7ed;color:#278658;font-size:9px;font-weight:700; }.status.inactive { background:#f0f1f4;color:#737985; }.actions { display:flex; gap:5px; position:relative; }.action { width:27px;height:25px;border:1px solid #eceef1;border-radius:5px;background:#fff;color:#606771;cursor:pointer; }.action:hover { border-color:#ed1740;color:#ed1740; }.action-menu { display:none; position:absolute; right:0; top:29px; z-index:10; min-width:120px; padding:5px; background:#fff; border:1px solid #eceef1; border-radius:6px; box-shadow:0 5px 14px rgba(25,30,38,.12); }.action-menu.open { display:block; }.action-menu a,.action-menu button { display:block; width:100%; padding:7px 8px; border:0; background:transparent; color:#424951; text-align:left; text-decoration:none; font:inherit; font-size:9px; cursor:pointer; }.action-menu a:hover,.action-menu button:hover { background:#fff0f2;color:#e32248; }.footer { display:flex;justify-content:space-between;align-items:center;padding:12px;font-size:9px;color:#727984; }.pagination { display:flex;gap:5px; }.pagination a,.pagination span { width:28px;height:25px;display:grid;place-items:center;border:1px solid #eceef1;border-radius:5px;color:#424951;text-decoration:none; }.pagination .current { background:#fff0f2;color:#e32248;border-color:#ffdbe2; }
.voucher-status { display:inline-flex; align-items:center; padding:5px 9px; border-radius:5px; font-size:9px; font-weight:700; }
.voucher-not-eligible { color:#667085; background:#f0f1f4; }
.voucher-eligible { color:#946200; background:#fff4d6; }
.voucher-sent { color:#18864b; background:#e5f7ed; }
.voucher-used { color:#1769aa; background:#e6f2ff; }
.voucher-expired { color:#b42318; background:#fee4e2; }
.voucher-code { display:block; margin-top:4px; color:#7b8189; font-size:9px; }
.voucher-dates { display:block; margin-top:4px; color:#7b8189; font-size:9px; line-height:1.5; }
.voucher-action { width:29px; height:27px; border:1px solid #e5e7eb; border-radius:5px; color:#4d5661; background:#fff; cursor:pointer; }
.voucher-action:hover { border-color:#ed1740; color:#ed1740; }
.voucher-action:disabled { opacity:.4; cursor:not-allowed; }
.voucher-alert { margin:0 0 14px; padding:10px 13px; border-radius:6px; font-size:11px; }
.voucher-alert.success { color:#176b43; background:#e8f7ef; }
.voucher-alert.error { color:#8e2335; background:#fff0f2; }
.panel.customers-panel .table-scroll table.customers-table { width:100%; min-width:0; margin:0; table-layout:fixed; }
.panel.customers-panel .table-scroll table.customers-table th:nth-child(1), .panel.customers-panel .table-scroll table.customers-table td:nth-child(1) { width:18%; }
.panel.customers-panel .table-scroll table.customers-table th:nth-child(2), .panel.customers-panel .table-scroll table.customers-table td:nth-child(2) { width:23%; }
.panel.customers-panel .table-scroll table.customers-table th:nth-child(3), .panel.customers-panel .table-scroll table.customers-table td:nth-child(3) { width:15%; }
.panel.customers-panel .table-scroll table.customers-table th:nth-child(4), .panel.customers-panel .table-scroll table.customers-table td:nth-child(4) { width:15%; }
.panel.customers-panel .table-scroll table.customers-table th:nth-child(5), .panel.customers-panel .table-scroll table.customers-table td:nth-child(5) { width:19%; }
.panel.customers-panel .table-scroll table.customers-table th:nth-child(6), .panel.customers-panel .table-scroll table.customers-table td:nth-child(6) { width:10%; }
.panel.customers-panel .table-scroll table.customers-table td:nth-child(1),
.panel.customers-panel .table-scroll table.customers-table td:nth-child(2),
.panel.customers-panel .table-scroll table.customers-table td:nth-child(3),
.panel.customers-panel .table-scroll table.customers-table td:nth-child(5) { white-space:normal; overflow-wrap:anywhere; }
.panel.customers-panel .table-scroll table.customers-table td,
.panel.customers-panel .table-scroll table.customers-table th { white-space:normal; overflow-wrap:anywhere; }
.panel.customers-panel .table-scroll table.customers-table .voucher-code,
.panel.customers-panel .table-scroll table.customers-table .voucher-dates { white-space:normal; overflow-wrap:anywhere; }
.customer-modal { display:none; position:fixed; inset:0; z-index:1000; align-items:center; justify-content:center; padding:20px; background:rgba(23,29,38,.42); }.customer-modal.open { display:flex; }.customer-dialog { width:min(390px,100%); padding:20px; background:#fff; border-radius:9px; box-shadow:0 18px 50px rgba(15,23,42,.2); }.customer-dialog h2 { margin:0 0 15px; font-size:16px; }.customer-dialog label { display:block; margin:10px 0 5px; color:#656b73; font-size:10px; }.customer-dialog input { width:100%; height:31px; padding:0 9px; border:1px solid #e5e7eb; border-radius:6px; font-size:10px; }.modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:16px; }.modal-actions button { width:auto; height:29px; padding:0 12px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; color:#424951; cursor:pointer; }.modal-actions button.save { border-color:#ed1740; background:#ed1740; color:#fff; }
@media(max-width:1100px){.metrics{grid-template-columns:repeat(3,1fr)}} @media(max-width:720px){.content{margin-left:0;padding:20px 14px}.customer-tabs{width:100%}.topbar{align-items:flex-start}.top-actions{display:none}.metrics{grid-template-columns:1fr 1fr}.toolbar,.filter-form{flex-wrap:wrap}.input-wrap{min-width:100%}.footer{align-items:flex-start;gap:10px;flex-direction:column}}
@media(min-width:1200px){
    .content { padding:36px 40px; }
    .panel { border-radius:10px; }
    .table-scroll { overflow-x:auto; }
    .panel .table-scroll table { width:calc(100% - 32px); min-width:0; margin:12px 16px; table-layout:fixed; }
    .panel.customers-panel .table-scroll table.customers-table { width:calc(100% - 32px); margin:12px 16px; }
    .panel .table-scroll thead { background:#e9ecef; }
    .panel .table-scroll th { background:transparent; padding:14px 12px; }
    .panel .table-scroll td { padding:15px 14px; font-size:13px; }
    th { font-size:12px; }
    th:last-child, td:last-child { width:125px; }
    .customer { gap:11px; }
    .avatar { width:36px; height:36px; font-size:11px; }
    .customer strong { font-size:13px; }
    .customer small { font-size:10px; }
    .status { padding:6px 10px; font-size:11px; }
    .voucher-status { padding:6px 10px; font-size:11px; }
    .action { width:31px; height:29px; }
}
</style>
<style>
.export { display:inline-flex; align-items:center; justify-content:center; gap:7px; height:34px; padding:0 12px; border:1px solid #e4e7eb; border-radius:6px; background:#fff; color:#4e5660; font-size:12px; font-weight:700; white-space:nowrap; }
.export:hover { border-color:#d5dbe1; background:#f9fafb; color:#4e5660; }
.export i { font-size:13px; line-height:1; }
.metric-icon { display:flex !important; align-items:center; justify-content:center; padding:0; text-align:center; line-height:1; }
.metric-icon.material-icons-outlined { font-family:'Material Icons Outlined'; font-weight:normal; font-style:normal; letter-spacing:normal; text-transform:none; white-space:nowrap; direction:ltr; -webkit-font-feature-settings:'liga'; -webkit-font-smoothing:antialiased; }
</style>
<style>
body { background:#f8f9fb; color:#252a30; }
.metric { height:112px; }
.metric-label { font-size:12px; line-height:14px; font-weight:700; }
.metric-value { margin-top:4px; font-size:24px; line-height:26px; font-weight:800; }
.metric:nth-child(1) .metric-value { color:#ed1b3b; }
.metric:nth-child(2) .metric-value { color:#15955c; }
.metric:nth-child(3) .metric-value { color:#1976d2; }
.metric:nth-child(4) .metric-value { color:#e9a316; }
.metric:nth-child(5) .metric-value { color:#7a38c5; }
.metric-note { margin-top:4px; font-size:10px; line-height:12px; font-weight:600; }
.panel { border-color:#f0f1f3; }
table th { background:#e9ecef; color:#495057; }
.panel th:first-child, .panel td:first-child { text-align:left; }
.panel th:not(:first-child), .panel td:not(:first-child) { text-align:center; }
.heading h1 { margin:0 0 7px; color:#252a30; font-family:'Segoe UI',Tahoma,sans-serif; font-size:30px; line-height:36px; font-weight:800; }
.loyalty-tab:hover { border-color:#ed1b3b; color:#ed1b3b; background:#fff2f4; }
.pink { background:#fff0f2; color:#ed1b3b; }
.metric-note.down { color:#ed1b3b; }
.input-wrap button:hover,.action:hover { border-color:#ed1b3b; color:#ed1b3b; }
.export { background:#fff; color:#4e5660; border-color:#e4e7eb; }
.export:hover { border-color:#d5dbe1; background:#f9fafb; color:#4e5660; }
.pagination .current { background:#fff2f4; color:#ed1b3b; border-color:#ffdbe2; }
.action-menu a:hover { background:#fff2f4; color:#ed1b3b; }
.modal-actions button.save { border-color:#ed1b3b; background:#ed1b3b; }
.panel > .toolbar { gap:9px; padding:15px; border-bottom:1px solid #f0f1f3; }
.panel > .toolbar .filter-form { gap:8px; }
.panel > .toolbar .input-wrap .search-icon { position:absolute; top:50%; left:12px; color:#89919a; font-size:14px; transform:translateY(-50%); pointer-events:none; }
.panel > .toolbar .input-wrap input { padding-left:34px; }
.panel > .toolbar .input-wrap input,
.panel > .toolbar .filter-button,
.panel > .toolbar select,
.panel > .toolbar .export { height:34px; font-size:12px; }
.panel > .toolbar select.segment { width:120px; flex:0 0 120px; }
.panel > .toolbar .export { padding:0 12px; }
.panel > .toolbar .export { border-color:#ed1b3b; background:#ed1b3b; color:#fff; }
.panel > .toolbar .export:hover { border-color:#d91635; background:#d91635; color:#fff; }
.panel .footer { padding:11px 12px; border-top:1px solid #f0f1f3; font-size:9px; }
.panel .pagination { gap:4px; }
.panel .pagination a, .panel .pagination span { width:auto; min-width:23px; height:23px; padding:0 5px; display:inline-flex; align-items:center; justify-content:center; border-color:#e1e5e9; border-radius:5px; color:#59636d; }
.panel .pagination .current { border-color:#ed1b3b; background:#ed1b3b; color:#fff; }
@media(min-width:1600px) {
    .metric-label { font-size:16px; line-height:18px; }
    .metric-value { font-size:32px; line-height:32px; }
    .metric-note { font-size:14px; line-height:16px; }
    .panel .table-scroll table { width:calc(100% - 32px); min-width:0; margin:12px 16px; table-layout:fixed; }
    .panel .table-scroll thead { background:#e9ecef; }
    .panel .table-scroll th { background:transparent; padding-top:14px; padding-bottom:14px; }
    .panel .table-scroll th, .panel .table-scroll td { padding:12px 10px; white-space:normal; }
    .panel .table-scroll th { font-size:16px; line-height:18px; font-weight:800; }
    .panel .table-scroll td { padding-top:14px; padding-bottom:14px; font-size:16px; line-height:18px; font-weight:400; overflow-wrap:anywhere; }
    .panel .table-scroll th:first-child, .panel .table-scroll td:first-child { text-align:left; }
    .panel .table-scroll th:not(:first-child), .panel .table-scroll td:not(:first-child) { text-align:center; }
    .panel .table-scroll th:nth-child(1), .panel .table-scroll td:nth-child(1) { width:22%; }
    .panel .table-scroll th:nth-child(2), .panel .table-scroll td:nth-child(2) { width:18%; }
    .panel .table-scroll th:nth-child(3), .panel .table-scroll td:nth-child(3) { width:7%; }
    .panel .table-scroll th:nth-child(4), .panel .table-scroll td:nth-child(4) { width:9%; }
    .panel .table-scroll th:nth-child(5), .panel .table-scroll td:nth-child(5) { width:11%; }
    .panel .table-scroll th:nth-child(6), .panel .table-scroll td:nth-child(6) { width:11%; }
    .panel .table-scroll th:nth-child(7), .panel .table-scroll td:nth-child(7) { width:8%; }
    .panel .table-scroll th:nth-child(8), .panel .table-scroll td:nth-child(8) { width:14%; }
    .panel .customer strong { font-size:16px; }
    .panel .customer small { font-size:12px; }
}
</style>
</head>
<body>
<?php include 'sidebar_owner.php'; ?>
<main class="content">
    <nav class="customer-tabs" aria-label="Customer sections">
        <a class="customer-tab active" href="user_orders.php" aria-current="page">Customers</a>
        <a class="customer-tab" href="loyalty_management.php">Customer Voucher</a>
    </nav>
    <header class="topbar"><div class="heading"><h1>Customers</h1><p>Manage your store customers and their information.</p></div></header>
    <section class="metrics">
        <div class="metric"><div class="metric-icon pink material-icons-outlined" aria-hidden="true">groups</div><div><div class="metric-label">Total Customers</div><div class="metric-value"><?php echo number_format($totalCustomers); ?></div><div class="metric-note">↑ All registered customers</div></div></div>
        <div class="metric"><div class="metric-icon green material-icons-outlined" aria-hidden="true">person_add_alt_1</div><div><div class="metric-label">New Customers</div><div class="metric-value"><?php echo number_format($newCustomers); ?></div><div class="metric-note">↑ Last 30 days</div></div></div>
        <div class="metric"><div class="metric-icon blue material-icons-outlined" aria-hidden="true">group</div><div><div class="metric-label">Returning Customers</div><div class="metric-value"><?php echo number_format($returningCustomers); ?></div><div class="metric-note">↑ More than one order</div></div></div>
        <div class="metric"><div class="metric-icon yellow material-icons-outlined" aria-hidden="true">star</div><div><div class="metric-label">Active Customers</div><div class="metric-value"><?php echo number_format($activeCustomers); ?></div><div class="metric-note">↑ Last 90 days</div></div></div>
        <div class="metric"><div class="metric-icon purple material-icons-outlined" aria-hidden="true">person_off</div><div><div class="metric-label">Inactive Customers</div><div class="metric-value"><?php echo number_format($inactiveCustomers); ?></div><div class="metric-note down">↓ No recent orders</div></div></div>
    </section>
    <section class="panel customers-panel">
        <div class="toolbar"><form class="filter-form" method="get"><div class="input-wrap"><i class="bi bi-search search-icon" aria-hidden="true"></i><input id="customerSearch" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, email or phone..." aria-label="Search customers"><button id="clearCustomerSearch" type="button" title="Clear search" aria-label="Clear search" style="display:<?php echo $search ? 'flex' : 'none'; ?>">&times;</button></div><select class="segment" name="segment" onchange="this.form.submit()" aria-label="Customer status"><option value="all" <?php echo $segment === 'all' ? 'selected' : ''; ?>>All Status</option><option value="active" <?php echo $segment === 'active' ? 'selected' : ''; ?>>Active Customers</option><option value="inactive" <?php echo $segment === 'inactive' ? 'selected' : ''; ?>>Inactive Customers</option><option value="not_eligible" <?php echo $segment === 'not_eligible' ? 'selected' : ''; ?>>Not Eligible</option><option value="eligible" <?php echo $segment === 'eligible' ? 'selected' : ''; ?>>Eligible</option><option value="voucher_sent" <?php echo $segment === 'voucher_sent' ? 'selected' : ''; ?>>Voucher Sent</option><option value="voucher_used" <?php echo $segment === 'voucher_used' ? 'selected' : ''; ?>>Voucher Used</option><option value="expired" <?php echo $segment === 'expired' ? 'selected' : ''; ?>>Expired</option></select><button class="export" type="button" onclick="window.print()"><i class="bi bi-box-arrow-up" aria-hidden="true"></i> Export</button></form></div>
        <p style="margin:0 16px 8px;color:#68707a;font-size:11px;">Voucher eligibility is based on this month’s spending and resets monthly. Rates: ₱1,000 = 10% · ₱2,000 = 15% · ₱3,000 = 20% · ₱4,000 = 30% · ₱5,000 = 40% · ₱10,000 = 50%. Sent vouchers expire after 7 days.</p>
        <div class="table-scroll"><table class="customers-table"><thead><tr><th>Customer</th><th>Email</th><th>Spent This Month</th><th>Voucher Discount</th><th>Voucher Status</th><th>Actions</th></tr></thead><tbody>
        <?php if ($customers && $customers->num_rows): while ($customer = $customers->fetch_assoc()): ?>
            <?php
                $name = $customer['username'] ?: 'Customer';
                $initials = strtoupper(substr($name, 0, 2));
                $isActive = !empty($customer['last_order']) && strtotime($customer['last_order']) >= strtotime('-90 days');
                $tierDiscount = $getVoucherDiscountPercent((float)$customer['total_spent']);
                $storedDiscount = (float)($customer['assigned_voucher_discount'] ?? 0);
                $hasIssuedVoucher = in_array($customer['voucher_status'], ['sent', 'used', 'expired'], true);
                $displayDiscount = $hasIssuedVoucher && $customer['voucher_discount_type'] === 'percent'
                    ? (int)$storedDiscount
                    : ($hasIssuedVoucher && $customer['voucher_discount_type'] === 'fixed'
                        ? $storedDiscount
                        : $tierDiscount);
                $voucherStatus = $customer['voucher_status'] === 'used'
                    ? 'Voucher Used'
                    : ($customer['voucher_status'] === 'expired'
                        ? 'Expired'
                        : ($customer['voucher_status'] === 'sent'
                            ? 'Voucher Sent'
                            : ($tierDiscount > 0 ? 'Eligible' : 'Not Eligible')));
                $voucherStatusClass = $voucherStatus === 'Voucher Used'
                    ? 'voucher-used'
                    : ($voucherStatus === 'Expired'
                        ? 'voucher-expired'
                        : ($voucherStatus === 'Voucher Sent'
                            ? 'voucher-sent'
                            : ($voucherStatus === 'Eligible' ? 'voucher-eligible' : 'voucher-not-eligible')));
                $canSendVoucher = $voucherSchemaReady
                    && $voucherStatus === 'Eligible'
                    && $tierDiscount > 0
                    && filter_var($customer['email'], FILTER_VALIDATE_EMAIL);
            ?>
            <tr>
                <td><div class="customer"><span class="avatar"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></span><div><strong><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo $customer['total_orders'] ? number_format((int)$customer['total_orders']) . ' orders' : 'No orders'; ?> · <?php echo $isActive ? 'Active' : 'Inactive'; ?></small></div></div></td>
                <td><?php echo htmlspecialchars($customer['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>₱<?php echo number_format((float)$customer['total_spent'], 2); ?></td>
                <td>
                    <?php
                        if ($displayDiscount <= 0) {
                            echo '—';
                        } elseif ($hasIssuedVoucher && $customer['voucher_discount_type'] === 'fixed') {
                            echo '₱' . number_format($displayDiscount, 2) . ' OFF';
                        } else {
                            echo number_format($displayDiscount) . '% OFF';
                        }
                    ?>
                </td>
                <td>
                    <span class="voucher-status <?php echo $voucherStatusClass; ?>"><?php echo htmlspecialchars($voucherStatus, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($customer['voucher_code']): ?><small class="voucher-code"><?php echo htmlspecialchars($customer['voucher_code'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    <?php if ($customer['voucher_issued_at']): ?><small class="voucher-dates">Issued: <?php echo date('M d, Y', strtotime($customer['voucher_issued_at'])); ?><br>Expires: <?php echo $customer['voucher_expires_at'] ? date('M d, Y', strtotime($customer['voucher_expires_at'])) : '—'; ?></small><?php endif; ?>
                </td>
                <td>
                    <?php if ($canSendVoucher): ?>
                        <form method="post" class="voucher-form" data-discount="<?php echo (int)$tierDiscount; ?>" data-email="<?php echo htmlspecialchars($customer['email'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="generate_loyalty_voucher">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($customerCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="customer_id" value="<?php echo (int)$customer['id']; ?>">
                            <input type="hidden" name="page" value="<?php echo $page; ?>">
                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="segment" value="<?php echo htmlspecialchars($segment, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="voucher-action" title="Generate and email voucher" aria-label="Generate and email voucher"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                        </form>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endwhile; else: ?><tr><td colspan="6" style="text-align:center">No customers found.</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="footer"><span>Showing <?php echo $totalCustomers ? $offset + 1 : 0; ?> to <?php echo min($offset + $perPage, $totalCustomers); ?> of <?php echo number_format($totalCustomers); ?> customers</span><?php if ($totalCustomers > $perPage): ?><div class="pagination"><?php if ($page > 1): ?><a href="<?php echo customerQuery($page - 1, $search, $segment); ?>" aria-label="Previous page">‹</a><?php endif; ?><span class="current" aria-current="page"><?php echo $page; ?></span><?php if ($page < $totalPages): ?><a href="<?php echo customerQuery($page + 1, $search, $segment); ?>" aria-label="Next page">›</a><?php endif; ?></div><?php endif; ?></div>
    </section>
    <?php if ($voucherMessage !== ''): ?>
        <div class="voucher-alert <?php echo strpos($voucherMessage, 'Voucher emailed successfully') === 0 ? 'success' : 'error'; ?>" role="status"><?php echo htmlspecialchars($voucherMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if (!$voucherSchemaReady): ?>
        <div class="voucher-alert error" role="alert">Customer voucher storage is unavailable. Please check the database configuration.</div>
    <?php endif; ?>
</main>
<div class="customer-modal" id="customerModal" role="dialog" aria-modal="true" aria-labelledby="customerModalTitle">
    <div class="customer-dialog">
        <h2 id="customerModalTitle">Customer Details</h2>
        <form method="post" id="customerForm">
            <input type="hidden" name="action" value="update_customer">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($customerCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="customer_id" id="customerId">
            <label for="customerName">Name</label>
            <input id="customerName" name="username" required>
            <label for="customerEmail">Email</label>
            <input id="customerEmail" name="email" type="email" required>
            <label for="customerPhone">Phone</label>
            <input id="customerPhone" name="phone">
            <div class="modal-actions">
                <button type="button" id="closeCustomerModal">Close</button>
                <button type="submit" class="save" id="saveCustomerButton">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<script>
// Clear search button functionality
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.voucher-form').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            const discount = Number(form.dataset.discount);
            const email = form.dataset.email;
            if (!window.confirm('Generate a ' + discount + '% OFF voucher valid for 7 days and email it to ' + email + '? A customer may receive one loyalty voucher per month.')) {
                event.preventDefault();
            }
        });
    });

    const searchInput = document.getElementById('customerSearch');
    const clearBtn = document.getElementById('clearCustomerSearch');
    
    if (searchInput && clearBtn) {
        searchInput.addEventListener('input', function() {
            clearBtn.style.display = this.value ? 'block' : 'none';
        });
        
        clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            searchInput.value = '';
            clearBtn.style.display = 'none';
            searchInput.form.submit();
        });
    }

    const customerModal = document.getElementById('customerModal');
    const customerForm = document.getElementById('customerForm');
    const saveCustomerButton = document.getElementById('saveCustomerButton');
    document.querySelectorAll('.metric').forEach(function(card, index) {
        const segments = ['all', 'all', 'all', 'active', 'inactive'];
        card.style.cursor = 'pointer';
        card.addEventListener('click', function() {
            window.location.href = '?segment=' + segments[index];
        });
    });
    document.querySelectorAll('.edit-customer').forEach(function(button) {
        button.addEventListener('click', function() {
            document.getElementById('customerModalTitle').textContent = 'Edit Customer';
            customerForm.style.display = 'block';
            document.getElementById('customerId').value = button.dataset.id;
            document.getElementById('customerName').value = button.dataset.name;
            document.getElementById('customerEmail').value = button.dataset.email;
            document.getElementById('customerPhone').value = button.dataset.phone || '';
            saveCustomerButton.style.display = 'inline-flex';
            customerModal.classList.add('open');
        });
    });
    document.getElementById('closeCustomerModal').addEventListener('click', function() { customerModal.classList.remove('open'); });
    customerModal.addEventListener('click', function(event) { if (event.target === customerModal) customerModal.classList.remove('open'); });
});
</script>
</body>
</html>
<?php exit; ?>
<?php
session_start();
include 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// ✅ TOTAL SALES
$totalResult = $conn->query("SELECT SUM(price) AS total FROM order_items");
$totalRow = $totalResult->fetch_assoc();
$grandTotal = $totalRow['total'] ?? 0;
?>

<!DOCTYPE html>
<html>
<head>
<title>Orders</title>

<link rel="stylesheet" href="css/sidebar_owner.css?v=20260820-4">
<style>
body { margin:0; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f6f8; min-height:100vh; }

.content { margin-left:260px; padding:32px; width:calc(100% - 260px); min-height:100vh; }

.card {
    background:#2c3e50;
    color:white;
    padding:20px;
    width:300px;
    border-radius:10px;
    margin-bottom:20px;
    font-size:18px;
}

table {
    width:100%;
    border-collapse:collapse;
    background:white;
}

th, td {
    padding:10px;
    border:1px solid #ccc;
    text-align:center;
}

th {
    background:#34495e;
    color:white;
}
</style>
</head>

<body>

<?php include 'sidebar_owner.php'; ?>

<div class="content">

<h2>📦 Orders List</h2>

<div class="card">
    💰 Total Sales: ₱<?php echo number_format($grandTotal,2); ?>
</div>

<table>
<tr>
    <th>ID</th>
    <th>Name</th>
    <th>Address</th>
    <th>Product</th>
    <th>Box / Pieces</th>
    <th>Total</th>
    <th>Payment</th>
    <th>Delivery</th>
    <th>Date</th>
</tr>

<?php
$sql = "SELECT 
    orders.id,
    orders.fullname,
    orders.address,
    orders.payment_method,
    orders.delivery_type,
    orders.order_date,
    products.product_name,

    order_items.box_qty,
    order_items.piece_qty,
    order_items.quantity,
    order_items.price

FROM orders
JOIN order_items ON orders.id = order_items.order_id
JOIN products ON order_items.product_id = products.id
ORDER BY orders.id DESC";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {

    while($row = $result->fetch_assoc()) {

        // ✅ FIX DATA
        $box = (int)($row['box_qty'] ?? 0);
        $pieces = (int)($row['piece_qty'] ?? 0);

        // fallback for old orders
        if ($box == 0 && $pieces == 0) {
            $pieces = (int)($row['quantity'] ?? 0);
        }

        echo "<tr>
            <td>{$row['id']}</td>
            <td>{$row['fullname']}</td>
            <td>{$row['address']}</td>
            <td>{$row['product_name']}</td>

            <td>
                Box: <b>$box</b><br>
                Pieces: <b>$pieces</b>
            </td>

            <td>₱" . number_format($row['price'],2) . "</td>

            <td>{$row['payment_method']}</td>
            <td>{$row['delivery_type']}</td>
            <td>{$row['order_date']}</td>
        </tr>";
    }

} else {
    echo "<tr><td colspan='9'>No orders found</td></tr>";
}
?>

</table>

</div>

</body>
</html>