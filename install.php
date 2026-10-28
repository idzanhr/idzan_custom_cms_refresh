<?php
declare(strict_types=1);
/**
 * One-time installer. Creates the MySQL tables, your admin account and (optionally)
 * pre-fills example content. Locks itself once an admin exists —
 * delete this file afterwards anyway.
 */
require __DIR__ . '/inc/bootstrap.php';
start_session();
header('X-Robots-Tag: noindex');

$hasConfig = is_file(ROOT . '/config.php');
$step = $hasConfig ? 'install' : 'config';
$error = '';
$configText = '';

function installed(): bool
{
    try { return (bool)one('SELECT id FROM users LIMIT 1'); } catch (Throwable $e) { return false; }
}

if ($hasConfig && installed()) {
    $step = 'done';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step !== 'done') {
    csrf_check();
    if ($step === 'config') {
        $db = [
            'host' => trim($_POST['host'] ?? 'localhost'), 'port' => (int)($_POST['port'] ?? 3306),
            'name' => trim($_POST['name'] ?? ''), 'user' => trim($_POST['user'] ?? ''),
            'pass' => (string)($_POST['pass'] ?? ''), 'charset' => 'utf8mb4',
        ];
        $site = rtrim(trim($_POST['site_url'] ?? ''), '/');
        $basePath = '/' . trim(trim($_POST['base_path'] ?? ''), '/');
        if ($basePath === '/') $basePath = '';
        try {
            new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $configText = "<?php\nreturn " . var_export([
                'db' => $db, 'base_path' => $basePath, 'site_url' => $site,
                'upload_max_mb' => 10, 'image_max_px' => 2400, 'timezone' => 'Europe/Zagreb',
            ], true) . ";\n";
            if (@file_put_contents(ROOT . '/config.php', $configText) !== false) {
                @chmod(ROOT . '/config.php', 0640);
                redirect($_SERVER['REQUEST_URI']);
            }
            $step = 'paste';
        } catch (PDOException $e) {
            $error = 'Could not connect to MySQL: ' . $e->getMessage();
        }
    } elseif ($step === 'install') {
        $user = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        if (!preg_match('~^[A-Za-z0-9._@-]{3,64}$~', $user)) $error = 'Username: 3–64 letters, numbers, . _ @ -';
        elseif (mb_strlen($pass) < 12) $error = 'Password needs at least 12 characters.';
        elseif ($pass !== ($_POST['password2'] ?? '')) $error = 'Passwords don\'t match.';
        else {
            try {
                $sql = file_get_contents(ROOT . '/install/schema.sql');
                $sql = preg_replace('~^--.*$~m', '', $sql);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) db()->exec($stmt);

                q('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)', [$user, password_hash($pass, PASSWORD_DEFAULT), now()]);
                save_setting('secret', bin2hex(random_bytes(32)));
                save_setting('schema_version', (string)SCHEMA_VERSION);
                if (!empty($_POST['seed'])) {
                    require ROOT . '/install/seed.php';
                    seed_content();
                } else {
                    save_setting('site_title', 'Your Name');
                    q("INSERT INTO pages (title, slug, content, status, in_footer, footer_order, created_at, updated_at) VALUES ('Contact', 'contact', '<p>Find me on my social networks.</p>', 'published', 0, 0, ?, ?)", [now(), now()]);
                }
                foreach (['uploads', 'storage'] as $d) if (!is_dir(ROOT . "/{$d}")) @mkdir(ROOT . "/{$d}", 0775, true);
                $step = 'finished';
            } catch (Throwable $e) {
                $error = 'Install failed: ' . $e->getMessage();
            }
        }
    }
}

$checks = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1', '>='),
    'PDO MySQL extension' => extension_loaded('pdo_mysql'),
    'GD extension (image resizing — optional)' => extension_loaded('gd'),
    'Zip extension (static export — optional)' => class_exists('ZipArchive'),
    'uploads/ is writable' => is_writable(public_dir() . '/uploads'),
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Install · CMS</title>
<link rel="stylesheet" href="<?= e(url('/assets/admin/admin.css')) ?>">
</head>
<body class="login">
<div class="login-box install">
  <h1>Install <small>domain.com CMS</small></h1>
  <?php if ($error): ?><p class="flash err" role="alert"><?= e($error) ?></p><?php endif ?>

  <?php if ($step === 'done'): ?>
    <p class="flash warn">Already installed. Delete <code>install.php</code> from the server.</p>
    <a class="btn primary" href="<?= e(url('/admin/')) ?>">Go to the CMS</a>

  <?php elseif ($step === 'finished'): ?>
    <p class="flash ok">Done! Your site is ready.</p>
    <p><strong>Now delete <code>install.php</code></strong> from the server.</p>
    <p>When you log in for the first time you'll set up two-factor login: have your authenticator app (e.g. Proton Authenticator) ready.</p>
    <a class="btn primary" href="<?= e(url('/admin/')) ?>">Log in to the CMS</a>
    <a class="btn" href="<?= e(url('/')) ?>">View the site</a>

  <?php elseif ($step === 'paste'): ?>
    <p>Connected — but I can't write <code>config.php</code>. Create it next to <code>index.php</code> with this content, then reload this page:</p>
    <textarea rows="16" readonly><?= e($configText) ?></textarea>

  <?php elseif ($step === 'config'): ?>
    <ul class="checks"><?php foreach ($checks as $l => $ok): ?><li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?> <?= e($l) ?></li><?php endforeach ?></ul>
    <form method="post">
      <?= csrf_field() ?>
      <p class="help">1/2 — MySQL database (create an empty database and user in your hosting panel first).</p>
      <label for="host">Database host</label><input id="host" name="host" value="<?= e($_POST['host'] ?? 'localhost') ?>" required>
      <label for="port">Port</label><input id="port" name="port" value="<?= e($_POST['port'] ?? '3306') ?>" inputmode="numeric">
      <label for="name">Database name</label><input id="name" name="name" value="<?= e($_POST['name'] ?? '') ?>" required>
      <label for="user">Database user</label><input id="user" name="user" value="<?= e($_POST['user'] ?? '') ?>" required autocomplete="off">
      <label for="pass">Database password</label><input id="pass" name="pass" type="password" autocomplete="new-password">
      <label for="site_url">Site address</label><input id="site_url" name="site_url" value="<?= e($_POST['site_url'] ?? ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'domain.com'))) ?>" required>
      <label for="base_path">Sub-folder (leave empty if the site is at the domain root)</label><input id="base_path" name="base_path" value="<?= e($_POST['base_path'] ?? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>" placeholder="/new">
      <button class="btn primary" type="submit">Connect</button>
    </form>

  <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <p class="help">2/2 — Create your admin login.</p>
      <label for="un">Username</label><input id="un" name="username" value="<?= e($_POST['username'] ?? '') ?>" required autocomplete="username">
      <label for="pw">Password (12+ characters)</label><input id="pw" name="password" type="password" minlength="12" required autocomplete="new-password">
      <label for="pw2">Repeat password</label><input id="pw2" name="password2" type="password" required autocomplete="new-password">
      <label class="check"><input type="checkbox" name="seed" value="1" checked> Pre-fill with example content (about, experience, education, a project, a post, legal pages)</label>
      <button class="btn primary" type="submit">Install</button>
    </form>
  <?php endif ?>
</div>
</body>
</html>
