<?php
/**
 * Centralized OTP engine — one code path for email / SMS / WhatsApp OTPs.
 * Consumed by:
 *   api/integrations/otp.php  (machine-to-machine, X-API-Key, scope 'otp')
 *   api/sales/otp-admin.php   (IT console: settings, test send/verify, log)
 *
 * Delivery:
 *   email    -> sendCrmEmail() (includes/email-helper.php, SMTP from Settings)
 *   whatsapp -> wa_send_text() (Meta Cloud API config from Settings)
 *   sms      -> Indian aggregators via otp_settings.sms_provider:
 *               MSG91 | Fast2SMS | Textlocal | custom HTTP GET template
 *
 * Codes are stored HASHED (password_hash); verification is attempt-limited and
 * rate-limited per recipient. Nothing here echoes the code back to the caller.
 */
require_once __DIR__ . '/../../includes/email-helper.php';
require_once __DIR__ . '/_whatsapp_engine.php';

function otp_settings(PDO $db): array
{
    $s = $db->query("SELECT * FROM otp_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    return $s ?: ['otp_length' => 6, 'otp_expiry_minutes' => 10, 'max_attempts' => 5,
                  'rate_limit_per_10min' => 3, 'sms_provider' => 'msg91',
                  'sms_api_key' => null, 'sms_sender_id' => null, 'sms_template_id' => null, 'sms_custom_url' => null];
}

/** Simple HTTP helper. Returns [httpCode, bodyString|null, curlError|null]. */
function otp_http(string $method, string $url, array $headers = [], $body = null): array
{
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
             CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => false];
    if (strtoupper($method) === 'POST') { $opts[CURLOPT_POST] = true; if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body; }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $errno ? null : (string)$resp, $errno ? curl_strerror($errno) : null];
}

/**
 * Resolve the best message template: app-specific purpose > app generic >
 * global purpose > global 'generic'. Returns the template row or null.
 */
function otp_resolve_template(PDO $db, ?int $appId, string $purpose, string $channel): ?array
{
    $tries = [];
    if ($appId) { $tries[] = [$appId, $purpose]; $tries[] = [$appId, 'generic']; }
    $tries[] = [null, $purpose];
    $tries[] = [null, 'generic'];
    foreach ($tries as [$aid, $p]) {
        $s = $db->prepare("SELECT * FROM message_templates
                            WHERE channel=? AND purpose=? AND is_active=1 AND " . ($aid ? "app_id=?" : "app_id IS NULL") . "
                            ORDER BY id DESC LIMIT 1");
        $s->execute($aid ? [$channel, $p, $aid] : [$channel, $p]);
        if ($row = $s->fetch(PDO::FETCH_ASSOC)) return $row;
    }
    return null;
}

/**
 * Render a DLT template: named placeholders first ({#otp#}, {#minutes#}, {#name#}…),
 * then positional {#var#} filled left-to-right from $seq.
 */
function otp_render_template(string $content, array $named, array $seq = []): string
{
    foreach ($named as $k => $v) $content = str_ireplace('{#' . $k . '#}', (string)$v, $content);
    foreach ($seq as $v) {
        $pos = stripos($content, '{#var#}');
        if ($pos === false) break;
        $content = substr_replace($content, (string)$v, $pos, strlen('{#var#}'));
    }
    return str_ireplace('{#var#}', '', $content);   // any leftover vars blank out
}

/** Raw SMS via the configured gateway (any message, not just OTPs). Returns [ok, detail]. */
function sms_send_raw(array $cfg, string $phone, string $message, ?string $senderId = null): array
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10) $digits = '91' . $digits;
    $sender = $senderId ?: ($cfg['sms_sender_id'] ?: 'PAYGin');
    switch ($cfg['sms_provider'] ?? 'hspsms') {
        case 'hspsms': {
            // the client's gateway: GET sms.hspsms.com/sendSMS (see Email and Template Code.txt)
            $url = 'http://sms.hspsms.com/sendSMS?' . http_build_query([
                'username' => $cfg['sms_username'] ?: '', 'message' => $message,
                'sendername' => $sender, 'smstype' => 'TRANS',
                'numbers' => $digits, 'apikey' => $cfg['sms_api_key'] ?: '']);
            [$http, $body, $err] = otp_http('GET', $url);
            $ok = $http >= 200 && $http < 300 && stripos((string)$body, 'invalidnumber') === false;
            return [$ok, 'hspsms HTTP ' . $http . ' ' . substr((string)($body ?? $err), 0, 160)];
        }
        case 'textlocal': {
            [$http, $body] = otp_http('POST', 'https://api.textlocal.in/send/', [],
                http_build_query(['apikey' => $cfg['sms_api_key'] ?: '', 'numbers' => $digits,
                    'sender' => $sender, 'message' => $message]));
            $j = json_decode((string)$body, true) ?: [];
            return [$http === 200 && (($j['status'] ?? '') === 'success'), 'textlocal HTTP ' . $http];
        }
        case 'custom': {
            $tpl = trim((string)($cfg['sms_custom_url'] ?? ''));
            if ($tpl === '') return [false, 'Custom SMS URL not configured'];
            $url = str_replace(['{phone}', '{otp}', '{message}', '{key}'],
                [$digits, rawurlencode($message), rawurlencode($message), rawurlencode($cfg['sms_api_key'] ?: '')], $tpl);
            [$http, $body, $err] = otp_http('GET', $url);
            return [$http >= 200 && $http < 300, 'custom HTTP ' . $http . ' ' . substr((string)($body ?? $err), 0, 160)];
        }
    }
    return [false, 'Provider ' . ($cfg['sms_provider'] ?? '?') . ' supports OTP-only sends'];
}

