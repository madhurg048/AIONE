<?php
/**
 * Service → Sales lead handoff.
 *
 * A service exec takes a call that turns out to be a SALES enquiry. Rather than telling the
 * caller to ring a different number, they capture it here from the Requests page and either:
 *
 *   action=save  — park it as an unassigned draft. The lead exists but stays vertical='service',
 *                  so it does NOT surface on the Sales Leads list and no sales exec is put on
 *                  the hook for it yet. The service exec can finish gathering details and send
 *                  it later.
 *   action=send  — hand it to Sales: flips vertical to 'sales' and round-robins an owner.
 *
 * The CRM- ticket ref is allocated at CREATE time for both actions (not at send time) —
 * the ref is the service exec's handle on the request from the moment they take the call, and
 * the shared sequence must reflect when the enquiry actually arrived, not when it was forwarded.
 *
 * Prefix is CRM (vs CHAT for chatbot, SLS for a sales-created lead) off the one shared per-year
 * counter — see next_ticket_ref_and_insert() in _ticket_ref.php. On the Requests page a CRM ref
 * is what marks the row as a lead rather than a chatbot/whatsapp/call request.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_automation_engine.php';
require_login();

$db = getDB();

/**
 * Freeze the handoff onto the ticket at the moment it's actually sent — who on Service's side
 * sent it, and who on Sales' side it landed on. Read back later by the Generated Leads list
 * instead of the live leads.owner_id join, so a later Sales-side reassignment can't silently
 * rewrite what Service's own record of the handoff shows (see phase48 migration).
 */
