<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/bootstrap.php';
start_session();

header_remove('X-Powered-By');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'");
header('Cross-Origin-Opener-Policy: same-origin');
header('Cache-Control: no-store');

run_migrations();

const PUBLIC_ADMIN_PAGES = ['login.php', 'setup.php'];
// Pages a signed-in user may open before two-factor login is set up.
const ENROLL_PAGES = ['2fa.php', 'logout.php'];

$GLOBALS['ME'] = null;
$script = basename($_SERVER['SCRIPT_NAME']);

// Not installed yet → the browser setup page.
if (!cms_installed() && $script !== 'setup.php') redirect(admin_base() . '/setup.php');
// No session yet, but this device was remembered after a 2FA login → sign straight in.
if (empty($_SESSION['uid']) && !empty($_COOKIE[REMEMBER_COOKIE])) {
    if ($u = login_from_remember_cookie()) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['mfa_ok'] = true;
        $_SESSION['remembered'] = true;
        $_SESSION['auth_tag'] = auth_tag($u);
        $_SESSION['seen'] = time();
    }
}

if (!in_array($script, PUBLIC_ADMIN_PAGES, true)) {
    $me = empty($_SESSION['uid']) ? null : one('SELECT * FROM users WHERE id = ?', [(int)$_SESSION['uid']]);
    if (!$me || !hash_equals((string)($_SESSION['auth_tag'] ?? ''), auth_tag($me))) {
        // Unknown user, or the password / 2FA changed since this session signed in.
        $_SESSION = [];
        session_destroy();
        redirect(admin_url('login.php'));
    }
    // Idle timeout: 4 hours (a remembered device signs back in automatically).
    if (($_SESSION['seen'] ?? 0) < time() - 4 * 3600) {
        $_SESSION = [];
        session_destroy();
        redirect(admin_url('login.php', ['expired' => 1]));
    }
    $_SESSION['seen'] = time();
    // 2FA is mandatory: an account without it can only reach the setup page.
    if (!(int)$me['totp_enabled'] && !in_array($script, ENROLL_PAGES, true)) {
        redirect(admin_url('2fa.php'));
    }
    // An account with 2FA must have passed the code step in this session.
    if ((int)$me['totp_enabled'] && empty($_SESSION['mfa_ok'])) {
        $_SESSION = [];
        session_destroy();
        redirect(admin_url('login.php'));
    }
    if (in_array($script, ADMIN_ONLY_PAGES, true) && !is_admin($me)) {
        http_response_code(403);
        exit('Only administrators can open this page.');
    }
    $GLOBALS['ME'] = $me;
}

function admin_url(string $page, array $params = []): string
{
    return admin_base() . '/' . $page . ($params ? '?' . http_build_query($params) : '');
}

/**
 * Sidebar menu, grouped like WordPress: [label, icon, [[href, label], …]].
 * The group holding the current page stays open; the others open as a flyout on hover or keyboard focus.
 */
function admin_menu(): array
{
    return [
        ['Dashboard', 'dash', [['index.php', 'Dashboard']]],
        ['Posts', 'pen', [['list.php?e=posts', 'All posts'], ['edit.php?e=posts', 'Add new post'], ['terms.php?t=category', 'Categories']]],
        ['Projects', 'case', [['list.php?e=projects', 'All projects'], ['edit.php?e=projects', 'Add new project'], ['terms.php?t=service', 'Services'], ['terms.php?t=tool', 'Tools']]],
        ['Résumé', 'cv', [['list.php?e=experience', 'Work experience'], ['list.php?e=education', 'Education'], ['list.php?e=certifications', 'Certifications']]],
        ['Affiliates', 'link', [['list.php?e=affiliates', 'All affiliates'], ['edit.php?e=affiliates', 'Add new affiliate']]],
        ['Pages', 'page', [['list.php?e=pages', 'All pages'], ['edit.php?e=pages', 'Add new page'], ['list.php?e=contacts', 'Contact links']]],
        ['Media', 'image', [['media.php', 'Library'], ['media.php?upload=1', 'Add new media'], ['icons.php', 'Icons']]],
        ['Settings', 'gear', [['settings.php', 'Site settings'], ['languages.php', 'Languages & translation']]],
        ['Tools', 'tool', [['import.php', 'Import from WordPress'], ['seo.php', 'SEO check'], ['export.php', 'Publish & export'], ['refresh.php', 'Refresh after an update']]],
        ['Users', 'users', [['users.php', 'All users'], ['account.php', 'Your account'], ['2fa.php', 'Two-factor login']]],
    ];
}

