<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
/** Shown at the bottom of every admin page, so you can see which code the server runs. */
const CMS_VERSION = '2026.10.17-28 (robots.txt: Internet Archive)';

$configFile = ROOT . '/config.php';
if (!is_file($configFile)) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php' && !defined('CMS_NO_CONFIG')) {
        http_response_code(503);
        exit('Not configured yet. Copy config.sample.php to config.php, then open /install.php');
    }
    $GLOBALS['CONFIG'] = require ROOT . '/config.sample.php';
} else {
    try {
        $GLOBALS['CONFIG'] = require $configFile;
    } catch (Error $e) {
        // ParseError (a typo) or Error (e.g. a value without quotes, read as an unknown constant).
        // Don't echo PHP's own message: it can contain a piece of the password.
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("config.php has a typo on or just before line {$e->getLine()}.\n\n"
            . "Most common causes:\n"
            . "- a ' or \\ inside the password: put a \\ in front of it, e.g. 'ab\\'cd'\n"
            . "- a missing ' around a value, or a missing , at the end of a line\n"
            . "- text without quotes where a number belongs (e.g. 'pull_zone_id' => 1234567)\n");
    }
    if (!is_array($GLOBALS['CONFIG'])) {
        http_response_code(500);
        exit('config.php must end with the closing ]; of the settings list (compare it with config.sample.php).');
    }
}

// On the setup page (before anything is installed), show what went wrong instead of a bare 500.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'setup.php') {
    set_exception_handler(function (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Setup error: ' . $e->getMessage() . "\n(" . basename($e->getFile()) . ' line ' . $e->getLine() . ')';
    });
}
foreach (defined('CMS_NO_CONFIG') ? ['mbstring'] : ['mbstring', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext) && !($ext === 'pdo_mysql' && str_starts_with((string)($GLOBALS['CONFIG']['db']['dsn'] ?? ''), 'sqlite'))) {
        http_response_code(500);
        exit("The PHP extension '{$ext}' is missing. In CloudPanel it's normally on; check the site's PHP version settings.");
    }
}

date_default_timezone_set($GLOBALS['CONFIG']['timezone'] ?? 'Europe/Zagreb');
mb_internal_encoding('UTF-8');

require ROOT . '/inc/helpers.php';
require ROOT . '/inc/i18n.php';
require ROOT . '/inc/schema.php';
require ROOT . '/inc/sanitize.php';
require ROOT . '/inc/svg.php';
require ROOT . '/inc/media.php';
require ROOT . '/inc/totp.php';
require ROOT . '/inc/migrate.php';
require ROOT . '/inc/auth.php';
require ROOT . '/inc/varnish.php';
require ROOT . '/inc/publish.php';
require ROOT . '/inc/favicon.php';
require ROOT . '/inc/contacts.php';
require ROOT . '/inc/boxes.php';
require ROOT . '/inc/perf.php';
require ROOT . '/inc/images.php';
require ROOT . '/inc/seo.php';
require ROOT . '/inc/indexnow.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.gc_maxlifetime', (string)(5 * 3600)); // longer than the 4-hour idle limit
    ini_set('session.use_strict_mode', '1');
    session_name('idzcms');
    session_set_cookie_params([
        'lifetime' => 0,
        // The cookie never goes to the public site (the web installer is the one exception).
        'path'     => basename($_SERVER['SCRIPT_NAME'] ?? '') === 'install.php' ? url('/') : admin_base() . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
