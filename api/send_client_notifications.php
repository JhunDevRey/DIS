<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$clientId = (int)($_POST['client_id'] ?? 0);
if ($clientId <= 0) {
    jsonResponse(false, 'Client not found.');
}

$result = sendClientDigestNow($clientId);

if (!$result['sent']) {
    jsonResponse(false, $result['message'], ['nothing_pending' => $result['message'] === 'Nothing pending for this client.']);
}

jsonResponse(true, 'Sent.', [
    'events_sent' => $result['events_sent'],
    'last_sent_at' => getLastDigestSentAt($clientId),
]);
