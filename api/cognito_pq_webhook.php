<?php
/**
 * api/cognito_pq_webhook.php
 * Receives PQ entries so the Front Desk card never has to ask Cognito.
 * No login here: every call must carry COGNITO_WEBHOOK_TOKEN (config.php,
 * 32+ random characters) as ?token=… or an X-DIS-Webhook-Token header.
 *
 * Callers (one URL per form, see docs/COGNITO_PQ_WEBHOOK.md):
 *   Cognito "Post JSON Data to a Website", per PQ form:
 *     …/api/cognito_pq_webhook.php?form=General&event=submit&token=…
 *     …&event=update  /  …&event=delete
 *     Body = the entry JSON (same shape as the API's entry).
 *   Make "PQ BOT - Sender", right after "Create Entry" (Cognito sends
 *   nothing for entries created through its API):
 *     …?form={{whichPq}}&event=create&token=…
 *     Body = {"id": "...", "number": n}  — the server then fetches that one
 *     entry from the API (1 request) because Make's copy has no dates.
 *
 * Replies 200 "OK" when stored (or nothing to do), 401 bad token, 400 bad
 * input, 500 on our error (so the sender retries). Errors go to
 * UPLOAD_DIR/logs/cognito_pq_webhook.log — one line per call, no client details.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pq_store.php';

function pqHookReply(int $code, string $text = ''): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $text !== '' ? $text : ($code === 200 ? 'OK' : 'Error');
    exit;
}

$secret = defined('COGNITO_WEBHOOK_TOKEN') ? (string)COGNITO_WEBHOOK_TOKEN : '';
if (!cognitoEnabled() || strlen($secret) < 32) {
    pqHookReply(404, 'Not found');
}
$given = (string)($_SERVER['HTTP_X_DIS_WEBHOOK_TOKEN'] ?? ($_GET['token'] ?? ''));
if (!hash_equals($secret, $given)) {
    pqLog('401 bad token from ' . clientIp());
    pqHookReply(401, 'Unauthorized');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pqHookReply(405, 'POST only');
}

$event = strtolower(trim((string)($_GET['event'] ?? 'submit')));
if (!in_array($event, ['submit', 'update', 'delete', 'create'], true)) {
    pqLog("400 unknown event '$event'");
    pqHookReply(400, 'Unknown event');
}

$raw = (string)file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024);
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    pqLog("400 $event: body is not JSON (" . strlen($raw) . ' bytes)');
    pqHookReply(400, 'Body must be JSON');
}

// Which form: ?form=… (preferred; an unknown name is rejected, never
// guessed), else the payload's own Form block.
$form = isset($_GET['form']) && trim((string)$_GET['form']) !== ''
    ? pqFormKey($_GET['form'])
    : (pqFormKey($payload['Form']['InternalName'] ?? null) ?? pqFormKey($payload['form'] ?? null));
if ($form === null) {
    pqLog("400 $event: unknown form '" . mb_substr((string)($_GET['form'] ?? ''), 0, 40) . "'");
    pqHookReply(400, 'Unknown form');
}

$number = pqEntryNumber($payload);
if ($number === null) {
    pqLog("400 $event $form: no entry number in payload");
    pqHookReply(400, 'No entry number');
}

try {
    if ($event === 'delete') {
        $hit = pqSoftDelete($form, $number);
        pqLog("200 delete $form #$number " . ($hit ? 'soft-deleted' : 'not stored (nothing to do)'));
        pqHookReply(200);
    }

    $result = pqUpsert($form, $payload, $event === 'create' ? 'make' : 'webhook');
    if ($result === 'skipped') {
        // Payload without the entry's dates/status (e.g. Make's "Create Entry"
        // output): fetch this one entry from the API — 1 request.
        $r = pqFetch($form, [$number], false);
        if (isset($r['found'][$number])) {
            $result = pqUpsert($form, $r['found'][$number], $event === 'create' ? 'make' : 'webhook', true) . ' (fetched, 1 API call)';
        } elseif (in_array($number, $r['missing'], true)) {
            $result = 'not found in Cognito (1 API call)';
        } else {
            throw new RuntimeException('Cognito did not answer for the entry (busy?)');
        }
    }
    pqLog("200 $event $form #$number $result");
    pqHookReply(200);
} catch (Throwable $e) {
    pqLog("500 $event $form #$number " . mb_substr($e->getMessage(), 0, 300));
    error_log('cognito_pq_webhook: ' . $e->getMessage());
    pqHookReply(500, 'Could not store the entry');
}
