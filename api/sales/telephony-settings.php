<?php
/**
 * Click-to-call (telephony) settings — admin/IT.
 *   GET  telephony-settings.php   -> config (api_token never returned)
 *   POST telephony-settings.php   -> save
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
if (($_SESSION['user']['user_type'] ?? '') !== 'admin' && current_role() !== 'it') fail('Admin or IT only', 403);
$db = db();

if (method() === 'POST' && isset($_GET['test'])) {
    $c = $db->query("SELECT * FROM telephony_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$c || !$c['account_sid'] || !$c['api_token']) fail('Save your credentials first', 400);
    if (($c['provider'] ?: 'exotel') === 'exotel') {
        if (!$c['api_key']) fail('Exotel needs an API key', 400);
        // GET the account — a free auth check, no call placed
        $url = 'https://' . rawurlencode($c['api_key']) . ':' . rawurlencode($c['api_token'])
             . '@' . ($c['subdomain'] ?: 'api.exotel.com') . '/v1/Accounts/' . $c['account_sid'] . '.json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false]);
        $resp = curl_exec($ch); $errno = curl_errno($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($errno) fail('Exotel unreachable', 502);
        if ($code === 401 || $code === 403) fail('Auth failed — check API key / token / SID', 502);
        if ($code >= 200 && $code < 300) ok([], ['message' => 'Exotel credentials valid ✓ (no call placed)']);
        fail('Exotel returned HTTP ' . $code, 502);
    }
    // TeleCMI has no free auth-check endpoint; validate config completeness
    if (!$c['caller_id']) fail('TeleCMI needs a Caller ID', 400);
    ok([], ['message' => 'Config looks complete ✓ — TeleCMI verifies on the first live call']);
}

if (method() === 'POST') {
    $b = body();
    $provider = in_array($b['provider'] ?? '', ['exotel','telecmi','servetel'], true) ? $b['provider'] : 'exotel';
    $cur = $db->query("SELECT api_token FROM telephony_settings WHERE id=1")->fetchColumn();
    $tok = (isset($b['api_token']) && $b['api_token'] !== '' && $b['api_token'] !== '********') ? $b['api_token'] : $cur;
    $db->prepare("UPDATE telephony_settings SET enabled=?, provider=?, account_sid=?, api_key=?, api_token=?,
                  caller_id=?, sales_number=?, toll_free_number=?, webrtc_app_id=?, webrtc_domain=?, flow_id=?,
                  subdomain=?, default_cc=? WHERE id=1")
       ->execute([
           !empty($b['enabled']) ? 1 : 0, $provider, trim($b['account_sid'] ?? ''), trim($b['api_key'] ?? ''),
           $tok, trim($b['caller_id'] ?? ''), trim($b['sales_number'] ?? ''), trim($b['toll_free_number'] ?? ''),
           trim($b['webrtc_app_id'] ?? ''), trim($b['webrtc_domain'] ?? ''), trim($b['flow_id'] ?? ''),
           trim($b['subdomain'] ?? 'api.exotel.com'),
           preg_replace('/\D+/', '', $b['default_cc'] ?? '91') ?: '91',
       ]);
    ok([], ['message' => 'Telephony settings saved']);
}

$cfg = $db->query("SELECT id,enabled,provider,account_sid,api_key,caller_id,sales_number,toll_free_number,
                          webrtc_app_id,webrtc_domain,flow_id,subdomain,default_cc,
                          (api_token IS NOT NULL AND api_token<>'') AS has_token
                   FROM telephony_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
ok($cfg ?: []);
