<?php
/**
 * WhatsApp follow-up from the lead screen.
 * If the Business API is configured (config/whatsapp.php, WHATSAPP_ENABLED=true) it sends via the
 * provider; otherwise it returns a wa.me click-to-chat link so the agent can message immediately.
 * Either way the message is logged (whatsapp_messages + a lead_followup channel=whatsapp).
 *
 *   POST whatsapp-send.php { lead_id, message }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_whatsapp_engine.php';
$waCfg = __DIR__ . '/../../config/whatsapp.php';
if (is_file($waCfg)) require_once $waCfg;
require_login();
$db = db();
$me = current_employee_id();

if (method() !== 'POST') fail('POST required', 405);
$b = body();
$lid = (int)($b['lead_id'] ?? 0);
$msg = trim($b['message'] ?? '');
if (!$lid || $msg === '') fail('lead_id and message required');

$s = $db->prepare("SELECT id,first_name,last_name,phone,mobile,owner_id FROM leads WHERE id=?");
$s->execute([$lid]);
$lead = $s->fetch();
if (!$lead) fail('Lead not found', 404);
assert_can_see($lead['owner_id']);   // reps can only message their own leads

// WhatsApp config: prefer Settings (DB) over the config file
$waDb = null;
try { $waDb = $db->query("SELECT * FROM whatsapp_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$cc = ($waDb && !empty($waDb['default_cc'])) ? preg_replace('/\D+/', '', $waDb['default_cc']) : '91';

// normalise phone to international digits (country code prepended for local 10-digit numbers)
$raw = wa_normalize_phone($lead['mobile'] ?: $lead['phone'], $cc);
if ($raw === '') fail('Lead has no phone number');

$useDb   = $waDb && $waDb['enabled'] && $waDb['provider'] === 'meta_cloud' && !empty($waDb['api_token']) && !empty($waDb['phone_number_id']);
$enabled = $useDb || (defined('WHATSAPP_ENABLED') && WHATSAPP_ENABLED && function_exists('whatsappCredentials'));
$status = 'link_generated'; $providerId = null; $waLink = null; $sent = false;

if ($enabled) {
    if ($useDb) {
        $settings = $waDb;
    } else {
        $c = whatsappCredentials();
        $settings = ['graph_version' => 'v25.0', 'phone_number_id' => $c['phone_id'], 'api_token' => $c['api_key']];
    }
    [$sent, $providerId, $err] = wa_send_text($settings, $raw, $msg);
    $status = $sent ? 'sent' : 'failed';
} else {
    // fallback: click-to-chat link (works with no API approval)
    $waLink = 'https://wa.me/' . $raw . '?text=' . rawurlencode($msg);
}

// keep the live-inbox thread in sync so this send shows up alongside webhook-driven conversations
$thread = wa_find_or_create_thread($db, $raw, $lid, trim($lead['first_name'] . ' ' . $lead['last_name']));

// log message + a follow-up touchpoint
$db->prepare("INSERT INTO whatsapp_messages (lead_id,phone,thread_id,direction,sender,body,status,provider_msg_id,created_by)
              VALUES (?,?,?, 'outbound','agent', ?,?,?,?)")
   ->execute([$lid, $raw, $thread['id'], $msg, $status, $providerId, $me ?: null]);
$db->prepare("UPDATE whatsapp_threads SET last_msg_at=NOW(), agent_id=COALESCE(agent_id,?) WHERE id=?")->execute([$me ?: null, $thread['id']]);
$db->prepare("INSERT INTO lead_followups (lead_id,channel,direction,outcome,feedback,created_by)
              VALUES (?, 'whatsapp','outbound','message_sent',?,?)")
   ->execute([$lid, 'WhatsApp: ' . $msg, $me ?: null]);
$db->prepare("UPDATE leads SET last_contacted_at=NOW() WHERE id=?")->execute([$lid]);

// auto-log ~1 min of agent time for the WhatsApp touch (feeds My Timesheet)
if ($me) {
    try {
        $db->prepare("INSERT INTO timesheets (employee_id,entry_date,hours,activity_type,related_type,related_id,description,source,status)
                      VALUES (?,CURDATE(),0.02,'whatsapp','lead',?,?, 'auto','draft')")
           ->execute([$me, $lid, 'WhatsApp: ' . substr($msg, 0, 80)]);
    } catch (Throwable $e) {}
}

ok(['sent'=>$sent, 'status'=>$status, 'wa_link'=>$waLink, 'phone'=>$raw],
   ['message'=>$sent ? 'WhatsApp message sent' : ($waLink ? 'Open WhatsApp to send (API not enabled)' : 'Send failed')]);
