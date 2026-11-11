<?php
session_start();
include '../owner/config.php';
require_once __DIR__ . '/../owner/temporary_password_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'rider') {
    header('Location: rider_login.php');
    exit();
}
try {
    $temporaryPasswordChangeRequired = hasPendingTemporaryPasswordChange($conn, (int)$_SESSION['user_id']);
    if ($temporaryPasswordChangeRequired) {
        $_SESSION['password_change_required'] = true;
    } else {
        unset($_SESSION['password_change_required']);
    }
} catch (RuntimeException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Unable to verify the account password status.');
}

$conn->query("CREATE TABLE IF NOT EXISTS rider_profiles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    phone VARCHAR(30) NULL,
    vehicle_type VARCHAR(50) NULL,
    plate_number VARCHAR(30) NULL,
    profile_image VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("ALTER TABLE rider_profiles ADD COLUMN IF NOT EXISTS profile_image VARCHAR(255) NULL AFTER plate_number");

$riderId = (int) $_SESSION['user_id'];
$message = '';
$error = '';
$temporaryPasswordCsrfToken = $_SESSION['temporary_password_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['temporary_password_csrf'] = $temporaryPasswordCsrfToken;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_temporary_password'])) {
    $temporaryPassword = (string)($_POST['temporary_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $passwordLookup = $conn->prepare('SELECT password, password_change_required FROM users WHERE id = ? AND role = \'rider\' LIMIT 1');

    if (!hash_equals($temporaryPasswordCsrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Session expired. Refresh the page and try again.';
    } elseif (!$temporaryPasswordChangeRequired) {
        $error = 'There is no pending temporary password for this account.';
    } elseif (!$passwordLookup) {
        $error = 'Unable to prepare password verification. Please try again.';
    } else {
        $passwordLookup->bind_param('i', $riderId);
        if (!$passwordLookup->execute()) {
            $error = 'Unable to verify the temporary password. Please try again.';
        } else {
            $passwordRecord = $passwordLookup->get_result()->fetch_assoc();
            $storedPassword = (string)($passwordRecord['password'] ?? '');
            $temporaryPasswordValid = !empty($passwordRecord['password_change_required'])
                && (password_verify($temporaryPassword, $storedPassword)
                    || (strlen($storedPassword) === 32 && hash_equals($storedPassword, md5($temporaryPassword))));

            if (!$temporaryPasswordValid) {
                $error = 'The temporary password is incorrect or has already been used.';
            } elseif (strlen($newPassword) < 8) {
                $error = 'The new password must be at least 8 characters long.';
            } elseif (!hash_equals($newPassword, $confirmPassword)) {
                $error = 'The new password and confirmation do not match.';
            } elseif (hash_equals($temporaryPassword, $newPassword)) {
                $error = 'Your new password must be different from the temporary password.';
            } else {
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $passwordUpdate = $conn->prepare('UPDATE users SET password = ?, password_change_required = 0 WHERE id = ? AND role = \'rider\' AND password = ? AND password_change_required = 1');
                if (!$passwordUpdate) {
                    $error = 'Unable to prepare the password update. Please try again.';
                } else {
                    $passwordUpdate->bind_param('sis', $newPasswordHash, $riderId, $storedPassword);
                    if ($passwordUpdate->execute() && $passwordUpdate->affected_rows === 1) {
                        $temporaryPasswordChangeRequired = false;
                        unset($_SESSION['password_change_required']);
                        $message = 'Password changed successfully. Your temporary password can no longer be used.';
                    } else {
                        $error = 'Unable to change your password. The temporary password may have already been used.';
                    }
                    $passwordUpdate->close();
                }
            }
        }
        $passwordLookup->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile_image'])) {
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        $mime = mime_content_type($_FILES['profile_image']['tmp_name']);
        if (!isset($allowed[$mime]) || $_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
            $error = 'Profile photo must be JPG, PNG, or WEBP and no larger than 2MB.';
        } else {
            $uploadDir = __DIR__ . '/uploads/rider_profile_pics';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $extension = $allowed[$mime];
            $filename = 'rider_' . $riderId . '_' . time() . '.' . $extension;
            $targetPath = $uploadDir . '/' . $filename;

            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetPath)) {
                $relativePath = 'uploads/rider_profile_pics/' . $filename;
                $stmt = $conn->prepare("INSERT INTO rider_profiles (user_id, profile_image) VALUES (?, ?) ON DUPLICATE KEY UPDATE profile_image = VALUES(profile_image)");
                $stmt->bind_param('is', $riderId, $relativePath);
                if ($stmt->execute()) {
                    $message = 'Profile photo updated successfully.';
                } else {
                    $error = 'Unable to save your profile photo. Please try again.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to upload the selected profile photo.';
            }
        }
    } else {
        $error = 'Please choose a valid image file to upload.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $vehicleType = trim($_POST['vehicle_type'] ?? '');
    $plateNumber = trim($_POST['plate_number'] ?? '');

    if ($fullName === '' || strlen($fullName) > 150) {
        $error = 'Please enter a name no longer than 150 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $error = 'Please enter a valid email address.';
    } elseif ($phone === '' || $vehicleType === '' || $plateNumber === '') {
        $error = 'Please complete your contact number, vehicle type, and plate number before accessing deliveries.';
    } elseif (strlen($phone) > 30 || strlen($vehicleType) > 50 || strlen($plateNumber) > 30) {
        $error = 'Please check the length of your contact and delivery details.';
    } else {
        $emailCheck = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $updateUser = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ? AND role = 'rider'");
        $saveRiderProfile = $conn->prepare('INSERT INTO rider_profiles (user_id, phone, vehicle_type, plate_number) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE phone = VALUES(phone), vehicle_type = VALUES(vehicle_type), plate_number = VALUES(plate_number)');

        if (!$emailCheck || !$updateUser || !$saveRiderProfile) {
            $error = 'Unable to prepare your profile update. Please try again.';
            if ($emailCheck) {
                $emailCheck->close();
            }
        } else {
            $emailCheckSucceeded = $emailCheck->bind_param('si', $email, $riderId) && $emailCheck->execute();
            $emailExists = $emailCheckSucceeded && $emailCheck->get_result()->num_rows > 0;
            $emailCheck->close();

            if (!$emailCheckSucceeded) {
                $error = 'Unable to verify your email address. Please try again.';
            } elseif ($emailExists) {
                $error = 'That email address is already in use.';
            } else {
                $conn->begin_transaction();
                $updateUser->bind_param('ssi', $fullName, $email, $riderId);
                $saveRiderProfile->bind_param('isss', $riderId, $phone, $vehicleType, $plateNumber);

                if ($updateUser->execute() && $saveRiderProfile->execute()) {
                    $conn->commit();
                    $_SESSION['rider_dashboard_message'] = 'Rider information saved successfully.';
                    header('Location: availdel.php');
                    exit();
                } else {
                    $conn->rollback();
                    $error = 'Unable to save your rider information. Please try again.';
                }
            }
        }

        if ($updateUser) {
            $updateUser->close();
        }
        if ($saveRiderProfile) {
            $saveRiderProfile->close();
        }
    }
}

$profileResult = $conn->query("SELECT u.full_name, u.username, u.email, rp.phone, rp.vehicle_type, rp.plate_number, rp.profile_image FROM users u LEFT JOIN rider_profiles rp ON rp.user_id = u.id WHERE u.id = $riderId AND u.role = 'rider' LIMIT 1");
$profile = $profileResult ? $profileResult->fetch_assoc() : [];
$profileImage = !empty($profile['profile_image']) ? $profile['profile_image'] : '';
$displayName = $profile['full_name'] ?? ($profile['username'] ?? 'Rider');
$initials = strtoupper(substr(preg_replace('/\s+/', '', $displayName), 0, 2));
if ($initials === '') {
    $initials = 'R';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rider Profile</title>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#f3f8f7 0%,#fffaf2 100%);color:#000;font-family:Segoe UI,Tahoma,sans-serif}.rider-sidebar{position:fixed;top:0;left:0;bottom:0;width:220px;padding:24px 16px;background:#174c4a;color:#fff;z-index:10;box-shadow:8px 0 24px rgba(23,76,74,.12)}.rider-brand{padding:6px 10px 24px;border-bottom:1px solid rgba(255,255,255,.16);font-size:18px;font-weight:800}.rider-brand span{display:block;margin-top:5px;color:#b8d8d3;font-size:10px;font-weight:600}.rider-nav{display:grid;gap:7px;margin-top:24px}.rider-nav a{padding:12px 13px;border-radius:8px;color:#d8efeb;text-decoration:none;font-size:12px;font-weight:700}.rider-nav a:hover,.rider-nav a.active{background:#267873;color:#fff}.rider-nav .logout-link{margin-top:18px;color:#ffd8d8}.page{margin-left:220px;width:min(760px,calc(100% - 220px));padding:34px 24px}.panel{background:rgba(255,255,255,.95);border:1px solid #dce9e7;border-radius:16px;padding:24px;box-shadow:0 12px 30px rgba(30,36,42,.07)}.profile-header{display:flex;align-items:center;gap:18px;margin-bottom:22px}.profile-avatar{width:92px;height:92px;border-radius:50%;overflow:hidden;border:4px solid #dbeee9;background:#eaf5f2;display:flex;align-items:center;justify-content:center;box-shadow:0 12px 28px rgba(23,76,74,.12)}.profile-avatar img{width:100%;height:100%;object-fit:cover}.avatar-fallback{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1d6f70,#7eb7b3);color:#fff;font-size:28px;font-weight:800}.header-copy h1{margin:0;color:#174c4a;font-size:28px}.subtitle{margin:8px 0 0;color:#6b7d82;font-size:12px}.status{margin:14px 0 0;padding:11px 12px;border-radius:10px;font-size:12px;font-weight:600}.status.success{background:#eafaf3;border:1px solid #cfead8;color:#1d6f51}.status.error{background:#fff1f1;border:1px solid #f8d8d8;color:#9b2c2c}.upload-form{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:18px 0 22px}.upload-box{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:11px 16px;background:#f2faf8;border:1px dashed #86b7b3;border-radius:10px;color:#174c4a;font-weight:700;font-size:12px;cursor:pointer;overflow:hidden}.upload-box input{position:absolute;inset:0;opacity:0;cursor:pointer}.upload-btn{padding:11px 16px;border:none;border-radius:10px;background:#174c4a;color:#fff;font-weight:700;font-size:12px;cursor:pointer}.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.profile-item{padding:14px;background:#f1f8f6;border:1px solid #e0efec;border-radius:9px}.profile-item span{display:block;margin-bottom:6px;color:#6b7d82;font-size:10px;font-weight:700}.profile-item strong{font-size:14px;color:#20333a}@media(max-width:780px){.rider-sidebar{position:relative;width:auto;padding:14px 16px}.rider-brand{padding:4px 6px 12px}.rider-nav{display:flex;flex-wrap:wrap;margin-top:12px}.rider-nav a{padding:9px 10px}.rider-nav .logout-link{margin-top:0}.page{margin-left:0;width:100%;padding:20px 14px 35px}.profile-header{flex-direction:column;align-items:flex-start}.profile-grid{grid-template-columns:1fr}.upload-form{align-items:flex-start;flex-direction:column}}</style>
<style>
.rider-appbar{position:fixed;top:0;left:0;right:0;height:88px;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:12px 30px;background:#fff;border-bottom:1px solid #e8edef;box-shadow:0 3px 12px rgba(25,30,38,.05);z-index:20}
.rider-appbar{left:220px}
.rider-appbar-brand{display:flex;align-items:center;gap:16px;min-width:0;margin-left:0}
.rider-appbar-logo{width:58px;height:58px;object-fit:contain;padding:4px;background:#fff;border-radius:10px;border:1px solid #e6eef0;display:block}
.rider-appbar-copy{display:grid;gap:3px}
.rider-appbar-title{color:#a82d45;font-size:21px;font-weight:700;letter-spacing:.2px}
.rider-appbar-tagline{color:#667085;font-size:11px;font-weight:600;letter-spacing:.6px}
.rider-appbar-account{display:flex;align-items:center;gap:10px;max-width:260px;padding:7px 12px;border:1px solid #edf0f2;border-radius:12px;background:#f8fafb}
.rider-appbar-avatar{width:38px;height:38px;flex:0 0 38px;display:grid;place-items:center;overflow:hidden;border-radius:50%;background:#e7f1ef;color:#174c4a;font-size:12px;font-weight:800}
.rider-appbar-avatar img{width:100%;height:100%;object-fit:cover}
.rider-appbar-user{min-width:0;display:grid;gap:2px}
.rider-appbar-user strong,.rider-appbar-user span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rider-appbar-user strong{color:#344054;font-size:12px}
.rider-appbar-user span{color:#7b8794;font-size:10px}
.rider-sidebar{top:0}
.page{padding-top:118px}
@media(max-width:780px){.rider-appbar{position:relative;left:0;height:auto;min-height:76px;padding:10px 14px}.rider-appbar-brand{gap:9px}.rider-appbar-logo{width:46px;height:46px}.rider-appbar-title{font-size:15px}.rider-appbar-tagline{font-size:8px;letter-spacing:.2px}.rider-appbar-account{gap:7px;padding:5px 7px;max-width:45%}.rider-appbar-avatar{width:32px;height:32px;flex-basis:32px}.rider-appbar-user strong{font-size:10px}.rider-appbar-user span{font-size:9px}.rider-sidebar{top:auto}.page{padding-top:20px}}
</style>
<style>
.rider-sidebar{transition:width .25s ease}
.rider-brand{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0 6px 18px}
.rider-brand-title{display:flex;align-items:center;gap:9px;min-width:0}
.rider-brand-badge{display:grid;place-items:center;width:42px;height:42px;flex:0 0 42px;background:rgba(255,255,255,.08);color:#fff}
.rider-brand-badge .material-icons-outlined{font-size:23px}
.rider-brand-badge span{display:inline-block;margin:0;color:inherit;font-size:23px}
.rider-brand-copy{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rider-brand-copy span{display:block;margin-top:3px;color:#b8d8d3;font-size:10px;font-weight:600}
.sidebar-toggle{display:grid;place-items:center;width:38px;height:38px;flex:0 0 38px;border:0;border-radius:6px;background:transparent;color:#d8efeb;cursor:pointer}
.sidebar-toggle:hover{background:rgba(255,255,255,.1);color:#fff}
.sidebar-toggle .material-icons-outlined{font-size:25px}
.rider-nav{margin-top:22px}
.rider-nav a{display:flex;align-items:center;gap:13px}
.rider-nav .material-icons-outlined{width:20px;flex:0 0 20px;font-size:20px;text-align:center}
.rider-nav .nav-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rider-sidebar.collapsed{width:76px;padding-right:10px;padding-left:10px}
.rider-sidebar.collapsed .rider-brand{justify-content:center;padding-right:0;padding-left:0}
.rider-sidebar.collapsed .rider-brand-copy,.rider-sidebar.collapsed .nav-label{display:none}
.rider-sidebar.collapsed .rider-brand-badge{display:none}
.rider-sidebar.collapsed .rider-brand-title{gap:0}
.rider-sidebar.collapsed .rider-nav a{justify-content:center;padding-right:8px;padding-left:8px}
.rider-sidebar.collapsed .rider-nav a .material-icons-outlined{width:24px;flex-basis:24px}
body.sidebar-collapsed .rider-appbar{left:76px}
body.sidebar-collapsed .page{margin-left:76px;width:min(760px,calc(100% - 76px))}
.profile-page{margin-left:220px;width:calc(100% - 220px);max-width:none;padding:118px clamp(22px,3vw,44px) 48px}
.profile-page-heading{margin-bottom:24px}
.profile-page-heading h1{margin:0;color:#174c4a;font-size:32px;letter-spacing:-.5px}
.profile-page-heading p{max-width:560px;margin:8px 0 0;color:#6b7d82;font-size:13px;line-height:1.6}
.profile-layout{display:grid;grid-template-columns:minmax(260px,340px) minmax(0,1fr);align-items:stretch;gap:24px}
.profile-summary,.profile-details{overflow:hidden;border:1px solid #dce9e7;border-radius:20px;background:rgba(255,255,255,.95);box-shadow:0 12px 30px rgba(30,36,42,.07)}
.profile-summary{display:flex;flex-direction:column}
.profile-summary-cover{height:138px;background:linear-gradient(135deg,#1d6f70,#174c4a)}
.profile-summary-body{position:relative;display:flex;flex:1;flex-direction:column;padding:0 24px 24px;text-align:center}
.profile-summary .profile-avatar{position:relative;margin:-54px auto 14px;width:108px;height:108px;border-width:5px;background:#eaf5f2}
.profile-summary .avatar-fallback{background:linear-gradient(135deg,#1d6f70,#7eb7b3)}
.profile-summary-name{margin:0;color:#20333a;font-size:23px}
.profile-summary-email{margin:6px 0 14px;color:#6b7d82;font-size:12px;overflow-wrap:anywhere}
.profile-role{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:20px;background:#e7f5f1;color:#174c4a;font-size:10px;font-weight:800;letter-spacing:.7px;text-transform:uppercase}
.profile-summary-facts{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:22px 0}
.profile-summary-fact{min-width:0;padding:12px 8px;background:#f1f8f6;border:1px solid #e0efec;border-radius:12px}
.profile-summary-fact strong,.profile-summary-fact span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.profile-summary-fact strong{color:#20333a;font-size:12px}
.profile-summary-fact span{margin-top:5px;color:#6b7d82;font-size:9px;font-weight:700;letter-spacing:.4px;text-transform:uppercase}
.profile-summary .upload-form{display:grid;gap:9px;margin:auto 0 0}
.profile-summary .upload-box,.profile-summary .upload-btn{width:100%;min-height:44px}
.profile-summary .upload-box{border-style:solid}
.profile-summary .upload-help{margin:0;color:#6b7d82;font-size:10px;line-height:1.5}
.profile-details{padding:28px 30px}
.profile-details-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding-bottom:22px;border-bottom:1px solid #dce9e7}
.profile-details-heading h2{margin:0;color:#174c4a;font-size:20px}
.profile-details-heading p{margin:7px 0 0;color:#6b7d82;font-size:12px;line-height:1.5}
.profile-details-heading-icon{display:grid;place-items:center;width:44px;height:44px;flex:0 0 44px;border-radius:12px;background:#e7f5f1;color:#174c4a}
.profile-details-heading-icon .material-icons-outlined{font-size:23px}
.profile-section{padding-top:22px}
.profile-section+.profile-section{margin-top:20px;border-top:1px solid #dce9e7}
.profile-section h3{margin:0 0 14px;color:#174c4a;font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase}
.profile-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.profile-item{min-width:0;padding:13px 14px;background:#f1f8f6;border-color:#e0efec;border-radius:10px}
.profile-item span{font-size:10px}
.profile-edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.profile-edit-field{display:grid;min-width:0;gap:7px}
.profile-edit-field span{color:#6b7d82;font-size:10px;font-weight:700}
.profile-edit-field input{width:100%;height:42px;padding:0 12px;border:1px solid #cddedb;border-radius:10px;background:#fff;color:#20333a;font:inherit;font-size:12px}
.profile-edit-field input:focus{outline:2px solid #9bcac2;border-color:#39736f}
.profile-edit-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:18px;border-top:1px solid #dce9e7}
.profile-save-button{min-height:42px;padding:0 20px;border:0;border-radius:10px;background:#174c4a;color:#fff;font-size:12px;font-weight:800;cursor:pointer}
.profile-save-button:hover{background:#267873}
.profile-setup-notice{margin:0 0 18px;padding:13px 16px;border:1px solid #b9dcd6;border-radius:10px;background:#e7f5f1;color:#174c4a;font-size:12px;line-height:1.6}
.profile-setup-notice strong{display:block;margin-bottom:3px}
.profile-message{margin:0 0 18px}
.temporary-password-card{margin:0 0 20px;padding:22px;border:1px solid #b9dcd6;border-radius:14px;background:#fff;box-shadow:0 8px 22px rgba(23,76,74,.06)}
.temporary-password-card h2{margin:0;color:#174c4a;font-size:18px}
.temporary-password-card>p{margin:7px 0 16px;color:#6b7d82;font-size:12px;line-height:1.55}
.temporary-password-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-items:end;gap:12px}
.temporary-password-grid .profile-save-button{min-height:42px}
.temporary-password-notice{margin:0 0 18px;padding:13px 16px;border:1px solid #e8c993;border-radius:10px;background:#fff8e8;color:#775418;font-size:12px;line-height:1.6}
body.sidebar-collapsed .profile-page{margin-left:76px;width:calc(100% - 76px)}
@media(max-width:900px){.profile-layout{grid-template-columns:minmax(220px,280px) minmax(0,1fr);gap:16px}.profile-details{padding:24px 20px}}
@media(max-width:780px){.profile-page,body.sidebar-collapsed .profile-page{margin-left:0;width:100%;padding:20px 14px 35px}.profile-page-heading{margin-bottom:18px}.profile-page-heading h1{font-size:27px}.profile-layout{grid-template-columns:1fr}.profile-summary{max-width:none}.profile-details{padding:22px 18px}.profile-details-heading h2{font-size:18px}}
@media(max-width:520px){.profile-grid,.profile-edit-grid,.temporary-password-grid{grid-template-columns:1fr}.profile-summary-cover{height:112px}.profile-summary-body{padding-right:18px;padding-left:18px}.profile-details-heading{gap:10px}.profile-edit-actions{justify-content:stretch}.profile-save-button{width:100%}}
@media(max-width:780px){.rider-sidebar,.rider-sidebar.collapsed{width:auto;padding:14px 16px}.rider-brand,.rider-sidebar.collapsed .rider-brand{justify-content:space-between;padding:0 6px 12px}.rider-brand-copy,.rider-sidebar.collapsed .rider-brand-copy{display:block}.rider-sidebar.collapsed .rider-brand-badge{display:grid}.rider-nav{margin-top:12px}.rider-sidebar.collapsed .rider-nav a{justify-content:flex-start}.rider-sidebar.collapsed .rider-nav a .material-icons-outlined{width:20px;flex-basis:20px}.rider-sidebar.collapsed .nav-label{display:inline}body.sidebar-collapsed .rider-nav{display:none}body.sidebar-collapsed .rider-appbar{left:0}body.sidebar-collapsed .page{margin-left:0;width:100%}}
@media(max-width:780px){
    .rider-sidebar,.rider-sidebar.collapsed{position:fixed;top:0;left:0;bottom:0;width:76px;padding:14px 10px}
    .rider-brand,.rider-sidebar.collapsed .rider-brand{justify-content:center;padding:0 0 12px}
    .rider-brand-title,.rider-sidebar.collapsed .rider-brand-title{justify-content:center;gap:0}
    .rider-brand-copy,.rider-sidebar.collapsed .rider-brand-copy,.sidebar-toggle{display:none}
    .rider-sidebar .rider-brand-badge,.rider-sidebar.collapsed .rider-brand-badge{display:grid}
    .rider-nav,body.sidebar-collapsed .rider-nav,.rider-sidebar.collapsed .rider-nav{display:grid;gap:7px;margin-top:12px}
    .rider-nav a,.rider-sidebar.collapsed .rider-nav a{justify-content:center;padding:11px 8px}
    .rider-nav a .material-icons-outlined,.rider-sidebar.collapsed .rider-nav a .material-icons-outlined{width:24px;flex-basis:24px}
    .rider-nav .nav-label,.rider-sidebar.collapsed .nav-label{display:none}
    .rider-nav .logout-link{margin-top:12px}
    .rider-appbar,body.sidebar-collapsed .rider-appbar{position:fixed;top:0;left:76px;right:0;height:auto;min-height:76px;padding:10px 14px}
    .rider-appbar-brand{gap:9px}
    .rider-appbar-logo{width:46px;height:46px}
    .rider-appbar-title{font-size:15px}
    .rider-appbar-tagline{font-size:8px;letter-spacing:.2px}
    .rider-appbar-account{gap:7px;padding:5px 7px;max-width:45%}
    .rider-appbar-avatar{width:32px;height:32px;flex-basis:32px}
    .rider-appbar-user strong{font-size:10px}
    .rider-appbar-user span{font-size:9px}
    .page,body.sidebar-collapsed .page,.profile-page,body.sidebar-collapsed .profile-page{width:calc(100% - 76px);margin:0 0 0 76px;padding:100px 14px 35px}
    .profile-page-heading{margin-bottom:18px}
    .profile-page-heading h1{font-size:27px}
    .profile-layout{grid-template-columns:1fr}
    .profile-summary{max-width:none}
    .profile-details{padding:22px 18px}
    .profile-details-heading h2{font-size:18px}
}
</style>
</head>
<body>
<?php
$riderLayoutProfile = $profile;
$riderLayoutDisplayName = $displayName;
$riderLayoutInitials = $initials;
$riderActivePage = 'profile';
$riderUseBrandLogo = false;
include __DIR__ . '/rider_sidebar.php';
?>
<main class="page profile-page">
    <div class="profile-page-heading">
        <h1>My Profile</h1>
        <p>View your rider identity, contact information, profile photo, and delivery details in one place.</p>
    </div>

    <?php if (isset($_GET['setup'])): ?>
        <div class="profile-setup-notice"><strong>Complete your rider profile to continue.</strong>Fill in your full name, email, contact number, vehicle type, and plate number. Your profile photo is optional.</div>
    <?php endif; ?>

    <?php if ($temporaryPasswordChangeRequired): ?>
        <div class="temporary-password-notice"><strong>Your account is using a temporary password.</strong>Change it below with the one-time password provided by the owner before continuing to deliveries.</div>
    <?php endif; ?>

    <?php if (!empty($message)): ?>
        <div class="status success profile-message"><?php echo htmlspecialchars($message); ?></div>
    <?php elseif (!empty($error)): ?>
        <div class="status error profile-message"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($temporaryPasswordChangeRequired): ?>
        <section class="temporary-password-card">
            <h2>Set a new password</h2>
            <p>Use the one-time temporary password provided by the owner. It becomes invalid as soon as your new password is saved.</p>
            <form method="post" class="temporary-password-grid" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($temporaryPasswordCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="profile-edit-field"><span>Temporary password</span><input type="password" name="temporary_password" autocomplete="current-password" required></label>
                <label class="profile-edit-field"><span>New password</span><input type="password" name="new_password" minlength="8" autocomplete="new-password" required></label>
                <label class="profile-edit-field"><span>Confirm new password</span><input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></label>
                <button class="profile-save-button" type="submit" name="reset_temporary_password" value="1">Set new password</button>
            </form>
        </section>
    <?php endif; ?>

    <div class="profile-layout">
        <aside class="profile-summary">
            <div class="profile-summary-cover"></div>
            <div class="profile-summary-body">
            <div class="profile-avatar" aria-label="Rider profile photo">
                <?php if (!empty($profileImage)): ?>
                    <img src="<?php echo htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?> profile picture">
                <?php else: ?>
                    <div class="avatar-fallback"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
            <h2 class="profile-summary-name"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="profile-summary-email"><?php echo htmlspecialchars($profile['email'] ?? 'Not provided', ENT_QUOTES, 'UTF-8'); ?></p>
            <span class="profile-role"><span class="material-icons-outlined" aria-hidden="true">delivery_dining</span>Rider</span>
            <div class="profile-summary-facts">
                <div class="profile-summary-fact">
                    <strong><?php echo htmlspecialchars($profile['vehicle_type'] ?: 'Not set', ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span>Vehicle</span>
                </div>
                <div class="profile-summary-fact">
                    <strong><?php echo htmlspecialchars($profile['plate_number'] ?: 'Not set', ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span>Plate number</span>
                </div>
            </div>
            <form class="upload-form" method="post" enctype="multipart/form-data">
                <label class="upload-box">
                    <input type="file" name="profile_image" accept="image/jpeg,image/png,image/webp">
                    <span>Choose photo</span>
                </label>
                <button class="upload-btn" type="submit" name="update_profile_image" value="1">Upload new picture</button>
                <p class="upload-help">JPG, PNG, or WEBP. Maximum file size: 2 MB.</p>
            </form>
            </div>
        </aside>

        <section class="profile-details">
            <header class="profile-details-heading">
                <div>
                    <h2>Profile information</h2>
                    <p>Review your account details and delivery information.</p>
                </div>
                <span class="profile-details-heading-icon"><span class="material-icons-outlined" aria-hidden="true">person_outline</span></span>
            </header>
            <form method="post">
            <section class="profile-section">
                <h3>Personal details</h3>
                <div class="profile-edit-grid">
                    <label class="profile-edit-field"><span>Full name</span><input type="text" name="full_name" maxlength="150" value="<?php echo htmlspecialchars($profile['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required></label>
                    <label class="profile-edit-field"><span>Username</span><input type="text" value="<?php echo htmlspecialchars($profile['username'] ?? $_SESSION['username'], ENT_QUOTES, 'UTF-8'); ?>" readonly></label>
                    <label class="profile-edit-field"><span>Email address</span><input type="email" name="email" maxlength="255" value="<?php echo htmlspecialchars($profile['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required></label>
                    <label class="profile-edit-field"><span>Contact number</span><input type="tel" name="phone" maxlength="30" value="<?php echo htmlspecialchars($profile['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter contact number" required></label>
                </div>
            </section>
            <section class="profile-section">
                <h3>Delivery details</h3>
                <div class="profile-edit-grid">
                    <label class="profile-edit-field"><span>Vehicle type</span><input type="text" name="vehicle_type" maxlength="50" value="<?php echo htmlspecialchars($profile['vehicle_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Motorcycle, bicycle, etc." required></label>
                    <label class="profile-edit-field"><span>Plate number</span><input type="text" name="plate_number" maxlength="30" value="<?php echo htmlspecialchars($profile['plate_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter plate number" required></label>
                </div>
            </section>
            <div class="profile-edit-actions">
                <button class="profile-save-button" type="submit" name="save_profile" value="1">Save rider information</button>
            </div>
            </form>
        </section>
        </div>
</main>
</body>
</html>
