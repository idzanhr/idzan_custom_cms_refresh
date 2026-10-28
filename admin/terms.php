<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$tax = (string)($_GET['t'] ?? '');
if (!isset(TAXONOMIES[$tax])) { http_response_code(404); exit('Unknown list'); }
$info = TAXONOMIES[$tax];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($action === 'add' && $name !== '') {
        $added = 0;
        foreach (preg_split('~[,\n]~', $name) as $n) {
            $n = trim($n);
            if ($n === '') continue;
            $slug = slugify($n);
            if (!one('SELECT id FROM terms WHERE taxonomy = ? AND slug = ?', [$tax, $slug])) {
                q('INSERT INTO terms (taxonomy, name, slug) VALUES (?, ?, ?)', [$tax, mb_substr($n, 0, 190), $slug]);
                $added++;
            }
        }
        flash($added ? "Added {$added}." : 'Already in the list.', $added ? 'ok' : 'warn');
    } elseif ($action === 'rename' && $id && $name !== '') {
        $slug = slugify($name);
        $clash = one('SELECT id FROM terms WHERE taxonomy = ? AND slug = ? AND id <> ?', [$tax, $slug, $id]);
        if ($clash) flash('Another entry already has that name.', 'err');
        else { q('UPDATE terms SET name = ?, slug = ? WHERE id = ? AND taxonomy = ?', [mb_substr($name, 0, 190), $slug, $id, $tax]); flash('Renamed.'); }
    } elseif ($action === 'delete' && $id) {
        if ($tax === 'category') q('UPDATE posts SET category_id = NULL WHERE category_id = ?', [$id]);
        q('DELETE FROM entity_terms WHERE term_id = ?', [$id]);
        q('DELETE FROM terms WHERE id = ? AND taxonomy = ?', [$id, $tax]);
        flash('Deleted.');
    }
    content_changed();
    redirect(admin_url('terms.php', ['t' => $tax]));
}

$rows = all('SELECT * FROM terms WHERE taxonomy = ? ORDER BY name', [$tax]);
admin_head($info['label'], $tax);
?>
<header class="pagehead"><h1><?= e($info['label']) ?></h1></header>

<form class="panel addterm" method="post">
  <?= csrf_field() ?>
  <label for="newterm">Add <?= e(strtolower($info['singular'])) ?> (comma-separate to add several)</label>
  <div class="inline"><input id="newterm" name="name" required maxlength="500"><button class="btn primary" name="action" value="add">Add</button></div>
</form>

<?php if ($rows): ?>
<div class="tablewrap">
<table class="list">
  <thead><tr><th>Name</th><th>Used</th><th class="right">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $used = $tax === 'category'
          ? (int)one("SELECT COUNT(*) c FROM posts p WHERE p.category_id = ? OR EXISTS (SELECT 1 FROM entity_terms et WHERE et.entity = 'posts' AND et.entity_id = p.id AND et.term_id = ?)", [$r['id'], $r['id']])['c']
          : (int)one('SELECT COUNT(*) c FROM entity_terms WHERE term_id = ?', [$r['id']])['c']; ?>
    <tr>
      <td data-label="Name">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input name="name" value="<?= e($r['name']) ?>" aria-label="Name" maxlength="190"><button class="btn small" name="action" value="rename">Rename</button></form>
      </td>
      <td data-label="Used"><?= $used ?></td>
      <td class="right">
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn small danger" name="action" value="delete" data-confirm="Delete “<?= e($r['name']) ?>”?<?= $used ? " It is used {$used}×." : '' ?>">Delete</button></form>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?php else: ?><p class="empty">Nothing yet.</p><?php endif ?>
<?php admin_foot();
