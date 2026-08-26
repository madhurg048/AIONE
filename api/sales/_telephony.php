<?php
/**
 * Provider-agnostic click-to-call. Routes to the provider chosen in Settings.
 *   crm_place_call($cfg, $fromAgent, $toCustomer, $statusCb) : [ok(bool), call_id, error]
 * Supported: Exotel (connect two numbers), TeleCMI (click-to-call).
 */
function crm_norm_number(string $n, string $cc = '91'): string
{
    $n = preg_replace('/\D+/', '', $n);
    if (strlen($n) === 10) $n = $cc . $n;
    return $n;
}

/** Returns the config if click-to-call is enabled + configured, else null. */
function crm_telephony_ready(PDO $db): ?array
{
    try {
        $c = $db->query("SELECT * FROM telephony_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    if ($c && $c['enabled'] && $c['account_sid'] && $c['api_token'] && $c['caller_id']) return $c;
    return null;
}

function crm_place_call(array $cfg, string $from, string $to, string $statusCb = ''): array
{
    $provider = $cfg['provider'] ?: 'exotel';

    if ($provider === 'exotel') {
        $url = 'https://' . rawurlencode($cfg['api_key']) . ':' . rawurlencode($cfg['api_token'])
             . '@' . ($cfg['subdomain'] ?: 'api.exotel.com') . '/v1/Accounts/' . $cfg['account_sid'] . '/Calls/connect.json';
        $post = ['From' => $from, 'To' => $to, 'CallerId' => $cfg['caller_id'], 'Record' => 'true'];
        if ($statusCb) $post['StatusCallback'] = $statusCb;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query($post), CURLOPT_TIMEOUT=>20]);
        $resp = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
        $j = json_decode($resp, true);
        $id = $j['Call']['Sid'] ?? null;
        return [(!$errno && $id), $id, $errno ? 'network error' : ($j['RestException']['Message'] ?? ($id ? '' : 'provider error'))];
    }

    if ($provider === 'telecmi') {
        // TeleCMI (PIOPIY) click-to-call — bridges agent (from) and customer (to)
        $url = 'https://rest.telecmi.com/v2/ind_click_to_call';
        $payload = [
            'appid'  => (int)$cfg['account_sid'],       // TeleCMI App ID
            'secret' => $cfg['api_token'],              // TeleCMI secret
            'from'   => (int)preg_replace('/\D+/', '', $from),
            'to'     => (int)preg_replace('/\D+/', '', $to),
            'caller_id' => (int)preg_replace('/\D+/', '', $cfg['caller_id'] ?: '0'),
        ];
        if ($statusCb) $payload['event_url'] = $statusCb;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>20]);
        $resp = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
        $j = json_decode($resp, true);
        $id = $j['cmiuuid'] ?? $j['request_id'] ?? $j['id'] ?? null;
        $good = !$errno && (($j['code'] ?? 0) == 200 || $id);
        return [$good, $id, $errno ? 'network error' : ($j['msg'] ?? ($good ? '' : 'provider error'))];
    }

    return [false, null, 'Provider "' . $provider . '" not supported'];
}

/** Returns true if the in-browser WebRTC calling app is configured (not just click-to-call). */
function crm_webrtc_ready(array $cfg): bool
{
    return !empty($cfg['webrtc_app_id']) && !empty($cfg['webrtc_domain'])
        && !empty($cfg['account_sid']) && !empty($cfg['api_key']) && !empty($cfg['api_token']);
}

/**
 * Requests a short-lived browser-calling device token for one agent from Exotel's Voice/App
 * Bridge SDK token endpoint, so the agent's browser tab (not their phone) becomes the softphone.
 *
 * NOTE: Exotel's exact token-endpoint path/response shape is account/plan-specific and evolves —
 * verify this against the current Exotel Developer docs for the account's WebRTC/App Bridge
 * product once real credentials are in place, and adjust the URL/params below if needed. Fails
 * soft (returns null, never throws) so the workspace can fall back to phone-bridge dialing.
 */
function crm_webrtc_token(array $cfg, string $agentIdentity): ?string
{
    if (!crm_webrtc_ready($cfg)) return null;
    try {
        $url = 'https://' . rawurlencode($cfg['api_key']) . ':' . rawurlencode($cfg['api_token'])
             . '@' . ($cfg['webrtc_domain'] ?: ($cfg['subdomain'] ?: 'api.exotel.com'))
             . '/v1/Accounts/' . $cfg['account_sid'] . '/Apps/' . rawurlencode($cfg['webrtc_app_id']) . '/token.json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['identity' => $agentIdentity]), CURLOPT_TIMEOUT => 15]);
        $resp = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
        if ($errno) return null;
        $j = json_decode((string)$resp, true);
        return $j['token'] ?? $j['Token'] ?? $j['access_token'] ?? null;
    } catch (Throwable $e) { error_log('crm_webrtc_token: ' . $e->getMessage()); return null; }
}

/**
 * Fetches a completed Exotel call's recording URL via GET /Calls/{Sid}.json, and returns it
 * (or null if the call has no recording yet / lookup failed). Only Exotel exposes this endpoint;
 * other providers return null. Called from call-status.php once the provider reports the call ended.
 */
function crm_fetch_recording_url(array $cfg, string $callSid): ?string
{
    if (($cfg['provider'] ?: 'exotel') !== 'exotel' || $callSid === '') return null;
    $url = 'https://' . rawurlencode($cfg['api_key']) . ':' . rawurlencode($cfg['api_token'])
         . '@' . ($cfg['subdomain'] ?: 'api.exotel.com') . '/v1/Accounts/' . $cfg['account_sid'] . '/Calls/' . rawurlencode($callSid) . '.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $resp = curl_exec($ch); $errno = curl_errno($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($errno || $code < 200 || $code >= 300) return null;
    $j = json_decode((string)$resp, true);
    return $j['Call']['RecordingUrl'] ?? null;
}
