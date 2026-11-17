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

// The Documenso envelope id stays on the server; the page only learns
// whether a file can be previewed.
require_once __DIR__ . '/../includes/documenso.php';
$files = documensoEnabled();
$out['contracts'] = array_map(function ($c) use ($files) {
    $c['has_file'] = $files && !empty($c['documenso_id']);
    unset($c['documenso_id']);
    return $c;
}, $out['contracts']);

jsonResponse(true, '', [
    'enabled'    => true,
    'notion_url' => notionContractsUrl(),
    'can_sign'   => $files && adminIsOwner(),
] + $out);
