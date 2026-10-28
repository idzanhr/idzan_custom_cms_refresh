<?php
declare(strict_types=1);
/**
 * Rebuild the static public site.
 *   php tools/build.php           only if a scheduled post has become due since the last build (for cron)
 *   php tools/build.php --force   always
 * Cron example (every 10 minutes, as the web server user):
 *   0,10,20,30,40,50 * * * * www-data php /var/www/domain.com/tools/build.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = 'build.php';
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/render.php';
run_migrations();
if (!static_mode()) { echo "Static mode is off in config.php — nothing to build.\n"; exit(0); }

$last = setting('last_build', '1970-01-01 00:00:00');
$due = (int)one("SELECT COUNT(*) c FROM posts WHERE status = 'published' AND published_at > ? AND published_at <= ?", [$last, now()])['c'];
if (!$due && !in_array('--force', $argv, true)) exit(0);
$n = rebuild_static();
varnish_purge();
bunny_purge();
echo "Built {$n} files" . ($due ? " ({$due} scheduled post(s) went live)" : '') . ".\n";
