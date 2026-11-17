<?php
/**
 * includes/security.php
 * One place for the defences every page and endpoint shares:
 *   - startAppSession()      hardened session start + idle/absolute timeouts
 *   - sendSecurityHeaders()  CSP, frame, sniffing, referrer, HSTS headers
 *   - clientIp()             the caller's IP for rate limits and the activity log
 *   - rateLimitHit() / rateLimitRecord() / rateLimitClear()  generic throttling
 *   - cleanUploadName() / contentDisposition()  safe names for uploaded files
 *
 * Loaded by includes/admin_auth.php, includes/client_auth.php, both login
 * and logout pages, and the WhatsApp webhook.
 */

require_once __DIR__ . '/db.php';

// A session with no request for this long is ended (seconds).
const SESSION_IDLE_SECONDS = 4 * 3600;
// A session is ended this long after sign-in no matter what (seconds).
// "Keep me signed in" devices then sign back in silently with their token.
const SESSION_MAX_SECONDS = 12 * 3600;

// A real bcrypt hash of a random string nobody knows. Sign-in checks it
// when the account doesn't exist, so a wrong email takes as long as a
// wrong password and the response time can't reveal who has an account.
const DUMMY_PASSWORD_HASH = '$2y$10$7ISmGTihM2niGbGPeV51QeyEIHI56ndJptkzBZbAqgcr4VERHnmu6';

function requestIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/**
 * Starts the named session with strict settings. Ends a session that has
 * been idle too long or is older than SESSION_MAX_SECONDS, then starts a
 * fresh empty one, so callers just see "not signed in".
 */
function startAppSession(string $name): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    // Reject session ids the server never issued (session fixation) and
    // never take an id from the URL.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    ini_set('session.gc_maxlifetime', (string)SESSION_IDLE_SECONDS);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => requestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name($name);
    session_start();

    $now = time();
    $started = (int)($_SESSION['_started_at'] ?? 0);
    $seen = (int)($_SESSION['_seen_at'] ?? 0);
    if ($started && ($now - $seen > SESSION_IDLE_SECONDS || $now - $started > SESSION_MAX_SECONDS)) {
        $_SESSION = [];
        session_regenerate_id(true);
        $started = 0;
    }
    if (!$started) {
        $_SESSION['_started_at'] = $now;
    }
    $_SESSION['_seen_at'] = $now;
}

/** Call right after a successful sign-in: new id, fresh lifetime. */
function sessionMarkSignedIn(): void
{
    session_regenerate_id(true);
    $_SESSION['_started_at'] = time();
    $_SESSION['_seen_at'] = time();
}

/**
 * Browser-side protections for every HTML page and JSON response.
 * The Content-Security-Policy lists the only places scripts, styles and
 * fonts may come from (this site, cdnjs for Font Awesome / pdf.js / QR
 * codes, Adobe's PDF viewer). Inline scripts stay allowed because the
 * pages use them; everything else from anywhere else is refused, as are
 * plugins, <base> tricks, and posting forms to other sites.
 */
function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    // Adobe PDF Embed (Application Review viewer) loads its code, fonts and
    // telemetry from these hosts.
    $adobe = 'https://acrobatservices.adobe.com https://*.adobe.com https://*.adobe.io https://*.adobelogin.com https://use.typekit.net https://p.typekit.net';
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com $adobe",
        "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com $adobe",
        "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com $adobe",
        "img-src 'self' data: blob: $adobe",
        "connect-src 'self' https://cdnjs.cloudflare.com $adobe",
        "worker-src 'self' blob: https://cdnjs.cloudflare.com",
        "frame-src 'self' blob: https://app.documenso.com $adobe",
        "media-src 'self' blob:",
        "manifest-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        'upgrade-insecure-requests',
    ]);
    header('Content-Security-Policy: ' . $csp);
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    if (requestIsHttps()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header_remove('X-Powered-By');
}

/**
 * The caller's IP. Uses the connection address, which the client can't
 * fake. X-Forwarded-For is only believed when config.php names the header
 * a trusted proxy sets (TRUSTED_PROXY_IP_HEADER, e.g. 'HTTP_X_FORWARDED_FOR')
 * and the connection really comes from that proxy (TRUSTED_PROXY_PREFIXES).
 */
