<?php
declare(strict_types=1);

/**
 * AI / machine translation of the site's content into the languages in LANGS.
 *
 * Providers (API keys only in config.php → 'translate'):
 *   google  – Google Cloud Translation, Basic (v2)   'google_api_key'
 *   gemini  – Google Gemini API (free tier works)     'gemini_api_key', 'gemini_model'
 *   claude  – Anthropic Claude API                    'claude_api_key', 'claude_model'
 *
 * Work is split into "items" (one post, one page, the site texts, the interface texts…) and each
 * item is translated into one language per request, so the admin page can show progress, pause
 * on rate limits and continue where it stopped.
 */

const TR_PROVIDERS = ['google' => 'Google Translate (basic)', 'gemini' => 'Google Gemini', 'claude' => 'Anthropic Claude', 'openai' => 'OpenAI (ChatGPT)'];
const TR_DEFAULT_MODELS = ['gemini' => 'gemini-3.8-flash', 'claude' => 'claude-haiku-5-5', 'openai' => 'gpt-6-luna'];
/** After the main service hits a limit, the backup is used straight away for this long (seconds). */
const TR_FALLBACK_HOLD = 900;

final class TrRateLimit extends RuntimeException
{
    public int $retry;
    public function __construct(string $msg, int $retry) { parent::__construct($msg); $this->retry = $retry; }
}

function tr_cfg(string $key, string $default = ''): string
{
    return (string)((cfg('translate') ?? [])[$key] ?? $default);
}

/** Providers that have an API key in config.php. */
function tr_ready_providers(): array
{
    $keys = ['google' => 'google_api_key', 'gemini' => 'gemini_api_key', 'claude' => 'claude_api_key', 'openai' => 'openai_api_key'];
    return array_values(array_filter(array_keys(TR_PROVIDERS), fn($p) => tr_cfg($keys[$p]) !== ''));
}

function tr_provider(): string
{
    $ready = tr_ready_providers();
    $p = setting('tr_provider', '');
    return in_array($p, $ready, true) ? $p : ($ready[0] ?? '');
}

/** Backup service, used when the main one hits its limit (rate limit, daily quota or spending cap). '' = none. */
function tr_fallback(): string
{
    $f = setting('tr_fallback', '');
    return $f !== tr_provider() && in_array($f, tr_ready_providers(), true) ? $f : '';
}

/** True for errors that mean "this service can't take more right now", as opposed to a problem with the text. */
function tr_is_limit(Throwable $e): bool
{
    return $e instanceof TrRateLimit
        || (bool)preg_match('~quota|exhausted|billing|spending|credit|insufficient|limit~i', $e->getMessage());
}

/* ---------------- what gets translated ---------------- */

/** All items: [entity, id, label, type label]. Only published content (drafts aren't on the site). */
function tr_items(): array
{
    $items = [['ui', 0, 'Interface texts (menus, buttons, labels)', 'Interface'], ['settings', 0, 'Site texts (tagline, About me, footer, cookie banner)', 'Site texts']];
    $types = ['posts' => ['Blog post', 'title'], 'projects' => ['Project', 'title'], 'pages' => ['Page', 'title'], 'experience' => ['Work experience', 'role'],
              'education' => ['Education', 'institution'], 'affiliates' => ['Affiliate', 'title'], 'contacts' => ['Contact link', 'label']];
    foreach ($types as $table => [$type, $tf]) {
        try { $rows = all_raw("SELECT id, {$tf} AS t FROM {$table} WHERE status = 'published' ORDER BY id"); } catch (Throwable $e) { continue; }
        foreach ($rows as $r) $items[] = [$table, (int)$r['id'], (string)$r['t'], $type];
    }
    foreach (all_raw("SELECT id, name, taxonomy FROM terms WHERE taxonomy IN ('category', 'service') ORDER BY taxonomy, name") as $r) {
        $items[] = ['terms', (int)$r['id'], (string)$r['name'], $r['taxonomy'] === 'category' ? 'Category' : 'Service'];
    }
    return $items;
}

