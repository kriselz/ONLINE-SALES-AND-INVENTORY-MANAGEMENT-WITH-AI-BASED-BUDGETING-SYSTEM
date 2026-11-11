<?php
session_start();
include 'config.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/access_control.php';

$isPharmacist = requireOwnerPageAccess($conn, true);
$currentUserId = (int)$_SESSION['user_id'];

$staffSchemaReady = $conn->query("CREATE TABLE IF NOT EXISTS pharmacy_staff (user_id INT NOT NULL PRIMARY KEY, active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$sellerSchemaReady = $conn->query('ALTER TABLE orders ADD COLUMN IF NOT EXISTS seller_id INT NULL');
$paymentReviewerSchemaReady = $conn->query('ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_verified_by INT NULL');
if (!$staffSchemaReady || !$sellerSchemaReady || !$paymentReviewerSchemaReady) {
    error_log('Pharmacist sales report schema preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load pharmacist sales.');
}
$dateRange = $_GET['date_range'] ?? 'yearly';
if (!in_array($dateRange, ['weekly', 'monthly', 'last30', 'yearly', 'custom'], true)) {
    $dateRange = 'yearly';
}
$dateFromInput = trim($_GET['date_from'] ?? '');
$dateToInput = trim($_GET['date_to'] ?? '');
$dateFrom = DateTime::createFromFormat('!Y-m-d', $dateFromInput);
$dateTo = DateTime::createFromFormat('!Y-m-d', $dateToInput);
$dateFromValid = $dateFrom && $dateFrom->format('Y-m-d') === $dateFromInput;
$dateToValid = $dateTo && $dateTo->format('Y-m-d') === $dateToInput;
$customRangeApplied = $dateRange === 'custom' && ($dateFromValid || $dateToValid);
$showCustomDateInputs = $dateRange === 'custom' && !$customRangeApplied;
$today = date('Y-m-d');
$dateFromValue = '';
$dateToValue = '';
$customDateRangeLabel = 'Custom Range';

if ($dateRange === 'weekly') {
    $dateFromValue = date('Y-m-d', strtotime('-6 days'));
    $dateToValue = $today;
} elseif ($dateRange === 'last30') {
    $dateFromValue = date('Y-m-d', strtotime('-29 days'));
    $dateToValue = $today;
} elseif ($dateRange === 'monthly') {
    $dateFromValue = date('Y-m-01');
    $dateToValue = $today;
} elseif ($dateRange === 'yearly') {
    $dateFromValue = date('Y-01-01');
    $dateToValue = $today;
} elseif ($dateFromValid && $dateToValid) {
    $dateFromValue = $dateFromInput;
    $dateToValue = $dateToInput;
    if ($dateFromValue > $dateToValue) {
        [$dateFromValue, $dateToValue] = [$dateToValue, $dateFromValue];
    }
    $customDateRangeLabel = date('M j, Y', strtotime($dateFromValue)) . ' - ' . date('M j, Y', strtotime($dateToValue));
} elseif ($dateFromValid) {
    $dateFromValue = $dateFromInput;
    $customDateRangeLabel = date('M j, Y', strtotime($dateFromInput));
} elseif ($dateToValid) {
    $dateToValue = $dateToInput;
    $customDateRangeLabel = 'Through ' . date('M j, Y', strtotime($dateToInput));
} else {
    $dateFromValue = $today;
    $dateToValue = $today;
}

if ($dateRange === 'custom' && $dateFromValid && $dateToValid) {
    $dateWhere = "DATE(o.order_date) BETWEEN '" . $conn->real_escape_string($dateFromValue) . "' AND '" . $conn->real_escape_string($dateToValue) . "'";
} elseif ($dateRange === 'custom' && $dateFromValid) {
    $dateWhere = "DATE(o.order_date) = '" . $conn->real_escape_string($dateFromInput) . "'";
} elseif ($dateRange === 'custom' && $dateToValid) {
    $dateWhere = "DATE(o.order_date) = '" . $conn->real_escape_string($dateToInput) . "'";
} else {
    $dateWhere = "DATE(o.order_date) BETWEEN '" . $conn->real_escape_string($dateFromValue) . "' AND '" . $conn->real_escape_string($dateToValue) . "'";
}

$orderItemRevenue = "CASE
    WHEN LOWER(COALESCE(o.payment_method, '')) = 'onsite' THEN COALESCE(oi.price, 0)
    WHEN COALESCE(oi.total_price, 0) > 0 THEN oi.total_price
    ELSE COALESCE(oi.price, 0) * GREATEST(COALESCE(oi.quantity, 0), 1)
END";
$completedSalesCondition = "LOWER(TRIM(COALESCE(o.status, ''))) <> 'cancelled'
    AND LOWER(TRIM(COALESCE(o.payment_status, 'unpaid'))) = 'paid'
    AND (
        LOWER(TRIM(COALESCE(o.status, ''))) IN ('completed', 'delivered')
        OR LOWER(TRIM(COALESCE(o.delivery_status, ''))) = 'delivered'
    )";
$pharmacistAccounts = [];
$viewStaffId = 0;
$viewStaffName = '';
if (!$isPharmacist) {
    $pharmacistAccountsResult = $conn->query("SELECT s.user_id, COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS staff_name
        FROM pharmacy_staff s
        JOIN users u ON u.id = s.user_id AND u.role = 'pharmacist'");
    if (!$pharmacistAccountsResult) {
        error_log('Pharmacist sales account query failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to load pharmacist sales accounts.');
    }
    while ($pharmacistAccount = $pharmacistAccountsResult->fetch_assoc()) {
        $pharmacistAccounts[(int)$pharmacistAccount['user_id']] = (string)$pharmacistAccount['staff_name'];
    }
    $requestedViewStaffId = filter_input(INPUT_GET, 'view_staff', FILTER_VALIDATE_INT);
    if ($requestedViewStaffId && isset($pharmacistAccounts[$requestedViewStaffId])) {
        $viewStaffId = $requestedViewStaffId;
        $viewStaffName = $pharmacistAccounts[$viewStaffId];
    }
}
$staffScope = $isPharmacist
    ? "WHERE s.user_id = $currentUserId"
    : '';
$transactionScope = $isPharmacist
    ? " AND COALESCE(o.seller_id, o.payment_verified_by) = $currentUserId"
    : ($viewStaffId ? " AND COALESCE(o.seller_id, o.payment_verified_by) = $viewStaffId" : '');
$transactionSearch = trim($_GET['transaction_search'] ?? '');
$paymentMethodFilter = (string)($_GET['payment_method'] ?? 'all');
$allowedPaymentMethods = ['all', 'cash', 'gcash', 'bpi', 'onsite', 'other'];
if (!in_array($paymentMethodFilter, $allowedPaymentMethods, true)) {
    $paymentMethodFilter = 'all';
}
$transactionFilters = [];
if ($transactionSearch !== '') {
    $transactionSearchSql = $conn->real_escape_string($transactionSearch);
    $transactionFilters[] = "(CAST(o.id AS CHAR) LIKE '%$transactionSearchSql%'
        OR CONCAT(
            'ORD-',
            DATE_FORMAT(o.order_date, '%y%m%d'),
            '-',
            LPAD((
                SELECT COUNT(*)
                FROM orders earlier
                WHERE earlier.order_date >= DATE(o.order_date)
                  AND earlier.order_date < DATE(o.order_date) + INTERVAL 1 DAY
                  AND earlier.id <= o.id
            ), 3, '0')
        ) LIKE '%$transactionSearchSql%'
        OR COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) LIKE '%$transactionSearchSql%'
        OR u.username LIKE '%$transactionSearchSql%'
        OR COALESCE(o.payment_method, '') LIKE '%$transactionSearchSql%'
        OR EXISTS (
            SELECT 1 FROM order_items search_oi
            WHERE search_oi.order_id = o.id
              AND COALESCE(search_oi.product_name, '') LIKE '%$transactionSearchSql%'
        ))";
}
if ($paymentMethodFilter === 'cash') {
    $transactionFilters[] = "LOWER(TRIM(COALESCE(o.payment_method, ''))) IN ('cash', 'cod')";
} elseif ($paymentMethodFilter === 'gcash') {
    $transactionFilters[] = "LOWER(TRIM(COALESCE(o.payment_method, ''))) = 'gcash'";
} elseif ($paymentMethodFilter === 'bpi') {
    $transactionFilters[] = "LOWER(TRIM(COALESCE(o.payment_method, ''))) IN ('bank', 'bpi', 'bank transfer')";
} elseif ($paymentMethodFilter === 'onsite') {
    $transactionFilters[] = "LOWER(TRIM(COALESCE(o.payment_method, ''))) = 'onsite'";
} elseif ($paymentMethodFilter === 'other') {
    $transactionFilters[] = "LOWER(TRIM(COALESCE(o.payment_method, ''))) NOT IN ('cash', 'cod', 'gcash', 'bank', 'bpi', 'bank transfer', 'onsite')";
}
$transactionFilterSql = $transactionFilters ? ' AND ' . implode(' AND ', $transactionFilters) : '';
$posTransactionFilters = [];
if ($transactionSearch !== '') {
    $transactionSearchSql = $conn->real_escape_string($transactionSearch);
    $posTransactionFilters[] = "(CAST(ps.id AS CHAR) LIKE '%$transactionSearchSql%'
        OR CONCAT(
            'SALE-',
            DATE_FORMAT(ps.created_at, '%y%m%d'),
            '-',
            LPAD((
                SELECT COUNT(*)
                FROM pos_sales earlier
                WHERE earlier.created_at >= DATE(ps.created_at)
                  AND earlier.created_at < DATE(ps.created_at) + INTERVAL 1 DAY
                  AND earlier.id <= ps.id
            ), 3, '0')
        ) LIKE '%$transactionSearchSql%'
        OR ps.customer_name LIKE '%$transactionSearchSql%'
        OR u.username LIKE '%$transactionSearchSql%'
        OR COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) LIKE '%$transactionSearchSql%'
        OR EXISTS (
            SELECT 1 FROM pos_sale_items search_psi
            WHERE search_psi.sale_id = ps.id
              AND COALESCE(search_psi.product_name, '') LIKE '%$transactionSearchSql%'
        ))";
}
$posDateWhere = "DATE(ps.created_at) BETWEEN '" . $conn->real_escape_string($dateFromValue) . "' AND '" . $conn->real_escape_string($dateToValue) . "'";
$posTransactionScope = $isPharmacist
    ? " AND ps.cashier_id = $currentUserId"
    : ($viewStaffId ? " AND ps.cashier_id = $viewStaffId" : '');
