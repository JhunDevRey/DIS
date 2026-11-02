<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$clientId = (int)($_POST['client_id'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

if ($clientId <= 0) {
    jsonResponse(false, 'Client not found.');
}

$db = getDb();
$stmt = $db->prepare('SELECT id FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
if (!$stmt->fetch()) {
    jsonResponse(false, 'Client not found.');
}

$db->prepare('UPDATE clients SET notes = ? WHERE id = ?')->execute([$notes ?: null, $clientId]);

jsonResponse(true, 'Notes saved.');
