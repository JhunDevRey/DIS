<?php
/**
 * includes/notion.php
 * Read-only access to the office Notion workspace for the staff Front Desk
 * (the "Contracts" window). Uses a Notion internal integration token
 * (NOTION_TOKEN in config.php); the Contracts database must be shared with
 * that integration in Notion (••• → Connections).
 *
 * Results are cached on disk for NOTION_CACHE_SECONDS so a room full of
 * open Front Desks doesn't hit Notion's rate limit (~3 requests/second).
 */

require_once __DIR__ . '/db.php'; // loads config.php
require_once __DIR__ . '/functions.php';

define('NOTION_VERSION', '2025-09-03');
define('NOTION_CACHE_SECONDS', 60);

/** Notion is on only once a token is in config.php. */
function notionEnabled(): bool
{
    return defined('NOTION_TOKEN') && NOTION_TOKEN !== '';
}

/**
 * One Notion API request. Returns decoded JSON; throws RuntimeException
 * with Notion's own message on failure.
 */
function notionApi(string $method, string $path, ?array $json = null): array
{
    // NOTION_API_BASE exists only so tests can point at a local fake API.
    $base = defined('NOTION_API_BASE') && NOTION_API_BASE ? rtrim(NOTION_API_BASE, '/') : 'https://api.notion.com';
    $ch = curl_init($base . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . NOTION_TOKEN,
            'Notion-Version: ' . NOTION_VERSION,
            'Content-Type: application/json',
        ],
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Could not reach Notion: ' . $curlError);
    }
    $data = json_decode($raw, true) ?: [];
    if ($status >= 400) {
        throw new RuntimeException($data['message'] ?? ('Notion error ' . $status), $status);
    }
    return $data;
}

/** Plain value of one Notion page property, whatever its type. */
function notionPropValue(?array $prop)
{
    if (!$prop) return null;
    switch ($prop['type'] ?? '') {
        case 'title':
        case 'rich_text':
            return trim(implode('', array_map(fn($t) => $t['plain_text'] ?? '', $prop[$prop['type']] ?? []))) ?: null;
        case 'select':
        case 'status':
            return $prop[$prop['type']]['name'] ?? null;
        case 'multi_select':
            return array_map(fn($o) => $o['name'], $prop['multi_select'] ?? []);
        case 'email':
        case 'phone_number':
        case 'url':
            return $prop[$prop['type']] ?: null;
        case 'checkbox':
            return (bool)$prop['checkbox'];
        case 'number':
            return $prop['number'];
        case 'created_time':
            return $prop['created_time'];
    }
    return null;
}


// ---- 🖊️ Contracts (Front Desk "Contracts" window) ----
// Make fills "Documenso Status" as contracts move through Documenso; the
// window lists the ones the client has signed (waiting for JD) and the ones
// both have signed. The PDF itself comes from Documenso (includes/documenso.php).
define('NOTION_CONTRACTS_DEFAULT_DS', 'f2f9876c-54e8-4952-b0c1-411dfc590aa2');
define('NOTION_CONTRACTS_DEFAULT_URL', 'https://www.notion.so/f74fe94d062b4f41bf3ecbba683a8075');
define('NOTION_CONTRACTS_DAYS', 120);   // older contracts are left to Notion
define('NOTION_CONTRACTS_DONE_DAYS', 30); // fully signed ones drop off after this long
define('NOTION_CONTRACTS_PAID', ['Paid', 'Paid w/ Square', 'Cheque', 'Cash']);

function notionContractsDataSource(): string
{
    return defined('NOTION_CONTRACTS_DS') && NOTION_CONTRACTS_DS ? NOTION_CONTRACTS_DS : NOTION_CONTRACTS_DEFAULT_DS;
}

function notionContractsUrl(): string
{
    return defined('NOTION_CONTRACTS_URL') && NOTION_CONTRACTS_URL ? NOTION_CONTRACTS_URL : NOTION_CONTRACTS_DEFAULT_URL;
}

/**
 * Which tab a contract belongs in, or null when it isn't one the Front
 * Desk shows (not sent yet, or still waiting for the client):
 *   jd   — the client has signed; JD hasn't yet
 *   done — signed by both the client and JD
 */
function notionContractStage(string $docStatus, array $paidSigned): ?string
{
    // "Sent - waiting … | Paid - reminders off": the part before " | " is the Documenso state.
    $state = strtolower(trim(explode('|', $docStatus)[0]));

    if (strpos($state, 'waiting for joel') !== false) return 'jd';
    if (strpos($state, 'signed by client and joel') !== false) return 'done';
    if (strpos($state, 'waiting for client signature') !== false || strpos($state, 'declined') !== false) return null;
    // Signed on paper in the office (no Documenso state to go by).
    if ($state === '' && in_array('Sig n office', $paidSigned, true)) return 'done';
    return null;
}

/** Lower-cased email addresses in one or more free-text email fields. */
function notionEmailList(?string ...$fields): array
{
    $out = [];
    foreach ($fields as $f) {
        foreach (preg_split('/[\s,;]+/', strtolower((string)$f)) as $one) {
            if ($one !== '' && strpos($one, '@') !== false) $out[] = $one;
        }
    }
    return array_values(array_unique($out));
}

