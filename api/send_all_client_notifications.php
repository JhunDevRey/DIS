<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

// Manual override for staff who don't want to wait for the daily cron —
// sends everything currently queued right now. Uses the exact same
// function the cron job calls, so behavior is identical either way.
$results = sendDailyDigests();

jsonResponse(true, 'Sent.', $results);
