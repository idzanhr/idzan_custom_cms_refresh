<?php
declare(strict_types=1);

/**
 * Languages. English is the original and lives at /…; each translation is a full copy of the
 * static site under /hr/…, /bs/…, /sr/…, /sl/… built from the translations table (published rows),
 * falling back to English for anything not translated yet.
 *
 * Code => [name in its own language, HTML lang / hreflang, description for the translator]
 */
const LANGS = [
    'hr' => ['Hrvatski', 'hr', 'Croatian (hrvatski jezik), Latin script'],
    'bs' => ['Bosanski', 'bs', 'Bosnian (bosanski jezik), Latin script'],
    'sr' => ['Српски', 'sr-Cyrl', 'Serbian (српски језик) written ONLY in Cyrillic script (ћирилица), never Latin'],
    'sl' => ['Slovenščina', 'sl', 'Slovenian (slovenski jezik)'],
];

/** Fields translated per table (everything else, e.g. company names, URLs, codes, stays as it is). */
const TRANSLATE_FIELDS = [
    'posts'          => ['title', 'excerpt', 'content', 'seo_title', 'seo_description'],
    'projects'       => ['title', 'content', 'seo_title', 'seo_description'],
    'pages'          => ['title', 'content', 'seo_title', 'seo_description'],
    'experience'     => ['role', 'description'],
    'education'      => ['program', 'description'],
    'affiliates'     => ['title', 'content', 'seo_title', 'seo_description'],
    'contacts'       => ['label'],
    'terms'          => ['name'],
];

/** Site settings that are text visitors read. */
const TRANSLATE_SETTINGS = ['tagline', 'meta_description', 'about_html', 'footer_links', 'footer_note',
    'consent_title', 'consent_text', 'consent_preferences_text', 'consent_statistics_text', 'consent_marketing_text'];

/** Interface texts written in the templates (translated once per language, editable in the admin). */
const UI_STRINGS = [
    'Homepage', 'About Me', 'Blog', 'Projects', 'Contact', 'Menu', 'Main', 'Skip to content', 'Portfolio and Blog', 'Language',
    'About me', 'View my Projects', 'Read Blog', 'Contact me',
    'Page not found', 'Well, This Is Embarrassing', 'Looks like this page/post/project has been misplaced or deleted.',
    'But don’t worry, we’ve got you covered!', 'Head back to the homepage to explore more.',
    'Get me back to the homepage—I’m lost here!', 'Or browse:', 'Something went wrong', 'Please try again in a moment.',
    'Copyright', 'Manage consent', 'Cookie Policy', 'Privacy Statement', 'Imprint',
    'Functional', 'Preferences', 'Statistics', 'Marketing', 'Needed for the site to work, including remembering this choice and your language.',
    'Always active', 'Accept', 'Deny', 'View preferences', 'Save preferences',
    'Accessibility', 'Accessibility options', 'Larger text', 'High contrast', 'Underline links', 'Readable font', 'Stop animations', 'Reset',
    'Work Experience', 'Education', 'Certifications', 'Issued by: %s', 'Issued on: %s', '%1$s issued by %2$s', 'View credential',
    '(opens in new tab)', 'Ongoing', 'Present', 'Start date', 'End date', 'Work type', 'Learn more', 'about %s', '%1$s at %2$s',
    'On-site', 'Hybrid', 'Remote', 'No projects yet.',
    'Client', 'Website', 'Started', 'Services', 'Tools', 'Details', 'Company', 'URL', 'Visit Site', 'Promo Code', 'Promo code:',
    'Type', 'NO CODE', 'Copy', 'Copied!', 'Learn More', 'Affiliate', 'Referral', 'Sponsored', 'Affiliates and Sponsorships',
    'Sponsored content. I only accept sponsorships for products I have used myself.',
    'This is an %s link: if you sign up through it, I may earn a commission at no extra cost to you.',
    'Previous', 'Next', 'More', 'Categories', 'All', 'No posts yet.', 'Page %d', 'Read more', 'Page %1$d of %2$d', 'Pages',
    '%d min read', 'Reading progress', 'read', 'min', 'Share on', 'Category', 'Posted on', 'About this post', 'Share by email',
    'Share on %s (opens in a new tab)', 'Email',
    'This page was translated automatically from English.', 'Read the original in English',
    'Switch to light mode', 'Switch to dark mode',
    'Close', 'Previous image', 'Next image', 'Enlarge image', 'Image %1$s of %2$s',
];

/** Month names for dates on translated pages (nominative, as used with a year: "ožujak 2024."). */
const MONTHS = [
    'hr' => ['siječanj', 'veljača', 'ožujak', 'travanj', 'svibanj', 'lipanj', 'srpanj', 'kolovoz', 'rujan', 'listopad', 'studeni', 'prosinac'],
    'bs' => ['januar', 'februar', 'mart', 'april', 'maj', 'juni', 'juli', 'august', 'septembar', 'oktobar', 'novembar', 'decembar'],
    'sr' => ['јануар', 'фебруар', 'март', 'април', 'мај', 'јун', 'јул', 'август', 'септембар', 'октобар', 'новембар', 'децембар'],
    'sl' => ['januar', 'februar', 'marec', 'april', 'maj', 'junij', 'julij', 'avgust', 'september', 'oktober', 'november', 'december'],
];

