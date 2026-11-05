<?php
/**
 * includes/client_remember.php
 * "Keep me signed in on this device" for the client portal — thin
 * wrappers over the shared engine in includes/remember_me.php (realm
 * 'client': client_remember_tokens, 30 days).
 *
 * Used by includes/client_auth.php (silent sign-in when the normal
 * session has expired), portal/login.php, portal/logout.php, the
 * portal profile page and the password-change paths.
 */

require_once __DIR__ . '/remember_me.php';

const CLIENT_REMEMBER_DAYS = 30;

function clientRememberIssue(int $clientId): void { rememberIssue('client', $clientId); }
function clientRememberTryLogin(): bool { return rememberTryLogin('client'); }
function clientRememberForgetCurrent(): void { rememberForgetCurrent('client'); }
function clientRememberForgetAll(int $clientId, bool $keepCurrent = false): int { return rememberForgetAll('client', $clientId, $keepCurrent); }
function clientRememberOtherDeviceCount(int $clientId): int { return rememberOtherDeviceCount('client', $clientId); }
function clientRememberThisDevice(int $clientId): bool { return rememberThisDevice('client', $clientId); }
