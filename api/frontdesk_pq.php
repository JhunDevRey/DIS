<?php
/**
 * api/frontdesk_pq.php
 * Front Desk "PQ Forms" window (staff only): PQ entries split into waiting
 * (not submitted yet) and submitted.
 *   GET                 -> entries from MySQL (pq_entries) — never calls Cognito
 *   POST action=resync  -> owners only, at most once an hour: re-checks
 *                          Waiting entries + looks for new ones via the
 *                          Cognito API (~1 request per Waiting entry + 4 per form)
 *
 * Entries arrive through api/cognito_pq_webhook.php (Cognito + Make).
 * Rollback: define('COGNITO_PQ_LIVE_API', true) in config.php brings back the
 * old live-API version (includes/cognito.php cognitoPqEntries()).
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pq_store.php';
requireAdmin();

if (!cognitoEnabled()) {
    jsonResponse(false, 'Cognito Forms is not connected yet.', ['enabled' => false]);
}

$canResync = adminIsOwner();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resync') {
    if (!$canResync) {
        jsonResponse(false, 'Only owner accounts can resync PQ forms.');
    }
    if (pqUseLiveApi()) {
        jsonResponse(false, 'Resync is not needed while the live Cognito mode is on.');
    }
    try {
        $admin = currentAdmin();
        $stats = pqResyncLimited((string)($admin['admin_name'] ?? 'Staff'));
    } catch (RuntimeException $e) {
        jsonResponse(false, $e->getMessage());
    } catch (Throwable $e) {
        error_log('frontdesk_pq resync: ' . $e->getMessage());
        jsonResponse(false, 'Resync failed. Please try again later.');
    }
    auditLog('pq_resync', null, $stats['api_calls'] . ' Cognito API call(s), ' . $stats['inserted'] . ' new, ' . $stats['updated'] . ' updated, ' . $stats['deleted'] . ' removed');
    $msg = 'Resynced with Cognito (' . $stats['api_calls'] . ' API calls): '
        . $stats['inserted'] . ' new, ' . $stats['updated'] . ' checked, ' . $stats['deleted'] . ' removed.'
        . ($stats['unanswered'] ? ' Cognito was busy for ' . $stats['unanswered'] . ' entr' . ($stats['unanswered'] === 1 ? 'y' : 'ies') . '.' : '');
    jsonResponse(true, $msg, ['stats' => $stats]);
}

try {
    if (pqUseLiveApi()) {
        $out = cognitoPqEntries(!empty($_GET['fresh']));
        $out['source'] = 'live';
    } else {
        $out = pqEntriesFromDb();
        $out['source'] = 'db';
    }
} catch (Throwable $e) {
    error_log('frontdesk_pq: ' . $e->getMessage());
    $msg = in_array($e->getCode(), [401, 403, 404], true)
        ? 'Cognito Forms refused the request. Check the API key and form name in config.php.'
        : 'Could not load PQ forms right now.';
    jsonResponse(false, $msg, ['enabled' => true]);
}

jsonResponse(true, '', ['enabled' => true, 'cognito_url' => cognitoEntriesUrl(), 'can_resync' => $canResync && !pqUseLiveApi()] + $out);
