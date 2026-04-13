<?php
/**
 * api-analyze.php — Claude Vision proxy for invoice/quote scanning
 *
 * Receives an image upload, sends it to the Claude Vision API,
 * and returns structured JSON with extracted line items.
 * Same origin-validation pattern as api-data.php.
 */

// ─── Load local config if present (gitignored) ─────────────
$localConf = __DIR__ . '/config.local.php';
if (file_exists($localConf)) require_once $localConf;

// ─── Configuration ───────────────────────────────────────────
$allowedHost = 'industrialfinishes.com';
$apiKey      = getenv('ANTHROPIC_API_KEY');
$model       = 'claude-sonnet-4-6-20250514';
$maxTokens   = 4096;
$maxFileSize = 10 * 1024 * 1024; // 10 MB

// ─── CORS / Headers ─────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ─── Origin / Referer Check ─────────────────────────────────
$origin  = isset($_SERVER['HTTP_ORIGIN'])  ? $_SERVER['HTTP_ORIGIN']  : '';
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

$originAllowed  = !empty($origin)  && stripos($origin, $allowedHost) !== false;
$refererAllowed = !empty($referer) && stripos($referer, $allowedHost) !== false;
$sameOrigin     = empty($origin) && !empty($referer) && stripos($referer, $allowedHost) !== false;

if (!$originAllowed && !$refererAllowed && !$sameOrigin) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

// ─── Validate Request ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}

if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'API key not configured.']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No image uploaded or upload error.']);
    exit;
}

$file = $_FILES['image'];

if ($file['size'] > $maxFileSize) {
    http_response_code(400);
    echo json_encode(['error' => 'Image too large. Maximum 10 MB.']);
    exit;
}

// ─── Read & Encode Image ────────────────────────────────────
$allowedTypes = [
    'image/jpeg' => 'image/jpeg',
    'image/png'  => 'image/png',
    'image/gif'  => 'image/gif',
    'image/webp' => 'image/webp',
];

$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowedTypes[$mimeType])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported image type. Use JPEG, PNG, GIF, or WebP.']);
    exit;
}

$imageData   = file_get_contents($file['tmp_name']);
$base64Image = base64_encode($imageData);

// ─── Build Claude API Request ───────────────────────────────
$prompt = 'You are analyzing a supplier pricing document (invoice, quote, or price list) for industrial paint and coatings products. Extract every line item you can find.

For each item, extract:
- partNumber: The part/item/SKU number (look for columns labeled Part #, Item #, SKU, Product Code, etc.)
- description: Product name/description
- quantity: Number of units (default 1 if not shown)
- unitPrice: Price per unit the customer pays (numeric, no $ sign)
- discountPercent: Discount percentage if shown (numeric, no % sign; 0 if not listed)

Return ONLY a JSON array with no markdown formatting, no code fences, no other text:
[{"partNumber":"...","description":"...","quantity":1,"unitPrice":0.00,"discountPercent":0}]

If you cannot find any line items, return an empty array: []';

$payload = [
    'model'      => $model,
    'max_tokens' => $maxTokens,
    'messages'   => [
        [
            'role'    => 'user',
            'content' => [
                [
                    'type'   => 'image',
                    'source' => [
                        'type'         => 'base64',
                        'media_type'   => $mimeType,
                        'data'         => $base64Image,
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ],
        ],
    ],
];

// ─── Call Claude API ────────────────────────────────────────
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to reach AI service.']);
    exit;
}

if ($httpCode !== 200) {
    http_response_code(502);
    $err = json_decode($response, true);
    $msg = isset($err['error']['message']) ? $err['error']['message'] : 'AI service error.';
    echo json_encode(['error' => $msg]);
    exit;
}

// ─── Parse Claude Response ──────────────────────────────────
$result = json_decode($response, true);

if (!isset($result['content'][0]['text'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Unexpected AI response format.']);
    exit;
}

$text = trim($result['content'][0]['text']);

// Strip markdown code fences if present
$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
$text = preg_replace('/\s*```$/', '', $text);
$text = trim($text);

$items = json_decode($text, true);

if (!is_array($items)) {
    http_response_code(502);
    echo json_encode(['error' => 'AI returned invalid data. Please try again with a clearer image.']);
    exit;
}

// Sanitize output
$clean = [];
foreach ($items as $item) {
    $clean[] = [
        'partNumber'      => isset($item['partNumber']) ? (string) $item['partNumber'] : '',
        'description'     => isset($item['description']) ? (string) $item['description'] : '',
        'quantity'        => isset($item['quantity']) ? max(1, (int) $item['quantity']) : 1,
        'unitPrice'       => isset($item['unitPrice']) ? round((float) $item['unitPrice'], 2) : 0,
        'discountPercent' => isset($item['discountPercent']) ? min(99, max(0, (int) $item['discountPercent'])) : 0,
    ];
}

echo json_encode(['items' => $clean]);
