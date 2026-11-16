<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dropbox.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

$clientId            = (int)($_POST['client_id'] ?? 0);
$fullName            = trim($_POST['full_name'] ?? '');
$email               = trim($_POST['email'] ?? '');
$caseType            = trim($_POST['case_type'] ?? '');
$phone               = trim($_POST['phone'] ?? '');
$referenceNumber     = trim($_POST['reference_number'] ?? '');
$processorId         = (int)($_POST['processor_id'] ?? 0);
$existingDropboxPath = trim($_POST['existing_dropbox_path'] ?? '');
$ccEmailsRaw         = trim($_POST['cc_emails'] ?? '');
$designatedName      = trim($_POST['designated_person_name'] ?? '');
$designatedPhone     = trim($_POST['designated_person_phone'] ?? '');
$portalCreatedDay    = trim($_POST['portal_created_day'] ?? '');
$portalCreatedMonth  = trim($_POST['portal_created_month'] ?? '');
$portalCreatedYear   = trim($_POST['portal_created_year'] ?? '');
$dropboxAddedMonth   = trim($_POST['dropbox_added_month'] ?? '');
$dropboxAddedYear    = trim($_POST['dropbox_added_year'] ?? '');

if ($clientId <= 0) {
    jsonResponse(false, 'Client not found.');
}
if ($fullName === '' || $email === '') {
    jsonResponse(false, 'Name and email are required.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, 'Please enter a valid email address.');
}
// Any separator (comma, semicolon, space, new line) is fine — saved tidied.
$ccEmailsRaw = (string)ccEmailsForSaving($ccEmailsRaw, $email);

// Portal creation date: only build it if all three parts were actually
// given, and only accept it if it's a real calendar date.
$portalCreatedAt = null;
if ($portalCreatedDay !== '' && $portalCreatedMonth !== '' && $portalCreatedYear !== '') {
    if (!checkdate((int)$portalCreatedMonth, (int)$portalCreatedDay, (int)$portalCreatedYear)) {
        jsonResponse(false, 'That "Portal account created on" date isn\'t valid — double-check the day.');
    }
    $portalCreatedAt = sprintf('%04d-%02d-%02d', (int)$portalCreatedYear, (int)$portalCreatedMonth, (int)$portalCreatedDay);
}

// "Dropbox folder added on" — purely informational reference metadata,
// deliberately NOT wired into any actual Dropbox folder creation/naming
// logic. Only month + year (no day), stored as the 1st of that month.
$dropboxAddedAt = null;
if ($dropboxAddedMonth !== '' && $dropboxAddedYear !== '') {
    $monthInt = (int)$dropboxAddedMonth;
    $yearInt = (int)$dropboxAddedYear;
    if ($monthInt < 1 || $monthInt > 12 || $yearInt < 2000 || $yearInt > 2100) {
        jsonResponse(false, 'That "Dropbox folder added on" date isn\'t valid.');
    }
    $dropboxAddedAt = sprintf('%04d-%02d-01', $yearInt, $monthInt);
}

$db = getDb();
$stmt = $db->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    jsonResponse(false, 'Client not found.');
}

// Email doubles as the portal login — check it's not already used by a
// *different* client before allowing the change.
if ($email !== $client['email']) {
    $stmt = $db->prepare('SELECT id FROM clients WHERE email = ? AND id != ?');
    $stmt->execute([$email, $clientId]);
    if ($stmt->fetch()) {
        jsonResponse(false, 'Another client is already using that email address.');
    }
}

if ($processorId > 0) {
    $stmt = $db->prepare('SELECT id FROM processors WHERE id = ?');
    $stmt->execute([$processorId]);
    if (!$stmt->fetch()) {
        $processorId = 0;
    }
}

$dropboxPath = $client['dropbox_folder_path'];
$dropboxLink = $client['dropbox_folder_link'];
$dropboxWarning = null;

// Only touch Dropbox if there's no folder on file yet, or the Client No.
// changed (which may point at a different client's existing folder, or
// mean this client needs their own new one).
$referenceChanged = $referenceNumber !== '' && $referenceNumber !== $client['reference_number'];
$needsProvisioning = $referenceNumber !== '' && ($referenceChanged || !$dropboxPath);

if ($existingDropboxPath !== '' && dropboxIsConfigured()) {
    // Staff already know exactly where this client's folder lives — link
    // straight to it. If it genuinely can't be found, stop rather than
    // silently saving everything else with a broken/unlinked path.
    $linked = dropboxLinkExistingFolder($existingDropboxPath);
    if (!$linked['ok']) {
        jsonResponse(false, 'That Dropbox path could not be found — double-check it and try again: ' . $linked['error'], ['error_type' => 'dropbox_path']);
    }
    $dropboxPath = $linked['path'];
    $dropboxLink = $linked['link'];
} elseif ($needsProvisioning && dropboxIsConfigured()) {
    // Does another client already hold this Client No. with a folder on file?
    $stmt = $db->prepare('SELECT dropbox_folder_path, dropbox_folder_link FROM clients WHERE reference_number = ? AND id != ? AND dropbox_folder_path IS NOT NULL LIMIT 1');
    $stmt->execute([$referenceNumber, $clientId]);
    $reused = $stmt->fetch();

    if ($reused) {
        $dropboxPath = $reused['dropbox_folder_path'];
        $dropboxLink = $reused['dropbox_folder_link'];
    } else {
        $provision = dropboxProvisionClientFolder($client['full_name'], $referenceNumber !== '' ? $referenceNumber : null, $caseType);
        if ($provision['ok']) {
            $dropboxPath = $provision['path'];
            $dropboxLink = $provision['link'];
        } else {
            $dropboxWarning = 'Details saved, but the Dropbox folder could not be set up: ' . $provision['error'];
        }
    }
}

$db->prepare('UPDATE clients SET full_name = ?, email = ?, case_type = ?, phone = ?, reference_number = ?, processor_id = ?, dropbox_folder_path = ?, dropbox_folder_link = ?, cc_emails = ?, designated_person_name = ?, designated_person_phone = ?, portal_created_at = ?, dropbox_added_at = ? WHERE id = ?')
   ->execute([
       $fullName,
       $email,
       $caseType ?: null,
       $phone ?: null,
       $referenceNumber ?: null,
       $processorId ?: null,
       $dropboxPath,
       $dropboxLink,
       $ccEmailsRaw ?: null,
       $designatedName ?: null,
       $designatedPhone ?: null,
       $portalCreatedAt,
       $dropboxAddedAt,
       $clientId,
   ]);

$changed = [];
foreach (['full_name' => $fullName, 'email' => $email, 'case_type' => $caseType ?: null, 'phone' => $phone ?: null, 'reference_number' => $referenceNumber ?: null, 'processor_id' => $processorId ?: null, 'cc_emails' => $ccEmailsRaw ?: null] as $col => $val) {
    if ((string)($client[$col] ?? '') !== (string)($val ?? '')) $changed[] = str_replace('_', ' ', $col);
}
auditLog('client.updated', $clientId, $changed ? 'Changed: ' . implode(', ', $changed) : 'No changes');

// Anyone newly added to Additional emails gets a short welcome (best-effort).
$welcomed = notifyNewCcEmails(['full_name' => $fullName, 'email' => $email], $client['cc_emails'], $ccEmailsRaw, 'staff');

jsonResponse(true, 'Client updated.' . ($welcomed ? ' Welcome email sent to ' . $welcomed . ' new contact' . ($welcomed === 1 ? '' : 's') . '.' : ''), ['dropbox_warning' => $dropboxWarning]);