/** English source of an item: [field => text], empty fields left out. */
function tr_source(string $entity, int $id): array
{
    if ($entity === 'ui') {
        $out = [];
        foreach (UI_STRINGS as $s) $out[md5($s)] = $s;
        return $out;
    }
    if ($entity === 'settings') {
        $out = [];
        foreach (TRANSLATE_SETTINGS as $k) { $v = s_raw($k); if (trim(strip_tags($v)) !== '') $out[$k] = $v; }
        return $out;
    }
    $fields = TRANSLATE_FIELDS[$entity] ?? [];
    if (!$fields) return [];
    $row = all_raw('SELECT ' . implode(', ', $fields) . " FROM {$entity} WHERE id = ?", [$id])[0] ?? null;
    if (!$row) return [];
    return array_filter(array_map('strval', $row), fn($v) => trim(strip_tags($v)) !== '');
}

/** English setting value without translation (s() would translate on language pages). */
function s_raw(string $key): string
{
    $saved = $GLOBALS['LANG'] ?? null;
    unset($GLOBALS['LANG']);
    $v = s($key);
    if ($saved !== null) $GLOBALS['LANG'] = $saved;
    return $v;
}

/** 'missing' | 'outdated' | 'done' | 'draft' for an item in a language. */
function tr_status(string $entity, int $id, string $lang, ?array $src = null, ?array $rows = null): string
{
    $src ??= tr_source($entity, $id);
    if (!$src) return 'done';
    $rows ??= tr_rows($entity, $id, $lang);
    if (!$rows) return 'missing';
    foreach ($src as $f => $v) if (!isset($rows[$f]) || $rows[$f]['src_hash'] !== md5($v)) return 'outdated';
    foreach ($rows as $r) if ($r['status'] !== 'published') return 'draft';
    return 'done';
}

function tr_rows(string $entity, int $id, string $lang): array
{
    $out = [];
    foreach (all_raw('SELECT field, value, src_hash, status, engine, updated_at FROM translations WHERE entity = ? AND entity_id = ? AND lang = ?', [$entity, $id, $lang]) as $r) $out[$r['field']] = $r;
    return $out;
}

