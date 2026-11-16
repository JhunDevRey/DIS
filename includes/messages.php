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
 *
 * WhatsApp (includes/whatsapp.php) is a second channel in the same thread:
 * messages.channel = 'whatsapp'.
 *
 * Staff see the thread in two places ("scopes"): the Processing Inbox
 * (admin/inbox.php, scope 'portal': portal messages only) and the Front
 * Desk chats (admin/chats.php, scope 'social': WhatsApp, Messenger,
 * Instagram, TikTok). Each keeps its own read marker -
 * clients.staff_messages_seen_id and clients.staff_social_seen_id
 * (sql/migration_social_inbox.sql) - so reading one never marks the other. Imported past chats (is_history = 1)
 * never count as unread, and staff WhatsApp replies don't badge the
 * client's portal — they already got them on their phone.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

define('MESSAGE_MAX_LENGTH', 5000);
define('MESSAGE_MAX_FILES', 5);
// Staff can correct a message they sent for this long (see editStaffMessage()).
define('MESSAGE_EDIT_WINDOW_MINUTES', 120);
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
        AND m.channel = 'portal' AND m.is_history = 0
    ");
    $stmt->execute([$clientId]);
    return (int)$stmt->fetchColumn();
}

/** 'social' for the Front Desk chats, anything else is the Processing Inbox. */
function messageScope(?string $scope): string
{
    return $scope === 'social' ? 'social' : 'portal';
}

/** SQL condition picking one scope's messages (fixed strings, never user input). */
function messageScopeSql(string $scope, string $alias): string
{
    return messageScope($scope) === 'social' ? "{$alias}.channel <> 'portal'" : "{$alias}.channel = 'portal'";
}

/** The clients column holding staff's read marker for a scope. */
function staffSeenColumn(string $scope): string
{
    return messageScope($scope) === 'social' ? 'staff_social_seen_id' : 'staff_messages_seen_id';
}

/**
 * Number of CONVERSATIONS with at least one unread client message in a
 * scope (what the sidebar badges show) — counted per client rather than
 * per message, so one chatty client doesn't inflate it.
 */
