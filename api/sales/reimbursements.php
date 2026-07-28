<?php
/**
 * Sales reimbursements (travel / stay / food …). A claim is always linked to a lead
 * that has NOT yet converted to a merchant.
 *   GET  reimbursements.php                      -> list + summary (own for reps, all for mgr/admin)
 *   GET  reimbursements.php?leads=1              -> eligible (non-merchant) leads for the claim dropdown
 *   POST reimbursements.php  {lead_id,category,amount,expense_date,description,receipt_ref}  -> submit
 *   POST reimbursements.php?approve=ID | ?reject=ID | ?pay=ID   -> managers/admin only
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
require_module('reimbursements');
$db = db();
$canManage = in_array(current_role(), ['admin', 'manager'], true);
$meEmp = (int)(current_employee_id() ?: -1);

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

/* ---- analysis: who claimed how much (managers & admin only) ---- */
if (method() === 'GET' && isset($_GET['analysis'])) {
    if (!$canManage) fail('Managers only', 403);
    $w = ['1']; $a = [];
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

/* ---- approve / reject / pay (managers & admin only) ---- */
if (method() === 'POST' && (isset($_GET['approve']) || isset($_GET['reject']) || isset($_GET['pay']))) {
    if (!$canManage) fail('Only managers can approve or pay reimbursements', 403);
    if (isset($_GET['approve'])) {
        $id = (int)$_GET['approve'];
        $db->prepare("UPDATE reimbursements SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND status='submitted'")
           ->execute([$meEmp ?: null, $id]);
        ok(['id' => $id, 'status' => 'approved']);
    }
    if (isset($_GET['reject'])) {
        $id = (int)$_GET['reject']; $b = body();
        $db->prepare("UPDATE reimbursements SET status='rejected', approved_by=?, approved_at=NOW(), remarks=? WHERE id=? AND status='submitted'")
           ->execute([$meEmp ?: null, substr($b['remarks'] ?? '', 0, 500), $id]);
        ok(['id' => $id, 'status' => 'rejected']);
    }
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
    $db->prepare("INSERT INTO reimbursements (employee_id,lead_id,category,amount,expense_date,description,receipt_ref,status)
                  VALUES (?,?,?,?,?,?,?, 'submitted')")
       ->execute([$meEmp, $leadId, $cat, $amount, $b['expense_date'] ?: date('Y-m-d'),
                  substr($b['description'] ?? '', 0, 500), substr($b['receipt_ref'] ?? '', 0, 255) ?: null]);
    ok(['id' => (int)$db->lastInsertId()], ['message' => 'Claim submitted']);
}

/* ---- list + summary ---- */
if (method() === 'GET') {
    $where = ['1']; $args = [];
    if (!$canManage) { $where[] = 'r.employee_id=?'; $args[] = $meEmp; }
    elseif (!empty($_GET['employee'])) { $where[] = 'r.employee_id=?'; $args[] = (int)$_GET['employee']; }
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
    ok($rows->fetchAll(PDO::FETCH_ASSOC), [
        'total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $per),
        'summary' => [
            'cnt' => (int)($s['cnt'] ?? 0), 'total' => (float)($s['total'] ?? 0),
            'pending' => (float)($s['pending'] ?? 0), 'approved' => (float)($s['approved'] ?? 0),
            'paid' => (float)($s['paid'] ?? 0), 'can_manage' => $canManage,
        ],
    ]);
}

fail('Unsupported method', 405);
