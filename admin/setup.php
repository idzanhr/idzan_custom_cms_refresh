<?php
declare(strict_types=1);
/**
 * Browser installer for servers without SSH (e.g. CloudPanel File Manager only).
 * Works only while no admin account exists, and asks for the database password from
 * config.php as proof that you control the server. Locks itself after the first install.
 */
require __DIR__ . '/_init.php';

if (cms_installed()) redirect(admin_url('login.php'));

$error = '';
$cfgDb = cfg('db');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $proof = (string)($_POST['dbpass'] ?? '');
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (!is_file(ROOT . '/config.php')) $error = 'config.php is missing — create it first (step 4).';
    elseif ($proof === '' || !hash_equals((string)($cfgDb['pass'] ?? ''), $proof)) { usleep(800000); $error = 'That isn\'t the database password from config.php.'; }
    elseif (!preg_match('~^[A-Za-z0-9._@-]{3,64}$~', $user)) $error = 'Username: 3–64 letters, numbers, . _ @ -';
    elseif (mb_strlen($pass) < 12) $error = 'Password needs at least 12 characters.';
    elseif ($pass !== ($_POST['password2'] ?? '')) $error = 'Passwords don\'t match.';
    else {
        try {
            @set_time_limit(300);
            $sql = preg_replace('~^--.*$~m', '', (string)file_get_contents(ROOT . '/install/schema.sql'));
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) db()->exec($stmt);
            q('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)', [$user, password_hash($pass, PASSWORD_DEFAULT), 'admin', now()]);
            save_setting('secret', bin2hex(random_bytes(32)));
            save_setting('schema_version', (string)SCHEMA_VERSION);
            if (!empty($_POST['seed'])) { require ROOT . '/install/seed.php'; seed_content(); }
            foreach ([public_dir() . '/uploads', ROOT . '/storage'] as $d) if (!is_dir($d)) @mkdir($d, 0775, true);
            content_changed();
            flash('Installed. Log in, then set up two-factor login with your authenticator app.');
            redirect(admin_url('login.php'));
        } catch (Throwable $e) {
            $error = 'Install failed: ' . $e->getMessage();
        }
    }
}

$dbOk = true; $dbErr = '';
try { db(); } catch (Throwable $e) { $dbOk = false; $dbErr = ' — ' . preg_replace('~^SQLSTATE\[\w+\]\s*(\[\d+\])?\s*~', '', $e->getMessage()); }
$checks = [
    'config.php found' => is_file(ROOT . '/config.php'),
    'Database connection' . $dbErr => $dbOk,
    'Public folder writable (' . basename(dirname(public_dir())) . '/' . basename(public_dir()) . ')' => is_dir(public_dir()) ? is_writable(public_dir()) : is_writable(dirname(public_dir())),
    'storage/ writable' => is_writable(ROOT . '/storage') || is_writable(ROOT),
    'PHP GD (image resizing)' => extension_loaded('gd'),
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Set up · CMS</title>
<link rel="stylesheet" href="<?= e(asset_url('/assets/admin/admin.css')) ?>">
</head>
<body class="login">
<form class="login-box install" method="post">
  <h1>Set up <small>domain.com CMS</small></h1>
  <ul class="checks"><?php foreach ($checks as $l => $ok): ?><li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?> <?= e($l) ?></li><?php endforeach ?></ul>
  <?php if ($error): ?><p class="flash err" role="alert"><?= e($error) ?></p><?php endif ?>
  <?= csrf_field() ?>
  <label for="dbp">Database password (from config.php)</label>
  <input id="dbp" name="dbpass" type="password" required autocomplete="off">
  <p class="help">Proves you control this server. This page stops working once the admin account exists.</p>
  <label for="un">Admin username</label><input id="un" name="username" value="<?= e($_POST['username'] ?? '') ?>" required autocomplete="username">
  <label for="pw">Admin password (12+ characters)</label><input id="pw" name="password" type="password" minlength="12" required autocomplete="new-password">
  <label for="pw2">Repeat password</label><input id="pw2" name="password2" type="password" required autocomplete="new-password">
  <label class="check"><input type="checkbox" name="seed" value="1" checked> Start with example content (settings, About text, legal pages)</label>
  <button class="btn primary" type="submit">Install</button>
</form>
</body>
</html>
