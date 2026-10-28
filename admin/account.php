<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$user = one('SELECT * FROM users WHERE id = ?', [(int)$_SESSION['uid']]);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $username = trim((string)($_POST['username'] ?? $user['username']));
    if (!password_verify($cur, $user['password_hash'])) $error = 'Current password is wrong.';
    elseif ($new !== '' && mb_strlen($new) < 12) $error = 'New password needs at least 12 characters.';
    elseif ($new !== '' && $new !== ($_POST['new2'] ?? '')) $error = 'New passwords don\'t match.';
    elseif (!preg_match('~^[A-Za-z0-9._@-]{3,64}$~', $username)) $error = 'Username: 3–64 letters, numbers, . _ @ -';
    else {
        q('UPDATE users SET username = ? WHERE id = ?', [$username, $user['id']]);
        if ($new !== '') {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            forget_all_devices((int)$user['id']); // other devices must sign in again
        }
        refresh_auth_tag((int)$user['id']);
        session_regenerate_id(true);
        flash('Account updated.');
        redirect(admin_url('account.php'));
    }
}
admin_head('Account', 'account');
?>
<header class="pagehead"><h1>Account</h1></header>
<?php if ($error): ?><p class="flash err" role="alert"><?= e($error) ?></p><?php endif ?>
<form class="panel narrow" method="post">
  <?= csrf_field() ?>
  <div class="field"><label for="un">Username</label><input id="un" name="username" value="<?= e($user['username']) ?>" autocomplete="username" required></div>
  <div class="field"><label for="np">New password <span class="muted">(leave empty to keep)</span></label><input id="np" name="new" type="password" autocomplete="new-password" minlength="12"></div>
  <div class="field"><label for="np2">Repeat new password</label><input id="np2" name="new2" type="password" autocomplete="new-password"></div>
  <div class="field"><label for="cp">Current password <span class="req">*</span></label><input id="cp" name="current" type="password" autocomplete="current-password" required></div>
  <button class="btn primary" type="submit">Save</button>
</form>
<?php admin_foot();
