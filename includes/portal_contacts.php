<?php
/**
 * includes/portal_contacts.php
 * Portal access for a client's CC contacts (their "Additional emails").
 *
 * Every address on a client's cc_emails list can sign in to that client's
 * portal with full access — the same as the applicant. Access is always
 * read from cc_emails itself (checked on every request), so taking an
 * address off the list ends that person's access straight away; the
 * portal_contacts table only holds each contact's own login (one per email
 * address, even if they're listed on several clients).
 *
 * A contact session carries the client they're working in like a normal
 * client session ($_SESSION['client_id'] / 'client_name'), so every portal
 * page works unchanged, plus $_SESSION['contact_id'] / 'contact_name' /
 * 'contact_email' so what they do is labelled as them (portalActorName()).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const CONTACT_SETUP_LINK_DAYS = 7;

function contactNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function portalIsContact(): bool
{
    return !empty($_SESSION['contact_id']);
}

/** Name to record on uploads, comments, messages and review responses. */
function portalActorName(): string
{
    if (portalIsContact()) {
        $who = trim((string)($_SESSION['contact_name'] ?? '')) ?: (string)($_SESSION['contact_email'] ?? 'Contact');
        return $who . ' (contact)';
    }
    return $_SESSION['client_name'] ?? 'Client';
}

/** Active clients that list this email in their Additional emails: [[id, full_name], …]. */
function contactClientsFor(string $email): array
{
    $email = contactNormalizeEmail($email);
    if ($email === '') return [];
    $stmt = getDb()->prepare("SELECT id, full_name, cc_emails FROM clients WHERE is_active = 1 AND cc_emails LIKE ? ORDER BY full_name");
    $stmt->execute(['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $email) . '%']);
    $out = [];
    foreach ($stmt->fetchAll() as $c) {
        foreach (splitCcEmails($c['cc_emails'])[0] as $cc) {
            if (contactNormalizeEmail($cc) === $email) { $out[] = ['id' => (int)$c['id'], 'full_name' => $c['full_name']]; break; }
        }
    }
    return $out;
}

function contactCanAccessClient(string $email, int $clientId): bool
{
    foreach (contactClientsFor($email) as $c) {
        if ($c['id'] === $clientId) return true;
    }
    return false;
}

function contactFind(int $contactId): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM portal_contacts WHERE id = ?');
    $stmt->execute([$contactId]);
    return $stmt->fetch() ?: null;
}

function contactFindByEmail(string $email): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM portal_contacts WHERE email = ?');
    $stmt->execute([contactNormalizeEmail($email)]);
    return $stmt->fetch() ?: null;
}

/** The contact's login row, created (without a password) if it doesn't exist yet. */
function contactEnsure(string $email): array
{
    $email = contactNormalizeEmail($email);
    getDb()->prepare('INSERT IGNORE INTO portal_contacts (email) VALUES (?)')->execute([$email]);
    return contactFindByEmail($email);
}

/** New one-time "set your password" link for a contact (valid CONTACT_SETUP_LINK_DAYS). */
function contactSetupLink(int $contactId): string
{
    $token = bin2hex(random_bytes(32));
    getDb()->prepare('UPDATE portal_contacts SET setup_token_hash = ?, setup_expires_at = ? WHERE id = ?')
           ->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + CONTACT_SETUP_LINK_DAYS * 86400), $contactId]);
    return rtrim(PORTAL_APP_URL, '/') . '/contact_setup.php?token=' . $token;
}

function contactFindBySetupToken(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = getDb()->prepare('SELECT * FROM portal_contacts WHERE setup_token_hash = ? AND setup_expires_at > UTC_TIMESTAMP()');
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

/**
 * For the welcome email when an address is added: a setup link if this
 * contact hasn't chosen a password yet, or null if they already have one
 * (they're already a contact on another file and just sign in).
 */
function contactInviteLink(string $email): ?string
{
    $contact = contactEnsure($email);
    return $contact['password_hash'] ? null : contactSetupLink((int)$contact['id']);
}

/** Starts a contact session (after a password / setup-link / remembered sign-in). */
function contactStartSession(array $contact): void
{
    session_regenerate_id(true);
    unset($_SESSION['client_id'], $_SESSION['client_name']);
    $_SESSION['contact_id'] = (int)$contact['id'];
    $_SESSION['contact_email'] = $contact['email'];
    $_SESSION['contact_name'] = $contact['name'] ?: '';
    getDb()->prepare('UPDATE portal_contacts SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$contact['id']]);
}

/** Puts the contact inside one client's portal (caller has checked access). */
function contactSelectClient(int $clientId, string $clientName): void
{
    $_SESSION['client_id'] = $clientId;
    $_SESSION['client_name'] = $clientName;
    getDb()->prepare('UPDATE portal_contacts SET last_client_id = ? WHERE id = ?')->execute([$clientId, $_SESSION['contact_id']]);
}

/**
 * Called on every portal request in a contact session. Makes sure the
 * contact still has access to the client they're in, or picks the client
 * for them (their last one, or their only one). Returns:
 *   'ok'     — a client is selected and access is confirmed
 *   'choose' — several clients; they need to pick (choose_client.php)
 *   'none'   — no access left anywhere; the session has been ended
 */
function contactResolveClient(): string
{
    $email = (string)($_SESSION['contact_email'] ?? '');
    $clients = contactClientsFor($email);
    if (!$clients) {
        $_SESSION = [];
        return 'none';
    }
    $current = (int)($_SESSION['client_id'] ?? 0);
    foreach ($clients as $c) {
        if ($c['id'] === $current) {
            $_SESSION['client_name'] = $c['full_name'];
            return 'ok';
        }
    }
    unset($_SESSION['client_id'], $_SESSION['client_name']);

    $contact = contactFind((int)$_SESSION['contact_id']);
    $pick = null;
    foreach ($clients as $c) {
        if ($contact && (int)$contact['last_client_id'] === $c['id']) $pick = $c;
    }
    if (!$pick && count($clients) === 1) $pick = $clients[0];
    if ($pick) {
        contactSelectClient($pick['id'], $pick['full_name']);
        return 'ok';
    }
    return 'choose';
}
