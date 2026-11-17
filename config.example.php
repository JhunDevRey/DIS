<?php
// config.example.php — copy to config.php and fill in the real values.
// config.php is git-ignored: it holds passwords and API keys and must never be committed.
/**
 * config.php
 * Environment-aware config: auto-detects Laragon (local) vs Hostinger (live,
 * split across admin.duqueimmigration.ca and portal.duqueimmigration.ca).
 * Same switching pattern used in Duque Workforce Connect.
 *
 * IMPORTANT for the Hostinger deployment: this exact file (with the same
 * values filled in) needs to exist in BOTH the admin/ and portal/ document
 * roots, since they're separate subdomains. They share one database, so the
 * DB_* values are identical in both copies � just make sure you edit both
 * copies whenever you change something here, since they don't sync
 * automatically.
 */

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array($host, ['localhost', '127.0.0.1'])
    || strpos($host, '.test') !== false
    || strpos($host, 'localhost:') !== false;

if ($isLocal) {
    // ---- Laragon (local dev) ----
    define('DB_HOST', '');
    define('DB_NAME', '');
    define('DB_USER', '');
    define('DB_PASS', '');
    define('APP_URL', 'http://localhost/dispa');

    // Local dev keeps uploads inside the project folder � simplest for
    // Laragon since there's only one "site" involved.
    define('UPLOAD_DIR', __DIR__ . '/uploads');
} else {
    // ---- Hostinger (live) � admin.duqueimmigration.ca + portal.duqueimmigration.ca ----
    // Find these in hPanel ? Databases ? your database, or via
    // `cat ~/.my.cnf` over SSH if you've forgotten them.
    define('DB_HOST', '');
    define('DB_NAME', '');
    define('DB_USER', '');
    define('DB_PASS', '');
    define('APP_URL', $host === 'admin.duqueimmigration.ca'
        ? 'https://admin.duqueimmigration.ca'
        : 'https://portal.duqueimmigration.ca');

    // CRITICAL: this exact same absolute path must be set in BOTH the
    // admin/ and portal/ copies of this file � that's what lets a file
    // uploaded on one subdomain be visible on the other. Find your real
    // home directory path via SSH (`pwd` right after logging in � it'll
    // look like /home/uXXXXXXXX), then point this at a folder outside
    // both subdomains' public document roots, e.g.:
    //   /home/uXXXXXXXX/duque_uploads
    // Create that folder once via SSH (`mkdir ~/duque_uploads`) � it does
    // NOT need to be inside public_html, since every file is served through
    // the download/view API endpoints, never linked to directly.
    define('UPLOAD_DIR', '/home/u612178738/duque_uploads');
}

// Allowed upload types + max size (30 MB)
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']);
define('MAX_UPLOAD_BYTES', 30 * 1024 * 1024);

// ---- Outgoing email (processor upload notifications) ----
// Uses PHP's built-in mail() by default. On InfinityFree this usually works
// but often lands in spam because there's no SPF/DKIM for their shared IPs.
// mailer.php tries, in order: SMTP (below) → Brevo API (if configured) →
// plain mail() as a last resort. You only need to fill in ONE of SMTP or
// Brevo for reliable delivery — SMTP is the simpler path if you already
// have a mailbox for this.
define('MAIL_FROM_ADDRESS', 'notification@duqueimmigration.ca');
define('MAIL_FROM_NAME', 'Duque Immigration Admin');

// ---- SMTP credentials — sends via the dedicated notification@ mailbox ----
// From hPanel → Emails → notification@duqueimmigration.ca → Connect Devices.
// A separate, dedicated mailbox (not donotreply@) so nothing else already
// depends on its password — safe to reset any time without breaking
// anything else. This is what makes mail() → SMTP actually authenticate as
// a real mailbox instead of sending unauthenticated, which is what was
// causing emails to silently vanish for external providers even though
// duqueimmigration.ca's own SPF/DKIM/DMARC records are all correctly set up.
define('SMTP_HOST', '');
define('SMTP_PORT', 465); // 465 = SSL, 587 = STARTTLS — mailer.php picks the right encryption mode based on this
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');

// ---- Brevo (optional second option) ----
// Only used if SMTP above isn't configured. Leave as the placeholder
// value below if you're not using Brevo — mailer.php skips straight past
// it to plain mail() as the last resort.
define('BREVO_API_KEY', '');

// ---- Dropbox integration ----
// 1. Create an app at https://www.dropbox.com/developers/apps (Scoped access,
//    "Full Dropbox" or a specific folder, whichever matches how your team's
//    Dropbox is organized).
// 2. Under Permissions, enable: files.content.write, files.content.read,
//    files.metadata.write, files.metadata.read, sharing.write, sharing.read.
// 3. Generate a refresh token once (Dropbox's OAuth2 "authorization code"
//    flow with token_access_type=offline) and paste it below � the app
//    exchanges it for a short-lived access token on every request, the same
//    pattern DWC already uses for Google Drive.
define('DROPBOX_APP_KEY', '');
define('DROPBOX_APP_SECRET', '');
define('DROPBOX_REFRESH_TOKEN', '');
// The app builds the rest of the path automatically: ROOT/{year}/{MONTH}/
// {Client Name} - {Case Type} � e.g. "/MASTER LIST/2026/AUGUST/Jane Doe -
// Work Permit". Year and month folders are the real current year/month,
// created the first time they're needed; no need to pre-create them.
define('DROPBOX_ROOT_FOLDER', '');

