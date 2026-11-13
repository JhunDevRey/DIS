<?php
/**
 * cron/wa_set_webhook.php — run ONCE from SSH right after the number is
 * connected in 360dialog (history import only arrives in the first 24h):
 *
 *   php cron/wa_set_webhook.php
 *
 * Tells 360dialog to send this number's WhatsApp events to
 * api/whatsapp_webhook.php, with WA_WEBHOOK_TOKEN in a header so the
 * webhook can reject anything that didn't come from 360dialog.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/whatsapp.php';

if (!waEnabled() || !defined('WA_WEBHOOK_TOKEN') || strlen(WA_WEBHOOK_TOKEN) < 32) {
    fwrite(STDERR, "Set WA_API_KEY and WA_WEBHOOK_TOKEN (32+ random characters) in config.php first.\n");
    exit(1);
}
$url = 'https://admin.duqueimmigration.ca/api/whatsapp_webhook.php';
try {
    $out = waApi('POST', 'v1/configs/webhook', [
        'url'     => $url,
        'headers' => ['X-DIS-Webhook-Token' => WA_WEBHOOK_TOKEN],
    ]);
    echo "Webhook set to $url\n" . json_encode($out) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
