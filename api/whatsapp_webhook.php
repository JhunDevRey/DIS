<?php
/**
 * api/whatsapp_webhook.php
 * The URL 360dialog calls for WhatsApp events (incoming messages, delivery
 * receipts, office-phone echoes, history import) — same JSON as Meta's
 * Cloud API webhooks. No login here: every POST must carry the secret
 * X-DIS-Webhook-Token header (WA_WEBHOOK_TOKEN, registered with 360dialog
 * by cron/wa_set_webhook.php); anything else is rejected before parsing.
 *
 *   POST (JSON + X-DIS-Webhook-Token)  -> see waProcessWebhook()
 *
 * 360dialog wants a 200 within 5 seconds and retries otherwise, so the
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$token = defined('WA_WEBHOOK_TOKEN') ? (string)WA_WEBHOOK_TOKEN : '';
$given = (string)($_SERVER['HTTP_X_DIS_WEBHOOK_TOKEN'] ?? '');
if (strlen($token) < 32 || !hash_equals($token, $given)) {
    http_response_code(401);
    exit;
}

$raw = file_get_contents('php://input');

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

$db = getDb();
$db->prepare('INSERT INTO whatsapp_webhook_log (payload) VALUES (?)')->execute([$raw]);
$logId = (int)$db->lastInsertId();

// Answer 360dialog now; keep working after the connection closes.
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
