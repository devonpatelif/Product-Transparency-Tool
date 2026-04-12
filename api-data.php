<?php
/**
 * api-data.php — Proxy for IVData.json
 *
 * Serves the product catalog JSON only when requested from the actual site.
 * Direct access via curl/browser URL bar from other origins is blocked.
 * The raw IVData.json file is blocked by .htaccess so this is the only way to get the data.
 */

// ─── Configuration ───────────────────────────────────────────
$jsonFile     = __DIR__ . '/IVData.json';
$allowedHost  = 'industrialfinishes.com';  // your domain (no https://)

// ─── CORS / Headers ─────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ─── Origin / Referer Check ─────────────────────────────────
// Block requests that don't come from your site.
// This stops direct curl / Postman / cross-origin scraping.
// It's not bulletproof (headers can be spoofed) but raises the bar significantly.

$origin  = isset($_SERVER['HTTP_ORIGIN'])  ? $_SERVER['HTTP_ORIGIN']  : '';
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

$originAllowed  = !empty($origin)  && stripos($origin, $allowedHost) !== false;
$refererAllowed = !empty($referer) && stripos($referer, $allowedHost) !== false;

// Allow if either origin or referer matches, OR if it's a same-origin request (no origin header sent)
$sameOrigin = empty($origin) && !empty($referer) && stripos($referer, $allowedHost) !== false;

if (!$originAllowed && !$refererAllowed && !$sameOrigin) {
    // If BOTH are empty, it's likely a direct URL hit (curl, browser address bar)
    // If they're present but don't match, it's a cross-origin request
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

// ─── Serve the JSON ─────────────────────────────────────────
if (!file_exists($jsonFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Data file not found.']);
    exit;
}

// Read and pass through the JSON file
$data = file_get_contents($jsonFile);

if ($data === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read data.']);
    exit;
}

echo $data;
