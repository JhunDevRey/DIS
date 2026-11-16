<?php
// Staff accounts, two-step sign-in and the activity log, end to end on the LOCAL rig.
require __DIR__ . '/tests_lib.php';
const CFG = SP . '/app/config.php';
$cfgOriginal = file_get_contents(CFG);
register_shutdown_function(fn() => file_put_contents(CFG, $GLOBALS['cfgOriginal']));
function staffApi(string $jar, array $fields): array {
    $tok = pageCsrf($jar, ADMIN . 'profile.php');
    [$s, , $body] = req($jar, 'POST', ADMIN . 'api/staff_accounts.php', $fields, ['X-CSRF-Token: ' . $tok]);
    return [$s, json_decode($body, true) ?: ['raw' => $body]];
}
function twofa(string $jar, array $fields): array {
    $tok = pageCsrf($jar, ADMIN . 'profile.php');
    [, , $body] = req($jar, 'POST', ADMIN . 'api/staff_2fa.php', $fields, ['X-CSRF-Token: ' . $tok]);
    return json_decode($body, true) ?: ['raw' => $body];
}
function codeNow(string $secret, int $offset = 0): string { return appCall('totpCode', $secret, intdiv(time(), 30) + $offset); }
/** Password step; returns the page after following to login.php. */
function passwordStep(string $jar, string $user, string $pw): array {
    [$s, $h] = staffLogin($jar, $user, $pw);
    return [$s, $h['location'] ?? ''];
}
function codeStep(string $jar, string $code): array {
    [, , $html] = req($jar, 'GET', ADMIN . 'login.php');
    [$s, $h, $body] = req($jar, 'POST', ADMIN . 'login.php', ['step' => 'code', 'code' => $code, 'csrf_token' => csrfFrom($html)]);
    return [$s, $h['location'] ?? '', $body];
}

echo "Authenticator codes (RFC 6238 test vectors)\n";
$rfc = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"
foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $t => $want) {
    check("code at t=$t", appCall('totpCode', $rfc, intdiv($t, 30)) === $want, appCall('totpCode', $rfc, intdiv($t, 30)));
}
check('verify accepts the current code', appCall('totpVerify', $rfc, '287082', null, 59) === 1);
check('verify accepts one step of clock drift', appCall('totpVerify', $rfc, '287082', null, 89) === 1);
check('verify refuses a replayed step', appCall('totpVerify', $rfc, '287082', 1, 59) === null);
$enc = appCall('totpEncryptSecret', $rfc);
check('secret stored encrypted', str_starts_with($enc, 'v1:') && !str_contains($enc, $rfc));
check('secret decrypts', appCall('totpDecryptSecret', $enc) === $rfc);
check('tampered secret refused', appCall('totpDecryptSecret', substr($enc, 0, -4) . 'AAAA') === null);

echo "Owner pages\n";
staffLogin('owner');
[$s] = req('owner', 'GET', ADMIN . 'staff.php');
check('owner opens Staff accounts', $s === 200, $s);
[$s] = req('owner', 'GET', ADMIN . 'activity.php');
check('owner opens Activity', $s === 200, $s);

echo "Create an individual account\n";
[, $j] = staffApi('owner', ['action' => 'create', 'username' => 'ana', 'admin_name' => 'Ana Reyes', 'password' => 'short']);
check('short staff password refused', ($j['success'] ?? true) === false, $j);
[, $j] = staffApi('owner', ['action' => 'create', 'username' => 'ana', 'admin_name' => 'Ana Reyes', 'email' => 'ana@example.test', 'processor_id' => 1, 'password' => 'Temporary pass for Ana']);
check('account created', ($j['success'] ?? false) === true, $j);
$anaId = (int)($j['id'] ?? 0);
[, $j] = staffApi('owner', ['action' => 'create', 'username' => 'ANA', 'admin_name' => 'Dup', 'password' => 'Another long pass 99']);
check('duplicate username refused', ($j['success'] ?? true) === false, $j);
$ana = q('SELECT * FROM admin_users WHERE id = ?', [$anaId])->fetch();
check('new account must change password, not owner', (int)$ana['must_change_password'] === 1 && (int)$ana['is_owner'] === 0, $ana);

echo "First sign-in: forced password change\n";
[$s, $loc] = passwordStep('ana', 'ana', 'Temporary pass for Ana');
check('ana signs in', $s === 302, $s);
[$s, $h] = req('ana', 'GET', ADMIN . 'clients.php');
check('sent to My Profile to choose a password', $s === 302 && ($h['location'] ?? '') === 'profile.php?set_password=1', [$s, $h['location'] ?? '']);
$tok = pageCsrf('ana', ADMIN . 'profile.php');
[, , $body] = req('ana', 'POST', ADMIN . 'api/update_admin_profile.php', ['action' => 'update_password', 'current_password' => 'Temporary pass for Ana', 'new_password' => 'Ana chooses a long one 7'], ['X-CSRF-Token: ' . $tok]);
check('own password saved', (json_decode($body, true)['success'] ?? false) === true, $body);
[$s] = req('ana', 'GET', ADMIN . 'clients.php');
check('portal opens after choosing', $s === 200, $s);

