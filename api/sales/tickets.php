<?php
/**
 * ITSM / support tickets list — searchable, filterable, paginated.
 *   GET   tickets.php?q=&status=&priority=&waiting=&from=&to=&sort=&dir=&page=&per=
 *   POST  tickets.php?action=claim  {id}   -> first-to-click-wins self-assignment
 *   PATCH tickets.php?id=            {subject?,status?,priority?,waiting_on?,last_update_note?}
 * Team-wide within a vertical: every Sales exec/manager sees every Sales ticket, every
 * Service exec/manager sees every Service ticket (round robin only spreads Sales assignment;
 * Service/Transaction/Callback tickets stay unowned until claimed) — admin sees everything.
 * Editing follows the same team-wide rule as reads: any exec in the ticket's vertical may
 * edit it, not just the current owner (mirrors claim's "whole team can act on it" model).
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
require_module('tickets');
$db = db();

/* Cheap, filter-independent count for the header bell (see includes/sales-layout.php) — how
   many tickets in the caller's own vertical are still sitting unclaimed. Polled from every page,
   not just the Tickets list, so a Service exec sees the alert no matter where they are. */
if (method() === 'GET' && ($_GET['action'] ?? '') === 'unclaimed_count') {
    $args = [];
    $scope = vertical_scope('vertical', $args);
    $s = $db->prepare("SELECT COUNT(*) FROM crm_tickets WHERE owner_id IS NULL AND status NOT IN ('closed','resolved') AND $scope");
    $s->execute($args);
    ok(['unclaimed' => (int)$s->fetchColumn()]);
}

/*
 * Round-robin only ever assigns Sales tickets (see chatbot-ticket.php) — a Service/Transaction/
 * Callback ticket is deliberately left owner_id=NULL so every Service exec sees it, and whoever
 * clicks "Claim" first gets it (atomic UPDATE ... WHERE owner_id IS NULL, same first-wins pattern
 * as live chat's pickup()). Managers are supervisory-only here too, same rule as chat/WhatsApp.
 */
if (method() === 'POST' && ($_GET['action'] ?? '') === 'claim') {
    if (in_array(current_role(), ['manager', 'service_manager'], true)) {
        fail('Managers can only view tickets — assignment happens via Claim by an exec', 403);
    }
    $id = (int)(body()['id'] ?? 0);
    if (!$id) fail('id required');
    $t = $db->prepare("SELECT vertical, owner_id FROM crm_tickets WHERE id=?");
    $t->execute([$id]);
    $row = $t->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Ticket not found', 404);
    assert_can_see_vertical($row['vertical']);
    if ($row['owner_id'] !== null) fail('Already claimed by another agent', 409);
    $me = current_employee_id();
    $nm = $db->prepare("SELECT full_name FROM employees WHERE id=?"); $nm->execute([$me]);
    $name = $nm->fetchColumn() ?: null;
    $up = $db->prepare("UPDATE crm_tickets SET owner_id=?, assigned_to_name=? WHERE id=? AND owner_id IS NULL");
    $up->execute([$me, $name, $id]);
    if (!$up->rowCount()) fail('Already claimed by another agent', 409);
    ok(['ok' => true, 'assigned_to_name' => $name]);
}

/* Everyone with a login, any dept — for the Transfer picker. "Transfer to the concerned team
   or person" is explicitly cross-department (a Service ticket might actually belong to Sales,
   IT, or HR), so this deliberately isn't vertical-scoped like leads.php's owner-reassign list. */
if (method() === 'GET' && ($_GET['action'] ?? '') === 'transfer_targets') {
    ok($db->query("SELECT e.id, e.full_name, e.designation, su.role
                   FROM employees e JOIN sales_users su ON su.employee_id = e.id
                   WHERE su.is_active = 1 ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC));
}

/*
 * Kanban feed for the Service pipeline (sales-pipeline-service.php) — the same vertical-scoped
 * crm_tickets rows as the list below, but returned whole for client-side grouping into the
 * board's four stages instead of paginated. Deliberately NOT `ct.*`: that would pull in
 * ct.attachment_data (LONGBLOB), whose non-UTF-8 bytes make json_encode() return false and the
 * whole response come back empty — see the note on the list query at the bottom of this file.
 */
