<?php
/**
 * includes/admin_remember.php
 * "Keep me signed in on this device" for the staff admin — thin wrappers
 * over includes/remember_me.php (realm 'admin': admin_remember_tokens,
 * 14 days — shorter than the client portal's 30, since a staff sign-in
 * reaches every client's file).
 *
 * Staff share one login, so "sign out of all devices" and a password
 * change sign out every staff member's remembered devices.
 */

require_once __DIR__ . '/remember_me.php';

const ADMIN_REMEMBER_DAYS = 14;

function adminRememberIssue(int $adminId): void { rememberIssue('admin', $adminId); }
function adminRememberTryLogin(): bool { return rememberTryLogin('admin'); }
function adminRememberForgetCurrent(): void { rememberForgetCurrent('admin'); }
function adminRememberForgetAll(int $adminId, bool $keepCurrent = false): int { return rememberForgetAll('admin', $adminId, $keepCurrent); }
function adminRememberOtherDeviceCount(int $adminId): int { return rememberOtherDeviceCount('admin', $adminId); }
function adminRememberThisDevice(int $adminId): bool { return rememberThisDevice('admin', $adminId); }
