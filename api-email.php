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

define('IFS_INTERNAL', true);
require __DIR__ . '/_ratelimit.php';
require __DIR__ . '/_check_origin.php';

// ─── Configuration ───────────────────────────────────────────
$allowedHost = 'industrialfinishes.com';
$logFile     = __DIR__ . '/leads.csv';

// Rate limit: legitimate users hit the email gate ~once per browser
// (it never re-prompts after capture). 3 / hour leaves headroom for
// shared NAT / coffee-shop IPs while denying scripted leads.csv pollution.
$rateLimitDir = __DIR__ . '/.ratelimit';
$rateWindow   = 3600;
$rateMax      = 3;

$serverHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$isProd     = stripos($serverHost, $allowedHost) !== false;

// ─── Headers ────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ─── Origin / Referer Check (production only) ───────────────
if ($isProd) enforceOrigin($allowedHost);

// ─── Rate limit ─────────────────────────────────────────────
$clientIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
enforceRateLimit($clientIp, $rateLimitDir, $rateWindow, $rateMax, 'email');

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

// CSV formula-injection guard: any field starting with =, +, -, @, \t, \r is
// interpreted as a formula by Excel/Sheets/LibreOffice. Prefix '\'' to
// neutralize. fputcsv() handles commas/quotes/newlines, but does NOT do this.
$row = [
    date('c'),                                       // ISO timestamp — always digit-leading
    csvSafe($email),                                 // FILTER_VALIDATE_EMAIL allows +/=/- in local-part
    csvSafe($trigger),                               // whitelist permits leading '-'
    csvSafe(isset($_SERVER['REMOTE_ADDR'])     ? $_SERVER['REMOTE_ADDR'] : ''),
    csvSafe(isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : ''),
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


/**
 * Disarm CSV-formula injection. Excel/Sheets/LibreOffice parse cells whose
 * first character is =, +, -, @, \t, or \r as formulas. A leading single
 * quote tells the spreadsheet to treat the cell as literal text and is
 * itself rendered invisibly.
 */
function csvSafe($s) {
    $s = (string) $s;
    if ($s !== '' && preg_match("/^[=+\\-@\t\r]/", $s)) return "'" . $s;
    return $s;
}
