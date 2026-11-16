<?php
/**
 * api/update_admin_profile.php — the signed-in staff account's own profile.
 *   POST action=update_name, full_name
 *   POST action=update_password, current_password, new_password
 *   POST action=forget_devices   -> sign this account out everywhere else
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff_security.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$db = getDb();
$action = $_POST['action'] ?? '';
$admin = currentAdmin();
$adminId = (int)$admin['id'];

if ($action === 'update_name') {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    if ($fullName === '' || mb_strlen($fullName) > 100) {
        jsonResponse(false, 'Please enter your name (up to 100 characters).');
    }
    $db->prepare('UPDATE admin_users SET admin_name = ? WHERE id = ?')->execute([$fullName, $adminId]);
    $_SESSION['admin_name'] = $fullName;
    jsonResponse(true, 'Profile updated.');
}

if ($action === 'update_password') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');

    // Guessing the current password here is throttled like signing in.
    enforceActionLimit('pwchange:staff:' . $adminId, 10, 15, 'Too many attempts. Please wait 15 minutes and try again.');

    if (!password_verify($current, $admin['password_hash'])) {
        jsonResponse(false, 'Current password is incorrect.');
    }
    $problem = staffPasswordProblem($new, (string)$admin['username']);
    if ($problem !== null) {
        jsonResponse(false, $problem);
    }
    if (password_verify($new, $admin['password_hash'])) {
        jsonResponse(false, 'Please choose a password different from the current one.');
    }

    $db->prepare('UPDATE admin_users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
       ->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);

    // Every other session and remembered device of this account must sign
    // in with the new password. This one stays signed in.
    $otherDevices = adminRememberOtherDeviceCount($adminId);
    adminSignOutEverywhere($adminId, true);
    auditLog('staff.password_changed');
    jsonResponse(true, 'Password updated.' . ($otherDevices ? ' ' . $otherDevices . ' other device' . ($otherDevices === 1 ? ' was' : 's were') . ' signed out.' : ''));
}

if ($action === 'forget_devices') {
    $n = adminRememberOtherDeviceCount($adminId);
    adminSignOutEverywhere($adminId, true);
    auditLog('staff.devices_signed_out', null, $n . ' remembered device(s)');
    jsonResponse(true, 'Signed out of every other device' . ($n ? ' (' . $n . ' remembered)' : '') . '.');
}

jsonResponse(false, 'Unknown action.');
