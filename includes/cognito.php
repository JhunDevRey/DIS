<?php
/**
 * includes/cognito.php
 * Read-only access to Cognito Forms for the staff Front Desk "PQ Forms"
 * window. COGNITO_API_KEY (config.php) is a read-only API key from
 * Cognito → organization → Settings → Integrations → New API Key, and
 * COGNITO_PQ_FORM is the form's internal name or number (the part of the
 * form's URL / entries link after /forms/).
 *
 * Entries are cached on disk for COGNITO_CACHE_SECONDS so several open
 * Front Desks don't hammer the API.
 */

require_once __DIR__ . '/db.php'; // loads config.php
require_once __DIR__ . '/functions.php';

define('COGNITO_CACHE_SECONDS', 60);
define('COGNITO_DAYS', 60);          // older entries are left to Cognito
define('COGNITO_PAGE', 100);
define('COGNITO_MAX_PAGES', 10);

function cognitoEnabled(): bool
{
    return defined('COGNITO_API_KEY') && COGNITO_API_KEY !== ''
        && defined('COGNITO_PQ_FORM') && COGNITO_PQ_FORM !== '';
}

/** One GET to the Cognito API. Returns decoded JSON; throws with the HTTP status as code. */
function cognitoApi(string $path, array $query = []): array
{
    // COGNITO_API_BASE exists only so tests can point at a local fake API.
    $base = defined('COGNITO_API_BASE') && COGNITO_API_BASE ? rtrim(COGNITO_API_BASE, '/') : 'https://www.cognitoforms.com/api';
    $url = $base . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . COGNITO_API_KEY,
            'Accept: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Could not reach Cognito Forms: ' . $curlError);
    }
    $data = json_decode($raw, true);
    if ($status >= 400) {
        $msg = is_array($data) ? ($data['Message'] ?? $data['message'] ?? $data['error'] ?? null) : null;
        throw new RuntimeException(is_string($msg) && $msg !== '' ? $msg : ('Cognito Forms error ' . $status), $status);
    }
    if (!is_array($data)) {
        throw new RuntimeException('Cognito Forms sent an unreadable reply.', 502);
    }
    return $data;
}

/** The entries in a reply, whether it is a bare list or {"value":[…]} / {"Entries":[…]}. */
function cognitoRows(array $data): array
{
    if (array_is_list($data)) return $data;
    foreach (['value', 'Value', 'Entries', 'entries', 'items'] as $k) {
        if (isset($data[$k]) && is_array($data[$k])) return $data[$k];
    }
    return [];
}

/** Flat list of every string leaf in an entry, as [path => value]. */
function cognitoLeaves($node, string $path = '', int $depth = 0): array
{
    if ($depth > 4) return [];
    if (is_string($node)) return $path === '' ? [] : [$path => $node];
    if (!is_array($node)) return [];
    $out = [];
    foreach ($node as $k => $v) {
        if ($k === 'Entry') continue; // built-in metadata, handled separately
        $out += cognitoLeaves($v, $path === '' ? (string)$k : $path . '.' . $k, $depth + 1);
    }
    return $out;
}

/** Best-effort person name for an entry: a field called *name*, else "Entry #n". */
function cognitoEntryName(array $row, $number): string
{
    foreach ($row as $key => $val) {
        if ($key === 'Entry' || stripos((string)$key, 'name') === false) continue;
        if (is_string($val) && trim($val) !== '') return trim($val);
        if (is_array($val)) {
            // Cognito name fields: {"First":…, "Last":…} (or a list of them)
            $one = array_is_list($val) ? ($val[0] ?? []) : $val;
            if (is_array($one)) {
                $parts = array_filter([$one['First'] ?? null, $one['Middle'] ?? null, $one['Last'] ?? null], 'is_string');
                $s = trim(implode(' ', $parts));
                if ($s !== '') return $s;
            }
        }
    }
    return $number !== null ? 'Entry #' . $number : '(no name)';
}

/** First non-empty string/int among the given keys, looking in Entry{} then the row. */
function cognitoPick(array $row, array $meta, string ...$keys)
{
    foreach ($keys as $k) {
        $v = $meta[$k] ?? $row[$k] ?? null;
        if ((is_string($v) && $v !== '') || is_int($v)) return $v;
    }
    return null;
}

