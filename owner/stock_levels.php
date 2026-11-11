<?php
session_start();
include 'config.php';
include 'functions.php';
include 'access_control.php';

requireOwnerPageAccess($conn, true);

$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_alert_sent TINYINT(1) NOT NULL DEFAULT 0");
ensureLowStockNotificationSchema($conn);

$currentPage = basename($_SERVER['PHP_SELF']);
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$statusFilter = trim($_GET['stock_status'] ?? '');
$stockPage = max(1, (int)($_GET['page'] ?? 1));
$stockPerPage = 10;
$searchSql = $conn->real_escape_string($search);
$categorySql = $conn->real_escape_string($category);
$categoryColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'category'");
$hasCategory = $categoryColumnResult && $categoryColumnResult->num_rows > 0;
$skuColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'sku'");
$hasSku = $skuColumnResult && $skuColumnResult->num_rows > 0;
$alertColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'low_stock_alert_sent'");
$hasAlertColumn = $alertColumnResult && $alertColumnResult->num_rows > 0;
$thresholdColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'low_stock_threshold'");
$hasThresholdColumn = $thresholdColumnResult && $thresholdColumnResult->num_rows > 0;
$priceColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'price'");
$hasPriceColumn = $priceColumnResult && $priceColumnResult->num_rows > 0;
$pricePieceColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'price_piece'");
$hasPricePieceColumn = $pricePieceColumnResult && $pricePieceColumnResult->num_rows > 0;
$priceBoxColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'price_box'");
$hasPriceBoxColumn = $priceBoxColumnResult && $priceBoxColumnResult->num_rows > 0;
$piecesPerBoxExpression = 'COALESCE(p.pieces_per_box, 12)';
$splitStockExpression = "(COALESCE(p.pieces, 0) + (COALESCE(p.box, 0) * $piecesPerBoxExpression))";
$stockExpression = "COALESCE(p.quantity, $splitStockExpression)";
$inventoryValueExpression = $hasPriceColumn
    ? "$stockExpression * COALESCE(p.price, 0)"
    : ($hasPricePieceColumn
        ? "COALESCE(p.pieces, 0) * COALESCE(p.price_piece, 0)" . ($hasPriceBoxColumn ? " + COALESCE(p.box, 0) * COALESCE(p.price_box, 0)" : '')
        : '0');
$where = '1=1';
if ($search !== '') {
    $where .= " AND (p.product_name LIKE '%$searchSql%'";
    if ($hasSku) {
        $where .= " OR p.sku LIKE '%$searchSql%'";
    }
    if ($hasCategory) {
        $where .= " OR p.category LIKE '%$searchSql%'";
    }
    $where .= ')';
}
if ($hasCategory && $category !== '') {
    $where .= " AND p.category = '$categorySql'";
}
if ($statusFilter === 'in_stock') {
    $where .= " AND $stockExpression > COALESCE(p.low_stock_threshold, 20)";
} elseif ($statusFilter === 'low_stock') {
    $where .= " AND $stockExpression BETWEEN 1 AND COALESCE(p.low_stock_threshold, 20)";
} elseif ($statusFilter === 'out_of_stock') {
    $where .= " AND $stockExpression <= 0";
}
$countResult = $conn->query("SELECT COUNT(*) AS total FROM products p WHERE $where");
if ($countResult === false) {
    die('Stock Levels database error: ' . htmlspecialchars($conn->error));
}
$totalProducts = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
$stockTotalPages = max(1, (int)ceil($totalProducts / $stockPerPage));
$stockPage = min($stockPage, $stockTotalPages);
$stockOffset = ($stockPage - 1) * $stockPerPage;
$stockPageItems = [];
if ($stockTotalPages <= 5) {
    $stockPageItems = range(1, $stockTotalPages);
} elseif ($stockPage <= 3) {
    $stockPageItems = [1, 2, 3, '...', $stockTotalPages];
} elseif ($stockPage >= $stockTotalPages - 2) {
    $stockPageItems = [1, '...', $stockTotalPages - 2, $stockTotalPages - 1, $stockTotalPages];
} else {
    $stockPageItems = [1, '...', $stockPage - 1, $stockPage, $stockPage + 1, '...', $stockTotalPages];
}

