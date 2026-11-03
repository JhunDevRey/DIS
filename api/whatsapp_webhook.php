<?php
/**
 * api/whatsapp_webhook.php
 * The URL Meta calls for WhatsApp events (incoming messages, delivery
 * receipts, office-phone echoes, history import). No login here — every
 * POST is instead checked against Meta's signature (WA_APP_SECRET), and
 * anything unsigned or mis-signed is rejected before it's even parsed.
 *
 *   GET  hub.mode=subscribe&hub.verify_token=…&hub.challenge=…  -> Meta's one-time URL check
 *   POST (signed JSON)                                           -> see waProcessWebhook()
 *
 * Meta wants a 200 within a few seconds and retries otherwise, so the
 * response is sent first and the work (media downloads etc.) happens
 * after. Retries are harmless anyway: wa_message_id is unique.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!waEnabled()) {
    http_response_code(404);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify = defined('WA_VERIFY_TOKEN') ? WA_VERIFY_TOKEN : '';
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && $verify !== ''
        && hash_equals($verify, (string)($_GET['hub_verify_token'] ?? ''))) {
        header('Content-Type: text/plain');
        echo (string)($_GET['hub_challenge'] ?? '');
        exit;
    }
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input');
$secret = defined('WA_APP_SECRET') ? WA_APP_SECRET : '';
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if ($secret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $signature)) {
    http_response_code(401);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

$db = getDb();
$db->prepare('INSERT INTO whatsapp_webhook_log (payload) VALUES (?)')->execute([$raw]);
$logId = (int)$db->lastInsertId();

// Answer Meta now; keep working after the connection closes.
ignore_user_abort(true);
set_time_limit(300);
http_response_code(200);
header('Content-Type: text/plain');
header('Content-Length: 2');
header('Connection: close');
echo 'OK';
if (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} elseif (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    flush();
}

try {
    waProcessWebhook($payload);
} catch (Throwable $e) {
    error_log('whatsapp_webhook #' . $logId . ': ' . $e->getMessage());
    $db->prepare('UPDATE whatsapp_webhook_log SET error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 500), $logId]);
}

// Keep the log to ~30 days (checked on roughly 1 in 50 calls).
if (random_int(1, 50) === 1) {
    $db->exec('DELETE FROM whatsapp_webhook_log WHERE received_at < NOW() - INTERVAL 30 DAY');
}
