<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$groups = settings_fields();
$flat = array_merge(...array_values($groups));
$pseudo = ['key' => 'settings', 'fields' => $flat, 'title_field' => 'site_title'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$data, , $errors] = collect_fields($pseudo, null);
    if (!$errors) {
        foreach ($data as $k => $v) save_setting($k, (string)$v);
        setting('__reset');
        content_changed();
        flash('Settings saved.');
        redirect(admin_url('settings.php'));
    }
}

$row = [];
foreach ($flat as $k => $def) $row[$k] = s($k);
foreach ($flat as $k => $def) if (!empty($def['alt'])) $row[$def['alt']] = setting($def['alt']);
if ($errors) $row = array_merge($row, $data ?? []);

admin_head('Settings', 'settings');
?>
<header class="pagehead"><h1>Settings</h1></header>
<?php if ($errors): ?><div class="flash err" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach ?></ul></div><?php endif ?>

<form class="settings" method="post" enctype="multipart/form-data" data-unsaved>
  <?= csrf_field() ?>
  <?php foreach ($groups as $gname => $fields): ?>
    <fieldset class="panel" id="<?= e(slugify($gname)) ?>">
      <legend><?= e($gname) ?></legend>
      <?php if ($gname === 'Colors'): ?>
        <p class="help">The whole site uses these four colors. Text should stay readable on both Primary and Secondary.</p>
      <?php endif ?>
      <div class="<?= $gname === 'Colors' ? 'grid3' : '' ?>">
      <?php foreach ($fields as $name => $def) render_field($name, $def, $row, 'settings'); ?>
      </div>
    </fieldset>
  <?php endforeach ?>
  <div class="savebar"><button class="btn primary" type="submit">Save settings</button></div>
</form>
<?php admin_foot();
