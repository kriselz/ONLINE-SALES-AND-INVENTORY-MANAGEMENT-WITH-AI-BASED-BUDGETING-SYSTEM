<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/access_control.php';

$isPharmacist = requireOwnerPageAccess($conn, true);
$staffId = (int)($_SESSION['user_id'] ?? 0);
$saleId = (int)($_GET['sale_id'] ?? 0);
$orderId = (int)($_GET['order_id'] ?? 0);
if ($saleId < 1 && $orderId < 1) {
    http_response_code(400);
    exit('Invalid receipt.');
}

$isPosSale = $saleId > 0;
if ($isPosSale) {
    $saleSql = "SELECT id, customer_name, payment_reference, discount_type, discount_amount,
            cashier_id, total_amount, created_at
        FROM pos_sales WHERE id = ?";
    if ($isPharmacist) {
        $saleSql .= ' AND cashier_id = ?';
    }
    $saleStmt = $conn->prepare($saleSql);
    if (!$saleStmt) {
        error_log('Physical POS receipt sale lookup preparation failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to load the receipt.');
    }
    if ($isPharmacist) {
        $saleStmt->bind_param('ii', $saleId, $staffId);
    } else {
        $saleStmt->bind_param('i', $saleId);
    }
    if (!$saleStmt->execute()) {
        error_log('Physical POS receipt sale lookup failed: ' . $saleStmt->error);
        $saleStmt->close();
        http_response_code(500);
        exit('Unable to load the receipt.');
    }
    $sale = $saleStmt->get_result()->fetch_assoc();
    $saleStmt->close();
    $order = $sale ? [
        'fullname' => $sale['customer_name'],
        'payment_reference' => $sale['payment_reference'],
        'discount_type' => $sale['discount_type'],
        'discount_status' => $sale['discount_type'] ? 'approved' : 'not_applicable',
        'discount_amount' => $sale['discount_amount'],
        'seller_id' => $sale['cashier_id'],
        'total_amount' => $sale['total_amount'],
        'order_date' => $sale['created_at'],
    ] : null;
} else {
    $amountColumnResult = $conn->query("SHOW COLUMNS FROM orders LIKE 'total_amount'");
    if (!$amountColumnResult) {
        error_log('Physical POS receipt total column lookup failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to load the receipt.');
    }
    $amountColumn = $amountColumnResult->num_rows > 0 ? 'total_amount' : 'total_price';
    $orderSql = "SELECT id, fullname, payment_reference, payment_method, delivery_type, order_date,
            discount_type, discount_status, discount_amount, seller_id, `$amountColumn` AS total_amount
        FROM orders
        WHERE id = ? AND LOWER(TRIM(COALESCE(payment_method, ''))) = 'onsite'
          AND LOWER(TRIM(COALESCE(delivery_type, ''))) = 'onsite'";
    if ($isPharmacist) {
        $orderSql .= ' AND seller_id = ?';
    }
    $orderStmt = $conn->prepare($orderSql);
    if (!$orderStmt) {
        error_log('Physical POS receipt order lookup preparation failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to load the receipt.');
    }
    if ($isPharmacist) {
        $orderStmt->bind_param('ii', $orderId, $staffId);
    } else {
        $orderStmt->bind_param('i', $orderId);
    }
    if (!$orderStmt->execute()) {
        error_log('Physical POS receipt order lookup failed: ' . $orderStmt->error);
        $orderStmt->close();
        http_response_code(500);
        exit('Unable to load the receipt.');
    }
    $order = $orderStmt->get_result()->fetch_assoc();
    $orderStmt->close();
}
if (!$order) {
    http_response_code(404);
    exit('Receipt not found.');
}

$itemSql = $isPosSale
    ? 'SELECT product_name, quantity, total_price, COALESCE(NULLIF(TRIM(category), \'\'), \'Medicine\') AS category FROM pos_sale_items WHERE sale_id = ? ORDER BY id ASC'
    : "SELECT oi.product_name, oi.quantity, oi.total_price, COALESCE(NULLIF(TRIM(p.category), ''), 'Medicine') AS category
       FROM order_items oi
       LEFT JOIN products p ON p.id = oi.product_id
       WHERE oi.order_id = ?
       ORDER BY oi.id ASC";
