<?php
/**
 * includes/client_auth.php
 * Include at the top of any portal/ or client-facing api/ page.
 */

if (session_status() === PHP_SESSION_NONE) {
    // See the matching comment in admin_auth.php — same reasoning.
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('duque_client_session');
    session_start();
}

function requireClient(): void
{
    if (empty($_SESSION['client_id'])) {
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

    // See the matching comment in admin_auth.php — same narrow scoping:
    // only a POST to /api/ triggers this, never a plain page view.
    $isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    if ($isApiRequest && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verifyCsrfToken();
    }
}