// date_default_timezone_set('America/Winnipeg');
date_default_timezone_set('UTC');

define('ADMIN_APP_URL', 'https://admin.duqueimmigration.ca');
define('PORTAL_APP_URL', 'https://portal.duqueimmigration.ca');

// ---- Adobe PDF Embed API (Application Review file viewer only) ----
// Free tier. Renders PDFs through Adobe's own engine instead of the
// browser's — the reason this exists at all is that some official forms
// (e.g. IRCC's IMM forms) are built with Adobe's XFA form technology,
// which NO browser's built-in PDF viewer can render (shows a permanent
// "Please wait..." placeholder) — only Adobe's own engine can. Also
// brings Adobe's real annotation/markup tools and live form-field editing.
// Registered against the root domain (duqueimmigration.ca), which is why
// one Client ID works for both admin. and portal. subdomains — see
// https://developer.adobe.com/document-services/apis/pdf-embed/
// Honest limitation: any annotation/edit a person makes in the viewer
// lives only in that browser session unless a save-back integration is
// built separately — it is not currently persisted to this app's storage.
define('ADOBE_PDF_EMBED_CLIENT_ID', '');

// ---- Notion (Front Desk "Contracts" window, read-only) ----
// Leave NOTION_TOKEN empty to keep the window switched off. Create an
// internal integration at notion.so/profile/integrations (read content
// only), copy its secret here, then in Notion open the 🖊️ Contracts
// database → ••• → Connections → add the integration.
define('NOTION_TOKEN', '');

// ---- Calendly (Front Desk "Scheduling" window, read-only) ----
// Leave CALENDLY_TOKEN empty to keep the window switched off. In Calendly:
// Integrations & apps -> API & webhooks -> Personal access tokens -> create
// one and paste it here. Made by an organization owner/admin it shows the
// whole team's bookings; otherwise only that person's own. Scopes needed:
// users:read, scheduled_events:read (organizations:read optional).
// Bookings on several Calendly logins? Put every token here, separated by
// commas: define('CALENDLY_TOKEN', 'tokenForJoel,tokenForKyle');
define('CALENDLY_TOKEN', '');
// define('CALENDLY_TIMEZONE', 'America/Winnipeg'); // office time used for "Today"

// ---- Cognito Forms (Front Desk "PQ Forms" window, read-only) ----
// Leave COGNITO_API_KEY empty to keep the window switched off. In Cognito:
// click the organization name -> Settings -> Integrations -> New API Key
// (read access to entries only), and copy it here at once - it can't be
// shown again. COGNITO_PQ_FORM is the PQ form's internal name or number
// (the part of its entries link after cognitoforms.com/).
define('COGNITO_API_KEY', '');
define('COGNITO_PQ_FORM', '');

// ---- WhatsApp (via 360dialog, Coexistence with the office WhatsApp Business app) ----
// Leave WA_API_KEY empty to keep WhatsApp switched off — the Inbox then
// works exactly as before. See docs/WHATSAPP_SETUP.md.
//   WA_API_KEY          the 360dialog API key for the office number (Hub -> API keys)
//   WA_BUSINESS_NUMBER  the office number, digits only, e.g. 12045551234
//   WA_WEBHOOK_TOKEN    32+ random characters; 360dialog sends it with every
//                       webhook call (cron/wa_set_webhook.php registers it)
//   WA_NOTICE_TEMPLATE  approved template sent instead of the message
//                       when the 24h reply window has closed
//                       (body with one variable: {{1}} = client first name)
define('WA_API_KEY', '');
define('WA_BUSINESS_NUMBER', '');
define('WA_WEBHOOK_TOKEN', '');
define('WA_NOTICE_TEMPLATE', '');      // e.g. portal_message_notice
define('WA_NOTICE_TEMPLATE_LANG', 'en');

// UTC is the baseline everywhere now — both here and in MySQL's own
// session timezone (see includes/db.php) — NOT because the app shows UTC
// to anyone, but because it's the one unambiguous reference point every
// stored/calculated timestamp shares. The actual display timezone is
// resolved per-viewer, client-side, in the browser (see the
// auto-local-time handling in js/ui-kit.js and localTimeSpan() in
// includes/functions.php) — so a client logging in from Manila and a
// staff member in Winnipeg each see times in their own local zone,
// automatically, with no manual setting needed anywhere. This was
// deliberately switched from a Winnipeg-only baseline (an earlier,
// simpler version of this app just fixed everything to Winnipeg time
// for everyone) once the app was going live as the actual CRM with an
// international client base.

date_default_timezone_set('UTC');

// ---- Security ----
// Encrypts staff two-step sign-in secrets. Required for two-step sign-in.
// Generate once and never change it (changing it switches every staff
// member's two-step sign-in off until they set it up again):
//   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
define('SECURITY_KEY', '');
// true = every staff account must turn on two-step sign-in before using
// the portal (they're sent to My Profile to set it up). Recommended once
// everyone has their own account.
define('ADMIN_REQUIRE_2FA', false);
// Only if the site sits behind a proxy/CDN that hides the visitor's IP:
// the header it sets and the start of its own addresses. Leave empty
// otherwise. Never trust X-Forwarded-For without this, since anyone can send it.
// define('TRUSTED_PROXY_IP_HEADER', 'HTTP_X_FORWARDED_FOR');
// define('TRUSTED_PROXY_PREFIXES', ['10.', '172.16.']);
