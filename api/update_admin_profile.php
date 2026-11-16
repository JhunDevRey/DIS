<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$db = getDb();
$action = $_POST['action'] ?? '';
$adminId = (int)$_SESSION['admin_id'];

if ($action === 'update_name') {
    $fullName = trim($_POST['full_name'] ?? '');
    if ($fullName === '') {
        jsonResponse(false, 'Full name is required.');
    }
    $db->prepare('UPDATE admin_users SET admin_name = ? WHERE id = ?')->execute([$fullName, $adminId]);
    $_SESSION['admin_name'] = $fullName;
    jsonResponse(true, 'Profile updated.');
}

if ($action === 'update_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';

    if (strlen($new) < 6) {
        jsonResponse(false, 'New password must be at least 6 characters.');
    }

    $stmt = $db->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
    $stmt->execute([$adminId]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        jsonResponse(false, 'Current password is incorrect.');
    }

    $db->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
       ->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);

    // Staff share this login: every other remembered device must now sign
    // in with the new password. This one stays signed in.
    $signedOut = adminRememberForgetAll($adminId, true);
    auditLog('staff.password_changed');
    jsonResponse(true, 'Password updated.' . ($signedOut ? ' ' . $signedOut . ' other device' . ($signedOut === 1 ? ' was' : 's were') . ' signed out.' : ''));
}

if ($action === 'forget_devices') {
    $n = adminRememberForgetAll($adminId, true);
    auditLog('staff.devices_signed_out', null, $n . ' device(s)');
    jsonResponse(true, $n ? 'Signed out of ' . $n . ' other device' . ($n === 1 ? '' : 's') . '.' : 'No other devices were signed in.');
}

jsonResponse(false, 'Unknown action.');
