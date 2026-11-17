<?php
/**
 * api/frontdesk_contract_sign.php
 * Front Desk Contracts: "Sign as JD". Owner accounts only.
 *   POST id=<Notion page id>  -> { signing_url } for JD's turn on a contract
 *                                 the client has already signed.
 * The page opens that link in a dialog. When JD finishes, Documenso's
 * webhook (the existing Make scenario) moves the contract to fully signed
 * in Notion.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notion.php';
require_once __DIR__ . '/../includes/documenso.php';
requireAdmin();
requireOwner();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'POST only.');
verifyCsrfToken();
if (!notionEnabled() || !documensoEnabled()) jsonResponse(false, 'Documenso is not connected yet.');

$id = (string)($_POST['id'] ?? '');
if (!preg_match('/^[0-9a-f\-]{32,36}$/i', $id)) jsonResponse(false, 'Bad contract id.');

try {
    $found = null;
    foreach (notionOpenContracts()['contracts'] as $c) {
        if (strcasecmp($c['id'], $id) === 0) { $found = $c; break; }
    }
    if (!$found) jsonResponse(false, 'That contract is no longer on the list — refresh and try again.');
    if ($found['stage'] !== 'jd' || empty($found['documenso_id'])) jsonResponse(false, 'This contract is not waiting for JD to sign.');

    $url = documensoJdSigningUrl($found['documenso_id']);
} catch (RuntimeException $e) {
    error_log('frontdesk_contract_sign: ' . $e->getMessage());
    // Messages thrown by documensoJdSigningUrl() are written for staff; API/network errors are not.
    $safe = strpos($e->getMessage(), 'Documenso error') === 0 || strpos($e->getMessage(), 'Could not reach') === 0
        ? 'Could not reach Documenso right now. Try again in a moment.' : $e->getMessage();
    jsonResponse(false, $safe);
} catch (Throwable $e) {
    error_log('frontdesk_contract_sign: ' . $e->getMessage());
    jsonResponse(false, 'Could not start signing right now.');
}

auditLog('contract_sign_started', $found['client_id'] ?? null, $found['name']);
jsonResponse(true, '', ['signing_url' => $url, 'name' => $found['name']]);
