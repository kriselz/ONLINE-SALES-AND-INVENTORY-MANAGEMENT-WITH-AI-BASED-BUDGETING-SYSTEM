<?php
session_start();
include 'config.php';
require_once __DIR__ . '/delivery_pricing.php';

$errors = [];
$field_errors = [];
$form_values = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'address' => '',
    'phone' => '',
    'dob' => '',
    'gender' => '',
];
$success = "";

foreach ([
    'dob' => 'DATE NULL',
    'gender' => 'VARCHAR(20) NULL',
] as $profile_column => $profile_definition) {
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE '$profile_column'");
    if (!$column_check || ($column_check->num_rows === 0
        && !$conn->query("ALTER TABLE users ADD COLUMN $profile_column $profile_definition"))) {
        $errors[] = 'Registration setup is incomplete. Please run the latest database migration first.';
        break;
    }
}

$addressTableReady = $conn->query("CREATE TABLE IF NOT EXISTS addresses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    recipient_name VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    address TEXT NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!$addressTableReady) {
    $errors[] = 'Registration setup is incomplete. Please run the latest database migration first.';
} else {
    foreach ([
        'recipient_name' => 'VARCHAR(255) NULL',
        'phone' => 'VARCHAR(20) NULL',
        'latitude' => 'DECIMAL(10,7) NULL',
        'longitude' => 'DECIMAL(10,7) NULL',
        'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ] as $address_column => $address_definition) {
        $column_check = $conn->query("SHOW COLUMNS FROM addresses LIKE '$address_column'");
        if (!$column_check || ($column_check->num_rows === 0
            && !$conn->query("ALTER TABLE addresses ADD COLUMN $address_column $address_definition"))) {
            $errors[] = 'Registration setup is incomplete. Please run the latest database migration first.';
            break;
        }
    }
}

if (isset($_POST['register'])) {

    // SANITIZE INPUT
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $dob = trim($_POST['dob'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $latitudeInput = trim((string)($_POST['latitude'] ?? ''));
    $longitudeInput = trim((string)($_POST['longitude'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');
    $form_values = [
        'full_name' => $full_name,
        'username' => $username,
        'email' => $email,
        'address' => $address,
        'phone' => $phone,
        'dob' => $dob,
        'gender' => $gender,
    ];

    // VALIDATIONS
    if ($full_name === '') {
        $field_errors['full_name'] = 'Full name is required.';
    } elseif (!preg_match("/^[\\p{L}][\\p{L} .'-]{1,99}$/u", $full_name)) {
        $field_errors['full_name'] = 'Please enter your real full name, for example Juan Dela Cruz.';
    }

    if ($username === '') {
        $field_errors['username'] = 'Username is required.';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $field_errors['username'] = 'Username must be between 3 and 50 characters.';
    }

    if ($email === '') {
        $field_errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $field_errors['email'] = 'Please enter a valid email address.';
    }

    if ($address === '') {
        $field_errors['address'] = 'Address is required.';
    } elseif (strlen($address) < 5 || strlen($address) > 255) {
        $field_errors['address'] = 'Address must be between 5 and 255 characters.';
    }

    if ($phone === '') {
        $field_errors['phone'] = 'Phone number is required.';
    } elseif (!preg_match("/^09[0-9]{9}$/", $phone)) {
        $field_errors['phone'] = 'Enter a valid PH phone number (09XXXXXXXXX).';
    }

    $dobDate = $dob !== '' ? DateTime::createFromFormat('!Y-m-d', $dob) : false;
    $dobErrors = $dob !== '' ? DateTime::getLastErrors() : false;
    if ($dob === '' || !$dobDate || $dobDate->format('Y-m-d') !== $dob
        || ($dobErrors !== false && ($dobErrors['warning_count'] > 0 || $dobErrors['error_count'] > 0))
        || $dobDate > new DateTime('today')) {
        $field_errors['dob'] = 'Enter a valid date of birth that is not in the future.';
    }

    if (!in_array($gender, ['Male', 'Female'], true)) {
        $field_errors['gender'] = 'Please select a valid gender.';
    }

    if (!is_numeric($latitudeInput) || !is_numeric($longitudeInput)
        || (float)$latitudeInput < -90 || (float)$latitudeInput > 90
        || (float)$longitudeInput < -180 || (float)$longitudeInput > 180) {
        $field_errors['address'] = 'Type your address and select a search suggestion.';
    }

    if ($password === '') {
        $field_errors['password'] = 'Password is required.';
    } else {
        $password_bytes = strlen($password);
        $missing_password_requirements = [];
        if ($password_bytes < 8) {
            $missing_password_requirements[] = 'at least 8 characters';
        }
        if ($password_bytes > 72) {
            $missing_password_requirements[] = 'no more than 72 bytes';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $missing_password_requirements[] = 'an uppercase letter';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $missing_password_requirements[] = 'a lowercase letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $missing_password_requirements[] = 'a number';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $missing_password_requirements[] = 'a special character';
        }
        if (!empty($missing_password_requirements)) {
            $field_errors['password'] = 'Password requirements not met: ' . implode(', ', $missing_password_requirements) . '.';
        }
    }

    if ($confirm_password === '') {
        $field_errors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== $confirm_password) {
        $field_errors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($errors) && empty($field_errors)) {
        try {
            $roadDistanceKm = deliveryRoadDistanceKm((float)$latitudeInput, (float)$longitudeInput);
            if (deliveryFeeForDistance($roadDistanceKm) === null) {
                $field_errors['address'] = 'Delivery is available only within 10 km by road from the store.';
            }
        } catch (RuntimeException $exception) {
            error_log('Registration address road-distance validation failed: ' . $exception->getMessage());
            $field_errors['address'] = 'Unable to verify the road distance right now. Please try again.';
        }
    }

    if (empty($errors) && empty($field_errors)) {
        $duplicate_check = $conn->prepare("SELECT EXISTS(SELECT 1 FROM users WHERE username=?) AS username_exists, EXISTS(SELECT 1 FROM users WHERE email=?) AS email_exists, EXISTS(SELECT 1 FROM users WHERE phone=?) AS phone_exists");
        if (!$duplicate_check) {
            $errors[] = 'Unable to validate account details. Please try again.';
        } else {
            $duplicate_check->bind_param('sss', $username, $email, $phone);
            if (!$duplicate_check->execute()) {
                $errors[] = 'Unable to validate account details. Please try again.';
            } else {
                $duplicates = $duplicate_check->get_result()->fetch_assoc();
                if (!empty($duplicates['username_exists'])) {
                    $field_errors['username'] = 'This username is already in use.';
                }
                if (!empty($duplicates['email_exists'])) {
                    $field_errors['email'] = 'This email is already registered.';
                }
                if (!empty($duplicates['phone_exists'])) {
                    $field_errors['phone'] = 'This phone number is already registered.';
                }
            }
        }
    }

    if (empty($errors) && empty($field_errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $otp = (string) random_int(100000, 999999);
        $otpExpiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'full_name'");
        if ($column_check && $column_check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN full_name VARCHAR(255) NULL");
        }

        $stmt = $conn->prepare("INSERT INTO users (full_name, username, email, password, role, address, phone, dob, gender, otp_code, otp_expiry, email_verified, is_active) VALUES (?, ?, ?, ?, 'user', ?, ?, ?, ?, ?, ?, 0, 0)");
        if (!$stmt) {
            $errors[] = 'Registration setup is incomplete. Please run the latest database migration first.';
        } else {
            $latitude = (float)$latitudeInput;
            $longitude = (float)$longitudeInput;
            $registrationSaved = false;
            if ($conn->begin_transaction()) {
                $stmt->bind_param("ssssssssss", $full_name, $username, $email, $hashed_password, $address, $phone, $dob, $gender, $otp, $otpExpiry);
                if ($stmt->execute()) {
                    $newUserId = (int)$stmt->insert_id;
                    $addressStmt = $conn->prepare('INSERT INTO addresses (user_id, recipient_name, phone, address, latitude, longitude, is_default) VALUES (?, ?, ?, ?, ?, ?, 1)');
                    if ($addressStmt) {
                        $addressStmt->bind_param('isssdd', $newUserId, $full_name, $phone, $address, $latitude, $longitude);
                        $registrationSaved = $addressStmt->execute() && $conn->commit();
                        if (!$registrationSaved) {
                            error_log('Registration default address insert failed: ' . $addressStmt->error);
                        }
                        $addressStmt->close();
                    } else {
                        error_log('Registration default address preparation failed: ' . $conn->error);
                    }
                } else {
                    error_log('Registration user insert failed: ' . $stmt->error);
                }

                if (!$registrationSaved) {
                    $conn->rollback();
                }
            } else {
                error_log('Registration transaction could not start: ' . $conn->error);
            }

            if ($registrationSaved) {
                $errorInfo = '';
                if (sendOtpEmail($email, $otp, $errorInfo)) {
                    header('Location: registration_verify.php?email=' . urlencode($email));
                    exit();
                }

                $deleteAddress = $conn->prepare('DELETE FROM addresses WHERE user_id = ?');
                if ($deleteAddress) {
                    $deleteAddress->bind_param('i', $newUserId);
                    if (!$deleteAddress->execute()) {
                        error_log('Unverified registration address cleanup failed: ' . $deleteAddress->error);
                    }
                    $deleteAddress->close();
                } else {
                    error_log('Unverified registration address cleanup preparation failed: ' . $conn->error);
                }
                $delete = $conn->prepare("DELETE FROM users WHERE id=?");
                if ($delete) {
                    $delete->bind_param('i', $newUserId);
                    if (!$delete->execute()) {
                        error_log('Unverified registration cleanup failed: ' . $delete->error);
                    }
                    $delete->close();
                } else {
                    error_log('Unverified registration cleanup preparation failed: ' . $conn->error);
                }
                error_log('Registration OTP email failed: ' . $errorInfo);
                $errors[] = 'Registration email failed: ' . htmlspecialchars($errorInfo, ENT_QUOTES, 'UTF-8');
            } else {
                $errors[] = 'Unable to save your account and delivery address. Please try again.';
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Register</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: linear-gradient(rgba(27, 20, 20, 0.28), rgba(27, 20, 20, 0.28)), url('images/background.jpg') center / cover fixed;
    min-height: 100vh;
    height: auto;
}

.container {
    width: 100%;
    min-height: 100vh;
    height: auto;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 28px 20px;
}

.box {
    width: min(420px, 100%);
    background: rgba(255, 255, 255, 0.68);
    border: 1px solid rgba(255, 255, 255, 0.72);
    border-radius: 20px;
    padding: 35px 30px;
    text-align: center;
    box-shadow: 0 15px 35px rgba(0,0,0,0.2);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}

@media (max-width: 480px) {
    .container { padding: 16px 12px; }
    .box { padding: 28px 20px; border-radius: 16px; }
    h2 { font-size: 32px; }
}

.box::before {
    content: "";
    display: block;
    width: 70px;
    height: 70px;
    margin: 0 auto 15px;
    background: url('images/logo.png') no-repeat center;
    background-size: contain;
}

h2 {
    font-size: 38px;
    font-weight: bold;
    color: #222;
    margin-bottom: 8px;
}

h2::after {
    content: "JOIN JOSEFINA FARMACIA COMMUNITY";
    display: block;
    font-size: 12px;
    font-weight: bold;
    color: #34a853;
    margin-top: 10px;
    letter-spacing: 1px;
}

input,
select {
    width: 100%;
    padding: 15px;
    margin: 6px 0 9px;
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    background: #fafafa;
    font-size: 15px;
    outline: none;
}

select {
    color: #344054;
}

.field-label {
    display: block;
    margin: 9px 0 0;
    color: #344054;
    font-size: 13px;
    font-weight: 700;
    text-align: left;
}

input:focus {
    border: 1px solid #34a853;
    background: #fff;
}

select:focus {
    border: 1px solid #34a853;
    background: #fff;
}

input.input-error,
input.input-error:focus,
select.input-error,
select.input-error:focus {
    border-color: #c62828;
    background: #fff8f8;
}

.field-error {
    display: block;
    margin: -4px 0 8px;
    color: #c62828;
    font-size: 12px;
    line-height: 1.4;
    text-align: left;
}

.address-lookup {
    position: relative;
    text-align: left;
}

.address-range-status {
    margin: 4px 0 7px;
    color: #667085;
    font-size: 12px;
    line-height: 1.45;
}

.address-range-status.is-error {
    color: #c62828;
}

.address-results {
    position: absolute;
    z-index: 10;
    top: calc(100% - 5px);
    left: 0;
    right: 0;
    max-height: 220px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #d8e2da;
    border-radius: 8px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
}

.address-results[hidden] {
    display: none;
}

.address-result {
    display: block;
    width: 100%;
    margin: 0;
    padding: 10px 12px;
    border: 0;
    border-bottom: 1px solid #edf0ed;
    border-radius: 0;
    background: #fff;
    box-shadow: none;
    color: #27332a;
    cursor: pointer;
    font-size: 13px;
    font-weight: 400;
    line-height: 1.4;
    text-align: left;
}

.address-result:last-child {
    border-bottom: 0;
}

.address-result:hover,
.address-result:focus {
    background: #f0f8f1;
    opacity: 1;
}

.address-lookup-status {
    min-height: 16px;
    margin: -3px 0 4px;
    color: #667085;
    font-size: 11px;
    line-height: 1.4;
}

.address-lookup-status:empty {
    display: none;
}

.address-lookup-status a {
    color: #287342;
}

.password-strength {
    display: none;
    margin: -2px 0 10px;
    text-align: left;
    color: #667085;
    font-size: 12px;
}

.password-strength.is-visible {
    display: block;
}

.password-strength-label {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
    font-weight: 700;
}

.password-strength-label strong {
    color: #667085;
}

.password-strength[data-strength="weak"] .password-strength-label strong {
    color: #c62828;
}

.password-strength[data-strength="fair"] .password-strength-label strong {
    color: #a15c00;
}

.password-strength[data-strength="strong"] .password-strength-label strong {
    color: #287342;
}

.password-strength-meter {
    display: flex;
    gap: 5px;
    margin-bottom: 8px;
}

.password-strength-meter span {
    height: 5px;
    flex: 1;
    border-radius: 5px;
    background: #e5e7eb;
}

.password-strength[data-strength="weak"] .password-strength-meter span:nth-child(-n+2) {
    background: #d64545;
}

.password-strength[data-strength="fair"] .password-strength-meter span:nth-child(-n+4) {
    background: #e2a52e;
}

.password-strength[data-strength="strong"] .password-strength-meter span {
    background: #34a853;
}

.password-requirements {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 10px;
    padding-left: 16px;
    line-height: 1.4;
}

.password-requirements li.is-met {
    color: #287342;
}

.password-requirements li.is-unmet {
    color: #667085;
}

.password-requirements li::marker {
    content: "•  ";
}

.password-requirements li.is-met::marker {
    content: "✓  ";
}

button {
    width: 100%;
    padding: 15px;
    margin-top: 15px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(to right, #34a853, #2d8f46);
    color: white;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(52,168,83,0.2);
    transition: 0.3s;
}

button:hover {
    opacity: 0.95;
}

button:disabled {
    background: #98a79b;
    box-shadow: none;
    cursor: not-allowed;
}

.error {
    background: #fff1f1;
    padding: 12px;
    margin-bottom: 15px;
    border-left: 5px solid #e53935;
    border-radius: 8px;
    text-align: left;
}

.error p {
    font-size: 14px;
    color: #c62828;
    margin: 4px 0;
}

.success {
    background: #f1fff3;
    padding: 12px;
    margin-bottom: 15px;
    border-left: 5px solid #34a853;
    border-radius: 8px;
}

p {
    margin-top: 18px;
    font-size: 14px;
    color: #666;
}

a {
    text-decoration: none;
    color: #c0392b;
    font-weight: bold;
}

a:hover {
    text-decoration: underline;
}
</style>
</head>

<body>

<div class="container">
<div class="box">

<h2>Create Account</h2>

<!-- DISPLAY ERRORS -->
<?php
if (!empty($errors)) {
    echo "<div class='error'>";
    foreach ($errors as $e) {
        echo "<p>$e</p>";
    }
    echo "</div>";
}
?>

<form method="POST">

    <label class="field-label" for="register-full-name">Full Name</label>
    <input type="text" id="register-full-name" name="full_name" placeholder="e.g. Juan Dela Cruz" minlength="2" maxlength="100" pattern="[A-Za-zÀ-ÿ .'-]+" value="<?= htmlspecialchars($form_values['full_name'], ENT_QUOTES, 'UTF-8') ?>" class="<?= isset($field_errors['full_name']) ? 'input-error' : '' ?>" <?= isset($field_errors['full_name']) ? 'aria-invalid="true" aria-describedby="field-error-full-name"' : '' ?> required>
    <?php if (isset($field_errors['full_name'])): ?><small class="field-error" id="field-error-full-name"><?= htmlspecialchars($field_errors['full_name'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>

    <label class="field-label" for="register-username">Username</label>
    <input type="text" id="register-username" name="username" placeholder="e.g. juandelaCruz" minlength="3" maxlength="50" value="<?= htmlspecialchars($form_values['username'], ENT_QUOTES, 'UTF-8') ?>" class="<?= isset($field_errors['username']) ? 'input-error' : '' ?>" <?= isset($field_errors['username']) ? 'aria-invalid="true" aria-describedby="field-error-username"' : '' ?> required>
    <?php if (isset($field_errors['username'])): ?><small class="field-error" id="field-error-username"><?= htmlspecialchars($field_errors['username'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>

    <label class="field-label" for="register-email">Email</label>
    <input type="email" id="register-email" name="email" placeholder="e.g. juan@gmail.com" value="<?= htmlspecialchars($form_values['email'], ENT_QUOTES, 'UTF-8') ?>" class="<?= isset($field_errors['email']) ? 'input-error' : '' ?>" <?= isset($field_errors['email']) ? 'aria-invalid="true" aria-describedby="field-error-email"' : '' ?> required>
    <?php if (isset($field_errors['email'])): ?><small class="field-error" id="field-error-email"><?= htmlspecialchars($field_errors['email'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>

    <label class="field-label" for="register-address">Delivery Address</label>
    <div class="address-lookup">
        <input type="text" id="register-address" name="address" placeholder="Search and select your delivery address" minlength="5" maxlength="255" autocomplete="street-address" value="<?= htmlspecialchars($form_values['address'], ENT_QUOTES, 'UTF-8') ?>" class="<?= isset($field_errors['address']) ? 'input-error' : '' ?>" aria-describedby="address-lookup-status address-results address-range-status<?= isset($field_errors['address']) ? ' field-error-address' : '' ?>" <?= isset($field_errors['address']) ? 'aria-invalid="true"' : '' ?> required>
        <div class="address-results" id="address-results" role="listbox" aria-label="OpenStreetMap address suggestions" hidden></div>
        <div class="address-lookup-status" id="address-lookup-status" aria-live="polite"></div>
        <input type="hidden" name="latitude" id="register-latitude" value="<?= htmlspecialchars((string)($_POST['latitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="longitude" id="register-longitude" value="<?= htmlspecialchars((string)($_POST['longitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <div class="address-range-status" id="address-range-status" aria-live="polite">Type your address and select a suggestion to check delivery availability within 10 km by road.</div>
    </div>
    <?php if (isset($field_errors['address'])): ?><small class="field-error" id="field-error-address"><?= htmlspecialchars($field_errors['address'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>

    <label class="field-label" for="register-phone">Phone</label>
    <input type="tel" id="register-phone" name="phone" placeholder="e.g. 09171234567" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" value="<?= htmlspecialchars($form_values['phone'], ENT_QUOTES, 'UTF-8') ?>" class="<?= isset($field_errors['phone']) ? 'input-error' : '' ?>" <?= isset($field_errors['phone']) ? 'aria-invalid="true" aria-describedby="field-error-phone"' : '' ?> required>
    <?php if (isset($field_errors['phone'])): ?><small class="field-error" id="field-error-phone"><?= htmlspecialchars($field_errors['phone'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>

    <label class="field-label" for="register-dob">Date of Birth</label>
    <input type="date" id="register-dob" name="dob" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($form_values['dob'], ENT_QUOTES, 'UTF-8') ?>" class="<?= isset($field_errors['dob']) ? 'input-error' : '' ?>" <?= isset($field_errors['dob']) ? 'aria-invalid="true" aria-describedby="field-error-dob"' : '' ?> required>
    <?php if (isset($field_errors['dob'])): ?><small class="field-error" id="field-error-dob"><?= htmlspecialchars($field_errors['dob'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>

    <label class="field-label" for="register-gender">Gender</label>
    <select id="register-gender" name="gender" class="<?= isset($field_errors['gender']) ? 'input-error' : '' ?>" <?= isset($field_errors['gender']) ? 'aria-invalid="true" aria-describedby="field-error-gender"' : '' ?> required>
        <option value="">Select gender</option>
        <option value="Male" <?= $form_values['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
        <option value="Female" <?= $form_values['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
    </select>
    <?php if (isset($field_errors['gender'])): ?><small class="field-error" id="field-error-gender"><?= htmlspecialchars($field_errors['gender'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>

    <label class="field-label" for="register-password">Create Password</label>
    <input type="password" id="register-password" name="password" placeholder="Enter a strong password" minlength="8" autocomplete="new-password" class="<?= isset($field_errors['password']) ? 'input-error' : '' ?>" aria-describedby="password-strength<?= isset($field_errors['password']) ? ' field-error-password' : '' ?>" <?= isset($field_errors['password']) ? 'aria-invalid="true"' : '' ?> required>
    <?php if (isset($field_errors['password'])): ?><small class="field-error" id="field-error-password"><?= htmlspecialchars($field_errors['password'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
    <div class="password-strength" id="password-strength" data-strength="empty" aria-live="polite">
        <div class="password-strength-label"><span>Password strength</span><strong id="password-strength-label">Enter a password</strong></div>
        <div class="password-strength-meter" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <ul class="password-requirements">
            <li data-password-rule="length">At least 8 characters</li>
            <li data-password-rule="upper">One uppercase letter</li>
            <li data-password-rule="lower">One lowercase letter</li>
            <li data-password-rule="number">One number</li>
            <li data-password-rule="special">One special character</li>
        </ul>
    </div>

    <label class="field-label" for="register-confirm-password">Confirm Password</label>
    <input type="password" id="register-confirm-password" name="confirm_password" placeholder="Re-enter your password" minlength="8" autocomplete="new-password" class="<?= isset($field_errors['confirm_password']) ? 'input-error' : '' ?>" <?= isset($field_errors['confirm_password']) ? 'aria-invalid="true" aria-describedby="field-error-confirm-password"' : '' ?> required>
    <?php if (isset($field_errors['confirm_password'])): ?><small class="field-error" id="field-error-confirm-password"><?= htmlspecialchars($field_errors['confirm_password'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>

    <button type="submit" name="register" id="register-submit">Register</button>

</form>

<p style="text-align:center; margin-top:10px;">
    Already have an account? <a href="login.php">Login</a>
</p>

</div>
</div>

<script>
    const addressInput = document.getElementById('register-address');
    const addressResults = document.getElementById('address-results');
    const addressStatus = document.getElementById('address-lookup-status');
    const addressRangeStatus = document.getElementById('address-range-status');
    const latitudeInput = document.getElementById('register-latitude');
    const longitudeInput = document.getElementById('register-longitude');
    const registrationForm = document.querySelector('form');
    const registerButton = document.getElementById('register-submit');
    const storePoint = [<?= PHARMACY_STORE_LATITUDE ?>, <?= PHARMACY_STORE_LONGITUDE ?>];
    let distanceCheckSequence = 0;
    let addressRangePending = false;
    let addressRangeAllowed = false;
    let addressSearchTimer;
    let addressSearchController;

    function updateRegistrationAvailability() {
        registerButton.disabled = addressRangePending || !addressRangeAllowed
            || !latitudeInput.value || !longitudeInput.value || addressInput.value.trim().length < 5;
    }

    async function verifyRegistrationAddressDistance(latitude, longitude) {
        const sequence = ++distanceCheckSequence;
        addressRangePending = true;
        addressRangeAllowed = false;
        addressRangeStatus.textContent = 'Checking road distance from the store…';
        addressRangeStatus.classList.remove('is-error');
        updateRegistrationAvailability();

        const coordinates = storePoint[1] + ',' + storePoint[0] + ';' + longitude + ',' + latitude;
        const endpoints = [
            'https://router.project-osrm.org/route/v1/driving/',
            'https://routing.openstreetmap.de/routed-car/route/v1/driving/'
        ];
        let lastError = null;
        for (const endpoint of endpoints) {
            if (sequence !== distanceCheckSequence) return;
            try {
                const response = await fetch(endpoint + coordinates + '?overview=false', {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();
                if (!response.ok || !data || data.code !== 'Ok'
                    || !Array.isArray(data.routes) || !data.routes[0]
                    || !Number.isFinite(Number(data.routes[0].distance))) {
                    throw new Error('A valid road distance could not be returned.');
                }
                if (sequence !== distanceCheckSequence) return;
                const distanceKm = Number(data.routes[0].distance) / 1000;
                addressRangeAllowed = distanceKm <= 10;
                addressRangePending = false;
                addressRangeStatus.textContent = addressRangeAllowed
                    ? distanceKm.toFixed(1) + ' km by road from the store. Your address is within the delivery area.'
                    : distanceKm.toFixed(1) + ' km by road from the store. This address is outside the 10 km delivery area.';
                addressRangeStatus.classList.toggle('is-error', !addressRangeAllowed);
                updateRegistrationAvailability();
                return;
            } catch (error) {
                lastError = error;
            }
        }
        if (sequence !== distanceCheckSequence) return;
        addressRangePending = false;
        addressRangeAllowed = false;
        addressRangeStatus.textContent = 'Could not verify the road distance. Please select the address again.';
        addressRangeStatus.classList.add('is-error');
        updateRegistrationAvailability();
        console.error('Unable to verify the registration address distance.', lastError);
    }

    addressInput.addEventListener('input', function () {
        distanceCheckSequence += 1;
        addressRangePending = false;
        addressRangeAllowed = false;
        latitudeInput.value = '';
        longitudeInput.value = '';
        addressRangeStatus.textContent = 'Select an address suggestion to check the 10 km road delivery limit.';
        addressRangeStatus.classList.remove('is-error');
        updateRegistrationAvailability();
        clearTimeout(addressSearchTimer);
        if (addressSearchController) {
            addressSearchController.abort();
        }
        addressResults.replaceChildren();
        addressResults.hidden = true;

        const query = addressInput.value.trim();
        addressStatus.textContent = query.length >= 4 ? 'Searching OpenStreetMap...' : '';
        if (query.length < 4) {
            return;
        }

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
                if (!response.ok) {
                    throw new Error('Address search unavailable');
                }

                const places = await response.json();
                if (addressInput.value.trim() !== query) {
                    return;
                }
                addressResults.replaceChildren();
                places.forEach(function (place) {
                    const result = document.createElement('button');
                    result.type = 'button';
                    result.className = 'address-result';
                    result.setAttribute('role', 'option');
                    result.textContent = place.display_name;
                    result.addEventListener('click', function () {
                        try {
                            addressInput.value = place.display_name.slice(0, 255);
                            addressResults.replaceChildren();
                            addressResults.hidden = true;
                            const latitude = Number(place.lat);
                            const longitude = Number(place.lon);
                            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
                                || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
                                throw new Error('The selected address returned invalid coordinates.');
                            }
                            latitudeInput.value = latitude.toFixed(7);
                            longitudeInput.value = longitude.toFixed(7);
                            addressStatus.textContent = 'Address selected. Checking delivery availability…';
                            verifyRegistrationAddressDistance(latitude, longitude);
                        } catch (error) {
                            addressStatus.textContent = 'Unable to use this search result. Please select another address.';
                            latitudeInput.value = '';
                            longitudeInput.value = '';
                            addressRangeAllowed = false;
                            updateRegistrationAvailability();
                            console.error('Unable to use the selected registration search result.', error);
                        }
                    });
                    addressResults.appendChild(result);
                });
                addressResults.hidden = places.length === 0;
                addressStatus.textContent = places.length ? 'Select an address to check delivery availability.' : 'No matching addresses found. Try a different search.';
            } catch (error) {
                if (error.name !== 'AbortError') {
                    addressStatus.textContent = 'Address search is unavailable right now. Please try again.';
                }
            }
        }, 800);
    });

    registrationForm.addEventListener('submit', function (event) {
        if (addressRangePending || !addressRangeAllowed
            || !latitudeInput.value || !longitudeInput.value || addressInput.value.trim().length < 5) {
            event.preventDefault();
            addressRangeStatus.textContent = addressRangePending
                ? 'Please wait for the road-distance check to finish.'
                : 'Type your address and select a suggestion within 10 km by road before registering.';
            addressRangeStatus.classList.add('is-error');
        }
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.address-lookup')) {
            addressResults.hidden = true;
        }
    });

    if (latitudeInput.value && longitudeInput.value && addressInput.value.trim().length >= 5) {
        verifyRegistrationAddressDistance(Number(latitudeInput.value), Number(longitudeInput.value));
    }
    updateRegistrationAvailability();

    const passwordInput = document.getElementById('register-password');
    const passwordStrength = document.getElementById('password-strength');
    const passwordStrengthLabel = document.getElementById('password-strength-label');
    const passwordRuleElements = document.querySelectorAll('[data-password-rule]');

    function updatePasswordStrength() {
        const value = passwordInput.value;
        passwordStrength.classList.toggle('is-visible', value.length > 0);
        const rules = {
            length: value.length >= 8,
            upper: /[A-Z]/.test(value),
            lower: /[a-z]/.test(value),
            number: /[0-9]/.test(value),
            special: /[^A-Za-z0-9]/.test(value)
        };

        passwordRuleElements.forEach(function (ruleElement) {
            const isMet = rules[ruleElement.dataset.passwordRule];
            ruleElement.classList.toggle('is-met', isMet);
            ruleElement.classList.toggle('is-unmet', !isMet);
        });

        if (!value) {
            passwordStrength.dataset.strength = 'empty';
            passwordStrengthLabel.textContent = 'Enter a password';
            return;
        }

        const score = ['length', 'upper', 'lower', 'number', 'special'].filter(function (rule) {
            return rules[rule];
        }).length;
        const strength = score < 3 ? 'weak' : (score === 5 ? 'strong' : 'fair');
        passwordStrength.dataset.strength = strength;
        passwordStrengthLabel.textContent = strength.charAt(0).toUpperCase() + strength.slice(1);
    }

    passwordInput.addEventListener('input', updatePasswordStrength);
    updatePasswordStrength();
</script>

</body>
</html>