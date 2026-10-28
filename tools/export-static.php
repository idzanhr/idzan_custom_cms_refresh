<?php
declare(strict_types=1);
/**
 * Build the static HTML copy from the command line (handy for a cron job or deploy script):
 *   php tools/export-static.php site.zip            clean URLs, for a web host at the domain root
 *   php tools/export-static.php site.zip --relative relative links, works from any folder
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = 'export-static.php';
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/render.php';
run_migrations();
$out = $argv[1] ?? 'site.zip';
$n = build_static_zip($out, in_array('--relative', $argv, true));
echo "Wrote {$n} pages to {$out}\n";
