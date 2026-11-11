<?php
function isGoogleProfileComplete(array $user): bool {
    $full_name = trim((string) ($user['full_name'] ?? ''));
    $username = trim((string) ($user['username'] ?? ''));
    $email = trim((string) ($user['email'] ?? ''));
    $phone = trim((string) ($user['phone'] ?? ''));
    $dob = trim((string) ($user['dob'] ?? ''));
    $gender = trim((string) ($user['gender'] ?? ''));
    $dob_date = DateTime::createFromFormat('!Y-m-d', $dob);
    $date_errors = DateTime::getLastErrors();

    return preg_match("/^[\\p{L}][\\p{L} .'-]{1,99}$/u", $full_name) === 1
        && preg_match('/^[A-Za-z0-9_.-]{3,20}$/', $username) === 1
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && preg_match('/^09[0-9]{9}$/', $phone) === 1
        && $dob_date !== false
        && $dob_date->format('Y-m-d') === $dob
        && ($date_errors === false || ($date_errors['warning_count'] === 0 && $date_errors['error_count'] === 0))
        && $dob_date <= new DateTime('today')
        && in_array($gender, ['Male', 'Female'], true);
}

function hasPinnedDefaultDeliveryAddress(mysqli $conn, int $userId): bool {
    $stmt = $conn->prepare(
        'SELECT id FROM addresses
         WHERE user_id = ? AND is_default = 1
           AND latitude IS NOT NULL AND longitude IS NOT NULL
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the saved delivery address check: ' . $conn->error);
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to check the saved delivery address: ' . $error);
    }

    $result = $stmt->get_result();
    $hasAddress = $result !== false && $result->num_rows > 0;
    $stmt->close();
    return $hasAddress;
}
