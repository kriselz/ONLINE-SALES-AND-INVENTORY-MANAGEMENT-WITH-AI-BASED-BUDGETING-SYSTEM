<?php
include 'config.php';
require_once 'functions.php';
require_once 'login_activity.php';
require_once __DIR__ . '/google_profile.php';
require_once __DIR__ . '/delivery_pricing.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Keep existing installations compatible with the account settings form.
$profile_columns = [
    'full_name' => 'VARCHAR(255) NULL',
    'dob' => 'DATE NULL',
    'gender' => 'VARCHAR(20) NULL',
    'city' => 'VARCHAR(100) NULL',
    'postal_code' => 'VARCHAR(20) NULL',
    'province' => 'VARCHAR(100) NULL',
    'profile_pic' => "VARCHAR(255) DEFAULT 'default.png'"
];
foreach ($profile_columns as $column_name => $column_definition) {
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE '$column_name'");
    if ($column_check && $column_check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN $column_name $column_definition");
    }
}
$address_name_check = $conn->query("SHOW COLUMNS FROM addresses LIKE 'recipient_name'");
if ($address_name_check && $address_name_check->num_rows === 0) {
    $conn->query("ALTER TABLE addresses ADD COLUMN recipient_name VARCHAR(255) NULL AFTER user_id");
}
$address_phone_check = $conn->query("SHOW COLUMNS FROM addresses LIKE 'phone'");
if ($address_phone_check && $address_phone_check->num_rows === 0) {
    $conn->query("ALTER TABLE addresses ADD COLUMN phone VARCHAR(20) NULL AFTER recipient_name");
}
$address_default_check = $conn->query("SHOW COLUMNS FROM addresses LIKE 'is_default'");
if ($address_default_check && $address_default_check->num_rows === 0) {
    $conn->query("ALTER TABLE addresses ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER address");
}
foreach (['latitude', 'longitude'] as $address_coordinate_column) {
    $coordinate_check = $conn->query("SHOW COLUMNS FROM addresses LIKE '$address_coordinate_column'");
    if ($coordinate_check && $coordinate_check->num_rows === 0) {
        $conn->query("ALTER TABLE addresses ADD COLUMN $address_coordinate_column DECIMAL(10,7) NULL");
    }
}

// ✅ 1. LOGIN CHECK
if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$user_check = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
if (!$user_check) {
    error_log('Settings user validation preparation failed: ' . $conn->error);
    header("Location: login.php");
    exit();
}
$user_check->bind_param("i", $user_id);
$user_check->execute();
$user_exists = $user_check->get_result()->num_rows === 1;
$user_check->close();
if (!$user_exists) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit();
}

$msg = '';
$msg_type = '';
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $msg = "✅ Account settings updated successfully!";
    $msg_type = "success";
}
if (isset($_GET['uploaded']) && $_GET['uploaded'] === '1') {
    $msg = "✅ Profile photo updated successfully!";
    $msg_type = "success";
}
if (isset($_GET['password_saved']) && $_GET['password_saved'] === '1') {
    $msg = "✅ Password updated successfully!";
    $msg_type = "success";
}
if (isset($_GET['other_sessions_logged_out']) && $_GET['other_sessions_logged_out'] === '1') {
    $msg = "✅ All other sessions have been logged out. This session is still active.";
    $msg_type = "success";
}
$allowed_tabs = ['account', 'password', 'addresses', 'appearance'];
$active_tab = $_GET['tab'] ?? '';
if (!in_array($active_tab, $allowed_tabs, true)) {
    $active_tab = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_account']) || isset($_POST['upload_profile'])) {
        $active_tab = 'account';
    } elseif (isset($_POST['change_password'])) {
        $active_tab = 'password';
    } elseif (isset($_POST['logout_other_sessions'])) {
        $active_tab = 'password';
    } elseif (isset($_POST['add_address']) || isset($_POST['edit_address']) || isset($_POST['delete_address']) || isset($_POST['set_default_address'])) {
        $active_tab = 'addresses';
    }
}

// Get user data
$user_stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_data = $user_stmt->get_result()->fetch_assoc();
$google_profile_completion_required = !empty($_SESSION['complete_google_profile'])
    && !isGoogleProfileComplete($user_data ?: []);
$google_address_completion_required = false;
if (!empty($_SESSION['complete_google_profile']) && !$google_profile_completion_required) {
    try {
        $google_address_completion_required = !hasPinnedDefaultDeliveryAddress($conn, $user_id);
    } catch (RuntimeException $exception) {
        error_log('Google profile delivery address check failed: ' . $exception->getMessage());
        $msg = '❌ Unable to verify your saved delivery address right now. Please try again.';
        $msg_type = 'danger';
        $google_address_completion_required = true;
    }
}
if (!empty($_SESSION['complete_google_profile'])) {
    $active_tab = $google_profile_completion_required ? 'account' : 'addresses';
}
ensureUserLoginActivityTable($conn);
if (empty($_SESSION['login_activity_token'])) {
    $initial_login_method = $_SESSION['auth_provider'] ?? (empty($user_data['password']) ? 'google' : 'password');
    recordUserLoginActivity($conn, (int) $user_id, $initial_login_method);
}
$is_google_sign_in = ($_SESSION['auth_provider'] ?? '') === 'google'
    || trim((string) ($user_data['password'] ?? '')) === '';
$account_type = strtolower((string) ($user_data['role'] ?? 'user')) === 'user'
    ? 'Customer'
    : ucfirst((string) $user_data['role']);
$current_login_token = (string) ($_SESSION['login_activity_token'] ?? '');
$current_login_token_hash = $current_login_token !== '' ? hash('sha256', $current_login_token) : '';
$login_activity_csrf_token = $_SESSION['login_activity_csrf_token'] ?? '';
if (!is_string($login_activity_csrf_token) || $login_activity_csrf_token === '') {
    $login_activity_csrf_token = bin2hex(random_bytes(32));
    $_SESSION['login_activity_csrf_token'] = $login_activity_csrf_token;
}

if (isset($_POST['logout_other_sessions'])) {
    $submittedCsrfToken = (string) ($_POST['login_activity_csrf_token'] ?? '');
    if ($current_login_token_hash === '' || !hash_equals($login_activity_csrf_token, $submittedCsrfToken)) {
        http_response_code(400);
        $msg = '❌ Unable to verify this request. Refresh the page and try again.';
        $msg_type = 'danger';
    } else {
        $revoke_stmt = $conn->prepare('UPDATE user_login_activity SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP) WHERE user_id = ? AND session_token_hash <> ?');
        if (!$revoke_stmt) {
            error_log('Other customer sessions revoke preparation failed: ' . $conn->error);
            $msg = '❌ Unable to log out other sessions right now.';
            $msg_type = 'danger';
        } else {
            $revoke_stmt->bind_param('is', $user_id, $current_login_token_hash);
            if ($revoke_stmt->execute()) {
                $revoke_stmt->close();
                header('Location: settings.php?tab=password&other_sessions_logged_out=1');
                exit();
            }
            error_log('Other customer sessions revoke failed: ' . $revoke_stmt->error);
            $revoke_stmt->close();
            $msg = '❌ Unable to log out other sessions right now.';
            $msg_type = 'danger';
        }
    }
}

$login_activity_rows = [];
$login_activity_stmt = $conn->prepare('SELECT session_token_hash, login_method, user_agent, logged_in_at, revoked_at FROM user_login_activity WHERE user_id = ? ORDER BY logged_in_at DESC, id DESC');
if ($login_activity_stmt) {
    $login_activity_stmt->bind_param('i', $user_id);
    $login_activity_stmt->execute();
    $login_activity_result = $login_activity_stmt->get_result();
    while ($login_activity_row = $login_activity_result->fetch_assoc()) {
        $login_activity_row['device_label'] = getLoginActivityDeviceLabel($login_activity_row['user_agent']);
        $login_activity_row['time_label'] = getLoginActivityTimeLabel($login_activity_row['logged_in_at']);
        $login_activity_row['is_current'] = $current_login_token_hash !== ''
            && hash_equals($current_login_token_hash, (string) $login_activity_row['session_token_hash']);
        $login_activity_rows[] = $login_activity_row;
    }
    $login_activity_stmt->close();
}
$recent_login_activity_rows = array_slice($login_activity_rows, 0, 3);
$display_name = trim((string) ($user_data['full_name'] ?? ''));
if ($display_name === '') {
    $display_name = trim((string) ($user_data['username'] ?? ''));
}

function savedAddressDeliveryRangeError(float $latitude, float $longitude): ?string
{
    try {
        $roadDistance = deliveryRoadDistanceKm($latitude, $longitude);
    } catch (RuntimeException $exception) {
        error_log('Saved address road-distance validation failed: ' . $exception->getMessage());
        return '❌ Unable to verify the road distance right now. Please try again.';
    }

    if (deliveryFeeForDistance($roadDistance) === null) {
        return '❌ This address is beyond the 10 km road delivery limit.';
    }

    return null;
}

// ✅ PROFILE PHOTO UPLOAD
if (isset($_POST['upload_profile']) && isset($_FILES['profile_pic'])) {
    $profile_file = $_FILES['profile_pic'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $upload_directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    $image_info = null;

    if ($profile_file['error'] !== UPLOAD_ERR_OK) {
        $msg = "❌ Unable to upload the profile photo.";
        $msg_type = "danger";
    } elseif ($profile_file['size'] > 2 * 1024 * 1024) {
        $msg = "❌ Profile photo must be 2MB or smaller.";
        $msg_type = "danger";
    } else {
        $image_info = @getimagesize($profile_file['tmp_name']);
        if (!$image_info || !in_array($image_info['mime'], $allowed_mimes, true)) {
            $msg = "❌ Please upload a valid JPG, PNG, GIF, or WEBP image.";
            $msg_type = "danger";
        } else {
            $extension = strtolower(pathinfo($profile_file['name'], PATHINFO_EXTENSION));
            $filename = 'profile_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $target_path = $upload_directory . DIRECTORY_SEPARATOR . $filename;

            if (move_uploaded_file($profile_file['tmp_name'], $target_path)) {
                $upload_stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                if ($upload_stmt && $upload_stmt->bind_param("si", $filename, $user_id) && $upload_stmt->execute()) {
                    header("Location: settings.php?tab=account&uploaded=1");
                    exit();
                }
                @unlink($target_path);
            }

            $msg = "❌ Unable to save the profile photo.";
            $msg_type = "danger";
        }
    }
}

// ✅ 2. UPDATE ACCOUNT SETTINGS
if(isset($_POST['update_account'])){
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $dob = trim($_POST['dob'] ?? '');
    $gender = trim($_POST['gender'] ?? '');

    $dob_date = !empty($dob) ? DateTime::createFromFormat('!Y-m-d', $dob) : null;
    $dob_errors = $dob !== '' ? DateTime::getLastErrors() : false;
    $today = new DateTime('today');

    if (!preg_match("/^[\\p{L}][\\p{L} .'-]{1,99}$/u", $full_name)) {
        $msg = "❌ Please enter a readable full name.";
        $msg_type = "danger";
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,20}$/', $username)) {
        $msg = "❌ Username must be 3-20 characters using letters, numbers, dots, underscores, or dashes.";
        $msg_type = "danger";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "❌ Please enter a valid email address.";
        $msg_type = "danger";
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $msg = "❌ Phone number must be 11 digits and start with 09.";
        $msg_type = "danger";
    } elseif (empty($dob) || !$dob_date || $dob_date->format('Y-m-d') !== $dob
        || ($dob_errors !== false && ($dob_errors['warning_count'] > 0 || $dob_errors['error_count'] > 0))
        || $dob_date > $today) {
        $msg = "❌ Date of birth cannot be empty or in the future.";
        $msg_type = "danger";
    } elseif (!in_array($gender, ['Male', 'Female'], true)) {
        $msg = "❌ Please select a valid gender.";
        $msg_type = "danger";
    } else {
        $full_name = $conn->real_escape_string($full_name);
        $username = $conn->real_escape_string($username);
        $email = $conn->real_escape_string($email);
        $phone = $conn->real_escape_string($phone);
        $dob = $conn->real_escape_string($dob);
        $gender = $conn->real_escape_string($gender);
        $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, dob = ?, gender = ? WHERE id = ?");

        if ($update_stmt && $update_stmt->bind_param("ssssssi", $full_name, $username, $email, $phone, $dob, $gender, $user_id) && $update_stmt->execute()) {
            $msg = "✅ Account settings updated successfully!";
            $msg_type = "success";
            addNotification($conn, "👤 Your account information has been updated", $user_id);
            if (!empty($_SESSION['complete_google_profile'])) {
                $google_address_check_failed = false;
                $completed_profile = [
                    'full_name' => $full_name,
                    'username' => $username,
                    'email' => $email,
                    'phone' => $phone,
                    'dob' => $dob,
                    'gender' => $gender
                ];
                if (isGoogleProfileComplete($completed_profile)) {
                    try {
                        if (hasPinnedDefaultDeliveryAddress($conn, $user_id)) {
                            unset($_SESSION['complete_google_profile']);
                            $_SESSION['username'] = $username;
                            $_SESSION['email'] = $email;
                            $_SESSION['success_message'] = 'Welcome to Josefina Farmacia, ' . $full_name . '! Your account setup is complete.';
                            header("Location: product_screen.php");
                            exit();
                        }
                    } catch (RuntimeException $exception) {
                        error_log('Google profile delivery address check failed after account update: ' . $exception->getMessage());
                        $msg = '❌ Your account details were saved, but we could not verify your delivery address. Please try again.';
                        $msg_type = 'danger';
                        $google_address_check_failed = true;
                        $google_profile_completion_required = false;
                        $google_address_completion_required = true;
                        $active_tab = 'addresses';
                    }
                    if (!$google_address_check_failed) {
                        header('Location: settings.php?tab=addresses');
                        exit();
                    }
                }
            }
            if (empty($google_address_check_failed)) {
                header("Location: settings.php?tab=account&saved=1");
                exit();
            }
        } else {
            $database_error = $update_stmt ? $update_stmt->error : $conn->error;
            $msg = "❌ Unable to update account settings: " . htmlspecialchars($database_error, ENT_QUOTES, 'UTF-8');
            $msg_type = "danger";
        }
    }
}