$includePosTransactions = in_array($paymentMethodFilter, ['all', 'onsite'], true);
$transactionsPerPage = 10;
$transactionsPage = max(1, (int)($_GET['transactions_page'] ?? 1));
$exportTransactions = ($_GET['export'] ?? '') === 'csv';
$transactionCountSql = "SELECT COUNT(*) AS total FROM (
    SELECT o.id
    FROM orders o
    JOIN pharmacy_staff s ON (s.user_id = o.seller_id OR (o.seller_id IS NULL AND s.user_id = o.payment_verified_by))
    JOIN users u ON u.id = s.user_id AND u.role = 'pharmacist'
    WHERE $completedSalesCondition
      AND $dateWhere
      $transactionScope
      $transactionFilterSql";
if ($includePosTransactions) {
    $transactionCountSql .= "
    UNION ALL
    SELECT ps.id
    FROM pos_sales ps
    LEFT JOIN users u ON u.id = ps.cashier_id
    WHERE $posDateWhere
      $posTransactionScope
      " . ($posTransactionFilters ? ' AND ' . implode(' AND ', $posTransactionFilters) : '');
}
$transactionCountSql .= ') AS staff_sales';
$transactionCountResult = $conn->query($transactionCountSql);
if (!$transactionCountResult) {
    error_log('Pharmacist transaction count query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load staff sales transactions.');
}
$totalTransactions = (int)$transactionCountResult->fetch_assoc()['total'];
$totalTransactionPages = max(1, (int)ceil($totalTransactions / $transactionsPerPage));
$transactionsPage = min($transactionsPage, $totalTransactionPages);
$transactionsOffset = ($transactionsPage - 1) * $transactionsPerPage;
$salesByStaff = $conn->query("
    SELECT s.user_id,
           COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS staff_name,
           u.username,
           s.active,
           COUNT(DISTINCT o.id) + COALESCE(MAX(pos.pos_sale_count), 0) AS order_count,
           COALESCE(SUM($orderItemRevenue), 0) + COALESCE(MAX(pos.pos_sales_total), 0) AS sales_total
    FROM pharmacy_staff s
    JOIN users u ON u.id = s.user_id AND u.role = 'pharmacist'
    LEFT JOIN orders o
        ON (o.seller_id = s.user_id OR (o.seller_id IS NULL AND o.payment_verified_by = s.user_id))
        AND $completedSalesCondition
        AND $dateWhere
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN (
        SELECT ps.cashier_id, COUNT(*) AS pos_sale_count, SUM(ps.total_amount) AS pos_sales_total
        FROM pos_sales ps
        WHERE $posDateWhere
        GROUP BY ps.cashier_id
    ) pos ON pos.cashier_id = s.user_id
    $staffScope
    GROUP BY s.user_id, u.full_name, u.username, s.active
    ORDER BY sales_total DESC, staff_name ASC
");
$transactionsSql = "
    SELECT 'order' AS sale_type,
           o.id,
           NULL AS sale_number,
           o.order_date,
           o.payment_method,
           COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS staff_name,
           COALESCE(SUM($orderItemRevenue), 0) AS sale_total,
           GROUP_CONCAT(
               DISTINCT CONCAT(
                   COALESCE(NULLIF(TRIM(oi.product_name), ''), 'Product'),
                   ' x',
                   COALESCE(oi.quantity, 1)
               )
               SEPARATOR ', '
           ) AS items
    FROM orders o
    JOIN pharmacy_staff s ON (s.user_id = o.seller_id OR (o.seller_id IS NULL AND s.user_id = o.payment_verified_by))
    JOIN users u ON u.id = s.user_id AND u.role = 'pharmacist'
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE $completedSalesCondition
      AND $dateWhere
      $transactionScope
      $transactionFilterSql
    GROUP BY o.id, o.order_date, o.payment_method, staff_name
";
if ($includePosTransactions) {
    $transactionsSql .= " UNION ALL
    SELECT 'pos' AS sale_type,
           ps.id,
           CONCAT(
               'SALE-',
               DATE_FORMAT(ps.created_at, '%y%m%d'),
               '-',
               LPAD((
                   SELECT COUNT(*)
                   FROM pos_sales earlier
                   WHERE earlier.created_at >= DATE(ps.created_at)
                     AND earlier.created_at < DATE(ps.created_at) + INTERVAL 1 DAY
                     AND earlier.id <= ps.id
               ), 3, '0')
           ) AS sale_number,
           ps.created_at AS order_date,
           'onsite' AS payment_method,
           COALESCE(NULLIF(TRIM(u.full_name), ''), u.username, 'Unassigned') AS staff_name,
           ps.total_amount AS sale_total,
           GROUP_CONCAT(
               DISTINCT CONCAT(
                   COALESCE(NULLIF(TRIM(psi.product_name), ''), 'Product'),
                   ' x',
                   COALESCE(psi.quantity, 1)
               )
               SEPARATOR ', '
           ) AS items
    FROM pos_sales ps
    LEFT JOIN users u ON u.id = ps.cashier_id
    LEFT JOIN pos_sale_items psi ON psi.sale_id = ps.id
    WHERE $posDateWhere
      $posTransactionScope
      " . ($posTransactionFilters ? ' AND ' . implode(' AND ', $posTransactionFilters) : '') . "
    GROUP BY ps.id, ps.created_at, ps.total_amount, staff_name";
}
$transactionsSql .= ' ORDER BY order_date DESC, id DESC';
if (!$exportTransactions) {
    $transactionsSql .= " LIMIT $transactionsPerPage OFFSET $transactionsOffset";
}
$transactions = $conn->query($transactionsSql);
if (!$transactions) {
    error_log('Pharmacist sales transaction query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load staff sales transactions.');
}
if ($exportTransactions) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="staff-sales-transactions.csv"');
    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to export staff sales transactions.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Sale / Order ID', 'Cashier / Staff', 'Items', 'Payment Method', 'Date', 'Total']);
    while ($row = $transactions->fetch_assoc()) {
        $paymentMethod = strtolower(trim((string)$row['payment_method']));
        $paymentLabel = in_array($paymentMethod, ['cash', 'cod'], true)
            ? 'Cash'
            : (in_array($paymentMethod, ['bank', 'bpi', 'bank transfer'], true)
                ? 'BPI / Bank'
                : ($paymentMethod === 'onsite' ? 'Onsite' : ($paymentMethod === 'gcash' ? 'GCash' : 'Other')));
        $csvValues = [
            $row['sale_type'] === 'pos'
                ? (string)$row['sale_number']
                : formatOrderNumber($conn, (int)$row['id']),
            trim((string)$row['staff_name']) ?: 'Unassigned',
            trim((string)$row['items']) ?: '—',
            $paymentLabel,
            date('M d, Y h:i A', strtotime($row['order_date'])),
            number_format((float)$row['sale_total'], 2, '.', ''),
        ];
        foreach ($csvValues as &$csvValue) {
            if (preg_match('/^[\s]*[=+\-@]/', (string)$csvValue)) {
                $csvValue = "'" . $csvValue;
            }
        }
        unset($csvValue);
        fputcsv($output, $csvValues);
    }
    fclose($output);
    exit();
}
$paymentSalesResult = $conn->query("
    SELECT CASE
               WHEN LOWER(TRIM(COALESCE(o.payment_method, ''))) IN ('cash', 'cod') THEN 'cash'
               WHEN LOWER(TRIM(COALESCE(o.payment_method, ''))) = 'gcash' THEN 'gcash'
               WHEN LOWER(TRIM(COALESCE(o.payment_method, ''))) IN ('bank', 'bpi', 'bank transfer') THEN 'bpi'
               ELSE 'other'
           END AS payment_group,
           COALESCE(SUM($orderItemRevenue), 0) AS sales_total
    FROM orders o
    JOIN pharmacy_staff s ON (s.user_id = o.seller_id OR (o.seller_id IS NULL AND s.user_id = o.payment_verified_by))
    JOIN users u ON u.id = s.user_id AND u.role = 'pharmacist'
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE $completedSalesCondition
      AND $dateWhere
      $transactionScope
    GROUP BY payment_group
");
if (!$paymentSalesResult) {
    error_log('Pharmacist payment sales query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load payment sales.');
}
$paymentSales = ['cash' => 0.0, 'gcash' => 0.0, 'bpi' => 0.0];
while ($paymentRow = $paymentSalesResult->fetch_assoc()) {
    if (array_key_exists($paymentRow['payment_group'], $paymentSales)) {
        $paymentSales[$paymentRow['payment_group']] = (float)$paymentRow['sales_total'];
    }
}
$posCashSalesResult = $conn->query("
    SELECT COALESCE(SUM(ps.total_amount), 0) AS total
    FROM pos_sales ps
    WHERE $posDateWhere
      $posTransactionScope
");
if (!$posCashSalesResult) {
    error_log('Pharmacist POS cash sales total query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load pharmacist POS cash sales.');
}
$paymentSales['cash'] += (float)$posCashSalesResult->fetch_assoc()['total'];
$totalSales = 0.0;
if ($salesByStaff) {
    while ($staffRow = $salesByStaff->fetch_assoc()) {
        if (!$viewStaffId || (int)$staffRow['user_id'] === $viewStaffId) {
            $totalSales += (float)$staffRow['sales_total'];
        }
    }
    $salesByStaff->data_seek(0);
}
if (!$isPharmacist && !$viewStaffId) {
    $nonPharmacistPosSalesResult = $conn->query("
        SELECT COALESCE(SUM(ps.total_amount), 0) AS total
        FROM pos_sales ps
        WHERE $posDateWhere
          AND NOT EXISTS (
              SELECT 1
              FROM pharmacy_staff s
              JOIN users u ON u.id = s.user_id AND u.role = 'pharmacist'
              WHERE s.user_id = ps.cashier_id
          )
    ");
    if (!$nonPharmacistPosSalesResult) {
        error_log('Non-pharmacist POS sales total query failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to load additional POS sales.');
    }
    $totalSales += (float)$nonPharmacistPosSalesResult->fetch_assoc()['total'];
}
$onsiteSalesResult = $conn->query("
    SELECT COALESCE(SUM($orderItemRevenue), 0) AS total
    FROM orders o
    JOIN pharmacy_staff s ON (s.user_id = o.seller_id OR (o.seller_id IS NULL AND s.user_id = o.payment_verified_by))
    JOIN users u ON u.id = s.user_id AND u.role = 'pharmacist'
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE $completedSalesCondition
      AND $dateWhere
      $transactionScope
      AND LOWER(TRIM(COALESCE(o.payment_method, ''))) = 'onsite'
");
if (!$onsiteSalesResult) {
    error_log('Pharmacist onsite sales total query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load onsite sales total.');
}
$onsiteSalesTotal = (float)$onsiteSalesResult->fetch_assoc()['total'];
$posOnsiteSalesResult = $conn->query("
    SELECT COALESCE(SUM(ps.total_amount), 0) AS total
    FROM pos_sales ps
    WHERE DATE(ps.created_at) BETWEEN '" . $conn->real_escape_string($dateFromValue) . "'
      AND '" . $conn->real_escape_string($dateToValue) . "'
      $posTransactionScope
");
if (!$posOnsiteSalesResult) {
    error_log('Pharmacist POS onsite sales total query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load onsite POS sales total.');
}
$onsiteSalesTotal += (float)$posOnsiteSalesResult->fetch_assoc()['total'];
$currentPage = basename($_SERVER['PHP_SELF']);
$reportTitle = $isPharmacist ? 'My Pharmacy Sales' : 'Pharmacist Sales';
$reportDescription = $isPharmacist
    ? 'Sales recorded under your pharmacist account.'
    : ($viewStaffId
        ? 'Sales recorded under the selected pharmacist account.'
        : 'Includes pharmacist sales and all onsite POS sales; POS remains attributed to its recorded cashier.');
$salesMetricLabel = $isPharmacist
    ? 'My sales total'
    : ($viewStaffId ? $viewStaffName . ' sales total' : 'Staff sales total');
$transactionsHeading = $isPharmacist
    ? 'My Transactions'
    : ($viewStaffId ? $viewStaffName . ' Transactions' : 'Staff Transactions');
$showPharmacistSalesTable = !$isPharmacist && count($pharmacistAccounts) >= 2;
$reportDateRangeLabel = date('M j, Y', strtotime($dateFromValue)) . ' - ' . date('M j, Y', strtotime($dateToValue));
$transactionPageItems = [];
if ($totalTransactionPages <= 5) {
    $transactionPageItems = range(1, $totalTransactionPages);
} elseif ($transactionsPage <= 3) {
    $transactionPageItems = [1, 2, 3, '...', $totalTransactionPages];
} elseif ($transactionsPage >= $totalTransactionPages - 2) {
    $transactionPageItems = [1, '...', $totalTransactionPages - 2, $totalTransactionPages - 1, $totalTransactionPages];
} else {
    $transactionPageItems = [1, '...', $transactionsPage - 1, $transactionsPage, $transactionsPage + 1, '...', $totalTransactionPages];
}
$transactionPageParams = ['date_range' => $dateRange];
if ($dateRange === 'custom' && $dateFromValid) {
    $transactionPageParams['date_from'] = $dateFromInput;
}
if ($dateRange === 'custom' && $dateToValid) {
    $transactionPageParams['date_to'] = $dateToInput;
}
if ($transactionSearch !== '') {
    $transactionPageParams['transaction_search'] = $transactionSearch;
}
if ($paymentMethodFilter !== 'all') {
    $transactionPageParams['payment_method'] = $paymentMethodFilter;
}
if ($viewStaffId) {
    $transactionPageParams['view_staff'] = $viewStaffId;
}
$transactionPageUrl = 'staff_sales_report.php?' . http_build_query($transactionPageParams) . '&transactions_page=';
$transactionExportUrl = 'staff_sales_report.php?' . http_build_query(array_merge($transactionPageParams, ['export' => 'csv']));
$allSalesUrl = 'staff_sales_report.php?' . http_build_query(array_diff_key($transactionPageParams, ['view_staff' => true]));
$viewStaffParams = array_merge($transactionPageParams, ['transactions_page' => null, 'export' => null]);
unset($viewStaffParams['transactions_page'], $viewStaffParams['export'], $viewStaffParams['view_staff']);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Staff Sales | Owner</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"><link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4"><style>
*{box-sizing:border-box}body{margin:0;background:#f7f8fa;color:#252a30;font-family:'Segoe UI',Tahoma,sans-serif}.content{margin-left:244px;padding:28px;min-height:100vh}.wrap{max-width:none;margin:0}.head{display:flex;justify-content:space-between;align-items:end;gap:16px;margin-bottom:16px}.head h1{margin:0 0 5px;font-size:24px}.head p{margin:0;color:#77818a;font-size:12px}.reports-tabs{display:flex;flex-wrap:nowrap;gap:6px;width:max-content;max-width:100%;overflow-x:auto;scrollbar-width:none;margin:0 0 16px;padding:5px;background:#fff;border:1px solid #ebedf0;border-radius:9px;box-shadow:0 2px 8px rgba(25,30,38,.04)}.reports-tabs::-webkit-scrollbar{display:none}.reports-tab{display:inline-flex;flex:0 0 auto;align-items:center;justify-content:center;min-height:34px;padding:6px 13px;border-radius:6px;color:#68707a;font-size:11px;font-weight:700;line-height:1.25;text-align:center;text-decoration:none;white-space:nowrap}.reports-tab:hover{background:#fff1f3;color:#ed1740}.reports-tab.active{background:#ed1740;color:#fff}.filters{display:flex;align-items:end;gap:8px;flex-wrap:wrap;min-width:0}.filters label{display:grid;flex:1 1 140px;min-width:0;gap:4px;color:#667079;font-size:10px;font-weight:700}.filters input{width:100%;min-width:0;height:34px;padding:0 8px;border:1px solid #dfe4e8;border-radius:6px}.button{height:35px;padding:0 13px;border:0;border-radius:6px;background:#a51f35;color:#fff;font-weight:700;cursor:pointer}.metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:18px}.metric,.panel{background:#fff;border:1px solid #e8ebee;border-radius:8px}.metric{min-height:112px;display:flex;align-items:center;gap:12px;padding:16px 20px;border-color:#f0f1f3;border-radius:10px;box-shadow:0 2px 9px #1c20260d}.metric-icon{display:grid;width:44px;height:44px;flex:0 0 44px;place-items:center;border-radius:50%;background:#fff0f3;color:#ed1b3b;font-size:20px;font-weight:800}.metric.transactions .metric-icon{background:#fff4d7;color:#e0a31b}.metric-copy{min-width:0}.metric-label{color:#6f7780;font-size:15px;line-height:19px;font-weight:700}.metric-value{margin-top:4px;color:#ed1b3b;font-size:clamp(14px,1.5vw,30px);line-height:1.1;font-weight:800;white-space:nowrap}.metric.transactions .metric-value{color:#e0a31b}.panel{padding:17px;margin-bottom:14px}.panel h2{margin:0 0 12px;font-size:16px}.table-wrap{width:100%;max-width:100%;overflow:auto;-webkit-overflow-scrolling:touch}table{width:100%;min-width:600px;border-collapse:collapse}th,td{padding:11px 9px;border-bottom:1px solid #edf0f2;text-align:left;font-size:12px}th{color:#68737c;text-transform:uppercase;font-size:10px}.amount{font-weight:700;white-space:nowrap}.active{color:#267b4d;font-weight:700}.inactive{color:#b23a4d;font-weight:700}.empty{padding:14px;color:#77818a;font-size:12px}@media(max-width:720px){.content{margin:0;padding:18px}.head{align-items:flex-start;flex-direction:column}.reports-tabs{width:100%}.metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.metric{padding:14px 12px;gap:9px}.metric-icon{width:38px;height:38px;flex-basis:38px;font-size:17px}.metric-label{font-size:13px}.metric-value{font-size:clamp(14px,3.5vw,20px)}}
body.reports-sales-page .content{box-sizing:border-box;margin:0 60px 0 320px!important;width:calc(100% - 380px)!important;padding:104px 0 32px!important;min-width:0}
body.reports-sales-page.owner-sidebar-collapsed .content{margin-left:144px!important;margin-right:60px!important;width:calc(100% - 204px)!important}
@media(min-width:901px) and (max-width:1199px){body.reports-sales-page .content{margin-left:276px!important;margin-right:24px!important;width:calc(100% - 300px)!important}body.reports-sales-page.owner-sidebar-collapsed .content{margin-left:100px!important;margin-right:24px!important;width:calc(100% - 124px)!important}}
@media(max-width:900px){body.reports-sales-page .content,body.reports-sales-page.owner-sidebar-collapsed .content{margin:0 12px 0 84px!important;width:calc(100% - 96px)!important;padding:72px 12px 24px!important}.reports-tabs{width:100%}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:420px){body.reports-sales-page .content,body.reports-sales-page.owner-sidebar-collapsed .content{margin:0 10px 0 72px!important;width:calc(100% - 82px)!important;padding-left:10px!important;padding-right:10px!important}}
@media(max-width:420px){.metrics{grid-template-columns:minmax(0,1fr)}}
.sales-pagination-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-top:12px;color:#727984;font-size:11px;font-weight:600}.sales-pagination{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap}.sales-pager-link{min-width:38px;height:38px;padding:0 8px;border:1px solid #e0e6f0;border-radius:8px;background:#fff;color:#4b5563;display:inline-flex;align-items:center;justify-content:center;text-align:center;text-decoration:none;font-size:14px;font-weight:600;transition:all .2s ease}.sales-pager-link:hover{border-color:#d32f2f;color:#d32f2f;background:#fff5f5}.sales-pager-link.active{background:#d32f2f;color:#fff;border-color:#d32f2f;box-shadow:0 4px 12px rgba(211,47,47,.2)}.sales-pager-ellipsis{min-width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;color:#010813}
.transactions-table th{background:#e9ecef;color:#495057;padding:12px 10px}
.transaction-toolbar{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:12px}.transaction-toolbar h2{margin:0}.transaction-filters{display:flex;align-items:center;gap:8px;flex:1 1 auto;justify-content:flex-end;flex-wrap:wrap}.transaction-search-field{position:relative;flex:1 1 180px;min-width:150px;max-width:280px}.transaction-search-icon{position:absolute;top:50%;left:10px;width:16px;height:16px;transform:translateY(-50%);color:#77818a;pointer-events:none}.transaction-search-field input,.transaction-filters select{height:36px;border:1px solid #dfe4e8;border-radius:6px;background:#fff;color:#4e5660;font:500 12px 'Segoe UI',Tahoma,sans-serif}.transaction-search-field input{width:100%;padding:0 10px 0 34px}.transaction-filters select{min-width:150px;padding:0 10px}.transaction-export-button{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:34px;padding:0 12px;border:1px solid #ed1b3b;border-radius:6px;background:#ed1b3b;color:#fff!important;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;cursor:pointer}.transaction-export-button:hover{background:#d91635;border-color:#d91635;color:#fff!important}.transaction-export-button i{font-size:13px;line-height:1}
.transaction-search-field{flex:1 1 320px;min-width:220px;max-width:none}
body.reports-sales-page .transaction-filters > .transaction-export-button,
body.reports-sales-page .transaction-filters > .transaction-export-button:hover,
body.reports-sales-page .transaction-filters > .transaction-export-button:focus,
body.reports-sales-page .transaction-filters > .transaction-export-button:visited {
    border:1px solid #ed1b3b !important;
    background:#ed1b3b !important;
    color:#fff !important;
}
body.reports-sales-page .transaction-filters > .transaction-export-button:hover { border-color:#d91635 !important; background:#d91635 !important; }
body.reports-sales-page .transaction-filters > .transaction-export-button i { color:#fff !important; }
.staff-sales-view-button{display:inline-flex;align-items:center;justify-content:center;min-width:66px;height:34px;padding:0 12px;border:1px solid #dfe4e8;border-radius:6px;background:#fff;color:#252a30!important;font-size:12px;font-weight:600;text-decoration:none;white-space:nowrap;cursor:pointer}
.staff-sales-view-button:hover,.staff-sales-view-button:focus{background:#f7f8fa;color:#111!important}
@media(max-width:720px){.sales-pagination-footer{align-items:flex-start;flex-direction:column}}
.page-tabs,.reports-tabs{display:flex;flex-wrap:nowrap;align-items:center;gap:6px;width:max-content;max-width:100%;overflow-x:auto;scrollbar-width:none;margin:0 0 16px;padding:5px;background:#fff;border:1px solid #ebedf0;border-radius:9px;box-shadow:0 2px 8px rgba(25,30,38,.04)}
.page-tabs::-webkit-scrollbar,.reports-tabs::-webkit-scrollbar{display:none}
.page-tabs .page-tab,.reports-tabs .reports-tab{display:inline-flex;flex:0 0 auto;min-height:34px;align-items:center;justify-content:center;padding:6px 13px;border-radius:6px;font-size:11px;font-weight:700;line-height:1.25;text-align:center;text-decoration:none;white-space:nowrap}
.reports-tabs > a.reports-tab.active,
.reports-tabs > a.reports-tab.active:hover,
.reports-tabs > a.reports-tab.active:focus{border:0!important;border-bottom:3px solid #ed1740!important;border-radius:0!important;background:#fff!important;color:#a51c38!important;box-shadow:none!important}
@media(max-width:900px){.page-tabs,.reports-tabs{width:100%}}
.sales-date-control{position:relative;display:flex;align-items:center;justify-content:flex-end;min-width:0}
.sales-period-selector{margin:0}
.sales-period-selector select{min-width:184px;height:38px;padding:0 12px;border:1px solid #e1e5e9;border-radius:7px;background:#fff;color:#4e5660;font:600 12px 'Segoe UI',Tahoma,sans-serif}
.sales-custom-date-range{position:absolute;top:calc(100% + 8px);right:0;z-index:20;display:flex;width:max-content;max-width:calc(100vw - 28px);align-items:center;gap:8px;margin:0;padding:10px 12px;border:1px solid #e1e5e9;border-radius:8px;background:#fff;box-shadow:0 8px 18px rgba(28,32,38,.14)}
.sales-custom-date-range label{display:block;min-width:0;font-size:0}
.sales-custom-date-range input{width:176px;min-width:0;height:42px;padding:0 10px;border:1px solid #dfe4e8;border-radius:7px;color:#4e5660;font:500 12px 'Segoe UI',Tahoma,sans-serif}
.sales-custom-date-range button{height:42px;flex:0 0 auto;padding:0 16px;border:0;border-radius:7px;background:#ed1740;color:#fff;font-size:12px;font-weight:700;cursor:pointer}
@media(max-width:720px){.sales-date-control{width:100%;justify-content:flex-start}.sales-period-selector,.sales-period-selector select{width:100%}.sales-custom-date-range{right:auto;left:0;gap:6px}.sales-custom-date-range input{width:clamp(112px,27vw,176px);padding:0 6px;font-size:11px}.sales-custom-date-range button{height:40px;padding:0 12px}}
.metrics.all-metrics{grid-template-columns:repeat(5,minmax(0,1fr))}
.metric.cash .metric-icon{background:#eaf8ef;color:#23834b}.metric.cash .metric-value{color:#23834b}
.metric.gcash .metric-icon{background:#e8f5ff;color:#1683c4}.metric.gcash .metric-value{color:#1683c4}
.metric.bpi .metric-icon{background:#f1ebff;color:#7043bc}.metric.bpi .metric-value{color:#7043bc}
.all-metrics .metric-icon{font-size:0}
.all-metrics .metric-icon .material-icons-outlined{font-size:22px;line-height:1}
.all-metrics .metric-icon{display:grid;place-items:center;padding:0;text-align:center;line-height:1}
@media(max-width:900px){.metrics.all-metrics{grid-template-columns:repeat(5,minmax(190px,1fr));overflow-x:auto;overscroll-behavior-x:contain}}
</style></head><body class="reports-sales-page">
<?php include 'sidebar_owner.php'; ?>
<main class="content"><div class="wrap">
<nav class="reports-tabs" aria-label="Reports navigation">
<?php if (!$isPharmacist): ?><a class="reports-tab" href="analytics.php?page=overview">📈 Overview</a><?php endif; ?>
<a class="reports-tab active" href="staff_sales_report.php" aria-current="page">💊 Pharma Sales</a>
<a class="reports-tab" href="inventory_alerts.php">⚠ Inventory Alerts</a>
<a class="reports-tab" href="inventory_alerts.php?tab=reorders">📦 Reorders</a>
</nav>
<header class="head"><div><h1><?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?></h1><p><?php echo htmlspecialchars($reportDescription, ENT_QUOTES, 'UTF-8'); ?> Showing <?php echo htmlspecialchars($reportDateRangeLabel, ENT_QUOTES, 'UTF-8'); ?>.</p></div><div class="sales-date-control">
<form class="sales-period-selector" method="get" aria-label="Staff sales date range">
<?php if ($viewStaffId): ?><input type="hidden" name="view_staff" value="<?php echo $viewStaffId; ?>"><?php endif; ?>
<?php if ($customRangeApplied): ?><?php if ($dateFromValid): ?><input type="hidden" name="date_from" value="<?php echo htmlspecialchars($dateFromInput, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?><?php if ($dateToValid): ?><input type="hidden" name="date_to" value="<?php echo htmlspecialchars($dateToInput, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?><?php endif; ?>
<?php if ($transactionSearch !== ''): ?><input type="hidden" name="transaction_search" value="<?php echo htmlspecialchars($transactionSearch, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?><?php if ($paymentMethodFilter !== 'all'): ?><input type="hidden" name="payment_method" value="<?php echo htmlspecialchars($paymentMethodFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
<select name="date_range" aria-label="Filter staff sales by date" onchange="this.form.submit()"><option value="weekly" <?php echo $dateRange === 'weekly' ? 'selected' : ''; ?>>Last 7 Days</option><option value="last30" <?php echo $dateRange === 'last30' ? 'selected' : ''; ?>>Last 30 Days</option><option value="monthly" <?php echo $dateRange === 'monthly' ? 'selected' : ''; ?>>This Month</option><option value="yearly" <?php echo $dateRange === 'yearly' ? 'selected' : ''; ?>>This Year</option><option value="custom" <?php echo $dateRange === 'custom' ? 'selected' : ''; ?>><?php echo htmlspecialchars($customDateRangeLabel, ENT_QUOTES, 'UTF-8'); ?></option></select>
</form>
<?php if ($showCustomDateInputs): ?><form class="sales-custom-date-range" method="get" aria-label="Custom staff sales date range"><input type="hidden" name="date_range" value="custom"><?php if ($viewStaffId): ?><input type="hidden" name="view_staff" value="<?php echo $viewStaffId; ?>"><?php endif; ?><?php if ($transactionSearch !== ''): ?><input type="hidden" name="transaction_search" value="<?php echo htmlspecialchars($transactionSearch, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?><?php if ($paymentMethodFilter !== 'all'): ?><input type="hidden" name="payment_method" value="<?php echo htmlspecialchars($paymentMethodFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?><label for="staffSalesDateFrom">From date<input id="staffSalesDateFrom" type="date" name="date_from" value="<?php echo $dateFromValid ? htmlspecialchars($dateFromInput, ENT_QUOTES, 'UTF-8') : ''; ?>"></label><label for="staffSalesDateTo">To date<input id="staffSalesDateTo" type="date" name="date_to" value="<?php echo $dateToValid ? htmlspecialchars($dateToInput, ENT_QUOTES, 'UTF-8') : ''; ?>"></label><button type="submit">Apply</button></form><?php endif; ?>
</div></header>
<section class="metrics all-metrics" aria-label="Staff sales summary">
<div class="metric sales"><span class="metric-icon" aria-hidden="true">₱</span><div class="metric-copy"><div class="metric-label"><?php echo htmlspecialchars($salesMetricLabel, ENT_QUOTES, 'UTF-8'); ?></div><div class="metric-value">₱<?php echo number_format($totalSales, 2); ?></div></div></div>
<div class="metric transactions"><span class="metric-icon" aria-hidden="true">↻</span><div class="metric-copy"><div class="metric-label">Onsite Sales</div><div class="metric-value">₱<?php echo number_format($onsiteSalesTotal, 2); ?></div></div></div>
<div class="metric cash"><span class="metric-icon" aria-hidden="true"><span class="material-icons-outlined">payments</span></span><div class="metric-copy"><div class="metric-label">Cash Sales</div><div class="metric-value">₱<?php echo number_format($paymentSales['cash'], 2); ?></div></div></div>
<div class="metric gcash"><span class="metric-icon" aria-hidden="true"><span class="material-icons-outlined">account_balance_wallet</span></span><div class="metric-copy"><div class="metric-label">GCash Sales</div><div class="metric-value">₱<?php echo number_format($paymentSales['gcash'], 2); ?></div></div></div>
<div class="metric bpi"><span class="metric-icon" aria-hidden="true"><span class="material-icons-outlined">account_balance</span></span><div class="metric-copy"><div class="metric-label">BPI Sales</div><div class="metric-value">₱<?php echo number_format($paymentSales['bpi'], 2); ?></div></div></div>
</section>
<?php if (!$isPharmacist): ?>
<section class="panel"><div class="transaction-toolbar"><h2>Sales by Pharmacist</h2><?php if ($viewStaffId && $showPharmacistSalesTable): ?><a class="staff-sales-view-button" href="<?php echo htmlspecialchars($allSalesUrl, ENT_QUOTES, 'UTF-8'); ?>">View all pharmacists</a><?php endif; ?></div><div class="table-wrap"><table><thead><tr><th>Pharmacist</th><th>Username</th><th>Transactions</th><th>Sales total</th><?php if ($showPharmacistSalesTable): ?><th>Action</th><?php endif; ?></tr></thead><tbody>
<?php if ($salesByStaff && $salesByStaff->num_rows): while ($row = $salesByStaff->fetch_assoc()): ?><tr><td><?php echo htmlspecialchars($row['staff_name']); ?></td><td><?php echo htmlspecialchars($row['username']); ?></td><td><?php echo number_format((int)$row['order_count']); ?></td><td class="amount">₱<?php echo number_format((float)$row['sales_total'], 2); ?></td><?php if ($showPharmacistSalesTable): ?><td><a class="staff-sales-view-button" href="<?php echo htmlspecialchars('staff_sales_report.php?' . http_build_query(array_merge($viewStaffParams, ['view_staff' => (int)$row['user_id']])), ENT_QUOTES, 'UTF-8'); ?>">View</a></td><?php endif; ?></tr><?php endwhile; else: ?><tr><td colspan="<?php echo $showPharmacistSalesTable ? '5' : '4'; ?>" class="empty">No pharmacist accounts have been created.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endif; ?>
<section class="panel"><div class="transaction-toolbar"><h2><?php echo htmlspecialchars($transactionsHeading, ENT_QUOTES, 'UTF-8'); ?></h2><form class="transaction-filters" method="get" aria-label="Search and filter staff sales transactions">
<input type="hidden" name="date_range" value="<?php echo htmlspecialchars($dateRange, ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($viewStaffId): ?><input type="hidden" name="view_staff" value="<?php echo $viewStaffId; ?>"><?php endif; ?>
<?php if ($dateRange === 'custom' && $dateFromValid): ?><input type="hidden" name="date_from" value="<?php echo htmlspecialchars($dateFromInput, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
<?php if ($dateRange === 'custom' && $dateToValid): ?><input type="hidden" name="date_to" value="<?php echo htmlspecialchars($dateToInput, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
<label class="transaction-search-field"><span class="transaction-search-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg></span><input type="search" name="transaction_search" value="<?php echo htmlspecialchars($transactionSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search order, item, staff..." aria-label="Search transactions"></label>
<select name="payment_method" aria-label="Filter by payment method" onchange="this.form.submit()"><option value="all" <?php echo $paymentMethodFilter === 'all' ? 'selected' : ''; ?>>All payment methods</option><option value="cash" <?php echo $paymentMethodFilter === 'cash' ? 'selected' : ''; ?>>Cash</option><option value="gcash" <?php echo $paymentMethodFilter === 'gcash' ? 'selected' : ''; ?>>GCash</option><option value="bpi" <?php echo $paymentMethodFilter === 'bpi' ? 'selected' : ''; ?>>BPI / Bank</option><option value="onsite" <?php echo $paymentMethodFilter === 'onsite' ? 'selected' : ''; ?>>Onsite</option><option value="other" <?php echo $paymentMethodFilter === 'other' ? 'selected' : ''; ?>>Other</option></select>
<a class="transaction-export-button" href="<?php echo htmlspecialchars($transactionExportUrl, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-box-arrow-up" aria-hidden="true"></i> Export</a>
</form></div><div class="table-wrap"><table class="transactions-table"><thead><tr><th>Sale / Order ID</th><th>Cashier / Staff</th><th>Items</th><th>Payment Method</th><th>Date</th><th>Total</th></tr></thead><tbody>
<?php if ($transactions && $transactions->num_rows): while ($row = $transactions->fetch_assoc()): ?>
<?php
$paymentMethod = strtolower(trim((string)$row['payment_method']));
$paymentLabel = in_array($paymentMethod, ['cash', 'cod'], true)
    ? 'Cash'
    : (in_array($paymentMethod, ['bank', 'bpi', 'bank transfer'], true)
        ? 'BPI / Bank'
        : ($paymentMethod === 'onsite' ? 'Onsite' : ($paymentMethod === 'gcash' ? 'GCash' : 'Other')));
$itemList = trim((string)$row['items']);
if ($itemList === '') {
    $itemList = '—';
}
?>
<tr><td><?php echo htmlspecialchars($row['sale_type'] === 'pos' ? (string)$row['sale_number'] : formatOrderNumber($conn, (int)$row['id']), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars(trim((string)$row['staff_name']) ?: 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($itemList); ?></td><td><?php echo htmlspecialchars($paymentLabel); ?></td><td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($row['order_date']))); ?></td><td class="amount">₱<?php echo number_format((float)$row['sale_total'], 2); ?></td></tr>
<?php endwhile; else: ?><tr><td colspan="6" class="empty"><?php echo $isPharmacist ? 'No completed and paid sales linked to your pharmacist account in this date range.' : 'No completed and paid staff sales in this date range.'; ?></td></tr><?php endif; ?>
</tbody></table></div><?php if ($totalTransactions > $transactionsPerPage): ?><div class="sales-pagination-footer"><span>Showing <?php echo number_format($transactionsOffset + 1); ?> to <?php echo number_format(min($transactionsOffset + $transactionsPerPage, $totalTransactions)); ?> of <?php echo number_format($totalTransactions); ?> transactions</span><nav class="sales-pagination" aria-label="Staff sales transaction pages"><?php if ($transactionsPage > 1): ?><a class="sales-pager-link" href="<?php echo htmlspecialchars($transactionPageUrl . ($transactionsPage - 1), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?><?php foreach ($transactionPageItems as $transactionPageItem): ?><?php if ($transactionPageItem === '...'): ?><span class="sales-pager-ellipsis" aria-hidden="true">...</span><?php else: ?><a class="sales-pager-link<?php echo $transactionPageItem === $transactionsPage ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($transactionPageUrl . $transactionPageItem, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $transactionPageItem === $transactionsPage ? 'aria-current="page"' : ''; ?>><?php echo $transactionPageItem; ?></a><?php endif; ?><?php endforeach; ?><?php if ($transactionsPage < $totalTransactionPages): ?><a class="sales-pager-link" href="<?php echo htmlspecialchars($transactionPageUrl . ($transactionsPage + 1), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?></nav></div><?php endif; ?></section></div></main></body></html>