<?php
// Client password handling, end to end on the LOCAL rig.
require __DIR__ . '/tests_lib.php';

echo "Staff never see client passwords\n";
staffLogin('staff');
[$s, , $html] = req('staff', 'GET', ADMIN . 'clients.php');
check('clients list loads', $s === 200, $s);
check('no data-password attribute in the page', !str_contains($html, 'data-password='));
check('reset-link and temporary-password buttons present', str_contains($html, 'data-send-reset=') && str_contains($html, 'data-edit-password='));
$tok = csrfFrom($html);

echo "Staff set a temporary password\n";
[, , $body] = req('staff', 'POST', ADMIN . 'api/admin_reset_client_password.php', ['client_id' => 1, 'new_password' => 'short'], ['X-CSRF-Token: ' . $tok]);
check('too-short password refused', (json_decode($body, true)['success'] ?? true) === false, $body);
[, , $body] = req('staff', 'POST', ADMIN . 'api/admin_reset_client_password.php', ['client_id' => 1, 'new_password' => 'password123'], ['X-CSRF-Token: ' . $tok]);
check('common password refused', (json_decode($body, true)['success'] ?? true) === false, $body);
[, , $body] = req('staff', 'POST', ADMIN . 'api/admin_reset_client_password.php', ['client_id' => 1, 'new_password' => 'Temp-Sunrise-42'], ['X-CSRF-Token: ' . $tok]);
$j = json_decode($body, true);
check('temporary password saved', ($j['success'] ?? false) === true, $body);
check('response does not echo the password', !str_contains($body, 'Temp-Sunrise-42'));
$c = q('SELECT plain_password, must_change_password, password_hash FROM clients WHERE id = 1')->fetch();
check('no readable copy stored', $c['plain_password'] === null, $c['plain_password']);
check('flagged as temporary', (int)$c['must_change_password'] === 1);
check('hash matches the new password', password_verify('Temp-Sunrise-42', $c['password_hash']));
check('activity logged', (int)q("SELECT COUNT(*) FROM audit_log WHERE action = 'client.password_set' AND client_id = 1")->fetchColumn() === 1);

echo "Client must choose their own password\n";
[$s, $h] = clientLogin('client', 'maria.santos@example.test', 'Temp-Sunrise-42');
check('temporary password signs in', $s === 302, $s);
[$s, $h] = req('client', 'GET', PORTAL . 'documents.php');
check('documents redirect to profile', $s === 302 && ($h['location'] ?? '') === 'profile.php?set_password=1', [$s, $h['location'] ?? '']);
[$s] = req('client', 'GET', PORTAL . 'api/client_messages.php?after_id=0');
check('API blocked until changed', $s === 403, $s);
[$s, , $html] = req('client', 'GET', PORTAL . 'profile.php?set_password=1');
check('profile shows the banner', $s === 200 && str_contains($html, 'Please choose your own password'), $s);
$ctok = csrfFrom($html);
[, , $body] = req('client', 'POST', PORTAL . 'api/update_client_profile.php', ['action' => 'update_password', 'current_password' => 'Temp-Sunrise-42', 'new_password' => 'Temp-Sunrise-42'], ['X-CSRF-Token: ' . $ctok]);
check('same password as temporary refused', (json_decode($body, true)['success'] ?? true) === false, $body);
[, , $body] = req('client', 'POST', PORTAL . 'api/update_client_profile.php', ['action' => 'update_password', 'current_password' => 'Temp-Sunrise-42', 'new_password' => 'maria.santos@example.test'], ['X-CSRF-Token: ' . $ctok]);
check('email as password refused', (json_decode($body, true)['success'] ?? true) === false, $body);
[, , $body] = req('client', 'POST', PORTAL . 'api/update_client_profile.php', ['action' => 'update_password', 'current_password' => 'Temp-Sunrise-42', 'new_password' => 'My own garden 2026'], ['X-CSRF-Token: ' . $ctok]);
check('own password accepted', (json_decode($body, true)['success'] ?? false) === true, $body);
$c = q('SELECT plain_password, must_change_password FROM clients WHERE id = 1')->fetch();
check('flag cleared, still no readable copy', (int)$c['must_change_password'] === 0 && $c['plain_password'] === null, $c);
[$s] = req('client', 'GET', PORTAL . 'documents.php');
check('documents open again', $s === 200, $s);

echo "Forgot password page\n";
freshJar('anon');
[, , $html] = req('anon', 'GET', PORTAL . 'forgot_password.php');
$atok = csrfFrom($html);
[, , $known] = req('anon', 'POST', PORTAL . 'forgot_password.php', ['email' => 'maria.santos@example.test', 'csrf_token' => $atok]);
[, , $unknown] = req('anon', 'POST', PORTAL . 'forgot_password.php', ['email' => 'nobody@example.test', 'csrf_token' => $atok]);
$strip = fn($h) => preg_replace('/\s+/', ' ', strip_tags(preg_replace(['/<strong>[^<]*<\/strong>/', '/<input[^>]+>/'], '', $h)));
check('same answer for a client and a stranger', $strip($known) === $strip($unknown));
check('link row created only for the real client', (int)q('SELECT COUNT(*) FROM client_password_resets')->fetchColumn() === 1);
[, , $body] = req('anon', 'POST', PORTAL . 'forgot_password.php', ['email' => 'maria.santos@example.test']);
check('no token = refused', str_contains($body, 'This page expired'));
for ($i = 0; $i < 3; $i++) req('anon', 'POST', PORTAL . 'forgot_password.php', ['email' => 'maria.santos@example.test', 'csrf_token' => $atok]);
[, , $body] = req('anon', 'POST', PORTAL . 'forgot_password.php', ['email' => 'maria.santos@example.test', 'csrf_token' => $atok]);
check('4th request for one email in an hour is throttled', str_contains($body, 'Too many reset requests'));

