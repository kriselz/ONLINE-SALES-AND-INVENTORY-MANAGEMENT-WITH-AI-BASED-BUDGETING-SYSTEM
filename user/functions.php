<?php
use PHPMailer\PHPMailer\PHPMailer;

require 'vendor/autoload.php';
require_once __DIR__ . '/../inventory_stock_notifications.php';

function getProductImageUrl($image): string {
    $image = trim((string)$image);
    if ($image === '' || filter_var($image, FILTER_VALIDATE_URL)) {
        return $image;
    }

    $filename = basename(str_replace('\\', '/', $image));
    if ($filename === '' || $filename === '.') {
        return '';
    }

    if (is_file(__DIR__ . '/../owner/uploads/' . $filename)) {
        return '../owner/uploads/' . rawurlencode($filename);
    }
    if (is_file(__DIR__ . '/uploads/' . $filename)) {
        return 'uploads/' . rawurlencode($filename);
    }

    return '../owner/uploads/' . rawurlencode($filename);
}

function addNotification($conn, $message, $user_id = null) {
    $message = $conn->real_escape_string($message);

    $conn->query("INSERT INTO notifications (user_id, message)
                  VALUES (" . ($user_id ?? "NULL") . ", '$message')");
}

function getCustomerSellableStockExpression(): string {
    return "CASE
        WHEN EXISTS (
            SELECT 1 FROM product_batches tracked_batch
            WHERE tracked_batch.product_id = products.id
        ) THEN LEAST(
            COALESCE(products.box, 0) * COALESCE(NULLIF(products.pieces_per_box, 0), 12)
                + COALESCE(products.pieces, 0),
            COALESCE((
                SELECT SUM(sellable_batch.current_quantity)
                FROM product_batches sellable_batch
                WHERE sellable_batch.product_id = products.id
                  AND sellable_batch.recalled = 0
                  AND sellable_batch.expiration_date >= CURDATE()
            ), 0)
        )
        ELSE COALESCE(products.box, 0) * COALESCE(NULLIF(products.pieces_per_box, 0), 12)
            + COALESCE(products.pieces, 0)
    END";
}

function getCustomerSellableStockCondition(): string {
    return '(' . getCustomerSellableStockExpression() . ') > 0';
}

function sendOrderConfirmationEmail($conn, $order_id, $user_email, $user_name) {
    global $mailHost, $mailSMTPAuth, $mailUsername, $mailPassword, $mailSMTPSecure, $mailPort, $mailFromEmail, $mailFromName;

    $order_id = (int)$order_id;
    $user_email = trim((string)$user_email);
    if ($order_id < 1 || !filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
        error_log('Order confirmation email skipped: invalid order or customer email.');
        return false;
    }
    if ($mailUsername === '' || $mailPassword === '' || $mailFromEmail === '') {
        error_log('Order confirmation email failed: SMTP credentials are not configured.');
        return false;
    }
    try {
        $orderStmt = $conn->prepare('SELECT total_amount FROM orders WHERE id = ? LIMIT 1');
        $itemsStmt = $conn->prepare('SELECT oi.quantity, oi.price, p.product_name
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?');
        if (!$orderStmt || !$itemsStmt) {
            error_log('Order confirmation email query preparation failed: ' . $conn->error);
            if ($orderStmt) {
                $orderStmt->close();
            }
            if ($itemsStmt) {
                $itemsStmt->close();
            }
            return false;
        }
        $orderStmt->bind_param('i', $order_id);
        $itemsStmt->bind_param('i', $order_id);
        if (!$orderStmt->execute() || !$itemsStmt->execute()) {
            error_log('Order confirmation email order lookup failed: ' . $conn->error);
            $orderStmt->close();
            $itemsStmt->close();
            return false;
        }
        $order = $orderStmt->get_result()->fetch_assoc();
        $items = $itemsStmt->get_result();
        $orderStmt->close();
        if (!$order || !$items || $items->num_rows === 0) {
            $itemsStmt->close();
            error_log('Order confirmation email skipped: order details are unavailable.');
            return false;
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $mailHost;
        $mail->SMTPAuth = $mailSMTPAuth;
        $mail->Username = $mailUsername;
        $mail->Password = $mailPassword;
        $mail->SMTPSecure = $mailSMTPSecure;
        $mail->Port = $mailPort;
        $mail->SMTPAutoTLS = true;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($mailFromEmail, $mailFromName);
        $mail->addAddress($user_email, (string)$user_name);
        $mail->isHTML(true);
        $formattedOrderNumber = formatOrderNumber($conn, $order_id);
        $mail->Subject = 'Order Confirmation - ' . $formattedOrderNumber;

        $safeName = htmlspecialchars(trim((string)$user_name) ?: 'Customer', ENT_QUOTES, 'UTF-8');
        $safeOrderNumber = htmlspecialchars($formattedOrderNumber, ENT_QUOTES, 'UTF-8');
        $body = '<h2>Thank you for your order, ' . $safeName . '!</h2>'
            . '<p>Order number: <strong>' . $safeOrderNumber . '</strong></p>'
            . '<table border="1" cellpadding="8" cellspacing="0"><thead><tr><th>Product</th><th>Quantity</th><th>Price</th></tr></thead><tbody>';
        $textLines = ["Order number: $formattedOrderNumber"];
        while ($item = $items->fetch_assoc()) {
            $productName = (string)($item['product_name'] ?: 'Unavailable product');
            $quantity = (int)$item['quantity'];
            $price = (float)$item['price'];
            $body .= '<tr><td>' . htmlspecialchars($productName, ENT_QUOTES, 'UTF-8')
                . '</td><td>' . number_format($quantity) . '</td><td>₱' . number_format($price, 2) . '</td></tr>';
            $textLines[] = $productName . ' x ' . $quantity . ' - ₱' . number_format($price, 2);
        }
        $itemsStmt->close();
        $body .= '</tbody></table><p>Total: ₱' . number_format((float)$order['total_amount'], 2) . '</p>';
        $textLines[] = 'Total: ₱' . number_format((float)$order['total_amount'], 2);
        $mail->Body = $body;
        $mail->AltBody = implode("\n", $textLines);
        $mail->send();

        $log = $conn->prepare("INSERT INTO email_logs (order_id, email_type, recipient_email, subject)
            VALUES (?, 'order_confirmation', ?, ?)");
        if ($log) {
            $subject = $mail->Subject;
            $log->bind_param('iss', $order_id, $user_email, $subject);
            if (!$log->execute()) {
                error_log('Order confirmation email was sent but could not be logged: ' . $log->error);
            }
            $log->close();
        } else {
            error_log('Order confirmation email was sent but logging could not be prepared: ' . $conn->error);
        }

        return true;
    } catch (\Throwable $e) {
        error_log('Order confirmation email failed: ' . $e->getMessage());
        return false;
    }
}

function getGeminiResponse($message, $conversation_context = '', $extra_context = '') {
    global $ai_api_key;

    if (empty($ai_api_key) || !function_exists('curl_init')) {
        return null;
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
    $context_block = trim($conversation_context) !== ''
        ? "\n\nConversation history:\n" . $conversation_context
        : '';
    $extra_block = trim($extra_context) !== ''
        ? "\n\nVerified active inventory:\n" . $extra_context
        : '';
    $prompt = "You are PharmaCare Professional Pharmacy Assistant. Reply in friendly, natural Filipino-English with moderate detail. "
        . "Use only verified inventory facts for product names, prices, stock, and availability. "
        . "For availability questions, mention only products present in the verified inventory. "
        . "If no inventory is provided, say no matching product was found instead of inventing medicine names. "
        . "For general symptom questions, including difficulty sleeping or insomnia, do not reply with only a pharmacist or doctor referral. "
        . "Give a useful answer with a brief explanation, possible general causes, basic low-risk self-care, relevant warning signs, and one or two follow-up questions. "
        . "For difficulty sleeping, include practical sleep-hygiene steps and explain that medicine or supplements should not be started without pharmacist or doctor advice. "
        . "Use short headings or bullet points when they improve readability, and aim for about 2 to 5 short paragraphs or sections. "
        . "Keep the answer complete and finish every sentence before stopping; do not end mid-sentence or mid-list. "
        . "Do not diagnose or prescribe. Recommend a pharmacist or doctor when symptoms are severe, persistent, worsening, or require medicine-specific advice. "
        . "For emergency warning signs, advise urgent medical care immediately."
        . $context_block . $extra_block
        . "\n\nCurrent user message:\n" . $message;

    $payload = json_encode([
        'contents' => [[
            'parts' => [['text' => $prompt]]
        ]],
        'generationConfig' => [
            'temperature' => 0.2,
            'maxOutputTokens' => 900
        ]
    ]);

    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $ai_api_key],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('Wise AI Gemini request failed. HTTP status: ' . $status);
        return null;
    }

    $data = json_decode($response, true);
    $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    return is_string($reply) && trim($reply) !== '' ? trim($reply) : null;
}

function getWiseAiInventoryContext($conn, $text, $limit = 8) {
    $terms = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
    $stop_words = ['ano', 'ang', 'mga', 'may', 'ba', 'na', 'ng', 'sa', 'para', 'gamot', 'medicine', 'available', 'show', 'list'];
    $terms = array_values(array_filter($terms, function ($term) use ($stop_words) {
        return mb_strlen($term, 'UTF-8') >= 3 && !in_array($term, $stop_words, true);
    }));
    $aliases = ['ubo' => ['cough', 'expectorant'], 'sipon' => ['cold', 'decongestant'], 'lagnat' => ['fever', 'paracetamol'], 'allergy' => ['antihistamine']];
    foreach ($terms as $term) {
        if (isset($aliases[$term])) $terms = array_merge($terms, $aliases[$term]);
    }
    $terms = array_values(array_unique($terms));

    $sellableStockCondition = getCustomerSellableStockCondition();
    $sellableStockExpression = getCustomerSellableStockExpression();
    $sql = "SELECT product_name, category, description, COALESCE(price_piece, price, 0) AS unit_price, $sellableStockExpression AS quantity FROM products WHERE status = 'active' AND $sellableStockCondition AND COALESCE(prescription_required, 0) = 0 AND LOWER(TRIM(COALESCE(category, ''))) <> 'prescription medicine'";
    $params = [];
    $types = '';
    $conditions = [];
    foreach (array_slice($terms, 0, 8) as $term) {
        $conditions[] = '(LOWER(product_name) LIKE ? OR LOWER(category) LIKE ? OR LOWER(description) LIKE ?)';
        $pattern = '%' . $term . '%';
        array_push($params, $pattern, $pattern, $pattern);
        $types .= 'sss';
    }
    if ($conditions) $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
    $sql .= ' ORDER BY quantity DESC, unit_price ASC LIMIT ?';
    $params[] = $limit;
    $types .= 'i';
    $stmt = $conn->prepare($sql);
    if (!$stmt) return '';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $lines = [];
    while ($row = $result->fetch_assoc()) {
        $lines[] = '- ' . $row['product_name'] . '; category: ' . ($row['category'] ?? '')
            . '; price: PHP ' . number_format((float) $row['unit_price'], 2)
            . '; stock: ' . (int) $row['quantity'];
    }
    $stmt->close();
    return implode("\n", $lines);
}

function getWiseAiSearchProducts($conn, $text, $limit = 8) {
    $terms = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
    $stop_words = ['ano', 'ang', 'mga', 'may', 'ba', 'na', 'ng', 'sa', 'para', 'with', 'and', 'the', 'gamot', 'medicine', 'available', 'show', 'find', 'search', 'please', 'me', 'want'];
    $terms = array_values(array_filter($terms, function ($term) use ($stop_words) {
        return mb_strlen($term, 'UTF-8') >= 3 && !in_array($term, $stop_words, true);
    }));
    $aliases = [
        'ubo' => ['cough', 'expectorant'],
        'sipon' => ['cold', 'decongestant'],
        'lagnat' => ['fever', 'paracetamol'],
        'allergy' => ['antihistamine'],
    ];
    foreach ($terms as $term) {
        if (isset($aliases[$term])) {
            $terms = array_merge($terms, $aliases[$term]);
        }
    }
    $terms = array_values(array_unique($terms));
    if (empty($terms)) {
        return [];
    }

    $conditions = [];
    $params = [];
    $types = '';
    foreach (array_slice($terms, 0, 8) as $term) {
        $conditions[] = '(LOWER(product_name) LIKE ? OR LOWER(category) LIKE ? OR LOWER(description) LIKE ?)';
        $pattern = '%' . $term . '%';
        array_push($params, $pattern, $pattern, $pattern);
        $types .= 'sss';
    }
    $sellableStockCondition = getCustomerSellableStockCondition();
    $sellableStockExpression = getCustomerSellableStockExpression();
    $sql = 'SELECT id, product_name, image, category, COALESCE(price_piece, price, 0) AS unit_price, '
        . $sellableStockExpression . ' AS quantity '
        . 'FROM products WHERE status = \'active\' AND ' . $sellableStockCondition . ' '
        . 'AND COALESCE(prescription_required, 0) = 0 '
        . 'AND LOWER(TRIM(COALESCE(category, \'\'))) <> \'prescription medicine\' '
        . 'AND (' . implode(' OR ', $conditions) . ') '
        . 'ORDER BY quantity DESC, unit_price ASC LIMIT ?';
    $params[] = $limit;
    $types .= 'i';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    $stmt->close();
    return $products;
}

function getWiseAiBudgetAmount($text) {
    if (preg_match('/(?:\bbudget\b|\bmoney\b|\bamount\b|\bspend\b|\bcash\b|\bmax\b|\bup to\b|\b(?:php|pesos?|peso)\b|₱|\bP)\s*[^\d]*(\d+(?:\.\d{1,2})?)/i', $text, $matches)) {
        return (float) $matches[1];
    }

    return null;
}

function wiseAiIsAvailabilityQuestion($text) {
    return (bool) preg_match('/\b(?:available|availability|ano-ano|anong available|list|mayroon ba|meron ba|what.*available|show.*(?:gamot|medicine|product)|available na gamot|available medicine)\b/i', $text);
}

function wiseAiExplicitMedicineRequest($text) {
    return (bool) preg_match('/\b(?:gamot|medicine|meds?|otc|over the counter|ano ang pwede|ano pwede|what can i take|what medicine|anong gamot|available na gamot|available medicine)\b/i', $text);
}

function wiseAiIsHealthConcern($text) {
    return (bool) preg_match('/(?:\bubo\b|\bsipon\b|\bcough\b|\bcold\b|\blagnat\b|\bfever\b|\ballergy\b|\ballergies\b|\bheadache\b|\bsakit ng ulo\b|\bmasakit\b|\bpain\b|\bsakit ng tiyan\b|\bmasakit ang tiyan\b|\bstomach ache\b|\babdominal pain\b|\bpagsusuka\b|\bvomit\b|\bpagtatae\b|\bdiarrhea\b|\bsore throat\b|\btoothache\b|\bsakit ng ngipin\b|\bnamumulang mata\b|\bmapulang mata\b|\bred eye\b|\beye redness\b|\bhirap matulog\b|\bdi makatulog\b|\bmakatulog\b|\bmahirap matulog\b|\binsomnia\b|\bsleep problem\b|\bsleeping problem\b)/i', $text);
}

function wiseAiHasSafetyContext($text) {
    return (bool) preg_match('/\b(?:\d{1,3}\s*(?:taon|years?|yrs?|yo)|edad|age)\b/i', $text)
        && (bool) preg_match('/\b(?:araw|days?|oras?|hours?|ngayon|today|simula|since)\b/i', $text);
}

function wiseAiIsClearProductRequest($text) {
    return wiseAiIsAvailabilityQuestion($text)
        || wiseAiExplicitMedicineRequest($text)
        || (bool) preg_match('/\b(?:presyo|price|magkano|how much|bumili|buy|order|umorder|product)\b/i', $text);
}

function getWiseAiProductsMentionedInReply($conn, $reply, $limit = 8) {
    $sellableStockCondition = getCustomerSellableStockCondition();
    $sellableStockExpression = getCustomerSellableStockExpression();
    $result = $conn->query("SELECT id, product_name, image, category, COALESCE(price_piece, price, 0) AS unit_price, $sellableStockExpression AS quantity FROM products WHERE status = 'active' AND $sellableStockCondition AND COALESCE(prescription_required, 0) = 0 AND LOWER(TRIM(COALESCE(category, ''))) <> 'prescription medicine' ORDER BY quantity DESC, unit_price ASC LIMIT 100");
    if (!$result) {
        return [];
    }

    $normalized_reply = mb_strtolower((string) $reply, 'UTF-8');
    $normalized_reply = preg_replace('/\s+/u', ' ', $normalized_reply);
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $product_name = trim((string) ($row['product_name'] ?? ''));
        $normalized_name = preg_replace('/\s+/u', ' ', mb_strtolower($product_name, 'UTF-8'));
        if ($normalized_name !== '' && strpos($normalized_reply, $normalized_name) !== false) {
            $products[] = $row;
            if (count($products) >= $limit) {
                break;
            }
        }
    }

    return $products;
}

function removeWiseAiProductListingText($reply, $products) {
    if (empty($products)) {
        return $reply;
    }

    $product_names = array_values(array_filter(array_map(function ($product) {
        return trim((string) ($product['product_name'] ?? ''));
    }, $products)));
    if (empty($product_names)) {
        return $reply;
    }

    $lines = preg_split('/\r\n|\r|\n/', (string) $reply);
    $filtered_lines = [];
    foreach ($lines as $line) {
        $contains_product = false;
        foreach ($product_names as $product_name) {
            if (stripos($line, $product_name) !== false) {
                $contains_product = true;
                break;
            }
        }
        if (!$contains_product) {
            $filtered_lines[] = $line;
        }
    }

    return trim(implode("\n", $filtered_lines));
}

function getWiseAiAlternatives($conn, $searched_product) {
    $search_pattern = '%' . trim($searched_product) . '%';
    $sellableStockCondition = getCustomerSellableStockCondition();
    $sellableStockExpression = getCustomerSellableStockExpression();
    $stmt = $conn->prepare("SELECT id, product_name, image, category, COALESCE(price_piece, price, 0) AS unit_price, $sellableStockExpression AS quantity FROM products WHERE product_name NOT LIKE ? AND status = 'active' AND $sellableStockCondition AND COALESCE(prescription_required, 0) = 0 AND LOWER(TRIM(COALESCE(category, ''))) <> 'prescription medicine' ORDER BY quantity DESC, unit_price ASC LIMIT 3");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $alternatives = [];
    while ($row = $result->fetch_assoc()) {
        $alternatives[] = $row;
    }
    $stmt->close();

    return $alternatives;
}

function getWiseAiBudgetSearchTerms($message) {
    $prompt = "Convert the customer's natural-language medicine search into up to 8 short product-catalog search terms. "
        . "Translate simple Filipino or English symptom wording into neutral catalog terms only when the customer clearly states it. "
        . "Keep any medicine name or active ingredient from the request. Do not recommend a medicine, diagnose, prescribe, or add treatment claims. "
        . "Return only a JSON array of strings, with no markdown or explanation.\nCustomer search: " . trim($message);
    $response = getGeminiResponse($prompt);
    if (!is_string($response) || trim($response) === '') {
        return [];
    }

    $json = trim($response);
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $json, $matches)) {
        $json = trim($matches[1]);
    }
    if (!preg_match('/\[[\s\S]*?\]/', $json, $matches)) {
        return [];
    }
    $json = $matches[0];
    $terms = json_decode($json, true);
    if (!is_array($terms)) {
        return [];
    }

    $normalized_terms = [];
    foreach (array_slice($terms, 0, 8) as $term) {
        if (!is_string($term)) {
            continue;
        }
        $term = mb_strtolower(trim($term), 'UTF-8');
        if ($term === '' || mb_strlen($term, 'UTF-8') > 60) {
            continue;
        }
        $normalized_terms[] = $term;
    }

    return array_values(array_unique($normalized_terms));
}

