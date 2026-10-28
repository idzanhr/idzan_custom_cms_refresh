<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require ROOT . '/inc/wpimport.php';

$log = [];
$importDir = ROOT . '/import';
$serverFiles = is_dir($importDir) ? array_map('basename', glob($importDir . '/*.xml') ?: []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    @set_time_limit(0);
    @ini_set('memory_limit', '768M');
    $source = (string)($_POST['source'] ?? 'xml');
    $types = array_intersect(array_keys(WP_IMPORT_TYPES), (array)($_POST['types'] ?? []));
    $download = !empty($_POST['download']);
    $wpUploads = trim((string)($_POST['wp_uploads'] ?? ''));
    $dirs = ($wpUploads !== '' && is_dir($wpUploads)) ? [$wpUploads] : [];
    $tmp = null;

    try {
        if ($source === 'xml') {
            $file = null;
            $up = $_FILES['wxr'] ?? null;
            if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $tmp = tempnam(sys_get_temp_dir(), 'wxr');
                move_uploaded_file($up['tmp_name'], $tmp);
                $file = $tmp;
            } elseif ($up && ($up['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('The upload failed (file too large for this server?). Put the .xml in the /import/ folder by FTP instead and pick it below.');
            } elseif (in_array($_POST['server_file'] ?? '', $serverFiles, true)) {
                $file = $importDir . '/' . $_POST['server_file'];
            }
            if (!$file) throw new RuntimeException('Choose your WordPress export (.xml) file.');
            [$items, $site, $icons, $fonts] = WpImporter::readWxr($file);
            $old = trim((string)($_POST['old_site'] ?? '')) ?: $site;
            if ($wpUploads !== '' && !$dirs) throw new RuntimeException('WordPress uploads folder not found: ' . $wpUploads);
            $imp = new WpImporter($old, $download, $dirs);
            $imp->log[] = 'Export from ' . ($site ?: 'unknown site') . ': ' . count($items) . ' items.';
            $imp->icons($icons);
            $imp->fonts($fonts);
            foreach ($items as $it) if (in_array($it['type'], $types, true)) $imp->save($it);
        } else {
            $site = rtrim(trim((string)($_POST['site'] ?? '')), '/');
            if (!preg_match('~^https?://~', $site)) $site = 'https://' . $site;
            $imp = new WpImporter($site, true);
            foreach ($types as $type) foreach (WpImporter::readRest($site, $type) as $it) $imp->save($it);
        }
        $log = $imp->summary();
        content_changed();
        flash('Import finished. Check each section, then fill in anything WordPress didn\'t include.');
    } catch (Throwable $ex) {
        $log = isset($imp) ? $imp->summary() : [];
        $log[] = 'Stopped: ' . $ex->getMessage();
        flash('Import stopped: ' . $ex->getMessage(), 'err');
    } finally {
        if ($tmp) @unlink($tmp);
    }
}

$hasBackup = is_dir($importDir . '/uploads') || is_file($importDir . '/uploads.zip');
// WordPress on the same server: guess its uploads folder next to this CMS (e.g. htdocs/wp_old).
$guess = '';
foreach (glob(dirname(ROOT) . '/*/wp-content/uploads', GLOB_ONLYDIR) ?: [] as $g) { $guess = $g; break; }
$maxUp = ini_get('upload_max_filesize');

admin_head('Import from WordPress', 'import');
?>
<header class="pagehead"><h1>Import from WordPress</h1></header>

<?php if ($log): ?>
<section class="panel medium"><h2>Import log</h2><ul class="log"><?php foreach ($log as $l): ?><li><?= e($l) ?></li><?php endforeach ?></ul></section>
<?php endif ?>

<form class="panel medium" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="source" value="xml">
  <h2>From a WordPress export file <span class="pill published">Recommended</span></h2>
  <p>In WordPress go to <strong>Tools → Export → All content</strong> and download the file. It includes drafts and every custom field (project client, URL and dates, affiliate codes…).</p>

  <div class="field"><label for="wxr">Export file (.xml, up to <?= e($maxUp) ?>)</label>
    <input id="wxr" type="file" name="wxr" accept=".xml,text/xml,application/xml"></div>
  <?php if ($serverFiles): ?>
  <div class="field"><label for="sf">…or a file already in the /import/ folder</label>
    <select id="sf" name="server_file"><option value="">—</option><?php foreach ($serverFiles as $f): ?><option><?= e($f) ?></option><?php endforeach ?></select></div>
  <?php endif ?>

  <div class="field"><label>Images</label>
    <p class="help"><?= $hasBackup
        ? '✓ Found your uploads backup in /import/ — images are copied from there first.'
        : 'Optional: upload your <code>wp-content/uploads</code> folder to <code>/import/uploads/</code> (or as <code>/import/uploads.zip</code>) by FTP, and images are copied from that backup.' ?></p>
    <label for="wpu">WordPress uploads folder on this server <span class="muted">(fastest — images, icons and fonts are copied directly)</span></label>
    <input id="wpu" name="wp_uploads" value="<?= e($_POST['wp_uploads'] ?? $guess) ?>" placeholder="/home/SITEUSER/htdocs/wp_old/wp-content/uploads">
    <label class="check"><input type="checkbox" name="download" value="1" checked> Download images that aren't in the backup from the old site</label>
  </div>
  <div class="field"><label for="os">Old site address</label>
    <input id="os" name="old_site" type="url" value="https://domain.com"><p class="help">Links to it inside posts become links on this site.</p></div>

  <div class="field"><label>What to import</label>
    <div class="chips"><?php foreach (WP_IMPORT_TYPES as $k => $l): ?>
      <label class="chip"><input type="checkbox" name="types[]" value="<?= e($k) ?>" checked> <?= e($l) ?></label>
    <?php endforeach ?></div>
  </div>
  <p class="help">Items with the same address are updated, not duplicated, so you can run it again. Cookie-policy pages come in as drafts because this site sets no cookies.</p>
  <button class="btn primary" type="submit">Import file</button>
</form>

<form class="panel medium" method="post">
  <?= csrf_field() ?><input type="hidden" name="source" value="rest">
  <h2>From the live site</h2>
  <p class="help">Reads the site's public API. Use this when you can't export a file; custom fields WordPress keeps private won't come across.</p>
  <div class="field"><label for="site">WordPress site address</label><input id="site" name="site" type="url" value="https://domain.com" required></div>
  <div class="chips"><?php foreach (WP_IMPORT_TYPES as $k => $l): ?>
    <label class="chip"><input type="checkbox" name="types[]" value="<?= e($k) ?>" checked> <?= e($l) ?></label>
  <?php endforeach ?></div>
  <p><button class="btn" type="submit">Import from live site</button></p>
</form>
<?php admin_foot();
