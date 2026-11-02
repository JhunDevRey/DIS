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

$fullName            = trim($_POST['full_name'] ?? '');
$email               = trim($_POST['email'] ?? '');
$caseType            = trim($_POST['case_type'] ?? '');
$phone               = trim($_POST['phone'] ?? '');
$password            = $_POST['password'] ?? '';
$referenceNumber     = trim($_POST['reference_number'] ?? '');
$processorId         = (int)($_POST['processor_id'] ?? 0);
$existingDropboxPath = trim($_POST['existing_dropbox_path'] ?? '');
$ccEmailsRaw         = trim($_POST['cc_emails'] ?? '');
$portalCreatedDay    = trim($_POST['portal_created_day'] ?? '');
$portalCreatedMonth  = trim($_POST['portal_created_month'] ?? '');
$portalCreatedYear   = trim($_POST['portal_created_year'] ?? '');
$dropboxAddedMonth   = trim($_POST['dropbox_added_month'] ?? '');
$dropboxAddedYear    = trim($_POST['dropbox_added_year'] ?? '');

if ($fullName === '' || $email === '' || $password === '') {
    jsonResponse(false, 'Name, email, and a temporary password are required.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, 'Please enter a valid email address.');
}
if (strlen($password) < 6) {
    jsonResponse(false, 'Temporary password must be at least 6 characters.');
}

// Portal creation date: only build it if all three parts were actually
// given, and only accept it if it's a real calendar date (checkdate
// catches e.g. day 31 in a month that doesn't have one).
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

// Validate each CC email individually so a typo gets a clear error now,
// rather than that address silently never receiving anything later.
if ($ccEmailsRaw !== '') {
    foreach (array_map('trim', explode(',', $ccEmailsRaw)) as $cc) {
        if ($cc !== '' && !filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, '"' . $cc . '" in the additional emails field doesn\'t look like a valid email address.');
        }
    }
}

$db = getDb();

$stmt = $db->prepare('SELECT id FROM clients WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    jsonResponse(false, 'A client with that email already exists.');
}

if ($processorId > 0) {
    $stmt = $db->prepare('SELECT id FROM processors WHERE id = ?');
    $stmt->execute([$processorId]);
    if (!$stmt->fetch()) {
        $processorId = 0;
    }
}

// Dropbox: auto-provisioning is best-effort (the client still gets created
// even if it fails, with a warning). But if staff explicitly typed in an
// EXISTING path, that's a claim of fact — if it can't actually be found,
// something's wrong (typo, wrong path, client doesn't really have that
// folder) and we should stop rather than create a client with a broken
// link, or worse, silently create a fresh folder somewhere unexpected.
$dropboxPath = null;
$dropboxLink = null;
$dropboxWarning = null;

if ($existingDropboxPath !== '' && dropboxIsConfigured()) {
    $linked = dropboxLinkExistingFolder($existingDropboxPath);
    if (!$linked['ok']) {
        jsonResponse(false, 'That Dropbox path could not be found — double-check it and try again: ' . $linked['error'], ['error_type' => 'dropbox_path']);
    }
    $dropboxPath = $linked['path'];
    $dropboxLink = $linked['link'];
} elseif (dropboxIsConfigured()) {
    // If this Client No. already belongs to another client with a folder on
    // file (e.g. a returning client, or a second case for the same person),
    // reuse that exact folder instead of creating a new one.
    $reused = null;
    if ($referenceNumber !== '') {
        $stmt = $db->prepare('SELECT dropbox_folder_path, dropbox_folder_link FROM clients WHERE reference_number = ? AND dropbox_folder_path IS NOT NULL LIMIT 1');
        $stmt->execute([$referenceNumber]);
        $reused = $stmt->fetch();
    }

    if ($reused) {
        $dropboxPath = $reused['dropbox_folder_path'];
        $dropboxLink = $reused['dropbox_folder_link'];
    } else {
        $provision = dropboxProvisionClientFolder($fullName, $referenceNumber !== '' ? $referenceNumber : null, $caseType);
        if ($provision['ok']) {
            $dropboxPath = $provision['path'];
            $dropboxLink = $provision['link'];
            // No Client No. given? Use the auto-assigned one so it's saved on the client record too.
            if ($referenceNumber === '' && $provision['client_number']) {
                $referenceNumber = (string)$provision['client_number'];
            }
        } else {
            $dropboxWarning = 'Client was created, but the Dropbox folder could not be set up: ' . $provision['error'];
        }
    }
}

$stmt = $db->prepare('INSERT INTO clients (full_name, email, password_hash, plain_password, case_type, phone, reference_number, processor_id, dropbox_folder_path, dropbox_folder_link, cc_emails, portal_created_at, dropbox_added_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([
    $fullName,
    $email,
    password_hash($password, PASSWORD_DEFAULT),
    $password,
    $caseType ?: null,
    $phone ?: null,
    $referenceNumber ?: null,
    $processorId ?: null,
    $dropboxPath,
    $dropboxLink,
    $ccEmailsRaw ?: null,
    $portalCreatedAt,
    $dropboxAddedAt,
]);

// Welcome email is best-effort — never blocks or fails client creation.
notifyClientWelcome(['full_name' => $fullName, 'email' => $email, 'cc_emails' => $ccEmailsRaw], $password);

jsonResponse(true, 'Client created.', [
    'client_id' => $db->lastInsertId(),
    'dropbox_warning' => $dropboxWarning,
]);
