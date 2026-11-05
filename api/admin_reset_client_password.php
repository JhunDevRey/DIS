<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$clientId = (int)($_POST['client_id'] ?? 0);
$newPassword = $_POST['new_password'] ?? '';

if ($clientId <= 0) {
    jsonResponse(false, 'Client not found.');
}
if (strlen($newPassword) < 6) {
    jsonResponse(false, 'Password must be at least 6 characters.');
}

$db = getDb();
$stmt = $db->prepare('SELECT id FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
if (!$stmt->fetch()) {
    jsonResponse(false, 'Client not found.');
}

$db->prepare('UPDATE clients SET password_hash = ?, plain_password = ? WHERE id = ?')
   ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $newPassword, $clientId]);

// A new password signs the client out of every remembered device.
require_once __DIR__ . '/../includes/client_remember.php';
clientRememberForgetAll($clientId);

jsonResponse(true, 'Password updated.', ['plain_password' => $newPassword]);