function service_lead_record_transfer(PDO $db, int $ticketId, ?array $owner): void
{
    $byId = current_employee_id() ?: null;
    $byName = null;
    if ($byId) {
        $nm = $db->prepare("SELECT full_name FROM employees WHERE id=?");
        $nm->execute([$byId]);
        $byName = $nm->fetchColumn() ?: null;
    }
    $db->prepare("UPDATE crm_tickets SET transferred_by_id=?, transferred_by_name=?,
                  transferred_to_id=?, transferred_to_name=?, transferred_at=NOW() WHERE id=?")
       ->execute([$byId, $byName, $owner['id'] ?? null, $owner['name'] ?? null, $ticketId]);
}

/*
 * ---------------- GENERATED LEADS (Service's own audit trail) ----------------
 * GET service-lead.php?list=1
 *
 * Every lead this Service team has ever captured here, whether still a parked draft
 * (vertical='service') or already handed to Sales (vertical='sales') — keyed off
 * lead_source='service_referral', which only this file ever writes, so a lead's
 * presence here survives the vertical flip that made it disappear from the Requests
 * list (sales-requests-service.php is vertical-scoped and only shows vertical='service'
 * rows). Team-wide, like every other list in this app (see leads.php/tickets.php) —
 * not owner-scoped — so any Service exec can see what the whole team has generated
 * and, crucially, exactly who in Sales a transferred lead landed on.
 */
if (method() === 'GET' && ($_GET['list'] ?? '') === '1') {
    require_module('genleads');
    $where = ["l.lead_source = 'service_referral'"];
    $args = [];
    if (!empty($_GET['q'])) {
        $where[] = "(l.first_name LIKE ? OR l.last_name LIKE ? OR l.company LIKE ? OR ct.ticket_ref LIKE ?)";
        $like = '%' . $_GET['q'] . '%';
        array_push($args, $like, $like, $like, $like);
    }
    if (($_GET['status'] ?? '') === 'draft')       $where[] = "l.vertical = 'service'";
    if (($_GET['status'] ?? '') === 'transferred') $where[] = "l.vertical = 'sales'";
    $wsql = implode(' AND ', $where);

    $per  = max(1, min(100, (int)($_GET['per'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $off  = ($page - 1) * $per;

    $joined = "FROM leads l
               LEFT JOIN crm_tickets ct ON ct.lead_id = l.id AND ct.source = 'service_lead'
               LEFT JOIN employees creator ON creator.id = l.created_by
               WHERE $wsql";

    $cnt = $db->prepare("SELECT COUNT(*) $joined");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();

    // assigned_to below is the FROZEN snapshot from the moment of transfer (ct.transferred_to_*,
    // see phase48 migration) — not a live join to leads.owner_id — so a later Sales-side
    // reassignment doesn't silently rewrite what Service's own record of the handoff shows.
    $sql = "SELECT l.id AS lead_id, l.first_name, l.last_name, l.company, l.email, l.mobile, l.phone,
                   l.vertical, l.created_at AS lead_created_at, l.created_by,
                   creator.full_name AS created_by_name,
                   ct.transferred_by_id, ct.transferred_by_name,
                   ct.transferred_to_id, ct.transferred_to_name AS assigned_to_name,
                   ct.transferred_at,
                   ct.id AS ticket_id, ct.ticket_ref, ct.subject, ct.status AS ticket_status,
                   ct.priority, ct.created_at AS ticket_created_at
            $joined
            ORDER BY COALESCE(ct.created_at, l.created_at) DESC
            LIMIT $per OFFSET $off";
    $rows = $db->prepare($sql);
    $rows->execute($args);

    ok($rows->fetchAll(PDO::FETCH_ASSOC), [
        'total' => $total, 'page' => $page, 'per' => $per, 'pages' => (int)ceil($total / $per),
    ]);
}

if (method() !== 'POST' && method() !== 'PATCH') fail('POST or PATCH required', 405);

$b = body();
$action = $_GET['action'] ?? ($b['action'] ?? 'save');
if (!in_array($action, ['save', 'send'], true)) fail('Unknown action');

/* ---------------- CREATE (Save draft, or Save+Send) ---------------- */
if (method() === 'POST') {
    if (empty($b['last_name']) && empty($b['company'])) fail('Name or company required');

    $subject = trim($b['subject'] ?? '') ?: 'Sales enquiry (via Service)';
    $company = $b['company'] ?? null;
    $email   = $b['email'] ?? null;
    $mobile  = $b['mobile'] ?? ($b['phone'] ?? null);

    // A draft stays in the service vertical so it can't leak onto the Sales Leads list before
    // it's actually been sent (Sales Leads is scoped team-wide by vertical, not by owner).
    $vertical = $action === 'send' ? 'sales' : 'service';

    $score = lead_completeness_score([
        'email' => $email, 'mobile' => $mobile, 'company' => $company,
        'title' => $b['title'] ?? null, 'industry' => $b['industry'] ?? null,
        'lead_source' => 'service_referral',
    ]);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO leads
            (first_name,last_name,company,title,email,phone,mobile,lead_source,vertical,lead_status,
             industry,score,owner_id,description,created_by)
            VALUES (?,?,?,?,?,?,?,'service_referral',?,'new',?,?,NULL,?,?)")
           ->execute([
               $b['first_name'] ?? null, $b['last_name'] ?? ($company ?: 'Lead'), $company,
               $b['title'] ?? null, $email, $b['phone'] ?? null, $mobile, $vertical,
               $b['industry'] ?? null, $score, $b['description'] ?? null,
               current_employee_id() ?: null,
           ]);
        $leadId = (int)$db->lastInsertId();

        $snapName = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: null;
        [$ticketRef, $ticketId] = next_ticket_ref_and_insert($db, 'CRM', function ($ticketRef)
            use ($db, $b, $leadId, $subject, $snapName, $company, $email, $mobile, $action, $vertical) {
            // Identity is snapshotted onto the ticket (phase45) so a later edit to the shared
            // lead can't retroactively rewrite what this request row shows.
            $db->prepare("INSERT INTO crm_tickets
                (ticket_ref, source, vertical, subject, status, waiting_on, priority, lead_id,
                 customer_name, customer_company, customer_email, customer_mobile, last_update_note)
                VALUES (?, 'service_lead', ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   $ticketRef, $vertical, $subject,
                   $action === 'send' ? 'sales_team' : 'us',
                   $b['priority'] ?? 'medium', $leadId,
                   $snapName, $company, $email, $mobile, $b['description'] ?? null,
               ]);
            return (int)$db->lastInsertId();
        });

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Automations fire only once the lead is genuinely Sales' problem — a parked draft must not
    // trigger welcome emails / assignment rules for an enquiry that hasn't been forwarded yet.
    // Outside the transaction and non-fatal by contract (see _automation_engine.php).
    $fired = [];
    $owner = null;
    if ($action === 'send') {
        // Assign BEFORE firing automations, so rule 1's "notify the owner" reaches the exec who
        // actually got the lead (the record it reads must already carry the owner).
        $owner = sales_assign_next($db, $leadId, $ticketId, 'sales');
        service_lead_record_transfer($db, $ticketId, $owner);
        $row = $db->prepare("SELECT * FROM leads WHERE id=?");
        $row->execute([$leadId]);
        $fired = run_automations($db, 'lead', 'on_create', $row->fetch() ?: []);
    }

    ok(['id' => $leadId, 'ticket_id' => $ticketId, 'ticket_ref' => $ticketRef,
        'sent' => $action === 'send', 'owner' => $owner],
       ['automations' => $fired]);
}

/* ---------------- SEND an existing draft ---------------- */
if (method() === 'PATCH') {
    $id = (int)($_GET['id'] ?? ($b['id'] ?? 0));
    if (!$id) fail('id required');
    if ($action !== 'send') fail('Only action=send is valid on an existing draft');

    $lead = $db->prepare("SELECT l.id, l.vertical, l.owner_id, ct.id AS ticket_id
                          FROM leads l LEFT JOIN crm_tickets ct ON ct.lead_id = l.id
                          WHERE l.id=? ORDER BY ct.id DESC LIMIT 1");
    $lead->execute([$id]);
    $row = $lead->fetch();
    if (!$row) fail('Lead not found', 404);
    // Already handed over — re-sending would round-robin a second owner onto a lead a rep is
    // already working, silently moving it out from under them.
    if ($row['vertical'] === 'sales') fail('This lead has already been sent to Sales');
    if (!$row['ticket_id']) fail('Draft has no request record', 409);

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE leads SET vertical='sales' WHERE id=?")->execute([$id]);
        $db->prepare("UPDATE crm_tickets SET vertical='sales', waiting_on='sales_team' WHERE id=?")
           ->execute([$row['ticket_id']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Assign before automations — see the POST path (rule 1 notifies "to owner").
    $owner = sales_assign_next($db, $id, (int)$row['ticket_id'], 'sales');
    service_lead_record_transfer($db, (int)$row['ticket_id'], $owner);
    $full = $db->prepare("SELECT * FROM leads WHERE id=?");
    $full->execute([$id]);
    $fired = run_automations($db, 'lead', 'on_create', $full->fetch() ?: []);

    ok(['id' => $id, 'sent' => true, 'owner' => $owner], ['automations' => $fired]);
}
