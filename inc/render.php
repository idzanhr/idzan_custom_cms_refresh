<?php
declare(strict_types=1);

/**
 * Public site: routing + templates. Every function returns plain HTML so the same
 * code serves live requests (index.php) and the static export (admin/export.php).
 * No analytics, no third-party fonts, no external scripts.
 */

const PUB = "status = 'published'";

/** SQL: post (table alias $a) belongs to category ? — as main category or one of its extra ones. Needs the id twice. */
function post_in_cat(string $a = 'posts'): string
{
    return "({$a}.category_id = ? OR EXISTS (SELECT 1 FROM entity_terms et WHERE et.entity = 'posts' AND et.entity_id = {$a}.id AND et.term_id = ?))";
}

/** All categories of a post: main one first, then the extra ones. */
function post_categories(array $p): array
{
    $cats = [];
    if ($p['category_id'] && ($c = one('SELECT id, name, slug FROM terms WHERE id = ?', [$p['category_id']]))) $cats[$c['id']] = $c;
    foreach (entity_terms('posts', (int)$p['id'], 'category') as $c) $cats[$c['id']] ??= $c;
    return array_values($cats);
}

/* ---------------- routing ---------------- */

/** @return array{0:int,1:string,2:string} [status, body, content-type] */
function route(string $path, string $method = 'GET'): array
{
    $path = trim(rawurldecode($path), '/');
    $seg = $path === '' ? [] : explode('/', $path);

    try {
        switch (true) {
            case $path === '':
                return [200, page_home(), 'text/html'];
            case $path === 'theme.css':
                return [200, public_css(), 'text/css'];
            case $path === 'feed.xml':
                return [200, feed_xml(), 'application/rss+xml'];
            case $path === 'sitemap.xml':
                return [200, sitemap_xml(), 'application/xml'];
            case $path === 'robots.txt':
                return [200, robots_txt(), 'text/plain'];
            case $path === 'llms.txt':
                return s('llms_txt') ? [200, llms_txt(), 'text/plain'] : not_found();
            case $path === 'about-me':
                return [200, page_about(), 'text/html'];
            case $path === 'projects':
                return [200, page_projects(), 'text/html'];
            case $seg[0] === 'blog' && (count($seg) === 1 || (count($seg) === 3 && $seg[1] === 'page' && ctype_digit($seg[2]))):
                return page_blog((int)($seg[2] ?? 1));
            case $seg[0] === 'category' && isset($seg[1]) && (count($seg) === 2 || (count($seg) === 4 && $seg[2] === 'page' && ctype_digit($seg[3]))):
                return page_blog((int)($seg[3] ?? 1), $seg[1]);
            case $seg[0] === 'project' && count($seg) === 2:
                $row = one('SELECT * FROM projects WHERE slug = ? AND ' . PUB, [$seg[1]]);
                return $row ? [200, page_project($row), 'text/html'] : not_found();
            case $seg[0] === 'affiliate' && count($seg) === 2:
                $row = one('SELECT * FROM affiliates WHERE slug = ? AND ' . PUB, [$seg[1]]);
                return $row ? [200, page_affiliate($row), 'text/html'] : not_found();
            case $seg[0] === 'work-experience' && count($seg) === 2:
                $row = one('SELECT * FROM experience WHERE slug = ? AND ' . PUB, [$seg[1]]);
                return $row ? [200, page_experience($row), 'text/html'] : not_found();
            case count($seg) === 1:
                if ($row = one('SELECT * FROM posts WHERE slug = ? AND ' . PUB . ' AND published_at <= ?', [$seg[0], now()])) {
                    return [200, page_post($row), 'text/html'];
                }
                if ($row = one('SELECT * FROM pages WHERE slug = ? AND ' . PUB, [$seg[0]])) {
                    return [200, page_page($row), 'text/html'];
                }
                return not_found();
            default:
                return not_found();
        }
    } catch (PDOException $ex) {
        error_log('[cms] ' . $ex->getMessage());
        return [500, layout('Error', '<section class="wrap narrow"><h1>' . e(t('Something went wrong')) . '</h1><p>' . e(t('Please try again in a moment.')) . '</p></section>'), 'text/html'];
    }
}

function not_found(): array
{
    // Text from the old site's "Error 404 - Page Not Found" template (Elementor), without its search box.
    $body = '<section class="wrap narrow nf">'
        . '<p class="nf-code" aria-hidden="true">404</p>'
        . '<h1>' . e(t('Well, This Is Embarrassing')) . ' <span aria-hidden="true">😅</span></h1>'
        . '<p class="nf-lead">' . e(t('Looks like this page/post/project has been misplaced or deleted.')) . '<br>' . e(t('But don’t worry, we’ve got you covered!')) . '</p>'
        . '<p>' . e(t('Head back to the homepage to explore more.')) . '</p>'
        . '<p class="btns"><a class="btn" href="' . e(url('/')) . '">' . e(t('Get me back to the homepage—I’m lost here!')) . '</a></p>'
        . '<p class="nf-browse">' . e(t('Or browse:')) . ' <a href="' . e(url('/blog/')) . '">' . e(t('Blog')) . '</a> · <a href="' . e(url('/projects/')) . '">' . e(t('Projects')) . '</a> · <a href="' . e(url('/about-me/')) . '">' . e(t('About Me')) . '</a> · <a href="' . e(url('/contact/')) . '">' . e(t('Contact')) . '</a></p>'
        . '</section>';
    return [404, layout(t('Page not found'), $body, ['robots' => 'noindex']), 'text/html'];
}

function public_url_for(string $entity, array $row): string
{
    return match ($entity) {
        'posts', 'pages' => url('/' . $row['slug'] . '/'),
        'projects'       => url('/project/' . $row['slug'] . '/'),
        'experience'     => url('/work-experience/' . $row['slug'] . '/'),
        'affiliates'     => url('/affiliate/' . $row['slug'] . '/'),
        'education', 'certifications' => url('/about-me/'),
        default          => url('/'),
    };
}

/* ---------------- layout ---------------- */