if (method() === 'GET' && ($_GET['board'] ?? '') === '1') {
    $args = [];
    $scope = vertical_scope('ct.vertical', $args);
    $sel = "SELECT ct.id, ct.ticket_ref, ct.subject, ct.status, ct.priority, ct.assigned_to_name,
                   ct.owner_id, ct.lead_id, ct.source, ct.assist_type, ct.created_at,
                   COALESCE(NULLIF(ct.customer_name,''),
                       TRIM(CONCAT(COALESCE(l.first_name,''),' ',COALESCE(l.last_name,'')))) AS customer_name,
                   COALESCE(ct.customer_company, l.company) AS customer_company,
                   COALESCE(
                       (SELECT cs.status FROM chat_sessions cs WHERE cs.ticket_id = ct.id ORDER BY cs.id DESC LIMIT 1),
                       (SELECT cs.status FROM chat_sessions cs WHERE cs.lead_id = ct.lead_id ORDER BY cs.id DESC LIMIT 1)
                   ) AS chat_status
            FROM crm_tickets ct
            LEFT JOIN leads l ON l.id = ct.lead_id
            WHERE $scope";
    // Unfinished work is shown in full — that's the point of the board.
    $open = $db->prepare("$sel AND ct.status NOT IN ('resolved','closed') ORDER BY CAST(SUBSTRING_INDEX(ct.ticket_ref, '-', -1) AS UNSIGNED) DESC");
    $open->execute($args);
    // Resolved grows without bound and would eventually bury the columns that need attention,
    // so cap it. The board is a work queue; the Requests list is the searchable archive.
    $done = $db->prepare("$sel AND ct.status IN ('resolved','closed') ORDER BY CAST(SUBSTRING_INDEX(ct.ticket_ref, '-', -1) AS UNSIGNED) DESC LIMIT 50");
    $done->execute($args);
    ok(array_merge($open->fetchAll(PDO::FETCH_ASSOC), $done->fetchAll(PDO::FETCH_ASSOC)));
}

/*
 * "Transfer to the concerned team or person" — reassign a ticket to a specific employee,
 * cross-vertical if needed (e.g. a Service agent discovers it's actually a Sales enquiry, or
 * a specialist elsewhere should take it). Unlike Claim (self-assign, unowned only), Transfer
 * lets the current owner (or a manager/admin) hand it to someone else by name.
 */
