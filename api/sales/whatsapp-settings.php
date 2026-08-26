<?php
/**
 * WhatsApp integration settings (admin / IT).
 *   GET  whatsapp-settings.php        -> config (api_token never returned)
 *   POST whatsapp-settings.php        -> save
 *   POST whatsapp-settings.php?test=1 { to } -> send a test message (API) or return a wa.me link
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_whatsapp_engine.php';
require_login();
if (($_SESSION['user']['user_type'] ?? '') !== 'admin' && current_role() !== 'it') fail('Admin or IT only', 403);
$db = db();

if (method() === 'POST' && isset($_GET['test'])) {
    $c = wa_settings($db);
    $to = wa_normalize_phone(body()['to'] ?? '', $c['default_cc'] ?? '91');
    if ($to === '') fail('Recipient phone required');
    $useApi = $c && $c['enabled'] && $c['provider'] === 'meta_cloud' && $c['api_token'] && $c['phone_number_id'];
    $textBody = 'PayG CRM — WhatsApp test message ✅';
    if (!$useApi) ok(['wa_link' => 'https://wa.me/' . $to . '?text=' . rawurlencode($textBody)], ['message' => 'API not enabled — opens click-to-chat instead']);
    [$sent, , $err] = wa_send_text($c, $to, $textBody);
    if ($sent) ok([], ['message' => 'Test WhatsApp sent to +' . $to]);
    fail('Send failed: ' . $err, 502);
}

if (method() === 'POST') {
    $b = body();
    $provider = in_array($b['provider'] ?? '', ['meta_cloud','link_only'], true) ? $b['provider'] : 'meta_cloud';
    $cur = $db->query("SELECT api_token, app_secret, webhook_verify_token FROM whatsapp_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $tok = (isset($b['api_token']) && $b['api_token'] !== '' && $b['api_token'] !== '********') ? $b['api_token'] : $cur['api_token'];
    $secret = (isset($b['app_secret']) && $b['app_secret'] !== '' && $b['app_secret'] !== '********') ? $b['app_secret'] : $cur['app_secret'];
    $verifyToken = $cur['webhook_verify_token'] ?: bin2hex(random_bytes(16));
    $db->prepare("UPDATE whatsapp_settings SET enabled=?, provider=?, api_token=?, phone_number_id=?,
                  business_account_id=?, from_display=?, default_cc=?, app_id=?, app_secret=?, webhook_verify_token=?,
                  template_name=?, template_lang=? WHERE id=1")
       ->execute([
           !empty($b['enabled']) ? 1 : 0, $provider, $tok, trim($b['phone_number_id'] ?? ''),
           trim($b['business_account_id'] ?? ''), trim($b['from_display'] ?? ''),
           preg_replace('/\D+/', '', $b['default_cc'] ?? '91') ?: '91',
           trim($b['app_id'] ?? '') ?: null,
           $secret, $verifyToken,
           trim($b['template_name'] ?? '') ?: 'hello_world', trim($b['template_lang'] ?? '') ?: 'en_US',
       ]);

    // best-effort: make sure the WABA actually forwards message events to this app — without this,
    // a webhook can be perfectly configured and still receive nothing (the WABA silently keeps
    // pushing to whatever app it was previously subscribed to, often Meta's own test-dashboard app).
    $saved = wa_settings($db);
    $subMsg = '';
    if ($saved && $saved['enabled'] && $saved['business_account_id'] && $saved['api_token']) {
        [$subOk, $subErr] = wa_subscribe_waba($saved);
        $subMsg = $subOk ? ' — WABA subscribed for inbound events' : (' — WABA subscription failed: ' . $subErr);
    }
    ok([], ['message' => 'WhatsApp settings saved' . $subMsg]);
}

$cfg = $db->query("SELECT id,enabled,provider,phone_number_id,business_account_id,from_display,default_cc,
                          template_name,template_lang,webhook_verify_token,app_id,
                          (api_token IS NOT NULL AND api_token<>'') AS has_token,
                          (app_secret IS NOT NULL AND app_secret<>'') AS has_app_secret
                   FROM whatsapp_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if ($cfg) {
    // TLS usually terminates at the front-end (Azure App Service, Nginx, Cloudflare, any LB), so PHP
    // sees plain HTTP and $_SERVER['HTTPS'] is unset — the proxy reports the real scheme in a header.
    // Getting this wrong hands the admin an http:// URL, which Meta refuses as a webhook callback.
    $fwdProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
          || $fwdProto === 'https'
          || strtolower($_SERVER['HTTP_X_ARR_SSL'] ?? '') !== ''            // Azure App Service (Windows)
          || strtolower($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '') === 'on'
          || (int)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? 0) === 443
          || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $scheme = $https ? 'https' : 'http';
    $root = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');   // .../api/sales/this.php -> project root
    $cfg['webhook_url'] = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $root . '/api/integrations/whatsapp-webhook.php';
}
ok($cfg ?: []);
