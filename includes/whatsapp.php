<?php
/**
 * includes/whatsapp.php
 * WhatsApp channel for Messages, via Meta's WhatsApp Business Platform
 * (Cloud API in Coexistence mode, so the office phone keeps working),
 * reached through 360dialog: same request/webhook format as Meta's Cloud
 * API, but a different host and a D360-API-KEY header instead of a Meta
 * token — so no Meta developer app is needed. WhatsApp messages live in the same per-client thread as
 * portal messages (messages.channel = 'whatsapp') — see
 * sql/migration_whatsapp.sql.
 *
 *   Incoming:  api/whatsapp_webhook.php -> waProcessWebhook()
 *   Outgoing:  api/admin_messages.php (channel=whatsapp) -> waDeliverStaffMessage()
 *
 * Matching a WhatsApp number to a client is deliberately strict: only an
 * exact match on ONE client links automatically. Anything else waits in
 * whatsapp_unmatched for staff to link by hand, because a wrong guess
 * would put one client's messages in another client's portal.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/messages.php';

/** WhatsApp is on only once the 360dialog API key is filled in config.php. */
function waEnabled(): bool
{
    return defined('WA_API_KEY') && WA_API_KEY !== '';
}

function waApiBase(): string
{
    // WA_API_BASE exists only so tests can point at a local fake API.
    return defined('WA_API_BASE') && WA_API_BASE ? rtrim(WA_API_BASE, '/') : 'https://waba-v2.360dialog.io';
}

function waApiUrl(string $path): string
{
    return waApiBase() . '/' . ltrim($path, '/');
}

// ---------- Phone numbers ----------

/**
 * Turns a phone as typed in the client record into international digits
 * (no +), or null when the country can't be known for sure. Only two
 * cases are trusted: a number written with + / 00 in front, and a
 * 10-digit North American number (Canada/US, +1). Local formats like
 * "09518069771" return null — those are linked by hand once.
 */
function waNormalizePhone(?string $phone): ?string
{
    $phone = trim((string)$phone);
    if ($phone === '') return null;
    $hasPlus = str_starts_with($phone, '+');
    $digits = preg_replace('/\D/', '', $phone);
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
        $hasPlus = true;
    }
    if ($hasPlus) {
        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }
    if (strlen($digits) === 10 && $digits[0] >= '2') return '1' . $digits;
    if (strlen($digits) === 11 && $digits[0] === '1') return $digits;
    return null;
}

/** "14312933949" -> "+1 431 293 3949" for display; others just get a +. */
function waFormatNumber(string $digits): string
{
    if (strlen($digits) === 11 && $digits[0] === '1') {
        return '+1 ' . substr($digits, 1, 3) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7);
    }
    return '+' . $digits;
}

/** The number DIS would send to for this client (row needs whatsapp_number + phone). */
function waNumberForClient(array $client): ?string
{
    return ($client['whatsapp_number'] ?? '') ?: waNormalizePhone($client['phone'] ?? '');
}

/**
 * Exact matches only: a client whose saved WhatsApp number is this one,
 * or (if none) whose phone normalizes to exactly this number. Returns the
 * client id when exactly one client matches, else null.
 */