if (method() === 'POST' && ($_GET['action'] ?? '') === 'transfer') {
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $toEmp = (int)($b['to_employee_id'] ?? 0);
    if (!$id || !$toEmp) fail('id and to_employee_id required');
    $t = $db->prepare("SELECT * FROM crm_tickets WHERE id=?"); $t->execute([$id]);
    $row = $t->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Ticket not found', 404);
    assert_can_see_vertical($row['vertical']);
    $me = current_employee_id();
    if (!can_manage_all_records() && (int)$row['owner_id'] !== $me) {
        fail('Only the current owner (or a manager) can transfer this ticket', 403);
    }
    $emp = $db->prepare("SELECT e.full_name, su.role FROM employees e JOIN sales_users su ON su.employee_id = e.id
                         WHERE e.id=? AND su.is_active=1"); $emp->execute([$toEmp]);
    $target = $emp->fetch(PDO::FETCH_ASSOC);
    if (!$target) fail('Employee not found (or has no active login)', 404);
    $note = trim((string)($b['note'] ?? ''));
    $stampedNote = trim(($row['last_update_note'] ?? '') . "\n" . '[transferred to ' . $target['full_name'] . ($note ? ': ' . $note : '') . ']');
    // Follow the ticket's vertical to the new owner's own department, so they can actually see
    // it afterward (reads are vertical_scope()'d) — a cross-team handoff shouldn't strand the
    // ticket in a vertical the new owner has no visibility into.
    $newVertical = in_array($target['role'], ['sales_exec', 'manager'], true) ? 'sales'
                 : (in_array($target['role'], ['service_exec', 'service_manager'], true) ? 'service' : $row['vertical']);
    // A ticket handed to someone else parks in the Service pipeline's Transferred column
    // (sales-pipeline-service.php) — same end state as transferring the live chat itself
    // (chat.php ?action=transfer), so both routes to "someone else owns this now" look alike on
    // the board. An already-resolved/closed ticket keeps its status: re-routing a finished issue
    // for the record must not resurrect it as open work.
    $newStatus = in_array($row['status'], ['resolved', 'closed'], true) ? $row['status'] : 'transferred';
    $db->prepare("UPDATE crm_tickets SET owner_id=?, assigned_to_name=?, vertical=?, status=?, last_update_note=? WHERE id=?")
       ->execute([$toEmp, $target['full_name'], $newVertical, $newStatus, $stampedNote, $id]);
    ok(['id' => $id, 'assigned_to_name' => $target['full_name']], ['message' => 'Transferred to ' . $target['full_name']]);
}

if (method() === 'PATCH') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');
    $t = $db->prepare("SELECT vertical FROM crm_tickets WHERE id=?");
    $t->execute([$id]);
    $vertical = $t->fetchColumn();
    if ($vertical === false) fail('Ticket not found', 404);
    assert_can_see_vertical($vertical);

    $b = body();
    // 'transferred' is the Service pipeline's 4th stage (sales-pipeline-service.php); the rest
    // are the states the edit modal already offers. crm_tickets.status is a free-form
    // varchar(60), so nothing at the DB level stops a typo'd/unknown status being written and
    // then silently vanishing off the board (it'd match no column) — hence the whitelist here.
    if (array_key_exists('status', $b) && !in_array($b['status'],
        ['open', 'in_progress', 'waiting_customer', 'on_hold', 'resolved', 'transferred', 'closed'], true)) {
        fail('Unknown status');
    }
    $allowed = ['subject', 'status', 'priority', 'waiting_on', 'last_update_note'];
    $set = []; $args = [];
    foreach ($allowed as $f) if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = $b[$f]; }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE crm_tickets SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    ok(['id' => $id]);
}

$where = ['1']; $args = [];
$where[] = vertical_scope('ct.vertical', $args);
if (!empty($_GET['q'])) {
    $where[] = "(ct.ticket_ref LIKE ? OR ct.subject LIKE ? OR ct.assigned_to_name LIKE ?)";
    $q = '%' . $_GET['q'] . '%'; array_push($args, $q, $q, $q);
}
if (!empty($_GET['status']))   { $where[] = "ct.status = ?";     $args[] = $_GET['status']; }
if (!empty($_GET['priority'])) { $where[] = "ct.priority = ?";   $args[] = $_GET['priority']; }
if (!empty($_GET['waiting']))  { $where[] = "ct.waiting_on = ?"; $args[] = $_GET['waiting']; }
if (!empty($_GET['from']))     { $where[] = "ct.created_at >= ?"; $args[] = $_GET['from'] . ' 00:00:00'; }
if (!empty($_GET['to']))       { $where[] = "ct.created_at <= ?"; $args[] = $_GET['to'] . ' 23:59:59'; }

$base = "FROM crm_tickets ct
         LEFT JOIN merchants m ON m.id=ct.merchant_id
         LEFT JOIN leads l ON l.id=ct.lead_id
         WHERE " . implode(' AND ', $where);
$bindAll = fn() => $args;

