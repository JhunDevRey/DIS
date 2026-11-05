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

// Session expired but this device was remembered ("Keep me signed in")?
// Sign back in silently — as the client, or as one of their CC contacts
// (see includes/client_remember.php and includes/portal_contacts.php).
require_once __DIR__ . '/client_remember.php';
require_once __DIR__ . '/portal_contacts.php';
if (empty($_SESSION['client_id']) && empty($_SESSION['contact_id'])) {
    clientRememberTryLogin() || rememberTryLogin('contact');
}

function requireClient(): void
{
    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

    // A CC contact: re-check on every request that they're still listed on
    // this client (taking them off Additional emails ends access at once),
    // and pick the client for them if needed.
    if (!empty($_SESSION['contact_id'])) {
        $state = contactResolveClient();
        if ($state === 'none') {
            rememberForgetCurrent('contact');
        } elseif ($state === 'choose') {
            if ($isApi) {
                header('Content-Type: application/json');
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Choose which client to open first.']);
                exit;
            }
            $back = basename($_SERVER['SCRIPT_NAME'] ?? '') . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
            header('Location: choose_client.php?redirect=' . urlencode($back));
            exit;
        }
    }

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
