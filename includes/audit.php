<?php
/**
 * includes/audit.php
 * Activity log: who did what, to which client, from where, and when.
 * Answers "who opened this passport scan?" or "who reset this password?".
 *
 *   auditLog('file.download', $clientId, 'passport.pdf');
 *
 * The actor comes from the current session: a staff account (admin
 * session) or a client (portal session). Never throws: a logging problem
 * must not stop the action itself. The table is created on first use,
 * like login_attempts, so a deploy can't miss a migration. Rows older
 * than AUDIT_KEEP_DAYS are pruned now and then.
 *
 * Staff can read it on admin/activity.php.
 */

require_once __DIR__ . '/db.php';

const AUDIT_KEEP_DAYS = 400;

function auditEnsureTable(PDO $db): void
{
    static $ensured = false;
    if ($ensured) return;
    $db->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actor_type VARCHAR(10) NOT NULL,
            actor_id INT DEFAULT NULL,
            actor_name VARCHAR(150) DEFAULT NULL,
            action VARCHAR(60) NOT NULL,
            client_id INT DEFAULT NULL,
            detail VARCHAR(500) DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            INDEX idx_audit_time (created_at),
            INDEX idx_audit_client (client_id, created_at),
            INDEX idx_audit_action (action, created_at),
            INDEX idx_audit_actor (actor_type, actor_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ensured = true;
}

/**
 * $actor overrides the session (e.g. a failed sign-in, where nobody is
 * signed in yet): ['type' => 'admin'|'client'|'system', 'id' => ?int, 'name' => ?string].
 */
function auditLog(string $action, ?int $clientId = null, string $detail = '', ?array $actor = null): void
{
    try {
        if ($actor === null) {
            if (session_name() === 'duque_admin_session' && !empty($_SESSION['admin_id'])) {
                $actor = ['type' => 'admin', 'id' => (int)$_SESSION['admin_id'], 'name' => $_SESSION['admin_name'] ?? null];
            } elseif (session_name() === 'duque_client_session' && !empty($_SESSION['client_id'])) {
                $actor = ['type' => 'client', 'id' => (int)$_SESSION['client_id'], 'name' => $_SESSION['client_name'] ?? null];
                $clientId = $clientId ?? (int)$_SESSION['client_id'];
            } else {
                $actor = ['type' => 'system', 'id' => null, 'name' => null];
            }
        }
        $db = getDb();
        auditEnsureTable($db);
        $db->prepare('INSERT INTO audit_log (actor_type, actor_id, actor_name, action, client_id, detail, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([
               $actor['type'],
               $actor['id'] ?? null,
               isset($actor['name']) ? mb_substr((string)$actor['name'], 0, 150) : null,
               mb_substr($action, 0, 60),
               $clientId ?: null,
               $detail !== '' ? mb_substr($detail, 0, 500) : null,
               function_exists('clientIp') ? mb_substr(clientIp(), 0, 45) : ($_SERVER['REMOTE_ADDR'] ?? null),
               mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
           ]);
        if (random_int(1, 200) === 1) {
            $db->exec('DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL ' . AUDIT_KEEP_DAYS . ' DAY');
        }
    } catch (Throwable $e) {
        error_log('auditLog ' . $action . ': ' . $e->getMessage());
    }
}

/** Human wording for the activity page. */
function auditActionLabel(string $action): string
{
    static $labels = [
        'staff.login'            => 'Signed in',
        'staff.login_failed'     => 'Failed sign-in',
        'staff.login_2fa_failed' => 'Wrong sign-in code',
        'staff.logout'           => 'Signed out',
        'staff.password_changed' => 'Changed own password',
        'staff.2fa_enabled'      => 'Turned on two-step sign-in',
        'staff.2fa_disabled'     => 'Turned off two-step sign-in',
        'staff.devices_signed_out' => 'Signed out other devices',
        'staff.account_created'  => 'Created staff account',
        'staff.account_updated'  => 'Changed staff account',
        'staff.account_disabled' => 'Deactivated staff account',
        'staff.account_enabled'  => 'Reactivated staff account',
        'staff.password_reset'   => 'Reset a staff password',
        'staff.2fa_reset'        => 'Reset staff two-step sign-in',
        'client.login'           => 'Client signed in',
        'client.login_failed'    => 'Client failed sign-in',
        'client.password_changed' => 'Client changed password',
        'client.password_reset_requested' => 'Client asked for a reset link',
        'client.password_reset_done' => 'Client reset password by link',
        'client.created'         => 'Created client',
        'client.updated'         => 'Edited client details',
        'client.password_set'    => 'Set client password',
        'client.reset_link_sent' => 'Sent client a reset link',
        'client.deactivated'     => 'Deactivated client portal',
        'client.reactivated'     => 'Reactivated client portal',
        'file.view'              => 'Viewed file',
        'file.download'          => 'Downloaded file',
        'file.download_zip'      => 'Downloaded files (zip)',
        'file.delete'            => 'Deleted file',
        'file.upload'            => 'Uploaded file',
        'message.file_view'      => 'Opened message attachment',
        'docfield.delete'        => 'Removed document field',
        'processor.created'      => 'Added processor',
        'processor.deleted'      => 'Removed processor',
    ];
    return $labels[$action] ?? $action;
}