/** Language of the page being built ('en' = original). */
function lang(): string
{
    return $GLOBALS['LANG'] ?? 'en';
}

/** Languages switched on in the admin, in a fixed order. */
function enabled_langs(): array
{
    $on = array_filter(array_map('trim', explode(',', setting('languages', ''))));
    return array_values(array_filter(array_keys(LANGS), fn($l) => in_array($l, $on, true)));
}

function lang_html(string $l): string
{
    return $l === 'en' ? 'en' : (LANGS[$l][1] ?? $l);
}

/** Address of a page in a language: '/blog/' → '/hr/blog/'. */
function lang_path(string $path, string $l): string
{
    return $l === 'en' ? $path : '/' . $l . ($path === '/' ? '/' : $path);
}

/** True for addresses that have a translated copy (pages), false for files (assets, uploads, feeds…). */
function is_page_path(string $path): bool
{
    // Files are the same for every language: never /hr/uploads/… or /hr/_img/….
    if (preg_match('~^/(assets|uploads|_img|wp-content|\.well-known)/~', $path)) return false;
    if (preg_match('~\.(css|js|xml|txt|json|ico|png|jpe?g|gif|webp|avif|svg|webmanifest|woff2?|ttf|otf|eot|pdf|zip|mp3|mp4|webm)$~i', $path)) return false;
    if (preg_match('~^/(hr|bs|sr|sl)(/|$)~', $path)) return false;
    return true;
}

/** Interface text in the current language (printf-style placeholders allowed). */
function t(string $s, ...$args): string
{
    $l = lang();
    if ($l !== 'en') {
        $map = tr_map('ui', $l);
        $v = $map[0][md5($s)] ?? null;
        if (is_string($v) && $v !== '') $s = $v;
    }
    if (!$args) return $s;
    $out = @vsprintf($s, $args);
    return $out === false || $out === '' ? vsprintf(str_replace(['%1$', '%2$'], '%', $s), $args) : $out;
}

/** Published translations of a table in a language: [id => [field => value]] (cached per build). */
function tr_map(string $entity, string $l): array
{
    static $cache = [];
    $k = $entity . '|' . $l;
    if (isset($GLOBALS['TR_RESET'])) { $cache = []; unset($GLOBALS['TR_RESET']); }
    if (!isset($cache[$k])) {
        $cache[$k] = [];
        try {
            foreach (all_raw("SELECT entity_id, field, value FROM translations WHERE entity = ? AND lang = ? AND status = 'published'", [$entity, $l]) as $r) {
                $cache[$k][(int)$r['entity_id']][$r['field']] = $r['value'];
            }
        } catch (Throwable $e) { /* table not created yet */ }
    }
    return $cache[$k];
}

/** Puts translations over rows read from a translatable table (called by one()/all()). */
function tr_overlay(string $sql, array $rows): array
{
    $l = lang();
    if ($l === 'en' || !$rows) return $rows;
    if (!preg_match('~^\s*SELECT\b.*?\bFROM\s+(\w+)~is', $sql, $m) || !isset(TRANSLATE_FIELDS[$m[1]])) return $rows;
    $map = tr_map($m[1], $l);
    if (!$map) return $rows;
    foreach ($rows as &$r) {
        if (!isset($r['id'], $map[(int)$r['id']])) continue;
        foreach ($map[(int)$r['id']] as $f => $v) if (array_key_exists($f, $r) && $v !== '') $r[$f] = tr_sync_media((string)$r[$f], $v);
    }
    return $rows;
}

/**
 * Images in a translation always use the English original's addresses: translation services sometimes
 * change or break them, and the "Fix old WordPress image links" tool only updates the originals.
 * Images are matched in order (same count) or by file name; the translated alt text is kept.
 */
function tr_sync_media(string $orig, string $tr): string
{
    if ($tr === '' || (!str_contains($orig, '<img') && !str_contains($orig, 'uploads/'))) return $tr;
    $attrs = ['src', 'srcset', 'sizes', 'width', 'height'];
    $get = function (string $tag, string $a): ?string {
        return preg_match('~\s' . $a . '\s*=\s*"([^"]*)"~i', $tag, $m) ? $m[1] : null;
    };
    $file = fn(?string $u) => $u === null ? '' : preg_replace('~-\d+x\d+(?=\.\w+$)~', '', strtolower(basename((string)parse_url(html_entity_decode($u), PHP_URL_PATH))));
    preg_match_all('~<img\b[^>]*>~i', $orig, $o);
    $o = $o[0];
    $byName = [];
    foreach ($o as $tag) $byName[$file($get($tag, 'src'))] ??= $tag;
    $i = 0;
    $same = substr_count(strtolower($tr), '<img') === count($o);
    $tr = (string)preg_replace_callback('~<img\b[^>]*>~i', function ($m) use (&$i, $o, $same, $byName, $attrs, $get, $file) {
        $tag = $m[0];
        $src = $same ? ($o[$i] ?? null) : ($byName[$file($get($tag, 'src'))] ?? null);
        $i++;
        if ($src === null) return $tag;
        foreach ($attrs as $a) {
            $v = $get($src, $a);
            $tag = (string)preg_replace('~\s' . $a . '\s*=\s*"[^"]*"~i', '', $tag);
            if ($v !== null) $tag = (string)preg_replace('~^<img\b~i', '<img ' . $a . '="' . $v . '"', $tag);
        }
        return $tag;
    }, $tr);
    // Links to image files (e.g. "open full size"): same address as in the original, matched by file name.
    if (preg_match_all('~href="([^"]*(?:/uploads/|/wp-content/)[^"]*)"~i', $orig, $h)) {
        $links = [];
        foreach ($h[1] as $u) $links[$file($u)] ??= $u;
        $tr = (string)preg_replace_callback('~href="([^"]*(?:/uploads/|/wp-content/)[^"]*)"~i',
            fn($m) => isset($links[$file($m[1])]) ? 'href="' . $links[$file($m[1])] . '"' : $m[0], $tr);
    }
    return $tr;
}

