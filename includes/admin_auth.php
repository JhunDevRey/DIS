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
    // Password changed, two-step reset or "sign out everywhere" since this
    // session started? Then this session is over too. (A session restored
    // from a remembered device adopts the current version: its token was
    // already checked, and those tokens are deleted at the same moments.)
    $version = (int)($account['auth_version'] ?? 0);
    if (!isset($_SESSION['admin_auth_version'])) {
        $_SESSION['admin_auth_version'] = $version;
    } elseif ((int)$_SESSION['admin_auth_version'] !== $version) {
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

    // A password an owner set is temporary, and (when config requires it)
    // two-step sign-in must be on: until then only My Profile is usable.
    require_once __DIR__ . '/staff_security.php';
    $mustChange = (int)($account['must_change_password'] ?? 0) === 1;
    $must2fa = adminRequire2fa() && !adminUses2fa($account);
    if ($mustChange || $must2fa) {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($script, ['profile.php', 'update_admin_profile.php', 'staff_2fa.php', 'logout.php', 'manifest.json'], true)) {
            if (adminIsApiRequest()) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => $mustChange ? 'Please choose your own password first (My Profile).' : 'Please turn on two-step sign-in first (My Profile).']);
                exit;
            }
            header('Location: profile.php?' . ($mustChange ? 'set_password=1' : 'setup_2fa=1'));
            exit;
        }
    }
}
