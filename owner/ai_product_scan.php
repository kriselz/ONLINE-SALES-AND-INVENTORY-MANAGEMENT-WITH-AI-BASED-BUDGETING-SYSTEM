<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include 'config.php';
include 'access_control.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'pharmacist'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Staff login required.']);
    exit();
}
requireOwnerPageAccess($conn, true);

$apiKey = getenv('GEMINI_API_KEY');
if (!$apiKey) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'GEMINI_API_KEY is not configured on the server.']);
    exit();
}

$imagesUpload = $_FILES['images'] ?? null;
$images = [];
if ($imagesUpload && is_array($imagesUpload['name'] ?? null)) {
    $imageCount = count($imagesUpload['name']);
    if ($imageCount > 4) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'You can scan up to 4 product images at once.']);
        exit();
    }
    for ($imageIndex = 0; $imageIndex < $imageCount; $imageIndex++) {
        $images[] = [
            'error' => $imagesUpload['error'][$imageIndex] ?? UPLOAD_ERR_NO_FILE,
            'size' => $imagesUpload['size'][$imageIndex] ?? 0,
            'tmp_name' => $imagesUpload['tmp_name'][$imageIndex] ?? '',
            'type' => $imagesUpload['type'][$imageIndex] ?? ''
        ];
    }
} elseif (isset($_FILES['image'])) {
    $images[] = $_FILES['image'];
}

if (!$images) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide a product image.']);
    exit();
}

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
$imageParts = [];
foreach ($images as $image) {
    if (($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($image['tmp_name'] ?? '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'One of the selected images could not be uploaded.']);
        exit();
    }
    if ((int)$image['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Each AI scan image must be smaller than 5 MB.']);
        exit();
    }
    $mime = function_exists('mime_content_type') ? mime_content_type($image['tmp_name']) : ($image['type'] ?? '');
    if (!in_array($mime, $allowedMimes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Use JPG, PNG, or WebP product images.']);
        exit();
    }
    $imageData = file_get_contents($image['tmp_name']);
    if ($imageData === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'One of the selected images could not be read.']);
        exit();
    }
    $imageParts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($imageData)]];
}

$prompt = <<<'PROMPT'
Analyze these product package images and return ONLY valid JSON with these keys:
product_name, sku, barcode, category, description, ingredients, benefits, usage_instructions, brand, product_form, serving_size, prescription_required.
Combine information across all images and use the clearest image as the source for each field. Use empty strings when a value cannot be read. prescription_required must be 0 or 1. Do not invent prices, stock quantities, expiration dates, or lot numbers. Keep category concise, such as Vitamins, Supplements, Prescription Medicine, or Personal Care.
PROMPT;

$parts = [['text' => $prompt], ...$imageParts];

$request = json_encode([
    'contents' => [[
        'parts' => $parts
    ]],
    'generationConfig' => [
        'temperature' => 0.1,
        'responseMimeType' => 'application/json'
    ]
]);

$curl = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=' . urlencode($apiKey));
$response = false;
$curlError = '';
$statusCode = 0;
$retryableStatusCodes = [429, 500, 502, 503, 504];
for ($attempt = 0; $attempt < 3; $attempt++) {
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $request,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($response !== false && !$curlError && !in_array($statusCode, $retryableStatusCodes, true)) {
        break;
    }
    if ($attempt < 2) {
        usleep((int)(500000 * (2 ** $attempt)));
    }
}
curl_close($curl);

if ($response === false || $curlError) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'AI service connection failed.']);
    exit();
}

$responseData = json_decode($response, true);
$text = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
$text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
$details = json_decode($text, true);

if ($statusCode >= 400 || !is_array($details)) {
    $apiMessage = $responseData['error']['message'] ?? '';
    if ($statusCode >= 400 && is_string($apiMessage) && $apiMessage !== '') {
        error_log('Gemini API error (' . $statusCode . '): ' . $apiMessage);
        $message = 'AI service rejected the images (' . $statusCode . '). Try smaller images or fewer images.';
    } elseif ($statusCode < 400 && $text === '') {
        $message = 'AI returned an empty response. Try clearer product images.';
    } else {
        $message = 'AI returned unreadable product details. Try clearer images or fewer images.';
    }
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

$fields = ['product_name', 'sku', 'barcode', 'category', 'description', 'ingredients', 'benefits', 'usage_instructions', 'brand', 'product_form', 'serving_size'];
$cleanDetails = [];
foreach ($fields as $field) {
    $cleanDetails[$field] = is_scalar($details[$field] ?? null) ? trim((string)$details[$field]) : '';
}
$cleanDetails['prescription_required'] = !empty($details['prescription_required']) ? 1 : 0;

$duplicateRows = [];
$duplicateConditions = [];
$duplicateValues = [];
$duplicateTypes = '';
foreach (['product_name', 'sku', 'barcode'] as $duplicateField) {
    $duplicateValue = trim($cleanDetails[$duplicateField] ?? '');
    if ($duplicateValue === '') {
        continue;
    }
    $duplicateConditions[] = "LOWER(TRIM(`$duplicateField`)) = LOWER(TRIM(?))";
    $duplicateValues[] = $duplicateValue;
    $duplicateTypes .= 's';
}
if ($duplicateConditions) {
    $duplicateQuery = $conn->prepare('SELECT id, product_name, sku, barcode FROM products WHERE ' . implode(' OR ', $duplicateConditions) . ' ORDER BY id DESC LIMIT 5');
    if ($duplicateQuery) {
        $duplicateBindValues = [$duplicateTypes];
        foreach ($duplicateValues as $duplicateKey => &$duplicateValue) {
            $duplicateBindValues[] = &$duplicateValue;
        }
        call_user_func_array([$duplicateQuery, 'bind_param'], $duplicateBindValues);
        $duplicateQuery->execute();
        $duplicateResult = $duplicateQuery->get_result();
        while ($duplicateResult && ($duplicateRow = $duplicateResult->fetch_assoc())) {
            $duplicateRows[] = [
                'id' => (int)$duplicateRow['id'],
                'product_name' => (string)$duplicateRow['product_name'],
                'sku' => (string)($duplicateRow['sku'] ?? ''),
                'barcode' => (string)($duplicateRow['barcode'] ?? '')
            ];
        }
        $duplicateQuery->close();
    }
}

$missingFields = [];
foreach (['product_name' => 'Product name', 'category' => 'Category'] as $missingField => $missingLabel) {
    if (($cleanDetails[$missingField] ?? '') === '') {
        $missingFields[] = $missingLabel;
    }
}

echo json_encode([
    'success' => true,
    'details' => $cleanDetails,
    'duplicates' => $duplicateRows,
    'missing_fields' => $missingFields
]);