function getWiseAiBudgetBundle($conn, $message, $budget) {
    $normalized = mb_strtolower($message, 'UTF-8');
    $terms = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    $ignored_terms = ['budget', 'money', 'amount', 'spend', 'cash', 'max', 'up', 'to', 'php', 'peso', 'pesos', 'gamot', 'medicine', 'medicines', 'meds', 'otc', 'please', 'help', 'me', 'show', 'give', 'buy', 'bibilhin', 'kailangan', 'ano', 'anong', 'available', 'na', 'ng', 'ang', 'sa', 'para', 'at', 'and', 'my', 'ko', 'masakit', 'sakit', 'sumasakit', 'ulo', 'head', 'hurts'];
    $terms = array_values(array_filter($terms, function ($term) use ($ignored_terms) {
        return mb_strlen($term, 'UTF-8') >= 3 && !in_array($term, $ignored_terms, true) && !is_numeric($term);
    }));

    $intent_terms = [];
    $has_cough_intent = preg_match('/\b(?:ubo|cough|phlegm|plema)\b/u', $normalized) === 1;
    if ($has_cough_intent) {
        $intent_terms = ['cough', 'ubo', 'expectorant', 'guaifenesin', 'dextromethorphan', 'ambroxol', 'carbocisteine'];
        $terms = $intent_terms;
    } elseif (preg_match('/(?:masakit|sakit|sumasakit).{0,16}ulo|ulo.{0,16}(?:masakit|sakit)|headache|head.{0,12}pain|pain.{0,12}head|head hurts|my head hurts/u', $normalized)) {
        $intent_terms = ['headache', 'head pain', 'pain', 'analgesic', 'sakit ng ulo'];
    } elseif (preg_match('/(?:masakit|sakit|sumasakit).{0,16}(?:ngipin|ngipin ko)|toothache|tooth pain/u', $normalized)) {
        $intent_terms = ['toothache', 'tooth pain', 'dental pain', 'pain', 'analgesic', 'sakit ng ngipin'];
    } elseif (preg_match('/(?:masakit|sakit|sumasakit).{0,16}(?:katawan|kasukasuan)|body pain|joint pain/u', $normalized)) {
        $intent_terms = ['body pain', 'joint pain', 'pain', 'analgesic', 'sakit ng katawan'];
    }
    $ai_search_terms = $has_cough_intent ? [] : getWiseAiBudgetSearchTerms($message);
    $terms = array_values(array_unique(array_merge($terms, $intent_terms, $ai_search_terms)));

    if (empty($terms)) {
        return ['items' => [], 'total' => 0, 'remaining' => $budget, 'budget' => $budget, 'ai_reply' => '', 'error' => 'no_search_terms'];
    }

    $aliases = [
        'ubo' => ['cough', 'expectorant'],
        'sipon' => ['cold', 'decongestant'],
        'lagnat' => ['fever', 'paracetamol'],
        'allergy' => ['antihistamine'],
        'vitamina' => ['vitamin', 'supplement']
    ];
    foreach ($terms as $term) {
        if (isset($aliases[$term])) {
            $terms = array_merge($terms, $aliases[$term]);
        }
    }
    $terms = array_values(array_unique($terms));

    $sellableStockCondition = getCustomerSellableStockCondition();
    $sellableStockExpression = getCustomerSellableStockExpression();
    $result = $conn->query(
        "SELECT id, product_name, image, category, description, ingredients, benefits, "
        . "usage_instructions, product_form, price, price_piece, price_box, pieces_per_box, "
        . "$sellableStockExpression AS quantity "
        . "FROM products WHERE status = 'active' AND $sellableStockCondition "
        . "AND COALESCE(prescription_required, 0) = 0 "
        . "AND LOWER(TRIM(COALESCE(category, ''))) <> 'prescription medicine' "
        . "ORDER BY COALESCE(NULLIF(price_piece, 0), price, 0) ASC, quantity DESC LIMIT 200"
    );
    if (!$result) {
        error_log('Wise AI budget search inventory query failed: ' . $conn->error);
        return ['items' => [], 'total' => 0, 'remaining' => $budget, 'budget' => $budget, 'ai_reply' => '', 'error' => 'inventory_unavailable'];
    }

    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        $piece_price = (float) (($row['price_piece'] ?? 0) > 0 ? $row['price_piece'] : ($row['price'] ?? 0));
        $stock_pieces = max(0, (int) ($row['quantity'] ?? 0));
        if ($piece_price <= 0 || $stock_pieces <= 0) {
            continue;
        }
        $search_text = mb_strtolower(implode(' ', [
            $row['product_name'] ?? '',
            $row['category'] ?? '',
            $row['description'] ?? '',
            $row['ingredients'] ?? '',
            $row['benefits'] ?? '',
            $row['usage_instructions'] ?? '',
            $row['product_form'] ?? ''
        ]), 'UTF-8');
        $score = 0;
        $matched_terms = [];
        foreach ($terms as $term) {
            if (strpos($search_text, $term) !== false) {
                $score += in_array($term, $intent_terms, true) ? 2 : 1;
                $matched_terms[] = $term;
            }
        }
        if ($score > 0) {
            $pieces_per_box = max(1, (int) ($row['pieces_per_box'] ?? 1));
            $box_price = round($piece_price * $pieces_per_box * 0.98, 2);
            $row['price_piece'] = $piece_price;
            $row['price_box'] = $box_price;
            $options = [[
                'unit' => 'piece',
                'unit_price' => $piece_price,
                'pieces_per_unit' => 1,
                'stock_units' => $stock_pieces
            ]];
            if ($stock_pieces >= $pieces_per_box && $box_price > 0) {
                $options[] = [
                    'unit' => 'box',
                    'unit_price' => $box_price,
                    'pieces_per_unit' => $pieces_per_box,
                    'stock_units' => intdiv($stock_pieces, $pieces_per_box)
                ];
            }
            $options = array_values(array_filter($options, function ($option) use ($budget) {
                return $option['unit_price'] > 0 && $option['unit_price'] <= $budget && $option['stock_units'] > 0;
            }));
            if (empty($options)) {
                continue;
            }
            usort($options, function ($first, $second) {
                $unit_price_comparison = ($first['unit_price'] / $first['pieces_per_unit']) <=> ($second['unit_price'] / $second['pieces_per_unit']);
                if ($unit_price_comparison !== 0) {
                    return $unit_price_comparison;
                }
                return ($first['unit'] === 'piece' ? 0 : 1) <=> ($second['unit'] === 'piece' ? 0 : 1);
            });
            $best_option = $options[0];
            $row['unit_price'] = $best_option['unit_price'];
            $row['recommended_unit'] = $best_option['unit'];
            $row['pieces_per_unit'] = $best_option['pieces_per_unit'];
            $row['price_per_piece'] = $best_option['unit_price'] / $best_option['pieces_per_unit'];
            $row['max_quantity'] = min($best_option['stock_units'], (int) floor($budget / $best_option['unit_price']));
            $row['stock_pieces'] = $stock_pieces;
            $row['pieces_per_box'] = $pieces_per_box;
            $row['box_savings'] = max(0, ($piece_price * $pieces_per_box) - $box_price);
            $row['_score'] = $score;
            $row['match_score'] = $score;
            $row['_matched_terms'] = array_values(array_unique($matched_terms));
            $candidates[] = $row;
        }
    }

    usort($candidates, function ($first, $second) {
        return ($second['_score'] <=> $first['_score'])
            ?: ((float) $first['price_per_piece'] <=> (float) $second['price_per_piece'])
            ?: ((float) $first['unit_price'] <=> (float) $second['unit_price']);
    });

    $items = array_slice($candidates, 0, 3);
    $total = 0;
    $best_score = !empty($items) ? max(array_map(function ($item) {
        return (int) ($item['_score'] ?? 0);
    }, $items)) : 0;
    $recommended_index = null;
    foreach ($items as $index => $item) {
        if ((int) ($item['_score'] ?? 0) === $best_score) {
            $recommended_index = $index;
            break;
        }
    }
    foreach ($items as &$item) {
        $item_index = array_search($item['id'], array_column($items, 'id'), true);
        unset($item['_score']);
        $item['is_best_value'] = $item_index === $recommended_index;
        $total += (float) $item['unit_price'];
    }
    unset($item);

    $inventory_lines = [];
    $rule_explanation_lines = [];
    $recommended_product_name = '';
    foreach ($items as $item) {
        if (!empty($item['is_best_value'])) {
            $recommended_product_name = $item['product_name'];
            break;
        }
    }
    if ($recommended_product_name !== '') {
        $rule_explanation_lines[] = 'Verdict: ' . $recommended_product_name
            . ' ang napili dahil ito ang may pinakamataas na catalog match score; kapag tabla ang match, mas mababa ang effective price nito kada piraso sa mga kapantay na match. Pasok din ito sa budget at available ang stock.';
    }

    foreach ($items as $item) {
        $catalog_details = trim(implode('; ', array_filter([
            !empty($item['ingredients']) ? 'ingredients ' . $item['ingredients'] : '',
            !empty($item['product_form']) ? 'form ' . $item['product_form'] : '',
            !empty($item['benefits']) ? 'benefits ' . $item['benefits'] : '',
            !empty($item['usage_instructions']) ? 'listed use ' . $item['usage_instructions'] : ''
        ])));
        $inventory_lines[] = '- ' . $item['product_name'] . '; recommended purchase: ' . $item['recommended_unit']
            . '; purchase price PHP ' . number_format((float) $item['unit_price'], 2)
            . '; effective price per piece PHP ' . number_format((float) $item['price_per_piece'], 2)
            . '; single-piece price PHP ' . number_format((float) $item['price_piece'], 2)
            . '; box price PHP ' . number_format((float) ($item['price_box'] > 0 ? $item['price_box'] : $item['price_piece'] * $item['pieces_per_box']), 2)
            . '; pieces per box ' . (int) $item['pieces_per_box']
            . '; stock pieces ' . (int) $item['stock_pieces']
            . '; max affordable purchase units ' . (int) $item['max_quantity']
            . '; catalog match score ' . (int) $item['match_score']
            . '; matched catalog terms ' . implode(', ', $item['_matched_terms'] ?? [])
            . '; recommendation ' . (!empty($item['is_best_value']) ? 'selected top match' : 'alternative match')
            . '; box savings versus same number of pieces PHP ' . number_format((float) $item['box_savings'], 2)
            . ($catalog_details !== '' ? '; ' . $catalog_details : '');
        $matched_terms_text = !empty($item['_matched_terms'])
            ? implode(', ', $item['_matched_terms'])
            : 'catalog details related to the search';
        $rule_explanation_lines[] = $item['product_name'] . ': Tumugma sa catalog terms na '
            . $matched_terms_text . '. ₱' . number_format((float) $item['unit_price'], 2)
            . ' kada ' . $item['recommended_unit'] . ' (₱'
            . number_format((float) $item['price_per_piece'], 2)
            . ' effective kada piraso); hanggang ' . (int) $item['max_quantity'] . ' '
            . ($item['recommended_unit'] === 'box' ? 'box(es)' : 'piraso')
            . ' ang pasok sa budget at stock.'
            . ((float) $item['box_savings'] > 0
                ? ' Mas mura ang kahon nang ₱' . number_format((float) $item['box_savings'], 2) . ' kumpara sa parehong bilang ng pirasong paisa-isa.'
                : ' Walang catalog box discount na mas mura kada piraso kaysa paisa-isang bili.')
            . (!empty($item['is_best_value'])
                ? ' Ito ang recommended na catalog match.'
                : ' Isa itong alternative; ikumpara ang presyo at catalog details.');
    }
    $ai_reply = '';
    if (!empty($items)) {
        $ai_reply = getGeminiResponse(
            "The customer requested medicine products within a budget. Based only on the verified catalog candidates below, "
            . "help the customer decide which listed option is more sulit for what they searched. Relevance comes first: favor the highest catalog match score, then compare effective price per piece among equally relevant matches. "
            . "Write a clear verdict naming the selected top recommendation and explain this relevance-and-price comparison, including whether a box discount makes it cheaper. "
            . "Then explain EACH listed product separately: what catalog terms/details match the request, its piece and box prices, effective price per piece, how many units fit the budget and stock, and whether its box price saves money versus buying the same pieces individually. "
            . "Use the exact product names from the catalog and do not omit alternatives. "
            . "Do not assess medical suitability, diagnose, prescribe, or claim a medicine treats a symptom unless the catalog explicitly lists that use. "
            . "Do not invent products, prices, stock, dosage, or medical claims. "
            . "This is a price/catalog verdict only, not a medical verdict. Do not call different products medically equivalent. Use friendly Filipino-English, separate the verdict and each product explanation with line breaks, and remind the customer to confirm medicine choice with a pharmacist.\n"
            . "Selected recommendation: " . $recommended_product_name . "\n"
            . "Customer request: " . $message . "\nBudget: PHP " . number_format($budget, 2),
            '',
            implode("\n", $inventory_lines)
        ) ?: '';
    }
    $rule_explanation_lines[] = 'Paalala: price at catalog match verdict ito, hindi medical advice. Kumpirmahin sa pharmacist kung angkop ang gamot.';

    return [
        'items' => $items,
        'total' => $total,
        'remaining' => max(0, $budget - $total),
        'budget' => $budget,
        'ai_reply' => $ai_reply,
        'rule_explanation' => implode("\n\n", $rule_explanation_lines),
        'error' => ''
    ];
}