// ✅ 3. CHANGE PASSWORD LOGIC (WITH VERIFICATION)
if(isset($_POST['change_password'])){
    $old_pass = $_POST['old_password'];
    $new_pass = $_POST['password'];
    $confirm_pass = $_POST['confirm_password'];

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $current_user = $stmt->get_result()->fetch_assoc();

    if (!password_verify($old_pass, $current_user['password'])) {
        $msg = "❌ Incorrect current password!";
        $msg_type = "danger";
    } elseif ($new_pass !== $confirm_pass) {
        $msg = "❌ New passwords do not match!";
        $msg_type = "danger";
    } elseif (strlen($new_pass) < 6) {
        $msg = "❌ Password must be at least 6 characters!";
        $msg_type = "danger";
    } else {
        $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update_stmt->bind_param("si", $hashed_pass, $user_id);
        
        if($update_stmt->execute()){
            $msg = "✅ Password updated successfully!";
            $msg_type = "success";
            addNotification($conn, "🔐 Your password has been changed successfully", $user_id);
            header("Location: settings.php?tab=password&password_saved=1");
            exit();
        }
    }
}

// ✅ 4. EDIT A SAVED DELIVERY ADDRESS
if (isset($_POST['edit_address'])) {
    $address_id = (int) ($_POST['address_id'] ?? 0);
    $recipient_name = trim($_POST['recipient_name'] ?? '');
    $recipient_phone = trim($_POST['recipient_phone'] ?? '');
    $new_address = trim($_POST['new_address'] ?? '');
    $latitude_input = trim((string) ($_POST['latitude'] ?? ''));
    $longitude_input = trim((string) ($_POST['longitude'] ?? ''));
    $make_default = isset($_POST['make_default']) ? 1 : 0;

    if (!preg_match("/^[\\p{L}][\\p{L} .'-]{1,99}$/u", $recipient_name)) {
        $msg = "❌ Please enter a readable recipient name.";
        $msg_type = "danger";
    } elseif (!preg_match('/^09[0-9]{9}$/', $recipient_phone)) {
        $msg = "❌ Contact number must be 11 digits and start with 09.";
        $msg_type = "danger";
    } elseif (strlen($new_address) < 5) {
        $msg = "❌ Please enter a complete readable address.";
        $msg_type = "danger";
    } elseif (!is_numeric($latitude_input) || !is_numeric($longitude_input)
        || (float) $latitude_input < -90 || (float) $latitude_input > 90
        || (float) $longitude_input < -180 || (float) $longitude_input > 180) {
        $msg = "❌ Please pin this address on the map before saving.";
        $msg_type = "danger";
    } else {
        $latitude = (float) $latitude_input;
        $longitude = (float) $longitude_input;
        $range_error = savedAddressDeliveryRangeError($latitude, $longitude);
        if ($range_error !== null) {
            $msg = $range_error;
            $msg_type = "danger";
        } else {
            $edit_stmt = $conn->prepare("UPDATE addresses SET recipient_name = ?, phone = ?, address = ?, latitude = ?, longitude = ? WHERE id = ? AND user_id = ?");
            $edit_stmt->bind_param("sssddii", $recipient_name, $recipient_phone, $new_address, $latitude, $longitude, $address_id, $user_id);

            if ($edit_stmt->execute() && $edit_stmt->affected_rows >= 0) {
                if ($make_default === 1) {
                    $conn->query("UPDATE addresses SET is_default = 0 WHERE user_id = $user_id");
                    $conn->query("UPDATE addresses SET is_default = 1 WHERE id = $address_id AND user_id = $user_id");
                }
                $msg = "✅ Delivery address updated successfully.";
                $msg_type = "success";
            } else {
                $msg = "❌ Unable to update the delivery address.";
                $msg_type = "danger";
            }
        }
    }
}

// ✅ 5. ADD A SECONDARY DELIVERY ADDRESS
if (isset($_POST['add_address'])) {
    $recipient_name = trim($_POST['recipient_name'] ?? '');
    $recipient_phone = trim($_POST['recipient_phone'] ?? '');
    $new_address = trim($_POST['new_address'] ?? '');
    $latitude_input = trim((string) ($_POST['latitude'] ?? ''));
    $longitude_input = trim((string) ($_POST['longitude'] ?? ''));
    $make_default = isset($_POST['make_default']) ? 1 : 0;

    if (!preg_match("/^[\\p{L}][\\p{L} .'-]{1,99}$/u", $recipient_name)) {
        $msg = "❌ Please enter a readable recipient name.";
        $msg_type = "danger";
    } elseif (!preg_match('/^09[0-9]{9}$/', $recipient_phone)) {
        $msg = "❌ Contact number must be 11 digits and start with 09.";
        $msg_type = "danger";
    } elseif (strlen($new_address) < 5) {
        $msg = "❌ Please enter a complete readable address.";
        $msg_type = "danger";
    } elseif (!is_numeric($latitude_input) || !is_numeric($longitude_input)
        || (float) $latitude_input < -90 || (float) $latitude_input > 90
        || (float) $longitude_input < -180 || (float) $longitude_input > 180) {
        $msg = "❌ Please pin this address on the map before saving.";
        $msg_type = "danger";
    } else {
        $latitude = (float) $latitude_input;
        $longitude = (float) $longitude_input;
        $range_error = savedAddressDeliveryRangeError($latitude, $longitude);
        if ($range_error !== null) {
            $msg = $range_error;
            $msg_type = "danger";
        } else {
            $count_stmt = $conn->prepare("SELECT COUNT(*) FROM addresses WHERE user_id = ?");
            $count_stmt->bind_param("i", $user_id);
            $count_stmt->execute();
            $address_count = (int) $count_stmt->get_result()->fetch_row()[0];
            $is_default = ($address_count === 0 || $make_default === 1
                || !empty($_SESSION['complete_google_profile'])) ? 1 : 0;
            $addressSaved = false;
            $addressSaveError = '';
            if (!$conn->begin_transaction()) {
                $addressSaveError = $conn->error;
            } else {
                if ($is_default === 1 && $address_count > 0) {
                    $clear_defaults_stmt = $conn->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?');
                    if (!$clear_defaults_stmt) {
                        $addressSaveError = $conn->error;
                    } else {
                        $clear_defaults_stmt->bind_param('i', $user_id);
                        if (!$clear_defaults_stmt->execute()) {
                            $addressSaveError = $clear_defaults_stmt->error;
                        }
                        $clear_defaults_stmt->close();
                    }
                }

                if ($addressSaveError === '') {
                    $address_stmt = $conn->prepare("INSERT INTO addresses (user_id, recipient_name, phone, address, latitude, longitude, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if (!$address_stmt) {
                        $addressSaveError = $conn->error;
                    } else {
                        $address_stmt->bind_param("isssddi", $user_id, $recipient_name, $recipient_phone, $new_address, $latitude, $longitude, $is_default);
                        if ($address_stmt->execute() && $conn->commit()) {
                            $addressSaved = true;
                        } else {
                            $addressSaveError = $address_stmt->error ?: $conn->error;
                        }
                        $address_stmt->close();
                    }
                }

                if (!$addressSaved) {
                    $conn->rollback();
                }
            }

            if ($addressSaved) {
                $msg = "✅ Delivery address added successfully!";
                $msg_type = "success";
                if (!empty($_SESSION['complete_google_profile'])) {
                    try {
                        if (isGoogleProfileComplete($user_data ?: [])
                            && hasPinnedDefaultDeliveryAddress($conn, $user_id)) {
                            unset($_SESSION['complete_google_profile']);
                            $_SESSION['success_message'] = 'Welcome to Josefina Farmacia, ' . $display_name . '! Your account and delivery address are ready.';
                            header('Location: product_screen.php');
                            exit();
                        }
                    } catch (RuntimeException $exception) {
                        error_log('Google profile delivery address check failed after address save: ' . $exception->getMessage());
                        $msg = '❌ Address saved, but we could not verify your Google account setup. Please try again.';
                        $msg_type = 'danger';
                    }
                }
            } else {
                error_log('Address save failed for user ' . $user_id . ': ' . $addressSaveError);
                $msg = "❌ Unable to save the delivery address.";
                $msg_type = "danger";
            }
        }
    }
}

