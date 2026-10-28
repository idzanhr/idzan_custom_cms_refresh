<?php
/**
 * Modern image formats. Every uploaded JPG/PNG/WebP gets AVIF and WebP copies (full size at upload, smaller sizes
 * during builds) in public/_img/. Pages offer them with <picture>:
 *     <picture><source type="image/avif" srcset="…"><source type="image/webp" srcset="…"><img src="original.jpg"></picture>
 * The browser takes the first format it supports; anything older gets the original file. GIFs (animation) and
 * SVGs are left as they are. AVIF needs GD with AVIF support or Imagick with AVIF; without either only WebP is made.
 */
declare(strict_types=1);

const IMG_WIDTHS = [240, 360, 480, 720, 960, 1280];     // header, card and portrait images
const IMG_CONTENT_WIDTHS = [480, 960, 1600];            // images in page text
const IMG_CONTENT_SIZES = '(max-width: 760px) calc(100vw - 32px), 760px';
const IMG_QUALITY = ['avif' => 52, 'webp' => 80];
const IMG_FULL_MAX = 2560;   // the largest copy made; bigger originals (old WordPress photos) aren't copied at full size

/** Widths to make for an image $w0 wide: the requested ones that are clearly smaller, plus the largest (≤ IMG_FULL_MAX). */
function img_widths(int $w0, array $widths): array
{
    $top = min($w0, (int)cfg('image_full_max', IMG_FULL_MAX));
    $want = array_values(array_filter($widths, fn($w) => $w < $top * 0.9));
    $want[] = $top;
    return $want;
}

/* ---- images that can't be converted on this server (too large, broken, PHP stopped) ---- */

function img_failed_file(): string { return ROOT . '/storage/img-failed.json'; }

