<?php
/**
 * Local development only: makes PHP's built-in web server behave like the production nginx vhost.
 *   php -S 127.0.0.1:8080 -t public dev/router.php
 * public/ is served as static files, the admin_path from config.php runs admin/*.php, /admin/ and other
 * .php addresses are 404, folders serve their index.html. Never upload this setup to the server.
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$app = dirname(__DIR__);
$cfg = is_file($app . '/config.php') ? (require $app . '/config.php') : [];
$secret = '/' . trim((string)($cfg['admin_path'] ?? '/studio-dev'), '/');
$p = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($p === '/admin' || str_starts_with($p, '/admin/')) { http_response_code(404); echo 'Not found'; return true; }
if ($p === $secret) { header('Location: ' . $secret . '/', true, 301); return true; }
if (str_starts_with($p, $secret . '/')) {
    $rel = substr($p, strlen($secret) + 1);
    if ($rel === '') $rel = 'index.php';
    $file = $app . '/admin/' . $rel;
    if (!is_file($file) || !str_ends_with($file, '.php') || str_contains($rel, '..')) { http_response_code(404); return true; }
    $_SERVER['SCRIPT_NAME'] = $secret . '/' . $rel;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    chdir(dirname($file));
    require $file;
    return true;
}
if (str_contains($p, '.php')) { http_response_code(404); return true; }
$f = $_SERVER['DOCUMENT_ROOT'] . $p;
if (is_file($f)) {
    if (str_ends_with($f, '.avif')) { header('Content-Type: image/avif'); readfile($f); return true; }
    return false;
}
if (is_dir($f) && is_file(rtrim($f, '/') . '/index.html')) {
    if (!str_ends_with($p, '/')) { header('Location: ' . $p . '/', true, 301); return true; }
    return false;
}
http_response_code(404);
if (is_file($_SERVER['DOCUMENT_ROOT'] . '/404.html')) readfile($_SERVER['DOCUMENT_ROOT'] . '/404.html');
return true;