function staffUnreadConversationCount(string $scope = 'portal'): int
{
    $seen = staffSeenColumn($scope);
    return (int)getDb()->query("
      SELECT COUNT(DISTINCT m.client_id) FROM messages m
      JOIN clients c ON c.id = m.client_id
      WHERE m.sender_type = 'client' AND m.id > c.{$seen} AND m.is_history = 0
        AND " . messageScopeSql($scope, 'm') . "
    ")->fetchColumn();
}

/**
 * Inbox list for staff, one scope: every client with at least one message
 * in it, most recent activity first, with that scope's latest message as
 * the preview and its unread count.
 */
function staffConversationList(string $scope = 'portal'): array
{
    $seen = staffSeenColumn($scope);
    $in = fn(string $alias) => messageScopeSql($scope, $alias);
    return getDb()->query("
      SELECT c.id AS client_id, c.full_name, c.case_type, c.reference_number,
        pr.full_name AS processor_name,
        last.id AS last_message_id, last.body AS last_body,
        last.sender_type AS last_sender_type, last.channel AS last_channel, last.created_at AS last_at,
        (SELECT COUNT(*) FROM messages u
          WHERE u.client_id = c.id AND u.sender_type = 'client' AND u.id > c.{$seen} AND u.is_history = 0
            AND {$in('u')}) AS unread_count,
        (SELECT GROUP_CONCAT(DISTINCT ch.channel) FROM messages ch WHERE ch.client_id = c.id AND {$in('ch')}) AS channels,
        (SELECT rc.channel FROM messages rc WHERE rc.client_id = c.id AND rc.sender_type = 'client' AND {$in('rc')}
          ORDER BY rc.created_at DESC, rc.id DESC LIMIT 1) AS reply_channel
      FROM clients c
      JOIN messages last ON last.id = (SELECT lm.id FROM messages lm WHERE lm.client_id = c.id AND {$in('lm')}
        ORDER BY lm.created_at DESC, lm.id DESC LIMIT 1)
      LEFT JOIN processors pr ON pr.id = c.processor_id
      ORDER BY last.created_at DESC, last.id DESC
    ")->fetchAll();
}

/**
 * staffConversationList() shaped for the inbox JS (shared by the inbox
 * pages, the Front Desk and api/admin_messages.php so all render the same).
 */
function staffConversationsForJson(string $scope = 'portal'): array
{
    return array_map(fn($c) => [
        'client_id'    => (int)$c['client_id'],
        'full_name'    => $c['full_name'], // kept here too: a deactivated client isn't in the directory
        'preview'      => ($c['last_sender_type'] === 'admin' ? 'You: ' : '') . mb_strimwidth($c['last_body'] !== '' ? $c['last_body'] : '📎 Attachment', 0, 90, '…'),
        'last_at'      => $c['last_at'],
        'channel'      => $c['last_channel'],
        'unread_count' => (int)$c['unread_count'],
        // Channels this thread has messages on, for the channel tabs.
        'channels'     => array_values(array_filter(explode(',', (string)$c['channels']))),
        // Where a reply goes by default: the channel the client last wrote on.
        'reply_channel' => (string)($c['reply_channel'] ?: $c['last_channel']),
    ], staffConversationList($scope));
}

/**
 * Inbox channel tabs, in display order. 'connected' => false shows the tab
 * with a "not connected yet" note instead of conversations.
 */
function messageChannels(): array
{
    return [
        'portal'    => ['label' => 'Portal',    'icon' => 'fa-solid fa-globe',               'connected' => true],
        'whatsapp'  => ['label' => 'WhatsApp',  'icon' => 'fa-brands fa-whatsapp',          'connected' => defined('WA_API_KEY') && WA_API_KEY !== ''],
        'messenger' => ['label' => 'Messenger', 'icon' => 'fa-brands fa-facebook-messenger', 'connected' => false],
        'instagram' => ['label' => 'Instagram', 'icon' => 'fa-brands fa-instagram',         'connected' => false],
        'tiktok'    => ['label' => 'TikTok',    'icon' => 'fa-brands fa-tiktok',            'connected' => false],
    ];
}

/** messageChannels() minus the portal — the Front Desk Messaging tabs. */
function frontDeskChannels(): array
{
    $all = messageChannels();
    unset($all['portal']);
    return $all;
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
 * The full thread is ordered by time sent, not id, so WhatsApp chats
 * linked or imported later still land in the right place.
 */
function fetchMessages(int $clientId, int $afterId = 0, ?string $scope = null): array
{
    $db = getDb();
    // $scope limits staff views to one inbox; null = the whole thread (client portal).
    $only = $scope === null ? '' : ' AND ' . messageScopeSql($scope, 'm');
    $stmt = $db->prepare('SELECT m.* FROM messages m WHERE m.client_id = ? AND m.id > ?' . $only . ' ORDER BY ' . ($afterId > 0 ? 'm.id ASC' : 'm.created_at ASC, m.id ASC'));
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

    // Messages these are replies to (may be older than $afterId, so fetched
    // separately), for the quote shown at the top of a reply.
    $replyIds = array_values(array_unique(array_filter(array_map('intval', array_column($messages, 'reply_to_id')))));
    $parents = [];
    if ($replyIds) {
        $placeholders = implode(',', array_fill(0, count($replyIds), '?'));
        $stmt = $db->prepare("SELECT m.id, m.sender_type, m.sender_name, m.body,
            (SELECT COUNT(*) FROM message_files f WHERE f.message_id = m.id) AS file_count
          FROM messages m WHERE m.client_id = ? AND m.id IN ($placeholders)");
        $stmt->execute(array_merge([$clientId], $replyIds));
        foreach ($stmt->fetchAll() as $p) {
            $parents[$p['id']] = $p;
        }
    }

    foreach ($messages as &$m) {
        $m['files'] = $filesByMessage[$m['id']] ?? [];
        $m['reply'] = $parents[(int)($m['reply_to_id'] ?? 0)] ?? null;
    }
    unset($m);
    return $messages;
}

/** Who sent a message, as the viewer sees it ('You' for a client's own). */
function messageSenderLabel(array $m, string $viewer): string
{
    if ($m['sender_type'] === 'admin') {
        return $m['sender_name'] ?: 'Duque Immigration';
    }
    return $viewer === 'client' ? 'You' : ($m['sender_name'] ?: 'Client');
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
        $sender = messageSenderLabel($m, $viewer);
        $reply = $m['reply'] ?? null;
        return [
            'id'          => (int)$m['id'],
            'mine'        => $m['sender_type'] === $viewer,
            'sender_type' => $m['sender_type'],
            'sender'      => $sender,
            // Line breaks are kept by the .msg-body white-space: pre-wrap rule.
            'body_html'   => linkifyComment($m['body']),
            'created_at'  => $m['created_at'],
            'edited_at'   => $m['edited_at'] ?? null,
            // Staff only: the raw text + deadline for the Edit button.
            'body'        => $viewer === 'admin' && $m['sender_type'] === 'admin' ? $m['body'] : null,
            'edit_until'  => $viewer === 'admin' ? messageEditDeadline($m) : null,
            // Messenger-style quote of the message this one replies to.
            // Plain text (escaped in the browser), one line, trimmed.
            'reply_to'    => $reply ? [
                'id'      => (int)$reply['id'],
                'sender'  => messageSenderLabel($reply, $viewer),
                'snippet' => mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $reply['body'])), 0, 160, '…'),
                'files'   => (int)$reply['file_count'],
            ] : null,
            'channel'     => $m['channel'] ?? 'portal',
            // Delivery state + Meta's error text are for staff only.
            'wa_status'   => $viewer === 'admin' ? ($m['wa_status'] ?? null) : null,
            'wa_error'    => $viewer === 'admin' ? ($m['wa_error'] ?? null) : null,
            'files'       => array_map(fn($f) => [
                'id'   => (int)$f['id'],
                'name' => $f['original_name'],
                'size' => (int)$f['file_size'],
            ], $m['files']),
        ];
    }, $messages);
}