/** Send $code to $phone via the configured Indian SMS provider. Returns [ok, detail]. */
function otp_sms_dispatch(array $cfg, string $phone, string $code, ?string $message = null, ?string $senderId = null): array
{
    // template-rendered message (or raw-message providers) go through the generic gateway
    if ($message !== null || in_array($cfg['sms_provider'] ?? '', ['hspsms', 'textlocal', 'custom'], true)) {
        $msg = $message ?? "Your PayG verification code is $code. Valid for {$cfg['otp_expiry_minutes']} minutes. Do not share it.";
        return sms_send_raw($cfg, $phone, $msg, $senderId);
    }
    $key = trim((string)($cfg['sms_api_key'] ?? ''));
    $provider = $cfg['sms_provider'] ?? 'msg91';
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10) $digits = '91' . $digits;
    if ($provider !== 'custom' && $key === '') return [false, 'SMS provider API key not configured (IT → Centralized APIs)'];

    switch ($provider) {
        case 'msg91': {
            // https://docs.msg91.com/otp — send a pre-approved DLT template with ##OTP##
            $url = 'https://control.msg91.com/api/v5/otp?' . http_build_query([
                'template_id' => $cfg['sms_template_id'] ?: '', 'mobile' => $digits, 'otp' => $code,
                'otp_expiry' => (int)($cfg['otp_expiry_minutes'] ?? 10),
            ]);
            [$http, $body] = otp_http('POST', $url, ['authkey: ' . $key, 'Content-Type: application/json'], '{}');
            $j = json_decode((string)$body, true) ?: [];
            $ok = $http === 200 && (($j['type'] ?? '') === 'success');
            return [$ok, 'msg91 HTTP ' . $http . ' ' . substr((string)$body, 0, 160)];
        }
        case 'fast2sms': {
            // https://docs.fast2sms.com — dedicated OTP route
            [$http, $body] = otp_http('POST', 'https://www.fast2sms.com/dev/bulkV2',
                ['authorization: ' . $key, 'Content-Type: application/x-www-form-urlencoded'],
                http_build_query(['route' => 'otp', 'variables_values' => $code, 'numbers' => substr($digits, -10)]));
            $j = json_decode((string)$body, true) ?: [];
            $ok = $http === 200 && !empty($j['return']);
            return [$ok, 'fast2sms HTTP ' . $http . ' ' . substr((string)$body, 0, 160)];
        }
        case 'textlocal': {
            // https://api.textlocal.in/docs
            [$http, $body] = otp_http('POST', 'https://api.textlocal.in/send/', [],
                http_build_query(['apikey' => $key, 'numbers' => $digits,
                    'sender' => $cfg['sms_sender_id'] ?: 'TXTLCL',
                    'message' => "Your OTP is $code. It is valid for {$cfg['otp_expiry_minutes']} minutes. Do not share it."]));
            $j = json_decode((string)$body, true) ?: [];
            $ok = $http === 200 && (($j['status'] ?? '') === 'success');
            return [$ok, 'textlocal HTTP ' . $http . ' ' . substr((string)$body, 0, 160)];
        }
        case 'custom': {
            // GET template with {phone} and {otp} placeholders, e.g. Kaleyra/Gupshup/any HTTP gateway
            $tpl = trim((string)($cfg['sms_custom_url'] ?? ''));
            if ($tpl === '') return [false, 'Custom SMS URL not configured'];
            $url = str_replace(['{phone}', '{otp}', '{key}'], [$digits, $code, rawurlencode($key)], $tpl);
            [$http, $body, $err] = otp_http('GET', $url);
            return [$http >= 200 && $http < 300, 'custom HTTP ' . $http . ' ' . substr((string)($body ?? $err), 0, 160)];
        }
    }
    return [false, 'Unknown SMS provider'];
}