echo "Reset links\n";
$url = appCall('createClientResetLink', 1, 'client');
$t = substr($url, strpos($url, 't=') + 2);
check('link points at the portal', str_starts_with($url, PORTAL_URL . 'reset_password.php?t='), $url);
$sel = substr($t, 0, 24);
$row = q('SELECT * FROM client_password_resets WHERE selector = ?', [$sel])->fetch();
check('only a hash of the secret is stored', $row && !str_contains(json_encode($row), substr($t, 25)));
check('older links cancelled by the new one', (int)q('SELECT COUNT(*) FROM client_password_resets WHERE client_id = 1')->fetchColumn() === 1);
freshJar('reset');
[$s, $h, $html] = req('reset', 'GET', PORTAL . 'reset_password.php?t=' . $t);
check('reset page opens', $s === 200 && str_contains($html, 'Choose a new password'), $s);
check('reset page sends no referrer', ($h['referrer-policy'] ?? '') === 'no-referrer', $h['referrer-policy'] ?? '');
$rtok = csrfFrom($html);
[, , $body] = req('reset', 'POST', PORTAL . 'reset_password.php', ['t' => $t, 'password' => 'Brand new pass 1', 'confirm_password' => 'Brand new pass 2', 'csrf_token' => $rtok]);
check('mismatched confirmation refused', str_contains($body, "don&#039;t match"));
clientLogin('other_device', 'maria.santos@example.test', 'My own garden 2026');
q("UPDATE clients SET id = id"); // no-op
[$s, $h] = req('reset', 'POST', PORTAL . 'reset_password.php', ['t' => $t, 'password' => 'Brand new pass 1', 'confirm_password' => 'Brand new pass 1', 'csrf_token' => $rtok]);
check('reset signs in and opens documents', $s === 302 && ($h['location'] ?? '') === 'documents.php', [$s, $h['location'] ?? '']);
[$s] = req('reset', 'GET', PORTAL . 'documents.php');
check('signed in after reset', $s === 200, $s);
check('new password works', password_verify('Brand new pass 1', q('SELECT password_hash FROM clients WHERE id = 1')->fetchColumn()));
[, , $html] = req('reset2', 'GET', PORTAL . 'reset_password.php?t=' . $t);
check('link works only once', str_contains($html, 'This link has expired'));
$url2 = appCall('createClientResetLink', 1, 'client');
q('UPDATE client_password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
[, , $html] = req('reset3', 'GET', PORTAL . 'reset_password.php?t=' . substr($url2, strpos($url2, 't=') + 2));
check('expired link refused', str_contains($html, 'This link has expired'));
[, , $html] = req('reset4', 'GET', PORTAL . 'reset_password.php?t=' . $sel . ':' . str_repeat('a', 64));
check('forged secret refused', str_contains($html, 'This link has expired'));
check('reset logged', (int)q("SELECT COUNT(*) FROM audit_log WHERE action = 'client.password_reset_done'")->fetchColumn() === 1);

echo "Staff send a reset link\n";
[, , $html] = req('staff', 'GET', ADMIN . 'clients.php');
$tok = csrfFrom($html);
q('DELETE FROM client_password_resets');
[, , $body] = req('staff', 'POST', ADMIN . 'api/admin_send_client_reset.php', ['client_id' => 1], ['X-CSRF-Token: ' . $tok]);
$row = q('SELECT requested_by, TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), expires_at) h FROM client_password_resets WHERE client_id = 1')->fetch();
check('staff link created, valid ~7 days', $row && $row['requested_by'] === 'staff' && (int)$row['h'] >= 167, [$row, $body]);
echo "  (local mail() can't deliver, response: " . (json_decode($body, true)['message'] ?? $body) . ")\n";

echo "New clients\n";
[, , $body] = req('staff', 'POST', ADMIN . 'api/add_client.php', ['full_name' => 'Test New', 'email' => 'new.client@example.test', 'password' => 'abc'], ['X-CSRF-Token: ' . $tok]);
check('short temporary password refused', (json_decode($body, true)['success'] ?? true) === false, $body);
[, , $body] = req('staff', 'POST', ADMIN . 'api/add_client.php', ['full_name' => 'Test New', 'email' => 'new.client@example.test', 'password' => 'Welcome-River-7'], ['X-CSRF-Token: ' . $tok]);
check('client created', (json_decode($body, true)['success'] ?? false) === true, $body);
$c = q("SELECT plain_password, must_change_password FROM clients WHERE email = 'new.client@example.test'")->fetch();
check('new client: no readable copy, must change', $c && $c['plain_password'] === null && (int)$c['must_change_password'] === 1, $c);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