function clientIp(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (defined('TRUSTED_PROXY_IP_HEADER') && TRUSTED_PROXY_IP_HEADER !== '') {
        $prefixes = defined('TRUSTED_PROXY_PREFIXES') ? (array)TRUSTED_PROXY_PREFIXES : [];
        $fromProxy = false;
        foreach ($prefixes as $p) {
            if ($p !== '' && str_starts_with($remote, $p)) { $fromProxy = true; break; }
        }
        $forwarded = (string)($_SERVER[TRUSTED_PROXY_IP_HEADER] ?? '');
        if ($fromProxy && $forwarded !== '') {
            // The proxy appends the address it saw last; take that one.
            $parts = array_map('trim', explode(',', $forwarded));
            $ip = end($parts);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $remote;
}

// ---------- Generic rate limiting ----------
// Stored in login_attempts (identifier + time), which already exists on
// every install; identifiers are namespaced ("login-ip:admin:1.2.3.4",
// "msg:client:12", ...).

function rateLimitTable(PDO $db): void
{
    static $ensured = false;
    if ($ensured) return;
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

function rateLimitKey(string $key): string
{
    // Keep identifiers inside the column and free of odd characters.
    return strlen($key) > 100 ? substr($key, 0, 59) . ':' . sha1($key) : $key;
}

/** True when $key already has $max events in the last $minutes. */
function rateLimitHit(string $key, int $max, int $minutes): bool
{
    $db = getDb();
    rateLimitTable($db);
    $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . (int)$minutes . ' MINUTE)');
    $stmt->execute([rateLimitKey($key)]);
    return (int)$stmt->fetchColumn() >= $max;
}

function rateLimitRecord(string $key): void
{
    $db = getDb();
    rateLimitTable($db);
    $db->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')->execute([rateLimitKey($key)]);
    // Housekeeping now and then: nothing older than a day is ever needed.
    if (random_int(1, 25) === 1) {
        $db->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    }
}

function rateLimitClear(string $key): void
{
    $db = getDb();
    rateLimitTable($db);
    $db->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([rateLimitKey($key)]);
}

// ---------- Uploaded file names ----------

/**
 * The name a file is shown and downloaded under. Drops control characters
 * (a newline would break the download header), path separators (zip
 * entries like "../x"), leading dots and characters Windows can't save,
 * and keeps it to 150 characters with the extension intact.
 */
function cleanUploadName(string $name): string
{
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $name);
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $name = ltrim($name, '. ');
    if (!mb_check_encoding($name, 'UTF-8')) {
        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
    }
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext !== '' ? mb_substr($name, 0, -(mb_strlen($ext) + 1)) : $name;
    $max = 150 - ($ext !== '' ? mb_strlen($ext) + 1 : 0);
    if (mb_strlen($base) > $max) $base = rtrim(mb_substr($base, 0, $max));
    if ($base === '') $base = 'file';
    return $ext !== '' ? $base . '.' . $ext : $base;
}

/**
 * Headers for sending a stored client file. $contentType defaults to the
 * type for the file's extension when shown inline, or a plain download.
 * Anything shown inline other than a PDF also gets a sandbox policy, so
 * even a file that tricks a browser into treating it as a web page can't
 * run scripts. Never cached by browsers or proxies.
 */
function sendFileHeaders(string $originalName, bool $inline, int $length, ?string $contentType = null): void
{
    $type = $contentType ?? ($inline ? mimeTypeForExtension($originalName) : 'application/octet-stream');
    header('Content-Type: ' . $type);
    header('Content-Disposition: ' . contentDisposition($inline ? 'inline' : 'attachment', $originalName));
    header('Content-Length: ' . $length);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    if ($inline && $type !== 'application/pdf') {
        header('Content-Security-Policy: sandbox; default-src \'none\'; img-src \'self\' data:; media-src \'self\'; style-src \'unsafe-inline\'');
    }
}

/** Content-Disposition header value that is safe for any stored name. */
function contentDisposition(string $type, string $name): string
{
    $name = cleanUploadName($name);
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
    $ascii = str_replace(['"', '\\', ';'], '_', $ascii);
    return $type . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}

require_once __DIR__ . '/audit.php';

/**
 * Text safe to show the user for a caught exception: the app's own
 * RuntimeException messages ("File type not allowed…") pass through;
 * anything else (database errors, file system paths) becomes $fallback.
 * The real error is always written to the server log.
 */
function userFacingError(Throwable $e, string $fallback = 'Something went wrong. Please try again.'): string
{
    error_log(get_class($e) . ': ' . $e->getMessage());
    return get_class($e) === RuntimeException::class ? $e->getMessage() : $fallback;
}

/**
 * Per-account throttle for actions that create work or email staff
 * (messages, comments, uploads). Ends the request with HTTP 429 and a
 * plain message once $max actions happened in the last $minutes;
 * otherwise counts this one ($weight times, e.g. one per file).
 */
function enforceActionLimit(string $key, int $max, int $minutes, string $message, int $weight = 1): void
{
    if (rateLimitHit($key, max(1, $max - $weight + 1), $minutes)) {
        http_response_code(429);
        jsonResponse(false, $message);
    }
    for ($i = 0; $i < max(1, $weight); $i++) {
        rateLimitRecord($key);
    }
}
