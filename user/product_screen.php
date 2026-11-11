<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
// CUSTOMER ONLY
if (($_SESSION['role'] ?? '') !== 'user') {
    if (isset($_SESSION['role'])) {
        http_response_code(403);
        exit('This page is available to customer accounts only.');
    }

    header("Location: login.php");
    exit();
}
if (($_SESSION['role'] ?? '') === 'user' && !empty($_SESSION['complete_google_profile'])) {
    header("Location: settings.php?tab=account");
    exit();
}


// PAGINATION SETTINGS
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;


// SEARCH FUNCTION
$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$category = isset($_GET['category']) ? $_GET['category'] : "";
$showApprovedPrescriptions = $category === 'approved-prescription';
$sort = $_GET['sort'] ?? 'newest';
$sort_orders = [
    'newest' => 'id DESC',
    'price-low' => 'COALESCE(price_piece, price, 0) ASC, id DESC',
    'price-high' => 'COALESCE(price_piece, price, 0) DESC, id DESC',
    'popular' => '(SELECT COALESCE(SUM(COALESCE(oi.pieces, 0) + COALESCE(oi.box, 0)), 0) FROM order_items oi WHERE oi.product_id = products.id) DESC, id DESC',
];
$sort = array_key_exists($sort, $sort_orders) ? $sort : 'newest';
$return_params = [];
foreach (['search', 'category', 'sort', 'page', 'ai_search', 'budget_search', 'budget_medicine', 'budget_amount'] as $return_param) {
    if (isset($_GET[$return_param]) && is_scalar($_GET[$return_param])) {
        $return_params[$return_param] = (string) $_GET[$return_param];
    }
}
$product_screen_return_url = 'product_screen.php';
if (!empty($return_params)) {
    $product_screen_return_url .= '?' . http_build_query($return_params);
}

$budget_search_submitted = isset($_GET['budget_search']) && $_GET['budget_search'] === '1';
$budget_medicine = trim((string) ($_GET['budget_medicine'] ?? ''));
$budget_amount_input = trim((string) ($_GET['budget_amount'] ?? ''));
$budget_amount = null;
$budget_search_error = '';
$budget_search_results = null;
$budget_panel_open = false;

if ($budget_search_submitted) {
    if ($budget_medicine === '') {
        $budget_search_error = 'Ilagay ang pangalan ng gamot o active ingredient.';
    } elseif (mb_strlen($budget_medicine, 'UTF-8') > 120) {
        $budget_search_error = 'Hanggang 120 characters lang ang medicine search.';
    } elseif ($budget_amount_input === '' || !is_numeric($budget_amount_input) || !is_finite((float) $budget_amount_input) || (float) $budget_amount_input <= 0) {
        $budget_search_error = 'Maglagay ng valid na budget na higit sa ₱0.';
    } else {
        $budget_amount = (float) $budget_amount_input;
        $budget_search_results = getWiseAiBudgetBundle($conn, $budget_medicine, $budget_amount);
    }
    $budget_panel_open = true;
}


// BUILD QUERY
$where_conditions = [];
$params = [];
$types = "";