$stats = [];
$statsResult = $conn->query("SELECT COUNT(*) AS total,
    SUM(CASE WHEN $stockExpression > COALESCE(p.low_stock_threshold, 20) THEN 1 ELSE 0 END) AS in_stock,
    SUM(CASE WHEN $stockExpression BETWEEN 1 AND COALESCE(p.low_stock_threshold, 20) THEN 1 ELSE 0 END) AS low_stock,
    SUM(CASE WHEN $stockExpression <= 0 THEN 1 ELSE 0 END) AS out_stock,
    COALESCE(SUM($inventoryValueExpression), 0) AS inventory_value
    FROM products p");
if ($statsResult) {
    $stats = $statsResult->fetch_assoc();
}

$lowStockAlerts = [];
$lowStockResult = $conn->query("SELECT p.id, p.product_name, $stockExpression AS current_stock, COALESCE(p.low_stock_threshold, 20) AS low_stock_threshold" . ($hasAlertColumn ? ', p.low_stock_alert_sent' : '') . " FROM products p WHERE $stockExpression <= COALESCE(p.low_stock_threshold, 20) ORDER BY current_stock ASC");
if ($lowStockResult) {
    while ($lowProduct = $lowStockResult->fetch_assoc()) {
        $lowStockAlerts[] = $lowProduct;
        checkAndSendLowStockAlert($conn, (int)$lowProduct['id']);
    }
}
if ($hasAlertColumn) {
    $conn->query("UPDATE products p SET low_stock_alert_sent = 0 WHERE $stockExpression > COALESCE(p.low_stock_threshold, 20) AND p.low_stock_alert_sent = 1");
}

$categoryOptions = [
    ['value' => 'OTC Medicine', 'label' => 'OTC Medicine'],
    ['value' => 'Prescription Medicine', 'label' => 'Prescription Medicine'],
    ['value' => 'Vitamins & Supplements', 'label' => 'Vitamins & Supplements'],
    ['value' => 'Herbal Medicine', 'label' => 'Herbal Medicine'],
    ['value' => 'Baby & Infant Products', 'label' => 'Baby & Infant Products'],
    ['value' => 'Topical & Personal Care', 'label' => 'Topical & Personal Care'],
    ['value' => 'Medical Supplies', 'label' => 'Medical Supplies'],
    ['value' => 'Hygiene & Personal Care', 'label' => 'Hygiene & Personal Care'],
];
$products = $conn->query("SELECT p.* FROM products p WHERE $where
    ORDER BY
        CASE
            WHEN $stockExpression <= 0 THEN 0
            WHEN $stockExpression <= COALESCE(p.low_stock_threshold, 20) THEN 1
            ELSE 2
        END ASC,
        CASE WHEN $stockExpression > 0 THEN $stockExpression ELSE 0 END ASC,
        p.product_name ASC
    LIMIT $stockPerPage OFFSET $stockOffset");
if ($products === false) {
    die('Stock Levels product query error: ' . htmlspecialchars($conn->error));
}

function stockUrl($page, $search, $category, $statusFilter) {
    return '?page=' . (int)$page . '&search=' . urlencode($search) . '&category=' . urlencode($category) . '&stock_status=' . urlencode($statusFilter);
}
function stockState($quantity, $threshold) {
    if ($quantity <= 0) return ['Out of Stock', 'out'];
    if ($quantity <= $threshold) return ['Low Stock', 'low'];
    return ['In Stock', 'active'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stock Levels | Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined">
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f7f8fa;color:#20232a;font-family:'Segoe UI',Tahoma,sans-serif}
.content{margin-left:244px;padding:26px 28px 34px;min-height:100vh}
.page-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.page-head h1{margin:0 0 4px;font-size:22px}
.page-head p{margin:0;color:#7b8189;font-size:11px}
.head-actions{display:flex;align-items:center;gap:18px}
.head-search{width:165px;height:29px;padding:0 12px;border:1px solid #e8eaed;border-radius:7px;background:#fff;font-size:10px}
.admin-name{font-size:12px;font-weight:700}
.metrics{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:26px}
.metric{display:flex;align-items:center;gap:12px;min-height:88px;padding:14px 13px;background:#fff;border:1px solid #eff0f2;border-radius:9px;box-shadow:0 2px 9px rgba(25,30,38,.04)}
.metric-icon{width:43px;height:43px;border-radius:50%;display:grid;place-items:center;font-size:21px;flex:0 0 auto}
.metric-icon.pink{background:#ffe8ed;color:#df274b}
.metric-icon.green{background:#e8f7ef;color:#15955c}
.metric-icon.orange{background:#fff5de;color:#e9a316}
.metric-icon.red{background:#ffe8ed;color:#db294b}
.metric-icon.purple{background:#f1e8ff;color:#7a38c5}
.metric{cursor:pointer;transition:all .2s ease}
.metric:hover{box-shadow:0 4px 16px rgba(25,30,38,.1);transform:translateY(-2px);border-color:#d4d7dc}
.label{color:#6e747c;font-size:10px}
.value{margin-top:5px;font-size:17px;font-weight:700}
.value.red{color:#db294b}
.value.green{color:#15955c}
.value.orange{color:#e9a316}
.value.purple{color:#7a38c5}
.note{margin-top:3px;color:#51a47b;font-size:9px}
.note.down{color:#ef5367}
.panel{overflow:hidden;background:#fff;border:1px solid #ebedf0;border-radius:8px;box-shadow:0 2px 10px rgba(25,30,38,.04)}
.toolbar{display:flex;gap:12px;padding:16px 10px 4px}
.toolbar form{display:flex;gap:12px;width:100%}
.search-input{height:29px;flex:1;min-width:170px;padding:0 12px;border:1px solid #e5e7eb;border-radius:6px;font-size:10px}
.toolbar select,.filter-btn,.export-btn{height:29px;padding:0 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;color:#4e5660;font-size:10px}
.toolbar select{min-width:112px}
.filter-btn{padding:0 14px}
.export-btn{margin-left:auto;background:#fff0f2;border-color:#fff0f2;color:#e32248}
.table-scroll{overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain}
.stock-table{width:100%;min-width:1000px;border-collapse:collapse;table-layout:auto}
.stock-table th{padding:10px 8px;text-align:left;color:#68707a;font-size:9px;font-weight:600;border-top:1px solid #f0f1f3;border-bottom:1px solid #f0f1f3}
.stock-table td{padding:10px 8px;text-align:left;font-size:10px;border-bottom:1px solid #f0f1f3;word-break:break-word}
.product-cell{display:flex;align-items:center;gap:9px;min-width:150px}
.product-thumb{width:28px;height:28px;display:grid;place-items:center;border-radius:5px;background:#f3f6f8;color:#7d8790;overflow:hidden}
.product-thumb img{width:100%;height:100%;object-fit:contain}
.product-cell strong{display:block;font-size:10px}
.product-cell small{display:block;color:#8b9198;font-size:8px;margin-top:2px}
.category-pill{display:block;width:max-content;max-width:100%;margin-top:4px;padding:4px 8px;border-radius:4px;background:#edf6ff;color:#3180c7;font-size:9px}
.stock-value{font-weight:700}
.stock-value.good{color:#15955c}
.stock-value.warn{color:#e99416}
.stock-value.zero{color:#db294b}
.status{padding:5px 8px;border-radius:4px;font-size:9px}
.status.active{background:#e5f7ed;color:#278658}
.status.low{background:#fff5df;color:#bd8314}
.status.out{background:#ffe8ed;color:#db294b}
.action{width:27px;height:25px;margin-left:4px;border:1px solid #eceef1;border-radius:5px;background:#fff;color:#606771}
.footer{display:flex;justify-content:space-between;align-items:center;padding:12px;font-size:9px;color:#727984}
.pagination{display:flex;gap:5px}
.pagination a,.pagination span{width:28px;height:25px;display:grid;place-items:center;border:1px solid #eceef1;border-radius:5px;color:#424951;text-decoration:none}
.pagination .current{background:#fff0f2;color:#e32248;border-color:#ffdbe2}
.empty{text-align:center;padding:30px;color:#727984}
@media(max-width:1100px){.metrics{grid-template-columns:repeat(3,1fr)}.stock-table th:nth-child(3),.stock-table td:nth-child(3),.stock-table th:nth-child(7),.stock-table td:nth-child(7),.stock-table th:nth-child(8),.stock-table td:nth-child(8){display:none}}
@media(max-width:720px){.content{margin-left:0;padding:20px 14px}.head-actions{display:none}.metrics{grid-template-columns:1fr 1fr}.toolbar form{flex-wrap:wrap}.search-input{min-width:100%}.export-btn{margin-left:0}.footer{align-items:flex-start;gap:10px;flex-direction:column}.stock-table th:nth-child(3),.stock-table td:nth-child(3),.stock-table th:nth-child(4),.stock-table td:nth-child(4),.stock-table th:nth-child(7),.stock-table td:nth-child(7),.stock-table th:nth-child(8),.stock-table td:nth-child(8){display:none}.product-cell{min-width:120px}.product-cell strong{font-size:9px}.product-cell small{font-size:7px}}
.stock-warning{margin-left:7px;color:#f59e0b;font-size:14px;vertical-align:middle}.stock-warning.out{color:#dc2626}
.stock-value.good,.status.active{color:#15955c}
.stock-value.warn,.status.low{color:#f59e0b}
.stock-value.zero,.status.out{color:#dc2626}
.action{display:inline-grid;place-items:center;text-decoration:none;cursor:pointer}
.stock-alert-button{width:32px;height:30px;border:1px solid #f3cf78;border-radius:6px;background:#fff8e7;color:#b96d00;font-size:15px;cursor:pointer}
.stock-alert-button:hover,.stock-alert-button:focus-visible{border-color:#b96d00;background:#fff1cc;outline:none}
.stock-alert-button.healthy{border-color:#b8e7d7;background:#e8f7ef;color:#15955c}
.stock-alert-modal{position:fixed;inset:0;z-index:1200;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(20,25,33,.48)}
.stock-alert-modal[hidden]{display:none}
.stock-alert-dialog{width:min(100%,460px);padding:22px;border:1px solid #ebedf0;border-radius:12px;background:#fff;box-shadow:0 18px 50px rgba(20,25,33,.24)}
.stock-alert-dialog-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
.stock-alert-dialog h2{margin:0;color:#20232a;font-size:18px}
.stock-alert-dialog-close{width:30px;height:30px;border:0;border-radius:6px;background:#f3f4f6;color:#606771;font-size:20px;cursor:pointer}
.stock-alert-product{margin:14px 0;color:#4e5660;font-size:14px;font-weight:700}
.stock-alert-status{display:inline-block;margin-bottom:12px;padding:5px 9px;border-radius:5px;font-size:12px;font-weight:700}
.stock-alert-status.low{background:#fff5df;color:#b96d00}
.stock-alert-status.out{background:#ffe8ed;color:#c91437}
.stock-alert-status.active{background:#e8f7ef;color:#15955c}
.stock-alert-detail{display:grid;grid-template-columns:1fr auto;gap:10px;padding:12px 0;border-top:1px solid #edf0f3;color:#68707a;font-size:13px}
.stock-alert-detail strong{color:#20232a}
.stock-alert-dialog-message{margin:4px 0 16px;color:#626a74;font-size:13px;line-height:1.5}
.stock-alert-dialog-link{display:inline-flex;align-items:center;gap:7px;padding:9px 12px;border-radius:6px;background:#b96d00;color:#fff;font-size:12px;font-weight:700;text-decoration:none}
.stock-alert-dialog-link:hover{background:#995900;color:#fff}
.low-stock-alert-banner{display:flex;align-items:center;gap:14px;margin:0 0 16px;padding:12px 16px;border:1px solid #f3cf78;border-radius:8px;background:#fff8e7;color:#8b5d00}
.low-stock-alert-icon{display:inline-flex;width:38px;height:38px;flex:0 0 38px;align-items:center;justify-content:center;font-size:24px}
.low-stock-alert-copy{flex:1 1 auto;min-width:0}
.low-stock-alert-copy strong,.low-stock-alert-copy span,.low-stock-alert-copy small{display:block}
.low-stock-alert-copy strong{color:#303740;font-size:14px}
.low-stock-alert-copy span{margin-top:2px;color:#626a74;font-size:13px}
.low-stock-alert-copy small{margin-top:3px;color:#8b7353;font-size:11px}
.low-stock-alert-link{display:inline-flex;flex:0 0 auto;align-items:center;gap:8px;padding:8px 12px;border-radius:6px;background:#b96d00;color:#fff;font-size:12px;font-weight:700;text-decoration:none}
.low-stock-alert-link:hover{background:#995900;color:#fff}
@media(max-width:600px){.low-stock-alert-banner{align-items:flex-start;flex-wrap:wrap;gap:8px 12px}.low-stock-alert-copy{flex:1 1 calc(100% - 52px)}.low-stock-alert-link{margin-left:50px}}
.search-input-wrapper button#clearStockSearch{align-items:center;justify-content:center;width:22px;height:22px;padding:0!important;border:0!important;border-radius:50%;background:#fff!important;color:#db294b!important;font-size:17px!important;font-weight:700;line-height:1;cursor:pointer}.search-input-wrapper button#clearStockSearch:hover{background:#fff0f2!important;color:#b91c3c!important}
.stock-pagination{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.stock-pager-link{min-width:38px;height:38px;padding:0 8px;border:1px solid #e0e6f0;border-radius:8px;background:#fff;color:#4b5563;display:inline-flex;align-items:center;justify-content:center;text-align:center;text-decoration:none;font-size:14px;font-weight:600;transition:all .2s ease}.stock-pager-link:hover{border-color:#d32f2f;color:#d32f2f;background:#fff5f5}.stock-pager-link.active{background:#d32f2f;color:#fff;border-color:#d32f2f;box-shadow:0 4px 12px rgba(211,47,47,.2)}.stock-pager-link.disabled{border:0;background:none;color:#010813;cursor:default;pointer-events:none}
html,body{min-height:100%;height:auto;overflow-x:hidden;overflow-y:auto}.content{min-height:100vh;height:auto;overflow:visible}
body{font-size:14px}.page-head h1{font-size:26px;font-weight:800}.page-head p{font-size:13px;font-weight:600}.label{font-size:12px;font-weight:700}.value{font-size:21px}.note{font-size:11px;font-weight:600}.toolbar input,.toolbar select,.filter-btn,.export-btn{font-size:12px;font-weight:600}.stock-table th{font-size:12px;font-weight:800;padding:12px 10px}.stock-table td{font-size:12px;font-weight:600;padding:12px 10px}.product-cell strong{font-size:12px;font-weight:800}.product-cell small{font-size:10px}.category-pill,.status{font-size:11px;font-weight:700}.footer{font-size:11px;font-weight:600}.low-stock-alarm strong{font-size:13px}.low-stock-alarm span{font-size:11px}
@media(min-width:1200px){
    .stock-table { width:calc(100% - 32px); min-width:0; margin:12px 16px; table-layout:fixed; }
    .stock-table thead { background:#e9ecef; }
    .stock-table th { background:transparent; }
    .stock-table th:nth-child(1), .stock-table td:nth-child(1) { width:25%; }
    .stock-table th:nth-child(2), .stock-table td:nth-child(2) { width:10%; }
    .stock-table th:nth-child(3), .stock-table td:nth-child(3) { width:10%; }
    .stock-table th:nth-child(4), .stock-table td:nth-child(4) { width:12%; }
    .stock-table th:nth-child(5), .stock-table td:nth-child(5) { width:11%; }
    .stock-table th:nth-child(6), .stock-table td:nth-child(6) { width:12%; }
    .stock-table th:nth-child(7), .stock-table td:nth-child(7) { width:13%; }
    .stock-table th:nth-child(8), .stock-table td:nth-child(8) { width:7%; }
    .stock-table th { padding:12px 8px; font-size:12px; }
    .stock-table td { padding:12px 8px; font-size:12px; }
    .stock-table th:nth-child(8) { padding-left:4px; padding-right:4px; white-space:nowrap; }
    .stock-table td:nth-child(8) { padding-left:4px; padding-right:4px; }
    .product-cell { gap:9px; min-width:0; }
    .product-thumb { width:38px; height:38px; }
    .product-cell strong { font-size:13px; }
    .product-cell small { font-size:11px; }
    .category-pill, .status { padding:6px 10px; font-size:12px; }
    .action { width:32px; height:30px; }
}
.export-btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; height:34px; padding:0 12px; border:1px solid #e4e7eb; border-radius:6px; background:#fff; color:#4e5660; font-size:12px; font-weight:700; white-space:nowrap; }
.export-btn:hover { border-color:#d5dbe1; background:#f9fafb; color:#4e5660; }
.export-btn i { font-size:13px; line-height:1; }
body { background:#f8f9fb; }
.content { padding:36px 40px 34px; }
.metric { height:112px; }
.metric .label { font-size:16px; line-height:18px; font-weight:700; }
.metric .value { margin-top:4px; font-size:32px; line-height:32px; font-weight:800; }
.metric .note { margin-top:4px; font-size:14px; line-height:16px; font-weight:600; }
.stock-table th { background:#e9ecef; color:#495057; }
.stock-table th:first-child, .stock-table td:first-child { text-align:left; }
.stock-table th:not(:first-child), .stock-table td:not(:first-child) { text-align:center; }
.stock-table th { padding:12px 10px; }
.stock-table td { padding:14px 10px; }
.search-input-wrapper .search-icon { position:absolute; top:50%; left:12px; color:#89919a; font-size:14px; transform:translateY(-50%); pointer-events:none; }
.search-input-wrapper .search-input { width:100%; padding-left:36px; }
.export-btn { border-color:#ed1b3b; background:#ed1b3b; color:#fff; }
.export-btn:hover { border-color:#d91635; background:#d91635; color:#fff; }
.metric-icon { display:grid; place-items:center; padding:0; text-align:center; line-height:1; }
.metric-icon.material-icons-outlined { display:grid !important; place-items:center !important; }
.content .panel .toolbar .export-btn { border-color:#ed1b3b !important; background:#ed1b3b !important; color:#fff !important; }
.content .panel .toolbar .export-btn:hover { border-color:#d91635 !important; background:#d91635 !important; color:#fff !important; }
.metric-icon.material-icons-outlined { font-family:'Material Icons Outlined'; font-weight:normal; font-style:normal; letter-spacing:normal; text-transform:none; white-space:nowrap; direction:ltr; -webkit-font-feature-settings:'liga'; -webkit-font-smoothing:antialiased; }
@media(max-width:720px){.content{margin-left:0;padding:20px 14px 28px}}
</style>
</head>
<body>
<?php include 'sidebar_owner.php'; ?>
<main class="content">
    <header class="page-head"><div><h1>Stock Levels</h1><p>Monitor and manage your product inventory levels.</p></div></header>
    <?php $outOfStockCount = (int)($stats['out_stock'] ?? 0); $lowStockCount = (int)($stats['low_stock'] ?? 0); $productsNeedingAttention = $outOfStockCount + $lowStockCount; ?>
    <?php if ($productsNeedingAttention > 0): ?>
    <div class="low-stock-alert-banner" role="alert">
        <i class="bi bi-exclamation-triangle-fill low-stock-alert-icon" aria-hidden="true"></i>
        <div class="low-stock-alert-copy"><strong>Low stock alerts</strong><span><?php echo number_format($productsNeedingAttention); ?> products need attention</span><small><?php echo number_format($outOfStockCount); ?> critical &middot; <?php echo number_format($lowStockCount); ?> low stock</small></div>
        <a class="low-stock-alert-link" href="inventory_alerts.php">View alerts <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </div>
    <?php endif; ?>
    <section class="metrics">
        <div class="metric" onclick="filterByStatus('')"><div class="metric-icon pink material-icons-outlined" aria-hidden="true">inventory_2</div><div><div class="label">Total Products</div><div class="value red"><?php echo number_format((int)($stats['total'] ?? 0)); ?></div><div class="note">↑ All products in store</div></div></div>
        <div class="metric" onclick="filterByStatus('in_stock')"><div class="metric-icon green material-icons-outlined" aria-hidden="true">inventory</div><div><div class="label">In Stock</div><div class="value green"><?php echo number_format((int)($stats['in_stock'] ?? 0)); ?></div><div class="note">↑ Healthy inventory</div></div></div>
        <div class="metric" onclick="filterByStatus('low_stock')"><div class="metric-icon orange material-icons-outlined" aria-hidden="true">warning_amber</div><div><div class="label">Low Stock</div><div class="value orange"><?php echo number_format((int)($stats['low_stock'] ?? 0)); ?></div><div class="note down">↓ Needs attention</div></div></div>
        <div class="metric" onclick="filterByStatus('out_of_stock')"><div class="metric-icon red material-icons-outlined" aria-hidden="true">remove_shopping_cart</div><div><div class="label">Out of Stock</div><div class="value red"><?php echo number_format((int)($stats['out_stock'] ?? 0)); ?></div><div class="note down">↓ Restock required</div></div></div>
        <div class="metric"><div class="metric-icon purple material-icons-outlined" aria-hidden="true">payments</div><div><div class="label">Total Inventory Value</div><div class="value purple">₱<?php echo number_format((float)($stats['inventory_value'] ?? 0), 2); ?></div><div class="note">↑ Current stock value</div></div></div>
    </section>
    <section class="panel">
        <div class="toolbar"><form method="get"><div class="search-input-wrapper" style="position:relative;flex:1"><i class="bi bi-search search-icon" aria-hidden="true"></i><input id="stockSearch" class="search-input" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by product name, SKU or barcode..." aria-label="Search stock"><button id="clearStockSearch" type="button" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);border:none;background:none;color:#e5e7eb;font-size:14px;cursor:pointer;display:<?php echo $search ? 'block' : 'none'; ?>" title="Clear">✕</button></div><select name="category" aria-label="Category" onchange="this.form.submit()"><option value="">All Categories</option><?php foreach($categoryOptions as $categoryOption): ?><option value="<?php echo htmlspecialchars($categoryOption['value']); ?>" <?php echo $category === $categoryOption['value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($categoryOption['label']); ?></option><?php endforeach; ?></select><select name="stock_status" aria-label="Stock status" onchange="this.form.submit()"><option value="">All Status</option><option value="in_stock" <?php echo $statusFilter==='in_stock'?'selected':''; ?>>In Stock</option><option value="low_stock" <?php echo $statusFilter==='low_stock'?'selected':''; ?>>Low Stock</option><option value="out_of_stock" <?php echo $statusFilter==='out_of_stock'?'selected':''; ?>>Out of Stock</option></select><button class="export-btn" type="button" onclick="window.print()"><i class="bi bi-box-arrow-up" aria-hidden="true"></i> Export</button></form></div>
        <div class="table-scroll"><table class="stock-table"><thead><tr><th>Product</th><th>SKU</th><th>Supplier</th><th>Stock<br>Quantity</th><th>Status</th><th>Stock<br>Value</th><th>Last<br>Updated</th><th>Alert</th></tr></thead><tbody>
        <?php if($products && $products->num_rows): while($product=$products->fetch_assoc()): $piecesPerBox=(int)($product['pieces_per_box'] ?? 12); $threshold=max(0,(int)($product['low_stock_threshold'] ?? 20)); $quantity=isset($product['quantity']) ? (int)$product['quantity'] : (int)($product['pieces'] ?? 0) + ((int)($product['box'] ?? 0) * $piecesPerBox); [$statusText,$statusClass]=stockState($quantity,$threshold); $price=(float)($product['price'] ?? ($product['price_piece'] ?? 0)); $image=trim((string)($product['image'] ?? '')); $productForm=trim((string)($product['product_form'] ?? '')); $lastUpdated=$product['updated_at'] ?? ($product['created_at'] ?? null); ?>
            <tr><td><div class="product-cell"><span class="product-thumb"><?php if($image):?><img src="uploads/<?php echo htmlspecialchars($image); ?>" alt=""><?php else:?>▦<?php endif;?></span><div><strong><?php echo htmlspecialchars($product['product_name']); ?></strong><span class="category-pill"><?php echo htmlspecialchars(($product['category'] ?? '') ?: 'General'); ?></span><small><?php echo htmlspecialchars($productForm ?: 'Product'); ?></small></div></div></td><td><?php echo htmlspecialchars($product['sku'] ?? '—'); ?></td><td>Unassigned</td><td class="stock-value <?php echo $statusClass==='active'?'good':($statusClass==='low'?'warn':'zero'); ?>"><?php echo number_format($quantity); ?><?php if($statusClass !== 'active'): ?> <i class="bi bi-exclamation-triangle-fill stock-warning <?php echo $statusClass === 'out' ? 'out' : ''; ?>" role="img" aria-label="<?php echo $statusClass === 'out' ? 'Out of stock' : 'Low stock'; ?>" title="<?php echo $statusClass === 'out' ? 'Out of stock' : 'Low stock'; ?>"></i><?php endif; ?></td><td><span class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td><td>₱<?php echo number_format($quantity*$price, 2); ?></td><td><?php if($lastUpdated): echo date('M d, Y', strtotime($lastUpdated)); ?><small style="display:block;color:#8b9198;font-size:8px"><?php echo date('h:i A', strtotime($lastUpdated)); ?></small><?php else: ?>Not available<?php endif; ?></td><td><button type="button" class="stock-alert-button <?php echo $statusClass === 'active' ? 'healthy' : ''; ?>" data-product-name="<?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>" data-current-stock="<?php echo $quantity; ?>" data-threshold="<?php echo $threshold; ?>" data-stock-state="<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>" data-status-text="<?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?>" aria-label="View stock alert for <?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>" title="View stock alert"><i class="bi bi-bell<?php echo $statusClass === 'active' ? '' : '-fill'; ?>" aria-hidden="true"></i></button></td></tr>
        <?php endwhile; else: ?><tr><td colspan="8" class="empty">No products found.</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="footer"><span>Showing <?php echo $totalProducts ? $stockOffset+1 : 0; ?> to <?php echo min($stockOffset+$stockPerPage,$totalProducts); ?> of <?php echo number_format($totalProducts); ?> products</span><?php if ($totalProducts > $stockPerPage): ?><nav class="stock-pagination" aria-label="Stock level pages"><?php if ($stockPage > 1): ?><a class="stock-pager-link" href="<?php echo stockUrl($stockPage-1,$search,$category,$statusFilter); ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?><?php foreach ($stockPageItems as $stockPageItem): ?><?php if ($stockPageItem === '...'): ?><span class="stock-pager-link disabled" aria-hidden="true">...</span><?php else: ?><a class="stock-pager-link <?php echo $stockPageItem === $stockPage ? 'active' : ''; ?>" href="<?php echo stockUrl($stockPageItem,$search,$category,$statusFilter); ?>" <?php echo $stockPageItem === $stockPage ? 'aria-current="page"' : ''; ?>><?php echo $stockPageItem; ?></a><?php endif; ?><?php endforeach; ?><?php if ($stockPage < $stockTotalPages): ?><a class="stock-pager-link" href="<?php echo stockUrl($stockPage+1,$search,$category,$statusFilter); ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?></nav><?php endif; ?></div>
    </section>
</main>
<div class="stock-alert-modal" id="stockAlertModal" hidden>
    <section class="stock-alert-dialog" role="dialog" aria-modal="true" aria-labelledby="stockAlertTitle" aria-describedby="stockAlertMessage">
        <div class="stock-alert-dialog-header">
            <h2 id="stockAlertTitle">Stock alert</h2>
            <button type="button" class="stock-alert-dialog-close" id="closeStockAlert" aria-label="Close stock alert">&times;</button>
        </div>
        <p class="stock-alert-product" id="stockAlertProduct"></p>
        <span class="stock-alert-status" id="stockAlertStatus"></span>
        <div class="stock-alert-detail"><span>Current stock</span><strong id="stockAlertQuantity"></strong></div>
        <div class="stock-alert-detail"><span>Low-stock threshold</span><strong id="stockAlertThreshold"></strong></div>
        <p class="stock-alert-dialog-message" id="stockAlertMessage"></p>
        <a class="stock-alert-dialog-link" href="inventory_alerts.php">Open Inventory Alerts <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </section>
</div>
<script>
// Clear search button functionality for stock levels
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('stockSearch');
    const clearBtn = document.getElementById('clearStockSearch');
    
    if (searchInput && clearBtn) {
        searchInput.addEventListener('input', function() {
            clearBtn.style.display = this.value ? 'block' : 'none';
        });
        
        clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            searchInput.value = '';
            clearBtn.style.display = 'none';
            searchInput.form.submit();
        });
    }

    const alertModal = document.getElementById('stockAlertModal');
    const closeAlertButton = document.getElementById('closeStockAlert');
    let lastAlertTrigger = null;

    const closeStockAlert = function () {
        alertModal.hidden = true;
        if (lastAlertTrigger) lastAlertTrigger.focus();
    };

    document.querySelectorAll('.stock-alert-button').forEach(function (button) {
        button.addEventListener('click', function () {
            lastAlertTrigger = button;
            const productName = button.dataset.productName || 'Product';
            const currentStock = Number(button.dataset.currentStock);
            const threshold = Number(button.dataset.threshold);
            const isOutOfStock = currentStock <= 0;
            const isLowStock = currentStock > 0 && currentStock <= threshold;
            const status = isOutOfStock ? 'out' : (isLowStock ? 'low' : 'active');
            const statusLabel = isOutOfStock ? 'Out of stock' : (isLowStock ? 'Low stock' : 'In stock');

            document.getElementById('stockAlertProduct').textContent = productName;
            document.getElementById('stockAlertStatus').textContent = statusLabel;
            document.getElementById('stockAlertStatus').className = 'stock-alert-status ' + status;
            document.getElementById('stockAlertQuantity').textContent = Number.isFinite(currentStock) ? currentStock.toLocaleString() + ' units' : 'Unavailable';
            document.getElementById('stockAlertThreshold').textContent = Number.isFinite(threshold) ? threshold.toLocaleString() + ' units' : 'Unavailable';
            document.getElementById('stockAlertMessage').textContent = isOutOfStock
                ? 'This product is out of stock and needs immediate restocking.'
                : (isLowStock
                    ? 'The current stock has reached the low-stock threshold and needs attention.'
                    : 'This product is above its low-stock threshold. There is no active stock alert.');
            alertModal.hidden = false;
            closeAlertButton.focus();
        });
    });

    closeAlertButton.addEventListener('click', closeStockAlert);
    alertModal.addEventListener('click', function (event) {
        if (event.target === alertModal) closeStockAlert();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !alertModal.hidden) closeStockAlert();
    });
});

// Filter by status metric click
function filterByStatus(status) {
    const url = new URL(window.location);
    if (status === '') {
        url.searchParams.delete('stock_status');
    } else {
        url.searchParams.set('stock_status', status);
    }
    url.searchParams.set('page', '1'); // Reset to first page
    window.location.href = url.toString();
}
</script>
</body>
</html>
