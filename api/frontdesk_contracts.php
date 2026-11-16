<?php
/**
 * api/frontdesk_contracts.php
 * Front Desk "Contracts" window (staff only): open contracts from the
 * Notion 🖊️ Contracts database, grouped by what they're waiting on.
 *   GET            -> open contracts (cached ~60s)
 *   GET ?fresh=1   -> same, skipping the cache (Refresh button)
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notion.php';
requireAdmin();

if (!notionEnabled()) {
    jsonResponse(false, 'Notion is not connected yet.', ['enabled' => false]);
}

try {
    $out = notionOpenContracts(!empty($_GET['fresh']));
} catch (Throwable $e) {
    error_log('frontdesk_contracts: ' . $e->getMessage());
    $msg = $e->getCode() === 404 || $e->getCode() === 401
        ? 'Notion refused the request. Check that the Contracts database is shared with the integration.'
        : 'Could not load contracts from Notion right now.';
    jsonResponse(false, $msg, ['enabled' => true]);
}

jsonResponse(true, '', ['enabled' => true, 'notion_url' => notionContractsUrl()] + $out);