function layout(string $title, string $content, array $meta = []): string
{
    $site = s('site_title');
    $fullTitle = $title === '' ? $site . ' - ' . t('Portfolio and Blog') : $title . ' - ' . $site;
    if (trim((string)($meta['seo_title'] ?? '')) !== '') $fullTitle = trim($meta['seo_title']);   // the item's own "Search title"
    $desc = trim((string)($meta['seo_description'] ?? '')) !== '' ? $meta['seo_description'] : ($meta['description'] ?? s('meta_description'));
    $desc = seo_clip((string)$desc);
    $canonical = abs_url($meta['path'] ?? url('/'));
    $ogSrc = ($meta['image'] ?? '') ?: (s('og_image') ?: s('profile_image'));
    [$ogImage, $ogW, $ogH] = og_image((string)$ogSrc);
    $ogAlt = trim((string)(($meta['image_alt'] ?? '') ?: (media_alt((string)$ogSrc) ?: ($title !== '' ? $title : $site))));
    $type = $meta['type'] ?? 'website';
    $active = $meta['nav'] ?? '';

    $nav = [
        ['/', 'Homepage', 'home'],
        ['/about-me/', 'About Me', 'about'],
        ['/blog/', 'Blog', 'blog'],
        ['/projects/', 'Projects', 'projects'],
        ['/contact/', 'Contact', 'contact'],
    ];

    ob_start(); ?>
<!doctype html>
<html lang="<?= e(lang_html(lang())) ?>" data-theme-default="<?= e(s('theme_default') === 'system' ? 'system' : 'dark') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="<?= e(csp_public()) ?>">
<script><?= theme_inline_js() ?></script>
<link rel="stylesheet" href="<?= e(url('/theme.css')) ?>?v=<?= e(substr(md5(public_css()), 0, 8)) ?>">
<?= lcp_preload_tag() ?><?= font_preload_tags() ?>
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php if (!empty($meta['robots'])): ?><meta name="robots" content="<?= e($meta['robots']) ?>">
<?php endif ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:locale" content="<?= e(['en' => 'en_US', 'hr' => 'hr_HR', 'bs' => 'bs_BA', 'sr' => 'sr_RS', 'sl' => 'sl_SI'][lang()] ?? 'en_US') ?>">
<meta property="og:site_name" content="<?= e($site) ?>">
<meta property="og:type" content="<?= e($type) ?>">
<meta property="og:title" content="<?= e($fullTitle) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<?php foreach (array_diff(array_merge(['en'], enabled_langs()), [lang()]) as $al): ?><meta property="og:locale:alternate" content="<?= e(['en' => 'en_US', 'hr' => 'hr_HR', 'bs' => 'bs_BA', 'sr' => 'sr_RS', 'sl' => 'sl_SI'][$al] ?? $al) ?>">
<?php endforeach ?>
<?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>">
<?php if ($ogW): ?><meta property="og:image:width" content="<?= (int)$ogW ?>">
<meta property="og:image:height" content="<?= (int)$ogH ?>">
<?php endif ?>
<meta property="og:image:alt" content="<?= e($ogAlt) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">
<meta name="twitter:image:alt" content="<?= e($ogAlt) ?>">
<?php endif ?>
<meta name="author" content="<?= e($site) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php if (s('twitter_handle')): ?><meta name="twitter:creator" content="<?= e(s('twitter_handle')) ?>">
<?php endif ?>
<?php if (!empty($meta['published'])): ?><meta property="article:published_time" content="<?= e(date('c', strtotime($meta['published']))) ?>">
<?php endif ?>
<?php if (!empty($meta['modified'])): ?><meta property="article:modified_time" content="<?= e(date('c', strtotime($meta['modified']))) ?>">
<?php endif ?>
<?= structured_data($meta, $fullTitle, $desc, $canonical, $ogImage) ?>
<?= favicon_tags() ?><?php if (s('mastodon_url')): ?><link rel="me" href="<?= e(s('mastodon_url')) ?>">
<?php endif ?>
<?= hreflang_tags($GLOBALS['BASE_PATH'] ?? '/') ?><link rel="alternate" type="application/rss+xml" title="<?= e($site) ?> — Blog" href="<?= e(url('/feed.xml')) ?>">
<script src="<?= e(asset_url('/assets/js/site.js')) ?>" defer></script>
</head>
<body data-lb="<?= e(implode('|', [t('Close'), t('Previous image'), t('Next image'), t('Enlarge image'), t('Image %1$s of %2$s', '{n}', '{total}')])) ?>">
<a class="skip" href="#content"><?= e(t('Skip to content')) ?></a>
<header class="site-header">
  <div class="wrap bar">
    <a class="brand" href="<?= e(url('/')) ?>"><?= e($site) ?></a>
    <button class="menu-btn" type="button" aria-expanded="false" aria-controls="mainnav"><span class="burger" aria-hidden="true"></span><span class="sr"><?= e(t('Menu')) ?></span></button>
    <?= lang_switcher($GLOBALS['BASE_PATH'] ?? '/') ?>
    <button class="theme-btn" type="button" data-theme-toggle aria-pressed="false" data-label-light="<?= e(t('Switch to light mode')) ?>" data-label-dark="<?= e(t('Switch to dark mode')) ?>" aria-label="<?= e(t('Switch to light mode')) ?>" title="<?= e(t('Switch to light mode')) ?>">
      <svg class="i-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>
      <svg class="i-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    </button>
    <nav id="mainnav" class="mainnav" aria-label="<?= e(t('Main')) ?>">
      <ul>
      <?php foreach ($nav as [$href, $label, $key]): ?>
        <li><a href="<?= e(url($href)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e(t($label)) ?></a></li>
      <?php endforeach ?>
      </ul>
    </nav>
  </div>
</header>
<main id="content" tabindex="-1">
<?= machine_note() ?><?= $content ?>
</main>
<?= site_footer() ?>
<?= consent_banner() ?>
<div class="a11y" data-a11y>
  <button class="a11y-btn" type="button" aria-expanded="false" aria-controls="a11ypanel" title="<?= e(t('Accessibility')) ?>"><span aria-hidden="true">Aa</span><span class="sr"><?= e(t('Accessibility options')) ?></span></button>
  <div class="a11y-panel" id="a11ypanel" hidden>
    <p class="a11y-title"><?= e(t('Accessibility')) ?></p>
    <label><input type="checkbox" data-a11y-opt="big"> <?= e(t('Larger text')) ?></label>
    <label><input type="checkbox" data-a11y-opt="contrast"> <?= e(t('High contrast')) ?></label>
    <label><input type="checkbox" data-a11y-opt="links"> <?= e(t('Underline links')) ?></label>
    <label><input type="checkbox" data-a11y-opt="readable"> <?= e(t('Readable font')) ?></label>
    <label><input type="checkbox" data-a11y-opt="still"> <?= e(t('Stop animations')) ?></label>
    <button type="button" class="a11y-reset" data-a11y-reset><?= e(t('Reset')) ?></button>
  </div>
</div>
</body>
</html>
<?php
    return (string)ob_get_clean();
}

function site_footer(): string
{
    $links = link_lines(s('footer_links'));
    $pages = all('SELECT id, title, slug FROM pages WHERE ' . PUB . ' AND in_footer = 1 ORDER BY footer_order, title');
    $from = s('copyright_from');
    $year = date('Y');
    $out = '<footer class="site-footer"><div class="wrap">';
    if ($links) {
        $out .= '<ul class="foot-links">';
        foreach ($links as [$l, $h]) $out .= '<li><a href="' . e($h) . '"' . (preg_match('~^https?://~', $h) ? ' target="_blank" rel="noopener"' : '') . '>' . e($l) . '</a></li>';
        $out .= '</ul>';
    }
    if ($pages) {
        $out .= '<ul class="legal">';
        foreach ($pages as $p) $out .= '<li><a href="' . e(url('/' . $p['slug'] . '/')) . '">' . e($p['title']) . '</a></li>';
        $out .= '</ul>';
    }
    $out .= '<p class="copy">' . e(t('Copyright')) . ' © ' . e($from && $from !== $year ? $from . ' – ' . $year : $year) . '</p>';
    if (s('consent_enabled') === '1') $out .= '<p class="consent-link"><button type="button" class="linkbtn" data-consent-open>' . e(t('Manage consent')) . '</button></p>';
    if (s('footer_note')) $out .= '<div class="note">' . s('footer_note') . '</div>';
    $out .= '</div></footer>';
    return $out;
}

/** GDPR consent banner (self-hosted, no third parties). Shown by site.js until a choice is saved. */
function consent_banner(): string
{
    if (s('consent_enabled') !== '1') return '';
    $days = max(1, (int)s('consent_days'));
    $link = fn(string $slug, string $label) => ($slug !== '' && one('SELECT id FROM pages WHERE slug = ? AND ' . PUB, [$slug]))
        ? '<a href="' . e(url('/' . $slug . '/')) . '">' . e($label) . '</a>' : '';
    $links = array_filter([$link(s('consent_policy_slug'), t('Cookie Policy')), $link(s('consent_privacy_slug'), t('Privacy Statement')), $link('imprint', t('Imprint'))]);
    $cats = [
        ['functional', t('Functional'), t('Needed for the site to work, including remembering this choice and your language.'), true],
        ['preferences', t('Preferences'), s('consent_preferences_text'), false],
        ['statistics', t('Statistics'), s('consent_statistics_text'), false],
        ['marketing', t('Marketing'), s('consent_marketing_text'), false],
    ];
    $h = '<div class="consent" id="consent-box" data-consent data-days="' . $days . '" role="dialog" aria-modal="false" aria-labelledby="consent-title" hidden>'
        . '<p class="consent-title" id="consent-title">' . e(s('consent_title')) . '</p>'
        . '<p class="consent-text">' . e(s('consent_text')) . '</p>'
        . '<div class="consent-cats" data-consent-cats hidden>';
    foreach ($cats as [$key, $label, $desc, $locked]) {
        $h .= '<label class="consent-cat"><span><strong>' . e($label) . '</strong><small>' . e($desc) . '</small></span>'
            . ($locked ? '<span class="always">' . e(t('Always active')) . '</span><input type="checkbox" checked disabled data-cat="' . $key . '">'
                       : '<input type="checkbox" data-cat="' . $key . '">') . '</label>';
    }
    $h .= '</div><div class="consent-btns">'
        . '<button type="button" class="btn" data-consent-accept>' . e(t('Accept')) . '</button>'
        . '<button type="button" class="btn ghost" data-consent-deny>' . e(t('Deny')) . '</button>'
        . '<button type="button" class="btn ghost" data-consent-prefs>' . e(t('View preferences')) . '</button>'
        . '<button type="button" class="btn" data-consent-save hidden>' . e(t('Save preferences')) . '</button>'
        . '</div>'
        . ($links ? '<p class="consent-links">' . implode(' · ', $links) . '</p>' : '')
        . '</div>'
        // Always-available way back to the choice (GDPR: withdrawing must be as easy as giving consent).
        . '<button type="button" class="consent-fab" data-consent-open data-consent-fab aria-haspopup="dialog" aria-controls="consent-box" hidden>'
        . '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-4.6-4.6A4 4 0 0 1 12 2Zm-4 7.5A1.5 1.5 0 1 1 8 12.5a1.5 1.5 0 0 1 0-3Zm7 6a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm-6 1.5a1 1 0 1 1 0 2 1 1 0 0 1 0-2Zm4-6a1 1 0 1 1 0 2 1 1 0 0 1 0-2Z"/></svg>'
        . '<span>' . e(t('Manage consent')) . '</span></button>';
    return $h;
}

