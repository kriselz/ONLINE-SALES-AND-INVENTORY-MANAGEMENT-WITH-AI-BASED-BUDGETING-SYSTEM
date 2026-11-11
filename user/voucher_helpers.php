<?php

function lookupCheckoutCoupon(mysqli $conn, int $userId, string $code, float $subtotal, bool $lock = false): array
{
    $loyaltyTable = $conn->query("SHOW TABLES LIKE 'customer_loyalty_vouchers'");
    if (!$loyaltyTable) {
        throw new RuntimeException('Unable to check voucher eligibility.');
    }

    $loyaltyJoin = $loyaltyTable->num_rows > 0
        ? 'LEFT JOIN customer_loyalty_vouchers clv ON clv.coupon_id = c.id'
        : '';
    $loyaltyFields = $loyaltyTable->num_rows > 0
        ? 'clv.user_id AS assigned_user_id, clv.status AS assigned_status'
        : 'NULL AS assigned_user_id, NULL AS assigned_status';
    $statement = $conn->prepare(
        "SELECT c.id, c.discount_type, c.discount_value, c.min_order, c.usage_limit, c.used_count,
                c.expiry_date, $loyaltyFields
         FROM coupons c
         $loyaltyJoin
         WHERE c.code = ?
         LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
    );
    if (!$statement) {
        throw new RuntimeException('Unable to check voucher eligibility.');
    }
    $statement->bind_param('s', $code);
    if (!$statement->execute()) {
        $statement->close();
        throw new RuntimeException('Unable to check voucher eligibility.');
    }
    $coupon = $statement->get_result()->fetch_assoc();
    $statement->close();
    $couponSource = 'coupons';

    if (!$coupon) {
        $voucherTable = $conn->query("SHOW TABLES LIKE 'loyalty_vouchers'");
        if (!$voucherTable) {
            throw new RuntimeException('Unable to check voucher eligibility.');
        }
        if ($voucherTable->num_rows > 0) {
            $voucherStatement = $conn->prepare(
                'SELECT id, discount_type, discount_value, min_order_amount AS min_order,
                        usage_limit, used_count, expires_at AS expiry_date, is_active,
                        NULL AS assigned_user_id, NULL AS assigned_status
                 FROM loyalty_vouchers
                 WHERE code = ?
                 LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
            );
            if (!$voucherStatement) {
                throw new RuntimeException('Unable to check voucher eligibility.');
            }
            $voucherStatement->bind_param('s', $code);
            if (!$voucherStatement->execute()) {
                $voucherStatement->close();
                throw new RuntimeException('Unable to check voucher eligibility.');
            }
            $coupon = $voucherStatement->get_result()->fetch_assoc();
            $voucherStatement->close();
            $couponSource = 'loyalty_vouchers';
        }
    }

    if (!$coupon) {
        throw new RuntimeException('Voucher code not found.');
    }
    if ($couponSource === 'loyalty_vouchers' && !(int)$coupon['is_active']) {
        throw new RuntimeException('This voucher is inactive.');
    }
    if ($coupon['assigned_user_id'] !== null) {
        if ((int)$coupon['assigned_user_id'] !== $userId) {
            throw new RuntimeException('This voucher is not available for your account.');
        }
        if ($coupon['assigned_status'] === 'used') {
            throw new RuntimeException('This voucher has already been used.');
        }
        if ($coupon['assigned_status'] !== 'sent') {
            throw new RuntimeException('This voucher is not available for your account.');
        }
    }
    if ($coupon['expiry_date'] !== null && strtotime((string)$coupon['expiry_date']) <= time()) {
        throw new RuntimeException('This voucher has expired.');
    }
    $usageLimit = $coupon['usage_limit'] === null ? 0 : (int)$coupon['usage_limit'];
    if ($usageLimit > 0 && (int)$coupon['used_count'] >= $usageLimit) {
        throw new RuntimeException($usageLimit === 1
            ? 'This voucher has already been used.'
            : 'This voucher has reached its usage limit.');
    }
    if ($subtotal < (float)$coupon['min_order']) {
        throw new RuntimeException('The order does not meet this voucher’s minimum purchase amount.');
    }

    $discountValue = (float)$coupon['discount_value'];
    if ($discountValue <= 0 || !is_finite($discountValue)) {
        throw new RuntimeException('This voucher has an invalid discount.');
    }
    if ($coupon['discount_type'] === 'percent') {
        $discountAmount = round($subtotal * ($discountValue / 100), 2);
    } elseif ($coupon['discount_type'] === 'fixed') {
        $discountAmount = $discountValue;
    } else {
        throw new RuntimeException('This voucher has an unsupported discount type.');
    }
    $discountAmount = min($subtotal, $discountAmount);
    if ($discountAmount <= 0) {
        throw new RuntimeException('This voucher does not apply to this order.');
    }

    return [
        'id' => (int)$coupon['id'],
        'discount_type' => (string)$coupon['discount_type'],
        'discount_value' => $discountValue,
        'discount_amount' => $discountAmount,
        'source' => $couponSource,
        'assigned_user_id' => $coupon['assigned_user_id'] === null ? null : (int)$coupon['assigned_user_id'],
        'assigned_status' => $coupon['assigned_status'],
    ];
}
