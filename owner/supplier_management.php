<?php
session_start();
include 'config.php';
include 'functions.php';
include 'access_control.php';

requireOwnerPageAccess($conn, true);

$message = '';
$error = '';
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS supplier_id INT NULL");
$supplierColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'supplier_id'");
$hasSupplierColumn = $supplierColumnResult && $supplierColumnResult->num_rows > 0;
$purchaseOrdersTableResult = $conn->query("SHOW TABLES LIKE 'purchase_orders'");
$hasPurchaseOrdersTable = $purchaseOrdersTableResult && $purchaseOrdersTableResult->num_rows > 0;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add_supplier') {
            $data = array(
                'name' => $_POST['name'],
                'contact_person' => $_POST['contact_person'],
                'email' => $_POST['email'],
                'phone' => $_POST['phone'],
                'address' => $_POST['address'],
                'city' => $_POST['city'],
                'province' => $_POST['province'],
                'postal_code' => $_POST['postal_code'],
                'company_name' => $_POST['company_name'],
                'tin_number' => $_POST['tin_number'],
                'payment_terms' => $_POST['payment_terms']
            );
            
            if (addSupplier($conn, $data)) {
                $message = "✅ Supplier added successfully!";
            } else {
                $error = "❌ Failed to add supplier";
            }
        } elseif ($_POST['action'] == 'update_supplier') {
            $supplier_id = (int)$_POST['supplier_id'];
            $data = array(
                'name' => $_POST['name'],
                'contact_person' => $_POST['contact_person'],
                'email' => $_POST['email'],
                'phone' => $_POST['phone'],
                'address' => $_POST['address'],
                'city' => $_POST['city'],
                'province' => $_POST['province'],
                'postal_code' => $_POST['postal_code'],
                'company_name' => $_POST['company_name'],
                'tin_number' => $_POST['tin_number'],
                'payment_terms' => $_POST['payment_terms']
            );
            
            if (updateSupplier($conn, $supplier_id, $data)) {
                $message = "✅ Supplier updated successfully!";
            } else {
                $error = "❌ Failed to update supplier";
            }
        } elseif ($_POST['action'] == 'toggle_status') {
            $supplier_id = (int)$_POST['supplier_id'];
            $is_active = (int)$_POST['is_active'];
            
            if (updateSupplier($conn, $supplier_id, array('is_active' => $is_active))) {
                $message = $is_active ? "✅ Supplier activated!" : "✅ Supplier deactivated!";
            } else {
                $error = "❌ Failed to update supplier status";
            }
        }
    }
}

