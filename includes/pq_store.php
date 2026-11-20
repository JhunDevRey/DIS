<?php
/**
 * includes/pq_store.php
 * PQ entries stored in MySQL (table pq_entries, sql/migration_pq_entries.sql)
 * so the Front Desk "PQ Forms" card never calls Cognito on page load.
 *
 * How entries get in:
 *   - api/cognito_pq_webhook.php: Cognito "Post JSON Data" on submit /
 *     update / delete, and Make "PQ BOT - Sender" right after it creates an
 *     entry (Cognito sends nothing for API-created entries).
 *   - pqSync() (cron/pq_backfill.php, and the owner-only Resync button):
 *     the only code here that uses the Cognito API, with a request count.
 *
 * Entry parsing reuses includes/cognito.php (cognitoEntryForJson() etc.),
 * so webhook payloads and API answers are read the same way.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cognito.php';

define('PQ_FOLLOW_UP_DAYS', 3);        // same as PQ_CHASE_DAYS in js/frontdesk.js
define('PQ_RESYNC_MIN_SECONDS', 3600); // Resync button: at most once an hour
define('PQ_LOG_MAX_BYTES', 1048576);   // webhook log rotates at 1 MB

/** True while the card should still use the old live-API code (config switch for rollback). */
function pqUseLiveApi(): bool
{
    return defined('COGNITO_PQ_LIVE_API') && COGNITO_PQ_LIVE_API;
}

/** The configured form whose internal name matches $name (case-insensitive), or null. */
function pqFormKey(?string $name): ?string
{
    $name = trim((string)$name);
    if ($name === '') return null;
    foreach (cognitoForms() as $f) {
        if (strcasecmp($f, $name) === 0) return $f;
    }
    return null;
}

/** Entry number from an entry/webhook/Make payload ("Number", Entry.Number, or the "<form>-<n>" Id). */
function pqEntryNumber(array $row): ?int
{
    $meta = is_array($row['Entry'] ?? null) ? $row['Entry'] : [];
    foreach ([$meta['Number'] ?? null, $row['Number'] ?? null, $row['number'] ?? null] as $v) {
        if (is_int($v) && $v > 0) return $v;
        if (is_string($v) && ctype_digit($v) && (int)$v > 0) return (int)$v;
    }
    foreach ([$row['Id'] ?? null, $row['id'] ?? null] as $v) {
        if (is_string($v) && preg_match('/^\d+-(\d+)$/', $v, $m)) return (int)$m[1];
    }
    return null;
}

/** A Cognito date string as a UTC 'Y-m-d H:i:s' (null if missing/unreadable). */
function pqUtc($v): ?string
{
    if (!is_string($v) || trim($v) === '') return null;
    try {
        return (new DateTimeImmutable($v))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Save one full entry (webhook payload or API answer).
 * $authoritative = fetched live from the API just now: always wins. A
 * webhook payload only replaces the stored copy when it is not older.
 * Returns 'inserted' | 'updated' | 'stale' | 'skipped'.
 */
function pqUpsert(string $form, array $row, string $source, bool $authoritative = false): string
{
    $number = pqEntryNumber($row);
    $e = cognitoEntryForJson($row);
    if ($number === null || !$e) return 'skipped';

    $created = pqUtc($e['created_at']);
    $updated = pqUtc($e['updated_at']) ?? $created;
    $submitted = pqUtc($e['submitted_at']);
    $followUp = $e['stage'] === 'waiting' && $created !== null
        && strtotime($created . ' UTC') < time() - PQ_FOLLOW_UP_DAYS * 86400;
    $url = is_string($e['url']) ? mb_substr($e['url'], 0, 500) : null;

    $vals = [
        'form_name'      => cognitoFormLabel($form),
        'entry_number'   => $number,
        'client_name'    => mb_substr($e['name'], 0, 255),
        'email'          => $e['emails'][0] ?? null,
        'emails'         => json_encode($e['emails']),
        'status'         => mb_substr($e['status'], 0, 40),
        'stage'          => $e['stage'],
        'follow_up'      => $followUp ? 1 : 0,
        'admin_url'      => $url,
        'date_created'   => $created,
        'date_submitted' => $submitted,
        'date_updated'   => $updated,
        'raw_json'       => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'source'         => $source,
    ];

    $db = getDb();
    $stmt = $db->prepare('SELECT id, date_updated, deleted_at FROM pq_entries WHERE form_id = ? AND entry_id = ?');
    $stmt->execute([$form, (string)$number]);
    $have = $stmt->fetch();

    if ($have) {
        if (!$authoritative && $have['date_updated'] !== null && $updated !== null && $updated < $have['date_updated']) {
            return 'stale'; // an older update arrived after a newer one
        }
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals)));
        // Seen alive again (live fetch, or an update newer than the delete) -> not deleted.
        $undelete = $authoritative || ($have['deleted_at'] !== null && $updated !== null && $updated > $have['deleted_at']);
        $db->prepare("UPDATE pq_entries SET $set" . ($undelete ? ', deleted_at = NULL' : '') . ' WHERE id = ?')
           ->execute([...array_values($vals), $have['id']]);
        return 'updated';
    }

    $cols = array_merge(['form_id', 'entry_id'], array_keys($vals));
    $sql = 'INSERT INTO pq_entries (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    try {
        $db->prepare($sql)->execute([$form, (string)$number, ...array_values($vals)]);
    } catch (PDOException $ex) {
        // Two deliveries of the same new entry at once: the other one won, update instead.
        if ($ex->getCode() === '23000') return pqUpsert($form, $row, $source, $authoritative);
        throw $ex;
    }
    return 'inserted';
}

