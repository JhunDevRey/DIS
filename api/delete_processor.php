<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$processorId = (int)($_POST['processor_id'] ?? 0);
if ($processorId <= 0) {
    jsonResponse(false, 'Processor not found.');
}

$db = getDb();
// Clients assigned to this processor fall back to unassigned (processor_id
// is nullable with ON DELETE SET NULL on the FK, but we do it explicitly
// too in case the FK constraint wasn't applied via migration).
$db->prepare('UPDATE clients SET processor_id = NULL WHERE processor_id = ?')->execute([$processorId]);
$db->prepare('DELETE FROM processors WHERE id = ?')->execute([$processorId]);

jsonResponse(true, 'Processor removed.');
