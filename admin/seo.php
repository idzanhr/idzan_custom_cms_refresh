<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require ROOT . '/inc/medialib.php';

/* SEO check: what to fix for search engines — search titles/descriptions, alt texts, file names. */

$issues = seo_issues();
$files = media_files();
$alts = media_alt_map();
$index = media_usage_index();
$noAlt = 0; $vague = 0;
foreach ($files as $rel => $t) {
    if (!media_used_in($rel, $index)) continue;          // only images that are actually on the site
    if (!isset($alts[$rel])) $noAlt++;
    if (media_name_vague($rel)) $vague++;
}
$fix = array_filter($issues, fn($i) => $i[0] === 'fix');
$tips = array_filter($issues, fn($i) => $i[0] === 'tip');
$ix = indexnow_status();

admin_head('SEO check', 'seo');
?>
<header class="pagehead"><h1>SEO check</h1></header>

<section class="panel">
  <h2>Overview</h2>
  <ul class="checklist">
    <li><strong><?= count($fix) ?></strong> thing<?= count($fix) === 1 ? '' : 's' ?> to fix and <strong><?= count($tips) ?></strong> suggestion<?= count($tips) === 1 ? '' : 's' ?> in published posts, pages, projects and affiliates.</li>
    <li><strong><?= $noAlt ?></strong> image<?= $noAlt === 1 ? '' : 's' ?> on the site without alt text in Media<?= $noAlt ? ' — <a href="' . e(admin_url('media.php', ['show' => 'noalt'])) . '">add it once per image</a>' : '' ?>. Alt text typed in a post still wins.</li>
    <li><strong><?= $vague ?></strong> image<?= $vague === 1 ? '' : 's' ?> with a file name that says nothing about the picture<?= $vague ? ' — <a href="' . e(admin_url('media.php', ['show' => 'vague'])) . '">rename them</a>' : '' ?>.</li>
    <li>Sitemap: <a href="<?= e(abs_url(url('/sitemap.xml'))) ?>" target="_blank" rel="noopener">/sitemap.xml</a> (submit it once in Google Search Console and Bing Webmaster Tools).
      IndexNow: <?= $ix === null ? 'off — switch it on in <a href="' . e(admin_url('settings.php')) . '#search-bots">Settings → Search &amp; bots</a>' : (empty($ix['at']) ? 'on, nothing sent yet' : ($ix['ok'] ? 'last sent ' . (int)$ix['count'] . ' address(es) on ' . e($ix['at']) : 'last attempt failed on ' . e($ix['at']) . ': ' . e($ix['why']))) ?>.</li>
  </ul>
</section>

<?php
$byItem = [];
foreach ($issues as [$sev, $where, $what, $link]) {
    $byItem[$where] ??= ['link' => $link, 'fix' => [], 'tip' => []];
    $byItem[$where][$sev][] = $what;
}
uasort($byItem, fn($a, $b) => count($b['fix']) <=> count($a['fix']) ?: count($b['tip']) <=> count($a['tip']));
if ($byItem): ?>
<section class="panel">
  <h2>By item <span class="muted">(<?= count($byItem) ?>)</span></h2>
  <table class="list seo-list">
    <thead><tr><th>Item</th><th>What to improve</th></tr></thead>
    <tbody>
    <?php foreach ($byItem as $where => $it): ?>
      <tr><td data-label="Item"><a href="<?= e($it['link']) ?>"><?= e($where) ?></a></td>
        <td data-label="What to improve"><ul class="seo-points">
          <?php foreach ($it['fix'] as $w): ?><li><span class="pill draft">Fix</span> <?= e($w) ?></li><?php endforeach ?>
          <?php foreach ($it['tip'] as $w): ?><li><span class="pill">Tip</span> <?= e($w) ?></li><?php endforeach ?>
        </ul></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
<?php endif ?>
<?php if (!$issues): ?><p class="panel">Nothing to fix. 🎉</p><?php endif ?>
<?php admin_foot();
