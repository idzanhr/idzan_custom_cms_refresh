<?php
declare(strict_types=1);
/**
 * Import a WordPress export from the command line (no upload size limits):
 *   php tools/import-wxr.php import/site.WordPress.xml [--uploads=/var/www/wordpress/wp-content/uploads] [--download] [--old=https://domain.com]
 * Images (and your SVG icons) come from --uploads (e.g. the WordPress folder on the same server),
 * import/uploads/ or import/uploads.zip; --download also fetches missing ones from the old site.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = 'import-wxr.php';
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/wpimport.php';
run_migrations();
@ini_set('memory_limit', '768M');

$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "Usage: php tools/import-wxr.php <export.xml> [--download] [--old=https://old-site]\n"); exit(1); }
$old = '';
$dirs = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--old=')) $old = substr($a, 6);
    if (str_starts_with($a, '--uploads=')) {
        $d = substr($a, 10);
        if (!is_dir($d)) { fwrite(STDERR, "Uploads folder not found: {$d}\n"); exit(1); }
        $dirs[] = $d;
    }
}
[$items, $site, $icons, $fonts] = WpImporter::readWxr($file);
$imp = new WpImporter($old ?: $site, in_array('--download', $argv, true), $dirs);
$imp->icons($icons);
$imp->fonts($fonts);
$imp->log[] = 'Export from ' . $site . ': ' . count($items) . ' items.';
foreach ($items as $it) $imp->save($it);
echo implode("\n", $imp->summary()), "\n";
if (function_exists('content_changed')) content_changed();
