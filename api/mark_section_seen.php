<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireClient();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$section = $input['section'] ?? '';

$columns = [
    'documents' => 'documents_seen_at',
    'review'    => 'review_seen_at',
];
if (!isset($columns[$section])) {
    jsonResponse(false, 'Invalid section.');
}

$clientId = (int)$_SESSION['client_id'];
$column = $columns[$section];

// Column name comes from the fixed $columns map above, never from user
// input directly, so this is safe to interpolate into the query.
$stmt = getDb()->prepare("UPDATE clients SET {$column} = NOW() WHERE id = ?");
$stmt->execute([$clientId]);

jsonResponse(true);