/** The one stylesheet every public page loads: site.css followed by the colours and fonts from Settings. */
function public_css(): string
{
    static $site = null;
    $site ??= rtrim((string)@file_get_contents(ROOT . '/assets/css/site.css'));
    return $site . "\n\n/* ---- from Settings (colours, fonts) ---- */\n" . theme_css();
}

function theme_css(): string
{
    $c = fn(string $k) => preg_match('~^#[0-9a-f]{6}$~i', s($k)) ? s($k) : '#000000';
    $fam = fn(string $k) => '"' . preg_replace('~[^\w \-]~u', '', s($k)) . '"';
    $stack = 'Roboto, "Roboto Fallback", system-ui, -apple-system, "Segoe UI", "Helvetica Neue", Arial, "Noto Sans", sans-serif';
    // Self-hosted brand fonts: only @font-face rules pointing at this site's own /assets/fonts/ are kept.
    $faces = '';
    if (preg_match_all('~@font-face\s*\{[^{}<]*\}~', s('fonts_css'), $m)) {
        foreach ($m[0] as $rule) {
            if (preg_match('~url\(\s*[\'"]?+(?!/assets/fonts/|' . preg_quote(url('/assets/fonts/'), '~') . ')~', $rule)) continue;
            $faces .= $rule . "\n";
        }
    }
    $faces = fonts_swap($faces) . ROBOTO_FALLBACK_FACE . "\n";
    return $faces . ":root{--primary:{$c('color_primary')};--secondary:{$c('color_secondary')};--text:{$c('color_text')};"
        . "--accent:{$c('color_accent')};--box-info-bg:{$c('color_info_bg')};--box-info-head:{$c('color_info_head')};"
        . "--box-notice:{$c('color_notice')};--box-warning:{$c('color_warning')};--font:{$fam('font_body')}, {$stack};--font-head:{$fam('font_heading')}, {$stack};}\n"
        . theme_light_css($c);
}

/** robots.txt from Settings → Search & bots, never revealing the admin address. */
function robots_txt(): string
{
    $txt = str_replace("\r", '', trim(s('robots_txt')));
    if ($txt === '' || in_array(md5($txt), ROBOTS_OLD_DEFAULTS, true)) $txt = ROBOTS_DEFAULT;
    $secret = trim((string)cfg('admin_path', ''), '/');
    $lines = array_filter(explode("\n", $txt), fn($l) =>
        !preg_match('~^\s*sitemap\s*:~i', $l) && ($secret === '' || stripos($l, $secret) === false));
    return rtrim(implode("\n", $lines)) . "\n\nSitemap: " . abs_url(url('/sitemap.xml')) . "\n";
}

/** llms.txt (llmstxt.org): the site in plain text for AI assistants — what it is and where the content is. */
function llms_txt(): string
{
    $o = '# ' . s('site_title') . "\n\n> " . trim(preg_replace('~\s+~', ' ', strip_tags(s('tagline') ?: s('site_description')))) . "\n\n";
    $o .= "Personal website and blog. Content is in English; translations: " . (enabled_langs() ? implode(', ', array_map(fn($l) => LANGS[$l][0] . ' (' . lang_abs('/', $l) . ')', enabled_langs())) : 'none') . ".\n\n";
    $o .= "## Pages\n\n";
    foreach ([['/', 'Home'], ['/about-me/', 'About me'], ['/projects/', 'Projects'], ['/blog/', 'Blog']] as [$p, $t]) $o .= '- [' . $t . '](' . abs_url($p) . ")\n";
    foreach (all('SELECT title, slug FROM pages WHERE ' . PUB . ' ORDER BY title') as $p) $o .= '- [' . $p['title'] . '](' . abs_url(url('/' . $p['slug'] . '/')) . ")\n";
    $o .= "\n## Blog posts\n\n";
    foreach (all('SELECT title, slug, excerpt, seo_description FROM posts WHERE ' . PUB . ' ORDER BY published_at DESC') as $p) {
        $ex = trim(preg_replace('~\s+~', ' ', strip_tags((string)(($p['seo_description'] ?? '') ?: ($p['excerpt'] ?? '')))));
        $o .= '- [' . $p['title'] . '](' . abs_url(url('/' . $p['slug'] . '/')) . ')' . ($ex !== '' ? ': ' . mb_strimwidth($ex, 0, 160, '…') : '') . "\n";
    }
    $o .= "\n## Projects\n\n";
    foreach (all('SELECT title, slug FROM projects WHERE ' . PUB . ' ORDER BY started_on DESC') as $p) $o .= '- [' . $p['title'] . '](' . abs_url(url('/project/' . $p['slug'] . '/')) . ")\n";
    return $o;
}

/** Search title / description of an item in the current language; on a translated page only when that field is translated. */
function seo_field(string $entity, array $row, string $field): string
{
    $v = trim((string)($row[$field] ?? ''));
    if ($v === '' || lang() === 'en') return $v;
    return isset(tr_map($entity, lang())[(int)($row['id'] ?? 0)][$field]) ? $v : '';
}

/** JSON-LD: who the site is about (home and About) and what each blog post is (author, dates, image, language). */
function structured_data(array $meta, string $title, string $desc, string $url, string $image): string
{
    $person = ['@type' => 'Person', '@id' => abs_url('/') . '#person', 'name' => s('site_title'), 'url' => abs_url(url('/'))];
    if (($meta['nav'] ?? '') === 'home' || (($meta['nav'] ?? '') === 'about' && ($meta['path'] ?? '') === url('/about-me/'))) {
        [$photo] = og_image((string)s('profile_image'));
        $same = array_values(array_unique(array_filter(array_merge(
            [s('mastodon_url')],
            array_map(fn($c) => (string)$c['link'], (function () { try { return all("SELECT link FROM contacts WHERE status = 'published'"); } catch (Throwable $e) { return []; } })())
        ), fn($u) => preg_match('~^https://~i', (string)$u))));
        $p = $person + ['jobTitle' => s('tagline'), 'description' => $desc];
        if ($photo) $p['image'] = abs_url(media_url((string)s('profile_image')));
        if ($same) $p['sameAs'] = $same;
        $out = [$p];
        if (($meta['nav'] ?? '') === 'home') $out[] = ['@type' => 'WebSite', '@id' => abs_url('/') . '#website', 'url' => abs_url(url('/')), 'name' => s('site_title'),
            'inLanguage' => lang_html(lang()), 'publisher' => ['@id' => $person['@id']]];
        return jsonld(['@context' => 'https://schema.org', '@graph' => $out]);
    }
    if (($meta['kind'] ?? '') === 'post') {
        $post = ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => mb_substr($meta['headline'] ?? $title, 0, 110), 'description' => $desc,
            'url' => $url, 'mainEntityOfPage' => $url, 'inLanguage' => lang_html(lang()),
            'author' => $person, 'publisher' => $person];
        if ($image) $post['image'] = [$image];
        if (!empty($meta['published'])) $post['datePublished'] = date('c', strtotime($meta['published']));
        $mod = $meta['modified'] ?? $meta['published'] ?? null;
        if ($mod) $post['dateModified'] = date('c', strtotime($mod));
        if (!empty($meta['section'])) $post['articleSection'] = $meta['section'];
        return jsonld($post);
    }
    return '';
}

/** Light mode colours: chosen with the header button, or by the visitor's device when set to "follow". */
function theme_light_css(callable $c): string
{
    $vars = "color-scheme:light;--primary:{$c('color_light_bg')};--secondary:{$c('color_light_secondary')};--text:{$c('color_light_text')};"
        . "--accent:{$c('color_light_accent')};--accent-fill:{$c('color_accent')};--on-accent:#1F2022;";
    $css = 'html[data-theme="light"]{' . $vars . "}\n";
    if (s('theme_default') === 'system') $css .= '@media (prefers-color-scheme: light){html:not([data-theme="dark"]){' . $vars . "}}\n";
    return $css;
}

/* ---------------- pages ---------------- */

function page_home(): string
{
    $img = s('profile_image');
    $html = '<section class="hero"><div class="wrap hero-grid">'
        . '<div class="hero-text"><h1>' . e(s('site_title')) . '</h1>'
        . '<p class="tagline">' . e(s('tagline')) . '</p>'
        . '<p class="btns">'
        . '<a class="btn" href="' . e(url('/about-me/')) . '">' . e(t('About me')) . '</a>'
        . '<a class="btn" href="' . e(url('/projects/')) . '">' . e(t('View my Projects')) . '</a>'
        . '<a class="btn" href="' . e(url('/blog/')) . '">' . e(t('Read Blog')) . '</a>'
        . '<a class="btn ghost" href="' . e(url('/contact/')) . '">' . e(t('Contact me')) . '</a>'
        . '</p></div>';
    if ($img) {
        $html .= '<div class="hero-img">' . responsive_img($img, setting('profile_alt', s('site_title')), '(max-width: 900px) 240px, 440px', [], true) . '</div>';
    }
    $html .= '</div></section>';
    return layout('', $html, ['nav' => 'home', 'path' => url('/')]);
}

