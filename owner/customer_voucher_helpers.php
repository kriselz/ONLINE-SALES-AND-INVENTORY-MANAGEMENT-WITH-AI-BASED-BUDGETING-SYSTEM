<?php

function ensureCustomerLoyaltyVoucherSchema(mysqli $conn): bool
{
    $created = $conn->query(
        "CREATE TABLE IF NOT EXISTS customer_loyalty_vouchers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            coupon_id INT NOT NULL UNIQUE,
            qualifying_month DATE NOT NULL,
            qualifying_spend DECIMAL(10,2) NOT NULL,
            discount_value DECIMAL(10,2) NOT NULL,
            status ENUM('pending', 'sent', 'used') NOT NULL DEFAULT 'pending',
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            used_at DATETIME NULL,
            order_id INT NULL,
            UNIQUE KEY uq_customer_loyalty_user_month (user_id, qualifying_month)
        )"
    );
    if (!$created) {
        error_log('Customer voucher table creation failed: ' . $conn->error);
        return false;
    }

    $monthColumn = $conn->query("SHOW COLUMNS FROM customer_loyalty_vouchers LIKE 'qualifying_month'");
    if (!$monthColumn) {
        error_log('Customer voucher monthly field lookup failed: ' . $conn->error);
        return false;
    }
    if ($monthColumn->num_rows === 0) {
        $monthColumn->free();
        if (!$conn->query(
            'ALTER TABLE customer_loyalty_vouchers
             ADD COLUMN qualifying_month DATE NULL'
        )) {
            error_log('Customer voucher monthly field preparation failed: ' . $conn->error);
            return false;
        }
        $monthColumn = $conn->query("SHOW COLUMNS FROM customer_loyalty_vouchers LIKE 'qualifying_month'");
        if (!$monthColumn) {
            error_log('Customer voucher monthly field verification failed: ' . $conn->error);
            return false;
        }
    }
    $backfill = $conn->query(
        "UPDATE customer_loyalty_vouchers
         SET qualifying_month = STR_TO_DATE(DATE_FORMAT(created_at, '%Y-%m-01'), '%Y-%m-%d')
         WHERE qualifying_month IS NULL"
    );
    if (!$backfill) {
        error_log('Customer voucher month backfill failed: ' . $conn->error);
        return false;
    }
    $monthColumnDetails = $monthColumn->fetch_assoc();
    $monthColumn->free();
    if (($monthColumnDetails['Null'] ?? 'YES') === 'YES'
        && !$conn->query(
            'ALTER TABLE customer_loyalty_vouchers
             MODIFY COLUMN qualifying_month DATE NOT NULL'
        )) {
        error_log('Customer voucher month constraint preparation failed: ' . $conn->error);
        return false;
    }

    $indexes = $conn->query('SHOW INDEX FROM customer_loyalty_vouchers');
    if (!$indexes) {
        error_log('Customer voucher index lookup failed: ' . $conn->error);
        return false;
    }
    $indexColumns = [];
    while ($index = $indexes->fetch_assoc()) {
        $indexName = (string)$index['Key_name'];
        if ($indexName === 'PRIMARY' || (int)$index['Non_unique'] !== 0) {
            continue;
        }
        $indexColumns[$indexName][(int)$index['Seq_in_index']] = (string)$index['Column_name'];
    }
    $indexes->free();

    foreach ($indexColumns as $indexName => $columns) {
        ksort($columns);
        if (array_values($columns) === ['user_id']) {
            $safeIndexName = str_replace('`', '``', $indexName);
            if (!$conn->query("ALTER TABLE customer_loyalty_vouchers DROP INDEX `$safeIndexName`")) {
                error_log('Obsolete customer voucher unique index removal failed: ' . $conn->error);
                return false;
            }
        }
    }

    $hasMonthlyUniqueIndex = false;
    foreach ($indexColumns as $columns) {
        ksort($columns);
        if (array_values($columns) === ['user_id', 'qualifying_month']) {
            $hasMonthlyUniqueIndex = true;
            break;
        }
    }
    if (!$hasMonthlyUniqueIndex
        && !$conn->query(
            'ALTER TABLE customer_loyalty_vouchers
             ADD UNIQUE KEY uq_customer_loyalty_user_month (user_id, qualifying_month)'
        )) {
        error_log('Customer voucher monthly uniqueness preparation failed: ' . $conn->error);
        return false;
    }

    return true;
}