echo "Owners only\n";
[$s] = req('ana', 'GET', ADMIN . 'staff.php');
check('non-owner refused Staff accounts', $s === 403, $s);
[$s] = req('ana', 'GET', ADMIN . 'activity.php');
check('non-owner refused Activity', $s === 403, $s);
[$s, $j] = staffApi('ana', ['action' => 'set_active', 'id' => 1, 'active' => 0]);
check('non-owner refused the accounts API', $s === 403 && ($j['success'] ?? true) === false, [$s, $j]);
[, , $html] = req('ana', 'GET', ADMIN . 'frontdesk.php');
check('non-owner sidebar has no owner links', !str_contains($html, 'href="staff.php"'));

echo "Turn on two-step sign-in\n";
$j = twofa('ana', ['action' => 'start', 'password' => 'wrong']);
check('wrong password refused', ($j['success'] ?? true) === false, $j);
$j = twofa('ana', ['action' => 'start', 'password' => 'Ana chooses a long one 7']);
$secret = str_replace(' ', '', $j['secret'] ?? '');
check('secret and QR link given', strlen($secret) === 32 && str_starts_with($j['uri'] ?? '', 'otpauth://totp/'), $j);
$j = twofa('ana', ['action' => 'confirm', 'code' => '000000']);
check('wrong code refused', ($j['success'] ?? true) === false, $j);
$j = twofa('ana', ['action' => 'confirm', 'code' => codeNow($secret)]);
$recovery = $j['recovery_codes'] ?? [];
check('two-step on, 8 recovery codes', ($j['success'] ?? false) === true && count($recovery) === 8, $j);
$row = q('SELECT totp_secret, totp_enabled, totp_recovery FROM admin_users WHERE id = ?', [$anaId])->fetch();
check('secret encrypted in the database', !str_contains($row['totp_secret'], $secret) && (int)$row['totp_enabled'] === 1);
check('recovery codes stored as hashes', !str_contains($row['totp_recovery'], $recovery[0] ?? 'x'));
[$s] = req('ana', 'GET', ADMIN . 'clients.php');
check('this session stays signed in', $s === 200, $s);

echo "Signing in with two-step\n";
req('ana', 'GET', ADMIN . 'logout.php');
[$s, $loc] = passwordStep('ana2', 'ana', 'Ana chooses a long one 7');
check('password step goes to the code step', $s === 302 && $loc === 'login.php', [$s, $loc]);
[$s] = req('ana2', 'GET', ADMIN . 'clients.php');
check('not signed in before the code', $s === 302, $s);
[, , $html] = req('ana2', 'GET', ADMIN . 'login.php');
check('code page shown', str_contains($html, 'Enter your code'));
[$s, $loc, $body] = codeStep('ana2', '123456');
check('wrong code refused', $s === 200 && str_contains($body, "didn&#039;t work"), $s);
sleep(0);
$step = intdiv(time(), 30);
$good = appCall('totpCode', $secret, $step + 1); // next step: never used yet, inside the drift window
[$s, $loc] = codeStep('ana2', $good);
check('right code signs in', $s === 302 && $loc === 'frontdesk.php', [$s, $loc]);
[$s] = req('ana2', 'GET', ADMIN . 'clients.php');
check('signed in', $s === 200, $s);
passwordStep('ana3', 'ana', 'Ana chooses a long one 7');
[$s, , $body] = codeStep('ana3', $good);
check('same code can\'t be used twice', $s === 200, $s);
[$s, $loc] = codeStep('ana3', strtoupper($recovery[0]));
check('recovery code signs in', $s === 302, [$s, $loc]);
passwordStep('ana4', 'ana', 'Ana chooses a long one 7');
[$s] = codeStep('ana4', $recovery[0]);
check('recovery code works only once', $s === 200, $s);
q("DELETE FROM login_attempts WHERE identifier LIKE 'login-2fa:%'");
passwordStep('ana5', 'ana', 'Ana chooses a long one 7');
for ($i = 0; $i < 5; $i++) codeStep('ana5', '00000' . $i);
[$s, , $body] = codeStep('ana5', appCall('totpCode', $secret, intdiv(time(), 30) - 1));
check('after 5 wrong codes the sign-in starts over', str_contains($body, 'Too many wrong codes') || str_contains($body, 'Staff sign in'), substr(strip_tags($body), 0, 120));
check('failed codes logged', (int)q("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.login_2fa_failed'")->fetchColumn() >= 5);
q("DELETE FROM login_attempts WHERE identifier LIKE 'login-2fa:%'");

