<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireClient();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$db = getDb();
$action = $_POST['action'] ?? '';
$clientId = (int)$_SESSION['client_id'];

if ($action === 'update_contact_info') {
    $phone = trim($_POST['phone'] ?? '');
    $ccEmailsRaw = trim($_POST['cc_emails'] ?? '');
    $designatedName = trim($_POST['designated_person_name'] ?? '');
    $designatedPhone = trim($_POST['designated_person_phone'] ?? '');

    // Any separator (comma, semicolon, space, new line) is fine — saved tidied.
    $stmt = $db->prepare('SELECT full_name, email, cc_emails FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $before = $stmt->fetch();
    $ccEmailsRaw = (string)ccEmailsForSaving($ccEmailsRaw, (string)$before['email']);

    $db->prepare('UPDATE clients SET phone = ?, cc_emails = ?, designated_person_name = ?, designated_person_phone = ? WHERE id = ?')
       ->execute([$phone ?: null, $ccEmailsRaw ?: null, $designatedName ?: null, $designatedPhone ?: null, $clientId]);

    // Anyone newly added gets a short welcome explaining why (best-effort).
    $welcomed = notifyNewCcEmails($before, $before['cc_emails'], $ccEmailsRaw, 'client');
    jsonResponse(true, 'Profile updated.' . ($welcomed ? ' We sent a short welcome email to ' . ($welcomed === 1 ? 'the new contact' : 'the ' . $welcomed . ' new contacts') . '.' : ''));
}

if ($action === 'update_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';

    if (strlen($new) < 6) {
        jsonResponse(false, 'New password must be at least 6 characters.');
    }

    $stmt = $db->prepare('SELECT password_hash FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        jsonResponse(false, 'Current password is incorrect.');
    }

    $db->prepare('UPDATE clients SET password_hash = ?, plain_password = ? WHERE id = ?')
       ->execute([password_hash($new, PASSWORD_DEFAULT), $new, $clientId]);

    jsonResponse(true, 'Password updated.');
}

jsonResponse(false, 'Unknown action.');
