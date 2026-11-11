<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = "";

$profile_column = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_pic'");
if ($profile_column && $profile_column->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) DEFAULT 'default.png'");
}
$last_login_column = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'");
if ($last_login_column && $last_login_column->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN last_login DATETIME NULL");
    $last_login_column = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'");
}

if (isset($_POST['upload'])) {
    if (!empty($_FILES['profile_pic']['name'])) {
        $filename = time() . "_" . $_FILES['profile_pic']['name'];
        $target = "uploads/" . $filename;

        if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target)) {
            $stmt = $conn->prepare("UPDATE users SET profile_pic=? WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("si", $filename, $user_id);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    writeAuditLog($conn, 'profile_updated', 'user', (int)$user_id, ['changed' => ['profile_picture']]);
                    $message = "Profile picture updated!";
                } else {
                    $message = "Profile picture could not be saved.";
                }
            } else {
                $message = "Profile picture could not be saved: " . $conn->error;
            }
        } else {
            $message = "Upload failed!";
        }
    }
}

if (isset($_POST['delete_profile'])) {
    $current_picture = trim((string) ($_POST['current_profile_pic'] ?? ''));
    $picture_name = basename($current_picture);
    if ($picture_name !== '' && $picture_name !== 'default.png') {
        $picture_path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $picture_name;
        if (is_file($picture_path)) {
            unlink($picture_path);
        }
    }

    $stmt = $conn->prepare("UPDATE users SET profile_pic=NULL WHERE id=?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $saved = $stmt->execute();
        $stmt->close();
        if ($saved) {
            writeAuditLog($conn, 'profile_updated', 'user', (int)$user_id, ['changed' => ['profile_picture_removed']]);
            $message = "Profile picture deleted.";
        } else {
            $message = "Profile picture could not be deleted.";
        }
    } else {
        $message = "Profile picture could not be deleted: " . $conn->error;
    }
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if ($last_login_column && $last_login_column->num_rows > 0 && empty($user['last_login'])) {
    $initialize_last_login_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ? AND last_login IS NULL");
    if ($initialize_last_login_stmt) {
        $initialize_last_login_stmt->bind_param("i", $user_id);
        if ($initialize_last_login_stmt->execute() && $initialize_last_login_stmt->affected_rows > 0) {
            $user['last_login'] = date('Y-m-d H:i:s');
        }
        $initialize_last_login_stmt->close();
    }
}

$full_name = trim((string) ($user['full_name'] ?? ''));
if ($full_name === '') {
    $full_name = trim((string) ($user['username'] ?? 'User'));
}
$username = trim((string) ($user['username'] ?? ''));
$profile_initial = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $full_name ?: $username ?: 'U'), 0, 1));
$profile_email = $user['email'] ?? 'Not set';
$profile_phone = $user['phone'] ?? 'Not set';
$profile_dob = $user['dob'] ?? 'Not set';
$account_created = !empty($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : 'Not available';
$last_login = !empty($user['last_login'])
    ? date('M j, Y', strtotime($user['last_login'])) . ' · ' . date('h:i A', strtotime($user['last_login']))
    : 'Not available';
$profile_address = $user['address'] ?? 'Not set';
$has_profile_picture = !empty($user['profile_pic']) && $user['profile_pic'] !== 'default.png';
$default_address = null;
$other_address_count = 0;
$address_stmt = $conn->prepare("SELECT recipient_name, phone, address FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1");
if ($address_stmt) {
    $address_stmt->bind_param("i", $user_id);
    $address_stmt->execute();
    $default_address = $address_stmt->get_result()->fetch_assoc();
}
$address_count_stmt = $conn->prepare("SELECT COUNT(*) FROM addresses WHERE user_id = ?");
if ($address_count_stmt) {
    $address_count_stmt->bind_param("i", $user_id);
    $address_count_stmt->execute();
    $address_count = (int) $address_count_stmt->get_result()->fetch_row()[0];
    $other_address_count = max(0, $address_count - ($default_address ? 1 : 0));
}
$gender_value = trim((string) ($user['gender'] ?? ''));
$gender_symbol = '⚧';
if (strtolower($gender_value) === 'male') {
    $gender_symbol = '♂';
} elseif (strtolower($gender_value) === 'female') {
    $gender_symbol = '♀';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f2f2f3;
            color: #1f2937;
        }

        .main-content {
            margin-left: 260px;
            width: calc(100% - 260px);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .profile-wrapper {
            width: min(760px, 100%);
            background: #f8f8f8;
            border: 1px solid #e7e7e7;
            border-radius: 18px;
            padding: 26px 28px 18px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.02);
        }

        .profile-top {
            position: relative;
            text-align: center;
            padding: 12px 0 14px;
        }

        .profile-actions {
            position: absolute;
            top: 0;
            right: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .profile-action {
            width: 36px;
            height: 36px;
            border: 1px solid #e1e1e1;
            border-radius: 10px;
            background: #fff;
            color: #5f6368;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 3px 8px rgba(0,0,0,0.05);
        }

        .profile-action:hover {
            color: #d32f2f;
            border-color: #d32f2f;
        }

        .profile-pic-wrap {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #f0dfe3;
            border: 3px solid #f6edf1;
            overflow: visible;
            box-shadow: 0 10px 18px rgba(0,0,0,0.06);
        }

        .avatar-image,
        .avatar-placeholder {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            border-radius: 50%;
        }

        .avatar-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 44px;
            font-weight: 700;
            color: #5f3a48;
            background: #f0dfe3;
        }

        .camera-btn {
            position: absolute;
            right: -12px;
            bottom: -8px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 3px solid #fff;
            background: #d32f2f;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            cursor: pointer;
            z-index: 2;
            box-shadow: 0 4px 12px rgba(211, 47, 47, 0.2);
        }

        .camera-btn:hover {
            background: #b71c1c;
        }

        .profile-name {
            margin: 0;
            font-size: 32px;
            color: #1f2937;
            font-weight: 800;
            letter-spacing: -0.04em;
        }

        .profile-username {
            margin-top: 8px;
            font-size: 15px;
            color: #6b7280;
            font-weight: 600;
        }

        .profile-username .at {
            color: #9ca3af;
            margin-right: 2px;
        }

        .upload-form {
            display: none;
            align-items: center;
            gap: 10px;
            margin: 14px 0 18px;
        }

        .upload-form.is-open {
            display: flex;
        }

        .file-box {
            display: block;
            position: relative;
            flex: 1;
            min-height: 46px;
            border: 1px solid #d8d8d8;
            border-radius: 10px;
            background: #fff;
            overflow: hidden;
        }

        .file-box input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .file-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            height: 100%;
            padding: 0 14px;
            font-size: 14px;
            color: #58606a;
            background: #fff;
        }

        .file-label .file-name {
            font-weight: 500;
            color: #7b7f86;
        }

        .save-btn {
            border: none;
            border-radius: 10px;
            background: #d32f2f;
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            padding: 0 20px;
            min-width: 120px;
            min-height: 46px;
            cursor: pointer;
        }

        .delete-btn {
            border: 1px solid #d32f2f;
            border-radius: 10px;
            background: #fff;
            color: #d32f2f;
            font-weight: 700;
            font-size: 14px;
            padding: 0 16px;
            min-height: 46px;
            cursor: pointer;
        }

        .delete-btn:hover {
            background: #fff1f1;
        }

        .save-btn:hover {
            background: #b71c1c;
        }

        .save-btn:disabled {
            background: #c9c9c9;
            cursor: not-allowed;
        }

        .success {
            margin: 0 0 18px;
            padding: 12px 14px;
            background: #eafaf1;
            color: #0d8b4e;
            border: 1px solid #c6efd9;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .info-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-top: 8px;
        }

        .info-item {
            background: #f4f4f4;
            border: 1px solid #e7e7e7;
            border-radius: 10px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 76px;
        }

        .info-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #dfeaf8;
            color: #2c5a8e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .info-icon.gender {
            background: #fce9ef;
            color: #d14b7d;
        }

        .info-content {
            min-width: 0;
            flex: 1;
        }

        .info-label {
            display: block;
            color: #8a8d93;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 4px;
            font-weight: 700;
        }

        .info-value {
            display: block;
            color: #1f2937;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.45;
            word-break: break-word;
        }

        /* Profile dashboard layout */
        body {
            background: #f4f7fb;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .main-content {
            align-items: flex-start;
            justify-content: flex-start;
            padding: 0 34px 48px;
        }

        .profile-wrapper {
            width: min(1120px, 100%);
            margin: 0 auto;
            padding: 0;
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }

        .profile-page-title {
            display: flex;
            align-items: center;
            padding: 24px 0 28px;
            font-family: Arial, 'Segoe UI', sans-serif;
        }

        .profile-page-title-icon {
            display: none;
        }

        .profile-page-title h1 {
            margin: 0;
            color: #1a202c;
            font-size: 35px;
            line-height: 1.1;
        }

        .profile-page-title p {
            margin: 4px 0 0;
            color: #9ca3af;
            font-size: 15px;
        }

        .profile-hero {
            display: flex;
            align-items: center;
            gap: 22px;
            min-height: 164px;
            padding: 24px 28px;
            background: linear-gradient(110deg, #fff 0%, #fff7f8 100%);
            border: 1px solid #f0e1e4;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(52, 72, 96, 0.06);
        }

        .profile-top {
            display: contents;
            text-align: left;
            padding: 0;
        }

        .profile-pic-wrap {
            width: 104px;
            height: 104px;
            margin: 0;
            flex: 0 0 auto;
            border-width: 2px;
        }

        .profile-name {
            margin: 0;
            color: #263247;
            font-size: 25px;
            letter-spacing: 0;
        }

        .profile-username {
            margin-top: 4px;
            color: #718096;
            font-size: 13px;
        }

        .profile-identity {
            min-width: 0;
            flex: 1;
        }

        .profile-description {
            margin: 12px 0 0;
            color: #718096;
            font-size: 12px;
        }

        .profile-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            padding: 5px 10px;
            border-radius: 14px;
            background: #e8f8f0;
            color: #148452;
            font-size: 11px;
            font-weight: 700;
        }

        .profile-status::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #16a765;
        }

        .profile-actions {
            position: static;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            min-width: 240px;
        }

        .profile-action {
            width: auto;
            height: auto;
            padding: 11px 16px;
            border: 1px solid #a52838;
            border-radius: 8px;
            color: #7d2636;
            font-size: 13px;
            font-weight: 700;
            box-shadow: none;
        }

        .profile-action::after {
            content: '  >';
            margin-left: 22px;
        }

        .profile-action:hover {
            background: #fff5f6;
        }

        .profile-actions::after {
            content: 'Edit your profile, security, addresses, and more.';
            color: #9ca3af;
            font-size: 11px;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-top: 18px;
        }

        .profile-card {
            min-width: 0;
            padding: 20px;
            background: #fff;
            border: 1px solid #e7edf4;
            border-radius: 12px;
            box-shadow: 0 8px 22px rgba(52, 72, 96, 0.05);
        }

        .profile-card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 14px;
            border-bottom: 1px solid #edf0f4;
            color: #263247;
            font-size: 15px;
            font-weight: 700;
        }

        .profile-card-title p {
            margin: 3px 0 0;
            color: #9ca3af;
            font-size: 11px;
            font-weight: 400;
        }

        .profile-card-icon {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            border-radius: 50%;
            background: #fbecef;
            color: #a52838;
            font-size: 16px;
        }

        .profile-card-icon .material-icons-outlined {
            font-size: 20px;
            line-height: 1;
        }

        .address-heading-icon {
            font-size: 17px;
            line-height: 1;
            vertical-align: -3px;
        }

        .profile-info-table {
            margin-top: 4px;
        }

        .profile-info-row {
            display: grid;
            grid-template-columns: 42% 1fr;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .profile-info-row:last-child {
            border-bottom: 0;
        }

        .profile-info-label {
            color: #718096;
            font-size: 12px;
        }

        .profile-info-value {
            color: #334155;
            font-size: 12px;
            font-weight: 600;
            word-break: break-word;
        }

        .default-address-box {
            margin-top: 16px;
            padding: 16px;
            border-radius: 8px;
            background: #fff7f8;
        }

        .default-address-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
        }

        .default-badge {
            padding: 5px 9px;
            border-radius: 13px;
            background: #f6e4e7;
            color: #a52838;
            font-size: 10px;
        }

        .default-address-text {
            margin: 10px 0 0 28px;
            color: #475569;
            font-size: 12px;
            line-height: 1.45;
        }

        .other-addresses {
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #edf0f4;
        }

        .other-addresses-title {
            color: #475569;
            font-size: 12px;
            font-weight: 700;
        }

        .other-addresses-empty {
            margin: 14px 0 0;
            color: #9ca3af;
            font-size: 11px;
        }

        .manage-addresses-link {
            display: inline-flex;
            margin-top: 14px;
            color: #a52838;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        .status-card {
            grid-column: 1 / -1;
        }

        .status-details {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-top: 16px;
        }

        .status-detail {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            padding: 4px 18px;
        }

        .status-detail + .status-detail {
            border-left: 1px solid #dfeaf8;
        }

        .status-detail-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dfeaf8;
            color: #2c5a8e;
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }

        .status-detail-icon.active {
            background: #e8f8f0;
            color: #148452;
        }

        .status-detail-copy {
            min-width: 0;
        }

        .status-detail-label {
            display: block;
            margin-bottom: 6px;
            color: #8a8d93;
            font-size: 12px;
        }

        .status-detail-value {
            display: block;
            color: #2c5a8e;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .status-active-value {
            display: inline-flex;
            padding: 3px 10px;
            border-radius: 14px;
            background: #e8f8f0;
            color: #148452;
            font-size: 12px;
            font-weight: 700;
        }

        .status-detail-note {
            display: block;
            margin-top: 4px;
            color: #718096;
            font-size: 11px;
        }

        @media (max-width: 760px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px 12px;
            }

            .profile-wrapper {
                padding: 0;
            }

            .profile-hero {
                align-items: flex-start;
                flex-direction: column;
                gap: 14px;
                padding: 20px;
            }

            .profile-actions {
                width: 100%;
                min-width: 0;
            }

            .profile-action {
                width: 100%;
                justify-content: space-between;
            }

            .profile-grid {
                grid-template-columns: 1fr;
            }

            .status-card {
                grid-column: auto;
            }

            .status-details {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .status-detail {
                padding: 8px 4px;
            }

            .status-detail + .status-detail {
                border-top: 1px solid #dfeaf8;
                border-left: 0;
                padding-top: 16px;
            }

            .upload-form.is-open {
                flex-wrap: wrap;
            }

            .upload-form .file-box {
                flex-basis: 100%;
            }

            .profile-name {
                font-size: 24px;
            }

            .profile-page-title h1 {
                font-size: 34px;
            }
        }
    </style>
