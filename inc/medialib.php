<?php
declare(strict_types=1);

/**
 * Media library helpers: every image under public/uploads, newest first, and where each one is used.
 * Files are the source of truth (no extra database table): alt text lives with each use, as before.
 */

const MEDIA_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

/** All image files as 'uploads/…' paths with their modification time, newest first. */
function media_files(): array
{
    $dir = public_dir() . '/uploads';
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile() || !in_array(strtolower($f->getExtension()), MEDIA_EXT, true)) continue;
        $out['uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1))] = $f->getMTime();
    }
    arsort($out);
    return $out;
}

/** Everything that can point at an image: [label, admin link, text to search]. */
function media_usage_index(): array
{
    $idx = [];
    foreach (entities() as $key => $ent) {
        $tf = $ent['title_field'] ?? 'title';
        try { $rows = all("SELECT * FROM {$key}"); } catch (Throwable $e) { continue; }
        foreach ($rows as $r) {
            $idx[] = [$ent['singular'] . ': ' . ($r[$tf] ?? '#' . $r['id']), admin_url('edit.php', ['e' => $key, 'id' => $r['id']]), implode("\n", array_map('strval', $r))];
        }
    }
    $settings = '';
    foreach (all('SELECT v FROM settings') as $r) $settings .= $r['v'] . "\n";
    $idx[] = ['Site settings', admin_url('settings.php'), $settings];
    return $idx;
}

/** Where an 'uploads/…' file is used: list of [label, link]. */
function media_used_in(string $rel, array $index): array
{
    $hits = [];
    foreach ($index as [$label, $link, $blob]) if (str_contains($blob, $rel)) $hits[] = [$label, $link];
    return $hits;
}

/** Absolute file path for an 'uploads/…' value, only when it really is inside the uploads folder. */
function media_abs(string $rel): ?string
{
    if (!preg_match('~^uploads/[^\0]+$~', $rel) || str_contains($rel, '..')) return null;
    $base = realpath(public_dir() . '/uploads');
    $abs = realpath(public_dir() . '/' . $rel);
    return ($base && $abs && str_starts_with($abs, $base . DIRECTORY_SEPARATOR) && is_file($abs)) ? $abs : null;
}