function page_about(): string
{
    // Ongoing jobs first (newest start on top), then finished ones from the most recent end date back.
    $exp = all('SELECT * FROM experience WHERE ' . PUB . ' ORDER BY ongoing DESC, COALESCE(end_date, start_date) DESC, start_date DESC, id DESC');
    $edu = all('SELECT * FROM education WHERE ' . PUB . ' ORDER BY sort_order ASC, start_date DESC, id ASC');
    $certs = all('SELECT * FROM certifications WHERE ' . PUB . ' ORDER BY issued_on DESC, id DESC');
    $img = s('profile_image');

    $h = '<section class="wrap page-head"><h1>' . e(t('About Me')) . '</h1></section>';
    $h .= '<section class="wrap about-grid"><div class="prose">' . modern_imgs(seo_imgs(s('about_html'))) . '</div>';
    if ($img) $h .= '<div class="about-img">' . responsive_img($img, setting('profile_alt', s('site_title')), '(max-width: 900px) 360px, 420px') . '</div>';
    $h .= '</section>';

    if ($exp) {
        $h .= '<section class="band"><div class="wrap"><h2>' . e(t('Work Experience')) . '</h2><div class="grid cards3">';
        foreach ($exp as $x) $h .= experience_card($x);
        $h .= '</div></div></section>';
    }
    if ($edu) {
        $h .= '<section class="wrap section"><h2>' . e(t('Education')) . '</h2><div class="grid cards3">';
        foreach ($edu as $ed) {
            $period = period($ed['start_date'], $ed['end_date'], (bool)$ed['ongoing']);
            $h .= '<article class="card edu"><span class="card-ico">' . icon(preg_match('~universit|college|fakultet|veleučili|sciences~i', $ed['institution']) ? 'college' : 'school') . '</span><h3>' . e($ed['institution']) . '</h3>'
                . ($ed['program'] ? '<p class="role">' . e($ed['program']) . '</p>' : '')
                . ($period ? '<p class="meta">' . e($period) . '</p>' : '')
                . ($ed['location'] ? '<p class="meta">' . e($ed['location']) . '</p>' : '')
                . ($ed['description'] ? '<p>' . nl2br(e($ed['description'])) . '</p>' : '')
                . '</article>';
        }
        $h .= '</div></section>';
    }
    if ($certs) {
        $h .= '<section class="band"><div class="wrap"><h2>' . e(t('Certifications')) . '</h2><div class="grid cards3">';
        foreach ($certs as $c) {
            $link = $c['credential_url'];
            $a = fn(string $inner) => $link ? '<a href="' . e($link) . '" target="_blank" rel="noopener noreferrer">' . $inner . '</a>' : $inner;
            $h .= '<article class="card cert">'
                . ($c['image'] ? $a('<img src="' . e(media_url($c['image'])) . '" alt="' . e($c['image_alt'] ?: ($c['issuer'] ? t('%1$s issued by %2$s', $c['name'], $c['issuer']) : $c['name'])) . '" loading="lazy">') : '')
                . '<h3>' . e($c['name']) . '</h3>'
                . ($c['issuer'] ? '<p class="meta with-ico">' . icon('certificate') . e(t('Issued by: %s', $c['issuer'])) . '</p>' : '')
                . ($c['issued_on'] ? '<p class="meta">' . e(t('Issued on: %s', fmt_month($c['issued_on']))) . '</p>' : '')
                . ($link ? '<p class="with-ico">' . icon('verify') . '<a class="more" href="' . e($link) . '" target="_blank" rel="noopener noreferrer">' . e(t('View credential')) . '<span class="sr"> ' . e(t('(opens in new tab)')) . '</span></a></p>' : '')
                . '</article>';
        }
        $h .= '</div></div></section>';
    }

    return layout(t('About Me'), $h, [
        'nav' => 'about', 'path' => url('/about-me/'), 'type' => 'article',
        'description' => excerpt_from(s('about_html'), 30) ?: s('meta_description'),
    ]);
}

function period(?string $start, ?string $end, bool $ongoing): string
{
    if (!$start && !$end) return $ongoing ? t('Ongoing') : '';
    $a = fmt_month($start);
    $b = $ongoing ? t('Present') : fmt_month($end);
    return $a && $b ? "{$a} – {$b}" : ($a ?: $b);
}

function experience_card(array $x): string
{
    return '<article class="card exp">'
        . '<h3>' . e($x['company']) . '</h3>'
        . '<p class="role">' . e($x['role']) . '</p>'
        . '<dl class="facts">'
        . '<div>' . icon('calendar') . '<dt>' . e(t('Start date')) . '</dt><dd>' . e(fmt_month($x['start_date'])) . '</dd></div>'
        . '<div>' . icon('calendar') . '<dt>' . e(t('End date')) . '</dt><dd>' . ($x['ongoing'] ? '<span class="now">' . e(t('Ongoing')) . '</span>' : e(fmt_month($x['end_date']) ?: '—')) . '</dd></div>'
        . '<div>' . icon('service') . '<dt>' . e(t('Work type')) . '</dt><dd>' . e(t(WORK_TYPES[$x['work_type']] ?? $x['work_type'])) . '</dd></div>'
        . '</dl>'
        . (trim(strip_tags((string)$x['description'])) !== '' ? '<a class="more" href="' . e(url('/work-experience/' . $x['slug'] . '/')) . '">' . e(t('Learn more')) . '<span class="sr"> ' . e(t('about %s', $x['role'])) . '</span></a>' : '')
        . '</article>';
}

function page_experience(array $x): string
{
    $h = '<article class="wrap narrow single">'
        . '<p class="kicker"><a href="' . e(url('/about-me/')) . '">← ' . e(t('Work Experience')) . '</a></p>'
        . '<h1>' . e($x['role']) . '</h1>'
        . '<p class="lead">' . e($x['company']) . '</p>'
        . '<dl class="facts inline-facts">'
        . '<div><dt>' . e(t('Start date')) . '</dt><dd>' . e(fmt_month($x['start_date'])) . '</dd></div>'
        . '<div><dt>' . e(t('End date')) . '</dt><dd>' . ($x['ongoing'] ? e(t('Ongoing')) : e(fmt_month($x['end_date']) ?: '—')) . '</dd></div>'
        . '<div><dt>' . e(t('Work type')) . '</dt><dd>' . e(t(WORK_TYPES[$x['work_type']] ?? '')) . '</dd></div>'
        . '</dl>'
        . '<div class="prose">' . modern_imgs(seo_imgs((string)$x['description'])) . '</div>'
        . '</article>';
    return layout($x['role'], $h, [
        'nav' => 'about', 'type' => 'article', 'path' => url('/work-experience/' . $x['slug'] . '/'),
        'description' => excerpt_from((string)$x['description'], 30) ?: t('%1$s at %2$s', $x['role'], $x['company']),
    ]);
}

function page_projects(): string
{
    $rows = all('SELECT * FROM projects WHERE ' . PUB . ' ORDER BY started_on DESC, id DESC');
    $h = '<section class="wrap page-head"><h1>' . e(t('Projects')) . '</h1></section><section class="wrap section"><div class="grid cards3">';
    foreach ($rows as $p) {
        $u = url('/project/' . $p['slug'] . '/');
        $h .= '<article class="card proj">'
            . ($p['featured_image'] ? '<a class="thumb" href="' . e($u) . '" tabindex="-1" aria-hidden="true">' . responsive_img($p['featured_image'], '', '(max-width: 660px) calc(100vw - 32px), (max-width: 1000px) 50vw, 380px') . '</a>' : '')
            . '<h3><a href="' . e($u) . '">' . e($p['title']) . '</a></h3>'
            . ($p['client'] ? '<p class="meta">' . e($p['client']) . '</p>' : '')
            . '<a class="more" href="' . e($u) . '">' . e(t('Learn more')) . '<span class="sr"> ' . e(t('about %s', $p['title'])) . '</span></a>'
            . '</article>';
    }
    if (!$rows) $h .= '<p>' . e(t('No projects yet.')) . '</p>';
    $h .= '</div></section>';
    return layout(t('Projects'), $h, ['nav' => 'projects', 'path' => url('/projects/')]);
}

/* ---------------- showcase layout (projects & affiliates) ---------------- */

