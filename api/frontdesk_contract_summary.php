<?php
/**
 * api/frontdesk_contract_summary.php
 * Front Desk Contracts: "Download" — a one-page PDF summary of a contract
 * record from the Notion Contracts table (who, what, signing status,
 * payment). GET ?id=<Notion page id>. Only contracts on the Front Desk list.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notion.php';
require_once __DIR__ . '/../includes/simple_pdf.php';
requireAdmin();

function summaryFail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!notionEnabled()) summaryFail(503, 'Notion is not connected yet.');
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f\-]{32,36}$/i', $id)) summaryFail(400, 'Bad contract id.');

try {
    $found = null;
    foreach (notionOpenContracts()['contracts'] as $c) {
        if (strcasecmp($c['id'], $id) === 0) { $found = $c; break; }
    }
} catch (Throwable $e) {
    error_log('frontdesk_contract_summary: ' . $e->getMessage());
    summaryFail(502, 'Could not load the contract from Notion right now.');
}
if (!$found) summaryFail(404, 'That contract is no longer on the list.');

$when = fn(?string $iso) => $iso ? gmdate('M j, Y', strtotime($iso)) : '';
$emails = trim(implode(', ', array_filter([$found['email'] ?? '', $found['alt_email'] ?? ''])));
$pdf = simpleTextPdf(
    (string)$found['name'],
    [
        ['Signing status', $found['stage'] === 'done' ? 'Signed by the client and by JD' : 'Signed by the client — waiting for JD'],
        ['Contract', ($found['eng'] ? 'Engagement letter' : 'PCAIC contract')],
        ['Case type', implode(', ', $found['case_types'] ?? [])],
        ['Client no.', (string)($found['client_no'] ?? '')],
        ['Email', $emails],
        ['Phone', (string)($found['phone'] ?? '')],
        ['Amount due', is_numeric($found['amount_due'] ?? null) && $found['amount_due'] > 0 ? '$' . number_format((float)$found['amount_due'], 2) . ' CAD' : ''],
        ['Paid & signed marks', implode(', ', $found['paid_signed'] ?? [])],
        ['Case status', (string)($found['status'] ?? '')],
        ['Created', $when($found['created_at'] ?? null)],
        ['Last updated', $when($found['updated_at'] ?? null)],
        ['Notes', (string)($found['note'] ?? '')],
    ],
    'Summary of the Notion Contracts record — not the signed contract. Printed ' . gmdate('M j, Y') . ' (UTC).'
);

$name = trim(preg_replace('/[^\w\- ]+/u', '', (string)$found['name'])) ?: 'contract';
auditLog('contract_summary_downloaded', $found['client_id'] ?? null, $found['name']);
sendFileHeaders($name . ' - contract summary.pdf', false, strlen($pdf), 'application/pdf');
echo $pdf;
