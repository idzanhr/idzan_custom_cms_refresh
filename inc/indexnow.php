<?php
/**
 * IndexNow (indexnow.org): after every rebuild, tell participating search engines (Bing — which also feeds
 * DuckDuckGo and Copilot — Yandex, Seznam, Naver…) exactly which addresses changed, so they re-read them
 * within minutes instead of waiting for the next crawl.
 *
 * What changed is worked out from the sitemap: an address is sent when it is new, its "last edited" time
 * changed, or it disappeared. The key file /{key}.txt proves the site is ours.
 */
declare(strict_types=1);

const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

function indexnow_enabled(): bool
{
    return (bool)s('indexnow_enabled') && str_starts_with((string)cfg('site_url', ''), 'https://')
        && !preg_match('~^https://(localhost|127\.|\[::1\])~', (string)cfg('site_url', ''));
}

/** The site's key (32 hex characters), created the first time it's needed. */
function indexnow_key(): string
{
    $k = (string)setting('indexnow_key', '');
    if (!preg_match('~^[0-9a-f]{32}$~', $k)) { $k = bin2hex(random_bytes(16)); save_setting('indexnow_key', $k); }
    return $k;
}

/** Called by rebuild_static(): publishes the key file. */
function indexnow_write_key(string $out): void
{
    if (!indexnow_enabled()) return;
    $k = indexnow_key();
    if (@file_get_contents($out . '/' . $k . '.txt') !== $k) write_atomic($out . '/' . $k . '.txt', $k);
}

/** Sends the changed addresses. Called after a successful rebuild; never throws. */
function indexnow_submit(): void
{
    if (!indexnow_enabled()) return;
    $now = $GLOBALS['SITEMAP_STATE'] ?? null;            // [absolute URL => last edited], filled by sitemap_xml()
    if (!$now) return;
    $file = ROOT . '/storage/indexnow-state.json';
    $old = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $changed = [];
    foreach ($now as $u => $d) if (!array_key_exists($u, $old) || $old[$u] !== $d) $changed[] = $u;
    foreach (array_keys($old) as $u) if (!array_key_exists($u, $now)) $changed[] = $u;   // removed: lets engines drop it
    if (!$changed) return;

    // One request per domain (IndexNow wants every address in a request on the same host as "host"):
    // domain.com, plus each language that has its own domain (domain.ba…). The key file answers on all of them.
    $byHost = [];
    foreach ($changed as $u) $byHost[strtolower((string)parse_url($u, PHP_URL_HOST))][] = $u;
    $key = indexnow_key();
    $ok = true; $code = 0; $sent = 0;
    foreach ($byHost as $host => $urls) {
        foreach (array_chunk($urls, 10000) as $chunk) {
            $body = json_encode(['host' => $host, 'key' => $key, 'keyLocation' => 'https://' . $host . '/' . $key . '.txt', 'urlList' => array_values($chunk)], JSON_UNESCAPED_SLASHES);
            [$code, $msg] = indexnow_post((string)cfg('indexnow_endpoint', INDEXNOW_ENDPOINT), $body);
            if ($code !== 200 && $code !== 202) { $ok = false; break 2; }
            $sent += count($chunk);
        }
    }
    $why = [0 => 'no answer (network)', 400 => 'bad request', 403 => 'key not accepted yet (the key file must be reachable)', 422 => 'addresses don\'t match the site', 429 => 'too many submissions, try later'][$code] ?? ('HTTP ' . $code);
    save_setting('indexnow_last', json_encode(['at' => now(), 'ok' => $ok, 'count' => $ok ? $sent : count($changed), 'code' => $code, 'why' => $ok ? '' : $why]));
    if ($ok) write_atomic($file, json_encode($now, JSON_UNESCAPED_SLASHES));   // on failure: retried with the next save
}

/** [HTTP status, body]; status 0 when the request couldn't be made. */
function indexnow_post(string $url, string $json): array
{
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8']]);
        $body = (string)curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        return [$code, $body];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json; charset=utf-8\r\n", 'content' => $json, 'timeout' => 8, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$code, (string)$body];
}

/** For Publish & export: null when off, else the last result. */
function indexnow_status(): ?array
{
    if (!indexnow_enabled()) return null;
    $j = json_decode((string)setting('indexnow_last', ''), true);
    return is_array($j) ? $j : ['at' => null];
}
