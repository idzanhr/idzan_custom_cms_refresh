<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$ent = entity((string)($_GET['e'] ?? ''));
if (!$ent) { http_response_code(404); exit('Unknown section'); }
$key = $ent['key'];
$id = (int)($_GET['id'] ?? 0) ?: null;

$row = [];
if ($id) {
    $row = one("SELECT * FROM {$key} WHERE id = ?", [$id]) ?? [];
    if (!$row) { flash('That item no longer exists.', 'err'); redirect(admin_url('list.php', ['e' => $key])); }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$data, $termSets, $errors] = collect_fields($ent, $id);

    if (!$errors) {
        $data['updated_at'] = now();
        // "Last edited" for the sitemap: only when something other than the status really changed.
        if (in_array($key, SITEMAP_TABLES, true)) {
            $changed = !$id;
            foreach ($data as $c => $v) if (!in_array($c, ['updated_at', 'status'], true) && array_key_exists($c, $row) && (string)$row[$c] !== (string)$v) { $changed = true; break; }
            if ($changed) $data['modified_at'] = now();
        }
        if ($id) {
            $sets = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
            q("UPDATE {$key} SET {$sets} WHERE id = ?", [...array_values($data), $id]);
        } else {
            $data['created_at'] = now();
            $cols = implode(', ', array_keys($data));
            $marks = implode(', ', array_fill(0, count($data), '?'));
            q("INSERT INTO {$key} ({$cols}) VALUES ({$marks})", array_values($data));
            $id = (int)db()->lastInsertId();
        }
        foreach ($termSets as [$tax, $ids]) set_entity_terms($key, $id, $tax, $ids);

        content_changed();
        flash($ent['singular'] . ' saved.');
        $next = ($_POST['after'] ?? '') === 'new'
            ? admin_url('edit.php', ['e' => $key])
            : admin_url('edit.php', ['e' => $key, 'id' => $id]);
        redirect($next);
    }
    // Re-show what was typed.
    $row = array_merge($row, $data);
    foreach ($termSets as $field => [$tax, $ids]) $row['__terms'][$field] = $ids;
}

$title = $id ? 'Edit ' . strtolower($ent['singular']) : 'New ' . strtolower($ent['singular']);
$viewUrl = null;
if ($id && ($row['status'] ?? '') === 'published') {
    require_once ROOT . '/inc/render.php';
    $viewUrl = public_url_for($key, $row);
}

admin_head($title, $key);
?>
<header class="pagehead">
  <h1><?= e($title) ?></h1>
  <div class="actions">
    <?php if ($viewUrl): ?><a class="btn" href="<?= e($viewUrl) ?>" target="_blank" rel="noopener">View on site ↗</a><?php endif ?>
    <a class="btn" href="<?= e(admin_url('list.php', ['e' => $key])) ?>">← All <?= e(strtolower($ent['label'])) ?></a>
  </div>
</header>

<?php if ($errors): ?>
  <div class="flash err" role="alert"><strong>Please fix:</strong><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<form class="editor" method="post" enctype="multipart/form-data" data-unsaved>
  <?= csrf_field() ?>
  <div class="col-main">
    <?php foreach ($ent['fields'] as $name => $def) if (empty($def['side'])) render_field($name, $def, $row, $key); ?>
  </div>
  <aside class="col-side">
    <div class="panel sticky">
      <?php foreach ($ent['fields'] as $name => $def) if (!empty($def['side'])) render_field($name, $def, $row, $key); ?>
      <button class="btn primary block" type="submit">Save</button>
      <button class="btn block" type="submit" name="after" value="new">Save &amp; add another</button>
      <?php if ($id && !empty($row['updated_at'])): ?><p class="help">Last saved <?= e(date('d.m.Y. H:i', strtotime($row['updated_at']))) ?></p><?php endif ?>
    </div>
  </aside>
</form>
<?php admin_foot();