/** One entry shaped for the Front Desk window (null = unusable). */
function cognitoEntryForJson(array $row): ?array
{
    $meta = is_array($row['Entry'] ?? null) ? $row['Entry'] : [];

    $number    = cognitoPick($row, $meta, 'Number');
    $status    = (string)(cognitoPick($row, $meta, 'Status') ?? '');
    $submitted = cognitoPick($row, $meta, 'DateSubmitted');
    $created   = cognitoPick($row, $meta, 'DateCreated') ?? $submitted;
    $updated   = cognitoPick($row, $meta, 'DateUpdated') ?? $created;
    if (!is_string($created)) return null;

    // Submitted = has a submission date and isn't still "Incomplete"
    $done = is_string($submitted) && stripos($status, 'incomplete') === false;

    $emails = [];
    foreach (cognitoLeaves($row) as $v) {
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $v, $mm)) {
            foreach ($mm[0] as $e) $emails[strtolower($e)] = true;
        }
    }
    return [
        'id'           => (string)(cognitoPick($row, $meta, 'Id') ?? $number ?? ''),
        'number'       => $number,
        'name'         => cognitoEntryName($row, $number),
        'status'       => $status !== '' ? $status : ($done ? 'Submitted' : 'Incomplete'),
        'stage'        => $done ? 'submitted' : 'waiting',
        'emails'       => array_keys($emails),
        'url'          => cognitoPick($row, $meta, 'AdminLink', 'ViewLink', 'EditLink', 'PublicLink'),
        'created_at'   => $created,
        'submitted_at' => $done ? $submitted : null,
        'updated_at'   => is_string($updated) ? $updated : $created,
    ];
}

/** Link of the entries list in Cognito, for the window's footer. */
function cognitoEntriesUrl(): string
{
    return 'https://www.cognitoforms.com/' . rawurlencode((string)COGNITO_PQ_FORM) . '/entries';
}

/**
 * PQ entries from the last COGNITO_DAYS days, each with 'client_id' when
 * an email in the entry matches an active client. Cached; $fresh skips it.
 */
function cognitoPqEntries(bool $fresh = false): array
{
    $cacheDir = UPLOAD_DIR . '/cache';
    $cacheFile = $cacheDir . '/cognito_pq.json';
    if (!$fresh && is_file($cacheFile) && filemtime($cacheFile) > time() - COGNITO_CACHE_SECONDS) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $rows = [];
    for ($i = 0; $i < COGNITO_MAX_PAGES; $i++) {
        $page = cognitoRows(cognitoApi('forms/' . rawurlencode((string)COGNITO_PQ_FORM) . '/entries', [
            '$top' => COGNITO_PAGE, '$skip' => $i * COGNITO_PAGE,
        ]));
        $rows = array_merge($rows, $page);
        if (count($page) < COGNITO_PAGE) break;
    }

    $cut = time() - COGNITO_DAYS * 86400;
    $entries = [];
    foreach ($rows as $row) {
        $e = is_array($row) ? cognitoEntryForJson($row) : null;
        if ($e && (strtotime($e['created_at']) ?: 0) >= $cut) $entries[] = $e;
    }
    usort($entries, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

    $all = [];
    foreach ($entries as $e) $all = array_merge($all, $e['emails']);
    $clients = [];
    if ($all) {
        $all = array_values(array_unique($all));
        $in = implode(',', array_fill(0, count($all), '?'));
        $stmt = getDb()->prepare("SELECT id, LOWER(email) AS email FROM clients WHERE is_active = 1 AND LOWER(email) IN ($in)");
        $stmt->execute($all);
        foreach ($stmt->fetchAll() as $r) $clients[$r['email']] = (int)$r['id'];
    }
    foreach ($entries as &$e) {
        $e['client_id'] = null;
        foreach ($e['emails'] as $one) {
            if (isset($clients[$one])) { $e['client_id'] = $clients[$one]; break; }
        }
        unset($e['emails']); // not needed by the browser
    }
    unset($e);

    $result = ['entries' => $entries, 'days' => COGNITO_DAYS, 'fetched_at' => gmdate('Y-m-d\TH:i:s\Z')];
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    @file_put_contents($cacheFile, json_encode($result), LOCK_EX);
    return $result;
}
