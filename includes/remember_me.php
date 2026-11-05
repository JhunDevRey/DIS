<?php
/**
 * includes/remember_me.php
 * "Keep me signed in on this device" — one engine for both sides:
 *   'client' — client portal  (client_remember_tokens, 30 days)
 *   'admin'  — staff admin    (admin_remember_tokens, 14 days: wider access)
 * Thin wrappers: includes/client_remember.php and includes/admin_remember.php.
 *
 * The browser keeps a long-lived cookie "selector:validator". The
 * selector finds the row; the validator is only ever stored as a SHA-256
 * hash and compared in constant time. Every time the cookie signs someone
 * in, the selector stays and the validator is replaced. So if a copied
 * cookie gets used, the real device later shows up with a validator
 * that's no longer current — the cookie was copied, and every remembered
 * device for that account is signed out. The previous validator stays
 * acceptable for REMEMBER_GRACE_SECONDS, so several tabs reopening at once
 * (all carrying the same old cookie) isn't mistaken for that.
 */

require_once __DIR__ . '/db.php';

const REMEMBER_GRACE_SECONDS = 60;

function rememberRealm(string $realm): array
{
    static $realms = [
        'client' => [
            'table' => 'client_remember_tokens', 'owner' => 'client_id',
            'cookie' => 'duque_client_remember', 'days' => 30,
            'session_id' => 'client_id', 'session_name' => 'client_name',
        ],
        'admin' => [
            'table' => 'admin_remember_tokens', 'owner' => 'admin_id',
            'cookie' => 'duque_admin_remember', 'days' => 14,
            'session_id' => 'admin_id', 'session_name' => 'admin_name',
        ],
        // A client's CC contact signing in to that client's portal
        // (includes/portal_contacts.php picks which client afterwards).
        'contact' => [
            'table' => 'contact_remember_tokens', 'owner' => 'contact_id',
            'cookie' => 'duque_contact_remember', 'days' => 30,
            'session_id' => 'contact_id', 'session_name' => 'contact_name',
        ],
    ];
    return $realms[$realm];
}

