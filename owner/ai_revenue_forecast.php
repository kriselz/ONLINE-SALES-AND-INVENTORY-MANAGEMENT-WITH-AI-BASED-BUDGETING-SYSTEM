<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include 'config.php';
include 'functions.php';

function respondWithForecastError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    respondWithForecastError(401, 'Admin login required.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respondWithForecastError(405, 'POST is required.');
}

$requestBody = file_get_contents('php://input');
if ($requestBody === false || strlen($requestBody) > 4096) {
    respondWithForecastError(400, 'Invalid forecast request.');
}

$request = json_decode($requestBody, true);
$expectedCsrfToken = (string)($_SESSION['ai_revenue_forecast_csrf'] ?? '');
if (!is_array($request)
    || $expectedCsrfToken === ''
    || !hash_equals($expectedCsrfToken, (string)($request['csrf_token'] ?? ''))) {
    respondWithForecastError(403, 'The forecast request could not be verified. Refresh the page and try again.');
}

$period = (string)($request['period'] ?? '');
if (!in_array($period, ['weekly', 'monthly', 'yearly', 'custom'], true)) {
    respondWithForecastError(400, 'Invalid forecast period.');
}

$dateFromInput = (string)($request['date_from'] ?? '');
$dateToInput = (string)($request['date_to'] ?? '');
$dateFrom = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFromInput);
$dateTo = DateTimeImmutable::createFromFormat('!Y-m-d', $dateToInput);
if (!$dateFrom || !$dateTo
    || $dateFrom->format('Y-m-d') !== $dateFromInput
    || $dateTo->format('Y-m-d') !== $dateToInput) {
    respondWithForecastError(400, 'A valid start and end date are required.');
}

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$historyDays = (int)$dateFrom->diff($dateTo)->format('%a') + 1;
if ($historyDays > 3650) {
    respondWithForecastError(400, 'The selected date range is too large to forecast.');
}

$forecastGranularity = 'day';
if ($period === 'yearly' || ($period === 'custom' && $historyDays > 180)) {
    $forecastGranularity = 'month';
} elseif ($period === 'custom' && $historyDays > 31) {
    $forecastGranularity = 'week';
}

if ($period === 'weekly') {
    $forecastSteps = 7;
} elseif ($period === 'monthly') {
    $forecastSteps = 30;
} elseif ($period === 'yearly') {
    $forecastSteps = 12;
} elseif ($forecastGranularity === 'month') {
    $forecastSteps = (int)ceil($historyDays / 30);
} elseif ($forecastGranularity === 'week') {
    $forecastSteps = (int)ceil($historyDays / 7);
} else {
    $forecastSteps = $historyDays;
}

$getRevenueBucket = static function (DateTimeImmutable $date) use ($forecastGranularity): array {
    if ($forecastGranularity === 'month') {
        $bucketDate = $date->modify('first day of this month');
        return [$bucketDate->format('Y-m'), $bucketDate->format('M Y')];
    }
    if ($forecastGranularity === 'week') {
        $bucketDate = $date->modify('monday this week');
        return [$bucketDate->format('Y-m-d'), $bucketDate->format('M j')];
    }
    return [$date->format('Y-m-d'), $date->format('M j')];
};

