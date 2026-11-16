<?php
/**
 * api/admin_send_client_reset.php
 *   POST client_id -> emails the client a one-time link (valid 7 days) to
 *                     choose their own password. Sent to their login email
 *                     only, not the CC contacts.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/client_passwords.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$clientId = (int)($_POST['client_id'] ?? 0);
$stmt = getDb()->prepare('SELECT id, full_name, email, is_active FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    jsonResponse(false, 'Client not found.');
}
if (!(int)$client['is_active']) {
    jsonResponse(false, "This client's portal access is switched off.");
}

enforceActionLimit('resetlink:staff:' . $clientId, 5, 60, 'A reset link was sent several times in the last hour. Please wait before sending another.');

$url = createClientResetLink($clientId, 'staff');
if (!sendClientResetEmail($client, $url, 'staff')) {
    jsonResponse(false, 'Could not send the email. Check the client\'s email address and try again.');
}
auditLog('client.reset_link_sent', $clientId, 'to ' . $client['email']);
jsonResponse(true, 'Reset link sent to ' . $client['email'] . '.');
