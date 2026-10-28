<?php
declare(strict_types=1);

function cfg(string $key, $default = null)
{
    return $GLOBALS['CONFIG'][$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg('db');
    $dsn = $c['dsn'] ?? sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'], (int)($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $c['user'] ?? null, $c['pass'] ?? null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    if (str_starts_with($dsn, 'mysql:')) {
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    if ($row === false) return null;
    return (isset($GLOBALS['LANG']) && $GLOBALS['LANG'] !== 'en') ? tr_overlay($sql, [$row])[0] : $row;
}

function all(string $sql, array $params = []): array
{
    $rows = q($sql, $params)->fetchAll();
    return (isset($GLOBALS['LANG']) && $GLOBALS['LANG'] !== 'en') ? tr_overlay($sql, $rows) : $rows;
}

/** Rows exactly as stored (no translation overlay). */
function all_raw(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Site-relative URL, honouring base_path. */
function url(string $path = '/'): string
{
    $base = rtrim((string)cfg('base_path', ''), '/');
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;
    // On a translated page, links to other pages stay in that language (/hr/blog/), or on that language's own
    // domain (https://domain.ba/blog/); files (images, CSS…) stay on the address the visitor is on.
    if (isset($GLOBALS['LANG']) && $GLOBALS['LANG'] !== 'en' && is_page_path($path)) {
        if (($d = lang_domain($GLOBALS['LANG'])) !== '') return $d . $path;
        $path = lang_path($path, $GLOBALS['LANG']);
    }
    return $base . $path;
}

function abs_url(string $path = '/'): string
{
    if (preg_match('~^https?://~i', $path)) return $path;   // already absolute (a language on its own domain)
    return rtrim((string)cfg('site_url', ''), '/') . $path;
}

/** Image value may be an uploaded relative path (uploads/...) or a full URL. */
function media_url(?string $v): string
{
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('~^https?://~i', $v)) return $v;
    return url('/' . ltrim($v, '/'));
}

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null || $key === '__reset') {
        $cache = [];
        try {
            foreach (all('SELECT k, v FROM settings') as $r) $cache[$r['k']] = (string)$r['v'];
        } catch (Throwable $e) {
            $cache = [];
        }
        if ($key === '__reset') return '';
    }
    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
}

function save_setting(string $key, string $value): void
{
    q('DELETE FROM settings WHERE k = ?', [$key]);
    q('INSERT INTO settings (k, v) VALUES (?, ?)', [$key, $value]);
    setting('__reset'); // later reads in the same request see the new value
}

function slugify(string $s): string
{
    $map = ['č' => 'c', 'ć' => 'c', 'đ' => 'd', 'š' => 's', 'ž' => 'z', 'Č' => 'c', 'Ć' => 'c', 'Đ' => 'd', 'Š' => 's', 'Ž' => 'z',
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
    $s = strtr($s, $map);
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        if ($t !== false) $s = $t;
    }
    $s = strtolower($s);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    $s = trim((string)$s, '-');
    return $s !== '' ? substr($s, 0, 180) : 'item';
}

function unique_slug(string $table, string $slug, ?int $exceptId = null): string
{
    $base = $slug;
    $i = 2;
    // Root-level URLs are shared between blog posts and pages, so check reserved words too.
    $reserved = ['admin', 'blog', 'projects', 'project', 'affiliate', 'category', 'work-experience', 'assets', 'uploads',
                 'feed.xml', 'sitemap.xml', 'theme.css', 'robots.txt', 'install', 'about-me', 'contact'];
    while (true) {
        $taken = one("SELECT id FROM {$table} WHERE slug = ?" . ($exceptId ? ' AND id <> ?' : ''),
            $exceptId ? [$slug, $exceptId] : [$slug]);
        $clash = false;
        if (in_array($table, ['posts', 'pages'], true)) {
            $other = $table === 'posts' ? 'pages' : 'posts';
            $clash = (bool)one("SELECT id FROM {$other} WHERE slug = ?", [$slug]);
            if ($table === 'posts' && in_array($slug, $reserved, true)) $clash = true;
            if ($table === 'pages' && in_array($slug, array_diff($reserved, ['about-me', 'contact']), true)) $clash = true;
        }
        if (!$taken && !$clash) return $slug;
        $slug = $base . '-' . $i++;
    }
}

function fmt_month(?string $date): string
{
    if (!$date) return '';
    $t = strtotime($date);
    if (!$t) return '';
    $l = $GLOBALS['LANG'] ?? 'en';
    return isset(MONTHS[$l]) ? MONTHS[$l][(int)date('n', $t) - 1] . ' ' . date('Y', $t) . '.' : date('F Y', $t);
}

