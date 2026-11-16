<?php
/**
 * api/staff_accounts.php — owners manage staff accounts (admin/staff.php).
 *   POST action=create, username, admin_name, email?, processor_id?, is_owner?, password
 *   POST action=update, id, username, admin_name, email?, processor_id?, is_owner?
 *   POST action=set_password, id, password      -> temporary; must be changed at next sign-in
 *   POST action=reset_2fa, id                   -> they set up two-step sign-in again
 *   POST action=set_active, id, active=0|1
 *   POST action=sign_out, id                    -> ends every session and remembered device
 * There is always at least one active owner, and owners can't deactivate
 * or demote themselves (so nobody locks the team out by accident).
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff_security.php';
requireAdmin();
requireOwner();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$db = getDb();
$me = currentAdmin();
$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

function staffAccountRow(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(false, 'Staff account not found.');
    return $row;
}

function activeOwnerCount(PDO $db, int $exceptId = 0): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM admin_users WHERE is_owner = 1 AND is_active = 1 AND id <> ?');
    $stmt->execute([$exceptId]);
    return (int)$stmt->fetchColumn();
}

/** Validated account fields from the form (shared by create and update). */
function staffAccountFields(PDO $db, int $exceptId): array
{
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $name = trim((string)($_POST['admin_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $processorId = (int)($_POST['processor_id'] ?? 0);
    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
        jsonResponse(false, 'Username: 3 to 50 letters, numbers, dots, dashes or underscores.');
    }
    if ($name === '' || mb_strlen($name) > 100) {
        jsonResponse(false, 'Please enter the person\'s name.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'That email address doesn\'t look right.');
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ? AND id <> ?');
    $stmt->execute([$username, $exceptId]);
    if ((int)$stmt->fetchColumn()) {
        jsonResponse(false, 'That username is already taken.');
    }
    if ($processorId > 0) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM processors WHERE id = ?');
        $stmt->execute([$processorId]);
        if (!(int)$stmt->fetchColumn()) $processorId = 0;
    }
    return [$username, $name, $email ?: null, $processorId ?: null, !empty($_POST['is_owner']) ? 1 : 0];
}

if ($action === 'create') {
    [$username, $name, $email, $processorId, $isOwner] = staffAccountFields($db, 0);
    $password = (string)($_POST['password'] ?? '');
    $problem = staffPasswordProblem($password, $username);
    if ($problem !== null) jsonResponse(false, 'Temporary password: ' . lcfirst($problem));
    // Old schema copies had a NOT NULL full_name column; fill both names.
    $cols = array_column($db->query('SHOW COLUMNS FROM admin_users')->fetchAll(), 'Field');
    $nameCols = in_array('full_name', $cols, true) ? ', full_name' : '';
    $db->prepare("INSERT INTO admin_users (username, password_hash, admin_name{$nameCols}, email, processor_id, is_owner, is_active, must_change_password) VALUES (?, ?, ?" . ($nameCols ? ', ?' : '') . ", ?, ?, ?, 1, 1)")
       ->execute(array_merge([$username, password_hash($password, PASSWORD_DEFAULT), $name], $nameCols ? [$name] : [], [$email, $processorId, $isOwner]));
    $newId = (int)$db->lastInsertId();
    auditLog('staff.account_created', null, $name . ' (@' . $username . ')' . ($isOwner ? ', owner' : ''));
    jsonResponse(true, 'Account created. Give ' . $name . ' the username and temporary password; they choose their own at first sign-in.', ['id' => $newId]);
}

if ($action === 'update') {
    $row = staffAccountRow($db, $id);
    [$username, $name, $email, $processorId, $isOwner] = staffAccountFields($db, $id);
    if ((int)$row['is_owner'] === 1 && !$isOwner) {
        if ($id === (int)$me['id']) jsonResponse(false, "You can't remove your own owner role. Ask another owner.");
        if (activeOwnerCount($db, $id) === 0) jsonResponse(false, 'There must always be at least one active owner.');
    }
    $db->prepare('UPDATE admin_users SET username = ?, admin_name = ?, email = ?, processor_id = ?, is_owner = ? WHERE id = ?')
       ->execute([$username, $name, $email, $processorId, $isOwner, $id]);
    if ($id === (int)$me['id']) $_SESSION['admin_name'] = $name;
    auditLog('staff.account_updated', null, $name . ' (@' . $username . ')');
    jsonResponse(true, 'Saved.');
}

if ($action === 'set_password') {
    $row = staffAccountRow($db, $id);
    $password = (string)($_POST['password'] ?? '');
    $problem = staffPasswordProblem($password, (string)$row['username']);
    if ($problem !== null) jsonResponse(false, $problem);
    $db->prepare('UPDATE admin_users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
       ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    adminSignOutEverywhere($id, $id === (int)$me['id']);
    auditLog('staff.password_reset', null, adminDisplayName($row));
    jsonResponse(true, 'Temporary password set. ' . adminDisplayName($row) . ' was signed out everywhere and must choose a new password at next sign-in.');
}

if ($action === 'reset_2fa') {
    $row = staffAccountRow($db, $id);
    $db->prepare('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL, totp_recovery = NULL WHERE id = ?')->execute([$id]);
    adminSignOutEverywhere($id, $id === (int)$me['id']);
    auditLog('staff.2fa_reset', null, adminDisplayName($row));
    jsonResponse(true, 'Two-step sign-in reset for ' . adminDisplayName($row) . '. They set it up again on My Profile.');
}

if ($action === 'set_active') {
    $row = staffAccountRow($db, $id);
    $active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;
    if (!$active) {
        if ($id === (int)$me['id']) jsonResponse(false, "You can't deactivate your own account.");
        if ((int)$row['is_owner'] === 1 && activeOwnerCount($db, $id) === 0) jsonResponse(false, 'There must always be at least one active owner.');
    }
    $db->prepare('UPDATE admin_users SET is_active = ? WHERE id = ?')->execute([$active, $id]);
    if (!$active) adminSignOutEverywhere($id, false);
    auditLog($active ? 'staff.account_enabled' : 'staff.account_disabled', null, adminDisplayName($row));
    jsonResponse(true, $active ? adminDisplayName($row) . ' can sign in again.' : adminDisplayName($row) . ' is deactivated and was signed out everywhere.');
}

if ($action === 'sign_out') {
    $row = staffAccountRow($db, $id);
    adminSignOutEverywhere($id, $id === (int)$me['id']);
    auditLog('staff.devices_signed_out', null, adminDisplayName($row) . ' (by owner)');
    jsonResponse(true, adminDisplayName($row) . ' was signed out on every device.');
}

jsonResponse(false, 'Unknown action.');
