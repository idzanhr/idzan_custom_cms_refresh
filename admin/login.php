<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (!empty($_SESSION['uid'])) redirect(admin_url('index.php'));

const MAX_TRIES = 8;          // failed passwords or codes…
const WINDOW = 15 * 60;       // …per IP in this many seconds
const PENDING_TTL = 5 * 60;   // time allowed between password and code

function too_many_tries(): bool
{
    q('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    return (int)one('SELECT COUNT(*) c FROM login_attempts WHERE ip_hash = ? AND attempted_at > ?',
        [client_ip_hash(), date('Y-m-d H:i:s', time() - WINDOW)])['c'] >= MAX_TRIES;
}

function failed_try(): void
{
    q('INSERT INTO login_attempts (ip_hash, attempted_at) VALUES (?, ?)', [client_ip_hash(), now()]);
    usleep(400000);
}

function finish_login(array $user, bool $mfaPassed, bool $remember = false): never
{
    session_regenerate_id(true);
    if ($mfaPassed && $remember) remember_device((int)$user['id']);
    unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['mfa_ok'] = $mfaPassed;
    $_SESSION['auth_tag'] = auth_tag(one('SELECT * FROM users WHERE id = ?', [(int)$user['id']]) ?? $user);
    $_SESSION['seen'] = time();
    q('DELETE FROM login_attempts WHERE ip_hash = ?', [client_ip_hash()]);
    redirect(admin_url($mfaPassed ? 'index.php' : '2fa.php'));
}

$pending = null;
if (!empty($_SESSION['pending_uid'])) {
    if (($_SESSION['pending_at'] ?? 0) > time() - PENDING_TTL) {
        $pending = one('SELECT * FROM users WHERE id = ? AND totp_enabled = 1', [(int)$_SESSION['pending_uid']]);
    }
    if (!$pending) unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
}
if (isset($_GET['restart'])) { unset($_SESSION['pending_uid'], $_SESSION['pending_at']); $pending = null; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (too_many_tries()) {
        $error = 'Too many attempts. Wait 15 minutes and try again.';
    } elseif ($pending) {
        // Step 2: authenticator code.
        $code = trim((string)($_POST['code'] ?? ''));
        $step = totp_verify((string)$pending['totp_secret'], $code, (int)$pending['totp_last_step']);
        if ($step !== null) {
            q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $pending['id']]);
            finish_login($pending, true, !empty($_POST['remember']));
        }
        failed_try();
        $error = 'Wrong or expired code. Check that your phone\'s clock is set automatically, then try the newest code.';
    } else {
        // Step 1: username + password.
        $user = one('SELECT * FROM users WHERE username = ?', [trim((string)($_POST['username'] ?? ''))]);
        if ($user && password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $user['id']]);
            }
            if ((int)$user['totp_enabled']) {
                session_regenerate_id(true);
                $_SESSION['pending_uid'] = (int)$user['id'];
                $_SESSION['pending_at'] = time();
                redirect(admin_url('login.php'));
            }
            finish_login($user, false); // no 2FA yet → straight to the setup page
        }
        failed_try();
        $error = 'Wrong username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Log in · CMS</title>
<link rel="stylesheet" href="<?= e(asset_url('/assets/admin/admin.css')) ?>">
</head>
<body class="login">
<form class="login-box" method="post" autocomplete="off">
  <h1><?= e(s('site_title')) ?> <small>CMS</small></h1>
  <?php foreach (take_flashes() as [$t, $m]): ?><p class="flash <?= e($t) ?>"><?= e($m) ?></p><?php endforeach ?>
  <?php if (isset($_GET['expired'])): ?><p class="flash warn">Session expired — please log in again.</p><?php endif ?>
  <?php if ($error): ?><p class="flash err" role="alert"><?= e($error) ?></p><?php endif ?>
  <?= csrf_field() ?>

  <?php if ($pending): ?>
    <p class="step">Step 2 of 2 · Two-factor login</p>
    <label for="code">6-digit code from your authenticator app</label>
    <input id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus class="code-input">
    <label class="check remember"><input type="checkbox" name="remember" value="1" checked> Keep me signed in on this device for <?= remember_days() ?> days</label>
    <button class="btn primary" type="submit">Verify</button>
    <p class="help">No access to your authenticator? An administrator can reset two-factor login for your account. <a href="<?= e(admin_url('login.php', ['restart' => 1])) ?>">Start over</a></p>

  <?php else: ?>
    <label for="u">Username</label>
    <input id="u" name="username" autocomplete="username" required autofocus>
    <label for="p">Password</label>
    <input id="p" name="password" type="password" autocomplete="current-password" required>
    <button class="btn primary" type="submit">Continue</button>
  <?php endif ?>
</form>
</body>
</html>