// ✅ 6. SET A DEFAULT DELIVERY ADDRESS
if (isset($_POST['set_default_address'])) {
    $address_id = (int) ($_POST['address_id'] ?? 0);
    $conn->begin_transaction();
    $clear_default_stmt = $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?");
    $clear_default_stmt->bind_param("i", $user_id);
    $set_default_stmt = $conn->prepare("UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
    $set_default_stmt->bind_param("ii", $address_id, $user_id);

    if ($clear_default_stmt->execute() && $set_default_stmt->execute() && $set_default_stmt->affected_rows > 0) {
        $conn->commit();
        $msg = "✅ Default delivery address updated.";
        $msg_type = "success";
    } else {
        $conn->rollback();
        $msg = "❌ Unable to set the default delivery address.";
        $msg_type = "danger";
    }
}

// ✅ 7. DELETE A SAVED DELIVERY ADDRESS
if (isset($_POST['delete_address'])) {
    $address_id = (int) ($_POST['address_id'] ?? 0);
    $deleted_address_stmt = $conn->prepare("SELECT is_default FROM addresses WHERE id = ? AND user_id = ?");
    $deleted_address_stmt->bind_param("ii", $address_id, $user_id);
    $deleted_address_stmt->execute();
    $deleted_address = $deleted_address_stmt->get_result()->fetch_assoc();
    $delete_stmt = $conn->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ?");
    $delete_stmt->bind_param("ii", $address_id, $user_id);

    if ($delete_stmt->execute() && $delete_stmt->affected_rows > 0) {
        if ((int) ($deleted_address['is_default'] ?? 0) === 1) {
            $next_default_result = $conn->query("SELECT id FROM addresses WHERE user_id = $user_id ORDER BY id DESC LIMIT 1");
            $next_default = $next_default_result ? $next_default_result->fetch_assoc() : null;
            if ($next_default) {
                $promote_stmt = $conn->prepare("UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
                $promote_stmt->bind_param("ii", $next_default['id'], $user_id);
                $promote_stmt->execute();
            }
        }
        $msg = "✅ Delivery address deleted successfully.";
        $msg_type = "success";
    } else {
        $msg = "❌ Unable to delete the delivery address.";
        $msg_type = "danger";
    }
}

// ✅ 8. GET ADDRESSES
$address_result = $conn->query("SELECT * FROM addresses WHERE user_id=$user_id ORDER BY is_default DESC, id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings - Account Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; background: #f5f6f8; display: flex; min-height: 100vh; overflow-x: hidden; overflow-y: auto; }

        /* --- MAIN WRAPPER --- */
        .main-wrapper {
            margin-left: 260px !important;
            display: flex !important;
            width: calc(100% - 260px) !important;
            min-height: 100vh;
            height: auto;
            background: #f5f6f8;
        }

        .settings-wrapper { display: block; min-height: 100vh; flex: 1; width: 100%; position: relative; }

        /* --- LEFT SIDEBAR --- */
        .settings-sidebar {
            width: 280px !important;
            min-width: 280px !important;
            display: block !important;
            background: #ffffff !important;
            border-right: 1px solid #e5e7eb;
            padding: 30px 20px;
            height: 100vh;
            position: sticky;
            top: 0;
        }

        /* --- HEADER --- */
        .settings-sidebar .sidebar-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        .settings-sidebar .sidebar-header .title {
            font-weight: 700;
            font-size: 18px;
            color: #1a202c;
        }

        .settings-sidebar .sidebar-header .subtitle {
            font-size: 12px;
            color: #9ca3af;
        }

        .settings-tabs,
        .sidebar-menu {
            list-style: none;
        }

        .settings-tabs a,
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 8px;
            text-decoration: none;
            color: #6b7280;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .settings-tabs a:hover,
        .settings-tabs a.active,
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: #f3f4f6;
            color: #1a202c;
        }

        .settings-tabs a i,
        .sidebar-menu a i {
            width: 18px;
            font-size: 16px;
        }

        /* --- MAIN CONTENT --- */
        .settings-main {
            flex: 1;
            padding: 24px 36px;
            overflow-x: hidden;
            overflow-y: visible;
            max-height: none;
            height: auto;
            min-width: 0;
            position: relative;
            z-index: 2;
            background: linear-gradient(135deg, #f8fbff 0%, #eef4fb 100%);
        }

        @keyframes settingsFadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .settings-header,
        .settings-overview,
        .account-detail-heading,
        .settings-section.active {
            animation: settingsFadeIn 0.45s ease-out both;
        }

        body.dark-mode { background: #25282d; color: #e8e5df; }
        body.dark-mode .main-wrapper,
        body.dark-mode .settings-main { background: #25282d; }
        body.dark-mode .section-title,
        body.dark-mode .settings-header h1,
        body.dark-mode label { color: #e8e5df; }
        body.dark-mode .section-subtitle,
        body.dark-mode .settings-header p { color: #aab2c0; }
        body.dark-mode input,
        body.dark-mode select,
        body.dark-mode .appearance-card { color: #e8e5df; background: #30343a; border-color: #484d55; }
        .appearance-card { padding: 20px; margin-top: 24px; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; }
        .appearance-row { display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .appearance-label { font-weight: 600; color: #1a202c; }
        .appearance-description { margin-top: 5px; color: #9ca3af; font-size: 13px; }
        .mode-toggle { position: relative; width: 48px; height: 26px; flex: 0 0 auto; }
        .mode-toggle input { width: 0; height: 0; opacity: 0; }
        .mode-slider { position: absolute; inset: 0; border-radius: 20px; background: #cbd5e1; cursor: pointer; transition: .2s; }
        .mode-slider::before { content: ''; position: absolute; width: 20px; height: 20px; left: 3px; top: 3px; border-radius: 50%; background: #fff; transition: .2s; }
        .mode-toggle input:checked + .mode-slider { background: #d32f2f; }
        .mode-toggle input:checked + .mode-slider::before { transform: translateX(22px); }
        .mode-state { min-width: 30px; color: #667085; font-size: 12px; font-weight: 600; }
        body.dark-mode .appearance-label,
        body.dark-mode .mode-state { color: #f3f4f6; }

        .appearance-detail-heading::before {
            content: '\f185';
        }

        .settings-main .settings-header-content h1 {
            font-size: 40px;
        }

        .settings-main .section-title,
        .settings-main .account-detail-heading .section-title {
            font-size: 22px;
        }

        .settings-main .section-subtitle,
        .settings-main .settings-header-content p,
        .settings-main label,
        .settings-main input,
        .settings-main select,
        .settings-main button,
        .settings-main .card-copy strong,
        .settings-main .card-copy span,
        .settings-main .appearance-group-title,
        .settings-main .appearance-group-description,
        .settings-main .saved-address-name,
        .settings-main .saved-address-card p {
            font-size: 14px;
        }

        .appearance-settings-card {
            padding: 0;
            background: transparent;
            border: 0;
            border-radius: 0;
        }

        .appearance-group + .appearance-group {
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px solid #edf0f4;
        }

        .appearance-group-title {
            margin: 0;
            color: #263247;
            font-size: 14px;
            font-weight: 700;
        }

        .appearance-group-description {
            margin: 4px 0 12px;
            color: #9ca3af;
            font-size: 12px;
        }

        .theme-options {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .theme-option {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 58px;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            color: #263247;
            text-align: left;
            cursor: pointer;
        }

        .theme-option.active {
            border-color: #8d2638;
            box-shadow: 0 0 0 1px #8d2638;
        }

        .theme-option i {
            color: #8d2638;
            font-size: 18px;
        }

        .theme-option strong,
        .theme-option span {
            display: block;
        }

        .theme-option strong {
            font-size: 12px;
        }

        .theme-option span {
            margin-top: 3px;
            color: #9ca3af;
            font-size: 10px;
        }

        .accent-options {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .accent-option {
            width: 28px;
            height: 28px;
            padding: 0;
            border: 3px solid #fff;
            border-radius: 50%;
            outline: 1px solid transparent;
            cursor: pointer;
        }

        .accent-option.active {
            outline-color: #263247;
        }

        .font-size-control {
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 360px;
        }

        .font-size-label {
            color: #4b5563;
            font-weight: 700;
            line-height: 1;
        }

        .font-size-label.small {
            font-size: 11px;
        }

        .font-size-label.large {
            font-size: 20px;
        }

        .font-size-range {
            flex: 1;
            min-width: 0;
            height: 5px;
            accent-color: #8d2638;
            cursor: pointer;
        }

        .appearance-save-row {
            display: flex;
            justify-content: flex-end;
            margin-top: 22px;
        }

        @media (max-width: 768px) {
            .theme-options {
                grid-template-columns: 1fr;
            }

            .appearance-settings-card {
                padding: 20px;
            }
        }

        .settings-header {
            display: flex;
            align-items: center;
            margin-bottom: 28px;
            padding-top: 6px;
            font-family: Arial, 'Segoe UI', sans-serif;
        }

        .settings-header-icon {
            display: none;
        }

        .settings-back-btn {
            background: transparent;
            border: none;
            color: #4b5563;
            padding: 0 0 12px 0;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            margin-bottom: 8px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
            margin-left: 8px;
            margin-bottom: 12px;
        }

        .settings-header-content h1 {
            font-size: 35px;
            font-weight: 700;
            color: #1a202c;
            margin: 0;
            line-height: 1.1;
        }

        .settings-header-content p {
            font-size: 15px;
            color: #9ca3af;
            margin-top: 4px;
        }

        .settings-overview {
            display: flex;
            flex-direction: column;
            gap: 18px;
            margin-bottom: 28px;
            width: calc(100% - 56px);
            max-width: 1120px;
            margin-left: auto;
            margin-right: auto;
            padding-right: 0;
        }

        .settings-overview.hidden,
        .settings-section.hidden {
            display: none !important;
        }

        .account-detail-heading {
            display: flex;
            align-items: center;
            gap: 16px;
            width: calc(100% - 56px);
            max-width: 1120px;
            margin: 0 auto 18px;
        }

        .account-detail-heading::before {
            content: '\f007';
            width: 50px;
            height: 50px;
            flex: 0 0 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #f1e5e2;
            color: #a52838;
            font-family: 'Font Awesome 6 Free';
            font-size: 22px;
            font-weight: 900;
        }

        .account-detail-copy {
            min-width: 0;
        }

        .account-detail-heading .section-title {
            font-size: 24px;
            line-height: 1.2;
            margin-bottom: 3px !important;
        }

        .account-detail-heading .section-subtitle {
            font-size: 13px;
            line-height: 1.35;
        }

        .account-detail-heading.hidden {
            display: none !important;
        }

        .addresses-detail-heading::before {
            content: '\f041';
        }

        .settings-card {
            width: 100%;
            min-height: 108px;
            background: rgba(255, 255, 255, 0.55);
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 18px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            text-align: left;
            cursor: pointer;
            transition: all 0.2s ease;
            color: inherit;
            box-shadow: 0 8px 22px rgba(52, 72, 96, 0.07);
        }

        .settings-card:hover {
            border-color: rgba(211, 47, 47, 0.35);
            background: #fffafa;
            box-shadow: 0 8px 20px rgba(211, 47, 47, 0.08);
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(52, 72, 96, 0.13);
        }

        .settings-card.active {
            background: #d32f2f;
            border-color: #d32f2f;
            box-shadow: none;
            transform: none;
            box-shadow: 0 10px 24px rgba(211, 47, 47, 0.16);
        }

        .settings-card .icon-wrap {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #fff0f0 0%, #fef2f2 100%);
            color: #d32f2f;
            font-size: 20px;
            flex-shrink: 0;
        }

        .settings-card.active .icon-wrap {
            background: rgba(255, 255, 255, 0.95);
            color: #d32f2f;
        }

        .settings-card .card-copy {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }

        .settings-card .card-copy strong {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
            line-height: 1.3;
        }

        .settings-card .card-copy span {
            font-size: 14px;
            color: #6b7280;
            line-height: 1.4;
        }

        .settings-card.active .card-copy strong,
        .settings-card.active .card-copy span {
            color: #ffffff;
        }

        body.dark-mode .settings-card,
        body.dark-mode .settings-card .card-copy strong {
            color: #e8e5df;
        }

        body.dark-mode .settings-card .card-copy span {
            color: #c2c8d1;
        }

        body.dark-mode .settings-card {
            background: #30343a;
            border-color: #484d55;
        }

        body.dark-mode .settings-card:hover,
        body.dark-mode .settings-card.active {
            background: #373b42;
            border-color: rgba(239, 68, 68, 0.7);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.12);
        }

        body.dark-mode .settings-card .icon-wrap {
            background: linear-gradient(135deg, rgba(239,68,68,0.15), rgba(127,29,29,0.25));
            color: #fca5a5;
        }

        /* --- ALERT --- */
        .custom-alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border-left-color: #10b981;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left-color: #ef4444;
        }

        /* --- CONTENT SECTIONS --- */
        .settings-section {
            background: white;
            border-radius: 12px;
            padding: 24px 28px;
            margin: 0 16px 24px;
            border: 1px solid #e5e7eb;
            display: none;
            width: calc(100% - 32px);
            max-width: 100%;
            box-sizing: border-box;
            box-shadow: 0 10px 26px rgba(52, 72, 96, 0.08);
            animation: settingsFadeIn 0.45s ease-out both;
        }

        .settings-section.active {
            display: block;
        }

        #account.settings-section {
            width: calc(100% - 56px);
            max-width: 1120px;
            margin-left: auto;
            margin-right: auto;
            padding-left: 72px;
            padding-right: 72px;
        }

        #password.settings-section {
            width: calc(100% - 56px);
            max-width: 1120px;
            margin-left: auto;
            margin-right: auto;
            padding: 0 72px 24px;
            background: transparent;
            border: none;
            box-shadow: none;
            box-sizing: border-box;
        }

        #password-detail-heading {
            display: flex;
            align-items: center;
            gap: 16px;
            width: calc(100% - 56px);
            max-width: 1120px;
            margin: 0 auto 18px;
        }

        #password-detail-heading::before {
            content: '\f023';
            width: 50px;
            height: 50px;
            flex: 0 0 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #f1e5e2;
            color: #a52838;
            font-family: 'Font Awesome 6 Free';
            font-size: 22px;
            font-weight: 900;
        }

        #password-detail-heading.hidden,
        #account-detail-heading.hidden {
            display: none !important;
        }

        #password .password-panel-header {
            display: flex;
            align-items: center;
            gap: 16px;
            background: rgba(255, 255, 255, 0.55);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 18px;
            box-shadow: 0 10px 26px rgba(52, 72, 96, 0.06);
            box-sizing: border-box;
        }

        #password .password-panel-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #fbe5e8 0%, #f3d4d9 100%);
            color: #a52838;
            font-size: 20px;
            box-shadow: 0 8px 18px rgba(120, 20, 35, 0.12);
            flex-shrink: 0;
        }

        #password .password-panel-copy {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        #password .password-panel-copy h2 {
            margin: 0;
            font-size: 22px;
            color: #1a202c;
            font-weight: 700;
            line-height: 1.2;
        }

        #password .password-panel-copy p {
            margin: 0;
            font-size: 14px;
            color: #6b7280;
            line-height: 1.4;
        }

        #password .password-form-shell {
            padding-top: 0;
            background: rgba(255, 255, 255, 0.55);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 26px rgba(52, 72, 96, 0.05);
            box-sizing: border-box;
        }

        #password.google-password-section.settings-section {
            padding: 0 0 24px;
        }

        #password .google-account-layout {
            display: block;
            padding: 18px 22px;
            border-color: #dce8f6;
            background: #fff;
        }

        #password .google-account-main {
            min-width: 0;
        }

        #password .google-account-heading {
            display: flex;
            align-items: center;
            gap: 18px;
            min-height: 82px;
            padding: 0 0 18px;
            border-bottom: 1px solid #e4edf7;
        }

        #password .google-account-icon {
            width: 64px;
            height: 64px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            border-radius: 50%;
            background: #eaf2ff;
            color: #4285f4;
            font-size: 31px;
        }

        #password .google-account-heading-copy {
            min-width: 0;
        }

        #password .google-account-heading-line {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        #password .google-account-heading h3 {
            margin: 0;
            color: #1a202c;
            font-size: 20px;
            font-weight: 700;
        }

        #password .google-connected {
            padding: 4px 10px;
            border: 1px solid #c8ecdc;
            border-radius: 14px;
            background: #e8f8f0;
            color: #148452;
            font-size: 12px;
            font-weight: 600;
        }

        #password .google-account-heading p {
            margin: 5px 0 0;
            color: #718096;
            font-size: 13px;
            line-height: 1.4;
        }

        #password .google-account-rows {
            margin-top: 12px;
        }

        #password .google-account-row {
            display: grid;
            grid-template-columns: 38px 150px minmax(0, 1fr);
            align-items: center;
            gap: 12px;
            min-height: 53px;
            border-bottom: 1px solid #e4edf7;
        }

        #password .google-account-row:last-child {
            border-bottom: 0;
        }

        #password .google-account-row-icon {
            color: #6e8db9;
            font-size: 17px;
            text-align: center;
        }

        #password .google-account-row-label {
            color: #7c96b9;
            font-size: 12px;
        }

        #password .google-account-row-value {
            min-width: 0;
            color: #354d6e;
            font-size: 13px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        #password .google-account-security {
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #e4edf7;
        }

        #password .google-security-note {
            flex: 1;
            margin: 0;
            color: #496587;
            font-size: 13px;
            line-height: 1.5;
        }

        #password .google-account-link {
            min-height: 46px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            width: min(320px, 100%);
            padding: 10px 14px;
            border: 1px solid #4285f4;
            border-radius: 8px;
            background: #fff;
            color: #263247;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        #password .google-account-link:hover {
            background: #f8fbff;
        }

        #password .google-account-link .google-link-icon {
            color: #4285f4;
            font-size: 18px;
        }

        #password .google-account-link .external-link-icon {
            margin-left: auto;
            color: #718096;
        }

        .login-activity-card {
            margin-top: 20px;
            padding: 18px 22px;
            border: 1px solid #dce8f6;
            border-radius: 12px;
            background: #fff;
        }

        .login-activity-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 12px;
        }

        .login-activity-heading {
            margin: 0;
            color: #1a202c;
            font-size: 18px;
            font-weight: 700;
        }

        .logout-other-sessions-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex: 0 0 auto;
            padding: 9px 13px;
            border: 1px solid #d32f2f;
            border-radius: 8px;
            background: #fff;
            color: #c62828;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
        }

        .logout-other-sessions-button:hover,
        .logout-other-sessions-button:focus-visible {
            background: #d32f2f;
            color: #fff;
            box-shadow: 0 4px 12px rgba(211, 47, 47, 0.2);
        }

        .logout-other-sessions-button:disabled {
            border-color: #cbd5e1;
            background: #f1f5f9;
            color: #94a3b8;
            cursor: not-allowed;
            box-shadow: none;
        }

        .login-activity-revoked {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 8px;
            border-radius: 12px;
            background: #f1f3f5;
            color: #667085;
            font-size: 11px;
            font-weight: 600;
        }

        .login-activity-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            min-height: 62px;
            padding: 10px 0;
            border-top: 1px solid #e4edf7;
        }

        .login-activity-device {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .login-activity-device-icon {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            border-radius: 50%;
            background: #eef5ff;
            color: #6687b6;
        }

        .login-activity-device-copy {
            min-width: 0;
        }

        .login-activity-device-name {
            display: block;
            color: #354d6e;
            font-size: 13px;
            font-weight: 600;
        }

        .login-activity-current {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 8px;
            border-radius: 12px;
            background: #e8f8f0;
            color: #148452;
            font-size: 11px;
            font-weight: 600;
        }

        .login-activity-time {
            flex: 0 0 auto;
            color: #6680a2;
            font-size: 12px;
            text-align: right;
        }

        .login-activity-empty {
            margin: 0;
            padding: 18px 0;
            border-top: 1px solid #e4edf7;
            color: #718096;
            font-size: 13px;
        }

        .login-activity-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 10px;
        }

        .login-activity-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0;
            border: 0;
            background: transparent;
            color: #4b73a6;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .login-activity-details summary::-webkit-details-marker {
            display: none;
        }

        .login-activity-details summary::marker {
            content: '';
        }

        .login-activity-collapse,
        .login-activity-details[open] .login-activity-expand {
            display: none;
        }

        .login-activity-details[open] .login-activity-collapse {
            display: inline;
        }

        .login-activity-link:hover {
            color: #315d96;
            text-decoration: underline;
        }

        #activity .login-activity-card {
            max-width: 1120px;
            margin: 0 auto;
        }

        @media (max-width: 760px) {
            .login-activity-header {
                align-items: flex-start;
                flex-direction: column;
            }

            #password .google-account-layout {
                padding: 14px;
            }

            #password .google-account-heading {
                gap: 12px;
            }

            #password .google-account-icon {
                width: 52px;
                height: 52px;
                font-size: 26px;
            }

            #password .google-account-row {
                grid-template-columns: 28px minmax(90px, 0.65fr) minmax(0, 1fr);
                gap: 8px;
            }

            #password .google-account-security {
                align-items: stretch;
                flex-direction: column;
                gap: 12px;
            }

            #password .google-account-link {
                width: 100%;
            }

            .login-activity-card {
                padding: 16px;
            }

            .login-activity-row {
                align-items: flex-start;
                flex-direction: column;
                gap: 6px;
            }

            .login-activity-time {
                margin-left: 46px;
                text-align: left;
            }
        }

        #password .password-inner-card {
            background: transparent;
            border: none;
            border-radius: 0;
            padding: 0;
            box-shadow: none;
        }

        #password .password-card-header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px 20px 18px;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 18px;
            background: transparent;
            border-radius: 0;
        }

        #password .password-card-header > div:first-child {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fbe5e8 0%, #f3d4d9 100%);
            color: #a52838;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        #password .password-card-header > div:last-child {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        #password .password-card-header > div:last-child > div:first-child {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
        }

        #password .password-card-header > div:last-child > div:last-child {
            font-size: 14px;
            color: #6b7280;
        }

        #password form {
            padding: 0 20px 22px;
            background: transparent;
            border: none;
            border-radius: 0;
        }

        #password .form-group {
            margin-bottom: 18px;
        }

        #password .button-group {
            justify-content: flex-end;
            gap: 12px;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
            margin-top: 12px;
        }

        #password .password-panel-header,
        #password .password-form-shell,
        #password .password-inner-card,
        #password .password-card-header,
        #password form {
            box-sizing: border-box;
        }

        #account .profile-photo-wrap {
            align-items: flex-start !important;
            gap: 22px !important;
            margin-top: 4px !important;
            margin-bottom: 22px !important;
            padding-bottom: 18px;
            border-bottom: 1px solid #e5e7eb;
        }

        #account .profile-photo-text {
            padding-top: 1px;
        }

        #account .profile-photo-text > div:first-child {
            line-height: 1.35;
        }

        #account .form-row-2 {
            column-gap: 24px;
        }

        .account-header-row {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 22px;
            padding-bottom: 8px;
        }

        .account-header-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, #f6d7dd 0%, #e7b1bd 100%);
            border: 2px solid #eecfd7;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #7a1f2d;
        }

        .account-header-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .account-header-avatar-fallback {
            font-weight: 700;
            color: #7a1f2d;
            font-size: 18px;
            letter-spacing: 0.08em;
        }

        .section-title {
            font-size: 22px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-subtitle {
            font-size: 14px;
            color: #9ca3af;
            margin-bottom: 14px;
        }

        /* --- FORM LAYOUT --- */
        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 10px;
        }

        .form-group {
            margin-bottom: 10px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 15px;
            background: #fafbfc;
            color: #1a202c;
            transition: all 0.2s;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            background: white;
            border-color: #d32f2f;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
        }

        /* --- PASSWORD WRAPPER --- */
        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 40px;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            font-size: 16px;
            z-index: 5;
            transition: color 0.2s;
        }

        .toggle-password:hover {
            color: #d32f2f;
        }

        /* --- CHECKBOX --- */
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-item input[type="checkbox"] {
            width: auto;
            margin: 0;
            cursor: pointer;
        }

        .checkbox-item label {
            margin: 0;
            font-weight: 500;
            cursor: pointer;
            color: #1a202c;
        }

        /* --- BUTTONS --- */
        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .btn-save {
            padding: 12px 28px;
            background: #d32f2f;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-save:hover {
            background: #b71c1c;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(211, 47, 47, 0.25);
            color: white;
        }

        .btn-save:disabled,
        .btn-save:disabled:hover {
            background: #aeb4be;
            color: #f8fafc;
            cursor: not-allowed;
            opacity: 0.75;
            transform: none;
            box-shadow: none;
        }

        .btn-cancel {
            padding: 12px 28px;
            background: white;
            color: #6b7280;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-cancel:hover {
            border-color: #d1d5db;
            color: #1a202c;
        }

        /* --- SECURITY CARD --- */
        .security-card {
            background: linear-gradient(135deg, #f0f9ff 0%, #f5f3ff 100%);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            margin-top: 24px;
        }

        .security-card-title {
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            font-size: 15px;
        }

        .security-card-status {
            color: #10b981;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .security-card-status i {
            font-size: 16px;
        }

        .security-card-description {
            color: #6b7280;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .btn-2fa {
            padding: 10px 20px;
            background: white;
            color: #d32f2f;
            border: 1px solid #d32f2f;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-2fa:hover {
            background: #fff5f5;
        }

        /* --- ADDRESSES PANEL --- */
        #addresses.settings-section {
            width: calc(100% - 56px);
            max-width: 1040px;
            margin-left: auto;
            margin-right: auto;
            padding: 28px 72px;
        }

        #appearance.settings-section {
            width: calc(100% - 56px);
            max-width: 1040px;
            margin-left: auto;
            margin-right: auto;
            padding: 28px 72px;
        }

        .addresses-heading {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 24px;
        }

        .addresses-heading-icon,
        .saved-address-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            color: #8d2638;
            background: #f8e8eb;
        }

        .addresses-heading-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            font-size: 22px;
        }

        .addresses-heading h2,
        .addresses-heading p,
        .addresses-card-header h3,
        .addresses-card-header p {
            margin: 0;
        }

        .addresses-heading h2 {
            color: #1a202c;
            font-size: 24px;
            line-height: 1.2;
        }

        .addresses-heading p,
        .addresses-card-header p {
            color: #9ca3af;
            font-size: 13px;
            margin-top: 4px;
        }

        .addresses-card {
            padding: 0;
            background: #ffffff;
            border: 0;
            border-radius: 0;
        }

        .addresses-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
        }

        .addresses-card-header h3 {
            color: #263247;
            font-size: 16px;
            line-height: 1.3;
        }

        .btn-add-address {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
            padding: 10px 16px;
            border: 0;
            border-radius: 8px;
            background: #8d2638;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-add-address:hover {
            background: #731d2d;
        }

        .address-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 0;
            column-gap: 14px;
            padding: 16px;
            margin-bottom: 18px;
            background: #fff;
            border: 1px solid #edf0f4;
            border-radius: 8px;
        }

        .address-form-backdrop {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(26, 32, 44, 0.34);
        }

        .address-form-backdrop[hidden],
        .address-form[hidden] {
            display: none !important;
        }

        .address-form[aria-hidden="false"] {
            position: fixed;
            z-index: 10000;
            top: 50%;
            left: 50%;
            width: min(640px, calc(100vw - 32px));
            margin: 0;
            transform: translate(-50%, -50%);
            box-shadow: 0 24px 70px rgba(26, 32, 44, 0.24);
        }

        .address-form-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            grid-column: 1 / -1;
            gap: 16px;
            margin-bottom: 4px;
        }

        .address-form-header h3,
        .address-form-header p {
            margin: 0;
        }

        .address-form-header h3 {
            color: #263247;
            font-size: 18px;
        }

        .address-form-header p {
            margin-top: 4px;
            color: #718096;
            font-size: 12px;
        }

        .address-form-close {
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            background: #fff;
            color: #718096;
            cursor: pointer;
        }

        .address-form-close:hover {
            color: #a52838;
            border-color: #e8b9c1;
        }

        .address-form .address-location-group,
        .address-form .address-map-group,
        .address-form .address-default-option {
            grid-column: 1 / -1;
        }

        .saved-address-map-help {
            margin: 8px 0 6px;
            color: #718096;
            font-size: 12px;
            line-height: 1.45;
        }

        #saved-address-map {
            height: 160px;
            border: 1px solid #dbe3ec;
            border-radius: 8px;
            background: #eef3f1;
            overflow: hidden;
        }

        .saved-address-map-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            margin-top: 7px;
            color: #536176;
            font-size: 11px;
            line-height: 1.4;
        }

        .saved-address-map-legend span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .saved-address-store-key,
        .saved-address-coverage-key,
        .saved-address-pin-key {
            width: 10px;
            height: 10px;
            display: inline-block;
            flex: 0 0 auto;
        }

        .saved-address-store-key {
            border-radius: 50%;
            background: #a52838;
        }

        .saved-address-coverage-key {
            border: 1px solid #a52838;
            border-radius: 50%;
            background: rgba(165, 40, 56, 0.12);
        }

        .saved-address-pin-key {
            border: 2px solid #fff;
            border-radius: 50%;
            background: #3388ff;
            box-shadow: 0 0 0 1px #3388ff;
        }

        .saved-address-map-status {
            min-height: 18px;
            margin-top: 6px;
            color: #718096;
            font-size: 12px;
        }

        .saved-address-map-status.is-error {
            color: #a52838;
        }

        .saved-address-range-status {
            min-height: 18px;
            margin-top: 3px;
            color: #536176;
            font-size: 12px;
        }

        .saved-address-range-status.is-error {
            color: #a52838;
            font-weight: 600;
        }

        #saved-address-input {
            min-height: 68px;
            line-height: 1.45;
            resize: vertical;
        }

        .address-form .btn-save {
            grid-column: 1 / -1;
            justify-self: end;
        }

        .address-default-option {
            display: flex;
            align-items: center;
            gap: 8px;
            grid-column: auto;
            margin-top: 2px;
            color: #4b5563;
            font-size: 13px;
            font-weight: 600;
        }

        .address-default-option input {
            width: auto;
            accent-color: #a52838;
        }

        .address-default-option label {
            margin: 0;
            cursor: pointer;
        }

        .saved-address-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px;
            margin-bottom: 10px;
            background: #fff;
            border: 1px solid #e7ebf0;
            border-radius: 8px;
        }

        .saved-address-card:last-child {
            margin-bottom: 0;
        }

        .saved-address-icon {
            width: 38px;
            height: 38px;
            border-radius: 9px;
            font-size: 17px;
        }

        .saved-address-content {
            min-width: 0;
            flex: 1;
        }

        .saved-address-name {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            color: #263247;
            font-size: 14px;
            font-weight: 700;
        }

        .saved-address-card p {
            margin: 3px 0 0;
            color: #718096;
            font-size: 12px;
            line-height: 1.35;
        }

        .address-status {
            padding: 4px 8px;
            border-radius: 12px;
            background: #fbe8eb;
            color: #a52838;
            font-size: 10px;
            font-weight: 700;
        }

        .saved-address-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
        }

        .address-default-button,
        .address-edit-button {
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 7px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 700;
        }

        .address-default-button {
            padding: 0 10px;
            border: 1px solid #e8b9c1;
            background: #fff7f8;
            color: #a52838;
        }

        .address-default-button:hover {
            background: #fbe8eb;
        }

        .address-edit-button {
            width: 32px;
            border: 1px solid #dbe3ec;
            background: #fff;
            color: #64748b;
        }

        .address-edit-button:hover {
            color: #a52838;
            border-color: #e8b9c1;
        }

        .address-delete-button {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #f1cbd1;
            border-radius: 7px;
            background: #fff;
            color: #c0394d;
            cursor: pointer;
        }

        .address-delete-button:hover {
            background: #fff5f6;
        }

        @media (max-width: 1024px) {
            .settings-wrapper { display: block !important; width: 100%; min-height: 100vh;}
            .settings-sidebar { display: none !important;}
            .settings-main { flex: 1 !important; min-width: 0; padding: 40px;}
            .form-row-2 { grid-template-columns: 1fr; }
            .main-wrapper { margin-left: 260px; }
            #account.settings-section {
                width: calc(100% - 44px);
                max-width: none;
                margin-left: auto;
                margin-right: auto;
                padding-left: 48px;
                padding-right: 48px;
            }
            .settings-overview {
                width: calc(100% - 44px);
                max-width: none;
            }

            #addresses.settings-section {
                width: calc(100% - 44px);
                margin-left: auto;
                margin-right: auto;
                padding-left: 48px;
                padding-right: 48px;
            }

            #appearance.settings-section {
                width: calc(100% - 44px);
                margin-left: auto;
                margin-right: auto;
                padding-left: 48px;
                padding-right: 48px;
            }
        }

        @media (max-width: 768px) {
            .settings-sidebar .sidebar-menu a span { display: none; }
            .main-wrapper { margin-left: 260px; }
            .account-detail-heading {
                width: calc(100% - 24px);
                max-width: none;
                margin-left: auto;
                margin-right: auto;
            }
            .settings-overview {
                width: calc(100% - 24px);
            }
            #account.settings-section {
                width: calc(100% - 24px);
                margin-left: auto;
                margin-right: auto;
                padding-left: 24px;
                padding-right: 24px;
            }

            #addresses.settings-section {
                width: calc(100% - 24px);
                padding: 24px;
            }

            #appearance.settings-section {
                width: calc(100% - 24px);
                padding: 24px;
            }

            .addresses-card-header {
                align-items: stretch;
                flex-direction: column;
            }

            .btn-add-address {
                justify-content: center;
            }

            .address-form[aria-hidden="false"] {
                width: calc(100vw - 28px);
                padding: 12px;
            }

            #saved-address-map {
                height: 125px;
            }

            .saved-address-card {
                align-items: flex-start;
                flex-wrap: wrap;
            }

            .saved-address-actions {
                margin-left: 52px;
            }
        }

        @media (max-height: 700px) {
            .address-form[aria-hidden="false"] {
                top: 10px;
                transform: translateX(-50%);
                padding: 10px 14px;
            }

            .address-form .form-group {
                margin-bottom: 5px;
            }

            .address-form .form-group label {
                margin-bottom: 4px;
                font-size: 12px;
            }

            .address-form .form-group input {
                padding: 8px 10px;
                font-size: 13px;
            }

            .saved-address-map-help {
                margin: 4px 0;
                font-size: 11px;
            }

            #saved-address-map {
                height: 100px;
            }

            .address-form .address-form-header {
                margin-bottom: 0;
            }
        }

        .settings-address-lookup {
            position: relative;
        }

        .settings-address-results {
            position: absolute;
            z-index: 30;
            top: 44px;
            left: 0;
            right: 0;
            max-height: 220px;
            overflow-y: auto;
            border: 1px solid #d8e2da;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.14);
        }

        .settings-address-results[hidden] {
            display: none;
        }

        .settings-address-result {
            display: block;
            width: 100%;
            margin: 0;
            padding: 10px 12px;
            border: 0;
            border-bottom: 1px solid #edf0ed;
            background: #fff;
            color: #27332a;
            cursor: pointer;
            font-size: 13px;
            line-height: 1.4;
            text-align: left;
        }

        .settings-address-result:last-child {
            border-bottom: 0;
        }

        .settings-address-result:hover,
        .settings-address-result:focus {
            background: #f0f8f1;
        }

        .settings-address-status {
            min-height: 16px;
            margin: 5px 2px 0;
            color: #667085;
            font-size: 11px;
            line-height: 1.4;
        }

        .settings-address-status a {
            color: #287342;
        }
    </style>
