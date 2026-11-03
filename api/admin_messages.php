<?php
/**
 * api/admin_messages.php
 * Staff side of Messages.
 *   GET  ?list=1                       -> conversation list + unread total (inbox sidebar refresh)
 *   GET  ?client_id=N&after_id=M       -> messages in one conversation newer than M (polling)
 *   POST action=send, client_id, body, attachments[], reply_to_id?, channel=portal|whatsapp -> send a message
 *   POST action=seen, client_id, up_to_id             -> mark conversation read
 *   POST action=edit, client_id, message_id, body     -> edit a staff message (within 2h)
 *   GET  ?client_id=N&history=ID                      -> earlier versions of an edited message
 *   POST action=link_wa, wa_number, client_id         -> link an unmatched WhatsApp number
 *   POST action=ignore_wa, wa_number                  -> hide an unmatched WhatsApp number
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/whatsapp.php';
requireAdmin();

$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['list'])) {
        jsonResponse(true, '', [
            'conversations' => staffConversationsForJson(),
            'clients'       => staffClientDirectory(),
            'unread_total'  => staffUnreadConversationCount() + (waEnabled() ? waUnmatchedCount() : 0),
            'wa_unmatched'  => waEnabled() ? waUnmatchedCount() : 0,
        ]);
    }

    $clientId = (int)($_GET['client_id'] ?? 0);
    $afterId = max(0, (int)($_GET['after_id'] ?? 0));
    if ($clientId <= 0) {
        jsonResponse(false, 'Missing client.');
    }
    if (!empty($_GET['history'])) {
        jsonResponse(true, '', ['versions' => messageEditHistory($clientId, (int)$_GET['history'])]);
    }
    // Recent WhatsApp delivery states, so ticks on messages already on
    // screen update while the conversation is open.
    $statuses = [];
    if (waEnabled()) {
        $stmt = $db->prepare('SELECT id, wa_status, wa_error FROM messages WHERE client_id = ? AND wa_status IS NOT NULL ORDER BY id DESC LIMIT 50');
        $stmt->execute([$clientId]);
        $statuses = array_map(fn($r) => ['id' => (int)$r['id'], 'wa_status' => $r['wa_status'], 'wa_error' => $r['wa_error']], $stmt->fetchAll());
    }
    jsonResponse(true, '', [
        'messages'    => messagesForJson(fetchMessages($clientId, $afterId), 'admin'),
        'edits'       => recentMessageEdits($clientId, 'admin'),
        'wa_statuses' => $statuses,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$action = $_POST['action'] ?? '';
$clientId = (int)($_POST['client_id'] ?? 0);

if ($action === 'ignore_wa') {
    $number = preg_replace('/\D/', '', (string)($_POST['wa_number'] ?? ''));
    if ($number === '') jsonResponse(false, 'Missing number.');
    waIgnoreNumber($number);
    jsonResponse(true, 'Hidden.');
}

$stmt = $db->prepare('SELECT id, full_name, email, cc_emails, phone, whatsapp_number, processor_id, client_messages_seen_id FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    jsonResponse(false, 'Client not found.');
}

if ($action === 'seen') {
    markMessagesSeen($clientId, 'admin', (int)($_POST['up_to_id'] ?? 0));
    jsonResponse(true);
}

// Who's acting: staff share one login, so it's whoever is picked in the
// inbox's "Sending as" menu — looked up from processors by id (never
// trusted as free text). No pick = the team name.
function inboxActorName(PDO $db): string
{
    $senderId = (int)($_POST['sender_id'] ?? 0);
    if ($senderId > 0) {
        $stmt = $db->prepare('SELECT full_name FROM processors WHERE id = ? AND is_active = 1');
        $stmt->execute([$senderId]);
        $name = trim((string)$stmt->fetchColumn());
        if ($name !== '') return $name;
    }
    return 'Duque Immigration';
}

if ($action === 'edit') {
    $messageId = (int)($_POST['message_id'] ?? 0);
    try {
        $error = editStaffMessage($clientId, $messageId, trim($_POST['body'] ?? ''), inboxActorName($db));
    } catch (Throwable $e) {
        error_log('admin_messages edit failed: ' . $e->getMessage());
        $error = 'Could not save the edit. Please try again.';
    }
    if ($error !== null) {
        jsonResponse(false, $error);
    }
    jsonResponse(true, 'Message updated.', ['messages' => messagesForJson(array_values(array_filter(fetchMessages($clientId), fn($m) => (int)$m['id'] === $messageId)), 'admin')]);
}

if ($action === 'link_wa') {
    $number = preg_replace('/\D/', '', (string)($_POST['wa_number'] ?? ''));
    if ($number === '') jsonResponse(false, 'Missing number.');
    try {
        $moved = waLinkNumber($number, $clientId);
    } catch (Throwable $e) {
        error_log('link_wa failed: ' . $e->getMessage());
        jsonResponse(false, 'Could not link the number. Please try again.');
    }
    jsonResponse(true, waFormatNumber($number) . ' linked to ' . $client['full_name'] . ' — ' . $moved . ' message' . ($moved === 1 ? '' : 's') . ' moved.', ['client_id' => $clientId]);
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

$senderName = inboxActorName($db);

try {
    $messageId = createMessage($clientId, 'admin', $senderName, $body, $files, (int)($_POST['reply_to_id'] ?? 0));
} catch (Throwable $e) {
    error_log('admin_messages send failed: ' . $e->getMessage());
    jsonResponse(false, 'Could not send the message. Please try again.');
}

// WhatsApp: the message is saved first (so it's never lost), then sent.
// If it can't go on WhatsApp it stays a portal message — see waDeliverStaffMessage().
$note = 'Message sent.';
$channel = 'portal';
if (($_POST['channel'] ?? '') === 'whatsapp' && waEnabled()) {
    $stmt = $db->prepare('SELECT original_name, stored_name FROM message_files WHERE message_id = ? ORDER BY id ASC');
    $stmt->execute([$messageId]);
    $note = waDeliverStaffMessage($messageId, $client, $body, $stmt->fetchAll());
    $stmt = $db->prepare('SELECT channel FROM messages WHERE id = ?');
    $stmt->execute([$messageId]);
    $channel = $stmt->fetchColumn();
}

// The email notice is for portal messages; WhatsApp ones reach the phone directly.
if ($channel === 'portal' && !$alreadyNotified) {
    notifyClientNewMessage($client, $senderName);
}

jsonResponse(true, $note, ['messages' => messagesForJson(fetchMessages($clientId, $messageId - 1), 'admin')]);
