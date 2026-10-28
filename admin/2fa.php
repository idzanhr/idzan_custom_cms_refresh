<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require ROOT . '/inc/qr.php';

$me = $GLOBALS['ME'];
$enabled = (int)$me['totp_enabled'] === 1;
$issuer = s('site_title') . ' CMS';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'enable' && !$enabled) {
        $secret = (string)($_SESSION['totp_pending'] ?? '');
        $step = $secret ? totp_verify($secret, (string)($_POST['code'] ?? '')) : null;
        if ($step === null) {
            $error = 'That code didn\'t match. Make sure you scanned the code on this page, then enter the newest 6 digits.';
        } else {
            q('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ?, recovery_codes = NULL WHERE id = ?', [$secret, $step, $me['id']]);
            unset($_SESSION['totp_pending']);
            session_regenerate_id(true);
            $_SESSION['mfa_ok'] = true;
            refresh_auth_tag((int)$me['id']);
            if (!empty($_POST['remember'])) remember_device((int)$me['id']);
            flash('Two-factor login is on.');
            redirect(admin_url('index.php'));
        }
    } elseif ($enabled && $action === 'reset') {
        $step = totp_verify((string)$me['totp_secret'], (string)($_POST['code'] ?? ''), (int)$me['totp_last_step']);
        if ($step === null) {
            $error = 'Wrong or expired authenticator code.';
        } elseif (!password_verify((string)($_POST['password'] ?? ''), $me['password_hash'])) {
            $error = 'Wrong password.';
        } else {
            // Move to a new phone / app: switch off, then set up again right away.
            q('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0 WHERE id = ?', [$me['id']]);
            forget_all_devices((int)$me['id']);
            unset($_SESSION['totp_pending']);
            refresh_auth_tag((int)$me['id']);
            flash('Old authenticator removed and remembered devices signed out. Scan the new code to finish.', 'warn');
            redirect(admin_url('2fa.php'));
        }
    } elseif ($enabled && $action === 'forget') {
        q('DELETE FROM remember_tokens WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $me['id']]);
        flash('Device signed out.');
        redirect(admin_url('2fa.php'));
    } elseif ($enabled && $action === 'forget_all') {
        forget_all_devices((int)$me['id']);
        clear_remember_cookie();
        flash('All remembered devices signed out. They\'ll need the password and a code next time.');
        redirect(admin_url('2fa.php'));
    }
}

if (!$enabled) {
    $_SESSION['totp_pending'] ??= totp_new_secret();
    $secret = $_SESSION['totp_pending'];
    $uri = totp_uri($secret, $me['username'], $issuer);
}
$devices = $enabled ? all('SELECT * FROM remember_tokens WHERE user_id = ? AND expires_at > ? ORDER BY last_used_at DESC', [$me['id'], now()]) : [];
$thisDevice = current_remember_selector();

admin_head('Two-factor login', '2fa');
?>
<header class="pagehead"><h1>Two-factor login</h1></header>
<?php if ($error): ?><p class="flash err" role="alert"><?= e($error) ?></p><?php endif ?>

<?php if (!$enabled): ?>
<section class="panel medium">
  <p class="flash warn">Two-factor login is required for this CMS. Finish these steps to continue.</p>
  <ol class="steps">
    <li>Open <strong>Proton Authenticator</strong> (or any authenticator app) and choose to add an account.</li>
    <li>Scan this QR code.
      <div class="qr"><?= QrSvg::svg($uri, 232, '#000000', '#ffffff') ?></div>
      <details><summary>Can't scan? Enter the key by hand</summary>
        <p class="help">Account: <strong><?= e($issuer) ?> (<?= e($me['username']) ?>)</strong> · Type: time-based (TOTP) · 6 digits · 30 seconds</p>
        <p><code class="secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code></p>
      </details>
    </li>
    <li>Type the 6-digit code the app shows:
      <form method="post" class="verify-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="enable">
        <div class="inline"><input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required aria-label="6-digit code" class="code-input">
        <button class="btn primary" type="submit">Turn on</button></div>
        <label class="check remember"><input type="checkbox" name="remember" value="1" checked> Keep me signed in on this device for <?= remember_days() ?> days</label>
      </form>
    </li>
  </ol>
  <p class="help">If you ever lose access to the authenticator, an administrator resets it under Users, or the server owner runs <code>php tools/reset-2fa.php <?= e($me['username']) ?></code>.</p>
</section>

<?php else: ?>
<section class="panel medium">
  <p class="status-on">● Two-factor login is on for <strong><?= e($me['username']) ?></strong>.</p>
  <p class="help">Lost your phone? <?= is_admin() ? 'Another administrator can reset it under Users, or run <code>php tools/reset-2fa.php ' . e($me['username']) . '</code> on the server.' : 'Ask an administrator to reset it for you.' ?></p>
</section>

<section class="panel medium">
  <h2>Remembered devices</h2>
  <p class="help">Devices where you ticked “Keep me signed in”. Each stays signed in for <?= remember_days() ?> days after its 2FA login.</p>
  <?php if (!$devices): ?>
    <p class="muted">None.</p>
  <?php else: ?>
    <ul class="devices">
    <?php foreach ($devices as $d): ?>
      <li>
        <div><strong><?= e(device_label($d['user_agent'])) ?></strong><?= $d['selector'] === $thisDevice ? ' <span class="pill published">This device</span>' : '' ?>
          <span class="muted">Last used <?= e(date('d.m.Y. H:i', strtotime($d['last_used_at']))) ?> · until <?= e(date('d.m.Y.', strtotime($d['expires_at']))) ?></span></div>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="forget"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="btn small">Sign out</button></form>
      </li>
    <?php endforeach ?>
    </ul>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="forget_all">
      <button class="btn small danger" data-confirm="Sign out every remembered device?">Sign out all devices</button></form>
  <?php endif ?>
</section>

<section class="panel medium">
  <h2>Move to a new phone or app</h2>
  <p class="help">Removes the current authenticator, signs out remembered devices and shows a new QR code to scan.</p>
  <form method="post" class="stack-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="reset">
    <div class="field"><label for="rp">Password</label><input id="rp" name="password" type="password" autocomplete="current-password" required></div>
    <div class="field"><label for="rc">Current 6-digit code</label><input id="rc" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required class="code-input"></div>
    <button class="btn danger" type="submit" data-confirm="Remove the current authenticator? You'll scan a new QR code straight away.">Replace authenticator</button>
  </form>
</section>
<?php endif ?>
<?php admin_foot();
