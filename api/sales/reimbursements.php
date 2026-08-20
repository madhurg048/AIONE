<?php
/**
 * Sales reimbursements (travel / stay / food …). A claim is always linked to a lead
 * that has NOT yet converted to a merchant.
 *
 * Two-stage hierarchy approval (added phase79):
 *   Stage 1 (mgr_*)  — the requester's Sales Manager (for a Sales Exec's claim) OR the
 *                       Sales Head (for anyone's claim) may act — whichever goes first settles it.
 *                       A Sales Manager only ever sees/acts on their OWN reports' claims, never
 *                       a peer manager's or their own.
 *   Stage 2 (hr_*)   — HR Head / HR Manager, sequential: only unlocks once stage 1 = approved.
 *   Final `status`   — 'approved' only once BOTH stages are approved; either stage rejecting
 *                       flips it straight to 'rejected'. 'submitted' = still pending somewhere.
 *   Admin            — READ-ONLY everywhere in this file: sees every claim + the analysis
 *                       tiles, but the approve/reject/pay actions below all refuse 'admin'.
 *
 *   GET  reimbursements.php                      -> list + summary (hierarchy-scoped, see visibleScope())
 *   GET  reimbursements.php?leads=1              -> eligible (non-merchant) leads for the claim dropdown
 *   POST reimbursements.php  {lead_id,category,amount,expense_date,description,receipt_ref}  -> submit
 *   POST reimbursements.php?mgr_approve=ID | ?mgr_reject=ID   -> stage 1 (manager or sales_head)
 *   POST reimbursements.php?hr_approve=ID  | ?hr_reject=ID    -> stage 2 (hr_head / hr_manager)
 *   POST reimbursements.php?pay=ID                            -> hr_head/hr_manager/sales_head, once approved
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
require_module('reimbursements');
$db = db();
$role = current_role();
$meEmp = (int)(current_employee_id() ?: -1);

$isAdmin      = $role === 'admin';                                    // read-only
$isSalesHead  = $role === 'sales_head';                                // stage 1, whole Sales dept
$isManager    = $role === 'manager';                                   // stage 1, own reports only
$isHR         = in_array($role, ['hr_head', 'hr_manager'], true);      // stage 2 + pay
// "Can see more than just their own claims" — admin, sales_head, manager (own reports), HR.
$canViewExtra = $isAdmin || $isSalesHead || $isManager || $isHR;
// "Can take an accept/deny action anywhere in the flow" — everyone above EXCEPT admin.
$canAct       = $isSalesHead || $isManager || $isHR;

/** Recompute the overall status from the two stage columns after any approve/reject write. */
function reimb_recompute(PDO $db, int $id): string
{
    $r = $db->prepare("SELECT status, mgr_status, hr_status FROM reimbursements WHERE id=?");
    $r->execute([$id]);
    $row = $r->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'submitted';
    if ($row['status'] === 'paid') return 'paid';               // already settled, don't reopen
    if ($row['mgr_status'] === 'rejected' || $row['hr_status'] === 'rejected') $new = 'rejected';
    elseif ($row['mgr_status'] === 'approved' && $row['hr_status'] === 'approved') $new = 'approved';
    else $new = 'submitted';
    if ($new !== $row['status']) {
        $db->prepare("UPDATE reimbursements SET status=? WHERE id=?")->execute([$new, $id]);
    }
    return $new;
}

// SQL: lead has not yet become a merchant (via source_lead_id or its won deal)
const NOT_MERCHANT = "NOT EXISTS (SELECT 1 FROM merchants m
    WHERE m.source_lead_id=l.id OR (l.converted_deal_id IS NOT NULL AND m.deal_id=l.converted_deal_id))";

