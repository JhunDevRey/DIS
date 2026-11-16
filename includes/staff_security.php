<?php
/**
 * includes/staff_security.php
 * Staff accounts: password rules, owners, two-step sign-in, and finishing
 * a sign-in. Used by admin/login.php, includes/admin_auth.php, the profile
 * and staff-accounts pages and their APIs.
 *
 * Owners (admin_users.is_owner) add, change and deactivate staff accounts
 * and read the activity log. Everyone manages their own password and
 * two-step sign-in on My Profile.
 *
 * Setting in config.php (optional):
 *   define('ADMIN_REQUIRE_2FA', true);  // every staff account must turn on
 *                                       // two-step sign-in before using the portal
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/totp.php';

const STAFF_PASSWORD_MIN = 12;
const STAFF_2FA_PENDING_SECONDS = 300;

function staffPasswordProblem(string $password, string $username = ''): ?string
{
    if (mb_strlen($password) < STAFF_PASSWORD_MIN) {
        return 'Staff passwords must be at least ' . STAFF_PASSWORD_MIN . ' characters. A short sentence works well.';
    }
    if (mb_strlen($password) > 200) {
        return 'Password is too long.';
    }
    $lower = mb_strtolower($password);
    if (mb_strlen($username) >= 4 && str_contains($lower, mb_strtolower($username))) {
        return "Password can't contain the username.";
    }
    if (preg_match('/^(.)\1+$/u', $password) || preg_match('/^(duque|immigration|password|welcome|admin|staff)[0-9!@#$%^&*.]*$/i', $password)) {
        return 'That password is too easy to guess. Please choose another.';
    }
    return null;
}

/** The signed-in staff account row (set by requireAdmin()). */
function currentAdmin(): ?array
{
    return $GLOBALS['currentAdmin'] ?? null;
}

function adminIsOwner(?array $admin = null): bool
{
    $admin = $admin ?? currentAdmin();
    return $admin !== null && (int)($admin['is_owner'] ?? 0) === 1;
}

/** Stops a non-owner (JSON for APIs, a short page otherwise). */
function requireOwner(): void
{
    if (adminIsOwner()) return;
    http_response_code(403);
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        jsonResponse(false, 'Only an owner can do that.');
    }
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Owners only</title><body style="font-family:sans-serif;padding:40px">'
       . '<h1>Owners only</h1><p>Only an owner account can open this page.</p><p><a href="clients.php">Back to Clients</a></p>';
    exit;
}

function adminRequire2fa(): bool
{
    return defined('ADMIN_REQUIRE_2FA') && ADMIN_REQUIRE_2FA === true && twoFactorAvailable();
}

/** Does this account need a code from the authenticator app at sign-in? */
function adminUses2fa(array $admin): bool
{
    return (int)($admin['totp_enabled'] ?? 0) === 1 && !empty($admin['totp_secret']) && twoFactorAvailable();
}

/**
 * Checks a 6-digit app code or a one-time recovery code for this account
 * and records it as used. Returns 'totp', 'recovery' or null.
 */
function adminCheckSecondFactor(array $admin, string $code): ?string
{
    $db = getDb();
    $secret = totpDecryptSecret($admin['totp_secret'] ?? null);
    if ($secret === null) return null;
    $digits = preg_replace('/\s+/', '', $code);
    if (preg_match('/^\d{6}$/', $digits)) {
        $step = totpVerify($secret, $digits, isset($admin['totp_last_step']) ? (int)$admin['totp_last_step'] : null);
        if ($step === null) return null;
        // Only one sign-in per code, even from two tabs at once.
        $stmt = $db->prepare('UPDATE admin_users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
        $stmt->execute([$step, (int)$admin['id'], $step]);
        return $stmt->rowCount() === 1 ? 'totp' : null;
    }
    $typed = strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
    if (strlen($typed) !== 8) return null;
    $typed = substr($typed, 0, 4) . '-' . substr($typed, 4);
    $hashes = json_decode((string)($admin['totp_recovery'] ?? '[]'), true) ?: [];
    foreach ($hashes as $i => $hash) {
        if (password_verify($typed, $hash)) {
            unset($hashes[$i]);
            $db->prepare('UPDATE admin_users SET totp_recovery = ? WHERE id = ?')->execute([json_encode(array_values($hashes)), (int)$admin['id']]);
            return 'recovery';
        }
    }
    return null;
}

/** How many unused recovery codes this account has left. */
function adminRecoveryCodesLeft(array $admin): int
{
    return count(json_decode((string)($admin['totp_recovery'] ?? '[]'), true) ?: []);
}

/**
 * Completes a staff sign-in: fresh session id, account in the session,
 * last-login time, activity log and (if asked) a remembered device.
 */
function adminFinishSignIn(array $admin, bool $remember, string $how): void
{
    sessionMarkSignedIn();
    unset($_SESSION['pending_2fa']);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_name'] = $admin['admin_name'] ?? $admin['full_name'] ?? 'Staff';
    $_SESSION['admin_auth_version'] = (int)($admin['auth_version'] ?? 0);
    try {
        getDb()->prepare('UPDATE admin_users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int)$admin['id']]);
    } catch (Throwable $e) { /* column not there yet: harmless */ }
    auditLog('staff.login', null, trim($how . ($remember ? ', remember this device' : ''), ', '));
    if ($remember) {
        adminRememberIssue((int)$admin['id']);
    }
}

/**
 * Ends every other session of an account: bumps auth_version (older
 * sessions are refused by requireAdmin()) and forgets remembered devices.
 * $keepThisSession leaves the caller's own session and device signed in.
 */
function adminSignOutEverywhere(int $adminId, bool $keepThisSession): void
{
    $db = getDb();
    try {
        $db->prepare('UPDATE admin_users SET auth_version = auth_version + 1 WHERE id = ?')->execute([$adminId]);
    } catch (Throwable $e) { /* column not there yet */ }
    adminRememberForgetAll($adminId, $keepThisSession);
    if ($keepThisSession && (int)($_SESSION['admin_id'] ?? 0) === $adminId) {
        $stmt = $db->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([$adminId]);
        $_SESSION['admin_auth_version'] = (int)($stmt->fetch()['auth_version'] ?? 0);
    }
}

/** Display name for a staff account row (live DB: admin_name; old: full_name). */
function adminDisplayName(array $admin): string
{
    return (string)($admin['admin_name'] ?? $admin['full_name'] ?? 'Staff');
}

/**
 * Who the signed-in account may send client messages as: [processors[], locked].
 * An account linked to a processor sends only as that person (locked);
 * the shared/team login picks from every active processor.
 */
function staffSenderOptions(): array
{
    $admin = currentAdmin();
    $linked = (int)($admin['processor_id'] ?? 0);
    if ($linked > 0) {
        $stmt = getDb()->prepare('SELECT id, full_name FROM processors WHERE id = ?');
        $stmt->execute([$linked]);
        $row = $stmt->fetch();
        if ($row) return [[$row], true];
    }
    return [getDb()->query('SELECT id, full_name FROM processors WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll(), false];
}
