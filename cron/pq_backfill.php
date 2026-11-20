<?php
/**
 * cron/pq_backfill.php — fill pq_entries from the Cognito API. Run from SSH:
 *
 *   php cron/pq_backfill.php                 one-time backfill: last 60 days, all PQ forms
 *   php cron/pq_backfill.php --days=120      further back (more API calls)
 *   php cron/pq_backfill.php --form=General  one form only (testing)
 *   php cron/pq_backfill.php --resync        catch-up: new entries + re-check Waiting
 *                                            (same as the owner Resync button, also
 *                                            limited to once an hour)
 *   php cron/pq_backfill.php --max-calls=300 stop after this many API calls (default 600)
 *
 * Request count is kept low: it starts from the newest entry number already
 * known (pq_entries, or the old live-API state file), so it does not have to
 * search for it, fetches 4 entries at a time, and stops as soon as entries
 * are older than --days. Prints how many API calls it made.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/pq_store.php';

$opt = getopt('', ['days::', 'form::', 'resync', 'max-calls::', 'help']);
if (isset($opt['help'])) {
    echo preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__), 2)[1], 2)[0]);
    exit(0);
}
if (!cognitoEnabled()) {
    fwrite(STDERR, "Set COGNITO_API_KEY and COGNITO_PQ_FORM in config.php first.\n");
    exit(1);
}
try {
    getDb()->query('SELECT 1 FROM pq_entries LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Table pq_entries is missing — run sql/migration_pq_entries.sql first.\n");
    exit(1);
}

$days = max(1, (int)($opt['days'] ?? COGNITO_DAYS));
$maxCalls = max(10, (int)($opt['max-calls'] ?? 600));
$say = function (string $s): void { echo $s, "\n"; };

if (isset($opt['form'])) {
    $one = pqFormKey((string)$opt['form']);
    if ($one === null) {
        fwrite(STDERR, "Unknown form '{$opt['form']}'. Configured: " . implode(', ', cognitoForms()) . "\n");
        exit(1);
    }
}

$start = microtime(true);
try {
    if (isset($opt['resync'])) {
        if (isset($one)) {
            fwrite(STDERR, "--resync always covers every form.\n");
            exit(1);
        }
        $stats = pqResyncLimited('CLI', true, $say);
    } else {
        $stats = pqSync('backfill', $days, $maxCalls, true, $say, isset($one) ? [$one] : null);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    fwrite(STDERR, 'API calls made before the failure: ' . pqApiCalls() . "\n");
    exit(1);
}

printf("\nDone in %.1f s. New: %d, updated: %d, removed: %d, not answered (busy): %d\n",
    microtime(true) - $start, $stats['inserted'], $stats['updated'], $stats['deleted'], $stats['unanswered']);
printf("Cognito API calls made: %d\n", $stats['api_calls']);
if ($stats['api_calls'] >= $maxCalls && !isset($opt['resync'])) {
    echo "Stopped at --max-calls=$maxCalls. Run again to continue (it picks up where the table ends).\n";
}
