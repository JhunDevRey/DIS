<?php
/**
 * includes/cognito.php
 * Read-only access to Cognito Forms for the staff Front Desk "PQ Forms"
 * window. COGNITO_API_KEY (config.php) is a read-only API key from
 * Cognito → organization → Settings → Integrations → New API Key, and
 * COGNITO_PQ_FORM is one form's internal name/number or a comma-separated
 * list (e.g. 'General,Spousal,Lmia').
 *
 * Cognito's REST API has no "list entries" call: an entry can only be
 * fetched by its number (GET forms/{form}/entries/{number}). So this keeps
 * a small state file (UPLOAD_DIR/cache/cognito_pq.json, outside the web
 * root) with every recent entry per form, and on each refresh:
 *   1. looks for new entries past the highest known number,
 *   2. walks backwards from the newest until it passes COGNITO_DAYS,
 *   3. re-checks entries still "Incomplete" to see whether they were submitted.
 *
 * Cognito throttles (HTTP 429), so every refresh has a request budget
 * (COGNITO_REQ_PER_MIN across a rolling minute). A request that was
 * throttled or skipped for budget is NEVER treated as "entry not found":
 * progress only advances over entries that were really answered, and the
 * rest is picked up by the next refresh ('partial' => true, 'wait' seconds).
 */

require_once __DIR__ . '/db.php'; // loads config.php
require_once __DIR__ . '/functions.php';

define('COGNITO_CACHE_SECONDS', 1800); // page loads answer from the state file if it is this fresh (Refresh button skips it)
define('COGNITO_DAYS', 60);            // older entries are left to Cognito
define('COGNITO_PARALLEL', 4);         // simultaneous requests
define('COGNITO_REQ_PER_MIN', 90);     // our own cap on API requests per rolling minute
define('COGNITO_FIND_MAX_COST', 45);   // budget needed to start finding a form's newest entry
define('COGNITO_RECHECK_AFTER', 180);  // seconds before an Incomplete entry is re-checked
define('COGNITO_RECHECK_MAX_FRESH', 150);

function cognitoEnabled(): bool
{
    return defined('COGNITO_API_KEY') && COGNITO_API_KEY !== ''
        && defined('COGNITO_PQ_FORM') && COGNITO_PQ_FORM !== '';
}

/** The PQ forms to read: COGNITO_PQ_FORM is one name/number or a comma-separated list. */
function cognitoForms(): array
{
    if (!defined('COGNITO_PQ_FORM')) return [];
    $list = array_map('trim', explode(',', (string)COGNITO_PQ_FORM));
    return array_values(array_unique(array_filter($list, 'strlen')));
}

/** Short label for a form's internal name (shown as a tag on each row). */
function cognitoFormLabel(string $form): string
{
    static $special = ['Lmia' => 'LMIA', 'Pgp' => 'PGP'];
    return $special[$form] ?? $form;
}

/** Link to Cognito, for the window's footer. */
function cognitoEntriesUrl(): string
{
    return 'https://www.cognitoforms.com/';
}

function cognitoBase(): string
{
    // COGNITO_API_BASE exists only so tests can point at a local fake API.
    return defined('COGNITO_API_BASE') && COGNITO_API_BASE ? rtrim(COGNITO_API_BASE, '/') : 'https://www.cognitoforms.com/api';
}

function cognitoCurl(string $path)
{
    $ch = curl_init(cognitoBase() . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . COGNITO_API_KEY, 'Accept: application/json'],
    ]);
    return $ch;
}

/**
 * Request budget for the current refresh: cognitoBudget(N) sets it,
 * cognitoBudget(null, n) spends n, cognitoBudget() reads what is left.
 */
function cognitoBudget(?int $set = null, int $spend = 0): int
{
    static $left = PHP_INT_MAX;
    if ($set !== null) $left = $set;
    $left -= $spend;
    return $left;
}

/**
 * GET several API paths in parallel, within the request budget.
 * Returns [path => [httpStatus, decoded|null]]. Status 0 = could not
 * connect, -1 = not sent (budget used up), 429 = Cognito says slow down.
 * Never throws.
 */