/** One Contracts page shaped for the Front Desk window (null = not open). */
function notionContractForJson(array $page): ?array
{
    $p = $page['properties'] ?? [];
    $v = fn(string $name) => notionPropValue($p[$name] ?? null);
    $docStatus = (string)($v('Documenso Status') ?? '');
    $paidSigned = $v('Paid & Signed') ?: [];
    $stage = notionContractStage($docStatus, $paidSigned);
    if ($stage === null) return null;

    $parts = array_values(array_filter(array_map('trim', explode('|', $docStatus)), 'strlen'));
    $state = array_shift($parts);
    $alt = trim((string)($v('Alternative Email') ?? ''));
    return [
        'id'          => $page['id'] ?? '',
        'url'         => $page['url'] ?? '',
        'stage'       => $stage,
        'documenso_id' => preg_match('/^envelope_[A-Za-z0-9]+$/', trim((string)($v('Documenso ID') ?? ''))) ? trim((string)$v('Documenso ID')) : null,
        'name'        => $v('Name') ?? '(no name)',
        'email'       => $v('Email'),
        'alt_email'   => $alt !== '' ? $alt : null,
        'phone'       => $v('Phone No.'),
        'case_types'  => $v('Case Type') ?: [],
        'client_no'   => $v('Client No.'),
        'eng'         => stripos((string)$state, 'ENG') === 0,     // engagement letter vs PCAIC
        'declined'    => stripos((string)$state, 'declined') !== false,
        'doc_state'   => $state ?: null,
        'note'        => $parts ? mb_strimwidth(implode(' · ', $parts), 0, 140, '…') : null,
        'paid_signed' => $paidSigned,
        'amount_due'  => $v('Amount Due'),
        'status'      => $v('Status'),
        'created_at'  => $page['created_time'] ?? null,
        'updated_at'  => $page['last_edited_time'] ?? null,
    ];
}

/**
 * Open contracts from the last NOTION_CONTRACTS_DAYS days, newest first,
 * each with 'client_id' when one of its emails matches an active client.
 * Cached for NOTION_CACHE_SECONDS; $fresh skips the cache (Refresh button).
 */
function notionOpenContracts(bool $fresh = false): array
{
    $cacheDir = UPLOAD_DIR . '/cache';
    $cacheFile = $cacheDir . '/notion_contracts.json';
    if (!$fresh && is_file($cacheFile) && filemtime($cacheFile) > time() - NOTION_CACHE_SECONDS) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $since = gmdate('Y-m-d\TH:i:s\Z', time() - NOTION_CONTRACTS_DAYS * 86400);
    $body = [
        'filter' => ['and' => [
            ['timestamp' => 'created_time', 'created_time' => ['on_or_after' => $since]],
            ['or' => [
                ['property' => 'Documenso Status', 'rich_text' => ['is_not_empty' => true]],
                ['property' => 'Paid & Signed', 'multi_select' => ['is_not_empty' => true]],
            ]],
        ]],
        'sorts'     => [['timestamp' => 'created_time', 'direction' => 'descending']],
        'page_size' => 100,
    ];
    $pages = [];
    for ($i = 0; $i < 5; $i++) {
        $data = notionApi('POST', 'v1/data_sources/' . notionContractsDataSource() . '/query', $body);
        $pages = array_merge($pages, $data['results'] ?? []);
        if (empty($data['has_more']) || empty($data['next_cursor'])) break;
        $body['start_cursor'] = $data['next_cursor'];
    }
    $contracts = array_values(array_filter(array_map('notionContractForJson', $pages)));
    // Fully signed contracts only stay on the list for a month after the last edit.
    $doneSince = time() - NOTION_CONTRACTS_DONE_DAYS * 86400;
    $contracts = array_values(array_filter($contracts, fn($c) =>
        $c['stage'] !== 'done' || strtotime((string)($c['updated_at'] ?? $c['created_at'] ?? '')) >= $doneSince));

    // Link to the client's file when an email matches an active client.
    $all = [];
    foreach ($contracts as $c) $all = array_merge($all, notionEmailList($c['email'], $c['alt_email']));
    $clients = [];
    if ($all) {
        $all = array_values(array_unique($all));
        $in = implode(',', array_fill(0, count($all), '?'));
        $stmt = getDb()->prepare("SELECT id, LOWER(email) AS email FROM clients WHERE is_active = 1 AND LOWER(email) IN ($in)");
        $stmt->execute($all);
        foreach ($stmt->fetchAll() as $row) $clients[$row['email']] = (int)$row['id'];
    }
    foreach ($contracts as &$c) {
        $c['client_id'] = null;
        foreach (notionEmailList($c['email'], $c['alt_email']) as $one) {
            if (isset($clients[$one])) { $c['client_id'] = $clients[$one]; break; }
        }
    }
    unset($c);

    $result = [
        'contracts'  => $contracts,
        'days'       => NOTION_CONTRACTS_DAYS,
        'fetched_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    @file_put_contents($cacheFile, json_encode($result), LOCK_EX);
    return $result;
}
