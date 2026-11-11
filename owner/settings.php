<?php
session_start();
include 'config.php';
include 'access_control.php';

requireOwnerPageAccess($conn, true);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getStaffAccountSmtpConfig(): ?array {
    $host = getenv('SMTP_HOST');
    $username = getenv('SMTP_USERNAME');
    $password = getenv('SMTP_PASSWORD');
    $from = getenv('SMTP_FROM_EMAIL') ?: $username;
    if (!$host || !$username || !$password || !$from) {
        return null;
    }

    return [
        'host' => $host,
        'username' => $username,
        'password' => $password,
        'from' => $from,
        'from_name' => getenv('SMTP_FROM_NAME') ?: 'Josefina Farmacia',
        'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
        'port' => (int)(getenv('SMTP_PORT') ?: 587),
    ];
}

function sendStaffAccountCredentialsEmail(array $smtp, string $email, string $name, string $role, string $temporaryPassword): bool {
    require_once __DIR__ . '/vendor/autoload.php';

    try {
        $safeName = h($name);
        $safeEmail = h($email);
        $safeRole = h(ucfirst($role));
        $safePassword = h($temporaryPassword);
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->SMTPSecure = $smtp['encryption'];
        $mail->Port = $smtp['port'];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($smtp['from'], $smtp['from_name']);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = 'Your Josefina Farmacia staff account';
        $mail->Body = '<h2>Welcome to Josefina Farmacia</h2>'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Your ' . $safeRole . ' account is ready. Sign in using these details:</p>'
            . '<p><strong>Email:</strong> ' . $safeEmail . '<br>'
            . '<strong>Temporary password:</strong> ' . $safePassword . '</p>'
            . '<p>Sign in with this temporary password, then open your Profile and enter it with your new password and confirmation. After you save the new password, this temporary password will no longer work.</p>'
            . '<p>Please keep it private.</p>';
        $mail->AltBody = "Welcome to Josefina Farmacia, {$name}.\n"
            . "Role: " . ucfirst($role) . "\nEmail: {$email}\n"
            . "Temporary password: {$temporaryPassword}\n"
            . "Sign in with this temporary password, then open your Profile and enter it with your new password and confirmation.\n"
            . "After you save the new password, this temporary password will no longer work. Please keep it private.";
        $mail->send();
        return true;
    } catch (\Throwable $exception) {
        error_log('Staff account credentials email failed: ' . $exception->getMessage());
        return false;
    }
}