function waMatchClient(string $waNumber): ?int
{
    $db = getDb();
    $stmt = $db->prepare('SELECT id FROM clients WHERE whatsapp_number = ?');
    $stmt->execute([$waNumber]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) return (int)$ids[0];
    if (count($ids) > 1) return null;

    $matches = [];
    foreach ($db->query("SELECT id, phone FROM clients WHERE phone IS NOT NULL AND phone <> ''") as $c) {
        if (waNormalizePhone($c['phone']) === $waNumber) $matches[] = (int)$c['id'];
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/**
 * Clients worth suggesting for an unmatched number: same last 9 digits
 * (catches "09518069771" vs 639518069771), or several exact matches
 * (family members sharing one phone). Only ever a suggestion.
 */
function waSuggestClients(string $waNumber): array
{
    $tail = substr($waNumber, -9);
    $out = [];
    foreach (getDb()->query("SELECT id, full_name, phone, whatsapp_number FROM clients WHERE is_active = 1") as $c) {
        $digits = preg_replace('/\D/', '', (string)$c['phone']);
        if ($c['whatsapp_number'] === $waNumber || ($digits !== '' && strlen($digits) >= 9 && substr($digits, -9) === $tail)) {
            $out[] = ['id' => (int)$c['id'], 'full_name' => $c['full_name'], 'phone' => (string)$c['phone']];
        }
    }
    return $out;
}

// ---------- 24-hour customer service window ----------

/**
 * WhatsApp allows free-form replies only within 24h of the client's last
 * WhatsApp message; after that only an approved template. Returns the
 * window's end as a UTC 'Y-m-d H:i:s' string, or null if it's closed.
 */
function waWindowEndsAt(int $clientId): ?string
{
    $stmt = getDb()->prepare("
      SELECT MAX(created_at) + INTERVAL 24 HOUR FROM messages
      WHERE client_id = ? AND channel = 'whatsapp' AND sender_type = 'client'
    ");
    $stmt->execute([$clientId]);
    $ends = $stmt->fetchColumn();
    return $ends && $ends > gmdate('Y-m-d H:i:s') ? $ends : null;
}

/** Channel the client last wrote on — what the reply box defaults to. */
function waPreferredChannel(int $clientId): string
{
    $stmt = getDb()->prepare("SELECT channel FROM messages WHERE client_id = ? AND sender_type = 'client' ORDER BY created_at DESC, id DESC LIMIT 1");
    $stmt->execute([$clientId]);
    return $stmt->fetchColumn() === 'whatsapp' ? 'whatsapp' : 'portal';
}

// ---------- 360dialog (Cloud API) calls ----------

/**
 * One API request. Returns the decoded JSON on success; throws
 * RuntimeException with Meta's own error text (and code) on failure —
 * 360dialog passes Meta's error objects through unchanged.
 */
function waApi(string $method, string $path, ?array $json = null, ?array $multipart = null): array
{
    $ch = curl_init(waApiUrl($path));
    $headers = ['D360-API-KEY: ' . WA_API_KEY];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    } elseif ($multipart !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Could not reach WhatsApp: ' . $curlError);
    }
    $data = json_decode($raw, true) ?: [];
    if ($status >= 400 || isset($data['error'])) {
        $err = $data['error'] ?? [];
        $text = trim(($err['message'] ?? 'WhatsApp error') . (isset($err['error_data']['details']) ? ' — ' . $err['error_data']['details'] : ''));
        throw new RuntimeException($text, (int)($err['code'] ?? $status));
    }
    return $data;
}

function waSend(string $to, array $payload): string
{
    $data = waApi('POST', 'messages', array_merge([
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
    ], $payload));
    $id = $data['messages'][0]['id'] ?? '';
    if ($id === '') throw new RuntimeException('WhatsApp did not return a message id.');
    return $id;
}

function waSendText(string $to, string $body): string
{
    return waSend($to, ['type' => 'text', 'text' => ['preview_url' => true, 'body' => $body]]);
}

/** Uploads a stored file to WhatsApp and sends it (image up to 5 MB, else document). */
function waSendFile(string $to, string $path, string $originalName): string
{
    $mime = mimeTypeForExtension($originalName);
    $upload = waApi('POST', 'media', null, [
        'messaging_product' => 'whatsapp',
        'type'              => $mime,
        'file'              => new CURLFile($path, $mime, $originalName),
    ]);
    $isImage = in_array($mime, ['image/jpeg', 'image/png'], true) && filesize($path) <= 5 * 1024 * 1024;
    return $isImage
        ? waSend($to, ['type' => 'image', 'image' => ['id' => $upload['id']]])
        : waSend($to, ['type' => 'document', 'document' => ['id' => $upload['id'], 'filename' => $originalName]]);
}

function waSendNoticeTemplate(string $to, string $firstName): string
{
    return waSend($to, ['type' => 'template', 'template' => [
        'name'       => WA_NOTICE_TEMPLATE,
        'language'   => ['code' => defined('WA_NOTICE_TEMPLATE_LANG') && WA_NOTICE_TEMPLATE_LANG ? WA_NOTICE_TEMPLATE_LANG : 'en'],
        'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $firstName ?: 'there']]]],
    ]]);
}

/**
 * Downloads an incoming WhatsApp media item into the Messages upload
 * folder (outside public_html). Streams straight to disk, so large
 * videos never pass through PHP's memory. Returns the message_files
 * fields, or null if the download failed (the message is still saved,
 * with a note in its body).
 */
function waDownloadMedia(string $mediaId, string $waMessageId, ?string $filename, ?string $mime): ?array
{
    try {
        $info = waApi('GET', $mediaId);
        $mime = $info['mime_type'] ?? $mime ?? 'application/octet-stream';
        $ext = waExtensionForMime($mime);
        $original = $filename ?: ('whatsapp-' . gmdate('Ymd-His') . ($ext ? '.' . $ext : ''));
        if ($ext && !pathinfo($original, PATHINFO_EXTENSION)) $original .= '.' . $ext;
        $original = cleanUploadName($original);

        if (!is_dir(MESSAGE_UPLOAD_DIR)) mkdir(MESSAGE_UPLOAD_DIR, 0755, true);
        $stored = 'wa_' . substr(sha1($waMessageId), 0, 16) . '_' . time() . '_' . sanitizeFilename($original);
        $path = MESSAGE_UPLOAD_DIR . '/' . $stored;

        // Meta hands back a lookaside.fbsbx.com link; through 360dialog the
        // same path + query is fetched from their host with the API key.
        $parts = parse_url((string)($info['url'] ?? ''));
        if (empty($parts['path'])) return null;
        $url = waApiBase() . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $fh = fopen($path, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_HTTPHEADER     => ['D360-API-KEY: ' . WA_API_KEY],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_FAILONERROR    => true,
        ]);
        $ok = curl_exec($ch);
        curl_close($ch);
        fclose($fh);
        if (!$ok || !filesize($path)) {
            @unlink($path);
            return null;
        }
        return ['original_name' => $original, 'stored_name' => $stored, 'file_size' => filesize($path)];
    } catch (Throwable $e) {
        error_log('waDownloadMedia ' . $mediaId . ': ' . $e->getMessage());
        return null;
    }
}

function waExtensionForMime(string $mime): string
{
    $mime = strtolower(trim(explode(';', $mime)[0]));
    return [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'text/plain' => 'txt',
    ][$mime] ?? '';
}

// ---------- Saving WhatsApp messages ----------

/**
 * Inserts one WhatsApp message into a client's thread. Duplicate
 * wa_message_ids (Meta retries, history overlap) are silently skipped.
 * $files are already-downloaded message_files rows. Returns the new id or 0.
 */
function waStoreMessage(int $clientId, string $senderType, string $senderName, string $body, array $files, string $waMessageId, string $createdAt, bool $isHistory, ?string $status = null): int
{
    $db = getDb();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT IGNORE INTO messages (client_id, sender_type, sender_name, channel, wa_message_id, wa_status, is_history, body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$clientId, $senderType, $senderName, 'whatsapp', $waMessageId, $status, $isHistory ? 1 : 0, $body, $createdAt]);
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            foreach ($files as $f) @unlink(MESSAGE_UPLOAD_DIR . '/' . $f['stored_name']);
            return 0;
        }
        $messageId = (int)$db->lastInsertId();
        foreach ($files as $f) {
            $db->prepare('INSERT INTO message_files (message_id, original_name, stored_name, file_size) VALUES (?, ?, ?, ?)')
               ->execute([$messageId, $f['original_name'], $f['stored_name'], (int)$f['file_size']]);
        }
        $db->commit();
        return $messageId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Reads one WhatsApp message object (from the messages, message echoes
 * or history webhook) into [body, files]. Media is downloaded; message
 * types DIS can't show become a short note instead of disappearing.
 */
