<?php
/**
 * IT console for the Centralized OTP APIs.
 *   GET  otp-admin.php?settings=1   -> provider config (api key masked) + channel readiness + api token
 *   POST otp-admin.php?settings=1   -> save provider config
 *   POST otp-admin.php?test_send=1  -> {channel, to} test-fire an OTP from the console
 *   POST otp-admin.php?test_verify=1-> {channel, to, code}
 *   GET  otp-admin.php?log=1        -> recent OTP activity (no codes — they are hashed)
 *
 * Access: IT department + admin.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_otp_engine.php';
require_login();
$db = db();

if (!in_array(current_role(), ['it', 'it_manager', 'admin'], true)) {
    fail('Centralized APIs are managed by IT and the admin', 403);
}

if (method() === 'GET' && isset($_GET['settings'])) {
    $cfg = otp_settings($db);
    $cfg['sms_api_key_set'] = !empty($cfg['sms_api_key']);
    unset($cfg['sms_api_key']);                 // never returned to the browser

    // channel readiness so the console can show live status dots
    $emailCfg = null;
    try { $emailCfg = crm_email_config(); } catch (Throwable $e) {}
    $wa = wa_settings($db);
    $tok = $db->query("SELECT label, token, is_active, last_used_at FROM integration_tokens
                        WHERE scopes LIKE '%otp%' ORDER BY id LIMIT 5")->fetchAll();
    ok(['settings' => $cfg,
        'channels' => [
            'email'    => ['ready' => (bool)($emailCfg && !empty($emailCfg['is_active'])), 'detail' => $emailCfg ? ($emailCfg['smtp_host'] ?? 'SMTP') : 'Configure in Settings → Email'],
            'whatsapp' => ['ready' => (bool)($wa && !empty($wa['enabled']) && !empty($wa['api_token'])), 'detail' => $wa && !empty($wa['phone_number_id']) ? ('phone id ' . $wa['phone_number_id']) : 'Configure in Settings → WhatsApp'],
            'sms'      => ['ready' => !empty($cfg['sms_api_key_set']) || ($cfg['sms_provider'] === 'custom' && !empty($cfg['sms_custom_url'])), 'detail' => strtoupper($cfg['sms_provider'])],
        ],
        'tokens' => $tok]);
}

if (method() === 'POST' && isset($_GET['settings'])) {
    $b = body();
    $set = []; $args = [];
    if (isset($b['sms_provider']) && in_array($b['sms_provider'], ['msg91','fast2sms','textlocal','custom'], true)) { $set[] = "sms_provider=?"; $args[] = $b['sms_provider']; }
    foreach (['sms_sender_id' => 20, 'sms_template_id' => 100, 'sms_custom_url' => 500] as $f => $len) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = ($b[$f] === '' ? null : substr((string)$b[$f], 0, $len)); }
    }
    // only overwrite the key when a new one is typed (blank = keep existing)
    if (!empty($b['sms_api_key'])) { $set[] = "sms_api_key=?"; $args[] = substr((string)$b['sms_api_key'], 0, 300); }
    foreach (['otp_length' => [4,8], 'otp_expiry_minutes' => [1,60], 'max_attempts' => [1,10], 'rate_limit_per_10min' => [1,20]] as $f => [$lo,$hi]) {
        if (isset($b[$f])) { $set[] = "$f=?"; $args[] = max($lo, min($hi, (int)$b[$f])); }
    }
    if (!$set) fail('Nothing to save');
    $db->prepare("UPDATE otp_settings SET " . implode(',', $set) . " WHERE id=1")->execute($args);
    ok();
}

if (method() === 'POST' && isset($_GET['test_send'])) {
    $b = body();
    [$ok, $msg, $id] = otp_send($db, strtolower((string)($b['channel'] ?? '')), (string)($b['to'] ?? ''),
                                'console_test', 'console:' . current_role());
    if (!$ok) fail($msg, 422);
    ok(['message' => $msg, 'otp_id' => $id]);
}

if (method() === 'POST' && isset($_GET['test_verify'])) {
    $b = body();
    [$ok, $msg] = otp_verify($db, strtolower((string)($b['channel'] ?? '')), (string)($b['to'] ?? ''),
                             (string)($b['code'] ?? ''), 'console_test');
    ok(['verified' => $ok, 'message' => $msg]);
}

if (method() === 'GET' && isset($_GET['log'])) {
    ok($db->query("SELECT id, channel, recipient, purpose, status, attempts, requested_via, provider_result, created_at, verified_at
                     FROM otp_codes ORDER BY id DESC LIMIT 100")->fetchAll());
}

fail('Unsupported request', 405);
