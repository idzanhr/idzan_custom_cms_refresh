<?php
declare(strict_types=1);

/**
 * WordPress → CMS import. Two sources feed the same saver:
 *   - a WordPress export file (Tools → Export → All content, a .xml "WXR" file), recommended;
 *   - the live site's public REST API (wp-json), as a fallback.
 *
 * Images are taken, in this order, from:
 *   1. a copy of wp-content/uploads placed in /import/uploads/ (or /import/uploads.zip),
 *   2. downloading them from the old site (if allowed),
 *   3. otherwise the old address is kept, so nothing breaks while the old site is still up.
 * Items are matched by slug, so running an import again updates instead of duplicating.
 */

const WP_IMPORT_TYPES = [
    'post'            => 'Blog posts + categories',
    'project'         => 'Projects (+ services & tools)',
    'work-experience' => 'Work experience',
    'certificate'     => 'Certifications',
    'affiliate'       => 'Affiliates & sponsorships',
    'page'            => 'Pages (imprint, disclaimer, privacy…)',
];

/** Pages this CMS builds itself, so their WordPress versions are skipped. */
const WP_SKIP_PAGES = ['home', 'homepage', 'about-me', 'blog', 'projects'];

/** Croatian, Bosnian, Slovenian and Serbian (Latin + Cyrillic) copies of pages — never imported. */
const WP_TRANSLATION_SLUGS = ['o-meni', 'pocetna-stranica', 'domaca-stran', 'o-mne', 'naslovna', 'kontakt', 'projekti', 'blog-2', 'blog-3', 'blog-4', 'blog-5'];

/** True for translated copies (Cyrillic text in slug/title, or a known translated slug). */
function wp_is_translation(array $it): bool
{
    $slug = rawurldecode((string)$it['slug']);
    if (preg_match('~\p{Cyrillic}~u', $slug . ' ' . $it['title'])) return true;
    if (in_array(slugify($slug), WP_TRANSLATION_SLUGS, true)) return true;
    return (bool)preg_match('~^(o-meni|pocetna-stranica|domaca-stran)(-\d+)?$~', slugify($slug));
}

final class WpImporter
{
    public string $oldHost;
    public bool $download;
    public array $log = [];
    public array $stats = [];
    /** meta keys seen per type that weren't mapped — shown in the log so mappings can be added */
    public array $unmapped = [];
    private array $imgCache = [];
    private ?ZipArchive $zip = null;
    private array $zipIndex = [];

    /** Extra local copies of wp-content/uploads, e.g. the live WordPress folder on the same server. */
    public array $uploadDirs = [];