function cognitoGetMany(array $paths): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($paths)), COGNITO_PARALLEL) as $chunk) {
        if (cognitoBudget() < count($chunk) || !empty($GLOBALS['__cognito_throttled'])) {
            foreach ($chunk as $p) $out[$p] = [-1, null];
            continue;
        }
        cognitoBudget(null, count($chunk));
        $GLOBALS['__cognito_spent'] = ($GLOBALS['__cognito_spent'] ?? 0) + count($chunk);
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $p) {
            $handles[$p] = cognitoCurl($p);
            curl_multi_add_handle($mh, $handles[$p]);
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $p => $ch) {
            $raw = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 429) $GLOBALS['__cognito_throttled'] = true; // stop sending for this refresh
            $out[$p] = [$code, is_string($raw) ? json_decode($raw, true) : null];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        usleep(150000); // gentle pacing between batches
    }
    return $out;
}

/** Flat list of every string leaf in an entry, as [path => value]. */
function cognitoLeaves($node, string $path = '', int $depth = 0): array
{
    if ($depth > 5) return [];
    if (is_string($node)) return $path === '' ? [] : [$path => $node];
    if (!is_array($node)) return [];
    $out = [];
    foreach ($node as $k => $v) {
        if ($k === 'Entry' || $k === 'Form') continue; // built-in metadata, handled separately
        $out += cognitoLeaves($v, $path === '' ? (string)$k : $path . '.' . $k, $depth + 1);
    }
    return $out;
}

/** A name object ({"First":…,"Last":…} or {"GivenNames":…,"LastName":…}) as text. */
function cognitoJoinName($v): string
{
    if (!is_array($v)) return '';
    $one = array_is_list($v) ? ($v[0] ?? []) : $v;
    if (!is_array($one)) return '';
    $parts = [];
    foreach (['First', 'GivenNames', 'Middle', 'MiddleName', 'Last', 'LastName'] as $k) {
        if (isset($one[$k]) && is_string($one[$k]) && trim($one[$k]) !== '') $parts[] = trim($one[$k]);
    }
    return implode(' ', $parts);
}

/**
 * Best-effort person name: Metadata.Name (set by the PQ BOT), then the
 * person's own name fields, then any field called *name*, else "Entry #n".
 */
function cognitoEntryName(array $row, $number): string
{
    $meta = $row['Metadata']['Name'] ?? null;
    if (is_string($meta) && trim($meta) !== '') return trim($meta);

    foreach ([$row['PersonalDetails'] ?? null, $row['Applicant'] ?? null, $row] as $scope) {
        if (!is_array($scope)) continue;
        $s = cognitoJoinName($scope);
        if ($s !== '') return $s;
    }
    foreach ($row as $key => $val) {
        if ($key === 'Entry' || stripos((string)$key, 'name') === false) continue;
        if (is_string($val) && trim($val) !== '') return trim($val);
        $s = cognitoJoinName($val);
        if ($s !== '') return $s;
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
        'id'           => (string)($number ?? ''),
        'number'       => $number,
        'name'         => cognitoEntryName($row, $number),
        'status'       => $status !== '' ? $status : ($done ? 'Submitted' : 'Incomplete'),
        'stage'        => $done ? 'submitted' : 'waiting',
        'emails'       => array_keys($emails),
        'url'          => cognitoPick($row, $meta, 'AdminLink', 'InternalLink', 'PublicLink'),
        'created_at'   => $created,
        'submitted_at' => $done ? $submitted : null,
        'updated_at'   => is_string($updated) ? $updated : $created,
    ];
}

/** Thrown when Cognito throttles us or the budget ran out: try again on the next refresh. */
class CognitoLater extends RuntimeException {}

/**
 * Highest existing entry number of a form (0 = none), found with parallel
 * probes: powers of two first, then repeated splits of the gap. Throws
 * CognitoLater if any probe was throttled/skipped (never guesses).
 */
