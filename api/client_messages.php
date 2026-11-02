<?php
/**
 * api/client_messages.php
 * Client side of Messages. The client id always comes from the session,
 * never from the request, so a client can only ever reach their own
 * conversation.
 *   GET  ?after_id=M                         -> messages newer than M (polling)
 *   POST action=send, body, attachments[]    -> send a message to the case team
 *   POST action=seen, up_to_id               -> mark conversation read
 */
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/mailer.php';
requireClient();

$db = getDb();
$clientId = (int)$_SESSION['client_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $afterId = max(0, (int)($_GET['after_id'] ?? 0));
    jsonResponse(true, '', ['messages' => messagesForJson(fetchMessages($clientId, $afterId), 'client')]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$action = $_POST['action'] ?? '';

if ($action === 'seen') {
    markMessagesSeen($clientId, 'client', (int)($_POST['up_to_id'] ?? 0));
    jsonResponse(true);
}

if ($action !== 'send') {
    jsonResponse(false, 'Unknown action.');
}

$body = trim($_POST['body'] ?? '');
$files = normalizeUploadedFiles($_FILES['attachments'] ?? null);

if ($body === '' && !$files) {
    jsonResponse(false, 'Write a message or attach a file.');
}
if (mb_strlen($body) > MESSAGE_MAX_LENGTH) {
    jsonResponse(false, 'Message is too long (max ' . MESSAGE_MAX_LENGTH . ' characters).');
}
$fileError = validateMessageAttachments($files);
if ($fileError !== null) {
    jsonResponse(false, $fileError);
}

$stmt = $db->prepare('SELECT full_name, processor_id, staff_messages_seen_id FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();

// Same 15-minute burst throttle as the staff side, for the processor email.
$stmt = $db->prepare("
  SELECT COUNT(*) FROM messages
  WHERE client_id = ? AND sender_type = 'client' AND id > ? AND created_at > NOW() - INTERVAL 15 MINUTE
");
$stmt->execute([$clientId, (int)$client['staff_messages_seen_id']]);
$alreadyNotified = (int)$stmt->fetchColumn() > 0;

$senderName = $_SESSION['client_name'] ?? $client['full_name'];

try {
    $messageId = createMessage($clientId, 'client', $senderName, $body, $files);
} catch (Throwable $e) {
    error_log('client_messages send failed: ' . $e->getMessage());
    jsonResponse(false, 'Could not send your message. Please try again.');
}

// Assigned processor hears about it right away, threaded with their
// other emails about this client. Unassigned clients still show up in
// the staff inbox with an unread badge.
if (!$alreadyNotified && $client['processor_id']) {
    $preview = $body !== '' ? mb_strimwidth($body, 0, 300, '…') : '(sent ' . count($files) . ' attachment' . (count($files) === 1 ? '' : 's') . ')';
    notifyProcessorRealtime(
        (int)$client['processor_id'],
        $clientId,
        $senderName,
        'message',
        $senderName . ' sent a message: "' . $preview . '"'
    );
}

jsonResponse(true, 'Message sent.', ['messages' => messagesForJson(fetchMessages($clientId, $messageId - 1), 'client')]);
