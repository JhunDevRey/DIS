<?php
/**
 * api/frontdesk_pq_file.php
 * Front Desk PQ Forms: download the PDF Cognito generates for a submitted
 * entry. GET ?id=<Form>:<entry number>  (e.g. General:894). Staff only.
 * The Cognito link and API key stay on the server.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cognito.php';
requireAdmin();

function pqFileFail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!cognitoEnabled()) pqFileFail(503, 'Cognito Forms is not connected yet.');
if (!preg_match('/^([A-Za-z0-9_\- ]{1,60}):(\d{1,9})$/', (string)($_GET['id'] ?? ''), $m)) pqFileFail(400, 'Bad PQ entry id.');
[, $form, $number] = $m;

try {
    $r = cognitoEntryPdf($form, (int)$number);
} catch (RuntimeException $e) {
    error_log('frontdesk_pq_file: ' . $e->getMessage());
    pqFileFail(502, $e->getMessage());
} catch (Throwable $e) {
    error_log('frontdesk_pq_file: ' . $e->getMessage());
    pqFileFail(502, 'Could not get the PQ from Cognito Forms right now.');
}

$name = trim(preg_replace('/[^\w\- ]+/u', '', (string)$r['name'])) ?: 'PQ';
auditLog('pq_downloaded', null, $form . ' #' . $number . ' ' . $r['name']);
sendFileHeaders($name . ' - PQ ' . cognitoFormLabel($form) . '.pdf', false, strlen($r['pdf']), 'application/pdf');
echo $r['pdf'];
