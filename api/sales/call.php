<?php
/**
 * Click-to-call from the lead panel. Triggers the configured Indian provider (Exotel by default)
 * to dial the AGENT and the CUSTOMER and bridge them. Falls back to the device dialer (tel:) if
 * the provider isn't configured. Logs to call_logs; call time is auto-logged when the provider's
 * status webhook (call-status.php) fires.
 *
 *   POST call.php { lead_id, agent_number }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_telephony.php';
require_login();
$db = db();
$me = current_employee_id();
$b = body();
$lid = (int)($b['lead_id'] ?? 0);
if (!$lid) fail('lead_id required');

$s = $db->prepare("SELECT id,first_name,last_name,phone,mobile,owner_id,vertical FROM leads WHERE id=?"); $s->execute([$lid]);
$lead = $s->fetch();
if (!$lead) fail('Lead not found', 404);
// Team-wide within a vertical, same as leads.php/followups.php — any exec can call any lead
// in their vertical, not just their own.
assert_can_see_vertical($lead['vertical']);

$cfg = $db->query("SELECT * FROM telephony_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$cc  = ($cfg && $cfg['default_cc']) ? $cfg['default_cc'] : '91';
$cust = preg_replace('/\D+/', '', $lead['mobile'] ?: $lead['phone']);
if ($cust === '') fail('Lead has no phone number');
if (strlen($cust) === 10) $cust = $cc . $cust;

$enabled = $cfg && $cfg['enabled'] && $cfg['api_key'] && $cfg['api_token'] && $cfg['account_sid'] && $cfg['caller_id'];
if (!$enabled) {
    // fallback: let the browser/device place the call
    ok(['mode' => 'device', 'dial' => '+' . $cust], ['message' => 'Provider not configured — dialing from this device']);
}

$agent = preg_replace('/\D+/', '', $b['agent_number'] ?? '');
if ($agent === '') fail('Your callback number is required to connect the call');
if (strlen($agent) === 10) $agent = $cc . $agent;

// route to the chosen provider (Exotel / TeleCMI)
$statusCb = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['SCRIPT_NAME']) . '/../integrations/call-status.php';
[$ok, $callId, $errMsg] = crm_place_call($cfg, $agent, $cust, $statusCb);

$db->prepare("INSERT INTO call_logs (lead_id,agent_id,to_number,from_number,provider_call_id,status,direction)
              VALUES (?,?,?,?,?,?, 'outbound')")
   ->execute([$lid, $me, $cust, $agent, $callId, $ok ? 'initiated' : 'failed']);

if (!$ok) fail('Call failed: ' . $errMsg, 502);
ok(['mode' => 'provider', 'call_id' => $callId], ['message' => 'Connecting your phone to the customer…']);
