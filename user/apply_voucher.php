<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/voucher_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in to apply a voucher.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !hash_equals((string)($_SESSION['voucher_csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
    exit();
}

$userId = (int)$_SESSION['user_id'];
$code = strtoupper(trim((string)($_POST['code'] ?? '')));
if ($code === '') {
    unset($_SESSION['checkout_voucher_code']);
    echo json_encode(['success' => true, 'removed' => true, 'message' => 'Voucher removed.']);
    exit();
}
if (strlen($code) > 50) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid voucher code.']);
    exit();
}

$productId = max(0, (int)($_POST['buy_now_product_id'] ?? 0));
if ($productId > 0) {
    $quantity = max(1, (int)($_POST['buy_now_quantity'] ?? 1));
    $unit = ($_POST['buy_now_unit'] ?? 'piece') === 'bottle' ? 'bottle' : 'piece';
    $subtotalQuery = $conn->prepare(
        'SELECT COALESCE(NULLIF(price_piece, 0), price, 0) AS price_piece,
                ROUND(COALESCE(NULLIF(price_piece, 0), price, 0)
                    * COALESCE(NULLIF(pieces_per_box, 0), 12) * 0.98, 2) AS price_box
         FROM products WHERE id = ? LIMIT 1'
    );
    if (!$subtotalQuery) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to calculate the order subtotal.']);
        exit();
    }
    $subtotalQuery->bind_param('i', $productId);
    if (!$subtotalQuery->execute()) {
        $subtotalQuery->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to calculate the order subtotal.']);
        exit();
    }
    $product = $subtotalQuery->get_result()->fetch_assoc();
    $subtotalQuery->close();
    if (!$product) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'The selected product is no longer available.']);
        exit();
    }
    $subtotal = $quantity * (float)($unit === 'bottle' ? $product['price_box'] : $product['price_piece']);
} else {
    $selectedCartProductIds = [];
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
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Select products from your cart before applying a voucher.']);
        exit();
    }
    $selectedProductIdList = implode(',', $selectedCartProductIds);
    $subtotalQuery = $conn->prepare(
        'SELECT COUNT(*) AS selected_count, COALESCE(SUM(
            cart.box * ROUND(COALESCE(NULLIF(products.price_piece, 0), products.price, 0)
                * COALESCE(NULLIF(products.pieces_per_box, 0), 12) * 0.98, 2)
            + cart.pieces * COALESCE(NULLIF(products.price_piece, 0), products.price, 0)
        ), 0) AS subtotal
         FROM cart JOIN products ON products.id = cart.product_id
        WHERE cart.user_id = ? AND cart.product_id IN (' . $selectedProductIdList . ')
          AND (cart.box > 0 OR cart.pieces > 0)'
    );
    if (!$subtotalQuery) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to calculate the order subtotal.']);
        exit();
    }
    $subtotalQuery->bind_param('i', $userId);
    if (!$subtotalQuery->execute()) {
        $subtotalQuery->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to calculate the order subtotal.']);
        exit();
    }
    $cartSubtotal = $subtotalQuery->get_result()->fetch_assoc();
    $subtotalQuery->close();
    if ((int)($cartSubtotal['selected_count'] ?? 0) !== count($selectedCartProductIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'One or more selected products are no longer in your cart. Refresh checkout and try again.']);
        exit();
    }
    $subtotal = (float)($cartSubtotal['subtotal'] ?? 0);
}

try {
    $coupon = lookupCheckoutCoupon($conn, $userId, $code, $subtotal);
    $_SESSION['checkout_voucher_code'] = $code;
    echo json_encode([
        'success' => true,
        'code' => $code,
        'discount_amount' => $coupon['discount_amount'],
        'message' => 'Voucher applied successfully.',
    ]);
} catch (RuntimeException $exception) {
    unset($_SESSION['checkout_voucher_code']);
    http_response_code($exception->getMessage() === 'Unable to check voucher eligibility.' ? 500 : 400);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