</head>
<body>
    <?php include 'sidebar_user.php'; ?>

    <div class="main-content">
        <div class="profile-wrapper">
            <div class="profile-page-title">
                <div class="profile-page-title-icon"><i class="material-icons-outlined">person_outline</i></div>
                <div>
                    <h1>Profile</h1>
                    <p>View and manage your personal information</p>
                </div>
            </div>

            <div class="profile-hero">
                <div class="profile-pic-wrap">
                    <div style="display:block;width:100%;height:100%;">
                        <?php if ($has_profile_picture): ?>
                            <img class="avatar-image" src="uploads/<?= htmlspecialchars($user['profile_pic'], ENT_QUOTES, 'UTF-8') ?>" alt="Profile picture">
                        <?php else: ?>
                            <div class="avatar-placeholder"><?= htmlspecialchars($profile_initial, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="profile-identity">
                    <h1 class="profile-name"><?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?></h1>
                    <div class="profile-username"><?= htmlspecialchars($profile_email, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="profile-status">Active</span>
                    <p class="profile-description">View your personal information and account details.</p>
                </div>

                <div class="profile-actions">
                    <a href="settings.php?tab=account" class="profile-action" title="Manage account settings" aria-label="Manage account settings">⚙ Manage Account Settings</a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="profile-grid">
                <section class="profile-card">
                    <div class="profile-card-title">
                        <span class="profile-card-icon"><i class="material-icons-outlined" aria-hidden="true">person_outline</i></span>
                        <div>Personal Information<p>Your basic information</p></div>
                    </div>
                    <div class="profile-info-table">
                        <div class="profile-info-row"><span class="profile-info-label">Full Name</span><span class="profile-info-value"><?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?></span></div>
                        <div class="profile-info-row"><span class="profile-info-label">Email Address</span><span class="profile-info-value"><?= htmlspecialchars($profile_email, ENT_QUOTES, 'UTF-8') ?></span></div>
                        <div class="profile-info-row"><span class="profile-info-label">Phone Number</span><span class="profile-info-value"><?= htmlspecialchars($profile_phone, ENT_QUOTES, 'UTF-8') ?></span></div>
                        <div class="profile-info-row"><span class="profile-info-label">Date of Birth</span><span class="profile-info-value"><?= htmlspecialchars($profile_dob, ENT_QUOTES, 'UTF-8') ?></span></div>
                        <div class="profile-info-row"><span class="profile-info-label">Gender</span><span class="profile-info-value"><?= htmlspecialchars($gender_value ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></span></div>
                    </div>
                </section>

                <section class="profile-card">
                    <div class="profile-card-title">
                        <span class="profile-card-icon"><i class="material-icons-outlined" aria-hidden="true">local_shipping</i></span>
                        <div>Delivery Information<p>Your default address for orders and deliveries</p></div>
                    </div>
                    <?php if ($default_address): ?>
                        <div class="default-address-box">
                            <div class="default-address-heading"><span><i class="material-icons-outlined address-heading-icon" aria-hidden="true">home</i>&nbsp; Default Address</span><span class="default-badge">Default</span></div>
                            <p class="default-address-text"><?= htmlspecialchars($default_address['address'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php else: ?>
                        <div class="default-address-box"><p class="default-address-text" style="margin-left: 0;">No default address saved yet.</p></div>
                    <?php endif; ?>
                    <div class="other-addresses">
                        <div class="other-addresses-title"><i class="material-icons-outlined address-heading-icon" aria-hidden="true">location_on</i>&nbsp; Other Addresses</div>
                        <p class="other-addresses-empty"><?= $other_address_count > 0 ? $other_address_count . ' additional address(es) saved.' : "You haven't added any additional addresses yet." ?></p>
                        <a href="settings.php?tab=addresses" class="manage-addresses-link">Manage Addresses &nbsp;→</a>
                    </div>
                </section>

                <section class="profile-card status-card">
                    <div class="profile-card-title">
                        <span class="profile-card-icon"><i class="material-icons-outlined" aria-hidden="true">verified_user</i></span>
                        <div>Account Status<p>Your account status and activity</p></div>
                    </div>
                    <div class="status-details">
                        <div class="status-detail">
                            <span class="status-detail-icon active"><i class="material-icons-outlined">verified_user</i></span>
                            <div class="status-detail-copy">
                                <span class="status-detail-label">Account Status</span>
                                <span class="status-active-value">Active</span>
                                <span class="status-detail-note">Your account is in good standing.</span>
                            </div>
                        </div>
                        <div class="status-detail">
                            <span class="status-detail-icon"><i class="material-icons-outlined">calendar_today</i></span>
                            <div class="status-detail-copy">
                                <span class="status-detail-label">Account Created</span>
                                <span class="status-detail-value"><?= htmlspecialchars($account_created, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                        <div class="status-detail">
                            <span class="status-detail-icon"><i class="material-icons-outlined">schedule</i></span>
                            <div class="status-detail-copy">
                                <span class="status-detail-label">Last Login</span>
                                <span class="status-detail-value"><?= htmlspecialchars($last_login, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script>
    </script>
</body>
</html>