if (!empty($search)) {
    $where_conditions[] = "product_name LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}


if (!empty($category) && !$showApprovedPrescriptions) {
    $where_conditions[] = "category = ?";
    $params[] = $category;
    $types .= "s";
}

$where_conditions[] = "COALESCE(prescription_required, 0) = 0";
$where_conditions[] = "LOWER(TRIM(COALESCE(category, ''))) <> 'prescription medicine'";
if ($showApprovedPrescriptions) {
    $where_conditions[] = '1 = 0';
}
$where_conditions[] = "products.status = 'active'";
$where_conditions[] = getCustomerSellableStockCondition();
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$ai_search_reply = null;
$ai_search_products = [];
if (!empty($_GET['ai_search']) && $search !== '') {
    $ai_search_reply = getWiseAiResponse(
        $conn,
        $search,
        'The customer submitted a natural-language product search. Keep the answer concise. '
        . 'Mention only verified inventory products and do not diagnose or prescribe.'
    );
    $ai_search_products = getWiseAiSearchProducts($conn, $search, 8);
}


// GET TOTAL COUNT FOR PAGINATION
$count_query = "SELECT COUNT(*) as total FROM products $where_clause";
$catalogError = '';
$total_products = 0;
$total_pages = 0;
$result = false;

try {
    if (!empty($params)) {
        $stmt = $conn->prepare($count_query);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare product count query: ' . $conn->error);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $count_result = $stmt->get_result();
    } else {
        $count_result = $conn->query($count_query);
    }

    if (!$count_result) {
        throw new RuntimeException('Unable to load product count: ' . $conn->error);
    }
    $count_row = $count_result->fetch_assoc();
    if (!$count_row) {
        throw new RuntimeException('Product count query returned no result.');
    }

    $total_products = (int) $count_row['total'];
    $total_pages = (int) ceil($total_products / $limit);

    // GET PRODUCTS WITH PAGINATION
    $query = "SELECT *, " . getCustomerSellableStockExpression() . " AS available_stock FROM products $where_clause ORDER BY {$sort_orders[$sort]} LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare product listing query: ' . $conn->error);
    }

    if (!empty($params)) {
        $query_params = array_merge($params, [$limit, $offset]);
        $stmt->bind_param($types . 'ii', ...$query_params);
    } else {
        $stmt->bind_param('ii', $limit, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result) {
        throw new RuntimeException('Unable to load product list: ' . $conn->error);
    }
} catch (Throwable $exception) {
    error_log('Customer product screen query failed: ' . $exception->getMessage());
    $catalogError = 'The product list is temporarily unavailable. Please try again later.';
    $total_products = 0;
    $total_pages = 0;
    $result = false;
}

$approvedPrescriptionProducts = [];
$prescriptionCatalogError = '';
$approvedPrescriptionQuery = $conn->prepare(
    'SELECT products.id, products.product_name, products.image,
            COALESCE(NULLIF(products.price_piece, 0), products.price, 0) AS display_price,
            ' . getCustomerSellableStockExpression() . ' AS available_stock,
            ppa.quantity_limit, ppa.quantity_purchased, ppa.expires_at,
            GREATEST(ppa.quantity_limit - ppa.quantity_purchased, 0) AS prescription_remaining
     FROM prescription_product_access ppa
     JOIN prescriptions approved_prescription
       ON approved_prescription.id = ppa.prescription_id
      AND approved_prescription.user_id = ppa.user_id
      AND approved_prescription.shared_product_id = ppa.product_id
      AND approved_prescription.status = \'approved\'
     JOIN products
       ON products.id = ppa.product_id
      AND products.status = \'active\'
     WHERE ppa.user_id = ?
       AND ppa.quantity_limit > ppa.quantity_purchased
       AND (ppa.expires_at IS NULL OR ppa.expires_at >= NOW())
       AND (approved_prescription.product_access_expires_at IS NULL
            OR approved_prescription.product_access_expires_at >= NOW())
       AND (COALESCE(products.prescription_required, 0) = 1
            OR LOWER(TRIM(COALESCE(products.category, \'\'))) = \'prescription medicine\')
       AND (' . getCustomerSellableStockExpression() . ') > 0
     ORDER BY ppa.granted_at DESC, products.product_name ASC'
);
if (!$approvedPrescriptionQuery) {
    error_log('Approved prescription catalog query preparation failed: ' . $conn->error);
    $prescriptionCatalogError = 'Approved prescription medicines are temporarily unavailable.';
} else {
    $customerId = (int)$_SESSION['user_id'];
    $approvedPrescriptionQuery->bind_param('i', $customerId);
    if (!$approvedPrescriptionQuery->execute()) {
        error_log('Approved prescription catalog query failed: ' . $approvedPrescriptionQuery->error);
        $prescriptionCatalogError = 'Approved prescription medicines are temporarily unavailable.';
    } else {
        $approvedPrescriptionResult = $approvedPrescriptionQuery->get_result();
        while ($approvedPrescription = $approvedPrescriptionResult->fetch_assoc()) {
            $approvedPrescriptionProducts[] = $approvedPrescription;
        }
    }
    $approvedPrescriptionQuery->close();
}

$visibleApprovedPrescriptionProducts = array_values(array_filter(
    $approvedPrescriptionProducts,
    static function (array $product) use ($search): bool {
        return $search === '' || stripos((string) $product['product_name'], $search) !== false;
    }
));
?>


<!DOCTYPE html>
<html>
<head>
    <title>Product Screen</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">


    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
       
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            display: flex;
            background: #f8f9fb;
            min-height: 100vh;
            overflow: hidden;
        }

        .content {
            margin-left: 292px;
            padding: 36px 42px 32px;
            width: calc(100% - 292px);
            height: 100vh;
            min-height: 0;
            overflow-x: hidden;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }


        .controls-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 15px;
            flex-wrap: wrap;
        }


        .search-filter-group {
            display: flex;
            gap: 12px;
            flex: 1;
            min-width: 300px;
        }


        .search-wrapper {
            position: relative;
            flex: 1;
            min-width: 250px;
        }


        .search-wrapper input {
            width: 100%;
            padding: 11px 16px 11px 40px;
            border: 1px solid #e0e6f0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: all 0.3s ease;
        }


        .search-wrapper input:focus {
            outline: none;
            border-color: #d32f2f;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
        }


        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 16px;
        }


        .clear-btn {
            position: absolute;
            right: 48px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            font-size: 18px;
            display: none;
            background: none;
            border: none;
            padding: 0;
            transition: color 0.2s;
        }


        .clear-btn:hover {
            color: #ef4444;
        }


        .ai-search-link {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            border: 0;
            background: transparent;
            cursor: pointer;
        }

        .ai-sparkle {
            position: relative;
            display: inline-block;
            width: 20px;
            height: 18px;
            margin-right: 2px;
            vertical-align: -4px;
            color: #9b61d4;
        }

        .ai-sparkle::before,
        .ai-sparkle::after {
            position: absolute;
            display: block;
            background: linear-gradient(135deg, #f5a13b 0%, #d56e9a 38%, #756dd7 68%, #299bd4 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            line-height: 1;
        }

        .ai-sparkle::before {
            content: '✦';
            top: -2px;
            left: 0;
            font-size: 18px;
        }

        .ai-sparkle::after {
            content: '✦';
            right: 0;
            bottom: -1px;
            font-size: 9px;
        }

        .filter-select {
            padding: 11px 14px;
            border: 1px solid #e0e6f0;
            border-radius: 8px;
            background: white;
            color: #1a2333;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 150px;
        }


        .filter-select:hover,
        .filter-select:focus {
            border-color: #d32f2f;
            outline: none;
        }


        .top-right-controls {
            display: flex;
            gap: 15px;
            align-items: center;
        }


        .items-count {
            font-size: 14px;
            color: #7c8ca3;
            white-space: nowrap;
        }

        .products-found {
            margin: 0 0 12px;
        }


        .sort-dropdown {
            padding: 8px 12px;
            border: 1px solid #e0e6f0;
            border-radius: 6px;
            background: white;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
        }


        .sort-dropdown:hover {
            border-color: #d32f2f;
        }


        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 12px;
            margin-bottom: 8px;
            flex: 0 0 auto;
            min-height: 0;
            align-content: start;
            grid-auto-rows: max-content;
            overflow-x: hidden;
            overflow-y: visible;
            padding-bottom: 4px;
        }

        .ai-search-heading {
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 0 0 8px;
            color: #8e1737;
            font-size: 13px;
            font-weight: 700;
        }

        .ai-products-grid {
            flex: 0 0 auto;
            max-height: 285px;
            margin-bottom: 16px;
            overflow-x: auto;
            overflow-y: hidden;
            grid-auto-flow: column;
            grid-auto-columns: minmax(210px, 240px);
            grid-template-columns: none;
        }

        .ai-search-empty {
            margin: 0 0 16px;
            padding: 12px 14px;
            border: 1px solid #eadde1;
            border-radius: 8px;
            color: #6d1729;
            background: #fffafa;
            font-size: 13px;
        }

        .budget-search-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            padding: 8px 13px;
            border: 1px solid #ead1d5;
            border-radius: 8px;
            background: #fff8f8;
            color: #8b1e2d;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .budget-search-toggle:hover {
            background: #8b1e2d;
            border-color: #8b1e2d;
            color: #fff;
        }

        .budget-search-toggle[aria-expanded="true"] {
            background: #8b1e2d;
            border-color: #8b1e2d;
            color: #fff;
        }

        .budget-search-panel {
            width: 100%;
            margin: 0 0 18px;
            padding: 20px 22px;
            border: 1px solid #f0dadd;
            border-radius: 14px;
            background: linear-gradient(135deg, #fff8f8, #fff);
            box-shadow: 0 3px 12px rgba(77, 22, 31, .05);
        }

        .budget-search-panel[hidden] {
            display: none;
        }

        .budget-search-panel h2 {
            margin-bottom: 6px;
            color: #1a2333;
            font-size: 22px;
        }

        .budget-search-description {
            margin-bottom: 16px;
            color: #738096;
            font-size: 13px;
        }

        .budget-search-form {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(140px, 190px) auto;
            align-items: end;
            gap: 12px;
        }

        .budget-search-field label {
            display: block;
            margin-bottom: 6px;
            color: #657184;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .03em;
        }

        .budget-search-field input {
            width: 100%;
            min-height: 44px;
            padding: 10px 12px;
            border: 1px solid #d9dfe7;
            border-radius: 8px;
            background: #fff;
            color: #1a2333;
            font-size: 14px;
        }

        .budget-search-field input:focus {
            outline: none;
            border-color: #8b1e2d;
            box-shadow: 0 0 0 3px rgba(139, 30, 45, .1);
        }

        .budget-search-submit {
            min-height: 44px;
            padding: 0 18px;
            border: 1px solid #8b1e2d;
            border-radius: 8px;
            background: #8b1e2d;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .budget-search-submit:hover {
            background: #701722;
        }

        .budget-smart-match {
            margin-top: 15px;
            padding: 12px 14px;
            border: 1px solid #f0dadd;
            border-radius: 9px;
            background: #fff;
            color: #657184;
            font-size: 12px;
            line-height: 1.5;
        }

        .budget-smart-match strong {
            display: block;
            margin-bottom: 3px;
            color: #6d1729;
            font-size: 12px;
        }

        .budget-ai-insight {
            margin-top: 8px;
            color: #4b5563;
            white-space: pre-line;
        }

        .budget-search-error,
        .budget-no-results {
            margin-top: 12px;
            padding: 11px 13px;
            border: 1px solid #f0dadd;
            border-radius: 8px;
            background: #fff8f8;
            color: #8b1e2d;
            font-size: 13px;
        }

        .budget-results-heading {
            margin: 0 0 12px;
            color: #1a2333;
            font-size: 18px;
        }

        .budget-mode .products-found,
        .budget-mode .products-grid:not(.budget-results-grid),
        .budget-mode .ai-search-heading,
        .budget-mode .ai-search-empty,
        .budget-mode .pagination,
        .budget-mode .catalog-product-list {
            display: none !important;
        }

        .budget-mode .search-filter-group,
        .budget-mode #sortBy {
            display: none !important;
        }

        body:not(.budget-mode) .budget-results-section {
            display: none;
        }

        .budget-results-section {
            margin: 0 0 18px;
        }

        .budget-results-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .budget-result-card {
            display: grid;
            position: relative;
            grid-template-columns: minmax(88px, 112px) minmax(0, 1fr);
            align-items: start;
            min-width: 0;
            min-height: 196px;
            gap: 16px;
            padding: 16px;
            border: 1px solid #e5e9ef;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .04);
            color: inherit;
            text-decoration: none;
            transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
        }

        .budget-result-card.recommended {
            border: 2px solid #4c9a65;
            background: #f4fbf6;
            box-shadow: 0 3px 12px rgba(56, 125, 76, .12);
        }

        .budget-result-card:hover {
            border-color: #4c9a65;
            box-shadow: 0 7px 18px rgba(56, 125, 76, .16);
            transform: translateY(-2px);
        }

        .budget-result-card img {
            display: block;
            width: 100%;
            height: 124px;
            min-width: 0;
            border-radius: 10px;
            background: #eaf3ff;
            object-fit: contain;
        }

        .budget-result-details {
            display: flex;
            min-width: 0;
            flex-direction: column;
            align-items: flex-start;
        }

        .budget-result-card h3 {
            max-width: 100%;
            margin: 0 0 6px;
            color: #1a2333;
            font-size: 17px;
            line-height: 1.35;
            overflow-wrap: break-word;
        }

        .budget-result-price {
            color: #8b1e2d;
            font-size: 17px;
            font-weight: 700;
            line-height: 1.4;
        }

        .budget-result-unit {
            color: #657184;
            font-size: 13px;
            font-weight: 600;
        }

        .budget-result-meta {
            margin-top: 6px;
            color: #657184;
            font-size: 13px;
            line-height: 1.45;
        }

        .budget-result-category {
            color: #7c8798;
            font-size: 13px;
            line-height: 1.4;
        }

        .budget-result-tag {
            display: inline-flex;
            margin: 0 0 6px;
            padding: 5px 10px;
            border-radius: 20px;
            background: #e4f4e9;
            color: #287342;
            font-size: 12px;
            font-weight: 700;
        }

        .budget-result-view {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            align-self: flex-start;
            margin-top: 10px;
            padding: 7px 13px;
            border: 1px solid #d9aab0;
            border-radius: 6px;
            background: #fff;
            color: #8b1e2d;
            font-size: 12px;
            font-weight: 700;
        }
        
        .budget-result-view:hover {
            background: #fff5f5;
        }

        .budget-ai-insight-panel {
            margin-top: 14px;
            padding: 12px 14px;
            border: 1px solid #f0dadd;
            border-radius: 9px;
            background: #fff8f8;
            color: #657184;
            font-size: 14px;
            line-height: 1.65;
        }

        .budget-ai-insight-panel strong {
            display: block;
            margin-bottom: 4px;
            color: #6d1729;
        }

        .budget-search-loading {
            display: none;
            align-items: center;
            gap: 8px;
            color: #6d1729;
            font-size: 12px;
            font-weight: 600;
        }

        .budget-search-loading.is-visible {
            display: inline-flex;
        }

        .budget-loading-spinner {
            width: 15px;
            height: 15px;
            border: 2px solid #edd3d7;
            border-top-color: #8b1e2d;
            border-radius: 50%;
            animation: budget-spin .7s linear infinite;
        }

        @keyframes budget-spin {
            to { transform: rotate(360deg); }
        }


        .product-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            display: flex;
            color: inherit;
            text-decoration: none;
            flex-direction: column;
            min-height: max-content;
            height: max-content;
        }


        .product-card:hover {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            transform: translateY(-4px);
        }


        .product-image-wrapper {
            width: 100%;
            aspect-ratio: 0.95;
            background: #010711;
            overflow: hidden;
            position: relative;
        }


        .product-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }


        .product-card:hover img {
            transform: scale(1.05);
        }


        .product-info {
            padding: 8px 10px 10px;
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
        }


        .product-name {
            font-size: 13px;
            font-weight: 600;
            color: #1a2333;
            margin-bottom: 4px;
            line-height: 1.2;
        }


        .product-price {
            font-size: 15px;
            font-weight: 700;
            color: #d32f2f;
            margin-bottom: 2px;
        }

        .approved-prescriptions-section { margin: 0 0 18px; }
        .approved-prescriptions-heading { margin: 0 0 10px; color: #1a2333; font-size: 18px; }
        .approved-prescription-meta { margin-top: 5px; color: #287342; font-size: 12px; font-weight: 700; line-height: 1.45; }
        .approved-prescription-expiry { margin-top: 3px; color: #657184; font-size: 11px; }


        .sold-badge {
            margin-top: 4px;
            padding: 4px 8px;
            background: #eef3ff;
            color: #1d4ed8;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            font-weight: 700;
            font-size: 9px;
            letter-spacing: 0.15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            width: fit-content;
            align-self: flex-start;
            min-height: 20px;
            line-height: 1;
        }


        /* Update these specific classes in your <style> tag */


    .product-buttons {
        display: flex;
        gap: 8px;
        margin-top: auto;
        width: 100%; /* Ensure the container takes full width */
    }


    .product-buttons form {
        flex: 1; /* This forces both forms to take up 50% of the space */
        display: flex; /* Makes the form a flex container for the button inside */
    }


    .btn {
        padding: 10px 5px; /* Reduced side padding to prevent overflow on small screens */
        border: none;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        width: 100%; /* Force button to fill the form's width */
        height: 40px; /* Optional: Set a fixed height so they are vertically identical */
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
    }
        .cart-btn {
            background: white;
            color: #d32f2f;
            border: 2px solid #d32f2f;
        }


        .cart-btn:hover {
            background: #ffebee;
        }


        .buy-btn {
            background: linear-gradient(135deg, #d32f2f 0%, #b71c1c 100%);
            color: white;
        }


        .buy-btn:hover {
            opacity: 0.9;
        }


        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7c8ca3;
        }


        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
            padding-top: 8px;
        }


        .pagination-btn {
            min-width: 38px; /* Ginawang min-width para lumapad kung double digit */
            height: 38px;
            padding: 0 8px;
            border: 1px solid #e0e6f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex; /* Para laging centered ang numbers */
            align-items: center;
            justify-content: center;
            text-decoration: none; /* Alisin ang underline ng <a> tag */
            color: #4b5563;
            transition: all 0.2s ease;
        }


        .pagination-btn:hover {
            border-color: #d32f2f;
            color: #d32f2f;
            background: #fff5f5;
        }


        .pagination-btn.active {
            background: #d32f2f;
            color: white;
            border-color: #d32f2f;
            box-shadow: 0 4px 12px rgba(211, 47, 47, 0.2);
        }


        @media (max-width: 1024px) {
            body.product-page {
                overflow-x: hidden;
                overflow-y: auto;
            }

            body.product-page .content {
                height: auto !important;
                min-height: calc(100vh - 72px);
                overflow: visible !important;
            }

            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 16px;
            }

            .budget-results-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }


        @media (max-width: 768px) {
            .controls-section {
                flex-direction: column;
                align-items: stretch;
            }

            .search-filter-group {
                width: 100%;
                min-width: 0 !important;
                flex-direction: column;
            }

            .search-wrapper {
                width: 100%;
                min-width: 0 !important;
            }

            .filter-select {
                width: 100%;
            }

            .top-right-controls {
                width: 100%;
                flex-wrap: wrap;
            }

            .sort-dropdown {
                flex: 1 1 180px;
            }

            .budget-search-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .budget-search-submit {
                grid-column: 1 / -1;
            }

            .products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .ai-products-grid {
                grid-auto-columns: minmax(150px, 72vw);
            }

            .pagination {
                margin-top: 20px;
            }
        }


        /* SUCCESS NOTIFICATION */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #34a853 0%, #2d8f46 100%);
            color: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(52, 168, 83, 0.35);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 9999;
            animation: slideInRight 0.5s ease-out, slideOutRight 0.5s ease-in 3.5s forwards;
            font-weight: 500;
            font-size: 15px;
            max-width: 350px;
        }


        .notification-icon {
            font-size: 24px;
            flex-shrink: 0;
        }


        .notification-content {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }


        .notification-title {
            font-weight: 700;
            font-size: 16px;
        }


        .notification-message {
            font-weight: 400;
            font-size: 14px;
            opacity: 0.95;
        }


        .notification-close {
            margin-left: auto;
            cursor: pointer;
            font-size: 20px;
            opacity: 0.8;
            transition: opacity 0.2s;
            flex-shrink: 0;
        }


        .notification-close:hover {
            opacity: 1;
        }


        @keyframes slideInRight {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }


        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(400px);
                opacity: 0;
            }
        }


        @media (max-width: 600px) {
            .notification {
                left: 10px;
                right: 10px;
                top: 10px;
                max-width: none;
            }

            .content {
                padding-right: 12px;
                padding-left: 12px;
            }

            .budget-search-panel {
                padding: 16px 13px;
            }

            .budget-search-panel h2 {
                font-size: 19px;
            }

            .budget-search-form {
                grid-template-columns: minmax(0, 1fr);
            }

            .budget-search-submit {
                grid-column: auto;
                width: 100%;
            }

            .budget-results-grid {
                grid-template-columns: minmax(0, 1fr);
                gap: 9px;
            }

            .budget-result-card {
                grid-template-columns: minmax(76px, 96px) minmax(0, 1fr);
                min-height: 132px;
                gap: 12px;
                padding: 12px;
            }

            .budget-result-card img {
                width: 100%;
                height: 100px;
            }

            .budget-result-view {
                max-width: 100%;
                padding: 7px 10px;
                text-align: center;
            }

            .products-grid {
                gap: 10px;
            }

            .product-info {
                padding: 8px;
            }

            .product-name {
                overflow-wrap: anywhere;
            }

            .product-price {
                font-size: 14px;
            }

            .sold-badge {
                max-width: 100%;
                padding: 4px 6px;
                font-size: 8px;
            }
        }
    </style>
