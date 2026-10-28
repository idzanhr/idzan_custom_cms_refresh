<?php
declare(strict_types=1);
/**
 * After uploading new CMS files: clears PHP's code cache (OPcache), so the server runs the new
 * files immediately, then offers a rebuild of the public site and a Varnish purge.
 * A new file on purpose: it can't be stuck in the code cache from an older version.
 */
require __DIR__ . '/_init.php';
if (!is_admin()) { http_response_code(403); exit('Admins only.'); }

$cleared = function_exists('opcache_reset') ? @opcache_reset() : null;
clearstatcache(true);
$root = ROOT;
$nested = is_dir($root . '/domain.com') ? $root . '/domain.com' : '';
$checks = [
    'Code cache (OPcache)' => $cleared === null ? 'not in use on this server' : ($cleared ? 'cleared' : 'could not be cleared (ask the host / restart PHP in CloudPanel)'),
    'CMS files are in' => $root,
    'New favicon code present' => is_file($root . '/inc/favicon.php') ? 'yes' : 'NO — the update was extracted somewhere else',
    'New consent button code present' => str_contains((string)file_get_contents($root . '/inc/render.php'), 'consent-fab') ? 'yes' : 'NO — the update was extracted somewhere else',
];
if ($nested) $checks['Stray folder'] = $nested . ' exists: the zip was extracted one level too deep. Delete that folder and extract into htdocs instead.';
admin_head('Refresh after an update', 'export');
?>
<header class="pagehead"><h1>Refresh after an update</h1></header>
<section class="panel medium">
  <ul class="log"><?php foreach ($checks as $k => $v): ?><li><strong><?= e($k) ?>:</strong> <?= e($v) ?></li><?php endforeach ?></ul>
  <p>Next, rebuild the public pages and clear Varnish so visitors get the new HTML:</p>
  <form method="post" action="<?= e(admin_url('export.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="rebuild">
    <button class="btn primary" type="submit">Rebuild site &amp; clear cache now</button></form>
  <p class="help">If BunnyCDN sits in front of the site, purge its pull zone too. Browsers keep favicons for a while: check in a private window.</p>
</section>
<?php admin_foot();
