<?php
// Security hardening checks (headers, CSRF, throttles, uploads, zips, activity log) on the LOCAL rig.
require __DIR__ . '/tests_lib.php';

echo "Security headers\n";
[$s, $h] = req('anon', 'GET', ADMIN . 'login.php');
check('staff login 200', $s === 200, $s);
check('CSP sent', str_contains($h['content-security-policy'] ?? '', "frame-ancestors 'self'"), $h['content-security-policy'] ?? '');
check('CSP blocks plugins', str_contains($h['content-security-policy'] ?? '', "object-src 'none'"));
check('X-Frame-Options', ($h['x-frame-options'] ?? '') === 'SAMEORIGIN');
check('nosniff', ($h['x-content-type-options'] ?? '') === 'nosniff');
check('Referrer-Policy', ($h['referrer-policy'] ?? '') === 'same-origin');
check('X-Powered-By removed', !isset($h['x-powered-by']), $h['x-powered-by'] ?? '');
[$s, $h] = req('anon', 'GET', PORTAL . 'login.php');
check('portal login has CSP too', isset($h['content-security-policy']));

echo "Login CSRF\n";
freshJar('x');
req('x', 'GET', ADMIN . 'login.php');
[$s, $h, $body] = req('x', 'POST', ADMIN . 'login.php', ['username' => 'processing', 'password' => 'StaffTest#2026']);
check('staff login without token refused', $s === 200 && str_contains($body, 'This page expired'), $s);
[$s, $h] = staffLogin('staff');
check('staff login with token redirects to Front Desk', $s === 302 && ($h['location'] ?? '') === 'frontdesk.php', [$s, $h['location'] ?? '']);
[$s, $h] = clientLogin('client');
check('client login redirects to documents', $s === 302 && ($h['location'] ?? '') === 'documents.php', [$s, $h['location'] ?? '']);

echo "API CSRF on every POST\n";
[$s, , $body] = req('staff', 'POST', ADMIN . 'api/update_client_notes.php', ['client_id' => 1, 'notes' => 'x']);
check('staff POST without token = 403', $s === 403, [$s, $body]);
$tok = pageCsrf('staff', ADMIN . 'clients.php');
[$s, , $body] = req('staff', 'POST', ADMIN . 'api/update_client_notes.php', ['client_id' => 1, 'notes' => 'notes ok'], ['X-CSRF-Token: ' . $tok]);
check('staff POST with token works', $s === 200 && json_decode($body, true)['success'] === true, $body);
[$s] = req('client', 'POST', PORTAL . 'api/mark_section_seen.php', [], ['Content-Type: application/json']);
check('client POST without token = 403', $s === 403, $s);

echo "Login throttling\n";
q('DELETE FROM login_attempts');
for ($i = 0; $i < 10; $i++) { [, , $body] = staffLogin('brute', 'processing', 'wrong' . $i); }
[, , $body] = staffLogin('brute', 'processing', 'StaffTest#2026');
check('11th try from same IP locked even with right password', str_contains($body, 'Too many failed'), substr(strip_tags($body), 0, 80));
$n = (int)q("SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE 'login-acct:admin:processing'")->fetchColumn();
check('per-account counter recorded', $n === 10, $n);
q('DELETE FROM login_attempts');
[$s, $h] = req('anon2', 'GET', ADMIN . 'login.php', [], ['X-Forwarded-For: 9.9.9.9']);
for ($i = 0; $i < 10; $i++) { freshJar('xff'); [, , $html] = req('xff', 'GET', ADMIN . 'login.php'); req('xff', 'POST', ADMIN . 'login.php', ['username' => 'nobody', 'password' => 'x', 'csrf_token' => csrfFrom($html)], ['X-Forwarded-For: 10.0.0.' . $i]); }
$ids = q("SELECT DISTINCT identifier FROM login_attempts WHERE identifier LIKE 'login-ip:%'")->fetchAll(PDO::FETCH_COLUMN);
check('spoofed X-Forwarded-For ignored (one IP bucket)', count($ids) === 1 && str_ends_with($ids[0], '127.0.0.1'), $ids);
q('DELETE FROM login_attempts');
[$s] = staffLogin('staff');

echo "Deactivated client is signed out on next request\n";
[$s] = req('client', 'GET', PORTAL . 'documents.php');
check('client can open documents', $s === 200, $s);
q('UPDATE clients SET is_active = 0 WHERE id = 1');
[$s, $h] = req('client', 'GET', PORTAL . 'documents.php');
check('after deactivation: redirected to login', $s === 302 && str_starts_with($h['location'] ?? '', 'login.php'), [$s, $h['location'] ?? '']);
[$s] = req('client', 'GET', PORTAL . 'api/client_messages.php?after_id=0');
check('after deactivation: API 401', $s === 401, $s);
q('UPDATE clients SET is_active = 1 WHERE id = 1');
clientLogin('client');