/* ---- eligible leads for the current rep (not yet a merchant) ---- */
if (method() === 'GET' && isset($_GET['leads'])) {
    $scope = owner_scope_inline('l.owner_id');
    $rows = $db->query("SELECT l.id, l.first_name, l.last_name, l.company, l.lead_status
        FROM leads l WHERE $scope AND " . NOT_MERCHANT . "
        ORDER BY l.company, l.last_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    ok($rows);
}

/** Employee-scope WHERE fragment (on r.employee_id) for the current role. Appends bound args. */
function reimb_employee_scope(string $role, int $meEmp, bool $isManager, bool $isSalesHead, bool $isHR, bool $isAdmin, array &$args): string
{
    if ($isAdmin || $isSalesHead || $isHR) return '1';   // whole Sales dept (module is Sales-only)
    if ($isManager) {
        $ids = array_values(array_unique(array_merge([$meEmp], manager_subtree_ids($meEmp))));
        $args = array_merge($args, $ids);
        return 'r.employee_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    }
    $args[] = $meEmp;
    return 'r.employee_id=?';
}

/* ---- analysis: who claimed how much (managers, sales_head, HR, admin) ---- */
if (method() === 'GET' && isset($_GET['analysis'])) {
    if (!$canViewExtra) fail('Managers, Sales Head, HR or Admin only', 403);
    $w = ['1']; $a = [];
    $w[] = reimb_employee_scope($role, $meEmp, $isManager, $isSalesHead, $isHR, $isAdmin, $a);
    if (!empty($_GET['from'])) { $w[] = 'r.expense_date>=?'; $a[] = $_GET['from']; }
    if (!empty($_GET['to']))   { $w[] = 'r.expense_date<=?'; $a[] = $_GET['to']; }
    $wsql = implode(' AND ', $w);
    $byRep = $db->prepare("SELECT r.employee_id, COALESCE(e.full_name, CONCAT('#', r.employee_id)) AS name, e.designation,
        COUNT(*) cnt, COALESCE(SUM(amount),0) total,
        COALESCE(SUM(CASE WHEN status='submitted' THEN amount ELSE 0 END),0) pending,
        COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) approved,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) paid
        FROM reimbursements r LEFT JOIN employees e ON e.id=r.employee_id
        WHERE $wsql GROUP BY r.employee_id ORDER BY total DESC");
    $byRep->execute($a);
    $byCat = $db->prepare("SELECT category, COUNT(*) cnt, COALESCE(SUM(amount),0) total
        FROM reimbursements r WHERE $wsql GROUP BY category ORDER BY total DESC");
    $byCat->execute($a);
    ok(['by_rep' => $byRep->fetchAll(PDO::FETCH_ASSOC), 'by_category' => $byCat->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ---- stage 1: manager (own reports only) or sales_head (whole Sales dept) ---- */
if (method() === 'POST' && (isset($_GET['mgr_approve']) || isset($_GET['mgr_reject']))) {
    if ($isAdmin) fail('Admin has read-only access to reimbursements', 403);
    if (!$isManager && !$isSalesHead) fail('Only a Sales Manager or Sales Head can act on this stage', 403);
    $id = (int)($_GET['mgr_approve'] ?? $_GET['mgr_reject']);
    $row = $db->prepare("SELECT employee_id, mgr_status FROM reimbursements WHERE id=?"); $row->execute([$id]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Not found', 404);
    if ((int)$r['employee_id'] === $meEmp) fail('You cannot approve your own claim', 403);
    if ($r['mgr_status'] !== 'pending') fail('This claim has already been actioned at this stage', 400);
    // A Sales Manager may only act on their own direct/indirect reports' claims (never a peer
    // manager's, never their own) — matches the same reporting-line scope the rest of the CRM uses.
    if ($isManager && !in_array((int)$r['employee_id'], manager_subtree_ids($meEmp), true)) {
        fail('Not authorized — this claim is not from your reporting line', 403);
    }
    $status = isset($_GET['mgr_approve']) ? 'approved' : 'rejected';
    $b = body();
    $db->prepare("UPDATE reimbursements SET mgr_status=?, mgr_by=?, mgr_at=NOW(), remarks=? WHERE id=?")
       ->execute([$status, $meEmp, $status === 'rejected' ? substr($b['remarks'] ?? '', 0, 500) : null, $id]);
    ok(['id' => $id, 'mgr_status' => $status, 'status' => reimb_recompute($db, $id)]);
}

/* ---- stage 2: HR Head / HR Manager — sequential, only once stage 1 is approved ---- */
if (method() === 'POST' && (isset($_GET['hr_approve']) || isset($_GET['hr_reject']))) {
    if (!$isHR) fail('Only HR can act on this stage', 403);
    $id = (int)($_GET['hr_approve'] ?? $_GET['hr_reject']);
    $row = $db->prepare("SELECT employee_id, mgr_status, hr_status FROM reimbursements WHERE id=?"); $row->execute([$id]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r) fail('Not found', 404);
    if ((int)$r['employee_id'] === $meEmp) fail('You cannot approve your own claim', 403);
    if ($r['mgr_status'] !== 'approved') fail('Waiting on Sales Manager/Sales Head approval first', 400);
    if ($r['hr_status'] !== 'pending') fail('HR has already actioned this claim', 400);
    $status = isset($_GET['hr_approve']) ? 'approved' : 'rejected';
    $b = body();
    $db->prepare("UPDATE reimbursements SET hr_status=?, hr_by=?, hr_at=NOW(), remarks=? WHERE id=?")
       ->execute([$status, $meEmp, $status === 'rejected' ? substr($b['remarks'] ?? '', 0, 500) : null, $id]);
    $final = reimb_recompute($db, $id);
    if ($final === 'approved') {
        $db->prepare("UPDATE reimbursements SET approved_by=?, approved_at=NOW() WHERE id=?")->execute([$meEmp, $id]);
    }
    ok(['id' => $id, 'hr_status' => $status, 'status' => $final]);
}

/* ---- mark paid: HR / Sales Head only, once fully approved (never admin, never plain manager) ---- */
if (method() === 'POST' && isset($_GET['pay'])) {
    if (!$isHR && !$isSalesHead) fail('Only HR or Sales Head can mark a claim as paid', 403);
    $id = (int)$_GET['pay'];
    $db->prepare("UPDATE reimbursements SET status='paid', paid_at=NOW() WHERE id=? AND status='approved'")->execute([$id]);
    ok(['id' => $id, 'status' => 'paid']);
}

/* ---- submit a claim ---- */
if (method() === 'POST') {
    $b = body();
    $leadId = (int)($b['lead_id'] ?? 0);
    $amount = (float)($b['amount'] ?? 0);
    if (!$leadId) fail('Pick the lead this expense was for');
    if ($amount <= 0) fail('Amount must be greater than 0');
    // the lead must be visible to the rep AND not yet a merchant
    $chk = $db->prepare("SELECT l.owner_id FROM leads l WHERE l.id=? AND " . NOT_MERCHANT);
    $chk->execute([$leadId]); $own = $chk->fetchColumn();
    if ($own === false) fail('That lead has already become a merchant — reimbursements can only be claimed against a lead not yet converted to a merchant', 400);
    assert_can_see($own);   // reps may only claim against their own leads
    $cat = in_array($b['category'] ?? '', ['travel','stay','food','fuel','other'], true) ? $b['category'] : 'travel';
    // A Sales Head's own claim has no one above them on the Sales side to do stage 1 (and they
    // can't approve their own) — it skips straight to HR, whose accept alone then makes it final.
    $skipStage1 = $isSalesHead;
    $db->prepare("INSERT INTO reimbursements (employee_id,lead_id,category,amount,expense_date,description,receipt_ref,status,mgr_status)
                  VALUES (?,?,?,?,?,?,?, 'submitted', ?)")
       ->execute([$meEmp, $leadId, $cat, $amount, $b['expense_date'] ?: date('Y-m-d'),
                  substr($b['description'] ?? '', 0, 500), substr($b['receipt_ref'] ?? '', 0, 255) ?: null,
                  $skipStage1 ? 'approved' : 'pending']);
    ok(['id' => (int)$db->lastInsertId()], ['message' => 'Claim submitted']);
}

/* ---- list + summary ---- */
if (method() === 'GET') {
    $where = ['1']; $args = [];
    $where[] = reimb_employee_scope($role, $meEmp, $isManager, $isSalesHead, $isHR, $isAdmin, $args);
    if ($canViewExtra && !empty($_GET['employee'])) { $where[] = 'r.employee_id=?'; $args[] = (int)$_GET['employee']; }
    if (!empty($_GET['status'])) { $where[] = 'r.status=?'; $args[] = $_GET['status']; }
    if (!empty($_GET['from']))   { $where[] = 'r.expense_date>=?'; $args[] = $_GET['from']; }
    if (!empty($_GET['to']))     { $where[] = 'r.expense_date<=?'; $args[] = $_GET['to']; }
    $base = "FROM reimbursements r
             LEFT JOIN employees e ON e.id=r.employee_id
             LEFT JOIN leads l ON l.id=r.lead_id
             WHERE " . implode(' AND ', $where);
    $sm = $db->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) total,
        COALESCE(SUM(CASE WHEN status='submitted' THEN amount ELSE 0 END),0) pending,
        COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) approved,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) paid $base");
    $sm->execute($args); $s = $sm->fetch(PDO::FETCH_ASSOC) ?: [];
    $per = max(1, min(200, (int)($_GET['per'] ?? 25)));
    $page = max(1, (int)($_GET['page'] ?? 1)); $off = ($page - 1) * $per;
    $cnt = $db->prepare("SELECT COUNT(*) $base"); $cnt->execute($args); $total = (int)$cnt->fetchColumn();
    $rows = $db->prepare("SELECT r.*, e.full_name AS employee_name,
        TRIM(CONCAT(COALESCE(l.first_name,''),' ',COALESCE(l.last_name,''))) AS lead_name,
        l.company AS lead_company
        $base ORDER BY r.created_at DESC LIMIT $per OFFSET $off");
    $rows->execute($args);
    $reports = $isManager ? manager_subtree_ids($meEmp) : [];
    $data = array_map(function ($r) use ($meEmp, $isManager, $isSalesHead, $isHR, $reports) {
        $mine = (int)$r['employee_id'] === $meEmp;
        $r['can_mgr_act'] = !$mine && $r['mgr_status'] === 'pending'
            && (($isManager && in_array((int)$r['employee_id'], $reports, true)) || $isSalesHead);
        $r['can_hr_act']  = !$mine && $isHR && $r['mgr_status'] === 'approved' && $r['hr_status'] === 'pending';
        $r['can_pay']     = ($isHR || $isSalesHead) && $r['status'] === 'approved';
        return $r;
    }, $rows->fetchAll(PDO::FETCH_ASSOC));
    ok($data, [
        'total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $per),
        'summary' => [
            'cnt' => (int)($s['cnt'] ?? 0), 'total' => (float)($s['total'] ?? 0),
            'pending' => (float)($s['pending'] ?? 0), 'approved' => (float)($s['approved'] ?? 0),
            'paid' => (float)($s['paid'] ?? 0),
            'can_view_extra' => $canViewExtra, 'is_admin' => $isAdmin, 'is_hr' => $isHR,
        ],
    ]);
}

fail('Unsupported method', 405);
