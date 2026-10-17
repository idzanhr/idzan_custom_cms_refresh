<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require ROOT . '/inc/medialib.php';

/*
 * Media library: browse, upload, find where an image is used, copy its address, delete unused ones.
 * ?format=json feeds the "Choose from Media" picker on image fields.
 */

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    $list = [];
    foreach (media_files() as $rel => $t) {
        if ($q !== '' && !str_contains(mb_strtolower(basename($rel)), $q)) continue;
        $list[] = ['path' => $rel, 'url' => media_url($rel), 'name' => basename($rel), 'alt' => media_alt_map()[$rel] ?? ''];
        if (count($list) >= 400) break;
    }
    echo json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'upload') {
        @set_time_limit(300);
        $f = $_FILES['files'] ?? null;
        $ok = 0; $errs = [];
        if ($f && is_array($f['name'])) {
            foreach ($f['name'] as $i => $name) {
                if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                try {
                    store_upload(['name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i]], (string)$name);
                    $ok++;
                } catch (RuntimeException $e) { $errs[] = $name . ': ' . $e->getMessage(); }
            }
        }
        if ($ok) flash($ok . ' image(s) uploaded.');
        foreach ($errs as $err) flash($err, 'err');
        if (!$ok && !$errs) flash('Choose one or more images first.', 'err');
        redirect(admin_url('media.php'));
    }
    if ($action === 'fixwp') {
        if (!is_admin()) { http_response_code(403); exit('Only administrators can do this.'); }
        require_once ROOT . '/inc/fixlinks.php';
        @set_time_limit(600);
        $dir = trim((string)($_POST['wp_uploads'] ?? ''));
        if ($dir === '' || !is_dir($dir)) { flash('WordPress uploads folder not found: ' . $dir, 'err'); redirect(admin_url('media.php')); }
        [$fixed, $missing] = wp_links_fix([$dir]);
        flash($fixed ? "Fixed {$fixed} old WordPress image address(es); the files are now in Media and the site was rebuilt." : 'Nothing could be fixed.', $fixed ? 'ok' : 'err');
        if ($missing) flash(count($missing) . ' file(s) were not found in the WordPress uploads folder and still use the old address: '
            . implode(', ', array_map(fn($u) => basename((string)parse_url($u, PHP_URL_PATH)), array_slice($missing, 0, 12))) . (count($missing) > 12 ? ', …' : ''), 'err');
        redirect(admin_url('media.php'));
    }
    if ($action === 'convert') {                       // step of "Make WebP/AVIF copies now" (admin.js repeats it)
        @set_time_limit(120);
        [$done, $total, $made] = img_convert_all(15);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['done' => $done, 'total' => $total, 'made' => $made]);
        exit;
    }
    if ($action === 'meta') {
        $rel = (string)($_POST['path'] ?? '');
        if (!media_abs($rel)) { flash('That image was not found.', 'err'); redirect(admin_url('media.php')); }
        media_alt_save($rel, (string)($_POST['alt'] ?? ''));
        $msg = 'Alt text saved.';
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '' && $name !== pathinfo($rel, PATHINFO_FILENAME)) {
            if (!is_admin()) { http_response_code(403); exit('Only administrators can rename files.'); }
            try { $rel = media_rename($rel, $name); $msg = 'Saved, renamed to ' . basename($rel) . ' and every page using it was updated.'; }
            catch (RuntimeException $e) { flash($e->getMessage(), 'err'); }
        }
        content_changed();
        flash($msg);
        redirect(admin_url('media.php', ['edit' => $rel]));
    }
    if ($action === 'delete') {
        if (!is_admin()) { http_response_code(403); exit('Only administrators can delete media.'); }
        $rel = (string)($_POST['path'] ?? '');
        $abs = media_abs($rel);
        if ($abs && @unlink($abs)) flash('Deleted ' . basename($rel) . '.');
        else flash('That file could not be deleted.', 'err');
        redirect(admin_url('media.php', array_filter(['q' => $_POST['q'] ?? '', 'show' => $_POST['show'] ?? '', 'p' => $_POST['p'] ?? ''])));
    }
}

