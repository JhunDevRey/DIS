<?php
if (session_status() === PHP_SESSION_NONE) {
    // See the matching comment in admin/logout.php.
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
// Signing out also forgets this device, so it asks for the password next time.
require_once __DIR__ . '/../includes/client_remember.php';
clientRememberForgetCurrent();
rememberForgetCurrent('contact');
session_destroy();
header('Location: login.php');
exit;
