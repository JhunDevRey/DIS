<?php
/**
 * includes/functions.php
 * Small shared helpers used across admin/, portal/, and api/.
 */

function jsonResponse(bool $success, string $message = '', array $extra = []): void
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

/**
 * Returns this session's CSRF token, generating one the first time it's
 * needed. One token per session (not regenerated per-request/per-page) —
 * that's deliberate, since a per-request token would break having the
 * same page open in two tabs, or a page left open for a while before an
 * action is taken on it.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Confirms a state-changing request actually came from this app's own
 * pages, not a form/script on some other site silently riding the
 * user's logged-in session (the standard CSRF attack). Accepts the
 * token from either the X-CSRF-Token header (how every fetch() call in
 * this app sends it, via the wrapper in ui-kit.js) or a csrf_token POST
 * field, so it works the same way regardless of how a given request
 * happens to be built. Ends the request with a 403 if it's missing or
 * doesn't match — never silently continues.
 */
function verifyCsrfToken(): void
{
    $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || $submitted === '' || !hash_equals($expected, $submitted)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session security check failed — please refresh the page and try again.']);
        exit;
    }
}

/**
 * Best-effort real client IP for login rate limiting only — NOT treated
 * as a trusted security boundary anywhere else, since X-Forwarded-For
 * can be spoofed by the client itself if there's no proxy actually
 * setting it. Good enough to slow down a brute-force script, which is
 * all this is for.
 */
function getClientIp(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

/**
 * Login rate limiting — counts recent failed attempts for this IP within
 * a rolling window and blocks further tries once the threshold is hit.
 * The table is created on first use (CREATE TABLE IF NOT EXISTS) rather
 * than requiring a separate manual migration step, specifically so this
 * can't end up silently not working because a migration was missed —
 * exactly the kind of gap that's caused real deployment issues earlier
 * in this project.
 */
function ensureLoginAttemptsTable(PDO $db): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(100) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_identifier_time (identifier, attempted_at)
        )
    ");
    $ensured = true;
}

