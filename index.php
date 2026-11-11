<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/render.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim((string)cfg('base_path', ''), '/');
if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base)) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// WordPress-style canonical URLs: add the trailing slash (but not on files like feed.xml).
if ($method === 'GET' && $uri !== '/' && !str_ends_with($uri, '/') && !preg_match('~\.[a-z0-9]{2,4}$~i', $uri)) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . url($uri . '/') . ($qs !== '' ? '?' . $qs : ''), true, 301);
    exit;
}

[$status, $body, $type] = route($uri, $method);

http_response_code($status);
header('Content-Type: ' . $type . '; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: interest-cohort=(), browsing-topics=(), camera=(), microphone=(), geolocation=()');
if ($type === 'text/html') {
    // Same policy as the generated pages (it carries the hash of the inline light/dark script), plus no framing.
    header('Content-Security-Policy: ' . csp_public() . "; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
}
if ($type === 'text/css') header('Cache-Control: public, max-age=300');
echo $body;
