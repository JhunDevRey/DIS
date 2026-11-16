<?php
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/client_passwords.php';
requireClient();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$db = getDb();
$action = $_POST['action'] ?? '';
$clientId = (int)$_SESSION['client_id'];

if ($action === 'update_contact_info') {
    enforceActionLimit('profile:client:' . $clientId, 20, 60, 'Too many changes in the last hour. Please try again later.');
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
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');

    // Guessing the current password here is throttled like signing in.
    enforceActionLimit('pwchange:client:' . $clientId, 10, 15, 'Too many attempts. Please wait 15 minutes and try again.');

    $stmt = $db->prepare('SELECT email, password_hash FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        jsonResponse(false, 'Current password is incorrect.');
    }
    $problem = clientPasswordProblem($new, (string)$row['email']);
    if ($problem !== null) {
        jsonResponse(false, $problem);
    }
    if (password_verify($new, $row['password_hash'])) {
        jsonResponse(false, 'Please choose a password different from the current one.');
    }

    // Stored only as a hash; this also ends a "must choose your own" state.
    setClientPassword($clientId, $new, false);

    // Other remembered devices must sign in with the new password; this one stays.
    $signedOut = clientRememberForgetAll($clientId, true);
    auditLog('client.password_changed');
    jsonResponse(true, 'Password updated.' . ($signedOut ? ' Your other devices have been signed out.' : ''));
}

if ($action === 'forget_devices') {
    $n = clientRememberForgetAll($clientId, true);
    jsonResponse(true, $n ? 'Signed out of ' . $n . ' other device' . ($n === 1 ? '' : 's') . '.' : 'No other devices were signed in.');
}

jsonResponse(false, 'Unknown action.');
