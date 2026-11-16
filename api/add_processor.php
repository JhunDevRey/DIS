<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$fullName = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');

if ($fullName === '' || $email === '') {
    jsonResponse(false, 'Name and email are required.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, 'Please enter a valid email address.');
}

$db = getDb();
$stmt = $db->prepare('INSERT INTO processors (full_name, email) VALUES (?, ?)');
$stmt->execute([$fullName, $email]);

auditLog('processor.created', null, $fullName . ' <' . $email . '>');
jsonResponse(true, 'Processor added.', ['processor_id' => $db->lastInsertId()]);
