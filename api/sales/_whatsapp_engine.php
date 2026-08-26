<?php
/**
 * Shared WhatsApp (Meta Cloud API) helpers.
 * Used by: api/integrations/whatsapp-webhook.php (inbound), api/sales/whatsapp-chat.php (agent console),
 *          api/sales/whatsapp-send.php (one-shot lead-screen send), api/sales/whatsapp-settings.php (test send).
 * Non-fatal by convention where noted — callers decide how to surface failures.
 */

/** Current whatsapp_settings row, or null if the table/row doesn't exist yet. */
function wa_settings(PDO $db): ?array
{
    try {
        $s = $db->query("SELECT * FROM whatsapp_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        return $s ?: null;
    } catch (Throwable $e) { return null; }
}

/** Digits-only phone, with the settings' default country code prepended for bare 10-digit local numbers. */
function wa_normalize_phone(string $raw, string $cc = '91'): string
{
    $d = preg_replace('/\D+/', '', $raw);
    if ($d !== '' && strlen($d) === 10) $d = (preg_replace('/\D+/', '', $cc) ?: '91') . $d;
    return $d;
}

/** HMAC-SHA256 verification for Meta's X-Hub-Signature-256 header. */
function wa_verify_signature(string $appSecret, string $rawBody, ?string $headerSig): bool
{
    if ($appSecret === '' || !$headerSig) return false;
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
    return hash_equals($expected, $headerSig);
}

/** Find (by phone) or create a whatsapp_threads row; keeps contact_name/lead_id fresh. */
function wa_find_or_create_thread(PDO $db, string $phone, ?int $leadId = null, ?string $name = null): array
{
    $s = $db->prepare("SELECT * FROM whatsapp_threads WHERE phone=?");
    $s->execute([$phone]);
    $t = $s->fetch(PDO::FETCH_ASSOC);
    if ($t) {
        if (($leadId && !$t['lead_id']) || ($name && !$t['contact_name'])) {
            $db->prepare("UPDATE whatsapp_threads SET lead_id=COALESCE(lead_id,?), contact_name=COALESCE(contact_name,?) WHERE id=?")
               ->execute([$leadId, $name, $t['id']]);
            $t['lead_id'] = $t['lead_id'] ?: $leadId;
            $t['contact_name'] = $t['contact_name'] ?: $name;
        }
        return $t;
    }
    $db->prepare("INSERT INTO whatsapp_threads (phone,lead_id,contact_name,status) VALUES (?,?,?, 'open')")
       ->execute([$phone, $leadId, $name]);
    $id = (int)$db->lastInsertId();
    $s->execute([$phone]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

/**
 * Find an existing lead by phone/mobile, else create one (source=whatsapp) and fire on_create automations.
 * Mirrors chat.php's `start` action / chat-lead.php's dedupe-by-contact pattern.
 */
function wa_find_or_create_lead(PDO $db, string $phone, ?string $name = null): int
{
    $s = $db->prepare("SELECT id FROM leads WHERE phone=? OR mobile=? ORDER BY id DESC LIMIT 1");
    $s->execute([$phone, $phone]);
    $existing = $s->fetchColumn();
    if ($existing) return (int)$existing;

    $parts = preg_split('/\s+/', trim((string)$name), 2);
    $first = $parts[0] !== '' ? $parts[0] : 'WhatsApp';
    $last  = $parts[1] ?? 'Contact';
    try {
        $ins = $db->prepare("INSERT INTO leads (first_name,last_name,phone,mobile,lead_source,lead_status,description)
                             VALUES (?,?,?,?, 'whatsapp','new','Started a WhatsApp conversation')");
        $ins->execute([$first, $last, $phone, $phone]);
        $leadId = (int)$db->lastInsertId();
        // Assign off the shared rotation before automations — the on_create rule only notifies
        // now, so assignment has to happen here or a WhatsApp lead lands owned by nobody.
        if (function_exists('sales_assign_next')) sales_assign_next($db, $leadId, null, 'sales');
        if (function_exists('run_automations')) {
            $row = $db->prepare("SELECT * FROM leads WHERE id=?"); $row->execute([$leadId]);
            run_automations($db, 'lead', 'on_create', $row->fetch(PDO::FETCH_ASSOC) ?: []);
        }
        return $leadId;
    } catch (Throwable $e) { return 0; }
}

/** True if a customer message arrived within the last 24h (Meta's free-form messaging window). */
function wa_window_open(array $thread): bool
{
    if (empty($thread['last_inbound_at'])) return false;
    return (strtotime($thread['last_inbound_at']) > time() - 24 * 3600);
}

/** POST a Graph API message payload. Returns [ok, providerMsgId, error]. */
function wa_graph_send(array $settings, array $payload): array
{
    $ver = $settings['graph_version'] ?: 'v25.0';
    $url = "https://graph.facebook.com/{$ver}/{$settings['phone_number_id']}/messages";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $settings['api_token']],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
    $j = json_decode((string)$resp, true);
    if (!$errno && isset($j['messages'][0]['id'])) return [true, $j['messages'][0]['id'], null];
    return [false, null, $j['error']['message'] ?? ($errno ? curl_strerror($errno) : 'provider error')];
}

/** Free-form text send — only valid while wa_window_open() is true. */
function wa_send_text(array $settings, string $to, string $body): array
{
    return wa_graph_send($settings, ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $body]]);
}

/**
 * Media send — image/document/video/audio via a publicly-fetchable HTTPS link (Meta downloads it
 * from `$link`; we don't upload bytes to Meta directly). Only valid while wa_window_open() is true,
 * same as wa_send_text().
 */
function wa_send_media(array $settings, string $to, string $type, string $link, ?string $caption = null): array
{
    $media = ['link' => $link];
    if ($caption !== null && $caption !== '') $media['caption'] = $caption;
    return wa_graph_send($settings, ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => $type, $type => $media]);
}

