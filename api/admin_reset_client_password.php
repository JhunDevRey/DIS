<?php
/**
 * api/admin_reset_client_password.php
 * Staff set a temporary password for a client (e.g. to read it out on the
 * phone). It is stored only as a hash and the client must choose their
 * own at the next sign-in. Usually "Send reset link" is the better option
 * (api/admin_send_client_reset.php): staff never know the password.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/client_passwords.php';
require_once __DIR__ . '/../includes/client_remember.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$clientId = (int)($_POST['client_id'] ?? 0);
$newPassword = (string)($_POST['new_password'] ?? '');

$stmt = getDb()->prepare('SELECT id, email FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    jsonResponse(false, 'Client not found.');
}
$problem = clientPasswordProblem($newPassword, (string)$client['email']);
if ($problem !== null) {
    jsonResponse(false, $problem);
}

setClientPassword($clientId, $newPassword, true);
auditLog('client.password_set', $clientId, 'temporary password');

// A new password signs the client out of every remembered device.
clientRememberForgetAll($clientId);

jsonResponse(true, 'Temporary password saved. The client will be asked to choose their own when they sign in.');