function isLoginRateLimited(string $loginType): bool
{
    $db = getDb();
    ensureLoginAttemptsTable($db);
    $identifier = $loginType . ':' . getClientIp();
    $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)');
    $stmt->execute([$identifier]);
    return (int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function recordFailedLogin(string $loginType): void
{
    $db = getDb();
    ensureLoginAttemptsTable($db);
    $identifier = $loginType . ':' . getClientIp();
    $db->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')->execute([$identifier]);
}

function clearLoginAttempts(string $loginType): void
{
    $db = getDb();
    ensureLoginAttemptsTable($db);
    $identifier = $loginType . ':' . getClientIp();
    $db->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([$identifier]);
}

function statusMeta(string $status): array
{
    $map = [
        'pending'        => ['label' => 'Awaiting Upload', 'class' => 'status-pending'],
        'received'       => ['label' => 'Received',        'class' => 'status-received'],
        'needs_revision' => ['label' => 'Needs Revision',  'class' => 'status-revision'],
        'accepted'       => ['label' => 'Accepted',        'class' => 'status-accepted'],
    ];
    return $map[$status] ?? ['label' => ucfirst($status), 'class' => ''];
}

/**
 * Display metadata for an "Application Review" field's review_status —
 * separate from statusMeta() above (which describes the ordinary
 * upload/accept lifecycle) because a review field's document is provided
 * by staff, not the client, so its states describe the client's response
 * instead: still waiting on them, confirmed correct, or flagged with an
 * issue to fix. $forStaff swaps in labels phrased for the staff side
 * ("Client Reported Issue") rather than the client's own ("Issue Reported"),
 * since the same three states read differently depending on who's looking.
 */
function reviewStatusMeta(?string $reviewStatus, bool $forStaff = false): array
{
    $client = [
        'awaiting_client'   => ['label' => 'Awaiting Your Review', 'class' => 'status-review-pending'],
        'confirmed'         => ['label' => 'Confirmed Correct',    'class' => 'status-review-confirmed'],
        'changes_requested' => ['label' => 'Issue Reported',       'class' => 'status-review-flagged'],
    ];
    $staff = [
        'awaiting_client'   => ['label' => 'Awaiting Client Review', 'class' => 'status-review-pending'],
        'confirmed'         => ['label' => 'Client Confirmed Correct', 'class' => 'status-review-confirmed'],
        'changes_requested' => ['label' => 'Client Reported an Issue', 'class' => 'status-review-flagged'],
    ];
    $map = $forStaff ? $staff : $client;
    return $map[$reviewStatus] ?? $map['awaiting_client'];
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Renders text safely, with links shown as real clickable elements
 * instead of dead text — e.g. a client pasting a link to a document
 * scan hosted elsewhere, or staff sharing a reference link. Used for
 * both comments and document/checklist instructions.
 *
 * Handles two forms of link:
 *   - A raw URL typed directly (http://... or https://...) — shown
 *     as-is, the URL itself is the display text.
 *   - A custom-labeled link, stored as "[display text](url)" — e.g.
 *     "[Sample Link](https://sample.com)" renders as a link reading
 *     "Sample Link" that still goes to the real URL. This is what the
 *     "edit link" pencil (see below) actually writes back to storage —
 *     staff never need to type this format by hand, but it's a plain,
 *     well-established, easy-to-read markdown convention if anyone
 *     ever does.
 *
 * Order matters here for safety: the ENTIRE text is HTML-escaped first
 * (h()), and only THEN is the already-escaped text scanned for link
 * patterns to wrap in <a> tags. This makes it impossible for someone to
 * inject raw HTML/script through a comment or instructions field —
 * anything that isn't a detected link stays as inert escaped text no
 * matter what it contains. Square brackets and parentheses aren't
 * HTML-special characters, so htmlspecialchars() passes them through
 * unchanged — the markdown-style pattern still matches correctly
 * against the already-escaped text.
 *
 * $editType + $editId (e.g. 'comment' + the comment's own id, or
 * 'instructions' + the document_request's own id) turn on the hover
 * pencil icon that opens the small "edit link" popup — pass both to
 * make links in this text editable (staff-facing admin views only);
 * leave both blank to render clean, non-editable links (client-facing
 * portal views) — same underlying function either way, just with or
 * without the editing affordance layered on top.
 */
function linkifyText(string $text, string $editType = '', ?int $editId = null): string
{
    $escaped = h($text);

    $pattern = '/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)|(https?:\/\/[^\s<]+)/i';

    $linked = preg_replace_callback(
        $pattern,
        function (array $m) use ($editType, $editId): string {
            $trailing = '';
            if (isset($m[2]) && $m[2] !== '') {
                // Custom-labeled markdown-style link — $m[1]/$m[2] are
                // already HTML-escaped, being substrings of $escaped.
                $displayText = $m[1];
                $url = $m[2];
                $rawMatch = $m[0];
            } else {
                // Plain raw URL — don't swallow trailing sentence
                // punctuation into the link itself ("...example.com."
                // shouldn't include that trailing period in the link).
                $url = rtrim($m[3], '.,;:!?)');
                $trailing = substr($m[3], strlen($url));
                $displayText = $url;
                $rawMatch = $url;
            }

            $linkHtml = '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $displayText . '</a>';

            if ($editType !== '' && $editId !== null) {
                // $rawMatch/$displayText/$url are already escaped (all
                // substrings of $escaped) — safe to place directly into
                // attribute values without re-escaping, which would
                // double-escape them.
                $linkHtml = '<span class="editable-link" data-raw="' . $rawMatch . '" data-text="' . $displayText . '" data-url="' . $url . '" data-edit-type="' . h($editType) . '" data-edit-id="' . $editId . '">'
                    . $linkHtml
                    . '<button type="button" class="edit-link-btn" title="Edit link" onclick="openLinkEditor(this, event)"><i class="fa-solid fa-pen"></i></button>'
                    . '</span>';
            }

            return $linkHtml . $trailing;
        },
        $escaped
    );

    return nl2br($linked);
}

/**
 * Kept as a thin alias — every existing call site used this name before
 * linkifyText() was generalized to also cover instructions fields, not
 * just comments.
 */
function linkifyComment(string $text): string
{
    return linkifyText($text);
}

/**
 * Renders a stored UTC datetime as a span JavaScript converts to the
 * VIEWER'S OWN local timezone on page load (see the auto-local-time
 * handler in js/ui-kit.js) — this is what makes dates/times shown in the
 * app adapt automatically to wherever someone is actually logging in
 * from, rather than always showing one fixed timezone to everyone.
 *
 * $intlOptions are passed straight through to JavaScript's
 * Intl.DateTimeFormat — e.g. ['month'=>'short','day'=>'numeric','year'=>'numeric']
 * for "Aug 17, 2026", or add 'hour'=>'numeric','minute'=>'2-digit' for a
 * time too. See existing call sites for real examples.
 *
 * Renders a UTC-labeled fallback inline (shown only if JavaScript is
 * disabled or hasn't run yet) so nothing is ever blank.
 *
 * Not used for email content — there's no JavaScript running in an
 * inbox, so emails necessarily stay in a fixed timezone (Winnipeg) by
 * necessity, not by choice. Only affects the live web app.
 */
function localTimeSpan(string $mysqlDatetime, array $intlOptions = ['month' => 'short', 'day' => 'numeric', 'year' => 'numeric']): string
{
    // Parsed with an explicit " UTC" suffix regardless of PHP's own
    // configured timezone, since MySQL now always stores in UTC — this
    // keeps the conversion correct even if config.php's own timezone
    // setting is ever revisited later.
    $ts = strtotime($mysqlDatetime . ' UTC');
    if (!$ts) {
        return '';
    }
    $iso = gmdate('Y-m-d\TH:i:s\Z', $ts);
    $fallback = gmdate('M j, Y', $ts) . ' UTC';
    $optionsJson = htmlspecialchars(json_encode($intlOptions), ENT_QUOTES, 'UTF-8');
    return '<span class="auto-local-time" data-utc="' . h($iso) . '" data-intl-options=\'' . $optionsJson . '\'>' . h($fallback) . '</span>';
}

function timeAgo(string $datetime): string
{
    // Unix timestamp math (time() and strtotime() both resolve to
    // absolute instants) — this comparison is already correct
    // regardless of timezone, no viewer-local conversion needed here.
    $diff = time() - strtotime($datetime . ' UTC');
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    // Older than a week: falls back to an actual calendar date, which
    // — unlike the relative diffs above — genuinely is timezone-
    // sensitive (a moment near midnight can land on a different
    // calendar date depending on the viewer's own zone), so this one
    // does get the full viewer-local treatment.
    return localTimeSpan($datetime);
}

/**
 * Returns a version string tied to style.css's actual last-modified time,
 * so appending it to the stylesheet URL (style.css?v=...) forces browsers
 * and any CDN/edge caching (like Hostinger's hCDN) to treat every real
 * edit as a brand-new file — no manual cache purge needed after updates.
 */
function cssVersion(): string
{
    $path = __DIR__ . '/../css/style.css';
    $mtime = @filemtime($path);
    return $mtime ? (string)$mtime : (string)time();
}

/**
 * Same cache-busting approach as cssVersion(), generalized to any file —
 * used for the JS files (ui-kit.js, admin.js, portal.js), which had no
 * cache-busting at all until now. Without this, an actual edit to one of
 * these files can sit invisible behind Hostinger's CDN (which caches
 * static assets for a full week by default) even after the new file is
 * correctly uploaded to the server — the browser and CDN edge both keep
 * serving the old cached copy, since the URL never changes to signal
 * "this is different now." Appending the file's own real last-modified
 * time as a query string (?v=...) forces both to treat any genuine edit
 * as a brand-new URL, without needing a manual cache purge.
 */
function assetVersion(string $relativePath): string
{
    $path = __DIR__ . '/../' . ltrim($relativePath, '/');
    $mtime = @filemtime($path);
    return $mtime ? (string)$mtime : (string)time();
}

function uploaderLabel(array $file): string
{
    $name = $file['uploaded_by_name'] ?: ($file['uploaded_by'] === 'admin' ? 'Staff' : 'Client');
    return 'Uploaded by ' . h($name) . ' on ' . localTimeSpan($file['uploaded_at']);
}

/**
 * Maps an allowed upload extension to a proper MIME type, so files opened
 * via the "View" action render inline in the browser (PDF preview, image
 * display) instead of triggering a download prompt.
 */
function mimeTypeForExtension(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        // WhatsApp media (voice notes, videos, stickers) received from clients
        'webp' => 'image/webp',
        'ogg'  => 'audio/ogg',
        'mp3'  => 'audio/mpeg',
        'm4a'  => 'audio/mp4',
        'aac'  => 'audio/aac',
        'amr'  => 'audio/amr',
        'mp4'  => 'video/mp4',
        '3gp'  => 'video/3gpp',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * Validates a "where to go after login" path from a query string, so it
 * can only ever point at another page within the same admin/ or portal/
 * folder — never an external URL or protocol-relative address. Returns
 * the safe path, or '' if the input isn't safe/valid.
 */
function safeRelativeRedirect(?string $path): string
{
    if (!$path) {
        return '';
    }
    // Must be a plain relative filename (optionally with a query string),
    // no scheme, no leading slash, no "..", no protocol-relative "//".
    if (preg_match('#^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%\-\.]*)?$#', $path)) {
        return $path;
    }
    return '';
}
function sanitizeFilename(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    return substr($name, 0, 150);
}

/**
 * Splits the "Additional emails (CC)" field into clean addresses. Accepts
 * whatever people type or paste between them — commas, semicolons
 * (Outlook), spaces or new lines — and "Name <email>" entries. Drops
 * repeats (any letter case) and the client's own login email, which is
 * already the To: address. Returns [valid[], invalid[]].
 */
function splitCcEmails(?string $raw, string $ownEmail = ''): array
{
    $raw = preg_replace('/"[^"]*"/', ' ', (string)$raw); // quoted display names may contain commas
    $raw = preg_replace('/[^<>,;\n]*<([^<>]+)>/', '$1', $raw);
    $seen = [strtolower(trim($ownEmail)) => true];
    $valid = [];
    $invalid = [];
    foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        $part = trim($part, "\"'");
        $key = strtolower($part);
        if ($part === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        if (filter_var($part, FILTER_VALIDATE_EMAIL)) $valid[] = $part;
        else $invalid[] = $part;
    }
    return [$valid, $invalid];
}

/**
 * Validates + tidies the CC field for saving: the cleaned "a@x.com, b@y.com"
 * string (or null when empty). Ends the request with a JSON error naming
 * the first address that isn't a valid email.
 */
function ccEmailsForSaving(?string $raw, string $ownEmail = ''): ?string
{
    [$valid, $invalid] = splitCcEmails($raw, $ownEmail);
    if ($invalid) {
        jsonResponse(false, '"' . $invalid[0] . '" in the additional emails field doesn\'t look like a valid email address.');
    }
    return $valid ? implode(', ', $valid) : null;
}

/**
 * Normalizes a $_FILES['fieldname'] entry into a flat list of single-file
 * arrays, regardless of whether the <input> had the "multiple" attribute.
 *
 * PHP represents `<input type="file" name="document">` as one assoc array
 * (name/type/tmp_name/error/size => scalar) but represents
 * `<input type="file" name="document" multiple>` as one assoc array whose
 * values are each an *array* of scalars (one per selected file). This
 * collapses both shapes into the same list-of-single-file-array format
 * that validateUploadedDocument()/storeUploadedDocument() expect, so
 * callers can loop over it the same way either way.
 *
 * Files the browser didn't actually attach (UPLOAD_ERR_NO_FILE, empty
 * name) are skipped rather than returned as empty placeholders.
 */
function normalizeUploadedFiles($filesField): array
{
    if (empty($filesField) || !isset($filesField['name'])) {
        return [];
    }

    if (!is_array($filesField['name'])) {
        // Single-file <input>: already the shape we want.
        if ($filesField['error'] === UPLOAD_ERR_NO_FILE || $filesField['name'] === '') {
            return [];
        }
        return [$filesField];
    }

    // Multi-file <input multiple>: transpose the parallel arrays into a
    // list of single-file arrays.
    $files = [];
    foreach ($filesField['name'] as $i => $name) {
        if ($filesField['error'][$i] === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }
        $files[] = [
            'name'     => $name,
            'type'     => $filesField['type'][$i] ?? '',
            'tmp_name' => $filesField['tmp_name'][$i] ?? '',
            'error'    => $filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $filesField['size'][$i] ?? 0,
        ];
    }
    return $files;
}

/** The per-file upload cap in whole MB, for user-facing messages. */
function maxUploadMb(): int
{
    return (int)round(MAX_UPLOAD_BYTES / 1048576);
}

/**
 * Validates an uploaded file (from $_FILES['document']) without moving it.
 * Returns ['ok' => true] or ['ok' => false, 'error' => '...'].
 */
function validateUploadedDocument(array $file): array
{
    if (empty($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please choose a file to upload.'];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'File is too large. Maximum size is ' . maxUploadMb() . ' MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Please try again.'];
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'error' => 'File is too large. Maximum size is ' . maxUploadMb() . ' MB.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        return ['ok' => false, 'error' => 'File type not allowed. Please upload a PDF, image, or Word document.'];
    }

    // Content-based check, on top of the extension check above — closes
    // the gap where someone renames a script or executable to look like
    // an allowed extension. Deliberately tolerant (several accepted
    // values per type, not just one exact string): fileinfo/libmagic
    // versions can report slightly different MIME strings for the same
    // real file across server environments, especially for ZIP-based
    // formats like DOCX, and a check strict enough to reject a genuine
    // document would be worse than not having this check at all. Only
    // rejects a file whose actual content doesn't look like the claimed
    // type in any recognized form — a real PDF/image/Word document
    // should never trip this.
    if (function_exists('finfo_open') && is_uploaded_file($file['tmp_name'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($detected) {
            $allowedMimesByExt = [
                'pdf'  => ['application/pdf'],
                'jpg'  => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png'  => ['image/png'],
                // DOCX is a ZIP container — some libmagic versions report
                // the specific OOXML type, others just see "it's a zip".
                // DOC (legacy binary format) is reported inconsistently
                // enough across environments that it's included here too
                // rather than risk a false rejection.
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
            ];
            $allowed = $allowedMimesByExt[$ext] ?? [];
            if ($allowed && !in_array($detected, $allowed, true)) {
                return ['ok' => false, 'error' => "This file's contents don't match a $ext file — it may be renamed or corrupted. Please check the file and try again."];
            }
        }
        // If finfo itself fails to produce a result, or a future extension
        // isn't in the map above, this check is simply skipped for that
        // upload — the extension check above still applies either way,
        // so this is purely an additional layer, never the only one.
    }

    return ['ok' => true];
}

/**
 * Validates an uploaded file (from $_FILES['document']) and moves it into
 * UPLOAD_DIR. Returns ['ok' => true, 'stored_name' => ..., 'original_name' => ..., 'size' => ...]
 * or ['ok' => false, 'error' => '...'].
 */
function storeUploadedDocument(array $file, int $requestId): array
{
    $check = validateUploadedDocument($file);
    if (!$check['ok']) {
        return $check;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $storedName = 'req' . $requestId . '_' . time() . '_' . sanitizeFilename($file['name']);
    $destination = UPLOAD_DIR . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['ok' => false, 'error' => 'Could not save the uploaded file.'];
    }

    return [
        'ok' => true,
        'stored_name' => $storedName,
        'original_name' => $file['name'],
        'size' => $file['size'],
    ];
}

/**
 * The fixed "Classes" taxonomy — every case type Duque Immigration handles,
 * grouped by category. Backs the case-type picker in the Add/Edit client
 * forms. Client records still store case_type as free text (so a one-off
 * or newly-added case type never blocks adding a client) — this list is
 * what's offered as suggestions and what's used to color-tag clients by
 * category everywhere their case type is shown.
 */
function classTaxonomy(): array
{
    return [
        'Economic' => [
            'Manitoba Provincial Nominee Program (MPNP)',
            'PR through PNP (Federal Stage Only)',
            'Morden Immigration',
            'BC PNP (British Columbia Provincial Nominee Program)',
            'Express Entry',
            'New Brunswick Provincial Nominee Program (NBPNP)',
            'MPNP – EOI Only',
            'Saskatchewan Immigrant Nominee Program (SINP)',
        ],
        'Family' => [
            'Spouse Sponsorship',
            'Child Sponsorship',
            'Common-law Sponsorship',
            'Conjugal Sponsorship',
            'Parent and Grandparents Sponsorship',
        ],
        'Work' => [
            'Work Permit',
            'Work Permit Extension',
            'Work Permit Restoration',
        ],
        'Study' => [
            'Study Permit',
            'Study Permit Extension',
            'Study Permit Restoration',
        ],
        'Visit' => [
            'Visitor Visa',
            'Visitor Record',
            'Visitor Record Restoration',
            'Super Visa',
        ],
        'LMIA' => [
            'LMIA (Low-Wage Stream)',
            'LMIA (High-Wage Stream)',
            'LMIA (Agricultural Stream)',
            'LMIA (Permanent Residence Stream)',
        ],
    ];
}

/**
 * URL/CSS-safe slug for a category name, e.g. "Economic" -> "economic".
 * Used to pick the matching .class-tag-{slug} color in style.css.
 */
function classCategorySlug(string $category): string
{
    return strtolower(preg_replace('/[^a-z]/i', '', $category)) ?: 'other';
}

/**
 * The Class categories in display order, exactly as defined in
 * classTaxonomy() — so any page that groups by Class (rather than by
 * individual case type) sorts groups the same way everywhere, without
 * re-declaring the order in JS.
 */
function classCategoryOrder(): array
{
    return array_keys(classTaxonomy());
}

/**
 * Looks up which taxonomy category a (free-text) case type belongs to.
 * Case type is a free-text field with taxonomy items offered only as
 * suggestions (staff can type anything), so this checks two layers:
 *
 * 1. Exact match against a taxonomy item (case-insensitive, trimmed) —
 *    the reliable path, used whenever staff picked from the list or
 *    typed it out in full.
 * 2. A keyword fallback for everything else — abbreviations and
 *    shorthand staff commonly type instead of the full taxonomy string
 *    (e.g. "MPNP", "SINP", "STUDENT VISA", "WP EXT (LMIA)") — so real
 *    case types still land under the right Class instead of "Other".
 *    Checked in the same order as classTaxonomy() is defined, so a
 *    string matching more than one category's keywords (e.g. "WP EXT
 *    (LMIA)" reads as both a Work signal and an LMIA one) resolves to
 *    whichever category comes first in that order.
 *
 * Returns null only when neither layer finds anything — a genuinely
 * custom, one-off case type.
 */
function classCategoryFor(?string $caseType): ?string
{
    $caseType = trim((string)$caseType);
    if ($caseType === '') {
        return null;
    }

    static $exactLookup = null;
    if ($exactLookup === null) {
        $exactLookup = [];
        foreach (classTaxonomy() as $category => $items) {
            foreach ($items as $item) {
                $exactLookup[mb_strtolower($item)] = $category;
            }
        }
    }
    $lower = mb_strtolower($caseType);
    if (isset($exactLookup[$lower])) {
        return $exactLookup[$lower];
    }

    static $keywordMap = [
        'Economic' => ['mpnp', 'pnp', 'sinp', 'bcpnp', 'nbpnp', 'express entry', 'economic', 'nominee'],
        'Family'   => ['sponsor', 'spousal', 'spouse', 'common-law', 'common law', 'conjugal', 'pgp', 'parent and grandparent'],
        'Work'     => ['work permit', 'wp ext', 'wp extension', 'workpermit'],
        'Study'    => ['study permit', 'student visa', 'study'],
        'Visit'    => ['visitor', 'visit', 'super visa'],
        'LMIA'     => ['lmia'],
    ];
    foreach ($keywordMap as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return $category;
            }
        }
    }

    return null;
}

/**
 * Renders a client's case type as a small colored tag matching its
 * taxonomy category (or a neutral "Other" tag for a custom case type
 * that isn't part of the standard list). Returns an em dash if the
 * client has no case type set at all.
 */
function classTagHtml(?string $caseType): string
{
    $caseType = trim((string)$caseType);
    if ($caseType === '') {
        return '<span style="color:var(--text-muted);font-size:12.5px;">—</span>';
    }
    $category = classCategoryFor($caseType);
    $slug = $category ? classCategorySlug($category) : 'other';
    return '<span class="class-tag class-tag-' . $slug . '">' . h($caseType) . '</span>';
}
