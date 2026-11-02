<?php
/**
 * cron/daily_digest.php
 *
 * Sends every client with pending queued notifications one consolidated
 * "daily update" email each, then marks those events as sent. Meant to
 * run once every 24 hours via a scheduled cron job — see the README
 * section "Setting up the daily digest cron job" for the exact
 * Hostinger setup.
 *
 * Processors are NOT part of this anymore — they now get a real-time,
 * threaded email the moment something happens (see
 * notifyProcessorRealtime() in includes/mailer.php), not a daily batch.
 * This script only handles the client-facing digest.
 *
 * Deliberately restricted to CLI execution only (php cron/daily_digest.php)
 * — never triggered by a web request, so there's no need for a secret
 * token or auth check the way a web-facing endpoint would need. This is
 * simpler and safer: nothing about this script is reachable from a browser.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script only runs from the command line (cron), not the web.');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

$startedAt = date('Y-m-d H:i:s');
$clientResults = sendDailyDigests();

echo "[{$startedAt}] Daily digest run complete.\n";
echo "  Clients notified:    {$clientResults['clients_notified']}\n";
echo "  Client events sent:  {$clientResults['events_sent']}\n";
echo "  Client emails failed: {$clientResults['emails_failed']}\n";

// Non-zero exit code if anything failed, so Hostinger's cron logs (or any
// monitoring you set up) can flag it — a run with zero failures exits 0.
exit($clientResults['emails_failed'] > 0 ? 1 : 0);
