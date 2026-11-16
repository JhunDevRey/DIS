<?php
/**
 * includes/client_auth.php
 * Include at the top of any portal/ or client-facing api/ page.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

startAppSession('duque_client_session');
sendSecurityHeaders();

// Session expired but this device was remembered ("Keep me signed in")?
// Sign back in silently — see includes/client_remember.php.
require_once __DIR__ . '/client_remember.php';
clientRememberTryLogin();

function clientIsApiRequest(): bool
{
    return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
}

function clientDenyAccess(): void
{
    if (clientIsApiRequest()) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
    // Every page that calls this lives right next to login.php (portal/ is
    // the subdomain's web root). ?redirect= brings them back to the page
    // they asked for; login.php re-validates it with safeRelativeRedirect().
    $back = basename($_SERVER['SCRIPT_NAME'] ?? '') . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    header('Location: login.php' . ($back !== '' ? '?redirect=' . urlencode($back) : ''));
    exit;
}

function requireClient(): void
{
    if (empty($_SESSION['client_id'])) {
        clientDenyAccess();
    }

    // Checked on every request, not just at sign-in: a client staff have
    // deactivated (or deleted) is signed out on their very next click.
    $stmt = getDb()->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([(int)$_SESSION['client_id']]);
    $row = $stmt->fetch();
    if (!$row || !(int)$row['is_active']) {
        clientRememberForgetAll((int)$_SESSION['client_id']);
        rememberClearCookie('client');
        $_SESSION = [];
        session_regenerate_id(true);
        clientDenyAccess();
    }

    // Password changed or reset since this session started? Then it's over.
    // (Sessions restored from a remembered device adopt the current version;
    // those tokens are deleted whenever the password changes.)
    $version = (int)($row['auth_version'] ?? 0);
    if (!isset($_SESSION['client_auth_version'])) {
        $_SESSION['client_auth_version'] = $version;
    } elseif ((int)$_SESSION['client_auth_version'] !== $version) {
        $_SESSION = [];
        session_regenerate_id(true);
        clientDenyAccess();
    }

    // Every state-changing request must carry this session's CSRF token.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        verifyCsrfToken();
    }

    // A password staff typed in is temporary: until the client picks their
    // own, every page except My Profile sends them there.
    if ((int)($row['must_change_password'] ?? 0) === 1) {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $allowed = ['profile.php', 'update_client_profile.php', 'logout.php', 'manifest.json'];
        if (!in_array($script, $allowed, true)) {
            if (clientIsApiRequest()) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Please choose your own password first (My Profile).']);
                exit;
            }
            header('Location: profile.php?set_password=1');
            exit;
        }
    }
}

/** True when the signed-in client still has a staff-set (temporary) password. */
function clientMustChangePassword(): bool
{
    if (empty($_SESSION['client_id'])) return false;
    $stmt = getDb()->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([(int)$_SESSION['client_id']]);
    $row = $stmt->fetch();
    return $row && (int)($row['must_change_password'] ?? 0) === 1;
}
