<?php
/**
 * Central AI routing — every destination comes from Settings → AI Engine (the ai_settings row).
 * When the box is enabled we health-check it first (/health); if it's down we say so. If the box
 * answers, we use its value; if it returns nothing we fall over to the cloud endpoint that an
 * admin has configured in the same screen.
 *
 * There is no hardcoded or environment-driven endpoint any more: the CRM will only ever contact
 * a URL that is explicitly entered in Settings.
 *
 *   crm_ai_settings()                         -> config row (or null)
 *   crm_ai_box_health()                       -> ['up'=>bool,'degraded'=>bool,'metrics'=>[],'error'=>str]
 *   crm_ai_call($userInput,$len,$path)        -> ['response'=>str,'source'=>box|cloud] | null
 */
require_once __DIR__ . '/../../config/database.php';

function crm_ai_settings(): ?array
{
    static $c = null;
    if ($c !== null) return $c ?: null;
    try { $c = getDB()->query("SELECT * FROM ai_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { $c = []; }
    return $c ?: null;
}

/** Probe the AI box /health. Cached per-request. */
function crm_ai_box_health(?string $url = null): array
{
    static $cache = [];
    $s = crm_ai_settings();
    $url = $url ?: ($s['box_url'] ?? '');
    if ($url === '') return ['up' => false, 'error' => 'no box url configured'];
    if (isset($cache[$url])) return $cache[$url];

    $ch = curl_init(rtrim($url, '/') . '/health');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_SSL_VERIFYPEER=>false]);
    $resp = curl_exec($ch); $errno = curl_errno($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($errno) return $cache[$url] = ['up' => false, 'error' => 'unreachable (box may be down)'];
    $j = json_decode($resp, true) ?: [];
    $up = ($code === 200) && (($j['chat'] ?? '') === 'reachable' || ($j['status'] ?? '') === 'ok');
    return $cache[$url] = [
        'up' => $up, 'degraded' => ($code === 503), 'http' => $code,
        'metrics' => ['requests'=>$j['requests']??null, 'cpu'=>$j['cpu']??null, 'memory'=>$j['memory']??null,
                      'chat'=>$j['chat']??null, 'embed'=>$j['embed']??null],
        'error' => $up ? '' : 'box degraded/unreachable',
    ];
}

/**
 * POST to the AI box; return the reply string or null.
 * Speaks the Superchat chat_v3 contract by default, OR OpenAI-compatible format when the
 * configured box URL is an OpenAI-style endpoint (…/chat/completions or …/v1/…), so a box
 * running an OpenAI-compatible server works without extra configuration.
 */
function _ai_post(string $url, string $userInput, string $len, ?string $key, string $path): ?string
{
    $isOpenAi = (bool)preg_match('~/chat/completions|/v1(/|$)~i', $url);
    if ($isOpenAi) {
        $maxTok = ['short'=>350,'medium'=>700,'long'=>1200][$len] ?? 700;
        $body = ['messages' => [
            ['role'=>'system','content'=>'You are a precise sales-operations analyst. When asked for JSON, output ONLY raw JSON.'],
            ['role'=>'user','content'=>$userInput],
        ], 'max_tokens' => $maxTok, 'target_lang' => 'en'];
    } else {
        $body = ['user_input' => $userInput, 'response_length' => 'auto', 'conversation_id' => null,  'target_lang' => 'en'];
    }
    // 'Expect:' (empty) disables libcurl's automatic "Expect: 100-continue" header for
    // POST bodies over ~1KB — without it, PHP-cURL stalls ~1s waiting for a 100-Continue
    // reply that most simple app/LLM servers never send (Postman doesn't add this header,
    // which is why the same endpoint looks instant there but slow from PHP).
    $headers = ['Content-Type: application/json', 'Expect:'];
    if ($key) $headers[] = 'Authorization: Bearer ' . $key;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($body), CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_TIMEOUT=>60, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_SSL_VERIFYPEER=>false]);
    $resp = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
    if ($errno) return null;
    $j = json_decode($resp, true);
    if (!is_array($j)) return is_string($resp) && trim($resp) !== '' ? $resp : null;
    if ($isOpenAi) {
        $out = $j['choices'][0]['message']['content'] ?? null;
        return ($out !== null && trim($out) !== '') ? $out : null;
    }
    $out = $j['response'] ?? $j['translation'] ?? $j['summary'] ?? null;
    return ($out !== null && trim($out) !== '') ? $out : null;
}

/**
 * Get an AI completion with box→cloud→local fallback.
 * $path is the box endpoint ('chat_v3' | 'summarize' | 'translate').
 */
/** Status of the last crm_ai_call: 'ok' | 'box_offline' | 'unavailable'. */
function crm_ai_status(?string $set = null): string
{
    static $st = 'ok';
    if ($set !== null) $st = $set;
    return $st;
}

/**
 * Routing rules — Settings-configured endpoints ONLY:
 *   • Box CHECKED + online   → try the box, then the cloud endpoint from Settings.
 *   • Box CHECKED + offline  → return null with status 'box_offline' (callers show "AI box offline";
 *                              we deliberately do NOT silently use the cloud).
 *   • Box UNCHECKED          → cloud endpoint from Settings.
 *
 * Both destinations come from the ai_settings row (Settings → AI Engine). The old
 * env-driven last-resort fallback (SERVICE_CHAT_ENDPOINT / LLM_URL via ai-bridge.php)
 * pointed at an unowned third-party host and has been removed, so nothing outside what
 * an admin has explicitly entered in Settings is ever contacted.
 */
function crm_ai_call(string $userInput, string $len = 'medium', string $path = 'chat_v3'): ?array
{
    crm_ai_status('ok');
    $s = crm_ai_settings();

    // Cloud endpoint as configured in Settings (used when box is unchecked, or box online but empty)
    $cloud = function () use ($s, $userInput, $len, $path) {
        if ($s && $s['cloud_enabled'] && $s['cloud_url']) {
            $ckey = $s['cloud_api_key'] ?: null;
            $r = _ai_post($s['cloud_url'], $userInput, $len, $ckey, $path);
            if ($r !== null) return ['response' => $r, 'source' => 'cloud'];
        }
        return null;
    };

    // Box CHECKED
    if ($s && $s['box_enabled'] && $s['box_url']) {
        if (crm_ai_box_health($s['box_url'])['up']) {
            // online → box first, then the configured cloud endpoint
            $r = _ai_post(rtrim($s['box_url'], '/') . '/' . $path, $userInput, $len, $s['ai_api_key'] ?: null, $path);
            if ($r !== null) return ['response' => $r, 'source' => 'box'];
            $c = $cloud();
            if ($c) return $c;
            crm_ai_status('unavailable');
            return null;
        }
        // checked but OFFLINE → tell the user; do NOT silently degrade to cloud
        crm_ai_status('box_offline');
        return null;
    }

    // Box UNCHECKED → configured cloud endpoint
    $c = $cloud();
    if ($c) return $c;
    crm_ai_status('unavailable');
    return null;
}