/** Session values to sign this account in with, or null if it may no longer sign in. */
function rememberLookupAccount(string $realm, int $id): ?array
{
    if ($realm === 'client') {
        $stmt = getDb()->prepare('SELECT full_name, is_active FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        return $c && (int)$c['is_active'] ? ['client_id' => $id, 'client_name' => $c['full_name']] : null;
    }
    if ($realm === 'contact') {
        // Only while they still have a password and are still listed on at
        // least one client — the client they open is resolved afterwards.
        require_once __DIR__ . '/portal_contacts.php';
        $contact = contactFind($id);
        if (!$contact || !$contact['password_hash'] || !contactClientsFor($contact['email'])) return null;
        return ['contact_id' => $id, 'contact_email' => $contact['email'], 'contact_name' => $contact['name'] ?: ''];
    }
    // Live admin_users has admin_name; older schema copies have full_name.
    $stmt = getDb()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([$id]);
    $a = $stmt->fetch();
    return $a ? ['admin_id' => $id, 'admin_name' => $a['admin_name'] ?? $a['full_name'] ?? 'Staff'] : null;
}

function rememberSetCookie(string $realm, string $value, int $expires): void
{
    setcookie(rememberRealm($realm)['cookie'], $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function rememberClearCookie(string $realm): void
{
    rememberSetCookie($realm, '', time() - 3600);
    unset($_COOKIE[rememberRealm($realm)['cookie']]);
}

/** [selector, validator] from the cookie, or null if absent/malformed. */
function rememberCookieParts(string $realm): ?array
{
    $raw = (string)($_COOKIE[rememberRealm($realm)['cookie']] ?? '');
    return preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m) ? [$m[1], $m[2]] : null;
}

/** Remembers this device (call right after a successful password sign-in). */
function rememberIssue(string $realm, int $ownerId): void
{
    $r = rememberRealm($realm);
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + $r['days'] * 86400;
    getDb()->prepare("INSERT INTO {$r['table']} ({$r['owner']}, selector, validator_hash, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)")
           ->execute([$ownerId, $selector, hash('sha256', $validator), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), gmdate('Y-m-d H:i:s', $expires)]);
    rememberSetCookie($realm, $selector . ':' . $validator, $expires);
}

/**
 * If there's no signed-in session but this device is remembered, signs
 * in silently. Returns true when it did.
 */
function rememberTryLogin(string $realm): bool
{
    $r = rememberRealm($realm);
    if (!empty($_SESSION[$r['session_id']])) {
        return false;
    }
    $parts = rememberCookieParts($realm);
    if ($parts === null) {
        if (isset($_COOKIE[$r['cookie']])) rememberClearCookie($realm);
        return false;
    }
    [$selector, $validator] = $parts;

    try {
        $db = getDb();
        $stmt = $db->prepare("SELECT id, {$r['owner']} AS owner_id, validator_hash, prev_validator_hash, rotated_at, expires_at FROM {$r['table']} WHERE selector = ?");
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return false; // e.g. table not created yet — just behave as before
    }

    if (!$row) {
        rememberClearCookie($realm);
        return false;
    }
    $presented = hash('sha256', $validator);
    $current = hash_equals($row['validator_hash'], $presented);
    $justRotated = !$current && $row['prev_validator_hash'] !== null
        && hash_equals($row['prev_validator_hash'], $presented)
        && $row['rotated_at'] >= gmdate('Y-m-d H:i:s', time() - REMEMBER_GRACE_SECONDS);
    if (!$current && !$justRotated) {
        // Right device id, outdated secret: the cookie was copied and used
        // elsewhere. Sign out every remembered device for this account.
        $db->prepare("DELETE FROM {$r['table']} WHERE {$r['owner']} = ?")->execute([$row['owner_id']]);
        rememberClearCookie($realm);
        return false;
    }
    $account = $row['expires_at'] >= gmdate('Y-m-d H:i:s') ? rememberLookupAccount($realm, (int)$row['owner_id']) : null;
    if ($account === null) {
        $db->prepare("DELETE FROM {$r['table']} WHERE id = ?")->execute([$row['id']]);
        rememberClearCookie($realm);
        return false;
    }

    session_regenerate_id(true);
    foreach ($account as $key => $value) {
        $_SESSION[$key] = $value;
    }

    // A parallel tab already swapped the secret a moment ago: the browser
    // is about to store that new cookie, so leave it alone.
    if ($justRotated) {
        return true;
    }

    // Normal case: new secret for this device, and a fresh expiry.
    $newValidator = bin2hex(random_bytes(32));
    $expires = time() + $r['days'] * 86400;
    $db->prepare("UPDATE {$r['table']} SET prev_validator_hash = validator_hash, validator_hash = ?, rotated_at = UTC_TIMESTAMP(), expires_at = ? WHERE id = ?")
       ->execute([hash('sha256', $newValidator), gmdate('Y-m-d H:i:s', $expires), $row['id']]);
    rememberSetCookie($realm, $selector . ':' . $newValidator, $expires);
    return true;
}

/** Forgets this device (sign out). */
function rememberForgetCurrent(string $realm): void
{
    $r = rememberRealm($realm);
    $parts = rememberCookieParts($realm);
    if ($parts !== null) {
        try {
            getDb()->prepare("DELETE FROM {$r['table']} WHERE selector = ?")->execute([$parts[0]]);
        } catch (Throwable $e) { /* table missing — nothing to forget */ }
    }
    rememberClearCookie($realm);
}

/**
 * Forgets an account's remembered devices — all of them, or all but this
 * one ($keepCurrent). Returns how many were removed.
 */
function rememberForgetAll(string $realm, int $ownerId, bool $keepCurrent = false): int
{
    $r = rememberRealm($realm);
    try {
        $parts = $keepCurrent ? rememberCookieParts($realm) : null;
        if ($parts !== null) {
            $stmt = getDb()->prepare("DELETE FROM {$r['table']} WHERE {$r['owner']} = ? AND selector <> ?");
            $stmt->execute([$ownerId, $parts[0]]);
        } else {
            $stmt = getDb()->prepare("DELETE FROM {$r['table']} WHERE {$r['owner']} = ?");
            $stmt->execute([$ownerId]);
        }
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Remembered devices other than this one. */
function rememberOtherDeviceCount(string $realm, int $ownerId): int
{
    $r = rememberRealm($realm);
    try {
        $parts = rememberCookieParts($realm);
        $stmt = getDb()->prepare("SELECT COUNT(*) FROM {$r['table']} WHERE {$r['owner']} = ? AND expires_at > UTC_TIMESTAMP() AND selector <> ?");
        $stmt->execute([$ownerId, $parts[0] ?? '']);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Is this device remembered? */
function rememberThisDevice(string $realm, int $ownerId): bool
{
    $r = rememberRealm($realm);
    $parts = rememberCookieParts($realm);
    if ($parts === null) return false;
    try {
        $stmt = getDb()->prepare("SELECT 1 FROM {$r['table']} WHERE {$r['owner']} = ? AND selector = ? AND expires_at > UTC_TIMESTAMP()");
        $stmt->execute([$ownerId, $parts[0]]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
