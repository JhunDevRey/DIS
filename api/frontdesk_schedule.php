<?php
/**
 * api/frontdesk_schedule.php
 * Front Desk "Scheduling" window (staff only).
 *   GET            -> Calendly bookings for today + the next 7 days (cached ~60s)
 *   GET ?fresh=1   -> same, skipping the cache (Refresh button)
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/calendly.php';
requireAdmin();

if (!calendlyEnabled()) {
    jsonResponse(false, 'Calendly is not connected yet.', ['enabled' => false]);
}

try {
    $out = calendlyUpcoming(!empty($_GET['fresh']));
} catch (Throwable $e) {
    error_log('frontdesk_schedule: ' . $e->getMessage());
    $msg = $e->getCode() === 401 || $e->getCode() === 403
        ? 'Calendly refused the request. Check the token in config.php.'
        : 'Could not load bookings from Calendly right now.';
    jsonResponse(false, $msg, ['enabled' => true]);
}

jsonResponse(true, '', ['enabled' => true] + $out);