/** Small line icons for the fact tiles. Inline SVG, no icon font or external request. */
/** Built-in icon name → slot in the icon library (storage/icons/<slot>.svg). */
const ICON_MAP = ['person' => 'customer', 'globe' => 'edge-browser', 'calendar' => 'calendar', 'code' => 'code',
    'service' => 'work-type', 'company' => 'company', 'promo' => 'coupon', 'tag' => 'work-type',
    'map' => 'map', 'certificate' => 'certificate', 'verify' => 'certificate-verification', 'school' => 'school', 'college' => 'college',
    'post' => 'scroll'];

function icon(string $name): string
{
    // Your own (licensed) icon from the library wins; the line icons below are the fallback.
    if (isset(ICON_MAP[$name]) && ($custom = icon_custom(ICON_MAP[$name]))) return $custom;
    $paths = [
        'person'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
        'company'  => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2M10 21v-3h4v3"/>',
        'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M8 14h2M12 14h2M16 14h0M8 17h2M12 17h2"/>',
        'code'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 8h18M10 12l-2 2 2 2M14 12l2 2-2 2"/>',
        'service'  => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M3 12h18"/>',
        'promo'    => '<path d="M3 9V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a3 3 0 0 0 0 6v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a3 3 0 0 0 0-6z"/><path d="M9.5 15l5-6"/><circle cx="9.5" cy="9.5" r=".9"/><circle cx="14.5" cy="14.5" r=".9"/>',
        'tag'      => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'map'      => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'certificate' => '<rect x="3" y="4" width="18" height="13" rx="1.5"/><circle cx="12" cy="10" r="3"/><path d="M10 13l-1 7 3-2 3 2-1-7"/>',
        'verify'   => '<path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'school'   => '<path d="M3 9l9-5 9 5-9 5z"/><path d="M7 11.5V16c0 1.5 2.2 3 5 3s5-1.5 5-3v-4.5"/>',
        'college'  => '<path d="M3 9l9-5 9 5-9 5z"/><path d="M7 11.5V16c0 1.5 2.2 3 5 3s5-1.5 5-3v-4.5M21 9v6"/>',
        'post'     => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4M9 11h7M9 14h7M9 17h5"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/** One icon tile + label + value. $valueHtml must already be escaped. */
function fact(string $icon, string $label, string $valueHtml): string
{
    return '<li class="fact"><span class="fact-ico">' . icon($icon) . '</span>'
        . '<span class="fact-txt"><span class="fact-lbl">' . e(t($label)) . '</span><span class="fact-val">' . $valueHtml . '</span></span></li>';
}

function ext_link(string $href, string $text): string
{
    return '<a href="' . e($href) . '" target="_blank" rel="noopener noreferrer">' . e($text) . '<span class="sr"> ' . e(t('(opens in new tab)')) . '</span></a>';
}

/** Image + title header, description on the left, fact tiles on the right. */
function showcase(array $row, string $kicker, string $kickerHref, array $facts, string $extra = ''): string
{
    $h = '<article class="showcase">';
    $h .= '<header class="wrap sc-head">';
    if (!empty($row['featured_image'])) {
        $h .= '<div class="sc-img">' . responsive_img($row['featured_image'], $row['featured_alt'] ?: (media_alt($row['featured_image']) ?: $row['title']), '(max-width: 900px) calc(100vw - 32px), 380px', [], true) . '</div>';
    }
    $h .= '<div class="sc-title"><p class="kicker"><a href="' . e($kickerHref) . '">← ' . e(t($kicker)) . '</a></p><h1>' . e($row['title']) . '</h1></div></header>';
    $h .= '<div class="wrap sc-body"><div class="prose">' . modern_imgs(seo_imgs((string)($row['content'] ?: ''))) . '</div>';
    $h .= '<aside class="sc-facts" aria-label="' . e(t('Details')) . '"><ul>' . implode('', $facts) . '</ul>' . $extra . '</aside></div>';
    return $h . '</article>';
}

function page_project(array $p): string
{
    $services = entity_terms('projects', (int)$p['id'], 'service');
    $tools = entity_terms('projects', (int)$p['id'], 'tool');
    $prev = one('SELECT id, title, slug FROM projects WHERE ' . PUB . ' AND (started_on < ? OR (started_on = ? AND id < ?)) ORDER BY started_on DESC, id DESC LIMIT 1',
        [$p['started_on'] ?? '0000-00-00', $p['started_on'] ?? '0000-00-00', $p['id']]);
    $next = one('SELECT id, title, slug FROM projects WHERE ' . PUB . ' AND (started_on > ? OR (started_on = ? AND id > ?)) ORDER BY started_on ASC, id ASC LIMIT 1',
        [$p['started_on'] ?? '0000-00-00', $p['started_on'] ?? '0000-00-00', $p['id']]);

    $facts = [];
    if ($p['client']) $facts[] = fact('person', 'Client', e($p['client']));
    if ($p['url']) $facts[] = fact('globe', 'Website', ext_link($p['url'], $p['url']));
    if ($p['started_on']) $facts[] = fact('calendar', 'Started', e(fmt_month($p['started_on'])));
    if ($services) $facts[] = fact('service', 'Services', e(implode(', ', array_column($services, 'name'))));
    if ($tools) $facts[] = fact('code', 'Tools', e(implode(', ', array_column($tools, 'name'))));

    $h = showcase($p, 'Projects', url('/projects/'), $facts);
    $h .= prev_next(
        $prev ? ['Previous', $prev['title'], url('/project/' . $prev['slug'] . '/')] : null,
        $next ? ['Next', $next['title'], url('/project/' . $next['slug'] . '/')] : null
    );

    return layout($p['title'], $h, [
        'nav' => 'projects', 'type' => 'article', 'path' => url('/project/' . $p['slug'] . '/'),
        'image' => $p['featured_image'], 'description' => excerpt_from((string)$p['content'], 30),
        'seo_title' => seo_field('projects', $p, 'seo_title'), 'seo_description' => seo_field('projects', $p, 'seo_description'),
        'image_alt' => $p['featured_alt'] ?? '', 'modified' => $p['modified_at'] ?? null,
    ]);
}

/* ---------------- affiliates & sponsorships ---------------- */

function promo_html(array $a, bool $withCopy = true): string
{
    if ($a['has_code'] !== 'yes' || trim((string)$a['promo_code']) === '') return '<span class="nocode">' . e(t('NO CODE')) . '</span>';
    $code = (string)$a['promo_code'];
    return '<span class="promo"><code class="promo-code">' . e($code) . '</code>'
        . ($withCopy ? '<button type="button" class="copy-btn" data-copy="' . e($code) . '" data-copied="' . e(t('Copied!')) . '">' . e(t('Copy')) . '</button>' : '') . '</span>';
}

function affiliate_kind_label(string $k): string
{
    return t(AFFILIATE_KINDS[$k] ?? ucfirst($k));
}

function affiliates_grid(): string
{
    $rows = all('SELECT * FROM affiliates WHERE ' . PUB . ' ORDER BY published_at DESC, id DESC');
    if (!$rows) return '';
    $h = '<section class="wrap section"><div class="grid cards3">';
    foreach ($rows as $a) {
        $u = url('/affiliate/' . $a['slug'] . '/');
        $h .= '<article class="card proj aff">'
            . ($a['featured_image'] ? '<a class="thumb" href="' . e($u) . '" tabindex="-1" aria-hidden="true">' . responsive_img($a['featured_image'], '', '(max-width: 660px) calc(100vw - 32px), (max-width: 1000px) 50vw, 380px') . '</a>' : '')
            . '<p class="badge-kind">' . e(affiliate_kind_label($a['kind'])) . '</p>'
            . '<h3><a href="' . e($u) . '">' . e($a['title']) . '</a></h3>'
            . ($a['company_name'] ? '<p class="meta">' . e(trim($a['company_name'])) . '</p>' : '')
            . '<p class="aff-code">' . e(t('Promo code:')) . ' ' . promo_html($a, false) . '</p>'
            . '<a class="more" href="' . e($u) . '">' . e(t('Learn More')) . '<span class="sr"> ' . e(t('about %s', $a['title'])) . '</span></a>'
            . '</article>';
    }
    return $h . '</div></section>';
}

function page_affiliate(array $a): string
{
    $facts = [];
    if ($a['company_name']) {
        $name = trim($a['company_name']);
        $facts[] = fact('company', 'Company', $a['company_url'] ? ext_link($a['company_url'], $name) : e($name));
    }
    if ($a['link_url']) $facts[] = fact('globe', 'URL', '<a href="' . e($a['link_url']) . '" target="_blank" rel="noopener noreferrer sponsored">' . e(t('Visit Site')) . '<span class="sr"> ' . e(t('(opens in new tab)')) . '</span></a>');
    $facts[] = fact('promo', 'Promo Code', promo_html($a));
    $facts[] = fact('tag', 'Type', e(affiliate_kind_label($a['kind'])));

    $note = '<p class="sc-note">' . ($a['kind'] === 'sponsored'
        ? e(t('Sponsored content. I only accept sponsorships for products I have used myself.'))
        : e(t('This is an %s link: if you sign up through it, I may earn a commission at no extra cost to you.', mb_strtolower(affiliate_kind_label($a['kind']))))) . '</p>';

    $h = showcase($a, 'Affiliates and Sponsorships', url('/affiliates-and-sponsorships/'), $facts, $note);
    return layout($a['title'], $h, [
        'type' => 'article', 'path' => url('/affiliate/' . $a['slug'] . '/'),
        'image' => $a['featured_image'], 'description' => excerpt_from((string)$a['content'], 30) ?: $a['title'],
        'seo_title' => seo_field('affiliates', $a, 'seo_title'), 'seo_description' => seo_field('affiliates', $a, 'seo_description'), 'image_alt' => $a['featured_alt'] ?? '',
    ]);
}

function prev_next(?array $prev, ?array $next): string
{
    if (!$prev && !$next) return '';
    $h = '<nav class="wrap prevnext" aria-label="' . e(t('More')) . '">';
    $h .= $prev ? '<a class="prev" href="' . e($prev[2]) . '"><span>← ' . e(t($prev[0])) . '</span>' . e($prev[1]) . '</a>' : '<span></span>';
    $h .= $next ? '<a class="next" href="' . e($next[2]) . '"><span>' . e(t($next[0])) . ' →</span>' . e($next[1]) . '</a>' : '<span></span>';
    return $h . '</nav>';
}

/** @return array{0:int,1:string,2:string} */
function page_blog(int $page = 1, ?string $catSlug = null): array
{
    $per = max(1, (int)s('posts_per_page'));
    $page = max(1, $page);
    $where = PUB . ' AND published_at <= ?';
    $params = [now()];
    $cat = null;
    if ($catSlug !== null) {
        $cat = one("SELECT * FROM terms WHERE taxonomy = 'category' AND slug = ?", [$catSlug]);
        if (!$cat) return not_found();
        $where .= ' AND ' . post_in_cat();
        $params[] = $cat['id'];
        $params[] = $cat['id'];
    }
    $total = (int)one("SELECT COUNT(*) c FROM posts WHERE {$where}", $params)['c'];
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) return not_found();
    $rows = all("SELECT * FROM posts WHERE {$where} ORDER BY published_at DESC, id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);

    $base = $cat ? '/category/' . $cat['slug'] . '/' : '/blog/';
    $cats = all("SELECT * FROM (SELECT t.id, t.name, t.slug, (SELECT COUNT(*) FROM posts p WHERE p.status = 'published' AND p.published_at <= ?
                   AND (p.category_id = t.id OR EXISTS (SELECT 1 FROM entity_terms et WHERE et.entity = 'posts' AND et.entity_id = p.id AND et.term_id = t.id))) n
                 FROM terms t WHERE t.taxonomy = 'category') x WHERE n > 0 ORDER BY name", [now()]);
    // Category names in the page's language (this query reads a sub-select, so translate them here).
    if (lang() !== 'en') { $tm = tr_map('terms', lang()); foreach ($cats as &$c) $c['name'] = $tm[(int)$c['id']]['name'] ?? $c['name']; unset($c); }

    $h = '<section class="wrap page-head"><h1>' . ($cat ? e($cat['name']) : e(t('Blog'))) . '</h1>';
    if ($cats) {
        $h .= '<nav class="cats" aria-label="' . e(t('Categories')) . '"><a href="' . e(url('/blog/')) . '"' . (!$cat ? ' aria-current="page"' : '') . '>' . e(t('All')) . '</a>';
        foreach ($cats as $c) $h .= '<a href="' . e(url('/category/' . $c['slug'] . '/')) . '"' . ($cat && $cat['slug'] === $c['slug'] ? ' aria-current="page"' : '') . '>' . e($c['name']) . '</a>';
        $h .= '</nav>';
    }
    $h .= '</section><section class="wrap section"><div class="grid cards3">';
    foreach ($rows as $p) $h .= post_card($p);
    if (!$rows) $h .= '<p>' . e(t('No posts yet.')) . '</p>';
    $h .= '</div>' . pager($page, $pages, $base) . '</section>';

    $title = ($cat ? $cat['name'] : t('Blog')) . ($page > 1 ? ' — ' . t('Page %d', $page) : '');
    return [200, layout($title, $h, ['nav' => 'blog', 'path' => url($base . ($page > 1 ? "page/{$page}/" : ''))]), 'text/html'];
}

function post_card(array $p): string
{
    $u = url('/' . $p['slug'] . '/');
    $ex = $p['excerpt'] ?: excerpt_from((string)$p['content']);
    return '<article class="card post">'
        . ($p['featured_image'] ? '<a class="thumb" href="' . e($u) . '" tabindex="-1" aria-hidden="true">' . responsive_img($p['featured_image'], '', '(max-width: 660px) calc(100vw - 32px), (max-width: 1000px) 50vw, 380px') . '</a>' : '')
        . '<h3><a href="' . e($u) . '">' . e($p['title']) . '</a></h3>'
        . '<p class="meta"><time datetime="' . e(date('Y-m-d', strtotime($p['published_at']))) . '">' . e(fmt_date($p['published_at'])) . '</time></p>'
        . '<p>' . e($ex) . '</p>'
        . '<a class="more" href="' . e($u) . '">' . e(t('Read more')) . ' &gt;<span class="sr"> ' . e($p['title']) . '</span></a>'
        . '</article>';
}

function pager(int $page, int $pages, string $base): string
{
    if ($pages < 2) return '';
    $link = fn(int $n) => url($base . ($n > 1 ? "page/{$n}/" : ''));
    $h = '<nav class="pager" aria-label="' . e(t('Pages')) . '"><span class="of">' . e(t('Page %1$d of %2$d', $page, $pages)) . '</span>';
    $h .= $page > 1 ? '<a href="' . e($link($page - 1)) . '" rel="prev">' . e(t('Previous')) . '</a>' : '<span class="off">' . e(t('Previous')) . '</span>';
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= 1) {
            $h .= $i === $page ? '<span class="cur" aria-current="page">' . $i . '</span>' : '<a href="' . e($link($i)) . '">' . $i . '</a>';
        } elseif (abs($i - $page) === 2) {
            $h .= '<span class="gap">…</span>';
        }
    }
    $h .= $page < $pages ? '<a href="' . e($link($page + 1)) . '" rel="next">' . e(t('Next')) . '</a>' : '<span class="off">' . e(t('Next')) . '</span>';
    return $h . '</nav>';
}

