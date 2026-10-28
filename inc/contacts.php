<?php
declare(strict_types=1);

/**
 * Contact links: managed under Admin → Contact links, shown as a card grid on the contact page.
 * In page text, [contact-links] marks where the grid goes. Without it, the grid is placed after
 * the first paragraph of the "contact" page.
 */

const CONTACT_MARKER = '[contact-links]';

/** Icon slot guessed from the address (used when the entry's icon is "Automatic"). */
function contact_icon_for(string $link): string
{
    $l = strtolower($link);
    if (str_starts_with($l, 'mailto:')) return 'protonmail';
    if (str_starts_with($l, 'tel:')) return 'phone';
    $host = (string)parse_url($l, PHP_URL_HOST);
    $map = ['facebook.' => 'facebook', 'fb.' => 'facebook', 'linkedin.' => 'linkedin', 'instagram.' => 'instagram',
        'x.com' => 'x', 'twitter.' => 'x', 'youtube.' => 'youtube', 'youtu.be' => 'youtube', 'threads.' => 'threads',
        'github.' => 'github', 'bsky.' => 'bluesky', 'soundcloud.' => 'soundcloud', 'tiktok.' => 'tiktok',
        'mastodon' => 'mastodon', 'wa.me' => 'whatsapp', 'whatsapp.' => 'whatsapp', 't.me' => 'telegram', 'telegram.' => 'telegram'];
    foreach ($map as $needle => $slot) if (str_contains($host, $needle)) return $slot;
    return 'link';
}

/** Icon markup: your licensed library icon when there is one, otherwise a simple built-in mark. */
function contact_icon(string $slot): string
{
    if ($slot !== 'link' && $slot !== 'phone' && ($svg = icon_custom($slot))) return $svg;
    $share = ['facebook', 'x', 'threads', 'linkedin', 'whatsapp', 'telegram'];
    if (in_array($slot, $share, true) && defined('SHARE_NETWORKS')) {
        [, , $path, $box] = SHARE_NETWORKS[$slot];
        return '<svg class="ico" viewBox="' . $box . '" fill="currentColor" aria-hidden="true" focusable="false"><path d="' . $path . '"/></svg>';
    }
    $lines = [
        'protonmail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'phone'      => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
    ];
    $p = $lines[$slot] ?? '<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>';
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

function contacts_grid(): string
{
    try { $rows = all("SELECT * FROM contacts WHERE status = 'published' ORDER BY sort_order ASC, id ASC"); }
    catch (Throwable $e) { return ''; }
    if (!$rows) return '';
    $h = '<ul class="contact-grid">';
    foreach ($rows as $r) {
        $link = (string)$r['link'];
        $ext = (bool)preg_match('~^https?://~i', $link);
        $slot = $r['icon'] !== '' && $r['icon'] !== null ? (string)$r['icon'] : contact_icon_for($link);
        $value = trim((string)$r['value']) !== '' ? (string)$r['value'] : preg_replace('~^(mailto:|tel:|https?://(www\.)?)~i', '', $link);
        $h .= '<li><a class="contact-card" href="' . e($link) . '"' . ($ext ? ' target="_blank" rel="noopener noreferrer me"' : '') . '>'
            . '<span class="contact-ico">' . contact_icon($slot) . '</span>'
            . '<span class="contact-txt"><span class="contact-lbl">' . e($r['label']) . '<span class="sr">: </span></span>'
            . '<span class="contact-val">' . e($value) . '</span></span>'
            . ($ext ? '<span class="sr"> (opens in new tab)</span>' : '') . '</a></li>';
    }
    return $h . '</ul>';
}

/** Put the grid into contact-page HTML: at the marker, or after the first paragraph. */
function contacts_place(string $html, bool $isContactPage): string
{
    $grid = null;
    $html = preg_replace_callback('~(<p>\s*)?\[contact-links\](\s*</p>)?~i', function () use (&$grid) {
        return $grid ??= contacts_grid();
    }, $html, 1);
    if ($grid === null && $isContactPage && ($g = contacts_grid()) !== '') {
        $pos = stripos($html, '</p>');
        $html = $pos === false ? $g . $html : substr($html, 0, $pos + 4) . $g . substr($html, $pos + 4);
    }
    return $html;
}

/**
 * Turns the old WordPress/Elementor "icon box" list (icon link, title link, text) into contact
 * entries. Returns [html with the marker in place of the list, entries found].
 */
function contacts_extract(string $html): array
{
    $rows = [];
    $re = '~<p>\s*<a\b[^>]*href="([^"]+)"[^>]*>\s*(<svg\b.*?</svg>)?\s*</a>\s*(?:<br\s*/?>)?\s*<a\b[^>]*href="\1"[^>]*>(.*?)</a>\s*</p>\s*(?:<p>((?:(?!</p>).){0,200})</p>)?~is';
    $first = true;
    $html = preg_replace_callback($re, function ($m) use (&$rows, &$first) {
        $link = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        $rows[] = [
            'link' => $link,
            'svg' => $m[2] ?? '',
            'label' => trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8')),
            'value' => trim(html_entity_decode(strip_tags($m[4] ?? ''), ENT_QUOTES, 'UTF-8')),
        ];
        $out = $first ? '<p>' . CONTACT_MARKER . '</p>' : '';
        $first = false;
        return $out;
    }, $html);
    return [$html, $rows];
}

/** One-time conversion of the contact page (runs only while there are no contact entries yet). */
function contacts_convert_page(): int
{
    if ((int)one('SELECT COUNT(*) c FROM contacts')['c'] > 0) return 0;
    $page = one("SELECT id, content FROM pages WHERE slug = 'contact'");
    if (!$page) return 0;
    [$html, $rows] = contacts_extract((string)$page['content']);
    if (!$rows) return 0;
    $order = 10;
    foreach ($rows as $r) {
        $slot = contact_icon_for($r['link']);
        // Keep your licensed icon: if the library has no icon for this network yet, store the one from the page.
        if ($r['svg'] !== '' && array_key_exists($slot, ICON_SLOTS) && !is_file(icon_dir() . '/' . $slot . '.svg')) icon_save($slot, $r['svg']);
        q('INSERT INTO contacts (label, value, link, icon, sort_order, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$r['label'] ?: ucfirst($slot), $r['value'], $r['link'], '', $order, 'published', now(), now()]);
        $order += 10;
    }
    q('UPDATE pages SET content = ?, updated_at = ? WHERE id = ?', [$html, now(), $page['id']]);
    return count($rows);
}