$successMessage = '';
$errorMessage = '';
$settingsSections = ['accounts', 'alerts', 'appearance', 'password', 'audit'];
$activeSettingsSection = (string)($_POST['settings_section'] ?? $_GET['section'] ?? '');
if (!in_array($activeSettingsSection, $settingsSections, true)) {
    $activeSettingsSection = '';
}
if ($activeSettingsSection === 'accounts' && ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Only admins can create staff accounts.');
}
if ($activeSettingsSection === 'audit' && !in_array(($_SESSION['role'] ?? ''), ['admin', 'pharmacist'], true)) {
    http_response_code(403);
    exit('You do not have permission to view audit logs.');
}
$accountCsrfToken = $_SESSION['settings_account_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['settings_account_csrf'] = $accountCsrfToken;
$passwordCsrfToken = $_SESSION['settings_password_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['settings_password_csrf'] = $passwordCsrfToken;
$temporaryPasswordSchemaReady = $conn->query(
    'ALTER TABLE users ADD COLUMN IF NOT EXISTS password_change_required TINYINT(1) NOT NULL DEFAULT 0'
);
if (!$temporaryPasswordSchemaReady && in_array($activeSettingsSection, ['accounts', 'password'], true)) {
    $errorMessage = 'Unable to prepare the temporary-password security setting: ' . $conn->error;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $activeSettingsSection = 'password';
    if (!hash_equals($passwordCsrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $errorMessage = 'Session expired. Refresh the page and try again.';
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $errorMessage = 'Complete all password fields to continue.';
        } elseif (strlen($newPassword) < 8) {
            $errorMessage = 'The new password must be at least 8 characters long.';
        } elseif (!hash_equals($newPassword, $confirmPassword)) {
            $errorMessage = 'The new password and confirmation do not match.';
        } elseif (hash_equals($currentPassword, $newPassword)) {
            $errorMessage = 'Your new password must be different from your current password.';
        } else {
            $userId = (int)$_SESSION['user_id'];
            $passwordLookup = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            if (!$passwordLookup) {
                $errorMessage = 'Unable to prepare the password verification query: ' . $conn->error;
            } else {
                $passwordLookup->bind_param('i', $userId);
                if (!$passwordLookup->execute()) {
                    $errorMessage = 'Unable to verify the current password: ' . $passwordLookup->error;
                    $passwordLookup->close();
                } else {
                    $passwordResult = $passwordLookup->get_result();
                    $userPassword = $passwordResult ? $passwordResult->fetch_assoc() : null;
                    $passwordLookup->close();

                    if (!$userPassword) {
                        $errorMessage = 'Unable to find your account.';
                    } else {
                        $storedPassword = (string)$userPassword['password'];
                        $passwordMatches = password_verify($currentPassword, $storedPassword)
                            || (strlen($storedPassword) === 32 && hash_equals($storedPassword, md5($currentPassword)));

                        if (!$passwordMatches) {
                            $errorMessage = 'The current password is incorrect.';
                        } else {
                            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                            $passwordUpdate = $conn->prepare('UPDATE users SET password = ?, password_change_required = 0 WHERE id = ?');
                            if (!$passwordUpdate) {
                                $errorMessage = 'Unable to prepare the password update: ' . $conn->error;
                            } else {
                                $passwordUpdate->bind_param('si', $newPasswordHash, $userId);
                                if ($passwordUpdate->execute()) {
                                    writeAuditLog($conn, 'password_changed', 'user', $userId);
                                    $successMessage = 'Your password has been changed successfully.';
                                } else {
                                    $errorMessage = 'Unable to change your password: ' . $passwordUpdate->error;
                                }
                                $passwordUpdate->close();
                            }
                        }
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_staff_account') {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Only admins can create staff accounts.');
    }
    if (!$temporaryPasswordSchemaReady) {
        $errorMessage = 'Unable to prepare one-time temporary passwords: ' . $conn->error;
        $activeSettingsSection = 'accounts';
    } elseif (!hash_equals($accountCsrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $errorMessage = 'Session expired. Refresh the page and try again.';
        $activeSettingsSection = 'accounts';
    } else {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $temporaryPassword = (string)($_POST['temporary_password'] ?? '');
        $accountRole = (string)($_POST['account_role'] ?? '');

        if ($fullName === '' || strlen($fullName) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100 || strlen($temporaryPassword) < 8 || !in_array($accountRole, ['pharmacist', 'rider'], true)) {
            $errorMessage = 'Enter a valid full name, email, role, and temporary password of at least 8 characters.';
            $activeSettingsSection = 'accounts';
        } else {
            $roleColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
            $roleDefinition = $roleColumn ? $roleColumn->fetch_assoc() : null;
            if (!$roleDefinition) {
                $errorMessage = 'Unable to verify the user role schema: ' . $conn->error;
            } elseif (strpos(strtolower((string)$roleDefinition['Type']), 'enum(') === 0
                && (strpos((string)$roleDefinition['Type'], "'pharmacist'") === false || strpos((string)$roleDefinition['Type'], "'rider'") === false)
                && !$conn->query("ALTER TABLE users MODIFY role ENUM('user', 'admin', 'owner', 'rider', 'pharmacist') NOT NULL DEFAULT 'user'")) {
                $errorMessage = 'Unable to enable staff account roles: ' . $conn->error;
            } else {
                $staffSchemaReady = $conn->query("CREATE TABLE IF NOT EXISTS pharmacy_staff (user_id INT NOT NULL PRIMARY KEY, active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
                    && $conn->query("CREATE TABLE IF NOT EXISTS rider_profiles (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, phone VARCHAR(30) NULL, vehicle_type VARCHAR(50) NULL, plate_number VARCHAR(30) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                if (!$staffSchemaReady) {
                    $errorMessage = 'Unable to prepare staff account tables: ' . $conn->error;
                } else {
                    $emailCheck = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                    if (!$emailCheck) {
                        $errorMessage = 'Unable to check the account email: ' . $conn->error;
                    } else {
                        $emailCheck->bind_param('s', $email);
                        $emailCheck->execute();
                        $emailExists = $emailCheck->get_result()->num_rows > 0;
                        $emailCheck->close();
                        if ($emailExists) {
                            $errorMessage = 'An account with this email address already exists.';
                        } else {
                            $usernameBase = strtolower((string)strtok($email, '@'));
                            $usernameBase = preg_replace('/[^a-z0-9_.-]/', '', $usernameBase);
                            $usernameBase = substr($usernameBase ?: 'staff', 0, 40);
                            $username = '';
                            $usernameCheck = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                            if (!$usernameCheck) {
                                $errorMessage = 'Unable to prepare username generation: ' . $conn->error;
                            } else {
                                for ($attempt = 0; $attempt < 5; $attempt++) {
                                    $candidate = $usernameBase . '_' . bin2hex(random_bytes(3));
                                    $usernameCheck->bind_param('s', $candidate);
                                    $usernameCheck->execute();
                                    if ($usernameCheck->get_result()->num_rows === 0) {
                                        $username = $candidate;
                                        break;
                                    }
                                }
                                $usernameCheck->close();

                                if ($username === '') {
                                    $errorMessage = 'Unable to generate a unique username. Please try again.';
                                } else {
                                    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                                    $conn->begin_transaction();
                                    $createUser = $conn->prepare('INSERT INTO users (username, email, password, role, full_name, password_change_required) VALUES (?, ?, ?, ?, ?, 1)');
                                    if (!$createUser) {
                                        $conn->rollback();
                                        $errorMessage = 'Unable to prepare account creation: ' . $conn->error;
                                    } else {
                                        $createUser->bind_param('sssss', $username, $email, $passwordHash, $accountRole, $fullName);
                                        if (!$createUser->execute()) {
                                            $createError = $createUser->error;
                                            $duplicateEmail = $createUser->errno === 1062;
                                            $createUser->close();
                                            $conn->rollback();
                                            $errorMessage = $duplicateEmail
                                                ? 'An account with this email address or username already exists.'
                                                : 'Unable to create the account: ' . $createError;
                                        } else {
                                            $newUserId = (int)$createUser->insert_id;
                                            $createUser->close();
                                            if ($accountRole === 'pharmacist') {
                                                $membership = $conn->prepare('INSERT INTO pharmacy_staff (user_id, active, created_by) VALUES (?, 1, ?)');
                                                if ($membership) {
                                                    $createdBy = (int)$_SESSION['user_id'];
                                                    $membership->bind_param('ii', $newUserId, $createdBy);
                                                    $relatedRecordSaved = $membership->execute();
                                                    $relatedError = $membership->error;
                                                    $membership->close();
                                                } else {
                                                    $relatedRecordSaved = false;
                                                    $relatedError = $conn->error;
                                                }
                                            } else {
                                                $membership = $conn->prepare('INSERT INTO rider_profiles (user_id) VALUES (?)');
                                                if ($membership) {
                                                    $membership->bind_param('i', $newUserId);
                                                    $relatedRecordSaved = $membership->execute();
                                                    $relatedError = $membership->error;
                                                    $membership->close();
                                                } else {
                                                    $relatedRecordSaved = false;
                                                    $relatedError = $conn->error;
                                                }
                                            }

                                            if ($relatedRecordSaved) {
                                                writeAuditLog($conn, 'staff_account_created', 'user', $newUserId, ['role' => $accountRole]);
                                            }
                                            if ($relatedRecordSaved && $conn->commit()) {
                                                $smtpConfig = getStaffAccountSmtpConfig();
                                                if ($smtpConfig !== null && sendStaffAccountCredentialsEmail($smtpConfig, $email, $fullName, $accountRole, $temporaryPassword)) {
                                                    $successMessage = ucfirst($accountRole) . ' account created. The name, login email, and temporary password were sent to ' . $email . '.';
                                                } else {
                                                    $successMessage = ucfirst($accountRole) . ' account created, but the credential email was not sent. '
                                                        . ($smtpConfig === null
                                                            ? 'SMTP is not configured. Give the temporary password you entered to the account holder securely.'
                                                            : 'The email could not be sent. Give the temporary password you entered to the account holder securely.');
                                                }
                                            } else {
                                                $conn->rollback();
                                                $errorMessage = 'Unable to finish account setup: ' . $relatedError;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $activeSettingsSection = 'accounts';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_staff_password') {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Only admins can reset staff passwords.');
    }
    $activeSettingsSection = 'accounts';
    if (!$temporaryPasswordSchemaReady) {
        $errorMessage = 'Unable to prepare one-time temporary passwords: ' . $conn->error;
    } elseif (!hash_equals($accountCsrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $errorMessage = 'Session expired. Refresh the page and try again.';
    } else {
        $staffUserId = filter_var($_POST['staff_user_id'] ?? null, FILTER_VALIDATE_INT);
        $temporaryPassword = (string)($_POST['staff_temporary_password'] ?? '');

        if (!$staffUserId || strlen($temporaryPassword) < 8) {
            $errorMessage = 'Choose an account and enter a temporary password of at least 8 characters.';
        } else {
            $staffLookup = $conn->prepare("SELECT full_name, username, email, role FROM users WHERE id = ? AND role IN ('pharmacist', 'rider') LIMIT 1");
            if (!$staffLookup) {
                $errorMessage = 'Unable to look up the staff account: ' . $conn->error;
            } else {
                $staffLookup->bind_param('i', $staffUserId);
                if (!$staffLookup->execute()) {
                    $errorMessage = 'Unable to look up the staff account: ' . $staffLookup->error;
                } else {
                    $staffAccount = $staffLookup->get_result()->fetch_assoc();
                    if (!$staffAccount) {
                        $errorMessage = 'No Pharmacist or Rider account was found to update.';
                    } else {
                        $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                        $updatePassword = $conn->prepare("UPDATE users SET password = ?, password_change_required = 1 WHERE id = ? AND role IN ('pharmacist', 'rider')");
                        if (!$updatePassword) {
                            $errorMessage = 'Unable to prepare the staff password reset: ' . $conn->error;
                        } else {
                            $updatePassword->bind_param('si', $passwordHash, $staffUserId);
                            if (!$updatePassword->execute()) {
                                $errorMessage = 'Unable to reset the staff password: ' . $updatePassword->error;
                            } elseif ($updatePassword->affected_rows > 0) {
                                writeAuditLog($conn, 'staff_password_reset', 'user', (int)$staffUserId, ['role' => (string)$staffAccount['role']]);
                                $staffName = trim((string)($staffAccount['full_name'] ?? '')) ?: (string)$staffAccount['username'];
                                $smtpConfig = getStaffAccountSmtpConfig();
                                if ($smtpConfig !== null
                                    && filter_var($staffAccount['email'] ?? '', FILTER_VALIDATE_EMAIL)
                                    && sendStaffAccountCredentialsEmail($smtpConfig, $staffAccount['email'], $staffName, $staffAccount['role'], $temporaryPassword)) {
                                    $successMessage = 'Temporary password updated and sent to ' . $staffAccount['email'] . '.';
                                } else {
                                    $successMessage = 'Temporary password updated, but the credential email was not sent. '
                                        . ($smtpConfig === null
                                            ? 'SMTP is not configured. Give the new password to the account holder securely.'
                                            : 'The email could not be sent. Give the new password to the account holder securely.');
                                }
                            } else {
                                $errorMessage = 'The password was not changed. Please enter a different temporary password.';
                            }
                            $updatePassword->close();
                        }
                    }
                }
                $staffLookup->close();
            }
        }
    }
}

$defaultSettings = [
    'company_name' => 'Josefina Farmacia',
    'currency_symbol' => '₱',
    'default_pieces_per_box' => 12,
    'default_min_purchase_quantity' => 1,
    'low_stock_threshold' => 20,
    'minimum_stock_level' => 10,
    'expiration_warning_days' => 30,
    'enable_low_stock_alerts' => 1,
    'enable_expiration_alerts' => 1,
    'enable_email_notifications' => 1,
    'auto_create_inventory_alerts' => 1,
    'default_order_status' => 'Pending',
    'items_per_page' => 20,
];

$settings = $defaultSettings;
$settingsTableReady = true;

$settingsSchema = [
    'company_name' => "VARCHAR(150) NOT NULL DEFAULT 'Josefina Farmacia'",
    'currency_symbol' => "VARCHAR(10) NOT NULL DEFAULT '₱'",
    'default_pieces_per_box' => 'INT UNSIGNED NOT NULL DEFAULT 12',
    'default_min_purchase_quantity' => 'INT UNSIGNED NOT NULL DEFAULT 1',
    'low_stock_threshold' => 'INT UNSIGNED NOT NULL DEFAULT 20',
    'minimum_stock_level' => 'INT UNSIGNED NOT NULL DEFAULT 10',
    'expiration_warning_days' => 'INT UNSIGNED NOT NULL DEFAULT 30',
    'enable_low_stock_alerts' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'enable_expiration_alerts' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'enable_email_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'auto_create_inventory_alerts' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'default_order_status' => "VARCHAR(30) NOT NULL DEFAULT 'Pending'",
    'items_per_page' => 'INT UNSIGNED NOT NULL DEFAULT 20',
    'updated_by' => 'INT NULL',
    'updated_at' => 'DATETIME NULL',
];

$settingsTableSql = "CREATE TABLE IF NOT EXISTS inventory_settings (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL DEFAULT 'Josefina Farmacia',
    currency_symbol VARCHAR(10) NOT NULL DEFAULT '₱',
    default_pieces_per_box INT UNSIGNED NOT NULL DEFAULT 12,
    default_min_purchase_quantity INT UNSIGNED NOT NULL DEFAULT 1,
    low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 20,
    minimum_stock_level INT UNSIGNED NOT NULL DEFAULT 10,
    expiration_warning_days INT UNSIGNED NOT NULL DEFAULT 30,
    enable_low_stock_alerts TINYINT(1) NOT NULL DEFAULT 1,
    enable_expiration_alerts TINYINT(1) NOT NULL DEFAULT 1,
    enable_email_notifications TINYINT(1) NOT NULL DEFAULT 1,
    auto_create_inventory_alerts TINYINT(1) NOT NULL DEFAULT 1,
    default_order_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    items_per_page INT UNSIGNED NOT NULL DEFAULT 20,
    updated_by INT NULL,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!$conn->query($settingsTableSql)) {
    $settingsTableReady = false;
    if ($activeSettingsSection === 'alerts') {
        $errorMessage = 'Hindi maihanda ang inventory_settings table: ' . $conn->error;
    }
} else {
    foreach ($settingsSchema as $fieldName => $definition) {
        $fieldNameSql = $conn->real_escape_string($fieldName);
        $columnCheck = $conn->query("SHOW COLUMNS FROM inventory_settings LIKE '{$fieldNameSql}'");
        if (!$columnCheck) {
            $settingsTableReady = false;
            if ($activeSettingsSection === 'alerts') {
                $errorMessage = 'Hindi ma-check ang settings column: ' . $conn->error;
            }
            break;
        }

        if ($columnCheck->num_rows === 0 && !$conn->query("ALTER TABLE inventory_settings ADD COLUMN {$fieldName} {$definition}")) {
            $settingsTableReady = false;
            if ($activeSettingsSection === 'alerts') {
                $errorMessage = "Hindi maidagdag ang settings column {$fieldName}: {$conn->error}";
            }
            break;
        }
    }

    if ($settingsTableReady) {
        $defaultRow = $conn->query("SELECT id FROM inventory_settings WHERE id = 1 LIMIT 1");
        if ($defaultRow && $defaultRow->num_rows === 0) {
            $conn->query("INSERT INTO inventory_settings (id) VALUES (1)");
        }

        $existing = $conn->query("SELECT * FROM inventory_settings WHERE id = 1 LIMIT 1");
        if ($existing && $existing->num_rows > 0) {
            $settings = array_merge($settings, $existing->fetch_assoc());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings']) && $settingsTableReady) {
    if ($activeSettingsSection !== 'alerts') {
        http_response_code(400);
        exit('Invalid settings section.');
    }

    $enableLowStockAlerts = isset($_POST['enable_low_stock_alerts']) ? 1 : 0;
    $enableExpirationAlerts = isset($_POST['enable_expiration_alerts']) ? 1 : 0;
    $enableEmailNotifications = isset($_POST['enable_email_notifications']) ? 1 : 0;
    $autoCreateAlerts = isset($_POST['auto_create_inventory_alerts']) ? 1 : 0;
    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("UPDATE inventory_settings SET
        enable_low_stock_alerts = ?,
        enable_expiration_alerts = ?,
        enable_email_notifications = ?,
        auto_create_inventory_alerts = ?,
        updated_by = ?,
        updated_at = NOW()
        WHERE id = 1");

    if (!$stmt) {
        $errorMessage = 'Hindi maihanda ang settings query: ' . $conn->error;
    } else {
        $stmt->bind_param(
            'iiiii',
            $enableLowStockAlerts,
            $enableExpirationAlerts,
            $enableEmailNotifications,
            $autoCreateAlerts,
            $userId
        );

        if ($stmt->execute()) {
            writeAuditLog($conn, 'alert_settings_updated', 'inventory_settings', 1, [
                'low_stock_alerts' => (bool)$enableLowStockAlerts,
                'expiration_alerts' => (bool)$enableExpirationAlerts,
                'email_notifications' => (bool)$enableEmailNotifications,
                'auto_create_alerts' => (bool)$autoCreateAlerts,
            ]);
            $successMessage = 'Inventory alert settings successfully na-save.';
            $settings = array_merge($settings, [
                'enable_low_stock_alerts' => $enableLowStockAlerts,
                'enable_expiration_alerts' => $enableExpirationAlerts,
                'enable_email_notifications' => $enableEmailNotifications,
                'auto_create_inventory_alerts' => $autoCreateAlerts,
                'updated_by' => $userId,
            ]);
        } else {
            $errorMessage = 'Hindi na-save ang settings: ' . $stmt->error;
        }
        $stmt->close();
    }
}

$staffAccounts = [];
$staffSmtpConfigured = getStaffAccountSmtpConfig() !== null;
if ($activeSettingsSection === 'accounts' && ($_SESSION['role'] ?? '') === 'admin') {
    $staffAccountResult = $conn->query("SELECT id, full_name, username, email, role FROM users WHERE role IN ('pharmacist', 'rider') ORDER BY role ASC, full_name ASC, username ASC");
    if (!$staffAccountResult) {
        $errorMessage = 'Unable to load Pharmacist and Rider accounts: ' . $conn->error;
    } else {
        while ($staffAccount = $staffAccountResult->fetch_assoc()) {
            $staffAccounts[] = $staffAccount;
        }
        $staffAccountResult->free();
    }
}
$auditLogsReady = null;
$auditLogs = [];
$auditLogsError = '';
$auditAccounts = [];
$auditActions = [];
$auditPage = max(1, (int)($_GET['audit_page'] ?? 1));
$auditPerPage = 10;
$auditExportRequested = ($_GET['audit_export'] ?? '') === 'csv';
$auditTotal = 0;
$auditTotalPages = 1;
$auditFilters = [
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
    'user_id' => '',
    'action' => trim((string)($_GET['audit_action'] ?? '')),
    'search' => trim((string)($_GET['audit_search'] ?? '')),
];
if ($activeSettingsSection === 'audit' && in_array(($_SESSION['role'] ?? ''), ['admin', 'pharmacist'], true)) {
    $auditTableError = '';
    $auditLogsReady = ensureAuditLogTable($conn, $auditTableError);
    if (!$auditLogsReady) {
        $auditLogsError = $auditTableError;
    } else {
        $isAdminViewingAuditLogs = ($_SESSION['role'] ?? '') === 'admin';
        $auditWhere = [];
        if ($isAdminViewingAuditLogs) {
            $auditWhere[] = "al.actor_role <> 'user'";
            $accountsResult = $conn->query(
                "SELECT id, full_name, username, role
                 FROM users
                 WHERE role IN ('admin', 'pharmacist', 'rider')
                 ORDER BY full_name ASC, username ASC"
            );
            if (!$accountsResult) {
                $auditLogsError = 'Unable to load accounts for the audit log filter.';
                error_log('Audit log account filter query failed: ' . $conn->error);
            } else {
                while ($account = $accountsResult->fetch_assoc()) {
                    $auditAccounts[] = $account;
                }
                $accountsResult->free();
                $requestedAuditUserId = filter_var($_GET['audit_user'] ?? '', FILTER_VALIDATE_INT);
                if ($requestedAuditUserId && in_array($requestedAuditUserId, array_map('intval', array_column($auditAccounts, 'id')), true)) {
                    $auditFilters['user_id'] = (string)$requestedAuditUserId;
                    $auditWhere[] = 'al.actor_user_id = ' . (int)$requestedAuditUserId;
                }
            }
        } else {
            $auditWhere[] = 'al.actor_user_id = ' . (int)$_SESSION['user_id'];
        }

        foreach (['date_from', 'date_to'] as $dateFilter) {
            $dateValue = $auditFilters[$dateFilter];
            $parsedDate = DateTime::createFromFormat('!Y-m-d', $dateValue);
            if ($dateValue !== '' && $parsedDate && $parsedDate->format('Y-m-d') === $dateValue) {
                $auditWhere[] = 'DATE(al.created_at) ' . ($dateFilter === 'date_from' ? '>=' : '<=') . " '" . $conn->real_escape_string($dateValue) . "'";
            } else {
                $auditFilters[$dateFilter] = '';
            }
        }

        $auditActionWhere = $isAdminViewingAuditLogs
            ? " WHERE actor_role <> 'user'"
            : ' WHERE actor_user_id = ' . (int)$_SESSION['user_id'];
        $auditActionsResult = $conn->query('SELECT DISTINCT action FROM audit_logs' . $auditActionWhere . ' ORDER BY action ASC');
        if (!$auditActionsResult) {
            $auditLogsError = 'Unable to load audit log actions.';
            error_log('Audit log action filter query failed: ' . $conn->error);
        } else {
            while ($auditActionRow = $auditActionsResult->fetch_assoc()) {
                $auditActions[] = (string)$auditActionRow['action'];
            }
            $auditActionsResult->free();
        }
        if ($auditFilters['action'] !== '' && in_array($auditFilters['action'], $auditActions, true)) {
            $auditWhere[] = "al.action = '" . $conn->real_escape_string($auditFilters['action']) . "'";
        } else {
            $auditFilters['action'] = '';
        }
        if ($auditFilters['search'] !== '') {
            $searchValue = $conn->real_escape_string($auditFilters['search']);
            $auditWhere[] = "(al.action LIKE '%$searchValue%' OR al.entity_type LIKE '%$searchValue%' OR CAST(al.details AS CHAR) LIKE '%$searchValue%' OR u.username LIKE '%$searchValue%' OR u.full_name LIKE '%$searchValue%')";
        }
        $auditWhereSql = $auditWhere ? ' WHERE ' . implode(' AND ', $auditWhere) : '';
        if ($auditLogsError === '') {
            $auditCountResult = $conn->query(
                "SELECT COUNT(*) AS total FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id$auditWhereSql"
            );
            if (!$auditCountResult) {
                $auditLogsError = 'Unable to count audit logs.';
                error_log('Audit log count query failed: ' . $conn->error);
            } else {
                $auditTotal = (int)$auditCountResult->fetch_assoc()['total'];
                $auditCountResult->free();
                $auditTotalPages = max(1, (int)ceil($auditTotal / $auditPerPage));
                $auditPage = min($auditPage, $auditTotalPages);
                $auditOffset = ($auditPage - 1) * $auditPerPage;
                $auditResult = $conn->query(
                    "SELECT al.actor_user_id, al.actor_role, al.action, al.entity_type, al.entity_id, al.details, al.created_at,
                            COALESCE(NULLIF(TRIM(u.full_name), ''), u.username, CONCAT('User #', al.actor_user_id), 'System') AS actor_name,
                            COALESCE(u.role, al.actor_role) AS display_role
                     FROM audit_logs al
                     LEFT JOIN users u ON u.id = al.actor_user_id
                     $auditWhereSql
                     ORDER BY al.id DESC" . ($auditExportRequested ? '' : " LIMIT $auditPerPage OFFSET $auditOffset")
                );
                if (!$auditResult) {
                    $auditLogsError = 'Unable to load audit logs right now.';
                    error_log('Audit log listing query failed: ' . $conn->error);
                } else {
                    while ($auditRow = $auditResult->fetch_assoc()) {
                        $auditLogs[] = $auditRow;
                    }
                    $auditResult->free();
                }
            }
        }
    }
}
$auditExportParams = [
    'section' => 'audit',
    'date_from' => $auditFilters['date_from'],
    'date_to' => $auditFilters['date_to'],
    'audit_user' => $auditFilters['user_id'],
    'audit_action' => $auditFilters['action'],
    'audit_search' => $auditFilters['search'],
    'audit_export' => 'csv',
];
$auditExportUrl = 'settings.php?' . http_build_query(array_filter($auditExportParams, static fn($value) => $value !== ''));
if ($auditExportRequested && $auditLogsReady && $auditLogsError === '') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit-logs.csv"');
    $auditOutput = fopen('php://output', 'w');
    if ($auditOutput === false) {
        http_response_code(500);
        exit('Unable to export audit logs.');
    }
    fwrite($auditOutput, "\xEF\xBB\xBF");
    fputcsv($auditOutput, ['Date & Time', 'User', 'Action', 'Module', 'Details']);
    foreach ($auditLogs as $auditRow) {
        $auditDetails = json_decode((string)($auditRow['details'] ?? ''), true);
        $auditDetailsText = is_array($auditDetails)
            ? (json_encode($auditDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
            : 'Audit event recorded.';
        $auditTimestamp = strtotime((string)$auditRow['created_at']);
        $csvRow = [
            $auditTimestamp !== false ? date('M d, Y h:i A', $auditTimestamp) : (string)$auditRow['created_at'],
            (string)$auditRow['actor_name'] . ' (' . (string)$auditRow['display_role'] . ')',
            ucwords(str_replace('_', ' ', (string)$auditRow['action'])),
            ucfirst((string)$auditRow['entity_type']) . ($auditRow['entity_id'] !== null ? ' #' . $auditRow['entity_id'] : ''),
            $auditDetailsText,
        ];
        foreach ($csvRow as &$csvValue) {
            if (preg_match('/^[\s]*[=+\-@]/', $csvValue)) {
                $csvValue = "'" . $csvValue;
            }
        }
        unset($csvValue);
        fputcsv($auditOutput, $csvRow);
    }
    fclose($auditOutput);
    exit();
}
$auditPageParams = ['section' => 'audit'];
foreach ([
    'date_from' => $auditFilters['date_from'],
    'date_to' => $auditFilters['date_to'],
    'audit_user' => $auditFilters['user_id'],
    'audit_action' => $auditFilters['action'],
    'audit_search' => $auditFilters['search'],
] as $auditParam => $auditValue) {
    if ($auditValue !== '') {
        $auditPageParams[$auditParam] = $auditValue;
    }
}
$auditPageItems = [];
$auditPageStart = max(1, min($auditPage - 2, $auditTotalPages - 4));
$auditPageEnd = min($auditTotalPages, $auditPageStart + 4);
if ($auditTotal > 0) {
    $auditPageItems = range($auditPageStart, $auditPageEnd);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Settings</title>
    <link rel="stylesheet" href="shared_styles.css">
    <link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-3">
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f7fb; color: #172033; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .settings-page { max-width: none; margin: 0 0 0 260px; padding: 118px 36px 50px; transition: margin-left .25s ease, width .25s ease; }
        .page-heading { display: flex; justify-content: space-between; gap: 20px; align-items: flex-end; margin-bottom: 24px; }
        .page-heading h1 { margin: 4px 0 0; font-size: 32px; color: #16243a; }
        .eyebrow { margin: 0; text-transform: uppercase; letter-spacing: .12em; color: #6b7280; font-size: 12px; font-weight: 700; }
        .intro { margin: 8px 0 0; color: #667085; max-width: 720px; line-height: 1.55; }
        .settings-layout { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; align-items: start; }
        .settings-card { background: #fff; border: 1px solid #e5eaf1; border-radius: 18px; padding: 24px; box-shadow: 0 14px 35px rgba(30, 45, 70, .06); }
        .settings-card.full-width { grid-column: 1 / -1; }
        .settings-card h2 { margin: 0 0 6px; font-size: 19px; color: #1e293b; }
        .settings-card .card-description { margin: 0 0 20px; color: #7a8494; font-size: 14px; line-height: 1.5; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 7px; }
        .form-group.full { grid-column: 1 / -1; }
        label { color: #344054; font-size: 13px; font-weight: 700; }
        input, select { width: 100%; border: 1px solid #d9e0ea; border-radius: 10px; padding: 12px 13px; color: #1f2937; background: #fff; outline: none; font: inherit; }
        input:focus, select:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .12); }
        .hint { color: #8a94a6; font-size: 12px; line-height: 1.4; }
        .toggle-list { display: grid; gap: 14px; }
        .toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 14px 0; border-bottom: 1px solid #eef1f5; }
        .toggle-row:first-child { padding-top: 0; }
        .toggle-row:last-child { padding-bottom: 0; border-bottom: 0; }
        .toggle-copy strong { display: block; color: #344054; font-size: 14px; }
        .toggle-copy span { display: block; margin-top: 3px; color: #8a94a6; font-size: 12px; }
        .switch { position: relative; width: 48px; height: 26px; flex: 0 0 auto; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; inset: 0; cursor: pointer; background: #cbd5e1; border-radius: 999px; transition: .2s; }
        .slider:before { content: ''; position: absolute; width: 20px; height: 20px; left: 3px; top: 3px; background: white; border-radius: 50%; transition: .2s; box-shadow: 0 2px 5px rgba(0,0,0,.2); }
        .switch input:checked + .slider { background: #2563eb; }
        .switch input:checked + .slider:before { transform: translateX(22px); }
        .message { padding: 14px 16px; border-radius: 12px; margin-bottom: 18px; font-size: 14px; }
        .message.success { color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; }
        .message.error { color: #991b1b; background: #fee2e2; border: 1px solid #fecaca; }
        .settings-toast {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 10000;
            display: flex;
            align-items: flex-start;
            gap: 13px;
            width: min(420px, calc(100vw - 32px));
            max-width: none !important;
            margin: 0 !important;
            padding: 16px 18px;
            border: 1px solid #d1e7d8 !important;
            border-left: 4px solid #21834a !important;
            border-radius: 12px;
            background: #fff !important;
            color: #344054 !important;
            box-shadow: 0 12px 32px rgba(16, 24, 40, .16);
            animation: settings-toast-enter .22s ease-out;
        }
        .settings-toast-icon {
            display: grid;
            width: 30px;
            height: 30px;
            flex: 0 0 30px;
            place-items: center;
            border-radius: 50%;
            background: #e8f5ed;
            color: #21834a;
            font-size: 18px;
            font-weight: 700;
        }
        .settings-toast-copy { min-width: 0; flex: 1; }
        .settings-toast-title { display: block; margin-bottom: 3px; color: #1d2939; font-size: 14px; font-weight: 700; }
        .settings-toast-message { display: block; color: #475467; font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
        .settings-toast-close {
            display: grid;
            width: 28px;
            height: 28px;
            flex: 0 0 28px;
            place-items: center;
            margin: -4px -6px 0 0;
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: #667085;
            cursor: pointer;
            font: inherit;
            font-size: 20px;
            line-height: 1;
        }
        .settings-toast-close:hover { background: #f2f4f7; color: #344054; }
        .settings-toast-close:focus-visible { outline: 2px solid #21834a; outline-offset: 2px; }
        @keyframes settings-toast-enter {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 480px) {
            .settings-toast { top: 12px; right: 12px; width: calc(100vw - 24px); }
        }
        @media (prefers-reduced-motion: reduce) {
            .settings-toast { animation: none; }
        }
        .actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 22px; }
        .button { border: 0; border-radius: 10px; padding: 12px 20px; cursor: pointer; font: inherit; font-weight: 700; text-decoration: none; display: inline-block; }
        .button.primary { color: #fff; background: #2563eb; }
        .button.primary:hover { background: #1d4ed8; }
        .button.secondary { color: #334155; background: #eef2f7; }
        .warning { padding: 14px 16px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; line-height: 1.5; font-size: 14px; }
        @media (max-width: 760px) {
            .sidebar-nav { display: flex; flex-wrap: wrap; }
            .settings-page { padding: 104px 14px 40px; }
            .page-heading { display: block; }
            .settings-layout, .form-grid { grid-template-columns: 1fr; }
            .settings-card.full-width, .form-group.full { grid-column: auto; }
        }
        @media (min-width: 721px) {
            body.owner-sidebar-collapsed .settings-page {
                width: calc(100% - 84px);
                margin-left: 84px;
                padding-left: 12px;
                padding-right: 12px;
            }
            body.owner-sidebar-collapsed .settings-header,
            body.owner-sidebar-collapsed .settings-overview,
            body.owner-sidebar-collapsed .settings-detail-heading,
            body.owner-sidebar-collapsed .settings-layout.single-section,
            body.owner-sidebar-collapsed .settings-account-form,
            body.owner-sidebar-collapsed .staff-account-list {
                max-width: 900px;
                margin-left: auto;
                margin-right: auto;
            }
        }
        @media (max-width: 720px) {
            .settings-page { width: 100%; margin-left: 0; margin-right: 0; }
        }
        .settings-header { display:flex; align-items:center; gap:16px; margin:0 auto 28px; max-width:900px; }
        .settings-header-icon { width:54px; height:54px; flex:0 0 54px; display:grid; place-items:center; border-radius:14px; background:#fff0f0; color:#c91437; }
        .settings-header-icon i { font-size:27px; }
        .settings-header h1 { margin:0 0 5px; color:#20232a; font-size:30px; }
        .settings-header p { margin:0; color:#7b8189; font-size:13px; }
        .settings-overview { display:grid; gap:16px; max-width:900px; margin:0 auto; }
        .settings-option { width:100%; min-height:108px; display:flex; align-items:center; gap:16px; padding:18px 22px; border:1px solid #e5e7eb; border-radius:14px; background:rgba(255,255,255,.78); color:inherit; text-align:left; text-decoration:none; box-shadow:0 8px 22px rgba(52,72,96,.07); transition:border-color .2s ease,background .2s ease,box-shadow .2s ease,transform .2s ease; }
        .settings-option:hover { transform:translateY(-1px); border-color:rgba(211,47,47,.35); background:#fffafa; box-shadow:0 12px 26px rgba(52,72,96,.13); }
        .settings-option-icon { width:42px; height:42px; flex:0 0 42px; display:grid; place-items:center; border-radius:12px; background:#fff0f0; color:#d32f2f; }
        .settings-option-icon i { font-size:21px; }
        .settings-option-copy { display:flex; flex-direction:column; gap:4px; min-width:0; }
        .settings-option-copy strong { color:#1a202c; font-size:19px; line-height:1.3; }
        .settings-option-copy span { color:#6b7280; font-size:14px; line-height:1.4; }
        .appearance-group { padding: 20px 0; border-bottom: 1px solid #eef1f5; }
        .appearance-group:first-child { padding-top: 0; }
        .appearance-group:last-child { padding-bottom: 0; border-bottom: 0; }
        .appearance-group-title { margin: 0 0 5px; color: #344054; font-size: 15px; }
        .appearance-group-description { margin: 0 0 14px; color: #7a8494; font-size: 13px; }
        .theme-options { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .theme-option { display: flex; align-items: center; gap: 11px; min-height: 72px; padding: 12px; border: 1px solid #e1e6ed; border-radius: 11px; background: #fff; color: #344054; text-align: left; cursor: pointer; }
        .theme-option.active { border-color: #8d2638; box-shadow: 0 0 0 1px #8d2638; }
        .theme-option > i { color: #8d2638; font-size: 20px; }
        .theme-option span { display: grid; gap: 3px; }
        .theme-option strong { font-size: 13px; }
        .theme-option span span { color: #7a8494; font-size: 11px; line-height: 1.35; }
        .accent-options { display: flex; flex-wrap: wrap; gap: 10px; }
        .accent-option { width: 34px; height: 34px; border: 3px solid #fff; border-radius: 50%; box-shadow: 0 0 0 1px #d6dce5; cursor: pointer; }
        .accent-option.active { box-shadow: 0 0 0 2px #344054; }
        .font-size-control { display: flex; align-items: center; gap: 14px; max-width: 420px; color: #667085; }
        .font-size-control input { padding: 0; accent-color: #8d2638; }
        .font-size-label.large { font-size: 22px; }
        @media (max-width: 560px) { .theme-options { grid-template-columns: 1fr; } }
        .settings-account-form { max-width:900px; margin:0 auto; }
        .password-tools { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px; }
        .password-input-wrap { position:relative; min-width:0; }
        .password-input-wrap input { min-width:0; padding-right:46px; }
        .password-copy-button { position:absolute; top:50%; right:7px; display:grid; width:32px; height:32px; place-items:center; padding:0; transform:translateY(-50%); border:0; border-radius:7px; background:transparent; color:#667085; cursor:pointer; }
        .password-copy-button:hover:not(:disabled) { background:#eef2f7; color:#1f2937; }
        .password-copy-button:disabled { color:#b8c0cc; cursor:not-allowed; }
        .password-copy-button .material-icons { font-size:20px; }
        .password-tools .button { white-space:nowrap; }
        .password-copy-status { min-height:17px; }
        .staff-account-list { max-width:900px; margin:20px auto 0; }
        .staff-account-rows { display:grid; gap:12px; }
        .staff-account-row { padding:16px; border:1px solid #e5eaf1; border-radius:12px; }
        .staff-account-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:14px; }
        .staff-account-heading strong { display:block; color:#1e293b; font-size:15px; }
        .staff-account-heading span { display:block; margin-top:4px; color:#667085; font-size:13px; overflow-wrap:anywhere; }
        .staff-account-heading > .staff-account-role { flex:0 0 auto; margin:0; padding:5px 9px; border-radius:999px; background:#f1f5f9; color:#475569; font-size:11px; font-weight:700; text-transform:capitalize; }
        .staff-password-form { display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:center; gap:10px; }
        .staff-password-form .form-group { min-width:0; }
        .staff-password-form .button { white-space:nowrap; }
        .staff-account-empty { margin:0; color:#667085; font-size:14px; }
        .settings-detail-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; max-width:900px; margin:0 auto 18px; }
        .settings-detail-heading.audit-detail-heading { max-width:1100px; }
        .settings-detail-heading h2 { margin:0 0 4px; color:#1a202c; font-size:22px; }
        .settings-detail-heading p { margin:0; color:#667085; font-size:13px; }
        .settings-back-link { flex:0 0 auto; padding:10px 14px; border:1px solid #dfe3e8; border-radius:7px; background:#fff; color:#344054; font-size:12px; font-weight:600; text-decoration:none; }
        .settings-layout.single-section { grid-template-columns:minmax(0,1fr); max-width:900px; margin:0 auto; }
        .settings-layout.single-section .settings-card { border-radius:14px; box-shadow:0 8px 22px rgba(52,72,96,.07); }
        .settings-header,
        .settings-overview,
        .settings-detail-heading,
        .settings-layout.single-section,
        .settings-account-form,
        .staff-account-list,
        .settings-page > .settings-card {
            width:100%;
            max-width:900px;
            margin-left:auto;
            margin-right:auto;
        }
        .staff-account-list { margin-top:20px; }
        .settings-page > form > .settings-layout.single-section { margin-top:0; }
        .settings-page > form > .settings-alert-actions {
            width:100%;
            max-width:900px;
            margin:22px auto 0;
            padding:0 24px;
        }
        .settings-page > .message,
        .settings-page > .warning {
            width:100%;
            max-width:900px;
            margin-left:auto;
            margin-right:auto;
        }
        .audit-log-panel { width:100%; max-width:1100px; margin:0 auto; padding:18px; overflow:hidden; border:1px solid #e5eaf1; border-radius:14px; background:#fff; box-shadow:0 8px 22px rgba(52,72,96,.07); }
        .audit-log-toolbar { display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
        .audit-log-toolbar select,.audit-log-toolbar input { width:auto; min-width:120px; height:36px; padding:0 10px; border:1px solid #d9e0ea; border-radius:7px; background:#fff; color:#344054; font:500 12px 'Segoe UI',Tahoma,sans-serif; }
        .audit-log-toolbar .audit-search { flex:1 1 150px; min-width:140px; }
        .audit-log-toolbar .audit-date { min-width:140px; }
        .audit-export-button { display:inline-flex; height:36px; align-items:center; justify-content:center; gap:6px; padding:0 13px; border:0; border-radius:7px; background:#2878e8; color:#fff; font-size:12px; font-weight:700; text-decoration:none; white-space:nowrap; }
        .audit-export-button:hover,.audit-export-button:focus { background:#1f68d0; color:#fff; }
        .audit-log-table-wrap { width:100%; overflow:auto; border:1px solid #e7ebf0; border-radius:8px; }
        .audit-log-table { width:100%; min-width:760px; border-collapse:collapse; }
        .audit-log-table th { padding:12px 10px; border-bottom:1px solid #e5eaf1; background:#f3f6fb; color:#536174; text-align:left; font-size:11px; font-weight:700; white-space:nowrap; }
        .audit-log-table td { padding:10px; border-bottom:1px solid #edf0f4; color:#344054; text-align:left; vertical-align:middle; font-size:11px; line-height:1.45; }
        .audit-log-table tr:last-child td { border-bottom:0; }
        .audit-log-user { display:flex; align-items:center; gap:8px; min-width:125px; }
        .audit-log-user-icon { display:grid; width:28px; height:28px; flex:0 0 28px; place-items:center; border-radius:50%; background:#e8eef8; color:#607594; }
        .audit-log-user-icon i { font-size:17px; }
        .audit-log-user-name { color:#344054; font-weight:600; overflow-wrap:anywhere; }
        .audit-log-user-role { color:#7a8494; font-size:10px; text-transform:capitalize; }
        .audit-action-badge { display:inline-flex; padding:3px 8px; border-radius:999px; background:#e8f2ff; color:#2166bf; font-size:10px; font-weight:700; white-space:nowrap; }
        .audit-action-badge.login,.audit-action-badge.password { background:#d9f7ee; color:#177a5b; }
        .audit-action-badge.create,.audit-action-badge.add { background:#dcecff; color:#2563a6; }
        .audit-action-badge.update,.audit-action-badge.edit { background:#fff1d6; color:#a45e00; }
        .audit-action-badge.delete,.audit-action-badge.remove { background:#ffe1e4; color:#b4233b; }
        .audit-action-badge.view { background:#eee5ff; color:#6941c6; }
        .audit-log-details { max-width:420px; overflow-wrap:anywhere; }
        .audit-log-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:12px; color:#667085; font-size:11px; }
        .audit-log-pagination { display:flex; align-items:center; gap:5px; }
        .audit-log-pagination a,.audit-log-pagination span { display:inline-flex; min-width:28px; height:28px; align-items:center; justify-content:center; padding:0 7px; border:1px solid #dfe5ee; border-radius:5px; background:#fff; color:#475467; text-decoration:none; }
        .audit-log-pagination a.current { border-color:#2878e8; background:#2878e8; color:#fff; }
        .audit-empty-state { padding:42px 20px; color:#667085; text-align:center; }
        @media (max-width:760px) {
            .audit-log-panel { padding:12px; }
            .audit-log-toolbar { justify-content:stretch; }
            .audit-log-toolbar select,.audit-log-toolbar input,.audit-log-toolbar .audit-search,.audit-log-toolbar .audit-date { flex:1 1 calc(50% - 8px); width:100%; min-width:0; }
            .audit-log-toolbar .audit-export-button { flex:1 1 100%; }
            .audit-log-footer { align-items:flex-start; flex-direction:column; }
        }
        @media (max-width:760px) {
            .settings-header { align-items:flex-start; margin-bottom:22px; }
            .settings-header-icon { width:45px; height:45px; flex-basis:45px; }
            .settings-header h1 { font-size:25px; }
            .settings-detail-heading { flex-direction:column; }
            .settings-option { min-height:92px; padding:16px; }
            .settings-option-copy strong { font-size:17px; }
            .staff-password-form { grid-template-columns:1fr; }
            .staff-account-heading { flex-wrap:wrap; }
        }
    </style>
</head>
<body>
    <?php $currentPage = 'settings.php'; include 'sidebar_owner.php'; ?>

    <main class="settings-page">
        <?php if ($activeSettingsSection === ''): ?>
        <div class="settings-header">
            <div class="settings-header-icon"><i class="material-icons">settings</i></div>
            <div>
                <h1>Settings</h1>
                <p>Manage inventory behavior and system preferences</p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="message success settings-toast" role="status" aria-live="polite">
                <span class="settings-toast-icon" aria-hidden="true">✓</span>
                <span class="settings-toast-copy">
                    <strong class="settings-toast-title">Success</strong>
                    <span class="settings-toast-message"><?php echo h($successMessage); ?></span>
                </span>
                <button class="settings-toast-close" type="button" aria-label="Dismiss success message">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="message error"><?php echo h($errorMessage); ?></div>
        <?php endif; ?>

                <?php if (!$settingsTableReady && $activeSettingsSection === 'alerts'): ?>
                <div class="warning">I-import muna ang <strong>inventory_settings.sql</strong> sa database na <strong>admin_dashboard_system_fresh</strong>, pagkatapos ay i-refresh ang page na ito.</div>
        <?php else: ?>
            <?php if ($activeSettingsSection === ''): ?>
                <nav class="settings-overview" aria-label="Settings navigation">
                <a class="settings-option" href="profile.php"><span class="settings-option-icon"><i class="material-icons">account_circle</i></span><span class="settings-option-copy"><strong>Profile</strong><span>Update your personal information and account details</span></span></a>
                <a class="settings-option" href="settings.php?section=password"><span class="settings-option-icon"><i class="material-icons">lock</i></span><span class="settings-option-copy"><strong>Password</strong><span>Change your account password securely</span></span></a>
                <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'pharmacist'], true)): ?><a class="settings-option" href="settings.php?section=audit"><span class="settings-option-icon"><i class="material-icons">history</i></span><span class="settings-option-copy"><strong>Audit Logs</strong><span><?php echo ($_SESSION['role'] ?? '') === 'admin' ? 'Review recent system and account activity' : 'Review your recent account activity'; ?></span></span></a><?php endif; ?>
                <a class="settings-option" href="settings.php?section=alerts"><span class="settings-option-icon"><i class="material-icons">notifications</i></span><span class="settings-option-copy"><strong>Inventory Alerts</strong><span>Manage low-stock, expiration, and email notifications</span></span></a>
                    <a class="settings-option" href="settings.php?section=appearance"><span class="settings-option-icon"><i class="material-icons">palette</i></span><span class="settings-option-copy"><strong>Appearance</strong><span>Choose theme, accent color, and text size for the dashboard</span></span></a>
                <?php if (($_SESSION['role'] ?? '') === 'admin'): ?><a class="settings-option" href="settings.php?section=accounts"><span class="settings-option-icon"><i class="material-icons">manage_accounts</i></span><span class="settings-option-copy"><strong>Accounts &amp; Access</strong><span>Create Pharmacist and Rider accounts and reset staff passwords</span></span></a><?php endif; ?>
                </nav>
            <?php else: ?>
                <?php
                    $sectionDetails = [
                        'accounts' => ['Accounts & Access', 'Create and manage Pharmacist and Rider accounts'],
                        'password' => ['Password', 'Change your account password'],
                        'appearance' => ['Appearance', 'Personalize the owner and pharmacist dashboard'],
                        'alerts' => ['Inventory Alerts', 'Manage low-stock, expiration, and email notifications'],
                        'audit' => ['Audit Logs', ($_SESSION['role'] ?? '') === 'admin' ? 'Recent system and account activity (up to 100 entries)' : 'Your recent account activity (up to 100 entries)'],
                    ];
                ?>
                <div class="settings-detail-heading<?php echo $activeSettingsSection === 'audit' ? ' audit-detail-heading' : ''; ?>">
                    <div><h2><?php echo h($sectionDetails[$activeSettingsSection][0]); ?></h2><p><?php echo h($sectionDetails[$activeSettingsSection][1]); ?></p></div>
                    <a class="settings-back-link" href="settings.php">Back to Settings</a>
                </div>
                <?php if ($activeSettingsSection === 'password'): ?>
                    <section class="settings-card settings-layout single-section">
                        <h2>Change Password</h2>
                        <p class="card-description">Enter your current password to verify your account, then choose a new password. If you have forgotten it, ask an admin for a temporary password first.</p>
                        <form method="POST" autocomplete="off">
                            <input type="hidden" name="action" value="change_password">
                            <input type="hidden" name="settings_section" value="password">
                            <input type="hidden" name="csrf_token" value="<?php echo h($passwordCsrfToken); ?>">
                            <div class="form-grid">
                                <div class="form-group full">
                                    <label for="current_password">Current password</label>
                                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password" placeholder="Enter your current password">
                                </div>
                                <div class="form-group">
                                    <label for="new_password">New password</label>
                                    <input id="new_password" name="new_password" type="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password">Confirm new password</label>
                                    <input id="confirm_password" name="confirm_password" type="password" required minlength="8" autocomplete="new-password" placeholder="Re-enter your new password">
                                </div>
                            </div>
                            <p class="hint">Choose a password with at least 8 characters. Never share your password with anyone.</p>
                            <div class="actions">
                                <button type="reset" class="button secondary">Clear fields</button>
                                <button type="submit" class="button primary">Update Password</button>
                            </div>
                        </form>
                    </section>
                <?php elseif ($activeSettingsSection === 'accounts'): ?>
                    <section class="settings-card settings-account-form">
                        <h2>Create Account</h2>
                        <p class="card-description">Create a login for a Pharmacist or Rider. The temporary password is for the first sign-in; the staff member must enter it with a new password in Profile before using the dashboard.</p>
                        <?php if (!$staffSmtpConfigured): ?>
                            <div class="message error" role="status">
                                Email is not configured. For Gmail, create a Google App Password and copy the values from
                                <code>smtp.env.example</code> to <code>capstone-mail.env</code> in the application root, replacing the email and app-password placeholders.
                                Keep the root <code>.htaccess</code> file in place to block direct web access. New accounts can still be created, but this app will not email passwords until SMTP is configured.
                            </div>
                        <?php endif; ?>
                        <form method="POST" novalidate>
                            <input type="hidden" name="action" value="create_staff_account">
                            <input type="hidden" name="settings_section" value="accounts">
                            <input type="hidden" name="csrf_token" value="<?php echo h($accountCsrfToken); ?>">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="account_full_name">Full name</label>
                                    <input id="account_full_name" name="full_name" type="text" maxlength="255" required>
                                </div>
                                <div class="form-group">
                                    <label for="account_email">Email address</label>
                                    <input id="account_email" name="email" type="email" maxlength="100" required autocomplete="email">
                                </div>
                                <div class="form-group">
                                    <label for="temporary_password">Temporary password</label>
                                    <div class="password-tools">
                                        <div class="password-input-wrap">
                                            <input id="temporary_password" name="temporary_password" type="text" minlength="8" required autocomplete="off" autocapitalize="off" spellcheck="false" readonly>
                                            <button class="password-copy-button" type="button" id="copyTemporaryPassword" aria-label="Copy temporary password" title="Copy temporary password" disabled><i class="material-icons" aria-hidden="true">content_copy</i></button>
                                        </div>
                                        <button class="button secondary password-generate-button" type="button" id="generateTemporaryPassword">Generate</button>
                                    </div>
                                    <span class="hint">Generate and copy this one-time reset password before submitting so you can securely share it if email is unavailable.</span>
                                    <span class="hint password-copy-status" id="temporaryPasswordStatus" role="status" aria-live="polite"></span>
                                </div>
                                <div class="form-group">
                                    <label for="account_role">User role</label>
                                    <select id="account_role" name="account_role" required>
                                        <option value="">Select a role</option>
                                        <option value="pharmacist">Pharmacist</option>
                                        <option value="rider">Rider</option>
                                    </select>
                                </div>
                            </div>
                            <div class="actions">
                                <button type="reset" class="button secondary">Clear fields</button>
                                <button type="submit" class="button primary">Create account</button>
                            </div>
                        </form>
                    </section>
                    <section class="settings-card staff-account-list">
                        <h2>Existing Accounts</h2>
                        <p class="card-description">Kapag nakalimutan ng staff ang password, sila ang direktang makikipag-ugnayan sa owner para magtakda ng one-time temporary password. Gagamitin ito sa Profile para makapag-save ng bagong password.</p>
                        <?php if (!$staffAccounts): ?>
                            <p class="staff-account-empty">Wala pang Pharmacist o Rider account.</p>
                        <?php else: ?>
                            <div class="staff-account-rows">
                                <?php foreach ($staffAccounts as $staffAccount): ?>
                                    <?php $staffPasswordId = 'staff_temporary_password_' . (int)$staffAccount['id']; ?>
                                    <article class="staff-account-row">
                                        <div class="staff-account-heading">
                                            <div>
                                                <strong><?php echo h($staffAccount['full_name'] ?: $staffAccount['username']); ?></strong>
                                                <span><?php echo h($staffAccount['email']); ?></span>
                                            </div>
                                            <span class="staff-account-role"><?php echo h($staffAccount['role']); ?></span>
                                        </div>
                                        <form class="staff-password-form" method="POST">
                                            <input type="hidden" name="action" value="reset_staff_password">
                                            <input type="hidden" name="settings_section" value="accounts">
                                            <input type="hidden" name="csrf_token" value="<?php echo h($accountCsrfToken); ?>">
                                            <input type="hidden" name="staff_user_id" value="<?php echo (int)$staffAccount['id']; ?>">
                                            <div class="form-group">
                                                <label for="<?php echo h($staffPasswordId); ?>">One-time temporary password</label>
                                                <div class="password-tools">
                                                    <div class="password-input-wrap">
                                                        <input id="<?php echo h($staffPasswordId); ?>" name="staff_temporary_password" type="text" minlength="8" required autocomplete="off" autocapitalize="off" spellcheck="false">
                                                        <button class="password-copy-button" type="button" aria-label="Copy temporary password" title="Copy temporary password" disabled><i class="material-icons" aria-hidden="true">content_copy</i></button>
                                                    </div>
                                                    <button class="button secondary password-generate-button" type="button">Generate</button>
                                                </div>
                                                <span class="hint password-copy-status" role="status" aria-live="polite"></span>
                                            </div>
                                            <button type="submit" class="button primary">Set temporary password</button>
                                        </form>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php elseif ($activeSettingsSection === 'audit'): ?>
                    <section class="audit-log-panel" aria-label="<?php echo ($_SESSION['role'] ?? '') === 'admin' ? 'Recent audit logs' : 'My audit logs'; ?>">
                        <?php if (!$auditLogsReady): ?>
                            <?php if ($auditLogsError !== ''): ?>
                                <p class="message error settings-card"><?php echo h($auditLogsError); ?></p>
                            <?php else: ?>
                                <p class="message error settings-card">The audit log table is not installed. Import or update <code>system.sql</code>, then refresh this page.</p>
                            <?php endif; ?>
                        <?php elseif ($auditLogsError !== ''): ?>
                            <p class="message error settings-card"><?php echo h($auditLogsError); ?></p>
                        <?php else: ?>
                            <div class="audit-log-toolbar">
                            <form method="get" aria-label="Filter audit logs" style="display:contents">
                                                <input type="hidden" name="section" value="audit">
                                                <input class="audit-search" type="search" name="audit_search" value="<?php echo h($auditFilters['search']); ?>" placeholder="Search logs..." aria-label="Search audit logs" onchange="this.form.submit()">
                                <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                                    <select name="audit_user" aria-label="Filter by account" onchange="this.form.submit()">
                                        <option value="">All Accounts</option>
                                        <?php foreach ($auditAccounts as $auditAccount): ?>
                                            <option value="<?php echo (int)$auditAccount['id']; ?>" <?php echo $auditFilters['user_id'] === (string)$auditAccount['id'] ? 'selected' : ''; ?>>
                                                <?php echo h(($auditAccount['full_name'] ?: $auditAccount['username']) . ' (' . $auditAccount['role'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <select name="audit_action" aria-label="Filter by action" onchange="this.form.submit()">
                                    <option value="">All Actions</option>
                                    <?php foreach ($auditActions as $auditAction): ?>
                                        <option value="<?php echo h($auditAction); ?>" <?php echo $auditFilters['action'] === $auditAction ? 'selected' : ''; ?>><?php echo h(ucwords(str_replace('_', ' ', $auditAction))); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input class="audit-date" type="date" name="date_from" value="<?php echo h($auditFilters['date_from']); ?>" aria-label="From date" onchange="this.form.submit()">
                                <input class="audit-date" type="date" name="date_to" value="<?php echo h($auditFilters['date_to']); ?>" aria-label="To date" onchange="this.form.submit()">
            </form>
                                <a class="audit-export-button" href="<?php echo h($auditExportUrl); ?>"><i class="material-icons" aria-hidden="true">file_download</i> Export</a>
            </div>
                            <div class="audit-log-table-wrap">
                                <table class="audit-log-table">
                                    <thead><tr><th>Date &amp; Time</th><th>User</th><th>Action</th><th>Module</th><th>Details</th></tr></thead>
                                    <tbody>
                                        <?php if (!$auditLogs): ?>
                                            <tr><td colspan="5" class="audit-empty-state">No audit events match these filters.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($auditLogs as $auditRow): ?>
                                                <?php
                                                    $auditDetails = json_decode((string)($auditRow['details'] ?? ''), true);
                                                    $detailsText = is_array($auditDetails)
                                                        ? (json_encode($auditDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
                                                        : '';
                                                    $auditAction = (string)$auditRow['action'];
                                                    $auditActionLabel = ucwords(str_replace('_', ' ', $auditAction));
                                                    $auditEntityLabel = ucfirst((string)$auditRow['entity_type']);
                                                    if ($auditRow['entity_id'] !== null) {
                                                        $auditEntityLabel .= ' #' . $auditRow['entity_id'];
                                                    }
                                                    $auditTimestamp = strtotime((string)$auditRow['created_at']);
                                                    $auditDateTimeLabel = $auditTimestamp !== false
                                                        ? date('M d, Y h:i A', $auditTimestamp)
                                                        : (string)$auditRow['created_at'];
                                                    $auditActionClass = preg_replace('/[^a-z0-9_-]/', '-', strtolower($auditAction));
                                                ?>
                                                <tr>
                                                    <td><?php echo h($auditDateTimeLabel); ?></td>
                                                    <td><div class="audit-log-user"><span class="audit-log-user-icon"><i class="material-icons" aria-hidden="true">account_circle</i></span><span><span class="audit-log-user-name"><?php echo h($auditRow['actor_name']); ?></span><br><span class="audit-log-user-role"><?php echo h($auditRow['display_role']); ?></span></span></div></td>
                                                    <td><span class="audit-action-badge <?php echo h($auditActionClass); ?>"><?php echo h($auditActionLabel); ?></span></td>
                                                    <td><?php echo h($auditEntityLabel); ?></td>
                                                    <td class="audit-log-details"><?php echo h($detailsText !== '' ? $detailsText : 'Audit event recorded.'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($auditTotal > 0): ?>
                                <?php $auditShowingStart = ($auditPage - 1) * $auditPerPage + 1; $auditShowingEnd = min($auditPage * $auditPerPage, $auditTotal); ?>
                                <div class="audit-log-footer">
                                    <span>Showing <?php echo number_format($auditShowingStart); ?>–<?php echo number_format($auditShowingEnd); ?> of <?php echo number_format($auditTotal); ?> logs</span>
                                    <nav class="audit-log-pagination" aria-label="Audit log pages">
                                        <?php if ($auditPage > 1): ?><a href="settings.php?<?php echo h(http_build_query(array_merge($auditPageParams, ['audit_page' => $auditPage - 1]))); ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?>
                                        <?php foreach ($auditPageItems as $auditPageItem): ?><a class="<?php echo $auditPageItem === $auditPage ? 'current' : ''; ?>" href="settings.php?<?php echo h(http_build_query(array_merge($auditPageParams, ['audit_page' => $auditPageItem]))); ?>" <?php echo $auditPageItem === $auditPage ? 'aria-current="page"' : ''; ?>><?php echo $auditPageItem; ?></a><?php endforeach; ?>
                                        <?php if ($auditPage < $auditTotalPages): ?><a href="settings.php?<?php echo h(http_build_query(array_merge($auditPageParams, ['audit_page' => $auditPage + 1]))); ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </section>
                <?php elseif ($activeSettingsSection === 'alerts'): ?>
                <form method="POST">
                    <input type="hidden" name="settings_section" value="alerts">
                    <div class="settings-layout single-section">
                    <section class="settings-card" <?php echo $activeSettingsSection !== 'alerts' ? 'hidden' : ''; ?>>
                        <h2>Inventory Notifications</h2>
                        <p class="card-description">I-on o i-off ang automatic alerts at notifications ng inventory system.</p>
                        <div class="toggle-list">
                            <div class="toggle-row">
                                <div class="toggle-copy"><strong>Low-stock alerts</strong><span>Magpakita ng alerts kapag mababa ang stock.</span></div>
                                <label class="switch"><input type="checkbox" name="enable_low_stock_alerts" <?php echo !empty($settings['enable_low_stock_alerts']) ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>
                            <div class="toggle-row">
                                <div class="toggle-copy"><strong>Expiration alerts</strong><span>Mag-monitor ng products na malapit nang mag-expire.</span></div>
                                <label class="switch"><input type="checkbox" name="enable_expiration_alerts" <?php echo !empty($settings['enable_expiration_alerts']) ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>
                            <div class="toggle-row">
                                <div class="toggle-copy"><strong>Email notifications</strong><span>Payagan ang system na magpadala ng inventory emails.</span></div>
                                <label class="switch"><input type="checkbox" name="enable_email_notifications" <?php echo !empty($settings['enable_email_notifications']) ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>
                            <div class="toggle-row">
                                <div class="toggle-copy"><strong>Auto-create alert rules</strong><span>Awtomatikong gumawa ng alert rule kapag may bagong product.</span></div>
                                <label class="switch"><input type="checkbox" name="auto_create_inventory_alerts" <?php echo !empty($settings['auto_create_inventory_alerts']) ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>
                        </div>
                    </section>
                </div>
                    <div class="actions settings-alert-actions">
                        <button type="submit" name="save_settings" value="1" class="button primary">Save Alert Settings</button>
                    </div>
                </form>
                <?php elseif ($activeSettingsSection === 'appearance'): ?>
                <section class="settings-card settings-layout single-section" id="appearance-settings">
                    <h2>Dashboard Appearance</h2>
                    <p class="card-description">These preferences are saved in this browser and apply across dashboards on this device.</p>

                    <div class="appearance-group">
                        <h3 class="appearance-group-title">Theme</h3>
                        <p class="appearance-group-description">Choose light, dark, or match your device preference.</p>
                        <div class="theme-options" role="group" aria-label="Dashboard theme">
                            <button class="theme-option" type="button" data-theme="light"><i class="material-icons">light_mode</i><span><strong>Light</strong><span>Bright dashboard</span></span></button>
                            <button class="theme-option" type="button" data-theme="dark"><i class="material-icons">dark_mode</i><span><strong>Dark</strong><span>Low-light dashboard</span></span></button>
                            <button class="theme-option" type="button" data-theme="system"><i class="material-icons">settings_brightness</i><span><strong>System</strong><span>Follow device setting</span></span></button>
                        </div>
                    </div>

                    <div class="appearance-group">
                        <h3 class="appearance-group-title">Accent color</h3>
                        <p class="appearance-group-description">Select the highlight color used for buttons and active elements.</p>
                        <div class="accent-options" role="group" aria-label="Dashboard accent color">
                            <button type="button" class="accent-option" data-accent="#8d2638" style="background:#8d2638" aria-label="Burgundy"></button>
                            <button type="button" class="accent-option" data-accent="#c56b7b" style="background:#c56b7b" aria-label="Rose"></button>
                            <button type="button" class="accent-option" data-accent="#7f3f98" style="background:#7f3f98" aria-label="Purple"></button>
                            <button type="button" class="accent-option" data-accent="#2856a6" style="background:#2856a6" aria-label="Blue"></button>
                            <button type="button" class="accent-option" data-accent="#4d963b" style="background:#4d963b" aria-label="Green"></button>
                            <button type="button" class="accent-option" data-accent="#df7b24" style="background:#df7b24" aria-label="Orange"></button>
                            <button type="button" class="accent-option" data-accent="#718096" style="background:#718096" aria-label="Slate"></button>
                        </div>
                    </div>

                    <div class="appearance-group">
                        <h3 class="appearance-group-title">Text size</h3>
                        <p class="appearance-group-description">Adjust the overall dashboard text size.</p>
                        <label class="font-size-control" for="appearance-font-scale">
                            <span class="font-size-label">A</span>
                            <input id="appearance-font-scale" type="range" min="0.9" max="1.15" step="0.05" value="1">
                            <span class="font-size-label large">A</span>
                            <output id="appearance-font-scale-value">100%</output>
                        </label>
                    </div>

                    <div class="actions">
                        <button type="button" class="button secondary" id="reset-appearance">Reset to default</button>
                        <button type="button" class="button primary" id="save-appearance">Save Appearance</button>
                    </div>
                    <p class="appearance-save-status" id="appearance-save-status" role="status" aria-live="polite"></p>
                </section>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php if ($activeSettingsSection === 'accounts'): ?>
    <script>
    (function () {
        const randomIndex = (length) => {
            const limit = Math.floor(256 / length) * length;
            const byte = new Uint8Array(1);
            do {
                crypto.getRandomValues(byte);
            } while (byte[0] >= limit);
            return byte[0] % length;
        };

        document.querySelectorAll('.password-tools').forEach(function (passwordTools) {
            const passwordInput = passwordTools.querySelector('input');
            const generateButton = passwordTools.querySelector('.password-generate-button');
            const copyButton = passwordTools.querySelector('.password-copy-button');
            const form = passwordTools.closest('form');
            const status = form && form.querySelector('.password-copy-status');
            if (!passwordInput || !generateButton || !copyButton || !form || !status) return;

            passwordInput.addEventListener('input', function () {
                copyButton.disabled = passwordInput.value.length === 0;
                status.textContent = passwordInput.value ? 'Temporary password ready.' : '';
            });

            generateButton.addEventListener('click', function () {
                const groups = [
                    'ABCDEFGHJKLMNPQRSTUVWXYZ',
                    'abcdefghijkmnopqrstuvwxyz',
                    '23456789',
                    '!@#$%*-_'
                ];
                const allCharacters = groups.join('');
                const password = groups.map(group => group[randomIndex(group.length)]);
                while (password.length < 16) {
                    password.push(allCharacters[randomIndex(allCharacters.length)]);
                }
                for (let index = password.length - 1; index > 0; index--) {
                    const swapIndex = randomIndex(index + 1);
                    [password[index], password[swapIndex]] = [password[swapIndex], password[index]];
                }
                passwordInput.value = password.join('');
                copyButton.disabled = false;
                status.textContent = 'Temporary password generated.';
            });

            copyButton.addEventListener('click', async function () {
                if (!passwordInput.value) {
                    status.textContent = 'Generate a password first.';
                    return;
                }
                try {
                    await navigator.clipboard.writeText(passwordInput.value);
                    status.textContent = 'Password copied. Give it to the account holder.';
                } catch (error) {
                    passwordInput.focus();
                    passwordInput.select();
                    const copied = document.execCommand('copy');
                    status.textContent = copied
                        ? 'Password copied. Give it to the account holder.'
                        : 'Copy failed. Select the password and copy it manually.';
                }
            });

            form.addEventListener('reset', function () {
                window.setTimeout(function () {
                    copyButton.disabled = true;
                    status.textContent = '';
                }, 0);
            });
        });
    }());
    </script>
    <?php endif; ?>
    <?php if ($activeSettingsSection === 'appearance'): ?>
    <script>
    (function () {
        const defaultTheme = 'light';
        const defaultAccent = '#8d2638';
        const defaultFontScale = '1';
        const themeButtons = Array.from(document.querySelectorAll('.theme-option'));
        const accentButtons = Array.from(document.querySelectorAll('.accent-option'));
        const fontScaleInput = document.getElementById('appearance-font-scale');
        const fontScaleOutput = document.getElementById('appearance-font-scale-value');
        const status = document.getElementById('appearance-save-status');
        let appearance = {
            theme: localStorage.getItem('appearanceTheme') || defaultTheme,
            accent: localStorage.getItem('appearanceAccent') || defaultAccent,
            fontScale: localStorage.getItem('appearanceFontScale') || defaultFontScale
        };

        function renderAppearance() {
            themeButtons.forEach(function (button) {
                const selected = button.dataset.theme === appearance.theme;
                button.classList.toggle('active', selected);
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            accentButtons.forEach(function (button) {
                const selected = button.dataset.accent === appearance.accent;
                button.classList.toggle('active', selected);
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            fontScaleInput.value = appearance.fontScale;
            fontScaleOutput.value = Math.round(Number(appearance.fontScale) * 100) + '%';
            fontScaleOutput.textContent = fontScaleOutput.value;
            window.applyGlobalAppearance(appearance.theme, appearance.accent, appearance.fontScale);
        }

        themeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                appearance.theme = button.dataset.theme;
                renderAppearance();
            });
        });
        accentButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                appearance.accent = button.dataset.accent;
                renderAppearance();
            });
        });
        fontScaleInput.addEventListener('input', function () {
            appearance.fontScale = fontScaleInput.value;
            renderAppearance();
        });
        document.getElementById('reset-appearance').addEventListener('click', function () {
            appearance = { theme: defaultTheme, accent: defaultAccent, fontScale: defaultFontScale };
            status.textContent = 'Defaults previewed. Select Save Appearance to keep these changes.';
            renderAppearance();
        });
        document.getElementById('save-appearance').addEventListener('click', function () {
            localStorage.setItem('appearanceTheme', appearance.theme);
            localStorage.setItem('appearanceAccent', appearance.accent);
            localStorage.setItem('appearanceFontScale', appearance.fontScale);
            status.textContent = 'Appearance preferences saved for this browser.';
        });

        renderAppearance();
    }());
    </script>
    <?php endif; ?>
    <script>
        document.querySelectorAll('.settings-toast-close').forEach(function (button) {
            button.addEventListener('click', function () {
                button.closest('.settings-toast').remove();
            });
        });
    </script>
</body>
</html>
