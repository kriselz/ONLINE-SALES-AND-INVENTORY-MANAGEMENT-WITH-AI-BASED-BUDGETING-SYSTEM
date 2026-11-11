<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];
$prescriptionId = (int)($_GET['id'] ?? 0);
$conn->query("ALTER TABLE prescriptions MODIFY status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending'");
$conn->query('ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS pharmacy_notes TEXT NULL');
$conn->query('ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS product_access_expires_at DATETIME NULL');
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_limit INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("UPDATE prescription_product_access ppa
    JOIN products p ON p.id = ppa.product_id
    SET ppa.quantity_limit = GREATEST(COALESCE(p.quantity, 0), 0), ppa.quantity_purchased = 0, ppa.expires_at = NULL
    WHERE ppa.quantity_limit = 0 AND ppa.expires_at IS NOT NULL");
$stmt = $conn->prepare('SELECT * FROM prescriptions WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->bind_param('ii', $prescriptionId, $userId);
$stmt->execute();
$prescription = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$prescription) {
    header('Location: support.php');
    exit();
}

$sharedProduct = null;
$sharedProductStock = 0;
$sharedProductQuantityRemaining = 0;
$sharedProductAccessExpired = false;
$sharedProductCanOrder = false;
$sharedProductMessage = '';
$sharedProductId = (int)($prescription['shared_product_id'] ?? 0);
if ($sharedProductId < 1) {
    $accessProductStmt = $conn->prepare('SELECT product_id FROM prescription_product_access
        WHERE user_id = ? AND prescription_id = ? ORDER BY granted_at DESC LIMIT 1');
    if ($accessProductStmt) {
        $accessProductStmt->bind_param('ii', $userId, $prescriptionId);
        if ($accessProductStmt->execute()) {
            $sharedProductId = (int)($accessProductStmt->get_result()->fetch_assoc()['product_id'] ?? 0);
        } else {
            error_log('Customer prescription access product lookup failed: ' . $accessProductStmt->error);
        }
        $accessProductStmt->close();
    } else {
        error_log('Customer prescription access product lookup preparation failed: ' . $conn->error);
    }
}
if ($sharedProductId < 1) {
    $chatProductStmt = $conn->prepare('SELECT COALESCE(NULLIF(shared_product_id, 0), product_id) AS product_id
        FROM customer_chat_messages
        WHERE user_id = ? AND prescription_id = ? AND (shared_product_id IS NOT NULL OR product_id IS NOT NULL)
        ORDER BY created_at DESC LIMIT 1');
    if ($chatProductStmt) {
        $chatProductStmt->bind_param('ii', $userId, $prescriptionId);
        if ($chatProductStmt->execute()) {
            $sharedProductId = (int)($chatProductStmt->get_result()->fetch_assoc()['product_id'] ?? 0);
        } else {
            error_log('Customer prescription chat product lookup failed: ' . $chatProductStmt->error);
        }
        $chatProductStmt->close();
    } else {
        error_log('Customer prescription chat product lookup preparation failed: ' . $conn->error);
    }
}
if ($sharedProductId > 0) {
    $productStmt = $conn->prepare("SELECT p.id, p.product_name, p.image, p.category, p.prescription_required,
            p.status, p.price, p.price_piece, p.price_box, p.quantity, p.box, p.pieces, p.pieces_per_box,
            (SELECT NULLIF(pi.image_path, '')
             FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.sort_order ASC LIMIT 1) AS gallery_image,
            (SELECT COUNT(*) FROM product_batches pb WHERE pb.product_id = p.id) AS batch_count,
            (SELECT COALESCE(SUM(CASE WHEN pb.recalled = 0 AND pb.expiration_date >= CURDATE() THEN pb.current_quantity ELSE 0 END), 0)
             FROM product_batches pb WHERE pb.product_id = p.id) AS sellable_batch_stock,
            (SELECT ppa.quantity_limit
             FROM prescription_product_access ppa
             WHERE ppa.product_id = p.id AND ppa.user_id = ? AND ppa.prescription_id = ?
             ORDER BY ppa.granted_at DESC LIMIT 1) AS quantity_limit,
            (SELECT GREATEST(ppa.quantity_limit - ppa.quantity_purchased, 0)
             FROM prescription_product_access ppa
             WHERE ppa.product_id = p.id AND ppa.user_id = ? AND ppa.prescription_id = ?
             ORDER BY ppa.granted_at DESC LIMIT 1) AS quantity_remaining,
            (SELECT ppa.expires_at
             FROM prescription_product_access ppa
             WHERE ppa.product_id = p.id AND ppa.user_id = ? AND ppa.prescription_id = ?
             ORDER BY ppa.granted_at DESC LIMIT 1) AS access_expires_at
        FROM products p WHERE p.id = ? LIMIT 1");
    if (!$productStmt) {
        error_log('Customer prescription product query preparation failed: ' . $conn->error);
        http_response_code(503);
        exit('The approved product is temporarily unavailable.');
    }
    $productStmt->bind_param('iiiiiii', $userId, $prescriptionId, $userId, $prescriptionId, $userId, $prescriptionId, $sharedProductId);
    if (!$productStmt->execute()) {
        error_log('Customer prescription product query failed: ' . $productStmt->error);
        $productStmt->close();
        http_response_code(503);
        exit('The approved product is temporarily unavailable.');
    }
    $sharedProduct = $productStmt->get_result()->fetch_assoc();
    $productStmt->close();
    if ($sharedProduct) {
        $piecesPerBox = max(1, (int)($sharedProduct['pieces_per_box'] ?? 12));
        $sharedProductStock = max(
            0,
            (int)($sharedProduct['quantity'] ?? (((int)($sharedProduct['box'] ?? 0) * $piecesPerBox) + (int)($sharedProduct['pieces'] ?? 0)))
        );
        if ((int)($sharedProduct['batch_count'] ?? 0) > 0) {
            $sharedProductStock = min($sharedProductStock, max(0, (int)($sharedProduct['sellable_batch_stock'] ?? 0)));
        }
        $sharedProductQuantityRemaining = max(0, (int)($sharedProduct['quantity_remaining'] ?? 0));
        $sharedProductAccessExpired = !empty($sharedProduct['access_expires_at'])
            && strtotime((string)$sharedProduct['access_expires_at']) < time();
    }
}

if ($sharedProduct) {
    $sharedMessageStmt = $conn->prepare("SELECT message FROM customer_chat_messages
        WHERE user_id = ? AND prescription_id = ?
          AND COALESCE(NULLIF(shared_product_id, 0), NULLIF(product_id, 0)) = ?
          AND sender_role IN ('admin', 'pharmacist')
          AND message IS NOT NULL AND TRIM(message) <> ''
        ORDER BY created_at DESC LIMIT 1");
    if ($sharedMessageStmt) {
        $sharedMessageStmt->bind_param('iii', $userId, $prescriptionId, $sharedProductId);
        if ($sharedMessageStmt->execute()) {
            $sharedProductMessage = trim((string)($sharedMessageStmt->get_result()->fetch_assoc()['message'] ?? ''));
        } else {
            error_log('Customer prescription pharmacy message lookup failed: ' . $sharedMessageStmt->error);
        }
        $sharedMessageStmt->close();
    } else {
        error_log('Customer prescription pharmacy message lookup preparation failed: ' . $conn->error);
    }
}

$status = strtolower($prescription['status']);
if ($sharedProduct && $sharedProductQuantityRemaining < 1 && $status === 'approved') {
    $status = 'completed';
    $completePrescription = $conn->prepare("UPDATE prescriptions SET status = 'completed' WHERE id = ? AND user_id = ? AND status = 'approved'");
    if ($completePrescription) {
        $completePrescription->bind_param('ii', $prescriptionId, $userId);
        $completePrescription->execute();
        $completePrescription->close();
    }
} elseif ($sharedProduct && $sharedProductQuantityRemaining > 0 && $status === 'completed') {
    $status = 'approved';
    $restorePrescription = $conn->prepare("UPDATE prescriptions SET status = 'approved' WHERE id = ? AND user_id = ? AND status = 'completed'");
    if ($restorePrescription) {
        $restorePrescription->bind_param('ii', $prescriptionId, $userId);
        $restorePrescription->execute();
        $restorePrescription->close();
    }
}
$statusTitle = $status === 'pending' ? 'Pending Review' : ucfirst($status);
$filePath = 'prescription_file.php?id=' . (int)$prescription['id'] . '&type=prescription';
$fileExtension = strtolower(pathinfo($prescription['file_name'], PATHINFO_EXTENSION));
$isImage = in_array($fileExtension, ['jpg', 'jpeg', 'png', 'webp'], true);
$validIdPath = !empty($prescription['valid_id_file_name']) ? 'prescription_file.php?id=' . (int)$prescription['id'] . '&type=valid_id' : null;
$submittedAt = strtotime($prescription['submitted_at']);
$reviewedAt = !empty($prescription['reviewed_at']) ? strtotime($prescription['reviewed_at']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription Details</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined">
    <style>
        :root { --primary:#8B1E2D; --dark:#6F1724; --soft:#FBEAEC; --ink:#172235; --muted:#6b7280; }
        * { box-sizing:border-box; }
        body { margin:0; background:#f8f9fb; color:var(--ink); font-family:Arial, 'Segoe UI', sans-serif; }
        .main-content { margin-left:260px; min-height:100vh; padding:28px 46px 50px; }
        .detail-panel { max-width:900px; margin:0 auto; background:#fff; border:1px solid #e7e9ed; border-radius:10px; padding:22px 28px 34px; box-shadow:0 3px 14px rgba(30,41,59,.04); }
        .back-link { display:inline-flex; align-items:center; gap:5px; color:var(--primary); text-decoration:none; font-size:13px; font-weight:700; margin-bottom:22px; }
        .detail-header { display:flex; align-items:center; gap:14px; margin-bottom:18px; }
        .status-icon { width:46px; height:46px; border-radius:50%; display:grid; place-items:center; background:#fff5df; color:#d89b16; flex:0 0 46px; }
        .status-icon.approved { background:#eaf7ee; color:#2e8b57; } .status-icon.rejected { background:var(--soft); color:var(--primary); } .status-icon.completed { background:#edf0f4; color:#667085; }
        h1 { margin:0 0 5px; font-size:20px; } .meta { margin:0; color:var(--muted); font-size:13px; }
        .status-label { margin-left:auto; border-radius:5px; padding:7px 10px; background:#fff5df; color:#a76e00; font-size:12px; font-weight:700; white-space:nowrap; } .status-label.approved { background:#eaf7ee; color:#287742; } .status-label.rejected { background:var(--soft); color:var(--primary); } .status-label.completed { background:#edf0f4; color:#667085; }
        .review-banner { display:flex; gap:12px; align-items:center; padding:13px 15px; border:1px solid #f2d99c; background:#fffaf0; border-radius:8px; color:#4b5563; font-size:12px; margin-bottom:24px; } .review-banner .material-icons-outlined { color:#d89b16; }
        .section-heading { display:flex; justify-content:space-between; align-items:center; margin:20px 0 12px; } h2 { font-size:15px; margin:0; } .open-file { border:1px solid #e2e5e9; border-radius:6px; color:#374151; background:#fff; padding:8px 11px; text-decoration:none; font-size:12px; }
        .uploaded { display:grid; grid-template-columns:minmax(260px, 1fr) 1fr; gap:22px; align-items:start; } .preview { height:190px; border:1px solid #e2e5e9; border-radius:7px; background:#f7f8fa; display:grid; place-items:center; overflow:hidden; color:var(--muted); font-size:13px; } .preview img { width:100%; height:100%; object-fit:contain; } .file-details p { margin:0 0 11px; color:var(--muted); font-size:13px; } .file-details strong { display:block; color:var(--ink); margin-top:3px; font-weight:500; }
        .shared-product { margin:0 0 24px; padding:20px; border:1px solid #e3e9ef; border-radius:12px; background:#fff; box-shadow:0 4px 18px rgba(30,41,59,.06); }
        .shared-product-heading { margin:0 0 16px; color:#243b59; font-size:15px; }
        .shared-product-card { position:relative; display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.08fr); gap:28px; align-items:center; padding-top:40px; }
        .shared-product-card-link { position:absolute; z-index:1; inset:0; }
        .shared-product-card-link:focus-visible { outline:3px solid #0868d7; outline-offset:3px; border-radius:8px; }
        .shared-product-card.is-clickable .shared-product-image,.shared-product-card.is-clickable .shared-product-info { position:relative; z-index:2; pointer-events:none; }
        .shared-product-card.is-clickable .shared-product-controls { position:relative; z-index:3; pointer-events:auto; }
        .shared-product-validity { position:absolute; z-index:4; top:0; right:0; display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border:1px solid #b9e5cc; border-radius:20px; background:#e8f7ef; color:#168352; font-size:12px; font-weight:700; pointer-events:none; }
        .shared-product-validity .material-icons-outlined { font-size:16px; }
        .shared-product-validity.expired { border-color:#f3c7cf; background:#fff0f2; color:#b4233d; }
        .shared-product-image { position:relative; display:grid; width:100%; aspect-ratio:1 / 1; place-items:center; overflow:hidden; border-radius:12px; background:#edf5ff; color:#8291a5; }
        .shared-product-image .material-icons-outlined { position:absolute; font-size:64px; }
        .shared-product-image img { position:relative; width:100%; height:100%; object-fit:contain; background:transparent; }
        .shared-product-info { display:grid; min-width:0; gap:14px; align-content:center; }
        .shared-product-info h3 { margin:0; color:#123052; font-size:clamp(22px,2.3vw,32px); line-height:1.15; overflow-wrap:anywhere; }
        .shared-product-rating { display:flex; align-items:center; gap:8px; color:#ffb400; font-size:17px; letter-spacing:1px; line-height:1; }
        .shared-product-rating-count { color:#7c8ca3; font-size:12px; letter-spacing:0; }
        .shared-product-category { width:max-content; padding:5px 9px; border-radius:6px; background:#eaf2ff; color:#315c91; font-size:11px; font-weight:700; }
        .shared-product-prices { display:flex; flex-wrap:wrap; align-items:baseline; gap:8px; color:#15955c; font-size:34px; line-height:1.1; font-weight:800; }
        .shared-product-prices strong { color:#15955c; font-size:inherit; }
        .shared-product-stock { display:inline-flex; align-items:center; gap:8px; width:max-content; max-width:100%; padding:10px 14px; border-radius:24px; background:#e8f7ef; color:#168352; font-size:14px; font-weight:700; }
        .shared-product-stock .material-icons-outlined { font-size:19px; }
        .shared-product-stock.unavailable { background:#fff0f2; color:#b4233d; }
        .shared-product-expiry { color:#687b94; font-size:11px; }
        .shared-product-controls { display:grid; min-width:0; gap:10px; }
        .shared-product-quantity-label { color:#53657a; font-size:15px; font-weight:700; }
        .shared-product-quantity { display:flex; width:100%; height:56px; overflow:hidden; border:1px solid #d9e4ef; border-radius:12px; background:#fff; }
        .shared-product-quantity button { width:54px; flex:0 0 54px; border:0; background:#f1f5f9; color:#536d87; font-size:25px; cursor:pointer; }
        .shared-product-quantity button:disabled { color:#b8c0ca; cursor:not-allowed; }
        .shared-product-quantity input { width:100%; min-width:0; border:0; outline:0; color:#243b59; font-size:22px; font-weight:700; text-align:center; }
        .shared-product-actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .shared-product-actions button { min-height:54px; padding:0 13px; display:inline-flex; align-items:center; justify-content:center; gap:8px; border:0; border-radius:10px; color:#fff; font:inherit; font-size:15px; font-weight:700; text-decoration:none; cursor:pointer; }
        .shared-product-add { background:#0868d7; }
        .shared-product-buy { background:#15955c; }
        .shared-product-actions button:disabled { opacity:.5; cursor:not-allowed; }
        .shared-product-feedback { min-height:0; color:#b4233d; font-size:12px; }
        .shared-product-feedback:empty { display:none; }
        .notes { margin-top:18px; padding:13px 15px; background:#fafafa; border-radius:7px; font-size:13px; color:#4b5563; } .notes strong { display:block; color:var(--ink); margin-bottom:5px; }
        .timeline { margin:0 0 24px; padding:0 0 22px; border-bottom:1px solid #eceef1; }
        .timeline-steps { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:24px; max-width:780px; margin:0 auto; }
        .timeline-item { display:grid; grid-template-columns:minmax(0,1fr); grid-template-rows:38px auto auto auto; justify-items:center; align-content:start; row-gap:7px; min-width:0; position:relative; text-align:center; }
        .timeline-item:not(:last-child)::after { content:""; position:absolute; z-index:0; top:18px; left:calc(50% + 18px); right:calc(-50% - 12px); border-top:2px dotted #d7dce2; }
        .timeline-item.connector-done:not(:last-child)::after { border-top-style:solid; border-top-color:var(--primary); }
        .timeline-dot { grid-column:1; grid-row:1; width:36px; height:36px; border-radius:50%; display:grid; place-items:center; border:2px solid #dfe3e8; color:#9aa3af; background:#fff; z-index:1; }
        .timeline-dot.done { color:#fff; border-color:var(--primary); background:var(--primary); }
        .timeline-dot.active { border-color:#d89b16; color:#a76e00; background:#fff5df; }
        .timeline-dot.rejected { border-color:var(--primary); color:#fff; background:var(--primary); }
        .timeline-item h3 { grid-column:1; grid-row:2; margin:0; font-size:15px; }
        .timeline-item p { grid-column:1; grid-row:3; max-width:220px; margin:0; color:var(--muted); font-size:13px; }
        .timeline-time { grid-column:1; grid-row:4; color:var(--muted); font-size:12px; }
        @media (max-width:900px) { .shared-product-card { grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:18px; } .shared-product-info { gap:11px; } .shared-product-prices { font-size:28px; } .shared-product-actions button { font-size:13px; } }
        @media (max-width:720px) { .main-content { margin-left:0; padding:20px 14px; } .detail-panel { padding:18px; } .uploaded { grid-template-columns:1fr; } .status-label { margin-left:auto; } .shared-product { padding:15px; } .shared-product-card { grid-template-columns:1fr; gap:18px; } .shared-product-image { max-width:420px; margin:0 auto; } .shared-product-info h3 { font-size:25px; } .shared-product-prices { font-size:30px; } .timeline-steps { grid-template-columns:1fr; gap:18px; } .timeline-item { grid-template-columns:38px minmax(0,1fr); grid-template-rows:auto auto auto; justify-items:start; column-gap:12px; row-gap:5px; text-align:left; } .timeline-item:not(:last-child)::after { top:38px; bottom:-18px; left:17px; right:auto; border-top:0; border-left:2px dotted #d7dce2; } .timeline-item.connector-done:not(:last-child)::after { border-left-style:solid; border-left-color:var(--primary); } .timeline-dot { grid-column:1; grid-row:1 / span 3; } .timeline-item h3,.timeline-item p,.timeline-time { grid-column:2; } .timeline-item h3 { grid-row:1; margin-top:2px; } .timeline-item p { grid-row:2; max-width:none; } .timeline-time { grid-row:3; } }
    </style>
</head>
<body>
<?php include 'sidebar_user.php'; ?>
<main class="main-content">
    <section class="detail-panel">
        <a class="back-link" href="support.php"><span class="material-icons-outlined">arrow_back</span> Back to Prescriptions</a>
        <header class="detail-header">
            <div class="status-icon <?= $status ?>"><span class="material-icons-outlined"><?= in_array($status, ['approved', 'completed'], true) ? 'check_circle' : ($status === 'rejected' ? 'close' : 'schedule') ?></span></div>
            <div><h1>Prescription #P-<?= str_pad((string)$prescription['id'], 4, '0', STR_PAD_LEFT) ?></h1><p class="meta">Submitted <?= date('M d, Y  •  h:i A', $submittedAt) ?></p></div>
            <span class="status-label <?= $status ?>"><?= htmlspecialchars($statusTitle) ?></span>
        </header>

        <div class="timeline">
            <div class="timeline-steps">
                <div class="timeline-item connector-done">
                    <div class="timeline-dot done"><span class="material-icons-outlined" style="font-size:16px">check</span></div>
                    <h3>Prescription Submitted</h3>
                    <p>Your prescription has been submitted successfully.</p>
                    <span class="timeline-time"><?= date('M d, Y  •  h:i A', $submittedAt) ?></span>
                </div>
                <div class="timeline-item <?= $status !== 'pending' ? 'connector-done' : '' ?>">
                    <div class="timeline-dot <?= $status === 'pending' ? 'active' : 'done' ?>"><span class="material-icons-outlined" style="font-size:16px"><?= $status === 'pending' ? 'more_horiz' : 'check' ?></span></div>
                    <h3><?= $status === 'pending' ? 'Under Review' : 'Review Complete' ?></h3>
                    <p><?= $status === 'pending' ? 'Our pharmacy staff is reviewing your prescription.' : 'Our pharmacy staff has completed the review.' ?></p>
                    <span class="timeline-time"><?= $reviewedAt ? date('M d, Y  •  h:i A', $reviewedAt) : '-' ?></span>
                </div>
                <div class="timeline-item">
                    <div class="timeline-dot <?= $status === 'pending' ? '' : ($status === 'rejected' ? 'rejected' : 'done') ?>"><span class="material-icons-outlined" style="font-size:16px"><?= $status === 'completed' || $status === 'approved' ? 'check' : ($status === 'rejected' ? 'close' : 'radio_button_unchecked') ?></span></div>
                    <h3><?= $status === 'pending' ? 'Awaiting Decision' : ($status === 'completed' ? 'Completed' : ucfirst($status)) ?></h3>
                    <p><?= $status === 'pending' ? 'The pharmacy will update this step after review.' : ($status === 'completed' ? 'The pharmacist-set quantity has been purchased.' : ($status === 'rejected' ? 'The pharmacy did not approve this prescription.' : 'Your prescription is approved and ready for ordering.')) ?></p>
                    <span class="timeline-time"><?= $reviewedAt ? date('M d, Y  •  h:i A', $reviewedAt) : '-' ?></span>
                </div>
            </div>
        </div>

        <?php if ($status === 'pending'): ?><div class="review-banner"><span class="material-icons-outlined">schedule</span><div><strong>Your prescription is currently being reviewed by our pharmacy staff.</strong><br>We will notify you once it has been reviewed.</div></div><?php elseif ($status === 'approved'): ?><div class="review-banner" style="background:#f0faf3;border-color:#b9dfc5"><span class="material-icons-outlined" style="color:#2e8b57">check_circle</span><div><strong>Your prescription has been approved.</strong><br>You may proceed with your order.</div></div><?php elseif ($status === 'completed'): ?><div class="review-banner"><span class="material-icons-outlined">check_circle</span><div><strong>The pharmacist-set quantity for this prescription has been purchased.</strong><br>Contact the pharmacy in Customer Chat if you need help.</div></div><?php else: ?><div class="review-banner" style="background:var(--soft);border-color:#e9b8c0"><span class="material-icons-outlined" style="color:var(--primary)">close</span><div><strong>Your prescription was rejected.</strong><br><?= trim((string)($prescription['review_reason'] ?? '')) !== '' ? nl2br(htmlspecialchars((string)$prescription['review_reason'], ENT_QUOTES, 'UTF-8')) : 'Please submit a clearer or updated prescription.' ?></div></div><?php endif; ?>

        <?php if ($sharedProductMessage !== '' || trim((string)($prescription['notes'] ?? '')) !== '' || trim((string)($prescription['pharmacy_notes'] ?? '')) !== ''): ?>
            <div class="notes">
                <strong>Messages or notes</strong>
                <?php if ($sharedProductMessage !== ''): ?><p><?= nl2br(htmlspecialchars($sharedProductMessage, ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
                <?php if (trim((string)($prescription['notes'] ?? '')) !== ''): ?><p><?= nl2br(htmlspecialchars((string)$prescription['notes'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
                <?php if (trim((string)($prescription['pharmacy_notes'] ?? '')) !== ''): ?><p><strong>Pharmacy notes</strong><?= nl2br(htmlspecialchars((string)$prescription['pharmacy_notes'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($sharedProduct): ?>
            <?php
            $sharedProductCategory = trim((string)($sharedProduct['category'] ?? ''));
            if (!empty($sharedProduct['prescription_required']) || strtolower($sharedProductCategory) === 'prescription medicine') {
                $sharedProductCategory = 'Prescription';
            } elseif ($sharedProductCategory === '') {
                $sharedProductCategory = 'Medicine';
            }
            $sharedProductImage = trim((string)($sharedProduct['gallery_image'] ?? ''));
            if ($sharedProductImage === '') {
                $sharedProductImage = trim((string)($sharedProduct['image'] ?? ''));
            }
            $sharedProductImagePath = ltrim(str_replace('\\', '/', $sharedProductImage), '/');
            $sharedProductImageUrl = preg_match('/^https?:\/\//i', $sharedProductImagePath)
                ? $sharedProductImagePath
                : 'uploads/' . preg_replace('#^(?:\.\./|uploads/)+#i', '', $sharedProductImagePath);
            $sharedPiecePrice = (float)($sharedProduct['price_piece'] ?? 0);
            if ($sharedPiecePrice <= 0) {
                $sharedPiecePrice = (float)($sharedProduct['price'] ?? 0);
            }
            $sharedPiecesPerBox = max(1, (int)($sharedProduct['pieces_per_box'] ?? 12));
            $sharedBoxPrice = round($sharedPiecePrice * $sharedPiecesPerBox * 0.98, 2);
            $sharedProductCanOrder = $status === 'approved'
                && $sharedProductQuantityRemaining > 0
                && !$sharedProductAccessExpired
                && strtolower(trim((string)($sharedProduct['status'] ?? ''))) === 'active'
                && $sharedProductStock > 0;
            $sharedProductUnavailableReason = $sharedProductAccessExpired
                ? 'Prescription access expired on ' . date('M d, Y', strtotime((string)$sharedProduct['access_expires_at'])) . '.'
                : ($sharedProductQuantityRemaining < 1
                ? 'The pharmacist-set quantity limit has been reached.'
                : (strtolower(trim((string)($sharedProduct['status'] ?? ''))) !== 'active'
                    ? 'This product is currently unavailable.'
                    : 'This product is currently out of stock.'));
            ?>
            <section class="shared-product" id="approved-product">
                <h2 class="shared-product-heading">Product shared by the pharmacy</h2>
                <div class="shared-product-card is-clickable">
                    <a class="shared-product-card-link" href="view_product.php?id=<?= (int)$sharedProduct['id'] ?>" aria-label="View <?= htmlspecialchars($sharedProduct['product_name'], ENT_QUOTES, 'UTF-8') ?>"></a>
                    <?php if (!empty($sharedProduct['access_expires_at'])): ?><span class="shared-product-validity<?= $sharedProductAccessExpired ? ' expired' : '' ?>"><span class="material-icons-outlined" aria-hidden="true"><?= $sharedProductAccessExpired ? 'event_busy' : 'event_available' ?></span><?= $sharedProductAccessExpired ? 'Access expired' : 'Valid until' ?> <?= htmlspecialchars(date('M d, Y', strtotime((string)$sharedProduct['access_expires_at'])), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    <div class="shared-product-image">
                        <span class="material-icons-outlined" aria-hidden="true">medication</span>
                        <?php if ($sharedProductImage !== ''): ?><img src="<?= htmlspecialchars($sharedProductImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($sharedProduct['product_name'], ENT_QUOTES, 'UTF-8') ?>" onerror="this.remove()"><?php endif; ?>
                    </div>
                    <div class="shared-product-info">
                        <div class="shared-product-rating" aria-label="5 out of 5 stars"><span aria-hidden="true">★ ★ ★ ★ ★</span><span class="shared-product-rating-count">(128 reviews)</span></div>
                        <h3><?= htmlspecialchars($sharedProduct['product_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <span class="shared-product-category">Prescription</span>
                        <div class="shared-product-prices">
                            <strong id="sharedProductPrice">₱<?= number_format($sharedPiecePrice, 2) ?></strong>
                        </div>
                        <span class="shared-product-stock<?= $sharedProductStock > 0 ? '' : ' unavailable' ?>"><span class="material-icons-outlined" aria-hidden="true"><?= $sharedProductStock > 0 ? 'check_circle' : 'error' ?></span><?= $sharedProductStock > 0 ? 'Stock: ' . number_format($sharedProductStock) . ' available' : 'This product is currently out of stock.' ?></span>
                        <span class="shared-product-expiry">Pharmacy-set quantity limit: <?= number_format((int)($sharedProduct['quantity_limit'] ?? 0)) ?> total · <?= number_format($sharedProductQuantityRemaining) ?> remaining</span>
                        <?php if (!$sharedProductCanOrder && !$sharedProductAccessExpired && $sharedProductQuantityRemaining > 0 && $sharedProductStock > 0): ?><span class="shared-product-expiry"><?= htmlspecialchars($sharedProductUnavailableReason, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        <?php if ($sharedProductCanOrder): ?>
                            <div class="shared-product-controls" data-product-id="<?= (int)$sharedProduct['id'] ?>">
                                <span class="shared-product-quantity-label">Quantity</span>
                                <div class="shared-product-quantity">
                                    <button type="button" data-quantity-step="-1" aria-label="Decrease quantity">−</button>
                                    <input type="number" value="1" min="1" max="<?= min($sharedProductStock, $sharedProductQuantityRemaining) ?>" data-stock="<?= min($sharedProductStock, $sharedProductQuantityRemaining) ?>" data-pieces-per-box="<?= $sharedPiecesPerBox ?>" aria-label="Quantity">
                                    <button type="button" data-quantity-step="1" aria-label="Increase quantity">+</button>
                                </div>
                                <div class="shared-product-actions">
                                    <button type="button" class="shared-product-add"><span class="material-icons-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button>
                                    <button type="button" class="shared-product-buy"><span class="material-icons-outlined" aria-hidden="true">bolt</span>Buy Now</button>
                                </div>
                                <span class="shared-product-feedback" role="alert" aria-live="polite"></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php elseif ($status === 'approved'): ?>
            <section class="shared-product" id="approved-product">
                <h2 class="shared-product-heading">Product shared by the pharmacy</h2>
                <p class="meta">No product has been linked to this prescription yet. Please contact the pharmacy for help.</p>
            </section>
        <?php endif; ?>

        <div class="section-heading"><h2>Uploaded Prescription</h2><a class="open-file" href="<?= htmlspecialchars($filePath) ?>" target="_blank" rel="noopener">View Full Size <span class="material-icons-outlined" style="font-size:14px;vertical-align:middle">open_in_new</span></a></div>
        <div class="uploaded"><div class="preview"><?php if ($isImage): ?><img src="<?= htmlspecialchars($filePath) ?>" alt="Uploaded prescription"><?php else: ?><span class="material-icons-outlined" style="font-size:42px;color:var(--primary)">picture_as_pdf</span><?php endif; ?></div><div class="file-details"><p>File name<strong><?= htmlspecialchars($prescription['original_name']) ?></strong></p><p>Uploaded on<strong><?= date('M d, Y  •  h:i A', $submittedAt) ?></strong></p></div></div>

        <?php if ($validIdPath): ?><div class="section-heading"><h2>Valid ID</h2><a class="open-file" href="<?= htmlspecialchars($validIdPath) ?>" target="_blank" rel="noopener">View Full Size <span class="material-icons-outlined" style="font-size:14px;vertical-align:middle">open_in_new</span></a></div><div class="uploaded"><div class="preview"><img src="<?= htmlspecialchars($validIdPath) ?>" alt="Uploaded valid ID"></div><div class="file-details"><p>File name<strong><?= htmlspecialchars($prescription['valid_id_original_name']) ?></strong></p></div></div><?php endif; ?>

    </section>
</main>
<?php if ($sharedProduct && $sharedProductCanOrder): ?>
<script>
(() => {
    const controls = document.querySelector('.shared-product-controls');
    const quantityInput = controls && controls.querySelector('input[type="number"]');
    const decreaseButton = controls && controls.querySelector('[data-quantity-step="-1"]');
    const increaseButton = controls && controls.querySelector('[data-quantity-step="1"]');
    const addButton = controls && controls.querySelector('.shared-product-add');
    const buyButton = controls && controls.querySelector('.shared-product-buy');
    const feedback = controls && controls.querySelector('.shared-product-feedback');
    if (!controls || !quantityInput || !decreaseButton || !increaseButton || !addButton || !buyButton || !feedback) return;

    const stock = Number.parseInt(quantityInput.dataset.stock, 10) || 0;
    const getMaximum = () => stock;
    const updateControls = () => {
        const quantity = Number.parseInt(quantityInput.value, 10);
        const maximum = getMaximum();
        const valid = Number.isInteger(quantity) && quantity >= 1 && quantity <= maximum;
        quantityInput.max = String(maximum);
        decreaseButton.disabled = !Number.isInteger(quantity) || quantity <= 1;
        increaseButton.disabled = !Number.isInteger(quantity) || quantity >= maximum;
        addButton.disabled = !valid;
        buyButton.disabled = !valid;
        if (valid) feedback.textContent = '';
    };

    decreaseButton.addEventListener('click', () => {
        quantityInput.value = String(Math.max(1, (Number.parseInt(quantityInput.value, 10) || 1) - 1));
        updateControls();
    });
    increaseButton.addEventListener('click', () => {
        quantityInput.value = String(Math.min(getMaximum(), (Number.parseInt(quantityInput.value, 10) || 1) + 1));
        updateControls();
    });
    quantityInput.addEventListener('input', updateControls);
    addButton.addEventListener('click', async () => {
        updateControls();
        if (addButton.disabled) return;
        addButton.disabled = true;
        feedback.textContent = '';
        const formData = new FormData();
        formData.set('product_id', controls.dataset.productId);
        formData.set('quantity', quantityInput.value);
        formData.set('unit', 'piece');
        try {
            const response = await fetch('add_to_cart.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const contentType = response.headers.get('content-type') || '';
            const result = contentType.includes('application/json')
                ? await response.json()
                : { success: false, message: await response.text() };
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to add the product to your cart.');
            const badge = document.getElementById('cartCountBadge');
            if (badge && Number.isInteger(Number(result.cart_count))) {
                badge.textContent = String(result.cart_count);
                badge.style.display = result.cart_count > 0 ? '' : 'none';
            }
        } catch (error) {
            feedback.textContent = error.message;
        } finally {
            updateControls();
        }
    });
    buyButton.addEventListener('click', () => {
        updateControls();
        if (buyButton.disabled) return;
        const params = new URLSearchParams({
            product_id: controls.dataset.productId,
            quantity: quantityInput.value,
            unit: 'piece'
        });
        window.location.href = 'receipt.php?' + params.toString();
    });
    updateControls();
})();
</script>
<?php endif; ?>
</body>
</html>
