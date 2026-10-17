<?php
/**
 * Search-engine helpers:
 *  - alt text per image (Media → edit image), used wherever that image appears without an alt of its own;
 *  - renaming an image file to a descriptive name, with every reference updated;
 *  - the SEO check list (Tools → SEO check).
 * Search title / description per item live in the content tables (seo_title, seo_description).
 */
declare(strict_types=1);

const SEO_TABLES = ['posts', 'pages', 'projects', 'affiliates'];   // have seo_title / seo_description
const SEO_TITLE_MAX = 60;
const SEO_DESC_MIN = 70;
const SEO_DESC_MAX = 160;

/** 'uploads/…' key for an image address (/uploads/…, https://site/uploads/…, uploads/…), or '' if it isn't a local upload. */
function media_key(string $src): string
{
    $p = html_entity_decode(trim($src), ENT_QUOTES);
    $site = rtrim((string)cfg('site_url', ''), '/');
    if ($site !== '' && stripos($p, $site . '/') === 0) $p = substr($p, strlen($site));
    $base = rtrim((string)cfg('base_path', ''), '/');
    if ($base !== '' && str_starts_with($p, $base . '/')) $p = substr($p, strlen($base));
    $p = ltrim((string)strtok($p, '?#'), '/');
    return preg_match('~^uploads/[^\s"<>]+$~', $p) ? $p : '';
}

/** All saved alt texts: ['uploads/…' => 'alt']. */
function media_alt_map(): array
{
    static $map = null;
    if (isset($GLOBALS['MEDIA_ALT_RESET'])) { $map = null; unset($GLOBALS['MEDIA_ALT_RESET']); }
    if ($map === null) {
        $map = [];
        try { foreach (all_raw('SELECT path, alt FROM media_meta') as $r) if (trim((string)$r['alt']) !== '') $map[$r['path']] = (string)$r['alt']; }
        catch (Throwable $e) { /* table not created yet */ }
    }
    return $map;
}

function media_alt(?string $src): string
{
    $k = media_key((string)$src);
    return $k !== '' ? (media_alt_map()[$k] ?? '') : '';
}

function media_alt_save(string $rel, string $alt): void
{
    $alt = mb_substr(trim(preg_replace('~\s+~', ' ', $alt)), 0, 255);
    q('DELETE FROM media_meta WHERE path = ?', [$rel]);
    if ($alt !== '') q('INSERT INTO media_meta (path, alt, updated_at) VALUES (?, ?, ?)', [$rel, $alt, now()]);
    $GLOBALS['MEDIA_ALT_RESET'] = true;
}

/** Images in page text without an alt (or with alt="") get the alt saved for that image in Media. */
function seo_imgs(string $html): string
{
    if (!str_contains($html, '<img') || !media_alt_map()) return $html;
    return (string)preg_replace_callback('~<img\b[^>]*>~i', function ($m) {
        $tag = $m[0];
        if (preg_match('~\salt\s*=\s*"([^"]*)"~i', $tag, $a) && trim($a[1]) !== '') return $tag;
        if (!preg_match('~\ssrc\s*=\s*"([^"]+)"~i', $tag, $s) || ($alt = media_alt($s[1])) === '') return $tag;
        $tag = (string)preg_replace('~\salt\s*=\s*"[^"]*"~i', '', $tag);
        return (string)preg_replace('~^<img\b~i', '<img alt="' . e($alt) . '"', $tag);
    }, $html);
}

/** Plain text of a search description: trimmed to SEO_DESC_MAX at a word boundary. */
function seo_clip(string $text, int $max = SEO_DESC_MAX): string
{
    $t = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES)));
    if (mb_strlen($t) <= $max) return $t;
    $cut = mb_substr($t, 0, $max - 1);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:-–") . '…';
}

/* ---------------- renaming images ---------------- */

/**
 * Renames an uploaded image to a descriptive file name (same folder, same extension) and updates every
 * reference: content tables, settings, translations and alt texts. Returns the new 'uploads/…' path.
 */
