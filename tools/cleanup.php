<?php
declare(strict_types=1);
/**
 * Tidy the CMS database (same as Admin → Publish & export → Tidy up):
 *   php tools/cleanup.php            show what would be removed
 *   php tools/cleanup.php --apply    remove it and rebuild the site
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = 'cleanup.php';
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/cleanup.php';
run_migrations();
$apply = in_array('--apply', $argv, true);
foreach (cms_cleanup($apply) as $line) echo ($apply ? '✓ ' : '· '), $line, "\n";
echo $apply ? "Done. Public site rebuilt.\n" : "Nothing changed yet — run again with --apply to remove the items above.\n";
