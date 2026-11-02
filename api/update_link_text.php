<?php
/**
 * api/update_link_text.php
 *
 * Saves an edited link (custom display text + URL) back into the
 * underlying stored text of either a comment or a document request's
 * instructions — called by the small "edit link" popup (see the
 * editable-link handling in js/ui-kit.js and linkifyText() in
 * includes/functions.php).
 *
 * Staff-only by design: the frontend only ever renders the edit pencil
 * on admin-authored content in the first place, but this endpoint
 * re-checks that server-side too (for comments specifically — never
 * trust the frontend alone for something like this) rather than relying
 * on the UI simply not offering the option elsewhere.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$editType = trim($_POST['edit_type'] ?? '');
$editId   = (int)($_POST['edit_id'] ?? 0);
$oldRaw   = $_POST['old_raw'] ?? '';
$newText  = trim($_POST['new_text'] ?? '');
$newUrl   = trim($_POST['new_url'] ?? '');

if (!in_array($editType, ['comment', 'instructions'], true) || $editId <= 0 || $oldRaw === '' || $newText === '') {
    jsonResponse(false, 'Missing or invalid data.');
}

// Empty $newUrl means "remove the link" (the popup's "Remove link"
// button) — otherwise it must actually look like a real http(s) URL,
// same rule linkifyText() itself uses to recognize a link at all.
if ($newUrl !== '' && !preg_match('/^https?:\/\//i', $newUrl)) {
    jsonResponse(false, 'That doesn\'t look like a valid link — it should start with http:// or https://');
}

$db = getDb();

if ($editType === 'comment') {
    $stmt = $db->prepare('SELECT id, comment_text, author_type FROM comments WHERE id = ?');
    $stmt->execute([$editId]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(false, 'Comment not found.');
    }
    // Defense in depth: only ever allow editing links within staff's own
    // comments, never a client's — regardless of what the frontend does.
    if ($row['author_type'] !== 'admin') {
        jsonResponse(false, 'Only staff comments can have their links edited.');
    }
    $currentText = $row['comment_text'];
} else {
    $stmt = $db->prepare('SELECT id, instructions FROM document_requests WHERE id = ?');
    $stmt->execute([$editId]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(false, 'Document request not found.');
    }
    $currentText = $row['instructions'] ?? '';
}

// Build the replacement: a real link becomes "[text](url)" markdown
// syntax (what linkifyText() knows how to render as a custom-labeled
// link); removing the link just leaves the plain display text behind.
$newRaw = $newUrl !== '' ? '[' . $newText . '](' . $newUrl . ')' : $newText;

if (strpos($currentText, $oldRaw) === false) {
    // The exact text to replace wasn't found — e.g. it changed since
    // the page loaded (another edit happened in the meantime). Fail
    // clearly rather than silently doing nothing or guessing.
    jsonResponse(false, 'This link has changed since the page loaded — refresh and try again.');
}

// preg_replace_callback deliberately used instead of plain preg_replace
// — its replacement is a real callback return value, not a replacement
// PATTERN string, so it's never at risk of $1/\1-style backreference
// syntax inside $newRaw (e.g. a literal "$" in the display text)
// corrupting the result the way preg_replace's string-based replacement
// parameter would.
$updatedText = preg_replace_callback(
    '/' . preg_quote($oldRaw, '/') . '/',
    fn() => $newRaw,
    $currentText,
    1
);

if ($editType === 'comment') {
    $db->prepare('UPDATE comments SET comment_text = ? WHERE id = ?')->execute([$updatedText, $editId]);
} else {
    $db->prepare('UPDATE document_requests SET instructions = ? WHERE id = ?')->execute([$updatedText, $editId]);
}

jsonResponse(true, 'Link updated.', ['new_raw' => $newRaw]);
