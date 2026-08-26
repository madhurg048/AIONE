<?php
/**
 * Streams an inbound WhatsApp media attachment (image/document/audio) on demand.
 * Meta's media URLs require an authenticated fetch and expire ~5 min, so we resolve + proxy
 * them lazily here instead of storing them at webhook time.
 *
 *   GET whatsapp-media.php?message_id=123
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_whatsapp_engine.php';
require_login();
$db = db();

$id = (int)($_GET['message_id'] ?? 0);
$s = $db->prepare("SELECT wm.*, wt.lead_id AS thread_lead_id, wt.agent_id FROM whatsapp_messages wm
                    LEFT JOIN whatsapp_threads wt ON wt.id = wm.thread_id WHERE wm.id=?");
$s->execute([$id]);
$m = $s->fetch(PDO::FETCH_ASSOC);
if (!$m || !$m['media_id']) fail('Not found', 404);

if (!can_manage_all_records()) {
    $ownerId = null;
    $leadId = $m['lead_id'] ?: $m['thread_lead_id'];
    if ($leadId) { $l = $db->prepare("SELECT owner_id FROM leads WHERE id=?"); $l->execute([$leadId]); $ownerId = $l->fetchColumn(); }
    $me = current_employee_id();
    if ((int)$m['agent_id'] !== $me && (int)$ownerId !== $me) fail('Not authorized to view this attachment', 403);
}

$cfg = wa_settings($db);
if (!$cfg || empty($cfg['api_token'])) fail('WhatsApp is not configured', 500);
$ver = $cfg['graph_version'] ?: 'v25.0';

$ch = curl_init("https://graph.facebook.com/{$ver}/{$m['media_id']}");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['api_token']], CURLOPT_TIMEOUT => 15]);
$meta = json_decode((string)curl_exec($ch), true);
curl_close($ch);
if (empty($meta['url'])) fail('Media no longer available', 502);

$ch = curl_init($meta['url']);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['api_token']], CURLOPT_TIMEOUT => 30]);
$bin = curl_exec($ch);
curl_close($ch);
if ($bin === false) fail('Download failed', 502);

header('Content-Type: ' . ($m['mime_type'] ?: ($meta['mime_type'] ?? 'application/octet-stream')));
header('Content-Length: ' . strlen($bin));
header('Cache-Control: private, max-age=3600');
echo $bin;
