<?php

function ensureDiscountCooldownSchema(mysqli $conn): void
{
    $schemaStatements = [
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_status VARCHAR(30) NOT NULL DEFAULT 'not_applicable'",
        'ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_used_at DATETIME NULL'
    ];
    foreach ($schemaStatements as $statement) {
        if (!$conn->query($statement)) {
            throw new RuntimeException('Unable to prepare the discount usage cooldown: ' . $conn->error);
        }
    }
}

function getDiscountCooldownExpiry(mysqli $conn, int $userId): ?string
{
    $stmt = $conn->prepare(
        "SELECT DATE_FORMAT(DATE_ADD(MAX(COALESCE(discount_used_at, order_date)), INTERVAL 24 HOUR), '%b %d, %Y %l:%i %p') AS available_at
         FROM orders
         WHERE user_id = ?
           AND discount_status = 'approved'
           AND discount_amount > 0
           AND COALESCE(discount_used_at, order_date) > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check discount eligibility: ' . $conn->error);
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to check discount eligibility: ' . $error);
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $availableAt = trim((string)($row['available_at'] ?? ''));
    return $availableAt !== '' ? $availableAt : null;
}
