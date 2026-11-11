<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];
$flashNotice = $_SESSION['prescription_flash_notice'] ?? null;
unset($_SESSION['prescription_flash_notice']);
$notice = is_array($flashNotice) ? (string)($flashNotice['message'] ?? '') : '';
$noticeType = is_array($flashNotice) && ($flashNotice['type'] ?? '') === 'danger' ? 'danger' : 'success';
$conn->query('ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS valid_id_original_name VARCHAR(255) NULL');
$conn->query('ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS valid_id_file_name VARCHAR(255) NULL');
$conn->query("ALTER TABLE prescriptions MODIFY status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending'");
$conn->query('ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS shared_product_id INT NULL');
$conn->query('ALTER TABLE prescriptions ADD COLUMN IF NOT EXISTS product_access_expires_at DATETIME NULL');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_prescription'])) {
    $file = $_FILES['prescription'] ?? null;
    $validIdFile = $_FILES['valid_id'] ?? null;
    $allowedTypes = ['image/jpeg', 'image/png'];
    $maxSize = 10 * 1024 * 1024;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $notice = 'Please choose a prescription JPG or PNG image.';
        $noticeType = 'danger';
    } elseif (!$validIdFile || $validIdFile['error'] !== UPLOAD_ERR_OK) {
        $notice = 'Please choose a valid ID image.';
        $noticeType = 'danger';
    } elseif ($file['size'] > $maxSize) {
        $notice = 'The file must be 10 MB or smaller.';
        $noticeType = 'danger';
    } elseif ($validIdFile['size'] > $maxSize) {
        $notice = 'The valid ID file must be 10 MB or smaller.';
        $noticeType = 'danger';
    } elseif (!in_array(mime_content_type($file['tmp_name']), $allowedTypes, true) || !in_array(mime_content_type($validIdFile['tmp_name']), $allowedTypes, true)) {
        $notice = 'Only JPG and PNG files are accepted for the prescription and valid ID.';
        $noticeType = 'danger';
    } else {
        $uploadDirectory = __DIR__ . '/uploads/prescriptions';
        if (!is_dir($uploadDirectory)) {
            mkdir($uploadDirectory, 0755, true);
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $validIdExtension = strtolower(pathinfo($validIdFile['name'], PATHINFO_EXTENSION));
        $storedName = 'prescription_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $validIdStoredName = 'valid_id_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $validIdExtension;
        $storedPath = $uploadDirectory . '/' . $storedName;
        $validIdStoredPath = $uploadDirectory . '/' . $validIdStoredName;
        $notes = trim($_POST['notes'] ?? '');

        if (move_uploaded_file($file['tmp_name'], $storedPath) && move_uploaded_file($validIdFile['tmp_name'], $validIdStoredPath)) {
            $insert = $conn->prepare('INSERT INTO prescriptions (user_id, original_name, file_name, valid_id_original_name, valid_id_file_name, notes) VALUES (?, ?, ?, ?, ?, ?)');
            if ($insert) {
                $insert->bind_param('isssss', $userId, $file['name'], $storedName, $validIdFile['name'], $validIdStoredName, $notes);
                if ($insert->execute()) {
                    $notice = 'Prescription submitted successfully and is pending review.';
                } else {
                    error_log('Prescription insert failed for user ' . $userId . ': ' . $insert->error);
                    @unlink($storedPath);
                    @unlink($validIdStoredPath);
                    $notice = 'The prescription could not be saved. Please sign out, sign back in, and try again.';
                    $noticeType = 'danger';
                }
                $insert->close();
            } else {
                @unlink($storedPath);
                @unlink($validIdStoredPath);
                $notice = 'The prescription table is not ready. Import system.sql from the capstone folder first.';
                $noticeType = 'danger';
            }
        } else {
            @unlink($storedPath);
            @unlink($validIdStoredPath);
            $notice = 'The prescription could not be uploaded. Please try again.';
            $noticeType = 'danger';
        }

    }
    $_SESSION['prescription_flash_notice'] = [
        'message' => $notice,
        'type' => $noticeType,
    ];
    $redirectParams = [];
    $requestedFilter = (string)($_GET['filter'] ?? '');
    if (in_array($requestedFilter, ['all', 'pending', 'approved', 'rejected', 'completed'], true)) {
        $redirectParams['filter'] = $requestedFilter;
    }
    $requestedPage = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT);
    if ($requestedPage !== false && $requestedPage !== null && $requestedPage > 1) {
        $redirectParams['page'] = $requestedPage;
    }
    $redirectUrl = 'support.php' . ($redirectParams ? '?' . http_build_query($redirectParams) : '');
    header('Location: ' . $redirectUrl, true, 303);
    exit();
}