$itemStmt = $conn->prepare($itemSql);
if (!$itemStmt) {
    error_log('Physical POS receipt items lookup preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load receipt items.');
}
$itemLookupId = $isPosSale ? $saleId : $orderId;
$itemStmt->bind_param('i', $itemLookupId);
if (!$itemStmt->execute()) {
    error_log('Physical POS receipt items lookup failed: ' . $itemStmt->error);
    $itemStmt->close();
    http_response_code(500);
    exit('Unable to load receipt items.');
}
$itemResult = $itemStmt->get_result();
$items = [];
$subtotal = 0.0;
while ($item = $itemResult->fetch_assoc()) {
    $item['quantity'] = max(1, (int)$item['quantity']);
    $item['line_total'] = (float)$item['total_price'];
    $item['unit_price'] = $item['line_total'] / $item['quantity'];
    $subtotal += $item['line_total'];
    $items[] = $item;
}
$itemStmt->close();
if (!$items) {
    http_response_code(404);
    exit('Receipt items not found.');
}

$discountAmount = strtolower((string)($order['discount_status'] ?? '')) === 'approved'
    ? max(0, (float)$order['discount_amount'])
    : 0.0;
$totalAmount = (float)$order['total_amount'];
$paymentReference = (string)($order['payment_reference'] ?? '');
$cashReceived = $totalAmount;
$changeDue = 0.0;
if (preg_match('/POS cash ([0-9]+(?:\.[0-9]+)?); change ([0-9]+(?:\.[0-9]+)?)/', $paymentReference, $cashMatch)) {
    $cashReceived = (float)$cashMatch[1];
    $changeDue = (float)$cashMatch[2];
}
$cashierName = 'Pharmacist';
$cashierStmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(full_name), ''), username) AS cashier_name FROM users WHERE id = ? LIMIT 1");
if (!$cashierStmt) {
    error_log('Physical POS receipt cashier lookup preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load cashier information.');
}
$cashierId = (int)($order['seller_id'] ?? 0);
$cashierStmt->bind_param('i', $cashierId);
if (!$cashierStmt->execute()) {
    error_log('Physical POS receipt cashier lookup failed: ' . $cashierStmt->error);
    $cashierStmt->close();
    http_response_code(500);
    exit('Unable to load cashier information.');
}
$cashier = $cashierStmt->get_result()->fetch_assoc();
$cashierStmt->close();
if (!empty($cashier['cashier_name'])) {
    $cashierName = trim((string)$cashier['cashier_name']);
}
$receiptNumber = $isPosSale
    ? 'POS-' . str_pad((string)$saleId, 8, '0', STR_PAD_LEFT)
    : formatOrderNumber($conn, $orderId);
