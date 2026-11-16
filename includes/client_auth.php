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
    $stmt = getDb()->prepare('SELECT is_active FROM clients WHERE id = ?');
    $stmt->execute([(int)$_SESSION['client_id']]);
    $active = $stmt->fetchColumn();
    if ($active === false || !(int)$active) {
        clientRememberForgetAll((int)$_SESSION['client_id']);
        rememberClearCookie('client');
        $_SESSION = [];
        session_regenerate_id(true);
        clientDenyAccess();
    }

    // Every state-changing request must carry this session's CSRF token.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        verifyCsrfToken();
    }
}
