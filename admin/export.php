<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require ROOT . '/inc/render.php';

/**
 * Static export: renders every public page to plain .html files (plus CSS, JS and
 * uploaded images) and downloads them as a .zip you can host anywhere — no PHP or
 * database needed for visitors.
 */
$tidy = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['tidy-check', 'tidy'], true)) {
    csrf_check();
    require_once ROOT . '/inc/cleanup.php';
    $tidy = cms_cleanup($_POST['action'] === 'tidy');
    if ($_POST['action'] === 'tidy') { flash($tidy ? 'Tidied: ' . count($tidy) . ' item(s) removed, site rebuilt.' : 'Nothing to tidy.'); redirect(admin_url('export.php')); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rebuild') {
    csrf_check();
    content_changed();
    $b = bunny_status();
    flash((static_mode() ? 'Public site rebuilt and Varnish cache cleared.' : 'Varnish cache cleared.')
        . ($b === null ? '' : ($b['ok'] ? ' BunnyCDN purged.' : ' BunnyCDN purge failed: ' . $b['why'] . '.')), $b && !$b['ok'] ? 'err' : 'ok');
    redirect(admin_url('export.php'));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    @set_time_limit(300);
    if (!class_exists('ZipArchive')) {
        flash('The PHP "zip" extension is missing on this server, so the export can\'t be packed.', 'err');
        redirect(admin_url('export.php'));
    }

    $dir = ROOT . '/storage/export';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        flash('Cannot create storage/export — check folder permissions.', 'err');
        redirect(admin_url('export.php'));
    }
    foreach (glob($dir . '/*.zip') ?: [] as $old) @unlink($old);
    $zipPath = $dir . '/site-' . date('Ymd-His') . '.zip';
    build_static_zip($zipPath, !empty($_POST['relative']));

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

require_once ROOT . '/inc/render.php';
$paths = all_public_paths();
admin_head('Static export', 'export');
?>
<header class="pagehead"><h1>Publish &amp; export</h1></header>
<section class="panel medium">
  <h2>Publishing</h2>
  <p class="help">Every save already rebuilds the public site<?= cfg('varnish') ? ' and clears Varnish' : '' ?>. Use this if you changed files on the server by hand.</p>
  <?php $bs = bunny_status(); ?>
  <p class="help">BunnyCDN: <?= $bs === null ? 'not connected (add the <code>bunny</code> lines to config.php to purge it automatically)'
      : ($bs['ok'] === null ? 'connected, not purged yet' : ($bs['ok'] ? 'purged automatically, last on ' . e($bs['at']) : 'last purge failed on ' . e($bs['at']) . ': ' . e($bs['why']))) ?>.</p>
  <?php $fm = img_encoders(); ?>
  <p class="help">Image formats: <?= $fm['avif'] ? 'AVIF (' . e($fm['avif']) . ')' : 'no AVIF on this server' ?>, <?= $fm['webp'] ? 'WebP (' . e($fm['webp']) . ')' : 'no WebP' ?>, original as fallback — see Media.</p>
  <?php $ix = indexnow_status(); ?>
  <p class="help">IndexNow: <?= $ix === null ? 'off (Settings → Search &amp; bots)' : (empty($ix['at']) ? 'on, nothing sent yet' : ($ix['ok'] ? 'sent ' . (int)$ix['count'] . ' changed address(es) on ' . e($ix['at']) : 'last attempt failed on ' . e($ix['at']) . ': ' . e($ix['why']) . ' — it is retried with the next save')) ?>.</p>
  <?php if ($tag = cloudpanel_cache_tag()): ?><p class="help">CloudPanel Varnish cache tag: <code><?= e($tag) ?></code> — the vhost's <code>X-Cache-Tags</code> line must use the same value.</p><?php endif ?>
  <p class="help">Inline light/dark script hash for the vhost's Content-Security-Policy (<code>script-src 'self' …</code>): <code><?= e(theme_js_hash()) ?></code></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rebuild"><button class="btn" type="submit">Rebuild site &amp; clear cache now</button></form>
</section>
<section class="panel medium">
  <h2>Tidy up</h2>
  <p class="help">Removes translated page copies, categories/services/tools nothing uses, and uploaded images no content points to.</p>
  <?php if ($tidy !== null): ?>
    <?php if ($tidy): ?><ul class="log"><?php foreach ($tidy as $l): ?><li><?= e($l) ?></li><?php endforeach ?></ul>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="tidy"><button class="btn danger" type="submit" data-confirm="Remove these <?= count($tidy) ?> item(s)?">Remove these</button></form>
    <?php else: ?><p>Nothing to tidy.</p><?php endif ?>
  <?php else: ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="tidy-check"><button class="btn" type="submit">Check what can be removed</button></form>
  <?php endif ?>
</section>
<section class="panel medium">
  <p>Download the whole public site as plain HTML + CSS + JavaScript files (a .zip). Upload the contents to any web host — no PHP or MySQL needed for visitors, and no tracking code is included.</p>
  <p><strong><?= count($paths) ?></strong> pages and files will be generated, plus your uploaded images.</p>
  <form method="post"><?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="relative" value="1"> Relative links (works from any folder or a preview host, e.g. opening files straight from your computer)</label>
    <p class="help">Leave unticked for a normal web host at the site root — addresses stay clean like /about-me/.</p>
    <button class="btn primary" type="submit">Download static site (.zip)</button></form>
</section>
<?php admin_foot();
