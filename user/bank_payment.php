<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/discount_id_upload.php';

if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
    header('Location: login.php');
    exit();
}

$discount_type = trim((string) ($_POST['discount_type'] ?? ''));
if (!in_array($discount_type, ['', 'senior_citizen', 'pwd'], true)) {
    http_response_code(400);
    exit('Please choose a valid discount type.');
}
if ($discount_type !== '') {
    http_response_code(400);
    exit('Senior Citizen and PWD discounts are only available with Cash on Delivery.');
}
clearStagedDiscountIdUpload();

$checkout_fields = [
    'fullname' => trim((string) ($_POST['fullname'] ?? '')),
    'contact_number' => trim((string) ($_POST['contact_number'] ?? '')),
    'address' => trim((string) ($_POST['address'] ?? '')),
    'delivery_latitude' => trim((string) ($_POST['delivery_latitude'] ?? '')),
    'delivery_longitude' => trim((string) ($_POST['delivery_longitude'] ?? '')),
    'buy_now_product_id' => (int) ($_POST['buy_now_product_id'] ?? 0),
    'buy_now_quantity' => max(1, (int) ($_POST['buy_now_quantity'] ?? 1)),
    'buy_now_unit' => ($_POST['buy_now_unit'] ?? 'piece') === 'bottle' ? 'bottle' : 'piece',
    'delivery_type' => trim((string) ($_POST['delivery_type'] ?? '')),
    'order_total' => (float) ($_POST['order_total'] ?? 0),
    'coupon_code' => strtoupper(trim((string) ($_POST['coupon_code'] ?? ''))),
    'discount_type' => $discount_type,
];
$amount_to_pay = number_format($checkout_fields['order_total'], 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InstaPay Payment</title>
    <style>
        body { margin: 0; padding: 35px 16px; font-family: Arial, sans-serif; background: #f1f7fb; color: #17324d; }
        .payment-card { width: min(420px, 100%); margin: auto; padding: 22px; background: #fff; border: 1px solid #d7eafa; border-radius: 16px; box-shadow: 0 10px 26px rgba(20,70,110,.10); text-align: center; }
        .brand { display: flex; justify-content: space-between; align-items: center; color: #0c3d73; font-size: 22px; font-weight: 800; }
        .secure { font-size: 10px; border: 1px solid #cfe8ff; border-radius: 12px; padding: 5px 8px; color: #0c3d73; }
        .qr { display: block; width: 190px; height: 190px; object-fit: contain; margin: 20px auto; border: 1px solid #cfe8ff; border-radius: 8px; }
        .proof { display: block; margin-top: 18px; padding: 14px; border: 1px dashed #a7c8e5; border-radius: 9px; text-align: left; background: #fff; }
        .proof input { width: 100%; margin-top: 8px; }
        .transaction-id { display: block; margin-top: 16px; text-align: left; color: #17324d; font-weight: 700; }
        .transaction-id input { width: 100%; margin-top: 8px; padding: 11px; border: 1px solid #cfe0ee; border-radius: 8px; font: inherit; box-sizing: border-box; }
        .place-order { width: 100%; margin-top: 18px; padding: 14px; border: 0; border-radius: 9px; background: #4a1018; color: #fff; font-weight: 700; cursor: pointer; }
        .back { display: block; margin-top: 12px; color: #4a1018; text-align: center; font-weight: 700; }
    </style>
</head>
<body>
    <main class="payment-card">
        <div class="brand"><span>InstaPay Transfer</span><span class="secure">Secure Payment</span></div>
        <p>Scan this QR code using your banking app.</p>
        <p><strong>Amount to Pay: ₱<?= htmlspecialchars($amount_to_pay, ENT_QUOTES, 'UTF-8') ?></strong></p>
        <p><strong>Josefina Farmacia</strong></p>
        <img class="qr" src="images/bpi_qr.jpg" alt="Josefina Farmacia BPI QR code">
        <p>Upload your proof of payment after completing the transfer.</p>
        <form method="POST" action="place_order.php" enctype="multipart/form-data">
            <?php foreach ($checkout_fields as $field_name => $field_value): ?>
                <input type="hidden" name="<?= htmlspecialchars($field_name, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $field_value, ENT_QUOTES, 'UTF-8') ?>">
            <?php endforeach; ?>
            <input type="hidden" name="payment_method" value="bank">
            <label class="transaction-id">
                Bank Transaction ID
                <input type="text" name="payment_transaction_id" maxlength="100" required autocomplete="off" placeholder="Enter the transaction ID shown in your receipt">
            </label>
            <label class="proof">
                Choose File
                <input type="file" name="payment_proof" accept="image/png,image/jpeg,image/jpg" required>
            </label>
            <button type="submit" class="place-order">CONFIRM PAYMENT</button>
        </form>
        <a class="back" href="javascript:history.back()">Back</a>
    </main>
    <script src="back-navigation.js"></script>
</body>
</html>
