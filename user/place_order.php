<?php
session_start();
include 'config.php';
require_once 'functions.php';
require_once __DIR__ . '/discount_id_upload.php';
require_once __DIR__ . '/discount_cooldown.php';
require_once __DIR__ . '/delivery_pricing.php';
require_once __DIR__ . '/../rider/delivery_helpers.php';
require_once __DIR__ . '/voucher_helpers.php';

function returnToCheckoutWithError(string $message, int $productId = 0, int $quantity = 1, string $unit = 'piece', string $payment = '', string $delivery = ''): void
{
    $_SESSION['checkout_error'] = $message;
    $query = [];
    if ($productId > 0) {
        $query['product_id'] = $productId;
        $query['quantity'] = max(1, $quantity);
        $query['unit'] = $unit === 'bottle' ? 'bottle' : 'piece';
    }
    if (in_array($payment, ['cod', 'gcash', 'bank'], true)) {
        $query['payment'] = $payment;
    }
    if (in_array($delivery, ['lalamove', 'pickup', 'self_pickup', 'self-pickup'], true)) {
        $query['delivery'] = $delivery;
    }
    header('Location: receipt.php' . ($query ? '?' . http_build_query($query) : ''));
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
try {
    ensureDiscountCooldownSchema($conn);
} catch (RuntimeException $exception) {
    error_log('Order discount schema preparation failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to prepare discount verification.');
}
ensureRiderDeliverySchema($conn);
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS prescription_required TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("CREATE TABLE IF NOT EXISTS prescription_product_access (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    granted_by INT NULL,
    prescription_id INT NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    quantity_limit INT UNSIGNED NOT NULL DEFAULT 0,
    quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_prescription_product_user (product_id, user_id),
    KEY idx_prescription_access_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS prescription_id INT NULL");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_limit INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("UPDATE prescription_product_access ppa
    JOIN products p ON p.id = ppa.product_id
    SET ppa.quantity_limit = GREATEST(COALESCE(p.quantity, 0), 0), ppa.quantity_purchased = 0, ppa.expires_at = NULL
    WHERE ppa.quantity_limit = 0 AND ppa.expires_at IS NOT NULL");
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart_screen.php');
    exit();
}
$buy_now_product_id = (int)($_POST['buy_now_product_id'] ?? 0);
$buy_now_quantity = max(1, (int)($_POST['buy_now_quantity'] ?? 1));
$buy_now_unit = ($_POST['buy_now_unit'] ?? 'piece') === 'bottle' ? 'bottle' : 'piece';
$selectedCartProductIds = [];
if ($buy_now_product_id <= 0) {
    $storedProductIds = $_SESSION['checkout_cart_product_ids'] ?? [];
    if (is_array($storedProductIds)) {
        foreach ($storedProductIds as $storedProductId) {
            $productId = (int)$storedProductId;
            if ($productId > 0) {
                $selectedCartProductIds[] = $productId;
            }
        }
    }
    $selectedCartProductIds = array_values(array_unique($selectedCartProductIds));
    if ($selectedCartProductIds === []) {
        $_SESSION['checkout_error'] = 'Select the products you want to order from your cart.';
        header('Location: cart_screen.php');
        exit();
    }
}
$coupon_code = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
if ($coupon_code === '') {
    unset($_SESSION['checkout_voucher_code']);
} else {
    $_SESSION['checkout_voucher_code'] = $coupon_code;
}
$payment_method = strtolower(trim((string)($_POST['payment_method'] ?? '')));
$delivery_type_input = strtolower(trim((string)($_POST['delivery_type'] ?? '')));
$discount_type_input = strtolower(trim((string)($_POST['discount_type'] ?? '')));
$allowedDeliveryTypes = ['lalamove', 'pickup', 'self_pickup', 'self-pickup'];
if (!in_array($payment_method, ['cod', 'gcash', 'bank'], true)) {
    http_response_code(400);
    exit('Please select a valid payment method.');
}
if (!in_array($delivery_type_input, $allowedDeliveryTypes, true)) {
    http_response_code(400);
    exit('Please select a valid delivery method.');
}
if ($delivery_type_input === 'lalamove') {
    $riderAvailability = getRiderAvailability($conn);
    if ($riderAvailability['error'] !== null) {
        error_log('Unable to confirm rider availability before order placement.');
        returnToCheckoutWithError(
            'Unable to confirm rider availability right now. Please choose Self-Pickup or try again.',
            $buy_now_product_id,
            $buy_now_quantity,
            $buy_now_unit,
            $payment_method,
            $delivery_type_input
        );
    }
    if (!$riderAvailability['available']) {
        returnToCheckoutWithError(
            'No rider is online right now. Please choose Self-Pickup.',
            $buy_now_product_id,
            $buy_now_quantity,
            $buy_now_unit,
            $payment_method,
            $delivery_type_input
        );
    }
}
if (!in_array($discount_type_input, ['', 'senior_citizen', 'pwd'], true)) {
    http_response_code(400);
    exit('Please select a valid discount type.');
}
if ($discount_type_input !== '' && $payment_method !== 'cod') {
    clearStagedDiscountIdUpload();
    http_response_code(400);
    exit('Senior Citizen and PWD discounts are only available with Cash on Delivery.');
}
if ($discount_type_input !== '' && $coupon_code !== '') {
    http_response_code(400);
    exit('Only one discount can be used per order.');
}
if ($discount_type_input !== '') {
    try {
        $discountCooldownExpiry = getDiscountCooldownExpiry($conn, $user_id);
    } catch (RuntimeException $exception) {
        error_log('Order discount eligibility check failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('Unable to check discount eligibility.');
    }
    if ($discountCooldownExpiry !== null) {
        clearStagedDiscountIdUpload();
        returnToCheckoutWithError(
            'You have already used a discount. It will be available again on '
                . $discountCooldownExpiry . '.',
            $buy_now_product_id,
            $buy_now_quantity,
            $buy_now_unit,
            $payment_method,
            $delivery_type_input
        );
    }
}
$delivery_type = $delivery_type_input;
$shipping_fee = 0.0;
$payment_transaction_id_input = trim((string)($_POST['payment_transaction_id'] ?? ''));
$payment_proof = '';

if (!$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00")) {
    die('Unable to prepare order shipping fee: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}
if (!$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS pickup_deadline DATETIME NULL")) {
    die('Unable to prepare self pick-up deadline: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}
if (!$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'")) {
    die('Unable to prepare order payment status: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}
$pickupDeadlineSql = 'NULL';

if ($payment_method === 'gcash' || $payment_method === 'bank') {
    if ($payment_transaction_id_input === '' || strlen($payment_transaction_id_input) > 100) {
        die('Please enter a valid transaction ID of no more than 100 characters.');
    }
    if (
        !isset($_FILES['payment_proof']) ||
        $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK ||
        !is_uploaded_file($_FILES['payment_proof']['tmp_name'])
    ) {
        die('Please upload your GCash proof of payment before placing the order.');
    }

    $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $file_type = mime_content_type($_FILES['payment_proof']['tmp_name']);
    if (!isset($allowed_types[$file_type]) || (int) $_FILES['payment_proof']['size'] > 5 * 1024 * 1024) {
        die('The GCash proof must be a JPG or PNG image up to 5 MB.');
    }

    $payment_proof = 'payment_proof_' . bin2hex(random_bytes(16)) . '.' . $allowed_types[$file_type];
}
$payment_transaction_id = $conn->real_escape_string($payment_transaction_id_input);

$proof_column = $conn->query("SHOW COLUMNS FROM orders LIKE 'payment_proof'");
if ($proof_column && $proof_column->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN payment_proof VARCHAR(255) NULL AFTER payment_method");
}
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_transaction_id VARCHAR(100) NULL");
if (
    !$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_type VARCHAR(30) NULL")
    || !$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_id_proof VARCHAR(255) NULL")
    || !$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_status VARCHAR(30) NOT NULL DEFAULT 'not_applicable'")
    || !$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00")
    || !$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(50) NULL")
    || !$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00")
) {
    die('Unable to prepare discount verification storage: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}
if (!$conn->query(
    "CREATE TABLE IF NOT EXISTS order_discount_ids (
        order_id INT NOT NULL PRIMARY KEY,
        image_data LONGBLOB NOT NULL,
        image_mime VARCHAR(50) NOT NULL,
        uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_order_discount_ids_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
)) {
    die('Unable to prepare discount ID database storage: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}

$user_result = $conn->query(
    "SELECT * FROM users WHERE id = $user_id"
);

if (!$user_result || $user_result->num_rows === 0) {
    die('User not found!');
}

$user = $user_result->fetch_assoc();
$fullname_input = trim($_POST['fullname'] ?? '');
$fullname = $conn->real_escape_string($fullname_input !== '' ? $fullname_input : ($user['full_name'] ?: $user['username']));
$contact_number = $conn->real_escape_string(trim($_POST['contact_number'] ?? ($user['phone'] ?? '')));
$address_input = trim((string)($_POST['address'] ?? ''));
if ($address_input === '') {
    $address_input = trim((string)($user['address'] ?? ''));
}
if ($address_input === '') {
    $saved_address_stmt = $conn->prepare(
        'SELECT address FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1'
    );
    if (!$saved_address_stmt) {
        die('Unable to verify your delivery address: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
    }
    $saved_address_stmt->bind_param('i', $user_id);
    if (!$saved_address_stmt->execute()) {
        die('Unable to verify your delivery address: ' . htmlspecialchars($saved_address_stmt->error, ENT_QUOTES, 'UTF-8'));
    }
    $saved_address_row = $saved_address_stmt->get_result()->fetch_assoc();
    $saved_address_stmt->close();
    $address_input = trim((string)($saved_address_row['address'] ?? ''));
}
if ($address_input === '') {
    returnToCheckoutWithError(
        'Please set or select a delivery address before placing your order.',
        $buy_now_product_id,
        $buy_now_quantity,
        $buy_now_unit,
        $payment_method,
        $delivery_type_input
    );
}
$address = $conn->real_escape_string($address_input);

$deliveryLatitude = null;
$deliveryLongitude = null;
if ($delivery_type_input === 'lalamove') {
    $latitudeInput = trim((string)($_POST['delivery_latitude'] ?? ''));
    $longitudeInput = trim((string)($_POST['delivery_longitude'] ?? ''));
    if (!is_numeric($latitudeInput) || !is_numeric($longitudeInput)) {
        $_SESSION['checkout_state'] = [
            'address' => $address_input,
            'delivery_latitude' => '',
            'delivery_longitude' => ''
        ];
        returnToCheckoutWithError(
            'Please pin your delivery location on the map before placing a rider delivery order.',
            $buy_now_product_id,
            $buy_now_quantity,
            $buy_now_unit,
            $payment_method,
            $delivery_type_input
        );
    }

    $deliveryLatitude = (float)$latitudeInput;
    $deliveryLongitude = (float)$longitudeInput;
    if (!is_finite($deliveryLatitude) || !is_finite($deliveryLongitude)
        || $deliveryLatitude < -90 || $deliveryLatitude > 90
        || $deliveryLongitude < -180 || $deliveryLongitude > 180) {
        http_response_code(400);
        exit('The selected delivery location is invalid.');
    }
    try {
        $distanceKm = deliveryRoadDistanceKm($deliveryLatitude, $deliveryLongitude);
    } catch (RuntimeException $exception) {
        error_log('Order delivery road-distance lookup failed: ' . $exception->getMessage());
        $_SESSION['checkout_state'] = [
            'address' => $address_input,
            'delivery_latitude' => number_format($deliveryLatitude, 7, '.', ''),
            'delivery_longitude' => number_format($deliveryLongitude, 7, '.', '')
        ];
        returnToCheckoutWithError(
            'Unable to calculate the road distance right now. Please try placing your order again.',
            $buy_now_product_id,
            $buy_now_quantity,
            $buy_now_unit,
            $payment_method,
            $delivery_type_input
        );
    }
    $calculatedFee = deliveryFeeForDistance($distanceKm);
    if ($calculatedFee === null) {
        $_SESSION['checkout_state'] = [
            'address' => $address_input,
            'delivery_latitude' => number_format($deliveryLatitude, 7, '.', ''),
            'delivery_longitude' => number_format($deliveryLongitude, 7, '.', '')
        ];
        returnToCheckoutWithError(
            'Delivery is unavailable beyond 10 km from the store. Choose self-pickup or update your delivery location.',
            $buy_now_product_id,
            $buy_now_quantity,
            $buy_now_unit,
            $payment_method,
            $delivery_type_input
        );
    }
    $shipping_fee = $calculatedFee;
}

$cart_query = $buy_now_product_id > 0
    ? "SELECT products.id AS product_id, " . ($buy_now_unit === 'bottle' ? "$buy_now_quantity AS box, 0 AS pieces" : "0 AS box, $buy_now_quantity AS pieces") . ",
        products.product_name,
        COALESCE(NULLIF(products.price_piece, 0), products.price, 0) AS price_piece,
        COALESCE(
            COALESCE(NULLIF(products.price_piece, 0), products.price, 0)
                * COALESCE(NULLIF(products.pieces_per_box, 0), 12) * 0.98,
            0
        ) AS price_box,
        products.pieces_per_box,
        products.prescription_required,
        products.category
    FROM products
    WHERE products.id = $buy_now_product_id"
    : "SELECT cart.product_id, cart.box, cart.pieces,
        products.product_name,
        COALESCE(NULLIF(products.price_piece, 0), products.price, 0) AS price_piece,
        COALESCE(
            COALESCE(NULLIF(products.price_piece, 0), products.price, 0)
                * COALESCE(NULLIF(products.pieces_per_box, 0), 12) * 0.98,
            0
        ) AS price_box,
        products.pieces_per_box,
        products.prescription_required,
        products.category
    FROM cart
    JOIN products ON cart.product_id = products.id
    WHERE cart.user_id = $user_id
      AND cart.product_id IN (" . implode(',', $selectedCartProductIds) . ")
      AND (cart.box > 0 OR cart.pieces > 0)";
$cart = $conn->query($cart_query);

$expectedCartRows = $buy_now_product_id > 0 ? 1 : count($selectedCartProductIds);
if (!$cart || $cart->num_rows !== $expectedCartRows) {
    $_SESSION['checkout_error'] = 'Some selected products are no longer available in your cart. Please review your selection.';
    unset($_SESSION['checkout_cart_product_ids']);
    header('Location: cart_screen.php');
    exit();
}

$orderRows = [];
while ($row = $cart->fetch_assoc()) {
    $orderRows[] = $row;
}

if (!$conn->begin_transaction()) {
    die('Unable to start order transaction.');
}

$proofPath = '';
$discountIdPath = '';
$discountIdWasStaged = false;
try {
    if ($discount_type_input !== '') {
        if ($payment_method === 'gcash' || $payment_method === 'bank') {
            $discountIdPath = getStagedDiscountIdUpload($user_id, $discount_type_input) ?? '';
            $discountIdWasStaged = $discountIdPath !== '';
            if ($discountIdPath === '') {
                throw new RuntimeException('Please return to checkout and upload your valid discount ID.');
            }
        } else {
            clearStagedDiscountIdUpload();
            $discountIdPath = storeDiscountIdUpload($_FILES['discount_id_proof'] ?? [], $user_id);
        }
    } elseif ($payment_method === 'cod') {
        clearStagedDiscountIdUpload();
    }

    $discount_type = $conn->real_escape_string($discount_type_input);
    $discount_id_proof = $conn->real_escape_string($discountIdPath);
    $discount_status = $discount_type_input === '' ? 'not_applicable' : 'pending_verification';

    $productLock = $conn->prepare(
        'SELECT product_name, status, price, price_piece, price_box, pieces, box,
                quantity, pieces_per_box, prescription_required, category
         FROM products WHERE id = ? FOR UPDATE'
    );
    $batchSelect = $conn->prepare(
        'SELECT id, current_quantity, expiration_date, recalled FROM product_batches
         WHERE product_id = ?
         ORDER BY expiration_date ASC, id ASC FOR UPDATE'
    );
    $prescriptionAccessLock = $conn->prepare(
        'SELECT id, prescription_id, quantity_limit, quantity_purchased FROM prescription_product_access
         WHERE product_id = ? AND user_id = ? AND quantity_limit > quantity_purchased
           AND (expires_at IS NULL OR expires_at >= NOW())
         ORDER BY granted_at DESC LIMIT 1 FOR UPDATE'
    );
    if (!$productLock || !$batchSelect || !$prescriptionAccessLock) {
        throw new Exception('Unable to verify product inventory: ' . $conn->error);
    }

    $validatedRows = [];
    foreach ($orderRows as $row) {
        $productId = (int)$row['product_id'];
        $boxQty = max(0, (int)$row['box']);
        $pieceQty = max(0, (int)$row['pieces']);
        $productLock->bind_param('i', $productId);
        if (!$productLock->execute()) {
            throw new Exception('Unable to verify product inventory: ' . $productLock->error);
        }
        $product = $productLock->get_result()->fetch_assoc();
        if (!$product) {
            throw new Exception('A product in your cart no longer exists. Please remove it and try again.');
        }
        $isPrescriptionProduct = !empty($product['prescription_required'])
            || strtolower(trim((string)($product['category'] ?? ''))) === 'prescription medicine';
        $prescriptionAccessId = 0;
        if ($isPrescriptionProduct) {
            $prescriptionAccessLock->bind_param('ii', $productId, $user_id);
            if (!$prescriptionAccessLock->execute()) {
                throw new Exception('Unable to verify the prescription quantity limit: ' . $prescriptionAccessLock->error);
            }
            $prescriptionAccess = $prescriptionAccessLock->get_result()->fetch_assoc();
            if (!$prescriptionAccess) {
                throw new Exception(
                    $product['product_name']
                    . ' has expired prescription access or no remaining quantity under your approved prescription. Please contact the pharmacy in Customer Chat.'
                );
            }
            $prescriptionAccessId = (int)$prescriptionAccess['id'];
            $prescriptionQuantityRemaining = (int)$prescriptionAccess['quantity_limit'] - (int)$prescriptionAccess['quantity_purchased'];
        }
        if ($isPrescriptionProduct && strtolower(trim((string)$product['status'])) !== 'active') {
            throw new Exception(
                $product['product_name']
                . ' is a prescription product and is not currently available for ordering. '
                . 'It stays hidden from the customer catalog; contact the pharmacy for approved access.'
            );
        }
        if (strtolower(trim((string)$product['status'])) !== 'active') {
            throw new Exception(
                $product['product_name']
                . ' is currently unavailable for ordering. Please choose an active product from the catalog.'
            );
        }

        $piecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
        $totalQty = ($boxQty * $piecesPerBox) + $pieceQty;
        if ($isPrescriptionProduct && $totalQty > $prescriptionQuantityRemaining) {
            throw new Exception(
                $product['product_name']
                . ' exceeds the quantity limit set by the pharmacist. Only '
                . max(0, $prescriptionQuantityRemaining)
                . ' unit(s) remain for this prescription.'
            );
        }
        $availableStock = ((int)($product['box'] ?? 0) * $piecesPerBox)
            + (int)($product['pieces'] ?? 0);
        if ($totalQty < 1 || $availableStock < $totalQty) {
            throw new Exception('There is not enough stock for ' . $product['product_name'] . '.');
        }

        $pricePiece = (float)($product['price_piece'] ?? 0);
        if ($pricePiece <= 0) {
            $pricePiece = (float)($product['price'] ?? 0);
        }
        $priceBox = (float)($product['price_box'] ?? 0);
        $priceBox = round($pricePiece * $piecesPerBox * 0.98, 2);
        if ($pricePiece <= 0 || $priceBox <= 0) {
            throw new Exception('A product in your cart has no valid price.');
        }

        $batchSelect->bind_param('i', $productId);
        if (!$batchSelect->execute()) {
            throw new Exception('Unable to verify batch stock: ' . $batchSelect->error);
        }
        $batchResult = $batchSelect->get_result();
        $batches = [];
        $batchStock = 0;
        $hasBatches = false;
        while ($batch = $batchResult->fetch_assoc()) {
            $hasBatches = true;
            if ((int)$batch['recalled'] === 1
                || $batch['expiration_date'] < date('Y-m-d')
                || (int)$batch['current_quantity'] <= 0) {
                continue;
            }
            $batch['id'] = (int)$batch['id'];
            $batch['current_quantity'] = (int)$batch['current_quantity'];
            $batches[] = $batch;
            $batchStock += $batch['current_quantity'];
        }
        if ($hasBatches && $batchStock < $totalQty) {
            throw new Exception('There is not enough unexpired batch stock for ' . $product['product_name'] . '.');
        }

        $row['product_name'] = $product['product_name'];
        $row['price_piece'] = $pricePiece;
        $row['price_box'] = $priceBox;
        $row['pieces_per_box'] = $piecesPerBox;
        $row['stock_before'] = $availableStock;
        $row['total_qty'] = $totalQty;
        $row['total_price'] = ($boxQty * $priceBox) + ($pieceQty * $pricePiece);
        $row['batches'] = $batches;
        $row['prescription_access_id'] = $prescriptionAccessId;
        if ($row['total_price'] <= 0) {
            throw new Exception('A product in your cart has an invalid total price.');
        }
        $validatedRows[] = $row;
    }
    $productLock->close();
    $batchSelect->close();
    $prescriptionAccessLock->close();
    if (!$validatedRows) {
        throw new Exception('Your cart has no valid items.');
    }

    $validatedItemsSubtotal = array_sum(array_column($validatedRows, 'total_price'));
    $checkoutCoupon = null;
    $couponDiscountAmount = 0.0;
    if ($coupon_code !== '') {
        $checkoutCoupon = lookupCheckoutCoupon($conn, $user_id, $coupon_code, $validatedItemsSubtotal, true);
        $couponDiscountAmount = (float)$checkoutCoupon['discount_amount'];
    }

    $initial_payment_status = $payment_method === 'cod' ? 'unpaid' : 'pending';
    $initial_order_status = 'Pending';
    if (!$conn->query(
        "INSERT INTO orders
            (user_id, fullname, contact_number, address, payment_method, payment_proof, payment_transaction_id, payment_status, delivery_type, shipping_fee, delivery_latitude, delivery_longitude, pickup_deadline, discount_type, discount_id_proof, discount_status, coupon_code, coupon_discount_amount, status, order_date)
         VALUES
            ($user_id, '$fullname', '$contact_number', '$address', '$payment_method', '$payment_proof', '$payment_transaction_id', '$initial_payment_status', '$delivery_type', $shipping_fee, "
            . ($deliveryLatitude === null ? 'NULL' : number_format($deliveryLatitude, 7, '.', '')) . ', '
            . ($deliveryLongitude === null ? 'NULL' : number_format($deliveryLongitude, 7, '.', '')) . ", $pickupDeadlineSql, "
            . ($discount_type_input === '' ? 'NULL' : "'$discount_type'") . ", "
            . ($discountIdPath === '' ? 'NULL' : "'$discount_id_proof'") . ", '$discount_status', "
            . ($checkoutCoupon ? "'" . $conn->real_escape_string($coupon_code) . "'" : 'NULL') . ", "
            . number_format($couponDiscountAmount, 2, '.', '') . ", '$initial_order_status', NOW())"
    )) {
        throw new Exception('Order creation failed: ' . $conn->error);
    }

    $order_id = $conn->insert_id;
    unset($_SESSION['checkout_state']);
    if ($discountIdPath !== '') {
        $discountIdAbsolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $discountIdPath);
        $discountIdData = file_get_contents($discountIdAbsolutePath);
        $discountIdMime = mime_content_type($discountIdAbsolutePath);
        if ($discountIdData === false || !in_array($discountIdMime, ['image/jpeg', 'image/png'], true)) {
            throw new Exception('Unable to read the uploaded discount ID for database storage.');
        }
        $saveDiscountId = $conn->prepare(
            'INSERT INTO order_discount_ids (order_id, image_data, image_mime) VALUES (?, ?, ?)'
        );
        if (!$saveDiscountId) {
            throw new Exception('Unable to prepare discount ID database storage: ' . $conn->error);
        }
        $emptyBlob = null;
        $saveDiscountId->bind_param('ibs', $order_id, $emptyBlob, $discountIdMime);
        $saveDiscountId->send_long_data(1, $discountIdData);
        if (!$saveDiscountId->execute()) {
            throw new Exception('Unable to save the discount ID in the database: ' . $saveDiscountId->error);
        }
        $saveDiscountId->close();
        unset($discountIdData);
    }
    if ($payment_proof !== '') {
        $proof_directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payment_proofs';
        if (!is_dir($proof_directory) && !mkdir($proof_directory, 0755, true)) {
            throw new Exception('Unable to save payment proof.');
        }
        $proofPath = $proof_directory . DIRECTORY_SEPARATOR . $payment_proof;
        if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], $proofPath)) {
            throw new Exception('Unable to save payment proof.');
        }
    }
    $item_count = 0;
    $items_subtotal = 0.0;

    $order_item = $conn->prepare(
        "INSERT INTO order_items
            (order_id, product_id, product_name, box, pieces, price, total_price, quantity, box_qty, piece_qty)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stock_update = $conn->prepare(
        "UPDATE products SET quantity = ?, box = ?, pieces = ? WHERE id = ?"
    );
    $batch_update = $conn->prepare(
        'UPDATE product_batches SET current_quantity = current_quantity - ?
         WHERE id = ? AND current_quantity >= ? AND recalled = 0 AND expiration_date >= CURDATE()'
    );
    $movement_insert = $conn->prepare(
        "INSERT INTO batch_movements
            (batch_id, product_id, order_id, quantity, movement_type, reference, created_by)
         VALUES (?, ?, ?, ?, 'sold', ?, NULL)"
    );
    $prescriptionAccessUpdate = $conn->prepare(
        'UPDATE prescription_product_access
         SET quantity_purchased = quantity_purchased + ?
         WHERE id = ? AND quantity_purchased + ? <= quantity_limit'
    );
    if (!$order_item || !$stock_update || !$batch_update || !$movement_insert || !$prescriptionAccessUpdate) {
        throw new Exception('Unable to prepare order inventory updates: ' . $conn->error);
    }

    foreach ($validatedRows as $row) {
        $product_id = (int)$row['product_id'];
        $box_qty = max(0, (int)$row['box']);
        $piece_qty = max(0, (int)$row['pieces']);
        $pieces_per_box = max(1, (int)($row['pieces_per_box'] ?? 12));
        $total_qty = (int)$row['total_qty'];
        $total_price = (float)$row['total_price'];
        $order_item->bind_param(
            'iisiiddiii',
            $order_id,
            $product_id,
            $row['product_name'],
            $box_qty,
            $piece_qty,
            $total_price,
            $total_price,
            $total_qty,
            $box_qty,
            $piece_qty
        );
        if (!$order_item->execute()) {
            throw new Exception('Order item insert failed: ' . $order_item->error);
        }
        $prescriptionAccessId = (int)($row['prescription_access_id'] ?? 0);
        if ($prescriptionAccessId > 0) {
            $prescriptionAccessUpdate->bind_param('iii', $total_qty, $prescriptionAccessId, $total_qty);
            if (!$prescriptionAccessUpdate->execute() || $prescriptionAccessUpdate->affected_rows !== 1) {
                throw new Exception('The prescription quantity limit changed during checkout. Please try again.');
            }
            $accessStateStmt = $conn->prepare('SELECT prescription_id, quantity_limit, quantity_purchased FROM prescription_product_access WHERE id = ? LIMIT 1');
            if (!$accessStateStmt) {
                throw new Exception('Unable to confirm the remaining prescription quantity.');
            }
            $accessStateStmt->bind_param('i', $prescriptionAccessId);
            if (!$accessStateStmt->execute()) {
                throw new Exception('Unable to confirm the remaining prescription quantity: ' . $accessStateStmt->error);
            }
            $accessState = $accessStateStmt->get_result()->fetch_assoc();
            $accessStateStmt->close();
            if ($accessState
                && (int)$accessState['prescription_id'] > 0
                && (int)$accessState['quantity_purchased'] >= (int)$accessState['quantity_limit']) {
                $completePrescriptionStmt = $conn->prepare("UPDATE prescriptions SET status = 'completed'
                    WHERE id = ? AND user_id = ? AND status = 'approved'");
                if (!$completePrescriptionStmt) {
                    throw new Exception('Unable to mark the completed prescription quantity.');
                }
                $prescriptionId = (int)$accessState['prescription_id'];
                $completePrescriptionStmt->bind_param('ii', $prescriptionId, $user_id);
                if (!$completePrescriptionStmt->execute()) {
                    throw new Exception('Unable to mark the completed prescription quantity: ' . $completePrescriptionStmt->error);
                }
                $completePrescriptionStmt->close();
            }
        }

        $remainingStock = max(
            0,
            $row['stock_before'] - $total_qty
        );
        $remainingBoxes = intdiv($remainingStock, $pieces_per_box);
        $remainingPieces = $remainingStock % $pieces_per_box;
        $stock_update->bind_param('iiii', $remainingStock, $remainingBoxes, $remainingPieces, $product_id);
        if (!$stock_update->execute() || $stock_update->affected_rows !== 1) {
            throw new Exception('Stock update failed: ' . $stock_update->error);
        }

        $batchQuantityRemaining = $total_qty;
        foreach ($row['batches'] as $batch) {
            if ($batchQuantityRemaining <= 0) {
                break;
            }
            $allocatedQuantity = min($batchQuantityRemaining, (int)$batch['current_quantity']);
            $batch_id = (int)$batch['id'];
            $batch_update->bind_param('iii', $allocatedQuantity, $batch_id, $allocatedQuantity);
            if (!$batch_update->execute() || $batch_update->affected_rows !== 1) {
                throw new Exception('Batch stock update failed: ' . $batch_update->error);
            }
            $reference = 'Customer order ' . formatOrderNumber($conn, (int)$order_id);
            $movement_insert->bind_param('iiiis', $batch_id, $product_id, $order_id, $allocatedQuantity, $reference);
            if (!$movement_insert->execute()) {
                throw new Exception('Unable to record batch movement: ' . $movement_insert->error);
            }
            $batchQuantityRemaining -= $allocatedQuantity;
        }
        if ($batchQuantityRemaining > 0) {
            throw new Exception('Batch stock changed during checkout. Please try again.');
        }
        $item_count++;
        $items_subtotal += $total_price;
    }
    $order_item->close();
    $stock_update->close();
    $batch_update->close();
    $movement_insert->close();
    $prescriptionAccessUpdate->close();

    if ($item_count === 0) {
        throw new Exception('Cart has no valid items.');
    }

    $order_total_amount = max(0, $items_subtotal + $shipping_fee - $couponDiscountAmount);
    $save_order_total = $conn->prepare('UPDATE orders SET total_amount = ? WHERE id = ?');
    if (!$save_order_total) {
        throw new Exception('Order total statement failed: ' . $conn->error);
    }
    $save_order_total->bind_param('di', $order_total_amount, $order_id);
    if (!$save_order_total->execute()) {
        throw new Exception('Unable to save order total: ' . $save_order_total->error);
    }
    $save_order_total->close();

    if ($checkoutCoupon) {
        $couponUse = $checkoutCoupon['source'] === 'loyalty_vouchers'
            ? $conn->prepare(
                'UPDATE loyalty_vouchers SET used_count = used_count + 1
                 WHERE id = ? AND is_active = 1
                   AND (expires_at IS NULL OR expires_at > NOW())
                   AND (usage_limit IS NULL OR usage_limit <= 0 OR used_count < usage_limit)'
            )
            : $conn->prepare(
                'UPDATE coupons SET used_count = used_count + 1
                 WHERE id = ? AND (expiry_date IS NULL OR expiry_date > NOW())
                   AND (usage_limit IS NULL OR usage_limit <= 0 OR used_count < usage_limit)'
            );
        if (!$couponUse) {
            throw new Exception('Unable to prepare voucher redemption: ' . $conn->error);
        }
        $couponId = (int)$checkoutCoupon['id'];
        $couponUse->bind_param('i', $couponId);
        if (!$couponUse->execute() || $couponUse->affected_rows !== 1) {
            $couponUse->close();
            throw new Exception('This voucher is no longer available. Please try another code.');
        }
        $couponUse->close();

        if ($checkoutCoupon['assigned_user_id'] !== null) {
            $markVoucherUsed = $conn->prepare(
                "UPDATE customer_loyalty_vouchers
                 SET status = 'used', used_at = NOW(), order_id = ?
                 WHERE coupon_id = ? AND user_id = ? AND status = 'sent'"
            );
            if (!$markVoucherUsed) {
                throw new Exception('Unable to prepare customer voucher history: ' . $conn->error);
            }
            $markVoucherUsed->bind_param('iii', $order_id, $couponId, $user_id);
            if (!$markVoucherUsed->execute() || $markVoucherUsed->affected_rows !== 1) {
                $markVoucherUsed->close();
                throw new Exception('This customer voucher is no longer available.');
            }
            $markVoucherUsed->close();
        }
    }

    if ($buy_now_product_id > 0) {
        $delete_cart = $conn->prepare('DELETE FROM cart WHERE user_id = ? AND product_id = ?');
        if (!$delete_cart) {
            throw new Exception('Unable to clear the purchased item from your cart.');
        }
        $delete_cart->bind_param('ii', $user_id, $buy_now_product_id);
    } else {
        $selectedProductIdList = implode(',', $selectedCartProductIds);
        $delete_cart = $conn->prepare(
            "DELETE FROM cart WHERE user_id = ? AND product_id IN ($selectedProductIdList)"
        );
        if (!$delete_cart) {
            throw new Exception('Unable to remove the ordered products from your cart.');
        }
        $delete_cart->bind_param('i', $user_id);
    }
    if (!$delete_cart->execute()) {
        throw new Exception('Unable to clear your cart after checkout.');
    }
    $delete_cart->close();

    $formattedOrderNumber = formatOrderNumber($conn, (int)$order_id);
    addNotification($conn, "New order $formattedOrderNumber from $fullname");
    addNotification(
        $conn,
        "Your order $formattedOrderNumber has been placed successfully!",
        $user_id
    );

    writeAuditLog($conn, 'order_created', 'order', (int)$order_id, [
        'status' => 'pending',
        'item_count' => count($validatedRows),
    ]);
    if (!$conn->commit()) {
        throw new Exception('Unable to finish placing your order: ' . $conn->error);
    }

    foreach ($validatedRows as $row) {
        try {
            $stockAlert = capstoneCheckAndNotifyStockState($conn, (int)$row['product_id']);
            if (!$stockAlert['success']) {
                error_log('Post-order inventory alert failed for product #' . (int)$row['product_id'] . ': ' . $stockAlert['error']);
            }
        } catch (Throwable $exception) {
            error_log('Post-order inventory alert failed for product #' . (int)$row['product_id'] . ': ' . $exception->getMessage());
        }
    }

    try {
        $customerEmailQuery = $conn->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        if (!$customerEmailQuery) {
            error_log('Order confirmation recipient lookup could not be prepared: ' . $conn->error);
        } else {
            $customerEmailQuery->bind_param('i', $user_id);
            if (!$customerEmailQuery->execute()) {
                error_log('Order confirmation recipient lookup failed: ' . $customerEmailQuery->error);
            } else {
                $customer = $customerEmailQuery->get_result()->fetch_assoc();
                if ($customer && !sendOrderConfirmationEmail($conn, (int)$order_id, (string)$customer['email'], $fullname)) {
                    error_log('Order confirmation email was not sent for order #' . (int)$order_id . '.');
                }
            }
            $customerEmailQuery->close();
        }
    } catch (Throwable $exception) {
        error_log('Order confirmation email processing failed for order #' . (int)$order_id . ': ' . $exception->getMessage());
    }

    if ($discountIdWasStaged) {
        unset($_SESSION['checkout_discount_id_upload']);
    }
    unset($_SESSION['checkout_voucher_code']);
    unset($_SESSION['checkout_cart_product_ids']);
    if ($discountIdPath !== '') {
        removeDiscountIdUpload($discountIdPath);
    }
    header('Location: success.php?order_id=' . $order_id);
    exit();
} catch (Throwable $exception) {
    $conn->rollback();
    if ($proofPath !== '' && is_file($proofPath)) {
        unlink($proofPath);
    }
    if ($discountIdPath !== '' && !$discountIdWasStaged) {
        removeDiscountIdUpload($discountIdPath);
    }
    returnToCheckoutWithError(
        'Unable to place your order: ' . $exception->getMessage(),
        $buy_now_product_id,
        $buy_now_quantity,
        $buy_now_unit,
        $payment_method,
        $delivery_type_input
    );
}
?>