</head>
<body>

    <!-- MAIN NAVIGATION SIDEBAR -->
    <?php include 'sidebar_user.php'; ?>

    <div class="main-wrapper">
    <div class="settings-wrapper">
        <!-- MAIN CONTENT -->
        <div class="settings-main">
            <div class="settings-header" id="settings-page-header" style="display: block;">
                <div class="settings-header-icon"><i class="fas fa-gear"></i></div>
                <div class="settings-header-content">
                    <h1>Settings</h1>
                    <p>Manage your account and preferences</p>
                </div>
            </div>

            <?php if($msg && $active_tab !== 'account'): ?>
                <div class="custom-alert alert-<?= $msg_type ?>"><?= $msg ?></div>
            <?php endif; ?>

            <div class="settings-overview" aria-label="Settings navigation">
                <button type="button" class="settings-card active" data-section="account">
                    <span class="icon-wrap"><i class="fas fa-user"></i></span>
                    <span class="card-copy">
                        <strong>Account Settings</strong>
                        <span>Update your personal information</span>
                    </span>
                </button>
                <button type="button" class="settings-card" data-section="password">
                    <span class="icon-wrap"><i class="fas fa-lock"></i></span>
                    <span class="card-copy">
                        <strong>Password &amp; Security</strong>
                        <span>Manage your password and security</span>
                    </span>
                </button>
                <button type="button" class="settings-card" data-section="addresses">
                    <span class="icon-wrap"><i class="fas fa-map-marker-alt"></i></span>
                    <span class="card-copy">
                        <strong>Addresses</strong>
                        <span>Manage your delivery addresses</span>
                    </span>
                </button>
                <button type="button" class="settings-card" data-section="appearance">
                    <span class="icon-wrap"><i class="fas fa-palette"></i></span>
                    <span class="card-copy">
                        <strong>Appearance</strong>
                        <span>Customize your display preferences</span>
                    </span>
                </button>
            </div>

            <button type="button" id="account-back-button" class="settings-back-btn" data-back="overview" aria-label="Back to Settings"><i class="fas fa-arrow-left"></i> Back to Settings</button>

            <div id="account-detail-heading" class="account-detail-heading hidden">
                <div class="account-detail-copy">
                    <h2 class="section-title" style="margin: 0 0 4px 0;">Account Settings</h2>
                    <p class="section-subtitle" style="margin: 0;">Update your personal information and account details</p>
                </div>
            </div>

            <div id="password-detail-heading" class="account-detail-heading hidden">
                <div class="account-detail-copy">
                    <h2 class="section-title" style="margin: 0 0 4px 0;">Password &amp; Security</h2>
                    <p class="section-subtitle" style="margin: 0;">Keep your account safe and secure.</p>
                </div>
            </div>

            <div id="addresses-detail-heading" class="account-detail-heading addresses-detail-heading hidden">
                <div class="account-detail-copy">
                    <h2 class="section-title" style="margin: 0 0 4px 0;">Addresses</h2>
                    <p class="section-subtitle" style="margin: 0;">Manage your delivery addresses.</p>
                </div>
            </div>

            <div id="appearance-detail-heading" class="account-detail-heading appearance-detail-heading hidden">
                <div class="account-detail-copy">
                    <h2 class="section-title" style="margin: 0 0 4px 0;">Appearance</h2>
                    <p class="section-subtitle" style="margin: 0;">Customize your display settings.</p>
                </div>
            </div>

            <!-- ACCOUNT SETTINGS SECTION -->
            <div id="account" class="settings-section hidden">
                <?php if (!empty($_SESSION['complete_google_profile'])): ?>
                    <div class="custom-alert alert-danger">Please complete all required account details below before continuing to the store.</div>
                <?php endif; ?>
                <?php if($msg && $active_tab === 'account'): ?>
                    <div class="custom-alert alert-<?= $msg_type ?>"><?= $msg ?></div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data" id="account-form">
                    <input type="hidden" name="upload_profile" value="0" id="upload-profile-flag">
                    <div class="profile-photo-wrap" style="display: flex; align-items: center; gap: 22px; margin-bottom: 22px; margin-top: 4px;">
                        <div class="profile-photo-box" style="width: 96px; height: 96px; border-radius: 50%; border: 1px solid #d9dbe1; background: #f3dfe3; color: #8d4655; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; position: relative;">
                            <?php if (!empty($user_data['profile_pic'] ?? '')): ?>
                                <img src="uploads/<?= htmlspecialchars($user_data['profile_pic']) ?>" alt="Profile preview" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <i class="fas fa-user" style="font-size: 38px;"></i>
                            <?php endif; ?>
                            <span style="position: absolute; right: 6px; bottom: 6px; width: 18px; height: 18px; border-radius: 50%; background: #b52d4d; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 10px; border: 2px solid #fff;">+</span>
                        </div>
                        <div class="profile-photo-text" style="display: flex; flex-direction: column; align-items: flex-start; gap: 10px;">
                            <div style="margin: 0; color: #4b5563; font-size: 14px; font-weight: 700; letter-spacing: 0.01em;">Profile Photo</div>
                            <div style="color: #6b7280; font-size: 15px;">Upload a profile photo to personalize your account.</div>
                            <button type="button" id="upload-photo-button" style="display: inline-flex; align-items: center; gap: 8px; background: transparent; border: 1px solid #b52d4d; color: #6b1d2d; border-radius: 8px; padding: 10px 16px; font-weight: 600; font-size: 15px; cursor: pointer;">
                                <i class="fas fa-upload"></i> Upload Photo
                            </button>
                            <input type="file" id="profile-photo-input" name="profile_pic" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                            <div style="color: #718096; font-size: 13px;">JPG, PNG (Max 2MB)</div>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Full Name <span style="color: #d32f2f;">*</span></label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($display_name) ?>" placeholder="e.g. Juan Dela Cruz" minlength="2" maxlength="100" pattern="[A-Za-zÀ-ÿ .'-]+" required>
                        </div>
                        <div class="form-group">
                            <label>Date of Birth <span style="color: #d32f2f;">*</span></label>
                            <input type="date" name="dob" value="<?= htmlspecialchars($user_data['dob'] ?? '') ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Username <span style="color: #d32f2f;">*</span></label>
                            <input type="text" name="username" value="<?= htmlspecialchars($user_data['username'] ?? '') ?>" placeholder="e.g. juandelacruz" minlength="3" maxlength="20" required>
                        </div>
                        <div class="form-group">
                            <label>Phone Number <span style="color: #d32f2f;">*</span></label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>" placeholder="e.g. 09171234567" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" required>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Email Address <span style="color: #d32f2f;">*</span></label>
                            <input type="email" name="email" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>" placeholder="john.doe@example.com" required>
                        </div>
                        <div class="form-group">
                            <label>Gender <span style="color: #d32f2f;">*</span></label>
                            <select name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male" <?= ($user_data['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($user_data['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="button-group" style="justify-content: flex-end; gap: 12px; padding-top: 18px; border-top: 1px solid #e5e7eb; margin-top: 18px;">
                        <button type="button" class="btn-cancel settings-cancel-btn">Cancel</button>
                        <button type="submit" name="update_account" class="btn-save"><i class="fas fa-check"></i> Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- PASSWORD & SECURITY SECTION -->
            <div id="password" class="settings-section hidden <?= $is_google_sign_in ? 'google-password-section' : '' ?>">
                <?php if ($is_google_sign_in): ?>
                <div class="password-form-shell google-account-layout">
                    <div class="google-account-main">
                    <div class="google-account-heading">
                        <span class="google-account-icon"><i class="fab fa-google"></i></span>
                        <div class="google-account-heading-copy">
                            <div class="google-account-heading-line">
                                <h3>Google Account</h3>
                                <span class="google-connected">Connected</span>
                            </div>
                            <p>You signed in using your Google Account.</p>
                            <p>Your password is managed by Google.</p>
                        </div>
                    </div>
                    <div class="google-account-rows">
                        <div class="google-account-row">
                            <i class="far fa-envelope google-account-row-icon" aria-hidden="true"></i>
                            <span class="google-account-row-label">Login Email</span>
                            <strong class="google-account-row-value"><?= htmlspecialchars((string) ($user_data['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="google-account-row">
                            <i class="far fa-user google-account-row-icon" aria-hidden="true"></i>
                            <span class="google-account-row-label">Login Method</span>
                            <strong class="google-account-row-value">Google Account</strong>
                        </div>
                        <div class="google-account-row">
                            <i class="fas fa-shield-alt google-account-row-icon" aria-hidden="true"></i>
                            <span class="google-account-row-label">Account Type</span>
                            <strong class="google-account-row-value"><?= htmlspecialchars($account_type, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    </div>
                    <div class="google-account-security">
                        <p class="google-security-note">You can change your password and security settings through your Google Account.</p>
                        <a class="google-account-link" href="https://myaccount.google.com/" target="_blank" rel="noopener noreferrer">
                            <i class="fab fa-google google-link-icon" aria-hidden="true"></i>
                            <span>Manage Google Account</span>
                            <i class="fas fa-arrow-up-right-from-square external-link-icon" aria-hidden="true"></i>
                        </a>
                    </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="password-form-shell">
                    <div class="password-inner-card">
                        <div>
                            <div class="password-card-header">
                                <div><i class="fas fa-lock"></i></div>
                                <div>
                                    <div>Change Password</div>
                                    <div>Update your password regularly to keep your account secure.</div>
                                </div>
                            </div>

                            <form method="POST">
                                <div class="form-group">
                                    <label>Current Password <span style="color: #d32f2f;">*</span></label>
                                    <div class="password-wrapper">
                                        <input type="password" name="old_password" id="old_p" placeholder="Enter your current password" required>
                                        <i class="fas fa-eye toggle-password" onclick="togglePass('old_p', this)"></i>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>New Password <span style="color: #d32f2f;">*</span></label>
                                    <div class="password-wrapper">
                                        <input type="password" name="password" id="new_p" placeholder="Enter new password (min 6 characters)" required minlength="6">
                                        <i class="fas fa-eye toggle-password" onclick="togglePass('new_p', this)"></i>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Confirm New Password <span style="color: #d32f2f;">*</span></label>
                                    <div class="password-wrapper">
                                        <input type="password" name="confirm_password" id="conf_p" placeholder="Confirm your new password" required>
                                        <i class="fas fa-eye toggle-password" onclick="togglePass('conf_p', this)"></i>
                                    </div>
                                </div>

                                <div class="button-group">
                                    <button type="button" class="btn-cancel settings-cancel-btn">Cancel</button>
                                    <button type="submit" name="change_password" class="btn-save"><i class="fas fa-check"></i> Update Password</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <section class="login-activity-card" aria-labelledby="recent-login-activity-heading">
                    <div class="login-activity-header">
                        <h3 class="login-activity-heading" id="recent-login-activity-heading">Recent Login Activity</h3>
                        <form method="POST" onsubmit="return confirm('Log out all other sessions? This session will remain active.');">
                            <input type="hidden" name="login_activity_csrf_token" value="<?= htmlspecialchars($login_activity_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" name="logout_other_sessions" value="1" class="logout-other-sessions-button" <?= $current_login_token_hash === '' ? 'disabled' : '' ?>>
                                <i class="fas fa-right-from-bracket" aria-hidden="true"></i> Log out all
                            </button>
                        </form>
                    </div>
                    <?php if ($recent_login_activity_rows): ?>
                        <?php foreach ($recent_login_activity_rows as $activity): ?>
                            <div class="login-activity-row">
                                <div class="login-activity-device">
                                    <span class="login-activity-device-icon"><i class="fas fa-laptop" aria-hidden="true"></i></span>
                                    <div class="login-activity-device-copy">
                                        <strong class="login-activity-device-name"><?= htmlspecialchars($activity['device_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php if ($activity['is_current']): ?><span class="login-activity-current">Current session</span><?php endif; ?>
                                        <?php if (!$activity['is_current'] && !empty($activity['revoked_at'])): ?><span class="login-activity-revoked">Logged out</span><?php endif; ?>
                                    </div>
                                </div>
                                <time class="login-activity-time" datetime="<?= htmlspecialchars($activity['logged_in_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($activity['time_label'], ENT_QUOTES, 'UTF-8') ?></time>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="login-activity-empty">No login activity recorded yet.</p>
                    <?php endif; ?>
                    <?php if (count($login_activity_rows) > count($recent_login_activity_rows)): ?>
                        <details class="login-activity-details">
                            <summary class="login-activity-actions login-activity-link">
                                <span class="login-activity-expand">View All Activity</span>
                                <span class="login-activity-collapse">Show Less Activity</span>
                                <span aria-hidden="true">&rarr;</span>
                            </summary>
                            <?php foreach (array_slice($login_activity_rows, count($recent_login_activity_rows)) as $activity): ?>
                                <div class="login-activity-row">
                                    <div class="login-activity-device">
                                        <span class="login-activity-device-icon"><i class="fas fa-laptop" aria-hidden="true"></i></span>
                                        <div class="login-activity-device-copy">
                                            <strong class="login-activity-device-name"><?= htmlspecialchars($activity['device_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if ($activity['is_current']): ?><span class="login-activity-current">Current session</span><?php endif; ?>
                                            <?php if (!$activity['is_current'] && !empty($activity['revoked_at'])): ?><span class="login-activity-revoked">Logged out</span><?php endif; ?>
                                        </div>
                                    </div>
                                    <time class="login-activity-time" datetime="<?= htmlspecialchars($activity['logged_in_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($activity['time_label'], ENT_QUOTES, 'UTF-8') ?></time>
                                </div>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ADDRESSES SECTION -->
            <div id="addresses" class="settings-section hidden">
                <div class="addresses-card">
                    <?php if ($google_address_completion_required): ?>
                        <div class="custom-alert alert-danger">Add a default delivery address and pin it on the map. Delivery is available within 10 km by road from the store.</div>
                    <?php endif; ?>
                    <div class="addresses-card-header">
                        <div>
                            <h3>Saved Addresses</h3>
                            <p><?php echo $google_address_completion_required
                                ? 'Your address will be saved as the default address for checkout.'
                                : 'You can add or remove your saved addresses.'; ?></p>
                        </div>
                        <button type="button" id="add-address-toggle" class="btn-add-address" aria-expanded="false" aria-controls="address-form">
                            <i class="fas fa-plus"></i> <span>Add New Address</span>
                        </button>
                    </div>

                <div id="address-form-backdrop" class="address-form-backdrop" hidden></div>
                <form method="POST" id="address-form" class="address-form" aria-hidden="true" hidden>
                    <input type="hidden" name="address_id" id="address-id-field" value="">
                    <div class="address-form-header">
                        <div>
                            <h3>Add New Address</h3>
                            <p>Enter the delivery details below.</p>
                        </div>
                        <button type="button" id="close-address-form" class="address-form-close" aria-label="Close add address form" title="Close"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="form-group address-recipient-group">
                        <label>Recipient Name</label>
                        <input type="text" name="recipient_name" placeholder="e.g. Juan Dela Cruz" minlength="2" maxlength="100" required>
                    </div>
                    <div class="form-group address-phone-group">
                        <label>Contact Number</label>
                        <input type="tel" name="recipient_phone" placeholder="e.g. 09171234567" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" required>
                    </div>
                    <div class="form-group address-location-group">
                        <label>Add New Address</label>
                        <div class="settings-address-lookup">
                            <textarea name="new_address" id="saved-address-input" rows="2" placeholder="Search address or enter it manually..." minlength="5" maxlength="255" aria-describedby="saved-address-status saved-address-results" required></textarea>
                            <div class="settings-address-results" id="saved-address-results" role="listbox" aria-label="OpenStreetMap address suggestions" hidden></div>
                            <div class="settings-address-status" id="saved-address-status" aria-live="polite"></div>
                        </div>
                    </div>
                    <input type="hidden" name="latitude" id="saved-address-latitude">
                    <input type="hidden" name="longitude" id="saved-address-longitude">
                    <div class="form-group address-map-group">
                        <label>Pin Delivery Location</label>
                        <p class="saved-address-map-help">Select a searched address or click the map to set the exact delivery pin.</p>
                        <div id="saved-address-map" aria-label="Pin this saved delivery address on the map"></div>
                        <div class="saved-address-map-legend" aria-label="Map legend">
                            <span><i class="saved-address-store-key"></i>Store</span>
                            <span><i class="saved-address-coverage-key"></i>Approx. 10 km coverage</span>
                            <span><i class="saved-address-pin-key"></i>Delivery pin</span>
                        </div>
                        <div class="saved-address-map-status" id="saved-address-map-status" aria-live="polite">Search and select your address, or click the map to pin it.</div>
                        <div class="saved-address-range-status" id="saved-address-range-status" aria-live="polite">Delivery addresses must be within 10 km by road from the store.</div>
                    </div>
                    <div class="address-default-option">
                        <input type="checkbox" name="make_default" id="make-default-address">
                        <label for="make-default-address">Set as default address</label>
                    </div>
                    <button type="submit" name="add_address" id="address-submit-add" class="btn-save" disabled><i class="fas fa-plus"></i> Add Address</button>
                    <button type="submit" name="edit_address" id="address-submit-edit" class="btn-save" disabled hidden><i class="fas fa-check"></i> Save Address</button>
                </form>

                <div>
                    <?php if($address_result && $address_result->num_rows > 0): ?>
                        <?php while($row = $address_result->fetch_assoc()): ?>
                            <div class="saved-address-card">
                                <div class="saved-address-icon"><i class="fas fa-home"></i></div>
                                <div class="saved-address-content">
                                    <div class="saved-address-name">
                                        <?= htmlspecialchars($row['recipient_name'] ?? $display_name) ?>
                                        <?php if ((int) ($row['is_default'] ?? 0) === 1): ?>
                                            <span class="address-status">DEFAULT</span>
                                        <?php endif; ?>
                                    </div>
                                    <p><?= htmlspecialchars($row['phone'] ?? 'Not set') ?></p>
                                    <p><?= htmlspecialchars($row['address']) ?></p>
                                </div>
                                <div class="saved-address-actions">
                                    <button type="button" class="address-edit-button" title="Edit address"
                                        data-address-id="<?= (int) $row['id'] ?>"
                                        data-recipient-name="<?= htmlspecialchars($row['recipient_name'] ?? $display_name, ENT_QUOTES, 'UTF-8') ?>"
                                        data-phone="<?= htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-address="<?= htmlspecialchars($row['address'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-latitude="<?= htmlspecialchars((string) ($row['latitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-longitude="<?= htmlspecialchars((string) ($row['longitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-is-default="<?= (int) ($row['is_default'] ?? 0) ?>">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Delete this saved address?');" style="margin: 0;">
                                        <input type="hidden" name="address_id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" name="delete_address" class="address-delete-button" aria-label="Delete saved address" title="Delete saved address"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: #9ca3af; padding: 30px;">No addresses saved yet. Add one above to get started!</p>
                    <?php endif; ?>
                </div>
                </div>
            </div>

            <!-- PLACEHOLDERS FOR OTHER SECTIONS -->
            <div id="payment" class="settings-section">
                <h2 class="section-title"><i class="fas fa-credit-card"></i> Payment Methods</h2>
                <p class="section-subtitle">Manage your saved payment methods</p>
                <p style="color: #9ca3af; text-align: center; padding: 40px;">Payment methods management coming soon...</p>
            </div>

            <div id="notifications" class="settings-section">
                <h2 class="section-title"><i class="fas fa-bell"></i> Notifications</h2>
                <p class="section-subtitle">Manage your notification preferences</p>
                <p style="color: #9ca3af; text-align: center; padding: 40px;">Notification preferences coming soon...</p>
            </div>

            <div id="privacy" class="settings-section">
                <h2 class="section-title"><i class="fas fa-shield-alt"></i> Privacy</h2>
                <p class="section-subtitle">Manage your privacy settings</p>
                <p style="color: #9ca3af; text-align: center; padding: 40px;">Privacy settings coming soon...</p>
            </div>

            <div id="appearance" class="settings-section hidden">
                <div class="appearance-settings-card">
                    <div class="appearance-group">
                        <h3 class="appearance-group-title">Theme</h3>
                        <p class="appearance-group-description">Choose your preferred color theme.</p>
                        <div class="theme-options">
                            <button type="button" class="theme-option" data-theme-choice="light">
                                <i class="fas fa-sun"></i>
                                <span><strong>Light</strong><span>Clean and bright interface.</span></span>
                            </button>
                            <button type="button" class="theme-option" data-theme-choice="dark">
                                <i class="fas fa-moon"></i>
                                <span><strong>Dark</strong><span>Easy on the eyes.</span></span>
                            </button>
                            <button type="button" class="theme-option" data-theme-choice="system">
                                <i class="fas fa-desktop"></i>
                                <span><strong>System</strong><span>Follows your device settings.</span></span>
                            </button>
                        </div>
                    </div>

                    <div class="appearance-group">
                        <h3 class="appearance-group-title">Accent Color</h3>
                        <p class="appearance-group-description">Choose your preferred accent color.</p>
                        <div class="accent-options" role="group" aria-label="Accent color">
                            <button type="button" class="accent-option active" data-accent="#8d2638" style="background: #8d2638;" aria-label="Red"></button>
                            <button type="button" class="accent-option" data-accent="#c56b7b" style="background: #c56b7b;" aria-label="Rose"></button>
                            <button type="button" class="accent-option" data-accent="#7f3f98" style="background: #7f3f98;" aria-label="Purple"></button>
                            <button type="button" class="accent-option" data-accent="#2856a6" style="background: #2856a6;" aria-label="Blue"></button>
                            <button type="button" class="accent-option" data-accent="#4d963b" style="background: #4d963b;" aria-label="Green"></button>
                            <button type="button" class="accent-option" data-accent="#df7b24" style="background: #df7b24;" aria-label="Orange"></button>
                            <button type="button" class="accent-option" data-accent="#718096" style="background: #718096;" aria-label="Slate"></button>
                        </div>
                    </div>

                    <div class="appearance-group">
                        <h3 class="appearance-group-title">Font Size</h3>
                        <p class="appearance-group-description">Adjust the text size for better readability.</p>
                        <div class="font-size-control">
                            <span class="font-size-label small">A</span>
                            <input type="range" id="font-size-range" class="font-size-range" min="0.9" max="1.15" step="0.01" value="1" aria-label="Adjust font size">
                            <span class="font-size-label large">A</span>
                        </div>
                    </div>

                    <div class="appearance-save-row">
                        <button type="button" class="btn-save" id="appearance-save"><i class="fas fa-check"></i> Save Changes</button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        function togglePass(inputId, iconElement) {
            const input = document.getElementById(inputId);
            
            if (input.type === "password") {
                input.type = "text";
                iconElement.classList.remove('fa-eye');
                iconElement.classList.add('fa-eye-slash');
            } else {
                input.type = "password";
                iconElement.classList.remove('fa-eye-slash');
                iconElement.classList.add('fa-eye');
            }
        }

        function switchTab(e, tabName) {
            e.preventDefault();

            // Hide all sections
            document.querySelectorAll('.settings-section').forEach(section => {
                section.classList.remove('active');
            });

            // Remove active class from all links
            document.querySelectorAll('.sidebar-menu a').forEach(link => {
                link.classList.remove('active');
            });

            // Show selected section
            document.getElementById(tabName).classList.add('active');

            // Add active class to clicked link
            e.currentTarget.classList.add('active');
        }

        const requestedTab = <?= json_encode($active_tab) ?>;

        function showOverview() {
            document.querySelector('.settings-overview')?.classList.remove('hidden');
            document.getElementById('account-detail-heading')?.classList.add('hidden');
            document.getElementById('password-detail-heading')?.classList.add('hidden');
            document.getElementById('addresses-detail-heading')?.classList.add('hidden');
            document.getElementById('appearance-detail-heading')?.classList.add('hidden');
            document.querySelectorAll('.settings-section').forEach(section => {
                section.classList.add('hidden');
                section.classList.remove('active');
            });
            document.querySelectorAll('.settings-card').forEach(card => {
                card.classList.remove('active');
            });
            const header = document.getElementById('settings-page-header');
            if (header) header.style.display = 'flex';
            const accountBackButton = document.getElementById('account-back-button');
            if (accountBackButton) accountBackButton.style.display = 'none';
        }

        function setActiveSection(sectionName) {
            const overview = document.querySelector('.settings-overview');
            const targetSection = document.getElementById(sectionName);
            const header = document.getElementById('settings-page-header');
            const accountHeading = document.getElementById('account-detail-heading');
            const passwordHeading = document.getElementById('password-detail-heading');
            const addressesHeading = document.getElementById('addresses-detail-heading');
            const appearanceHeading = document.getElementById('appearance-detail-heading');
            const accountBackButton = document.getElementById('account-back-button');

            if (overview) overview.classList.add('hidden');
            document.querySelectorAll('.account-detail-heading').forEach(heading => {
                heading.classList.add('hidden');
            });
            const activeHeading = {
                account: accountHeading,
                password: passwordHeading,
                addresses: addressesHeading,
                appearance: appearanceHeading
            }[sectionName];
            if (activeHeading) activeHeading.classList.remove('hidden');
            document.querySelectorAll('.settings-section').forEach(section => {
                const isTarget = section.id === sectionName;
                section.classList.toggle('active', isTarget);
                section.classList.toggle('hidden', !isTarget);
            });

            document.querySelectorAll('.settings-card').forEach(card => {
                card.classList.toggle('active', card.dataset.section === sectionName);
            });

            if (header) {
                header.style.display = 'none';
            }
            if (accountBackButton) {
                accountBackButton.style.display = 'inline-flex';
            }

            if (targetSection) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        document.querySelectorAll('.settings-card').forEach(card => {
            card.addEventListener('click', function () {
                setActiveSection(this.dataset.section);
            });
        });

        document.querySelectorAll('.settings-back-btn').forEach(button => {
            button.addEventListener('click', function () {
                showOverview();
            });
        });

        document.querySelectorAll('.settings-cancel-btn').forEach(button => {
            button.addEventListener('click', function () {
                showOverview();
            });
        });

        const addAddressToggle = document.getElementById('add-address-toggle');
        const addressForm = document.getElementById('address-form');
        const addressFormBackdrop = document.getElementById('address-form-backdrop');
        const closeAddressFormButton = document.getElementById('close-address-form');
        const addressIdField = document.getElementById('address-id-field');
        const addressSubmitAdd = document.getElementById('address-submit-add');
        const addressSubmitEdit = document.getElementById('address-submit-edit');
        const defaultAddressCheckbox = document.getElementById('make-default-address');
        const addressFormTitle = addressForm?.querySelector('.address-form-header h3');
        const recipientNameField = addressForm?.querySelector('[name="recipient_name"]');
        const recipientPhoneField = addressForm?.querySelector('[name="recipient_phone"]');
        const addressField = addressForm?.querySelector('[name="new_address"]');
        const addressResults = document.getElementById('saved-address-results');
        const addressStatus = document.getElementById('saved-address-status');
        const addressLatitudeField = document.getElementById('saved-address-latitude');
        const addressLongitudeField = document.getElementById('saved-address-longitude');
        const addressMapStatus = document.getElementById('saved-address-map-status');
        const addressRangeStatus = document.getElementById('saved-address-range-status');
        document.body.append(addressFormBackdrop, addressForm);
        const pharmacyLocation = [<?= PHARMACY_STORE_LATITUDE ?>, <?= PHARMACY_STORE_LONGITUDE ?>];
        const savedAddressMap = L.map('saved-address-map').setView(pharmacyLocation, 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(savedAddressMap);
        const deliveryCoverage = L.circle(pharmacyLocation, {
            radius: 10000,
            color: '#a52838',
            weight: 2,
            opacity: 0.75,
            fillColor: '#a52838',
            fillOpacity: 0.08
        }).addTo(savedAddressMap);
        L.marker(pharmacyLocation).addTo(savedAddressMap)
            .bindPopup('Store: <?= htmlspecialchars(PHARMACY_STORE_ADDRESS, ENT_QUOTES, 'UTF-8') ?><br>Approximate 10 km delivery coverage');
        let savedAddressMarker = null;
        let addressReverseSequence = 0;
        let addressRangeSequence = 0;
        let addressRangePending = false;
        let addressRangeAllowed = false;
        let addressSearchTimer;
        let addressSearchController;

        function updateAddressSaveAvailability() {
            const disabled = addressRangePending || !addressRangeAllowed
                || !addressLatitudeField.value || !addressLongitudeField.value;
            addressSubmitAdd.disabled = disabled;
            addressSubmitEdit.disabled = disabled;
        }

        function resetAddressRangeCheck(message) {
            addressRangeSequence += 1;
            addressRangePending = false;
            addressRangeAllowed = false;
            addressRangeStatus.textContent = message;
            addressRangeStatus.classList.remove('is-error');
            updateAddressSaveAvailability();
        }

        async function checkSavedAddressRoadDistance(point) {
            const sequence = ++addressRangeSequence;
            addressRangePending = true;
            addressRangeAllowed = false;
            addressRangeStatus.textContent = 'Checking road distance from the store…';
            addressRangeStatus.classList.remove('is-error');
            updateAddressSaveAvailability();

            const coordinates = pharmacyLocation[1] + ',' + pharmacyLocation[0]
                + ';' + point[1] + ',' + point[0];
            const routeEndpoints = [
                'https://router.project-osrm.org/route/v1/driving/',
                'https://routing.openstreetmap.de/routed-car/route/v1/driving/'
            ];
            let lastError = null;

            for (const endpoint of routeEndpoints) {
                if (sequence !== addressRangeSequence) return;
                try {
                    const response = await fetch(endpoint + coordinates + '?overview=false', {
                        headers: {'Accept': 'application/json'}
                    });
                    const data = await response.json();
                    if (!response.ok) throw new Error('Routing service returned HTTP ' + response.status + '.');
                    if (!data || data.code !== 'Ok' || !Array.isArray(data.routes) || !data.routes[0]
                        || !Number.isFinite(Number(data.routes[0].distance))) {
                        throw new Error(data && data.message ? data.message : 'No road distance was returned.');
                    }

                    if (sequence !== addressRangeSequence) return;
                    const distanceKm = Number(data.routes[0].distance) / 1000;
                    addressRangePending = false;
                    addressRangeAllowed = distanceKm <= 10;
                    addressRangeStatus.textContent = addressRangeAllowed
                        ? distanceKm.toFixed(1) + ' km by road from store. Address is within the 10 km delivery limit.'
                        : distanceKm.toFixed(1) + ' km by road from store. Outside the 10 km delivery limit; this address cannot be saved.';
                    addressRangeStatus.classList.toggle('is-error', !addressRangeAllowed);
                    updateAddressSaveAvailability();
                    return;
                } catch (error) {
                    lastError = error;
                }
            }

            if (sequence !== addressRangeSequence) return;
            addressRangePending = false;
            addressRangeAllowed = false;
            addressRangeStatus.textContent = 'Could not verify the road distance. Try pinning again before saving.';
            addressRangeStatus.classList.add('is-error');
            updateAddressSaveAvailability();
            console.error('Unable to check the saved address delivery range.', lastError);
        }

        function setSavedAddressPin(latitude, longitude) {
            const point = [Number(latitude), Number(longitude)];
            addressLatitudeField.value = point[0].toFixed(7);
            addressLongitudeField.value = point[1].toFixed(7);
            if (!savedAddressMarker) {
                savedAddressMarker = L.marker(point).addTo(savedAddressMap);
            } else {
                savedAddressMarker.setLatLng(point);
            }
            fitSavedAddressMap();
            checkSavedAddressRoadDistance(point);
        }

        function fitSavedAddressMap() {
            const coverageBounds = deliveryCoverage.getBounds();
            const bounds = savedAddressMarker
                ? L.latLngBounds([
                    coverageBounds.getSouthWest(),
                    coverageBounds.getNorthEast(),
                    savedAddressMarker.getLatLng()
                ])
                : coverageBounds;
            savedAddressMap.fitBounds(bounds, {padding: [18, 18], maxZoom: 12});
        }

        async function reverseGeocodeSavedAddress(latitude, longitude) {
            const sequence = ++addressReverseSequence;
            addressLatitudeField.value = Number(latitude).toFixed(7);
            addressLongitudeField.value = Number(longitude).toFixed(7);
            addressMapStatus.textContent = 'Finding the street address for this pin…';
            addressMapStatus.classList.remove('is-error');

            try {
                const params = new URLSearchParams({
                    format: 'jsonv2',
                    addressdetails: '1',
                    zoom: '18',
                    lat: String(latitude),
                    lon: String(longitude)
                });
                const response = await fetch('https://nominatim.openstreetmap.org/reverse?' + params.toString(), {
                    headers: {'Accept': 'application/json'}
                });
                if (!response.ok) throw new Error('Address lookup returned HTTP ' + response.status + '.');
                const result = await response.json();
                if (sequence !== addressReverseSequence) return;
                if (!result || typeof result.display_name !== 'string' || !result.display_name.trim()) {
                    throw new Error('No street address was found for this pin.');
                }

                addressField.value = result.display_name.slice(0, 255);
                addressMapStatus.textContent = '';
            } catch (error) {
                if (sequence !== addressReverseSequence) return;
                addressLatitudeField.value = '';
                addressLongitudeField.value = '';
                addressMapStatus.textContent = 'Could not find an address for this pin. Try another spot or select a search result.';
                addressMapStatus.classList.add('is-error');
                console.error('Unable to reverse geocode the saved address pin.', error);
            }
        }

        savedAddressMap.on('click', function (event) {
            setSavedAddressPin(event.latlng.lat, event.latlng.lng);
            reverseGeocodeSavedAddress(event.latlng.lat, event.latlng.lng);
        });

        addressField?.addEventListener('input', function () {
            addressReverseSequence += 1;
            addressLatitudeField.value = '';
            addressLongitudeField.value = '';
            resetAddressRangeCheck('Select or pin a location to check the 10 km road delivery limit.');
            if (savedAddressMarker) {
                savedAddressMap.removeLayer(savedAddressMarker);
                savedAddressMarker = null;
            }
            clearTimeout(addressSearchTimer);
            addressSearchController?.abort();
            addressResults?.replaceChildren();
            if (addressResults) addressResults.hidden = true;

            const query = addressField.value.trim();
            if (addressStatus) {
                addressStatus.textContent = query.length >= 4
                    ? 'Searching OpenStreetMap...'
                    : '';
            }
            if (query.length < 4) return;

            addressSearchTimer = setTimeout(async function () {
                addressSearchController = new AbortController();
                try {
                    const params = new URLSearchParams({
                        q: query,
                        format: 'jsonv2',
                        addressdetails: '1',
                        limit: '5',
                        countrycodes: 'ph'
                    });
                    const response = await fetch('https://nominatim.openstreetmap.org/search?' + params.toString(), {
                        headers: { 'Accept': 'application/json' },
                        signal: addressSearchController.signal
                    });
                    if (!response.ok) throw new Error('Address search unavailable');

                    const places = await response.json();
                    if (addressField.value.trim() !== query) return;
                    addressResults?.replaceChildren();
                    places.forEach(function (place) {
                        const result = document.createElement('button');
                        result.type = 'button';
                        result.className = 'settings-address-result';
                        result.setAttribute('role', 'option');
                        result.textContent = place.display_name;
                        result.addEventListener('click', function () {
                            addressReverseSequence += 1;
                            addressField.value = place.display_name.slice(0, 255);
                            setSavedAddressPin(place.lat, place.lon);
                            if (addressResults) {
                                addressResults.replaceChildren();
                                addressResults.hidden = true;
                            }
                            if (addressStatus) {
                                const mapLink = document.createElement('a');
                                mapLink.href = 'https://www.openstreetmap.org/?mlat=' + encodeURIComponent(place.lat) + '&mlon=' + encodeURIComponent(place.lon) + '#map=17/' + encodeURIComponent(place.lat) + '/' + encodeURIComponent(place.lon);
                                mapLink.target = '_blank';
                                mapLink.rel = 'noopener noreferrer';
                                mapLink.textContent = 'View selected location on OpenStreetMap';
                                addressStatus.replaceChildren(mapLink, document.createTextNode(' · © OpenStreetMap contributors'));
                            }
                            addressMapStatus.textContent = '';
                            addressMapStatus.classList.remove('is-error');
                        });
                        addressResults?.appendChild(result);
                    });
                    if (addressResults) addressResults.hidden = places.length === 0;
                    if (addressStatus) {
                        addressStatus.textContent = places.length
                            ? 'Select an address to use it. © OpenStreetMap contributors.'
                            : 'No matching addresses found. You can enter your address manually.';
                    }
                } catch (error) {
                    if (error.name !== 'AbortError' && addressStatus) {
                        addressStatus.textContent = 'Address search is unavailable right now. You can enter your address manually.';
                    }
                }
            }, 800);
        });

        addressForm?.addEventListener('submit', function (event) {
            if (!addressLatitudeField.value || !addressLongitudeField.value
                || addressRangePending || !addressRangeAllowed) {
                event.preventDefault();
                addressRangeStatus.textContent = addressRangePending
                    ? 'Wait for the road distance check to finish before saving.'
                    : !addressLatitudeField.value || !addressLongitudeField.value
                        ? 'Please select or pin this address on the map before saving.'
                        : 'This address is outside the 10 km road delivery limit or its range could not be verified.';
                addressRangeStatus.classList.add('is-error');
            }
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.settings-address-lookup') && addressResults) {
                addressResults.hidden = true;
            }
        });

        function setAddressFormMode(mode, address = null) {
            const isEdit = mode === 'edit';
            if (addressIdField) addressIdField.value = isEdit ? address.id : '';
            if (recipientNameField) recipientNameField.value = isEdit ? address.recipientName : '';
            if (recipientPhoneField) recipientPhoneField.value = isEdit ? address.phone : '';
            if (addressField) addressField.value = isEdit ? address.address : '';
            addressReverseSequence += 1;
            if (isEdit && address.latitude && address.longitude) {
                setSavedAddressPin(address.latitude, address.longitude);
                addressMapStatus.textContent = 'Saved pin loaded. Click the map to adjust it.';
                addressMapStatus.classList.remove('is-error');
            } else {
                addressLatitudeField.value = '';
                addressLongitudeField.value = '';
                resetAddressRangeCheck('Select or pin a location to check the 10 km road delivery limit.');
                if (savedAddressMarker) {
                    savedAddressMap.removeLayer(savedAddressMarker);
                    savedAddressMarker = null;
                }
                savedAddressMap.setView(pharmacyLocation, 11);
                addressMapStatus.textContent = isEdit
                    ? 'This address has no saved pin yet. Select the address or click the map.'
                    : 'Search and select your address, or click the map to pin it.';
                addressMapStatus.classList.remove('is-error');
            }
            if (defaultAddressCheckbox) defaultAddressCheckbox.checked = isEdit && address.isDefault === '1';
            if (addressFormTitle) addressFormTitle.textContent = isEdit ? 'Edit Address' : 'Add New Address';
            if (addressSubmitAdd) addressSubmitAdd.hidden = isEdit;
            if (addressSubmitEdit) addressSubmitEdit.hidden = !isEdit;
        }

        function setAddressFormOpen(isOpen) {
            if (!addAddressToggle || !addressForm) {
                return;
            }

            const icon = addAddressToggle.querySelector('i');
            const label = addAddressToggle.querySelector('span');

            addressForm.toggleAttribute('hidden', !isOpen);
            addressForm.setAttribute('aria-hidden', String(!isOpen));
            if (addressFormBackdrop) {
                addressFormBackdrop.toggleAttribute('hidden', !isOpen);
            }

            addAddressToggle.setAttribute('aria-expanded', String(isOpen));
            if (icon) {
                icon.classList.toggle('fa-plus', !isOpen);
                icon.classList.toggle('fa-minus', isOpen);
            }
            if (label) {
                label.textContent = isOpen ? 'Close Add Address' : 'Add New Address';
            }

            if (isOpen) {
                window.setTimeout(function () {
                    savedAddressMap.invalidateSize();
                    fitSavedAddressMap();
                }, 0);
                addressForm.querySelector('input')?.focus();
            }
        }

        if (addAddressToggle && addressForm) {
            addAddressToggle.addEventListener('click', function () {
                if (addressForm.hasAttribute('hidden')) {
                    setAddressFormMode('add');
                }
                setAddressFormOpen(addressForm.hasAttribute('hidden'));
            });
        }

        document.querySelectorAll('.address-edit-button').forEach(button => {
            button.addEventListener('click', function () {
                setAddressFormMode('edit', {
                    id: button.dataset.addressId,
                    recipientName: button.dataset.recipientName || '',
                    phone: button.dataset.phone || '',
                    address: button.dataset.address || '',
                    latitude: button.dataset.latitude || '',
                    longitude: button.dataset.longitude || '',
                    isDefault: button.dataset.isDefault || '0'
                });
                setAddressFormOpen(true);
            });
        });

        if (closeAddressFormButton) {
            closeAddressFormButton.addEventListener('click', function () {
                setAddressFormOpen(false);
            });
        }

        if (addressFormBackdrop) {
            addressFormBackdrop.addEventListener('click', function () {
                setAddressFormOpen(false);
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && addressForm && !addressForm.hasAttribute('hidden')) {
                setAddressFormOpen(false);
            }
        });

        const uploadPhotoButton = document.getElementById('upload-photo-button');
        const profilePhotoInput = document.getElementById('profile-photo-input');
        const accountForm = document.getElementById('account-form');
        const uploadProfileFlag = document.getElementById('upload-profile-flag');

        if (uploadPhotoButton && profilePhotoInput && accountForm && uploadProfileFlag) {
            uploadPhotoButton.addEventListener('click', function () {
                profilePhotoInput.click();
            });

            profilePhotoInput.addEventListener('change', function () {
                const selectedFile = profilePhotoInput.files[0];
                if (!selectedFile) {
                    return;
                }

                if (!selectedFile.type.startsWith('image/') || selectedFile.size > 2 * 1024 * 1024) {
                    alert('Please choose a valid image up to 2MB.');
                    profilePhotoInput.value = '';
                    return;
                }

                uploadProfileFlag.value = '1';
                accountForm.submit();
            });
        }

        if (requestedTab && document.getElementById(requestedTab)) {
            setActiveSection(requestedTab);
        } else {
            showOverview();
        }
        if (requestedTab === 'addresses' && <?= json_encode($google_address_completion_required) ?>) {
            setAddressFormMode('add');
            if (recipientNameField) recipientNameField.value = <?= json_encode($display_name) ?>;
            if (recipientPhoneField) recipientPhoneField.value = <?= json_encode((string) ($user_data['phone'] ?? '')) ?>;
            if (defaultAddressCheckbox) defaultAddressCheckbox.checked = true;
            setAddressFormOpen(true);
        }

        const themeOptions = document.querySelectorAll('[data-theme-choice]');
        const accentOptions = document.querySelectorAll('[data-accent]');
        const fontSizeRange = document.getElementById('font-size-range');
        const appearanceSaveButton = document.getElementById('appearance-save');
        const savedTheme = localStorage.getItem('appearanceTheme') || 'light';
        const savedAccent = localStorage.getItem('appearanceAccent') || '#8d2638';
        const savedFontScale = localStorage.getItem('appearanceFontScale') || '1';

        function applyTheme(theme) {
            window.applyGlobalAppearance(
                theme,
                document.querySelector('[data-accent].active')?.dataset.accent || savedAccent,
                fontSizeRange?.value || savedFontScale
            );
            themeOptions.forEach(option => option.classList.toggle('active', option.dataset.themeChoice === theme));
        }

        function applyAccent(accent) {
            window.applyGlobalAppearance(
                document.querySelector('[data-theme-choice].active')?.dataset.themeChoice || savedTheme,
                accent,
                fontSizeRange?.value || savedFontScale
            );
            accentOptions.forEach(option => option.classList.toggle('active', option.dataset.accent === accent));
        }

        function applyFontSize(fontScale) {
            const scale = Number(fontScale).toFixed(2);
            if (fontSizeRange) fontSizeRange.value = scale;
            window.applyGlobalAppearance(
                document.querySelector('[data-theme-choice].active')?.dataset.themeChoice || savedTheme,
                document.querySelector('[data-accent].active')?.dataset.accent || savedAccent,
                scale
            );
        }

        applyTheme(savedTheme);
        applyAccent(savedAccent);
        applyFontSize(savedFontScale);

        themeOptions.forEach(option => {
            option.addEventListener('click', function () {
                applyTheme(option.dataset.themeChoice);
            });
        });

        accentOptions.forEach(option => {
            option.addEventListener('click', function () {
                applyAccent(option.dataset.accent);
            });
        });

        if (fontSizeRange) {
            fontSizeRange.addEventListener('input', function () {
                applyFontSize(fontSizeRange.value);
            });
        }

        if (appearanceSaveButton) {
            appearanceSaveButton.addEventListener('click', function () {
                const activeTheme = document.querySelector('[data-theme-choice].active')?.dataset.themeChoice || 'light';
                const activeAccent = document.querySelector('[data-accent].active')?.dataset.accent || '#8d2638';
                const activeFontScale = fontSizeRange?.value || '1';
                localStorage.setItem('appearanceTheme', activeTheme);
                localStorage.setItem('appearanceAccent', activeAccent);
                localStorage.setItem('appearanceFontScale', activeFontScale);
                window.applyGlobalAppearance(activeTheme, activeAccent, activeFontScale);
            });
        }
    </script>

    </div>
    </div>

</body>
</html>