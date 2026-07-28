<?php
/**
 * WhatsApp live agent console — mirrors chat.php's action shape for the live-chat UI, but backed by
 * whatsapp_threads/whatsapp_messages. Inbound messages arrive via api/integrations/whatsapp-webhook.php,
 * not through this file — every action here requires a logged-in CRM session.
 *
 *   ?action=open_by_phone GET ?phone=&name=&lead_id=          -> {thread_id, contact_name} (find-or-create)
 *   ?action=inbox     GET                                    -> {threads:[]}
 *   ?action=poll      GET  ?thread_id=&since=                -> {messages, status, window_open}
 *   ?action=post      POST {thread_id, body}                 -> free-text reply (only while window_open)
 *   ?action=template  POST {thread_id}                       -> send the configured template (reopens the door)
 *   ?action=assign    POST {thread_id, agent_id}              (admin/manager only)
 *   ?action=transfer  POST {thread_id, agent_id}              (current owner or admin/manager; cross-team handoff)
 *   ?action=agents    GET                                    -> assignable operators
 *   ?action=close     POST {thread_id}
 *   ?action=summarize POST {thread_id}                       -> {summary, source} (AI, evidence = the thread itself)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_whatsapp_engine.php';
require_once __DIR__ . '/_task_events.php';
require_once __DIR__ . '/_ai.php';
require_login();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$db = db();
$me = current_employee_id();
$action = $_GET['action'] ?? '';

/**
 * The employee who currently "owns" this conversation for visibility purposes.
 * Once a lead converts to a merchant, the merchant's owner is authoritative (a merchant can be
 * handed over to a different team/agent than whoever originally worked the lead) — so merchant
 * ownership wins first. After that, the thread's own agent_id (set by an explicit claim/
 * transfer on THIS conversation) wins over the lead's owner_id, which may just be a stale
 * round-robin assignment made at lead-creation time before anyone touched this thread —
 * otherwise whoever picks up/is assigned a thread could be locked out as read-only the moment
 * the underlying lead happened to round-robin to a different rep.
 */
function wa_effective_owner(PDO $db, array $thread): ?int
{
    if (!empty($thread['lead_id'])) {
        $m = $db->prepare("SELECT owner_id FROM merchants WHERE source_lead_id=? ORDER BY id DESC LIMIT 1");
        $m->execute([$thread['lead_id']]);
        $owner = $m->fetchColumn();
        if ($owner !== false && $owner !== null) return (int)$owner;
    }
    if ($thread['agent_id'] !== null) return (int)$thread['agent_id'];
    if (!empty($thread['lead_id'])) {
        $s = $db->prepare("SELECT owner_id FROM leads WHERE id=?"); $s->execute([$thread['lead_id']]);
        $owner = $s->fetchColumn();
        if ($owner !== false && $owner !== null) return (int)$owner;
    }
    return null;
}

/**
 * Read access: any logged-in CRM team member may view a WhatsApp conversation's history.
 * Unlike leads/tickets, a WhatsApp thread isn't cleanly vertical-partitioned — the same phone
 * number can have contacted both Sales and Service over time (one thread per phone, by design,
 * since that's how WhatsApp itself works) — so gating read access by vertical/ownership just
 * produces a confusing 403 for a teammate who has every reason to want to see the conversation.
 * Only wa_thread_can_write() below is actually ownership-gated.
 */
function wa_thread_can_read(PDO $db, array $thread): bool
{
    return true;
}

/**
 * Write access ("who can actually reply/close/act on this conversation"): admin always; a dept
 * manager is supervisory-only (can view every conversation, can never send a message — only
 * assign/reassign it), same rule as live chat (see chat_session_can_write in chat.php).
 * Everyone else only while they're the effective owner. A sales/service exec loses write access
 * the moment the thread is reassigned/transferred, or the underlying merchant is handed to a
 * different team/agent (e.g. after activation) — even if this thread was theirs.
 */