$revenueRows = getRevenueByDate($conn, $dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d') . ' 23:59:59');
$dailyRevenue = [];
if ($revenueRows) {
    $revenueRows->data_seek(0);
    while ($row = $revenueRows->fetch_assoc()) {
        $dailyRevenue[$row['date']] = (float)$row['revenue'];
    }
}

$historyBuckets = [];
for ($date = $dateFrom; $date <= $dateTo; $date = $date->modify('+1 day')) {
    [$bucketKey, $bucketLabel] = $getRevenueBucket($date);
    if (!isset($historyBuckets[$bucketKey])) {
        $historyBuckets[$bucketKey] = ['label' => $bucketLabel, 'revenue' => 0.0];
    }
    $historyBuckets[$bucketKey]['revenue'] += $dailyRevenue[$date->format('Y-m-d')] ?? 0.0;
}

$history = array_values(array_map(
    static fn(array $bucket): array => [
        'period' => $bucket['label'],
        'revenue' => round($bucket['revenue'], 2)
    ],
    $historyBuckets
));
$hasHistoricalRevenue = false;
foreach ($history as $point) {
    if ($point['revenue'] > 0) {
        $hasHistoricalRevenue = true;
        break;
    }
}
if (count($history) < 2 || !$hasHistoricalRevenue) {
    respondWithForecastError(422, 'There is not enough revenue history in this range for an AI forecast.');
}

$forecastCacheKey = 'revenue_' . hash('sha256', json_encode([
    'period' => $period,
    'start' => $dateFrom->format('Y-m-d'),
    'end' => $dateTo->format('Y-m-d'),
    'granularity' => $forecastGranularity,
    'steps' => $forecastSteps,
    'history' => $history
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$cachedForecast = $_SESSION['ai_forecast_cache'][$forecastCacheKey] ?? null;
if (is_array($cachedForecast)
    && isset($cachedForecast['created_at'], $cachedForecast['payload'])
    && (int)$cachedForecast['created_at'] >= time() - 900
    && is_array($cachedForecast['payload'])) {
    echo json_encode($cachedForecast['payload']);
    exit();
}

$apiKey = getenv('GEMINI_API_KEY');
if (!$apiKey) {
    respondWithForecastError(503, 'AI forecasting is unavailable because the server Gemini key is not configured.');
}
if (!function_exists('curl_init')) {
    respondWithForecastError(503, 'AI forecasting is unavailable because the server HTTP client is not enabled.');
}

$prompt = 'You are forecasting pharmacy business revenue from historical aggregate totals. '
    . 'Return ONLY valid JSON with exactly these keys: forecast (an array of numbers) and summary (a brief plain-language explanation). '
    . 'Forecast exactly ' . $forecastSteps . ' future ' . $forecastGranularity . ' periods. '
    . 'Use only the supplied revenue figures; do not invent events, causes, customers, or outside data. '
    . 'Account for the visible direction and variability in the history, avoid extreme extrapolation, keep values non-negative, '
    . 'and express all amounts in Philippine pesos. '
    . 'Historical revenue by period: ' . json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$requestBody = json_encode([
    'model' => 'gemini-3.8-flash',
    'input' => $prompt,
    'store' => false,
    'generation_config' => [
        'temperature' => 0.2
    ],
    'response_format' => [
        'type' => 'text',
        'mime_type' => 'application/json',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'forecast' => [
                    'type' => 'array',
                    'items' => ['type' => 'number']
                ],
                'summary' => ['type' => 'string']
            ],
            'required' => ['forecast', 'summary']
        ]
    ]
]);
$modelUsed = 'gemini-3.8-flash';

$curl = curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey,
        'Api-Revision: 2026-05-20'
    ],
    CURLOPT_POSTFIELDS => $requestBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60
]);
$response = curl_exec($curl);
$curlError = curl_error($curl);
$curlErrorNumber = curl_errno($curl);
$statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($response !== false && $statusCode === 503) {
    $fallbackRequest = json_decode($requestBody, true);
    if (is_array($fallbackRequest)) {
        $fallbackRequest['model'] = 'gemini-3.5-flash-lite';
        $fallbackBody = json_encode($fallbackRequest);
        if ($fallbackBody !== false) {
            $modelUsed = 'gemini-3.5-flash-lite';
            $curl = curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $apiKey,
                    'Api-Revision: 2026-05-20'
                ],
                CURLOPT_POSTFIELDS => $fallbackBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60
            ]);
            $response = curl_exec($curl);
            $curlError = curl_error($curl);
            $curlErrorNumber = curl_errno($curl);
            $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
        } else {
            error_log('Gemini revenue fallback request JSON encoding failed: ' . json_last_error_msg());
        }
    }
}

