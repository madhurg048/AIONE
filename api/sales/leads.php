<?php
/**
 * Leads API — list (+filters/pagination/metrics), create, update.
 *   GET  leads.php?metrics=1                 -> funnel + source + agent metrics
 *   GET  leads.php?status=new&source=web&q=..&page=1&per=20
 *   GET  leads.php?id=5                       -> single lead + activities
 *   POST leads.php                            -> create
 *   PATCH leads.php?id=5                       -> update fields
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_automation_engine.php';
require_once __DIR__ . '/_task_events.php';
require_login();
$db = db();

/* ---------------- EMPLOYEES (for bulk owner assignment) ---------------- */
if (method() === 'GET' && isset($_GET['employees'])) {
    if (can_manage_all_records()) {
        // A dept MANAGER may assign only within their own line of control (self + reports).
        $vis = visible_owner_ids();
        if ($vis !== null) {
            $in = implode(',', array_map('intval', $vis));
            ok($db->query("SELECT id, full_name, designation FROM employees WHERE id IN ($in) ORDER BY full_name")->fetchAll());
        }
        // Admin / dept Head are unrestricted:
        $v = current_vertical();
        if ($v === null) {
            // true admin: everyone
            ok($db->query("SELECT id, full_name, designation FROM employees ORDER BY full_name")->fetchAll());
        }
        // a dept head sees/assigns within their own dept's reps
        $role = $v === 'service' ? 'service_exec' : 'sales_exec';
        $s = $db->prepare("SELECT e.id, e.full_name, e.designation
                           FROM employees e JOIN sales_users su ON su.employee_id = e.id
                           WHERE su.role = ? AND su.is_active = 1
                           ORDER BY e.full_name");
        $s->execute([$role]);
        ok($s->fetchAll());
    }
    $s = $db->prepare("SELECT id, full_name, designation FROM employees WHERE id=?");
    $s->execute([current_employee_id()]);
    ok($s->fetchAll());
}

/* ---------------- BOARD (lead Kanban: active, unconverted leads) ---------------- */
if (method() === 'GET' && isset($_GET['board'])) {
    $vsc = vertical_scope_inline('l.vertical');
    ok($db->query("SELECT l.id, l.first_name, l.last_name, l.company, l.score, l.rating,
                          l.lead_status, l.next_followup_at, e.full_name AS owner_name
                   FROM leads l LEFT JOIN employees e ON e.id=l.owner_id
                   WHERE l.is_converted=0 AND $vsc
                   ORDER BY l.updated_at DESC LIMIT 500")->fetchAll());
}

/* ---------------- BULK ACTIONS (group action from checkboxes) ---------------- */
if (method() === 'POST' && isset($_GET['bulk'])) {
    $b = body();
    // The Leads list is one row per TICKET now (see the GET list above), and a repeat visitor's
    // several tickets all share one lead id — so checking two rows for the same lead is a normal,
    // valid selection that legitimately sends the same lead id twice. array_unique so the
    // allowed-vs-requested count check below compares like for like (leads.id is the PK, so the
    // membership query naturally de-dupes on its side) instead of false-failing as "not accessible".
    $ids = array_values(array_unique(array_filter(array_map('intval', $b['ids'] ?? []))));
    $action = $b['action'] ?? '';
    if (!$ids) fail('No rows selected');
    $scopeArgs = [];
    // Team-wide within a vertical (see the GET list above) — a rep may bulk-act on any
    // lead in their own vertical, not just ones they personally own.
    $vscope = vertical_scope('vertical', $scopeArgs);
    $chk = $db->prepare("SELECT id, owner_id FROM leads WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND $vscope");
    $chk->execute(array_merge($ids, $scopeArgs));
    $allowedIds = array_map('intval', array_column($chk->fetchAll(), 'id'));
    if (count($allowedIds) !== count($ids)) fail('One or more leads are not accessible', 403);
    if ($action === 'owner') {
        assert_can_assign_owner((int)($b['value'] ?? 0));
    }
    $in = implode(',', array_fill(0, count($allowedIds), '?'));
    if ($action === 'status') {
        $st = $db->prepare("UPDATE leads SET lead_status=? WHERE id IN ($in)");
        $st->execute(array_merge([$b['value']], $allowedIds));
        ok(['updated' => $st->rowCount()]);
    }
    if ($action === 'owner') {
        $st = $db->prepare("UPDATE leads SET owner_id=? WHERE id IN ($in)");
        $st->execute(array_merge([(int)$b['value']], $allowedIds));
        ok(['updated' => $st->rowCount()]);
    }
    if ($action === 'followup_date') {
        $st = $db->prepare("UPDATE leads SET next_followup_at=? WHERE id IN ($in)");
        $st->execute(array_merge([$b['value']], $allowedIds));
        ok(['updated' => $st->rowCount()]);
    }
    if ($action === 'delete') {
        $st = $db->prepare("DELETE FROM leads WHERE id IN ($in)");
        $st->execute($allowedIds);
        ok(['deleted' => $st->rowCount()]);
    }
    fail('Unknown bulk action');
}

/* ---------------- METRICS (Lead metrics dashboard) ---------------- */
if (method() === 'GET' && isset($_GET['metrics'])) {
    $out = [];
    // Leads themselves are team-wide within a vertical (see the GET list above), so the
    // KPI strip/funnel/leaderboard-source numbers match what the list below actually shows —
    // only vertical-scoped, not owner-scoped. Deal/merchant sub-counts below stay owner-scoped
    // (that's a different module's row-level security, unaffected by this).
    $sc  = vertical_scope_inline('vertical');
    $scL = owner_scope_inline('l.owner_id');
    $scD = owner_scope_inline('d.owner_id');
    $scM = owner_scope_inline('owner_id');
    $scE = owner_scope_inline('e.id');           // employees the user may see (self for reps)

    // Funnel counts
    $out['funnel'] = [
        'leads'      => (int)$db->query("SELECT COUNT(*) FROM leads WHERE $sc")->fetchColumn(),
        'qualified'  => (int)$db->query("SELECT COUNT(*) FROM leads WHERE lead_status='qualified' AND $sc")->fetchColumn(),
        'converted'  => (int)$db->query("SELECT COUNT(*) FROM leads WHERE is_converted=1 AND $sc")->fetchColumn(),
        'open_deals' => (int)$db->query("SELECT COUNT(*) FROM deals d JOIN deal_stages s ON s.id=d.stage_id WHERE s.stage_type='open' AND $scD")->fetchColumn(),
        'merchants'  => (int)$db->query("SELECT COUNT(*) FROM merchants WHERE onboarding_stage='activated' AND $scM")->fetchColumn(),
    ];
    $lt = max(1, $out['funnel']['leads']);
    $out['conversion_rate'] = round($out['funnel']['converted'] * 100 / $lt, 1);

    // New leads this month
    $out['new_this_month'] = (int)$db->query(
        "SELECT COUNT(*) FROM leads WHERE DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') AND $sc"
    )->fetchColumn();

    // Source performance (leads vs converted per source)
    $out['by_source'] = $db->query("
        SELECT COALESCE(lead_source,'unknown') AS source,
               COUNT(*) AS total,
               SUM(is_converted) AS converted
        FROM leads WHERE $sc GROUP BY source ORDER BY total DESC LIMIT 12
    ")->fetchAll();

    // Status distribution
    $out['by_status'] = $db->query("
        SELECT lead_status AS status, COUNT(*) AS c FROM leads WHERE $sc GROUP BY lead_status
    ")->fetchAll();

    // Follow-ups due (overdue or due now, not converted)
    $out['due_followups'] = (int)$db->query("
        SELECT COUNT(*) FROM leads
        WHERE next_followup_at IS NOT NULL AND next_followup_at <= NOW() AND is_converted=0 AND $sc
    ")->fetchColumn();

    // Agent leaderboard (scoped: reps only see themselves)
    $out['leaderboard'] = $db->query("
        SELECT e.id, e.full_name,
               COUNT(l.id) AS leads,
               SUM(l.is_converted) AS converted,
               (SELECT COUNT(*) FROM merchants m WHERE m.owner_id=e.id AND m.onboarding_stage='activated') AS merchants
        FROM employees e
        LEFT JOIN leads l ON l.owner_id = e.id
        WHERE $scE
        GROUP BY e.id, e.full_name
        HAVING leads > 0 OR merchants > 0
        ORDER BY merchants DESC, converted DESC
        LIMIT 15
    ")->fetchAll();

    ok($out);
}

/* ---------------- SINGLE LEAD ---------------- */
if (method() === 'GET' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT l.*, e.full_name AS owner_name
                          FROM leads l LEFT JOIN employees e ON e.id=l.owner_id WHERE l.id=?");
    $stmt->execute([$id]);
    $lead = $stmt->fetch();
    if (!$lead) fail('Lead not found', 404);
    // Team-wide within a vertical (like tickets.php/chat.php): every Sales exec/manager
    // sees & can work every Sales lead, every Service exec/manager sees & can work every
    // Service lead — owner_id is who it's currently assigned to (shown in the Owner
    // column), not a visibility restriction. Admin sees everything.
    assert_can_see_vertical($lead['vertical']);   // sales_exec can't open service leads and vice versa

    $act = $db->prepare("SELECT * FROM activities WHERE related_type='lead' AND related_id=? ORDER BY created_at DESC");
    $act->execute([$id]);
    $lead['activities'] = $act->fetchAll();

    // Service → Sales handoff context: when a lead reaches Sales from the Service team (whether
    // captured as a generated lead or transferred as a ticket), the notes the Service exec wrote
    // live on the linked ticket's last_update_note (the transfer stamp and/or the "notes from the
    // call"). Surface the latest linked ticket so the Sales rep can click through and read exactly
    // what Service handed over — the same context Service had from the call / live chat / WhatsApp.
    $lead['handoff'] = null;
    try {
        $h = $db->prepare("SELECT ticket_ref, source, assist_type, subject, last_update_note,
                                  transferred_by_name, transferred_at, created_at
                             FROM crm_tickets
                             WHERE lead_id=? ORDER BY id DESC LIMIT 1");
        $h->execute([$id]);
        $hrow = $h->fetch(PDO::FETCH_ASSOC);
        // Only present it as a handoff when there's actually something to read (a note or a
        // recorded transfer) and the lead genuinely came from Service.
        if ($hrow && (trim((string)$hrow['last_update_note']) !== '' || $hrow['transferred_by_name'])
            && ($lead['lead_source'] === 'service_referral' || $hrow['source'] === 'service_lead' || $hrow['transferred_by_name'])) {
            $lead['handoff'] = $hrow;
        }
    } catch (Throwable $e) { /* non-fatal — handoff panel just won't show */ }

    ok($lead);
}

/* ---------------- LIST (filters + pagination) ----------------
 * One row per TICKET, not per lead: a repeat visitor within the 6h dedup window (chatbot-ticket.php)
 * reuses the same lead across several submissions, and each of those submissions is a distinct
 * request an exec needs to see and act on — collapsing them into a single row (showing only the
 * latest ticket ref) silently hid every earlier one. LEFT JOIN crm_tickets means a lead with zero
 * tickets (manual/web/whatsapp/etc. leads, most of them) still gets exactly one row, ct.* just NULL.
 * A lead with 3 tickets now produces 3 rows, sharing the lead's identity/status/owner columns but
 * each carrying its own ticket_ref/subject/chat_status — mirrors how Service's Requests/Tickets
 * pages are ticket-centric, and matches how leads.php?board=1 was never used for this page anyway.
 */
if (method() === 'GET') {
    $where = [];
    $args  = [];
    // Team-wide within a vertical — every Sales exec/manager sees every Sales lead, every
    // Service exec/manager sees every Service lead (owner_id is just who it's assigned to,
    // shown in the Owner column). Admin sees everything. See _bootstrap.php's vertical_scope().
    $where[] = vertical_scope('l.vertical', $args);
    if (!empty($_GET['status'])) { $where[] = 'l.lead_status = ?'; $args[] = $_GET['status']; }
    if (!empty($_GET['source'])) { $where[] = 'l.lead_source = ?'; $args[] = $_GET['source']; }
    if (!empty($_GET['owner']))  { $where[] = 'l.owner_id = ?';    $args[] = (int)$_GET['owner']; }
    if (!empty($_GET['due']))    { $where[] = 'l.next_followup_at IS NOT NULL AND l.next_followup_at <= NOW() AND l.is_converted=0'; }
    if (!empty($_GET['q'])) {
        $where[] = '(l.first_name LIKE ? OR l.last_name LIKE ? OR l.company LIKE ? OR l.email LIKE ? OR l.phone LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $joined = "FROM leads l LEFT JOIN crm_tickets ct ON ct.lead_id = l.id LEFT JOIN employees e ON e.id=l.owner_id $wsql";

    $total = (int)(function () use ($db, $joined, $args) {
        $s = $db->prepare("SELECT COUNT(*) $joined");
        $s->execute($args);
        return $s->fetchColumn();
    })();

    $per  = max(1, min(100, (int)($_GET['per'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $off  = ($page - 1) * $per;

    // safe sort (whitelist). 'ticket_ref' is a real joined column now (ct.ticket_ref) — same
    // "newest chat reference first" ordering the Service Requests/Tickets screens use; MySQL
    // sorts NULL last on DESC, so leads with no ticket fall below ticketed rows.
    // Every ticket_ref follows PFX-YYYY-NNNNN, but the prefix now varies by source (CHAT vs
    // SLS — see next_ticket_ref_and_insert() in _bootstrap.php), so a raw string sort on
    // ct.ticket_ref would permanently rank one prefix above the other regardless of actual
    // creation order ('S' > 'C'). Sort on the numeric suffix (shared across all prefixes)
    // instead, so newest-first genuinely means newest-first.
    // 'created_at' sorts on COALESCE(ct.created_at, l.created_at) — the same per-ticket
    // timestamp the Created column now displays (see display_created_at below); sorting on
    // the lead's own created_at instead would order rows by a date different from what's
    // actually shown for repeat-visitor tickets.
    $sortMap = ['created_at'=>'COALESCE(ct.created_at, l.created_at)', 'last_name'=>'l.last_name', 'company'=>'l.company',
                'lead_source'=>'l.lead_source', 'lead_status'=>'l.lead_status', 'score'=>'l.score',
                'next_followup_at'=>'l.next_followup_at',
                'ticket_ref'=>"CAST(SUBSTRING_INDEX(ct.ticket_ref, '-', -1) AS UNSIGNED)"];
    $sort = $sortMap[$_GET['sort'] ?? ''] ?? 'COALESCE(ct.created_at, l.created_at)';
    $dir  = (strtolower($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

    // Leads' live-chat icon is a "needs attention right now" signal, not a history viewer —
    // only surface waiting/active/missed. A 'closed' chat is done; showing its dot here just
    // reads as "a chat is happening" long after it isn't (full history, incl. closed, belongs
    // on the Tickets page instead — see tickets.php's chat_status join). Scoped to THIS row's
    // ticket (cs.ticket_id) so a repeat visitor's older ticket-rows don't all show the same
    // newest chat's status; falls back to lead_id only for the ticket-less row.
    // display_name/company/email/mobile prefer THIS ticket's own identity snapshot (frozen at
    // the moment that ticket/lead-edit was made — see phase45 migration) over the shared
    // lead's CURRENT identity. Leads is ticket-per-row (see the docstring above): a repeat
    // visitor's several tickets all share one lead_id, and the chatbot's repeat-visitor path
    // overwrites that shared lead row's name/company/email on every new submission — without
    // this override, l.first_name/last_name/company/email (from `SELECT l.*`) would make EVERY
    // one of that lead's past ticket-rows retroactively show the newest submission's identity,
    // which is exactly the "previous records keep changing" bug. Falls back to the live lead
    // fields for the ticket-less row / legacy tickets with no snapshot.
    $sql = "SELECT l.*, ct.id AS ticket_id, ct.ticket_ref, ct.status AS ticket_status,
                   ct.last_update_note AS handoff_note, ct.transferred_by_name AS handoff_by,
                   ct.source AS ticket_source,
                   COALESCE(ct.subject, NULL) AS ticket_subject,
                   COALESCE(ct.assigned_to_name, e.full_name) AS owner_name,
                   COALESCE(ct.created_at, l.created_at) AS display_created_at,
                   COALESCE(NULLIF(ct.customer_name,''),
                       TRIM(CONCAT(COALESCE(l.first_name,''),' ',COALESCE(l.last_name,'')))) AS display_name,
                   COALESCE(ct.customer_company, l.company) AS display_company,
                   COALESCE(ct.customer_email, l.email) AS display_email,
                   COALESCE(ct.customer_mobile, l.mobile, l.phone) AS display_mobile,
                   (SELECT cs.status FROM chat_sessions cs
                    WHERE ((ct.id IS NOT NULL AND cs.ticket_id = ct.id) OR (ct.id IS NULL AND cs.lead_id = l.id))
                      AND cs.status IN ('waiting','active','missed')
                    ORDER BY cs.id DESC LIMIT 1) AS chat_status
            $joined ORDER BY $sort $dir, l.id DESC LIMIT $per OFFSET $off";
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    ok($stmt->fetchAll(), ['total' => $total, 'page' => $page, 'per' => $per,
                            'pages' => (int)ceil($total / $per)]);
}

/* ---------------- CREATE ---------------- */
if (method() === 'POST') {
    $b = body();
    if (empty($b['last_name']) && empty($b['company'])) fail('Name or company required');
    // An explicitly-chosen owner is honoured; otherwise the lead goes to the next exec in the
    // shared rotation (below, once the ticket exists) rather than defaulting to whoever typed
    // it in — a manually-added enquiry is queue work like any other, and the rotation is what
    // keeps the split even across every source (CHAT/SLS/CRM).
    $explicitOwner = isset($b['owner_id']) && (int)$b['owner_id'] > 0;
    $owner = $explicitOwner ? (int)$b['owner_id'] : (int)current_employee_id();
    assert_can_assign_owner($owner);
    $vertical = current_vertical() ?? (in_array($b['vertical'] ?? '', ['sales','service'], true) ? $b['vertical'] : 'sales');
    $stmt = $db->prepare("INSERT INTO leads
        (first_name,last_name,company,title,email,phone,mobile,lead_source,vertical,lead_status,industry,rating,owner_id,description,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $b['first_name'] ?? null, $b['last_name'] ?? ($b['company'] ?? 'Lead'),
        $b['company'] ?? null, $b['title'] ?? null, $b['email'] ?? null,
        $b['phone'] ?? null, $b['mobile'] ?? null, $b['lead_source'] ?? 'manual', $vertical,
        $b['lead_status'] ?? 'new', $b['industry'] ?? null, $b['rating'] ?? null,
        $owner ?: null, $b['description'] ?? null, current_employee_id() ?: null
    ]);
    $newId = (int)$db->lastInsertId();

    // Create ticket in crm_tickets if a subject is provided. Shares the CHAT-2026-NNNNN
    // sequence (just a different 3-letter prefix, 'SLS') so this ticket takes its true place
    // in the newest-first tickets queue instead of camping at the top forever — see
    // next_ticket_ref_and_insert() in _bootstrap.php.
    if (!empty($b['subject'])) {
        // Snapshot the customer's identity as of THIS submission — see phase45 migration:
        // a later edit to the lead's name must not retroactively change what this ticket shows.
        $snapName = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: null;
        [, $newTicketId] = next_ticket_ref_and_insert($db, 'SLS', function ($ticketRef) use ($db, $b, $newId, $snapName, $vertical) {
            $db->prepare("INSERT INTO crm_tickets (ticket_ref, source, vertical, subject, status, lead_id,
                              customer_name, customer_company, customer_email, customer_mobile, last_update_note)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$ticketRef, 'manual', $vertical, $b['subject'], 'open', $newId,
                          $snapName, $b['company'] ?? null, $b['email'] ?? null,
                          $b['mobile'] ?? ($b['phone'] ?? null), $b['description'] ?? null]);
            return (int)$db->lastInsertId();
        });
    }

    // auto-score on create (data completeness + source quality) unless the caller set one —
    // gives every new lead a triage score instead of leaving it at 0.
    if (!isset($b['score']) || $b['score'] === '' || (int)$b['score'] === 0) {
        $db->prepare("UPDATE leads SET score=? WHERE id=?")
           ->execute([lead_completeness_score($b), $newId]);
    }

    // Round-robin unless an owner was explicitly chosen. Assignment lives here rather than in
    // the 'Round-robin assign new leads' rule (which now only notifies) so it can't be turned
    // off by toggling a rule — and it keeps the SLS ticket's owner in step with the lead's,
    // which the rule could never do (it only ever touched the lead, leaving every SLS ticket
    // showing no owner at all). See sales_assign_next() in _automation_engine.php.
    $owner = null;
    if (!$explicitOwner) {
        $owner = sales_assign_next($db, $newId, $newTicketId ?? null, $vertical);
    } elseif (!empty($newTicketId)) {
        $nm = $db->prepare("SELECT full_name FROM employees WHERE id=?");
        $nm->execute([$owner]);
        $db->prepare("UPDATE crm_tickets SET owner_id=?, assigned_to_name=? WHERE id=?")
           ->execute([$owner, $nm->fetchColumn() ?: null, $newTicketId]);
    }

    // Fire on_create automations AFTER assignment — rule 1 notifies "to owner", so the record
    // it reads must already carry the owner.
    $row = $db->prepare("SELECT * FROM leads WHERE id=?");
    $row->execute([$newId]);
    $fired = run_automations($db, 'lead', 'on_create', $row->fetch() ?: []);

    ok(['id' => $newId, 'owner' => $owner], ['automations' => $fired]);
}

/* ---------------- UPDATE ---------------- */
if (method() === 'PATCH' || method() === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');
    $b = body();
    // Team-wide within a vertical (see the GET list above) — any rep in the same vertical
    // may work/update the lead, not just its current owner.
    $current = $db->prepare("SELECT id, owner_id, vertical FROM leads WHERE id=?");
    $current->execute([$id]);
    $lead = $current->fetch();
    if (!$lead) fail('Lead not found', 404);
    assert_can_see_vertical($lead['vertical']);
    if (array_key_exists('owner_id', $b)) {
        assert_can_assign_owner((int)$b['owner_id']);
    }
    if (array_key_exists('vertical', $b) && !can_manage_all_records()) fail('Not authorized to change vertical', 403);
    $allowed = ['first_name','last_name','company','title','email','phone','mobile',
                'lead_source','vertical','lead_status','industry','rating','owner_id','description','score'];
    $set = []; $args = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f = ?"; $args[] = $b[$f]; }
    }
    if (!$set && !array_key_exists('subject', $b)) fail('Nothing to update');
    if ($set) {
        $args[] = $id;
        $db->prepare("UPDATE leads SET " . implode(',', $set) . " WHERE id = ?")->execute($args);
    }

    // Update/create ticket if subject is provided in update
    if (array_key_exists('subject', $b)) {
        $stmtTicket = $db->prepare("SELECT id FROM crm_tickets WHERE lead_id=? ORDER BY created_at DESC LIMIT 1");
        $stmtTicket->execute([$id]);
        $ticketId = $stmtTicket->fetchColumn();
        if ($ticketId) {
            $db->prepare("UPDATE crm_tickets SET subject=? WHERE id=?")->execute([$b['subject'], $ticketId]);
        } elseif (!empty($b['subject'])) {
            // Snapshot the customer's identity as it stands right now — see phase45 migration:
            // a later edit to the lead's name must not retroactively change what this ticket shows.
            $snap = $db->prepare("SELECT first_name, last_name, company, email, mobile, phone FROM leads WHERE id=?");
            $snap->execute([$id]);
            $sl = $snap->fetch() ?: [];
            $snapName = trim(($sl['first_name'] ?? '') . ' ' . ($sl['last_name'] ?? '')) ?: null;
            // Shares the CHAT-2026-NNNNN sequence under prefix 'SLS' — see
            // next_ticket_ref_and_insert() in _bootstrap.php.
            next_ticket_ref_and_insert($db, 'SLS', function ($ticketRef) use ($db, $b, $id, $sl, $snapName) {
                $db->prepare("INSERT INTO crm_tickets (ticket_ref, source, subject, status, lead_id,
                                  customer_name, customer_company, customer_email, customer_mobile)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$ticketRef, 'manual', $b['subject'], 'open', $id,
                              $snapName, $sl['company'] ?? null, $sl['email'] ?? null,
                              $sl['mobile'] ?? ($sl['phone'] ?? null)]);
            });
        }
    }

    $row = $db->prepare("SELECT * FROM leads WHERE id=?");
    $row->execute([$id]);
    $fired = run_automations($db, 'lead', 'on_update', $row->fetch() ?: []);

    // system event -> auto task when the sales status changes
    if (array_key_exists('lead_status', $b)) on_lead_status_changed($db, $id, $b['lead_status']);

    ok(['id' => $id], ['automations' => $fired]);
}

fail('Unsupported method', 405);