function tr_save(string $entity, int $id, string $lang, string $field, string $value, string $srcHash, string $status, string $engine): void
{
    q('DELETE FROM translations WHERE entity = ? AND entity_id = ? AND lang = ? AND field = ?', [$entity, $id, $lang, $field]);
    q('INSERT INTO translations (entity, entity_id, lang, field, value, src_hash, status, engine, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$entity, $id, $lang, $field, $value, $srcHash, $status, $engine, now()]);
}

/**
 * "Copy images from the English originals": saves tr_sync_media() into every stored translation.
 * $markCurrent: translations whose images were updated are marked up to date with the current original
 * (for when only image addresses changed in the originals, e.g. after "Fix old WordPress image links").
 * Returns [translations changed, of which marked up to date].
 */
function tr_sync_all(bool $markCurrent): array
{
    $changed = 0; $marked = 0; $src = [];
    foreach (all_raw("SELECT id, entity, entity_id, lang, field, value, src_hash FROM translations WHERE value LIKE '%<img%' OR value LIKE '%uploads/%' OR value LIKE '%wp-content%'") as $r) {
        $k = $r['entity'] . ':' . $r['entity_id'];
        $src[$k] ??= tr_source((string)$r['entity'], (int)$r['entity_id']);
        $orig = $src[$k][$r['field']] ?? null;
        if ($orig === null) continue;
        $new = tr_sync_media($orig, (string)$r['value']);
        if ($new === $r['value']) continue;
        $hash = $markCurrent ? md5($orig) : $r['src_hash'];
        if ($markCurrent && $hash !== $r['src_hash']) $marked++;
        q('UPDATE translations SET value = ?, src_hash = ? WHERE id = ?', [$new, $hash, $r['id']]);
        $changed++;
    }
    if ($changed) $GLOBALS['TR_RESET'] = true;
    return [$changed, $marked];
}

/* ---------------- translating one item ---------------- */

/** Translates an item into a language with the chosen provider and stores it. Returns number of fields. */
function tr_translate_item(string $entity, int $id, string $lang, ?string $provider = null): int
{
    if ($provider !== null) return tr_translate_with($entity, $id, $lang, $provider);
    $main = tr_provider();
    $backup = tr_fallback();
    // The main service hit its limit a moment ago: go straight to the backup for a while.
    if ($backup !== '' && (int)setting('tr_main_paused_until', '0') > time()) {
        $GLOBALS['TR_USED'] = $backup;
        return tr_translate_with($entity, $id, $lang, $backup);
    }
    try {
        $GLOBALS['TR_USED'] = $main;
        return tr_translate_with($entity, $id, $lang, $main);
    } catch (Throwable $e) {
        if ($backup === '' || !tr_is_limit($e)) throw $e;
        save_setting('tr_main_paused_until', (string)(time() + max(TR_FALLBACK_HOLD, $e instanceof TrRateLimit ? $e->retry : 0)));
        $GLOBALS['TR_USED'] = $backup;
        return tr_translate_with($entity, $id, $lang, $backup);
    }
}

function tr_translate_with(string $entity, int $id, string $lang, string $provider): int
{
    if (!isset(LANGS[$lang])) throw new RuntimeException('Unknown language ' . $lang);
    if ($provider === '') throw new RuntimeException('No translation service is set up: add an API key under \'translate\' in config.php.');
    $src = tr_source($entity, $id);
    if (!$src) return 0;

    // Long texts are split at block boundaries so every request stays small.
    $pieces = [];
    foreach ($src as $f => $v) {
        $parts = mb_strlen($v) > 7000 ? tr_split_html($v, 6000) : [$v];
        foreach ($parts as $i => $p) $pieces[$f . '#' . $i] = $p;
    }
    $limit = $provider === 'google' ? 4500 : 9000;
    $batches = []; $cur = []; $size = 0;
    foreach ($pieces as $k => $v) {
        $len = mb_strlen($v);
        if ($cur && $size + $len > $limit) { $batches[] = $cur; $cur = []; $size = 0; }
        $cur[$k] = $v; $size += $len;
    }
    if ($cur) $batches[] = $cur;

    $done = [];
    foreach ($batches as $batch) $done += tr_call($provider, $lang, $batch);

    $out = [];
    foreach ($pieces as $k => $v) {
        [$f] = explode('#', $k, 2);
        $out[$f] = ($out[$f] ?? '') . ($done[$k] ?? $v);
    }
    $status = setting('tr_autopublish', '1') === '1' ? 'published' : 'draft';
    foreach ($out as $f => $v) {
        $v = tr_clean($entity, $f, $src[$f], $v, $lang);
        tr_save($entity, $id, $lang, $f, $v, md5($src[$f]), $status, $provider);
    }
    return count($out);
}

/** Splits HTML into chunks of about $max characters at the end of top-level blocks. */
function tr_split_html(string $html, int $max): array
{
    $blocks = preg_split('~(?<=</p>|</h2>|</h3>|</h4>|</ul>|</ol>|</figure>|</blockquote>|</pre>|</table>|</div>|<hr>)~i', $html) ?: [$html];
    $chunks = []; $cur = '';
    foreach ($blocks as $b) {
        if ($cur !== '' && mb_strlen($cur) + mb_strlen($b) > $max) { $chunks[] = $cur; $cur = ''; }
        $cur .= $b;
    }
    if ($cur !== '') $chunks[] = $cur;
    return $chunks;
}

/** Final checks on a translated value; falls back to English where something broke. */
function tr_clean(string $entity, string $field, string $src, string $v, string $lang): string
{
    $v = trim($v);
    if ($v === '') return $src;
    $isHtml = str_contains($src, '<');
    if ($isHtml) $v = clean_html($v);
    else $v = html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Placeholders (%s, %1$s, %d) and the contact-links marker must survive.
    preg_match_all('~%(?:\d\$)?[sd]~', $src, $a); preg_match_all('~%(?:\d\$)?[sd]~', $v, $b);
    if (count($a[0]) !== count($b[0])) return $src;
    if (str_contains($src, CONTACT_MARKER) && !str_contains($v, CONTACT_MARKER)) $v .= "\n<p>" . CONTACT_MARKER . '</p>';
    // Footer links: every "Label | URL" line must keep its address.
    if ($entity === 'settings' && $field === 'footer_links') {
        $srcLines = link_lines($src); $newLines = link_lines($v);
        if (count($srcLines) !== count($newLines)) return $src;
        foreach ($srcLines as $i => [, $h]) if (($newLines[$i][1] ?? '') !== $h) return $src;
    }
    return $v;
}

/* ---------------- providers ---------------- */

/** Translates [key => text] into $lang. Returns [key => translated text]. */
function tr_call(string $provider, string $lang, array $texts): array
{
    $texts = array_map(fn($t) => str_replace(CONTACT_MARKER, '<span class="notranslate" translate="no">' . CONTACT_MARKER . '</span>', $t), $texts);
    $out = match ($provider) {
        'google' => tr_google($lang, $texts),
        'gemini' => tr_llm('gemini', $lang, $texts),
        'claude' => tr_llm('claude', $lang, $texts),
        'openai' => tr_llm('openai', $lang, $texts),
        default  => throw new RuntimeException('Unknown provider ' . $provider),
    };
    foreach ($out as $k => $v) {
        $out[$k] = preg_replace('~<span class="notranslate" translate="no">\s*\[contact-links\]\s*</span>~i', CONTACT_MARKER, (string)$v);
        if ($lang === 'sr' && preg_match('~\p{L}~u', strip_tags($out[$k])) && !preg_match('~\p{Cyrillic}~u', $out[$k]) && preg_match('~\p{Latin}{4,}~u', strip_tags($texts[$k] ?? ''))) {
            throw new RuntimeException('The Serbian translation came back in Latin script instead of Cyrillic. Try again or pick another service.');
        }
    }
    return $out;
}

function tr_http(string $url, array $headers, string $body): array
{
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 180,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $respHeaders[strtolower(trim($k))] = trim($v); }
            return strlen($line);
        },
    ]);
    $res = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($res === false) throw new RuntimeException('No answer from the translation service: ' . $err);
    $json = json_decode((string)$res, true);
    if ($status === 429 || $status === 529 || $status === 503) {
        $retry = (int)($respHeaders['retry-after'] ?? 0) ?: 60;
        throw new TrRateLimit('The translation service is busy or the free-tier limit was reached (HTTP ' . $status . ').', min(max($retry, 20), 900));
    }
    if ($status < 200 || $status >= 300) {
        $msg = $json['error']['message'] ?? ($json['error']['type'] ?? '') ?: substr(strip_tags((string)$res), 0, 200);
        throw new RuntimeException('Translation service error (HTTP ' . $status . '): ' . $msg);
    }
    if (!is_array($json)) throw new RuntimeException('The translation service sent an unreadable answer.');
    return $json;
}

