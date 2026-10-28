<?php
declare(strict_types=1);
/**
 * Make a slim copy of a WordPress export with only what this CMS uses:
 *   php tools/clean-wxr.php import/site.WordPress.xml [import/site.clean.xml]
 *
 * Removes: translated pages (hr / bs / sl / sr Latin + Cyrillic), Elementor templates,
 * menus, ACF definitions, theme styles, comments, unused attachments and SEO/plugin meta.
 * Keeps: posts, pages, projects, work experience, certificates, affiliates, the images they
 * use, your SVG icon set, and your self-hosted brand fonts (kept exactly as they are).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = 'clean-wxr.php';
define('CMS_NO_CONFIG', true); // works on any computer, no database needed
require dirname(__DIR__) . '/inc/bootstrap.php';
require ROOT . '/inc/wpimport.php';

$in = $argv[1] ?? '';
if (!is_file($in)) { fwrite(STDERR, "Usage: php tools/clean-wxr.php <export.xml> [out.xml]\n"); exit(1); }
$out = $argv[2] ?? preg_replace('~\.xml$~i', '', $in) . '.clean.xml';

const WP_NS = 'http://wordpress.org/export/1.2/';
const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

$doc = new DOMDocument();
$doc->preserveWhiteSpace = false;
if (!$doc->load($in, LIBXML_PARSEHUGE | LIBXML_NONET)) { fwrite(STDERR, "Not readable XML.\n"); exit(1); }
$xp = new DOMXPath($doc);
$xp->registerNamespace('wp', WP_NS);
$xp->registerNamespace('content', CONTENT_NS);
$val = fn(DOMNode $n, string $q) => ($r = $xp->query($q, $n)->item(0)) ? $r->textContent : '';

$keepTypes = array_merge(array_keys(WP_IMPORT_TYPES), ['elementor_font']); // brand fonts stay
$stats = [];
$keptItems = [];
$attachments = [];
$referenced = [];
$keptText = '';

foreach (iterator_to_array($xp->query('/rss/channel/item')) as $item) {
    $type = $val($item, 'wp:post_type');
    if ($type === 'attachment') { $attachments[] = $item; continue; }
    $status = $val($item, 'wp:status');
    $it = ['slug' => $val($item, 'wp:post_name'), 'title' => $val($item, 'title')];
    $why = null;
    if (!in_array($type, $keepTypes, true)) $why = "{$type}";
    elseif (in_array($status, ['trash', 'auto-draft', 'inherit'], true)) $why = 'trash / auto-draft';
    elseif (wp_is_translation($it)) $why = 'translation (hr/bs/sl/sr)';
    elseif ($type === 'page' && (in_array(slugify(rawurldecode($it['slug'])), WP_SKIP_PAGES, true) || preg_match('~^blog-\d+$~', $it['slug']))) $why = 'page built by the CMS itself';
    if ($why) {
        $stats["removed: {$why}"] = ($stats["removed: {$why}"] ?? 0) + 1;
        $item->parentNode->removeChild($item);
        continue;
    }
    $stats["kept: {$type}"] = ($stats["kept: {$type}"] ?? 0) + 1;
    $keptItems[] = $item;
    // Drop comments and plugin/SEO meta; keep featured image + custom (ACF) fields.
    foreach (iterator_to_array($xp->query('wp:comment', $item)) as $c) $item->removeChild($c);
    foreach (iterator_to_array($xp->query('wp:postmeta', $item)) as $pm) {
        $k = $val($pm, 'wp:meta_key');
        $v = $val($pm, 'wp:meta_value');
        $drop = ($k !== '' && $k[0] === '_' && !in_array($k, ['_thumbnail_id', '_elementor_data'], true))
            || preg_match('~^(rank_math|iawmlf|classic-editor|cmplz|ekit|_elementor_(?!data))~', $k);
        if ($k === '_elementor_data' && trim($val($item, 'content:encoded')) !== '') $drop = true;
        if ($drop) { $item->removeChild($pm); continue; }
        if (in_array($k, ['_thumbnail_id', 'project_image'], true) && ctype_digit($v)) $referenced[(int)$v] = true;
    }
    $content = $val($item, 'content:encoded');
    $keptText .= $content . "\n";
    if (preg_match_all('~wp-image-(\d+)~', $content, $m)) foreach ($m[1] as $id) $referenced[(int)$id] = true;
}

// Attachments: keep images the kept content uses, plus the SVG icon set (not the Roboto font files).
foreach ($attachments as $a) {
    $id = (int)$val($a, 'wp:post_id');
    $url = $val($a, 'wp:attachment_url');
    $file = basename((string)parse_url($url, PHP_URL_PATH));
    $base = preg_replace('~\.[a-z0-9]+$~i', '', $file);
    $isFont = (bool)preg_match('~\.(woff2?|ttf|otf|eot)$~i', $file) || (str_ends_with(strtolower($file), '.svg') && preg_match('~^(Roboto|Noto)~i', $file));
    $isIcon = str_ends_with(strtolower($file), '.svg') && !$isFont;
    $used = isset($referenced[$id]) || ($base !== '' && str_contains($keptText, '/' . $base));
    if ($isFont) {
        $stats['kept: brand font files'] = ($stats['kept: brand font files'] ?? 0) + 1;
        continue;
    }
    if ($isIcon || $used) {
        foreach (iterator_to_array($xp->query('wp:comment', $a)) as $c) $a->removeChild($c);
        $stats[$isIcon ? 'kept: SVG icons' : 'kept: images in use'] = ($stats[$isIcon ? 'kept: SVG icons' : 'kept: images in use'] ?? 0) + 1;
    } else {
        $a->parentNode->removeChild($a);
        $stats['removed: unused attachments'] = ($stats['removed: unused attachments'] ?? 0) + 1;
    }
}

// Terms of taxonomies the CMS doesn't use.
foreach (iterator_to_array($xp->query('/rss/channel/wp:term')) as $t) {
    $tax = $val($t, 'wp:term_taxonomy');
    if (!in_array($tax, ['project-category', 'project-technology'], true)) { $t->parentNode->removeChild($t); }
}

$doc->formatOutput = true;
$doc->save($out);
ksort($stats);
foreach ($stats as $k => $n) echo str_pad((string)$n, 5, ' ', STR_PAD_LEFT), "  {$k}\n";
printf("Wrote %s (%.1f MB → %.1f MB)\n", $out, filesize($in) / 1048576, filesize($out) / 1048576);