$suppliers = getAllSuppliers($conn);
$supplierStatsResult = $conn->query("SELECT COUNT(*) AS total, SUM(is_active = 1) AS active, SUM(is_active = 0) AS inactive FROM suppliers");
$supplierStats = $supplierStatsResult ? $supplierStatsResult->fetch_assoc() : [];
$productStatsResult = $hasSupplierColumn ? $conn->query("SELECT COUNT(*) AS products FROM products WHERE supplier_id IS NOT NULL") : false;
$productStats = $productStatsResult ? $productStatsResult->fetch_assoc() : [];
$purchaseStatsResult = $hasPurchaseOrdersTable ? $conn->query("SELECT COALESCE(SUM(total_amount), 0) AS spend FROM purchase_orders") : false;
$purchaseStats = $purchaseStatsResult ? $purchaseStatsResult->fetch_assoc() : [];
$purchaseOrdersAvailable = $purchaseStatsResult !== false;
$totalSuppliers = (int)($supplierStats['total'] ?? 0);
$activeSuppliers = (int)($supplierStats['active'] ?? 0);
$inactiveSuppliers = (int)($supplierStats['inactive'] ?? 0);
$newSuppliersResult = $conn->query("SELECT COUNT(*) AS total FROM suppliers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$newSuppliers = $newSuppliersResult ? (int)($newSuppliersResult->fetch_assoc()['total'] ?? 0) : 0;
$suppliedProducts = (int)($productStats['products'] ?? 0);
$totalSpend = (float)($purchaseStats['spend'] ?? 0);
?>

<!DOCTYPE html>
<html>
<head>
<title>Supplier Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-2">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
    
    .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
    .header { margin-bottom: 30px; }
    .header h1 { color: #2c3e50; font-size: 28px; }
    
    .controls {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    
    .btn-new {
        padding: 10px 20px;
        background: #27ae60;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
    }
    
    .btn-new:hover { background: #229954; }
    
    .message {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
        font-weight: 500;
    }
    
    .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    
    .content-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th {
        background: #ecf0f1;
        padding: 15px;
        text-align: left;
        font-weight: 600;
        color: #2c3e50;
        font-size: 12px;
        text-transform: uppercase;
    }
    
    td {
        padding: 15px;
        border-bottom: 1px solid #ecf0f1;
    }
    
    tr:hover { background: #f9f9f9; }
    
    .badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .badge-active { background: #d4edda; color: #155724; }
    .badge-inactive { background: #f8d7da; color: #721c24; }
    
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    button {
        padding: 8px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
    }
    
    .btn-primary {
        background: #3498db;
        color: white;
    }
    
    .btn-danger {
        background: #e74c3c;
        color: white;
    }
    
    .btn-primary:hover { background: #2980b9; }
    .btn-danger:hover { background: #c0392b; }
    
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.4);
        overflow-y: auto;
    }
    
    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border: 1px solid #888;
        border-radius: 8px;
        width: 90%;
        max-width: 600px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.2);
    }
    
    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    
    .close:hover { color: black; }
    
    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }
    
    .form-group {
        margin-bottom: 15px;
    }
    
    .form-group.full {
        grid-column: 1 / -1;
    }
    
    label {
        display: block;
        margin-bottom: 5px;
        color: #2c3e50;
        font-weight: 500;
    }
    
    input, select, textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #bdc3c7;
        border-radius: 4px;
        font-size: 14px;
        font-family: inherit;
    }
    
    input:focus, select:focus, textarea:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 5px rgba(52, 152, 219, 0.5);
    }
    
    .btn-submit {
        background: #3498db;
        color: white;
        width: 100%;
        padding: 12px;
        border: none;
        border-radius: 4px;
        font-weight: 600;
        cursor: pointer;
        font-size: 14px;
    }
    
    .btn-submit:hover { background: #2980b9; }
    
    .back-button {
        display: inline-block;
        padding: 10px 20px;
        background: #3498db;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        margin-bottom: 20px;
    }
    
    .back-button:hover { background: #2980b9; }
    
    .supplier-details {
        font-size: 13px;
        color: #7f8c8d;
        line-height: 1.6;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #7f8c8d;
    }
</style>
<style>
body { background:#f7f8fa; color:#20232a; }
.container { max-width:none; margin:0 0 0 260px; padding:32px 36px 40px; }
.back-button, .controls { display:none; }
.header { margin:0 0 20px; }.header h1 { display:block; margin:0; color:#20232a; font-size:22px; }.header h1:before { content:'Suppliers'; }.header h1 { font-size:0; }.header h1:before { font-size:22px; }.header:after { content:'Manage your store suppliers and their information.'; display:block; color:#7b8189; font-size:11px; margin-top:4px; }
.supplier-metrics { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:26px; }.supplier-metric { display:flex; align-items:center; gap:12px; min-height:88px; padding:14px 13px; background:#fff; border:1px solid #eff0f2; border-radius:9px; box-shadow:0 2px 9px rgba(25,30,38,.04); }.supplier-icon { width:43px;height:43px;border-radius:50%;display:grid;place-items:center;font-size:20px;flex:0 0 auto }.supplier-icon.pink{background:#ffe8ed;color:#df274b}.supplier-icon.green{background:#e8f7ef;color:#15955c}.supplier-icon.blue{background:#e8f2ff;color:#1976d2}.supplier-icon.purple{background:#f1e8ff;color:#7a38c5}.supplier-icon.yellow{background:#fff5de;color:#e9a316}.supplier-label{color:#6e747c;font-size:10px}.supplier-value{margin-top:5px;font-size:17px;font-weight:700}.supplier-note{margin-top:3px;color:#51a47b;font-size:9px}.supplier-note.down{color:#ef5367}
.supplier-icon.material-icons-outlined { display:grid; place-items:center; text-align:center; padding:0; font-family:'Material Icons Outlined'; font-weight:normal; font-style:normal; line-height:1; letter-spacing:normal; text-transform:none; white-space:nowrap; direction:ltr; -webkit-font-feature-settings:'liga'; -webkit-font-smoothing:antialiased; }
.supplier-contact-email { display:block; margin-top:3px; color:#8b9198; font-size:8px; line-height:1.3; overflow-wrap:anywhere; }
.supplier-value.pink { color:#df274b; }
.supplier-value.green { color:#15955c; }
.supplier-value.blue { color:#1976d2; }
.supplier-value.purple { color:#7a38c5; }
.supplier-value.yellow { color:#e9a316; }
.supplier-toolbar { display:flex; gap:12px; padding:16px 10px 10px; }.supplier-search{flex:1;padding:0 12px;height:29px;border:1px solid #e5e7eb;border-radius:6px;font-size:10px}.supplier-filter{height:29px;padding:0 14px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;font-size:10px}.supplier-export,.supplier-add{height:29px;border:0;border-radius:6px;padding:0 14px;font-size:10px;font-weight:600}.supplier-export{background:#fff0f2;color:#e32248}.supplier-add{background:#ed1740;color:#fff}
.content-card{padding:0;background:#fff;border:1px solid #ebedf0;border-radius:8px;box-shadow:0 2px 10px rgba(25,30,38,.04);overflow:hidden}.supplier-table-wrap{width:100%;max-width:100%;overflow-x:auto}.content-card table{width:100%;min-width:0;table-layout:fixed}.content-card th{background:#fff;color:#68707a;text-transform:none;font-size:9px;padding:10px 8px;border-bottom:1px solid #f0f1f3}.content-card td{padding:10px 8px;border-bottom:1px solid #f0f1f3;font-size:10px;overflow-wrap:anywhere}.supplier-name{display:flex;align-items:center;gap:9px}.supplier-avatar{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:#e8f7ef;color:#15955c;font-size:14px}.supplier-name strong{display:block;font-size:10px}.supplier-name small{display:block;color:#8b9198;font-size:8px;margin-top:2px}.category{padding:5px 8px;border-radius:4px;background:#fff0f2;color:#df274b;font-size:9px}.supplier-status{padding:5px 9px;border-radius:5px;background:#e5f7ed;color:#278658;font-size:9px}.supplier-status.inactive{background:#f0f1f4;color:#737985}.supplier-actions{position:relative;z-index:1;display:flex;gap:5px;pointer-events:auto}.supplier-actions button{position:relative;z-index:1;width:27px;height:25px;padding:0;border:1px solid #eceef1;border-radius:5px;background:#fff;color:#606771;cursor:pointer;pointer-events:auto;touch-action:manipulation}.supplier-footer{display:flex;justify-content:space-between;align-items:center;padding:12px;font-size:9px;color:#727984}.supplier-pagination{display:flex;gap:5px}.supplier-pagination button,.supplier-pagination span{width:28px;height:25px;display:grid;place-items:center;border:1px solid #eceef1;border-radius:5px;background:#fff;color:#606771;font:inherit;cursor:pointer}.supplier-pagination .current{background:#fff0f2;color:#e32248;border-color:#ffdbe2}.supplier-pagination .ellipsis{border:0;background:none;cursor:default}.supplier-pagination[hidden]{display:none}.message{margin:0 0 14px}.modal{z-index:1000;background:rgba(24,28,35,.46)}.modal-content{width:90%;max-width:458px;margin:8% auto;padding:18px 16px 16px;border:0;border-radius:8px;background:#fff;box-shadow:0 16px 45px rgba(20,24,30,.22)}.modal-content h2{color:#252a31;font-size:15px;margin:0 0 5px}.modal-content h2:after{content:'Fill in the details below to add a new supplier.';display:block;color:#7b8189;font-size:10px;font-weight:400;margin-top:5px}.modal-content .close{color:#68707a;font-size:20px;line-height:20px}.modal-content form:before{content:'Supplier Information';display:block;border-bottom:1px solid #eceef1;padding:12px 0 9px;margin-bottom:10px;color:#30353d;font-weight:700;font-size:10px}.form-grid{gap:10px}.form-group{margin-bottom:4px}.form-group label{margin-bottom:5px;color:#30353d;font-size:9px}.form-group input,.form-group select,.form-group textarea{padding:8px 9px;border:1px solid #e4e7eb;border-radius:5px;font-size:10px}.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#ed1740;box-shadow:0 0 0 2px rgba(237,23,64,.1)}.btn-submit{width:auto;float:right;margin-top:14px!important;padding:9px 13px;background:#ed1740;border-radius:5px;font-size:10px}.modal-content form:after{content:'';display:block;clear:both}
@media(max-width:1100px){.supplier-metrics{grid-template-columns:repeat(3,1fr)}} @media(max-width:720px){.container{margin-left:0;padding:20px 14px}.supplier-metrics{grid-template-columns:1fr 1fr}.supplier-toolbar{flex-wrap:wrap}.supplier-search{min-width:100%}.supplier-footer{align-items:flex-start;gap:10px;flex-direction:column}}
</style>
<style>
body { overflow:hidden; }
.container { height:100vh; overflow-y:auto; }
.header h1:before { content:'Suppliers'; }
.supplier-metrics { grid-template-columns:repeat(5, minmax(0, 1fr)); gap:16px; }
.supplier-metric { min-height:102px; padding:17px 16px; }
.supplier-metric.clickable { cursor:pointer; transition:transform .15s ease, box-shadow .15s ease; }
.supplier-metric.clickable:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(25,30,38,.1); }
.supplier-icon { width:48px; height:48px; font-size:22px; }
.supplier-value { font-size:21px; }
.content-card { border-radius:10px; }
.supplier-toolbar { align-items:center; gap:10px; padding:16px; }
.supplier-toolbar > div:first-child { display:block; flex:1 1 auto; min-width:180px; }
.supplier-search { height:36px; font-size:11px; }
.supplier-filter, .supplier-export, .supplier-add { height:36px; font-size:11px; white-space:nowrap; }
.content-card table { min-width:0; }
.content-card th { padding:14px 12px; font-size:10px; font-weight:700; }
.content-card td { padding:14px 12px; font-size:11px; vertical-align:middle; }
.supplier-name strong { font-size:11px; }
.supplier-name small { font-size:9px; }
.supplier-avatar { width:34px; height:34px; font-size:16px; }
.supplier-status, .category { padding:6px 9px; font-size:10px; }
.supplier-actions button { width:32px; height:30px; font-size:14px; cursor:pointer; }
.supplier-actions button:hover { color:#ed1740; border-color:#ed1740; background:#fff7f8; }
.supplier-footer { padding:14px 16px; font-size:10px; }
@media(max-width:1100px){.supplier-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:720px){body{overflow:hidden}.container{height:100vh;overflow-y:auto}.supplier-metrics{grid-template-columns:1fr 1fr}.supplier-toolbar{align-items:stretch}.supplier-filter,.supplier-export,.supplier-add{flex:1}.content-card table{min-width:0}}
@media(min-width:1200px){
    .content-card { border-radius:10px; }
    .content-card table { min-width:0; }
    .content-card th { padding:16px 14px; font-size:12px; }
    .content-card td { padding:16px 14px; font-size:13px; }
    .supplier-name { gap:11px; }
    .supplier-avatar { width:40px; height:40px; font-size:18px; }
    .supplier-name strong { font-size:13px; }
    .supplier-name small { font-size:10px; }
    .category, .supplier-status { padding:7px 10px; font-size:11px; }
    .supplier-actions button { width:34px; height:32px; font-size:15px; }
    .supplier-footer { padding:16px 18px; font-size:12px; }
}
.supplier-export { display:inline-flex; flex:0 0 auto; align-items:center; justify-content:center; gap:7px; height:34px; padding:0 12px; border:1px solid #e4e7eb; border-radius:6px; background:#fff; color:#4e5660; font-size:12px; font-weight:700; white-space:nowrap; }
.supplier-export:hover { border-color:#d5dbe1; background:#f9fafb; color:#4e5660; }
.supplier-export i { font-size:13px; line-height:1; }
body { background:#f8f9fb; }
.container { max-width:none; margin-left:244px; padding:36px 40px 40px; }
.supplier-metric { height:112px; }
.supplier-label { font-size:16px; line-height:18px; font-weight:700; }
.supplier-value { margin-top:4px; font-size:32px; line-height:32px; font-weight:800; }
.supplier-note { margin-top:4px; font-size:14px; line-height:16px; font-weight:600; }
.header h1:before { color:#252a30; font-family:'Segoe UI', Tahoma, sans-serif; font-size:30px; font-weight:800; }
.supplier-toolbar { gap:9px; padding:15px; border-bottom:1px solid #f0f1f3; }
.supplier-search { height:34px; font-size:12px; }
.supplier-search-wrap .search-icon { position:absolute; top:50%; left:12px; color:#89919a; font-size:14px; transform:translateY(-50%); pointer-events:none; }
.supplier-search-wrap .supplier-search { width:100%; padding-left:34px; }
.supplier-filter#statusFilter { width:120px; flex:0 0 120px; height:34px; font-size:12px; }
.supplier-add { height:34px; padding:0 12px; border:1px solid #e0e5e9; background:#fff; color:#4e5660; font-size:12px; font-weight:600; }
.supplier-add:hover { border-color:#cdd4dc; background:#f9fafb; color:#4e5660; }
.supplier-export { height:34px; border-color:#ed1b3b; background:#ed1b3b; color:#fff; }
.supplier-export:hover { border-color:#d91635; background:#d91635; color:#fff; }
.supplier-actions .action-button { width:27px; height:27px; padding:0; border:1px solid #e1e5e9; border-radius:6px; background:#fff; color:#66707a; font-size:11px; }
.supplier-actions .action-button:hover { border-color:#ed1b3b; background:#fff; color:#ed1b3b; }
.supplier-actions { align-items:center; justify-content:center; }
.modal-content { width:min(620px, calc(100vw - 32px)); max-width:620px; max-height:calc(100vh - 32px); overflow-y:auto; margin:16px auto; padding:24px; border:0; border-radius:10px; }
.modal-content h2 { margin:0 0 8px; font-size:20px; line-height:24px; }
.modal-content h2:after { margin-top:6px; font-size:12px; line-height:18px; }
.modal-content form:before { padding:14px 0 11px; margin-bottom:14px; font-size:13px; }
.modal-content .form-grid { gap:14px 12px; }
.modal-content .form-group { margin-bottom:0; }
.modal-content .form-group label { margin-bottom:6px; font-size:12px; line-height:16px; font-weight:600; }
.modal-content .form-group input, .modal-content .form-group select, .modal-content .form-group textarea { min-height:40px; padding:8px 11px; border-radius:6px; font-size:13px; }
.modal-content .btn-submit { min-height:40px; margin-top:18px !important; padding:0 18px; border-radius:6px; font-size:13px; }
.modal-content .close { font-size:24px; }
@media(max-width:420px) { .modal-content { padding:18px; } .modal-content .form-grid { grid-template-columns:1fr; } }
.content-card th { background:#e9ecef; color:#495057; }
.content-card thead { background:#e9ecef; }
.content-card th { background:transparent; }
.content-card th:first-child, .content-card td:first-child { text-align:left; }
.content-card th:not(:first-child), .content-card td:not(:first-child) { text-align:center; }
.content-card th { padding:14px 16px; }
.content-card td { padding:14px 16px; vertical-align:middle; }
@media(min-width:1200px){.content-card th{font-size:16px;line-height:18px;font-weight:800}.content-card td{font-size:16px;line-height:18px;font-weight:400}}
@media(max-width:720px){.container{margin-left:0;padding:20px 14px}}
@media(min-width:1200px){.container{padding-left:40px;padding-right:40px}}
@media(min-width:1200px){
    .content-card table { width:calc(100% - 32px); min-width:0; margin:12px 16px; table-layout:fixed; }
    .content-card th:nth-child(1), .content-card td:nth-child(1) { width:21%; }
    .content-card th:nth-child(2), .content-card td:nth-child(2) { width:16%; }
    .content-card th:nth-child(3), .content-card td:nth-child(3) { width:9%; }
    .content-card th:nth-child(4), .content-card td:nth-child(4) { width:11%; }
    .content-card th:nth-child(5), .content-card td:nth-child(5) { width:13%; }
    .content-card th:nth-child(6), .content-card td:nth-child(6) { width:12%; }
    .content-card th:nth-child(7), .content-card td:nth-child(7) { width:8%; }
    .content-card th:nth-child(8), .content-card td:nth-child(8) { width:10%; }
    .content-card th:last-child, .content-card td:last-child { white-space:nowrap; }
    .supplier-actions { flex-wrap:nowrap; justify-content:center; }
    .supplier-actions .action-button { flex:0 0 34px; }
    .content-card td { overflow-wrap:anywhere; }
}
</style>
</head>
<body>

<?php include 'sidebar_owner.php'; ?>

<div class="container">
    <a href="dashboard.php" class="back-button">← Back to Dashboard</a>
    
    <div class="header">
        <h1>🏭 Supplier Management</h1>
    </div>
    
    <div class="supplier-metrics">
        <div class="supplier-metric"><div class="supplier-icon pink material-icons-outlined" aria-hidden="true">groups</div><div><div class="supplier-label">Total Suppliers</div><div class="supplier-value pink"><?php echo number_format($totalSuppliers); ?></div><div class="supplier-note">↑ All registered suppliers</div></div></div>
        <div class="supplier-metric"><div class="supplier-icon green material-icons-outlined" aria-hidden="true">person_add_alt_1</div><div><div class="supplier-label">New Suppliers</div><div class="supplier-value green"><?php echo number_format($newSuppliers); ?></div><div class="supplier-note">↑ Last 30 days</div></div></div>
        <div class="supplier-metric"><div class="supplier-icon blue material-icons-outlined" aria-hidden="true">local_shipping</div><div><div class="supplier-label">Active Suppliers</div><div class="supplier-value blue"><?php echo number_format($activeSuppliers); ?></div><div class="supplier-note">↑ Currently available</div></div></div>
        <div class="supplier-metric"><div class="supplier-icon purple material-icons-outlined" aria-hidden="true">inventory_2</div><div><div class="supplier-label">Products Supplied</div><div class="supplier-value purple"><?php echo number_format($suppliedProducts); ?></div><div class="supplier-note">↑ Linked products</div></div></div>
        <div class="supplier-metric"><div class="supplier-icon yellow material-icons-outlined" aria-hidden="true">payments</div><div><div class="supplier-label">Total Spend</div><div class="supplier-value yellow">₱<?php echo number_format($totalSpend, 2); ?></div><div class="supplier-note">↑ Purchase orders</div></div></div>
    </div>
    
    <?php if ($message): ?>
        <div class="message success"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="message error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="content-card">
        <div class="supplier-toolbar"><div class="supplier-search-wrap" style="position:relative;flex:1"><i class="bi bi-search search-icon" aria-hidden="true"></i><input id="supplierSearch" class="supplier-search" placeholder="Search by name, email or phone..." aria-label="Search suppliers"><button id="clearSupplierSearch" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);border:none;background:none;color:#e5e7eb;font-size:14px;cursor:pointer;display:none" title="Clear">✕</button></div><select class="supplier-filter" id="statusFilter" aria-label="Supplier status"><option value="">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option></select><button class="supplier-add" type="button" onclick="openAddModal()">＋ Add Supplier</button><button class="supplier-export" type="button" onclick="window.print()"><i class="bi bi-box-arrow-up" aria-hidden="true"></i> Export</button></div>
        <?php if ($suppliers && $suppliers->num_rows > 0): ?>
        <div class="supplier-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>Category</th>
                    <th>Total Products</th>
                    <th>Total Spend</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                while ($supplier = $suppliers->fetch_assoc()) {
                    $status_badge = $supplier['is_active'] ? 'badge-active' : 'badge-inactive';
                    $status_text = $supplier['is_active'] ? 'Active' : 'Inactive';
                    
                    echo "<tr>";
                    $supplierName = htmlspecialchars($supplier['company_name'] ?: $supplier['name']);
                    $supplierInitial = strtoupper(substr($supplierName, 0, 1));
                    $supplierId = (int)$supplier['id'];
                    $productCountResult = $hasSupplierColumn ? $conn->query("SELECT COUNT(*) AS total FROM products WHERE supplier_id = $supplierId") : false;
                    $productCount = $productCountResult ? ($productCountResult->fetch_assoc()['total'] ?? 0) : 0;
                    $supplierSpend = 0;
                    if ($purchaseOrdersAvailable) {
                        $supplierSpendResult = $conn->query("SELECT COALESCE(SUM(total_amount), 0) AS total FROM purchase_orders WHERE supplier_id = $supplierId");
                        $supplierSpend = $supplierSpendResult ? ($supplierSpendResult->fetch_assoc()['total'] ?? 0) : 0;
                    }
                    echo "<td><div class='supplier-name'><span class='supplier-avatar'>$supplierInitial</span><div><strong>$supplierName</strong><small>Joined " . date('M d, Y', strtotime($supplier['created_at'])) . "</small></div></div></td>";
                    echo "<td><strong>" . htmlspecialchars($supplier['contact_person'] ?: '—') . "</strong><small class='supplier-contact-email'>" . htmlspecialchars($supplier['email'] ?: 'No email') . "</small></td>";
                    echo "<td>" . htmlspecialchars($supplier['phone'] ?: '—') . "</td>";
                    echo "<td><span class='category'>General</span></td>";
                    echo "<td>" . number_format((int)$productCount) . "</td>";
                    echo "<td>₱" . number_format((float)$supplierSpend, 2) . "</td>";
                    echo "<td><span class='supplier-status " . ($supplier['is_active'] ? '' : 'inactive') . "'>$status_text</span></td>";
                    echo "<td>";
                    echo "<div class='supplier-actions'>";
                    echo "<button type='button' class='action-button' title='Edit supplier' aria-label='Edit supplier' onclick=\"openEditModal({$supplier['id']})\"><i class='bi bi-pencil' aria-hidden='true'></i></button>";
                    
                    echo "</div>";
                    echo "</td>";
                    echo "</tr>";
                }
                ?>
            </tbody>
        </table>
        </div>
        <div class="supplier-footer"><span id="supplierShowingCount" aria-live="polite"></span><nav class="supplier-pagination" id="supplierPagination" aria-label="Supplier pages" hidden></nav></div>
        <?php else: ?>
        <div class="empty-state">
            <p>No suppliers found. Click "Add New Supplier" to get started.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ADD SUPPLIER MODAL -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeAddModal()">&times;</span>
        <h2>Add New Supplier</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="add_supplier">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="name">Supplier Name:</label>
                    <input type="text" id="name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="company_name">Company Name:</label>
                    <input type="text" id="company_name" name="company_name">
                </div>
                
                <div class="form-group">
                    <label for="contact_person">Contact Person:</label>
                    <input type="text" id="contact_person" name="contact_person">
                </div>
                
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email">
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone:</label>
                    <input type="tel" id="phone" name="phone">
                </div>
                
                <div class="form-group">
                    <label for="tin_number">TIN Number:</label>
                    <input type="text" id="tin_number" name="tin_number">
                </div>
                
                <div class="form-group full">
                    <label for="address">Address:</label>
                    <input type="text" id="address" name="address">
                </div>
                
                <div class="form-group">
                    <label for="city">City:</label>
                    <input type="text" id="city" name="city">
                </div>
                
                <div class="form-group">
                    <label for="province">Province:</label>
                    <input type="text" id="province" name="province">
                </div>
                
                <div class="form-group">
                    <label for="postal_code">Postal Code:</label>
                    <input type="text" id="postal_code" name="postal_code">
                </div>
                
                <div class="form-group full">
                    <label for="payment_terms">Payment Terms:</label>
                    <input type="text" id="payment_terms" name="payment_terms" placeholder="e.g., Net 30, Net 45">
                </div>
            </div>
            
            <button type="submit" class="btn-submit" style="margin-top: 20px;">Add Supplier</button>
        </form>
    </div>
</div>

<!-- EDIT SUPPLIER MODAL -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeEditModal()">&times;</span>
        <h2>Edit Supplier</h2>
        <p id="editSupplierError" class="message error" role="alert" hidden></p>
        
        <form method="POST">
            <input type="hidden" name="action" value="update_supplier">
            <input type="hidden" id="editSupplierId" name="supplier_id" value="">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="editName">Supplier Name:</label>
                    <input type="text" id="editName" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="editCompanyName">Company Name:</label>
                    <input type="text" id="editCompanyName" name="company_name">
                </div>
                
                <div class="form-group">
                    <label for="editContactPerson">Contact Person:</label>
                    <input type="text" id="editContactPerson" name="contact_person">
                </div>
                
                <div class="form-group">
                    <label for="editEmail">Email:</label>
                    <input type="email" id="editEmail" name="email">
                </div>
                
                <div class="form-group">
                    <label for="editPhone">Phone:</label>
                    <input type="tel" id="editPhone" name="phone">
                </div>
                
                <div class="form-group">
                    <label for="editTinNumber">TIN Number:</label>
                    <input type="text" id="editTinNumber" name="tin_number">
                </div>
                
                <div class="form-group full">
                    <label for="editAddress">Address:</label>
                    <input type="text" id="editAddress" name="address">
                </div>
                
                <div class="form-group">
                    <label for="editCity">City:</label>
                    <input type="text" id="editCity" name="city">
                </div>
                
                <div class="form-group">
                    <label for="editProvince">Province:</label>
                    <input type="text" id="editProvince" name="province">
                </div>
                
                <div class="form-group">
                    <label for="editPostalCode">Postal Code:</label>
                    <input type="text" id="editPostalCode" name="postal_code">
                </div>
                
                <div class="form-group full">
                    <label for="editPaymentTerms">Payment Terms:</label>
                    <input type="text" id="editPaymentTerms" name="payment_terms">
                </div>
            </div>
            
            <button type="submit" class="btn-submit" style="margin-top: 20px;">Update Supplier</button>
        </form>
    </div>
</div>

<script>
const supplierEndpoint = <?= json_encode(capstoneUrl('owner/get_supplier.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function openAddModal() {
    document.getElementById('addModal').style.display = 'block';
}

function closeAddModal() {
    document.getElementById('addModal').style.display = 'none';
}

function openEditModal(supplierId) {
    const errorMessage = document.getElementById('editSupplierError');
    errorMessage.hidden = true;
    errorMessage.textContent = '';
    fetch(supplierEndpoint + '?id=' + encodeURIComponent(supplierId), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
    })
        .then(async response => {
            const responseText = await response.text();
            if (!responseText.trim()) {
                throw new Error(`Supplier lookup returned an empty response (HTTP ${response.status}).`);
            }
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(`Supplier lookup returned an invalid response (HTTP ${response.status}).`);
            }
            if (!response.ok || data.error) {
                throw new Error(data.error || `Unable to load supplier details (HTTP ${response.status}).`);
            }
            return data;
        })
        .then(data => {
            document.getElementById('editSupplierId').value = data.id;
            document.getElementById('editName').value = data.name;
            document.getElementById('editCompanyName').value = data.company_name || '';
            document.getElementById('editContactPerson').value = data.contact_person || '';
            document.getElementById('editEmail').value = data.email || '';
            document.getElementById('editPhone').value = data.phone || '';
            document.getElementById('editTinNumber').value = data.tin_number || '';
            document.getElementById('editAddress').value = data.address || '';
            document.getElementById('editCity').value = data.city || '';
            document.getElementById('editProvince').value = data.province || '';
            document.getElementById('editPostalCode').value = data.postal_code || '';
            document.getElementById('editPaymentTerms').value = data.payment_terms || '';
            document.getElementById('editModal').style.display = 'block';
        })
        .catch(error => {
            errorMessage.textContent = error.message || 'Unable to load supplier details. Please try again.';
            errorMessage.hidden = false;
            document.getElementById('editModal').style.display = 'block';
        });
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

window.onclick = function(event) {
    const addModal = document.getElementById('addModal');
    const editModal = document.getElementById('editModal');
    
    if (event.target == addModal) {
        addModal.style.display = 'none';
    }
    if (event.target == editModal) {
        editModal.style.display = 'none';
    }
}

// Clear search button and filter functionality
document.addEventListener('DOMContentLoaded', function() {
    const supplierMetrics = document.querySelectorAll('.supplier-metric');
    const statusFilter = document.getElementById('statusFilter');
    if (supplierMetrics.length && statusFilter) {
        supplierMetrics[0].classList.add('clickable');
        supplierMetrics[2].classList.add('clickable');
        supplierMetrics[0].addEventListener('click', function() {
            statusFilter.value = '';
            statusFilter.dispatchEvent(new Event('change'));
        });
        supplierMetrics[2].addEventListener('click', function() {
            statusFilter.value = 'active';
            statusFilter.dispatchEvent(new Event('change'));
        });
    }
    const searchInput = document.getElementById('supplierSearch');
    const clearBtn = document.getElementById('clearSupplierSearch');
    const tableBody = document.querySelector('table tbody');
    const supplierShowingCount = document.getElementById('supplierShowingCount');
    const supplierPagination = document.getElementById('supplierPagination');
    const suppliersPerPage = 10;
    let supplierPage = 1;
    
    if (searchInput && clearBtn) {
        searchInput.addEventListener('input', function() {
            clearBtn.style.display = this.value ? 'block' : 'none';
            supplierPage = 1;
            filterSuppliers();
        });
        
        clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            searchInput.value = '';
            clearBtn.style.display = 'none';
            supplierPage = 1;
            filterSuppliers();
        });
    }
    
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            supplierPage = 1;
            filterSuppliers();
        });
    }
    
    function filterSuppliers() {
        if (!tableBody) return;
        const query = searchInput ? searchInput.value.toLowerCase() : '';
        const status = statusFilter ? statusFilter.value : '';
        const rows = Array.from(tableBody.querySelectorAll('tr'));
        const matchingRows = rows.filter(row => {
            const text = row.textContent.toLowerCase();
            const statusCell = row.querySelector('td:nth-child(8)');
            const statusText = statusCell ? statusCell.textContent.toLowerCase() : '';
            
            const matchesSearch = !query || text.includes(query);
            const matchesStatus = !status || statusText.includes(status);
            return matchesSearch && matchesStatus;
        });
        const totalPages = Math.max(1, Math.ceil(matchingRows.length / suppliersPerPage));
        supplierPage = Math.min(supplierPage, totalPages);
        const startIndex = (supplierPage - 1) * suppliersPerPage;
        const endIndex = Math.min(startIndex + suppliersPerPage, matchingRows.length);

        rows.forEach(row => { row.style.display = 'none'; });
        matchingRows.slice(startIndex, endIndex).forEach(row => { row.style.display = ''; });

        if (supplierShowingCount) {
            supplierShowingCount.textContent = matchingRows.length
                ? `Showing ${startIndex + 1} to ${endIndex} of ${matchingRows.length} suppliers`
                : 'Showing 0 suppliers';
        }
        if (!supplierPagination) return;
        supplierPagination.replaceChildren();
        supplierPagination.hidden = matchingRows.length <= suppliersPerPage;
        if (supplierPagination.hidden) return;

        if (supplierPage > 1) {
            addSupplierPageButton('‹', supplierPage - 1, 'Previous page');
        }
        let pageItems;
        if (totalPages <= 5) {
            pageItems = Array.from({ length: totalPages }, (_, index) => index + 1);
        } else if (supplierPage <= 3) {
            pageItems = [1, 2, 3, '…', totalPages];
        } else if (supplierPage >= totalPages - 2) {
            pageItems = [1, '…', totalPages - 2, totalPages - 1, totalPages];
        } else {
            pageItems = [1, '…', supplierPage - 1, supplierPage, supplierPage + 1, '…', totalPages];
        }
        pageItems.forEach(pageItem => {
            if (pageItem === '…') {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'ellipsis';
                ellipsis.textContent = '…';
                ellipsis.setAttribute('aria-hidden', 'true');
                supplierPagination.appendChild(ellipsis);
                return;
            }
            addSupplierPageButton(String(pageItem), pageItem, `Page ${pageItem}`, pageItem === supplierPage);
        });
        if (supplierPage < totalPages) {
            addSupplierPageButton('›', supplierPage + 1, 'Next page');
        }
    }

    function addSupplierPageButton(label, pageNumber, ariaLabel, isCurrent) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.setAttribute('aria-label', ariaLabel);
        if (isCurrent) {
            button.className = 'current';
            button.setAttribute('aria-current', 'page');
        }
        button.addEventListener('click', function() {
            supplierPage = pageNumber;
            filterSuppliers();
        });
        supplierPagination.appendChild(button);
    }
    filterSuppliers();
});
</script>

</body>
</html>