$filter = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'pending', 'approved', 'rejected', 'completed'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

$prescriptionsPerPage = 10;
$prescriptionPage = max(1, (int)($_GET['page'] ?? 1));
$prescriptionFilterSql = 'user_id = ?';
$prescriptionCountTypes = 'i';
$prescriptionCountParams = [$userId];
if ($filter !== 'all') {
    $prescriptionFilterSql .= ' AND status = ?';
    $prescriptionCountTypes .= 's';
    $prescriptionCountParams[] = $filter;
}
$prescriptionCountStmt = $conn->prepare("SELECT COUNT(*) AS total FROM prescriptions WHERE $prescriptionFilterSql");
$filteredPrescriptionCount = 0;
if ($prescriptionCountStmt) {
    $prescriptionCountStmt->bind_param($prescriptionCountTypes, ...$prescriptionCountParams);
    if ($prescriptionCountStmt->execute()) {
        $filteredPrescriptionCount = (int)($prescriptionCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
    } else {
        error_log('Prescription list count query failed: ' . $prescriptionCountStmt->error);
    }
    $prescriptionCountStmt->close();
} else {
    error_log('Prescription list count query preparation failed: ' . $conn->error);
}
$prescriptionTotalPages = max(1, (int)ceil($filteredPrescriptionCount / $prescriptionsPerPage));
$prescriptionPage = min($prescriptionPage, $prescriptionTotalPages);
$prescriptionOffset = ($prescriptionPage - 1) * $prescriptionsPerPage;
$prescriptionPageItems = [];
if ($prescriptionTotalPages <= 5) {
    $prescriptionPageItems = range(1, $prescriptionTotalPages);
} elseif ($prescriptionPage <= 3) {
    $prescriptionPageItems = [1, 2, 3, '...', $prescriptionTotalPages];
} elseif ($prescriptionPage >= $prescriptionTotalPages - 2) {
    $prescriptionPageItems = [1, '...', $prescriptionTotalPages - 2, $prescriptionTotalPages - 1, $prescriptionTotalPages];
} else {
    $prescriptionPageItems = [1, '...', $prescriptionPage - 1, $prescriptionPage, $prescriptionPage + 1, '...', $prescriptionTotalPages];
}

$prescriptions = false;
$query = "SELECT * FROM prescriptions WHERE $prescriptionFilterSql";
$query .= ' ORDER BY submitted_at DESC LIMIT ? OFFSET ?';
$listStmt = $conn->prepare($query);
if ($listStmt) {
    if ($filter === 'all') {
        $listStmt->bind_param('iii', $userId, $prescriptionsPerPage, $prescriptionOffset);
    } else {
        $listStmt->bind_param('isii', $userId, $filter, $prescriptionsPerPage, $prescriptionOffset);
    }
    if ($listStmt->execute()) {
        $prescriptions = $listStmt->get_result();
    } else {
        error_log('Prescription list query failed: ' . $listStmt->error);
    }
    $listStmt->close();
}

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'completed' => 0];
$countStmt = $conn->prepare('SELECT status, COUNT(*) AS total FROM prescriptions WHERE user_id = ? GROUP BY status');
if ($countStmt) {
    $countStmt->bind_param('i', $userId);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    while ($count = $countResult->fetch_assoc()) {
        $counts[$count['status']] = (int)$count['total'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescriptions</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined">
    <style>
        :root { --primary:#8B1E2D; --dark:#6F1724; --soft:#FBEAEC; --ink:#1f2937; --muted:#6b7280; }
        * { box-sizing:border-box; }
        body { margin:0; background:#f8f9fb; color:var(--ink); font-family:Arial, 'Segoe UI', sans-serif; }
        .main-content { margin-left:260px; min-height:100vh; padding:32px 46px 48px; }
        .page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:24px; margin-bottom:28px; }
        h1 { margin:0 0 8px; font-size:35px; letter-spacing:-.4px; }
        .intro { margin:0; color:var(--muted); font-size:14px; max-width:620px; line-height:1.5; }
        .primary-button { display:inline-flex; align-items:center; gap:8px; border:0; border-radius:7px; background:var(--primary); color:#fff; padding:12px 18px; font-weight:700; cursor:pointer; white-space:nowrap; box-shadow:0 4px 12px rgba(139,30,45,.18); }
        .primary-button:hover { background:var(--dark); }
        .notice { margin-bottom:18px; padding:13px 16px; border-radius:8px; background:#eaf7ee; color:#25713b; }
        .notice.danger { background:var(--soft); color:var(--dark); }
        .panel { background:#fff; border:1px solid #e8eaee; border-radius:10px; padding:22px; box-shadow:0 3px 14px rgba(30,41,59,.04); }
        .panel-title { margin:0 0 18px; font-size:18px; }
        .filters { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
        .filter { display:inline-flex; align-items:center; gap:7px; border:1px solid #e3e6eb; border-radius:6px; padding:9px 15px; color:#374151; text-decoration:none; font-size:13px; font-weight:600; }
        .filter.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .count-dot { width:7px; height:7px; border-radius:50%; background:#e5a320; }
        .approved .count-dot { background:#2e8b57; } .rejected .count-dot { background:var(--primary); }
        .prescription-list { display:flex; flex-direction:column; gap:12px; }
        .prescription-pager { display:flex; align-items:center; justify-content:center; gap:10px; flex-wrap:wrap; margin:20px auto 0; }
        .prescription-pager-link { display:inline-flex; min-width:43px; height:42px; align-items:center; justify-content:center; padding:0 10px; border:1px solid #e0e6f0; border-radius:10px; background:#fff; color:#4b5563; font-size:16px; font-weight:600; text-decoration:none; transition:all .2s ease; }
        .prescription-pager-link:hover { border-color:#d32f2f; background:#fff5f5; color:#d32f2f; }
        .prescription-pager-link.active { border-color:#d32f2f; background:#d32f2f; color:#fff; box-shadow:0 4px 12px rgba(211,47,47,.2); }
        .prescription-pager-link.disabled { border:0; background:none; color:#010813; pointer-events:none; }
        .prescription { display:flex; align-items:center; gap:18px; min-height:112px; padding:18px 20px; border:1px solid #edf0f3; border-radius:8px; color:inherit; text-decoration:none; transition:border-color .2s, box-shadow .2s; }
        .prescription:hover { border-color:var(--primary); box-shadow:0 4px 12px rgba(139,30,45,.08); }
        .prescription.approved-card { border-color:#a9d8ba; }
        .status-icon { width:42px; height:42px; border-radius:50%; display:grid; place-items:center; background:#eaf7ee; color:#2e8b57; flex:0 0 42px; }
        .status-icon.pending { background:#fff5df; color:#d89b16; } .status-icon.rejected { background:var(--soft); color:var(--primary); }
        .prescription-info { flex:1; min-width:0; } .prescription-info h3 { margin:0 0 8px; font-size:16px; } .prescription-info p { margin:4px 0; color:var(--muted); font-size:13px; }
        .file-name { display:flex; align-items:center; gap:7px; }
        .status-label { border-radius:5px; padding:6px 9px; font-size:11px; font-weight:700; white-space:nowrap; background:#eaf7ee; color:#287742; }
        .status-icon.completed { background:#edf0f4; color:#667085; } .status-label.completed { background:#edf0f4; color:#667085; }
        .status-label.pending { background:#fff5df; color:#a76e00; } .status-label.rejected { background:var(--soft); color:var(--primary); }
        .empty { text-align:center; padding:48px 20px; color:var(--muted); }
        .modal { display:none; position:fixed; inset:0; z-index:200; background:rgba(20,10,12,.48); place-items:center; padding:20px; } .modal.open { display:grid; }
        .modal-card { width:min(500px,100%); background:#fff; border-radius:10px; padding:24px; } .modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; } .modal-head h2 { margin:0; font-size:20px; } .close { border:0; background:transparent; font-size:24px; cursor:pointer; color:var(--muted); }
        label { display:block; margin:14px 0 6px; font-size:13px; font-weight:700; } input[type=file], input[type=number], textarea { width:100%; padding:11px; border:1px solid #dfe3e8; border-radius:6px; font:inherit; } textarea { min-height:92px; resize:vertical; } .help { color:var(--muted); font-size:12px; margin-top:6px; }
        @media (max-width:720px) { .main-content { margin-left:0; padding:24px 18px; } .page-header { flex-direction:column; } .primary-button { align-self:stretch; justify-content:center; } .prescription { align-items:flex-start; } }
    </style>
</head>
<body>
<?php include 'sidebar_user.php'; ?>
<main class="main-content">
    <header class="page-header">
        <div><h1>Prescriptions</h1><p class="intro">Upload and manage your prescriptions. Our pharmacy staff will review and verify your prescription to process your order.</p></div>
        <button class="primary-button" type="button" id="openPrescriptionModal"><span class="material-icons-outlined">add</span> Submit New Prescription</button>
    </header>

    <?php if ($notice): ?><div class="notice <?= $noticeType === 'danger' ? 'danger' : '' ?>"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

    <section class="panel">
        <h2 class="panel-title">My Prescriptions</h2>
        <nav class="filters" aria-label="Prescription filters">
            <a class="filter <?= $filter === 'all' ? 'active' : '' ?>" href="support.php?filter=all">All</a>
            <a class="filter <?= $filter === 'pending' ? 'active' : '' ?>" href="support.php?filter=pending">Pending <span class="count-dot"></span><?= $counts['pending'] ?></a>
            <a class="filter approved <?= $filter === 'approved' ? 'active' : '' ?>" href="support.php?filter=approved">Approved <span class="count-dot"></span><?= $counts['approved'] ?></a>
            <a class="filter rejected <?= $filter === 'rejected' ? 'active' : '' ?>" href="support.php?filter=rejected">Rejected <span class="count-dot"></span><?= $counts['rejected'] ?></a>
            <a class="filter completed <?= $filter === 'completed' ? 'active' : '' ?>" href="support.php?filter=completed">Completed <span class="count-dot"></span><?= $counts['completed'] ?></a>
        </nav>
        <div class="prescription-list">
            <?php if ($prescriptions && $prescriptions->num_rows > 0): while ($prescription = $prescriptions->fetch_assoc()):
                $status = strtolower($prescription['status']);
                $statusTitle = $status === 'pending' ? 'Pending Review' : ucfirst($status);
                $dateLabel = $status === 'pending' ? 'Submitted' : ($status === 'completed' ? 'Completed' : 'Reviewed');
            ?>
                <a class="prescription <?= $status === 'approved' ? 'approved-card' : '' ?>" href="prescription_details.php?id=<?= (int)$prescription['id'] ?>">
                    <div class="status-icon <?= $status ?>"><span class="material-icons-outlined"><?= in_array($status, ['approved', 'completed'], true) ? 'check_circle' : ($status === 'rejected' ? 'close' : 'schedule') ?></span></div>
                    <div class="prescription-info"><h3>Prescription #P-<?= str_pad((string)$prescription['id'], 4, '0', STR_PAD_LEFT) ?></h3><p class="file-name"><span class="material-icons-outlined">attach_file</span><?= htmlspecialchars($prescription['original_name']) ?></p><?php if (!empty($prescription['notes'])): ?><p><?= htmlspecialchars($prescription['notes']) ?></p><?php endif; ?><p><?= $dateLabel ?> on <?= date('M d, Y  •  h:i A', strtotime($prescription['submitted_at'])) ?></p></div>
                    <span class="status-label <?= $status ?>"><?= $statusTitle ?></span><span class="material-icons-outlined" style="color:var(--primary)">chevron_right</span>
                </a>
            <?php endwhile; else: ?><div class="empty">You haven't submitted any prescriptions yet.</div><?php endif; ?>
        </div>
        <?php if ($filteredPrescriptionCount > $prescriptionsPerPage): ?>
            <?php $prescriptionPagerUrl = 'support.php?' . http_build_query(['filter' => $filter]) . '&page='; ?>
            <nav class="prescription-pager" aria-label="Prescription pages">
                <?php if ($prescriptionPage > 1): ?><a class="prescription-pager-link" href="<?= htmlspecialchars($prescriptionPagerUrl . ($prescriptionPage - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?>
                <?php foreach ($prescriptionPageItems as $prescriptionPageItem): ?>
                    <?php if ($prescriptionPageItem === '...'): ?>
                        <span class="prescription-pager-link disabled" aria-hidden="true">...</span>
                    <?php else: ?>
                        <a class="prescription-pager-link <?= $prescriptionPageItem === $prescriptionPage ? 'active' : '' ?>" href="<?= htmlspecialchars($prescriptionPagerUrl . $prescriptionPageItem, ENT_QUOTES, 'UTF-8') ?>" <?= $prescriptionPageItem === $prescriptionPage ? 'aria-current="page"' : '' ?>><?= $prescriptionPageItem ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($prescriptionPage < $prescriptionTotalPages): ?><a class="prescription-pager-link" href="<?= htmlspecialchars($prescriptionPagerUrl . ($prescriptionPage + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>

<div class="modal" id="prescriptionModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <form class="modal-card" method="POST" enctype="multipart/form-data">
        <div class="modal-head"><h2 id="modalTitle">Submit New Prescription</h2><button class="close" type="button" id="closePrescriptionModal" aria-label="Close">&times;</button></div>
        <label for="prescription">Prescription file</label><input id="prescription" type="file" name="prescription" accept="image/jpeg,image/png" required><div class="help">Accepted: JPG or PNG. Maximum 10 MB.</div>
        <label for="valid_id">Customer Valid ID</label><input id="valid_id" type="file" name="valid_id" accept="image/jpeg,image/png" required><div class="help">Upload a JPG or PNG image of your valid ID. Maximum 10 MB.</div>
        <label for="notes">Message or notes <span style="font-weight:400;color:var(--muted)">(optional)</span></label><textarea id="notes" name="notes" rows="4" placeholder="Add a message or any details for our pharmacy staff..."></textarea>
        <button class="primary-button" type="submit" name="submit_prescription" style="margin-top:22px;width:100%;justify-content:center">Submit Prescription</button>
    </form>
</div>
<script>
    const prescriptionModal = document.getElementById('prescriptionModal');
    document.getElementById('openPrescriptionModal').addEventListener('click', () => prescriptionModal.classList.add('open'));
    document.getElementById('closePrescriptionModal').addEventListener('click', () => prescriptionModal.classList.remove('open'));
    prescriptionModal.addEventListener('click', event => { if (event.target === prescriptionModal) prescriptionModal.classList.remove('open'); });
</script>
</body>
</html>