/**
 * Generate + dispatch an OTP. Returns [ok, message, otpRowId].
 * Never returns the code itself.
 */
function otp_send(PDO $db, string $channel, string $recipient, string $purpose = 'generic', string $via = 'api', ?array $app = null): array
{
    if (!in_array($channel, ['email', 'sms', 'whatsapp'], true)) return [false, 'channel must be email|sms|whatsapp', 0];
    $recipient = trim($recipient);
    if ($channel === 'email') {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) return [false, 'Invalid email address', 0];
    } else {
        $d = preg_replace('/\D+/', '', $recipient);
        if (strlen($d) < 10) return [false, 'Invalid phone number', 0];
        $recipient = $d;
    }
    $cfg = otp_settings($db);

    // rate limit per recipient+channel
    $rl = $db->prepare("SELECT COUNT(*) FROM otp_codes WHERE channel=? AND recipient=? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $rl->execute([$channel, $recipient]);
    if ((int)$rl->fetchColumn() >= (int)$cfg['rate_limit_per_10min']) {
        return [false, 'Rate limit: too many OTPs for this recipient — try again later', 0];
    }

    $len = max(4, min(8, (int)$cfg['otp_length']));
    $code = str_pad((string)random_int(0, (10 ** $len) - 1), $len, '0', STR_PAD_LEFT);
    $expMin = max(1, (int)$cfg['otp_expiry_minutes']);

    // supersede previous pending codes for the same recipient+channel+purpose
    $db->prepare("UPDATE otp_codes SET status='expired' WHERE channel=? AND recipient=? AND purpose=? AND status='sent'")
       ->execute([$channel, $recipient, $purpose]);

    $db->prepare("INSERT INTO otp_codes (channel, recipient, code_hash, purpose, expires_at, requested_via)
                  VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)")
       ->execute([$channel, $recipient, password_hash($code, PASSWORD_DEFAULT), $purpose, $expMin, substr($via, 0, 40)]);
    $id = (int)$db->lastInsertId();

    // per-app / per-purpose DLT template (falls back to the built-in wording)
    $named = ['otp' => $code, 'minutes' => $expMin, 'code' => $code, 'app' => $app['app_name'] ?? 'PayG'];
    $tplSms = otp_resolve_template($db, $app['id'] ?? null, $purpose, 'sms');
    $msg = $tplSms && $channel !== 'email'
        ? otp_render_template($tplSms['content'], $named, [$code, $expMin])
        : "Your PayG verification code is $code. Valid for $expMin minutes. Do not share it with anyone.";
    $smsSender = ($tplSms['sender_header'] ?? null) ?: ($app['sms_sender_id'] ?? null);

    $ok = false; $detail = '';
    try {
        switch ($channel) {
            case 'email': {
                $tplEm = otp_resolve_template($db, $app['id'] ?? null, $purpose, 'email');
                $subject = ($tplEm['subject'] ?? null) ?: 'Your PayG verification code';
                $html = $tplEm
                    ? otp_render_template($tplEm['content'], $named, [$code, $expMin])
                    : "<p style='font-size:15px'>Your PayG verification code is</p>"
                    . "<p style='font-size:28px;font-weight:bold;letter-spacing:6px'>$code</p>"
                    . "<p>Valid for $expMin minutes. Do not share it with anyone.</p>";
                // the app's own sender account (email_servers) beats the default SMTP config
                $server = null;
                if (!empty($app['email_server_id'])) {
                    $s = $db->prepare("SELECT * FROM email_servers WHERE id=? AND is_active=1 AND smtp_host IS NOT NULL AND smtp_host<>''");
                    $s->execute([(int)$app['email_server_id']]);
                    $server = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if ($server) {
                    [$ok, $m] = crm_smtp_send($server, $recipient, 'User', $subject, $html, strip_tags($html));
                    $detail = $ok ? 'smtp ok (' . $server['name'] . ')' : 'smtp failed: ' . $m;
                } else {
                    $ok = sendCrmEmail($recipient, 'User', $subject, $html, strip_tags($html));
                    $detail = $ok ? 'smtp ok' : 'smtp failed (check Settings → Email)';
                }
                break;
            }
            case 'whatsapp': {
                $wa = wa_settings($db);
                if (!$wa || empty($wa['enabled']) || empty($wa['api_token']) || empty($wa['phone_number_id'])) {
                    $detail = 'WhatsApp API not configured/enabled (Settings → WhatsApp)';
                    break;
                }
                [$ok, , $err] = wa_send_text($wa, wa_normalize_phone($recipient, $wa['default_country_code'] ?? '91'), $msg);
                // outside Meta's 24h window free-form text is rejected -> fall back to the approved template
                if (!$ok) { [$ok, , $err2] = wa_send_template($wa, wa_normalize_phone($recipient)); $err = $ok ? null : ($err . ' | template: ' . $err2); }
                $detail = $ok ? 'whatsapp sent' : 'whatsapp failed: ' . $err;
                break;
            }
            case 'sms':
                [$ok, $detail] = otp_sms_dispatch($cfg, $recipient, $code, $tplSms ? $msg : null, $smsSender);
                break;
        }
    } catch (Throwable $e) { $ok = false; $detail = 'dispatch error: ' . substr($e->getMessage(), 0, 120); }

    $db->prepare("UPDATE otp_codes SET status=?, provider_result=? WHERE id=?")
       ->execute([$ok ? 'sent' : 'failed', substr($detail, 0, 300), $id]);
    return [$ok, $ok ? 'OTP sent via ' . $channel : $detail, $id];
}

/** Verify a code. Returns [ok, message]. */
function otp_verify(PDO $db, string $channel, string $recipient, string $code, string $purpose = 'generic'): array
{
    $recipient = $channel === 'email' ? trim($recipient) : preg_replace('/\D+/', '', trim($recipient));
    $cfg = otp_settings($db);
    $s = $db->prepare("SELECT * FROM otp_codes
                        WHERE channel=? AND recipient=? AND purpose=? AND status='sent'
                        ORDER BY id DESC LIMIT 1");
    $s->execute([$channel, $recipient, $purpose]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [false, 'No pending OTP for this recipient — request a new one'];
    if (strtotime($row['expires_at']) < time()) {
        $db->prepare("UPDATE otp_codes SET status='expired' WHERE id=?")->execute([$row['id']]);
        return [false, 'OTP expired — request a new one'];
    }
    if ((int)$row['attempts'] >= (int)$cfg['max_attempts']) {
        $db->prepare("UPDATE otp_codes SET status='expired' WHERE id=?")->execute([$row['id']]);
        return [false, 'Too many wrong attempts — request a new one'];
    }
    $db->prepare("UPDATE otp_codes SET attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
    if (!password_verify(trim($code), $row['code_hash'])) return [false, 'Incorrect OTP'];
    $db->prepare("UPDATE otp_codes SET status='verified', verified_at=NOW() WHERE id=?")->execute([$row['id']]);
    return [true, 'OTP verified'];
}
