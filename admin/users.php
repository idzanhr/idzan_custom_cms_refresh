<?php
declare(strict_types=1);
// Administrators only (see ADMIN_ONLY_PAGES): add people, change roles, and reset two-factor login.
require __DIR__ . '/_init.php';

$me = $GLOBALS['ME'];
$error = '';

/** Every sensitive change here needs the administrator's own current 2FA code. */
function confirm_admin_code(array $me): bool
{
    $step = totp_verify((string)$me['totp_secret'], (string)($_POST['code'] ?? ''), (int)$me['totp_last_step']);
    if ($step === null) return false;
    q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $me['id']]);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $target = $id ? one('SELECT * FROM users WHERE id = ?', [$id]) : null;
    $admins = (int)one("SELECT COUNT(*) c FROM users WHERE role = 'admin'")['c'];

    if (!confirm_admin_code($me)) {
        $error = 'Enter your current 6-digit authenticator code to confirm.';
    } elseif ($action === 'add') {
        $u = trim((string)($_POST['username'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        $role = array_key_exists($_POST['role'] ?? '', ROLES) ? $_POST['role'] : 'editor';
        if (!preg_match('~^[A-Za-z0-9._@-]{3,64}$~', $u)) $error = 'Username: 3–64 letters, numbers, . _ @ -';
        elseif (one('SELECT id FROM users WHERE username = ?', [$u])) $error = 'That username is taken.';
        elseif (mb_strlen($pw) < 12) $error = 'Temporary password needs at least 12 characters.';
        else {
            q('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)', [$u, password_hash($pw, PASSWORD_DEFAULT), $role, now()]);
            flash("Added {$u}. They set up two-factor login on their first sign-in.");
        }
    } elseif ($target && $action === 'reset2fa') {
        q('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0 WHERE id = ?', [$target['id']]);
        forget_all_devices((int)$target['id']);
        flash("Two-factor login reset for {$target['username']}. They'll scan a new QR code at their next sign-in.", 'warn');
        if ((int)$target['id'] === (int)$me['id']) { $_SESSION = []; session_destroy(); redirect(admin_url('login.php')); }
    } elseif ($target && $action === 'signout') {
        forget_all_devices((int)$target['id']);
        flash("Remembered devices signed out for {$target['username']}.");
    } elseif ($target && $action === 'role') {
        $role = array_key_exists($_POST['role'] ?? '', ROLES) ? $_POST['role'] : $target['role'];
        if ($target['role'] === 'admin' && $role !== 'admin' && $admins <= 1) $error = 'There must be at least one administrator.';
        else { q('UPDATE users SET role = ? WHERE id = ?', [$role, $target['id']]); flash('Role changed.'); }
    } elseif ($target && $action === 'password') {
        $pw = (string)($_POST['password'] ?? '');
        if (mb_strlen($pw) < 12) $error = 'New password needs at least 12 characters.';
        else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $target['id']]);
            forget_all_devices((int)$target['id']);
            flash("Password changed for {$target['username']}.");
        }
    } elseif ($target && $action === 'delete') {
        if ((int)$target['id'] === (int)$me['id']) $error = 'You can\'t delete your own account.';
        elseif ($target['role'] === 'admin' && $admins <= 1) $error = 'There must be at least one administrator.';
        else { forget_all_devices((int)$target['id']); q('DELETE FROM users WHERE id = ?', [$target['id']]); flash("Deleted {$target['username']}."); }
    }
    if (!$error) redirect(admin_url('users.php'));
}

$users = all('SELECT u.*, (SELECT COUNT(*) FROM remember_tokens t WHERE t.user_id = u.id AND t.expires_at > ?) AS devices FROM users u ORDER BY u.role, u.username', [now()]);
$codeField = '<input name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required placeholder="Your 2FA code" aria-label="Your current 2FA code" class="code-input small-code">';

admin_head('Users', 'users');
?>
<header class="pagehead"><h1>Users</h1></header>
<?php if ($error): ?><p class="flash err" role="alert"><?= e($error) ?></p><?php endif ?>
<p class="help">Only administrators see this page. Every change asks for your current authenticator code.</p>

<div class="tablewrap">
<table class="list">
  <thead><tr><th>User</th><th>Role</th><th>Two-factor</th><th>Remembered devices</th><th class="right">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$me['id']; ?>
    <tr>
      <td data-label="User"><strong><?= e($u['username']) ?></strong><?= $self ? ' <span class="muted">(you)</span>' : '' ?></td>
      <td data-label="Role"><?= e(ROLES[$u['role']] ?? $u['role']) ?></td>
      <td data-label="Two-factor"><?= (int)$u['totp_enabled'] ? '<span class="pill published">On</span>' : '<span class="pill draft">Not set up</span>' ?></td>
      <td data-label="Devices"><?= (int)$u['devices'] ?></td>
      <td class="right">
        <details class="user-actions"><summary class="btn small">Manage</summary>
          <form method="post" class="ua"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="reset2fa">
            <span>Reset two-factor login (lost phone)</span><?= $codeField ?><button class="btn small danger" data-confirm="Reset two-factor login for <?= e($u['username']) ?>?">Reset 2FA</button></form>
          <form method="post" class="ua"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="signout">
            <span>Sign out remembered devices</span><?= $codeField ?><button class="btn small">Sign out</button></form>
          <form method="post" class="ua"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="password">
            <span>Set a new password</span><input name="password" type="password" minlength="12" required autocomplete="new-password" aria-label="New password" placeholder="12+ characters"><?= $codeField ?><button class="btn small">Save</button></form>
          <form method="post" class="ua"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="role">
            <span>Role</span><select name="role" aria-label="Role"><?php foreach (ROLES as $k => $l): ?><option value="<?= e($k) ?>"<?= $u['role'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select><?= $codeField ?><button class="btn small">Change</button></form>
          <?php if (!$self): ?>
          <form method="post" class="ua"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="delete">
            <span>Delete this user</span><?= $codeField ?><button class="btn small danger" data-confirm="Delete <?= e($u['username']) ?>?">Delete</button></form>
          <?php endif ?>
        </details>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>

<form class="panel medium add-user" method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <h2>Add a user</h2>
  <div class="field"><label for="nu">Username</label><input id="nu" name="username" required autocomplete="off"></div>
  <div class="field"><label for="np">Temporary password (12+ characters)</label><input id="np" name="password" type="password" minlength="12" required autocomplete="new-password"></div>
  <div class="field"><label for="nr">Role</label><select id="nr" name="role"><option value="editor">Editor — content only</option><option value="admin">Administrator — also users, settings, import, export</option></select></div>
  <div class="field"><label for="nc">Your current 2FA code</label><?= str_replace('class="code-input small-code"', 'id="nc" class="code-input"', $codeField) ?></div>
  <button class="btn primary">Add user</button>
</form>
<?php admin_foot();
