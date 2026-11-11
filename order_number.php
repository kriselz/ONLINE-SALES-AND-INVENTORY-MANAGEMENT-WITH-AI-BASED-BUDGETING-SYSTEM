<?php

function formatOrderNumber(mysqli $conn, int $orderId): string
{
    static $formattedNumbers = [];

    if ($orderId <= 0) {
        throw new InvalidArgumentException('Order ID must be a positive integer.');
    }
    if (isset($formattedNumbers[$orderId])) {
        return $formattedNumbers[$orderId];
    }

    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(o.order_date, '%y%m%d') AS order_day,
               (
                   SELECT COUNT(*)
                   FROM orders earlier
                   WHERE earlier.order_date >= DATE(o.order_date)
                     AND earlier.order_date < DATE(o.order_date) + INTERVAL 1 DAY
                     AND earlier.id <= o.id
               ) AS daily_sequence
        FROM orders o
        WHERE o.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        error_log('Order number query preparation failed: ' . $conn->error);
        throw new RuntimeException('Unable to format the order number.');
    }

    $stmt->bind_param('i', $orderId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        error_log('Order number query failed: ' . $error);
        throw new RuntimeException('Unable to format the order number.');
    }

    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) {
        throw new RuntimeException('Unable to find the order for its order number.');
    }

    $formattedNumbers[$orderId] = 'ORD-'
        . $order['order_day']
        . '-'
        . str_pad((string)$order['daily_sequence'], 3, '0', STR_PAD_LEFT);

    return $formattedNumbers[$orderId];
}

function resolveOrderNumber(mysqli $conn, string $orderNumber): int
{
    static $resolvedOrderIds = [];

    if (!preg_match('/^ORD-(\d{6}|\d{8})-(\d{3,})$/', $orderNumber, $matches)) {
        return 0;
    }
    if (isset($resolvedOrderIds[$orderNumber])) {
        return $resolvedOrderIds[$orderNumber];
    }

    $stmt = $conn->prepare("
        SELECT o.id
        FROM orders o
        WHERE DATE_FORMAT(o.order_date, ?) = ?
          AND (
              SELECT COUNT(*)
              FROM orders earlier
              WHERE earlier.order_date >= DATE(o.order_date)
                AND earlier.order_date < DATE(o.order_date) + INTERVAL 1 DAY
                AND earlier.id <= o.id
          ) = ?
        LIMIT 1
    ");
    if (!$stmt) {
        error_log('Order number lookup preparation failed: ' . $conn->error);
        throw new RuntimeException('Unable to look up the order number.');
    }

    $dateFormat = strlen($matches[1]) === 6 ? '%y%m%d' : '%Y%m%d';
    $orderDay = $matches[1];
    $sequence = (int)$matches[2];
    $stmt->bind_param('ssi', $dateFormat, $orderDay, $sequence);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        error_log('Order number lookup failed: ' . $error);
        throw new RuntimeException('Unable to look up the order number.');
    }

    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $resolvedOrderIds[$orderNumber] = $order ? (int)$order['id'] : 0;
    return $resolvedOrderIds[$orderNumber];
}
