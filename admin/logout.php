<?php
if (session_status() === PHP_SESSION_NONE) {
    // Same params as everywhere else this session name is started —
    // doesn't change behavior here (the session is destroyed on the very
    // next line regardless), kept only for consistency across all the
    // places this session gets started.
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
// Signing out also forgets this device, so it asks for the password next time.
require_once __DIR__ . '/../includes/admin_remember.php';
adminRememberForgetCurrent();
session_destroy();
header('Location: login.php');
exit;
