<?php
declare(strict_types=1);
// Icon library: your licensed SVG icons, stored outside the public folder and inlined into pages.
require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $slot = (string)($_POST['slot'] ?? '');
    if (!array_key_exists($slot, ICON_SLOTS)) { flash('Unknown icon.', 'err'); redirect(admin_url('icons.php')); }
    if (($_POST['action'] ?? '') === 'remove') {
        @unlink(icon_dir() . '/' . $slot . '.svg');
        flash(ICON_SLOTS[$slot] . ' icon removed — the built-in icon is used again.');
    } else {
        $f = $_FILES['svg'] ?? null;
        $ok = $f && ($f['error'] ?? 4) === UPLOAD_ERR_OK && $f['size'] < 500000
            && icon_save($slot, (string)file_get_contents($f['tmp_name']));
        flash($ok ? ICON_SLOTS[$slot] . ' icon saved.' : 'That file isn\'t a usable SVG (scripts, links and embedded images are not allowed).', $ok ? 'ok' : 'err');
    }
    content_changed();
    redirect(admin_url('icons.php'));
}

admin_head('Icons', 'icons');
?>
<header class="pagehead"><h1>Icons</h1></header>
<p class="help">Upload your own SVG icons. Each file is cleaned (no scripts, links or embedded files), kept outside the public folder, and drawn straight into the pages, so the original files are never published for download. Icons without an upload use the simple built-in line icons.</p>
<div class="icon-grid">
<?php foreach (ICON_SLOTS as $slot => $label): $svg = icon_custom($slot, 'ico-prev'); ?>
  <div class="panel icon-card">
    <div class="icon-prev"><?= $svg ?: '<span class="muted">built-in</span>' ?></div>
    <strong><?= e($label) ?></strong><code><?= e($slot) ?>.svg</code>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="slot" value="<?= e($slot) ?>">
      <input type="file" name="svg" accept=".svg,image/svg+xml" required aria-label="SVG file for <?= e($label) ?>">
      <button class="btn small">Upload</button></form>
    <?php if ($svg): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="slot" value="<?= e($slot) ?>"><input type="hidden" name="action" value="remove">
      <button class="btn small danger" data-confirm="Remove this icon?">Remove</button></form>
    <?php endif ?>
  </div>
<?php endforeach ?>
</div>
<?php admin_foot();