if ($response === false || $curlError !== '') {
    error_log('Gemini revenue forecast request failed (' . $curlErrorNumber . '): ' . $curlError);
    if ($curlErrorNumber === CURLE_OPERATION_TIMEDOUT) {
        respondWithForecastError(504, 'Gemini took too long to generate the revenue forecast. Please try again.');
    }
    respondWithForecastError(502, 'Could not connect to the AI forecasting service. Try again later.');
}

$responseData = json_decode($response, true);
$responseSteps = $responseData['steps'] ?? [];
$responseText = '';
if (is_array($responseSteps)) {
    foreach ($responseSteps as $responseStep) {
        if (!is_array($responseStep) || ($responseStep['type'] ?? '') !== 'model_output') {
            continue;
        }
        $responseContent = $responseStep['content'] ?? [];
        if (!is_array($responseContent)) {
            continue;
        }
        foreach ($responseContent as $contentPart) {
            if (is_array($contentPart) && isset($contentPart['text']) && is_string($contentPart['text'])) {
                $responseText .= $contentPart['text'];
            }
        }
    }
}
$responseText = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string)$responseText));
$forecastResult = json_decode($responseText, true);
$forecastValues = $forecastResult['forecast'] ?? null;
$summary = $forecastResult['summary'] ?? null;
$validForecast = is_array($forecastValues) && count($forecastValues) === $forecastSteps;
if ($validForecast) {
    foreach ($forecastValues as $value) {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            $validForecast = false;
            break;
        }
    }
}

if ($statusCode >= 400) {
    $apiMessage = $responseData['error']['message'] ?? '';
    error_log('Gemini revenue forecast error (' . $statusCode . ', model ' . $modelUsed . '): ' . (is_string($apiMessage) ? $apiMessage : 'Unspecified provider error.'));
    if ($statusCode === 400) {
        respondWithForecastError(502, 'Gemini rejected the forecast request format. Check the server error log for details.');
    }
    if ($statusCode === 401 || $statusCode === 403) {
        respondWithForecastError(502, 'Gemini could not authorize this API key. Check that the key is active and enabled for the Gemini API.');
    }
    if ($statusCode === 404) {
        respondWithForecastError(502, 'The configured Gemini forecast model is unavailable. Check the model configuration.');
    }
    if ($statusCode === 503) {
        respondWithForecastError(503, 'Gemini is temporarily busy on the primary and backup models. Please try again in a few minutes.');
    }
    if ($statusCode === 429) {
        $retryHint = '';
        if (is_string($apiMessage)
            && preg_match('/retry in\s+([0-9hms ]+)/i', $apiMessage, $retryMatch)) {
            $retryHint = ' Try again in ' . trim($retryMatch[1]) . '.';
        }
        respondWithForecastError(
            503,
            'The Gemini API rate limit or project quota was reached.' . $retryHint . ' Check the quota for the model that was used, or try again after it resets.'
        );
    }
    respondWithForecastError(502, 'The AI forecasting service returned an error. Please try again later.');
}

if (!$validForecast || !is_string($summary) || trim($summary) === '') {
    $interactionStatus = $responseData['status'] ?? 'unknown';
    error_log(
        'Gemini revenue forecast response was invalid. Interaction status: '
        . (is_string($interactionStatus) ? $interactionStatus : 'unknown')
        . '; response text length: ' . strlen($responseText)
        . '; forecast item count: ' . (is_array($forecastValues) ? count($forecastValues) : 0)
        . '; expected item count: ' . $forecastSteps
    );
    respondWithForecastError(502, 'The AI service did not return a valid forecast. Please try again.');
}

$forecastValues = array_map(
    static fn($value): float => round(max(0, (float)$value), 2),
    $forecastValues
);
$forecastPayload = [
    'success' => true,
    'forecast' => $forecastValues,
    'summary' => substr(trim($summary), 0, 400)
];
$_SESSION['ai_forecast_cache'][$forecastCacheKey] = [
    'created_at' => time(),
    'payload' => $forecastPayload
];
echo json_encode($forecastPayload);
