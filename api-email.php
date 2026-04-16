<?php
/**
 * api-email.php — Email lead capture endpoint
 *
 * Receives an email (+ trigger context) from the email gate modal,
 * appends to leads.csv. Same origin-validation pattern as api-data.php.
 */

// ─── Load local config if present (gitignored) ─────────────
$localConf = __DIR__ . '/config.local.php';
if (file_exists($localConf)) require_once $localConf;

// ─── Configuration ───────────────────────────────────────────
$allowedHost = 'industrialfinishes.com';
$logFile     = __DIR__ . '/leads.csv';

$serverHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$isProd     = stripos($serverHost, $allowedHost) !== false;

// ─── Headers ────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ─── Origin / Referer Check (production only) ───────────────
if ($isProd) {
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
}

// ─── Validate Request ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}

$email   = isset($_POST['email'])   ? trim($_POST['email'])   : '';
$trigger = isset($_POST['trigger']) ? trim($_POST['trigger']) : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email.']);
    exit;
}

// Length caps + whitelist trigger to avoid log injection
$email   = substr($email, 0, 254);
$trigger = preg_replace('/[^a-z0-9_-]/i', '', substr($trigger, 0, 32));

$row = [
    date('c'),
    $email,
    $trigger,
    isset($_SERVER['REMOTE_ADDR'])     ? $_SERVER['REMOTE_ADDR'] : '',
    isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
];

$fp = @fopen($logFile, 'a');
if ($fp) {
    if (flock($fp, LOCK_EX)) {
        if (ftell($fp) === 0) {
            fputcsv($fp, ['timestamp', 'email', 'trigger', 'ip', 'user_agent']);
        }
        fputcsv($fp, $row);
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save.']);
    exit;
}

echo json_encode(['ok' => true]);