function waParseMessage(array $m): array
{
    $type = $m['type'] ?? 'unknown';
    $id = (string)($m['id'] ?? '');
    $files = [];
    $body = '';

    switch ($type) {
        case 'text':
            $body = (string)($m['text']['body'] ?? '');
            break;
        case 'image': case 'document': case 'audio': case 'video': case 'sticker':
            $media = $m[$type] ?? [];
            $body = (string)($media['caption'] ?? '');
            if (!empty($media['id'])) {
                $file = waDownloadMedia($media['id'], $id, $media['filename'] ?? null, $media['mime_type'] ?? null);
                if ($file) $files[] = $file;
                else $body = trim($body . "\n[" . ucfirst($type) . ' could not be downloaded from WhatsApp]');
            } else {
                $body = trim($body . "\n[" . ucfirst($type) . ' — not included in the import]');
            }
            if ($type === 'audio' && !empty($media['voice'])) $body = trim('🎤 Voice message ' . $body);
            break;
        case 'location':
            $loc = $m['location'] ?? [];
            $body = '📍 ' . trim(($loc['name'] ?? '') . ' ' . ($loc['address'] ?? '')) . "\nhttps://maps.google.com/?q=" . ($loc['latitude'] ?? '') . ',' . ($loc['longitude'] ?? '');
            break;
        case 'contacts':
            $lines = [];
            foreach ($m['contacts'] ?? [] as $c) {
                $phones = implode(', ', array_column($c['phones'] ?? [], 'phone'));
                $lines[] = '👤 ' . ($c['name']['formatted_name'] ?? 'Contact') . ($phones ? ' — ' . $phones : '');
            }
            $body = implode("\n", $lines);
            break;
        case 'button':
            $body = (string)($m['button']['text'] ?? '');
            break;
        case 'interactive':
            $i = $m['interactive'] ?? [];
            $body = (string)($i['button_reply']['title'] ?? $i['list_reply']['title'] ?? '');
            break;
        case 'reaction':
            $emoji = $m['reaction']['emoji'] ?? '';
            $body = $emoji !== '' ? 'Reacted ' . $emoji : 'Removed a reaction';
            break;
        default:
            $body = '[WhatsApp ' . $type . ' message — open WhatsApp to see it]';
    }
    return [trim($body), $files];
}

