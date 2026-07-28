<?php
/**
 * Live chat engine — visitor <-> agent.
 * Visitor actions are PUBLIC (authenticated by the session token issued at start).
 * Agent actions require a logged-in CRM session.
 *
 *   Visitor:  ?action=start   POST {name,email,phone,message}         -> {token, session_id}
 *             ?action=v_post  POST {token, body}
 *             ?action=v_poll  GET  ?token=&since=                      -> {messages, status, agent}
 *   Agent:    ?action=start_from_lead POST {lead_id}                  -> {session_id} (find-or-create, self-assigned)
 *             ?action=inbox   GET                                     -> {waiting[], active[]}
 *             ?action=pickup  POST {session_id}
 *             ?action=a_post  POST {session_id, body}
 *             ?action=a_poll  GET  ?session_id=&since=
 *             ?action=close   POST {session_id}
 *             ?action=a_summarize  POST {session_id}                  -> {summary, source}
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_task_events.php';
require_once __DIR__ . '/_automation_engine.php';
require_once __DIR__ . '/_telephony.php';
require_once __DIR__ . '/_ai.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db = db();
$action = $_GET['action'] ?? '';

function chat_session_by_token(PDO $db, string $t): ?array
{
    $s = $db->prepare("SELECT * FROM chat_sessions WHERE token=?");
    $s->execute([$t]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
// chat_push / chat_assign_to / chat_next_agent / chat_auto_assign_on / chat_open_waiting_session
// live in _automation_engine.php (shared with chatbot-ticket.php's public chat entry point).
/**
 * The employee who currently "owns" this chat for visibility purposes — mirrors the same rule used
 * for WhatsApp threads (api/sales/whatsapp-chat.php). Once a lead converts to a merchant, the
 * merchant's owner is authoritative (a merchant can be handed to a different team/agent than
 * whoever originally worked the lead/picked up the chat), so merchant ownership wins over lead
 * ownership, which wins over the chat's own agent_id.
 */
function chat_effective_owner(PDO $db, ?int $leadId, ?int $fallbackAgentId): ?int
{
    if ($leadId) {
        $m = $db->prepare("SELECT owner_id FROM merchants WHERE source_lead_id=? ORDER BY id DESC LIMIT 1");
        $m->execute([$leadId]);
        $owner = $m->fetchColumn();
        if ($owner !== false && $owner !== null)
            return (int) $owner;
    }
    // The chat's own agent_id — set by an explicit pickup/assign/transfer action on THIS
    // conversation — is more authoritative than the lead's owner_id, which may just be a
    // round-robin assignment made at lead-creation time, before anyone touched the chat.
    // Without this order, whoever picks up a waiting chat would get locked out as read-only
    // the moment the underlying lead happened to round-robin to a different rep.
    if ($fallbackAgentId !== null)
        return (int) $fallbackAgentId;
    if ($leadId) {
        $s = $db->prepare("SELECT owner_id FROM leads WHERE id=?");
        $s->execute([$leadId]);
        $owner = $s->fetchColumn();
        if ($owner !== false && $owner !== null)
            return (int) $owner;
    }
    return null;
}
/**
 * Read access: admin sees everything; everyone else (exec or dept manager) sees every chat in
 * their own vertical — a Service exec can read any Service chat, not just ones assigned to them,
 * so the team has visibility into what's happening even on a chat someone else owns.
 */
function chat_session_can_read(array $session): bool
{
    if (current_role() === 'admin')
        return true;
    return ($session['vertical'] ?? null) === current_vertical();
}
/**
 * Write access ("who can actually reply/close/transfer this chat"): admin always; a dept
 * manager (Sales/Service Manager) is supervisory-only here — they can see every chat in their
 * vertical but never send a message themselves, only assign/reassign it to an exec. Everyone
 * else (execs) can write only while they're the chat's current effective owner. Transferring
 * the chat (see ?action=transfer) moves write access to the new owner and drops the previous
 * one to read-only immediately.
 */
function chat_session_can_write(PDO $db, array $session): bool
{
    if (current_role() === 'admin')
        return true;
    if (in_array(current_role(), ['manager', 'service_manager'], true))
        return false;
    return chat_effective_owner($db, $session['lead_id'] ?? null, $session['agent_id'] ?? null) === current_employee_id();
}

/* Log a handoff (cross-dept transfer or in-dept assign) into chat_transfers so the Service
   dashboard can report transfer volumes. Best-effort: a logging failure (e.g. migration not
   applied yet) must never break the actual transfer/assign. */
function chat_log_handoff(PDO $db, int $sid, string $action, $fromAgent, int $toAgent, ?string $fromVertical, ?string $toVertical, $leadId): void
{
    try {
        $db->prepare("INSERT INTO chat_transfers
            (session_id, lead_id, action, from_agent_id, to_agent_id, from_vertical, to_vertical, created_by)
            VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$sid, $leadId ?: null, $action === 'assign' ? 'assign' : 'transfer',
                      $fromAgent ?: null, $toAgent, $fromVertical, $toVertical, current_employee_id() ?: null]);
    } catch (Throwable $e) { /* non-fatal */ }
}

