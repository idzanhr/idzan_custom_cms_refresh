<?php
declare(strict_types=1);

/**
 * Admin sign-in helpers: hidden admin address, "remember this device" tokens and roles.
 *
 * Remember-me uses the split-token pattern: the cookie holds selector:validator, the
 * database stores only a SHA-256 of the validator, and the validator changes on every
 * use. A wrong validator for a known selector looks like a stolen cookie, so every
 * remembered device of that user is signed out.
 */

const REMEMBER_COOKIE = 'idzcms_remember';

/** Admin address, e.g. "/studio-x7k2" (set admin_path in config.php). */
function admin_base(): string
{
    $p = '/' . trim((string)cfg('admin_path', '/admin'), '/');
    return url($p === '/' ? '/admin' : $p);
}

function remember_days(): int
{
    return max(1, (int)cfg('remember_days', 10));
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function set_remember_cookie(string $value, int $expires): void
{
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => admin_base() . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    $_COOKIE[REMEMBER_COOKIE] = $value;
}

function clear_remember_cookie(): void
{
    set_remember_cookie('', time() - 3600);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/** Remember this browser for this user, starting now. */
function remember_device(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = random_bytes(32);
    $expires = time() + remember_days() * 86400;
    q('INSERT INTO remember_tokens (user_id, selector, validator_hash, user_agent, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$userId, $selector, hash('sha256', $validator), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), now(), now(), date('Y-m-d H:i:s', $expires)]);
    set_remember_cookie($selector . ':' . rtrim(strtr(base64_encode($validator), '+/', '-_'), '='), $expires);
}

/**
 * Sign in from a remember cookie. Returns the user row or null.
 * The expiry stays fixed (counted from the 2FA login); only the secret part rotates.
 */
function login_from_remember_cookie(): ?array
{
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (!preg_match('~^([a-f0-9]{24}):([A-Za-z0-9_-]{43})$~', $raw, $m)) {
        if ($raw !== '') clear_remember_cookie();
        return null;
    }
    q('DELETE FROM remember_tokens WHERE expires_at < ?', [now()]);
    $row = one('SELECT * FROM remember_tokens WHERE selector = ?', [$m[1]]);
    if (!$row) { clear_remember_cookie(); return null; }
    $validator = base64_decode(strtr($m[2], '-_', '+/') . '=');
    if (!hash_equals($row['validator_hash'], hash('sha256', (string)$validator))) {
        // Known selector, wrong secret: treat as a stolen cookie.
        q('DELETE FROM remember_tokens WHERE user_id = ?', [$row['user_id']]);
        clear_remember_cookie();
        return null;
    }
    $user = one('SELECT * FROM users WHERE id = ? AND totp_enabled = 1', [$row['user_id']]);
    if (!$user) { q('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]); clear_remember_cookie(); return null; }

    $new = random_bytes(32);
    q('UPDATE remember_tokens SET validator_hash = ?, last_used_at = ? WHERE id = ?', [hash('sha256', $new), now(), $row['id']]);
    set_remember_cookie($m[1] . ':' . rtrim(strtr(base64_encode($new), '+/', '-_'), '='), strtotime($row['expires_at']));
    return $user;
}

/** Forget the device this request comes from. */
function forget_this_device(): void
{
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (preg_match('~^([a-f0-9]{24}):~', $raw, $m)) q('DELETE FROM remember_tokens WHERE selector = ?', [$m[1]]);
    clear_remember_cookie();
}

function forget_all_devices(int $userId): void
{
    q('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
}

function current_remember_selector(): string
{
    return preg_match('~^([a-f0-9]{24}):~', (string)($_COOKIE[REMEMBER_COOKIE] ?? ''), $m) ? $m[1] : '';
}

/** Short "Firefox on Windows" style label for a user agent. */
function device_label(?string $ua): string
{
    $ua = (string)$ua;
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') => 'Opera',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        default => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS X') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => 'unknown system',
    };
    return "{$browser} on {$os}";
}

/**
 * Fingerprint of the account's credentials. Stored in the session at sign-in; when the
 * password changes or 2FA is reset, it no longer matches and old sessions are ended.
 */
function auth_tag(array $user): string
{
    return hash('sha256', (string)$user['password_hash'] . '|' . (string)$user['totp_secret'] . '|' . (string)$user['totp_enabled']);
}

function refresh_auth_tag(int $userId): void
{
    $u = one('SELECT * FROM users WHERE id = ?', [$userId]);
    if ($u) $_SESSION['auth_tag'] = auth_tag($u);
}

const ROLES = ['admin' => 'Administrator', 'editor' => 'Editor'];
/** Pages only administrators may open. Editors manage content only. */
const ADMIN_ONLY_PAGES = ['users.php', 'settings.php', 'import.php', 'export.php', 'icons.php', 'refresh.php', 'languages.php'];

function is_admin(?array $user = null): bool
{
    $user ??= $GLOBALS['ME'] ?? null;
    return ($user['role'] ?? 'admin') === 'admin';
}
