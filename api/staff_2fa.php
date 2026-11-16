<?php
/**
 * api/staff_2fa.php — two-step sign-in for the signed-in staff account.
 *   POST action=start, password        -> new secret (kept in the session until confirmed): {secret, uri}
 *   POST action=confirm, code          -> turns it on; returns one-time recovery codes (shown once)
 *   POST action=disable, password, code -> turns it off (not allowed when ADMIN_REQUIRE_2FA)
 *   POST action=recovery, code         -> replaces the recovery codes
 * Turning it on or off signs this account out of every other device.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff_security.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}
if (!twoFactorAvailable()) {
    jsonResponse(false, 'Two-step sign-in needs SECURITY_KEY in config.php first.');
}

$db = getDb();
$admin = currentAdmin();
$adminId = (int)$admin['id'];
$action = $_POST['action'] ?? '';

// Password and code guesses here are throttled like signing in.
enforceActionLimit('2fa-manage:' . $adminId, 10, 15, 'Too many attempts. Please wait 15 minutes and try again.');

$passwordOk = fn() => password_verify((string)($_POST['password'] ?? ''), $admin['password_hash']);

if ($action === 'start') {
    if (!$passwordOk()) jsonResponse(false, 'Your password is incorrect.');
    $secret = totpNewSecret();
    $_SESSION['totp_setup'] = ['secret' => totpEncryptSecret($secret), 'at' => time()];
    jsonResponse(true, '', [
        'secret' => trim(chunk_split($secret, 4, ' ')),
        'uri'    => totpUri($secret, $admin['username']),
    ]);
}

if ($action === 'confirm') {
    $setup = $_SESSION['totp_setup'] ?? null;
    if (!$setup || time() - (int)$setup['at'] > 900) {
        jsonResponse(false, 'Setup timed out. Please start again.');
    }
    $secret = totpDecryptSecret($setup['secret']);
    $step = $secret ? totpVerify($secret, (string)($_POST['code'] ?? '')) : null;
    if ($step === null) {
        jsonResponse(false, "That code didn't match. Check the time on your phone is set automatically, then try the newest code.");
    }
    [$codes, $hashes] = newRecoveryCodes();
    $db->prepare('UPDATE admin_users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ?, totp_recovery = ? WHERE id = ?')
       ->execute([$setup['secret'], $step, json_encode($hashes), $adminId]);
    unset($_SESSION['totp_setup']);
    adminSignOutEverywhere($adminId, true);
    auditLog('staff.2fa_enabled');
    jsonResponse(true, 'Two-step sign-in is on.', ['recovery_codes' => $codes]);
}

if ($action === 'disable') {
    if (adminRequire2fa()) jsonResponse(false, 'Two-step sign-in is required for every staff account.');
    if (!adminUses2fa($admin)) jsonResponse(false, 'Two-step sign-in is already off.');
    if (!$passwordOk()) jsonResponse(false, 'Your password is incorrect.');
    if (adminCheckSecondFactor($admin, (string)($_POST['code'] ?? '')) === null) jsonResponse(false, "That code didn't work.");
    $db->prepare('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL, totp_recovery = NULL WHERE id = ?')->execute([$adminId]);
    adminSignOutEverywhere($adminId, true);
    auditLog('staff.2fa_disabled');
    jsonResponse(true, 'Two-step sign-in is off.');
}

if ($action === 'recovery') {
    if (!adminUses2fa($admin)) jsonResponse(false, 'Turn on two-step sign-in first.');
    if (adminCheckSecondFactor($admin, (string)($_POST['code'] ?? '')) === null) jsonResponse(false, "That code didn't work.");
    [$codes, $hashes] = newRecoveryCodes();
    $db->prepare('UPDATE admin_users SET totp_recovery = ? WHERE id = ?')->execute([json_encode($hashes), $adminId]);
    auditLog('staff.2fa_enabled', null, 'new recovery codes');
    jsonResponse(true, 'New recovery codes made. The old ones no longer work.', ['recovery_codes' => $codes]);
}

jsonResponse(false, 'Unknown action.');
