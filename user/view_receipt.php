<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$order_id = (int) ($_GET['order_id'] ?? 0);

$stmt = $conn->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
$stmt->bind_param('ii', $order_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    header('Location: my_orders.php');
    exit();
}

$paymentMethod = strtolower(trim((string)($order['payment_method'] ?? '')));
$paymentStatus = strtolower(trim((string)($order['payment_status'] ?? 'unpaid')));
$orderStatus = strtolower(trim((string)($order['status'] ?? '')));
$deliveryStatus = strtolower(trim((string)($order['delivery_status'] ?? '')));
$isOnlinePayment = in_array($paymentMethod, ['gcash', 'bank', 'bpi', 'bank transfer'], true);
$isCodPayment = in_array($paymentMethod, ['cod', 'cash'], true);
$orderCompleted = in_array($orderStatus, ['completed', 'delivered'], true) || $deliveryStatus === 'delivered';
$receiptAvailable = $orderStatus !== 'cancelled'
    && $paymentStatus === 'paid'
    && (
        $isOnlinePayment
        || ($isCodPayment && $orderCompleted)
        || (!$isOnlinePayment && !$isCodPayment && $orderCompleted)
    );
if (!$receiptAvailable) {
    header('Location: order_details.php?id=' . $order_id . '&receipt=unavailable');
    exit();
}

$item_stmt = $conn->prepare(
    'SELECT oi.product_name, oi.price, oi.total_price, oi.box, oi.pieces,
            COALESCE(p.pieces_per_box, 12) AS pieces_per_box
     FROM order_items oi
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE oi.order_id = ?
     ORDER BY oi.id ASC'
);
$item_stmt->bind_param('i', $order_id);
$item_stmt->execute();
$items = $item_stmt->get_result();

$subtotal = 0;
$order_items = [];
while ($item = $items->fetch_assoc()) {
    $quantity = ((int) $item['box'] * (int) $item['pieces_per_box']) + (int) $item['pieces'];
    $item['quantity'] = max(1, $quantity);
    $item['line_total'] = (float) $item['total_price'];
    $subtotal += $item['line_total'];
    $order_items[] = $item;
}
$item_stmt->close();

$payment_labels = [
    'cod' => 'Cash',
    'gcash' => 'GCash',
    'bank' => 'Bank Transfer'
];
$cashierName = 'Pharmacist not recorded';
$cashierUserId = (int)($order['seller_id'] ?? 0);
$paymentReviewerId = (int)($order['payment_verified_by'] ?? 0);
if ($cashierUserId > 0 || $paymentReviewerId > 0) {
    $cashierStmt = $conn->prepare(
        "SELECT COALESCE(NULLIF(TRIM(full_name), ''), username) AS cashier_name
         FROM users
         WHERE id IN (?, ?) AND role = 'pharmacist'
         ORDER BY CASE WHEN id = ? THEN 0 ELSE 1 END
         LIMIT 1"
    );
    if (!$cashierStmt) {
        error_log('Receipt pharmacist cashier lookup preparation failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to load receipt cashier information.');
    }
    $cashierStmt->bind_param('iii', $cashierUserId, $paymentReviewerId, $cashierUserId);
    if (!$cashierStmt->execute()) {
        error_log('Receipt pharmacist cashier lookup failed: ' . $cashierStmt->error);
        $cashierStmt->close();
        http_response_code(500);
        exit('Unable to load receipt cashier information.');
    }
    $cashier = $cashierStmt->get_result()->fetch_assoc();
    $cashierStmt->close();
    if (!empty($cashier['cashier_name'])) {
        $cashierName = trim((string)$cashier['cashier_name']);
    }
}
$payment_key = strtolower(trim($order['payment_method'] ?? ''));
$payment_method = $payment_labels[$payment_key] ?? ucfirst($payment_key ?: 'Not specified');
$discount_status = strtolower(trim((string)($order['discount_status'] ?? 'not_applicable')));
$discount_amount = $discount_status === 'approved' ? (float)($order['discount_amount'] ?? 0) : 0.0;
$discount_label = ($order['discount_type'] ?? '') === 'pwd'
    ? 'PWD Discount (20%)'
    : 'Senior Citizen Discount (20%)';