function cognitoFindMax(string $form): int
{
    $path = fn(int $n) => 'forms/' . rawurlencode($form) . '/entries/' . $n;
    $answer = function (array $res, int $n) use ($path): int {
        $s = $res[$path($n)][0];
        if ($s === 401 || $s === 403) throw new RuntimeException('Cognito Forms refused the API key.', $s);
        if ($s === 200 || $s === 404) return $s;
        throw new CognitoLater('Cognito Forms is busy (HTTP ' . $s . ').', $s);
    };
    $pow = [];
    for ($n = 1; $n <= 8192; $n *= 2) $pow[] = $n;
    $res = cognitoGetMany(array_map($path, $pow));
    $lo = 0;
    $hi = null;
    foreach ($pow as $n) {
        if ($answer($res, $n) === 200) { $lo = $n; } else { $hi = $n; break; }
    }
    if ($hi === null) return $lo;
    while ($hi - $lo > 1) {
        $step = max(1, intdiv($hi - $lo, COGNITO_PARALLEL + 1));
        $pts = [];
        for ($n = $lo + $step; $n < $hi; $n += $step) $pts[] = $n;
        $res = cognitoGetMany(array_map($path, $pts));
        $newLo = $lo;
        $newHi = $hi;
        foreach ($pts as $n) {
            if ($answer($res, $n) === 200) $newLo = $n; else { $newHi = $n; break; }
        }
        if ($newLo === $lo && $newHi === $hi) break;
        [$lo, $hi] = [$newLo, $newHi];
    }
    return $lo;
}

/**
 * Fetch these entry numbers of a form. Returns [found, unanswered]:
 * found = [number => normalized entry] for entries that exist, unanswered =
 * numbers that were throttled / skipped for budget / failed to connect
 * (NOT the same as "doesn't exist" - those are simply absent from both).
 */
function cognitoFetchEntries(string $form, array $numbers, int &$authErrors): array
{
    $path = fn(int $n) => 'forms/' . rawurlencode($form) . '/entries/' . $n;
    $res = cognitoGetMany(array_map($path, $numbers));
    $found = [];
    $unanswered = [];
    foreach ($numbers as $n) {
        [$s, $d] = $res[$path($n)];
        if ($s === 401 || $s === 403) { $authErrors++; $unanswered[] = $n; continue; }
        if ($s === 200 && is_array($d)) {
            if ($e = cognitoEntryForJson($d)) $found[$n] = $e + ['checked' => time()];
        } elseif ($s !== 404) {
            $unanswered[] = $n;
        }
    }
    return [$found, $unanswered];
}

/**
 * Steps 1-2 for one form: new entries past the highest known number, then
 * walk backwards until entries pass the window. Progress is only recorded
 * over entries Cognito really answered. Returns the new state.
 */
function cognitoSyncForm(string $form, array $st, int &$authErrors): array
{
    $st += ['max' => null, 'floor' => null, 'done' => false, 'entries' => []];
    $cut = time() - COGNITO_DAYS * 86400;

    if ($st['max'] === null) {
        if (cognitoBudget() < COGNITO_FIND_MAX_COST) throw new CognitoLater('No budget left to find the newest entry.');
        $st['max'] = cognitoFindMax($form);
        $st['floor'] = $st['max'] + 1;
        $st['done'] = $st['max'] === 0;
    } else {
        // New entries: probe the next few numbers past the highest known.
        for ($round = 0; $round < 5 && cognitoBudget() >= COGNITO_PARALLEL; $round++) {
            $nums = range($st['max'] + 1, $st['max'] + COGNITO_PARALLEL);
            [$found, $unanswered] = cognitoFetchEntries($form, $nums, $authErrors);
            $st['entries'] += $found;
            if ($unanswered) break;                     // can't tell yet - look again next time
            if (!$found) break;                         // nothing new
            $st['max'] = max(array_keys($found));
        }
    }

    // Walk backwards until the entries are older than the window.
    $emptyRuns = 0;
    while (!$st['done'] && $st['floor'] > 1 && cognitoBudget() >= COGNITO_PARALLEL) {
        $hi = $st['floor'] - 1;
        $lo = max(1, $hi - COGNITO_PARALLEL + 1);
        [$found, $unanswered] = cognitoFetchEntries($form, range($hi, $lo), $authErrors);
        $st['entries'] += $found;
        if ($unanswered) break;                         // retry this same stretch next time
        $st['floor'] = $lo;
        $emptyRuns = $found ? 0 : $emptyRuns + 1;
        $oldest = null;
        foreach ($found as $e) { $t = strtotime($e['created_at']) ?: 0; $oldest = $oldest === null ? $t : min($oldest, $t); }
        if (($oldest !== null && $oldest < $cut) || $emptyRuns >= 3 || $lo <= 1) $st['done'] = true;
    }
    return $st;
}