/** True when a menu link points at the page being shown (editing an item counts as its "All …" list). */
function admin_menu_current(string $href): bool
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    parse_str((string)parse_url($href, PHP_URL_QUERY), $q);
    $page = (string)parse_url($href, PHP_URL_PATH);
    $e = (string)($_GET['e'] ?? '');
    if ($script === 'edit.php') {
        $editing = !empty($_GET['id']);
        if ($page === 'edit.php') return !$editing && ($q['e'] ?? '') === $e;
        return $editing && $page === 'list.php' && ($q['e'] ?? '') === $e;
    }
    if ($page !== $script) return false;
    if ($page === 'list.php') return ($q['e'] ?? '') === $e;
    if ($page === 'terms.php') return ($q['t'] ?? '') === ($_GET['t'] ?? '');
    if ($page === 'media.php') return isset($q['upload']) === isset($_GET['upload']);
    return true;
}

function admin_icon(string $name): string
{
    $p = [
        'dash'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'pen'   => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13 7l4 4"/>',
        'case'  => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M3 12h18"/>',
        'cv'    => '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="9" r="2.5"/><path d="M8 17c.6-2 2.1-3 4-3s3.4 1 4 3"/>',
        'link'  => '<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
        'page'  => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4M9 12h7M9 16h5"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 17l-5-5-9 8"/>',
        'gear'  => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'tool'  => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.5-.5-.5-2.5z"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.5-3.5 3.2-5.5 6.5-5.5s6 2 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c2 .7 3.2 2.5 3.5 5.2"/>',
    ][$name] ?? '';
    return '<svg class="mi" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

function admin_head(string $title, string $active = ''): void
{
    $menu = admin_menu();
    $me = $GLOBALS['ME'] ?? [];
    $allowed = function (string $href) use ($me): bool {
        $page = (string)parse_url($href, PHP_URL_PATH);
        if (empty($me['totp_enabled'])) return $page === '2fa.php';          // 2FA must be set up first
        return is_admin() || !in_array($page, ADMIN_ONLY_PAGES, true);
    };
    foreach ($menu as $i => [$label, $icon, $items]) {
        $menu[$i][2] = array_values(array_filter($items, fn($it) => $allowed($it[0])));
        if (!$menu[$i][2]) unset($menu[$i]);
    }
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · CMS</title>
<link rel="stylesheet" href="<?= e(asset_url('/assets/admin/admin.css')) ?>">
<script src="<?= e(asset_url('/assets/admin/admin.js')) ?>" defer></script>
</head>
<body data-csrf="<?= e(csrf_token()) ?>" data-upload="<?= e(admin_url('upload.php')) ?>" data-media="<?= e(admin_url('media.php', ['format' => 'json'])) ?>">
<div class="shell">
  <aside class="side">
    <a class="brand" href="<?= e(admin_url('index.php')) ?>"><?= e(setting('site_title', 'Your Name')) ?><small>CMS</small></a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="adminnav">Menu</button>
    <nav id="adminnav" aria-label="Admin menu">
      <ul class="menu">
      <?php foreach ($menu as [$label, $icon, $items]):
          $cur = array_values(array_filter($items, fn($it) => admin_menu_current($it[0])));
          $open = (bool)$cur; $single = count($items) === 1; ?>
        <li class="grp<?= $open ? ' open' : '' ?><?= $single ? ' single' : '' ?>">
          <a class="grp-link" href="<?= e(admin_base() . '/' . $items[0][0]) ?>"<?= $single && $open ? ' aria-current="page"' : '' ?>><?= admin_icon($icon) ?><span><?= e($label) ?></span></a>
          <?php if (!$single): ?>
          <ul class="sub" aria-label="<?= e($label) ?>">
            <?php foreach ($items as [$href, $text]): $on = admin_menu_current($href); ?>
            <li><a href="<?= e(admin_base() . '/' . $href) ?>"<?= $on ? ' class="on" aria-current="page"' : '' ?>><?= e($text) ?></a></li>
            <?php endforeach ?>
          </ul>
          <?php endif ?>
        </li>
      <?php endforeach ?>
      </ul>
      <div class="side-foot">
        <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener">View site ↗</a>
        <form method="post" action="<?= e(admin_url('logout.php')) ?>"><?= csrf_field() ?><button class="linklike" type="submit">Log out</button></form>
      </div>
    </nav>
  </aside>
  <main class="main">
    <?php foreach (take_flashes() as [$type, $msg]): ?>
      <div class="flash <?= e($type) ?>" role="status"><?= e($msg) ?></div>
    <?php endforeach ?>
<?php
}

function admin_foot(): void
{
    echo '  <p class="muted cms-version">CMS ' . e(CMS_VERSION) . ' · <a href="' . e(admin_url('refresh.php')) . '">Refresh after an update</a></p>' . "\n";
    echo "  </main>\n</div>\n</body>\n</html>\n";
}

/* ---------- generic form fields ---------- */

function field_value(array $def, string $name, array $row)
{
    if (array_key_exists($name, $row)) return $row[$name];
    $d = $def['default'] ?? '';
    if ($d === 'now') return date('Y-m-d H:i');
    return $d;
}

function render_field(string $name, array $def, array $row, string $entityKey): void
{
    $type = $def['type'];
    $id = 'f_' . $name;
    $label = $def['label'] ?? ucfirst($name);
    $val = field_value($def, $name, $row);
    $req = !empty($def['required']) ? ' required' : '';
    $attrs = '';
    if (!empty($def['disabled_by'])) $attrs .= ' data-disabled-by="f_' . e($def['disabled_by']) . '"';
    if (!empty($def['enabled_when'])) $attrs .= ' data-enabled-when="f_' . e($def['enabled_when'][0]) . '" data-enabled-value="' . e($def['enabled_when'][1]) . '"';

    if (!empty($def['counter'])) $attrs .= ' data-counter="' . (int)$def['counter'] . '"';
    if (!empty($def['panel'])) echo '<h3 class="field-panel">' . e($def['panel']) . '</h3>';
    echo '<div class="field field-' . e($type) . '">';
    if ($type === 'checkbox') {
        echo '<label class="check"><input type="hidden" name="' . e($name) . '" value="0">'
            . '<input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ((int)$val ? ' checked' : '') . '> '
            . e($label) . '</label>';
    } else {
        echo '<label for="' . e($id) . '">' . e($label) . (!empty($def['required']) ? ' <span class="req">*</span>' : '') . '</label>';
    }

    switch ($type) {
        case 'text':
            echo '<input type="text" id="' . e($id) . '" name="' . e($name) . '" value="' . e($val) . '" maxlength="255"' . $req . $attrs . (!empty($def['raw']) ? ' autocapitalize="off" autocomplete="off" spellcheck="false"' : '') . '>';
            break;
        case 'slug':
            echo '<input type="text" id="' . e($id) . '" name="' . e($name) . '" value="' . e($val) . '" maxlength="180" pattern="[a-z0-9-]*" data-slug-from="f_' . e($def['from']) . '" placeholder="generated from ' . e($def['from']) . '">';
            break;
        case 'url':
            echo '<input type="url" id="' . e($id) . '" name="' . e($name) . '" value="' . e($val) . '" placeholder="https://"' . $req . '>';
            break;
        case 'link':
            echo '<input type="text" inputmode="url" id="' . e($id) . '" name="' . e($name) . '" value="' . e($val) . '" placeholder="https://… or you@example.com or +385…"' . $req . '>';
            break;
        case 'color':
            $c = preg_match('~^#[0-9a-f]{6}$~i', (string)$val) ? $val : '#000000';
            echo '<div class="inline colorpair"><input type="color" value="' . e($c) . '" aria-label="' . e($label) . ' picker" data-pair="' . e($id) . '">'
                . '<input type="text" id="' . e($id) . '" name="' . e($name) . '" value="' . e($val) . '" pattern="#[0-9A-Fa-f]{6}" maxlength="7" placeholder="#RRGGBB"></div>';
            break;
        case 'number':
            echo '<input type="number" id="' . e($id) . '" name="' . e($name) . '" value="' . e($val) . '" step="1">';
            break;
        case 'date':
            echo '<input type="date" id="' . e($id) . '" name="' . e($name) . '" value="' . e($val ? substr((string)$val, 0, 10) : '') . '"' . $req . $attrs . '>';
            break;
        case 'datetime':
            $v = $val ? date('Y-m-d\TH:i', strtotime((string)$val)) : '';
            echo '<input type="datetime-local" id="' . e($id) . '" name="' . e($name) . '" value="' . e($v) . '"' . $req . '>';
            break;
        case 'textarea':
            echo '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="' . (int)($def['rows'] ?? 5) . '"' . $req . $attrs . '>' . e($val) . '</textarea>';
            break;
        case 'richtext':
            echo '<textarea class="rte" id="' . e($id) . '" name="' . e($name) . '" rows="14">' . e($val) . '</textarea>';
            break;
        case 'select':
            echo '<select id="' . e($id) . '" name="' . e($name) . '">';
            foreach ($def['options'] as $k => $l) echo '<option value="' . e($k) . '"' . ((string)$val === (string)$k ? ' selected' : '') . '>' . e($l) . '</option>';
            echo '</select>';
            break;
        case 'term':
            echo '<div class="inline"><select id="' . e($id) . '" name="' . e($name) . '"><option value="">— none —</option>';
            foreach (terms($def['taxonomy']) as $t) echo '<option value="' . (int)$t['id'] . '"' . ((int)$val === (int)$t['id'] ? ' selected' : '') . '>' . e($t['name']) . '</option>';
            echo '</select><input type="text" name="__new_' . e($name) . '" placeholder="or add new…" maxlength="190"></div>';
            break;
        case 'terms':
            $chosen = isset($row['id']) ? array_column(entity_terms($entityKey, (int)$row['id'], $def['taxonomy']), 'id') : [];
            if (isset($row['__terms'][$name])) $chosen = $row['__terms'][$name];
            $all = terms($def['taxonomy']);
            echo '<div class="chips">';
            if (!$all) echo '<span class="muted">None yet — add some below.</span>';
            foreach ($all as $t) {
                echo '<label class="chip"><input type="checkbox" name="' . e($name) . '[]" value="' . (int)$t['id'] . '"' . (in_array((int)$t['id'], array_map('intval', $chosen), true) ? ' checked' : '') . '> ' . e($t['name']) . '</label>';
            }
            echo '</div><input type="text" name="__new_' . e($name) . '" placeholder="Add new, comma separated (e.g. WordPress, Elementor)" maxlength="500">';
            break;
        case 'image':
            $src = media_url((string)$val);
            echo '<div class="imgfield">';
            echo '<img class="preview" src="' . e($src) . '" alt=""' . ($src ? '' : ' hidden') . '>';
            echo '<input type="file" id="' . e($id) . '" name="' . e($name) . '__file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif">';
            echo '<input type="text" name="' . e($name) . '" value="' . e($val) . '" placeholder="…or paste an image URL / uploads path">';
            if (!empty($def['alt'])) {
                echo '<input type="text" name="' . e($def['alt']) . '" value="' . e($row[$def['alt']] ?? '') . '" placeholder="Alt text (describe the image for screen readers)" maxlength="255">';
            }
            echo '</div>';
            break;
    }
    if (!empty($def['help'])) echo '<p class="help">' . e($def['help']) . '</p>';
    echo '</div>';
}

/**
 * Read POSTed fields for an entity. Returns [$data, $termSets, $errors].
 */
function collect_fields(array $ent, ?int $id): array
{
    $data = [];
    $termSets = [];
    $errors = [];

    foreach ($ent['fields'] as $name => $def) {
        $raw = $_POST[$name] ?? null;
        switch ($def['type']) {
            case 'text':
            case 'slug':
                $data[$name] = trim((string)$raw);
                break;
            case 'textarea':
                $data[$name] = trim((string)$raw);
                break;
            case 'richtext':
                $data[$name] = clean_html((string)$raw);
                break;
            case 'url':
                $v = trim((string)$raw);
                if ($v !== '' && !preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_URL)) $errors[] = ($def['label'] ?? $name) . ' is not a valid URL.';
                $data[$name] = $v;
                break;
            case 'link':
                // Profile address, email address or phone number.
                $v = trim((string)$raw);
                if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) $v = 'mailto:' . $v;
                elseif ($v !== '' && preg_match('~^\+?[0-9 ()/.-]{6,}$~', $v)) $v = 'tel:' . preg_replace('~[^0-9+]~', '', $v);
                elseif ($v !== '' && !preg_match('~^(https?://|mailto:|tel:)~i', $v)) $v = 'https://' . $v;
                if ($v !== '' && str_starts_with($v, 'http') && !filter_var($v, FILTER_VALIDATE_URL)) $errors[] = ($def['label'] ?? $name) . ' is not a valid address.';
                if ($v !== '' && str_starts_with($v, 'mailto:') && !filter_var(substr($v, 7), FILTER_VALIDATE_EMAIL)) $errors[] = ($def['label'] ?? $name) . ' is not a valid email address.';
                if (!empty($def['required']) && $v === '') $errors[] = ($def['label'] ?? $name) . ' is required.';
                $data[$name] = $v;
                break;
            case 'number':
                $data[$name] = (int)$raw;
                break;
            case 'color':
                $v = trim((string)$raw);
                if ($v !== '' && $v[0] !== '#') $v = '#' . $v;
                if ($v !== '' && !preg_match('~^#[0-9a-f]{6}$~i', $v)) { $errors[] = ($def['label'] ?? $name) . ' must be a hex color like #1A2B3C.'; $v = ''; }
                $data[$name] = strtoupper($v);
                break;
            case 'checkbox':
                $data[$name] = (int)!empty($raw);
                break;
            case 'date':
                $v = trim((string)$raw);
                $data[$name] = preg_match('~^\d{4}-\d{2}-\d{2}$~', $v) ? $v : null;
                break;
            case 'datetime':
                $t = $raw ? strtotime((string)$raw) : false;
                $data[$name] = $t ? date('Y-m-d H:i:s', $t) : null;
                break;
            case 'select':
                $data[$name] = array_key_exists((string)$raw, $def['options']) ? (string)$raw : (string)array_key_first($def['options']);
                break;
            case 'term':
                $new = trim((string)($_POST['__new_' . $name] ?? ''));
                $data[$name] = $new !== '' ? ensure_term($def['taxonomy'], $new) : ((int)$raw ?: null);
                break;
            case 'terms':
                $ids = array_map('intval', (array)($raw ?? []));
                foreach (explode(',', (string)($_POST['__new_' . $name] ?? '')) as $n) {
                    if (trim($n) !== '') $ids[] = ensure_term($def['taxonomy'], trim($n));
                }
                $termSets[$name] = [$def['taxonomy'], $ids];
                break;
            case 'image':
                $data[$name] = trim((string)$raw);
                $f = $_FILES[$name . '__file'] ?? null;
                if ($f && ($f['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                    try {
                        $data[$name] = store_upload($f, (string)($_POST[$ent['title_field']] ?? ''));
                    } catch (RuntimeException $ex) {
                        $errors[] = ($def['label'] ?? 'Image') . ': ' . $ex->getMessage();
                    }
                }
                if (!empty($def['alt'])) $data[$def['alt']] = trim((string)($_POST[$def['alt']] ?? ''));
                break;
        }
        if (!empty($def['required']) && ($data[$name] ?? '') === '' && $def['type'] !== 'terms') {
            $errors[] = ($def['label'] ?? $name) . ' is required.';
        }
    }

    // Ongoing work clears the end date; otherwise end date can't be before start.
    if (isset($data['ongoing'], $data['end_date'])) {
        if ($data['ongoing']) $data['end_date'] = null;
        elseif ($data['end_date'] && !empty($data['start_date']) && $data['end_date'] < $data['start_date']) {
            $errors[] = 'End date is before the start date.';
        }
    }

    // Promo code: "NO CODE" clears it; "Has a promo code" requires one. Kept exactly as typed.
    if (isset($data['has_code'])) {
        $data['promo_code'] = $data['has_code'] === 'yes' ? (string)($_POST['promo_code'] ?? '') : '';
        $data['promo_code'] = trim($data['promo_code']);
        if ($data['has_code'] === 'yes' && $data['promo_code'] === '') $errors[] = 'Enter the promo code, or set Promo code to NO CODE.';
    }

    // Slugs
    foreach ($ent['fields'] as $name => $def) {
        if ($def['type'] !== 'slug') continue;
        $src = $data[$name] !== '' ? $data[$name] : (string)($data[$def['from']] ?? '');
        if ($ent['key'] === 'experience' && $data[$name] === '') $src = $data['role'] . ' ' . $data['company'];
        $data[$name] = unique_slug($ent['key'], slugify($src), $id);
    }

    return [$data, $termSets, $errors];
}


