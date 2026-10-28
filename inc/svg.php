<?php
declare(strict_types=1);

/**
 * SVG icons, kept safe.
 *
 * - Every SVG (uploaded icon or inline in content) passes a strict whitelist: shapes, gradients
 *   and styling attributes only. Scripts, event handlers, external links, <foreignObject>,
 *   <use>/<image> references and CSS are removed, so an icon can't run code or load anything.
 * - Icon files live in storage/icons/ — outside the public web root — and are inlined into the
 *   generated pages. The licensed files themselves are never published as downloadable files.
 */

const SVG_ELEMENTS = ['svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon',
    'lineargradient', 'radialgradient', 'stop', 'defs', 'clippath', 'mask', 'title', 'desc', 'symbol'];
const SVG_ATTRS = ['viewbox', 'width', 'height', 'xmlns', 'fill', 'stroke', 'stroke-width', 'stroke-linecap',
    'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'd', 'cx', 'cy', 'r', 'rx', 'ry',
    'x', 'y', 'x1', 'x2', 'y1', 'y2', 'fx', 'fy', 'points', 'transform', 'opacity', 'fill-opacity', 'stroke-opacity',
    'fill-rule', 'clip-rule', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform',
    'spreadmethod', 'id', 'clip-path', 'mask', 'maskunits', 'clippathunits', 'preserveaspectratio', 'aria-hidden', 'role', 'focusable'];

/** Clean an SVG string. Returns null if it isn't a usable SVG. $idPrefix keeps gradient IDs unique per page. */
function svg_sanitize(string $svg, string $idPrefix = ''): ?string
{
    $svg = trim(preg_replace('~<\?xml.*?\?>|<!DOCTYPE.*?>|<!--.*?-->~is', '', $svg));
    if ($svg === '' || strlen($svg) > 500000 || stripos($svg, '<!ENTITY') !== false) return null;
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || !$doc->documentElement || strtolower($doc->documentElement->localName) !== 'svg') return null;

    $ids = [];
    $walk = function (DOMElement $el) use (&$walk, &$ids, $idPrefix) {
        foreach (iterator_to_array($el->childNodes) as $c) {
            if ($c instanceof DOMElement) {
                if (!in_array(strtolower($c->localName), SVG_ELEMENTS, true)) { $el->removeChild($c); continue; }
                $walk($c);
            } elseif (!($c instanceof DOMText && in_array(strtolower($el->localName), ['title', 'desc'], true))) {
                $el->removeChild($c); // comments, CDATA (e.g. <style> leftovers), processing instructions, stray text
            }
        }
        foreach (iterator_to_array($el->attributes) as $a) {
            $name = strtolower($a->localName);
            $v = trim($a->value);
            $bad = !in_array($name, SVG_ATTRS, true) || $a->prefix !== ''
                || preg_match('~javascript:|data:|expression\(|@import~i', $v)
                || (stripos($v, 'url(') !== false && !preg_match('~^url\(\s*#[\w.-]+\s*\)$~i', $v));
            if ($bad) { $el->removeAttributeNode($a); continue; }
            if ($idPrefix !== '' && $name === 'id' && !preg_match('~^s[0-9a-f]{6}-~', $v)) { $ids[] = $v; $el->setAttribute('id', $idPrefix . $v); }
        }
    };
    $root = $doc->documentElement;
    $walk($root);
    $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    $out = $doc->saveXML($root);
    if ($idPrefix !== '' && $ids) {
        $out = preg_replace_callback('~url\(\s*#([\w.-]+)\s*\)~', fn($m) => in_array($m[1], $ids, true) ? 'url(#' . $idPrefix . $m[1] . ')' : $m[0], $out);
    }
    return $out;
}

/* ---------------- icon library ---------------- */

/** Icon slots used by the site, with a description for the Icons screen. File name = slot name. */
const ICON_SLOTS = [
    'customer' => 'Client (projects)', 'edge-browser' => 'Website / URL', 'calendar' => 'Dates',
    'code' => 'Tools', 'work-type' => 'Services / type of work', 'company' => 'Company',
    'coupon' => 'Promo code', 'map' => 'Location', 'certificate' => 'Certificate',
    'certificate-verification' => 'Credential link', 'school' => 'School', 'college' => 'College / university',
    'home' => 'Home', 'blog' => 'Blog', 'scroll' => 'Scroll',
    'protonmail' => 'Email', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn',
    'youtube' => 'YouTube', 'github' => 'GitHub', 'x' => 'X / Twitter', 'bluesky' => 'Bluesky',
    'soundcloud' => 'SoundCloud', 'tiktok' => 'TikTok', 'mastodon' => 'Mastodon', 'threads' => 'Threads',
    'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'phone' => 'Phone',
    // Language menu: shown next to each language's name (no icon = name only).
    'flag-en' => 'Flag: English (US)', 'flag-hr' => 'Flag: Hrvatski', 'flag-bs' => 'Flag: Bosanski',
    'flag-sr' => 'Flag: Српски', 'flag-sl' => 'Flag: Slovenščina',
];

function icon_dir(): string
{
    return ROOT . '/storage/icons';
}

function icon_slot_from_filename(string $file): ?string
{
    $base = strtolower(preg_replace('~\.svg$~i', '', basename($file)));
    $base = preg_replace('~-svg$|-\d+$~', '', $base);
    return array_key_exists($base, ICON_SLOTS) ? $base : null;
}

/** Store an icon (sanitized). Returns false if the SVG was rejected. */
function icon_save(string $slot, string $svg): bool
{
    if (!array_key_exists($slot, ICON_SLOTS)) return false;
    $clean = svg_sanitize($svg);
    if ($clean === null) return false;
    if (!is_dir(icon_dir())) mkdir(icon_dir(), 0770, true);
    return file_put_contents(icon_dir() . '/' . $slot . '.svg', $clean) !== false;
}

/** Inline SVG for a slot, or null when no custom icon is stored. */
function icon_custom(string $slot, string $class = 'ico'): ?string
{
    static $n = 0;
    $f = icon_dir() . '/' . $slot . '.svg';
    if (!is_file($f)) return null;
    $svg = svg_sanitize((string)file_get_contents($f), 'i' . (++$n) . '-');
    if ($svg === null) return null;
    return preg_replace('~^<svg\b~', '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"', $svg);
}