/* ================= VISITOR (public) ================= */
if ($action === 'start') {
    $b = body();
    $name = trim($b['name'] ?? '');
    $email = trim($b['email'] ?? '');
    $phone = trim($b['phone'] ?? '');
    if ($email === '' && $phone === '')
        fail('email or phone required');

    // create/attach a lead (source=live_chat)
    $leadId = null;
    try {
        $parts = preg_split('/\s+/', $name, 2);
        $ins = $db->prepare("INSERT INTO leads (first_name,last_name,company,email,phone,mobile,lead_source,lead_status,description)
                             VALUES (?,?,?,?,?,?, 'live_chat','new','Started a live chat')");
        $ins->execute([$parts[0] ?? '', $parts[1] ?? 'Live Chat', $b['company'] ?? '', $email, $phone, $phone]);
        $leadId = (int) $db->lastInsertId();
        // Round-robin assign off the shared rotation. This was the on_create rule's job; the
        // rule now only notifies, so assignment happens here (and before the rule fires, so it
        // has an owner to notify). See sales_assign_next() in _automation_engine.php.
        sales_assign_next($db, $leadId, null, 'sales');
        $row = $db->prepare("SELECT * FROM leads WHERE id=?");
        $row->execute([$leadId]);
        run_automations($db, 'lead', 'on_create', $row->fetch() ?: []);
    } catch (Throwable $e) {
    }

    $opened = chat_open_waiting_session($db, $leadId, $name, $email ?: null, $phone ?: null, 'sales', $b['message'] ?? null);
    ok(['token' => $opened['token'], 'session_id' => $opened['session_id']]);
}
if ($action === 'v_post') {
    $b = body();
    $s = chat_session_by_token($db, $b['token'] ?? '');
    if (!$s)
        fail('invalid session', 404);
    if ($s['status'] === 'closed')
        fail('chat closed', 410);
    if (trim($b['body'] ?? '') === '')
        fail('empty');
    chat_push($db, (int) $s['id'], 'visitor', trim($b['body']));
    ok(['ok' => true]);
}
/* visitor ends the chat themselves (e.g. "Close" button on the widget) */
if ($action === 'v_close') {
    $s = chat_session_by_token($db, body()['token'] ?? '');
    if (!$s)
        fail('invalid session', 404);
    if ($s['status'] === 'closed')
        ok(['ok' => true]);
    $start = strtotime($s['picked_at'] ?: $s['started_at']);
    $dur = max(0, time() - $start);
    $db->prepare("UPDATE chat_sessions SET status='closed', closed_at=NOW(), duration_sec=? WHERE id=?")->execute([$dur, $s['id']]);
    chat_push($db, (int) $s['id'], 'system', 'Chat closed by visitor');
    ok(['ok' => true]);
}
if ($action === 'v_poll') {
    $s = chat_session_by_token($db, $_GET['token'] ?? '');
    if (!$s)
        fail('invalid session', 404);
    $since = (int) ($_GET['since'] ?? 0);
    chat_sweep_missed($db);
    chat_sweep_inactive_active($db);
    $status = $db->prepare("SELECT status FROM chat_sessions WHERE id=?");
    $status->execute([$s['id']]);
    $s['status'] = $status->fetchColumn();
    // Internal agent remarks (sender='note', see missed_remark) are for the team only — never
    // leak them to the visitor-facing widget.
    $m = $db->prepare("SELECT id,sender,body,attachment_url,attachment_type,created_at FROM chat_messages WHERE session_id=? AND id>? AND sender<>'note' ORDER BY id");
    $m->execute([$s['id'], $since]);
    $agent = null;
    if ($s['agent_id']) {
        $a = $db->prepare("SELECT full_name FROM employees WHERE id=?");
        $a->execute([$s['agent_id']]);
        $agent = $a->fetchColumn() ?: null;
    }
    // can the visitor request a call? only if telephony is live + a sales number is set + we have their phone
    $tp = crm_telephony_ready($db);
    $canCall = $tp && !empty($tp['sales_number']) && !empty($s['visitor_phone']);
    ok(['messages' => $m->fetchAll(), 'status' => $s['status'], 'agent' => $agent, 'call_enabled' => (bool) $canCall]);
}
/* visitor requests a call — provider rings the operator (sales_number) and the visitor, then bridges */
if ($action === 'v_call') {
    $s = chat_session_by_token($db, body()['token'] ?? '');
    if (!$s)
        fail('invalid session', 404);
    $tp = crm_telephony_ready($db);
    if (!$tp || empty($tp['sales_number']) || empty($s['visitor_phone']))
        fail('Calling is not available right now', 400);
    // simple abuse guard: one call request per session per 3 min
    $recent = $db->prepare("SELECT COUNT(*) FROM call_logs WHERE lead_id<=>? AND started_at>NOW()-INTERVAL 3 MINUTE");
    $recent->execute([$s['lead_id']]);
    if ($recent->fetchColumn() > 0)
        fail('A call was just requested — please wait a moment', 429);

    $cc = $tp['default_cc'] ?: '91';
    $visitor = crm_norm_number($s['visitor_phone'], $cc);
    $operator = crm_norm_number($tp['sales_number'], $cc);
    $cb = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname($_SERVER['SCRIPT_NAME']) . '/../integrations/call-status.php';
    [$ok, $callId, $err] = crm_place_call($tp, $operator, $visitor, $cb);   // ring operator, then visitor
    $db->prepare("INSERT INTO call_logs (lead_id,agent_id,to_number,from_number,provider_call_id,status,direction)
                  VALUES (?,?,?,?,?,?, 'inbound')")
        ->execute([$s['lead_id'], $s['agent_id'], $visitor, $operator, $callId, $ok ? 'initiated' : 'failed']);
    if (!$ok)
        fail('Could not place the call: ' . $err, 502);
    chat_push($db, (int) $s['id'], 'system', 'Connecting a call to you now — please keep your phone handy.');
    ok(['ok' => true]);
}

/* ================= AGENT (login required) ================= */
require_login();
$me = current_employee_id();

/* Leads page's "Live Chat" icon: jump straight to this lead's most recent chat session,
   whatever tab it's actually sitting in — a closed or missed chat opens read-only in its own
   tab instead of silently spawning a new session, and only a genuinely unclaimed ('waiting')
   chat gets auto-picked-up. Returns `status` so the frontend can switch to the matching tab. */
if ($action === 'start_from_lead') {
    $leadId = (int) (body()['lead_id'] ?? 0);
    if (!$leadId)
        fail('lead_id required');
    $lead = $db->prepare("SELECT * FROM leads WHERE id=?");
    $lead->execute([$leadId]);
    $l = $lead->fetch(PDO::FETCH_ASSOC);
    if (!$l)
        fail('Lead not found', 404);
    // Team-wide within a vertical, same as leads.php/followups.php — any exec can start a
    // live chat from any lead in their vertical, not just their own.
    assert_can_see_vertical($l['vertical'] ?? null);

    $existing = $db->prepare("SELECT id, status, agent_id FROM chat_sessions WHERE lead_id=? ORDER BY id DESC LIMIT 1");
    $existing->execute([$leadId]);
    $sess = $existing->fetch(PDO::FETCH_ASSOC);
    if ($sess) {
        if ($sess['status'] === 'waiting') {
            chat_assign_to($db, (int) $sess['id'], $me);
            $sess['status'] = 'active';
        }
        // missed/active/closed: just open it where it is — read-only unless the caller
        // already owns it (chat_session_can_write handles that in a_poll).
        ok(['session_id' => (int) $sess['id'], 'status' => $sess['status']]);
    }
    $token = bin2hex(random_bytes(16));
    $vertical = $l['vertical'] === 'service' ? 'service' : 'sales';
    $db->prepare("INSERT INTO chat_sessions (token,visitor_name,visitor_phone,lead_id,vertical,status,agent_id,started_at,picked_at,last_activity_at)
                  VALUES (?,?,?,?,?, 'active', ?, NOW(), NOW(), NOW())")
        ->execute([$token, trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')), $l['mobile'] ?: $l['phone'], $leadId, $vertical, $me]);
    $sid = (int) $db->lastInsertId();
    chat_push($db, $sid, 'system', 'Chat started by agent');
    ok(['session_id' => $sid, 'status' => 'active']);
}

/**
 * Builds a " AND ..." WHERE fragment (and appends bind args) for the inbox search dropdown:
 * name/phone/company match visitor_name/visitor_phone/leads.company; date matches the given
 * tab's own time column (started_at for waiting, picked_at for active, closed_at for
 * missed/closed) to a single calendar day.
 */
function chat_search_sql(string $field, string $q, string $dateVal, string $timeCol, array &$args): string
{
    if ($field === 'date') {
        if ($dateVal === '') return '';
        $args[] = $dateVal;
        return " AND DATE(cs.$timeCol) = ?";
    }
    if ($q === '') return '';
    $args[] = "%$q%";
    switch ($field) {
        case 'phone':   return ' AND cs.visitor_phone LIKE ?';
        case 'company': return ' AND l.company LIKE ?';
        case 'name':
        default:        return ' AND cs.visitor_name LIKE ?';
    }
}

/**
 * Team-wide-within-vertical, searched, paginated chat_sessions list for a given status (admin
 * sees all). Every exec/manager sees every chat in their vertical — owner_id/agent_name is who
 * currently has write access (see chat_session_can_write()), not a visibility gate.
 * Returns ['rows'=>[], 'total'=>int, 'page'=>int, 'per'=>int, 'pages'=>int].
 */
function chat_inbox_list(PDO $db, string $status, string $timeCol, string $q, int $page, int $per, string $field = 'name', string $dateVal = ''): array
{
    $args = [$status];
    $scope = vertical_scope('cs.vertical', $args);
    $searchSql = chat_search_sql($field, $q, $dateVal, $timeCol, $args);
    $joins = "FROM chat_sessions cs
              LEFT JOIN leads l ON l.id = cs.lead_id
              LEFT JOIN merchants m ON m.source_lead_id = cs.lead_id
              LEFT JOIN employees ae ON ae.id = cs.agent_id
              WHERE cs.status=? AND $scope$searchSql";

    $ct = $db->prepare("SELECT COUNT(*) $joins");
    $ct->execute($args);
    $total = (int) $ct->fetchColumn();

    $off = ($page - 1) * $per;
    $sql = "SELECT cs.id,cs.visitor_name,cs.visitor_phone,cs.$timeCol,cs.last_activity_at,cs.lead_id,cs.missed_reason,
                   ae.full_name AS agent_name, l.company,
                   (SELECT body FROM chat_messages cm WHERE cm.session_id=cs.id ORDER BY id DESC LIMIT 1) last_msg
            $joins
            ORDER BY cs.$timeCol DESC LIMIT $per OFFSET $off";
    $rows = $db->prepare($sql);
    $rows->execute($args);

    return [
        'rows' => $rows->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'page' => $page,
        'per' => $per,
        'pages' => max(1, (int) ceil($total / $per))
    ];
}

/**
 * Vertical-wide status-strip counts: active total, waiting (unclaimed), unread (awaiting an agent
 * reply), missed, closed today, and closed total (all-time — what the Closed tab badge shows).
 */
function chat_inbox_counts(PDO $db): array
{
    $args = [];
    $scope = vertical_scope('cs.vertical', $args);
    $joins = "FROM chat_sessions cs
              LEFT JOIN leads l ON l.id = cs.lead_id
              LEFT JOIN merchants m ON m.source_lead_id = cs.lead_id";

    $active = $db->prepare("SELECT COUNT(*) $joins WHERE cs.status='active' AND $scope");
    $active->execute($args);

    $waiting = $db->prepare("SELECT COUNT(*) $joins WHERE cs.status='waiting' AND $scope");
    $waiting->execute($args);

    // "unread" = active chats whose latest message is from the visitor (i.e. awaiting an agent reply)
    $unread = $db->prepare("SELECT COUNT(*) $joins WHERE cs.status='active' AND $scope
                             AND (SELECT sender FROM chat_messages cm WHERE cm.session_id=cs.id ORDER BY id DESC LIMIT 1) = 'visitor'");
    $unread->execute($args);

    $missed = $db->prepare("SELECT COUNT(*) $joins WHERE cs.status='missed' AND $scope");
    $missed->execute($args);

    $closedToday = $db->prepare("SELECT COUNT(*) $joins WHERE cs.status='closed' AND $scope AND cs.closed_at >= CURDATE()");
    $closedToday->execute($args);

    $closedTotal = $db->prepare("SELECT COUNT(*) $joins WHERE cs.status='closed' AND $scope");
    $closedTotal->execute($args);

    return [
        'active' => (int) $active->fetchColumn(),
        'waiting' => (int) $waiting->fetchColumn(),
        'unread' => (int) $unread->fetchColumn(),
        'missed' => (int) $missed->fetchColumn(),
        'closed_today' => (int) $closedToday->fetchColumn(),
        'closed_total' => (int) $closedTotal->fetchColumn()
    ];
}

/* Customer detail strip for the chat header (name/phone/email/company) — the session itself
   only carries name/phone/email; company comes from the linked lead, if any. */
if ($action === 'session_info') {
    $sid = (int) ($_GET['session_id'] ?? 0);
    // assist_type/subject come from the crm_tickets row this chat was opened for (chatbot-ticket.php
    // sets it from what the customer picked in the widget: service / transaction / callback / sales),
    // so the agent sees what the customer is here for right in the header. Fall back to the ticket
    // linked via lead_id for older sessions predating the chat_sessions.ticket_id link.
    $s = $db->prepare("SELECT cs.visitor_name, cs.visitor_phone, cs.visitor_email, cs.vertical, l.company,
                              ct.assist_type, ct.subject
                        FROM chat_sessions cs
                        LEFT JOIN leads l ON l.id = cs.lead_id
                        LEFT JOIN crm_tickets ct ON ct.id = COALESCE(cs.ticket_id,
                            (SELECT id FROM crm_tickets WHERE lead_id = cs.lead_id ORDER BY id DESC LIMIT 1))
                        WHERE cs.id=?");
    $s->execute([$sid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Chat not found', 404);
    if (!chat_session_can_read($row)) fail('Not authorized to view this chat', 403);
    ok($row);
}

if ($action === 'inbox') {
    // a waiting chat nobody claimed in time becomes 'missed' — checked on every inbox load
    chat_sweep_missed($db);
    // an active chat the visitor went silent on for 5+ min after the agent's last message
    // auto-closes the same way — checked here too so the inbox reflects it promptly
    chat_sweep_inactive_active($db);

    // NB: validate against the coalesced value, not a second raw $_GET[...] read — when the
    // param is absent, $_GET['filter']/$_GET['field'] on the "valid" branch evaluates to null
    // (not the 'active'/'name' default), and chat_search_sql()/chat_inbox_list() both declare
    // $field as a non-nullable `string` param, so a missing ?field= (the normal case — the
    // Live Chat page's loadInbox() never sends one) fataled this whole endpoint with a
    // TypeError on every single load, for every role/vertical.
    $filter = $_GET['filter'] ?? 'active';
    if (!in_array($filter, ['active', 'missed', 'closed'], true)) $filter = 'active';
    $q = trim($_GET['q'] ?? '');
    $field = $_GET['field'] ?? 'name';
    if (!in_array($field, ['name', 'phone', 'company', 'date'], true)) $field = 'name';
    $dateVal = trim($_GET['date'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per = max(1, min(50, (int) ($_GET['per'] ?? 10)));

    $waiting = [];
    $active = $missed = $closed = ['rows' => [], 'total' => 0, 'page' => 1, 'per' => $per, 'pages' => 1];
    if ($filter === 'active') {
        // waiting chats are unclaimed — visible to any free agent in the same vertical (Sales/Service)
        $wargs = [];
        $wscope = vertical_scope('cs.vertical', $wargs);
        $wsearch = chat_search_sql($field, $q, $dateVal, 'started_at', $wargs);
        $ws = $db->prepare("SELECT cs.id,cs.visitor_name,cs.visitor_phone,cs.started_at,cs.last_activity_at,cs.lead_id, l.company,
                                    (SELECT body FROM chat_messages cm WHERE cm.session_id=cs.id AND sender='visitor' ORDER BY id DESC LIMIT 1) last_msg
                             FROM chat_sessions cs LEFT JOIN leads l ON l.id = cs.lead_id
                             WHERE cs.status='waiting' AND $wscope$wsearch ORDER BY cs.started_at");
        $ws->execute($wargs);
        $waiting = $ws->fetchAll(PDO::FETCH_ASSOC);
        // active chats: scoped by effective owner (merchant handover-aware), same rule as WhatsApp threads
        $active = chat_inbox_list($db, 'active', 'picked_at', $q, $page, $per, $field, $dateVal);
    } elseif ($filter === 'missed') {
        $missed = chat_inbox_list($db, 'missed', 'started_at', $q, $page, $per, $field, $dateVal);
    } elseif ($filter === 'closed') {
        $closed = chat_inbox_list($db, 'closed', 'closed_at', $q, $page, $per, $field, $dateVal);
    }

    ok(['waiting' => $waiting, 'active' => $active, 'missed' => $missed, 'closed' => $closed, 'counts' => chat_inbox_counts($db)]);
}
if ($action === 'pickup') {
    // Managers are supervisory-only in chat (see chat_session_can_write) — picking up a chat
    // would make them its owner without ever letting them actually write to it, a dead end.
    if (in_array(current_role(), ['manager', 'service_manager'], true)) {
        fail('Managers can only view chats — use Assign to hand this to an agent', 403);
    }
    $sid = (int) (body()['session_id'] ?? 0);
    $chk = $db->prepare("SELECT vertical, ticket_id FROM chat_sessions WHERE id=?");
    $chk->execute([$sid]);
    $sessRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$sessRow)
        fail('Chat not found', 404);
    assert_can_see_vertical($sessRow['vertical']);   // sales_exec can't pick up a Service chat and vice versa
    // Only a genuinely unclaimed ('waiting') chat can be picked up this way — a 'missed' chat is
    // read-only (remark/close only, see ?action=missed_remark/missed_close) once it's expired.
    $up = $db->prepare("UPDATE chat_sessions SET status='active', agent_id=?, picked_at=NOW() WHERE id=? AND status='waiting'");
    $up->execute([$me, $sid]);
    if (!$up->rowCount())
        fail('Already picked up by someone else', 409);
    $nm = $db->prepare("SELECT full_name FROM employees WHERE id=?");
    $nm->execute([$me]);
    $name = $nm->fetchColumn() ?: null;
    chat_push($db, $sid, 'system', ($name ?: 'An agent') . ' joined the chat');
    // The ticket this chat was opened for (chatbot-ticket.php) is a separate alarm/queue
    // (tickets.php's unclaimed-ticket bell) — claim it here too, so accepting the chat is a
    // single action that silences both, instead of leaving the ticket to ring on its own.
    if (!empty($sessRow['ticket_id'])) {
        $db->prepare("UPDATE crm_tickets SET owner_id=?, assigned_to_name=? WHERE id=? AND owner_id IS NULL")
            ->execute([$me, $name, (int) $sessRow['ticket_id']]);
        // Accepting the chat is the moment work actually starts on the issue, so advance it
        // New Issues -> Pending on the Service pipeline (sales-pipeline-service.php). Kept as a
        // separate statement from the claim above: the claim is guarded by `owner_id IS NULL`
        // (first-to-click-wins), but a ticket that was already owned — e.g. transferred to this
        // agent, then picked up — still needs to move back into Pending. Only ever moves it
        // FORWARD from open/transferred: a ticket the agent already resolved must not silently
        // reopen, and closing a chat deliberately does NOT resolve the ticket (the agent moves
        // that card to Resolved themselves).
        $db->prepare("UPDATE crm_tickets SET status='in_progress' WHERE id=? AND status IN ('open','transferred')")
            ->execute([(int) $sessRow['ticket_id']]);
    }
    ok(['ok' => true]);
}
/* List of operators a chat can be transferred to, with live online/offline status (green/red
   dot in the UI) — "online" mirrors automation_vertical_has_online_agent()'s 90s heartbeat
   window. Defaults to the caller's own dept; pass ?vertical= explicitly to cross-team (e.g.
   building a "transfer to Sales" picker), or ?vertical=all for every team at once. */
if ($action === 'agents') {
    $reqVertical = $_GET['vertical'] ?? '';
    $vertical = in_array($reqVertical, ['sales', 'service'], true) ? $reqVertical
        : ($reqVertical === 'all' ? 'all' : current_vertical());
    $roles = $vertical === 'service' ? "'service_exec','service_manager','admin'"
        : ($vertical === 'sales' ? "'sales_exec','manager','admin'" : "'sales_exec','service_exec','manager','service_manager','admin'");
    ok($db->query("SELECT DISTINCT e.id, e.full_name, su.role,
                   (su.last_heartbeat_at IS NOT NULL AND su.last_heartbeat_at > NOW() - INTERVAL 90 SECOND) AS is_online
                   FROM sales_users su JOIN employees e ON e.id=su.employee_id
                   WHERE su.is_active=1 AND su.role IN($roles) AND e.id <> " . (int) current_employee_id() . "
                   ORDER BY is_online DESC, e.full_name")->fetchAll());
}
/* dept manager/admin: assign a waiting chat, or REASSIGN an active one to another operator
   *within the same dept*. First assignment announces the agent to the visitor; reassignment
   is SILENT (no visitor message). Cross-dept handoff goes through ?action=transfer instead. */
if ($action === 'assign') {
    if (!can_manage_all_records())
        fail('Only a manager/admin can assign chats', 403);
    $b = body();
    $sid = (int) ($b['session_id'] ?? 0);
    $agentId = (int) ($b['agent_id'] ?? 0);
    if (!$sid || !$agentId)
        fail('session_id and agent_id required');
    $chk = $db->prepare("SELECT vertical, agent_id, lead_id FROM chat_sessions WHERE id=?");
    $chk->execute([$sid]);
    $prev = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$prev)
        fail('Chat not found', 404);
    $vertical = $prev['vertical'];
    assert_can_see_vertical($vertical);
    assert_can_assign_owner($agentId);   // dept manager can't hand it to the other dept's rep this way
    $r = chat_assign_to($db, $sid, $agentId);
    if ($r === null)
        fail('Chat not found or already closed', 410);
    chat_log_handoff($db, $sid, 'assign', $prev['agent_id'], $agentId, $vertical, $vertical, $prev['lead_id']);
    ok(['ok' => true, 'reassigned' => $r['reassigned']]);
}
/*
 * Cross-dept handoff: the exec/manager currently on a chat sends it to a specific agent in
 * the OTHER dept. This is a full case handoff — the underlying lead's owner and vertical move
 * to the receiving agent/team too (chat visibility resolves through lead ownership, so a partial
 * "just reassign the chat" wouldn't actually let the receiving agent see it). The linked ticket
 * moves with it. Available to whoever currently owns the chat (or a manager/admin), not just
 * admin — e.g. a service_exec transferring a live chat to a sales_exec.
 */
if ($action === 'transfer') {
    $b = body();
    $sid = (int) ($b['session_id'] ?? 0);
    $agentId = (int) ($b['agent_id'] ?? 0);
    if (!$sid || !$agentId)
        fail('session_id and agent_id required');
    $s = $db->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $s->execute([$sid]);
    $sess = $s->fetch(PDO::FETCH_ASSOC);
    if (!$sess)
        fail('Chat not found', 404);
    if ($sess['status'] === 'closed')
        fail('Chat is already closed', 410);
    if ($sess['status'] === 'missed')
        fail('A missed chat can only be closed or given a remark — pick it up first via another chat if it needs a full handoff', 409);
    if (!chat_session_can_write($db, $sess))
        fail('Only the current owner (or a manager/admin) can transfer this chat', 403);

    $tgt = $db->prepare("SELECT su.role, e.full_name FROM sales_users su JOIN employees e ON e.id=su.employee_id
                          WHERE su.employee_id=? AND su.is_active=1 LIMIT 1");
    $tgt->execute([$agentId]);
    $target = $tgt->fetch(PDO::FETCH_ASSOC);
    if (!$target)
        fail('Unknown or inactive agent', 400);
    $targetVertical = $target['role'] === 'service_exec' ? 'service' : ($target['role'] === 'sales_exec' ? 'sales' : null);
    if ($targetVertical === null)
        fail('Chats can only be transferred to a Sales Exec or Service Exec', 400);

    $db->beginTransaction();
    try {
        chat_assign_to($db, $sid, $agentId);
        $db->prepare("UPDATE chat_sessions SET vertical=? WHERE id=?")->execute([$targetVertical, $sid]);
        if ($sess['lead_id']) {
            $db->prepare("UPDATE leads SET owner_id=?, vertical=? WHERE id=?")->execute([$agentId, $targetVertical, $sess['lead_id']]);
            $db->prepare("UPDATE crm_tickets SET vertical=?, waiting_on=?, owner_id=?, assigned_to_name=? WHERE lead_id=?")
                ->execute([$targetVertical, $targetVertical === 'service' ? 'service_team' : 'sales_team', $agentId, $target['full_name'], $sess['lead_id']]);
        }
        // Park the issue in the Service pipeline's Transferred column (sales-pipeline-service.php)
        // until the receiving agent picks the chat up, which pulls it back into Pending (see
        // ?action=pickup). Keyed off chat_sessions.ticket_id — the exact ticket this chat was
        // opened for (phase33 link, and what pickup uses) — in preference to lead_id, because a
        // lead can own SEVERAL tickets and only this chat's issue is being handed over; keying on
        // lead_id alone would also mark that customer's unrelated tickets as transferred, and
        // would skip a session that has a ticket but no lead entirely. lead_id stays as the
        // fallback for sessions predating the ticket_id link. Deliberately outside the lead_id
        // branch above. Resolved/closed are left alone: a later handoff must not drag a finished
        // issue back onto the board.
        if (!empty($sess['ticket_id'])) {
            $db->prepare("UPDATE crm_tickets SET status='transferred' WHERE id=? AND status NOT IN ('resolved','closed')")
                ->execute([(int) $sess['ticket_id']]);
        } elseif ($sess['lead_id']) {
            $db->prepare("UPDATE crm_tickets SET status='transferred' WHERE lead_id=? AND status NOT IN ('resolved','closed')")
                ->execute([$sess['lead_id']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail('Transfer failed: ' . $e->getMessage(), 500);
    }
    chat_log_handoff($db, $sid, 'transfer', $sess['agent_id'], $agentId, $sess['vertical'], $targetVertical, $sess['lead_id']);
    chat_push($db, $sid, 'system', 'Transferred to ' . $target['full_name'] . ' (' . ucfirst($targetVertical) . ' team)');
    ok(['ok' => true, 'vertical' => $targetVertical]);
}
/* chat auto-assign policy (dept manager/admin) */
if ($action === 'settings') {   // GET returns; POST saves auto_assign
    if (method() === 'POST') {
        if (!can_manage_all_records())
            fail('Manager/admin only', 403);
        $db->prepare("UPDATE chat_settings SET auto_assign=? WHERE id=1")->execute([!empty(body()['auto_assign']) ? 1 : 0]);
    }
    ok(['auto_assign' => (int) chat_auto_assign_on($db)]);
}
/* distribute waiting chats round-robin to the next available operators (own dept only for a dept manager) */
if ($action === 'distribute') {
    if (!can_manage_all_records())
        fail('Manager/admin only', 403);
    $wargs = [];
    $wscope = vertical_scope('vertical', $wargs);
    $waiting = $db->prepare("SELECT id, vertical FROM chat_sessions WHERE status='waiting' AND $wscope ORDER BY started_at");
    $waiting->execute($wargs);
    $n = 0;
    foreach ($waiting->fetchAll(PDO::FETCH_ASSOC) as $w) {
        $ag = chat_next_agent($db, $w['vertical'] ?: 'sales');
        if (!$ag)
            continue;
        if (chat_assign_to($db, (int) $w['id'], $ag))
            $n++;
    }
    ok(['assigned' => $n]);
}
if ($action === 'a_post') {
    $b = body();
    $sid = (int) ($b['session_id'] ?? 0);
    $chk = $db->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $chk->execute([$sid]);
    $s = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$s || !chat_session_can_read($s))
        fail('not your chat', 403);
    if (!chat_session_can_write($db, $s))
        fail('View only — this chat is currently assigned to another agent', 403);
    if (trim($b['body'] ?? '') === '')
        fail('empty');
    chat_push($db, $sid, 'agent', trim($b['body']));
    ok(['ok' => true]);
}
// if ($action === 'a_poll') {
//     $sid = (int)($_GET['session_id'] ?? 0); $since = (int)($_GET['since'] ?? 0);
//     $st = $db->prepare("SELECT * FROM chat_sessions WHERE id=?"); $st->execute([$sid]); $sess = $st->fetch(PDO::FETCH_ASSOC);
//     if (!$sess || !chat_session_can_read($sess)) fail('not your chat', 403);
//     $m = $db->prepare("SELECT id,sender,body,attachment_url,attachment_type,created_at FROM chat_messages WHERE session_id=? AND id>? ORDER BY id");
//     $m->execute([$sid, $since]);
//     $canWrite = chat_session_can_write($db, $sess);
//     $ownerId = chat_effective_owner($db, $sess['lead_id'] ?? null, $sess['agent_id'] ?? null);
//     $ownerName = null;
//     if ($ownerId) { $nm = $db->prepare("SELECT full_name FROM employees WHERE id=?"); $nm->execute([$ownerId]); $ownerName = $nm->fetchColumn() ?: null; }
//     ok(['messages' => $m->fetchAll(), 'status' => $sess['status'], 'can_write' => $canWrite, 'owner_name' => $ownerName]);
// }
if ($action === 'a_poll') {

    $sid = (int) ($_GET['session_id'] ?? 0);
    $since = (int) ($_GET['since'] ?? 0);


    /* =========================
       GET CHAT SESSION
       ========================= */

    $st = $db->prepare("
        SELECT *
        FROM chat_sessions
        WHERE id = ?
        LIMIT 1
    ");

    $st->execute([$sid]);

    $sess = $st->fetch(PDO::FETCH_ASSOC);


    if (!$sess || !chat_session_can_read($sess)) {
        fail('not your chat', 403);
    }


    /* =========================
       GET CHAT MESSAGES
       ========================= */

    $m = $db->prepare("
        SELECT
            id,
            sender,
            body,
            attachment_url,
            attachment_type,
            created_at
        FROM chat_messages
        WHERE session_id = ?
          AND id > ?
        ORDER BY id
    ");

    $m->execute([
        $sid,
        $since
    ]);

    $messages = $m->fetchAll(PDO::FETCH_ASSOC);


    /* =========================
   GET EXACT TICKET ATTACHMENT
   ========================= */
    $ticketAttachment = null;
    $ticketId = (int) ($sess['ticket_id'] ?? 0);
    if ($ticketId > 0) {
        $att = $db->prepare("
        SELECT
            id,
            attachment_name,
            attachment_type
        FROM crm_tickets
        WHERE id = ?
          AND attachment_data IS NOT NULL
          AND attachment_name IS NOT NULL
        LIMIT 1
    ");
        $att->execute([$ticketId]);
        $attachment = $att->fetch(PDO::FETCH_ASSOC);
        if ($attachment) {
            $ticketAttachment = [
                'id' => (int) $attachment['id'],
                'name' =>
                    $attachment['attachment_name'],
                'type' =>
                    $attachment['attachment_type'],
                'url' =>
                    'api/sales/chat-ticket-attachment.php?id=' .
                    (int) $attachment['id']
            ];
        }
    }


    /* =========================
       CHAT PERMISSIONS
       ========================= */

    $canWrite = chat_session_can_write(
        $db,
        $sess
    );


    $ownerId = chat_effective_owner(
        $db,
        $sess['lead_id'] ?? null,
        $sess['agent_id'] ?? null
    );


    $ownerName = null;


    if ($ownerId) {

        $nm = $db->prepare("
            SELECT full_name
            FROM employees
            WHERE id = ?
        ");

        $nm->execute([$ownerId]);

        $ownerName =
            $nm->fetchColumn() ?: null;
    }


    /* =========================
       RESPONSE
       ========================= */

    ok([
        'messages' =>
            $messages,

        'ticket_attachment' =>
            $ticketAttachment,

        'status' =>
            $sess['status'],

        'missed_reason' =>
            $sess['missed_reason'] ?? null,

        'can_write' =>
            $canWrite,

        'owner_name' =>
            $ownerName
    ]);
}
if ($action === 'a_summarize') {
    $sid = (int) (body()['session_id'] ?? 0);
    $s = $db->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $s->execute([$sid]);
    $sess = $s->fetch(PDO::FETCH_ASSOC);
    if (!$sess)
        fail('Chat not found', 404);
    // Summarizing is a read-level action — any teammate who can view the chat may ask for one.
    if (!chat_session_can_read($sess))
        fail('Not authorized', 403);
    $m = $db->prepare("SELECT sender,body FROM chat_messages WHERE session_id=? AND sender IN('visitor','agent') ORDER BY id");
    $m->execute([$sid]);
    $rows = $m->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows)
        fail('No messages to summarize yet', 400);
    $transcript = implode("\n", array_map(fn($r) => ($r['sender'] === 'agent' ? 'Agent' : 'Visitor') . ': ' . $r['body'], $rows));
    $prompt = "Summarize this live chat conversation in 2-4 plain sentences for a sales agent. " .
        "Respond with plain prose only — no JSON, no markdown, no field labels.\n\n" . $transcript;
    $r = crm_ai_call($prompt, 'short', 'summarize');
    if (!$r)
        fail('No AI endpoint answered (box down and no fallback)', 502);
    ok(['summary' => $r['response'], 'source' => $r['source']]);
}
/* Missed tab is read-only for the normal composer — the only two actions available on a missed
   chat are leaving an internal remark (e.g. "called the customer, no answer") and closing it out.
   Any teammate who can read the chat (same vertical, or admin) may do either — a missed chat has
   no current owner to gate writes on. */
if ($action === 'missed_remark') {
    $b = body();
    $sid = (int) ($b['session_id'] ?? 0);
    $s = $db->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $s->execute([$sid]);
    $sess = $s->fetch(PDO::FETCH_ASSOC);
    if (!$sess || !chat_session_can_read($sess))
        fail('Not authorized', 403);
    if ($sess['status'] !== 'missed')
        fail('This chat is no longer in Missed', 409);
    if (trim($b['body'] ?? '') === '')
        fail('empty');
    $mid = chat_push($db, $sid, 'note', trim($b['body']), $me);
    ok(['ok' => true, 'id' => $mid]);
}
/* Edit a previously-saved remark — only its own author (or admin) may edit it, so Priya can
   fix her own note but never touch Zoya's, and vice versa. */
if ($action === 'edit_remark') {
    $b = body();
    $mid = (int) ($b['message_id'] ?? 0);
    $newBody = trim($b['body'] ?? '');
    if ($newBody === '') fail('empty');
    $m = $db->prepare("SELECT cm.*, cs.vertical FROM chat_messages cm JOIN chat_sessions cs ON cs.id=cm.session_id WHERE cm.id=?");
    $m->execute([$mid]);
    $row = $m->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['sender'] !== 'note') fail('Remark not found', 404);
    if (!chat_session_can_read(['vertical' => $row['vertical']])) fail('Not authorized', 403);
    if (current_role() !== 'admin' && (int) $row['created_by'] !== $me) {
        fail('You can only edit your own remark', 403);
    }
    $db->prepare("UPDATE chat_messages SET body=?, edited_at=NOW() WHERE id=?")->execute([$newBody, $mid]);
    ok(['ok' => true]);
}
if ($action === 'missed_close') {
    $sid = (int) (body()['session_id'] ?? 0);
    $s = $db->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $s->execute([$sid]);
    $sess = $s->fetch(PDO::FETCH_ASSOC);
    if (!$sess || !chat_session_can_read($sess))
        fail('Not authorized', 403);
    if ($sess['status'] !== 'missed')
        fail('This chat is no longer in Missed', 409);
    $db->prepare("UPDATE chat_sessions SET status='closed', closed_at=NOW() WHERE id=?")->execute([$sid]);
    $nm = $db->prepare("SELECT full_name FROM employees WHERE id=?");
    $nm->execute([$me]);
    chat_push($db, $sid, 'system', 'Chat closed from Missed by ' . ($nm->fetchColumn() ?: 'an agent'));
    ok(['ok' => true]);
}
if ($action === 'close') {
    $sid = (int) (body()['session_id'] ?? 0);
    $s = $db->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $s->execute([$sid]);
    $sess = $s->fetch(PDO::FETCH_ASSOC);
    if (!$sess || !chat_session_can_write($db, $sess))
        fail('View only — this chat is currently assigned to another agent', 403);
    $start = strtotime($sess['picked_at'] ?: $sess['started_at']);
    $dur = max(0, time() - $start);
    $db->prepare("UPDATE chat_sessions SET status='closed', closed_at=NOW(), duration_sec=? WHERE id=?")->execute([$dur, $sid]);
    chat_push($db, $sid, 'system', 'Chat closed by agent');
    // auto-log the time + touchpoint
    $mc = $db->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id=?");
    $mc->execute([$sid]);
    auto_log_activity(
        $db,
        $me,
        'chat',
        'Live chat with ' . ($sess['visitor_name'] ?: 'visitor') . ' (' . (int) $mc->fetchColumn() . ' msgs, ' . round($dur / 60, 1) . ' min)',
        $sess['lead_id'] ? (int) $sess['lead_id'] : null,
        $dur
    );
    ok(['duration_sec' => $dur]);
}

fail('Unknown action', 400);
