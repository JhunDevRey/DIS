<?php
/**
 * includes/client_remember.php
 * "Keep me signed in on this device" for the client portal.
 *
 * The browser keeps a long-lived cookie "selector:validator". The
 * selector finds the row in client_remember_tokens; the validator is only
 * ever stored as a SHA-256 hash and compared in constant time. Every time
 * the cookie signs someone in, the selector stays and the validator is
 * replaced. So if a copied cookie gets used, the real device later shows
 * up with a validator that's no longer current — that means the cookie
 * was copied, and every remembered device for that client is signed out.
 * The previous validator stays acceptable for REMEMBER_GRACE_SECONDS, so
 * several tabs reopening at once (same old cookie) isn't mistaken for that.
 *
 * Used by includes/client_auth.php (silent sign-in when the normal
 * session has expired), portal/login.php, portal/logout.php and the
 * password-change paths.
 */

require_once __DIR__ . '/db.php';

const CLIENT_REMEMBER_COOKIE = 'duque_client_remember';
const CLIENT_REMEMBER_DAYS = 30;
const REMEMBER_GRACE_SECONDS = 60;

function clientRememberSetCookie(string $value, int $expires): void
{
    setcookie(CLIENT_REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clientRememberClearCookie(): void
{
    clientRememberSetCookie('', time() - 3600);
    unset($_COOKIE[CLIENT_REMEMBER_COOKIE]);
}

/** [selector, validator] from the cookie, or null if absent/malformed. */
function clientRememberCookieParts(): ?array
{
    $raw = (string)($_COOKIE[CLIENT_REMEMBER_COOKIE] ?? '');
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
        return null;
    }
    return [$m[1], $m[2]];
}

/** Remembers this device for the client (call right after a successful sign-in). */
function clientRememberIssue(int $clientId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + CLIENT_REMEMBER_DAYS * 86400;
    getDb()->prepare('INSERT INTO client_remember_tokens (client_id, selector, validator_hash, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)')
           ->execute([$clientId, $selector, hash('sha256', $validator), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), gmdate('Y-m-d H:i:s', $expires)]);
    clientRememberSetCookie($selector . ':' . $validator, $expires);
}

/**
 * If there's no signed-in session but this device is remembered, signs
 * the client in silently. Returns true when it did.
 */
function clientRememberTryLogin(): bool
{
    if (!empty($_SESSION['client_id'])) {
        return false;
    }
    $parts = clientRememberCookieParts();
    if ($parts === null) {
        if (isset($_COOKIE[CLIENT_REMEMBER_COOKIE])) clientRememberClearCookie();
        return false;
    }
    [$selector, $validator] = $parts;

    try {
        $db = getDb();
        $stmt = $db->prepare('
          SELECT t.id, t.client_id, t.validator_hash, t.prev_validator_hash, t.rotated_at, t.expires_at,
                 c.full_name, c.is_active
          FROM client_remember_tokens t JOIN clients c ON c.id = t.client_id
          WHERE t.selector = ?
        ');
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return false; // e.g. table not created yet — just behave as before
    }

    if (!$row) {
        clientRememberClearCookie();
        return false;
    }
    $presented = hash('sha256', $validator);
    $current = hash_equals($row['validator_hash'], $presented);
    $justRotated = !$current && $row['prev_validator_hash'] !== null
        && hash_equals($row['prev_validator_hash'], $presented)
        && $row['rotated_at'] >= gmdate('Y-m-d H:i:s', time() - REMEMBER_GRACE_SECONDS);
    if (!$current && !$justRotated) {
        // Right device id, outdated secret: the cookie was copied and used
        // elsewhere. Sign out every remembered device for this client.
        $db->prepare('DELETE FROM client_remember_tokens WHERE client_id = ?')->execute([$row['client_id']]);
        clientRememberClearCookie();
        return false;
    }
    if ($row['expires_at'] < gmdate('Y-m-d H:i:s') || !(int)$row['is_active']) {
        $db->prepare('DELETE FROM client_remember_tokens WHERE id = ?')->execute([$row['id']]);
        clientRememberClearCookie();
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['client_id'] = (int)$row['client_id'];
    $_SESSION['client_name'] = $row['full_name'];

    // A parallel tab already swapped the secret a moment ago: the browser
    // is about to store that new cookie, so leave it alone.
    if ($justRotated) {
        return true;
    }

    // Normal case: new secret for this device, and 30 more days.
    $newValidator = bin2hex(random_bytes(32));
    $expires = time() + CLIENT_REMEMBER_DAYS * 86400;
    $db->prepare('UPDATE client_remember_tokens SET prev_validator_hash = validator_hash, validator_hash = ?, rotated_at = UTC_TIMESTAMP(), expires_at = ? WHERE id = ?')
       ->execute([hash('sha256', $newValidator), gmdate('Y-m-d H:i:s', $expires), $row['id']]);
    clientRememberSetCookie($selector . ':' . $newValidator, $expires);
    return true;
}

/** Forgets this device (sign out). */
function clientRememberForgetCurrent(): void
{
    $parts = clientRememberCookieParts();
    if ($parts !== null) {
        try {
            getDb()->prepare('DELETE FROM client_remember_tokens WHERE selector = ?')->execute([$parts[0]]);
        } catch (Throwable $e) { /* table missing — nothing to forget */ }
    }
    clientRememberClearCookie();
}

/**
 * Forgets the client's remembered devices — all of them, or all but this
 * one ($keepCurrent, e.g. after the client changes their own password).
 * Returns how many were removed.
 */
function clientRememberForgetAll(int $clientId, bool $keepCurrent = false): int
{
    try {
        $parts = $keepCurrent ? clientRememberCookieParts() : null;
        if ($parts !== null) {
            $stmt = getDb()->prepare('DELETE FROM client_remember_tokens WHERE client_id = ? AND selector <> ?');
            $stmt->execute([$clientId, $parts[0]]);
        } else {
            $stmt = getDb()->prepare('DELETE FROM client_remember_tokens WHERE client_id = ?');
            $stmt->execute([$clientId]);
        }
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Remembered devices other than this one (for the profile page). */
function clientRememberOtherDeviceCount(int $clientId): int
{
    try {
        $parts = clientRememberCookieParts();
        $stmt = getDb()->prepare('SELECT COUNT(*) FROM client_remember_tokens WHERE client_id = ? AND expires_at > UTC_TIMESTAMP() AND selector <> ?');
        $stmt->execute([$clientId, $parts[0] ?? '']);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Is this device remembered (for the profile page)? */
function clientRememberThisDevice(int $clientId): bool
{
    $parts = clientRememberCookieParts();
    if ($parts === null) return false;
    try {
        $stmt = getDb()->prepare('SELECT 1 FROM client_remember_tokens WHERE client_id = ? AND selector = ? AND expires_at > UTC_TIMESTAMP()');
        $stmt->execute([$clientId, $parts[0]]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