function getWiseAiResponse($conn, $message, $conversation_context = '') {
    $text = trim($message);
    if ($text === '') {
        return "Kumusta! Pwede kitang tulungan sa available na gamot, presyo, at pagplano ng budget. Halimbawa: 'budget 500 paracetamol and vitamin c' o 'anong available na gamot'.";
    }

    $inventory_context = (wiseAiIsClearProductRequest($text) || wiseAiIsHealthConcern($text))
        ? getWiseAiInventoryContext($conn, $text)
        : '';
    $ai_reply = getGeminiResponse($text, $conversation_context, $inventory_context);
    if ($ai_reply !== null) {
        return $ai_reply;
    }

    $normalized = mb_strtolower($text, 'UTF-8');
    $budget = null;

    if (preg_match('/\b(?:budget|money|amount|spend|cash|max|up to)\b[^\d]*(\d+(?:\.\d{1,2})?)/i', $text, $matches)) {
        $budget = (float) $matches[1];
    } elseif (preg_match('/(?:\b(?:php|pesos?|₱)\b\s*|\b)(\d+(?:\.\d{1,2})?)(?=\s*(?:php|pesos?|peso|₱))/i', $text, $matches)) {
        $budget = (float) $matches[1];
    }

    $cleaned = preg_replace('/\b(?:budget|money|amount|spend|cash|max|up to|please|help|me|show|give|buy|bibilhin|kailangan|ano|anong|available|na|ng|ang|gamot|medicine|medicines|meds?)\b/i', ' ', $text);
    $cleaned = preg_replace('/[^\p{L}\p{N}\s,\/&+-]/u', ' ', $cleaned);
    $cleaned = preg_replace('/\s+/u', ' ', $cleaned);
    $parts = preg_split('/(?:,| and |\/| plus |\+ )/i', $cleaned);

    $medicines = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $medicines[] = $part;
        }
    }
    $medicines = array_values(array_unique($medicines));

    $isAvailableQuestion = strpos($normalized, 'available') !== false || strpos($normalized, 'anong') !== false || strpos($normalized, 'ano') !== false || strpos($normalized, 'gamot') !== false || strpos($normalized, 'medicine') !== false;

    if ($budget !== null && !empty($medicines)) {
        $lines = [];
        $lines[] = "Budget check: ₱" . number_format($budget, 2) . " para sa mga gamot na gusto mo.";
        $estimated_total = 0;
        $cheapest_items = [];

        foreach ($medicines as $medicine) {
            $search = "%$medicine%";
            $stmt = $conn->prepare("SELECT id, product_name, category, COALESCE(price_piece, price, 0) AS unit_price FROM products WHERE (LOWER(product_name) LIKE ? OR LOWER(description) LIKE ?) AND COALESCE(prescription_required, 0) = 0 AND LOWER(TRIM(COALESCE(category, ''))) <> 'prescription medicine' ORDER BY COALESCE(price_piece, price, 0) ASC LIMIT 3");
            if ($stmt) {
                $stmt->bind_param('ss', $search, $search);
                $stmt->execute();
                $result = $stmt->get_result();
                $best = null;
                while ($row = $result->fetch_assoc()) {
                    $best = $row;
                    break;
                }
                $stmt->close();

                if ($best) {
                    $price = (float) ($best['unit_price'] ?? 0);
                    $estimated_total += $price;
                    $cheapest_items[] = "• " . ucfirst($medicine) . " → " . htmlspecialchars($best['product_name']) . " — ₱" . number_format($price, 2);
                } else {
                    $cheapest_items[] = "• " . ucfirst($medicine) . " → Walang tumugma sa current inventory.";
                }
            }
        }

        $lines = array_merge($lines, $cheapest_items);
        $lines[] = "Estimated total: ₱" . number_format($estimated_total, 2);

        if ($estimated_total <= $budget) {
            $lines[] = "Kasya sa budget mo! Mas okay pa kung gusto mong dagdagan ang quantity o pumili ng ibang variant.";
        } else {
            $difference = $budget - $estimated_total;
            $lines[] = "Hindi kasya sa budget mo. Kailangan mo ng karagdagang ₱" . number_format(abs($difference), 2) . " o bawasan ang listahan.";
        }

        return implode("\n", $lines);
    }

    if ($budget !== null && empty($medicines)) {
        return "Nakakita ako ng budget na ₱" . number_format($budget, 2) . ", pero kailangan ko pa ng mga gamot na gusto mong bilhin. Halimbawa: 'budget 500 paracetamol and vitamin c'.";
    }

    if (!empty($medicines)) {
        $lines = [];
        $lines[] = "Narito ang pinakamurang available na option para sa mga gamot na binanggit mo:";

        foreach ($medicines as $medicine) {
            $search = "%$medicine%";
            $stmt = $conn->prepare("SELECT product_name, COALESCE(price_piece, price, 0) AS unit_price FROM products WHERE (LOWER(product_name) LIKE ? OR LOWER(description) LIKE ?) AND COALESCE(prescription_required, 0) = 0 AND LOWER(TRIM(COALESCE(category, ''))) <> 'prescription medicine' ORDER BY COALESCE(price_piece, price, 0) ASC LIMIT 3");
            if ($stmt) {
                $stmt->bind_param('ss', $search, $search);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();

                if ($row) {
                    $lines[] = "• " . ucfirst($medicine) . " → " . htmlspecialchars($row['product_name']) . " — ₱" . number_format((float) ($row['unit_price'] ?? 0), 2);
                } else {
                    $lines[] = "• " . ucfirst($medicine) . " → Walang match sa inventory.";
                }
            }
        }

        return implode("\n", $lines);
    }

    if ($isAvailableQuestion) {
        $result = $conn->query("SELECT product_name, COALESCE(price_piece, price, 0) AS unit_price FROM products WHERE COALESCE(prescription_required, 0) = 0 AND LOWER(TRIM(COALESCE(category, ''))) <> 'prescription medicine' ORDER BY COALESCE(price_piece, price, 0) ASC LIMIT 8");
        if ($result && $result->num_rows > 0) {
            $lines = ["Narito ang ilang available na gamot sa system:"];
            while ($row = $result->fetch_assoc()) {
                $lines[] = "• " . htmlspecialchars($row['product_name']) . " — ₱" . number_format((float) ($row['unit_price'] ?? 0), 2);
            }
            return implode("\n", $lines);
        }
    }

    return "Pwede mo akong tanungin ng ganito: 'anong available na gamot', 'budget 300 paracetamol and vitamin c', o 'san mas kakamura'.";
}
?>