function page_post(array $p): string
{
    $cats = post_categories($p);
    $prev = one('SELECT id, title, slug FROM posts WHERE ' . PUB . ' AND published_at < ? ORDER BY published_at DESC LIMIT 1', [$p['published_at']]);
    $next = one('SELECT id, title, slug FROM posts WHERE ' . PUB . ' AND published_at > ? AND published_at <= ? ORDER BY published_at ASC LIMIT 1', [$p['published_at'], now()]);
    $mins = reading_minutes((string)$p['content']);
    $time = '<time datetime="' . e(date('c', strtotime($p['published_at']))) . '">' . e(date('d.m.Y', strtotime($p['published_at']))) . '</time>';

    $h = '<article class="single post-single">';
    // Reading progress for phones (the sidebar version is used on wide screens).
    $h .= '<div class="read-bar" aria-hidden="true"><span data-read-fill></span></div>';
    $h .= '<header class="wrap post-hero' . ($p['featured_image'] ? '' : ' no-image') . '">';
    if ($p['featured_image']) {
        $h .= responsive_img($p['featured_image'], $p['featured_alt'] ?: (media_alt($p['featured_image']) ?: $p['title']), '(max-width: 760px) calc(100vw - 32px), (max-width: 1180px) 37vw, 420px', ['class' => 'post-hero-img'], true);
    }
    $h .= '<div class="post-hero-txt"><h1>' . e($p['title']) . '</h1><p class="kicker">' . e(t('%d min read', $mins)) . '</p></div></header>';

    $h .= '<div class="wrap post-grid">';
    $h .= '<div class="prose post-body" data-read-body>' . modern_imgs(seo_imgs((string)$p['content'])) . '</div>';
    $h .= '<aside class="post-side" aria-label="' . e(t('About this post')) . '">';
    $h .= '<div class="side-block read-block"><p class="side-title" id="read-lbl">' . e(t('Reading progress')) . '</p>'
        . '<progress max="100" value="0" aria-labelledby="read-lbl" data-read-progress></progress>'
        . '<p class="read-txt"><span data-read-pct>0</span>% ' . e(t('read')) . ' · ' . $mins . ' ' . e(t('min')) . '</p></div>';
    $h .= '<div class="side-block"><p class="side-title">' . e(t('Share on')) . '</p>' . share_buttons($p['title'], abs_url(url('/' . $p['slug'] . '/'))) . '</div>';
    $h .= '<ul class="side-facts">';
    if ($cats) $h .= fact('post', count($cats) > 1 ? 'Categories' : 'Category', implode(', ', array_map(
        fn($c) => '<a href="' . e(url('/category/' . $c['slug'] . '/')) . '">' . e($c['name']) . '</a>', $cats)));
    $h .= fact('calendar', 'Posted on', $time);
    $h .= '</ul></aside></div>';

    $h .= prev_next(
        $prev ? ['Previous', $prev['title'], url('/' . $prev['slug'] . '/')] : null,
        $next ? ['Next', $next['title'], url('/' . $next['slug'] . '/')] : null
    );
    $h .= '</article>';

    return layout($p['title'], $h, [
        'nav' => 'blog', 'type' => 'article', 'path' => url('/' . $p['slug'] . '/'),
        'image' => $p['featured_image'], 'published' => $p['published_at'],
        'description' => $p['excerpt'] ?: excerpt_from((string)$p['content'], 30),
        'seo_title' => seo_field('posts', $p, 'seo_title'), 'seo_description' => seo_field('posts', $p, 'seo_description'),
        'image_alt' => $p['featured_alt'] ?? '', 'modified' => $p['modified_at'] ?? null, 'kind' => 'post', 'headline' => $p['title'],
        'section' => post_categories($p)[0]['name'] ?? '',
    ]);
}

