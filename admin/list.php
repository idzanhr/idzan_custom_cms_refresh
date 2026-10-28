<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$ent = entity((string)($_GET['e'] ?? ''));
if (!$ent) { http_response_code(404); exit('Unknown section'); }
$key = $ent['key'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'delete' && $id) {
        q("DELETE FROM {$key} WHERE id = ?", [$id]);
        q('DELETE FROM entity_terms WHERE entity = ? AND entity_id = ?', [$key, $id]);
        flash($ent['singular'] . ' deleted.');
    } elseif ($action === 'toggle' && $id) {
        q("UPDATE {$key} SET status = CASE WHEN status = 'published' THEN 'draft' ELSE 'published' END, updated_at = ? WHERE id = ?", [now(), $id]);
        flash('Status changed.');
    }
    content_changed();
    redirect(admin_url('list.php', ['e' => $key] + array_intersect_key($_GET, ['s' => 1, 'p' => 1])));
}

$search = trim((string)($_GET['s'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 30;
$where = '';
$params = [];
if ($search !== '') {
    $tf = $ent['title_field'];
    $where = "WHERE {$tf} LIKE ?";
    $params[] = '%' . $search . '%';
}
$total = (int)one("SELECT COUNT(*) c FROM {$key} {$where}", $params)['c'];
$rows = all("SELECT * FROM {$key} {$where} ORDER BY {$ent['order']} LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);

admin_head($ent['label'], $key);
?>
<header class="pagehead">
  <h1><?= e($ent['label']) ?> <span class="muted">(<?= $total ?>)</span></h1>
  <div class="actions">
    <form class="search" method="get"><input type="hidden" name="e" value="<?= e($key) ?>">
      <input type="search" name="s" value="<?= e($search) ?>" placeholder="Search…" aria-label="Search"></form>
    <a class="btn primary" href="<?= e(admin_url('edit.php', ['e' => $key])) ?>">+ Add <?= e(strtolower($ent['singular'])) ?></a>
  </div>
</header>

<?php if (!$rows): ?>
  <p class="empty">Nothing here yet. <a href="<?= e(admin_url('edit.php', ['e' => $key])) ?>">Add the first one</a>.</p>
<?php else: ?>
<div class="tablewrap">
<table class="list">
  <thead><tr>
    <?php foreach ($ent['list'] as $col => $label): ?><th><?= e($label) ?></th><?php endforeach ?>
    <th class="right">Actions</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <?php $first = true; foreach ($ent['list'] as $col => $label): ?>
        <td data-label="<?= e($label) ?>"><?php if ($first): ?><a class="rowlink" href="<?= e(admin_url('edit.php', ['e' => $key, 'id' => $row['id']])) ?>"><?= list_cell($col, $row, $ent) ?></a><?php else: ?><?= list_cell($col, $row, $ent) ?><?php endif ?></td>
      <?php $first = false; endforeach ?>
      <td class="right nowrap">
        <a class="btn small" href="<?= e(admin_url('edit.php', ['e' => $key, 'id' => $row['id']])) ?>">Edit</a>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <button class="btn small" name="action" value="toggle"><?= $row['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button>
          <button class="btn small danger" name="action" value="delete" data-confirm="Delete “<?= e($row[$ent['title_field']]) ?>”? This can't be undone.">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?php $pages = (int)ceil($total / $per); if ($pages > 1): ?>
<nav class="pager">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a<?= $i === $page ? ' class="on"' : '' ?> href="<?= e(admin_url('list.php', ['e' => $key, 's' => $search, 'p' => $i])) ?>"><?= $i ?></a>
  <?php endfor ?>
</nav>
<?php endif ?>
<?php endif ?>
<?php admin_foot();
