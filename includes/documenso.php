<?php
/**
 * includes/documenso.php
 * Documenso API access for the Front Desk "Contracts" window: fetch a
 * contract's PDF (preview / download) and find JD's signing link.
 *
 * Config (server config.php):
 *   DOCUMENSO_TOKEN      API token (Documenso → team settings → API tokens)
 *   DOCUMENSO_JD_EMAIL   optional: JD's recipient email, used to pick the
 *                        right recipient when more than one is unsigned
 *   DOCUMENSO_API_BASE   tests only (default https://app.documenso.com/api/v2)
 *   DOCUMENSO_APP_URL    tests only (default https://app.documenso.com)
 *
 * The token never leaves the server: the browser only ever receives the PDF
 * bytes (through api/frontdesk_contract_file.php) or JD's signing link.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

define('DOCUMENSO_MAX_PDF_BYTES', 40 * 1024 * 1024);

function documensoEnabled(): bool
{
    return defined('DOCUMENSO_TOKEN') && DOCUMENSO_TOKEN !== '';
}

function documensoApiBase(): string
{
    return rtrim(defined('DOCUMENSO_API_BASE') && DOCUMENSO_API_BASE ? DOCUMENSO_API_BASE : 'https://app.documenso.com/api/v2', '/');
}

function documensoAppUrl(): string
{
    return rtrim(defined('DOCUMENSO_APP_URL') && DOCUMENSO_APP_URL ? DOCUMENSO_APP_URL : 'https://app.documenso.com', '/');
}

/**
 * One API request. $raw returns the body bytes (PDF) instead of JSON.
 * Throws RuntimeException (message safe to log, not to show) on failure.
 */
function documensoApi(string $method, string $path, ?array $json = null, bool $raw = false)
{
    $ch = curl_init(documensoApiBase() . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => array_merge(
            ['Authorization: ' . DOCUMENSO_TOKEN, 'Accept: ' . ($raw ? 'application/pdf, */*' : 'application/json')],
            $json !== null ? ['Content-Type: application/json'] : []
        ),
    ]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException('Could not reach Documenso: ' . $err);
    if ($status >= 400) {
        $msg = $raw ? '' : ((json_decode($body, true)['message'] ?? ''));
        throw new RuntimeException('Documenso error ' . $status . ($msg !== '' ? ': ' . $msg : ''), $status);
    }
    if ($raw) {
        if (strlen($body) > DOCUMENSO_MAX_PDF_BYTES) throw new RuntimeException('Contract file is too large.');
        return $body;
    }
    return json_decode($body, true) ?: [];
}

/** Envelope ids look like envelope_abc123; anything else never reaches a URL. */
function documensoEnvelopeIdOk(?string $id): bool
{
    return $id !== null && preg_match('/^envelope_[A-Za-z0-9]+$/', $id) === 1;
}

function documensoEnvelope(string $envelopeId): array
{
    if (!documensoEnvelopeIdOk($envelopeId)) throw new RuntimeException('Bad envelope id.');
    $env = documensoApi('GET', 'envelope/' . $envelopeId);
    return isset($env['envelope']) && is_array($env['envelope']) ? $env['envelope'] : $env;
}

function documensoIsCompleted(array $env): bool
{
    return strtoupper((string)($env['status'] ?? '')) === 'COMPLETED';
}

/**
 * The contract's PDF bytes: the sealed (fully signed) copy once Documenso has
 * completed it, otherwise the original document. Returns [bytes, isSigned].
 */
function documensoContractPdf(string $envelopeId): array
{
    $env = documensoEnvelope($envelopeId);
    $items = $env['envelopeItems'] ?? $env['items'] ?? [];
    $itemId = is_array($items) && $items ? ($items[0]['id'] ?? null) : null;
    if (!is_string($itemId) || !preg_match('/^[A-Za-z0-9_\-]+$/', $itemId)) {
        throw new RuntimeException('Envelope has no document.');
    }
    $signed = documensoIsCompleted($env);
    try {
        $pdf = documensoApi('GET', 'envelope/item/' . $itemId . '/download?version=' . ($signed ? 'signed' : 'original'), null, true);
    } catch (RuntimeException $e) {
        if (!$signed) throw $e;
        $signed = false;   // sealed copy not ready yet — fall back to the original
        $pdf = documensoApi('GET', 'envelope/item/' . $itemId . '/download?version=original', null, true);
    }
    if (strncmp($pdf, '%PDF', 4) !== 0) throw new RuntimeException('Documenso did not return a PDF.');
    return [$pdf, $signed];
}

/**
 * JD's signing page URL for an envelope the client has already signed.
 * Picks the one recipient that still has to sign (matching
 * DOCUMENSO_JD_EMAIL when several do). Throws RuntimeException with a
 * message that is safe to show staff.
 */
function documensoJdSigningUrl(string $envelopeId): string
{
    $env = documensoEnvelope($envelopeId);
    if (documensoIsCompleted($env)) throw new RuntimeException('This contract is already fully signed.');

    $pending = [];
    foreach ($env['recipients'] ?? [] as $r) {
        $role = strtoupper((string)($r['role'] ?? 'SIGNER'));
        if ($role !== 'SIGNER' && $role !== 'APPROVER') continue;
        if (strtoupper((string)($r['signingStatus'] ?? '')) === 'SIGNED') continue;
        $pending[] = $r;
    }
    if (defined('DOCUMENSO_JD_EMAIL') && DOCUMENSO_JD_EMAIL !== '') {
        $want = strtolower(DOCUMENSO_JD_EMAIL);
        $pending = array_values(array_filter($pending, fn($r) => strtolower((string)($r['email'] ?? '')) === $want));
    }
    if (count($pending) === 0) throw new RuntimeException('Nobody is waiting to sign this contract in Documenso.');
    if (count($pending) > 1) throw new RuntimeException('More than one person still has to sign this contract — open it in Documenso.');

    $r = $pending[0];
    $url = (string)($r['signingUrl'] ?? '');
    if ($url === '' && !empty($r['token']) && preg_match('/^[A-Za-z0-9_\-]+$/', (string)$r['token'])) {
        $url = documensoAppUrl() . '/sign/' . $r['token'];
    }
    // Only ever hand the browser a link on the Documenso host.
    $host = parse_url($url, PHP_URL_HOST);
    $appHost = parse_url(documensoAppUrl(), PHP_URL_HOST);
    if ($url === '' || !$host || strcasecmp($host, (string)$appHost) !== 0 || !preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('Documenso did not give a signing link.');
    }
    return $url;
}
