<?php
session_start();
include 'config.php';
include 'access_control.php';

$role = $_SESSION['role'] ?? '';
if ($role === 'pharmacist') {
    requireOwnerPageAccess($conn, true);
} elseif (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'user'], true)) {
    header('Location: login_a.php');
    exit();
}
$isStaff = in_array($role, ['admin', 'pharmacist'], true);

$conn->query("CREATE TABLE IF NOT EXISTS customer_chat_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sender_role VARCHAR(10) NOT NULL DEFAULT 'user',
    message TEXT NULL,
    medicine_name VARCHAR(255) NULL,
    prescription_image VARCHAR(255) NULL,
    prescription_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    prescription_reviewed_at DATETIME NULL,
    prescription_reviewed_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    product_id INT NULL,
    ocr_text TEXT NULL,
    ocr_status VARCHAR(20) NOT NULL DEFAULT 'not_run',
    INDEX idx_chat_user (user_id),
    INDEX idx_chat_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS ocr_text TEXT NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS ocr_status VARCHAR(20) NOT NULL DEFAULT 'not_run'");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS prescription_status VARCHAR(20) NOT NULL DEFAULT 'pending'");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS prescription_reviewed_at DATETIME NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS prescription_reviewed_by INT NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS product_id INT NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS prescription_id INT NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS order_id INT NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS prescription_number VARCHAR(100) NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS prescription_date DATE NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS product_access_expires_at DATETIME NULL");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS shared_product_id INT NULL");
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
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS shared_product_id INT NULL");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS product_access_expires_at DATETIME NULL");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS valid_id_original_name VARCHAR(255) NULL");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS valid_id_file_name VARCHAR(255) NULL");
$conn->query("ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS original_name VARCHAR(255) NULL");
$conn->query("CREATE TABLE IF NOT EXISTS prescription_chat_open_requests (
    prescription_id INT NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_at DATETIME NULL,
    KEY idx_prescription_chat_open_user (user_id, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("UPDATE customer_chat_messages SET message = '' WHERE sender_role = 'user' AND prescription_id IS NOT NULL AND message = 'I submitted a prescription for review.'");
$conn->query("INSERT INTO customer_chat_messages (user_id, sender_role, message, prescription_image, prescription_status, prescription_id, created_at)
    SELECT p.user_id, 'user', COALESCE(NULLIF(p.notes, ''), ''),
           CONCAT('prescriptions/', p.file_name), p.status, p.id, p.submitted_at
    FROM prescriptions p
    LEFT JOIN customer_chat_messages c ON c.prescription_id = p.id
    WHERE c.id IS NULL");
    $conn->query("INSERT INTO customer_chat_messages (user_id, sender_role, message, prescription_image, prescription_status, order_id, created_at)
        SELECT o.user_id, 'user', CONCAT('Prescription submitted for order ORD-', DATE_FORMAT(o.order_date, '%y%m%d'), '-', LPAD((
                SELECT COUNT(*) FROM orders earlier
                WHERE earlier.order_date >= DATE(o.order_date)
                  AND earlier.order_date < DATE(o.order_date) + INTERVAL 1 DAY
                  AND earlier.id <= o.id
            ), 3, '0'), '.'),
            CONCAT('prescriptions/', o.prescription_image), o.prescription_status, o.id, COALESCE(o.order_date, NOW())
        FROM orders o
        LEFT JOIN customer_chat_messages c ON c.order_id = o.id
        WHERE o.prescription_image IS NOT NULL AND TRIM(o.prescription_image) <> '' AND c.id IS NULL");
$conn->query("CREATE TABLE IF NOT EXISTS prescription_product_access (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, user_id INT NOT NULL, granted_by INT NULL, prescription_id INT NULL, granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NULL, quantity_limit INT UNSIGNED NOT NULL DEFAULT 0, quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0, UNIQUE KEY uq_prescription_product_user (product_id, user_id), KEY idx_prescription_access_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_limit INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS quantity_purchased INT UNSIGNED NOT NULL DEFAULT 0");
$conn->query("UPDATE prescription_product_access ppa
    JOIN products p ON p.id = ppa.product_id
    SET ppa.quantity_limit = GREATEST(COALESCE(p.quantity, 0), 0), ppa.quantity_purchased = 0, ppa.expires_at = NULL
    WHERE ppa.quantity_limit = 0 AND ppa.expires_at IS NOT NULL");
$conn->query("ALTER TABLE prescription_product_access ADD COLUMN IF NOT EXISTS prescription_id INT NULL");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS prescription_required TINYINT(1) NOT NULL DEFAULT 0");
$prescriptionColumnResult = $conn->query("SHOW COLUMNS FROM products LIKE 'prescription_required'");
$hasPrescriptionColumn = $prescriptionColumnResult && $prescriptionColumnResult->num_rows > 0;
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS prescription_status VARCHAR(20) NOT NULL DEFAULT 'none'");
$orderPrescriptionColumnResult = $conn->query("SHOW COLUMNS FROM orders LIKE 'prescription_status'");
$hasOrderPrescriptionStatus = $orderPrescriptionColumnResult && $orderPrescriptionColumnResult->num_rows > 0;

$userId = (int)$_SESSION['user_id'];
$selectedUserId = $isStaff ? (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0) : $userId;
$conversationCategory = $_GET['category'] ?? 'all';
if (!in_array($conversationCategory, ['all', 'prescription'], true)) {
    $conversationCategory = 'all';
}
$message = '';
$error = '';
$prescriptionProducts = $isStaff && $hasPrescriptionColumn ? $conn->query("SELECT p.id, p.product_name, p.status, p.box, p.pieces, p.pieces_per_box,
    (SELECT COUNT(*) FROM product_batches pb WHERE pb.product_id = p.id) AS batch_count,
    (SELECT COALESCE(SUM(CASE WHEN pb.recalled = 0 AND pb.expiration_date >= CURDATE() THEN pb.current_quantity ELSE 0 END), 0)
     FROM product_batches pb WHERE pb.product_id = p.id) AS sellable_batch_stock
    FROM products p WHERE p.prescription_required = 1 ORDER BY p.product_name ASC") : false;

function prescriptionAccessExpiry(string $date): ?string
{
    $expiryDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$expiryDate
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $expiryDate->format('Y-m-d') !== $date
        || $date < date('Y-m-d')) {
        return null;
    }
    return $expiryDate->format('Y-m-d') . ' 23:59:59';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_prescription' && $isStaff) {
    $chatId = (int)($_POST['chat_id'] ?? 0);
    $decision = strtolower(trim($_POST['decision'] ?? ''));
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantityLimit = filter_var($_POST['quantity_limit'] ?? null, FILTER_VALIDATE_INT);
    $medicineName = trim($_POST['medicine_name'] ?? '');
    $accessExpiresAt = prescriptionAccessExpiry(trim((string)($_POST['access_valid_until'] ?? '')));
    if ($chatId > 0 && in_array($decision, ['accepted', 'rejected'], true)) {
        $reviewDetailsResult = $conn->query("SELECT prescription_image, prescription_number, prescription_date, prescription_id FROM customer_chat_messages WHERE id = $chatId LIMIT 1");
        $reviewDetails = $reviewDetailsResult ? $reviewDetailsResult->fetch_assoc() : null;
        $hasCompletePrescription = $reviewDetails
            && trim((string)($reviewDetails['prescription_image'] ?? '')) !== ''
            && trim((string)($reviewDetails['prescription_number'] ?? '')) !== ''
            && !empty($reviewDetails['prescription_date']);
        if (!$hasCompletePrescription) {
            $error = 'Cannot accept: prescription image, ID, and date are required.';
        }
        if ($decision === 'accepted' && empty($error)) {
            if ($productId < 1 || !$hasPrescriptionColumn || $quantityLimit === false || $quantityLimit === null || $quantityLimit < 1) {
                $error = 'Select a prescription-required product before accepting this prescription.';
            } elseif ($accessExpiresAt === null) {
                $error = 'Set a valid access date that is today or later before accepting this prescription.';
            } else {
                $productCheck = $conn->prepare("SELECT id, status, box, pieces, pieces_per_box,
                    (SELECT COUNT(*) FROM product_batches pb WHERE pb.product_id = products.id) AS batch_count,
                    (SELECT COALESCE(SUM(CASE WHEN pb.recalled = 0 AND pb.expiration_date >= CURDATE() THEN pb.current_quantity ELSE 0 END), 0)
                     FROM product_batches pb WHERE pb.product_id = products.id) AS sellable_batch_stock
                    FROM products WHERE id = ? AND prescription_required = 1 LIMIT 1");
                if (!$productCheck) {
                    $error = 'Unable to verify the selected prescription product.';
                } else {
                    $productCheck->bind_param('i', $productId);
                    $productCheck->execute();
                    $product = $productCheck->get_result()->fetch_assoc();
                    $productCheck->close();
                    if (!$product) {
                        $error = 'The selected product is not marked as prescription-required.';
                    } else {
                        $productPiecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
                        $productAvailableStock = max(0, ((int)($product['box'] ?? 0) * $productPiecesPerBox) + (int)($product['pieces'] ?? 0));
                        if ((int)($product['batch_count'] ?? 0) > 0) {
                            $productAvailableStock = min($productAvailableStock, max(0, (int)($product['sellable_batch_stock'] ?? 0)));
                        }
                        if (strtolower((string)$product['status']) !== 'active') {
                            $error = 'The selected product is inactive and cannot be shared.';
                        } elseif ($productAvailableStock < 1) {
                            $error = 'The selected product has no available, unexpired stock.';
                        } elseif ($quantityLimit > $productAvailableStock) {
                            $error = 'Quantity limit cannot exceed the selected product’s available stock of ' . number_format($productAvailableStock) . ' pieces.';
                        }
                    }
                }
            }
        }
    }
    if ($chatId > 0 && in_array($decision, ['accepted', 'rejected'], true) && empty($error)) {
        $review = $conn->prepare("UPDATE customer_chat_messages SET prescription_status = ?, prescription_reviewed_at = NOW(), prescription_reviewed_by = ? WHERE id = ? AND prescription_image IS NOT NULL");
        if ($review) {
            $adminId = (int)$_SESSION['user_id'];
            $review->bind_param('sii', $decision, $adminId, $chatId);
            $review->execute();
            $review->close();

            $prescriptionIdResult = $conn->query("SELECT prescription_id, order_id, user_id FROM customer_chat_messages WHERE id = $chatId LIMIT 1");
            $prescriptionIdRow = $prescriptionIdResult ? $prescriptionIdResult->fetch_assoc() : null;
            if (!empty($prescriptionIdRow['prescription_id'])) {
                $prescriptionStatus = $decision === 'accepted' ? 'approved' : 'rejected';
                $prescriptionUpdate = $conn->prepare('UPDATE prescriptions SET status = ?, reviewed_at = NOW() WHERE id = ?');
                if ($prescriptionUpdate) {
                    $prescriptionUpdate->bind_param('si', $prescriptionStatus, $prescriptionIdRow['prescription_id']);
                    $prescriptionUpdate->execute();
                    $prescriptionUpdate->close();
                }
            }
            if (!empty($prescriptionIdRow['order_id'])) {
                $orderUpdate = $conn->prepare('UPDATE orders SET prescription_status = ?, prescription_reviewed_at = NOW(), prescription_reviewed_by = ? WHERE id = ?');
                if ($orderUpdate) {
                    $orderUpdate->bind_param('sii', $decision, $adminId, $prescriptionIdRow['order_id']);
                    $orderUpdate->execute();
                    $orderUpdate->close();
                }
            }

            $chatOwner = $conn->query("SELECT user_id FROM customer_chat_messages WHERE id = $chatId LIMIT 1");
            $chatOwnerRow = $chatOwner ? $chatOwner->fetch_assoc() : null;
            if (!empty($chatOwnerRow['user_id'])) {
                if ($decision === 'accepted' && $productId > 0 && $hasPrescriptionColumn) {
                    $productStmt = $conn->prepare('SELECT product_name FROM products WHERE id = ? AND prescription_required = 1 LIMIT 1');
                    $productStmt->bind_param('i', $productId);
                    $productStmt->execute();
                    $approvedProduct = $productStmt->get_result()->fetch_assoc();
                    $productStmt->close();
                    if ($approvedProduct) {
                        $access = $conn->prepare('INSERT INTO prescription_product_access (product_id, user_id, granted_by, prescription_id, expires_at, quantity_limit, quantity_purchased) VALUES (?, ?, ?, NULLIF(?, 0), ?, ?, 0) ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by), prescription_id = VALUES(prescription_id), granted_at = CURRENT_TIMESTAMP, expires_at = VALUES(expires_at), quantity_limit = VALUES(quantity_limit), quantity_purchased = 0');
                        $adminId = (int)$_SESSION['user_id'];
                        $linkedPrescriptionId = (int)($prescriptionIdRow['prescription_id'] ?? 0);
                        $access->bind_param('iiiisi', $productId, $chatOwnerRow['user_id'], $adminId, $linkedPrescriptionId, $accessExpiresAt, $quantityLimit);
                        if (!$access->execute()) {
                            $error = 'Unable to save the approved product quantity limit.';
                        }
                        $access->close();
                        if ($error === '') {
                            $medicineName = $approvedProduct['product_name'];

                            if (!empty($prescriptionIdRow['prescription_id'])) {
                                $prescriptionId = (int)$prescriptionIdRow['prescription_id'];
                                $shareUpdate = $conn->prepare("UPDATE prescriptions SET status = 'approved', shared_product_id = ?, product_access_expires_at = ? WHERE id = ? AND user_id = ?");
                                if ($shareUpdate) {
                                    $shareUpdate->bind_param('isii', $productId, $accessExpiresAt, $prescriptionId, $chatOwnerRow['user_id']);
                                    $shareUpdate->execute();
                                    $shareUpdate->close();
                                    $openRequest = $conn->prepare('INSERT INTO prescription_chat_open_requests (prescription_id, user_id, requested_at, opened_at) VALUES (?, ?, NOW(), NULL) ON DUPLICATE KEY UPDATE requested_at = NOW(), opened_at = NULL');
                                    if ($openRequest) {
                                        $openRequest->bind_param('ii', $prescriptionId, $chatOwnerRow['user_id']);
                                        $openRequest->execute();
                                        $openRequest->close();
                                    }
                                }
                            }
                            $shareChatUpdate = $conn->prepare("UPDATE customer_chat_messages SET prescription_status = 'accepted', shared_product_id = ?, product_access_expires_at = ? WHERE id = ?");
                            if ($shareChatUpdate) {
                                $shareChatUpdate->bind_param('isi', $productId, $accessExpiresAt, $chatId);
                                $shareChatUpdate->execute();
                                $shareChatUpdate->close();
                            }
                            $reply = $conn->prepare('INSERT INTO customer_chat_messages (user_id, sender_role, message, medicine_name, product_id, prescription_status, prescription_id, product_access_expires_at, shared_product_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                            $replyText = 'Your prescription was approved. The pharmacy has shared a set quantity of this medicine for you.';
                            $replyRole = 'admin';
                            $replyStatus = 'accepted';
                            $linkedPrescriptionId = (int)($prescriptionIdRow['prescription_id'] ?? 0);
                            if ($reply) {
                                $reply->bind_param('isssisisi', $chatOwnerRow['user_id'], $replyRole, $replyText, $medicineName, $productId, $replyStatus, $linkedPrescriptionId, $accessExpiresAt, $productId);
                                $reply->execute();
                                $reply->close();
                            }
                        }
                    }
                }
                $notice = 'Your prescription in Customer Chat was ' . $decision . ' by the pharmacy team.';
                $noticeStmt = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())');
                if ($noticeStmt) {
                    $noticeStmt->bind_param('is', $chatOwnerRow['user_id'], $notice);
                    $noticeStmt->execute();
                    $noticeStmt->close();
                }
                $selectedUserId = (int)$chatOwnerRow['user_id'];
            }
            $message = 'Prescription ' . $decision . '.';
        }
    }
}

function chatUpload(array $file, string &$error): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int)$file['size'] > 5 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
        $error = 'Prescription image must be valid and 5 MB or smaller.';
        return null;
    }
    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
        $error = 'Only JPG, PNG, and WEBP prescription images are accepted.';
        return null;
    }
    $name = 'chat_prescription_' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/uploads/' . $name)) {
        $error = 'Unable to save the prescription image.';
        return null;
    }
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $targetUserId = $isStaff ? (int)($_POST['user_id'] ?? 0) : $userId;
    $productId = $isStaff ? (int)($_POST['product_id'] ?? 0) : 0;
    $quantityLimit = filter_var($_POST['quantity_limit'] ?? null, FILTER_VALIDATE_INT);
    $accessExpiresAt = prescriptionAccessExpiry(trim((string)($_POST['access_valid_until'] ?? '')));
    $medicineName = trim($_POST['medicine_name'] ?? '');
    $chatText = trim($_POST['message'] ?? '');
    $prescriptionNumber = trim($_POST['prescription_number'] ?? '');
    $prescriptionDate = trim($_POST['prescription_date'] ?? '');
    $prescriptionDateObject = DateTime::createFromFormat('Y-m-d', $prescriptionDate);
    $productShareTransaction = false;
    if ($role === 'user' && !empty($_FILES['prescription_image']['name']) && ($prescriptionNumber === '' || !$prescriptionDateObject || $prescriptionDateObject->format('Y-m-d') !== $prescriptionDate)) {
        $error = 'Prescription image requires a prescription ID and a valid prescription date.';
    }
    $imageName = !$error ? chatUpload($_FILES['prescription_image'] ?? [], $error) : null;
    if ($isStaff && $productId > 0) {
        $productResult = $hasPrescriptionColumn ? $conn->query("SELECT product_name, prescription_required, status, box, pieces, pieces_per_box,
            (SELECT COUNT(*) FROM product_batches pb WHERE pb.product_id = products.id) AS batch_count,
            (SELECT COALESCE(SUM(CASE WHEN pb.recalled = 0 AND pb.expiration_date >= CURDATE() THEN pb.current_quantity ELSE 0 END), 0)
             FROM product_batches pb WHERE pb.product_id = products.id) AS sellable_batch_stock
            FROM products WHERE id = $productId LIMIT 1") : false;
        $product = $productResult ? $productResult->fetch_assoc() : null;
        $approvedPrescriptionId = 0;
        $approvedChatId = 0;
        $approvedPrescriptionStmt = $conn->prepare("SELECT id FROM prescriptions WHERE user_id = ? AND status = 'approved' ORDER BY reviewed_at DESC, submitted_at DESC LIMIT 1");
        if ($approvedPrescriptionStmt) {
            $approvedPrescriptionStmt->bind_param('i', $targetUserId);
            $approvedPrescriptionStmt->execute();
            $approvedPrescriptionId = (int)($approvedPrescriptionStmt->get_result()->fetch_assoc()['id'] ?? 0);
            $approvedPrescriptionStmt->close();
        }
        if ($approvedPrescriptionId < 1) {
            $approvedChatStmt = $conn->prepare("SELECT id FROM customer_chat_messages WHERE user_id = ? AND prescription_image IS NOT NULL AND prescription_status IN ('approved', 'accepted') ORDER BY created_at DESC LIMIT 1");
            if ($approvedChatStmt) {
                $approvedChatStmt->bind_param('i', $targetUserId);
                $approvedChatStmt->execute();
                $approvedChatId = (int)($approvedChatStmt->get_result()->fetch_assoc()['id'] ?? 0);
                $approvedChatStmt->close();
            }
        }
        if (!$product || empty($product['prescription_required']) || ($approvedPrescriptionId < 1 && $approvedChatId < 1)) {
            $error = 'This customer must have an approved prescription before receiving this item.';
        } elseif ($quantityLimit === false || $quantityLimit === null || $quantityLimit < 1) {
            $error = 'Set a valid quantity limit before sharing this product.';
        } elseif ($accessExpiresAt === null) {
            $error = 'Set a valid access date that is today or later before sharing this product.';
        } else {
            $productPiecesPerBox = max(1, (int)($product['pieces_per_box'] ?? 12));
            $productAvailableStock = max(0, ((int)($product['box'] ?? 0) * $productPiecesPerBox) + (int)($product['pieces'] ?? 0));
            if ((int)($product['batch_count'] ?? 0) > 0) {
                $productAvailableStock = min($productAvailableStock, max(0, (int)($product['sellable_batch_stock'] ?? 0)));
            }
            if (strtolower((string)($product['status'] ?? '')) !== 'active') {
                $error = 'The selected product is inactive and cannot be shared.';
            } elseif ($productAvailableStock < 1) {
                $error = 'The selected product has no available, unexpired stock.';
            } elseif ($quantityLimit > $productAvailableStock) {
                $error = 'Quantity limit cannot exceed the selected product’s available stock of ' . number_format($productAvailableStock) . ' pieces.';
            }
            if (!$error) {
                $productShareTransaction = $conn->begin_transaction();
                if (!$productShareTransaction) {
                    $error = 'Unable to start product sharing. Please try again.';
                    error_log('Customer chat product-sharing transaction failed: ' . $conn->error);
                }
            }
            $access = !$error ? $conn->prepare('INSERT INTO prescription_product_access (product_id, user_id, granted_by, prescription_id, expires_at, quantity_limit, quantity_purchased) VALUES (?, ?, ?, NULLIF(?, 0), ?, ?, 0) ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by), prescription_id = VALUES(prescription_id), granted_at = CURRENT_TIMESTAMP, expires_at = VALUES(expires_at), quantity_limit = VALUES(quantity_limit), quantity_purchased = 0') : false;
            if ($access) {
                $access->bind_param('iiiisi', $productId, $targetUserId, $userId, $approvedPrescriptionId, $accessExpiresAt, $quantityLimit);
                $accessSaved = $access->execute();
                if (!$accessSaved) {
                    $error = 'Unable to grant product access.';
                    error_log('Customer chat product access insert failed: ' . $access->error);
                }
                $access->close();
            } elseif (!$error) {
                $error = 'Unable to prepare product access.';
                error_log('Customer chat product access prepare failed: ' . $conn->error);
            }
            if (!$error && $approvedPrescriptionId > 0) {
                $shareUpdate = $conn->prepare("UPDATE prescriptions SET shared_product_id = ?, product_access_expires_at = ? WHERE id = ? AND user_id = ? AND status = 'approved'");
                if ($shareUpdate) {
                    $shareUpdate->bind_param('isii', $productId, $accessExpiresAt, $approvedPrescriptionId, $targetUserId);
                    $prescriptionUpdated = $shareUpdate->execute();
                    $shareUpdate->close();
                    if (!$prescriptionUpdated) {
                        $error = 'Unable to update the approved prescription access.';
                        error_log('Customer chat prescription access update failed: ' . $conn->error);
                    } else {
                        $openRequest = $conn->prepare('INSERT INTO prescription_chat_open_requests (prescription_id, user_id, requested_at, opened_at) VALUES (?, ?, NOW(), NULL) ON DUPLICATE KEY UPDATE requested_at = NOW(), opened_at = NULL');
                        if ($openRequest) {
                            $openRequest->bind_param('ii', $approvedPrescriptionId, $targetUserId);
                            $requestSaved = $openRequest->execute();
                            $openRequest->close();
                            if (!$requestSaved) {
                                $error = 'Unable to update the prescription access request.';
                                error_log('Customer chat prescription open request failed: ' . $conn->error);
                            }
                        } else {
                            $error = 'Unable to prepare the prescription access request.';
                            error_log('Customer chat prescription open request prepare failed: ' . $conn->error);
                        }
                    }
                } else {
                    $error = 'Unable to prepare the approved prescription access update.';
                    error_log('Customer chat prescription access update prepare failed: ' . $conn->error);
                }
            } elseif (!$error && $approvedChatId > 0) {
                $shareUpdate = $conn->prepare("UPDATE customer_chat_messages SET shared_product_id = ?, product_access_expires_at = ? WHERE id = ? AND user_id = ?");
                if ($shareUpdate) {
                    $shareUpdate->bind_param('isii', $productId, $accessExpiresAt, $approvedChatId, $targetUserId);
                    $prescriptionUpdated = $shareUpdate->execute();
                    $shareUpdate->close();
                    if (!$prescriptionUpdated) {
                        $error = 'Unable to update the approved prescription access.';
                        error_log('Customer chat prescription-message access update failed: ' . $conn->error);
                    }
                } else {
                    $error = 'Unable to prepare the approved prescription access update.';
                    error_log('Customer chat prescription-message access update prepare failed: ' . $conn->error);
                }
            }
            $medicineName = $product['product_name'];
        }
    }
    if (!$error && $targetUserId > 0 && ($medicineName !== '' || $chatText !== '' || $imageName !== null)) {
        $senderRole = $isStaff ? 'admin' : 'user';
        $linkedPrescriptionId = $isStaff && $productId > 0 ? ($approvedPrescriptionId ?? 0) : 0;
        $messageExpiry = $isStaff && $productId > 0 ? ($accessExpiresAt ?? '') : '';
        $insert = $conn->prepare('INSERT INTO customer_chat_messages (user_id, sender_role, message, medicine_name, prescription_image, prescription_status, product_id, prescription_number, prescription_date, prescription_id, product_access_expires_at, shared_product_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, 0), NULLIF(?, \'\'), NULLIF(?, 0))');
        if ($insert) {
            $prescriptionStatus = $imageName !== null ? 'pending' : ($productId > 0 ? 'accepted' : 'none');
            $sharedProductId = $isStaff ? $productId : 0;
            $insert->bind_param('isssssissisi', $targetUserId, $senderRole, $chatText, $medicineName, $imageName, $prescriptionStatus, $productId, $prescriptionNumber, $prescriptionDate, $linkedPrescriptionId, $messageExpiry, $sharedProductId);
            $inserted = $insert->execute();
            if (!$inserted) {
                error_log('Customer chat message insert failed: ' . $insert->error);
            }
            $insert->close();
            if ($inserted) {
                if ($productShareTransaction && !$conn->commit()) {
                    $error = 'Unable to finish sharing the product. Please try again.';
                    error_log('Customer chat product-sharing commit failed: ' . $conn->error);
                    $conn->rollback();
                } else {
                    $message = 'Message sent.';
                    $selectedUserId = $targetUserId;
                }
            } else {
                $error = 'Unable to save the chat message.';
            }
        } else {
            $error = 'Unable to send the chat message.';
        }
    } elseif (!$error) {
        $error = 'Add a medicine, message, or prescription image first.';
    }
    if ($productShareTransaction && $error !== '') {
        $conn->rollback();
    }
}

if ($isStaff) {
    $prescriptionOnlySql = $conversationCategory === 'prescription'
        ? " AND (p.id IS NOT NULL OR m.prescription_id IS NOT NULL OR (m.prescription_image IS NOT NULL AND TRIM(m.prescription_image) <> ''))"
        : '';
    $conversationUsers = $conn->query("SELECT u.id, u.username, u.email, COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS customer_name,
            MAX(COALESCE(m.created_at, p.submitted_at)) AS last_message,
            MAX(CASE WHEN p.id IS NOT NULL OR m.prescription_id IS NOT NULL OR (m.prescription_image IS NOT NULL AND TRIM(m.prescription_image) <> '') THEN 1 ELSE 0 END) AS has_prescription
        FROM users u
        LEFT JOIN customer_chat_messages m ON m.user_id = u.id
        LEFT JOIN prescriptions p ON p.user_id = u.id
        WHERE u.role = 'user' AND (m.id IS NOT NULL OR p.id IS NOT NULL)
            $prescriptionOnlySql
        GROUP BY u.id, u.username, u.email, u.full_name ORDER BY last_message DESC");
    $selectedUser = null;
    if ($selectedUserId > 0) {
        $selectedResult = $conn->query("SELECT id, username, email, COALESCE(NULLIF(TRIM(full_name), ''), username) AS customer_name FROM users WHERE id = $selectedUserId AND role = 'user' LIMIT 1");
        $selectedUser = $selectedResult ? $selectedResult->fetch_assoc() : null;
    }
} else {
    $selectedResult = $conn->query("SELECT id, username, email, COALESCE(NULLIF(TRIM(full_name), ''), username) AS customer_name FROM users WHERE id = $userId LIMIT 1");
    $selectedUser = $selectedResult ? $selectedResult->fetch_assoc() : null;
    $conversationUsers = false;
}

$chatMessages = [];
$completePrescriptionChatIds = [];
$prescriptionDetailsByChatId = [];
if ($selectedUserId > 0) {
    $messagesResult = $conn->query("SELECT m.*, u.username AS account_username, u.email AS account_email,
            COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS account_name,
            p.id AS linked_prescription_id, p.original_name AS linked_prescription_name, p.valid_id_original_name AS linked_valid_id_name,
            p.valid_id_file_name AS linked_valid_id_file, p.submitted_at AS linked_prescription_submitted_at,
            sp.id AS shared_product_record_id, sp.product_name AS shared_product_name, sp.image AS shared_product_image,
            sp.category AS shared_product_category, sp.prescription_required AS shared_product_requires_prescription,
            sp.status AS shared_product_status, sp.quantity AS shared_product_quantity, sp.box AS shared_product_box,
            sp.pieces AS shared_product_pieces, sp.pieces_per_box AS shared_product_pieces_per_box,
            sp.price AS shared_product_price, sp.price_piece AS shared_product_price_piece, sp.price_box AS shared_product_price_box,
            (SELECT NULLIF(pi.image_path, '')
             FROM product_images pi WHERE pi.product_id = sp.id ORDER BY pi.sort_order ASC LIMIT 1) AS shared_product_gallery_image,
            (SELECT COUNT(*) FROM product_batches pb WHERE pb.product_id = sp.id) AS shared_product_batch_count,
            (SELECT COALESCE(SUM(CASE WHEN pb.recalled = 0 AND pb.expiration_date >= CURDATE() THEN pb.current_quantity ELSE 0 END), 0)
             FROM product_batches pb WHERE pb.product_id = sp.id) AS shared_product_sellable_batch_stock,
            spa.quantity_limit AS shared_product_quantity_limit,
            spa.quantity_purchased AS shared_product_quantity_purchased,
            spa.expires_at AS shared_product_access_expires_at
        FROM customer_chat_messages m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN prescriptions p ON p.id = COALESCE(
            (SELECT matched.id FROM prescriptions matched WHERE matched.id = m.prescription_id AND matched.user_id = m.user_id LIMIT 1),
            (SELECT matched.id FROM prescriptions matched
                WHERE matched.user_id = m.user_id
                  AND matched.file_name = SUBSTRING_INDEX(m.prescription_image, '/', -1)
                ORDER BY matched.submitted_at DESC LIMIT 1)
        )
        LEFT JOIN products sp ON sp.id = COALESCE(NULLIF(m.shared_product_id, 0), NULLIF(m.product_id, 0))
        LEFT JOIN prescription_product_access spa ON spa.product_id = sp.id AND spa.user_id = m.user_id
        WHERE m.user_id = $selectedUserId
        ORDER BY m.created_at ASC");
    if ($messagesResult) {
        while ($row = $messagesResult->fetch_assoc()) {
            $chatMessages[] = $row;
            if (trim((string)($row['prescription_image'] ?? '')) !== '' && trim((string)($row['prescription_number'] ?? '')) !== '' && !empty($row['prescription_date'])) {
                $completePrescriptionChatIds[] = (int)$row['id'];
                $prescriptionDetailsByChatId[(int)$row['id']] = [
                    'number' => (string)$row['prescription_number'],
                    'date' => (string)$row['prescription_date']
                ];
            }
        }
    } else {
        error_log('Customer chat messages query failed: ' . $conn->error);
        $error = 'Unable to load this conversation right now. Please refresh the page or contact support.';
    }
    if ($isStaff) $conn->query("UPDATE customer_chat_messages SET is_read = 1 WHERE user_id = $selectedUserId AND sender_role = 'user'");
}
$currentPage = basename($_SERVER['PHP_SELF']);
$backUrl = $isStaff ? 'prescriptions.php' : '../user/product_screen.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer Chat</title>
<?php if ($isStaff): ?><link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4"><?php endif; ?>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f7fb;color:#20232a;font-family:'Segoe UI',Tahoma,sans-serif}.page{max-width:1180px;margin:0 auto;padding:28px}.admin-page{margin-left:244px;max-width:none}.heading{margin-bottom:20px}.heading h1{margin:0 0 5px;font-size:24px}.heading p{margin:0;color:#7b8189;font-size:12px}.chat-layout{display:grid;grid-template-columns:260px minmax(0,1fr);min-height:600px;background:#fff;border:1px solid #e5e9ef;border-radius:12px;overflow:hidden;box-shadow:0 5px 20px rgba(25,30,38,.06)}.users{border-right:1px solid #edf0f4;background:#fbfcfd}.users h2{padding:18px;margin:0;font-size:14px;border-bottom:1px solid #edf0f4}.user-link{display:block;padding:14px 16px;color:#303740;text-decoration:none;border-bottom:1px solid #f0f2f5}.user-link:hover,.user-link.active{background:#fff0f2;color:#d91f43}.user-link strong,.user-link small{display:block}.user-link small{margin-top:4px;color:#8a929a;font-size:10px}.chat-panel{display:flex;flex-direction:column;min-width:0}.chat-header{padding:18px;border-bottom:1px solid #edf0f4}.chat-header h2{margin:0;font-size:16px}.chat-header p{margin:5px 0 0;color:#7b8189;font-size:11px}.messages{flex:1;padding:20px;min-height:360px;max-height:520px;overflow:auto;background:#f8fafc}.bubble{max-width:76%;margin:0 0 14px;padding:11px 13px;border-radius:10px;background:#fff;border:1px solid #e5e9ef}.bubble.owner{margin-left:auto;background:#fff0f2;border-color:#ffd6df}.bubble-meta{margin-bottom:6px;color:#7b8189;font-size:10px}.bubble-text{font-size:12px;line-height:1.5;white-space:pre-wrap}.medicine{display:inline-block;margin-bottom:7px;padding:5px 8px;border-radius:5px;background:#eaf2ff;color:#2563a6;font-size:10px;font-weight:700}.prescription{display:block;max-width:240px;max-height:180px;margin-top:10px;border-radius:7px;border:1px solid #e1e5eb;object-fit:contain;background:#fff}.prescription-review{margin-top:8px;color:#68707a;font-size:10px}.review-actions{display:flex;gap:7px;margin-top:8px}.review-actions form{margin:0}.review-actions button{border:0;border-radius:5px;padding:6px 10px;color:#fff;font-size:10px;font-weight:700;cursor:pointer}.review-actions .accept{background:#15955c}.review-actions .reject{background:#db294b}.composer{padding:16px;border-top:1px solid #edf0f4}.composer-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.composer input,.composer textarea{width:100%;padding:10px;border:1px solid #dfe4e9;border-radius:6px;font:inherit;font-size:11px}.composer textarea{min-height:68px;resize:vertical;grid-column:1/-1}.composer-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px}.send{border:0;border-radius:6px;padding:10px 16px;background:#ed1740;color:#fff;font-size:11px;font-weight:700;cursor:pointer}.notice{margin-bottom:14px;padding:10px 12px;border-radius:6px;font-size:11px}.success{background:#e9f8ef;color:#278658}.error{background:#ffe8ed;color:#db294b}.empty{display:grid;place-items:center;min-height:360px;color:#7b8189;font-size:12px}@media(max-width:760px){.page{padding:20px 14px}.admin-page{margin-left:0}.chat-layout{grid-template-columns:1fr}.users{border-right:0;border-bottom:1px solid #edf0f4;max-height:190px;overflow:auto}.composer-grid{grid-template-columns:1fr}.bubble{max-width:90%}}
</style>
<style>
.prescription-label { display:flex; flex-direction:column; gap:5px; color:#626a74; font-size:11px; font-weight:700; }
.prescription-label input { width:100%; }
.accept-and-send-btn:disabled { opacity:.5; cursor:not-allowed; }
.missing-prescription-details { display:block; margin-top:6px; color:#c83b5a; font-size:10px; font-weight:700; }
</style>
<style>
body { min-height:100vh; }
.page { display:flex; width:100%; max-width:none; height:100vh; flex-direction:column; gap:12px; margin:0; padding:14px 18px 18px; }
.admin-page { width:calc(100% - 244px); height:100vh; margin-left:244px; padding:14px 18px 18px; }
.chat-back { display:inline-flex; width:max-content; align-items:center; gap:6px; color:#53657a; font-size:12px; font-weight:700; text-decoration:none; }
.chat-back:hover { color:#b4233d; }
.chat-back .material-icons-outlined { font-size:18px; }
.chat-layout { display:grid; width:100%; min-height:0; flex:1; grid-template-columns:minmax(0,1fr); border-radius:10px; }
.chat-layout.chat-inbox { grid-template-columns:260px minmax(0,1fr); }
.users { min-width:0; overflow-y:auto; }
.user-filters { display:grid; grid-template-columns:1fr 1fr; gap:6px; padding:12px; border-bottom:1px solid #edf0f4; }
.user-filter { padding:8px 6px; border:1px solid #e5e9ef; border-radius:6px; background:#fff; color:#596474; font-size:11px; font-weight:700; text-align:center; text-decoration:none; }
.user-filter:hover,.user-filter.active { border-color:#e7a4b0; background:#fff0f2; color:#b4233d; }
.chat-panel { min-height:0; overflow:hidden; }
.chat-header { display:flex; min-height:78px; align-items:center; justify-content:space-between; gap:16px; padding:14px 22px; background:#fff; }
.chat-contact { display:flex; min-width:0; align-items:center; gap:12px; }
.chat-avatar { display:grid; width:44px; height:44px; flex:0 0 44px; place-items:center; border-radius:50%; background:#b4233d; color:#fff; font-size:15px; font-weight:800; }
.chat-contact-copy { min-width:0; }
.chat-contact-copy h2 { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.chat-contact-copy p { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.user-prescription-label { display:inline-flex; width:max-content; align-items:center; gap:4px; margin-top:7px; padding:3px 6px; border-radius:5px; background:#fff0f2; color:#b4233d; font-size:9px; font-weight:700; }
.user-prescription-label .material-icons-outlined { font-size:12px; }
.customer-details { position:relative; flex:0 0 auto; }
.customer-details summary { display:inline-flex; min-height:36px; align-items:center; gap:6px; padding:7px 12px; border:1px solid #e7a4b0; border-radius:6px; color:#b4233d; font-size:11px; font-weight:700; cursor:pointer; list-style:none; }
.customer-details summary::-webkit-details-marker { display:none; }
.customer-details summary .material-icons-outlined { font-size:17px; }
.customer-details-popover { position:absolute; z-index:5; top:calc(100% + 6px); right:0; display:grid; min-width:220px; gap:5px; padding:12px; border:1px solid #e5e9ef; border-radius:7px; background:#fff; box-shadow:0 8px 24px rgba(25,30,38,.12); color:#68707a; font-size:11px; }
.customer-details-popover strong { color:#303740; }
.messages { display:flex; min-height:0; max-height:none; flex:1 1 auto; flex-direction:column; gap:4px; padding:20px 28px; background:#f8fafc; }
.bubble { max-width:min(620px,78%); padding:12px 14px; border-radius:10px; box-shadow:0 1px 3px rgba(20,30,40,.03); }
.bubble.owner { margin-right:0; }
.bubble-meta { font-size:10px; }
.bubble-text { font-size:13px; }
.composer { display:grid; flex:0 0 auto; gap:10px; padding:14px 18px; border-top:1px solid #e9edf2; background:#fff; }
.composer-grid { grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; }
.composer input,.composer textarea,.composer select { min-height:38px; padding:9px 10px; font-size:12px; }
.composer textarea { min-height:58px; max-height:100px; }
.composer .prescription-label { min-width:0; font-size:10px; }
.composer .prescription-label input { min-height:36px; padding:7px; }
.composer-actions { margin-top:0; }
.composer-actions small { color:#778393; font-size:10px; }
.send { min-height:38px; padding:0 18px; background:#b4233d; font-size:12px; }
.chat-composer { gap:8px; padding:14px 16px 10px; }
.chat-composer-main { display:grid; grid-template-columns:minmax(0,1fr) max-content; align-items:center; gap:10px; }
.chat-composer-main.with-tools { grid-template-columns:max-content minmax(0,1fr) max-content; }
.chat-tools { display:flex; align-items:center; gap:7px; padding-right:10px; border-right:1px solid #e5e9ef; }
.chat-tool { display:flex; min-width:62px; height:56px; flex-direction:column; align-items:center; justify-content:center; gap:3px; border:1px solid #e1e6ec; border-radius:6px; background:#fff; color:#53657a; cursor:pointer; }
.chat-tool:hover { border-color:#b4233d; color:#b4233d; }
.chat-tool .material-icons-outlined { font-size:20px; }
.chat-tool span:last-child { font-size:9px; }
.chat-input-wrap { position:relative; min-width:0; }
.chat-input-wrap textarea { display:block; width:100%; min-height:62px; max-height:110px; padding:11px 12px; border:1px solid #dfe4e9; border-radius:7px; background:#fff; color:#303740; font:inherit; font-size:12px; resize:vertical; }
.chat-composer-main > .chat-send { display:inline-flex; width:max-content!important; min-width:126px; min-height:58px; align-items:center; justify-self:start; justify-content:center; gap:7px; white-space:nowrap; }
.chat-send .material-icons-outlined { font-size:18px; }
.chat-prescription-meta { display:grid; grid-template-columns:1fr 1fr; gap:8px; padding:8px 0 0; }
.chat-prescription-meta[hidden] { display:none; }
.chat-prescription-meta label { display:grid; gap:4px; color:#68707a; font-size:10px; font-weight:700; }
.chat-prescription-meta input { width:100%; min-height:34px; padding:7px 9px; border:1px solid #dfe4e9; border-radius:5px; font:inherit; font-size:11px; }
.chat-file-input { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; clip-path:inset(50%); }
.chat-file-name { color:#687b94; font-size:10px; }
.chat-share-fields { display:grid; grid-template-columns:minmax(180px,.8fr) minmax(220px,1.2fr); gap:8px; }
.chat-share-fields select,.chat-share-fields input { min-width:0; min-height:36px; padding:8px 10px; border:1px solid #dfe4e9; border-radius:5px; background:#fff; font:inherit; font-size:11px; }
.chat-access-date { display:none; flex-direction:column; gap:5px; color:#778393; font-size:10px; }
.chat-access-date.visible { display:flex; }
.chat-access-date input { width:100%; }
.chat-composer-note { color:#778393; font-size:10px; }
.date-divider { display:flex; align-items:center; gap:12px; margin:0 auto 16px; color:#778393; font-size:10px; }
.date-divider::before,.date-divider::after { width:min(180px,20vw); height:1px; background:#e4e9ef; content:""; }
.bubble { max-width:min(620px,78%); }
.bubble.has-shared-product { width:100%; max-width:min(1120px,98%); padding:14px; }
.chat-shared-product { position:relative; display:grid; grid-template-columns:minmax(0,.98fr) minmax(0,1.02fr); align-items:center; gap:28px; margin-top:8px; padding:44px 10px 10px; border:0; border-radius:12px; background:#fff; box-shadow:none; }
.chat-shared-product-link { position:absolute; z-index:1; inset:0; border-radius:12px; }
.chat-shared-product-link:focus-visible { outline:3px solid #0868d7; outline-offset:3px; }
.chat-shared-product.is-clickable .chat-shared-product-image,.chat-shared-product.is-clickable .chat-shared-product-info { position:relative; z-index:2; pointer-events:none; }
.chat-shared-product.is-clickable .chat-shared-product-controls { position:relative; z-index:3; pointer-events:auto; }
.chat-shared-product-validity { position:absolute; z-index:4; top:8px; right:10px; display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border:1px solid #b9e5cc; border-radius:20px; background:#e8f7ef; color:#168352; font-size:12px; font-weight:700; pointer-events:none; }
.chat-shared-product-validity .material-icons-outlined { font-size:16px; }
.chat-shared-product-validity.expired { border-color:#f3c7cf; background:#fff0f2; color:#b4233d; }
.chat-shared-product-image { position:relative; display:grid; width:100%; aspect-ratio:1.02/1; place-items:center; overflow:hidden; border-radius:12px; background:#edf5ff; color:#8291a5; }
.chat-shared-product-image-link { display:block; width:100%; height:100%; }
.chat-shared-product-image .material-icons-outlined { position:absolute; font-size:58px; }
.chat-shared-product-image img { position:relative; width:100%; height:100%; object-fit:contain; }
.chat-shared-product-info { display:grid; min-width:0; gap:14px; align-content:center; }
.chat-shared-product-info h3 { margin:0; color:#123052; font-size:clamp(32px,4.35vw,50px); font-weight:750; line-height:1.12; overflow-wrap:anywhere; }
.chat-shared-product-info h3 a { color:inherit; text-decoration:none; }
.chat-shared-product-info h3 a:hover { text-decoration:underline; }
.chat-shared-product-rating { display:flex; align-items:center; gap:8px; color:#ffb400; font-size:clamp(15px,1.55vw,20px); letter-spacing:1px; line-height:1; }
.chat-shared-product-rating-count { color:#7c8ca3; font-size:clamp(11px,1vw,14px); letter-spacing:0; }
.chat-shared-product-category { width:max-content; padding:6px 10px; border-radius:6px; background:#fff3db; color:#a56a00; font-size:12px; font-weight:700; }
.chat-shared-product-price { display:flex; flex-wrap:wrap; align-items:baseline; color:#15955c; }
.chat-shared-product-price strong { font-size:clamp(42px,4.8vw,58px); line-height:1.1; }
.chat-shared-product-stock { display:inline-flex; width:max-content; max-width:100%; align-items:center; gap:9px; padding:12px 17px; border-radius:28px; background:#e8f7ef; color:#168352; font-size:clamp(15px,1.45vw,21px); font-weight:700; }
.chat-shared-product-stock .material-icons-outlined { font-size:25px; }
.chat-shared-product-stock.unavailable { background:#fff0f2; color:#b4233d; }
.chat-shared-product-expiry { color:#687b94; font-size:10px; }
.chat-shared-product-controls { display:grid; gap:12px; }
.chat-shared-product-quantity-label { color:#53657a; font-size:clamp(19px,2.2vw,25px); font-weight:700; }
.chat-shared-product-quantity { display:flex; width:100%; height:clamp(58px,7.3vw,84px); overflow:hidden; border:2px solid #e1eaf3; border-radius:17px; background:#fff; }
.chat-shared-product-quantity button { width:clamp(54px,8.3vw,94px); flex:0 0 clamp(54px,8.3vw,94px); border:0; background:#f1f5f9; color:#536d87; font-size:clamp(26px,3vw,42px); cursor:pointer; }
.chat-shared-product-quantity button:disabled { color:#b8c0ca; cursor:not-allowed; }
.chat-shared-product-quantity input { width:100%; min-width:0; border:0; outline:0; color:#243b59; font-size:clamp(24px,2.5vw,36px); font-weight:700; text-align:center; appearance:textfield; -moz-appearance:textfield; }
.chat-shared-product-quantity input::-webkit-inner-spin-button,
.chat-shared-product-quantity input::-webkit-outer-spin-button { margin:0; -webkit-appearance:none; }
.chat-shared-product-actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:2px; }
.chat-shared-product-actions button { display:inline-flex; min-height:clamp(62px,8vw,92px); align-items:center; justify-content:center; gap:10px; padding:0 12px; border:0; border-radius:15px; color:#fff; font:inherit; font-size:clamp(18px,2.25vw,26px); font-weight:700; cursor:pointer; }
.chat-shared-product-add { background:#0868d7; }
.chat-shared-product-buy { background:#15955c; }
.chat-shared-product-actions button:disabled { opacity:.5; cursor:not-allowed; }
.chat-shared-product-feedback { color:#b4233d; font-size:11px; }
.chat-shared-product-feedback.success { color:#168352; }
.document-section { margin-top:16px; }
.document-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
.document-heading h3 { margin:0; color:#263449; font-size:13px; }
.document-layout { display:grid; grid-template-columns:minmax(0,1.25fr) minmax(120px,1fr); align-items:start; gap:18px; }
.document-preview { position:relative; display:grid; min-width:0; height:235px; place-items:center; overflow:hidden; border:1px solid #e2e5e9; border-radius:7px; background:#f7f8fa; }
.document-preview img { width:100%; height:100%; object-fit:contain; }
.document-preview .material-icons-outlined { color:#b4233d; font-size:42px; }
.document-details { display:grid; align-content:start; gap:5px; padding-top:2px; color:#718198; font-size:11px; }
.document-details strong { overflow-wrap:anywhere; color:#263449; font-size:12px; font-weight:500; }
.document-view { display:inline-flex; min-height:34px; align-items:center; gap:5px; padding:0 10px; border:1px solid #e1e5ec; border-radius:6px; background:#fff; color:#475569; font-size:11px; text-decoration:none; white-space:nowrap; }
.document-view:hover { border-color:#b4233d; color:#b4233d; }
.attachment-status { width:max-content; padding:4px 7px; border-radius:5px; background:#e8f7ed; color:#218252; font-size:10px; font-weight:700; }
.attachment-status.pending { background:#fff3d6; color:#986b09; }
.attachment-status.rejected { background:#ffe8ed; color:#b4233d; }
.attachment-status.completed { background:#edf0f4; color:#667085; }
.attachment-view { display:inline-flex; min-height:34px; align-items:center; gap:5px; padding:0 10px; border:1px solid #e7a4b0; border-radius:6px; color:#b4233d; font-size:11px; font-weight:700; text-decoration:none; }
.attachment-view:hover { background:#fff3f5; }
@media(max-width:760px) {
    .page,.admin-page { width:100%; height:100vh; margin-left:0; padding:12px; }
    .chat-layout,.chat-layout.chat-inbox { grid-template-columns:1fr; }
    .users { max-height:140px; }
    .chat-header { min-height:68px; padding:12px; }
    .chat-avatar { width:38px; height:38px; flex-basis:38px; }
    .customer-details summary { width:36px; min-width:36px; justify-content:center; overflow:hidden; padding:7px; font-size:0; }
    .customer-details summary .material-icons-outlined { font-size:18px; }
    .messages { padding:14px 12px; }
    .bubble { max-width:92%; }
    .bubble.has-shared-product { max-width:100%; }
    .chat-shared-product { grid-template-columns:1fr; gap:18px; padding:44px 4px 4px; }
    .chat-shared-product-image { max-width:520px; margin:0 auto; }
    .chat-shared-product-info { gap:12px; }
    .chat-shared-product-info h3 { font-size:clamp(30px,8vw,42px); }
    .chat-shared-product-price strong { font-size:42px; }
    .chat-shared-product-actions button { min-height:64px; font-size:16px; }
    .composer { padding:10px; }
    .composer-grid { grid-template-columns:1fr; }
    .composer-actions { align-items:flex-end; }
    .chat-composer-main,.chat-composer-main.with-tools { grid-template-columns:minmax(0,1fr) max-content; }
    .chat-tools { grid-column:1/-1; padding-right:0; border-right:0; }
    .chat-tool { min-width:0; flex:1; }
    .chat-send { min-width:112px; padding:0 12px; }
    .chat-prescription-meta,.chat-share-fields,.document-layout { grid-template-columns:1fr; }
    .document-preview { height:210px; }
}
</style>
</head>
<body>
<?php if ($isStaff) { include 'appbar_owner.php'; include 'sidebar_owner.php'; } ?>
<main class="page <?php echo $isStaff ? 'admin-page' : ''; ?>">
<a class="chat-back" href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>"><span class="material-icons-outlined" aria-hidden="true">arrow_back</span>Back</a>
<?php if ($message): ?><div class="notice success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<div class="chat-layout <?php echo $isStaff && !$selectedUser ? 'chat-inbox' : ''; ?>">
<?php if ($isStaff && !$selectedUser): ?><aside class="users"><h2>Customers</h2><nav class="user-filters" aria-label="Customer categories"><a class="user-filter <?php echo $conversationCategory === 'all' ? 'active' : ''; ?>" href="?category=all" <?php echo $conversationCategory === 'all' ? 'aria-current="page"' : ''; ?>>All</a><a class="user-filter <?php echo $conversationCategory === 'prescription' ? 'active' : ''; ?>" href="?category=prescription" <?php echo $conversationCategory === 'prescription' ? 'aria-current="page"' : ''; ?>>Prescription</a></nav><?php if ($conversationUsers && $conversationUsers->num_rows): while ($chatUser = $conversationUsers->fetch_assoc()): ?><a class="user-link" href="?user_id=<?php echo (int)$chatUser['id']; ?>&amp;category=<?php echo htmlspecialchars($conversationCategory, ENT_QUOTES, 'UTF-8'); ?>"><strong><?php echo htmlspecialchars($chatUser['customer_name'] ?? $chatUser['username']); ?></strong><small><?php echo htmlspecialchars($chatUser['email']); ?></small><?php if (!empty($chatUser['has_prescription'])): ?><span class="user-prescription-label"><span class="material-icons-outlined" aria-hidden="true">prescriptions</span>Prescription</span><?php endif; ?></a><?php endwhile; else: ?><div class="empty">No conversations in this category.</div><?php endif; ?></aside><?php endif; ?>
<section class="chat-panel">
<?php if ($selectedUser): ?>
<?php
$chatDisplayName = $isStaff
    ? (string)($selectedUser['customer_name'] ?? $selectedUser['username'])
    : 'Pharmacy Owner';
$chatInitials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $chatDisplayName), 0, 2));
?>
<div class="chat-header">
    <div class="chat-contact">
        <div class="chat-avatar" aria-hidden="true"><?php echo htmlspecialchars($chatInitials); ?></div>
        <div class="chat-contact-copy">
            <h2><?php echo htmlspecialchars($chatDisplayName); ?></h2>
            <p><?php echo $isStaff ? htmlspecialchars($selectedUser['email']) : 'Send your medicine request and prescription here.'; ?></p>
        </div>
    </div>
</div>
<div class="messages">
<?php if ($chatMessages): ?>
    <?php $lastMessageDay = ''; foreach ($chatMessages as $chat): ?>
        <?php
        $messageTimestamp = strtotime($chat['created_at']);
        $messageDay = date('Y-m-d', $messageTimestamp);
        if ($messageDay !== $lastMessageDay) {
            $dayLabel = $messageDay === date('Y-m-d') ? 'Today' : date('M d, Y', $messageTimestamp);
            echo '<div class="date-divider"><span>' . htmlspecialchars($dayLabel) . '</span></div>';
            $lastMessageDay = $messageDay;
        }
        ?>
        <?php
        $chatSharedProductId = (int)($chat['shared_product_record_id'] ?? 0);
        $chatSharedProductCategory = strtolower(trim((string)($chat['shared_product_category'] ?? '')));
        $chatSharedProductIsPrescription = !empty($chat['shared_product_requires_prescription'])
            || $chatSharedProductCategory === 'prescription medicine';
        $chatSharedProductCard = $chatSharedProductId > 0
            && (!empty($chat['shared_product_id']) || $chatSharedProductIsPrescription);
        $chatSharedProductPiecesPerBox = max(1, (int)($chat['shared_product_pieces_per_box'] ?? 12));
        $chatSharedProductStock = max(
            0,
            ((int)($chat['shared_product_box'] ?? 0) * $chatSharedProductPiecesPerBox) + (int)($chat['shared_product_pieces'] ?? 0)
        );
        if ((int)($chat['shared_product_batch_count'] ?? 0) > 0) {
            $chatSharedProductStock = min($chatSharedProductStock, max(0, (int)($chat['shared_product_sellable_batch_stock'] ?? 0)));
        }
        $chatSharedProductQuantityRemaining = max(
            0,
            (int)($chat['shared_product_quantity_limit'] ?? 0) - (int)($chat['shared_product_quantity_purchased'] ?? 0)
        );
        $chatSharedProductExpiry = trim((string)($chat['shared_product_access_expires_at'] ?? ''));
        $chatSharedProductExpired = $chatSharedProductExpiry !== '' && strtotime($chatSharedProductExpiry) < time();
        $chatSharedProductMax = min($chatSharedProductStock, $chatSharedProductQuantityRemaining);
        $chatSharedProductCanOrder = $chatSharedProductMax > 0
            && !$chatSharedProductExpired
            && strtolower(trim((string)($chat['shared_product_status'] ?? ''))) === 'active';
        $chatSharedProductImage = trim((string)($chat['shared_product_gallery_image'] ?? ''));
        if ($chatSharedProductImage === '') {
            $chatSharedProductImage = trim((string)($chat['shared_product_image'] ?? ''));
        }
        $chatSharedProductImagePath = ltrim(str_replace('\\', '/', $chatSharedProductImage), '/');
        $chatSharedProductImageUrl = preg_match('/^https?:\/\//i', $chatSharedProductImagePath)
            ? $chatSharedProductImagePath
            : '../user/uploads/' . preg_replace('#^(?:\.\./|uploads/)+#i', '', $chatSharedProductImagePath);
        $chatSharedPiecePrice = (float)($chat['shared_product_price_piece'] ?? 0);
        if ($chatSharedPiecePrice <= 0) {
            $chatSharedPiecePrice = (float)($chat['shared_product_price'] ?? 0);
        }
        $chatSharedBoxPrice = round($chatSharedPiecePrice * $chatSharedProductPiecesPerBox * 0.98, 2);
        ?>
        <article class="bubble <?php echo in_array($chat['sender_role'], ['admin', 'pharmacist'], true) ? 'owner' : ''; ?><?php echo $chatSharedProductCard ? ' has-shared-product' : ''; ?>">
            <div class="bubble-meta"><?php echo in_array($chat['sender_role'], ['admin', 'pharmacist'], true) ? 'Pharmacy Team' : 'Customer'; ?> · <?php echo date('M d, Y h:i A', $messageTimestamp); ?></div>
            <?php if ($chat['medicine_name']): ?><span class="medicine"><?php echo htmlspecialchars($chat['medicine_name']); ?></span><?php endif; ?>
            <?php if ($chat['message']): ?><div class="bubble-text"><?php echo htmlspecialchars($chat['message']); ?></div><?php endif; ?>
            <?php if ($chatSharedProductCard): ?>
                <section class="chat-shared-product<?php echo !$isStaff ? ' is-clickable' : ''; ?>" data-product-id="<?php echo $chatSharedProductId; ?>" data-stock="<?php echo $chatSharedProductMax; ?>" data-orderable="<?php echo $chatSharedProductCanOrder ? '1' : '0'; ?>">
                    <?php if (!$isStaff): ?><a class="chat-shared-product-link" href="../user/view_product.php?id=<?php echo $chatSharedProductId; ?>" aria-label="View <?php echo htmlspecialchars((string)$chat['shared_product_name'], ENT_QUOTES, 'UTF-8'); ?>"></a><?php endif; ?>
                    <?php if ($chatSharedProductExpiry !== ''): ?><span class="chat-shared-product-validity<?php echo $chatSharedProductExpired ? ' expired' : ''; ?>"><span class="material-icons-outlined" aria-hidden="true"><?php echo $chatSharedProductExpired ? 'event_busy' : 'event_available'; ?></span><?php echo $chatSharedProductExpired ? 'Access expired' : 'Valid until'; ?> <?php echo htmlspecialchars(date('M d, Y', strtotime($chatSharedProductExpiry)), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                    <div class="chat-shared-product-image">
                        <span class="material-icons-outlined" aria-hidden="true">medication</span>
                        <?php if ($chatSharedProductImagePath !== ''): ?><img src="<?php echo htmlspecialchars($chatSharedProductImageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string)$chat['shared_product_name'], ENT_QUOTES, 'UTF-8'); ?>" onerror="this.remove()"><?php endif; ?>
                    </div>
                    <div class="chat-shared-product-info">
                        <div class="chat-shared-product-rating" aria-label="5 out of 5 stars">
                            <span aria-hidden="true">★ ★ ★ ★ ★</span>
                            <span class="chat-shared-product-rating-count">(128 reviews)</span>
                        </div>
                        <h3><?php echo htmlspecialchars((string)$chat['shared_product_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <span class="chat-shared-product-category">Prescription</span>
                        <div class="chat-shared-product-price"><strong>₱<?php echo number_format($chatSharedPiecePrice, 2); ?></strong></div>
                        <span class="chat-shared-product-stock<?php echo $chatSharedProductStock > 0 ? '' : ' unavailable'; ?>"><span class="material-icons-outlined" aria-hidden="true"><?php echo $chatSharedProductStock > 0 ? 'check_circle' : 'error'; ?></span><?php echo $chatSharedProductStock > 0 ? 'Stock: ' . number_format($chatSharedProductStock) . ' available' : 'Product is out of stock.'; ?></span>
                        <span class="chat-shared-product-expiry">Pharmacy-set quantity limit: <?php echo number_format((int)($chat['shared_product_quantity_limit'] ?? 0)); ?> total · <?php echo number_format($chatSharedProductQuantityRemaining); ?> remaining</span>
                        <?php if ($chatSharedProductExpired): ?><span class="chat-shared-product-expiry">Prescription access expired.</span><?php endif; ?>
                        <?php if (!$chatSharedProductCanOrder && !$chatSharedProductExpired && $chatSharedProductQuantityRemaining > 0 && $chatSharedProductStock > 0): ?><span class="chat-shared-product-expiry">Product is currently unavailable.</span><?php endif; ?>
                        <?php if (!$isStaff): ?>
                            <div class="chat-shared-product-controls">
                                <span class="chat-shared-product-quantity-label">Quantity</span>
                                <div class="chat-shared-product-quantity">
                                        <button type="button" data-role="decrease" aria-label="Decrease quantity" <?php echo !$chatSharedProductCanOrder ? 'disabled' : ''; ?>>−</button>
                                        <input type="number" value="1" min="1" max="<?php echo max(1, $chatSharedProductMax); ?>" data-role="quantity" aria-label="Quantity" <?php echo !$chatSharedProductCanOrder ? 'disabled' : ''; ?>>
                                        <button type="button" data-role="increase" aria-label="Increase quantity" <?php echo !$chatSharedProductCanOrder ? 'disabled' : ''; ?>>+</button>
                                </div>
                                <div class="chat-shared-product-actions">
                                        <button type="button" class="chat-shared-product-add" data-role="add" <?php echo !$chatSharedProductCanOrder ? 'disabled' : ''; ?>><span class="material-icons-outlined" aria-hidden="true">shopping_cart</span>Add to Cart</button>
                                        <button type="button" class="chat-shared-product-buy" data-role="buy" <?php echo !$chatSharedProductCanOrder ? 'disabled' : ''; ?>><span class="material-icons-outlined" aria-hidden="true">bolt</span>Buy Now</button>
                                </div>
                                <span class="chat-shared-product-feedback" data-role="feedback" role="alert" aria-live="polite"></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($chat['prescription_image']): ?>
                <?php
                $storedImage = (string)$chat['prescription_image'];
                $imageName = basename($storedImage);
                $linkedPrescriptionId = (int)($chat['linked_prescription_id'] ?? $chat['prescription_id'] ?? 0);
                if ($linkedPrescriptionId > 0) {
                    $prescriptionPath = '../user/prescription_file.php?id=' . $linkedPrescriptionId . '&type=prescription';
                    $prescriptionDiskPath = __DIR__ . '/../user/uploads/prescriptions/' . basename((string)($chat['file_name'] ?? $imageName));
                } elseif (strpos($storedImage, 'prescriptions/') === 0) {
                    $prescriptionPath = '../user/uploads/prescriptions/' . rawurlencode($imageName);
                    $prescriptionDiskPath = __DIR__ . '/../user/uploads/prescriptions/' . $imageName;
                } elseif (is_file(__DIR__ . '/uploads/' . $imageName)) {
                    $prescriptionPath = 'uploads/' . rawurlencode($imageName);
                    $prescriptionDiskPath = __DIR__ . '/uploads/' . $imageName;
                } else {
                    $prescriptionPath = '../user/uploads/' . rawurlencode($imageName);
                    $prescriptionDiskPath = __DIR__ . '/../user/uploads/' . $imageName;
                }
                $fileBytes = is_file($prescriptionDiskPath) ? filesize($prescriptionDiskPath) : 0;
                $fileSizeLabel = $fileBytes >= 1048576 ? round($fileBytes / 1048576, 1) . ' MB' : max(1, round($fileBytes / 1024)) . ' KB';
                $prescriptionFileName = trim((string)($chat['linked_prescription_name'] ?? '')) !== '' ? (string)$chat['linked_prescription_name'] : $imageName;
                $prescriptionUploadedAt = !empty($chat['linked_prescription_submitted_at']) ? strtotime($chat['linked_prescription_submitted_at']) : $messageTimestamp;
                $prescriptionIsImage = in_array(strtolower(pathinfo($prescriptionFileName, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
                $validIdAvailable = $linkedPrescriptionId > 0 && !empty($chat['linked_valid_id_file']);
                $validIdName = $validIdAvailable
                    ? (trim((string)($chat['linked_valid_id_name'] ?? '')) !== '' ? (string)$chat['linked_valid_id_name'] : basename((string)$chat['linked_valid_id_file']))
                    : '';
                $validIdPath = $validIdAvailable
                    ? '../user/prescription_file.php?id=' . $linkedPrescriptionId . '&type=valid_id'
                    : '';
                $validIdIsImage = $validIdAvailable && in_array(strtolower(pathinfo($validIdName, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
                $rawPrescriptionStatus = strtolower((string)($chat['prescription_status'] ?? 'pending'));
                $prescriptionStatusLabel = in_array($rawPrescriptionStatus, ['accepted', 'approved'], true) ? 'Approved' : ucfirst($rawPrescriptionStatus);
                $prescriptionStatusClass = in_array($rawPrescriptionStatus, ['rejected', 'completed'], true) ? $rawPrescriptionStatus : ($rawPrescriptionStatus === 'pending' ? 'pending' : '');
                ?>
                <section class="document-section">
                    <div class="document-heading"><h3>Uploaded Prescription</h3><a class="document-view" href="<?php echo htmlspecialchars($prescriptionPath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">View Full Size <span class="material-icons-outlined" aria-hidden="true">open_in_new</span></a></div>
                    <div class="document-layout">
                        <a class="document-preview" href="<?php echo htmlspecialchars($prescriptionPath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php if ($prescriptionIsImage): ?><img src="<?php echo htmlspecialchars($prescriptionPath, ENT_QUOTES, 'UTF-8'); ?>" alt="Uploaded prescription"><?php else: ?><span class="material-icons-outlined" aria-hidden="true">description</span><?php endif; ?></a>
                        <div class="document-details"><span>File name</span><strong><?php echo htmlspecialchars($prescriptionFileName); ?></strong><span>Uploaded on</span><strong><?php echo date('M d, Y · h:i A', $prescriptionUploadedAt); ?></strong><?php if ($fileBytes > 0): ?><span><?php echo htmlspecialchars($fileSizeLabel); ?></span><?php endif; ?><span class="attachment-status <?php echo $prescriptionStatusClass; ?>"><?php echo htmlspecialchars($prescriptionStatusLabel); ?></span></div>
                    </div>
                </section>
                <?php if ($validIdAvailable): ?>
                    <section class="document-section">
                        <div class="document-heading"><h3>Valid ID</h3><a class="document-view" href="<?php echo htmlspecialchars($validIdPath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">View Full Size <span class="material-icons-outlined" aria-hidden="true">open_in_new</span></a></div>
                        <div class="document-layout">
                            <a class="document-preview" href="<?php echo htmlspecialchars($validIdPath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php if ($validIdIsImage): ?><img src="<?php echo htmlspecialchars($validIdPath, ENT_QUOTES, 'UTF-8'); ?>" alt="Uploaded valid ID"><?php else: ?><span class="material-icons-outlined" aria-hidden="true">description</span><?php endif; ?></a>
                            <div class="document-details"><span>File name</span><strong><?php echo htmlspecialchars($validIdName); ?></strong><span>Uploaded on</span><strong><?php echo date('M d, Y · h:i A', $prescriptionUploadedAt); ?></strong></div>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($chat['sender_role'] === 'user'): ?>
                    <div class="prescription-review"><strong>Prescription ID: <?php echo htmlspecialchars($chat['prescription_number'] ?? 'Not provided'); ?> · Date: <?php echo htmlspecialchars($chat['prescription_date'] ?? 'Not provided'); ?></strong>
                    <?php if ($isStaff && $rawPrescriptionStatus === 'pending'): ?><div class="review-actions"><button type="button" class="accept accept-and-send-btn" data-chat-id="<?php echo (int)$chat['id']; ?>" data-user-id="<?php echo $selectedUserId; ?>">Accept &amp; Send</button><form method="post"><input type="hidden" name="action" value="review_prescription"><input type="hidden" name="chat_id" value="<?php echo (int)$chat['id']; ?>"><input type="hidden" name="decision" value="rejected"><input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>"><button class="reject" type="submit">Reject</button></form></div><?php endif; ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty">No messages yet. Send a message or prescription below.</div>
<?php endif; ?>
</div>
<form class="composer chat-composer" method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="send_message"><input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
<?php if ($isStaff): ?>
<div class="chat-share-fields">
    <select id="chatSharedProductId" name="product_id">
        <option value="">Share a prescription item (optional)</option>
        <?php if ($prescriptionProducts): while ($prescriptionProduct = $prescriptionProducts->fetch_assoc()):
            $listedStock = max(0, ((int)($prescriptionProduct['box'] ?? 0) * max(1, (int)($prescriptionProduct['pieces_per_box'] ?? 12))) + (int)($prescriptionProduct['pieces'] ?? 0));
            if ((int)($prescriptionProduct['batch_count'] ?? 0) > 0) {
                $listedStock = min($listedStock, max(0, (int)($prescriptionProduct['sellable_batch_stock'] ?? 0)));
            }
            $productSelectable = strtolower((string)($prescriptionProduct['status'] ?? '')) === 'active' && $listedStock > 0;
        ?>
        <option value="<?php echo (int)$prescriptionProduct['id']; ?>" data-stock="<?php echo $listedStock; ?>" <?php echo $productSelectable ? '' : 'disabled'; ?>>
            <?php echo htmlspecialchars($prescriptionProduct['product_name']); ?><?php echo $productSelectable ? '' : ' (unavailable)'; ?>
        </option>
        <?php endwhile; endif; ?>
    </select>
    <input name="medicine_name" placeholder="Medicine name / request">
    <input id="chatQuantityLimit" type="number" name="quantity_limit" min="1" placeholder="Quantity limit">
    <label class="chat-access-date" id="chatAccessDateField">Prescription access valid until<input id="chatAccessValidUntil" type="date" name="access_valid_until" min="<?php echo date('Y-m-d'); ?>"></label>
    <span class="chat-composer-note" id="chatShareNote">Choose a prescription item to set its purchase quantity and valid-until date.</span>
</div>
<?php endif; ?>
<?php if ($role === 'user'): ?><input class="chat-file-input" id="chatPrescriptionImage" type="file" name="prescription_image" accept="image/jpeg,image/png,image/webp"><div class="chat-prescription-meta" id="chatPrescriptionMeta" hidden><label>Prescription ID<input id="chatPrescriptionNumber" name="prescription_number" placeholder="Enter prescription ID"></label><label>Prescription date<input id="chatPrescriptionDate" type="date" name="prescription_date"></label></div><?php endif; ?>
<div class="chat-composer-main <?php echo $role === 'user' ? 'with-tools' : ''; ?>">
    <?php if ($role === 'user'): ?><div class="chat-tools"><label class="chat-tool" for="chatPrescriptionImage"><span class="material-icons-outlined" aria-hidden="true">attach_file</span><span>Attach</span></label><button type="button" class="chat-tool" id="togglePrescriptionDetails"><span class="material-icons-outlined" aria-hidden="true">prescriptions</span><span>Prescription</span></button><label class="chat-tool" for="chatPrescriptionImage"><span class="material-icons-outlined" aria-hidden="true">image</span><span>Image</span></label></div><?php endif; ?>
    <div class="chat-input-wrap"><textarea name="message" id="chatMessageInput" placeholder="Type your message..." aria-label="Type your message"></textarea></div>
    <button class="send chat-send" type="submit"><span class="material-icons-outlined" aria-hidden="true">send</span>Send Message</button>
</div>
<?php if ($role === 'user'): ?><div class="chat-composer-note"><span class="material-icons-outlined" aria-hidden="true">verified_user</span> Prescription image, ID, and date are required for review.<span class="chat-file-name" id="chatFileName"></span></div><?php endif; ?></form>
<?php else: ?><div class="empty">Select a customer conversation.</div><?php endif; ?>
</section>
</div>
</main>
<?php if ($role === 'user'): ?>
<script>
(() => {
    let latestAdminMessageId = <?php
        $latestAdminMessageId = 0;
        foreach ($chatMessages as $chatMessage) {
            if (($chatMessage['sender_role'] ?? '') === 'admin') {
                $latestAdminMessageId = max($latestAdminMessageId, (int)$chatMessage['id']);
            }
        }
        echo $latestAdminMessageId;
    ?>;
    let polling = false;
    const pollForAdminMessages = async () => {
        if (polling) return;
        polling = true;
        try {
            const response = await fetch('../user/chat_message_poll.php', { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            const result = await response.json();
            if (result.latestAdminMessageId > latestAdminMessageId) {
                const input = document.getElementById('chatMessageInput');
                if (!input || input.value.trim() === '') window.location.reload();
            }
        } catch (error) {
            console.error('Unable to refresh customer chat.', error);
        } finally {
            polling = false;
        }
    };
    window.setInterval(pollForAdminMessages, 3000);
})();
</script>
<?php endif; ?>

<!-- Modal para sa Accept & Send Medicine -->
<?php if ($isStaff): ?>
<div id="acceptSendModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:10px;padding:20px;width:100%;max-width:500px;box-shadow:0 10px 40px rgba(0,0,0,0.2);max-height:90vh;overflow-y:auto;">
        <h2 style="margin:0 0 15px;font-size:18px;color:#20232a;">Accept Prescription & Send Medicine</h2>
        
        <!-- Show Prescription Image -->
        <div id="prescriptionImageContainer" style="margin-bottom:15px;text-align:center;">
            <p style="color:#7b8189;font-size:11px;margin-bottom:8px;">Prescription from customer:</p>
            <img id="modalPrescriptionImage" src="" alt="Prescription" style="max-width:100%;max-height:250px;border:1px solid #e1e5eb;border-radius:8px;">
        </div>
        
        <form id="acceptSendForm" method="post">
            <input type="hidden" name="action" value="review_prescription">
            <input type="hidden" name="decision" value="accepted">
            <input type="hidden" name="chat_id" id="modalChatId">
            <input type="hidden" name="user_id" id="modalUserId">
            
            <label style="display:block;margin-bottom:8px;color:#626a74;font-size:12px;font-weight:600;">Medicine Name (from prescription):</label>
            <input type="text" id="modalMedicineName" name="medicine_name" placeholder="e.g., Amoxicillin 500mg - 1 cap, 3x daily" style="width:100%;padding:8px;border:1px solid #dfe4e9;border-radius:6px;margin-bottom:15px;font-size:11px;" required>
            
            <label for="modalQuantityLimit" style="display:block;margin-bottom:8px;color:#626a74;font-size:12px;font-weight:600;">Quantity available for this prescription:</label>
            <input type="number" id="modalQuantityLimit" name="quantity_limit" min="1" value="1" required style="width:100%;padding:8px;border:1px solid #dfe4e9;border-radius:6px;margin-bottom:15px;font-size:11px;">

            <label for="modalAccessValidUntil" style="display:block;margin-bottom:8px;color:#626a74;font-size:12px;font-weight:600;">Prescription access valid until:</label>
            <input type="date" id="modalAccessValidUntil" name="access_valid_until" min="<?php echo date('Y-m-d'); ?>" required style="width:100%;padding:8px;border:1px solid #dfe4e9;border-radius:6px;margin-bottom:15px;font-size:11px;">

                        <label style="display:block;margin-bottom:8px;color:#626a74;font-size:12px;font-weight:600;">Select Product (will send product image):</label>
            <select id="modalProductId" name="product_id" required style="width:100%;padding:8px;border:1px solid #dfe4e9;border-radius:6px;margin-bottom:15px;">
                <option value="">-- Choose product --</option>
                <?php if ($prescriptionProducts): 
                    $prescriptionProducts = $conn->query("SELECT id, product_name FROM products WHERE prescription_required = 1 ORDER BY product_name ASC");
                    while ($product = $prescriptionProducts->fetch_assoc()): 
                ?>
                    <option value="<?php echo (int)$product['id']; ?>"><?php echo htmlspecialchars($product['product_name']); ?></option>
                <?php endwhile; endif; ?>
            </select>
            
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" id="cancelAcceptSend" style="padding:8px 16px;border:1px solid #dfe4e9;background:#fff;color:#555e68;border-radius:6px;cursor:pointer;font-size:11px;">Cancel</button>
                <button type="button" id="confirmAcceptSend" style="padding:8px 16px;border:1px solid #ed1b3b;background:#ed1b3b;color:#fff;border-radius:6px;cursor:pointer;font-size:11px;font-weight:700;">Accept & Send</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('acceptSendModal');
    const acceptSendButtons = document.querySelectorAll('.accept-and-send-btn');
    const cancelBtn = document.getElementById('cancelAcceptSend');
    const confirmBtn = document.getElementById('confirmAcceptSend');
    const modalChatId = document.getElementById('modalChatId');
    const modalUserId = document.getElementById('modalUserId');
    const modalProductId = document.getElementById('modalProductId');
    const modalMedicineName = document.getElementById('modalMedicineName');
    const modalQuantityLimit = document.getElementById('modalQuantityLimit');
    const modalAccessValidUntil = document.getElementById('modalAccessValidUntil');
    const modalPrescriptionImage = document.getElementById('modalPrescriptionImage');
    const completePrescriptionChatIds = <?php echo json_encode($completePrescriptionChatIds); ?>;
    const prescriptionDetailsByChatId = <?php echo json_encode($prescriptionDetailsByChatId); ?>;

    document.querySelectorAll('.accept-and-send-btn').forEach(function(button) {
        const chatId = Number(button.dataset.chatId);
        if (!completePrescriptionChatIds.includes(chatId)) {
            button.disabled = true;
            button.title = 'Prescription image, ID, and date are required before acceptance.';
            const reviewActions = button.closest('.review-actions');
            if (reviewActions) {
                reviewActions.insertAdjacentHTML('beforebegin', '<span class="missing-prescription-details">Cannot accept: prescription ID and date are missing.</span>');
            }
        } else if (prescriptionDetailsByChatId[chatId]) {
            const review = button.closest('.prescription-review');
            if (review) {
                review.insertAdjacentHTML('afterbegin', '<div>Prescription ID: <strong>' + prescriptionDetailsByChatId[chatId].number + '</strong> | Date: <strong>' + prescriptionDetailsByChatId[chatId].date + '</strong></div>');
            }
        }
    });
    
    // Open modal when Accept & Send is clicked
    acceptSendButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            modalChatId.value = this.getAttribute('data-chat-id');
            modalUserId.value = this.getAttribute('data-user-id');
            modalMedicineName.value = '';
            modalProductId.value = '';
            modalQuantityLimit.value = '1';
            modalAccessValidUntil.value = '';
            
            // Find and display the prescription image from this chat
            const chatBubble = this.closest('article');
            if (chatBubble) {
                const prescriptionImg = chatBubble.querySelector('.prescription');
                if (prescriptionImg) {
                    modalPrescriptionImage.src = prescriptionImg.src;
                    modalPrescriptionImage.style.display = 'block';
                }
            }
            
            modal.style.display = 'flex';
        });
    });
    
    // Close modal
    cancelBtn.addEventListener('click', function() {
        modal.style.display = 'none';
    });
    
    // Close modal when clicking outside
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
    
    // Confirm and send
    confirmBtn.addEventListener('click', function() {
        if (!modalProductId.value) {
            alert('Please select a product');
            return;
        }
        if (!modalMedicineName.value) {
            alert('Please enter the medicine name from the prescription');
            return;
        }
        if (!Number.isInteger(Number(modalQuantityLimit.value)) || Number(modalQuantityLimit.value) < 1) {
            alert('Enter a valid quantity limit for this prescription.');
            return;
        }
        if (!modalAccessValidUntil.reportValidity()) {
            return;
        }
        
        const userId = modalUserId.value;
        const productId = modalProductId.value;
        const medicineName = modalMedicineName.value;
        
        // Step 1: Accept the prescription
        const acceptForm = document.getElementById('acceptSendForm');
        const formData = new FormData(acceptForm);
        
        fetch('customer_chat.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            modal.style.display = 'none';
            location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error processing request');
        });
    });
});
</script>
<?php endif; ?>

<?php if ($role === 'user'): ?>
<script>
const chatPrescriptionImage = document.getElementById('chatPrescriptionImage');
const chatPrescriptionMeta = document.getElementById('chatPrescriptionMeta');
const chatPrescriptionNumber = document.getElementById('chatPrescriptionNumber');
const chatPrescriptionDate = document.getElementById('chatPrescriptionDate');
const chatFileName = document.getElementById('chatFileName');
const togglePrescriptionDetails = document.getElementById('togglePrescriptionDetails');
const chatComposer = document.querySelector('.chat-composer');

const setPrescriptionDetailsVisible = (visible) => {
    chatPrescriptionMeta.hidden = !visible;
    chatPrescriptionNumber.required = visible;
    chatPrescriptionDate.required = visible;
    togglePrescriptionDetails.setAttribute('aria-expanded', String(visible));
};

togglePrescriptionDetails.addEventListener('click', () => {
    const shouldShow = chatPrescriptionMeta.hidden;
    setPrescriptionDetailsVisible(shouldShow);
    if (shouldShow) chatPrescriptionNumber.focus();
});

chatPrescriptionImage.addEventListener('change', () => {
    const selectedFile = chatPrescriptionImage.files[0];
    chatFileName.textContent = selectedFile ? ' · ' + selectedFile.name : '';
    setPrescriptionDetailsVisible(Boolean(selectedFile));
});

chatComposer.addEventListener('submit', (event) => {
    if (chatPrescriptionImage.files.length && (!chatPrescriptionNumber.value.trim() || !chatPrescriptionDate.value)) {
        event.preventDefault();
        setPrescriptionDetailsVisible(true);
        chatPrescriptionNumber.reportValidity();
        chatPrescriptionDate.reportValidity();
    }
});

</script>
<?php endif; ?>
<?php if ($role === 'user'): ?>
<script>
document.querySelectorAll('.chat-shared-product').forEach((card) => {
    const controls = card.querySelector('.chat-shared-product-controls');
    if (!controls) return;

    const quantityInput = controls.querySelector('[data-role="quantity"]');
    const decreaseButton = controls.querySelector('[data-role="decrease"]');
    const increaseButton = controls.querySelector('[data-role="increase"]');
    const addButton = controls.querySelector('[data-role="add"]');
    const buyButton = controls.querySelector('[data-role="buy"]');
    const feedback = controls.querySelector('[data-role="feedback"]');
    const stock = Number.parseInt(card.dataset.stock, 10) || 0;
    const orderable = card.dataset.orderable === '1';
    const getMaximum = () => stock;

    const updateControls = () => {
        const quantity = Number.parseInt(quantityInput.value, 10);
        const maximum = getMaximum();
        const valid = orderable && Number.isInteger(quantity) && quantity >= 1 && quantity <= maximum;
        quantityInput.max = String(maximum);
        decreaseButton.disabled = !Number.isInteger(quantity) || quantity <= 1;
        increaseButton.disabled = !Number.isInteger(quantity) || quantity >= maximum;
        addButton.disabled = !valid;
        buyButton.disabled = !valid;
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
    quantityInput.addEventListener('input', () => {
        feedback.textContent = '';
        feedback.classList.remove('success');
    });
    addButton.addEventListener('click', async () => {
        updateControls();
        if (addButton.disabled) return;
        addButton.disabled = true;
        feedback.textContent = '';
        feedback.classList.remove('success');
        const formData = new FormData();
        formData.set('product_id', card.dataset.productId);
        formData.set('quantity', quantityInput.value);
        formData.set('unit', 'piece');
        try {
            const response = await fetch('../user/add_to_cart.php', {
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
            feedback.textContent = 'Added to cart.';
            feedback.classList.add('success');
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
            product_id: card.dataset.productId,
            quantity: quantityInput.value,
            unit: 'piece'
        });
        window.location.href = '../user/receipt.php?' + params.toString();
    });
    updateControls();
});
</script>
<?php endif; ?>
<?php if ($isStaff): ?>
<script>
const sharedProductSelect = document.getElementById('chatSharedProductId');
const sharedAccessDate = document.getElementById('chatAccessValidUntil');
const sharedAccessDateField = document.getElementById('chatAccessDateField');
const sharedQuantityLimit = document.getElementById('chatQuantityLimit');
const sharedProductNote = document.getElementById('chatShareNote');
const staffChatComposer = document.querySelector('.chat-composer');

if (sharedProductSelect && sharedAccessDate && sharedAccessDateField && sharedQuantityLimit && sharedProductNote && staffChatComposer) {
    const syncSharedAccessDate = () => {
        const selectedOption = sharedProductSelect.selectedOptions[0];
        const isPrescriptionProduct = Boolean(sharedProductSelect.value);
        const availableStock = isPrescriptionProduct ? Number(selectedOption.dataset.stock || 0) : 0;
        sharedAccessDate.required = isPrescriptionProduct;
        sharedQuantityLimit.required = isPrescriptionProduct;
        sharedQuantityLimit.setCustomValidity('');
        sharedAccessDateField.classList.toggle('visible', isPrescriptionProduct);
        if (isPrescriptionProduct) {
            sharedQuantityLimit.max = String(availableStock);
            sharedQuantityLimit.placeholder = `Quantity limit (max ${availableStock})`;
            sharedProductNote.textContent = `Available stock: ${availableStock} pieces. Set a quantity within this stock and choose the last access date.`;
        } else {
            sharedQuantityLimit.removeAttribute('max');
            sharedQuantityLimit.placeholder = 'Quantity limit';
            sharedProductNote.textContent = 'Choose a prescription item to set its purchase quantity and valid-until date.';
        }
    };

    syncSharedAccessDate();
    sharedProductSelect.addEventListener('change', syncSharedAccessDate);
    staffChatComposer.addEventListener('submit', (event) => {
        syncSharedAccessDate();
        if (sharedProductSelect.value) {
            const selectedOption = sharedProductSelect.selectedOptions[0];
            const availableStock = Number(selectedOption.dataset.stock || 0);
            const requestedQuantity = Number(sharedQuantityLimit.value);
            if (!requestedQuantity || requestedQuantity < 1 || requestedQuantity > availableStock) {
                event.preventDefault();
                sharedQuantityLimit.setCustomValidity(`Enter a quantity from 1 to ${availableStock}.`);
                sharedQuantityLimit.reportValidity();
                sharedQuantityLimit.addEventListener('input', () => sharedQuantityLimit.setCustomValidity(''), { once: true });
                return;
            }
            sharedQuantityLimit.setCustomValidity('');
            if (!sharedAccessDate.value) {
                event.preventDefault();
                sharedAccessDate.reportValidity();
            }
        }
    });
}
</script>
<?php endif; ?>

</body>
</html>