/** Step 3 for one form: re-check entries still Incomplete (oldest check first), within what budget is left. */
function cognitoRecheckForm(string $form, array $st, bool $fresh, int &$authErrors): array
{
    $due = [];
    foreach ($st['entries'] ?? [] as $n => $e) {
        if ($e['stage'] === 'waiting' && ($fresh || ($e['checked'] ?? 0) < time() - COGNITO_RECHECK_AFTER)) $due[$n] = $e['checked'] ?? 0;
    }
    asort($due);
    $nums = array_map('intval', array_slice(array_keys($due), 0, min(COGNITO_RECHECK_MAX_FRESH, max(0, cognitoBudget()))));
    if ($nums) {
        [$found] = cognitoFetchEntries($form, $nums, $authErrors);
        $st['entries'] = $found + $st['entries'];       // newer copies win
    }
    return $st;
}

/**
 * PQ entries from the last COGNITO_DAYS days across all configured forms,
 * newest first, each with 'form' and 'client_id' (when an email in the
 * entry matches an active client). $fresh = Refresh button.
 * Result 'partial' = still catching up (call again after 'wait' seconds).
 */
function cognitoPqEntries(bool $fresh = false): array
{
    $cacheDir = UPLOAD_DIR . '/cache';
    $file = $cacheDir . '/cognito_pq.json';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

    $state = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $forms = cognitoForms();
    $now = time();
    $wait = max(0, (int)($state['throttled_until'] ?? 0) - $now);
    $failed = [];
    $partial = !empty($state['partial']);

    // A half-loaded state is never "fresh": keep going - unless we are cooling off.
    $fresh_enough = !$fresh && !$partial && ($state['fetched_at_ts'] ?? 0) > $now - COGNITO_CACHE_SECONDS;
    if (!$fresh_enough && $wait === 0) {
        // One refresh at a time; others use the current state file.
        $lock = @fopen($cacheDir . '/cognito_pq.lock', 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            @set_time_limit(60);
            // Our own cap: requests made in the last 60 s count against this minute.
            $log = array_values(array_filter($state['req_log'] ?? [], fn($r) => $r[0] > $now - 60));
            $usedLastMinute = array_sum(array_column($log, 1));
            $GLOBALS['__cognito_spent'] = 0;
            $GLOBALS['__cognito_throttled'] = false;
            cognitoBudget(max(0, COGNITO_REQ_PER_MIN - $usedLastMinute));

            $authErrors = 0;
            $lastError = null;
            $allDone = true;
            foreach ($forms as $form) {                 // 1-2: find new + backfill, every form first
                try {
                    $state['forms'][$form] = cognitoSyncForm($form, $state['forms'][$form] ?? [], $authErrors);
                    if (!$state['forms'][$form]['done']) $allDone = false;
                } catch (CognitoLater $ex) {
                    $allDone = false;                   // nothing recorded for this form yet
                } catch (Throwable $ex) {
                    $failed[] = $form;
                    $lastError = $ex;
                    error_log('cognito form ' . $form . ': ' . $ex->getMessage());
                }
            }
            foreach ($forms as $form) {                 // 3: still-Incomplete entries, with what is left
                if (isset($state['forms'][$form]['entries'])) {
                    $state['forms'][$form] = cognitoRecheckForm($form, $state['forms'][$form], $fresh, $authErrors);
                }
            }
            $cut = $now - COGNITO_DAYS * 86400;         // forget entries older than the window
            foreach ($state['forms'] ?? [] as $f => $s) {
                foreach ($s['entries'] ?? [] as $n => $e) {
                    if ((strtotime($e['created_at']) ?: 0) < $cut) unset($state['forms'][$f]['entries'][$n]);
                }
            }

            if ($authErrors > 0 || ($lastError && count($failed) === count($forms))) {
                flock($lock, LOCK_UN);
                fclose($lock);
                throw $lastError ?: new RuntimeException('Cognito Forms refused the API key.', 401);
            }
            $throttled = !empty($GLOBALS['__cognito_throttled']);
            $log[] = [$now, (int)($GLOBALS['__cognito_spent'] ?? 0)];
            $state['req_log'] = $log;
            if ($throttled) $state['throttled_until'] = $now + 60;
            $state['partial'] = !$allDone || $throttled;
            $partial = $state['partial'];
            $wait = $throttled ? 60 : (!$allDone ? (cognitoBudget() < COGNITO_PARALLEL ? 20 : 3) : 0);
            $state['failed'] = $failed;
            $state['fetched_at_ts'] = $now;
            $state['fetched_at'] = gmdate('Y-m-d\TH:i:s\Z');
            @file_put_contents($file, json_encode($state), LOCK_EX);
            flock($lock, LOCK_UN);
        } else {
            $failed = $state['failed'] ?? [];
        }
        if ($lock) fclose($lock);
    } else {
        $failed = $state['failed'] ?? [];
        if ($wait === 0 && $partial) $wait = 3;
    }

    $cut = $now - COGNITO_DAYS * 86400;
    $entries = [];
    foreach ($forms as $form) {
        foreach (($state['forms'][$form]['entries'] ?? []) as $e) {
            if ((strtotime($e['created_at']) ?: 0) < $cut) continue;
            $e['form'] = cognitoFormLabel($form);
            $e['id'] = $form . ':' . $e['id'];
            unset($e['checked']);
            $entries[] = $e;
        }
    }
    usort($entries, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

    // Link to the client's file when an email in the entry matches an active client.
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

    return [
        'entries'      => $entries,
        'days'         => COGNITO_DAYS,
        'partial'      => $partial,
        'wait'         => $partial ? max(3, $wait) : 0,
        'failed_forms' => $failed,
        'fetched_at'   => $state['fetched_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
    ];
}

/**
 * The generated PDF of one submitted entry (Cognito's "Document" link on the
 * entry). Fetches the entry fresh so the link is current, then downloads the
 * PDF without sending our API key to the document link. Returns
 * ['name' => person name, 'pdf' => bytes]. Throws RuntimeException with a
 * message that is safe to show staff.
 *
 * $storedRow = the entry JSON we already have (pq_entries.raw_json): its
 * Document link is tried first, so a download normally costs no API call;
 * only if that fails is the entry fetched fresh (1 API call).
 */
function cognitoEntryPdf(string $form, int $number, ?array $storedRow = null): array
{
    if (!in_array($form, cognitoForms(), true) || $number < 1) throw new RuntimeException('Unknown PQ form entry.');

    if ($storedRow !== null) {
        try {
            return cognitoPdfFromRow($storedRow);
        } catch (RuntimeException $e) {
            // Link missing or expired: fall through to a fresh copy of the entry.
        }
    }

    $ch = cognitoCurl('forms/' . rawurlencode($form) . '/entries/' . $number);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 429) throw new RuntimeException('Cognito Forms is busy right now. Try again in a minute.');
    if ($code === 404) throw new RuntimeException('That entry no longer exists in Cognito Forms.');
    if ($code !== 200 || !is_array($row = json_decode((string)$raw, true))) {
        error_log('cognitoEntryPdf: entry http ' . $code);
        throw new RuntimeException('Could not reach Cognito Forms right now.');
    }
    return cognitoPdfFromRow($row);
}

/** Download the PDF behind an entry's Document link (no API key sent). */
function cognitoPdfFromRow(array $row): array
{
    $entry = cognitoEntryForJson($row);
    if (!$entry || $entry['stage'] !== 'submitted') throw new RuntimeException('This PQ has not been submitted yet, so there is no PDF.');

    $meta = is_array($row['Entry'] ?? null) ? $row['Entry'] : [];
    $links = [];
    foreach ($meta as $k => $v) {
        if (is_string($v) && preg_match('/^Document\d*$/', (string)$k)) $links[(string)$k] = $v;
    }
    ksort($links, SORT_NATURAL);
    $url = $links ? reset($links) : '';
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($url === '' || strncasecmp($url, 'https://', 8) !== 0 || ($host !== 'cognitoforms.com' && substr($host, -17) !== '.cognitoforms.com')) {
        throw new RuntimeException('Cognito Forms has no PDF for this entry.');
    }

    $dl = curl_init($url);
    curl_setopt_array($dl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_MAXFILESIZE    => 40 * 1024 * 1024,
    ]);
    $pdf = curl_exec($dl);
    $dlCode = (int)curl_getinfo($dl, CURLINFO_HTTP_CODE);
    curl_close($dl);
    if ($pdf === false || $dlCode !== 200 || strncmp($pdf, '%PDF', 4) !== 0) {
        error_log('cognitoEntryPdf: document http ' . $dlCode);
        throw new RuntimeException('Cognito Forms did not return the PDF. Try again in a moment.');
    }
    return ['name' => $entry['name'], 'pdf' => $pdf];
}