</head>


<body class="product-page<?= $budget_panel_open ? ' budget-mode' : '' ?>">


<!-- SUCCESS NOTIFICATION -->
<?php if (isset($_SESSION['success_message'])): ?>
<div class="notification" id="notification">
    <div class="notification-icon">✓</div>
    <div class="notification-content">
        <div class="notification-title">Success!</div>
        <div class="notification-message"><?php echo htmlspecialchars($_SESSION['success_message']); ?></div>
    </div>
    <span class="notification-close" onclick="closeNotification()">✕</span>
</div>
<script>
    function closeNotification() {
        const notif = document.getElementById('notification');
        if (notif) {
            notif.style.animation = 'slideOutRight 0.5s ease-in forwards';
            setTimeout(() => notif.remove(), 500);
        }
    }
    // Auto-hide after 4 seconds
    setTimeout(() => {
        const notif = document.getElementById('notification');
        if (notif) {
            notif.style.animation = 'slideOutRight 0.5s ease-in forwards';
            setTimeout(() => notif.remove(), 500);
        }
    }, 4000);
</script>
<?php unset($_SESSION['success_message']); endif; ?>


<?php include 'sidebar_user.php'; ?>


<div class="content">
    <!-- Controls Section -->
    <div class="controls-section">
        <form method="GET" class="search-filter-group" action="product_screen.php" style="display:flex; gap:12px; flex:1; min-width:300px;">
            <div class="search-wrapper" style="flex: 1; min-width: 250px;">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchInput" name="search" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="button" class="clear-btn" id="clearBtn" onclick="clearSearch()">✕</button>
                <input type="hidden" name="page" value="1">
            </div>
            <select class="filter-select" name="category" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php if ($approvedPrescriptionProducts || $prescriptionCatalogError !== '' || $showApprovedPrescriptions): ?>
                    <option value="approved-prescription" <?= $showApprovedPrescriptions ? 'selected' : '' ?>>Approved Prescription Medicines</option>
                <?php endif; ?>
                <option value="OTC Medicine" <?php echo $category == 'OTC Medicine' ? 'selected' : ''; ?>>OTC Medicine</option>
                <option value="Vitamins & Supplements" <?php echo $category == 'Vitamins & Supplements' ? 'selected' : ''; ?>>Vitamins & Supplements</option>
                <option value="Herbal Medicine" <?php echo $category == 'Herbal Medicine' ? 'selected' : ''; ?>>Herbal Medicine</option>
                <option value="Baby & Infant Products" <?php echo $category == 'Baby & Infant Products' ? 'selected' : ''; ?>>Baby & Infant Products</option>
                <option value="Topical & Personal Care" <?php echo $category == 'Topical & Personal Care' ? 'selected' : ''; ?>>Topical & Personal Care</option>
                <option value="Medical Supplies" <?php echo $category == 'Medical Supplies' ? 'selected' : ''; ?>>Medical Supplies</option>
                <option value="Hygiene & Personal Care" <?php echo $category == 'Hygiene & Personal Care' ? 'selected' : ''; ?>>Hygiene & Personal Care</option>
            </select>
        </form>
       
        <div class="top-right-controls">
            <select class="sort-dropdown" id="sortBy">
                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                <option value="price-low" <?php echo $sort === 'price-low' ? 'selected' : ''; ?>>Price: Low to High</option>
                <option value="price-high" <?php echo $sort === 'price-high' ? 'selected' : ''; ?>>Price: High to Low</option>
                <option value="popular" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Most Popular</option>
            </select>
            <button class="budget-search-toggle" id="budgetSearchToggle" type="button" aria-label="AI Budget-Based" aria-expanded="<?= $budget_panel_open ? 'true' : 'false' ?>" aria-controls="budgetSearchPanel">
                <span class="ai-sparkle" aria-hidden="true"></span> AI Budget-Based
            </button>
        </div>
    </div>

    <section class="budget-search-panel" id="budgetSearchPanel" <?= $budget_panel_open ? '' : 'hidden' ?>>
        <h2>Find medicine within your budget</h2>
        <p class="budget-search-description">Enter the medicine name and your budget to see matching products and prices.</p>
        <form class="budget-search-form" method="GET" action="product_screen.php">
            <input type="hidden" name="budget_search" value="1">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="category" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
            <div class="budget-search-field">
                <label for="budgetMedicine">MEDICINE NAME OR ACTIVE INGREDIENT</label>
                <input id="budgetMedicine" name="budget_medicine" type="search" maxlength="120" placeholder="e.g., paracetamol" value="<?= htmlspecialchars($budget_medicine, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="budget-search-field">
                <label for="budgetAmount">YOUR BUDGET (₱)</label>
                <input id="budgetAmount" name="budget_amount" type="number" min="0.01" step="0.01" placeholder="e.g., 100" value="<?= htmlspecialchars($budget_amount_input, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <button class="budget-search-submit" id="budgetSearchSubmit" type="submit">Find options</button>
            <span class="budget-search-loading" id="budgetSearchLoading" role="status" aria-live="polite">
                <span class="budget-loading-spinner" aria-hidden="true"></span>Finding matches…
            </span>
        </form>
        <?php if ($budget_search_error !== ''): ?>
            <div class="budget-search-error" role="alert"><?= htmlspecialchars($budget_search_error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </section>

    <?php if ($prescriptionCatalogError !== '' || $approvedPrescriptionProducts || $showApprovedPrescriptions): ?>
        <section class="approved-prescriptions-section" aria-labelledby="approvedPrescriptionHeading" <?= $showApprovedPrescriptions ? '' : 'hidden' ?>>
            <h2 class="approved-prescriptions-heading" id="approvedPrescriptionHeading">Approved Prescription Medicines</h2>
            <?php if ($prescriptionCatalogError !== ''): ?>
                <div class="budget-search-error" role="alert"><?= htmlspecialchars($prescriptionCatalogError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif ($visibleApprovedPrescriptionProducts): ?>
                <div class="products-grid">
                    <?php foreach ($visibleApprovedPrescriptionProducts as $approvedProduct): ?>
                        <?php
                        $approvedImage = trim((string)($approvedProduct['image'] ?? ''));
                        $approvedImageUrl = getProductImageUrl($approvedImage);
                        $approvedRemaining = (int)$approvedProduct['prescription_remaining'];
                        $approvedStock = max(0, (int)$approvedProduct['available_stock']);
                        $approvedAvailableNow = min($approvedRemaining, $approvedStock);
                        $approvedExpiry = trim((string)($approvedProduct['expires_at'] ?? ''));
                        ?>
                        <div class="product-card">
                            <a href="view_product.php?id=<?= (int)$approvedProduct['id'] ?>&amp;return_to=<?= rawurlencode($product_screen_return_url) ?>" style="text-decoration:none;color:inherit;flex:1;display:flex;flex-direction:column;">
                                <div class="product-image-wrapper">
                                    <img src="<?= htmlspecialchars($approvedImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($approvedProduct['product_name'], ENT_QUOTES, 'UTF-8') ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22240%22 height=%22240%22%3E%3Crect fill=%22%23f0f3f8%22 width=%22240%22 height=%22240%22/%3E%3C/svg%3E'">
                                </div>
                                <div class="product-info">
                                    <h3 class="product-name"><?= htmlspecialchars($approvedProduct['product_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <div class="product-price">₱<?= number_format((float)$approvedProduct['display_price'], 2) ?></div>
                                    <div class="approved-prescription-meta">
                                        <?= number_format($approvedRemaining) ?> approved pieces remaining
                                        <?php if ($approvedAvailableNow < $approvedRemaining): ?>
                                            · <?= number_format($approvedAvailableNow) ?> currently in stock
                                        <?php endif; ?>
                                    </div>
                                    <div class="approved-prescription-expiry">
                                        Access until <?= $approvedExpiry !== '' ? htmlspecialchars(date('M d, Y', strtotime($approvedExpiry)), ENT_QUOTES, 'UTF-8') : 'no expiry date' ?>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <p><?= $search !== '' ? 'No approved prescription medicines match your search.' : 'No approved prescription medicines are currently available.' ?></p>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($budget_search_submitted && $budget_search_error === '' && $budget_search_results !== null): ?>
        <section class="budget-results-section" aria-labelledby="budgetResultsTitle">
            <h2 class="budget-results-heading" id="budgetResultsTitle">
                Matches for “<?= htmlspecialchars($budget_medicine, ENT_QUOTES, 'UTF-8') ?>” under ₱<?= number_format((float) $budget_amount, 2) ?>
            </h2>
            <?php if (($budget_search_results['error'] ?? '') === 'inventory_unavailable'): ?>
                <div class="budget-search-error" role="alert">Hindi makuha ang product catalog sa ngayon. Subukan ulit mamaya.</div>
            <?php elseif (($budget_search_results['error'] ?? '') === 'no_search_terms'): ?>
                <div class="budget-no-results" role="status">Maglagay ng mas tiyak na pangalan ng gamot o active ingredient para makapaghanap.</div>
            <?php elseif (empty($budget_search_results['items'])): ?>
                <div class="budget-no-results" role="status">
                    <?php if (preg_match('/\b(?:ubo|cough|phlegm|plema)\b/ui', $budget_medicine)): ?>
                        Walang active at in-stock na produktong tumutugma sa ubo sa catalog. Hindi kami magmumungkahi ng ibang gamot o supplement na hindi tugma; magtanong sa pharmacist para sa angkop na option.
                    <?php else: ?>
                        Walang active, in-stock na product na tumugma at pasok sa budget. Subukan ang ibang pangalan, ingredient, o budget.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="budget-results-grid">
                    <?php foreach ($budget_search_results['items'] as $budget_product): ?>
                        <?php
                        $budget_product_price = (float) $budget_product['unit_price'];
                        $budget_image = getProductImageUrl($budget_product['image'] ?? '');
                        $budget_quantity_label = $budget_product['recommended_unit'] === 'box'
                            ? 'Up to ' . (int) $budget_product['max_quantity'] . ' box(es) (' . number_format((int) $budget_product['max_quantity'] * (int) $budget_product['pieces_per_box']) . ' pcs) within budget'
                            : 'Up to ' . (int) $budget_product['max_quantity'] . ' pcs within budget';
                        ?>
                        <a class="budget-result-card <?= !empty($budget_product['is_best_value']) ? 'recommended' : '' ?>" href="view_product.php?id=<?= (int) $budget_product['id'] ?>&amp;return_to=<?= rawurlencode($product_screen_return_url) ?>">
                            <img src="<?= htmlspecialchars($budget_image, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($budget_product['product_name'], ENT_QUOTES, 'UTF-8') ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2260%22 height=%2260%22%3E%3Crect width=%2260%22 height=%2260%22 fill=%22%23eaf3ff%22/%3E%3C/svg%3E'">
                            <span class="budget-result-details">
                                <?php if (!empty($budget_product['is_best_value'])): ?>
                                    <span class="budget-result-tag">Recommended</span>
                                <?php endif; ?>
                                <h3><?= htmlspecialchars($budget_product['product_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <span class="budget-result-price">₱<?= number_format($budget_product_price, 2) ?> <span class="budget-result-unit">/ <?= htmlspecialchars($budget_product['recommended_unit'], ENT_QUOTES, 'UTF-8') ?></span></span>
                                <span class="budget-result-category"><?= htmlspecialchars($budget_product['category'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="budget-result-meta"><?= htmlspecialchars($budget_quantity_label, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="budget-result-view">View Product</span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="budget-ai-insight-panel">
                    <strong>✦ Smart Price Match · AI Insight</strong>
                    <?php if (!empty($budget_search_results['ai_reply'])): ?>
                        <div class="budget-ai-insight"><?= htmlspecialchars($budget_search_results['ai_reply'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php elseif (!empty($budget_search_results['rule_explanation'])): ?>
                        <div class="budget-ai-insight"><?= htmlspecialchars($budget_search_results['rule_explanation'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php else: ?>
                        <div class="budget-ai-insight">Hindi available ang AI explanation sa ngayon. Ang mga option ay inayos batay sa catalog match, presyo kada piraso, budget, at stock. Kumpirmahin sa pharmacist kung angkop ang gamot.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($catalogError !== ''): ?>
        <div role="alert" style="margin:16px 0;padding:12px 16px;border:1px solid #f0c9c9;border-radius:6px;background:#fff4f4;color:#8b1e2d;">
            <?= htmlspecialchars($catalogError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="items-count products-found">
        <?php if ($showApprovedPrescriptions): ?>
            <?= count($visibleApprovedPrescriptionProducts) ?> approved prescription medicines found
        <?php else: ?>
            <?php echo $total_products; ?> products found
        <?php endif; ?>
    </div>

    <?php if (!$showApprovedPrescriptions && !empty($ai_search_products)): ?>
        <div class="ai-search-heading"><span class="ai-sparkle" aria-hidden="true"></span>AI product suggestions</div>
        <div class="products-grid ai-products-grid">
            <?php foreach ($ai_search_products as $ai_product): ?>
                <div class="product-card">
                    <a href="view_product.php?id=<?php echo (int) $ai_product['id']; ?>&amp;return_to=<?php echo rawurlencode($product_screen_return_url); ?>" style="text-decoration: none; color: inherit; flex: 1; display: flex; flex-direction: column;">
                        <div class="product-image-wrapper">
                            <img src="<?php echo htmlspecialchars(getProductImageUrl($ai_product['image'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($ai_product['product_name']); ?>">
                        </div>
                        <div class="product-info">
                            <h3 class="product-name"><?php echo htmlspecialchars($ai_product['product_name']); ?></h3>
                            <div class="product-price">₱<?php echo number_format((float) $ai_product['unit_price'], 2); ?></div>
                            <div class="sold-badge">Available: <?php echo (int) $ai_product['quantity']; ?></div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif (!$showApprovedPrescriptions && $ai_search_reply !== null): ?>
        <div class="ai-search-empty" role="status">Walang eksaktong matching product sa kasalukuyang inventory.</div>
    <?php endif; ?>


    <!-- Products Grid -->
    <div class="products-grid catalog-product-list" <?= $showApprovedPrescriptions ? 'hidden' : '' ?>>
    <?php
    if ($result && $result->num_rows > 0):
        while($row = $result->fetch_assoc()):
            $price = $row['price_piece'] ?? $row['price'] ?? 0;
            $product_id = $row['id'];
    ?>
        <div class="product-card">
            <a href="view_product.php?id=<?php echo $product_id; ?>&amp;return_to=<?php echo rawurlencode($product_screen_return_url); ?>" style="text-decoration: none; color: inherit; flex: 1; display: flex; flex-direction: column;">
                <div class="product-image-wrapper">
                    <img src="<?php echo htmlspecialchars(getProductImageUrl($row['image'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($row['product_name']); ?>" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22240%22 height=%22240%22><rect fill=%22%23f0f3f8%22 width=%22240%22 height=%22240%22/></svg>'">
                </div>
                <div class="product-info">
                    <h3 class="product-name"><?php echo htmlspecialchars($row['product_name']); ?></h3>
                    <div class="product-price">₱<?php echo number_format($price, 2); ?></div>
                    <?php
                    $sold = isset($row['sold']) ? intval($row['sold']) : 0;
                    echo '<div class="sold-badge">🛍️ ' . number_format($sold) . ' Sold</div>';
                    ?>
                </div>
            </a>
            <div class="product-buttons">
            </div>
        </div>
    <?php
        endwhile;
    else:
    ?>
        <div class="empty-state" style="grid-column: 1 / -1;">
            <p>No products found</p>
        </div>
    <?php endif; ?>
    </div>


    <!-- Pagination -->
    <div class="pagination" <?= $showApprovedPrescriptions ? 'hidden' : '' ?>>
        <?php if ($total_pages > 1): ?>
            <?php
            // Build URL parameters for pagination links
            $url_params = [];
            if (!empty($search)) $url_params[] = "search=" . urlencode($search);
            if (!empty($category)) $url_params[] = "category=" . urlencode($category);
            if ($sort !== 'newest') $url_params[] = "sort=" . urlencode($sort);
            $base_url = "product_screen.php?" . implode("&", $url_params);
            if (!empty($url_params)) $base_url .= "&";
            $base_url .= "page=";


           
            // Previous button
            if ($page > 1): ?>
                <a href="<?php echo $base_url . ($page - 1); ?>" class="pagination-btn">‹</a>
            <?php endif; ?>
           
            <?php
            // Page numbers
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
           
            // Sa loob ng iyong pagination loop:
            if ($start_page > 1): ?>
                <a href="<?php echo $base_url; ?>1" class="pagination-btn">1</a>
                <?php if ($start_page > 2): ?>
                    <span class="pagination-btn" style="border:none; cursor:default;">...</span>
                <?php endif; ?>
            <?php endif; ?>
           
            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <a href="<?php echo $base_url . $i; ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
           
            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <span class="pagination-btn" style="background: none; color: #010813; cursor: default;">...</span>
                <?php endif; ?>
                <a href="<?php echo $base_url . $total_pages; ?>" class="pagination-btn"><?php echo $total_pages; ?></a>
            <?php endif; ?>
           
            <?php // Next button ?>
            <?php if ($page < $total_pages): ?>
                <a href="<?php echo $base_url . ($page + 1); ?>" class="pagination-btn">›</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>


</div>

<script>
const input = document.getElementById("searchInput");
const clearBtn = document.getElementById("clearBtn");


// show/hide X button
function toggleClear() {
    clearBtn.style.display = input.value ? "block" : "none";
}


// run on load
toggleClear();

const productSearchRestoreState = sessionStorage.getItem("productCatalogSearchState");
if (productSearchRestoreState !== null) {
    sessionStorage.removeItem("productCatalogSearchState");
    const restoreState = JSON.parse(productSearchRestoreState);
    if (restoreState.path === window.location.pathname && input.value === restoreState.value) {
        input.focus({ preventScroll: true });
        const caret = Math.min(restoreState.caret, input.value.length);
        input.setSelectionRange(caret, caret);
    }
}

// Apply the catalog search as the customer types.
let productSearchTimer;
input.addEventListener("input", function() {
    toggleClear();
    window.clearTimeout(productSearchTimer);
    productSearchTimer = window.setTimeout(function() {
        const url = new URL(window.location.href);
        const search = input.value.trim();
        if (search) {
            url.searchParams.set("search", search);
        } else {
            url.searchParams.delete("search");
        }
        url.searchParams.set("page", "1");
        url.searchParams.delete("ai_search");
        url.searchParams.delete("budget_search");
        url.searchParams.delete("budget_medicine");
        url.searchParams.delete("budget_amount");
        sessionStorage.setItem("productCatalogSearchState", JSON.stringify({
            path: window.location.pathname,
            value: search,
            caret: input.selectionStart === null ? search.length : input.selectionStart
        }));
        window.location.replace(url.toString());
    }, 300);
});


// handle Enter key for search
input.addEventListener("keypress", function(e) {
    if (e.key === "Enter") {
        e.preventDefault();
        const form = input.closest("form");
        const formData = new FormData(form);
        const url = new URL(window.location);
       
        // Update search parameter
        url.searchParams.set('search', input.value);
        url.searchParams.set('page', '1'); // Reset to page 1 on new search
       
        window.location.href = url.toString();
    }
});


// clear + reload
function clearSearch() {
    const url = new URL(window.location);
    url.searchParams.delete('search');
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}


// Sort products while preserving the current search and category filters.
document.getElementById("sortBy").addEventListener("change", function(e) {
    const url = new URL(window.location.href);
    if (e.target.value === "newest") {
        url.searchParams.delete("sort");
    } else {
        url.searchParams.set("sort", e.target.value);
    }
    url.searchParams.set("page", "1");
    window.location.href = url.toString();
});

const budgetSearchToggle = document.getElementById("budgetSearchToggle");
const budgetSearchPanel = document.getElementById("budgetSearchPanel");
const budgetMedicineInput = document.getElementById("budgetMedicine");
const budgetSearchForm = document.querySelector(".budget-search-form");
const budgetSearchLoading = document.getElementById("budgetSearchLoading");

function setBudgetSearchOpen(isOpen) {
    budgetSearchToggle.setAttribute("aria-expanded", String(isOpen));
    budgetSearchToggle.setAttribute("aria-label", isOpen ? "Close AI Budget-Based search" : "Open AI Budget-Based search");
    budgetSearchPanel.hidden = !isOpen;
    document.body.classList.toggle("budget-mode", isOpen);
}

budgetSearchToggle.addEventListener("click", function() {
    const isExpanded = budgetSearchToggle.getAttribute("aria-expanded") === "true";
    setBudgetSearchOpen(!isExpanded);
    if (!isExpanded && budgetMedicineInput) {
        budgetMedicineInput.focus();
    }
});

budgetSearchForm.addEventListener("submit", function() {
    budgetSearchLoading.classList.add("is-visible");
});

document.addEventListener("keydown", function(event) {
    if (event.key === "Escape" && budgetSearchToggle.getAttribute("aria-expanded") === "true") {
        setBudgetSearchOpen(false);
        budgetSearchToggle.focus();
    }
});

</script>


</body>
</html>