/* ---------- edit one image: alt text and file name ---------- */
if (isset($_GET['edit']) && ($abs = media_abs((string)$_GET['edit']))) {
    $rel = (string)$_GET['edit'];
    $used = media_used_in($rel, media_usage_index());
    $dim = @getimagesize($abs);
    $alt = media_alt_map()[$rel] ?? '';
    admin_head('Edit image', 'media');
    ?>
<header class="pagehead"><h1>Edit image</h1><a class="btn" href="<?= e(admin_url('media.php')) ?>">← Media</a></header>
<form method="post" class="panel media-edit" data-unsaved>
  <?= csrf_field() ?><input type="hidden" name="action" value="meta"><input type="hidden" name="path" value="<?= e($rel) ?>">
  <img class="media-edit-img" src="<?= e(media_url($rel)) ?>" alt="">
  <div>
    <p class="muted small"><?= e($rel) ?><?= $dim ? ' · ' . (int)$dim[0] . '×' . (int)$dim[1] : '' ?> · <?= e(number_format((filesize($abs) ?: 0) / 1024, 0)) ?> KB</p>
    <div class="field"><label for="m_alt">Alt text</label>
      <textarea id="m_alt" name="alt" rows="3" maxlength="255" data-counter="125"><?= e($alt) ?></textarea>
      <p class="help">What the picture shows, in one sentence, for screen readers and image search (e.g. "Xiaomi Watch 2 on a wrist showing the heart-rate screen"). Used wherever this image has no alt text of its own; alt text typed in a post wins. Leave empty for purely decorative images.</p></div>
    <div class="field"><label for="m_name">File name</label>
      <div class="inline"><input id="m_name" name="name" value="<?= e(pathinfo($rel, PATHINFO_FILENAME)) ?>" maxlength="80"<?= is_admin() ? '' : ' disabled' ?>><span class="muted">.<?= e(pathinfo($rel, PATHINFO_EXTENSION)) ?></span>
      <button type="button" class="btn small" data-name-from="m_alt" data-name-to="m_name">Use the alt text</button></div>
      <p class="help">Descriptive names help image search: <code>xiaomi-watch-2-heart-rate</code> rather than <code>IMG_2041</code>. Renaming updates every post, page, setting and translation that uses the image<?= media_name_vague($rel) ? '. <strong>This name says nothing about the picture.</strong>' : '.' ?> The old address stops working, so search engines need a little time to pick up the new one.</p></div>
    <p class="small muted">Modern copies: <?= img_complete($rel) ? 'all made' : 'some still missing (made during the next rebuilds)' ?><?= img_formats() ? ' · ' . e(strtoupper(implode(' + ', img_formats()))) : '' ?></p>
    <p class="actions"><button class="btn primary" type="submit">Save</button></p>
    <?php if ($used): ?><h2 class="h3">Used in</h2><ul><?php foreach ($used as [$label, $link]): ?><li><a href="<?= e($link) ?>"><?= e($label) ?></a></li><?php endforeach ?></ul>
    <?php else: ?><p class="small"><span class="pill draft">Unused</span></p><?php endif ?>
  </div>
</form>
<?php
    admin_foot();
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$show = in_array($_GET['show'] ?? '', ['used', 'unused', 'noalt', 'vague'], true) ? $_GET['show'] : 'all';
$files = media_files();
$index = media_usage_index();
$alts = media_alt_map();
$rows = [];
foreach ($files as $rel => $t) {
    if ($q !== '' && !str_contains(mb_strtolower(basename($rel) . ' ' . ($alts[$rel] ?? '')), mb_strtolower($q))) continue;
    $used = media_used_in($rel, $index);
    if ($show === 'used' && !$used) continue;
    if ($show === 'unused' && $used) continue;
    if ($show === 'noalt' && (isset($alts[$rel]) || !$used)) continue;
    if ($show === 'vague' && (!media_name_vague($rel) || !$used)) continue;
    $rows[] = [$rel, $t, $used];
}
$per = 48;
$pages = max(1, (int)ceil(count($rows) / $per));
$page = min($pages, max(1, (int)($_GET['p'] ?? 1)));
$slice = array_slice($rows, ($page - 1) * $per, $per);
$unusedTotal = count(array_filter($files, fn($t, $rel) => !media_used_in($rel, $index), ARRAY_FILTER_USE_BOTH));
$maxUp = ini_get('upload_max_filesize');

admin_head('Media', 'media');
?>
<header class="pagehead"><h1>Media <span class="muted">(<?= count($files) ?>)</span></h1></header>

<?php if (is_admin()): require_once ROOT . '/inc/fixlinks.php'; $oldLinks = wp_link_count(); if ($oldLinks): $guess = wp_uploads_guess()[0] ?? ''; ?>
<section class="panel notice-panel">
  <h2>Old WordPress image addresses: <?= $oldLinks ?></h2>
  <p>Some posts or pages still point to images at <code>/wp-content/uploads/</code> on the old WordPress site, mostly links wrapped around images. They break once WordPress is gone. This copies those files into Media and points everything at the copies.</p>
  <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="fixwp">
    <label for="wpu">WordPress uploads folder on this server</label>
    <input id="wpu" name="wp_uploads" value="<?= e($guess) ?>" placeholder="/home/SITEUSER/htdocs/idzan-wp_old/wp-content/uploads" required>
    <button class="btn primary" type="submit">Copy images and fix the addresses</button>
  </form>
</section>
<?php endif; endif ?>

<?php $fmts = img_encoders(); $convTotal = 0; $convDone = 0;
foreach (array_keys($files) as $r) { if (!preg_match('~\.(jpe?g|png|webp)$~i', $r)) continue; $convTotal++; if (img_complete($r)) $convDone++; } ?>
<section class="panel formats-panel">
  <h2>Modern image formats</h2>
  <p class="help">Every uploaded JPG, PNG or WebP gets
    <?= $fmts['avif'] ? '<strong>AVIF</strong> (' . e($fmts['avif']) . ')' : '<span class="muted">no AVIF — this server\'s PHP can\'t write it (needs GD with AVIF or Imagick with AVIF)</span>' ?> and
    <?= $fmts['webp'] ? '<strong>WebP</strong> (' . e($fmts['webp']) . ')' : '<span class="muted">no WebP</span>' ?> copies in several sizes. Pages offer them first and fall back to the original for older browsers. GIFs and SVGs stay as they are.</p>
  <?php if ($fmts['avif'] || $fmts['webp']): ?>
  <p><strong data-convert-count><?= $convDone ?> of <?= $convTotal ?></strong> images have all their copies. New uploads get full-size copies at once; the smaller sizes are made during the next rebuilds, or right now:</p>
  <p><button class="btn" type="button" data-convert="<?= e(admin_url('media.php')) ?>"<?= $convDone >= $convTotal ? ' disabled' : '' ?>>Make the missing copies now</button> <span class="small muted" data-convert-status role="status" aria-live="polite"></span></p>
  <?php endif ?>
</section>

<details class="panel upload-panel"<?= isset($_GET['upload']) || !$files ? ' open' : '' ?>>
  <summary><strong>Add new media</strong> <span class="muted">— JPG, PNG, WebP, GIF or AVIF, up to <?= e((string)cfg('upload_max_mb', 10)) ?> MB each (server limit <?= e($maxUp) ?>)</span></summary>
  <form method="post" enctype="multipart/form-data" class="upload-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <label class="dropzone" for="mfiles"><span>Drop images here or <u>choose files</u></span>
      <input id="mfiles" type="file" name="files[]" multiple accept="image/jpeg,image/png,image/webp,image/gif,image/avif"<?= isset($_GET['upload']) ? ' autofocus' : '' ?>></label>
    <p class="help">Large photos are scaled down to <?= (int)cfg('image_max_px', 2400) ?> px and location data (EXIF) is removed.</p>
    <button class="btn primary" type="submit">Upload</button>
  </form>
</details>

<form class="filters" method="get" role="search">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by file name or alt text" aria-label="Search media by file name or alt text">
  <select name="show" aria-label="Show">
    <option value="all"<?= $show === 'all' ? ' selected' : '' ?>>All images</option>
    <option value="used"<?= $show === 'used' ? ' selected' : '' ?>>Used</option>
    <option value="unused"<?= $show === 'unused' ? ' selected' : '' ?>>Unused (<?= $unusedTotal ?>)</option>
    <option value="noalt"<?= $show === 'noalt' ? ' selected' : '' ?>>Used, without alt text</option>
    <option value="vague"<?= $show === 'vague' ? ' selected' : '' ?>>Used, vague file name</option>
  </select>
  <button class="btn" type="submit">Filter</button>
</form>

<?php if (!$slice): ?>
  <p class="muted">No images<?= $q !== '' || $show !== 'all' ? ' match this filter' : ' yet' ?>.</p>
<?php else: ?>
<ul class="media-grid">
  <?php foreach ($slice as [$rel, $t, $used]):
      $abs = public_dir() . '/' . $rel;
      $dim = @getimagesize($abs);
      $url = media_url($rel); ?>
  <li class="media-item">
    <a class="media-thumb" href="<?= e($url) ?>" target="_blank" rel="noopener"><img src="<?= e($url) ?>" alt="" loading="lazy"><span class="sr">Open <?= e(basename($rel)) ?> in a new tab</span></a>
    <div class="media-info">
      <p class="media-name" title="<?= e($rel) ?>"><?= e(basename($rel)) ?></p>
      <p class="muted small"><?= $dim ? (int)$dim[0] . '×' . (int)$dim[1] . ' · ' : '' ?><?= e(number_format((filesize($abs) ?: 0) / 1024, 0)) ?> KB · <?= e(date('d.m.Y', $t)) ?></p>
      <?php if ($used): ?>
        <details class="usage"><summary>Used in <?= count($used) ?> place<?= count($used) > 1 ? 's' : '' ?></summary>
          <ul><?php foreach ($used as [$label, $link]): ?><li><a href="<?= e($link) ?>"><?= e($label) ?></a></li><?php endforeach ?></ul></details>
      <?php else: ?>
        <p class="small"><span class="pill draft">Unused</span></p>
      <?php endif ?>
      <p class="small"><?= isset($alts[$rel]) ? '<span class="pill ok">Alt text</span>' : '<span class="pill draft">No alt text</span>' ?><?= media_name_vague($rel) ? ' <span class="pill draft">Vague name</span>' : '' ?></p>
      <div class="media-actions">
        <a class="btn small" href="<?= e(admin_url('media.php', ['edit' => $rel])) ?>">Edit<span class="sr"> <?= e(basename($rel)) ?></span></a>
        <button type="button" class="btn small" data-copy="<?= e(abs_url($url)) ?>">Copy URL</button>
        <?php if (is_admin()): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="path" value="<?= e($rel) ?>">
          <input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="show" value="<?= e($show) ?>"><input type="hidden" name="p" value="<?= $page ?>">
          <button class="btn small danger" type="submit" data-confirm="<?= e($used ? 'This image is used in ' . count($used) . ' place(s); those will show a missing image. Delete anyway?' : 'Delete ' . basename($rel) . '?') ?>">Delete</button></form>
        <?php endif ?>
      </div>
    </div>
  </li>
  <?php endforeach ?>
</ul>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Media pages">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a class="btn small<?= $i === $page ? ' primary' : '' ?>" href="<?= e(admin_url('media.php', array_filter(['q' => $q, 'show' => $show === 'all' ? '' : $show, 'p' => $i > 1 ? $i : '']))) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor ?>
</nav>
<?php endif ?>
<?php endif ?>
<?php admin_foot();