function list_cell(string $col, array $row, array $ent): string
{
    $v = $row[$col] ?? '';
    $def = $ent['fields'][$col] ?? ['type' => 'text'];
    switch ($def['type']) {
        case 'term':
            if (!$v) return '<span class="muted">—</span>';
            $t = one('SELECT name FROM terms WHERE id = ?', [(int)$v]);
            return e($t['name'] ?? '—');
        case 'select':
            if ($col === 'has_code') return $v === 'yes' ? '<code>' . e($row['promo_code'] ?? '') . '</code>' : '<span class="muted">NO CODE</span>';
            $label = $def['options'][$v] ?? $v;
            return $col === 'status' ? '<span class="pill ' . e($v) . '">' . e($label) . '</span>' : e($label);
        case 'checkbox':
            return (int)$v ? 'Yes' : '<span class="muted">No</span>';
        case 'date':
            if ($col === 'end_date' && !empty($row['ongoing'])) return '<strong>Ongoing</strong>';
            return $v ? e(fmt_month($v)) : '<span class="muted">—</span>';
        case 'datetime':
            return $v ? e(date('d.m.Y. H:i', strtotime($v))) : '<span class="muted">—</span>';
        case 'slug':
            return '<code>' . e($v) . '</code>';
        default:
            return e(mb_strimwidth((string)$v, 0, 70, '…'));
    }
}
