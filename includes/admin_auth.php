<?php
/**
 * includes/admin_auth.php
 * Include at the top of any admin/ or admin-only api/ page.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

startAppSession('duque_admin_session');
sendSecurityHeaders();

// Session expired but this device was remembered ("Keep me signed in")?
// Sign back in silently — see includes/admin_remember.php.
require_once __DIR__ . '/admin_remember.php';
adminRememberTryLogin();

function adminIsApiRequest(): bool
{
    return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
}

function adminDenyAccess(): void
{
    if (adminIsApiRequest()) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
    // Every page that calls this lives right next to login.php (admin/ is
    // the subdomain's web root). ?redirect= brings them back to the page
    // they asked for; login.php re-validates it with safeRelativeRedirect().
    $back = basename($_SERVER['SCRIPT_NAME'] ?? '') . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    header('Location: login.php' . ($back !== '' ? '?redirect=' . urlencode($back) : ''));
    exit;
}

function requireAdmin(): void
{
    if (empty($_SESSION['admin_id'])) {
        adminDenyAccess();
    }

    // Checked on every request: a staff account that was removed is
    // signed out on its very next click, on every device.
    $stmt = getDb()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $account = $stmt->fetch();
    if (!$account || (array_key_exists('is_active', $account) && !(int)$account['is_active'])) {
        adminRememberForgetAll((int)$_SESSION['admin_id']);
        rememberClearCookie('admin');
        $_SESSION = [];
        session_regenerate_id(true);
        adminDenyAccess();
    }
    $GLOBALS['currentAdmin'] = $account;

    // Every state-changing request must carry this session's CSRF token.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        verifyCsrfToken();
    }
}
