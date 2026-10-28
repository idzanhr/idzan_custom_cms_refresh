<?php
declare(strict_types=1);
/**
 * Emergency 2FA reset when both the phone and the recovery codes are lost.
 * Run on the server over SSH:   php tools/reset-2fa.php your-username
 * The next login will ask you to scan a new QR code.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = 'reset-2fa.php';
require dirname(__DIR__) . '/inc/bootstrap.php';
run_migrations();

$user = $argv[1] ?? '';
if ($user === '') { fwrite(STDERR, "Usage: php tools/reset-2fa.php <username>\n"); exit(1); }
$row = one('SELECT id FROM users WHERE username = ?', [$user]);
if (!$row) { fwrite(STDERR, "No user called {$user}.\n"); exit(1); }
q('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, recovery_codes = NULL WHERE id = ?', [$row['id']]);
echo "Two-factor login removed for {$user}. Log in with your password and scan the new QR code.\n";