echo "Uploads: hostile names\n";
$req = q("SELECT id FROM document_requests WHERE client_id = 1 AND is_review = 0 ORDER BY id LIMIT 1")->fetchColumn();
if (!$req) { q("INSERT INTO document_requests (client_id, doc_name) VALUES (1, 'Passport')"); $req = q('SELECT LAST_INSERT_ID()')->fetchColumn(); }
$pdfPath = SP . '/t.pdf';
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$ctok = pageCsrf('client', PORTAL . 'documents.php');
$evil = "../../evil\r\nX-Injected: 1 \"quote\".pdf";
[$s, , $body] = req('client', 'POST', PORTAL . 'api/upload_document.php', ['request_id' => $req, 'document' => new CURLFile($pdfPath, 'application/pdf', $evil)], ['X-CSRF-Token: ' . $ctok], true);
$j = json_decode($body, true);
check('upload accepted', ($j['success'] ?? false) === true, $body);
$row = q('SELECT * FROM document_files WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$req])->fetch();
check('stored original name has no path or control chars', $row && !preg_match('/[\/\\\\\r\n"]/', $row['original_name']), $row['original_name'] ?? '');
check('stored file name has random part', $row && preg_match('/^req\d+_\d+_[0-9a-f]{8}_/', $row['stored_name']), $row['stored_name'] ?? '');
[$s, $h] = req('client', 'GET', PORTAL . 'api/client_download_file.php?file_id=' . $row['id']);
check('download works', $s === 200, $s);
check('download header safe + UTF-8 name', str_contains($h['content-disposition'] ?? '', "filename*=UTF-8''") && !isset($h['x-injected']), $h['content-disposition'] ?? '');
check('download not cacheable', str_contains($h['cache-control'] ?? '', 'no-store'), $h['cache-control'] ?? '');
[$s, $h] = req('staff', 'GET', ADMIN . 'api/admin_download_file.php?file_id=' . $row['id'] . '&mode=view');
check('staff inline PDF view works', $s === 200 && ($h['content-type'] ?? '') === 'application/pdf', [$s, $h['content-type'] ?? '']);

echo "Zip downloads\n";
q("INSERT INTO document_files (request_id, original_name, stored_name, file_size, uploaded_by) VALUES (?, ?, ?, ?, 'client')", [$req, "../../../etc/passwd\n.pdf", $row['stored_name'], 10]);
[$s, $h, $zipBody] = req('staff', 'GET', ADMIN . 'api/admin_download_all_for_client.php?client_id=1');
check('all-documents zip downloads', $s === 200 && ($h['content-type'] ?? '') === 'application/zip', [$s, $h['content-type'] ?? '']);
if (class_exists('ZipArchive') && $s === 200) {
    file_put_contents(SP . '/t.zip', $zipBody);
    $z = new ZipArchive(); $z->open(SP . '/t.zip'); $names = [];
    for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
    check('no zip entry escapes its folder', !array_filter($names, fn($n) => str_contains($n, '../') || str_starts_with($n, '/') || preg_match('/[\r\n]/', $n) || substr_count($n, '/') > 1), $names);
} else {
    echo "  (ZipArchive not loaded in this CLI; checking body is a zip)\n";
    check('zip magic bytes', str_starts_with($zipBody, "PK"));
}

echo "Huge image does not crash the converter\n";
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(8000, 7000); // 56 MP PNG: ~840 MB to convert, over the 768 MB ceiling
    imagepng($im, SP . '/uploads/huge.png', 9); imagedestroy($im);
    q("INSERT INTO document_requests (client_id, doc_name, status) VALUES (1, 'Huge scan', 'accepted')");
    $hreq = q('SELECT LAST_INSERT_ID()')->fetchColumn();
    q("INSERT INTO document_files (request_id, original_name, stored_name, file_size, uploaded_by) VALUES (?, 'huge.png', 'huge.png', ?, 'client')", [$hreq, filesize(SP . '/uploads/huge.png')]);
    $hid = q('SELECT LAST_INSERT_ID()')->fetchColumn();
    [$s, $h] = req('staff', 'GET', ADMIN . 'api/admin_download_file.php?file_id=' . $hid);
    check('accepted huge PNG served as original (no 500)', $s === 200 && str_contains($h['content-disposition'] ?? '', 'huge.png'), [$s, $h['content-disposition'] ?? '']);
}

echo "Error messages\n";
[$s, , $body] = req('staff', 'POST', ADMIN . 'api/add_document_request.php', ['client_id' => 1, 'doc_name' => str_repeat('x', 400)], ['X-CSRF-Token: ' . $tok]);
check('database error not shown to the browser', !preg_match('/SQLSTATE|PDO|Data too long/i', $body), $body);

echo "Client throttles\n";
q("DELETE FROM login_attempts WHERE identifier LIKE 'msg:%'");
for ($i = 0; $i < 59; $i++) q("INSERT INTO login_attempts (identifier) VALUES ('msg:client:1')");
[, , $body] = req('client', 'POST', PORTAL . 'api/client_messages.php', ['action' => 'send', 'body' => 'hello 60'], ['X-CSRF-Token: ' . $ctok]);
check('60th message in an hour is sent', (json_decode($body, true)['success'] ?? false) === true, $body);
[$s, , $body] = req('client', 'POST', PORTAL . 'api/client_messages.php', ['action' => 'send', 'body' => 'hello 61'], ['X-CSRF-Token: ' . $ctok]);
check('61st message refused with 429', $s === 429, [$s, $body]);
q("DELETE FROM login_attempts WHERE identifier LIKE 'msg:%'");

echo "Activity log\n";
$acts = q("SELECT action, COUNT(*) n FROM audit_log GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['staff.login', 'staff.login_failed', 'client.login', 'file.upload', 'file.download', 'file.view', 'file.download_zip'] as $a) {
    check("logged: $a", !empty($acts[$a]), $acts);
}
$ip = q("SELECT ip FROM audit_log WHERE action = 'staff.login' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('activity log stores the caller IP', $ip === '127.0.0.1', $ip);

echo "Logout\n";
[$s, $h] = req('staff', 'GET', ADMIN . 'logout.php');
check('logout redirects to login', $s === 302 && ($h['location'] ?? '') === 'login.php', $s);
[$s] = req('staff', 'GET', ADMIN . 'clients.php');
check('after logout pages need sign-in', $s === 302, $s);
check('logout logged', (int)q("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.logout'")->fetchColumn() > 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
