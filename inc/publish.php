<?php
declare(strict_types=1);

/**
 * Publishing. Two ways to run the public site:
 *
 *  - static (recommended on nginx): every change in the CMS rebuilds plain HTML files into
 *    'public_dir', and nginx serves that folder directly. No PHP runs for visitors.
 *  - dynamic: index.php renders pages on each request.
 * Either way, Varnish (if configured) is purged after each change.
 */

function static_mode(): bool
{
    return (bool)cfg('static', false);
}

/** Folder the web server serves. Uploads live inside it. */
function public_dir(): string
{
    return rtrim((string)cfg('public_dir', ROOT), '/');
}

/** Call after anything that changes what visitors see. */
function content_changed(): void
{
    if (!static_mode()) { varnish_purge(); bunny_purge(); return; }
    try {
        require_once ROOT . '/inc/render.php';
        rebuild_static();
        varnish_purge();
        bunny_purge();
        indexnow_submit();   // after the caches are clear, so search engines read the new version
    } catch (Throwable $e) {
        error_log('[cms] static rebuild failed: ' . $e->getMessage());
        if (function_exists('flash')) flash('Saved, but the public site could not be rebuilt: ' . $e->getMessage(), 'err');
    }
}

function write_atomic(string $file, string $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create ' . $dir);
    $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $data) === false) throw new RuntimeException('Cannot write ' . $file);
    @chmod($tmp, 0644);
    rename($tmp, $file);
}

/** Copy a folder's files into the public folder (only when changed). */
function sync_tree(string $src, string $dst): void
{
    if (!is_dir($src)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getFilename() === '.htaccess') continue;
        $target = $dst . '/' . substr($f->getPathname(), strlen($src) + 1);
        if (is_file($target) && filesize($target) === $f->getSize() && filemtime($target) >= $f->getMTime()) continue;
        write_atomic($target, (string)file_get_contents($f->getPathname()));
    }
}

/**
 * Regenerate every public page into public_dir. Pages that no longer exist are removed.
 * Returns the number of files written.
 */
function rebuild_static(): int
{
    $out = public_dir();
    if ($out === ROOT) throw new RuntimeException('Set public_dir in config.php to a separate folder (e.g. ' . ROOT . '/public) for static mode.');
    run_migrations();   // a scheduled build right after an update must not wait for someone to open the admin
    $GLOBALS['STATIC_EXPORT'] = true;
    if (!is_dir(ROOT . '/storage')) @mkdir(ROOT . '/storage', 0775, true);
    $lock = fopen(ROOT . '/storage/.build.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);

    try { favicon_build(); } catch (Throwable $e) { error_log('favicon: ' . $e->getMessage()); }
    $written = [];
    $paths = all_public_paths();
    $GLOBALS['TR_RESET'] = true;
    // English at the root, then a full copy per switched-on language under /hr/, /bs/, /sr/, /sl/.
    try {
        foreach (array_merge(['en'], enabled_langs()) as $l) {
            $GLOBALS['LANG'] = $l;
            foreach ($paths as $path) {
                if ($l !== 'en' && !is_page_path($path)) continue;
                $GLOBALS['BASE_PATH'] = $path;
                [$status, $body] = route($path);
                if ($status !== 200) continue;
                if ($l !== 'en') $body = lang_rewrite_links($body, $l);
                $file = ($l === 'en' ? '' : $l . '/') . ltrim($path, '/');
                if ($file === '' || str_ends_with($file, '/')) $file .= 'index.html';
                write_atomic($out . '/' . $file, $body);
                $written[$file] = true;
            }
        }
    } finally {
        unset($GLOBALS['LANG'], $GLOBALS['BASE_PATH']);
    }
    $GLOBALS['BASE_PATH'] = '/';
    [, $nf] = not_found();
    unset($GLOBALS['BASE_PATH']);
    write_atomic($out . '/404.html', $nf);
    $written['404.html'] = true;
    @unlink($out . '/search-index.json'); // left over from a version with a 404 search box
    indexnow_write_key($out);
    foreach (['css', 'js', 'admin'] as $d) sync_tree(ROOT . '/assets/' . $d, $out . '/assets/' . $d);

    // Remove pages that were deleted or unpublished since the last build.
    $manifest = ROOT . '/storage/static-manifest.json';
    $old = is_file($manifest) ? (json_decode((string)file_get_contents($manifest), true) ?: []) : [];
    foreach (array_keys($old) as $f) {
        if (isset($written[$f]) || !str_ends_with($f, '.html') || str_contains($f, '..')) continue;
        @unlink($out . '/' . $f);
        $dir = dirname($out . '/' . $f);
        if ($dir !== $out && is_dir($dir) && count(scandir($dir)) === 2) @rmdir($dir);
    }
    // A language that was switched off: remove its whole folder (only generated pages live there).
    foreach (array_diff(array_keys(LANGS), enabled_langs()) as $l) {
        $dir = $out . '/' . $l;
        if (!is_dir($dir) || is_link($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { if ($f->isDir()) @rmdir($f->getPathname()); elseif (str_ends_with($f->getFilename(), '.html')) @unlink($f->getPathname()); }
        @rmdir($dir);
    }
    write_atomic($manifest, json_encode($written));
    try { img_cleanup(array_filter(array_keys($written), fn($f) => str_ends_with($f, '.html'))); } catch (Throwable $e) { error_log('[cms] image cleanup: ' . $e->getMessage()); }
    save_setting('last_build', now());
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return count($written);
}