function media_rename(string $rel, string $newName): string
{
    require_once ROOT . '/inc/medialib.php';
    $abs = media_abs($rel);
    if (!$abs) throw new RuntimeException('That image was not found.');
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    $base = substr(slugify(preg_replace('~\.(jpe?g|png|webp|gif|avif)$~i', '', trim($newName))), 0, 80);
    if ($base === '') throw new RuntimeException('Type a name using letters or numbers.');
    $dir = dirname($rel);
    $new = $dir . '/' . $base . '.' . $ext;
    if ($new === $rel) return $rel;
    for ($i = 2; is_file(public_dir() . '/' . $new); $i++) $new = $dir . '/' . $base . '-' . $i . '.' . $ext;
    if (!@rename($abs, public_dir() . '/' . $new)) throw new RuntimeException('The file could not be renamed (check folder permissions).');

    $swap = fn(string $v) => str_replace($rel, $new, $v);
    foreach (array_keys(entities()) as $table) {
        try { $rows = all_raw("SELECT * FROM {$table}"); } catch (Throwable $e) { continue; }
        foreach ($rows as $r) foreach ($r as $col => $v) {
            if (is_string($v) && str_contains($v, $rel)) q("UPDATE {$table} SET {$col} = ? WHERE id = ?", [$swap($v), $r['id']]);
        }
    }
    foreach (all('SELECT k, v FROM settings') as $r) if (str_contains((string)$r['v'], $rel)) save_setting((string)$r['k'], $swap((string)$r['v']));
    try { foreach (all_raw('SELECT id, value FROM translations WHERE value LIKE ?', ['%' . $rel . '%']) as $r) q('UPDATE translations SET value = ? WHERE id = ?', [$swap((string)$r['value']), $r['id']]); }
    catch (Throwable $e) { /* no translations yet */ }
    try { q('UPDATE media_meta SET path = ? WHERE path = ?', [$new, $rel]); } catch (Throwable $e) {}
    $GLOBALS['MEDIA_ALT_RESET'] = true;
    $GLOBALS['TR_RESET'] = true;
    return $new;
}

/** File names that say nothing about the picture: IMG_1234, DSC0001, Screenshot…, random letters. */
function media_name_vague(string $rel): bool
{
    $n = strtolower(pathinfo($rel, PATHINFO_FILENAME));
    $n = preg_replace('~(-[0-9a-f]{6}|-\d{1,4}x\d{1,4}|-scaled|-\d)+$~', '', $n);       // upload suffix, WP sizes
    if (preg_match('~^(img|image|dsc|dscn|dcim|pxl|photo|foto|slika|screenshot|screen-shot|snimka-zaslona|capture|untitled|file|whatsapp-image|signal|pexels|unsplash|wp)[-_]?[\d-]*~', $n)) return true;
    if (preg_match('~^[\d_-]+$~', $n) || preg_match('~^[0-9a-f-]{12,}$~', $n)) return true;
    return mb_strlen(str_replace('-', '', $n)) < 4;
}

/* ---------------- SEO check ---------------- */

/** Problems to fix: [severity 'fix'|'tip', where, what, admin link]. */
function seo_issues(): array
{
    $out = [];
    $ents = entities();
    $add = function (string $sev, string $where, string $what, string $link) use (&$out) { $out[] = [$sev, $where, $what, $link]; };
    foreach (SEO_TABLES as $t) {
        $ent = $ents[$t] ?? null;
        if (!$ent) continue;
        foreach (all_raw("SELECT * FROM {$t} WHERE status = 'published'") as $r) {
            $label = $ent['singular'] . ': ' . ($r['title'] ?? '#' . $r['id']);
            $link = admin_url('edit.php', ['e' => $t, 'id' => $r['id']]);
            $title = trim((string)(($r['seo_title'] ?? '') ?: ($r['title'] ?? '')));
            if (mb_strlen($title) > SEO_TITLE_MAX) $add('tip', $label, 'Search title is ' . mb_strlen($title) . ' characters; Google shows about ' . SEO_TITLE_MAX . '. Add a shorter "Search title".', $link);
            $desc = trim((string)($r['seo_description'] ?? ''));
            $fallback = trim((string)($r['excerpt'] ?? ''));
            if ($desc === '' && $fallback === '') $add('fix', $label, 'No search description: Google and share previews take the first lines of the text.', $link);
            elseif ($desc !== '' && mb_strlen($desc) > SEO_DESC_MAX) $add('tip', $label, 'Search description is ' . mb_strlen($desc) . ' characters; aim for ' . SEO_DESC_MIN . '–' . SEO_DESC_MAX . '.', $link);
            elseif ($desc !== '' && mb_strlen($desc) < SEO_DESC_MIN) $add('tip', $label, 'Search description is short (' . mb_strlen($desc) . ' characters); aim for ' . SEO_DESC_MIN . '–' . SEO_DESC_MAX . '.', $link);
            if (!empty($r['featured_image']) && trim((string)($r['featured_alt'] ?? '')) === '' && media_alt($r['featured_image']) === '')
                $add('fix', $label, 'Featured image has no alt text.', $link);
            $html = (string)($r['content'] ?? '');
            if (preg_match_all('~<img\b[^>]*>~i', $html, $m)) {
                $n = 0;
                foreach ($m[0] as $tag) {
                    if (preg_match('~\salt\s*=\s*"([^"]*)"~i', $tag, $a) && trim($a[1]) !== '') continue;
                    if (preg_match('~\ssrc\s*=\s*"([^"]+)"~i', $tag, $s) && media_alt($s[1]) !== '') continue;
                    $n++;
                }
                if ($n) $add('fix', $label, $n . ' image' . ($n > 1 ? 's' : '') . ' in the text without alt text. Add it in the editor, or once per image in Media.', $link);
            }
            if ($t === 'posts' && str_word_count(strip_tags($html)) < 300) $add('tip', $label, 'Short post (' . str_word_count(strip_tags($html)) . ' words). Search engines rank longer, detailed posts better.', $link);
        }
    }
    return $out;
}