function fmt_date(?string $date): string
{
    if (!$date) return '';
    $t = strtotime($date);
    if (!$t) return '';
    return ($GLOBALS['LANG'] ?? 'en') !== 'en' ? date('j. n. Y.', $t) : date('j F Y', $t);
}

function excerpt_from(string $html, int $words = 45): string
{
    $text = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES, 'UTF-8')));
    $parts = preg_split('~\s+~u', $text);
    if (count($parts) <= $words) return $text;
    return implode(' ', array_slice($parts, 0, $words)) . ' […]';
}

function reading_minutes(string $html): int
{
    $n = str_word_count(strip_tags($html));
    return max(1, (int)round($n / 220));
}

/* ---------- CSRF & flash ---------- */

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Security token expired. Go back, reload the page and try again.');
    }
}

function flash(string $msg, string $type = 'ok'): void
{
    start_session();
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/**
 * Visitor IP. Behind CloudPanel/nginx the admin is proxied from 127.0.0.1, so the real
 * address comes from X-Real-IP — trusted only when the request itself came from localhost.
 */
function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (in_array($ip, ['127.0.0.1', '::1'], true)) {
        $fwd = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($fwd === '' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $fwd = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($fwd, FILTER_VALIDATE_IP)) $ip = $fwd;
    }
    return $ip;
}

function client_ip_hash(): string
{
    return hash('sha256', client_ip() . '|' . (cfg('db')['name'] ?? ''));
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/* ---------- terms ---------- */

function terms(string $taxonomy): array
{
    return all('SELECT * FROM terms WHERE taxonomy = ? ORDER BY name', [$taxonomy]);
}

function entity_terms(string $entity, int $id, string $taxonomy): array
{
    return all('SELECT t.* FROM terms t JOIN entity_terms et ON et.term_id = t.id
                WHERE et.entity = ? AND et.entity_id = ? AND t.taxonomy = ? ORDER BY t.name',
        [$entity, $id, $taxonomy]);
}

function set_entity_terms(string $entity, int $id, string $taxonomy, array $termIds): void
{
    q('DELETE FROM entity_terms WHERE entity = ? AND entity_id = ? AND term_id IN (SELECT id FROM terms WHERE taxonomy = ?)',
        [$entity, $id, $taxonomy]);
    foreach (array_unique(array_map('intval', $termIds)) as $tid) {
        if ($tid > 0 && one('SELECT id FROM terms WHERE id = ? AND taxonomy = ?', [$tid, $taxonomy])) {
            q('INSERT INTO entity_terms (entity, entity_id, term_id) VALUES (?, ?, ?)', [$entity, $id, $tid]);
        }
    }
}

function ensure_term(string $taxonomy, string $name): int
{
    $name = mb_substr(trim($name), 0, 190);
    $slug = slugify($name);
    $row = one('SELECT id FROM terms WHERE taxonomy = ? AND slug = ?', [$taxonomy, $slug]);
    if ($row) return (int)$row['id'];
    q('INSERT INTO terms (taxonomy, name, slug) VALUES (?, ?, ?)', [$taxonomy, $name, $slug]);
    return (int)db()->lastInsertId();
}

/** Parse "Label | URL" lines from a settings textarea. */
function link_lines(string $raw): array
{
    $out = [];
    foreach (preg_split('~\R~', $raw) as $line) {
        if (!str_contains($line, '|')) continue;
        [$label, $href] = array_map('trim', explode('|', $line, 2));
        if ($label !== '' && preg_match('~^(https?://|mailto:|/)~i', $href)) $out[] = [$label, $href];
    }
    return $out;
}

/** Asset address with a version stamp from the file's content, so browsers and CDNs fetch the new file after every update. */
function asset_url(string $path): string
{
    $file = ROOT . '/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5_file($file), 0, 8) : '';
    // The file is served from the public folder, which normally only changes on a rebuild. Right after the CMS
    // files are updated the admin would otherwise load the OLD script under the NEW ?v= stamp, and the CDN
    // would keep that wrong copy. So the public copy is brought up to date first.
    if ($v !== '' && function_exists('public_dir') && str_starts_with($path, '/assets/')) {
        $pub = public_dir() . $path;
        if (!is_file($pub) || substr((string)md5_file($pub), 0, 8) !== $v) {
            if (!is_dir(dirname($pub))) @mkdir(dirname($pub), 0755, true);
            if (@copy($file, $pub . '.tmp')) @rename($pub . '.tmp', $pub);
        }
    }
    return url($path) . ($v !== '' ? '?v=' . $v : '');
}
