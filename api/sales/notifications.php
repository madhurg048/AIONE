<?php
/**
 * Unified "pending work" feed for the SERVICE team's live notification popup.
 *
 * A Service exec, on ANY page of the CRM, polls this endpoint every few seconds (see the global
 * notifier in includes/sales-layout.php). It returns every unclaimed item that should ring a
 * Service exec's screen right now:
 *
 *   - chat     : a live chat (chatbot service/transaction) sitting in the waiting queue
 *   - whatsapp : an unassigned WhatsApp conversation with a recent unread inbound message
 *   - call     : an inbound phone call ringing, not yet claimed
 *   - callback : an unclaimed "callback request" ticket from the chatbot
 *
 * Each item carries a stable `key` (e.g. "chat:42"). The frontend renders one popup card per key
 * and plays a repeating alarm while ANY item is present. Because the alarm is driven purely by
 * "does the latest poll still contain items", the moment an item is claimed — by THIS agent or any
 * OTHER agent — it drops out of every agent's next poll, their card disappears, and once the list
 * is empty the sound stops everywhere. That declarative model is the fix for the old bug where the
 * sound kept ringing after a chat was accepted.
 *
 * Claiming itself reuses the existing first-to-click-wins endpoints (atomic UPDATE ... WHERE still
 * unclaimed): chat.php?action=pickup, whatsapp-chat.php?action=open_by_phone, calls.php?action=claim,
 * tickets.php?action=claim. This endpoint is read-only.
 *
 * SERVICE EXECS ONLY. Managers are supervisory (they never pick up work directly) and admins are
 * not on the queue, so neither gets these popups — matches the existing waiting-chat alarm rule.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_automation_engine.php';
require_login();

$db = db();

// Not a Service exec → nothing to notify. Return an empty, well-formed payload (200, not 403) so
// the shared poller can run harmlessly on every role's pages without special-casing.
if (current_role() !== 'service_exec') {
    ok(['items' => [], 'count' => 0]);
}

// Keep the waiting/ringing queues honest on every poll — same inline sweeps the Live Chat and
// Calls pages run, so an expired waiting chat / unclaimed call doesn't keep ringing forever here.
try { chat_sweep_missed($db); } catch (Throwable $e) { }
if (function_exists('call_sweep_missed')) { try { call_sweep_missed($db); } catch (Throwable $e) { } }

$items = [];

/* ---- Waiting live chats (chatbot service/transaction) ------------------------------------- */
try {
    $rows = $db->query("
        SELECT cs.id, cs.visitor_name, cs.visitor_phone,
               (SELECT body FROM chat_messages cm WHERE cm.session_id=cs.id AND cm.sender='visitor' ORDER BY cm.id DESC LIMIT 1) AS last_msg
        FROM chat_sessions cs
        WHERE cs.status='waiting' AND cs.vertical='service'
        ORDER BY cs.started_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $items[] = [
            'key'      => 'chat:' . (int) $r['id'],
            'kind'     => 'chat',
            'title'    => 'New live chat',
            'name'     => $r['visitor_name'] ?: 'A visitor',
            'subtitle' => $r['last_msg'] ?: 'is waiting to chat',
            'ref_id'   => (int) $r['id'],
        ];
    }
} catch (Throwable $e) { }

/* ---- Unassigned WhatsApp conversations with a recent unread inbound ------------------------- */
try {
    $rows = $db->query("
        SELECT wt.id, wt.phone, wt.contact_name,
               (SELECT body FROM whatsapp_messages wm WHERE wm.thread_id=wt.id ORDER BY wm.id DESC LIMIT 1) AS last_msg
        FROM whatsapp_threads wt
        WHERE wt.agent_id IS NULL
          AND wt.status='open'
          AND wt.unread_count > 0
          AND wt.last_inbound_at IS NOT NULL
          AND wt.last_inbound_at > NOW() - INTERVAL 10 MINUTE
        ORDER BY wt.last_inbound_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $items[] = [
            'key'      => 'wa:' . (int) $r['id'],
            'kind'     => 'whatsapp',
            'title'    => 'New WhatsApp message',
            'name'     => $r['contact_name'] ?: $r['phone'],
            'subtitle' => $r['last_msg'] ?: 'sent a WhatsApp message',
            'ref_id'   => (int) $r['id'],
            'phone'    => $r['phone'],
        ];
    }
} catch (Throwable $e) { }

/* ---- Ringing inbound calls, unclaimed ------------------------------------------------------ */
try {
    $rows = $db->query("
        SELECT id, from_number, customer_name
        FROM call_logs
        WHERE status='ringing' AND claimed_by IS NULL AND direction='inbound' AND vertical='service'
        ORDER BY started_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $items[] = [
            'key'      => 'call:' . (int) $r['id'],
            'kind'     => 'call',
            'title'    => 'Incoming call',
            'name'     => $r['customer_name'] ?: ($r['from_number'] ?: 'Unknown caller'),
            'subtitle' => $r['from_number'] ?: 'is calling',
            'ref_id'   => (int) $r['id'],
        ];
    }
} catch (Throwable $e) { }

/* ---- Unclaimed callback-request tickets (chatbot "call me back") ---------------------------- */
try {
    $rows = $db->query("
        SELECT id, customer_name, customer_mobile
        FROM crm_tickets
        WHERE assist_type='callback' AND vertical='service'
          AND owner_id IS NULL AND status IN ('open','transferred')
          AND created_at > NOW() - INTERVAL 12 HOUR
        ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $items[] = [
            'key'      => 'callback:' . (int) $r['id'],
            'kind'     => 'callback',
            'title'    => 'Callback request',
            'name'     => $r['customer_name'] ?: ($r['customer_mobile'] ?: 'A customer'),
            'subtitle' => 'requested a call back',
            'ref_id'   => (int) $r['id'],
        ];
    }
} catch (Throwable $e) { }

ok(['items' => $items, 'count' => count($items)]);