$voucher_code = trim((string)($order['coupon_code'] ?? ''));
$voucher_discount = (float)($order['coupon_discount_amount'] ?? 0);
$shipping_fee = (float)($order['shipping_fee'] ?? 0);
$total = (float) ($order['total_amount'] ?? 0);
if ($total <= 0) {
    $total = max(0, $subtotal - $discount_amount - $voucher_discount + $shipping_fee);
}
$receipt_number = formatOrderNumber($conn, $order_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= htmlspecialchars($receipt_number) ?></title>
    <style>
        :root { --red: #e51c23; --ink: #151b22; --muted: #53606d; --line: #b9c1c7; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: start center; padding: 18px; background: #f3f6f8; color: var(--ink); font-family: "Segoe UI", Arial, sans-serif; }
        .receipt { position: relative; width: min(100%, 360px); min-height: 720px; padding: 20px 18px 32px; background: #fff; border: 1px solid #e1e6ea; box-shadow: 0 8px 24px rgba(30, 48, 60, .12); }
        .receipt::after { content: ''; position: absolute; right: -1px; bottom: -8px; left: -1px; height: 9px; background: linear-gradient(135deg, #fff 5px, transparent 5px) 0 0 / 10px 10px repeat-x; }
        .brand { text-align: center; padding-bottom: 12px; border-bottom: 1px solid var(--ink); }
        .logo { display: block; width: 58px; height: 58px; margin: 0 auto 5px; object-fit: contain; }
        .brand-name { display: block; color: var(--red); font-size: 21px; line-height: 1.1; font-weight: 800; }
        .tagline { display: block; color: var(--ink); font-size: 10px; line-height: 1.15; font-weight: 700; letter-spacing: .1px; }
        .contact { margin-top: 9px; color: var(--ink); font-size: 11px; line-height: 1.45; letter-spacing: .1px; }
        .receipt-meta { display: grid; grid-template-columns: 1fr auto; gap: 5px; padding: 10px 0 9px; border-bottom: 1px solid var(--line); font-size: 11px; }
        .receipt-meta strong { font-size: 12px; }
        .meta-right { text-align: right; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th { padding: 10px 0 8px; border-bottom: 1px solid var(--line); text-align: left; font-size: 10px; }
        th:not(:first-child), td:not(:first-child) { text-align: right; }
        td { padding: 8px 0; border-bottom: 1px solid #edf0f2; vertical-align: top; }
        .product-name { font-weight: 700; }
        .product-type { display: block; color: var(--ink); font-size: 10px; margin-top: 2px; }
        .summary { padding: 8px 0 5px; border-bottom: 1px solid var(--line); }
        .summary-line { display: flex; justify-content: flex-end; gap: 42px; padding: 3px 0; font-size: 11px; }
        .total-line { display: flex; justify-content: flex-end; align-items: center; gap: 42px; padding: 8px 0 4px; font-size: 18px; font-weight: 800; }
        .total-line strong { color: var(--red); font-size: 20px; }
        .payment { padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 11px; }
        .payment strong { display: block; margin-bottom: 5px; font-size: 10px; }
        .footer { padding-top: 22px; text-align: center; }
        .footer p { margin: 0 0 7px; font-size: 10px; line-height: 1.45; }
        .footer a { color: var(--red); font-weight: 700; text-decoration: none; }
        .actions { display: flex; justify-content: center; gap: 10px; margin-top: 18px; }
        .actions a, .actions button { border: 1px solid var(--red); border-radius: 5px; padding: 8px 12px; color: var(--red); background: white; font-size: 11px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .actions .primary { color: var(--red); background: white; }
        @media print { body { padding: 0; background: white; } .receipt { width: 100%; border: 0; box-shadow: none; } .actions { display: none; } }
    </style>
</head>
<body>
    <main class="receipt">
        <header class="brand">
            <div><img class="logo" src="images/logo.png" alt="Josefina Farmacia logo"><span class="brand-name">Josefina Farmacia<span class="tagline">QUALITY AND AFFORDABLE BRANDED<br>&amp; GENERIC MEDICINES</span></span></div>
            <div class="contact">123 Main St., Manila, Philippines<br>Tel: (02) 1234-5678</div>
        </header>

        <section class="receipt-meta">
            <div><strong>RECEIPT <?= htmlspecialchars($receipt_number, ENT_QUOTES, 'UTF-8') ?></strong><br>Cashier: <?= htmlspecialchars($cashierName, ENT_QUOTES, 'UTF-8') ?><br>Customer: <?= htmlspecialchars($order['fullname'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="meta-right"><?= date('d M Y', strtotime($order['order_date'])) ?><br><?= date('h:i A', strtotime($order['order_date'])) ?></div>
        </section>

        <table>
            <thead><tr><th>ITEM</th><th>QTY</th><th>PRICE</th><th>TOTAL</th></tr></thead>
            <tbody>
                <?php foreach ($order_items as $item): ?>
                    <tr>
                        <td><span class="product-name"><?= htmlspecialchars($item['product_name']) ?></span><span class="product-type">Medicine</span></td>
                        <td><?= $item['quantity'] ?></td>
                        <td>₱<?= number_format((float) $item['price'], 2) ?></td>
                        <td>₱<?= number_format($item['line_total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <section class="summary">
            <div class="summary-line"><span>Subtotal</span><span>₱<?= number_format($subtotal, 2) ?></span></div>
            <?php if ($shipping_fee > 0): ?>
                <div class="summary-line"><span>Delivery Fee</span><span>₱<?= number_format($shipping_fee, 2) ?></span></div>
            <?php endif; ?>
            <?php if ($discount_amount > 0): ?>
                <div class="summary-line"><span><?= htmlspecialchars($discount_label, ENT_QUOTES, 'UTF-8') ?></span><span>-₱<?= number_format($discount_amount, 2) ?></span></div>
            <?php endif; ?>
            <?php if ($voucher_discount > 0): ?>
                <div class="summary-line">
                    <span>Voucher Discount</span>
                    <span>-₱<?= number_format($voucher_discount, 2) ?></span>
                </div>
            <?php endif; ?>
        </section>
        <div class="total-line"><span>TOTAL</span><strong>₱<?= number_format($total, 2) ?></strong></div>
        <div class="payment"><strong>PAYMENT</strong><?= htmlspecialchars($payment_method) ?><span style="float:right;">₱<?= number_format($total, 2) ?></span></div>

        <footer class="footer">
            <p>Thank you for your purchase!<br>Please keep this receipt for reference.</p>
            <p>Receipt verification / e-receipt<br>Visit<br><a href="https://www.josefinafarmacia.ph/verify">www.josefinafarmacia.ph/verify</a></p>
        </footer>
        <nav class="actions" aria-label="Receipt actions"><button class="primary" type="button" onclick="window.print()">▣ &nbsp; Print Receipt</button><a href="my_orders.php">← &nbsp; Back to Orders</a></nav>
    </main>
</body>
</html>