    public function __construct(string $oldSite, bool $download, array $uploadDirs = [])
    {
        $this->oldHost = strtolower((string)parse_url($oldSite, PHP_URL_HOST));
        $this->download = $download;
        $this->uploadDirs = array_values(array_filter(array_merge(
            array_map(fn($d) => rtrim($d, '/'), $uploadDirs),
            [ROOT . '/import/uploads', ROOT . '/import/wp-content/uploads']
        ), 'is_dir'));
        foreach ($this->uploadDirs as $d) if (!str_starts_with($d, ROOT . '/import')) $this->log[] = "Using images from {$d}.";
        $zipPath = ROOT . '/import/uploads.zip';
        if (is_file($zipPath) && class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($zipPath) === true) {
                $this->zip = $z;
                for ($i = 0; $i < $z->numFiles; $i++) {
                    $name = (string)$z->getNameIndex($i);
                    $pos = strpos($name, 'uploads/');
                    $rel = $pos === false ? $name : substr($name, $pos + 8);
                    $this->zipIndex[$rel] = $name;
                }
                $this->log[] = 'Using images from import/uploads.zip (' . $z->numFiles . ' files).';
            }
        }
        if (is_dir(ROOT . '/import/uploads')) $this->log[] = 'Using images from the import/uploads/ folder.';
    }

    /* ---------------- images ---------------- */

    /** Path below wp-content/uploads/, or null if the URL isn't a WordPress upload. */
    private function uploadRel(string $url): ?string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $pos = strpos($path, '/wp-content/uploads/');
        if ($pos === false) return null;
        return rawurldecode(substr($path, $pos + 20));
    }

    /** Original upload for a resized variant: photo-1024x683.jpg → photo.jpg */
    private static function originalOf(string $rel): ?string
    {
        $o = preg_replace('~-\d{2,5}x\d{2,5}(\.[a-z0-9]+)$~i', '$1', $rel);
        return $o !== $rel ? $o : null;
    }

    /** Returns a CMS uploads/ path, or null when the image can't be brought over. */
    public function image(string $url, string $hint = ''): ?string
    {
        $url = html_entity_decode(trim($url));
        if ($url === '') return null;
        if (array_key_exists($url, $this->imgCache)) return $this->imgCache[$url];
        $rel = $this->uploadRel($url);
        $result = null;
        $key = $rel !== null ? 'rel:' . (self::originalOf($rel) ?? $rel) : null;
        if ($key && array_key_exists($key, $this->imgCache) && $this->imgCache[$key] !== null) return $this->imgCache[$url] = $this->imgCache[$key];
        if ($rel !== null) {
            $tries = array_filter([self::originalOf($rel), $rel]);
            foreach ($tries as $r) {
                foreach (array_map(fn($d) => $d . '/' . $r, $this->uploadDirs) as $file) {
                    if (is_file($file)) { $result = $this->storeBytes((string)file_get_contents($file), basename($r), $hint); break 2; }
                }
                if ($this->zip && isset($this->zipIndex[$r])) {
                    $bin = $this->zip->getFromName($this->zipIndex[$r]);
                    if ($bin !== false) { $result = $this->storeBytes($bin, basename($r), $hint); break; }
                }
            }
        }
        if ($result === null && $this->download && preg_match('~^https?://~i', $url)) {
            $cands = [$url];
            if ($rel !== null && ($o = self::originalOf($rel))) array_unshift($cands, str_replace(rawurlencode(basename($rel)), rawurlencode(basename($o)), str_replace(basename($rel), basename($o), $url)));
            foreach ($cands as $u) {
                $bin = $this->fetch($u);
                if ($bin !== null) { $result = $this->storeBytes($bin, basename((string)parse_url($u, PHP_URL_PATH)), $hint); break; }
            }
        }
        if ($result === null) $this->stats['images']['not copied'] = ($this->stats['images']['not copied'] ?? 0) + 1;
        else $this->stats['images']['copied'] = ($this->stats['images']['copied'] ?? 0) + 1;
        if ($key && $result !== null) $this->imgCache[$key] = $result;
        return $this->imgCache[$url] = $result;
    }

    private function fetch(string $url): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 40, 'header' => "User-Agent: php-cms-importer/1.1\r\n"]]);
        $bin = @file_get_contents($url, false, $ctx);
        return ($bin === false || strlen($bin) < 100) ? null : $bin;
    }

    private function storeBytes(string $bin, string $name, string $hint): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wpimg');
        file_put_contents($tmp, $bin);
        try {
            return store_upload(['tmp_name' => $tmp, 'name' => $name, 'size' => strlen($bin), 'error' => UPLOAD_ERR_OK], $name);
        } catch (RuntimeException $e) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    /* ---------------- content ---------------- */

    public function content(string $html, array $meta = []): string
    {
        $html = trim($html);
        if ($html === '' && !empty($meta['_elementor_data'])) $html = self::elementorToHtml((string)$meta['_elementor_data']);

        // Classic-editor / Elementor text-editor content is stored without paragraphs; WordPress adds them on display.
        $classic = !str_contains($html, '<!-- wp:');

        // Shortcodes: [caption] → figure, [embed] → URL line, everything else unwrapped.
        $html = preg_replace_callback('~\[caption[^\]]*\](.*?)\[/caption\]~is', function ($m) {
            $inner = $m[1];
            if (preg_match('~(<a[^>]*>)?\s*<img[^>]*>\s*(</a>)?~is', $inner, $img)) {
                $cap = trim(strip_tags(str_replace($img[0], '', $inner)));
                return '<figure>' . $img[0] . ($cap !== '' ? '<figcaption>' . e($cap) . '</figcaption>' : '') . '</figure>';
            }
            return $inner;
        }, $html);
        $html = preg_replace('~\[embed\](.*?)\[/embed\]~is', "\n\n$1\n\n", $html);
        $html = preg_replace('~\[/?[a-zA-Z][\w-]*(?:\s[^\]]*)?\]~', '', $html);
        if ($classic) $html = self::autop((string)$html);


        // YouTube links on their own (WordPress embeds) → privacy-friendly player.
        $html = preg_replace_callback('~(?:<p>|<div[^>]*>|^|\n)\s*(https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)([\w-]{11})\S*)\s*(?:</p>|</div>|$|\n)~im',
            fn($m) => '<p><iframe src="https://www.youtube-nocookie.com/embed/' . $m[2] . '" title="YouTube video" width="560" height="315" allowfullscreen></iframe></p>', $html);

        // Images: bring files over, prefer the full-size original.
        $html = preg_replace_callback('~<img\b[^>]*>~i', function ($m) {
            $tag = $m[0];
            $src = null;
            if (preg_match('~srcset="([^"]+)"~i', $tag, $s)) {
                $best = 0;
                foreach (explode(',', $s[1]) as $cand) {
                    $p = preg_split('~\s+~', trim($cand));
                    if ((int)($p[1] ?? 0) >= $best) { $best = (int)($p[1] ?? 0); $src = $p[0]; }
                }
            }
            if (!$src && preg_match('~\bsrc="([^"]+)"~i', $tag, $s)) $src = $s[1];
            if (!$src) return '';
            $alt = preg_match('~\balt="([^"]*)"~i', $tag, $a) ? $a[1] : '';
            $local = $this->image($src, html_entity_decode($alt));
            return '<img src="' . e($local ? media_url($local) : html_entity_decode($src)) . '" alt="' . $alt . '">';
        }, (string)$html);

        // Links to pages on the old site → same address on this site (slugs are kept).
        if ($this->oldHost !== '') {
            $host = preg_quote($this->oldHost, '~');
            $html = preg_replace_callback('~href="https?://(?:www\.)?' . $host . '(/[^"]*)?"~i', function ($m) {
                $path = $m[1] ?? '/';
                if (str_starts_with($path, '/wp-content/') || str_starts_with($path, '/wp-admin')) return $m[0];
                return 'href="' . e(url($path ?: '/')) . '"';
            }, (string)$html);
        }
        // Keep heading levels within what the site styles (h2–h4).
        $html = preg_replace(['~<(/?)h1\b~i', '~<(/?)h[56]\b~i'], ['<$1h2', '<$1h4'], (string)$html);
        // Icon-only links (SVG icons are removed) keep their accessible name as visible text.
        $html = preg_replace_callback('~<a\b([^>]*)>(.*?)</a>~is', function ($m) {
            if (trim(strip_tags($m[2], '<img>')) !== '' || stripos($m[2], '<svg') !== false) return $m[0];
            if (preg_match('~aria-label="([^"]+)"~i', $m[1], $l) && preg_match('~href="([^"]+)"~i', $m[1], $h)) {
                $label = $l[1];
                if (str_starts_with($h[1], 'mailto:')) $label .= ': ' . substr($h[1], 7);
                return '<a href="' . $h[1] . '">' . $label . '</a>';
            }
            return '';
        }, (string)$html);
        // Links straight to image files ("link to media file", lightbox links) → this site's copy.
        $html = $this->localizeUploads((string)$html);
        $html = clean_html((string)$html);
        // Page-builder icon boxes repeat each link (icon link + text link) — keep one.
        $html = preg_replace('~(<a href="([^"]+)"[^>]*>[^<]*</a>)\s*(?:<br>\s*)?<a href="\2"[^>]*>(?:\s|<br>)*[^<]*</a>~u', '$1', $html);
        $html = preg_replace('~(<a\b[^>]*>)\s*(?:<br>\s*)+~', '$1', (string)$html);
        $html = preg_replace('~(?:\s*<br>)+\s*(</a>)~', '$1', (string)$html);
        // Collapse the indentation builders leave inside text.
        $html = preg_replace('~[ \t]*\n[ \t]*~', "\n", (string)$html);
        $html = preg_replace('~(?:\s|&nbsp;)+</p>~u', '</p>', $html);
        $html = preg_replace('~<p>(?:\s|&nbsp;)+~u', '<p>', $html);
        return trim((string)$html);
    }

    /**
     * Every address of a file in the old site's wp-content/uploads (absolute on the old host, or
     * relative) is copied into this CMS and replaced with the local address. Addresses of files that
     * can't be found are left as they are. $wholeValue = the text is one image field ('uploads/…' back).
     */
    public function localizeUploads(string $text, bool $wholeValue = false): string
    {
        if (!str_contains($text, '/wp-content/uploads/')) return $text;
        $hosts = array_unique(array_filter([$this->oldHost, preg_replace('~^www\.~', '', $this->oldHost),
            strtolower((string)parse_url((string)cfg('site_url'), PHP_URL_HOST))]));
        $h = implode('|', array_map(fn($x) => '(?:www\.)?' . preg_quote(preg_replace('~^www\.~', '', $x), '~'), $hosts));
        // Absolute on the old host, or relative (at the start of a value, or after a quote, bracket, space or =).
        $re = '~(?:(?:https?:)?//(?:' . $h . ')|(?<=["\'(\s=])|^)/wp-content/uploads/[^"\'\s<>()]+~i';
        return (string)preg_replace_callback($re, function ($m) use ($wholeValue) {
            $local = $this->image($m[0]);
            if ($local === null) { $this->missing[$m[0]] = true; return $m[0]; }
            $this->fixed++;
            return $wholeValue ? $local : media_url($local);
        }, $text);
    }

    /** Addresses localizeUploads() couldn't find a file for, and how many it fixed. */
    public array $missing = [];
    public int $fixed = 0;

    /** Port of WordPress' wpautop(): blank lines become paragraphs, single newlines become <br>. */
    public static function autop(string $pee): string
    {
        if (trim($pee) === '') return '';
        $pre = [];
        $pee = preg_replace_callback('~<pre\b.*?</pre>~is', function ($m) use (&$pre) { $k = '<pre wp-pre-tag-' . count($pre) . '></pre>'; $pre[$k] = $m[0]; return $k; }, $pee);
        $pee .= "\n";
        $pee = preg_replace('|<br\s*/?>\s*<br\s*/?>|', "\n\n", $pee);
        $all = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary|iframe)';
        $pee = preg_replace('!(<' . $all . '[\s/>])!', "\n\n$1", $pee);
        $pee = preg_replace('!(</' . $all . '>)!', "$1\n\n", $pee);
        $pee = str_replace(["\r\n", "\r"], "\n", $pee);
        $pee = preg_replace("/\n\n+/", "\n\n", $pee);
        $out = '';
        foreach (preg_split('/\n\s*\n/', $pee, -1, PREG_SPLIT_NO_EMPTY) as $t) $out .= '<p>' . trim($t, "\n") . "</p>\n";
        $pee = $out;
        $pee = preg_replace('|<p>\s*</p>|', '', $pee);
        $pee = preg_replace('!<p>([^<]+)</(div|address|form)>!', '<p>$1</p></$2>', $pee);
        $pee = preg_replace('!<p>\s*(</?' . $all . '[^>]*>)\s*</p>!', '$1', $pee);
        $pee = preg_replace('|<p>(<li.+?)</p>|', '$1', $pee);
        $pee = preg_replace('|<p><blockquote([^>]*)>|i', '<blockquote$1><p>', $pee);
        $pee = str_replace('</blockquote></p>', '</p></blockquote>', $pee);
        $pee = preg_replace('!<p>\s*(</?' . $all . '[^>]*>)!', '$1', $pee);
        $pee = preg_replace('!(</?' . $all . '[^>]*>)\s*</p>!', '$1', $pee);
        $pee = preg_replace('|(?<!<br />)\s*\n(?!\s*$)|', "<br />\n", $pee);
        $pee = preg_replace('!(</?' . $all . '[^>]*>)\s*<br />!', '$1', $pee);
        $pee = preg_replace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $pee);
        $pee = preg_replace("|\n</p>$|", '</p>', $pee);
        return strtr($pee, $pre);
    }

    /** Pull readable content out of an Elementor layout when a page has no normal content. */
    public static function elementorToHtml(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) return '';
        $out = '';
        $walk = function (array $els) use (&$walk, &$out) {
            foreach ($els as $el) {
                $set = $el['settings'] ?? [];
                switch ($el['widgetType'] ?? '') {
                    case 'text-editor': $out .= (string)($set['editor'] ?? ''); break;
                    case 'heading': if (!empty($set['title'])) $out .= '<h2>' . strip_tags((string)$set['title']) . '</h2>'; break;
                    case 'image': if (!empty($set['image']['url'])) $out .= '<p><img src="' . e($set['image']['url']) . '" alt=""></p>'; break;
                    case 'video': if (!empty($set['youtube_url'])) $out .= "\n\n" . $set['youtube_url'] . "\n\n"; break;
                }
                if (!empty($el['elements'])) $walk($el['elements']);
            }
        };
        $walk($data);
        return $out;
    }

    /* ---------------- saving ---------------- */

    private function count(string $type, string $result): void
    {
        $this->stats[$type][$result] = ($this->stats[$type][$result] ?? 0) + 1;
    }

    private function noteUnmapped(string $type, array $meta, array $known): void
    {
        foreach ($meta as $k => $v) {
            if ($k === '' || $k[0] === '_' || in_array($k, $known, true) || in_array($k, $this->used, true) || $v === '' || $v === null) continue;
            if (preg_match('~^(rank_math|_yoast|cmplz|elementor|ekit|wpr_|footnotes|ast-|site-|theme-|stick|header|footer|adv-|iawmlf|classic-editor)~', $k)) continue;
            $this->unmapped[$type][$k] = true;
        }
    }

    private array $used = [];

    private function pick(array $meta, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($meta[$k]) && trim((string)$meta[$k]) !== '') { $this->used[] = $k; return trim((string)$meta[$k]); }
        }
        // fuzzy: any meta key containing one of the words (e.g. project_start_date)
        foreach ($keys as $want) {
            foreach ($meta as $k => $v) {
                if ($k === '' || $k[0] === '_' || trim((string)$v) === '' || in_array($k, $this->used, true)) continue;
                if (str_contains(strtolower((string)$k), $want)) { $this->used[] = $k; return trim((string)$v); }
            }
        }
        return null;
    }

    /** ACF dates are stored as Ymd (20241202); also accepts normal date strings. */
    public static function date(?string $v): ?string
    {
        if ($v && preg_match('~^(\d{4})(\d{2})(\d{2})$~', $v, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        if ($v && preg_match('~\d{4}~', $v) && ($t = strtotime($v))) return date('Y-m-d', $t);
        return null;
    }

    private function upsert(string $table, array $data, string $key = 'slug'): string
    {
        $data['updated_at'] = now();
        $row = one("SELECT id FROM {$table} WHERE {$key} = ?", [$data[$key]]);
        if ($row) {
            $sets = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($data)));
            q("UPDATE {$table} SET {$sets} WHERE id = ?", [...array_values($data), $row['id']]);
            $this->lastId = (int)$row['id'];
            return 'updated';
        }
        $data['created_at'] = now();
        q("INSERT INTO {$table} (" . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
        $this->lastId = (int)db()->lastInsertId();
        return 'added';
    }
    public int $lastId = 0;

    /**
     * Save one normalized item:
     * [type, slug, title, html, excerpt, date, status(published|draft), featured(url|null), featured_alt, terms[tax=>names], meta[key=>value], parent]
     */
    public function save(array $it): void
    {
        $this->used = [];
        if (wp_is_translation($it)) { $this->count('translations', 'skipped'); return; }
        $type = $it['type'];
        $slug = slugify($it['slug'] ?: $it['title']);
        $title = $it['title'];
        $meta = $it['meta'] ?? [];
        $status = $it['status'];
        $date = $it['date'];
        $img = null;
        $alt = (string)($it['featured_alt'] ?? '');
        if (!empty($it['featured'])) {
            $img = $this->image($it['featured'], $alt ?: $title) ?? $it['featured'];
        }
        $html = $this->content((string)$it['html'], $meta);

        switch ($type) {
            case 'post':
                $catIds = [];
                foreach ($it['terms']['category'] ?? [] as $c) {
                    if (strcasecmp($c, 'Uncategorized') !== 0) $catIds[] = ensure_term('category', $c);
                }
                $catIds = array_values(array_unique($catIds));
                $catId = $catIds[0] ?? null;
                $ex = trim(html_entity_decode(strip_tags((string)($it['excerpt'] ?? '')), ENT_QUOTES, 'UTF-8'));
                $data = ['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'excerpt' => $ex,
                         'content' => $html, 'status' => $status, 'published_at' => $date];
                if ($img) { $data['featured_image'] = $img; $data['featured_alt'] = $alt ?: $title; }
                $this->count($type, $this->upsert('posts', $data));
                set_entity_terms('posts', $this->lastId, 'category', array_slice($catIds, 1));
                break;

            case 'project':
                $known = ['client', 'client_name', 'project_client', 'url', 'project_url', 'project_link', 'project_image', 'project_screenshots', 'website', 'link', 'site_url', 'start_date', 'date', 'project_date', 'started', 'start'];
                $data = ['title' => $title, 'slug' => $slug, 'content' => $html, 'status' => $status];
                if ($img) { $data['featured_image'] = $img; $data['featured_alt'] = $alt ?: $title; }
                if ($v = $this->pick($meta, ['client', 'client_name', 'project_client', 'customer'])) $data['client'] = $v;
                if ($v = $this->pick($meta, ['project_url', 'website', 'site_url', 'url', 'link'])) $data['url'] = preg_match('~^https?://~', $v) ? $v : 'https://' . $v;
                $start = self::date($this->pick($meta, ['start_date', 'project_date', 'started', 'start', 'date']));
                $existing = one('SELECT started_on FROM projects WHERE slug = ?', [$slug]);
                if ($start) $data['started_on'] = $start;
                elseif (!$existing || !$existing['started_on']) $data['started_on'] = substr($date, 0, 10);
                $this->noteUnmapped($type, $meta, $known);
                $this->count($type, $this->upsert('projects', $data));
                $pid = $this->lastId;
                $svc = array_map(fn($n) => ensure_term('service', $n), $it['terms']['project-category'] ?? []);
                $tls = array_map(fn($n) => ensure_term('tool', $n), $it['terms']['project-technology'] ?? []);
                if ($svc) set_entity_terms('projects', $pid, 'service', $svc);
                if ($tls) set_entity_terms('projects', $pid, 'tool', $tls);
                break;

            case 'work-experience':
                $known = ['company', 'position_type', 'location_type', 'location', 'start_month_and_year', 'ongoing', 'start_month_and_year_copy', 'end_month_and_year'];
                $this->noteUnmapped($type, $meta, $known);
                $loc = strtolower((string)($meta['location_type'] ?? ''));
                $wt = str_contains($loc, 'hybrid') ? 'hybrid' : (str_contains($loc, 'remote') ? 'remote' : ($loc !== '' ? 'onsite' : 'remote'));
                $ongoing = !empty($meta['ongoing']) && preg_match('~ongoing|yes|^1$|true~i', (string)$meta['ongoing']);
                $end = $ongoing ? null : self::date($meta['end_month_and_year'] ?? $meta['start_month_and_year_copy'] ?? null);
                $this->count($type, $this->upsert('experience', [
                    'company' => (string)($meta['company'] ?? ''), 'role' => $title, 'slug' => $slug,
                    'start_date' => self::date($meta['start_month_and_year'] ?? null) ?? substr($date, 0, 10),
                    'end_date' => $end, 'ongoing' => (int)$ongoing, 'work_type' => $wt,
                    'description' => $html, 'status' => $status,
                ]));
                break;

            case 'certificate':
                $known = ['issued_by', 'issuer', 'issued_on', 'issue_date', 'issued_month_and_year', 'url', 'link', 'credential_url', 'certificate_url', 'certification_url_source', 'date'];
                $data = ['name' => $title, 'status' => $status];
                if ($img) { $data['image'] = $img; $data['image_alt'] = $alt ?: $title; }
                if ($v = $this->pick($meta, ['issued_by', 'issuer', 'issuing_organization', 'organization'])) $data['issuer'] = $v;
                if ($v = $this->pick($meta, ['credential_url', 'certificate_url', 'url', 'link'])) $data['credential_url'] = $v;
                $on = self::date($this->pick($meta, ['issued_month_and_year', 'issued_on', 'issue_date', 'date']));
                $data['issued_on'] = $on ?? substr($date, 0, 10);
                $this->noteUnmapped($type, $meta, $known);
                $this->count($type, $this->upsert('certifications', $data, 'name'));
                break;

            case 'affiliate':
                $known = ['company_name', 'company_url', 'url', 'link', 'have_coupon', 'coupon_code', 'affiliate_or_sponsored_content'];
                $this->noteUnmapped($type, $meta, $known);
                $kindRaw = strtolower((string)($meta['affiliate_or_sponsored_content'] ?? 'affiliate'));
                $kind = str_contains($kindRaw, 'spons') ? 'sponsored' : (str_contains($kindRaw, 'refer') ? 'referral' : 'affiliate');
                $code = trim((string)($meta['coupon_code'] ?? ''));
                $hasCode = preg_match('~^(yes|1|true)$~i', trim((string)($meta['have_coupon'] ?? ''))) && $code !== '';
                $data = [
                    'title' => $title, 'slug' => $slug, 'content' => $html, 'status' => $status, 'published_at' => $date,
                    'company_name' => trim((string)($meta['company_name'] ?? '')),
                    'company_url' => trim((string)($meta['company_url'] ?? '')),
                    'link_url' => trim((string)($meta['url'] ?? $meta['link'] ?? '')),
                    'kind' => $kind, 'has_code' => $hasCode ? 'yes' : 'no', 'promo_code' => $hasCode ? $code : '',
                ];
                if ($img) { $data['featured_image'] = $img; $data['featured_alt'] = $alt ?: $title; }
                $this->count($type, $this->upsert('affiliates', $data));
                break;

            case 'page':
                if (in_array($slug, WP_SKIP_PAGES, true) || preg_match('~^blog-\d+$~', $slug) || !empty($it['parent'])) { $this->count($type, 'skipped'); break; }
                if (trim(strip_tags($html, '<img><iframe>')) === '') {
                    $this->log[] = "“{$title}” skipped: its text is generated by a WordPress plugin, so there is nothing to copy. Write it under Pages.";
                    $this->count($type, 'skipped');
                    break;
                }
                $existing = one('SELECT in_footer FROM pages WHERE slug = ?', [$slug]);
                // Contact page: its icon list becomes Contact links (kept as they are if you already have some).
                $hasContacts = false;
                if ($slug === 'contact') {
                    try { $hasContacts = (int)one('SELECT COUNT(*) c FROM contacts')['c'] > 0; } catch (Throwable $e) {}
                    if ($hasContacts) $html = contacts_extract($html)[0];
                }
                $html = legacy_boxes($slug, $html);
                $data = ['title' => $title, 'slug' => $slug, 'content' => $html, 'status' => $status];
                if (!$existing) $data['in_footer'] = 1;
                $this->count($type, $this->upsert('pages', $data));
                if ($slug === 'contact' && !$hasContacts && ($n = contacts_convert_page())) $this->log[] = "Contact page: {$n} contact links created (Admin → Contact links).";
                break;
        }
    }

    /* ---------------- source 1: WordPress export file (WXR) ---------------- */

    /** @return array{0: array[], 1: string} [normalized items, site url] */
    public static function readWxr(string $file): array
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_PARSEHUGE | LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if (!$xml || !isset($xml->channel)) throw new RuntimeException('This is not a WordPress export file (expected an .xml from Tools → Export).');
        $ns = $xml->getDocNamespaces(true);
        $wpNs = $ns['wp'] ?? 'http://wordpress.org/export/1.2/';
        $contentNs = $ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $excerptNs = $ns['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/';
        $site = (string)($xml->channel->children($wpNs)->base_blog_url ?: $xml->channel->link);

        $attachments = [];
        $items = [];
        $fonts = [];
        foreach ($xml->channel->item as $node) {
            $wp = $node->children($wpNs);
            $type = (string)$wp->post_type;
            $meta = [];
            foreach ($wp->postmeta as $pm) $meta[(string)$pm->meta_key] = (string)$pm->meta_value;
            if ($type === 'elementor_font') {
                if (!empty($meta['elementor_font_face'])) $fonts[] = ['family' => (string)$node->title, 'css' => $meta['elementor_font_face']];
                continue;
            }
            if ($type === 'attachment') {
                $attachments[(int)$wp->post_id] = ['url' => (string)$wp->attachment_url, 'alt' => $meta['_wp_attachment_image_alt'] ?? (string)$node->title];
                continue;
            }
            if (!isset(WP_IMPORT_TYPES[$type])) continue;
            $st = (string)$wp->status;
            if (in_array($st, ['trash', 'auto-draft', 'inherit'], true)) continue;
            $terms = [];
            foreach ($node->category as $c) {
                $dom = (string)$c['domain'];
                $terms[$dom][] = html_entity_decode((string)$c, ENT_QUOTES, 'UTF-8');
            }
            $gmt = (string)$wp->post_date;
            $items[] = [
                'type' => $type,
                'slug' => rawurldecode((string)$wp->post_name),
                'title' => html_entity_decode((string)$node->title, ENT_QUOTES, 'UTF-8'),
                'html' => (string)$node->children($contentNs)->encoded,
                'excerpt' => (string)$node->children($excerptNs)->encoded,
                'date' => ($gmt && $gmt !== '0000-00-00 00:00:00') ? $gmt : now(),
                'status' => in_array($st, ['publish', 'future'], true) ? 'published' : 'draft',
                'thumb_id' => (int)($meta['_thumbnail_id'] ?? 0),
                'terms' => $terms,
                'meta' => $meta,
                'parent' => (int)$wp->post_parent,
            ];
        }
        foreach ($items as &$it) {
            $a = $attachments[$it['thumb_id']] ?? null;
            $it['featured'] = $a['url'] ?? null;
            $it['featured_alt'] = $a['alt'] ?? '';
        }
        unset($it);
        $icons = array_values(array_filter(array_column($attachments, 'url'), fn($u) => str_ends_with(strtolower($u), '.svg')));
        return [$items, $site, $icons, $fonts];
    }

    /* ---------------- source 2: live site REST API ---------------- */

    public static function readRest(string $site, string $type): array
    {
        $base = ['post' => 'posts', 'page' => 'pages'][$type] ?? $type;
        $out = [];
        for ($page = 1; $page < 100; $page++) {
            $url = "{$site}/wp-json/wp/v2/{$base}?per_page=50&page={$page}&_embed=1";
            $ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nUser-Agent: php-cms-importer/1.1\r\n"]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) throw new RuntimeException('Could not reach ' . $url);
            $status = 0; $pages = 1;
            // PHP 8.4+ has http_get_last_response_headers(); the magic variable is deprecated in 8.5.
            $hdrs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
            foreach ($hdrs as $h) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $status = (int)$m[1];
                if (stripos($h, 'X-WP-TotalPages:') === 0) $pages = (int)trim(substr($h, 16));
            }
            if ($status === 400 && str_contains($body, 'rest_post_invalid_page_number')) break;
            if ($status >= 400) throw new RuntimeException("HTTP {$status} from {$url}");
            $batch = json_decode($body, true);
            if (!is_array($batch)) throw new RuntimeException('Not a JSON response from ' . $url);
            foreach ($batch as $r) {
                $terms = [];
                foreach ($r['_embedded']['wp:term'] ?? [] as $group) foreach ($group as $t) {
                    $terms[$t['taxonomy'] ?? ''][] = html_entity_decode((string)$t['name'], ENT_QUOTES, 'UTF-8');
                }
                $fm = $r['_embedded']['wp:featuredmedia'][0] ?? null;
                $txt = fn($f) => trim(html_entity_decode(strip_tags((string)($f['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $out[] = [
                    'type' => $type, 'slug' => (string)($r['slug'] ?? ''), 'title' => $txt($r['title'] ?? null),
                    'html' => (string)($r['content']['rendered'] ?? ''), 'excerpt' => $txt($r['excerpt'] ?? null),
                    'date' => date('Y-m-d H:i:s', strtotime((string)($r['date'] ?? 'now'))), 'status' => 'published',
                    'featured' => $fm['source_url'] ?? null,
                    'featured_alt' => trim((string)($fm['alt_text'] ?? '')) ?: ($fm ? $txt($fm['title'] ?? null) : ''),
                    'terms' => $terms, 'meta' => is_array($r['acf'] ?? null) ? $r['acf'] : [], 'parent' => (int)($r['parent'] ?? 0),
                ];
            }
            if ($page >= $pages) break;
        }
        return $out;
    }

    /** Bring SVG icons into the icon library (storage/icons, outside the web root), sanitized. */
    public function icons(array $urls): void
    {
        foreach ($urls as $url) {
            $slot = icon_slot_from_filename((string)parse_url($url, PHP_URL_PATH));
            if (!$slot) continue;
            $rel = $this->uploadRel($url);
            $svg = null;
            foreach ($this->uploadDirs as $d) if ($rel !== null && is_file($d . '/' . $rel)) { $svg = (string)file_get_contents($d . '/' . $rel); break; }
            if ($svg === null && $rel !== null && $this->zip && isset($this->zipIndex[$rel])) $svg = $this->zip->getFromName($this->zipIndex[$rel]) ?: null;
            if ($svg === null && $this->download) $svg = $this->fetch($url);
            $result = $svg === null ? 'not found' : (icon_save($slot, $svg) ? 'added' : 'rejected (not a clean SVG)');
            $this->stats['icons'][$result] = ($this->stats['icons'][$result] ?? 0) + 1;
        }
    }

    /**
     * Self-hosted brand fonts (Elementor custom fonts): copy every font file into public
     * assets/fonts/ unchanged and keep the @font-face rules exactly as they were, with
     * the addresses pointed at this site.
     */
    public function fonts(array $fonts): void
    {
        if (!$fonts) return;
        $dir = public_dir() . '/assets/fonts';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $css = '';
        foreach ($fonts as $f) {
            $css .= preg_replace_callback('~url\(\s*([\'"]?)([^\'")]+)\1\s*\)~', function ($m) use ($dir) {
                $url = $m[2];
                $suffix = '';
                if (preg_match('~^([^?#]+)([?#].*)$~', $url, $p)) { $url = $p[1]; $suffix = $p[2]; }
                $name = basename((string)parse_url($url, PHP_URL_PATH));
                if (!preg_match('~^[\w.-]+\.(woff2?|ttf|otf|eot|svg)$~i', $name)) return $m[0];
                if (!is_file($dir . '/' . $name)) {
                    $bin = $this->rawFile($url);
                    $this->stats['fonts'][$bin !== null ? 'files copied' : 'files not found'] = ($this->stats['fonts'][$bin !== null ? 'files copied' : 'files not found'] ?? 0) + 1;
                    if ($bin !== null) file_put_contents($dir . '/' . $name, $bin);
                }
                return "url('" . url('/assets/fonts/' . $name) . $suffix . "')";
            }, $f['css']) . "\n";
        }
        save_setting('fonts_css', trim($css));
        $this->log[] = 'Brand fonts: ' . implode(', ', array_column($fonts, 'family')) . '.';
    }

    /** Original bytes of an upload (no image processing) from local copies, the zip, or the old site. */
    private function rawFile(string $url): ?string
    {
        $rel = $this->uploadRel($url);
        if ($rel !== null) {
            foreach ($this->uploadDirs as $d) if (is_file($d . '/' . $rel)) return (string)file_get_contents($d . '/' . $rel);
            if ($this->zip && isset($this->zipIndex[$rel])) { $b = $this->zip->getFromName($this->zipIndex[$rel]); if ($b !== false) return $b; }
        }
        return $this->download ? $this->fetch($url) : null;
    }

    public function summary(): array
    {
        $lines = $this->log;
        foreach ($this->stats as $t => $s) {
            $label = WP_IMPORT_TYPES[$t] ?? ['translations' => 'Translated pages (hr/bs/sl/sr)', 'icons' => 'SVG icons → icon library', 'fonts' => 'Brand font files → /assets/fonts/'][$t] ?? ucfirst($t);
            $lines[] = $label . ': ' . implode(', ', array_map(fn($k, $v) => "{$v} {$k}", array_keys($s), $s));
        }
        foreach ($this->unmapped as $t => $keys) {
            $lines[] = (WP_IMPORT_TYPES[$t] ?? $t) . ' — custom fields not used: ' . implode(', ', array_keys($keys));
        }
        return $lines;
    }
}
