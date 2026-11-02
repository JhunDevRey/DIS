<?php
/**
 * api/admin_messages.php
 * Staff side of Messages.
 *   GET  ?list=1                       -> conversation list + unread total (inbox sidebar refresh)
 *   GET  ?client_id=N&after_id=M       -> messages in one conversation newer than M (polling)
 *   POST action=send, client_id, body, attachments[]  -> send a message
 *   POST action=seen, client_id, up_to_id             -> mark conversation read
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['list'])) {
        jsonResponse(true, '', [
            'conversations' => staffConversationsForJson(),
            'clients'       => staffClientDirectory(),
            'unread_total'  => staffUnreadConversationCount(),
        ]);
    }

    $clientId = (int)($_GET['client_id'] ?? 0);
    $afterId = max(0, (int)($_GET['after_id'] ?? 0));
    if ($clientId <= 0) {
        jsonResponse(false, 'Missing client.');
    }
    jsonResponse(true, '', ['messages' => messagesForJson(fetchMessages($clientId, $afterId), 'admin')]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$action = $_POST['action'] ?? '';
$clientId = (int)($_POST['client_id'] ?? 0);

$stmt = $db->prepare('SELECT id, full_name, email, cc_emails, processor_id, client_messages_seen_id FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    jsonResponse(false, 'Client not found.');
}

if ($action === 'seen') {
    markMessagesSeen($clientId, 'admin', (int)($_POST['up_to_id'] ?? 0));
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

// Throttle the client email: if they already have an unread staff
// message from the last 15 minutes, they've already been emailed about
// it — a burst of quick replies sends one email, not one per message.
$stmt = $db->prepare("
  SELECT COUNT(*) FROM messages
  WHERE client_id = ? AND sender_type = 'admin' AND id > ? AND created_at > NOW() - INTERVAL 15 MINUTE
");
$stmt->execute([$clientId, (int)$client['client_messages_seen_id']]);
$alreadyNotified = (int)$stmt->fetchColumn() > 0;

// Staff share one login, so the sender is whoever picked their name in
// the inbox's "Sending as" menu — looked up here from processors by id
// (never trusted as free text). No pick = the team name.
$senderName = 'Duque Immigration';
$senderId = (int)($_POST['sender_id'] ?? 0);
if ($senderId > 0) {
    $stmt = $db->prepare('SELECT full_name FROM processors WHERE id = ? AND is_active = 1');
    $stmt->execute([$senderId]);
    $senderName = trim((string)$stmt->fetchColumn()) ?: $senderName;
}

try {
    $messageId = createMessage($clientId, 'admin', $senderName, $body, $files);
} catch (Throwable $e) {
    error_log('admin_messages send failed: ' . $e->getMessage());
    jsonResponse(false, 'Could not send the message. Please try again.');
}

if (!$alreadyNotified) {
    notifyClientNewMessage($client, $senderName);
}

jsonResponse(true, 'Message sent.', ['messages' => messagesForJson(fetchMessages($clientId, $messageId - 1), 'admin')]);