function waTimestamp($unix): string
{
    $unix = (int)$unix;
    return gmdate('Y-m-d H:i:s', $unix > 0 ? $unix : time());
}

/**
 * Files one WhatsApp message under the right client, or into the
 * unmatched queue. $direction: 'in' = from the client, 'out' = sent by
 * the office (phone app echo / history). $customer is the client's number.
 */
function waFileMessage(array $m, string $customer, string $direction, ?string $profileName, bool $isHistory): void
{
    $waId = (string)($m['id'] ?? '');
    if ($waId === '' || $customer === '') return;

    // Already stored (webhook retry, or our own API send echoed back)?
    $db = getDb();
    $stmt = $db->prepare('SELECT 1 FROM messages WHERE wa_message_id = ? UNION SELECT 1 FROM whatsapp_unmatched WHERE wa_message_id = ?');
    $stmt->execute([$waId, $waId]);
    if ($stmt->fetchColumn()) return;

    [$body, $files] = waParseMessage($m);
    if ($body === '' && !$files) return;
    $createdAt = waTimestamp($m['timestamp'] ?? 0);

    $clientId = waMatchClient($customer);
    if ($clientId === null) {
        $stmt = $db->prepare('SELECT ignored FROM whatsapp_unmatched WHERE wa_from = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$customer]);
        $ignored = (int)$stmt->fetchColumn();
        $db->prepare('INSERT IGNORE INTO whatsapp_unmatched (wa_from, profile_name, direction, body, files_json, wa_message_id, is_history, ignored, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$customer, $profileName, $direction, $body, $files ? json_encode($files) : null, $waId, $isHistory ? 1 : 0, $ignored, $createdAt]);
        return;
    }

    if ($direction === 'in') {
        $stmt = $db->prepare('SELECT full_name, processor_id, staff_messages_seen_id FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        $name = $client['full_name'] ?? ($profileName ?: 'Client');
        $messageId = waStoreMessage($clientId, 'client', $name, $body, $files, $waId, $createdAt, $isHistory);
        if ($messageId && !$isHistory) waNotifyProcessor($clientId, $client, $name, $body, count($files));
    } else {
        waStoreMessage($clientId, 'admin', 'WhatsApp (office phone)', $body, $files, $waId, $createdAt, $isHistory, 'sent');
    }
}

/** Same email the processor gets for a portal message, same 15-minute throttle. */
function waNotifyProcessor(int $clientId, array $client, string $name, string $body, int $fileCount): void
{
    if (empty($client['processor_id']) || !function_exists('notifyProcessorRealtime')) return;
    $stmt = getDb()->prepare("
      SELECT COUNT(*) FROM messages
      WHERE client_id = ? AND sender_type = 'client' AND id > ? AND created_at > NOW() - INTERVAL 15 MINUTE
    ");
    $stmt->execute([$clientId, (int)$client['staff_messages_seen_id']]);
    if ((int)$stmt->fetchColumn() > 1) return; // the one just stored counts as 1
    $preview = $body !== '' ? mb_strimwidth($body, 0, 300, '…') : '(sent ' . $fileCount . ' attachment' . ($fileCount === 1 ? '' : 's') . ')';
    notifyProcessorRealtime((int)$client['processor_id'], $clientId, $name, 'message', $name . ' sent a WhatsApp message: "' . $preview . '"');
}

// ---------- Webhook ----------

/** Processes one verified webhook payload from Meta. */
function waProcessWebhook(array $payload): void
{
    foreach ($payload['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $field = $change['field'] ?? '';
            $value = $change['value'] ?? [];

            if ($field === 'messages') {
                $names = [];
                foreach ($value['contacts'] ?? [] as $c) $names[$c['wa_id'] ?? ''] = $c['profile']['name'] ?? null;
                foreach ($value['messages'] ?? [] as $m) {
                    $from = preg_replace('/\D/', '', (string)($m['from'] ?? ''));
                    waFileMessage($m, $from, 'in', $names[$m['from'] ?? ''] ?? null, false);
                }
                foreach ($value['statuses'] ?? [] as $s) waApplyStatus($s);
            } elseif ($field === 'smb_message_echoes') {
                // Sent from the WhatsApp Business app on the office phone (Coexistence).
                foreach ($value['message_echoes'] ?? [] as $m) {
                    waFileMessage($m, preg_replace('/\D/', '', (string)($m['to'] ?? '')), 'out', null, false);
                }
            } elseif ($field === 'history') {
                waProcessHistory($value);
            }
        }
    }
}

/**
 * Past chats shared from the phone during Coexistence onboarding. Comes
 * in chunks; each thread is one customer. Messages from the office
 * number are 'out', everything else 'in'. All marked is_history so they
 * never show as unread.
 */
function waProcessHistory(array $value): void
{
    $business = defined('WA_BUSINESS_NUMBER') ? preg_replace('/\D/', '', WA_BUSINESS_NUMBER) : '';
    foreach ($value['history'] ?? [] as $chunk) {
        foreach ($chunk['threads'] ?? [] as $thread) {
            $customer = preg_replace('/\D/', '', (string)($thread['id'] ?? ''));
            foreach ($thread['messages'] ?? [] as $m) {
                $from = preg_replace('/\D/', '', (string)($m['from'] ?? ''));
                $direction = ($from !== '' && $from !== $customer && ($business === '' || $from === $business)) ? 'out' : 'in';
                waFileMessage($m, $customer, $direction, null, true);
            }
        }
    }
}

/** Delivery receipts for messages DIS sent. Never moves a status backwards. */
function waApplyStatus(array $s): void
{
    $id = (string)($s['id'] ?? '');
    $status = (string)($s['status'] ?? '');
    if ($id === '' || !in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) return;

    $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
    $error = null;
    if ($status === 'failed') {
        $e = $s['errors'][0] ?? [];
        $error = mb_substr(trim(($e['title'] ?? 'Failed') . (isset($e['error_data']['details']) ? ' — ' . $e['error_data']['details'] : '')), 0, 255);
    }
    $db = getDb();
    $stmt = $db->prepare('SELECT id, wa_status FROM messages WHERE wa_message_id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return;
    // Portal messages whose WhatsApp *notice* went out track the notice's
    // progress as notice_sent / notice_delivered / notice_read / notice_failed.
    $isNotice = str_starts_with((string)$row['wa_status'], 'notice_');
    $current = (string)$row['wa_status'];
    if ($isNotice) $current = substr($current, 7);
    if ($rank[$status] <= ($rank[$current] ?? 0)) return;
    $db->prepare('UPDATE messages SET wa_status = ?, wa_error = ? WHERE id = ?')
       ->execute([($isNotice ? 'notice_' : '') . $status, $error, $row['id']]);
}

// ---------- Sending from the staff Inbox ----------

/**
 * Delivers an already-saved staff message over WhatsApp. Inside the 24h
 * window: the text, then each attachment as its own WhatsApp message.
 * Outside it (or if Meta says the window has closed): the message stays
 * in the portal and the client gets the notice template instead.
 * Returns a short note for the staff toast.
 */
function waDeliverStaffMessage(int $messageId, array $client, string $body, array $fileRows): string
{
    $db = getDb();
    $to = waNumberForClient($client);
    if (!$to) {
        $db->prepare("UPDATE messages SET channel = 'portal' WHERE id = ?")->execute([$messageId]);
        return 'No WhatsApp number on file — sent in the portal instead.';
    }

    $inWindow = waWindowEndsAt((int)$client['id']) !== null;
    if ($inWindow) {
        try {
            $firstId = null;
            if ($body !== '') $firstId = waSendText($to, $body);
            foreach ($fileRows as $f) {
                $id = waSendFile($to, MESSAGE_UPLOAD_DIR . '/' . $f['stored_name'], $f['original_name']);
                $firstId = $firstId ?? $id;
            }
            $db->prepare("UPDATE messages SET channel = 'whatsapp', wa_message_id = ?, wa_status = 'sent' WHERE id = ?")->execute([$firstId, $messageId]);
            return 'Sent on WhatsApp.';
        } catch (RuntimeException $e) {
            // 131047 = more than 24h since the client last wrote: fall through to the notice.
            if ($e->getCode() !== 131047) {
                $db->prepare("UPDATE messages SET channel = 'whatsapp', wa_status = 'failed', wa_error = ? WHERE id = ?")
                   ->execute([mb_substr($e->getMessage(), 0, 255), $messageId]);
                return 'WhatsApp send failed: ' . $e->getMessage() . ' — the message is still in the portal.';
            }
        }
    }

    $db->prepare("UPDATE messages SET channel = 'portal' WHERE id = ?")->execute([$messageId]);
    if (!defined('WA_NOTICE_TEMPLATE') || WA_NOTICE_TEMPLATE === '') {
        return 'Over 24 hours since the client last wrote on WhatsApp, so it was sent in the portal only.';
    }
    try {
        $noticeId = waSendNoticeTemplate($to, explode(' ', trim($client['full_name']))[0]);
        $db->prepare("UPDATE messages SET wa_message_id = ?, wa_status = 'notice_sent' WHERE id = ?")->execute([$noticeId, $messageId]);
        return 'Over 24 hours since the client last wrote on WhatsApp — sent in the portal, and a WhatsApp notice was sent asking them to read it.';
    } catch (RuntimeException $e) {
        return 'Sent in the portal. The WhatsApp notice failed: ' . $e->getMessage();
    }
}

// ---------- Unmatched numbers (staff Inbox) ----------

/** Unmatched numbers waiting for staff, newest activity first. */
function waUnmatchedList(): array
{
    $db = getDb();
    $rows = $db->query("
      SELECT wa_from, MAX(profile_name) AS profile_name, COUNT(*) AS message_count, MAX(created_at) AS last_at
      FROM whatsapp_unmatched WHERE ignored = 0
      GROUP BY wa_from ORDER BY last_at DESC
    ")->fetchAll();
    $preview = $db->prepare('SELECT direction, body, files_json, created_at FROM whatsapp_unmatched WHERE wa_from = ? AND ignored = 0 ORDER BY created_at DESC, id DESC LIMIT 3');
    foreach ($rows as &$r) {
        $preview->execute([$r['wa_from']]);
        $r['recent'] = array_reverse($preview->fetchAll());
        $r['suggestions'] = waSuggestClients($r['wa_from']);
    }
    unset($r);
    return $rows;
}

function waUnmatchedCount(): int
{
    try {
        return (int)getDb()->query('SELECT COUNT(DISTINCT wa_from) FROM whatsapp_unmatched WHERE ignored = 0')->fetchColumn();
    } catch (Throwable $e) {
        return 0; // table not there yet (migration not run)
    }
}

/**
 * Links an unmatched number to a client: saves it as their WhatsApp
 * number and moves every waiting message into their thread (in the
 * order they were sent). Returns how many messages moved.
 */
function waLinkNumber(string $waNumber, int $clientId): int
{
    $db = getDb();
    $stmt = $db->prepare('SELECT full_name FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $name = $stmt->fetchColumn();
    if ($name === false) throw new RuntimeException('Client not found.');

    $db->prepare('UPDATE clients SET whatsapp_number = ? WHERE id = ?')->execute([$waNumber, $clientId]);

    $stmt = $db->prepare('SELECT * FROM whatsapp_unmatched WHERE wa_from = ? ORDER BY created_at ASC, id ASC');
    $stmt->execute([$waNumber]);
    $moved = 0;
    foreach ($stmt->fetchAll() as $u) {
        $files = $u['files_json'] ? (json_decode($u['files_json'], true) ?: []) : [];
        $isOut = $u['direction'] === 'out';
        $id = waStoreMessage($clientId, $isOut ? 'admin' : 'client', $isOut ? 'WhatsApp (office phone)' : $name,
            $u['body'], $files, $u['wa_message_id'], $u['created_at'], (bool)$u['is_history'], $isOut ? 'sent' : null);
        if ($id) $moved++;
        $db->prepare('DELETE FROM whatsapp_unmatched WHERE id = ?')->execute([$u['id']]);
    }
    return $moved;
}

/** Hides a number's waiting messages (spam, wrong number); later ones stay hidden too. */
function waIgnoreNumber(string $waNumber): void
{
    getDb()->prepare('UPDATE whatsapp_unmatched SET ignored = 1 WHERE wa_from = ?')->execute([$waNumber]);
}
