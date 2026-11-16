<?php
// Shared helpers for the LOCAL rig tests (admin :3401, portal :3402, MySQL :3399 db sectest).
// DIS_TEST_DIR = the rig folder (app/ copy with its own config.php, uploads/, cookie jars).
// See tests/security/README.md. Never point these at the live site or database.
const ADMIN = 'http://127.0.0.1:3401/';
const PORTAL = 'http://127.0.0.1:3402/';
const PORTAL_URL = 'http://127.0.0.1:3402/';
define('SP', rtrim(str_replace('\\', '/', getenv('DIS_TEST_DIR') ?: sys_get_temp_dir() . '/dis-rig'), '/'));
$pdo = new PDO('mysql:host=127.0.0.1;port=3399;dbname=sectest;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pass = 0; $fail = 0;
function check(string $name, $cond, $extra = '') { global $pass, $fail; if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name  " . (is_string($extra) ? $extra : json_encode($extra)) . "\n"; } }
function q(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s; }
function req(string $jar, string $method, string $url, array $fields = [], array $headers = [], bool $multipart = false): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => SP . "/jar_$jar.txt", CURLOPT_COOKIEFILE => SP . "/jar_$jar.txt", CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers];
    if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $multipart ? $fields : http_build_query($fields); }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $h = [];
    foreach (explode("\r\n", substr($raw, 0, $hsize)) as $line) { if (strpos($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); } }
    return [$status, $h, substr($raw, $hsize)];
}
function csrfFrom(string $html): string { return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) || preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
function freshJar(string $jar): void { @unlink(SP . "/jar_$jar.txt"); }
function staffLogin(string $jar, string $user = 'processing', string $pw = 'StaffTest#2026', array $extra = []): array {
    freshJar($jar);
    [, , $html] = req($jar, 'GET', ADMIN . 'login.php');
    return req($jar, 'POST', ADMIN . 'login.php', ['username' => $user, 'password' => $pw, 'csrf_token' => csrfFrom($html)] + $extra);
}
function clientLogin(string $jar, string $email = 'maria.santos@example.test', string $pw = 'ClientTest#2026'): array {
    freshJar($jar);
    [, , $html] = req($jar, 'GET', PORTAL . 'login.php');
    return req($jar, 'POST', PORTAL . 'login.php', ['email' => $email, 'password' => $pw, 'csrf_token' => csrfFrom($html)]);
}
function pageCsrf(string $jar, string $url): string { [, , $html] = req($jar, 'GET', $url); return csrfFrom($html); }
/** Calls an app function in this process, against the same test DB. */
function appCall(string $fn, ...$args) {
    static $loaded = false;
    if (!$loaded) {
        $_SERVER['HTTP_HOST'] = 'localhost';
        foreach (['client_passwords.php', 'staff_security.php', 'totp.php'] as $f) {
            if (is_file(SP . '/app/includes/' . $f)) require_once SP . '/app/includes/' . $f;
        }
        $loaded = true;
    }
    return $fn(...$args);
}
q('DELETE FROM login_attempts');