$receiptDate = (string)($order['order_date'] ?? '');
if ($receiptDate === '' || strtotime($receiptDate) === false) {
    $receiptDate = date('Y-m-d H:i:s');
}
$discountLabel = ($order['discount_type'] ?? '') === 'pwd'
    ? 'PWD Discount (20%)'
    : 'Senior Citizen Discount (20%)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= htmlspecialchars($receiptNumber, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root { --red:#e51c23; --ink:#151b22; --muted:#53606d; --line:#b9c1c7; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:start center; padding:18px; background:#f3f6f8; color:var(--ink); font-family:"Segoe UI",Arial,sans-serif; }
        .receipt { position:relative; width:min(100%,360px); min-height:720px; padding:20px 18px 32px; background:#fff; border:1px solid #e1e6ea; box-shadow:0 8px 24px rgba(30,48,60,.12); }
        .receipt::after { content:""; position:absolute; right:-1px; bottom:-8px; left:-1px; height:9px; background:linear-gradient(135deg,#fff 5px,transparent 5px) 0 0/10px 10px repeat-x; }
        .brand { padding-bottom:12px; border-bottom:1px solid var(--ink); text-align:center; }
        .logo { display:block; width:58px; height:58px; margin:0 auto 5px; object-fit:contain; }
        .brand-name { display:block; color:var(--red); font-size:21px; line-height:1.1; font-weight:800; }
        .tagline { display:block; color:var(--ink); font-size:10px; line-height:1.15; font-weight:700; letter-spacing:.1px; }
        .contact { margin-top:9px; color:var(--ink); font-size:11px; line-height:1.45; letter-spacing:.1px; }
        .receipt-meta { display:grid; grid-template-columns:1fr auto; gap:5px; padding:10px 0 9px; border-bottom:1px solid var(--line); font-size:11px; }
        .receipt-meta strong { font-size:12px; }
        .meta-right { text-align:right; }
        table { width:100%; border-collapse:collapse; font-size:11px; }
        th { padding:10px 0 8px; border-bottom:1px solid var(--line); text-align:left; font-size:10px; }
        th:not(:first-child), td:not(:first-child) { text-align:right; }
        td { padding:8px 0; border-bottom:1px solid #edf0f2; vertical-align:top; }
        .product-name { font-weight:700; }
        .product-type { display:block; margin-top:2px; font-size:10px; }
        .numeric { white-space:nowrap; }
        .line-total { color:#e52332; font-weight:700; }
        .summary { padding:8px 0 5px; border-bottom:1px solid var(--line); }
        .summary-line { display:flex; justify-content:flex-end; gap:42px; padding:3px 0; font-size:11px; }
        .summary-line span:last-child { min-width:46px; text-align:right; }
        .total-line { display:flex; justify-content:flex-end; align-items:center; gap:42px; padding:8px 0 4px; font-size:18px; font-weight:800; }
        .total-line strong { min-width:80px; color:var(--red); font-size:20px; text-align:right; }
        .payment { padding:10px 0; border-bottom:1px solid var(--line); font-size:11px; }
        .payment strong { display:block; margin-bottom:5px; font-size:10px; }
        .payment-amount { float:right; }
        .cash-detail { display:flex; justify-content:space-between; gap:10px; margin-top:4px; color:var(--muted); font-size:10px; }
        .footer { padding-top:22px; text-align:center; }
        .footer p { margin:0 0 7px; font-size:10px; line-height:1.45; }
        .footer a { color:var(--red); font-weight:700; text-decoration:none; }
        .actions { display:flex; justify-content:center; gap:10px; margin-top:18px; }
        .actions a, .actions button { border:1px solid var(--red); border-radius:5px; padding:8px 12px; color:var(--red); background:#fff; font-size:11px; font-weight:700; text-decoration:none; cursor:pointer; }
        @media print { body { padding:0; background:#fff; } .receipt { width:100%; border:0; box-shadow:none; } .receipt::after { display:none; } .actions { display:none; } }
    </style>
</head>
<body>
    <main class="receipt">
        <header class="brand">
            <img class="logo" src="../user/images/logo.png" alt="Josefina Farmacia logo">
            <span class="brand-name">Josefina Farmacia</span>
            <span class="tagline">QUALITY AND AFFORDABLE BRANDED<br>&amp; GENERIC MEDICINES</span>
            <div class="contact">123 Main St., Manila, Philippines<br>Tel: (02) 1234-5678</div>
        </header>
        <section class="receipt-meta">
            <div><strong>RECEIPT <?= htmlspecialchars($receiptNumber, ENT_QUOTES, 'UTF-8') ?></strong><br>Cashier: <?= htmlspecialchars($cashierName, ENT_QUOTES, 'UTF-8') ?><br>Customer: <?= htmlspecialchars((string)$order['fullname'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="meta-right"><?= date('d M Y', strtotime($receiptDate)) ?><br><?= date('h:i A', strtotime($receiptDate)) ?></div>
        </section>
        <table>
            <thead><tr><th>ITEM</th><th>QTY</th><th>PRICE</th><th>TOTAL</th></tr></thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><span class="product-name"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></span><span class="product-type"><?= htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td class="numeric"><?= number_format($item['quantity']) ?></td>
                    <td class="numeric">₱<?= number_format($item['unit_price'], 2) ?></td>
                    <td class="numeric line-total">₱<?= number_format($item['line_total'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <section class="summary">
            <div class="summary-line"><span>Subtotal</span><span>₱<?= number_format($subtotal, 2) ?></span></div>
            <div class="summary-line"><span><?= $discountAmount > 0 ? htmlspecialchars($discountLabel, ENT_QUOTES, 'UTF-8') : 'Discount' ?></span><span>-₱<?= number_format($discountAmount, 2) ?></span></div>
            <div class="summary-line"><span>VAT (0%)</span><span>₱0.00</span></div>
        </section>
        <div class="total-line"><span>TOTAL</span><strong>₱<?= number_format($totalAmount, 2) ?></strong></div>
        <div class="payment"><strong>PAYMENT</strong>Cash<span class="payment-amount">₱<?= number_format($totalAmount, 2) ?></span>
            <div class="cash-detail"><span>Cash received</span><span>₱<?= number_format($cashReceived, 2) ?></span></div>
            <div class="cash-detail"><span>Change</span><span>₱<?= number_format($changeDue, 2) ?></span></div>
        </div>
        <footer class="footer">
            <p>Thank you for your purchase!<br>Please keep this receipt for reference.</p>
            <p>Receipt verification / e-receipt<br>Visit<br><a href="https://www.josefinafarmacia.ph/verify">www.josefinafarmacia.ph/verify</a></p>
        </footer>
        <nav class="actions" aria-label="Receipt actions">
            <button type="button" onclick="window.print()">▣ &nbsp; Print Receipt</button>
            <a href="<?= $isPosSale ? 'inventory_batches.php?tab=sales#recent-sales' : 'admin_orders.php?q=' . rawurlencode($receiptNumber) ?>">← &nbsp; <?= $isPosSale ? 'Back to Batch Sales' : 'Back to Orders' ?></a>
        </nav>
    </main>
</body>
</html>
