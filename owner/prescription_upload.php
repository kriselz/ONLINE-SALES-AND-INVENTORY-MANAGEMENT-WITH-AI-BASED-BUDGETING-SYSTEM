<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header('Location: login_a.php');
    exit();
}

$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS prescription_image VARCHAR(255) NULL");
$conn->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS prescription_status VARCHAR(20) NOT NULL DEFAULT 'none'");
$conn->query("CREATE TABLE IF NOT EXISTS customer_chat_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    sender_role VARCHAR(10) NOT NULL DEFAULT 'user',
    message TEXT NULL,
    prescription_image VARCHAR(255) NULL,
    prescription_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    prescription_reviewed_at DATETIME NULL,
    prescription_reviewed_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    order_id INT NULL,
    INDEX idx_chat_user (user_id),
    INDEX idx_chat_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("ALTER TABLE customer_chat_messages ADD COLUMN IF NOT EXISTS order_id INT NULL");

$userId = (int)$_SESSION['user_id'];
$orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$message = '';
$error = '';

$order = null;
if ($orderId > 0) {
    $orderStmt = $conn->prepare('SELECT id, prescription_image, prescription_status FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
    if ($orderStmt) {
        $orderStmt->bind_param('ii', $orderId, $userId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        $orderStmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order) {
    $file = $_FILES['prescription_image'] ?? null;
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $maxBytes = 5 * 1024 * 1024;

    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Please choose a prescription image.';
    } elseif ((int)$file['size'] > $maxBytes) {
        $error = 'The prescription image must be 5 MB or smaller.';
    } elseif (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid upload.';
    } else {
        $mimeType = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
        if (!in_array($mimeType, $allowedMimeTypes, true) || @getimagesize($file['tmp_name']) === false) {
            $error = 'Only JPG, PNG, and WEBP images are accepted.';
        } else {
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mimeType];
            $fileName = 'prescription_' . $orderId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $uploadDirectory = __DIR__ . '/../user/uploads/prescriptions';
            if (!is_dir($uploadDirectory)) {
                @mkdir($uploadDirectory, 0755, true);
            }
            if (move_uploaded_file($file['tmp_name'], $uploadDirectory . '/' . $fileName)) {
                $update = $conn->prepare("UPDATE orders SET prescription_image = ?, prescription_status = 'pending', prescription_reviewed_at = NULL, prescription_reviewed_by = NULL WHERE id = ? AND user_id = ?");
                if ($update) {
                    $update->bind_param('sii', $fileName, $orderId, $userId);
                    $update->execute();
                    $update->close();
                    $chatMessage = 'Prescription submitted for order ' . formatOrderNumber($conn, $orderId) . '.';
                    $chatImage = 'prescriptions/' . $fileName;
                    $chatInsert = $conn->prepare('INSERT INTO customer_chat_messages (user_id, sender_role, message, prescription_image, prescription_status, order_id) VALUES (?, ?, ?, ?, ?, ?)');
                    if ($chatInsert) {
                        $senderRole = 'user';
                        $chatStatus = 'pending';
                        $chatInsert->bind_param('issssi', $userId, $senderRole, $chatMessage, $chatImage, $chatStatus, $orderId);
                        $chatInsert->execute();
                        $chatInsert->close();
                    }
                    $notice = 'A prescription was uploaded for order ' . formatOrderNumber($conn, $orderId) . '.';
                    $noticeStmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (NULL, ?, NOW())");
                    if ($noticeStmt) {
                        $noticeStmt->bind_param('s', $notice);
                        $noticeStmt->execute();
                        $noticeStmt->close();
                    }
                    $message = 'Prescription sent. The owner will review it shortly.';
                    $order['prescription_image'] = $fileName;
                    $order['prescription_status'] = 'pending';
                } else {
                    $error = 'Unable to save the prescription record.';
                }
            } else {
                $error = 'Unable to save the uploaded image.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload Prescription</title>
<style>body{margin:0;background:#f5f7fb;color:#20232a;font-family:Arial,sans-serif}.box{width:min(520px,calc(100% - 30px));margin:60px auto;padding:24px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 8px 25px rgba(25,30,38,.08)}h1{margin:0 0 8px;font-size:22px}p{color:#68707a;font-size:13px;line-height:1.5}.notice{padding:11px;border-radius:6px;margin:14px 0;font-size:13px}.success{background:#e9f8ef;color:#278658}.error{background:#ffe8ed;color:#db294b}label{display:block;margin:18px 0 7px;font-size:12px;font-weight:700}input[type=file]{width:100%;padding:10px;border:1px solid #dfe4e9;border-radius:6px;box-sizing:border-box}button{margin-top:16px;padding:10px 14px;border:0;border-radius:6px;background:#ed1740;color:#fff;font-weight:700;cursor:pointer}.preview{max-width:100%;max-height:260px;margin-top:14px;border:1px solid #e5e7eb;border-radius:6px}.status{font-weight:700;color:#e32248}</style>
</head>
<body>
<main class="box">
<h1>Upload Prescription</h1>
<?php if (!$order): ?>
<p>Order not found or you do not have access to this order.</p>
<?php else: ?>
<p>Order <strong><?php echo htmlspecialchars(formatOrderNumber($conn, $orderId), ENT_QUOTES, 'UTF-8'); ?></strong>. Upload a clear JPG, PNG, or WEBP photo of your prescription.</p>
<?php if ($message): ?><div class="notice success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<p>Review status: <span class="status"><?php echo htmlspecialchars(ucfirst($order['prescription_status'] ?: 'none')); ?></span></p>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="order_id" value="<?php echo $orderId; ?>">
<label for="prescription_image">Prescription image</label>
<input id="prescription_image" type="file" name="prescription_image" accept="image/jpeg,image/png,image/webp" required>
<button type="submit">Send Prescription</button>
</form>
<?php if (!empty($order['prescription_image'])): ?><img class="preview" src="../user/uploads/prescriptions/<?php echo rawurlencode(basename($order['prescription_image'])); ?>" alt="Current prescription image"><?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
