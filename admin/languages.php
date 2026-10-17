<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require ROOT . '/inc/translate.php';

/*
 * Languages & translation: switch languages on, pick the translation service, translate everything
 * missing or outdated (runs step by step in the browser, pausing on rate limits), and edit any
 * translation by hand.
 */

$json = function (array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'settings') {
        $langs = array_values(array_intersect(array_keys(LANGS), (array)($_POST['langs'] ?? [])));
        save_setting('languages', implode(',', $langs));
        if (array_key_exists((string)($_POST['provider'] ?? ''), TR_PROVIDERS)) save_setting('tr_provider', (string)$_POST['provider']);
        $fb = (string)($_POST['fallback'] ?? '');
        save_setting('tr_fallback', array_key_exists($fb, TR_PROVIDERS) ? $fb : '');
        save_setting('tr_main_paused_until', '0');
        save_setting('tr_autopublish', !empty($_POST['autopublish']) ? '1' : '0');
        save_setting('tr_note', !empty($_POST['note']) ? '1' : '0');
        content_changed();
        flash('Language settings saved and the site was rebuilt.');
        redirect(admin_url('languages.php'));
    }

    if ($action === 'sync-images') {
        [$n, $m] = tr_sync_all(!empty($_POST['mark']));
        if ($n) content_changed();
        flash($n ? "Images copied from the originals into {$n} translation(s)" . ($m ? ", {$m} marked up to date" : '') . ', and the site was rebuilt.'
                 : 'All translations already use the same images as the originals.');
        redirect(admin_url('languages.php'));
    }

    // Step-by-step translation, driven by admin.js.
    if ($action === 'queue') {
        @set_time_limit(120);
        $mode = ($_POST['mode'] ?? 'missing') === 'outdated' ? 'outdated' : 'missing';
        $only = (string)($_POST['lang'] ?? 'all');
        $langs = $only === 'all' ? enabled_langs() : array_intersect([$only], enabled_langs());
        $jobs = [];
        foreach (tr_items() as [$entity, $id, $label]) {
            $src = tr_source($entity, $id);
            if (!$src) continue;
            foreach ($langs as $l) {
                $st = tr_status($entity, $id, $l, $src);
                if ($st === 'missing' || ($mode === 'outdated' && $st === 'outdated')) $jobs[] = ['e' => $entity, 'id' => $id, 'l' => $l, 'label' => $label];
            }
        }
        $json(['jobs' => $jobs, 'provider' => TR_PROVIDERS[tr_provider()] ?? '']);
    }
    if ($action === 'run') {
        @set_time_limit(600);
        try {
            $n = tr_translate_item((string)$_POST['e'], (int)$_POST['id'], (string)$_POST['l']);
            $used = $GLOBALS['TR_USED'] ?? '';
            $json(['ok' => true, 'fields' => $n, 'backup' => $used !== '' && $used !== tr_provider() ? (TR_PROVIDERS[$used] ?? $used) : '']);
        } catch (TrRateLimit $e) {
            $json(['ok' => false, 'error' => $e->getMessage(), 'retry' => $e->retry]);
        } catch (Throwable $e) {
            $json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    if ($action === 'finish') {
        content_changed();
        $json(['ok' => true]);
    }

    // Manual editing of one item in one language.
    if ($action === 'save-item' || $action === 'retranslate') {
        $entity = (string)$_POST['e']; $id = (int)$_POST['id']; $l = (string)$_POST['l'];
        $back = admin_url('languages.php', ['edit' => $entity . ':' . $id, 'lang' => $l]);
        if (!isset(LANGS[$l])) redirect(admin_url('languages.php'));
        if ($action === 'retranslate') {
            try { tr_translate_item($entity, $id, $l); content_changed(); flash('Translated again.'); }
            catch (Throwable $e) { flash('Translation failed: ' . $e->getMessage(), 'err'); }
            redirect($back);
        }
        $src = tr_source($entity, $id);
        $status = ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published';
        foreach ($src as $f => $v) {
            $new = trim((string)($_POST['f'][$f] ?? ''));
            if ($new === '') { q('DELETE FROM translations WHERE entity = ? AND entity_id = ? AND lang = ? AND field = ?', [$entity, $id, $l, $f]); continue; }
            if (str_contains($v, '<')) $new = clean_html($new);
            tr_save($entity, $id, $l, $f, $new, md5($v), $status, 'manual');
        }
        content_changed();
        flash('Translation saved' . ($status === 'draft' ? ' as a draft (not shown on the site).' : ' and published.'));
        redirect($back);
    }
}

$enabled = enabled_langs();
$ready = tr_ready_providers();
$provider = tr_provider();

/* ---------- edit one item ---------- */
if (isset($_GET['edit'], $_GET['lang']) && isset(LANGS[$_GET['lang']])) {
    [$entity, $id] = array_pad(explode(':', (string)$_GET['edit'], 2), 2, '0');
    $id = (int)$id; $l = (string)$_GET['lang'];
    $label = '';
    foreach (tr_items() as $it) if ($it[0] === $entity && $it[1] === $id) { $label = $it[3] . ': ' . $it[2]; break; }
    $src = tr_source($entity, $id);
    $rows = tr_rows($entity, $id, $l);
    admin_head('Translation', 'languages');
    ?>
<header class="pagehead"><h1><?= e(LANGS[$l][0]) ?> <span class="muted">· <?= e($label ?: $entity . ' #' . $id) ?></span></h1>
  <div class="actions"><a class="btn" href="<?= e(admin_url('languages.php')) ?>">← Languages</a></div></header>
<?php if (!$src): ?><p class="muted">Nothing to translate here.</p><?php else: ?>
<form method="post" class="panel" data-unsaved>
  <?= csrf_field() ?><input type="hidden" name="e" value="<?= e($entity) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="l" value="<?= e($l) ?>">
  <p class="help">Status: <strong><?= e(tr_status($entity, $id, $l, $src, $rows)) ?></strong><?php if ($rows): $any = reset($rows); ?> · last change <?= e($any['updated_at']) ?> (<?= e($any['engine'] === 'manual' ? 'edited by hand' : (TR_PROVIDERS[$any['engine']] ?? $any['engine'])) ?>)<?php endif ?>. Empty a field to use the English text there.</p>
  <?php foreach ($src as $f => $v):
      $isHtml = str_contains($v, '<'); $cur = $rows[$f]['value'] ?? '';
      $old = isset($rows[$f]) && $rows[$f]['src_hash'] !== md5($v);
      $name = $entity === 'ui' ? $v : ($entity === 'settings' ? $f : ucfirst($f)); ?>
  <div class="field tr-field">
    <label for="f_<?= e($f) ?>"><?= e(mb_strimwidth($name, 0, 90, '…')) ?><?= $old ? ' <span class="pill draft">English changed since</span>' : '' ?></label>
    <?php if ($entity !== 'ui'): ?><details class="tr-src"><summary>English original</summary><div class="<?= $isHtml ? 'prose' : '' ?>"><?= $isHtml ? $v : nl2br(e($v)) ?></div></details><?php endif ?>
    <?php if ($isHtml): ?>
      <textarea class="rte" id="f_<?= e($f) ?>" name="f[<?= e($f) ?>]" rows="12"><?= e($cur) ?></textarea>
    <?php else: ?>
      <textarea id="f_<?= e($f) ?>" name="f[<?= e($f) ?>]" rows="<?= mb_strlen($v) > 90 ? 3 : 1 ?>" lang="<?= e(lang_html($l)) ?>"><?= e($cur) ?></textarea>
    <?php endif ?>
  </div>
  <?php endforeach ?>
  <div class="field"><label for="st">Show on the site</label>
    <select id="st" name="status"><option value="published">Yes, published</option><option value="draft"<?= $rows && reset($rows)['status'] === 'draft' ? ' selected' : '' ?>>No, keep as draft</option></select></div>
  <p class="actions"><button class="btn primary" type="submit" name="action" value="save-item">Save</button>
    <?php if ($provider): ?><button class="btn" type="submit" name="action" value="retranslate" data-confirm="Replace this translation with a new one from <?= e(TR_PROVIDERS[$provider]) ?>?">Translate again with <?= e(TR_PROVIDERS[$provider]) ?></button><?php endif ?></p>
</form>
<?php endif;
    admin_foot();
    exit;
}

/* ---------- overview ---------- */
$items = tr_items();
$byType = [];
$statusOf = [];
foreach ($items as [$entity, $id, $label, $type]) {
    $src = tr_source($entity, $id);
    if (!$src) continue;
    foreach ($enabled as $l) {
        $st = tr_status($entity, $id, $l, $src);
        $statusOf[$entity . ':' . $id][$l] = $st;
        $byType[$type][$l][$st] = ($byType[$type][$l][$st] ?? 0) + 1;
        $byType[$type][$l]['all'] = ($byType[$type][$l]['all'] ?? 0) + 1;
    }
}
$filter = (string)($_GET['type'] ?? 'attention');
admin_head('Languages & translation', 'languages');
?>
<header class="pagehead"><h1>Languages &amp; translation</h1></header>

<form method="post" class="panel medium">
  <?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <h2>Languages on the site</h2>
  <p class="help">English stays the original at <code>/</code>. Each language gets its own copy of the site (<code>/hr/</code>, <code>/bs/</code>, <code>/sr/</code>, <code>/sl/</code>) and appears in the header's language menu. Anything not translated yet shows in English.</p>
  <div class="chips">
    <?php foreach (LANGS as $code => [$name]): ?>
      <label class="chip"><input type="checkbox" name="langs[]" value="<?= e($code) ?>"<?= in_array($code, $enabled, true) ? ' checked' : '' ?>> <?= e($name) ?> <span class="muted">/<?= e($code) ?>/</span></label>
    <?php endforeach ?>
  </div>
  <h2 class="mt">Translation service</h2>
  <?php if (!$ready): ?>
    <p class="flash err">No service is set up yet. Add at least one API key to <code>config.php</code> under <code>'translate'</code> (see the lines in <code>config.sample.php</code>), then reload this page.</p>
  <?php endif ?>
  <div class="chips">
    <?php foreach (TR_PROVIDERS as $p => $name): $ok = in_array($p, $ready, true); ?>
      <label class="chip<?= $ok ? '' : ' is-off' ?>"><input type="radio" name="provider" value="<?= e($p) ?>"<?= $p === $provider ? ' checked' : '' ?><?= $ok ? '' : ' disabled' ?>> <?= e($name) ?>
        <span class="muted"><?= $ok ? ($p === 'google' ? '' : '· ' . e(tr_cfg($p . '_model', TR_DEFAULT_MODELS[$p]))) : '· no API key' ?></span></label>
    <?php endforeach ?>
  </div>
  <h3 class="mt">Backup service</h3>
  <p class="help">Used automatically when the main service hits its limit (too many requests, daily quota or your spending cap). After that it keeps using the backup for <?= (int)(TR_FALLBACK_HOLD / 60) ?> minutes, then tries the main service again.</p>
  <div class="chips">
    <label class="chip"><input type="radio" name="fallback" value=""<?= tr_fallback() === '' ? ' checked' : '' ?>> None <span class="muted">· wait for the limit to pass</span></label>
    <?php foreach (TR_PROVIDERS as $p => $name): $ok = in_array($p, $ready, true); ?>
      <label class="chip<?= $ok ? '' : ' is-off' ?>"><input type="radio" name="fallback" value="<?= e($p) ?>"<?= $p === tr_fallback() ? ' checked' : '' ?><?= $ok && $p !== $provider ? '' : ' disabled' ?>> <?= e($name) ?>
        <span class="muted"><?= !$ok ? '· no API key' : ($p === $provider ? '· main service' : ($p === 'google' ? '' : '· ' . e(tr_cfg($p . '_model', TR_DEFAULT_MODELS[$p])))) ?></span></label>
    <?php endforeach ?>
  </div>
  <label class="check"><input type="checkbox" name="autopublish" value="1"<?= setting('tr_autopublish', '1') === '1' ? ' checked' : '' ?>> Publish new translations right away (untick to review them first: they wait as drafts)</label>
  <label class="check"><input type="checkbox" name="note" value="1"<?= setting('tr_note', '1') === '1' ? ' checked' : '' ?>> Show a small “translated automatically” note with a link to the English original</label>
  <p><button class="btn primary" type="submit">Save</button></p>
</form>

<?php if ($enabled): ?>
<section class="panel" data-tr-runner data-url="<?= e(admin_url('languages.php')) ?>">
  <h2>Translate</h2>
  <?php if (!$provider): ?><p class="muted">Set up a translation service first (above).</p><?php else: ?>
  <p class="help">Runs one item and language at a time with <strong><?= e(TR_PROVIDERS[$provider]) ?></strong>. You can leave this page open and watch, pause, or stop; free tiers have a limit per minute and per day, so it waits by itself when the service says “too many requests”, and you can continue another day: finished items aren't translated again.</p>
  <div class="actions">
    <select data-tr-lang aria-label="Language"><option value="all">All languages</option><?php foreach ($enabled as $l): ?><option value="<?= e($l) ?>"><?= e(LANGS[$l][0]) ?></option><?php endforeach ?></select>
    <button class="btn primary" type="button" data-tr-start="missing">Translate everything missing</button>
    <button class="btn" type="button" data-tr-start="outdated">… and update outdated</button>
    <button class="btn" type="button" data-tr-pause hidden>Pause</button>
  </div>
  <div class="tr-progress" hidden><progress max="100" value="0" data-tr-bar></progress><p class="small" data-tr-text role="status" aria-live="polite"></p><ul class="log small" data-tr-log></ul></div>
  <?php endif ?>
</section>

<section class="panel">
  <h2>Images in translations</h2>
  <p class="help">Translated pages always show the images of the English original. This also saves that into the stored translations,
    e.g. after "Fix old WordPress image links", which only updates the originals. Text and alt texts stay as translated.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync-images">
    <label class="check"><input type="checkbox" name="mark" value="1"> Also mark those translations as up to date (only if nothing but images changed in the originals since they were translated — saves translating them again)</label>
    <p><button class="btn" type="submit">Copy images from the English originals</button></p>
  </form>
</section>

<section class="panel">
  <h2>Progress</h2>
  <table class="list tr-table">
    <thead><tr><th>Type</th><?php foreach ($enabled as $l): ?><th><?= e(LANGS[$l][0]) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach ($byType as $type => $cells): ?>
      <tr><td data-label="Type"><a href="<?= e(admin_url('languages.php', ['type' => $type])) ?>#items"><?= e($type) ?></a></td>
      <?php foreach ($enabled as $l): $c = $cells[$l] ?? []; $done = ($c['done'] ?? 0); $all = $c['all'] ?? 0; ?>
        <td data-label="<?= e(LANGS[$l][0]) ?>"><?= $done ?>/<?= $all ?><?= !empty($c['outdated']) ? ' · <span class="warn">' . (int)$c['outdated'] . ' outdated</span>' : '' ?><?= !empty($c['draft']) ? ' · ' . (int)$c['draft'] . ' draft' : '' ?></td>
      <?php endforeach ?></tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>

<section class="panel" id="items">
  <h2>Check or edit a translation</h2>
  <form method="get" class="filters" action="#items">
    <select name="type" aria-label="Show"><option value="attention"<?= $filter === 'attention' ? ' selected' : '' ?>>Needs attention (missing, outdated, draft)</option><option value=""<?= $filter === '' ? ' selected' : '' ?>>Everything</option><?php foreach (array_keys($byType) as $t): ?><option<?= $t === $filter ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach ?></select>
    <button class="btn" type="submit">Show</button>
  </form>
  <table class="list tr-table">
    <thead><tr><th>Item</th><?php foreach ($enabled as $l): ?><th><?= e(strtoupper($l)) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach ($items as [$entity, $id, $label, $type]):
        if (!isset($statusOf[$entity . ':' . $id])) continue;
        if ($filter === 'attention') { if (!array_diff($statusOf[$entity . ':' . $id], ['done'])) continue; }
        elseif ($filter !== '' && $type !== $filter) continue;
        $shown = ($shown ?? 0) + 1; ?>
      <tr><td data-label="Item"><span class="muted small"><?= e($type) ?></span><br><?= e(mb_strimwidth($label, 0, 80, '…')) ?></td>
      <?php foreach ($enabled as $l): $st = $statusOf[$entity . ':' . $id][$l]; ?>
        <td data-label="<?= e(strtoupper($l)) ?>"><a class="tr-st tr-<?= e($st) ?>" href="<?= e(admin_url('languages.php', ['edit' => $entity . ':' . $id, 'lang' => $l])) ?>"><?= e(['done' => '✓ done', 'missing' => 'missing', 'outdated' => 'outdated', 'draft' => 'draft'][$st]) ?></a></td>
      <?php endforeach ?></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php if (empty($shown)): ?><p class="muted"><?= $filter === 'attention' ? 'Everything is translated and up to date. 🎉' : 'Nothing here.' ?></p><?php endif ?>
</section>
<?php endif ?>
<?php admin_foot();
