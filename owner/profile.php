<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/access_control.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login_a.php');
    exit();
}
requireOwnerPageAccess($conn, true);

$userId = (int)$_SESSION['user_id'];
$successMessage = '';
$errorMessage = '';

function getProfileImageUrl(?string $image): string
{
    $image = trim((string)$image);

    if ($image === '') {
        return '';
    }

    $imagePath = parse_url($image, PHP_URL_PATH);
    if (strtolower(pathinfo((string)($imagePath ?: $image), PATHINFO_EXTENSION)) === 'gif') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $image)) {
        return $image;
    }

    if (preg_match('#^uploads/#i', $image)) {
        return htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
    }

    return 'uploads/' . rawurlencode(basename($image));
}

function loadUser(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $user ?: null;
}

$user = loadUser($conn, $userId);
if (!$user) {
    http_response_code(404);
    exit('No user found.');
}
$displayName = $user['name'] ?? $user['username'] ?? 'Administrator';
$temporaryPasswordChangeRequired = (int)($user['password_change_required'] ?? 0) === 1;
$temporaryPasswordCsrfToken = $_SESSION['temporary_password_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['temporary_password_csrf'] = $temporaryPasswordCsrfToken;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload'])) {
    $file = $_FILES['profile_pic'] ?? null;

    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errorMessage = 'Please choose an image before uploading.';
    } elseif (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errorMessage = 'The image upload failed. Please try again.';
    } elseif (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $errorMessage = 'The profile image must not exceed 5 MB.';
    } else {
        $imageInfo = @getimagesize($file['tmp_name']);
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $mimeType = $imageInfo['mime'] ?? '';

        if (!$imageInfo || !isset($allowedTypes[$mimeType])) {
            $errorMessage = 'Please upload a valid JPG, PNG, or WEBP image.';
        } else {
            $uploadDirectory = __DIR__ . '/uploads';
            if (!is_dir($uploadDirectory)) {
                @mkdir($uploadDirectory, 0755, true);
            }

            $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
            $destination = $uploadDirectory . '/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $stmt = $conn->prepare('UPDATE users SET profile_pic = ? WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('si', $filename, $userId);
                    $saved = $stmt->execute();
                    $stmt->close();

                    if ($saved) {
                        writeAuditLog($conn, 'profile_updated', 'user', $userId, ['changed' => ['profile_picture']]);
                        header('Location: profile.php?success=picture');
                        exit();
                    }
                }
            }

            $errorMessage = 'The profile image could not be saved. Please check the uploads folder permission.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $temporaryPassword = (string)($_POST['temporary_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $passwordUpdateRequested = $temporaryPasswordChangeRequired;

    if (!hash_equals($temporaryPasswordCsrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $errorMessage = 'Session expired. Refresh the page and try again.';
    } elseif ($fullname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please provide a valid full name and email address.';
    } elseif ($passwordUpdateRequested && ($temporaryPassword === '' || $newPassword === '' || $confirmPassword === '')) {
        $errorMessage = 'Enter the temporary password, new password, and confirmation to complete the required password reset.';
    } elseif ($passwordUpdateRequested && !(
        password_verify($temporaryPassword, (string)($user['password'] ?? ''))
        || (strlen((string)($user['password'] ?? '')) === 32 && hash_equals((string)$user['password'], md5($temporaryPassword)))
    )) {
        $errorMessage = 'The temporary password is incorrect or has already been used.';
    } elseif ($passwordUpdateRequested && strlen($newPassword) < 8) {
        $errorMessage = 'The new password must be at least 8 characters long.';
    } elseif ($passwordUpdateRequested && !hash_equals($newPassword, $confirmPassword)) {
        $errorMessage = 'The new password and confirmation do not match.';
    } elseif ($passwordUpdateRequested && hash_equals($temporaryPassword, $newPassword)) {
        $errorMessage = 'Your new password must be different from the temporary password.';
    } else {
        if ($passwordUpdateRequested) {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET username = ?, email = ?, password = ?, password_change_required = 0 WHERE id = ? AND password = ? AND password_change_required = 1');
            if ($stmt) {
                $storedPassword = (string)$user['password'];
                $stmt->bind_param('sssis', $fullname, $email, $newPasswordHash, $userId, $storedPassword);
            }
        } else {
            $stmt = $conn->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('ssi', $fullname, $email, $userId);
            }
        }

        if (isset($stmt) && $stmt && $stmt->execute() && (!$passwordUpdateRequested || $stmt->affected_rows === 1)) {
            $stmt->close();
            if ($passwordUpdateRequested) {
                writeAuditLog($conn, 'password_changed', 'user', $userId);
                writeAuditLog($conn, 'profile_updated', 'user', $userId, ['changed' => ['username', 'email']]);
                unset($_SESSION['password_change_required']);
            } else {
                writeAuditLog($conn, 'profile_updated', 'user', $userId, ['changed' => ['username', 'email']]);
            }
            header('Location: profile.php?success=' . ($passwordUpdateRequested ? 'password' : 'profile'));
            exit();
        }

        if (isset($stmt) && $stmt) {
            $stmt->close();
        }
        $errorMessage = $passwordUpdateRequested
            ? 'Unable to save your profile and change the password. The temporary password may have already been used.'
            : 'Your profile could not be updated. Please try again.';
    }
}

