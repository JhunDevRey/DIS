<?php
// Test-only router: on the server admin/ and portal/ symlink api, css, js, images to the shared folders.
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__ . '/app';
if (preg_match('#^/(?:admin/|portal/)?(api|css|js|images)/(.+)$#', $uri, $m)) {
    $f = "$root/$m[1]/$m[2]";
    if (!is_file($f)) { http_response_code(404); return true; }
    if (str_ends_with($f, '.php')) { chdir(dirname($f)); require $f; return true; }
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'json' => 'application/json', 'svg' => 'image/svg+xml'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($f);
    return true;
}
return false;
