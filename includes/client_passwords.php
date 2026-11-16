<?php
/**
 * includes/client_passwords.php
 * Client password rules and "reset your password" links.
 *
 * Passwords are only ever stored as hashes (password_hash). Nobody, staff
 * included, can read one back. A password staff type in (new client, or
 * "Set password") is temporary: must_change_password makes the client
 * choose their own before using the portal (see requireClient()).
 *
 * Reset links: portal/reset_password.php?t=<selector>:<validator>. Only a
 * SHA-256 of the validator is stored; links work once; a client's request
 * lasts 1 hour, a link staff send lasts 7 days. Sent to the client's own
 * login email only, never to the CC contacts: whoever opens the link can
 * take over the account.
 * Table: client_password_resets (sql/migration_client_passwords.sql).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const CLIENT_PASSWORD_MIN = 8;
const RESET_LINK_MINUTES_CLIENT = 60;
const RESET_LINK_MINUTES_STAFF = 7 * 24 * 60;

/** Null when the password is acceptable, otherwise what's wrong. */
function clientPasswordProblem(string $password, string $email = ''): ?string
{
    if (mb_strlen($password) < CLIENT_PASSWORD_MIN) {
        return 'Password must be at least ' . CLIENT_PASSWORD_MIN . ' characters.';
    }
    if (mb_strlen($password) > 200) {
        return 'Password is too long.';
    }
    $lower = mb_strtolower($password);
    if ($email !== '' && ($lower === mb_strtolower($email) || $lower === mb_strtolower(strtok($email, '@')))) {
        return "Password can't be your email address.";
    }
    if (preg_match('/^(.)\1+$/u', $password) || in_array($lower, commonPasswords(), true)) {
        return 'That password is too easy to guess. Please choose another.';
    }
    return null;
}

/** The most-guessed passwords (and local favourites); always refused. */
function commonPasswords(): array
{
    return ['password', 'password1', 'password123', '12345678', '123456789', '1234567890', 'qwerty123',
        'qwertyuiop', 'iloveyou', 'welcome1', 'welcome123', 'abcd1234', 'admin123', 'letmein1',
        'canada123', 'manila123', 'winnipeg', 'immigration', 'duque123', 'changeme', 'p@ssw0rd'];
}

/** Sets a client's password. $temporary = set by staff, must be changed. */
function setClientPassword(int $clientId, string $password, bool $temporary): void
{
    getDb()->prepare('UPDATE clients SET password_hash = ?, plain_password = NULL, must_change_password = ? WHERE id = ?')
           ->execute([password_hash($password, PASSWORD_DEFAULT), $temporary ? 1 : 0, $clientId]);
}

/** Creates a reset link for this client and returns its full URL. */
function createClientResetLink(int $clientId, string $requestedBy): string
{
    $db = getDb();
    // One live link per client: a new one cancels older ones.
    $db->prepare('DELETE FROM client_password_resets WHERE client_id = ? OR expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY')->execute([$clientId]);
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $minutes = $requestedBy === 'staff' ? RESET_LINK_MINUTES_STAFF : RESET_LINK_MINUTES_CLIENT;
    $db->prepare('INSERT INTO client_password_resets (client_id, selector, validator_hash, requested_by, expires_at) VALUES (?, ?, ?, ?, ?)')
       ->execute([$clientId, $selector, hash('sha256', $validator), $requestedBy, gmdate('Y-m-d H:i:s', time() + $minutes * 60)]);
    return rtrim(PORTAL_APP_URL, '/') . '/reset_password.php?t=' . $selector . ':' . $validator;
}

/**
 * The reset row for a link token if it's genuine, unused and unexpired
 * (with the client's name and email), else null.
 */
function findClientResetLink(string $token): ?array
{
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $token, $m)) {
        return null;
    }
    $stmt = getDb()->prepare('
      SELECT r.*, c.full_name, c.email, c.is_active
      FROM client_password_resets r JOIN clients c ON c.id = r.client_id
      WHERE r.selector = ?');
    $stmt->execute([$m[1]]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
        return null;
    }
    if ($row['used_at'] !== null || $row['expires_at'] < gmdate('Y-m-d H:i:s') || !(int)$row['is_active']) {
        return null;
    }
    return $row;
}

/** Uses up a link: new password saved, every link and remembered device cancelled. */
function completeClientReset(array $resetRow, string $password): void
{
    $db = getDb();
    $clientId = (int)$resetRow['client_id'];
    setClientPassword($clientId, $password, false);
    $db->prepare('UPDATE client_password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$resetRow['id']]);
    $db->prepare('DELETE FROM client_password_resets WHERE client_id = ? AND id <> ?')->execute([$clientId, $resetRow['id']]);
    require_once __DIR__ . '/client_remember.php';
    clientRememberForgetAll($clientId);
}

/** Emails a reset link to the client's own address (never the CCs). */
function sendClientResetEmail(array $client, string $url, string $requestedBy): bool
{
    require_once __DIR__ . '/mailer.php';
    $first = explode(' ', trim($client['full_name']))[0] ?: 'there';
    $hours = $requestedBy === 'staff' ? '7 days' : '1 hour';
    $subject = 'Set your Duque Immigration portal password';
    $intro = $requestedBy === 'staff'
        ? 'Our team has sent you a link to choose a new password for your Duque Immigration client portal.'
        : 'Someone (hopefully you) asked to reset the password for your Duque Immigration client portal.';
    $textBody = "Hi " . $first . ",\n\n" . $intro . "\n\n"
        . "Choose your new password here (the link works once, for " . $hours . "):\n" . $url . "\n\n"
        . "If you didn't ask for this, you can ignore this email. Your password stays the same.\n\n"
        . "— Duque Immigration";
    $htmlBody = buildEmailHtml($first, [
        htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'),
        'The link works once, for ' . $hours . '.',
        "If you didn't ask for this, you can ignore this email. Your password stays the same.",
    ], 'Choose a new password', $url);
    // Deliberately no CC: the link gives full access to the account.
    return sendMail($client['email'], $client['full_name'], $subject, $textBody, $htmlBody);
}