/** Map an uploaded file's MIME type to the WhatsApp media type bucket Graph API expects. */
function wa_media_type_for_mime(string $mime): string
{
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    if (str_starts_with($mime, 'audio/')) return 'audio';
    return 'document';
}

/** Template send — the only way to message outside the 24h window (or to a brand-new contact). */
function wa_send_template(array $settings, string $to, ?string $name = null, ?string $lang = null): array
{
    return wa_graph_send($settings, [
        'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
        'template' => ['name' => $name ?: ($settings['template_name'] ?: 'hello_world'),
                        'language' => ['code' => $lang ?: ($settings['template_lang'] ?: 'en_US')]],
    ]);
}

/**
 * Registers a callback URL with Meta's app-level webhooks endpoint (POST /{app_id}/subscriptions),
 * so a tunnel script can re-point the WhatsApp webhook without a manual dashboard visit each time
 * the public URL changes. Requires app_id + app_secret to be saved in whatsapp_settings.
 * Returns [ok, error].
 */
function wa_register_webhook(array $settings, string $webhookUrl): array
{
    if (empty($settings['app_id']) || empty($settings['app_secret']) || empty($settings['webhook_verify_token'])) {
        return [false, 'app_id, app_secret and webhook_verify_token must all be saved in Settings first'];
    }
    $ver = $settings['graph_version'] ?: 'v25.0';
    $ch = curl_init("https://graph.facebook.com/{$ver}/{$settings['app_id']}/subscriptions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'object' => 'whatsapp_business_account',
            'callback_url' => $webhookUrl,
            'verify_token' => $settings['webhook_verify_token'],
            'fields' => 'messages',
            'access_token' => $settings['app_id'] . '|' . $settings['app_secret'],
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
    $j = json_decode((string)$resp, true);
    if (!$errno && !empty($j['success'])) return [true, null];
    return [false, $j['error']['message'] ?? ($errno ? curl_strerror($errno) : ('unexpected response: ' . $resp))];
}

/**
 * Subscribes our app to a WhatsApp Business Account's message events (POST /{waba_id}/subscribed_apps).
 * This is a SEPARATE registration from wa_register_webhook() — the app-level webhook config says
 * "what URL/fields exist", but a WABA still needs to explicitly list our app as a listener, or it
 * silently keeps sending events only to whatever app it was already subscribed to (often Meta's own
 * default test-dashboard app) and nothing reaches our webhook at all. Uses the WhatsApp access token
 * (not the app secret) since this is a WABA-scoped call. Returns [ok, error].
 */
function wa_subscribe_waba(array $settings): array
{
    if (empty($settings['business_account_id']) || empty($settings['api_token'])) {
        return [false, 'business_account_id and api_token must both be saved in Settings first'];
    }
    $ver = $settings['graph_version'] ?: 'v25.0';
    $ch = curl_init("https://graph.facebook.com/{$ver}/{$settings['business_account_id']}/subscribed_apps");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $settings['api_token']],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
    $j = json_decode((string)$resp, true);
    if (!$errno && !empty($j['success'])) return [true, null];
    return [false, $j['error']['message'] ?? ($errno ? curl_strerror($errno) : ('unexpected response: ' . $resp))];
}
