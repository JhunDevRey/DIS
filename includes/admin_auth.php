<?php
/**
 * includes/admin_auth.php
 * Include at the top of any admin/ or admin-only api/ page.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Secure only turns on when the request itself is actually HTTPS —
    // checked directly per-request rather than reusing config.php's
    // $isLocal (which isn't guaranteed to be defined yet at this point
    // in the require order, and isn't needed here anyway: this is a more
    // direct, accurate signal). Forcing Secure on unconditionally would
    // break local Laragon development over plain HTTP, since a Secure
    // cookie is never sent back by the browser on a non-HTTPS request.
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('duque_admin_session');
    session_start();
}

// Session expired but this device was remembered ("Keep me signed in")?
// Sign back in silently — see includes/admin_remember.php.
require_once __DIR__ . '/admin_remember.php';
adminRememberTryLogin();

function requireAdmin(): void
{
    if (empty($_SESSION['admin_id'])) {
        // If this is an API call, respond JSON; otherwise redirect to login.
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
            exit;
        }
        // Every page that calls this lives right next to login.php (admin/ or
        // portal/, which is also each subdomain's web root), so a plain relative
        // 'login.php' is correct both live and on Laragon. ?redirect= brings
        // them back to the page they asked for; login.php re-validates it
        // with safeRelativeRedirect().
        $back = basename($_SERVER['SCRIPT_NAME'] ?? '') . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header('Location: login.php' . ($back !== '' ? '?redirect=' . urlencode($back) : ''));
        exit;
    }

    // CSRF check — scoped deliberately narrow: only a POST request to an
    // /api/ endpoint (i.e. an actual state-changing action) ever hits
    // this. A plain page load (GET) is never affected, so this can't
    // block simply viewing a page — only submitting an action without a
    // valid same-site token, which is exactly the thing worth blocking.
    $isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    if ($isApiRequest && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verifyCsrfToken();
    }
}
