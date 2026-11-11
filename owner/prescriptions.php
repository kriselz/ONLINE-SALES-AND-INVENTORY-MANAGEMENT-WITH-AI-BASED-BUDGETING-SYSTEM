<?php
session_start();
include 'config.php';
include 'access_control.php';
require_once 'functions.php';

requireOwnerPageAccess($conn, true);

$conn->query("CREATE TABLE IF NOT EXISTS prescriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE prescriptions MODIFY status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending'");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS notes TEXT NULL");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS valid_id_original_name VARCHAR(255) NULL");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS valid_id_file_name VARCHAR(255) NULL");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS review_reason TEXT NULL");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS shared_product_id INT NULL");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS product_access_expires_at DATETIME NULL");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS pharmacy_notes TEXT NULL");
$conn->query("CREATE TABLE IF NOT EXISTS prescription_product_access (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    granted_by INT NULL,
    prescription_id INT NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    quantity_limit INT UNSIGNED NOT NULL DEFAULT 0,
    quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_prescription_product_user (product_id, user_id),
    KEY idx_prescription_access_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS prescription_id INT NULL");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_limit INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0");
$completedPrescriptionSync = $conn->query("UPDATE prescriptions p
    JOIN prescription_product_access ppa
      ON ppa.prescription_id = p.id AND ppa.user_id = p.user_id
    SET p.status = 'completed'
    WHERE p.status = 'approved'
      AND ppa.quantity_limit > 0
      AND ppa.quantity_purchased >= ppa.quantity_limit");
if (!$completedPrescriptionSync) {
    error_log('Prescription completion status sync failed: ' . $conn->error);
}

$message = '';
$error = $completedPrescriptionSync ? '' : 'Unable to refresh completed prescription statuses.';
$emailWarning = '';
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS prescription_required TINYINT(1) NOT NULL DEFAULT 0");
$approvalProductsResult = $conn->query("SELECT p.id, p.product_name, p.status, p.box, p.pieces, p.pieces_per_box,
    (SELECT COUNT(*) FROM product_batches pb WHERE pb.product_id = p.id) AS batch_count,
    (SELECT COALESCE(SUM(CASE WHEN pb.recalled = 0 AND pb.expiration_date >= CURDATE() THEN pb.current_quantity ELSE 0 END), 0)
     FROM product_batches pb WHERE pb.product_id = p.id) AS sellable_batch_stock
    FROM products p WHERE p.prescription_required = 1 ORDER BY p.product_name ASC");
$approvalProducts = [];
if ($approvalProductsResult) {
    while ($approvalProduct = $approvalProductsResult->fetch_assoc()) {
        $piecesPerBox = max(1, (int)($approvalProduct['pieces_per_box'] ?? 12));
        $availableStock = max(0, ((int)($approvalProduct['box'] ?? 0) * $piecesPerBox) + (int)($approvalProduct['pieces'] ?? 0));
        if ((int)($approvalProduct['batch_count'] ?? 0) > 0) {
            $availableStock = min($availableStock, max(0, (int)($approvalProduct['sellable_batch_stock'] ?? 0)));
        }
        $approvalProduct['available_stock'] = $availableStock;
        $approvalProducts[] = $approvalProduct;
    }
} elseif (!$error) {
    $error = 'Unable to load prescription medicines for approval.';
    error_log('Prescription approval product list query failed: ' . $conn->error);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    $prescriptionId = (int)($_POST['prescription_id'] ?? 0);
    $status = strtolower(trim($_POST['status'] ?? ''));
    if ($prescriptionId > 0 && in_array($status, ['approved', 'rejected'], true)) {
        if ($status === 'approved') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $quantityLimit = filter_var($_POST['quantity_limit'] ?? null, FILTER_VALIDATE_INT);
            $pharmacyNotes = trim((string)($_POST['pharmacy_notes'] ?? ''));
            $pharmacyNotesLength = function_exists('mb_strlen') ? mb_strlen($pharmacyNotes, 'UTF-8') : strlen($pharmacyNotes);
            $expiryDate = trim((string)($_POST['access_valid_until'] ?? ''));
            $expiryDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $expiryDate);
            $expiryDateErrors = DateTimeImmutable::getLastErrors();
            $accessExpiresAt = $expiryDateObject
                && ($expiryDateErrors === false || ($expiryDateErrors['warning_count'] === 0 && $expiryDateErrors['error_count'] === 0))
                && $expiryDateObject->format('Y-m-d') === $expiryDate
                && $expiryDate >= date('Y-m-d')
                ? $expiryDateObject->format('Y-m-d') . ' 23:59:59'
                : null;
            $productStmt = $productId > 0 ? $conn->prepare("SELECT id, product_name, status, box, pieces, pieces_per_box,
                    (SELECT COUNT(*) FROM product_batches pb WHERE pb.product_id = products.id) AS batch_count,
                    (SELECT COALESCE(SUM(CASE WHEN pb.recalled = 0 AND pb.expiration_date >= CURDATE() THEN pb.current_quantity ELSE 0 END), 0)
                     FROM product_batches pb WHERE pb.product_id = products.id) AS sellable_batch_stock
                FROM products WHERE id = ? AND prescription_required = 1 LIMIT 1") : false;
            $product = null;
            if ($productStmt) {
                $productStmt->bind_param('i', $productId);
                if ($productStmt->execute()) {
                    $product = $productStmt->get_result()->fetch_assoc();
                } else {
                    error_log('Prescription approval product lookup failed: ' . $productStmt->error);
                }
                $productStmt->close();
            }
            $prescriptionOwnerStmt = $conn->prepare("SELECT p.user_id, p.status, u.email, COALESCE(u.full_name, u.username) AS customer_name
                FROM prescriptions p JOIN users u ON u.id = p.user_id AND u.role = 'user'
                WHERE p.id = ? LIMIT 1");
            $prescriptionOwner = null;
            if ($prescriptionOwnerStmt) {
                $prescriptionOwnerStmt->bind_param('i', $prescriptionId);
                if ($prescriptionOwnerStmt->execute()) {
                    $prescriptionOwner = $prescriptionOwnerStmt->get_result()->fetch_assoc();
                } else {
                    error_log('Prescription approval customer lookup failed: ' . $prescriptionOwnerStmt->error);
                }
                $prescriptionOwnerStmt->close();
            }
            if (!$product) {
                $error = 'Select a valid prescription-required medicine.';
            } elseif (!$prescriptionOwner) {
                $error = 'The customer account for this prescription is unavailable.';
            } elseif (strtolower(trim((string)$prescriptionOwner['status'])) !== 'pending') {
                $error = in_array(strtolower(trim((string)$prescriptionOwner['status'])), ['approved', 'completed'], true)
                    ? 'This prescription has already been approved.'
                    : 'This prescription has already been reviewed and cannot be approved again.';
            } elseif (strtolower(trim((string)$product['status'])) !== 'active') {
                $error = 'The selected medicine is inactive and cannot be shared.';
            } elseif ($accessExpiresAt === null) {
                $error = 'Choose a valid access date that is today or later.';
            } elseif ($quantityLimit === false || $quantityLimit === null || $quantityLimit < 1) {
                $error = 'Enter a quantity limit greater than zero.';
            } elseif ($pharmacyNotesLength > 2000) {
                $error = 'Pharmacy notes must be 2,000 characters or fewer.';
            } else {
                $piecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
                $availableStock = max(0, ((int)($product['box'] ?? 0) * $piecesPerBox) + (int)($product['pieces'] ?? 0));
                if ((int)($product['batch_count'] ?? 0) > 0) {
                    $availableStock = min($availableStock, max(0, (int)($product['sellable_batch_stock'] ?? 0)));
                }
                if ($availableStock < 1) {
                    $error = 'The selected medicine has no available, unexpired stock.';
                } elseif ($quantityLimit > $availableStock) {
                    $error = 'Quantity limit cannot exceed the available stock of ' . number_format($availableStock) . ' pieces.';
                }
            }
            if (!$error) {
                $customerId = (int)$prescriptionOwner['user_id'];
                $reviewerId = (int)$_SESSION['user_id'];
                if (!$conn->begin_transaction()) {
                    $error = 'Unable to start prescription approval. Please try again.';
                    error_log('Prescription approval transaction failed: ' . $conn->error);
                } else {
                    $accessStmt = $conn->prepare('INSERT INTO prescription_product_access (product_id, user_id, granted_by, prescription_id, expires_at, quantity_limit, quantity_purchased) VALUES (?, ?, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by), prescription_id = VALUES(prescription_id), granted_at = CURRENT_TIMESTAMP, expires_at = VALUES(expires_at), quantity_limit = VALUES(quantity_limit), quantity_purchased = 0');
                    $accessSaved = false;
                    if ($accessStmt) {
                        $accessStmt->bind_param('iiiisi', $productId, $customerId, $reviewerId, $prescriptionId, $accessExpiresAt, $quantityLimit);
                        $accessSaved = $accessStmt->execute();
                        if (!$accessSaved) {
                            error_log('Prescription approval product access update failed: ' . $accessStmt->error);
                        }
                        $accessStmt->close();
                    } else {
                        error_log('Prescription approval product access preparation failed: ' . $conn->error);
                    }
                    $review = $accessSaved ? $conn->prepare("UPDATE prescriptions SET status = 'approved', reviewed_at = NOW(), review_reason = NULL, shared_product_id = ?, product_access_expires_at = ?, pharmacy_notes = ? WHERE id = ? AND user_id = ? AND status = 'pending'") : false;
                    $reviewed = false;
                    if ($review) {
                        $review->bind_param('issii', $productId, $accessExpiresAt, $pharmacyNotes, $prescriptionId, $customerId);
                        $reviewed = $review->execute() && $review->affected_rows > 0;
                        if (!$reviewed) {
                            error_log('Prescription approval status update failed: ' . $review->error);
                        }
                        $review->close();
                    }
                    if (!$accessSaved || !$reviewed) {
                        $conn->rollback();
                        $error = 'Unable to approve and share the medicine for this prescription.';
                    } else {
                        $chatReview = $conn->prepare("UPDATE customer_chat_messages SET prescription_status = 'approved', prescription_reviewed_at = NOW() WHERE prescription_id = ?");
                        if ($chatReview) {
                            $chatReview->bind_param('i', $prescriptionId);
                            if (!$chatReview->execute()) {
                                error_log('Prescription approval chat status update failed: ' . $chatReview->error);
                            }
                            $chatReview->close();
                        }
                        $notificationText = 'Your prescription was approved. ' . $product['product_name']
                            . ' is available up to ' . number_format((int)$quantityLimit) . ' pieces until ' . date('M d, Y', strtotime($accessExpiresAt)) . '.';
                        $noticeStmt = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())');
                        if ($noticeStmt) {
                            $noticeStmt->bind_param('is', $customerId, $notificationText);
                            if (!$noticeStmt->execute()) {
                                error_log('Prescription approval in-app notification failed: ' . $noticeStmt->error);
                            }
                            $noticeStmt->close();
                        } else {
                            error_log('Prescription approval notification preparation failed: ' . $conn->error);
                        }
                        if (!$conn->commit()) {
                            $conn->rollback();
                            $error = 'Prescription approval could not be completed. Please try again.';
                            error_log('Prescription approval transaction commit failed: ' . $conn->error);
                        } else {
                            $message = 'Prescription approved and medicine shared with the customer.';
                            writeAuditLog($conn, 'prescription_approved', 'prescription', $prescriptionId, [
                                'customer_user_id' => $customerId,
                                'product_id' => $productId,
                                'product_name' => (string)$product['product_name'],
                                'quantity_limit' => (int)$quantityLimit,
                                'expires_at' => $accessExpiresAt,
                            ]);
                            $emailResult = sendPrescriptionApprovalEmail(
                                (string)$prescriptionOwner['email'],
                                (string)$prescriptionOwner['customer_name'],
                                $prescriptionId,
                                (string)$product['product_name'],
                                (int)$quantityLimit,
                                $accessExpiresAt,
                                $pharmacyNotes
                            );
                            if (!$emailResult['success']) {
                                $emailWarning = 'The prescription was approved and shared, but the email could not be sent. ' . $emailResult['error'];
                            }
                        }
                    }
                }
            }
        } else {
            $reviewReasonOptions = [
                'Expired Prescription',
                'Unreadable Prescription',
                'Incomplete Information',
                'Incorrect Patient Information',
                'Missing Doctor Information',
                "Missing Doctor's Signature",
                'Medicine Not Clearly Specified',
                'Prescription Already Used',
                'Suspicious or Altered Prescription',
                'Other',
            ];
            $reviewReasonCategory = trim((string)($_POST['review_reason_category'] ?? ''));
            $reviewReasonDetails = trim((string)($_POST['review_reason_details'] ?? ''));
            $reviewReason = in_array($reviewReasonCategory, $reviewReasonOptions, true)
                ? ($reviewReasonCategory === 'Other' ? 'Other: ' . $reviewReasonDetails : $reviewReasonCategory)
                : '';
            if ($reviewReason === ''
                || ($reviewReasonCategory === 'Other' && $reviewReasonDetails === '')
                || strlen($reviewReasonDetails) > 500) {
                $error = 'Select a rejection reason and provide details when choosing Other.';
            } else {
                $review = $conn->prepare("UPDATE prescriptions SET status = 'rejected', reviewed_at = NOW(), review_reason = ? WHERE id = ? AND status = 'pending'");
                if ($review) {
                    $review->bind_param('si', $reviewReason, $prescriptionId);
                    $reviewed = $review->execute() && $review->affected_rows > 0;
                    $review->close();
                    if ($reviewed) {
                        writeAuditLog($conn, 'prescription_rejected', 'prescription', $prescriptionId, [
                            'reason' => $reviewReason,
                        ]);
                        $chatReview = $conn->prepare("UPDATE customer_chat_messages SET prescription_status = 'rejected', prescription_reviewed_at = NOW() WHERE prescription_id = ?");
                        if ($chatReview) {
                            $chatReview->bind_param('i', $prescriptionId);
                            $chatReview->execute();
                            $chatReview->close();
                        }
                        $notice = 'Your prescription was rejected. Reason: ' . $reviewReason;
                        $noticeStmt = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) SELECT user_id, ?, NOW() FROM prescriptions WHERE id = ?');
                        if ($noticeStmt) {
                            $noticeStmt->bind_param('si', $notice, $prescriptionId);
                            $noticeStmt->execute();
                            $noticeStmt->close();
                        }
                        $message = 'Prescription rejected successfully.';
                    } else {
                        $error = 'This prescription has already been reviewed.';
                    }
                } else {
                    $error = 'Unable to update the prescription status.';
                    error_log('Prescription rejection statement preparation failed: ' . $conn->error);
                }
            }
        }
    } else {
        $error = 'Invalid prescription review request.';
    }
}

