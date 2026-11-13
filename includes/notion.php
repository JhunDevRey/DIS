<?php
/**
 * includes/notion.php
 * Read-only access to the office Notion workspace for the staff Front Desk
 * (the "Notion · Inquiries" window). Uses a Notion internal integration
 * token (NOTION_TOKEN in config.php); the Inquiries database must be shared
 * with that integration in Notion (••• → Connections).
 *
 * Results are cached on disk for NOTION_CACHE_SECONDS so a room full of
 * open Front Desks doesn't hit Notion's rate limit (~3 requests/second).
 */

require_once __DIR__ . '/db.php'; // loads config.php
require_once __DIR__ . '/functions.php';

define('NOTION_VERSION', '2025-09-03');
define('NOTION_CACHE_SECONDS', 60);
// The 📬 Inquiries data source and the database page staff open in Notion.
define('NOTION_INQUIRIES_DEFAULT_DS', '54a74c41-aa2a-4718-854c-3433727c4608');
define('NOTION_INQUIRIES_DEFAULT_URL', 'https://www.notion.so/072cfe33276e44e498ed33cac24b0843');

/** Notion is on only once a token is in config.php. */
function notionEnabled(): bool
{
    return defined('NOTION_TOKEN') && NOTION_TOKEN !== '';
}

function notionInquiriesDataSource(): string
{
    return defined('NOTION_INQUIRIES_DS') && NOTION_INQUIRIES_DS ? NOTION_INQUIRIES_DS : NOTION_INQUIRIES_DEFAULT_DS;
}

function notionInquiriesUrl(): string
{
    return defined('NOTION_INQUIRIES_URL') && NOTION_INQUIRIES_URL ? NOTION_INQUIRIES_URL : NOTION_INQUIRIES_DEFAULT_URL;
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

/** One Inquiries page shaped for the Front Desk window. */
function notionInquiryForJson(array $page): array
{
    $p = $page['properties'] ?? [];
    $v = fn(string $name) => notionPropValue($p[$name] ?? null);
    $message = (string)($v('Message') ?? '');
    return [
        'id'           => $page['id'] ?? '',
        'url'          => $page['url'] ?? '',
        'created_at'   => $page['created_time'] ?? null,     // ISO 8601, UTC
        'name'         => $v('Name') ?? '(no name)',
        'email'        => $v('Email'),
        'phone'        => $v('Phone No.'),
        'inquiring_as' => $v('Inquiring as'),
        'service'      => $v('Service'),
        'case_types'   => $v('Case Type') ?: [],
        'heard_via'    => $v('Type'),                          // how they found us
        'from_website' => ($v('Source') ?? '') === 'website_typeform',
        'country'      => $v('Country'),
        'message'      => $message !== '' ? mb_strimwidth($message, 0, 220, '…') : null,
    ];
}

/**
 * Inquiries created in the last $days days, newest first (max 100).
 * Cached for NOTION_CACHE_SECONDS; $fresh skips the cache (Refresh button).
 */
function notionRecentInquiries(int $days = 7, bool $fresh = false): array
{
    $cacheDir = UPLOAD_DIR . '/cache';
    $cacheFile = $cacheDir . '/notion_inquiries_' . $days . '.json';
    if (!$fresh && is_file($cacheFile) && filemtime($cacheFile) > time() - NOTION_CACHE_SECONDS) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $since = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
    $data = notionApi('POST', 'v1/data_sources/' . notionInquiriesDataSource() . '/query', [
        'filter'    => ['timestamp' => 'created_time', 'created_time' => ['on_or_after' => $since]],
        'sorts'     => [['timestamp' => 'created_time', 'direction' => 'descending']],
        'page_size' => 100,
    ]);
    $result = [
        'inquiries'  => array_map('notionInquiryForJson', $data['results'] ?? []),
        'fetched_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    @file_put_contents($cacheFile, json_encode($result), LOCK_EX);
    return $result;
}