/** ['uploads/…' => 'reason'] — skipped by builds and by "Make the missing copies now" until retried. */
function img_failed(): array
{
    if (!array_key_exists('IMG_FAILED', $GLOBALS)) {
        $GLOBALS['IMG_FAILED'] = is_file(img_failed_file()) ? (json_decode((string)@file_get_contents(img_failed_file()), true) ?: []) : [];
        // A request that died in the middle of an image (killed by the server) leaves this note behind.
        $w = ROOT . '/storage/img-working.txt';
        if (is_file($w) && filemtime($w) < time() - 600 && ($rel = trim((string)@file_get_contents($w))) !== '') {
            $GLOBALS['IMG_FAILED'][$rel] = 'PHP stopped while converting it';
            @unlink($w);
            @file_put_contents(img_failed_file(), json_encode($GLOBALS['IMG_FAILED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
    return $GLOBALS['IMG_FAILED'];
}

function img_fail(string $rel, string $why): void
{
    img_failed();
    $GLOBALS['IMG_FAILED'][$rel] = $why;
    @file_put_contents(img_failed_file(), json_encode($GLOBALS['IMG_FAILED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    error_log('[cms] image skipped (' . $rel . '): ' . $why);
}

/** "Try skipped images again". */
function img_failed_reset(): void
{
    @unlink(img_failed_file());
    $GLOBALS['IMG_FAILED'] = [];
}

/**
 * Marks the image being converted. If PHP dies (memory or time limit), the shutdown handler records it as
 * skipped, so the next round or build moves on instead of dying on the same picture again.
 */
function img_working(?string $rel): void
{
    $w = ROOT . '/storage/img-working.txt';
    $GLOBALS['IMG_WORKING'] = $rel;
    if ($rel === null) { @unlink($w); return; }
    @file_put_contents($w, $rel);
    if (empty($GLOBALS['IMG_SHUTDOWN'])) {
        $GLOBALS['IMG_SHUTDOWN'] = true;
        register_shutdown_function(function () {
            $rel = $GLOBALS['IMG_WORKING'] ?? null;
            if ($rel === null) return;
            $err = error_get_last();
            $why = $err && str_contains((string)$err['message'], 'memory') ? 'too large for PHP memory'
                 : ($err && str_contains((string)$err['message'], 'execution time') ? 'took longer than PHP allows' : 'PHP stopped while converting it');
            img_fail($rel, $why);
            @unlink(ROOT . '/storage/img-working.txt');
        });
    }
}

/** Makes room in PHP memory for an image of $w×$h (GD needs about 5 bytes a pixel, plus the scaled copy). */
function img_memory_ok(int $w, int $h): bool
{
    $need = (int)($w * $h * 5 * 1.6) + memory_get_usage() + 32 * 1048576;
    $lim = img_bytes((string)ini_get('memory_limit'));
    if ($lim < 0 || $need <= $lim) return true;
    if ($need <= 1024 * 1048576 && @ini_set('memory_limit', (string)(int)ceil($need / 1048576) . 'M') !== false) return true;
    return false;
}

function img_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return -1;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/** How each format is encoded on this server: ['avif' => 'GD'|'Imagick'|'', 'webp' => …]. */
function img_encoders(): array
{
    static $enc = null;
    if ($enc !== null) return $enc;
    $im = [];
    if (extension_loaded('imagick')) { try { $im = array_map('strtoupper', \Imagick::queryFormats()); } catch (Throwable $e) {} }
    $enc = [
        'avif' => function_exists('imageavif') ? 'GD' : (in_array('AVIF', $im, true) ? 'Imagick' : ''),
        'webp' => function_exists('imagewebp') ? 'GD' : (in_array('WEBP', $im, true) ? 'Imagick' : ''),
    ];
    if (cfg('image_formats') !== null) foreach (['avif', 'webp'] as $f) if (!in_array($f, (array)cfg('image_formats'), true)) $enc[$f] = '';
    return $enc;
}

/** Formats this server can make, best first. */
function img_formats(): array
{
    return array_keys(array_filter(img_encoders()));
}

/** How long one build may spend making copies; the rest are made on the next builds (or with Media → Convert). */
function img_budget_left(): bool
{
    $GLOBALS['IMG_T0'] ??= microtime(true);
    return microtime(true) - $GLOBALS['IMG_T0'] < (float)cfg('image_variant_seconds', 20);
}

/** 'uploads/…' path of a local JPG/PNG/WebP upload, or '' (remote, GIF, SVG, missing…). */
function img_local(string $path): string
{
    $rel = function_exists('media_key') ? media_key($path) : ltrim(trim($path), '/');
    if ($rel === '' || !preg_match('~\.(jpe?g|png|webp)$~i', $rel) || str_contains($rel, '..')) return '';
    return is_file(public_dir() . '/' . $rel) ? $rel : '';
}

function img_key(string $rel): string
{
    $abs = public_dir() . '/' . $rel;
    return substr(md5($rel . '|' . filesize($abs) . '|' . filemtime($abs)), 0, 12);
}

/** Reads an image into GD (or null). */
function img_load(string $abs, string $mime)
{
    $load = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$mime] ?? null;
    if (!$load || !function_exists($load)) return null;
    $im = @$load($abs);
    if (!$im) return null;
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    return $im;
}

/** Writes $im (scaled to $w wide) as AVIF or WebP. */
function img_encode($im, int $w, string $fmt, string $file): bool
{
    $enc = img_encoders()[$fmt] ?? '';
    if ($enc === '') return false;
    $sw = imagesx($im); $sh = imagesy($im);
    $dst = $im;
    if ($w < $sw) {
        $h = max(1, (int)round($sh * $w / $sw));
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $w, $h, $sw, $sh);
    }
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    $tmp = $file . '.tmp';
    $ok = false;
    try {
        if ($enc === 'GD') {
            $ok = $fmt === 'avif' ? imageavif($dst, $tmp, IMG_QUALITY['avif'], 8) : imagewebp($dst, $tmp, IMG_QUALITY['webp']);
        } else {                                                  // Imagick: hand over a lossless PNG
            ob_start(); imagepng($dst, null, 1); $png = (string)ob_get_clean();
            $m = new \Imagick();
            $m->readImageBlob($png);
            $m->setImageFormat($fmt);
            $m->setImageCompressionQuality(IMG_QUALITY[$fmt]);
            if ($fmt === 'avif') { $m->setOption('heic:speed', '8'); $m->setOption('avif:speed', '8'); }   // fast encoder setting (default is very slow)
            $m->stripImage();
            $ok = (bool)file_put_contents($tmp, $m->getImageBlob());
            $m->clear();
        }
    } catch (Throwable $e) { error_log('[cms] ' . $fmt . ' encode failed: ' . $e->getMessage()); $ok = false; }
    if (!$ok || !is_file($tmp) || filesize($tmp) === 0) { @unlink($tmp); return false; }
    return rename($tmp, $file);
}

/**
 * Sources for an uploaded image: ['w' => , 'h' => , 'orig' => url, 'avif' => [width => url], 'webp' => [width => url]].
 * Missing copies are made while the build's time budget lasts ($force: always, e.g. right after upload).
 * Returns null for images that aren't local JPG/PNG/WebP uploads.
 */
function img_sources(string $path, array $widths, bool $force = false): ?array
{
    $rel = img_local($path);
    if ($rel === '') return null;
    $abs = public_dir() . '/' . $rel;
    $info = @getimagesize($abs);
    if (!$info || $info[0] < 1) return null;
    [$w0, $h0] = $info;
    $GLOBALS['IMG_DIMS'][$rel] = [$w0, $h0];
    $key = img_key($rel);
    $isWebp = ($info['mime'] ?? '') === 'image/webp';
    $want = img_widths($w0, $widths);
    $out = ['w' => $w0, 'h' => $h0, 'orig' => url('/' . $rel), 'avif' => [], 'webp' => []];
    $im = null;
    $skip = !$force && isset(img_failed()[$rel]);    // couldn't be converted before: use what exists, make nothing
    $tried = false;
    foreach (img_formats() as $fmt) {
        foreach ($want as $w) {
            if ($fmt === 'webp' && $isWebp && $w === $w0) { $out['webp'][$w] = url('/' . $rel); continue; }   // the original already is WebP
            $name = $key . '-' . $w . '.' . $fmt;
            $file = public_dir() . '/_img/' . $name;
            if (!is_file($file)) {
                if ($skip || (!$force && !img_budget_left())) continue;
                if (!$tried) {
                    $tried = true;
                    if (!img_memory_ok($w0, $h0)) { img_fail($rel, "too large for PHP memory ({$w0}×{$h0})"); $skip = true; continue; }
                    img_working($rel);
                    $im = img_load($abs, (string)($info['mime'] ?? ''));
                    if (!$im) { img_working(null); img_fail($rel, 'the file could not be read'); $skip = true; continue; }
                }
                if (!$im) continue;
                if (!img_encode($im, $w, $fmt, $file)) { img_working(null); img_fail($rel, strtoupper($fmt) . ' could not be written'); $skip = true; continue; }
                $GLOBALS['IMG_MADE'] = ($GLOBALS['IMG_MADE'] ?? 0) + 1;
            }
            $out[$fmt][$w] = url('/_img/' . $name);
        }
    }
    if ($tried) img_working(null);
    return $out;
}

/** Right after an upload: full-size AVIF and WebP copies (the smaller sizes follow during builds). */
function img_convert_upload(string $rel): void
{
    try {
        $info = @getimagesize(public_dir() . '/' . $rel);
        if ($info) img_sources($rel, [], true);
    } catch (Throwable $e) { error_log('[cms] image conversion failed for ' . $rel . ': ' . $e->getMessage()); }
}

/** srcset text from [width => url]. */
function img_srcset(array $set): string
{
    ksort($set);
    return implode(', ', array_map(fn($w, $u) => $u . ' ' . $w . 'w', array_keys($set), $set));
}

/**
 * <picture> with AVIF and WebP sources and the original as fallback (or a plain <img> when there's nothing to offer).
 * $attrs: attributes of the <img> (already plain values; they are escaped here).
 */
function picture_html(?array $src, array $attrs, string $sizes): string
{
    $img = '<img';
    foreach ($attrs as $k => $v) $img .= ' ' . $k . '="' . e((string)$v) . '"';
    $img .= '>';
    if (!$src || (!$src['avif'] && !$src['webp'])) return $img;
    $h = '<picture>';
    foreach (['avif', 'webp'] as $fmt) if ($src[$fmt]) $h .= '<source type="image/' . $fmt . '" srcset="' . e(img_srcset($src[$fmt])) . '" sizes="' . e($sizes) . '">';
    return $h . $img . '</picture>';
}

/**
 * Header, card and portrait images. $lcp marks the page's main image: it loads first and is preloaded from <head>.
 * $attrs are extra <img> attributes (e.g. ['class' => 'post-hero-img']).
 */
function responsive_img(string $path, string $alt, string $sizes, array $attrs = [], bool $lcp = false): string
{
    $src = img_sources($path, IMG_WIDTHS);
    $a = ['src' => $src['orig'] ?? media_url($path), 'alt' => $alt];
    if ($src && !isset($attrs['width'])) { $a['width'] = $src['w']; $a['height'] = $src['h']; }
    $a += $attrs;
    if ($lcp) {
        $a['fetchpriority'] = 'high';
        unset($a['loading']);
        $best = $src ? ($src['avif'] ? 'avif' : ($src['webp'] ? 'webp' : '')) : '';
        $GLOBALS['LCP_IMG'] = ['href' => $a['src'], 'srcset' => $best ? img_srcset($src[$best]) : '', 'sizes' => $sizes, 'type' => $best ? 'image/' . $best : ''];
    } else {
        $a += ['loading' => 'lazy', 'decoding' => 'async'];
    }
    return picture_html($src, $a, $sizes);
}

/** <link rel="preload"> for the main image of the page being built (set by responsive_img(..., lcp: true)). */
function lcp_preload_tag(): string
{
    $a = $GLOBALS['LCP_IMG'] ?? null;
    unset($GLOBALS['LCP_IMG']);
    if (!$a) return '';
    // With type="image/avif", browsers without AVIF skip the preload instead of fetching the wrong file.
    return '<link rel="preload" as="image" href="' . e($a['href']) . '"'
        . ($a['srcset'] !== '' ? ' imagesrcset="' . e($a['srcset']) . '" imagesizes="' . e($a['sizes']) . '"' : '')
        . ($a['type'] !== '' ? ' type="' . e($a['type']) . '"' : '')
        . ' fetchpriority="high">' . "\n";
}

/** Images in page text: offered as AVIF/WebP in a few sizes, with intrinsic width/height (no layout jumps). */
function modern_imgs(string $html): string
{
    if (!str_contains($html, '<img')) return $html;
    return (string)preg_replace_callback('~<picture\b.*?</picture>|<img\b[^>]*>~is', function ($m) {
        $tag = $m[0];
        if (stripos($tag, '<picture') === 0) return $tag;
        if (!preg_match('~\ssrc\s*=\s*"([^"]+)"~i', $tag, $s)) return $tag;
        $src = img_sources(html_entity_decode($s[1], ENT_QUOTES), IMG_CONTENT_WIDTHS);
        if (!$src) return $tag;
        if (!preg_match('~\swidth\s*=~i', $tag)) $tag = preg_replace('~^<img\b~i', '<img width="' . $src['w'] . '" height="' . $src['h'] . '"', $tag);
        if (!preg_match('~\sloading\s*=~i', $tag)) $tag = preg_replace('~^<img\b~i', '<img loading="lazy" decoding="async"', $tag);
        if (!$src['avif'] && !$src['webp']) return $tag;
        $h = '<picture>';
        foreach (['avif', 'webp'] as $fmt) if ($src[$fmt]) $h .= '<source type="image/' . $fmt . '" srcset="' . e(img_srcset($src[$fmt])) . '" sizes="' . IMG_CONTENT_SIZES . '">';
        return $h . $tag . '</picture>';
    }, $html);
}

/**
 * Media → "Make WebP/AVIF copies now": works through every uploaded image for up to $seconds.
 * Returns [images complete, images in total, copies made in this round, ['uploads/…' => why skipped]].
 */
function img_convert_all(float $seconds): array
{
    require_once ROOT . '/inc/medialib.php';
    $GLOBALS['IMG_T0'] = microtime(true) - (float)cfg('image_variant_seconds', 20) + $seconds;
    $done = 0; $total = 0; $skipped = []; $GLOBALS['IMG_MADE'] = 0;
    foreach (array_keys(media_files()) as $rel) {
        if (!preg_match('~\.(jpe?g|png|webp)$~i', $rel)) continue;
        $total++;
        img_sources($rel, IMG_WIDTHS);
        img_sources($rel, IMG_CONTENT_WIDTHS);
        if (img_complete($rel)) $done++;
        elseif (isset(img_failed()[$rel])) $skipped[$rel] = img_failed()[$rel];
    }
    return [$done, $total, (int)$GLOBALS['IMG_MADE'], $skipped];
}

/** True when every copy of an image exists (all formats, all sizes). */
function img_complete(string $rel): bool
{
    $abs = public_dir() . '/' . $rel;
    $info = @getimagesize($abs);
    if (!$info) return true;
    $key = img_key($rel);
    $ws = array_unique(array_merge(img_widths($info[0], IMG_WIDTHS), img_widths($info[0], IMG_CONTENT_WIDTHS)));
    foreach (img_formats() as $fmt) foreach ($ws as $w) {
        if ($fmt === 'webp' && ($info['mime'] ?? '') === 'image/webp' && $w === $info[0]) continue;
        if (!is_file(public_dir() . '/_img/' . $key . '-' . $w . '.' . $fmt)) return false;
    }
    return true;
}

/** After a build: delete copies in public/_img/ that no page uses any more (renamed, replaced or deleted images). */
function img_cleanup(array $htmlFiles): int
{
    $dir = public_dir() . '/_img';
    if (!is_dir($dir)) return 0;
    $used = [];
    foreach ($htmlFiles as $f) {
        $html = @file_get_contents(public_dir() . '/' . $f);
        if ($html && preg_match_all('~/_img/([A-Za-z0-9._-]+)~', $html, $m)) foreach ($m[1] as $n) $used[$n] = true;
    }
    // Keep copies of every current upload too (a page may use them again on the next build).
    require_once ROOT . '/inc/medialib.php';
    $keys = [];
    foreach (array_keys(media_files()) as $rel) $keys[img_key($rel)] = true;
    $n = 0;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f[0] === '.' || isset($used[$f])) continue;
        $k = substr($f, 0, 12);
        if (isset($keys[$k])) continue;
        if (filemtime($dir . '/' . $f) > time() - 3600) continue;      // just made: leave it
        if (@unlink($dir . '/' . $f)) $n++;
    }
    return $n;
}
