<?php
/**
 * api/frontdesk_contract_file.php
 * Front Desk Contracts: the contract PDF for preview (inline) or download.
 *   GET ?id=<Notion page id>            -> PDF shown inline (iframe preview)
 *   GET ?id=<Notion page id>&download=1 -> PDF as a download
 * The id must be a contract currently on the Front Desk list; the Documenso
 * envelope id stays on the server.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notion.php';
require_once __DIR__ . '/../includes/documenso.php';
requireAdmin();

function contractFileFail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!notionEnabled() || !documensoEnabled()) contractFileFail(503, 'Contract files are not connected yet.');

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f\-]{32,36}$/i', $id)) contractFileFail(400, 'Bad contract id.');

try {
    $found = null;
    foreach (notionOpenContracts()['contracts'] as $c) {
        if (strcasecmp($c['id'], $id) === 0) { $found = $c; break; }
    }
    if (!$found || empty($found['documenso_id'])) contractFileFail(404, 'This contract has no file in Documenso.');

    [$pdf, $signed] = documensoContractPdf($found['documenso_id']);
} catch (Throwable $e) {
    error_log('frontdesk_contract_file: ' . $e->getMessage());
    contractFileFail(502, 'Could not get the contract from Documenso right now.');
}

$name = trim(preg_replace('/[^\w\- ]+/u', '', (string)$found['name'])) ?: 'contract';
$name .= $signed ? ' - signed.pdf' : ' - contract.pdf';
auditLog(!empty($_GET['download']) ? 'contract_downloaded' : 'contract_previewed', $found['client_id'] ?? null, $found['name']);
sendFileHeaders($name, empty($_GET['download']), strlen($pdf), 'application/pdf');
echo $pdf;
