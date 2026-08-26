<?php
/**
 * Exotel inbound-call webhook (public). Configure this URL as the "Passthru Applet" / webhook
 * step in Exotel's call flow for the toll-free number, so every inbound call to that number
 * creates a `ringing` call_logs row here — unclaimed, so it fans out to every Service agent's
 * Calls workspace (polling calls.php?action=state) until one of them claims it.
 *
 *   POST call-inbound.php  (Exotel-style: CallSid, CallFrom, CallTo)
 */
require_once __DIR__ . '/../../config/database.php';
header('Content-Type: application/json');

$db = getDB();
if (!$db) { http_response_code(500); echo '{"ok":false}'; exit; }

$in = $_POST ?: json_decode(file_get_contents('php://input'), true) ?: [];
$sid  = $in['CallSid'] ?? $in['call_id'] ?? '';
$from = preg_replace('/\D+/', '', $in['CallFrom'] ?? $in['From'] ?? '');
$to   = preg_replace('/\D+/', '', $in['CallTo'] ?? $in['To'] ?? '');
if ($sid === '' || $from === '') { echo '{"ok":false,"error":"missing CallSid/CallFrom"}'; exit; }

// Same shared-secret guard pattern as call-status.php — blocks spoofed inbound-call rows.
@require_once __DIR__ . '/../../config/config.php';
if (defined('TELEPHONY_WEBHOOK_SECRET') && TELEPHONY_WEBHOOK_SECRET !== '') {
    $tok = $_GET['token'] ?? $in['token'] ?? '';
    if (!hash_equals(TELEPHONY_WEBHOOK_SECRET, (string)$tok)) { http_response_code(403); echo '{"ok":false,"error":"bad token"}'; exit; }
}

// Idempotency: Exotel may retry the same webhook; don't create a duplicate ringing row for one CallSid.
$exists = $db->prepare("SELECT id FROM call_logs WHERE provider_call_id=? LIMIT 1");
$exists->execute([$sid]);
if ($exists->fetchColumn()) { echo '{"ok":true,"note":"already registered"}'; exit; }

$db->prepare("INSERT INTO call_logs (to_number, from_number, provider_call_id, status, direction, vertical, source, channel)
              VALUES (?,?,?, 'ringing', 'inbound', 'service', 'exotel', 'telephony')")
   ->execute([$to, $from, $sid]);

echo '{"ok":true,"call_id":' . (int)$db->lastInsertId() . '}';
