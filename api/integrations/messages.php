<?php
/**
 * Centralized templated messaging for PayG products — SMS + Email.
 * Auth: per-app API key (X-API-Key, IT → App API Keys) with scope 'sms'/'email'.
 *
 *   POST messages.php?action=send_sms
 *        { "to": "98XXXXXXXX", "purpose": "merchant_activation" | "template_id": 12,
 *          "vars": ["MID123", "https://payg.in/..."] }
 *     -> renders the app's (or global) DLT template and sends via the configured
 *        SMS gateway (HSP SMS / Textlocal / custom). {#var#} filled in order.
 *
 *   POST messages.php?action=send_email
 *        { "to": "a@b.com", "to_name": "...", "purpose": "customer_creation" | "template_id": 40,
 *          "subject": "optional override", "vars": {"name": "...", "link": "..."} }
 *     -> renders the template ({#name#} named + {#var#} positional) and sends via
 *        the app's email server (email_servers) or the default SMTP account.
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../sales/_otp_engine.php';
require_once __DIR__ . '/_apps.php';

$db = idb();
$b = ibody();
$action = $_GET['action'] ?? ($b['action'] ?? '');

function msg_template(PDO $db, array $app, array $b, string $channel): array
{
    if (!empty($b['template_id'])) {
        $s = $db->prepare("SELECT * FROM message_templates
                            WHERE id=? AND channel=? AND is_active=1 AND (app_id IS NULL OR app_id=?)");
        $s->execute([(int)$b['template_id'], $channel, (int)$app['id']]);
        $tpl = $s->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) ifail('Template not found (or not visible to this app)', 404);
        return $tpl;
    }
    $purpose = substr(trim((string)($b['purpose'] ?? '')), 0, 80);
    if ($purpose === '') ifail('purpose or template_id required');
    $tpl = otp_resolve_template($db, (int)$app['id'], $purpose, $channel);
    if (!$tpl) ifail("No active $channel template for purpose '$purpose'", 404);
    return $tpl;
}

function msg_vars(array $b): array
{
    $vars = $b['vars'] ?? [];
    $named = []; $seq = [];
    foreach ((array)$vars as $k => $v) {
        if (is_int($k)) $seq[] = (string)$v; else $named[$k] = (string)$v;
    }
    return [$named, $seq];
}

if ($action === 'send_sms') {
    $app = app_auth($db, 'sms');
    $to = preg_replace('/\D+/', '', (string)($b['to'] ?? ''));
    if (strlen($to) < 10) ifail('Valid 10-digit mobile required');
    $tpl = msg_template($db, $app, $b, 'sms');
    [$named, $seq] = msg_vars($b);
    $message = otp_render_template($tpl['content'], $named + ['app' => $app['app_name']], $seq);
    $cfg = otp_settings($db);
    [$ok, $detail] = sms_send_raw($cfg, $to, $message, $tpl['sender_header'] ?: $app['sms_sender_id']);
    if (!$ok) ifail('SMS gateway error: ' . $detail, 502);
    iok(['sent' => true, 'template' => $tpl['name'], 'dlt_template_id' => $tpl['dlt_template_id'], 'detail' => $detail]);
}

if ($action === 'send_email') {
    $app = app_auth($db, 'email');
    $to = trim((string)($b['to'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) ifail('Valid to address required');
    $tpl = msg_template($db, $app, $b, 'email');
    [$named, $seq] = msg_vars($b);
    $html = otp_render_template($tpl['content'], $named + ['app' => $app['app_name']], $seq);
    $subject = trim((string)($b['subject'] ?? '')) ?: ($tpl['subject'] ?: $tpl['name']);
    $server = null;
    if (!empty($app['email_server_id'])) {
        $s = $db->prepare("SELECT * FROM email_servers WHERE id=? AND is_active=1 AND smtp_host IS NOT NULL AND smtp_host<>''");
        $s->execute([(int)$app['email_server_id']]);
        $server = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($server) [$ok, $detail] = crm_smtp_send($server, $to, (string)($b['to_name'] ?? ''), $subject, $html, strip_tags($html));
    else { $ok = sendCrmEmail($to, (string)($b['to_name'] ?? ''), $subject, $html); $detail = $ok ? 'sent (default account)' : 'default SMTP failed/not configured'; }
    if (!$ok) ifail('Email send failed: ' . $detail, 502);
    iok(['sent' => true, 'template' => $tpl['name'], 'via' => $server['name'] ?? 'default', 'detail' => $detail]);
}

ifail('Unknown action — use ?action=send_sms or ?action=send_email');