/* summary over the filtered set */
$sm = $db->prepare("SELECT COUNT(*) total,
        COALESCE(SUM(ct.status NOT IN('closed','resolved')),0) open_cnt,
        COALESCE(SUM(ct.waiting_on='us'),0) waiting_us,
        COALESCE(SUM(ct.priority IN('urgent','high')),0) high $base");
$sm->execute($bindAll()); $s = $sm->fetch(PDO::FETCH_ASSOC) ?: [];

$per = max(1, min(200, (int)($_GET['per'] ?? 25)));
$page = max(1, (int)($_GET['page'] ?? 1));
$off = ($page - 1) * $per;
$cnt = $db->prepare("SELECT COUNT(*) $base"); $cnt->execute($bindAll()); $total = (int)$cnt->fetchColumn();
// ticket_ref is the customer-facing chat reference (PFX-<year>-<5-digit seq>, see
// next_ticket_ref_and_insert() in _bootstrap.php). It's zero-padded and allocated strictly in
// sequence, so sorting on the numeric suffix orders by "newest request first" — and unlike
// updated_at it doesn't reshuffle the list under an agent every time someone edits an old
// ticket. Hence: the default. Sort on the suffix rather than the raw string: the prefix now
// varies by source (CHAT vs SLS, sharing one counter), and a raw string compare would rank
// one prefix above the other regardless of actual creation order ('S' > 'C').
$ticketRefSort = "CAST(SUBSTRING_INDEX(ct.ticket_ref, '-', -1) AS UNSIGNED)";
$sortMap = ['ticket_ref'=>$ticketRefSort, 'created_at'=>'ct.created_at', 'updated_at'=>'ct.updated_at',
            'status'=>'ct.status', 'priority'=>'ct.priority'];
$sort = $sortMap[$_GET['sort'] ?? ''] ?? $ticketRefSort;
$dir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
// ct.* would pull in ct.attachment_data (LONGBLOB — the raw transaction screenshot/PDF bytes,
// see chat-ticket-attachment.php for the dedicated endpoint that actually serves it). Binary
// bytes aren't valid UTF-8, so json_encode() on the result silently returns false and ok()
// echoes nothing — a 200 response with an empty body, no PHP error, for every ticket in the
// list the moment even ONE row in it has an attachment. Explicit column list instead of ct.*.
// Full chat history (waiting/active/missed/closed), unlike leads.php's icon which deliberately
// hides 'closed' — Tickets is the audit trail, so all states are relevant here. Prefer the
// session opened for THIS exact ticket (chat_sessions.ticket_id, see phase33 migration); fall
// back to the lead's most recent session for tickets that predate that link or were opened by
// an agent manually starting a chat from the lead (no ticket_id set in that path).
$rows = $db->prepare("SELECT ct.id, ct.ticket_ref, ct.source, ct.vertical, ct.assist_type, ct.subject,
        ct.status, ct.assigned_to_name, ct.owner_id, ct.waiting_on, ct.priority, ct.merchant_id,
        ct.lead_id, ct.last_update_note, ct.updated_at, ct.created_at,
        ct.attachment_name, ct.attachment_type, (ct.attachment_data IS NOT NULL) AS has_attachment,
        m.legal_name AS merchant_name,
        COALESCE(NULLIF(ct.customer_name,''),
            TRIM(CONCAT(COALESCE(l.first_name,''),' ',COALESCE(l.last_name,'')))) AS customer_name,
        COALESCE(ct.customer_company, l.company) AS customer_company,
        COALESCE(ct.customer_email, l.email) AS customer_email,
        COALESCE(ct.customer_mobile, l.mobile, l.phone) AS customer_mobile,
        COALESCE(
            (SELECT cs.status FROM chat_sessions cs WHERE cs.ticket_id = ct.id ORDER BY cs.id DESC LIMIT 1),
            (SELECT cs.status FROM chat_sessions cs WHERE cs.lead_id = ct.lead_id ORDER BY cs.id DESC LIMIT 1)
        ) AS chat_status
        $base ORDER BY $sort $dir LIMIT $per OFFSET $off");
$rows->execute($bindAll());

ok($rows->fetchAll(PDO::FETCH_ASSOC), [
    'total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $per),
    'summary' => [
        'total' => (int)($s['total'] ?? 0), 'open' => (int)($s['open_cnt'] ?? 0),
        'waiting_us' => (int)($s['waiting_us'] ?? 0), 'high' => (int)($s['high'] ?? 0),
    ],
]);