/**
 * When a staff message stops being editable ('Y-m-d H:i:s' UTC), or null
 * if it isn't editable at all (client messages, or the window has passed).
 */
function messageEditDeadline(array $m): ?string
{
    if (($m['sender_type'] ?? '') !== 'admin' || ($m['channel'] ?? 'portal') !== 'portal') {
        return null;
    }
    $until = strtotime($m['created_at'] . ' UTC') + MESSAGE_EDIT_WINDOW_MINUTES * 60;
    return $until > time() ? gmdate('Y-m-d H:i:s', $until) : null;
}

/**
 * Replaces the text of a staff message, keeping the previous version in
 * message_edits. Only staff messages, only within MESSAGE_EDIT_WINDOW_MINUTES
 * of sending (checked again here — the button hiding in the browser is
 * just a convenience). Returns null on success or an error message.
 */
function editStaffMessage(int $clientId, int $messageId, string $newBody, string $editorName): ?string
{
    $db = getDb();
    $stmt = $db->prepare('SELECT m.*, (SELECT COUNT(*) FROM message_files f WHERE f.message_id = m.id) AS file_count FROM messages m WHERE m.id = ? AND m.client_id = ?');
    $stmt->execute([$messageId, $clientId]);
    $m = $stmt->fetch();
    if (!$m || $m['sender_type'] !== 'admin') {
        return 'Only messages sent by your team can be edited.';
    }
    if (($m['channel'] ?? 'portal') !== 'portal') {
        return 'Messages sent on WhatsApp can\'t be edited — WhatsApp doesn\'t allow changing a message once it\'s delivered.';
    }
    if (messageEditDeadline($m) === null) {
        return 'This message can no longer be edited — messages can be edited for ' . (MESSAGE_EDIT_WINDOW_MINUTES / 60) . ' hours after sending.';
    }
    if ($newBody === '' && !(int)$m['file_count']) {
        return 'A message can\'t be empty.';
    }
    if (mb_strlen($newBody) > MESSAGE_MAX_LENGTH) {
        return 'Message is too long (max ' . MESSAGE_MAX_LENGTH . ' characters).';
    }
    if ($newBody === $m['body']) {
        return null; // nothing changed
    }

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO message_edits (message_id, old_body, edited_by) VALUES (?, ?, ?)')
           ->execute([$messageId, $m['body'], $editorName]);
        // created_at = created_at keeps the original send time even if the
        // column were ever set to auto-update.
        $db->prepare('UPDATE messages SET body = ?, edited_at = UTC_TIMESTAMP(), created_at = created_at WHERE id = ?')
           ->execute([$newBody, $messageId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return null;
}

/**
 * Messages in this thread edited recently, so an open conversation (staff
 * or client) can swap in the new text while polling. Edits can only happen
 * within the edit window, so a few hours back covers every open tab.
 */
function recentMessageEdits(int $clientId, string $viewer): array
{
    $stmt = getDb()->prepare('SELECT * FROM messages WHERE client_id = ? AND edited_at IS NOT NULL AND edited_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE');
    $stmt->execute([$clientId, MESSAGE_EDIT_WINDOW_MINUTES + 60]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['files'] = []; $r['reply'] = null; }
    unset($r);
    return array_map(fn($m) => [
        'id'        => $m['id'],
        'body_html' => $m['body_html'],
        'body'      => $m['body'],
        'edited_at' => $m['edited_at'],
    ], messagesForJson($rows, $viewer));
}

/** Earlier versions of a message, oldest first (staff only). */
function messageEditHistory(int $clientId, int $messageId): array
{
    $stmt = getDb()->prepare('SELECT e.old_body, e.edited_by, e.edited_at FROM message_edits e JOIN messages m ON m.id = e.message_id WHERE e.message_id = ? AND m.client_id = ? ORDER BY e.id ASC');
    $stmt->execute([$messageId, $clientId]);
    return $stmt->fetchAll();
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
 * catch and respond with JSON. For staff, $scope says which inbox sent it,
 * and only that inbox's read marker moves.
 */
function createMessage(int $clientId, string $senderType, string $senderName, string $body, array $files, int $replyToId = 0, string $scope = 'portal'): int
{
    $db = getDb();
    // A reply can only quote a message from this same conversation;
    // anything else is quietly sent as an ordinary message.
    if ($replyToId > 0) {
        $stmt = $db->prepare('SELECT id FROM messages WHERE id = ? AND client_id = ?');
        $stmt->execute([$replyToId, $clientId]);
        $replyToId = (int)$stmt->fetchColumn();
    }
    if ($files && !is_dir(MESSAGE_UPLOAD_DIR)) {
        mkdir(MESSAGE_UPLOAD_DIR, 0755, true);
    }

    $movedPaths = [];
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO messages (client_id, sender_type, sender_name, body, reply_to_id) VALUES (?, ?, ?, ?, ?)')
           ->execute([$clientId, $senderType, $senderName, $body, $replyToId ?: null]);
        $messageId = (int)$db->lastInsertId();

        foreach ($files as $i => $file) {
            $originalName = cleanUploadName((string)$file['name']);
            $storedName = 'msg' . $messageId . '_' . $i . '_' . time() . '_' . sanitizeFilename($originalName);
            $destination = MESSAGE_UPLOAD_DIR . '/' . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new RuntimeException('Could not save the attached file.');
            }
            $movedPaths[] = $destination;
            $db->prepare('INSERT INTO message_files (message_id, original_name, stored_name, file_size) VALUES (?, ?, ?, ?)')
               ->execute([$messageId, $originalName, $storedName, (int)$file['size']]);
        }

        // Sending a message means you've seen the conversation up to it.
        $seenColumn = $senderType === 'admin' ? staffSeenColumn($scope) : 'client_messages_seen_id';
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
 * Marks a conversation read up to $upToId for one side (and, for staff,
 * one scope). GREATEST() keeps it from ever moving backwards (e.g. an
 * older tab reporting late). $side is 'admin' or 'client' — mapped to a
 * fixed column, never user input.
 */
function markMessagesSeen(int $clientId, string $side, int $upToId, string $scope = 'portal'): void
{
    $column = $side === 'admin' ? staffSeenColumn($scope) : 'client_messages_seen_id';
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

    sendFileHeaders((string)$file['original_name'], $inline, (int)filesize($path));
    readfile($path);
    exit;
}
