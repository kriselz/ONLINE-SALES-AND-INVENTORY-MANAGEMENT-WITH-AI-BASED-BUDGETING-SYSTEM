<?php

function storeDiscountIdUpload(array $file, int $userId): string
{
    if (
        !isset($file['error'], $file['tmp_name'], $file['size'])
        || $file['error'] !== UPLOAD_ERR_OK
        || !is_uploaded_file($file['tmp_name'])
    ) {
        throw new RuntimeException('Please upload your valid Senior Citizen or PWD ID.');
    }

    if ((int) $file['size'] <= 0 || (int) $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Your discount ID image must be a JPG or PNG up to 5 MB.');
    }

    $mimeType = mime_content_type($file['tmp_name']);
    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Your discount ID must be a JPG or PNG image.');
    }

    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'discount_ids';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare secure discount ID storage.');
    }

    $filename = 'discount_id_' . $userId . '_' . bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Unable to save your discount ID.');
    }

    return 'uploads/discount_ids/' . $filename;
}

function clearStagedDiscountIdUpload(): void
{
    $staged = $_SESSION['checkout_discount_id_upload'] ?? null;
    unset($_SESSION['checkout_discount_id_upload']);
    if (is_array($staged) && isset($staged['path'])) {
        removeDiscountIdUpload((string) $staged['path']);
    }
}

function stageDiscountIdUpload(array $file, int $userId, string $discountType): string
{
    $path = storeDiscountIdUpload($file, $userId);
    clearStagedDiscountIdUpload();
    $_SESSION['checkout_discount_id_upload'] = [
        'user_id' => $userId,
        'discount_type' => $discountType,
        'path' => $path,
        'created_at' => time(),
    ];

    return $path;
}

function removeDiscountIdUpload(string $relativePath): void
{
    if (!preg_match('/^uploads\/discount_ids\/discount_id_[0-9]+_[a-f0-9]{32}\.(?:jpg|png)$/', $relativePath)) {
        return;
    }

    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($absolutePath) && !unlink($absolutePath)) {
        error_log('Unable to remove a staged discount ID upload.');
    }
}

function getStagedDiscountIdUpload(int $userId, string $discountType): ?string
{
    $staged = $_SESSION['checkout_discount_id_upload'] ?? null;
    if (
        !is_array($staged)
        || (int) ($staged['user_id'] ?? 0) !== $userId
        || ($staged['discount_type'] ?? '') !== $discountType
        || (int) ($staged['created_at'] ?? 0) < time() - 1800
        || !isset($staged['path'])
    ) {
        clearStagedDiscountIdUpload();
        return null;
    }

    $path = (string) $staged['path'];
    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    return is_file($absolutePath) ? $path : null;
}
