<?php
/**
 * Admin/dept-manager live-conversations monitor: chats in progress + calls + WhatsApp threads.
 * Admin sees everything; the Sales Manager sees only Sales-vertical conversations, the Service
 * Manager only Service-vertical ones.
 *   GET conversations.php
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
if (!can_manage_all_records()) fail('Admin or manager only', 403);
$db = db();
$vertical = current_vertical();   // null for true admin -> unrestricted

// a waiting chat nobody claimed in time becomes 'missed' — same rule as chat.php's inbox action.
// This is just a backstop in case chat_sweep_missed() (30s/60s ladder, api/sales/_automation_engine.php)
// never ran for this session (no visitor/agent poll hit it); same root cause, so same reason tag.
$db->exec("UPDATE chat_sessions SET status='missed', missed_reason='waiting_left' WHERE status='waiting' AND started_at < NOW() - INTERVAL 10 MINUTE");

$liveArgs = [];
$liveScope = vertical_scope('cs.vertical', $liveArgs);
$live = $db->prepare("
    SELECT cs.id, cs.visitor_name, cs.visitor_phone, cs.status, cs.started_at, cs.picked_at, cs.lead_id, cs.agent_id,
           e.full_name AS agent,
           TIMESTAMPDIFF(SECOND, cs.started_at, NOW()) AS age_sec,
           (SELECT body FROM chat_messages cm WHERE cm.session_id=cs.id ORDER BY id DESC LIMIT 1) AS last_msg
    FROM chat_sessions cs LEFT JOIN employees e ON e.id=cs.agent_id
    WHERE cs.status IN('waiting','active','missed') AND $liveScope
    ORDER BY FIELD(cs.status,'missed','waiting','active'), cs.started_at");
$live->execute($liveArgs);
$live = $live->fetchAll();

// calls/whatsapp have no vertical of their own — for a dept manager, scope via the linked lead's
// vertical (entries with no linked lead are excluded for a dept manager; admin sees everything).
$callArgs = [];
$callScope = $vertical === null ? '1' : 'l.vertical = ?';
if ($vertical !== null) $callArgs[] = $vertical;
$calls = $db->prepare("
    SELECT cl.id, cl.to_number, cl.status, cl.duration_sec, cl.started_at, cl.recording_url,
           cl.transcript, cl.transcript_status, e.full_name AS agent,
           TRIM(CONCAT(COALESCE(l.first_name,''),' ',COALESCE(l.last_name,''))) AS lead_name
    FROM call_logs cl LEFT JOIN employees e ON e.id=cl.agent_id LEFT JOIN leads l ON l.id=cl.lead_id
    WHERE cl.started_at > NOW()-INTERVAL 1 DAY AND $callScope ORDER BY cl.id DESC LIMIT 20");
$calls->execute($callArgs);
$calls = $calls->fetchAll();

$waArgs = [];
$waScope = $vertical === null ? '1' : 'l.vertical = ?';
if ($vertical !== null) $waArgs[] = $vertical;
$wa = $db->prepare("
    SELECT wm.id, wm.phone, wm.body, wm.status, wm.created_at, e.full_name AS agent,
           TRIM(CONCAT(COALESCE(l.first_name,''),' ',COALESCE(l.last_name,''))) AS lead_name
    FROM whatsapp_messages wm LEFT JOIN employees e ON e.id=wm.created_by LEFT JOIN leads l ON l.id=wm.lead_id
    WHERE wm.created_at > NOW()-INTERVAL 1 DAY AND $waScope ORDER BY wm.id DESC LIMIT 20");
$wa->execute($waArgs);
$wa = $wa->fetchAll();

$vsc = vertical_scope_inline('vertical');
$callsToday = $db->prepare("SELECT COUNT(*) FROM call_logs cl LEFT JOIN leads l ON l.id=cl.lead_id
                             WHERE cl.started_at>NOW()-INTERVAL 1 DAY AND $callScope");
$callsToday->execute($callArgs);
$waToday = $db->prepare("SELECT COUNT(*) FROM whatsapp_messages wm LEFT JOIN leads l ON l.id=wm.lead_id
                          WHERE wm.created_at>NOW()-INTERVAL 1 DAY AND $waScope");
$waToday->execute($waArgs);
$counts = [
    'chats_active'  => (int)$db->query("SELECT COUNT(*) FROM chat_sessions WHERE status='active' AND $vsc")->fetchColumn(),
    'chats_waiting' => (int)$db->query("SELECT COUNT(*) FROM chat_sessions WHERE status='waiting' AND $vsc")->fetchColumn(),
    'chats_missed'  => (int)$db->query("SELECT COUNT(*) FROM chat_sessions WHERE status='missed' AND $vsc")->fetchColumn(),
    'calls_today'   => (int)$callsToday->fetchColumn(),
    'wa_today'      => (int)$waToday->fetchColumn(),
];

ok(['live_chats' => $live, 'calls' => $calls, 'whatsapp' => $wa, 'counts' => $counts]);