/** Google Cloud Translation Basic (v2). Serbian ("sr") comes back in Cyrillic. */
function tr_google(string $lang, array $texts): array
{
    $out = [];
    foreach (['html' => array_filter($texts, fn($t) => str_contains($t, '<')), 'text' => array_filter($texts, fn($t) => !str_contains($t, '<'))] as $format => $group) {
        if (!$group) continue;
        $body = 'source=en&target=' . rawurlencode($lang) . '&format=' . $format;
        foreach ($group as $t) $body .= '&q=' . rawurlencode($t);
        $json = tr_http('https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode(tr_cfg('google_api_key')),
            ['Content-Type: application/x-www-form-urlencoded; charset=utf-8'], $body);
        $tr = $json['data']['translations'] ?? [];
        $i = 0;
        foreach ($group as $k => $t) {
            $v = (string)($tr[$i++]['translatedText'] ?? $t);
            $out[$k] = $format === 'text' ? html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $v;
        }
    }
    return $out;
}

function tr_instructions(string $lang): string
{
    return 'You are a professional translator for a personal website (web design, system administration, tech reviews, blog). '
        . 'Translate every value of the JSON object you receive from English into ' . LANGS[$lang][2] . '. '
        . 'Rules: keep all HTML tags and attributes exactly as they are and translate only the visible text; '
        . 'do not translate URLs, email addresses, code, file names, promo codes, product, brand and company names; '
        . 'keep placeholders like %s, %d, %1$s, %2$s exactly as they are; keep anything inside translate="no" unchanged; '
        . 'use natural, friendly language for the target country; keep emoji. '
        . 'Answer with only a JSON object that has exactly the same keys, each with its translated value. No explanations.';
}

