<?php
declare(strict_types=1);

/**
 * "Fix old WordPress image links": anything in the database that still points at the old site's
 * wp-content/uploads (image fields, links around images, images in text) is copied from the old
 * WordPress uploads folder on this server into the CMS, and the address is replaced.
 */
require_once ROOT . '/inc/wpimport.php';

/** The old WordPress uploads folder(s) next to this CMS, e.g. htdocs/wp_old/wp-content/uploads. */
function wp_uploads_guess(): array
{
    return glob(dirname(ROOT) . '/*/wp-content/uploads', GLOB_ONLYDIR) ?: [];
}

/** Where old addresses remain: [table, id, column, value]. */
function wp_link_cells(): array
{
    $cells = [];
    foreach (array_keys(entities()) as $table) {
        try { $rows = all("SELECT * FROM {$table}"); } catch (Throwable $e) { continue; }
        foreach ($rows as $r) foreach ($r as $col => $v) {
            if (is_string($v) && str_contains($v, '/wp-content/uploads/')) $cells[] = [$table, (int)$r['id'], $col, $v];
        }
    }
    foreach (all('SELECT k, v FROM settings') as $r) {
        if (str_contains((string)$r['v'], '/wp-content/uploads/')) $cells[] = ['settings', 0, (string)$r['k'], (string)$r['v']];
    }
    return $cells;
}

/** Number of old addresses still in use. */
function wp_link_count(): int
{
    $n = 0;
    foreach (wp_link_cells() as $c) $n += substr_count($c[3], '/wp-content/uploads/');
    return $n;
}

/** Copies the files and rewrites the addresses. Returns [fixed count, list of addresses not found]. */
function wp_links_fix(array $dirs, bool $download = false): array
{
    $imp = new WpImporter((string)cfg('site_url'), $download, $dirs);
    foreach (wp_link_cells() as [$table, $id, $col, $v]) {
        $whole = (bool)preg_match('~^\S+$~', trim($v)) && !str_contains($v, '<');
        $new = $imp->localizeUploads($v, $whole);
        if ($new === $v) continue;
        if ($table === 'settings') save_setting($col, $new);
        else q("UPDATE {$table} SET {$col} = ? WHERE id = ?", [$new, $id]);
    }
    if ($imp->fixed) content_changed();
    return [$imp->fixed, array_keys($imp->missing)];
}
