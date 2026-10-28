<?php
declare(strict_types=1);

/**
 * Whitelist HTML cleaner for rich-text fields. Removes scripts, event handlers,
 * inline styles and anything not on the list below, so pasted content from Word,
 * Google Docs or old WordPress posts can't smuggle in trackers or broken markup.
 */
function clean_html(string $html): string
{
    $html = trim($html);
    if ($html === '') return '';

    // Inline SVG icons: cleaned with the strict SVG whitelist, then put back after HTML cleaning.
    $svgs = [];
    $html = preg_replace_callback('~<svg\b.*?</svg>~is', function ($m) use (&$svgs) {
        $clean = svg_sanitize($m[0], 's' . substr(md5($m[0]), 0, 6) . '-');
        if ($clean === null) return '';
        // Root tag only: size comes from CSS, and the accessibility attributes are set once.
        $clean = preg_replace_callback('~^<svg\b[^>]*>~', function ($t) {
            $tag = preg_replace('~\s(width|height|class|aria-hidden|focusable)="[^"]*"~', '', $t[0]);
            return preg_replace('~^<svg\b~', '<svg class="ico" aria-hidden="true" focusable="false"', $tag);
        }, $clean);
        $key = 'SVGTOKEN' . count($svgs) . 'X' . bin2hex(random_bytes(3));
        $svgs[$key] = $clean;
        return $key;
    }, $html);

    $allowed = [
        'p' => [], 'br' => [], 'hr' => [],
        'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'mark' => [], 'small' => [], 'sub' => [], 'sup' => [],
        'a' => ['href', 'title', 'target', 'aria-label'],
        'ul' => [], 'ol' => ['start'], 'li' => [],
        'blockquote' => ['cite'], 'pre' => [], 'code' => [],
        'figure' => ['class'], 'figcaption' => [],
        'img' => ['src', 'alt', 'width', 'height', 'title'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'iframe' => ['src', 'title', 'width', 'height', 'allowfullscreen'],
    ];
    $drop = ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'noscript', 'svg', 'math', 'template'];

    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $root = $doc->getElementById('__root') ?? $doc->documentElement;

    $walk = function (DOMNode $node) use (&$walk, $allowed, $drop, $doc) {
        for ($child = $node->firstChild; $child; $child = $next) {
            $next = $child->nextSibling;
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);

            if (in_array($tag, $drop, true)) {
                $node->removeChild($child);
                continue;
            }
            // The three content boxes (info / notice / warning) are kept; any other div is unwrapped.
            if ($tag === 'div' && preg_match('~^box box-(info|notice|warning)$~', trim($child->getAttribute('class')))) {
                foreach (iterator_to_array($child->attributes) as $attr) if (strtolower($attr->name) !== 'class') $child->removeAttribute($attr->name);
                $walk($child);
                continue;
            }
            if ($tag === 'div' || $tag === 'span' || $tag === 'font' || $tag === 'section' || $tag === 'article' || !isset($allowed[$tag])) {
                // Unwrap: keep the text, lose the tag. Divs become paragraphs-ish line breaks.
                $walk($child);
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $hasBlock = false;
                foreach (['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'figure', 'blockquote', 'table', 'pre', 'iframe', 'hr'] as $bt) {
                    if ($child->getElementsByTagName($bt)->length) { $hasBlock = true; break; }
                }
                if ($tag === 'div' && !$hasBlock && trim($child->textContent) !== '' && $child->nextSibling) $node->insertBefore($doc->createElement('br'), $child);
                $node->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                $val = trim($attr->value);
                $ok = in_array($name, $allowed[$tag], true);
                if ($ok && in_array($name, ['href', 'src', 'cite'], true)) $ok = safe_url($val, $tag, $name);
                if ($ok && $name === 'class') $ok = (bool)preg_match('~^[a-z0-9 _-]{0,60}$~i', $val);
                if (!$ok) $child->removeAttribute($attr->name);
            }

            if ($tag === 'iframe' && !$child->hasAttribute('src')) {
                $node->removeChild($child);
                continue;
            }
            if ($tag === 'img') {
                if (!$child->hasAttribute('src')) { $node->removeChild($child); continue; }
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('decoding', 'async');
                if (!$child->hasAttribute('alt')) $child->setAttribute('alt', '');
            }
            if ($tag === 'iframe') {
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('referrerpolicy', 'strict-origin');
            }
            if ($tag === 'a') {
                $href = $child->getAttribute('href');
                if (preg_match('~^https?://~i', $href) && !is_own_url($href)) {
                    $child->setAttribute('target', '_blank');
                }
                if ($child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $child->removeAttribute('target');
                }
            }
            $walk($child);
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $n) $out .= $doc->saveHTML($n);
    // Collapse empty paragraphs left behind by editors.
    $out = preg_replace('~<p>(\s|&nbsp;|<br>)*</p>~i', '', $out);
    if ($svgs) $out = strtr((string)$out, $svgs);
    return trim((string)$out);
}

function safe_url(string $url, string $tag, string $attr): bool
{
    if ($url === '') return false;
    if ($tag === 'iframe') {
        // Privacy-friendly video embeds only (no-cookie YouTube, Vimeo with dnt).
        return (bool)preg_match('~^https://(www\.youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)~i', $url);
    }
    if ($tag === 'img') {
        return (bool)preg_match('~^(https?://|/|uploads/)~i', $url);
    }
    if (preg_match('~^(https?:|mailto:|tel:|/|#)~i', $url)) return true;
    // Relative paths without a scheme are fine; anything with an unknown scheme (javascript:, data:) is not.
    return !preg_match('~^[a-z][a-z0-9+.-]*:~i', $url);
}

function is_own_url(string $url): bool
{
    $host = parse_url((string)cfg('site_url'), PHP_URL_HOST);
    return $host && strcasecmp((string)parse_url($url, PHP_URL_HOST), $host) === 0;
}
