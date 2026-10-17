<?php
/**
 * Page-speed helpers (Lighthouse / PageSpeed Insights):
 *  - the tiny light/dark script is inlined in <head> and allowed by its CSP hash (no render-blocking request);
 *  - the brand fonts get font-display: swap, a metric-matched fallback (no layout jump) and preload links;
 *  - content images get WebP size variants + srcset/sizes, and the page's main (LCP) image is preloaded.
 */
declare(strict_types=1);

/** The light/dark script exactly as it is inlined (the part after the INLINE marker). */
function theme_inline_js(): string
{
    static $js = null;
    if ($js === null) {
        $src = (string)@file_get_contents(ROOT . '/assets/js/theme.js');
        $p = strpos($src, '/*!INLINE*/');
        $js = trim($p === false ? $src : substr($src, $p + 11));
    }
    return $js;
}

/** CSP source for the inline script: 'sha256-…'. The vhost's Content-Security-Policy must carry the same value. */
function theme_js_hash(): string
{
    return "'sha256-" . base64_encode(hash('sha256', theme_inline_js(), true)) . "'";
}

function csp_public(): string
{
    return "default-src 'self'; img-src 'self' data: https:; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; "
        . "style-src 'self'; script-src 'self' " . theme_js_hash() . "; font-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none'";
}

/* ---------------- fonts ---------------- */

/** Metric overrides that make Arial take the same space as Roboto, so swapping in the brand font doesn't move text. */
const ROBOTO_FALLBACK_FACE = '@font-face{font-family:"Roboto Fallback";src:local("Arial"),local("ArialMT"),local("Liberation Sans"),local("Helvetica");'
    . 'ascent-override:92.77%;descent-override:24.41%;line-gap-override:0%;size-adjust:100.3%}';

/** The imported @font-face rules with text shown straight away in the fallback font (font-display: swap). The font files are untouched. */
function fonts_swap(string $faces): string
{
    $faces = preg_replace('~font-display\s*:\s*(auto|block|fallback|optional)\s*;?~i', 'font-display: swap;', $faces);
    // rules that didn't say anything get it too
    return preg_replace_callback('~@font-face\s*\{([^{}]*)\}~', fn($m) => stripos($m[1], 'font-display') === false
        ? '@font-face {' . rtrim($m[1]) . "\n\tfont-display: swap;\n}" : $m[0], $faces);
}

/** woff2 files of the text (400, 500) and heading (700) weights, for <link rel="preload">. */
function font_preload_urls(): array
{
    $fams = array_unique([mb_strtolower(trim(s('font_body'))), mb_strtolower(trim(s('font_heading')))]);
    $urls = [];
    if (!preg_match_all('~@font-face\s*\{([^{}]*)\}~', s('fonts_css'), $m)) return [];
    foreach ($m[1] as $rule) {
        if (!preg_match('~font-family\s*:\s*[\'"]?([^\'";]+)~i', $rule, $f) || !in_array(mb_strtolower(trim($f[1])), $fams, true)) continue;
        if (preg_match('~font-style\s*:\s*(italic|oblique)~i', $rule)) continue;
        $w = preg_match('~font-weight\s*:\s*(\d+)~i', $rule, $wm) ? (int)$wm[1] : 400;
        if (!in_array($w, [400, 500, 700], true)) continue;
        if (preg_match('~url\(\s*[\'"]?(/assets/fonts/[^\'")\s?#]+\.woff2)~i', $rule, $u)) $urls[$w] = $u[1];
    }
    ksort($urls);
    return array_values($urls);
}

function font_preload_tags(): string
{
    $h = '';
    foreach (font_preload_urls() as $u) $h .= '<link rel="preload" href="' . e(url($u)) . '" as="font" type="font/woff2" crossorigin>' . "\n";
    return $h;
}

/* ---------------- images ---------------- */

/* Content images (WebP/AVIF copies, sizes, <picture>) live in inc/images.php. */

/* ---------------- share image (Open Graph / X) ---------------- */

const OG_W = 1200;
const OG_H = 630;

/**
 * The image used when a page is shared: 1200×630 JPEG, the size Facebook, LinkedIn, X, WhatsApp and Telegram show
 * as a large preview. Wide images are cropped to fill it; square or tall ones (like a profile photo) are placed
 * whole on the brand background, so nothing important is cut off. Returns [absolute url, width|null, height|null].
 */
function og_image(string $path): array
{
    if ($path === '') return ['', null, null];
    $rel = ltrim(trim($path), '/');
    $abs = preg_match('~^uploads/[^\0]+\.(jpe?g|png|webp)$~i', $rel) && !str_contains($rel, '..') ? public_dir() . '/' . $rel : '';
    $fallback = media_url($path);
    if ($fallback !== '' && $fallback[0] === '/') $fallback = abs_url($fallback);
    if ($abs === '' || !is_file($abs) || !extension_loaded('gd')) return [$fallback, null, null];
    $info = @getimagesize($abs);
    if (!$info || $info[0] < 200) return [$fallback, $info[0] ?? null, $info[1] ?? null];
    $bg = preg_match('~^#([0-9a-f]{6})$~i', s('color_primary'), $m) ? $m[1] : '1F2022';
    $name = 'og-' . substr(md5($rel . '|' . filesize($abs) . '|' . filemtime($abs) . '|' . $bg), 0, 12) . '.jpg';
    $file = public_dir() . '/_img/' . $name;
    if (!is_file($file)) {
        $load = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$info['mime'] ?? ''] ?? null;
        $src = $load && function_exists($load) ? @$load($abs) : null;
        if (!$src) return [$fallback, $info[0], $info[1]];
        [$w, $h] = [imagesx($src), imagesy($src)];
        $dst = imagecreatetruecolor(OG_W, OG_H);
        imagefill($dst, 0, 0, imagecolorallocate($dst, hexdec(substr($bg, 0, 2)), hexdec(substr($bg, 2, 2)), hexdec(substr($bg, 4, 2))));
        if ($w / $h >= 1.3) {                         // wide: crop to fill, keep the centre
            $scale = max(OG_W / $w, OG_H / $h);
            $cw = (int)round(OG_W / $scale); $ch = (int)round(OG_H / $scale);
            imagecopyresampled($dst, $src, 0, 0, (int)(($w - $cw) / 2), (int)(($h - $ch) / 2), OG_W, OG_H, $cw, $ch);
        } else {                                      // square or tall: whole picture, centred
            $scale = min(OG_W / $w, OG_H / $h);
            $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
            imagecopyresampled($dst, $src, (int)((OG_W - $nw) / 2), (int)((OG_H - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        }
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
        if (!imagejpeg($dst, $file . '.tmp', 85) || !rename($file . '.tmp', $file)) return [$fallback, $info[0], $info[1]];
    }
    return [abs_url(url('/_img/' . $name)), OG_W, OG_H];
}

/** <script type="application/ld+json"> (structured data for search engines; not executed, so the CSP doesn't apply). */
function jsonld(array $data): string
{
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
}