/**
 * Plain share links: no scripts, no counters, nothing loads from the networks until a visitor clicks.
 * Built-in marks: Simple Icons (CC0); LinkedIn from Font Awesome Free (CC BY 4.0, fontawesome.com/license/free).
 */
const SHARE_NETWORKS = [
    'facebook' => ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u={url}', 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a9 9 0 0 1 1.141.195v3.325a9 9 0 0 0-.653-.036 27 27 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.7 1.7 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647', '0 0 24 24'],
    'x'        => ['X', 'https://x.com/intent/tweet?url={url}&text={text}', 'M14.234 10.162 22.977 0h-2.072l-7.591 8.824L7.251 0H.258l9.168 13.343L.258 24H2.33l8.016-9.318L16.749 24h6.993zm-2.837 3.299-.929-1.329L3.076 1.56h3.182l5.965 8.532.929 1.329 7.754 11.09h-3.182z', '0 0 24 24'],
    'threads'  => ['Threads', 'https://www.threads.net/intent/post?text={text}%20{url}', 'M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.589 12c.027 3.086.718 5.496 2.057 7.164 1.43 1.783 3.631 2.698 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.964-.065-1.19.408-2.285 1.33-3.082.88-.76 2.119-1.207 3.583-1.291a14 14 0 0 1 3.02.142c-.126-.742-.375-1.332-.75-1.757-.513-.586-1.308-.883-2.359-.89h-.029c-.844 0-1.992.232-2.721 1.32l-1.757-1.18c.98-1.454 2.568-2.256 4.478-2.256h.044c3.194.02 5.097 1.975 5.287 5.388q.163.07.321.142c1.49.7 2.58 1.761 3.154 3.07.797 1.82.871 4.79-1.548 7.158-1.85 1.81-4.094 2.628-7.277 2.65Zm1.003-11.69q-.362 0-.739.021c-1.836.103-2.98.946-2.916 2.143.067 1.256 1.452 1.839 2.784 1.767 1.224-.065 2.818-.543 3.086-3.71a10.5 10.5 0 0 0-2.215-.221', '0 0 24 24'],
    'linkedin' => ['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url={url}', 'M100.3 448H7.4V148.9h92.9zM53.8 108.1C24.1 108.1 0 83.5 0 53.8c0-14.3 5.7-27.9 15.8-38S39.6 0 53.8 0s27.9 5.7 38 15.8 15.8 23.8 15.8 38c0 29.7-24.1 54.3-53.8 54.3M447.9 448h-92.7V302.4c0-34.7-.7-79.2-48.3-79.2-48.3 0-55.7 37.7-55.7 76.7V448h-92.8V148.9h89.1v40.8h1.3c12.4-23.5 42.7-48.3 87.9-48.3 94 0 111.3 61.9 111.3 142.3V448z', '0 0 448 512'],
    'whatsapp' => ['WhatsApp', 'https://wa.me/?text={text}%20{url}', 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52s.198-.298.298-.497c.099-.198.05-.371-.025-.52s-.669-1.612-.916-2.207c-.242-.579-.487-.5-.669-.51a13 13 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074s2.096 3.2 5.077 4.487c.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413s.248-1.289.173-1.413c-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.82 9.82 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.82 11.82 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.9 11.9 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.82 11.82 0 0 0-3.48-8.413', '0 0 24 24'],
    'telegram' => ['Telegram', 'https://t.me/share/url?url={url}&text={text}', 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zm4.962 7.224c.1-.002.321.023.465.14a.5.5 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024q-.159.037-5.061 3.345-.72.495-1.302.48c-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789q.04-.324.893-.663 5.247-2.286 6.998-3.014c3.332-1.386 4.025-1.627 4.476-1.635', '0 0 24 24'],
    'email'    => ['email', 'mailto:?subject={text}&body={url}', 'M2 5h20v14H2zM2 5l10 8 10-8', '0 0 24 24'],
];

function share_buttons(string $title, string $url): string
{
    $h = '<ul class="share">';
    foreach (SHARE_NETWORKS as $key => [$name, $tpl, $path, $box]) {
        $href = strtr($tpl, ['{url}' => rawurlencode($url), '{text}' => rawurlencode($title)]);
        $svg = $key === 'email'
            ? '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . $path . '"/></svg>'
            : '<svg class="ico" viewBox="' . $box . '" fill="currentColor" aria-hidden="true" focusable="false"><path d="' . $path . '"/></svg>';
        $label = $key === 'email' ? t('Share by email') : t('Share on %s (opens in a new tab)', $name);
        $h .= '<li><a class="share-' . $key . '" href="' . e($href) . '"'
            . ($key === 'email' ? '' : ' target="_blank" rel="noopener noreferrer"')
            . ' aria-label="' . e($label) . '" title="' . e($key === 'email' ? t('Email') : $name) . '">' . $svg . '</a></li>';
    }
    return $h . '</ul>';
}

function page_page(array $pg): string
{
    $body = modern_imgs(seo_imgs(contacts_place((string)$pg['content'], $pg['slug'] === 'contact')));
    $h = '<section class="wrap narrow page-head"><h1>' . e($pg['title']) . '</h1></section>'
        . '<section class="wrap narrow prose page-body">' . $body . '</section>';
    if ($pg['slug'] === 'affiliates-and-sponsorships') $h .= affiliates_grid();
    return layout($pg['title'], $h, [
        'nav' => $pg['slug'] === 'contact' ? 'contact' : '', 'path' => url('/' . $pg['slug'] . '/'),
        'description' => excerpt_from(str_ireplace(CONTACT_MARKER, '', (string)$pg['content']), 30) ?: null,
        'seo_title' => seo_field('pages', $pg, 'seo_title'), 'seo_description' => seo_field('pages', $pg, 'seo_description'),
    ]);
}

/* ---------------- feeds ---------------- */

function feed_xml(): string
{
    $rows = all('SELECT * FROM posts WHERE ' . PUB . ' AND published_at <= ? ORDER BY published_at DESC LIMIT 20', [now()]);
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
        . '<title>' . e(s('site_title')) . '</title><link>' . e(abs_url(url('/'))) . '</link>'
        . '<description>' . e(s('tagline')) . '</description><language>en-us</language>'
        . '<atom:link href="' . e(abs_url(url('/feed.xml'))) . '" rel="self" type="application/rss+xml"/>';
    foreach ($rows as $p) {
        $link = abs_url(url('/' . $p['slug'] . '/'));
        $x .= '<item><title>' . e($p['title']) . '</title><link>' . e($link) . '</link><guid>' . e($link) . '</guid>'
            . '<pubDate>' . e(date(DATE_RSS, strtotime($p['published_at']))) . '</pubDate>'
            . '<description>' . e($p['excerpt'] ?: excerpt_from((string)$p['content'], 60)) . '</description></item>';
    }
    return $x . '</channel></rss>';
}

function sitemap_xml(): string
{
    // [path, lastmod, translation key "entity:id" or null]
    $max = fn(?string ...$d) => ($d = array_filter($d)) ? max($d) : null;
    $mod = fn(array $r) => $max($r['published_at'] ?? null, $r['modified_at'] ?? null);
    $live = 'published_at <= ?';
    $items = [];
    $newestPost = null;
    foreach (all("SELECT id, slug, published_at, modified_at FROM posts WHERE " . PUB . " AND {$live} ORDER BY published_at DESC", [now()]) as $r) {
        $items[] = ['/' . $r['slug'] . '/', $mod($r), 'posts:' . $r['id']];
        $newestPost = $max($newestPost, $r['published_at']);   // lists change when a post is published, not when an old one is edited
    }
    $newestProject = null;
    foreach (all('SELECT id, slug, modified_at FROM projects WHERE ' . PUB) as $r) { $items[] = ['/project/' . $r['slug'] . '/', $r['modified_at'], 'projects:' . $r['id']]; $newestProject = $max($newestProject, $r['modified_at']); }
    $newestJob = null;
    foreach (all('SELECT id, slug, modified_at FROM experience WHERE ' . PUB) as $r) { $items[] = ['/work-experience/' . $r['slug'] . '/', $r['modified_at'], 'experience:' . $r['id']]; $newestJob = $max($newestJob, $r['modified_at']); }
    foreach (all('SELECT id, slug, published_at, modified_at FROM affiliates WHERE ' . PUB) as $r) $items[] = ['/affiliate/' . $r['slug'] . '/', $mod($r), 'affiliates:' . $r['id']];
    foreach (all('SELECT id, slug, modified_at FROM pages WHERE ' . PUB) as $r) $items[] = ['/' . $r['slug'] . '/', $r['modified_at'], 'pages:' . $r['id']];
    // Category pages, dated by their newest post.
    foreach (all("SELECT id, slug FROM terms WHERE taxonomy = 'category'") as $c) {
        $d = one('SELECT MAX(published_at) d FROM posts WHERE ' . PUB . " AND {$live} AND " . post_in_cat(), [now(), $c['id'], $c['id']])['d'] ?? null;
        if ($d) $items[] = ['/category/' . $c['slug'] . '/', $d, null];
    }
    // Overview pages first, dated by what they list.
    array_unshift($items, ['/', $max($newestPost, $newestProject)], ['/blog/', $newestPost], ['/projects/', $newestProject], ['/about-me/', $newestJob]);

    // Translated copies: dated by the newer of the original and its translation.
    $langs = enabled_langs();
    $trDates = [];
    if ($langs) {
        try {
            foreach (all_raw("SELECT entity, entity_id, lang, MAX(updated_at) d FROM translations WHERE status = 'published' GROUP BY entity, entity_id, lang") as $t)
                $trDates[$t['entity'] . ':' . $t['entity_id']][$t['lang']] = $t['d'];
        } catch (Throwable $e) { /* no translations table yet */ }
    }
    $base = rtrim((string)cfg('base_path', ''), '/');
    $loc = fn(string $path, string $l) => lang_abs($path, $l);
    $day = fn(?string $d) => $d ? date('Y-m-d', strtotime($d)) : null;

    $GLOBALS['SITEMAP_STATE'] = [];
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    foreach ($items as $it) {
        [$path, $date] = $it;
        $key = $it[2] ?? null;
        $alts = '';
        if ($langs) {
            foreach (array_merge(['en'], $langs) as $al) $alts .= '    <xhtml:link rel="alternate" hreflang="' . e(lang_html($al)) . '" href="' . e($loc($path, $al)) . '"/>' . "\n";
            $alts .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . e($loc($path, 'en')) . '"/>' . "\n";
        }
        foreach (array_merge(['en'], $langs) as $l) {
            $d = $l === 'en' ? $date : $max($date, $key ? ($trDates[$key][$l] ?? null) : null);
            $GLOBALS['SITEMAP_STATE'][$loc($path, $l)] = (string)$d;   // for IndexNow: what changed since last time
            $x .= "  <url>\n    <loc>" . e($loc($path, $l)) . "</loc>\n" . ($d ? '    <lastmod>' . $day($d) . "</lastmod>\n" : '') . $alts . "  </url>\n";
        }
    }
    return $x . "</urlset>\n";
}

/** Every public path, for the static export. */
function all_public_paths(): array
{
    $paths = ['/', '/about-me/', '/projects/', '/feed.xml', '/sitemap.xml', '/robots.txt', '/llms.txt', '/theme.css'];
    $per = max(1, (int)s('posts_per_page'));
    $n = (int)one('SELECT COUNT(*) c FROM posts WHERE ' . PUB . ' AND published_at <= ?', [now()])['c'];
    $paths[] = '/blog/';
    for ($i = 2; $i <= (int)ceil($n / $per); $i++) $paths[] = "/blog/page/{$i}/";
    foreach (all("SELECT t.id, t.slug FROM terms t WHERE taxonomy = 'category'") as $c) {
        $cn = (int)one('SELECT COUNT(*) c FROM posts WHERE ' . PUB . ' AND published_at <= ? AND ' . post_in_cat(), [now(), $c['id'], $c['id']])['c'];
        if (!$cn) continue;
        $paths[] = '/category/' . $c['slug'] . '/';
        for ($i = 2; $i <= (int)ceil($cn / $per); $i++) $paths[] = '/category/' . $c['slug'] . "/page/{$i}/";
    }
    foreach (all('SELECT slug FROM posts WHERE ' . PUB . ' AND published_at <= ?', [now()]) as $r) $paths[] = '/' . $r['slug'] . '/';
    foreach (all('SELECT slug FROM projects WHERE ' . PUB) as $r) $paths[] = '/project/' . $r['slug'] . '/';
    foreach (all('SELECT slug FROM experience WHERE ' . PUB) as $r) $paths[] = '/work-experience/' . $r['slug'] . '/';
    foreach (all('SELECT slug FROM affiliates WHERE ' . PUB) as $r) $paths[] = '/affiliate/' . $r['slug'] . '/';
    foreach (all('SELECT slug FROM pages WHERE ' . PUB) as $r) $paths[] = '/' . $r['slug'] . '/';
    return array_values(array_unique($paths));
}

/**
 * Rewrite site-absolute links (/about-me/) into relative ones (../about-me/index.html)
 * so an exported copy works from any folder, sub-path or preview host.
 */
function relativize_html(string $html, string $pagePath): string
{
    $base = rtrim((string)cfg('base_path', ''), '/');
    $dir = trim($pagePath, '/');
    $depth = $dir === '' ? 0 : substr_count($dir, '/') + (str_ends_with($pagePath, '/') ? 1 : 0);
    $up = str_repeat('../', $depth);
    return (string)preg_replace_callback('~\b(href|src)="(' . preg_quote($base, '~') . '/[^"#?]*)([?#][^"]*)?"~',
        function ($m) use ($base, $up) {
            $p = substr($m[2], strlen($base) + 1);
            if ($p === '' || str_ends_with($p, '/')) $p .= 'index.html';
            return $m[1] . '="' . $up . $p . ($m[3] ?? '') . '"';
        }, $html);
}

/** Write the whole public site (HTML, CSS, JS, uploads) into a .zip. Returns the page count. */
function build_static_zip(string $zipPath, bool $relative): int
{
    $GLOBALS['STATIC_EXPORT'] = true;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot write ' . $zipPath);
    $base = rtrim((string)cfg('base_path', ''), '/');
    $count = 0;
    foreach (all_public_paths() as $path) {
        [$status, $html] = route($path);
        if ($status !== 200) continue;
        $file = ltrim($path, '/');
        if ($file === '' || str_ends_with($file, '/')) $file .= 'index.html';
        if ($relative && str_ends_with($file, '.html')) $html = relativize_html($html, $path);
        $zip->addFromString($file, $html);
        $count++;
    }
    [, $nf] = not_found();
    $zip->addFromString('404.html', $relative ? relativize_html($nf, '/') : $nf);
    $zip->addFromString('.htaccess', "Options -Indexes\nErrorDocument 404 {$base}/404.html\n");
    $addTree = function (string $src, string $prefix) use ($zip) {
        if (!is_dir($src)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getFilename() === '.htaccess') continue;
            $rel = substr($f->getPathname(), strlen($src) + 1);
            $zip->addFile($f->getPathname(), $prefix . str_replace('\\', '/', $rel));
        }
    };
    $addTree(ROOT . '/assets/css', 'assets/css/');
    $addTree(ROOT . '/assets/js', 'assets/js/');
    $addTree(public_dir() . '/assets/fonts', 'assets/fonts/');
    $addTree(public_dir() . '/uploads', 'uploads/');
    foreach (FAVICON_FILES as $f) if (is_file(public_dir() . '/' . $f)) { $zip->addFile(public_dir() . '/' . $f, $f); $count++; }
    $zip->close();
    return $count;
}


