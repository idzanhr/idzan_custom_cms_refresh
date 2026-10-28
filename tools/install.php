<?php
declare(strict_types=1);
/**
 * Command-line installer (use this on nginx, where install.php isn't served).
 *   php tools/install.php            asks for an admin username and password
 *   php tools/install.php --seed     also pre-fills the starter content
 * Needs config.php with the database details. Run once.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = 'install-cli.php';
require dirname(__DIR__) . '/inc/bootstrap.php';
if (!is_file(ROOT . '/config.php')) { fwrite(STDERR, "Create config.php first (copy config.sample.php).\n"); exit(1); }

try { $has = (bool)one('SELECT id FROM users LIMIT 1'); } catch (Throwable $e) { $has = false; }
if ($has) { fwrite(STDERR, "Already installed. To reset a lost authenticator use tools/reset-2fa.php.\n"); exit(1); }

function ask(string $q, bool $hidden = false): string
{
    echo $q;
    if ($hidden && DIRECTORY_SEPARATOR === '/') system('stty -echo');
    $v = trim((string)fgets(STDIN));
    if ($hidden && DIRECTORY_SEPARATOR === '/') { system('stty echo'); echo "\n"; }
    return $v;
}

$user = getenv('CMS_ADMIN_USER') ?: ask('Admin username: ');
$pass = getenv('CMS_ADMIN_PASS') ?: ask('Admin password (12+ characters): ', true);
if (!preg_match('~^[A-Za-z0-9._@-]{3,64}$~', $user)) { fwrite(STDERR, "Username: 3–64 letters, numbers, . _ @ -\n"); exit(1); }
if (mb_strlen($pass) < 12) { fwrite(STDERR, "Password needs at least 12 characters.\n"); exit(1); }

$sql = preg_replace('~^--.*$~m', '', (string)file_get_contents(ROOT . '/install/schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) db()->exec($stmt);
q('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)', [$user, password_hash($pass, PASSWORD_DEFAULT), 'admin', now()]);
save_setting('secret', bin2hex(random_bytes(32)));
save_setting('schema_version', (string)SCHEMA_VERSION);
if (in_array('--seed', $argv, true)) {
    require ROOT . '/install/seed.php';
    seed_content();
    echo "Starter content added.\n";
}
foreach ([public_dir() . '/uploads', ROOT . '/storage'] as $d) if (!is_dir($d)) @mkdir($d, 0775, true);
if (static_mode()) { require ROOT . '/inc/render.php'; echo 'Built ' . rebuild_static() . " public files.\n"; }
echo "Installed. Open " . rtrim((string)cfg('site_url'), '/') . admin_base() . "/ and set up two-factor login.\n";