/** Soft-delete one entry. Returns true if a row was marked. */
function pqSoftDelete(string $form, int $number): bool
{
    $stmt = getDb()->prepare('UPDATE pq_entries SET deleted_at = UTC_TIMESTAMP() WHERE form_id = ? AND entry_id = ? AND deleted_at IS NULL');
    $stmt->execute([$form, (string)$number]);
    return $stmt->rowCount() > 0;
}

/** Append one line to the webhook log (outside the web root; no client details). */
function pqLog(string $line): void
{
    $dir = UPLOAD_DIR . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/cognito_pq_webhook.log';
    if (is_file($file) && filesize($file) > PQ_LOG_MAX_BYTES) @rename($file, $file . '.1');
    @file_put_contents($file, gmdate('Y-m-d H:i:s') . 'Z ' . str_replace(["\r", "\n"], ' ', $line) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Everything the Front Desk card needs, from MySQL only (same JSON shape the
 * old live-API version returned, so js/frontdesk.js renders it unchanged).
 */
function pqEntriesFromDb(): array
{
    $db = getDb();
    // Keep the stored flag honest (cheap: a few dozen rows).
    $db->prepare("UPDATE pq_entries SET follow_up = (stage = 'waiting' AND date_created < UTC_TIMESTAMP() - INTERVAL ? DAY)
                  WHERE deleted_at IS NULL AND follow_up <> (stage = 'waiting' AND date_created < UTC_TIMESTAMP() - INTERVAL ? DAY)")
       ->execute([PQ_FOLLOW_UP_DAYS, PQ_FOLLOW_UP_DAYS]);

    $stmt = $db->prepare("SELECT form_id, form_name, entry_number, client_name, emails, status, stage, follow_up, admin_url,
                                 date_created, date_submitted, date_updated
                          FROM pq_entries
                          WHERE deleted_at IS NULL AND date_created >= UTC_TIMESTAMP() - INTERVAL ? DAY
                          ORDER BY date_created DESC");
    $stmt->execute([COGNITO_DAYS]);
    $forms = cognitoForms();
    $iso = fn(?string $d) => $d === null ? null : str_replace(' ', 'T', $d) . 'Z';

    $entries = [];
    $all = [];
    foreach ($stmt->fetchAll() as $r) {
        if (!in_array($r['form_id'], $forms, true)) continue; // form taken out of config
        $emails = json_decode((string)$r['emails'], true) ?: [];
        $all = array_merge($all, $emails);
        $entries[] = [
            'id'           => $r['form_id'] . ':' . $r['entry_number'],
            'number'       => (int)$r['entry_number'],
            'name'         => $r['client_name'] !== '' ? $r['client_name'] : 'Entry #' . $r['entry_number'],
            'status'       => $r['status'],
            'stage'        => $r['stage'],
            'follow_up'    => (int)$r['follow_up'] === 1,
            'url'          => $r['admin_url'],
            'created_at'   => $iso($r['date_created']),
            'submitted_at' => $iso($r['date_submitted']),
            'updated_at'   => $iso($r['date_updated']),
            'form'         => $r['form_name'] !== '' ? $r['form_name'] : cognitoFormLabel($r['form_id']),
            '_emails'      => $emails,
        ];
    }

    // Link to the client's file when an email in the entry matches an active client.
    $clients = [];
    if ($all) {
        $all = array_values(array_unique($all));
        $in = implode(',', array_fill(0, count($all), '?'));
        $q = $db->prepare("SELECT id, LOWER(email) AS email FROM clients WHERE is_active = 1 AND LOWER(email) IN ($in)");
        $q->execute($all);
        foreach ($q->fetchAll() as $c) $clients[$c['email']] = (int)$c['id'];
    }
    foreach ($entries as &$e) {
        $e['client_id'] = null;
        foreach ($e['_emails'] as $one) {
            if (isset($clients[$one])) { $e['client_id'] = $clients[$one]; break; }
        }
        unset($e['_emails']);
    }
    unset($e);

    return [
        'entries'      => $entries,
        'days'         => COGNITO_DAYS,
        'partial'      => false,
        'wait'         => 0,
        'failed_forms' => [],
        'fetched_at'   => gmdate('Y-m-d\TH:i:s\Z'),
        'last_resync'  => pqLastResync(),
    ];
}

/* ---------------- Sync from the Cognito API (backfill / resync) ---------------- */

/** Requests actually sent to Cognito during this PHP run. */
function pqApiCalls(): int
{
    return (int)($GLOBALS['__cognito_spent'] ?? 0);
}

/**
 * GET these entry numbers of one form. Returns [found => [n => raw row],
 * missing => [n...] (404 = doesn't exist), unanswered => [n...]].
 * In CLI mode a throttle (429) waits a minute and retries, up to 3 times.
 */
function pqFetch(string $form, array $numbers, bool $cli): array
{
    $path = fn(int $n) => 'forms/' . rawurlencode($form) . '/entries/' . $n;
    $found = [];
    $missing = [];
    $todo = array_values($numbers);
    for ($try = 0; $todo && $try < 4; $try++) {
        if ($try > 0) {
            if (!$cli) break;
            fwrite(STDERR, "  Cognito asked us to slow down; waiting 65 s…\n");
            sleep(65);
        }
        $GLOBALS['__cognito_throttled'] = false;
        $res = cognitoGetMany(array_map($path, $todo));
        $again = [];
        foreach ($todo as $n) {
            [$s, $d] = $res[$path($n)] ?? [-1, null];
            if ($s === 401 || $s === 403) throw new RuntimeException('Cognito Forms refused the API key.', $s);
            if ($s === 200 && is_array($d)) $found[$n] = $d;
            elseif ($s === 404) $missing[] = $n;
            else $again[] = $n;
        }
        $todo = $again;
        if ($todo && cognitoBudget() < count($todo)) break; // out of budget: stop, report
    }
    return ['found' => $found, 'missing' => $missing, 'unanswered' => $todo];
}

/** Highest entry number we already know for a form (DB, else the old live-API state file), or null. */
function pqKnownMax(string $form): ?int
{
    $stmt = getDb()->prepare('SELECT MAX(entry_number) FROM pq_entries WHERE form_id = ?');
    $stmt->execute([$form]);
    $db = $stmt->fetchColumn();
    $file = UPLOAD_DIR . '/cache/cognito_pq.json';
    $st = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $old = $st['forms'][$form]['max'] ?? null;
    $vals = array_filter([$db !== null && $db !== false ? (int)$db : null, is_int($old) ? $old : null], fn($v) => $v !== null);
    return $vals ? max($vals) : null;
}

/**
 * Pull entries from Cognito into pq_entries.
 *   $mode 'backfill': new entries past the known max, then walk back to $days ago.
 *   $mode 'resync'  : new entries past the known max + re-check every stored
 *                     Waiting entry (404 => soft-deleted). Cheapest catch-up.
 * $budget caps API requests for this run. Returns stats incl. 'api_calls'.
 */
function pqSync(string $mode, int $days, int $budget, bool $cli, ?callable $say = null, ?array $forms = null): array
{
    $say = $say ?? function (string $s): void {};
    @set_time_limit($cli ? 0 : 120);
    $GLOBALS['__cognito_spent'] = 0;
    cognitoBudget($budget);
    $cut = time() - $days * 86400;
    $stats = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'unanswered' => 0, 'forms' => []];
    $count = function (string $r) use (&$stats) { if (isset($stats[$r])) $stats[$r]++; };

    foreach ($forms ?? cognitoForms() as $form) {
        $before = pqApiCalls();
        $max = pqKnownMax($form);
        if ($max === null) {
            $say("$form: finding the newest entry number…");
            $max = cognitoFindMax($form); // ~15-45 requests, only when nothing is known
        }

        // New entries past the highest known number, 4 at a time.
        for ($round = 0; $round < 50; $round++) {
            $r = pqFetch($form, range($max + 1, $max + COGNITO_PARALLEL), $cli);
            foreach ($r['found'] as $n => $row) $count(pqUpsert($form, $row, $mode, true));
            $stats['unanswered'] += count($r['unanswered']);
            if (!$r['found'] || $r['unanswered']) break;
            $max = max(array_keys($r['found']));
        }

        if ($mode === 'backfill') {
            // Walk back from the newest until entries are older than $days.
            // Entries already stored are not fetched again (a re-run after
            // --max-calls only pays for what is still missing).
            $stmt = getDb()->prepare('SELECT entry_number, date_created FROM pq_entries WHERE form_id = ?');
            $stmt->execute([$form]);
            $stored = [];
            foreach ($stmt->fetchAll() as $s) $stored[(int)$s['entry_number']] = $s['date_created'] ? strtotime($s['date_created'] . ' UTC') : 0;
            $hi = $max;
            $empty = 0;
            while ($hi >= 1 && cognitoBudget() >= 1) {
                $lo = max(1, $hi - COGNITO_PARALLEL + 1);
                $oldest = null;
                $want = [];
                foreach (range($hi, $lo) as $n) {
                    if (isset($stored[$n])) $oldest = $oldest === null ? $stored[$n] : min($oldest, $stored[$n]);
                    else $want[] = $n;
                }
                $r = $want ? pqFetch($form, $want, $cli) : ['found' => [], 'missing' => [], 'unanswered' => []];
                $anyHere = $r['found'] || count($want) < $hi - $lo + 1; // fetched or already stored
                foreach ($r['found'] as $n => $row) {
                    $res = pqUpsert($form, $row, 'backfill', true);
                    $count($res);
                    $e = cognitoEntryForJson($row);
                    $t = $e ? (strtotime($e['created_at']) ?: 0) : 0;
                    $oldest = $oldest === null ? $t : min($oldest, $t);
                }
                $stats['unanswered'] += count($r['unanswered']);
                if ($r['unanswered']) break;
                $empty = $anyHere ? 0 : $empty + 1;
                if (($oldest !== null && $oldest < $cut) || $empty >= 3) break;
                $hi = $lo - 1;
            }
        } else {
            // Re-check what we think is still Waiting (catches missed submits/deletes).
            $stmt = getDb()->prepare("SELECT entry_number FROM pq_entries WHERE form_id = ? AND stage = 'waiting'
                                      AND deleted_at IS NULL AND date_created >= UTC_TIMESTAMP() - INTERVAL ? DAY");
            $stmt->execute([$form, $days]);
            $nums = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($nums) {
                $r = pqFetch($form, $nums, $cli);
                foreach ($r['found'] as $n => $row) $count(pqUpsert($form, $row, 'resync', true));
                foreach ($r['missing'] as $n) if (pqSoftDelete($form, $n)) $stats['deleted']++;
                $stats['unanswered'] += count($r['unanswered']);
            }
        }
        $stats['forms'][$form] = pqApiCalls() - $before;
        $say(sprintf('%s: %d API call(s), newest #%d', $form, $stats['forms'][$form], $max));
    }
    $stats['api_calls'] = pqApiCalls();
    return $stats;
}

/* ---------------- Resync rate limit (owner button) ---------------- */

function pqResyncFile(): string
{
    $dir = UPLOAD_DIR . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/pq_resync.json';
}

/** ['at' => ISO UTC, 'api_calls' => n, 'by' => name] of the last resync, or null. */
function pqLastResync(): ?array
{
    $f = pqResyncFile();
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) && isset($d['ts']) ? ['at' => gmdate('Y-m-d\TH:i:s\Z', (int)$d['ts']), 'api_calls' => (int)($d['api_calls'] ?? 0), 'by' => (string)($d['by'] ?? '')] : null;
}

/**
 * Resync once, unless one ran in the last hour (or is running now).
 * Returns stats, or throws RuntimeException with a message for staff.
 */
function pqResyncLimited(string $by, bool $cli = false, ?callable $say = null): array
{
    $f = pqResyncFile();
    $lock = fopen($f . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('A resync is already running. Try again in a minute.');
    try {
        $last = pqLastResync();
        $ago = $last ? time() - strtotime($last['at']) : PHP_INT_MAX;
        if ($ago < PQ_RESYNC_MIN_SECONDS) {
            $mins = (int)ceil((PQ_RESYNC_MIN_SECONDS - $ago) / 60);
            throw new RuntimeException("Resync was run " . (int)floor($ago / 60) . " min ago. You can run it again in $mins min.");
        }
        $stats = pqSync('resync', COGNITO_DAYS, 200, $cli, $say);
        @file_put_contents($f, json_encode(['ts' => time(), 'api_calls' => $stats['api_calls'], 'by' => $by]), LOCK_EX);
        return $stats;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