/** A setting in the current language. */
function tr_setting(string $key, string $value): string
{
    $l = lang();
    if ($l === 'en' || !in_array($key, TRANSLATE_SETTINGS, true)) return $value;
    $v = tr_map('settings', $l)[0][$key] ?? '';
    return $v !== '' ? tr_sync_media($value, $v) : $value;
}

/** Language switcher for the header: links to this page in every language. */
function lang_switcher(string $basePath): string
{
    $langs = enabled_langs();
    if (!$langs) return '';
    $cur = lang();
    $all = array_merge(['en' => 'English'], array_combine($langs, array_map(fn($l) => LANGS[$l][0], $langs)));
    $h = '<div class="lang" data-lang-switch><button class="lang-btn" type="button" aria-expanded="false" aria-controls="langmenu">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 3 12 3z"/></svg>'
        . '<span>' . e(strtoupper($cur)) . '</span><span class="sr"> — ' . e(t('Language')) . ': ' . e($all[$cur]) . '</span></button>'
        . '<ul class="lang-menu" id="langmenu" hidden>';
    foreach ($all as $code => $name) {
        $h .= '<li><a href="' . e(rtrim((string)cfg('base_path', ''), '/') . lang_path($basePath, $code)) . '" hreflang="' . e(lang_html($code)) . '" lang="' . e(lang_html($code)) . '" data-lang="' . e($code) . '"'
            . ($code === $cur ? ' aria-current="true"' : '') . '>' . e($name) . '</a></li>';
    }
    return $h . '</ul></div>';
}

/** <link rel="alternate" hreflang> tags for this page. */
function hreflang_tags(string $basePath): string
{
    $langs = enabled_langs();
    if (!$langs) return '';
    $base = rtrim((string)cfg('base_path', ''), '/');
    $h = '<link rel="alternate" hreflang="en" href="' . e(abs_url($base . $basePath)) . '">' . "\n";
    foreach ($langs as $l) $h .= '<link rel="alternate" hreflang="' . e(lang_html($l)) . '" href="' . e(abs_url($base . lang_path($basePath, $l))) . '">' . "\n";
    return $h . '<link rel="alternate" hreflang="x-default" href="' . e(abs_url($base . $basePath)) . '">' . "\n";
}

/** Small note on translated pages (Admin → Languages: "Show a note…"), with a link to the English original. */
function machine_note(): string
{
    $l = lang();
    if ($l === 'en' || setting('tr_note', '1') !== '1') return '';
    $en = rtrim((string)cfg('base_path', ''), '/') . ($GLOBALS['BASE_PATH'] ?? '/');
    return '<p class="wrap tr-note">' . e(t('This page was translated automatically from English.'))
        . ' <a href="' . e($en) . '" hreflang="en" lang="en" data-lang="en">' . e(t('Read the original in English')) . '</a></p>';
}

/**
 * On a translated page, links to this site's pages written inside content (posts, footer note, footer
 * links) are pointed at the same language: href="/blog/" → href="/hr/blog/". The language menu's own
 * links (data-lang / hreflang) and files (images, downloads) are left alone.
 */
function lang_rewrite_links(string $html, string $l): string
{
    if ($l === 'en') return $html;
    $base = rtrim((string)cfg('base_path', ''), '/');
    $site = rtrim((string)cfg('site_url', ''), '/');
    return (string)preg_replace_callback('~<a\b[^>]*>~i', function ($m) use ($l, $base, $site) {
        $tag = $m[0];
        if (str_contains($tag, 'data-lang=') || str_contains($tag, 'hreflang=')) return $tag;
        return (string)preg_replace_callback('~\bhref="(' . preg_quote($site, '~') . ')?(' . preg_quote($base, '~') . ')(/[^"]*)"~', function ($h) use ($l) {
            $path = html_entity_decode($h[3], ENT_QUOTES);
            if (!is_page_path(strtok($path, '?#') ?: '/')) return $h[0];
            return 'href="' . $h[1] . $h[2] . e(lang_path($path, $l)) . '"';
        }, $tag, 1);
    }, $html);
}

