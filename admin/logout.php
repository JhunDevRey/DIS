<?php
// Signs out: forgets this device's "Keep me signed in" token, empties the
// session on the server and expires the session cookie in the browser.
require_once __DIR__ . '/../includes/security.php';
startAppSession('duque_admin_session');
sendSecurityHeaders();
require_once __DIR__ . '/../includes/admin_remember.php';
auditLog('staff.logout');
adminRememberForgetCurrent();
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
header('Location: login.php');
exit;
