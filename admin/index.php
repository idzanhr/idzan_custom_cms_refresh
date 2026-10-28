<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

admin_head('Dashboard', 'dashboard');
?>
<header class="pagehead">
  <h1>Dashboard</h1>
  <div class="actions">
    <a class="btn primary" href="<?= e(admin_url('edit.php', ['e' => 'posts'])) ?>">+ New blog post</a>
    <a class="btn" href="<?= e(admin_url('edit.php', ['e' => 'projects'])) ?>">+ New project</a>
  </div>
</header>

<section class="cards">
<?php foreach (entities() as $key => $ent):
    $total = (int)one("SELECT COUNT(*) c FROM {$key}")['c'];
    $drafts = (int)one("SELECT COUNT(*) c FROM {$key} WHERE status = 'draft'")['c']; ?>
  <a class="card" href="<?= e(admin_url('list.php', ['e' => $key])) ?>">
    <span class="num"><?= $total ?></span>
    <span class="lbl"><?= e($ent['label']) ?></span>
    <?php if ($drafts): ?><span class="muted"><?= $drafts ?> draft<?= $drafts > 1 ? 's' : '' ?></span><?php endif ?>
  </a>
<?php endforeach ?>
</section>

<section class="panel">
  <h2>Recently edited</h2>
  <ul class="recent">
  <?php
  $recent = [];
  foreach (entities() as $key => $ent) {
      $tf = $ent['title_field'] ?? 'title';
      foreach (all("SELECT id, {$tf} AS t, updated_at FROM {$key} ORDER BY updated_at DESC LIMIT 5") as $r) {
          $recent[] = $r + ['e' => $key, 'label' => $ent['singular']];
      }
  }
  usort($recent, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
  foreach (array_slice($recent, 0, 8) as $r): ?>
    <li><a href="<?= e(admin_url('edit.php', ['e' => $r['e'], 'id' => $r['id']])) ?>"><?= e($r['t']) ?></a>
      <span class="muted"><?= e($r['label']) ?> · <?= e(date('d.m.Y. H:i', strtotime($r['updated_at']))) ?></span></li>
  <?php endforeach ?>
  <?php if (!$recent): ?><li class="muted">Nothing yet.</li><?php endif ?>
  </ul>
</section>
<?php admin_foot();