/** Gemini or Claude: one JSON in, the same keys back. */
function tr_llm(string $provider, string $lang, array $texts): array
{
    $payload = json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($provider === 'gemini') {
        $model = tr_cfg('gemini_model', TR_DEFAULT_MODELS['gemini']);
        $json = tr_http('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            ['Content-Type: application/json', 'x-goog-api-key: ' . tr_cfg('gemini_api_key')],
            json_encode([
                'systemInstruction' => ['parts' => [['text' => tr_instructions($lang)]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $payload]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.2],
            ], JSON_UNESCAPED_UNICODE));
        $text = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) $text .= $part['text'] ?? '';
        if ($text === '') throw new RuntimeException('Gemini returned no text (' . ($json['candidates'][0]['finishReason'] ?? $json['promptFeedback']['blockReason'] ?? 'unknown reason') . ').');
    } elseif ($provider === 'openai') {
        $model = tr_cfg('openai_model', TR_DEFAULT_MODELS['openai']);
        $req = [
            'model' => $model, 'max_completion_tokens' => 16000,
            'reasoning_effort' => 'none',                    // translation needs no "thinking": faster and cheaper
            'response_format' => ['type' => 'json_object'],
            'messages' => [['role' => 'system', 'content' => tr_instructions($lang)], ['role' => 'user', 'content' => $payload]],
        ];
        $send = fn(array $r) => tr_http('https://api.openai.com/v1/chat/completions',
            ['Content-Type: application/json', 'Authorization: Bearer ' . tr_cfg('openai_api_key')], json_encode($r, JSON_UNESCAPED_UNICODE));
        try { $json = $send($req); }
        catch (RuntimeException $e) {
            // An older or different model in config.php may not know an option: drop it and ask once more.
            if ($e instanceof TrRateLimit || !preg_match('~reasoning_effort|response_format|max_completion_tokens~', $e->getMessage(), $m)) throw $e;
            if ($m[0] === 'max_completion_tokens') { unset($req['max_completion_tokens']); $req['max_tokens'] = 16000; } else unset($req[$m[0]]);
            $json = $send($req);
        }
        $text = (string)($json['choices'][0]['message']['content'] ?? '');
        if (($json['choices'][0]['finish_reason'] ?? '') === 'length') throw new RuntimeException('The answer was cut off (text too long). Try again; long posts are split automatically.');
        if ($text === '') throw new RuntimeException('OpenAI returned no text' . (!empty($json['choices'][0]['message']['refusal']) ? ': ' . $json['choices'][0]['message']['refusal'] : '.'));
    } else {
        $model = tr_cfg('claude_model', TR_DEFAULT_MODELS['claude']);
        $json = tr_http('https://api.anthropic.com/v1/messages',
            ['Content-Type: application/json', 'x-api-key: ' . tr_cfg('claude_api_key'), 'anthropic-version: 2023-06-01'],
            json_encode([
                'model' => $model, 'max_tokens' => 16000, 'temperature' => 0.2,
                'system' => tr_instructions($lang),
                'messages' => [['role' => 'user', 'content' => $payload]],
            ], JSON_UNESCAPED_UNICODE));
        $text = '';
        foreach ($json['content'] ?? [] as $block) if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        if (($json['stop_reason'] ?? '') === 'max_tokens') throw new RuntimeException('The answer was cut off (text too long). Try again; long posts are split automatically.');
    }
    $text = trim(preg_replace('~^```(?:json)?\s*|\s*```$~', '', trim($text)));
    $data = json_decode($text, true);
    if (!is_array($data)) throw new RuntimeException('The AI answer was not valid JSON. Try again.');
    $out = [];
    foreach ($texts as $k => $t) $out[$k] = isset($data[$k]) && is_string($data[$k]) ? $data[$k] : $t;
    return $out;
}
