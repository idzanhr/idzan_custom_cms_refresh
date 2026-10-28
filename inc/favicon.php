<?php
declare(strict_types=1);

/**
 * Favicon set, generated from the one image chosen in Settings → Favicon:
 *   favicon.ico (16, 32, 48)        browser tabs, bookmarks, old browsers asking /favicon.ico
 *   icon-192.png, icon-512.png      Android / "Add to home screen" (via site.webmanifest)
 *   apple-touch-icon.png (180)      iPhone / iPad home screen (always square; iOS rounds it)
 *   site.webmanifest
 * Files live in the site root of public_dir and are rebuilt only when the image or shape changes.
 */

const FAVICON_FILES = ['favicon.ico', 'icon-192.png', 'icon-512.png', 'apple-touch-icon.png', 'site.webmanifest'];
const FAVICON_SHAPES = ['circle' => 'Circle', 'rounded' => 'Rounded square', 'square' => 'Square'];

function favicon_build(): void
{
    $out = public_dir();
    $src = s('favicon');
    $file = $src !== '' && !preg_match('~^https?://~i', $src) ? public_dir() . '/' . ltrim($src, '/') : '';
    if ($file === '' || !is_file($file) || !extension_loaded('gd')) {
        if (setting('favicon_stamp', '') !== '') {
            foreach (FAVICON_FILES as $f) @unlink($out . '/' . $f);
            save_setting('favicon_stamp', '');
        }
        return;
    }
    $shape = array_key_exists(s('favicon_shape'), FAVICON_SHAPES) ? s('favicon_shape') : 'circle';
    $bg = preg_match('~^#[0-9a-f]{6}$~i', s('color_primary')) ? s('color_primary') : '#1f2022';
    $stamp = md5($src . '|' . filemtime($file) . '|' . $shape . '|' . $bg . '|' . s('site_title') . '|v2');
    if (setting('favicon_stamp', '') === $stamp && is_file($out . '/favicon.ico')) return;

    $img = @imagecreatefromstring((string)file_get_contents($file));
    if (!$img) return;
    // Centre square crop.
    $w = imagesx($img); $h = imagesy($img); $side = min($w, $h);
    $sq = imagecreatetruecolor($side, $side);
    imagealphablending($sq, false); imagesavealpha($sq, true);
    imagecopy($sq, $img, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), $side, $side);

    $png = function (int $size, string $mask) use ($sq, $side): string {
        $im = imagecreatetruecolor($size, $size);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagecopyresampled($im, $sq, 0, 0, 0, 0, $size, $size, $side, $side);
        if ($mask !== 'square') favicon_mask($im, $size, $mask);
        ob_start(); imagepng($im, null, 9); return (string)ob_get_clean();
    };
    $sizes = [];
    foreach ([16, 32, 48] as $s) $sizes[$s] = $png($s, $shape);
    write_atomic($out . '/favicon.ico', favicon_ico($sizes));
    write_atomic($out . '/icon-192.png', $png(192, $shape));
    write_atomic($out . '/icon-512.png', $png(512, $shape));

    // Apple: no transparency (iOS would fill it black), so the square photo on the site background.
    $apple = imagecreatetruecolor(180, 180);
    [$r, $g, $b] = sscanf($bg, '#%02x%02x%02x');
    imagefill($apple, 0, 0, imagecolorallocate($apple, $r, $g, $b));
    imagealphablending($apple, true);
    imagecopyresampled($apple, $sq, 0, 0, 0, 0, 180, 180, $side, $side);
    ob_start(); imagepng($apple, null, 9);
    write_atomic($out . '/apple-touch-icon.png', (string)ob_get_clean());

    $name = s('site_title') ?: 'Website';
    write_atomic($out . '/site.webmanifest', json_encode([
        'name' => $name, 'short_name' => mb_strimwidth($name, 0, 12, ''),
        'icons' => [
            ['src' => url('/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => url('/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
        ],
        'theme_color' => $bg, 'background_color' => $bg, 'display' => 'browser', 'start_url' => url('/'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    save_setting('favicon_stamp', $stamp);
}

/** Transparent corners with a soft (anti-aliased) edge: a circle or a rounded square. */
function favicon_mask(GdImage $im, int $size, string $shape): void
{
    $r = $shape === 'circle' ? $size / 2 : $size * 0.22;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            // Distance outside the shape, in pixels (negative = inside).
            $px = $x + 0.5; $py = $y + 0.5;
            $cx = min(max($px, $r), $size - $r); $cy = min(max($py, $r), $size - $r);
            $d = sqrt(($px - $cx) ** 2 + ($py - $cy) ** 2) - $r;
            if ($d <= -0.5) continue;
            $keep = $d >= 0.5 ? 0.0 : 0.5 - $d;
            $c = imagecolorat($im, $x, $y);
            $a = ($c >> 24) & 0x7F;
            $alpha = (int)round(127 - (127 - $a) * $keep);
            imagesetpixel($im, $x, $y, ($alpha << 24) | ($c & 0xFFFFFF));
        }
    }
}

/** .ico container holding PNG images (supported by every current browser). */
function favicon_ico(array $pngs): string
{
    $head = pack('vvv', 0, 1, count($pngs));
    $dir = ''; $data = '';
    $offset = 6 + 16 * count($pngs);
    foreach ($pngs as $size => $bin) {
        $dir .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($bin), $offset + strlen($data));
        $data .= $bin;
    }
    return $head . $dir . $data;
}

/** <head> tags for whatever favicon files exist. */
function favicon_tags(): string
{
    $out = public_dir();
    $t = '';
    if (is_file($out . '/favicon.ico')) {
        $t .= '<link rel="icon" href="' . e(url('/favicon.ico')) . '" sizes="48x48">' . "\n"
            . '<link rel="icon" href="' . e(url('/icon-192.png')) . '" type="image/png" sizes="192x192">' . "\n"
            . '<link rel="apple-touch-icon" href="' . e(url('/apple-touch-icon.png')) . '">' . "\n"
            . '<link rel="manifest" href="' . e(url('/site.webmanifest')) . '">' . "\n";
    } elseif (s('favicon')) {
        $t .= '<link rel="icon" href="' . e(media_url(s('favicon'))) . '">' . "\n";
    }
    $bg = preg_match('~^#[0-9a-f]{6}$~i', s('color_primary')) ? s('color_primary') : '#1f2022';
    return $t . '<meta name="theme-color" content="' . e($bg) . '">' . "\n";
}