$user = loadUser($conn, $userId) ?: $user;
$displayName = $user['name'] ?? $user['username'] ?? 'Administrator';
$profileImage = getProfileImageUrl($user['profile_pic'] ?? '');
$profileInitials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$displayName), 0, 2)) ?: 'U';
$memberSince = !empty($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : '—';
$userStatus = trim((string)($user['status'] ?? 'Active')) ?: 'Active';
$userRole = trim((string)($user['role'] ?? 'Administrator')) ?: 'Administrator';
$successCode = $_GET['success'] ?? '';
if ($successCode === 'picture') {
    $successMessage = 'Profile picture updated successfully.';
} elseif ($successCode === 'profile' || $successCode === '1') {
    $successMessage = 'Profile details updated successfully.';
} elseif ($successCode === 'password') {
    $successMessage = 'Profile details saved and password changed. Your temporary password can no longer be used.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Admin Dashboard</title>
    <link rel="stylesheet" href="shared_styles.css">
    <link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-2">
    <style>
        :root {
            --brand-900: #42101a;
            --brand-800: #5c0910;
            --brand-600: #b4161b;
            --brand-500: #ec4c4f;
            --ink-900: #172033;
            --ink-700: #475569;
            --ink-500: #64748b;
            --line: #e5e7eb;
            --surface: #ffffff;
            --page: #f4f7fb;
            --success-bg: #ecfdf5;
            --success-text: #047857;
            --danger-bg: #fff1f2;
            --danger-text: #be123c;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            background: var(--page);
            color: var(--ink-900);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        button, input { font: inherit; }
        button { cursor: pointer; }

        .content {
            width: calc(100% - 260px);
            min-height: 100vh;
            margin-left: 260px;
            padding: 34px clamp(20px, 4vw, 56px) 50px;
        }

        .page-shell { width: min(1160px, 100%); margin: 0 auto; }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 28px;
        }

        .eyebrow {
            margin: 0 0 8px;
            color: var(--brand-600);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .13em;
            text-transform: uppercase;
        }

        .page-header h1 {
            margin: 0;
            color: var(--ink-900);
            font-size: clamp(28px, 4vw, 38px);
            line-height: 1.1;
            letter-spacing: -.035em;
        }

        .page-header p {
            max-width: 560px;
            margin: 10px 0 0;
            color: var(--ink-500);
            font-size: 14px;
            line-height: 1.6;
        }

        .page-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border: 1px solid #dbe4ef;
            border-radius: 999px;
            color: var(--ink-700);
            background: rgba(255,255,255,.7);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .page-badge::before {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            content: '';
        }

        .page-header-actions { display: flex; align-items: center; gap: 10px; }

        .settings-back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 14px;
            border: 1px solid #dbe4ef;
            border-radius: 999px;
            color: var(--ink-700);
            background: #fff;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .settings-back-link:hover { background: #f8fafc; }

        .flash {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 20px;
            padding: 14px 16px;
            border: 1px solid transparent;
            border-radius: 14px;
            font-size: 13px;
            line-height: 1.5;
        }

        .flash.success { color: var(--success-text); background: var(--success-bg); border-color: #a7f3d0; }
        .flash.error { color: var(--danger-text); background: var(--danger-bg); border-color: #fecdd3; }
        .flash-icon { font-weight: 900; }

        .profile-layout {
            display: grid;
            grid-template-columns: minmax(280px, 340px) minmax(0, 1fr);
            gap: 24px;
            align-items: stretch;
        }

        .card {
            overflow: hidden;
            border: 1px solid rgba(148,163,184,.2);
            border-radius: 24px;
            background: var(--surface);
            box-shadow: 0 20px 55px rgba(31, 41, 55, .08);
        }

        .identity-cover {
            position: relative;
            height: 138px;
            overflow: hidden;
            background: radial-gradient(circle at 15% 20%, rgba(255,255,255,.22), transparent 28%), linear-gradient(135deg, #b4161b, #5c0910 68%, #2d0812);
        }

        .identity-cover::after {
            position: absolute;
            right: -35px;
            bottom: -80px;
            width: 220px;
            height: 220px;
            border: 1px solid rgba(255,255,255,.16);
            border-radius: 50%;
            box-shadow: 0 0 0 18px rgba(255,255,255,.05), 0 0 0 38px rgba(255,255,255,.04);
            content: '';
        }

        .identity-content {
            position: relative;
            padding: 0 24px 26px;
            text-align: center;
        }

        .avatar-wrap {
            position: relative;
            display: inline-block;
            margin-top: -58px;
        }

        .profile-pic {
            display: block;
            width: 116px;
            height: 116px;
            border: 5px solid #fff;
            border-radius: 50%;
            background: #f8fafc;
            box-shadow: 0 12px 30px rgba(15,23,42,.2);
            object-fit: cover;
        }

        .profile-pic[hidden] { display: none; }

        .profile-pic-fallback {
            display: grid;
            width: 116px;
            height: 116px;
            place-items: center;
            border: 5px solid #fff;
            border-radius: 50%;
            background: #f3dfe3;
            box-shadow: 0 12px 30px rgba(15,23,42,.2);
            color: #7d2636;
            font-size: 32px;
            font-weight: 700;
        }

        .profile-pic-fallback[hidden] { display: none; }

        .profile-pic-input {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
        }

        .identity-content h2 {
            margin: 18px 0 5px;
            color: var(--ink-900);
            font-size: 23px;
            letter-spacing: -.025em;
        }

        .identity-email {
            margin: 0;
            overflow-wrap: anywhere;
            color: var(--ink-500);
            font-size: 13px;
        }

        .identity-role {
            display: inline-flex;
            margin-top: 15px;
            padding: 7px 12px;
            border-radius: 999px;
            color: #9f1239;
            background: #ffe4e6;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .identity-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 24px 0;
        }

        .identity-stat {
            padding: 13px 10px;
            border: 1px solid #edf1f5;
            border-radius: 14px;
            background: #f8fafc;
        }

        .identity-stat strong {
            display: block;
            color: var(--ink-900);
            font-size: 14px;
        }

        .identity-stat span {
            display: block;
            margin-top: 4px;
            color: var(--ink-500);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .upload-form { display: grid; gap: 10px; margin-top: 4px; }

        .photo-choose-button,
        .upload-form .btn-block {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 54px;
            min-height: 54px;
            padding: 0 18px;
            box-sizing: border-box;
        }

        .photo-choose-button {
            overflow: hidden;
            border: 1px solid #d8b7bc;
            border-radius: 12px;
            color: var(--brand-800);
            background: #fff8f8;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .photo-choose-button:hover { background: #fff1f2; }
        .profile-pic-input:focus-visible + .photo-choose-button { outline: 3px solid rgba(180,22,27,.25); outline-offset: 2px; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            min-height: 46px;
            padding: 12px 18px;
            border: 0;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 800;
            transition: box-shadow .2s ease, background .2s ease;
        }

        .btn-primary { color: #fff; background: linear-gradient(135deg, var(--brand-600), var(--brand-800)); box-shadow: 0 12px 24px rgba(180,22,27,.2); }
        .btn-primary:hover { box-shadow: 0 16px 28px rgba(180,22,27,.28); }
        .btn:disabled { cursor: not-allowed; opacity: .58; }
        .btn-secondary { color: var(--ink-700); background: #f8fafc; border: 1px solid #e2e8f0; }
        .btn-secondary:hover { background: #f1f5f9; }
        .btn-block { width: 100%; }

        .form-card { padding: clamp(22px, 4vw, 34px); }

        .card-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 22px;
            border-bottom: 1px solid #edf1f5;
        }

        .card-heading h2 {
            margin: 0;
            color: var(--ink-900);
            font-size: 20px;
            letter-spacing: -.02em;
        }

        .card-heading p {
            margin: 7px 0 0;
            color: var(--ink-500);
            font-size: 13px;
            line-height: 1.5;
        }

        .heading-icon {
            display: grid;
            flex: 0 0 auto;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 13px;
            color: var(--brand-600);
            background: #fff1f2;
            font-size: 18px;
        }

        .form-section { padding: 26px 0 4px; }
        .form-section + .form-section { border-top: 1px solid #edf1f5; }

        .section-label {
            margin: 0 0 18px;
            color: var(--ink-900);
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-section .field-grid.field-grid-security {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: start;
        }

        .field-grid-security .form-group { min-width: 0; }

        .form-group { margin-bottom: 18px; }
        .field-grid .form-group { margin-bottom: 0; }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--ink-700);
            font-size: 12px;
            font-weight: 800;
        }

        .form-group input {
            width: 100%;
            min-height: 46px;
            padding: 11px 13px;
            border: 1px solid #dbe2ea;
            border-radius: 12px;
            outline: none;
            color: var(--ink-900);
            background: #fbfdff;
            font-size: 14px;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }

        .form-group input::placeholder { color: #94a3b8; }
        .form-group input:hover { border-color: #cbd5e1; }
        .form-group input:focus { border-color: var(--brand-500); background: #fff; box-shadow: 0 0 0 4px rgba(236,76,79,.11); }

        .field-hint {
            margin: 8px 0 0;
            color: var(--ink-500);
            font-size: 12px;
            line-height: 1.5;
        }

        .password-note {
            display: flex;
            gap: 9px;
            margin: -5px 0 18px;
            padding: 11px 12px;
            border-radius: 11px;
            color: #92400e;
            background: #fffbeb;
            font-size: 12px;
            line-height: 1.5;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 22px;
            padding-top: 22px;
            border-top: 1px solid #edf1f5;
        }

        @media (max-width: 980px) {
            .profile-layout { grid-template-columns: 1fr; }
            .identity-card { max-width: 560px; width: 100%; }
        }

        @media (max-width: 720px) {
            .content { width: 100%; margin-left: 0; padding: 78px 16px 36px; }
            .page-header { display: block; margin-bottom: 22px; }
            .page-header-actions { flex-wrap: wrap; margin-top: 16px; }
            .page-badge { margin-top: 0; }
            .field-grid { grid-template-columns: 1fr; gap: 0; }
            .form-section .field-grid.field-grid-security { grid-template-columns: 1fr; }
            .field-grid .form-group { margin-bottom: 18px; }
            .form-actions { flex-direction: column-reverse; }
            .form-actions .btn { width: 100%; }
        }

        @media (max-width: 420px) {
            .identity-content { padding-inline: 18px; }
            .form-card { padding: 20px 17px; border-radius: 20px; }
            .card-heading { gap: 12px; }
            .card-heading h2 { font-size: 18px; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar_owner.php'; ?>

    <main class="content">
        <div class="page-shell">
            <header class="page-header">
                <div>
                    <p class="eyebrow">Account settings</p>
                    <h1>My Profile</h1>
                    <p>Manage your administrator identity, contact information, profile photo, and account security from one place.</p>
                </div>
                <div class="page-header-actions">
                    <a class="settings-back-link" href="settings.php"><span aria-hidden="true">←</span> Back to Settings</a>
                    <div class="page-badge">Account is active</div>
                </div>
            </header>

            <?php if ($successMessage): ?>
                <div class="flash success" role="status"><span class="flash-icon">✓</span><span><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></span></div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="flash error" role="alert"><span class="flash-icon">!</span><span><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></span></div>
            <?php endif; ?>
            <?php if ($temporaryPasswordChangeRequired): ?>
                <div class="flash error" role="alert"><span class="flash-icon">!</span><span>Your account has a temporary password. Use it below to set a new password before returning to the dashboard.</span></div>
            <?php endif; ?>

            <section class="profile-layout">
                <article class="card identity-card">
                    <div class="identity-cover"></div>
                    <div class="identity-content">
                        <div class="avatar-wrap">
                            <?php if ($profileImage !== ''): ?>
                                <img src="<?= $profileImage; ?>" class="profile-pic" alt="Profile picture" onerror="this.hidden=true; this.nextElementSibling.hidden=false;">
                            <?php endif; ?>
                            <div class="profile-pic-fallback" <?php echo $profileImage !== '' ? 'hidden' : ''; ?> aria-label="Profile initials"><?php echo htmlspecialchars($profileInitials, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>

                        <h2><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p class="identity-email"><?= htmlspecialchars($user['email'] ?? 'No email address', ENT_QUOTES, 'UTF-8'); ?></p>
                        <span class="identity-role"><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?></span>

                        <div class="identity-stats">
                            <div class="identity-stat">
                                <strong><?= htmlspecialchars($memberSince, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span>Member since</span>
                            </div>
                            <div class="identity-stat">
                                <strong><?= htmlspecialchars($userStatus, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span>Status</span>
                            </div>
                        </div>

                        <form class="upload-form" method="POST" enctype="multipart/form-data">
                            <input type="file" name="profile_pic" id="profile_pic_input" class="profile-pic-input" accept="image/jpeg,image/png,image/webp">
                            <label class="photo-choose-button" for="profile_pic_input">Choose photo</label>
                            <button type="submit" name="upload" class="btn btn-primary btn-block">Upload new picture</button>
                            <p class="field-hint">JPG, PNG, or WEBP. Maximum file size: 5 MB.</p>
                        </form>
                    </div>
                </article>

                <article class="card form-card">
                    <div class="card-heading">
                        <div>
                            <h2>Profile information</h2>
                            <p>Keep your details current so account notifications reach you correctly.</p>
                        </div>
                        <div class="heading-icon">◉</div>
                    </div>

                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($temporaryPasswordCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-section">
                            <p class="section-label">Personal details</p>
                            <div class="field-grid">
                                <div class="form-group">
                                    <label for="fullname">Full name</label>
                                    <input type="text" id="fullname" name="fullname" value="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>" required placeholder="Enter your full name">
                                </div>
                                <div class="form-group">
                                    <label for="email">Email address</label>
                                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required placeholder="you@example.com">
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <p class="section-label">Temporary password reset</p>
                            <p class="field-hint">
                                <?php if ($temporaryPasswordChangeRequired): ?>
                                    Enter the one-time password sent by the owner. Use it to sign in and set a new password; it becomes invalid afterward.
                                <?php else: ?>
                                    If you forget your password, ask the owner to reset it. A new temporary password will be sent to your email and can be used once to sign in and set a new password. Leave these fields blank until then.
                                <?php endif; ?>
                            </p>
                            <div class="field-grid field-grid-security">
                                <div class="form-group">
                                    <label for="temporary_password">Temporary password</label>
                                    <input type="password" id="temporary_password" name="temporary_password" autocomplete="current-password" <?= $temporaryPasswordChangeRequired ? 'required' : ''; ?> placeholder="Enter temporary password">
                                </div>
                                <div class="form-group">
                                    <label for="new_password">New password</label>
                                    <input type="password" id="new_password" name="new_password" <?= $temporaryPasswordChangeRequired ? 'minlength="8" required' : ''; ?> autocomplete="new-password" placeholder="At least 8 characters">
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password">Confirm password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" <?= $temporaryPasswordChangeRequired ? 'minlength="8" required' : ''; ?> autocomplete="new-password" placeholder="Re-enter your new password">
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="reset" class="btn btn-secondary">Reset fields</button>
                            <button type="submit" name="save_profile" value="1" class="btn btn-primary">Save changes</button>
                        </div>
                    </form>
                </article>
            </section>
        </div>
    </main>

    <script>
        const profilePhotoInput = document.getElementById('profile_pic_input');
        const profileAvatarWrap = document.querySelector('.avatar-wrap');

        if (profilePhotoInput && profileAvatarWrap) {
            profilePhotoInput.addEventListener('change', function () {
                const file = this.files && this.files[0];
                if (!file) return;

                if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
                    alert('Choose a JPG, PNG, or WEBP image no larger than 5 MB.');
                    this.value = '';
                    return;
                }

                let image = profileAvatarWrap.querySelector('.profile-pic');
                if (!image) {
                    image = document.createElement('img');
                    image.className = 'profile-pic';
                    image.alt = 'Profile picture preview';
                    profileAvatarWrap.insertBefore(image, profileAvatarWrap.firstChild);
                }

                const fallback = profileAvatarWrap.querySelector('.profile-pic-fallback');
                const previewUrl = URL.createObjectURL(file);
                image.hidden = false;
                image.onload = function () { URL.revokeObjectURL(previewUrl); };
                image.onerror = function () {
                    URL.revokeObjectURL(previewUrl);
                    image.hidden = true;
                    if (fallback) fallback.hidden = false;
                };
                image.src = previewUrl;
                if (fallback) fallback.hidden = true;
            });
        }
    </script>
</body>
</html>