echo "Owner actions end sessions\n";
[, $j] = staffApi('owner', ['action' => 'reset_2fa', 'id' => $anaId]);
check('owner resets two-step', ($j['success'] ?? false) === true, $j);
[$s] = req('ana2', 'GET', ADMIN . 'clients.php');
check('ana\'s open session ended', $s === 302, $s);
passwordStep('ana6', 'ana', 'Ana chooses a long one 7');
[$s] = req('ana6', 'GET', ADMIN . 'clients.php');
check('ana signs in with password only after reset', $s === 200, $s);
[, $j] = staffApi('owner', ['action' => 'set_active', 'id' => $anaId, 'active' => 0]);
check('owner deactivates ana', ($j['success'] ?? false) === true, $j);
[$s] = req('ana6', 'GET', ADMIN . 'clients.php');
check('deactivated: open session ends', $s === 302, $s);
[$s, $loc] = passwordStep('ana7', 'ana', 'Ana chooses a long one 7');
check('deactivated: can\'t sign in', $s === 200, $s);
[, $j] = staffApi('owner', ['action' => 'set_active', 'id' => $anaId, 'active' => 1]);
check('reactivated', ($j['success'] ?? false) === true, $j);

echo "Owner lock-out guards\n";
[, $j] = staffApi('owner', ['action' => 'set_active', 'id' => 1, 'active' => 0]);
check('owner can\'t deactivate self', ($j['success'] ?? true) === false, $j);
[, $j] = staffApi('owner', ['action' => 'update', 'id' => 1, 'username' => 'processing', 'admin_name' => 'Processing Staff', 'is_owner' => 0]);
check('owner can\'t remove own owner role', ($j['success'] ?? true) === false, $j);
[, $j] = staffApi('owner', ['action' => 'update', 'id' => $anaId, 'username' => 'ana', 'admin_name' => 'Ana Reyes', 'processor_id' => 1, 'is_owner' => 1]);
check('second owner added', ($j['success'] ?? false) === true, $j);
[, $j] = staffApi('owner', ['action' => 'update', 'id' => $anaId, 'username' => 'ana', 'admin_name' => 'Ana Reyes', 'processor_id' => 1, 'is_owner' => 0]);
check('…and removed again', ($j['success'] ?? false) === true, $j);

echo "Messages go out under the linked processor\n";
passwordStep('ana8', 'ana', 'Ana chooses a long one 7');
[, , $html] = req('ana8', 'GET', ADMIN . 'inbox.php?client_id=1');
check('inbox picker offers only the linked name', str_contains($html, 'Lucia Liu') && !str_contains($html, '<option value="">Duque Immigration (team)</option>'));
$tok = csrfFrom($html);
q("INSERT INTO processors (full_name, email) VALUES ('Other Person', 'o@example.test')");
$other = (int)q('SELECT LAST_INSERT_ID()')->fetchColumn();
req('ana8', 'POST', ADMIN . 'api/admin_messages.php', ['action' => 'send', 'client_id' => 1, 'body' => 'hello from ana', 'sender_id' => $other], ['X-CSRF-Token: ' . $tok]);
$sender = q("SELECT sender_name FROM messages WHERE body = 'hello from ana'")->fetchColumn();
check('sent as Lucia Liu even when asking for someone else', $sender === 'Lucia Liu', $sender);
staffLogin('owner');
[, , $html] = req('owner', 'GET', ADMIN . 'inbox.php?client_id=1');
check('shared login still has the full picker', str_contains($html, 'Duque Immigration (team)') && str_contains($html, 'Other Person'));

echo "Client sessions end when staff set a password\n";
clientLogin('cl');
[$s] = req('cl', 'GET', PORTAL . 'documents.php');
check('client signed in', $s === 200, $s);
$tok = pageCsrf('owner', ADMIN . 'clients.php');
req('owner', 'POST', ADMIN . 'api/admin_reset_client_password.php', ['client_id' => 1, 'new_password' => 'Fresh temp pass 55'], ['X-CSRF-Token: ' . $tok]);
[$s] = req('cl', 'GET', PORTAL . 'documents.php');
check('client\'s open session ended', $s === 302, $s);

echo "Required two-step for everyone\n";
file_put_contents(CFG, $cfgOriginal . "define('ADMIN_REQUIRE_2FA', true);\n");
[$s, $h] = req('owner', 'GET', ADMIN . 'clients.php');
check('account without two-step sent to set it up', $s === 302 && ($h['location'] ?? '') === 'profile.php?setup_2fa=1', [$s, $h['location'] ?? '']);
[$s, , $html] = req('owner', 'GET', ADMIN . 'profile.php?setup_2fa=1');
check('profile explains it', $s === 200 && str_contains($html, 'Every staff account needs two-step sign-in'));
file_put_contents(CFG, $cfgOriginal);

echo "Activity page\n";
[, , $html] = req('owner', 'GET', ADMIN . 'activity.php');
check('lists sign-ins', str_contains($html, 'Signed in'));
check('lists account changes', str_contains($html, 'Created staff account') && str_contains($html, 'Deactivated staff account'));
[, , $html] = req('owner', 'GET', ADMIN . 'activity.php?type=failed');
check('failed filter shows wrong codes', str_contains($html, 'Wrong sign-in code') && !str_contains($html, 'Created staff account'));
[, , $html] = req('owner', 'GET', ADMIN . 'activity.php?actor=admin:' . $anaId);
check('per-person filter', str_contains($html, '>Ana Reyes</a>') && !str_contains($html, '>Processing Staff</a>'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
