<?php
declare(strict_types=1);

/**
 * Clear the Varnish cache after every publish, so visitors see changes immediately.
 *
 * CloudPanel (recommended): point 'varnish' at the site's CloudPanel settings file and the
 * CMS sends exactly what CloudPanel's own "Purge Entire Cache" button sends — a PURGE
 * request tagged with the site's Cache Tag Prefix:
 *   'varnish' => ['cloudpanel' => '/home/SITEUSER/.varnish-cache/settings.json']
 *
 * Plain Varnish (deploy/generic/default.vcl): a BAN request.
 *   'varnish' => ['url' => 'http://127.0.0.1:6081', 'host' => 'domain.com']
 */
function varnish_purge(): bool
{
    $v = cfg('varnish');
    if (!is_array($v)) return false;

    if (!empty($v['cloudpanel'])) {
        $file = (string)$v['cloudpanel'];
        $s = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!is_array($s) || empty($s['enabled']) || empty($s['server']) || empty($s['cacheTagPrefix'])) return false;
        $server = preg_match('~^https?://~', $s['server']) ? $s['server'] : 'http://' . $s['server'];
        return varnish_request($server, 'PURGE', ['X-Cache-Tags: ' . $s['cacheTagPrefix']]);
    }
    if (!empty($v['url'])) {
        $host = $v['host'] ?? (string)parse_url((string)cfg('site_url'), PHP_URL_HOST);
        return varnish_request((string)$v['url'], 'BAN', ['Host: ' . $host, 'X-Ban-Url: .']);
    }
    return false;
}

/** The Cache Tag Prefix CloudPanel uses for this site (also needed in the vhost, see SETUP.md). */
function cloudpanel_cache_tag(): ?string
{
    $file = (string)(cfg('varnish')['cloudpanel'] ?? '');
    $s = $file !== '' && is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($s) && !empty($s['cacheTagPrefix']) ? (string)$s['cacheTagPrefix'] : null;
}

function varnish_request(string $url, string $method, array $headers): bool
{
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers) . "\r\n",
        'timeout' => 3,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents(rtrim($url, '/') . '/', false, $ctx);
    // PHP 8.4+ has http_get_last_response_headers(); the magic variable is deprecated in 8.5.
    $hdrs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $ok = $body !== false && preg_match('~^HTTP/\S+\s+2\d\d~', (string)($hdrs[0] ?? ''));
    if (!$ok) error_log('[cms] Varnish purge failed (' . $method . ' ' . $url . ')');
    return (bool)$ok;
}

/**
 * BunnyCDN: purge the whole pull zone after Varnish, so the CDN edge serves the new pages too.
 * config.php:  'bunny' => ['api_key' => '…', 'pull_zone_id' => 123456],
 * API key: Bunny dashboard → Account settings → API key (it controls the whole Bunny account, so it
 * lives only in config.php, never in the database or the admin). Pull zone id: shown in the address
 * bar when the pull zone is open (…/cdn/123456/…).
 * The result is remembered and shown on Publish & export.
 */
function bunny_purge(): ?bool
{
    $b = cfg('bunny');
    if (!is_array($b) || empty($b['api_key']) || empty($b['pull_zone_id'])) return null;
    // One pull zone (123) or several, for country domains in their own zones: '123,456' or [123, 456].
    $zones = array_values(array_filter(array_map('trim', is_array($b['pull_zone_id']) ? array_map('strval', $b['pull_zone_id']) : explode(',', (string)$b['pull_zone_id'])), 'strlen'));
    foreach ($zones as $z) if (!ctype_digit($z)) {
        save_setting('bunny_last', json_encode(['ok' => false, 'at' => now(), 'why' => 'pull_zone_id must be the pull zone number (from its address), not its name']));
        return false;
    }
    $ok = true; $why = '';
    foreach ($zones as $z) {
        $status = bunny_purge_zone((string)$b['api_key'], $z);
        if ($status >= 200 && $status < 300) continue;
        $ok = false;
        $why = ([0 => 'no answer from api.bunny.net (outgoing connection blocked?)', 401 => 'wrong API key',
                404 => 'no pull zone with that id'][$status] ?? 'HTTP ' . $status) . (count($zones) > 1 ? ' (pull zone ' . $z . ')' : '');
        break;
    }
    save_setting('bunny_last', json_encode(['ok' => $ok, 'at' => now(), 'why' => $why]));
    if (!$ok) error_log('[cms] BunnyCDN purge failed: ' . $why);
    return $ok;
}

/** HTTP status of one pull-zone purge (0 = no answer). */
function bunny_purge_zone(string $key, string $zone): int
{
    $url = 'https://api.bunny.net/pullzone/' . rawurlencode($zone) . '/purgeCache';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['AccessKey: ' . $key, 'Accept: application/json', 'Content-Length: 0'],
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($ch);
        return (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "AccessKey: {$key}\r\nAccept: application/json\r\nContent-Length: 0\r\n",
        'content' => '', 'timeout' => 15, 'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
    $hdrs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    return preg_match('~^HTTP/\S+\s+(\d{3})~', (string)($hdrs[0] ?? ''), $m) ? (int)$m[1] : 0;
}

/** Last BunnyCDN purge result for the admin, or null when Bunny isn't set up. */
function bunny_status(): ?array
{
    $b = cfg('bunny');
    if (!is_array($b) || empty($b['api_key']) || empty($b['pull_zone_id'])) return null;
    $s = json_decode((string)setting('bunny_last', ''), true);
    return is_array($s) ? $s : ['ok' => null, 'at' => '', 'why' => ''];
}

