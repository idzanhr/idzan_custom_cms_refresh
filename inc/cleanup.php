<?php
declare(strict_types=1);

/**
 * Tidy the database: translated page copies (hr / bs / sl / sr), categories/services/tools
 * nothing uses, uploaded images nothing references, expired sign-in records.
 * Returns human-readable lines; only changes anything when $apply is true.
 */
function cms_cleanup(bool $apply): array
{
    require_once ROOT . '/inc/wpimport.php';
    $out = [];
    foreach (all('SELECT id, slug, title FROM pages') as $p) {
        if (wp_is_translation(['slug' => $p['slug'], 'title' => $p['title']])) {
            $out[] = "translated page “{$p['title']}” (/{$p['slug']}/)";
            if ($apply) q('DELETE FROM pages WHERE id = ?', [$p['id']]);
        }
    }
    foreach (all("SELECT t.* FROM terms t WHERE (t.taxonomy = 'category' AND NOT EXISTS (SELECT 1 FROM posts p WHERE p.category_id = t.id) AND NOT EXISTS (SELECT 1 FROM entity_terms et WHERE et.term_id = t.id))
                  OR (t.taxonomy <> 'category' AND NOT EXISTS (SELECT 1 FROM entity_terms et WHERE et.term_id = t.id))") as $t) {
        $out[] = "unused {$t['taxonomy']} “{$t['name']}”";
        if ($apply) q('DELETE FROM terms WHERE id = ?', [$t['id']]);
    }
    $refs = '';
    foreach (['posts' => 'featured_image, content', 'projects' => 'featured_image, content', 'affiliates' => 'featured_image, content',
              'certifications' => 'image', 'pages' => 'content', 'experience' => 'description'] as $table => $cols) {
        foreach (all("SELECT {$cols} FROM {$table}") as $r) $refs .= implode("\n", $r) . "\n";
    }
    foreach (all('SELECT v FROM settings') as $r) $refs .= $r['v'] . "\n";
    $dir = public_dir() . '/uploads';
    $n = 0; $bytes = 0;
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!$f->isFile() || $f->getFilename()[0] === '.') continue;
            $rel = 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            if (!str_contains($refs, $rel)) { $n++; $bytes += $f->getSize(); if ($apply) @unlink($f->getPathname()); }
        }
    }
    if ($n) $out[] = sprintf('%d unused image file(s), %.1f MB', $n, $bytes / 1048576);
    if ($apply) {
        q('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        q('DELETE FROM remember_tokens WHERE expires_at < ?', [now()]);
        content_changed();
    }
    return $out;
}
