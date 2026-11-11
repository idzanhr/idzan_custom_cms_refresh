<?php
/**
 * Local development only: creates a SQLite database from install/schema.sql (written for MySQL),
 * an admin user and, with --seed, the starter content, then builds the site.
 *   php dev/setup-sqlite.php [--seed]
 * Needs config.php with a sqlite DSN (see dev/config.local.sample.php). Production uses MySQL/MariaDB.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = 'install-cli.php';
require dirname(__DIR__) . '/inc/bootstrap.php';
if (!str_starts_with((string)(cfg('db')['dsn'] ?? ''), 'sqlite:')) { fwrite(STDERR, "config.php must use a sqlite: DSN for this script.\n"); exit(1); }
try { $has = (bool)one('SELECT id FROM users LIMIT 1'); } catch (Throwable $e) { $has = false; }
if ($has) { fwrite(STDERR, "Already set up. Delete the .sqlite file to start over.\n"); exit(1); }

$sql = preg_replace('~^--.*$~m', '', (string)file_get_contents(ROOT . '/install/schema.sql'));
$sql = preg_replace(
    ['~INT UNSIGNED AUTO_INCREMENT PRIMARY KEY~', '~\) ENGINE=InnoDB[^;]*~', '~SET NAMES utf8mb4~', '~,\s*KEY \w+ \([^)]*\)~',
     '~,\s*CONSTRAINT \w+ FOREIGN KEY \((\w+)\) REFERENCES (\w+) \((\w+)\)( ON DELETE [A-Z ]+)?~', '~UNIQUE KEY \w+ \(~'],
    ['INTEGER PRIMARY KEY AUTOINCREMENT', ')', '', ' ', '', ' UNIQUE ('], $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) db()->exec($stmt);

$user = getenv('CMS_ADMIN_USER') ?: 'dev';
$pass = getenv('CMS_ADMIN_PASS') ?: bin2hex(random_bytes(9));   // a new password every time: no well-known default login
q('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)', [$user, password_hash($pass, PASSWORD_DEFAULT), 'admin', now()]);
save_setting('secret', bin2hex(random_bytes(32)));
save_setting('schema_version', '3');            // the migrations bring it up to date
if (in_array('--seed', $argv, true)) { require ROOT . '/install/seed.php'; seed_content(); }
require_once ROOT . '/inc/render.php';
require_once ROOT . '/inc/publish.php';
rebuild_static();
echo "Ready. Admin user: {$user}   password: {$pass}   (write it down; two-factor setup on first login).\n";