$filter = $_GET['status'] ?? 'all';
if (!in_array($filter, ['all', 'pending', 'approved', 'rejected', 'completed'], true)) $filter = 'all';
$where = $filter === 'all' ? '' : " WHERE p.status = '" . $conn->real_escape_string($filter) . "'";
$prescriptionsPerPage = 10;
$prescriptionPage = max(1, (int)($_GET['page'] ?? 1));
$prescriptionCount = $conn->query("SELECT COUNT(*) AS total
    FROM prescriptions p
    JOIN users u ON u.id = p.user_id AND u.role = 'user'
    $where");
if ($prescriptionCount === false) {
    error_log('Prescription count query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load prescriptions.');
}
$totalPrescriptions = (int)$prescriptionCount->fetch_assoc()['total'];
$totalPrescriptionPages = max(1, (int)ceil($totalPrescriptions / $prescriptionsPerPage));
$prescriptionPage = min($prescriptionPage, $totalPrescriptionPages);
$prescriptionOffset = ($prescriptionPage - 1) * $prescriptionsPerPage;
$prescriptions = $conn->query("SELECT p.*, u.username, u.email, u.phone, COALESCE(u.full_name, u.username) AS customer_name,
        sp.product_name AS shared_product_name,
        sp.image AS shared_product_image,
        COALESCE(NULLIF(sp.price_piece, 0), sp.price, 0) AS shared_product_price,
        ppa.quantity_limit AS shared_quantity_limit,
        ppa.quantity_purchased AS shared_quantity_purchased,
        GREATEST(COALESCE(ppa.quantity_limit, 0) - COALESCE(ppa.quantity_purchased, 0), 0) AS shared_quantity_remaining
    FROM prescriptions p
    JOIN users u ON u.id = p.user_id AND u.role = 'user'
    LEFT JOIN products sp ON sp.id = COALESCE(NULLIF(p.shared_product_id, 0), (
        SELECT access.product_id FROM prescription_product_access access
        WHERE access.user_id = p.user_id AND access.prescription_id = p.id
        ORDER BY access.granted_at DESC LIMIT 1
    ))
    LEFT JOIN prescription_product_access ppa ON ppa.product_id = sp.id AND ppa.user_id = p.user_id
    $where
    ORDER BY p.submitted_at DESC
    LIMIT $prescriptionsPerPage OFFSET $prescriptionOffset");
if ($prescriptions === false) {
    error_log('Prescription list query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load prescriptions.');
}
$currentPage = basename($_SERVER['PHP_SELF']);

function prescriptionPaginationItems(int $page, int $totalPages): array
{
    if ($totalPages <= 5) {
        return range(1, $totalPages);
    }
    if ($page <= 3) {
        return [1, 2, 3, '...', $totalPages];
    }
    if ($page >= $totalPages - 2) {
        return [1, '...', $totalPages - 2, $totalPages - 1, $totalPages];
    }
    return [1, '...', $page - 1, $page, $page + 1, '...', $totalPages];
}

function prescriptionPageUrl(int $page, string $filter): string
{
    return 'prescriptions.php?' . http_build_query(['status' => $filter, 'page' => $page]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prescription Review</title>
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
<style>
*{box-sizing:border-box}body{margin:0;background:#f7f8fa;color:#20232a;font-family:'Segoe UI', Tahoma, sans-serif}.content{margin-left:244px;padding:28px;min-height:100vh}.header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:18px}.header h1{margin:0 0 5px;font-size:23px}.header p{margin:0;color:#78808a;font-size:12px}.filters{display:flex;gap:8px}.filters a{padding:8px 12px;border:1px solid #e1e5e9;border-radius:6px;color:#555e68;background:#fff;text-decoration:none;font-size:11px}.filters a.active{background:#711329;color:#fff;border-color:#711329}.notice{padding:11px 13px;border-radius:7px;margin-bottom:14px;background:#e9f8ef;color:#23784c;font-size:12px}.error{background:#ffe8ed;color:#b4233d}.panel{background:#fff;border:1px solid #e8ebee;border-radius:9px;overflow:hidden}.table-wrap{overflow:auto}.table{width:100%;min-width:900px;border-collapse:collapse}.table th,.table td{padding:12px 14px;border-bottom:1px solid #f0f1f3;text-align:left;font-size:11px;vertical-align:top}.table th{background:#fbfcfd;color:#68707a;font-size:10px}.file-link{color:#711329;font-weight:700;text-decoration:none}.customer{font-weight:700}.muted{display:block;color:#8a919a;margin-top:3px}.status{display:inline-block;padding:4px 8px;border-radius:12px;font-size:10px;font-weight:700;text-transform:capitalize}.status.pending{background:#fff3d6;color:#986b09}.status.approved{background:#e7f7ed;color:#247849}.status.rejected{background:#ffe8ed;color:#b4233d}.status.completed{background:#edf0f4;color:#667085}.actions{display:flex;gap:6px}.actions button{border:0;border-radius:5px;padding:7px 9px;color:#fff;font-size:10px;font-weight:700;cursor:pointer}.approve{background:#258251}.reject{background:#bd304d}.empty{padding:30px;text-align:center;color:#818892;font-size:12px}@media(max-width:760px){.content{margin-left:0;padding:18px}.header{display:block}.filters{margin-top:14px;flex-wrap:wrap}}
.prescription-pagination{display:flex;align-items:center;justify-content:center;gap:8px;padding:14px 16px;border-top:1px solid #f0f1f3}
.prescription-pagination a{display:inline-flex;min-width:44px;height:44px;align-items:center;justify-content:center;padding:0 10px;border:1px solid #d9e0e8;border-radius:10px;background:#fff;color:#536174;text-decoration:none;font-size:14px;font-weight:600}
.prescription-pagination a:hover{border-color:#ed1b3b;color:#ed1b3b}
.prescription-pagination a.active{border-color:#ed1b3b;background:#ed1b3b;color:#fff;box-shadow:0 4px 10px rgba(237,27,59,.18)}
.prescription-pagination a:focus-visible{outline:2px solid #711329;outline-offset:2px}
.prescription-pagination .disabled{display:inline-flex;min-width:44px;height:44px;align-items:center;justify-content:center;padding:0 10px;border:1px solid #d9e0e8;border-radius:10px;color:#9aa3af}
</style>
<style>
.table{width:calc(100% - 32px);min-width:0;margin:12px 16px;border-collapse:collapse;table-layout:fixed}
.table thead{background:#e9ecef}
.table th{background:transparent;color:#495057;font-size:13px;font-weight:800;vertical-align:middle;padding:14px 12px}
.table th:nth-child(1){width:14%}.table th:nth-child(2){width:17%}.table th:nth-child(3),.table th:nth-child(4){width:17%}.table th:nth-child(5){width:16%}.table th:nth-child(6){width:11%}.table th:nth-child(7){width:8%}
.table td{min-width:0;font-size:13px;vertical-align:middle;overflow-wrap:anywhere}
.file-link{display:block;overflow-wrap:anywhere;word-break:break-word}
.actions{white-space:nowrap}
.table tbody tr:last-child td{border-bottom:0}
.prescription-id{font-weight:800;white-space:nowrap}
.table tr.new-prescription td{background:#fff2f4;font-weight:700}
.table tr.new-prescription td:first-child{border-left:3px solid #ed1b3b}
.new-prescription-badge{display:inline-flex;align-items:center;margin-left:6px;padding:3px 7px;border-radius:999px;background:#dcf5e7;color:#168246;font-size:10px;line-height:1;font-weight:800;vertical-align:middle}
.actions { position:relative; z-index:2; align-items:center; }
.chat-button, .view-button { display:inline-flex; width:32px; height:32px; align-items:center; justify-content:center; border:1px solid #e1e5e9; border-radius:6px; background:#fff; color:#53657a; text-decoration:none; cursor:pointer; }
.chat-button .material-icons-outlined, .view-button .material-icons-outlined { font-size:18px; }
.actions .view-button { position:relative; z-index:3; width:34px; min-width:34px; height:34px; padding:0; border:0; border-radius:50%; background:transparent; color:#711329; cursor:pointer; pointer-events:auto; }
.actions .view-button .material-icons-outlined { color:#711329; font-size:21px; }
.chat-button:hover, .actions .view-button:hover { border-color:transparent; background:#eef2f6; color:#304966; }
.actions .view-button:hover .material-icons-outlined { color:#304966; }
.view-button:focus-visible { outline:2px solid #53657a; outline-offset:2px; }
.prescription-modal { display:none; position:fixed; inset:0; z-index:1200; align-items:center; justify-content:center; padding:20px; background:rgba(20,25,33,.55); }
.prescription-modal.open { display:flex; }
.prescription-dialog { position:relative; display:flex; width:min(820px,100%); height:calc(100vh - 40px); max-height:calc(100vh - 40px); flex-direction:column; overflow:hidden; border:1px solid #e6ebf1; border-radius:10px; background:#fff; box-shadow:0 20px 60px rgba(15,23,42,.25); }
.prescription-modal-header { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:15px 18px; border-bottom:1px solid #edf0f3; }
.prescription-modal-header h2 { margin:0; color:#273b56; font-size:18px; }
.prescription-modal-header p { margin:3px 0 0; color:#778393; font-size:12px; }
.prescription-modal-close { width:34px; height:34px; border:0; border-radius:6px; background:transparent; color:#778393; font-size:23px; cursor:pointer; }
.prescription-modal-close:hover { background:#f2f4f7; }
.prescription-preview-frame { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; min-height:180px; flex:1 1 auto; overflow:auto; padding:16px; background:#f4f6f8; }
.prescription-document { min-width:0; padding:10px; border:1px solid #e3e9f0; border-radius:8px; background:#fff; }
.prescription-document h3 { margin:0 0 8px; color:#394e69; font-size:12px; }
.prescription-document img { display:block; width:100%; max-height:calc(100vh - 250px); object-fit:contain; border-radius:5px; }
.prescription-no-file { display:flex; min-height:160px; align-items:center; justify-content:center; color:#8793a3; font-size:12px; text-align:center; }
.prescription-review-reason { display:none; padding:10px 12px; border:1px solid #f1c7cf; border-radius:7px; background:#fff4f5; color:#883646; font-size:12px; line-height:1.45; }
.prescription-review-reason.visible { display:block; }
.prescription-review-actions { display:flex; align-items:flex-end; justify-content:flex-end; gap:8px; padding:12px 18px; border-top:1px solid #edf0f3; }
.prescription-review-actions form { margin:0; }
.prescription-review-actions .reject-form { display:flex; flex:1 1 auto; align-items:flex-end; gap:8px; }
.reject-reason-field { display:grid; flex:1 1 auto; gap:4px; color:#626f80; font-size:10px; font-weight:700; }
.reject-reason-field textarea { width:100%; min-height:36px; max-height:80px; resize:vertical; padding:8px; border:1px solid #dfe4e9; border-radius:6px; font-family:'Segoe UI', Tahoma, sans-serif; font-size:12px; }
.prescription-review-actions button { width:auto; min-width:92px; height:36px; padding:0 12px; border:0; border-radius:6px; color:#fff; font-size:12px; font-weight:700; cursor:pointer; }
.prescription-review-actions .approve { background:#258251; }
.prescription-review-actions .reject { background:#bd304d; }
.prescription-reviewed-label { align-self:center; margin-right:auto; color:#778393; font-size:12px; }
@media(max-width:600px) { .prescription-modal { padding:10px; } .prescription-dialog { max-height:calc(100vh - 20px); } .prescription-modal-header, .prescription-review-actions { padding:12px; } .prescription-preview-frame { grid-template-columns:1fr; } .prescription-review-actions { flex-wrap:wrap; } .prescription-review-actions .reject-form { flex-wrap:wrap; } }
.prescription-dialog { position:relative; width:min(1280px,calc(100vw - 40px)); max-width:calc(100vw - 40px); min-width:0; min-height:0; }
.prescription-modal-header { padding:16px 20px; }
.prescription-modal-body { display:grid; width:100%; min-width:0; flex:1 1 0; grid-template-columns:minmax(0,1.25fr) minmax(0,.85fr); gap:16px; min-height:0; overflow:hidden; padding:16px; }
.prescription-preview-column { display:flex; min-width:0; flex-direction:column; overflow:hidden; border:1px solid #e3e9f0; border-radius:8px; background:#fff; }
.prescription-document-tabs { display:flex; gap:6px; padding:8px; border-bottom:1px solid #edf0f3; background:#f7f9fc; }
.prescription-document-tabs button { padding:7px 10px; border:1px solid transparent; border-radius:5px; background:transparent; color:#687b94; font-size:11px; font-weight:700; cursor:pointer; }
.prescription-document-tabs button.active { border-color:#d9e5f3; background:#fff; color:#304966; }
.prescription-document-tabs button:disabled { opacity:.45; cursor:not-allowed; }
.prescription-main-preview { position:relative; display:flex; min-width:0; min-height:0; flex:1 1 auto; align-items:center; justify-content:center; overflow:hidden; padding:12px; background:#f4f6f8; }
.prescription-main-preview img { display:block; width:100%; height:100%; max-width:100%; max-height:100%; object-fit:contain; border-radius:5px; background:#fff; transform:scale(1); transform-origin:center; transition:transform .16s ease; }
.prescription-document-arrow { position:absolute; z-index:1; top:50%; display:flex; width:42px; height:42px; align-items:center; justify-content:center; transform:translateY(-50%); border:0; border-radius:50%; background:#fff; color:#304966; box-shadow:0 2px 8px rgba(25,40,60,.18); cursor:pointer; }
.prescription-document-arrow:hover:not(:disabled) { background:#f7f9fc; color:#711329; }
.prescription-document-arrow:disabled { opacity:.35; cursor:not-allowed; }
.prescription-document-arrow .material-icons-outlined { font-size:24px; }
.prescription-document-arrow.previous { left:18px; }
.prescription-document-arrow.next { right:18px; }
.prescription-preview-toolbar { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px; border-top:1px solid #edf0f3; background:#fff; }
.prescription-zoom-controls { display:flex; align-items:center; gap:6px; }
.prescription-zoom-controls button,.prescription-preview-download { display:inline-flex; min-width:40px; height:38px; align-items:center; justify-content:center; gap:6px; padding:0 10px; border:1px solid #e1e6ec; border-radius:6px; background:#fff; color:#5b6f88; font:inherit; font-size:12px; text-decoration:none; cursor:pointer; }
.prescription-zoom-controls button:hover:not(:disabled),.prescription-preview-download:hover { border-color:#711329; color:#711329; }
.prescription-zoom-controls button:disabled { opacity:.4; cursor:not-allowed; }
.prescription-zoom-controls .material-icons-outlined,.prescription-preview-download .material-icons-outlined { font-size:19px; }
.prescription-preview-download { min-width:110px; font-weight:700; }
.prescription-review-details { display:flex; min-width:0; min-height:0; height:100%; flex-direction:column; gap:10px; overflow-y:auto; }
.prescription-modal-action-buttons { display:flex; flex-wrap:wrap; gap:8px; }
.prescription-modal-action-buttons[hidden] { display:none; }
.prescription-modal-action-buttons button { display:inline-flex; min-height:38px; align-items:center; justify-content:center; gap:6px; padding:0 12px; border:0; border-radius:6px; color:#fff; font-size:12px; font-weight:700; cursor:pointer; }
.prescription-modal-action-buttons button .material-icons-outlined { font-size:18px; }
.prescription-actions-card { position:absolute; z-index:10; right:24px; bottom:20px; display:flex; width:max-content; max-width:calc(100% - 32px); align-items:center; justify-content:space-between; gap:12px; padding:0; border:0; border-radius:0; background:transparent; box-shadow:none; pointer-events:auto; }
.prescription-actions-card { display:none; }
.prescription-actions-card.is-visible { display:flex!important; }
.prescription-modal-action-buttons[hidden] { display:none!important; }
.prescription-actions-card .prescription-modal-action-buttons { width:100%; justify-content:flex-end; }
.prescription-actions-card .prescription-modal-action-buttons .approve { background:#258251; }
.prescription-actions-card .prescription-modal-action-buttons .reject { background:#bd304d; }
.prescription-actions-card .prescription-reviewed-label { margin:0; }
.prescription-review-details > #prescriptionApprovalForm,.prescription-review-details > #prescriptionReasonForm { flex:0 0 auto; margin:0; }
.prescription-review-details > #prescriptionApprovalForm:not([hidden]),.prescription-review-details > #prescriptionReasonForm:not([hidden]) { display:grid; gap:10px; }
.prescription-info-card { padding:12px; border:1px solid #e3eaf2; border-radius:8px; background:#fff; }
.prescription-info-heading { display:flex; align-items:center; gap:8px; margin-bottom:9px; color:#304966; font-size:12px; font-weight:800; }
.prescription-info-heading i { width:16px; text-align:center; }
.prescription-info-card strong { display:block; color:#304966; font-size:12px; }
.prescription-info-card p { margin:4px 0 0; color:#718198; font-size:11px; overflow-wrap:anywhere; }
.prescription-info-row { display:grid; grid-template-columns:95px minmax(0,1fr); gap:8px; margin-top:6px; color:#718198; font-size:10px; }
.prescription-info-row strong { overflow-wrap:anywhere; font-size:10px; }
.prescription-info-status { display:inline-flex!important; width:max-content; margin:0!important; padding:4px 8px; border-radius:999px; background:#fff3d6; color:#986b09!important; font-size:10px!important; }
.prescription-info-status.approved { background:#e7f7ed; color:#247849!important; }
.prescription-info-status.rejected { background:#ffe8ed; color:#b4233d!important; }
.prescription-shared-product[hidden] { display:none; }
.prescription-shared-product { display:grid; grid-template-columns:76px minmax(0,1fr); gap:12px; margin-top:10px; padding:11px; border:1px solid #e3eaf2; border-radius:8px; background:#fff; }
.prescription-shared-product-image { position:relative; display:grid; min-height:76px; place-items:center; overflow:hidden; border-radius:7px; background:#f1f5f9; color:#8392a6; }
.prescription-shared-product-image .material-icons-outlined { font-size:32px; }
.prescription-shared-product-image img { position:absolute; width:100%; height:100%; object-fit:contain; background:#fff; }
.prescription-shared-product-details { display:grid; min-width:0; gap:5px; align-content:center; }
.prescription-shared-product-name { color:#304966; font-size:13px; font-weight:800; overflow-wrap:anywhere; }
.prescription-shared-product-price { color:#168352; font-size:12px; font-weight:700; }
.prescription-shared-product-quantities { display:flex; flex-wrap:wrap; gap:5px 12px; color:#718198; font-size:10px; }
.prescription-shared-product-quantities strong { display:inline; font-size:10px; }
.prescription-review-details { gap:12px; }
.prescription-info-card { min-height:170px; padding:22px; }
.prescription-review-details > .prescription-info-card:nth-of-type(2) { min-height:220px; }
.prescription-info-heading { gap:10px; margin-bottom:12px; font-size:16px; }
.prescription-info-heading i { width:20px; font-size:19px; }
.prescription-info-card > strong { font-size:15px; }
.prescription-info-card p { font-size:14px; line-height:1.45; }
.prescription-info-card p .material-icons-outlined { font-size:17px; vertical-align:middle; }
.prescription-info-row { grid-template-columns:115px minmax(0,1fr); gap:10px; margin-top:8px; font-size:13px; line-height:1.4; }
.prescription-info-row strong { font-size:13px; }
.prescription-info-status { font-size:12px!important; }
.prescription-shared-product { min-height:250px; grid-template-columns:180px minmax(0,1fr); gap:22px; margin-top:0; padding:22px; }
.prescription-shared-product-image { min-height:180px; }
.prescription-shared-product-name { font-size:16px; }
.prescription-shared-product-price { font-size:15px; }
.prescription-shared-product-quantities { gap:7px 14px; font-size:12px; }
.prescription-shared-product-quantities strong { font-size:12px; }
.prescription-review-details .prescription-review-reason { margin:8px 0 0; }
.prescription-actions-card { margin:0; }
.prescription-modal-action-buttons { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
.prescription-modal-action-buttons[hidden] { display:none; }
.prescription-modal-action-buttons form { min-width:0; }
.prescription-modal-action-buttons button { display:inline-flex; width:100%; min-height:48px; align-items:center; justify-content:center; gap:8px; padding:8px 10px; border:1px solid transparent; border-radius:6px; font-size:13px; line-height:1.25; font-weight:700; text-align:center; cursor:pointer; }
.prescription-modal-action-buttons button i { display:inline-flex; flex:0 0 auto; align-items:center; justify-content:center; font-size:20px; line-height:1; }
.prescription-modal-action-buttons .approve { background:#711329; color:#fff; }
.prescription-modal-action-buttons .reject { border-color:#e9a0ad; background:#fff; color:#bd304d; }
.prescription-modal-action-buttons .resubmit { grid-column:1/-1; border-color:#dfe5ec; background:#f5f7fa; color:#53657a; }
@media(max-width:600px) { .prescription-actions-card { right:10px; bottom:10px; max-width:calc(100% - 20px); padding:0; } .prescription-modal-action-buttons { grid-template-columns:1fr 1fr; gap:6px; } .prescription-modal-action-buttons button { min-height:42px; padding:7px; font-size:11px; } }
.reject-reason-field { display:none; gap:6px; margin-top:10px; color:#626f80; font-size:12px; font-weight:700; }
.reject-reason-field.visible { display:grid; }
.reject-reason-field select,.reject-reason-field input,.reject-reason-field textarea { width:100%; min-height:40px; padding:8px 10px; border:1px solid #dfe4e9; border-radius:6px; background:#fff; color:#344a66; font-family:'Segoe UI',Tahoma,sans-serif; font-size:13px; }
.reject-reason-field textarea { resize:vertical; }
.approval-product-search { position:relative; display:grid; gap:5px; color:#626f80; font-size:12px; font-weight:700; }
.approval-product-search-input { width:100%; min-height:40px; padding:8px 10px; border:1px solid #dfe4e9; border-radius:6px; background:#fff; color:#344a66; font-family:'Segoe UI',Tahoma,sans-serif; font-size:13px; }
.approval-product-search-input:focus { outline:2px solid rgba(113,19,41,.16); border-color:#711329; }
.approval-product-results { position:absolute; z-index:5; top:100%; right:0; left:0; max-height:190px; overflow:auto; border:1px solid #dfe4e9; border-radius:6px; background:#fff; box-shadow:0 8px 18px rgba(25,40,60,.14); }
.approval-product-results[hidden] { display:none; }
.approval-product-results:not([hidden]) { display:block; }
.approval-product-result[hidden] { display:none; }
.approval-product-result { display:flex; width:100%; flex-direction:column; gap:3px; padding:9px 10px; border:0; border-bottom:1px solid #edf0f3; background:#fff; color:#344a66; font:inherit; font-size:12px; text-align:left; cursor:pointer; }
.approval-product-result:last-child { border-bottom:0; }
.approval-product-result:hover,.approval-product-result:focus { outline:0; background:#f7f9fc; }
.approval-product-result small { color:#718198; font-size:11px; font-weight:400; }
.approval-product-empty { padding:10px; color:#718198; font-size:12px; font-weight:400; }
.prescription-reason-submit { display:none; gap:7px; justify-content:flex-end; margin-top:8px; }
.prescription-reason-submit.visible { display:flex; }
.prescription-reason-submit button { min-height:34px; padding:0 12px; border:0; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; }
.prescription-reason-submit .confirm-reason { background:#bd304d; color:#fff; }
.prescription-reason-submit .cancel-reason { background:#f0f3f7; color:#5d7089; }
.approval-fields { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.approval-fields .reject-reason-field { display:grid; }
.prescription-approval-submit .confirm-reason { background:#258251; color:#fff; }
.prescription-review-actions[hidden] { display:none; }
.header h1 { color:#252a30; font-family:'Segoe UI', Tahoma, sans-serif; font-size:30px; font-weight:800; }
.prescription-modal-header .status { margin-right:6px; vertical-align:middle; }
.prescription-modal-action-buttons .approve { background:#258251; color:#fff; }
.prescription-modal-action-buttons .reject { border-color:#bd304d; background:#bd304d; color:#fff; }
@media(max-width:760px) { .prescription-modal-body { grid-template-columns:1fr; gap:10px; padding:12px; overflow:auto; } .prescription-main-preview { min-height:220px; } .prescription-main-preview img { max-height:52vh; } .approval-fields { grid-template-columns:1fr; } }
</style>
</head>
<body>
<?php include 'sidebar_owner.php'; ?>
<main class="content">
    <header class="header"><div><h1>Prescription Review</h1><p>Review prescriptions submitted by customers.</p></div><nav class="filters" aria-label="Prescription status filters">
        <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'completed' => 'Completed'] as $value => $label): ?><a class="<?= $filter === $value ? 'active' : '' ?>" href="?status=<?= $value ?>"><?= $label ?></a><?php endforeach; ?>
    </nav></header>
    <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($emailWarning): ?><div class="notice error"><?= htmlspecialchars($emailWarning) ?></div><?php endif; ?>
    <section class="panel"><div class="table-wrap"><table class="table"><thead><tr><th>Prescription #</th><th>Name</th><th>Prescription</th><th>Valid ID</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead><tbody>
    <?php if ($prescriptions && $prescriptions->num_rows): while ($prescription = $prescriptions->fetch_assoc()): ?>
        <?php
        $isPendingReview = strtolower(trim((string)$prescription['status'])) === 'pending';
        $isNewPrescription = $isPendingReview
            && !empty($prescription['submitted_at'])
            && date('Y-m-d', strtotime($prescription['submitted_at'])) === date('Y-m-d');
        $fileUrl = '../user/prescription_file.php?id=' . (int)$prescription['id'];
        $validIdUrl = !empty($prescription['valid_id_file_name']) ? '../user/prescription_file.php?id=' . (int)$prescription['id'] . '&type=valid_id' : '';
        $sharedProductImage = ltrim(str_replace('\\', '/', trim((string)($prescription['shared_product_image'] ?? ''))), '/');
        $sharedProductImageUrl = preg_match('/^https?:\/\//i', $sharedProductImage)
            ? $sharedProductImage
            : ($sharedProductImage !== ''
                ? '../user/uploads/' . preg_replace('#^(?:\.\./|uploads/)+#i', '', $sharedProductImage)
                : '');
        ?>
        <tr class="<?= $isNewPrescription ? 'new-prescription' : '' ?>">
            <td><span class="prescription-id">#P-<?= str_pad((string)$prescription['id'], 4, '0', STR_PAD_LEFT) ?></span><?php if ($isNewPrescription): ?><span class="new-prescription-badge">NEW</span><?php endif; ?></td>
            <td><span class="customer"><?= htmlspecialchars($prescription['customer_name']) ?></span><span class="muted"><?= htmlspecialchars($prescription['email']) ?></span></td>
            <td><a class="file-link" href="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($prescription['original_name']) ?></a></td>
            <td><?php if ($validIdUrl): ?><a class="file-link" href="<?= htmlspecialchars($validIdUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($prescription['valid_id_original_name']) ?></a><?php else: ?><span class="muted">No Valid ID</span><?php endif; ?></td>
            <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($prescription['submitted_at']))) ?></td>
            <td><span class="status <?= htmlspecialchars($prescription['status']) ?>"><?= htmlspecialchars($prescription['status']) ?></span></td>
            <td><div class="actions"><button type="button" class="view-button prescription-preview-trigger" data-file-url="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" data-valid-id-url="<?= htmlspecialchars($validIdUrl, ENT_QUOTES, 'UTF-8') ?>" data-customer="<?= htmlspecialchars($prescription['customer_name'], ENT_QUOTES, 'UTF-8') ?>" data-email="<?= htmlspecialchars($prescription['email'], ENT_QUOTES, 'UTF-8') ?>" data-phone="<?= htmlspecialchars($prescription['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-filename="<?= htmlspecialchars($prescription['original_name'], ENT_QUOTES, 'UTF-8') ?>" data-valid-id-filename="<?= htmlspecialchars($prescription['valid_id_original_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-submitted="<?= htmlspecialchars(date('M d, Y h:i A', strtotime($prescription['submitted_at'])), ENT_QUOTES, 'UTF-8') ?>" data-notes="<?= htmlspecialchars($prescription['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-id="<?= (int)$prescription['id'] ?>" data-status="<?= htmlspecialchars($prescription['status'], ENT_QUOTES, 'UTF-8') ?>" data-can-review="<?= $isPendingReview ? 'true' : 'false' ?>" data-reason="<?= htmlspecialchars($prescription['review_reason'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-product-name="<?= htmlspecialchars($prescription['shared_product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-product-image="<?= htmlspecialchars($sharedProductImageUrl, ENT_QUOTES, 'UTF-8') ?>" data-product-price="<?= number_format((float)($prescription['shared_product_price'] ?? 0), 2, '.', '') ?>" data-quantity-limit="<?= (int)($prescription['shared_quantity_limit'] ?? 0) ?>" data-quantity-purchased="<?= (int)($prescription['shared_quantity_purchased'] ?? 0) ?>" data-quantity-remaining="<?= (int)($prescription['shared_quantity_remaining'] ?? 0) ?>" title="<?= $isPendingReview ? 'Review prescription' : 'View prescription details' ?>" aria-label="<?= $isPendingReview ? 'Review prescription from ' : 'View prescription details from ' ?><?= htmlspecialchars($prescription['customer_name'], ENT_QUOTES, 'UTF-8') ?>"><span class="material-icons-outlined" aria-hidden="true"><?= $isPendingReview ? 'edit' : 'visibility' ?></span></button></div></td>
        </tr>
    <?php endwhile; else: ?><tr><td colspan="7" class="empty">No prescriptions found.</td></tr><?php endif; ?>
    </tbody></table></div>
    </section>
    <?php if ($totalPrescriptions > $prescriptionsPerPage): ?>
    <nav class="prescription-pagination" aria-label="Prescription pages">
        <?php if ($prescriptionPage > 1): ?><a href="<?= htmlspecialchars(prescriptionPageUrl($prescriptionPage - 1, $filter), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">&lsaquo;</a><?php else: ?><span class="disabled" aria-hidden="true">&lsaquo;</span><?php endif; ?>
        <?php foreach (prescriptionPaginationItems($prescriptionPage, $totalPrescriptionPages) as $pageItem): ?>
            <?php if ($pageItem === '...'): ?><span aria-hidden="true">...</span>
            <?php else: ?><a class="<?= $pageItem === $prescriptionPage ? 'active' : '' ?>" href="<?= htmlspecialchars(prescriptionPageUrl((int)$pageItem, $filter), ENT_QUOTES, 'UTF-8') ?>" <?= $pageItem === $prescriptionPage ? 'aria-current="page"' : '' ?>><?= (int)$pageItem ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($prescriptionPage < $totalPrescriptionPages): ?><a href="<?= htmlspecialchars(prescriptionPageUrl($prescriptionPage + 1, $filter), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">&rsaquo;</a><?php else: ?><span class="disabled" aria-hidden="true">&rsaquo;</span><?php endif; ?>
    </nav>
    <?php endif; ?>
</main>
<div class="prescription-modal" id="prescriptionModal" role="dialog" aria-modal="true" aria-labelledby="prescriptionModalTitle" aria-hidden="true">
    <div class="prescription-dialog">
        <header class="prescription-modal-header">
            <div><h2 id="prescriptionModalTitle">Prescription #P-0000</h2><p><span class="status prescription-info-status pending" id="prescriptionModalStatus">Pending Review</span><span id="prescriptionSubmittedAtHeader"></span></p></div>
            <button type="button" class="prescription-modal-close" id="closePrescriptionModal" aria-label="Close prescription preview">&times;</button>
        </header>
        <div class="prescription-modal-body">
            <section class="prescription-preview-column">
                <nav class="prescription-document-tabs" aria-label="Prescription documents">
                    <button type="button" class="active" data-document-tab="prescription">Prescription</button>
                    <button type="button" id="validIdTab" data-document-tab="valid-id">Valid ID</button>
                </nav>
                <div class="prescription-main-preview">
                    <button type="button" class="prescription-document-arrow previous" id="previousDocument" aria-label="Previous document"><span class="material-icons-outlined" aria-hidden="true">chevron_left</span></button>
                    <img id="activeDocumentPreview" src="" alt="Uploaded prescription">
                    <button type="button" class="prescription-document-arrow next" id="nextDocument" aria-label="Next document"><span class="material-icons-outlined" aria-hidden="true">chevron_right</span></button>
                    <div class="prescription-no-file" id="activeDocumentFallback" hidden>No document available.</div>
                </div>
                <div class="prescription-preview-toolbar">
                    <div class="prescription-zoom-controls" aria-label="Image zoom controls">
                        <button type="button" id="zoomOut" title="Zoom out" aria-label="Zoom out"><span class="material-icons-outlined" aria-hidden="true">remove</span></button>
                        <button type="button" id="zoomIn" title="Zoom in" aria-label="Zoom in"><span class="material-icons-outlined" aria-hidden="true">add</span></button>
                        <button type="button" id="zoomFit" title="Fit image" aria-label="Fit image"><span class="material-icons-outlined" aria-hidden="true">fit_screen</span> Fit</button>
                    </div>
                    <a class="prescription-preview-download" id="downloadPrescription" href="#" download><span class="material-icons-outlined" aria-hidden="true">download</span> Download</a>
                </div>
            </section>
            <div class="prescription-review-details">
                <section class="prescription-info-card">
                    <div class="prescription-info-heading"><i class="material-icons-outlined" aria-hidden="true">person</i>Customer Information</div>
                    <strong id="prescriptionCustomerName"></strong><p><i class="material-icons-outlined" aria-hidden="true">mail</i> <span id="prescriptionCustomerEmail"></span></p><p><i class="material-icons-outlined" aria-hidden="true">call</i> <span id="prescriptionCustomerPhone"></span></p>
                </section>
                <section class="prescription-info-card">
                    <div class="prescription-info-heading"><i class="material-icons-outlined" aria-hidden="true">event_note</i>Submission Details</div>
                    <div class="prescription-info-row"><span>Prescription ID</span><strong id="prescriptionIdLabel"></strong></div>
                    <div class="prescription-info-row"><span>Prescription</span><strong id="prescriptionFileName"></strong></div>
                    <div class="prescription-info-row"><span>Valid ID</span><strong id="validIdFileName"></strong></div>
                    <div class="prescription-info-row" id="prescriptionNotesRow" hidden><span>Notes</span><strong id="prescriptionNotes"></strong></div>
                    <div class="prescription-review-reason" id="prescriptionReviewReason"></div>
                </section>
                <section class="prescription-shared-product" id="prescriptionSharedProduct" aria-label="Shared prescription medicine" hidden>
                    <div class="prescription-shared-product-image"><span class="material-icons-outlined" aria-hidden="true">medication</span><img id="sharedProductImage" alt="" hidden></div>
                    <div class="prescription-shared-product-details">
                        <div class="prescription-shared-product-name" id="sharedProductName"></div>
                        <div class="prescription-shared-product-price" id="sharedProductPrice"></div>
                        <div class="prescription-shared-product-quantities">
                            <span>Limit: <strong id="sharedQuantityLimit"></strong></span>
                            <span>Purchased: <strong id="sharedQuantityPurchased"></strong></span>
                            <span>Remaining: <strong id="sharedQuantityRemaining"></strong></span>
                        </div>
                    </div>
                </section>
                <form method="post" id="prescriptionApprovalForm" hidden>
                    <input type="hidden" name="action" value="review"><input type="hidden" name="prescription_id" id="approvePrescriptionId"><input type="hidden" name="status" value="approved">
                    <input type="hidden" name="product_id" id="approvalProductId">
                    <div class="approval-product-search">
                        <label for="approvalProductSearch">Prescription Medicine</label>
                        <input class="approval-product-search-input" id="approvalProductSearch" type="search" placeholder="Search prescription medicines..." autocomplete="off" aria-autocomplete="list" aria-controls="approvalProductResults" aria-expanded="false" required>
                        <div class="approval-product-results" id="approvalProductResults" role="listbox" hidden>
                            <?php foreach ($approvalProducts as $approvalProduct):
                                $productSelectable = strtolower((string)$approvalProduct['status']) === 'active' && $approvalProduct['available_stock'] > 0;
                                if (!$productSelectable) continue;
                            ?>
                                <button type="button" class="approval-product-result" role="option" data-product-id="<?= (int)$approvalProduct['id'] ?>" data-product-name="<?= htmlspecialchars($approvalProduct['product_name'], ENT_QUOTES, 'UTF-8') ?>" data-stock="<?= (int)$approvalProduct['available_stock'] ?>">
                                    <span><?= htmlspecialchars($approvalProduct['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <small><?= number_format((int)$approvalProduct['available_stock']) ?> available</small>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="approval-product-empty" id="approvalProductEmpty" role="status" hidden>No prescription medicines found.</div>
                    </div>
                    <div class="approval-fields">
                        <label class="reject-reason-field" for="approvalQuantityLimit">Quantity Limit
                            <input id="approvalQuantityLimit" name="quantity_limit" type="number" min="1" required>
                        </label>
                        <label class="reject-reason-field" for="approvalExpiryDate">Valid Until
                            <input id="approvalExpiryDate" name="access_valid_until" type="date" min="<?= date('Y-m-d') ?>" required>
                        </label>
                    </div>
                    <label class="reject-reason-field visible" for="approvalPharmacyNotes">Notes for Customer
                        <textarea id="approvalPharmacyNotes" name="pharmacy_notes" maxlength="2000" rows="3" placeholder="Optional instructions or notes for the customer"></textarea>
                    </label>
                    <div class="prescription-reason-submit prescription-approval-submit visible"><button type="submit" class="confirm-reason">Approve &amp; Notify Customer</button><button type="button" class="cancel-reason" id="cancelApprovalButton">Cancel</button></div>
                </form>
                <form method="post" id="prescriptionReasonForm" hidden>
                    <input type="hidden" name="action" value="review"><input type="hidden" name="prescription_id" id="rejectPrescriptionId"><input type="hidden" name="status" value="rejected">
                    <label class="reject-reason-field visible" for="reviewReasonSelect">Reason for Rejection
                        <select id="reviewReasonSelect" name="review_reason_category" required>
                            <option value="">Select a reason</option>
                            <option>Expired Prescription</option>
                            <option>Unreadable Prescription</option>
                            <option>Incomplete Information</option>
                            <option>Incorrect Patient Information</option>
                            <option>Missing Doctor Information</option>
                            <option>Missing Doctor's Signature</option>
                            <option>Medicine Not Clearly Specified</option>
                            <option>Prescription Already Used</option>
                            <option>Suspicious or Altered Prescription</option>
                            <option>Other</option>
                        </select>
                    </label>
                    <label class="reject-reason-field" id="otherReasonField" for="reviewReasonInput" hidden>Specify Other Reason
                        <input id="reviewReasonInput" name="review_reason_details" type="text" maxlength="500" placeholder="Enter the reason">
                    </label>
                    <div class="prescription-reason-submit visible"><button type="submit" class="confirm-reason" id="confirmReasonButton">Confirm Rejection</button><button type="button" class="cancel-reason" id="cancelReasonButton">Cancel</button></div>
                </form>
            </div>
        </div>
        <section class="prescription-actions-card" id="prescriptionActionsCard" aria-label="Prescription review actions">
            <div class="prescription-modal-action-buttons" id="prescriptionModalActions">
                <button type="button" class="approve" id="startApproveReview"><i class="material-icons-outlined" aria-hidden="true">check</i> Approve &amp; Share Product</button>
                <button type="button" class="reject" id="startRejectReview"><i class="material-icons-outlined" aria-hidden="true">close</i> Reject Prescription</button>
            </div>
            <span class="prescription-reviewed-label" id="prescriptionReviewedLabel" hidden>Already reviewed</span>
        </section>
    </div>
</div>
<script>
const prescriptionModal = document.getElementById('prescriptionModal');
const activeDocumentPreview = document.getElementById('activeDocumentPreview');
const activeDocumentFallback = document.getElementById('activeDocumentFallback');
const prescriptionReviewedLabel = document.getElementById('prescriptionReviewedLabel');
const prescriptionActionsCard = document.getElementById('prescriptionActionsCard');
const prescriptionReviewReason = document.getElementById('prescriptionReviewReason');
const prescriptionSharedProduct = document.getElementById('prescriptionSharedProduct');
const sharedProductName = document.getElementById('sharedProductName');
const sharedProductImage = document.getElementById('sharedProductImage');
const sharedProductPrice = document.getElementById('sharedProductPrice');
const sharedQuantityLimit = document.getElementById('sharedQuantityLimit');
const sharedQuantityPurchased = document.getElementById('sharedQuantityPurchased');
const sharedQuantityRemaining = document.getElementById('sharedQuantityRemaining');
const prescriptionModalActions = document.getElementById('prescriptionModalActions');
const prescriptionApprovalForm = document.getElementById('prescriptionApprovalForm');
const approvalProductSearch = document.getElementById('approvalProductSearch');
const approvalProductId = document.getElementById('approvalProductId');
const approvalProductResults = document.getElementById('approvalProductResults');
const approvalProductEmpty = document.getElementById('approvalProductEmpty');
const approvalProductOptions = Array.from(approvalProductResults.querySelectorAll('.approval-product-result'));
const approvalQuantityLimit = document.getElementById('approvalQuantityLimit');
const approvalExpiryDate = document.getElementById('approvalExpiryDate');
const approvalPharmacyNotes = document.getElementById('approvalPharmacyNotes');
const prescriptionReasonForm = document.getElementById('prescriptionReasonForm');
const reviewReasonSelect = document.getElementById('reviewReasonSelect');
const reviewReasonInput = document.getElementById('reviewReasonInput');
const otherReasonField = document.getElementById('otherReasonField');
const confirmReasonButton = document.getElementById('confirmReasonButton');
const documentTabs = Array.from(document.querySelectorAll('[data-document-tab]'));
const previousDocumentButton = document.getElementById('previousDocument');
const nextDocumentButton = document.getElementById('nextDocument');
const zoomOutButton = document.getElementById('zoomOut');
const zoomInButton = document.getElementById('zoomIn');
const zoomFitButton = document.getElementById('zoomFit');
let currentPrescriptionDocuments = { prescription: '', 'valid-id': '' };
let activeDocumentType = 'prescription';
let imageZoom = 1;
const setImageZoom = (zoom) => {
    imageZoom = Math.min(3, Math.max(1, zoom));
    activeDocumentPreview.style.transform = `scale(${imageZoom})`;
    zoomOutButton.disabled = imageZoom <= 1;
    zoomInButton.disabled = imageZoom >= 3;
};
const resetReviewReason = () => {
    reviewReasonSelect.value = '';
    reviewReasonInput.value = '';
    reviewReasonInput.required = false;
    otherReasonField.hidden = true;
    otherReasonField.classList.remove('visible');
};
const updateOtherReasonField = () => {
    const isOther = reviewReasonSelect.value === 'Other';
    otherReasonField.hidden = !isOther;
    otherReasonField.classList.toggle('visible', isOther);
    reviewReasonInput.required = isOther;
    if (!isOther) reviewReasonInput.value = '';
};
const resetApprovalForm = () => {
    approvalProductSearch.value = '';
    approvalProductId.value = '';
    approvalProductId.dataset.stock = '';
    approvalProductSearch.setCustomValidity('');
    approvalProductResults.hidden = true;
    approvalProductSearch.setAttribute('aria-expanded', 'false');
    approvalProductEmpty.hidden = true;
    approvalQuantityLimit.value = '';
    approvalExpiryDate.value = '';
    approvalPharmacyNotes.value = '';
    approvalQuantityLimit.placeholder = '';
    approvalQuantityLimit.removeAttribute('max');
};
const searchApprovalProducts = () => {
    const query = approvalProductSearch.value.trim().toLocaleLowerCase();
    approvalProductId.value = '';
    approvalProductId.dataset.stock = '';
    approvalQuantityLimit.value = '';
    approvalQuantityLimit.placeholder = 'Select a medicine first';
    approvalQuantityLimit.removeAttribute('max');
    approvalProductSearch.setCustomValidity('');
    let matches = 0;
    approvalProductOptions.forEach((option) => {
        const isMatch = query !== '' && option.dataset.productName.toLocaleLowerCase().includes(query);
        option.hidden = !isMatch;
        if (isMatch) matches += 1;
    });
    approvalProductEmpty.hidden = query === '' || matches > 0;
    approvalProductResults.hidden = query === '';
    approvalProductSearch.setAttribute('aria-expanded', query !== '' ? 'true' : 'false');
};
const selectApprovalProduct = (option) => {
    const productName = option.dataset.productName || '';
    const stock = Number(option.dataset.stock || 0);
    approvalProductSearch.value = productName;
    approvalProductId.value = option.dataset.productId || '';
    approvalProductId.dataset.stock = String(stock);
    approvalProductSearch.setCustomValidity('');
    approvalProductResults.hidden = true;
    approvalProductSearch.setAttribute('aria-expanded', 'false');
    approvalQuantityLimit.max = String(stock);
    approvalQuantityLimit.placeholder = `Max ${stock}`;
    approvalQuantityLimit.focus();
};
const updateApprovalQuantityLimit = () => {
    const availableStock = Number(approvalProductId.dataset.stock || 0);
    approvalQuantityLimit.max = availableStock > 0 ? String(availableStock) : '';
    if (availableStock > 0) {
        approvalQuantityLimit.placeholder = `Max ${availableStock}`;
        if (Number(approvalQuantityLimit.value) > availableStock) approvalQuantityLimit.value = String(availableStock);
    } else {
        approvalQuantityLimit.value = '';
        approvalQuantityLimit.placeholder = 'Select a medicine first';
    }
};
const closePrescriptionModal = () => {
    prescriptionModal.classList.remove('open');
    prescriptionModal.setAttribute('aria-hidden', 'true');
    activeDocumentPreview.src = '';
    prescriptionReasonForm.hidden = true;
    resetReviewReason();
    prescriptionApprovalForm.hidden = true;
    resetApprovalForm();
};
const showPrescriptionDocument = (documentType) => {
    const documentUrl = currentPrescriptionDocuments[documentType] || '';
    activeDocumentType = documentType;
    activeDocumentPreview.hidden = !documentUrl;
    activeDocumentFallback.hidden = Boolean(documentUrl);
    activeDocumentFallback.textContent = documentUrl ? '' : 'No valid ID uploaded.';
    activeDocumentPreview.src = documentUrl;
    activeDocumentPreview.alt = documentType === 'valid-id' ? 'Uploaded valid ID' : 'Uploaded prescription';
    setImageZoom(1);
    documentTabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.documentTab === documentType));
    documentTabs.forEach((tab) => { tab.disabled = !currentPrescriptionDocuments[tab.dataset.documentTab]; });
    const canSwitchDocuments = Boolean(currentPrescriptionDocuments.prescription && currentPrescriptionDocuments['valid-id']);
    previousDocumentButton.disabled = !canSwitchDocuments;
    nextDocumentButton.disabled = !canSwitchDocuments;
    const downloadLink = document.getElementById('downloadPrescription');
    downloadLink.href = documentUrl || '#';
    downloadLink.hidden = !documentUrl;
};
const openPrescriptionPreview = (trigger) => {
        currentPrescriptionDocuments = { prescription: trigger.dataset.fileUrl, 'valid-id': trigger.dataset.validIdUrl || '' };
        const prescriptionId = 'P-' + String(trigger.dataset.id).padStart(4, '0');
        document.getElementById('prescriptionModalTitle').textContent = 'Prescription #' + prescriptionId;
        document.getElementById('prescriptionCustomerName').textContent = trigger.dataset.customer;
        document.getElementById('prescriptionCustomerEmail').textContent = trigger.dataset.email || 'No email provided';
        document.getElementById('prescriptionCustomerPhone').textContent = trigger.dataset.phone || 'No phone provided';
        document.getElementById('prescriptionIdLabel').textContent = '#' + prescriptionId;
        document.getElementById('prescriptionSubmittedAtHeader').textContent = ' · Submitted ' + trigger.dataset.submitted;
        document.getElementById('prescriptionFileName').textContent = trigger.dataset.filename;
        document.getElementById('validIdFileName').textContent = trigger.dataset.validIdFilename || 'No valid ID uploaded';
        const notesRow = document.getElementById('prescriptionNotesRow');
        notesRow.hidden = !trigger.dataset.notes;
        document.getElementById('prescriptionNotes').textContent = trigger.dataset.notes || '';
        document.getElementById('approvePrescriptionId').value = trigger.dataset.id;
        document.getElementById('rejectPrescriptionId').value = trigger.dataset.id;
        const status = (trigger.dataset.status || '').trim().toLowerCase();
        const isPending = trigger.dataset.canReview === 'true' || status === 'pending';
        const reason = trigger.dataset.reason || '';
        prescriptionReviewReason.textContent = reason ? 'Rejection reason: ' + reason : '';
        prescriptionReviewReason.classList.toggle('visible', Boolean(reason));
        const productName = trigger.dataset.productName || '';
        const quantityLimit = Number(trigger.dataset.quantityLimit || 0);
        const quantityPurchased = Number(trigger.dataset.quantityPurchased || 0);
        const quantityRemaining = Number(trigger.dataset.quantityRemaining || 0);
        const hasSharedProduct = productName !== '';
        prescriptionSharedProduct.hidden = !hasSharedProduct;
        sharedProductName.textContent = productName;
        sharedProductPrice.textContent = Number(trigger.dataset.productPrice || 0) > 0
            ? `₱${Number(trigger.dataset.productPrice).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} / piece`
            : '';
        sharedProductImage.hidden = true;
        sharedProductImage.removeAttribute('src');
        sharedProductImage.onerror = () => { sharedProductImage.hidden = true; };
        if (trigger.dataset.productImage) {
            sharedProductImage.src = trigger.dataset.productImage;
            sharedProductImage.hidden = false;
        }
        sharedQuantityLimit.textContent = quantityLimit.toLocaleString();
        sharedQuantityPurchased.textContent = quantityPurchased.toLocaleString();
        sharedQuantityRemaining.textContent = quantityRemaining.toLocaleString();
        prescriptionReviewedLabel.hidden = isPending;
        prescriptionActionsCard.classList.toggle('is-visible', isPending);
        prescriptionModalActions.hidden = !isPending;
        prescriptionApprovalForm.hidden = true;
        resetApprovalForm();
        prescriptionReasonForm.hidden = true;
        resetReviewReason();
        const statusBadge = document.getElementById('prescriptionModalStatus');
        statusBadge.textContent = status === 'approved' ? 'Approved' : (status === 'rejected' ? 'Rejected' : (status === 'completed' ? 'Completed' : 'Pending Review'));
        statusBadge.className = 'status prescription-info-status ' + status;
        document.getElementById('validIdTab').disabled = !currentPrescriptionDocuments['valid-id'];
        showPrescriptionDocument('prescription');
        prescriptionModal.classList.add('open');
        prescriptionModal.setAttribute('aria-hidden', 'false');
};
document.querySelectorAll('.prescription-preview-trigger').forEach((trigger) => {
    trigger.addEventListener('click', () => openPrescriptionPreview(trigger));
});
documentTabs.forEach((tab) => tab.addEventListener('click', () => showPrescriptionDocument(tab.dataset.documentTab)));
const moveDocument = (direction) => {
    const documentTypes = ['prescription', 'valid-id'].filter((type) => currentPrescriptionDocuments[type]);
    if (documentTypes.length < 2) return;
    const currentIndex = documentTypes.indexOf(activeDocumentType);
    const nextIndex = (currentIndex + direction + documentTypes.length) % documentTypes.length;
    showPrescriptionDocument(documentTypes[nextIndex]);
};
previousDocumentButton.addEventListener('click', () => moveDocument(-1));
nextDocumentButton.addEventListener('click', () => moveDocument(1));
zoomOutButton.addEventListener('click', () => setImageZoom(imageZoom - 0.25));
zoomInButton.addEventListener('click', () => setImageZoom(imageZoom + 0.25));
zoomFitButton.addEventListener('click', () => setImageZoom(1));
document.getElementById('startApproveReview').addEventListener('click', () => {
    resetReviewReason();
    prescriptionActionsCard.classList.remove('is-visible');
    prescriptionReasonForm.hidden = true;
    resetApprovalForm();
    prescriptionApprovalForm.hidden = false;
    approvalProductSearch.focus();
});
approvalProductSearch.addEventListener('input', searchApprovalProducts);
approvalProductSearch.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' && !approvalProductResults.hidden) {
        const firstVisible = approvalProductOptions.find((option) => !option.hidden);
        if (firstVisible) {
            event.preventDefault();
            firstVisible.focus();
        }
    } else if (event.key === 'Escape') {
        approvalProductResults.hidden = true;
        approvalProductSearch.setAttribute('aria-expanded', 'false');
    }
});
approvalProductOptions.forEach((option) => {
    option.addEventListener('click', () => selectApprovalProduct(option));
    option.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            approvalProductResults.hidden = true;
            approvalProductSearch.setAttribute('aria-expanded', 'false');
            approvalProductSearch.focus();
        }
    });
});
prescriptionApprovalForm.addEventListener('submit', (event) => {
    const hasSelectedProduct = approvalProductId.value !== '';
    approvalProductSearch.setCustomValidity(hasSelectedProduct ? '' : 'Search for and select a prescription medicine from the results.');
    if (!hasSelectedProduct) {
        event.preventDefault();
        approvalProductSearch.reportValidity();
    }
});
document.addEventListener('click', (event) => {
    if (!event.target.closest('.approval-product-search')) {
        approvalProductResults.hidden = true;
        approvalProductSearch.setAttribute('aria-expanded', 'false');
    }
});
document.getElementById('cancelApprovalButton').addEventListener('click', () => {
    prescriptionApprovalForm.hidden = true;
    prescriptionActionsCard.classList.add('is-visible');
    resetApprovalForm();
});
document.getElementById('startRejectReview').addEventListener('click', () => {
    confirmReasonButton.textContent = 'Confirm Rejection';
    prescriptionApprovalForm.hidden = true;
    resetApprovalForm();
    resetReviewReason();
    prescriptionActionsCard.classList.remove('is-visible');
    prescriptionReasonForm.hidden = false;
    reviewReasonSelect.focus();
});
reviewReasonSelect.addEventListener('change', updateOtherReasonField);
document.getElementById('cancelReasonButton').addEventListener('click', () => {
    prescriptionReasonForm.hidden = true;
    prescriptionActionsCard.classList.add('is-visible');
    resetReviewReason();
});
document.getElementById('closePrescriptionModal').addEventListener('click', closePrescriptionModal);
prescriptionModal.addEventListener('click', (event) => { if (event.target === prescriptionModal) closePrescriptionModal(); });
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closePrescriptionModal(); });
activeDocumentPreview.addEventListener('error', () => {
    activeDocumentPreview.hidden = true;
    activeDocumentFallback.hidden = false;
    activeDocumentFallback.textContent = 'Unable to load this document.';
});
</script>
</body>
</html>
