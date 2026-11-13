<?php
/**
 * api/frontdesk_inquiries.php
 * Front Desk "Notion · Inquiries" window (staff only).
 *   GET            -> inquiries from the last 7 days (cached ~60s)
 *   GET ?fresh=1   -> same, skipping the cache (Refresh button)
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notion.php';
requireAdmin();

if (!notionEnabled()) {
    jsonResponse(false, 'Notion is not connected yet.', ['enabled' => false]);
}

try {
    $out = notionRecentInquiries(7, !empty($_GET['fresh']));
} catch (Throwable $e) {
    error_log('frontdesk_inquiries: ' . $e->getMessage());
    $msg = $e->getCode() === 404 || $e->getCode() === 401
        ? 'Notion refused the request. Check the token, and that the Inquiries database is shared with the integration.'
        : 'Could not load inquiries from Notion right now.';
    jsonResponse(false, $msg, ['enabled' => true]);
}

jsonResponse(true, '', ['enabled' => true, 'notion_url' => notionInquiriesUrl()] + $out);