function wa_thread_can_write(PDO $db, array $thread): bool
{
    if (current_role() === 'admin') return true;
    if (in_array(current_role(), ['manager', 'service_manager'], true)) return false;
    return wa_effective_owner($db, $thread) === current_employee_id();
}
function wa_thread(PDO $db, int $id): ?array
{
    $s = $db->prepare("SELECT * FROM whatsapp_threads WHERE id=?"); $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* Deep link from the Leads page's WhatsApp icon: find (or open a new) thread for this phone
   number, so the caller can jump straight into the Conversations WhatsApp console. */
if ($action === 'open_by_phone') {
    $phone = wa_normalize_phone($_GET['phone'] ?? '');
    if ($phone === '') fail('phone required');
    $leadId = (int)($_GET['lead_id'] ?? 0) ?: null;
    $name = trim($_GET['name'] ?? '') ?: null;
    $t = wa_find_or_create_thread($db, $phone, $leadId, $name);
    // Genuinely unowned (no lead/merchant owner and no agent yet) -> claim it for the caller,
    // same as chat.php's pickup(). Otherwise it belongs to someone else — still open it (read
    // access is unconditional, see wa_thread_can_read()), just read-only until it's transferred
    // or the current owner lets go of it.
    if (wa_effective_owner($db, $t) === null && !in_array(current_role(), ['manager', 'service_manager'], true)) {
        $db->prepare("UPDATE whatsapp_threads SET agent_id=COALESCE(agent_id,?) WHERE id=?")->execute([$me, $t['id']]);
        $t = wa_thread($db, (int)$t['id']);
    }
    ok([
        'thread_id' => (int)$t['id'],
        'contact_name' => $t['contact_name'] ?: $t['phone'],
        'can_write' => wa_thread_can_write($db, $t),
    ]);
}
if ($action === 'inbox') {
    // Team-wide read (see wa_thread_can_read()) — every conversation is listed for every
    // logged-in team member; `agent`/`agent_id` show who currently owns it (write access),
    // the UI uses that to render it read-only for anyone else. Not vertical-scoped, same
    // reasoning as wa_thread_can_read(): one phone can span both teams over time.
    $field = in_array($_GET['field'] ?? 'name', ['name', 'phone', 'company', 'date'], true) ? $_GET['field'] : 'name';
    $q = trim($_GET['q'] ?? '');
    $dateVal = trim($_GET['date'] ?? '');
    $args = [];
    $where = '';
    if ($field === 'date') {
        if ($dateVal !== '') { $where = ' AND DATE(wt.last_msg_at) = ?'; $args[] = $dateVal; }
    } elseif ($q !== '') {
        $col = $field === 'phone' ? 'wt.phone' : ($field === 'company' ? 'l.company' : 'wt.contact_name');
        $where = " AND $col LIKE ?";
        $args[] = "%$q%";
    }
    $sql = "SELECT wt.id, wt.phone, wt.contact_name, wt.lead_id, wt.agent_id, wt.status, wt.last_msg_at, wt.unread_count, l.company,
                   (TIMESTAMPDIFF(HOUR, wt.last_inbound_at, NOW()) < 24) AS window_open,
                   e.full_name AS agent,
                   (SELECT body FROM whatsapp_messages wm WHERE wm.thread_id=wt.id ORDER BY id DESC LIMIT 1) AS last_msg
            FROM whatsapp_threads wt
            LEFT JOIN leads l ON l.id = wt.lead_id
            LEFT JOIN merchants m ON m.source_lead_id = wt.lead_id
            LEFT JOIN employees e ON e.id = wt.agent_id
            WHERE 1=1$where
            ORDER BY wt.last_msg_at DESC";
    $s = $db->prepare($sql);
    $s->execute($args);
    ok(['threads' => $s->fetchAll()]);
}
if ($action === 'agents') {
    // Includes both verticals — a WhatsApp thread isn't team-partitioned (see wa_thread_can_read),
    // so a Service exec handling something sales-related needs Sales execs in this list to
    // hand off to, and vice versa.
    $s = $db->prepare("SELECT DISTINCT e.id, e.full_name, su.role,
                   (su.last_heartbeat_at IS NOT NULL AND su.last_heartbeat_at > NOW() - INTERVAL 90 SECOND) AS is_online
                   FROM sales_users su JOIN employees e ON e.id=su.employee_id
                   WHERE su.is_active=1 AND su.role IN('sales_exec','service_exec','manager','service_manager','admin')
                   AND e.id <> ?
                   ORDER BY is_online DESC, e.full_name");
    $s->execute([current_employee_id()]);
    ok($s->fetchAll());
}
/*
 * Cross-dept handoff, mirroring chat.php's ?action=transfer: the exec currently on a WhatsApp
 * conversation (or a manager/admin) hands it to a specific agent — e.g. a Service exec who
 * picked up a sales-related WhatsApp enquiry sending it to a Sales exec, who can then take over
 * the same conversation. Unlike chat_sessions, whatsapp_threads has no vertical column (WhatsApp
 * is shared across teams by design), so this just moves agent_id + the underlying lead's owner.
 */
if ($action === 'transfer') {
    $b = body(); $tid = (int)($b['thread_id'] ?? 0); $agentId = (int)($b['agent_id'] ?? 0);
    if (!$tid || !$agentId) fail('thread_id and agent_id required');
    $t = wa_thread($db, $tid);
    if (!$t) fail('Thread not found', 404);
    if ($t['status'] === 'closed') fail('Conversation is already closed', 410);
    if (!wa_thread_can_write($db, $t)) fail('Only the current owner (or a manager/admin) can transfer this conversation', 403);

    $tgt = $db->prepare("SELECT su.role, e.full_name FROM sales_users su JOIN employees e ON e.id=su.employee_id
                          WHERE su.employee_id=? AND su.is_active=1 LIMIT 1");
    $tgt->execute([$agentId]);
    $target = $tgt->fetch(PDO::FETCH_ASSOC);
    if (!$target) fail('Unknown or inactive agent', 400);
    $targetVertical = $target['role'] === 'service_exec' ? 'service' : ($target['role'] === 'sales_exec' ? 'sales' : null);
    if ($targetVertical === null) fail('Conversations can only be transferred to a Sales Exec or Service Exec', 400);

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE whatsapp_threads SET agent_id=? WHERE id=?")->execute([$agentId, $tid]);
        if ($t['lead_id']) {
            $db->prepare("UPDATE leads SET owner_id=?, vertical=? WHERE id=?")->execute([$agentId, $targetVertical, $t['lead_id']]);
            $db->prepare("UPDATE crm_tickets SET vertical=?, waiting_on=?, owner_id=?, assigned_to_name=? WHERE lead_id=?")
               ->execute([$targetVertical, $targetVertical === 'service' ? 'service_team' : 'sales_team', $agentId, $target['full_name'], $t['lead_id']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail('Transfer failed: ' . $e->getMessage(), 500);
    }
    $db->prepare("INSERT INTO whatsapp_messages (lead_id,phone,thread_id,direction,sender,body,status,created_by)
                  VALUES (?,?,?, 'outbound','system', ?, 'sent', ?)")
       ->execute([$t['lead_id'], $t['phone'], $tid, 'Transferred to ' . $target['full_name'] . ' (' . ucfirst($targetVertical) . ' team)', $me]);
    $db->prepare("UPDATE whatsapp_threads SET last_msg_at=NOW() WHERE id=?")->execute([$tid]);
    ok(['ok' => true, 'vertical' => $targetVertical]);
}
if ($action === 'poll') {
    $tid = (int)($_GET['thread_id'] ?? 0);
    $t = wa_thread($db, $tid);
    if (!$t) fail('Thread not found', 404);
    // Read is unconditional (wa_thread_can_read()) — can_write tells the UI whether to show
    // the composer or a "view only, assigned to X" banner.
    $since = (int)($_GET['since'] ?? 0);
    $m = $db->prepare("SELECT id,sender,body,media_id,media_type,mime_type,local_path,status,created_at FROM whatsapp_messages WHERE thread_id=? AND id>? ORDER BY id");
    $m->execute([$tid, $since]);
    if ($t['unread_count'] > 0) $db->prepare("UPDATE whatsapp_threads SET unread_count=0 WHERE id=?")->execute([$tid]);
    $ownerId = wa_effective_owner($db, $t);
    $ownerName = null;
    if ($ownerId) { $nm = $db->prepare("SELECT full_name FROM employees WHERE id=?"); $nm->execute([$ownerId]); $ownerName = $nm->fetchColumn() ?: null; }
    ok([
        'messages' => $m->fetchAll(),
        'status' => $t['status'],
        'window_open' => wa_window_open($t),
        'can_write' => wa_thread_can_write($db, $t),
        'owner_name' => $ownerName,
    ]);
}
if ($action === 'post') {
    $b = body(); $tid = (int)($b['thread_id'] ?? 0);
    $t = wa_thread($db, $tid);
    if (!$t) fail('Thread not found', 404);
    if (!wa_thread_can_write($db, $t)) fail('View only — this conversation is currently assigned to another agent', 403);
    $msg = trim($b['body'] ?? '');
    if ($msg === '') fail('empty');
    if (!wa_window_open($t)) fail('24h window closed — send a template to reopen the conversation', 409);
    $cfg = wa_settings($db);
    if (!$cfg || !$cfg['enabled'] || !$cfg['api_token'] || !$cfg['phone_number_id']) fail('WhatsApp is not configured', 500);
    [$sent, $providerId, $err] = wa_send_text($cfg, $t['phone'], $msg);
    $status = $sent ? 'sent' : 'failed';
    $db->prepare("INSERT INTO whatsapp_messages (lead_id,phone,thread_id,direction,sender,body,status,provider_msg_id,created_by)
                  VALUES (?,?,?, 'outbound','agent', ?,?,?,?)")
       ->execute([$t['lead_id'], $t['phone'], $tid, $msg, $status, $providerId, $me]);
    $db->prepare("UPDATE whatsapp_threads SET last_msg_at=NOW(), agent_id=COALESCE(agent_id,?) WHERE id=?")->execute([$me, $tid]);
    if (!$sent) fail('Send failed: ' . $err, 502);
    ok(['ok' => true]);
}
if ($action === 'template') {
    $b = body(); $tid = (int)($b['thread_id'] ?? 0);
    $t = wa_thread($db, $tid);
    if (!$t) fail('Thread not found', 404);
    if (!wa_thread_can_write($db, $t)) fail('View only — this conversation is currently assigned to another agent', 403);
    $cfg = wa_settings($db);
    if (!$cfg || !$cfg['enabled'] || !$cfg['api_token'] || !$cfg['phone_number_id']) fail('WhatsApp is not configured', 500);
    [$sent, $providerId, $err] = wa_send_template($cfg, $t['phone']);
    $status = $sent ? 'sent' : 'failed';
    $label = 'Template: ' . ($cfg['template_name'] ?: 'hello_world');
    $db->prepare("INSERT INTO whatsapp_messages (lead_id,phone,thread_id,direction,sender,body,template,status,provider_msg_id,created_by)
                  VALUES (?,?,?, 'outbound','agent', ?,?,?,?,?)")
       ->execute([$t['lead_id'], $t['phone'], $tid, $label, $cfg['template_name'] ?: 'hello_world', $status, $providerId, $me]);
    $db->prepare("UPDATE whatsapp_threads SET last_msg_at=NOW(), agent_id=COALESCE(agent_id,?) WHERE id=?")->execute([$me, $tid]);
    if (!$sent) fail('Template send failed: ' . $err, 502);
    ok(['ok' => true, 'message' => 'Template sent — the conversation reopens once the customer replies']);
}
if ($action === 'assign') {
    if (!can_manage_all_records()) fail('Only a manager/admin can assign conversations', 403);
    $b = body(); $tid = (int)($b['thread_id'] ?? 0); $agentId = (int)($b['agent_id'] ?? 0);
    if (!$tid || !$agentId) fail('thread_id and agent_id required');
    $up = $db->prepare("UPDATE whatsapp_threads SET agent_id=? WHERE id=?");
    $up->execute([$agentId, $tid]);
    if (!$up->rowCount() && !wa_thread($db, $tid)) fail('Thread not found', 404);
    ok(['ok' => true]);
}
if ($action === 'summarize') {
    $tid = (int)(body()['thread_id'] ?? 0);
    $t = wa_thread($db, $tid);
    if (!$t) fail('Thread not found', 404);
    // Summarizing is a read-level action — any teammate who can view the thread may ask for one.
    $m = $db->prepare("SELECT sender,body FROM whatsapp_messages WHERE thread_id=? AND sender IN('customer','agent') ORDER BY id DESC LIMIT 60");
    $m->execute([$tid]);
    $rows = array_reverse($m->fetchAll(PDO::FETCH_ASSOC));
    if (!$rows) fail('No messages to summarize yet', 400);
    $transcript = implode("\n", array_map(fn($r) => ($r['sender'] === 'agent' ? 'Agent' : 'Customer') . ': ' . $r['body'], $rows));
    $prompt = "Summarize this WhatsApp conversation in 2-4 plain sentences for a sales agent. " .
              "Respond with plain prose only — no JSON, no markdown, no field labels.\n\n" . $transcript;
    $r = crm_ai_call($prompt, 'short', 'summarize');
    if (!$r) fail('No AI endpoint answered (box down and no fallback)', 502);
    ok(['summary' => $r['response'], 'source' => $r['source']]);
}
/* Edit the contact's display name / phone number from the chat header — same write-access
   rule as replying (only the current effective owner, or admin, may change it). Phone is
   unique per thread, so a collision with an existing thread for that number fails cleanly
   rather than silently merging two conversations. */
if ($action === 'update_contact') {
    $b = body();
    $tid = (int) ($b['thread_id'] ?? 0);
    $t = wa_thread($db, $tid);
    if (!$t) fail('Thread not found', 404);
    if (!wa_thread_can_write($db, $t)) fail('View only — this conversation is currently assigned to another agent', 403);
    $set = []; $args = [];
    if (array_key_exists('contact_name', $b)) { $set[] = 'contact_name=?'; $args[] = trim($b['contact_name']) ?: null; }
    if (array_key_exists('phone', $b)) {
        $phone = wa_normalize_phone((string) $b['phone']);
        if ($phone === '') fail('A valid phone number is required');
        $set[] = 'phone=?'; $args[] = $phone;
    }
    if (!$set) fail('Nothing to update');
    $args[] = $tid;
    try {
        $db->prepare("UPDATE whatsapp_threads SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') fail('Another conversation already uses that phone number', 409);
        throw $e;
    }
    $updated = wa_thread($db, $tid);
    ok(['contact_name' => $updated['contact_name'], 'phone' => $updated['phone']]);
}

if ($action === 'close') {
    $tid = (int)(body()['thread_id'] ?? 0);
    $t = wa_thread($db, $tid);
    if (!$t) fail('Thread not found', 404);
    if (!wa_thread_can_write($db, $t)) fail('View only — this conversation is currently assigned to another agent', 403);
    $db->prepare("UPDATE whatsapp_threads SET status='closed' WHERE id=?")->execute([$tid]);
    auto_log_activity($db, $me, 'whatsapp', 'WhatsApp conversation with ' . ($t['contact_name'] ?: $t['phone']) . ' marked resolved', $t['lead_id'] ? (int)$t['lead_id'] : null, 60);
    ok(['ok' => true]);
}

fail('Unknown action', 400);
