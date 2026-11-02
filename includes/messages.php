<?php
/**
 * includes/messages.php
 * Shared helpers for Messages — the staff <-> client inbox. Used by
 * admin/inbox.php, portal/messages.php, both sidebars (unread badges),
 * and the api/*_messages.php endpoints.
 *
 * Model: one ongoing conversation per client, shared by all staff. Read
 * state is tracked per side by message id (see sql/migration_messages.sql):
 * clients.client_messages_seen_id for the client, and
 * clients.staff_messages_seen_id for staff.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

define('MESSAGE_MAX_LENGTH', 5000);
define('MESSAGE_MAX_FILES', 5);
define('MESSAGE_UPLOAD_DIR', UPLOAD_DIR . '/messages');

/**
 * Unread staff messages for one client (what the portal sidebar badge shows).
 */
function clientUnreadMessageCount(int $clientId): int
{
    $stmt = getDb()->prepare("
      SELECT COUNT(*) FROM messages m
      JOIN clients c ON c.id = m.client_id
      WHERE m.client_id = ? AND m.sender_type = 'admin' AND m.id > c.client_messages_seen_id
    ");
    $stmt->execute([$clientId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Number of CONVERSATIONS with at least one unread client message (what
 * the admin sidebar badge shows) — counted per client rather than per
 * message, so one chatty client doesn't inflate it.
 */
function staffUnreadConversationCount(): int
{
    return (int)getDb()->query("
      SELECT COUNT(DISTINCT m.client_id) FROM messages m
      JOIN clients c ON c.id = m.client_id
      WHERE m.sender_type = 'client' AND m.id > c.staff_messages_seen_id
    ")->fetchColumn();
}

/**
 * Inbox list for staff: every client that has at least one message, most
 * recent activity first, with the latest message preview and unread count.
 */
function staffConversationList(): array
{
    return getDb()->query("
      SELECT c.id AS client_id, c.full_name, c.case_type, c.reference_number,
        pr.full_name AS processor_name,
        last.id AS last_message_id, last.body AS last_body,
        last.sender_type AS last_sender_type, last.created_at AS last_at,
        (SELECT COUNT(*) FROM messages u
          WHERE u.client_id = c.id AND u.sender_type = 'client' AND u.id > c.staff_messages_seen_id) AS unread_count
      FROM clients c
      JOIN messages last ON last.id = (SELECT MAX(id) FROM messages WHERE client_id = c.id)
      LEFT JOIN processors pr ON pr.id = c.processor_id
      ORDER BY last.id DESC
    ")->fetchAll();
}

/**
 * staffConversationList() shaped for the inbox JS (shared by admin/inbox.php
 * and api/admin_messages.php so both render the same thing).
 */
function staffConversationsForJson(): array
{
    return array_map(fn($c) => [
        'client_id'    => (int)$c['client_id'],
        'full_name'    => $c['full_name'], // kept here too: a deactivated client isn't in the directory
        'preview'      => ($c['last_sender_type'] === 'admin' ? 'You: ' : '') . mb_strimwidth($c['last_body'] !== '' ? $c['last_body'] : '📎 Attachment', 0, 90, '…'),
        'last_at'      => $c['last_at'],
        'unread_count' => (int)$c['unread_count'],
    ], staffConversationList());
}

/**
 * Every active client, with the fields the inbox's "Search clients" box and
 * Class / Processor filters match against.
 */
function staffClientDirectory(): array
{
    $rows = getDb()->query("
      SELECT c.id, c.full_name, c.email, c.case_type, c.reference_number, p.full_name AS processor_name
      FROM clients c
      LEFT JOIN processors p ON p.id = c.processor_id
      WHERE c.is_active = 1
      ORDER BY c.full_name ASC
    ")->fetchAll();

    return array_map(function (array $c) {
        $caseType = trim((string)$c['case_type']);
        $category = $caseType === '' ? null : (classCategoryFor($caseType) ?? 'Other');
        return [
            'client_id'  => (int)$c['id'],
            'full_name'  => $c['full_name'],
            'email'      => $c['email'],
            'case_type'  => $caseType,
            'class'      => $category,
            'class_slug' => $category ? classCategorySlug($category) : '',
            'ref'        => (string)($c['reference_number'] ?? ''),
            'processor'  => (string)($c['processor_name'] ?? ''),
        ];
    }, $rows);
}

/**
 * Messages for one client, oldest first, each with its 'files' list.
 * Pass $afterId to fetch only messages newer than that (used by polling).
 */
function fetchMessages(int $clientId, int $afterId = 0): array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM messages WHERE client_id = ? AND id > ? ORDER BY id ASC');
    $stmt->execute([$clientId, $afterId]);
    $messages = $stmt->fetchAll();
    if (!$messages) {
        return [];
    }

    $ids = array_column($messages, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, message_id, original_name, file_size FROM message_files WHERE message_id IN ($placeholders) ORDER BY id ASC");
    $stmt->execute($ids);
    $filesByMessage = [];
    foreach ($stmt->fetchAll() as $f) {
        $filesByMessage[$f['message_id']][] = $f;
    }

    foreach ($messages as &$m) {
        $m['files'] = $filesByMessage[$m['id']] ?? [];
    }
    unset($m);
    return $messages;
}

/**
 * Shapes messages for the JSON the inbox JS renders. The body is sent
 * pre-rendered through linkifyComment() — escaped first, then links
 * wrapped — so the browser never inserts raw user text as HTML.
 * $viewer is 'admin' or 'client'; 'mine' marks bubbles on the viewer's side.
 */
function messagesForJson(array $messages, string $viewer): array
{
    return array_map(function (array $m) use ($viewer) {
        if ($m['sender_type'] === 'admin') {
            $sender = $m['sender_name'] ?: 'Duque Immigration';
        } else {
            $sender = $viewer === 'client' ? 'You' : ($m['sender_name'] ?: 'Client');
        }
        return [
            'id'          => (int)$m['id'],
            'mine'        => $m['sender_type'] === $viewer,
            'sender_type' => $m['sender_type'],
            'sender'      => $sender,
            // Line breaks are kept by the .msg-body white-space: pre-wrap rule.
            'body_html'   => linkifyComment($m['body']),
            'created_at'  => $m['created_at'],
            'files'       => array_map(fn($f) => [
                'id'   => (int)$f['id'],
                'name' => $f['original_name'],
                'size' => (int)$f['file_size'],
            ], $m['files']),
        ];
    }, $messages);
}

/**
 * Validates every attached file up front, so a message is never half-sent
 * (text saved, then one attachment rejected). Returns null if all are
 * fine, or the first error message.
 */
function validateMessageAttachments(array $files): ?string
{
    if (count($files) > MESSAGE_MAX_FILES) {
        return 'You can attach up to ' . MESSAGE_MAX_FILES . ' files per message.';
    }
    foreach ($files as $file) {
        $check = validateUploadedDocument($file);
        if (!$check['ok']) {
            return $file['name'] . ': ' . $check['error'];
        }
    }
    return null;
}

/**
 * Saves a message (and its already-validated attachments) in one
 * transaction. Returns the new message id. Throws on failure — callers
 * catch and respond with JSON.
 */
function createMessage(int $clientId, string $senderType, string $senderName, string $body, array $files): int
{
    $db = getDb();
    if ($files && !is_dir(MESSAGE_UPLOAD_DIR)) {
        mkdir(MESSAGE_UPLOAD_DIR, 0755, true);
    }

    $movedPaths = [];
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO messages (client_id, sender_type, sender_name, body) VALUES (?, ?, ?, ?)')
           ->execute([$clientId, $senderType, $senderName, $body]);
        $messageId = (int)$db->lastInsertId();

        foreach ($files as $i => $file) {
            $storedName = 'msg' . $messageId . '_' . $i . '_' . time() . '_' . sanitizeFilename($file['name']);
            $destination = MESSAGE_UPLOAD_DIR . '/' . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new RuntimeException('Could not save the attached file.');
            }
            $movedPaths[] = $destination;
            $db->prepare('INSERT INTO message_files (message_id, original_name, stored_name, file_size) VALUES (?, ?, ?, ?)')
               ->execute([$messageId, $file['name'], $storedName, (int)$file['size']]);
        }

        // Sending a message means you've seen the conversation up to it.
        $seenColumn = $senderType === 'admin' ? 'staff_messages_seen_id' : 'client_messages_seen_id';
        $db->prepare("UPDATE clients SET {$seenColumn} = GREATEST({$seenColumn}, ?) WHERE id = ?")
           ->execute([$messageId, $clientId]);

        $db->commit();
        return $messageId;
    } catch (Throwable $e) {
        $db->rollBack();
        foreach ($movedPaths as $p) {
            @unlink($p);
        }
        throw $e;
    }
}

/**
 * Marks a conversation read up to $upToId for one side. GREATEST() keeps
 * it from ever moving backwards (e.g. an older tab reporting late).
 * $side is 'admin' or 'client' — mapped to a fixed column, never user input.
 */
function markMessagesSeen(int $clientId, string $side, int $upToId): void
{
    $column = $side === 'admin' ? 'staff_messages_seen_id' : 'client_messages_seen_id';
    getDb()->prepare("UPDATE clients SET {$column} = GREATEST({$column}, ?) WHERE id = ?")
           ->execute([$upToId, $clientId]);
}

/**
 * Streams a message attachment. Caller has already checked access.
 */
function streamMessageFile(array $file, bool $inline): void
{
    $path = MESSAGE_UPLOAD_DIR . '/' . $file['stored_name'];
    if (!is_file($path)) {
        http_response_code(404);
        die('File not found on disk.');
    }

    // Same reasoning as api/client_download_file.php — no compression or
    // buffering, so Content-Length always matches the bytes actually sent.
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', 'Off');
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $safeName = str_replace('"', '', $file['original_name']);
    header('Content-Type: ' . ($inline ? mimeTypeForExtension($file['original_name']) : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    readfile($path);
    exit;
}
