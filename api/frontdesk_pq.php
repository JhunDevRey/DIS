<?php
/**
 * api/frontdesk_pq.php
 * Front Desk "PQ Forms" window (staff only): entries on the Cognito Forms
 * PQ form, split into waiting (not submitted yet) and submitted.
 *   GET            -> entries (cached ~60s)
 *   GET ?fresh=1   -> same, skipping the cache (Refresh button)
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cognito.php';
requireAdmin();

if (!cognitoEnabled()) {
    jsonResponse(false, 'Cognito Forms is not connected yet.', ['enabled' => false]);
}

try {
    $out = cognitoPqEntries(!empty($_GET['fresh']));
} catch (Throwable $e) {
    error_log('frontdesk_pq: ' . $e->getMessage());
    $msg = in_array($e->getCode(), [401, 403, 404], true)
        ? 'Cognito Forms refused the request. Check the API key and form name in config.php.'
        : 'Could not load PQ forms from Cognito Forms right now.';
    jsonResponse(false, $msg, ['enabled' => true]);
}

jsonResponse(true, '', ['enabled' => true, 'cognito_url' => cognitoEntriesUrl()] + $out);
