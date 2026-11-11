<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$order_id = (int)($_GET['order_id'] ?? 0);
$order = null;

if ($order_id > 0) {
    $stmt = $conn->prepare(
        "SELECT o.*, COALESCE(SUM(oi.total_price), 0) AS calculated_total
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE o.id = ? AND o.user_id = ?
         GROUP BY o.id"
    );
    if ($stmt) {
        $stmt->bind_param('ii', $order_id, $user_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$order) {
    header('Location: my_orders.php');
    exit();
}

$payment_labels = [
    'cod' => 'Cash',
    'gcash' => 'GCash',
    'bank' => 'Bank Transfer'
];
$payment_key = strtolower(trim($order['payment_method'] ?? ''));
$hasDiscountId = in_array($order['discount_type'] ?? '', ['senior_citizen', 'pwd'], true);
$discountStatus = strtolower(trim((string)($order['discount_status'] ?? 'not_applicable')));
$discountAmount = $discountStatus === 'approved' ? (float)($order['discount_amount'] ?? 0) : 0.0;
$payment_method = $payment_labels[$payment_key]
    ?? (ucfirst($payment_key) ?: 'Not specified');
$statusValue = strtolower(trim((string)($order['status'] ?? 'pending')));
$status = $statusValue === 'ready_for_pickup' ? 'Ready for Pickup' : ucfirst(str_replace('_', ' ', $statusValue));
if ($status === '') {
    $status = 'Pending';
}
$payment_status = strtolower(trim((string)($order['payment_status'] ?? 'pending')));
$delivery_status = strtolower(trim((string)($order['delivery_status'] ?? '')));
$isCodPayment = in_array($payment_key, ['cod', 'cash'], true);
$isOnlinePayment = in_array($payment_key, ['gcash', 'bank', 'bpi', 'bank transfer'], true);
$orderCompleted = in_array(strtolower(trim((string)($order['status'] ?? ''))), ['completed', 'delivered'], true)
    || $delivery_status === 'delivered';
$receiptAvailable = strtolower(trim((string)($order['status'] ?? ''))) !== 'cancelled'
    && $payment_status === 'paid'
    && (
        $isOnlinePayment
        || ($isCodPayment && $orderCompleted)
        || (!$isOnlinePayment && !$isCodPayment && $orderCompleted)
    );
$isOnlinePaymentRejected = in_array($payment_key, ['gcash', 'bank'], true)
    && $payment_status === 'rejected';
$isOnlinePaymentPending = in_array($payment_key, ['gcash', 'bank'], true)
    && !in_array($payment_status, ['paid', 'rejected'], true);
$payment_rejection_reason = trim((string)($order['payment_reference'] ?? ''));
$isLalamoveRiderCancellation = strtolower(trim((string)($order['status'] ?? ''))) === 'cancelled'
    && in_array(strtolower(trim((string)($order['delivery_type'] ?? ''))), ['lalamove', 'delivery'], true)
    && strtolower(trim((string)($order['delivery_action'] ?? ''))) === 'cancel_order';
$delivery_rejection_reason = ucwords(str_replace('_', ' ', trim((string)($order['delivery_failure_reason'] ?? '')) ?: 'Not provided'));
$order_message = $isLalamoveRiderCancellation
    ? 'Your Lalamove delivery was cancelled by the rider. Reason: ' . $delivery_rejection_reason . '. Action: Cancel order.'
    : ($isOnlinePaymentRejected
    ? 'Your payment was rejected and the order was cancelled.'
        . ($payment_rejection_reason !== '' ? ' Reason: ' . $payment_rejection_reason : '')
    : ($isOnlinePaymentPending
        ? 'Your payment is pending admin approval. Your order will be processed after the payment is approved.'
        : 'Your order has been placed successfully. Current status: ' . strtolower($status) . '.'));
$itemTotal = (float)$order['calculated_total'];
$recordedTotal = (float)($order['total_amount'] ?? 0);
$deliveryFee = (float)($order['shipping_fee'] ?? 0);
$total_amount = $recordedTotal > 0 ? $recordedTotal : $itemTotal + $deliveryFee;
$order_number = formatOrderNumber($conn, $order_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Placed Successfully</title>
    <style>
        :root { --maroon: #651b25; --ink: #26313d; --muted: #77808a; --line: #e9edef; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 28px 16px; color: var(--ink); background: #f4f6f7; font-family: "Segoe UI", Arial, sans-serif; }
        .receipt { width: min(100%, 470px); overflow: hidden; background: #fff; border: 1px solid #e1e5e7; border-radius: 14px; box-shadow: 0 16px 40px rgba(38, 49, 61, .10); }
        .hero { padding: 34px 24px 28px; text-align: center; }
        .check { width: 58px; height: 58px; display: grid; place-items: center; margin: 0 auto 16px; border-radius: 50%; color: #fff; background: #2d9b68; font-size: 31px; font-weight: 700; }
        h1 { margin: 0 0 10px; color: var(--maroon); font-size: 22px; letter-spacing: .04em; }
        .message { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.5; }
        .details { border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); padding: 22px 26px; }
        .row { display: flex; justify-content: space-between; gap: 20px; padding: 9px 0; }
        .label { color: var(--muted); font-size: 11px; font-weight: 700; letter-spacing: .09em; }
        .value { color: var(--ink); font-size: 14px; font-weight: 600; text-align: right; }
        .total { color: var(--maroon); font-size: 17px; }
        .status { color: #c97820; }
        .actions { display: grid; gap: 10px; padding: 24px 26px 28px; text-align: center; }
        .button { display: block; padding: 12px 16px; border-radius: 7px; font-size: 13px; font-weight: 700; text-decoration: none; }
        .primary { color: #fff; background: var(--maroon); }
        .receipt-disabled-wrap { position:relative; display:block; width:100%; }
        .receipt-button { width:100%; border:0; cursor:pointer; }
        .receipt-button.receipt-disabled { border:1px solid #d5dbe4; color:#778396; background:#edf0f4; cursor:not-allowed; opacity:1; }
        .receipt-tooltip { position:absolute; z-index:2; top:calc(100% + 8px); left:50%; width:max-content; max-width:100%; padding:9px 12px; border-radius:6px; color:#fff; background:#263247; font-size:12px; font-weight:500; line-height:1.4; transform:translate(-50%,-3px); opacity:0; visibility:hidden; transition:opacity .15s ease,transform .15s ease,visibility .15s ease; }
        .receipt-tooltip::before { position:absolute; bottom:100%; left:calc(50% - 6px); border:6px solid transparent; border-bottom-color:#263247; content:""; }
        .receipt-disabled-wrap:hover .receipt-tooltip,.receipt-disabled-wrap:focus .receipt-tooltip,.receipt-disabled-wrap.is-open .receipt-tooltip { opacity:1; visibility:visible; transform:translate(-50%,0); }
        .secondary { color: var(--maroon); border: 1px solid var(--maroon); background: #fff; }
        .link { color: var(--muted); font-size: 13px; text-decoration: none; }
        @media (max-width: 420px) { .details, .actions { padding-left: 20px; padding-right: 20px; } }
    </style>
</head>
<body>
    <main class="receipt" aria-labelledby="order-title">
        <section class="hero">
            <div class="check" aria-hidden="true">&#10003;</div>
            <h1 id="order-title">ORDER PLACED SUCCESSFULLY!</h1>
            <p class="message"><?= htmlspecialchars($order_message, ENT_QUOTES, 'UTF-8') ?></p>
        </section>
        <section class="details">
            <div class="row"><span class="label">ORDER NUMBER</span><span class="value"><?= htmlspecialchars($order_number) ?></span></div>
            <div class="row"><span class="label">ORDER DATE</span><span class="value"><?= date('F j, Y', strtotime($order['order_date'])) ?> &#8226; <?= date('g:i A', strtotime($order['order_date'])) ?></span></div>
            <div class="row"><span class="label">PAYMENT METHOD</span><span class="value"><?= htmlspecialchars($payment_method) ?></span></div>
            <?php if ($discountAmount > 0): ?>
                <div class="row"><span class="label">20% DISCOUNT</span><span class="value">-₱<?= number_format($discountAmount, 2) ?></span></div>
            <?php endif; ?>
            <div class="row"><span class="label">TOTAL AMOUNT</span><span class="value total">&#8369;<?= number_format($total_amount, 2) ?></span></div>
            <div class="row"><span class="label">ORDER STATUS</span><span class="value status">&#9679; <?= htmlspecialchars($status) ?></span></div>
            <?php if ($hasDiscountId): ?>
                <div class="row"><span class="label">ID STATUS</span><span class="value status"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $discountStatus)), ENT_QUOTES, 'UTF-8') ?></span></div>
            <?php endif; ?>
        </section>
        <nav class="actions" aria-label="Order actions">
            <?php if ($receiptAvailable): ?>
                <a class="button primary" href="view_receipt.php?order_id=<?= $order_id ?>">VIEW RECEIPT</a>
            <?php elseif (strtolower(trim((string)($order['status'] ?? ''))) !== 'cancelled'): ?>
                <span class="receipt-disabled-wrap" tabindex="0" role="button" aria-disabled="true" aria-label="Receipt unavailable" aria-describedby="receiptUnavailableNote">
                    <span class="button receipt-button receipt-disabled">VIEW RECEIPT</span>
                    <span class="receipt-tooltip" id="receiptUnavailableNote" role="tooltip"><?= $isOnlinePayment
                        ? 'Your receipt will be available after the admin approves your payment.'
                        : ($isCodPayment
                            ? 'Your COD receipt will be available after the order is completed and paid.'
                            : 'Your receipt will be available after payment and order completion.') ?></span>
                </span>
            <?php endif; ?>
            <a class="button secondary" href="my_orders.php">CHECK MY ORDERS</a>
            <a class="link" href="product_screen.php">Continue Shopping</a>
        </nav>
    </main>
    <script>
    document.querySelectorAll('.receipt-disabled-wrap').forEach(function (control) {
        control.addEventListener('click', function () {
            control.classList.toggle('is-open');
        });
        control.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                control.classList.toggle('is-open');
            } else if (event.key === 'Escape') {
                control.classList.remove('is-open');
            }
        });
    });
    document.addEventListener('click', function (event) {
        document.querySelectorAll('.receipt-disabled-wrap.is-open').forEach(function (control) {
            if (!control.contains(event.target)) control.classList.remove('is-open');
        });
    });
    </script>
</body>
</html>
