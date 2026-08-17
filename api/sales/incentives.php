<?php
/**
 * Incentives API.
 *   GET  incentives.php?summary=1           -> totals by status + this-month payout
 *   GET  incentives.php?leaderboard=1        -> per-agent earned/paid
 *   GET  incentives.php?employee=5&status=accrued&page=1  -> ledger rows
 *   POST incentives.php?approve=12           -> approve one ledger row   (managers/admin only)
 *   POST incentives.php?pay=12               -> mark paid                (managers/admin only)
 *   POST incentives.php?bulk=approve&period=2026-07  -> approve all accrued for a period
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
require_module('incentives');
$db = db();

/* Approve/pay/bulk-approve/bulk-pay are manager/admin-only: a sales_exec must never
   approve or pay any incentive, including their own — this is sensitive payout data,
   not a self-service action. Reps still see their OWN ledger rows in every read below. */
$canManage = can_manage_all_records();
if (method() === 'POST' && !$canManage) fail('Only managers can approve or pay incentives', 403);
$meEmp     = (int)(current_employee_id() ?: -1);
// Row scope follows the hierarchy: a Head/Admin sees the whole ledger, a Manager sees only
// their own line of control (self + reports), an exec only their own. owner_scope_inline()
// returns '1' for the unrestricted roles and an IN(...) list otherwise.
$scopeIl   = owner_scope_inline('il.employee_id');   // for il-aliased queries
$scopeBare = owner_scope_inline('employee_id');       // for single-table queries

if (method() === 'POST' && isset($_GET['approve'])) {
    $id=(int)$_GET['approve'];
    // a manager may only approve rows for employees in their own line of control ($scopeBare)
    $db->prepare("UPDATE incentive_ledger SET status='approved', approved_by=?, approved_at=NOW()
                  WHERE id=? AND status='accrued' AND $scopeBare")->execute([current_employee_id()?:null,$id]);
    ok(['id'=>$id,'status'=>'approved']);
}
if (method() === 'POST' && isset($_GET['pay'])) {
    $id=(int)$_GET['pay'];
    $db->prepare("UPDATE incentive_ledger SET status='paid', paid_at=NOW()
                  WHERE id=? AND status='approved' AND $scopeBare")->execute([$id]);
    ok(['id'=>$id,'status'=>'paid']);
}
if (method() === 'POST' && isset($_GET['bulk'])) {
    $period = $_GET['period'] ?? date('Y-m');
    if ($_GET['bulk']==='approve') {
        $st=$db->prepare("UPDATE incentive_ledger SET status='approved', approved_by=?, approved_at=NOW()
                          WHERE status='accrued' AND period_month=? AND $scopeBare");
        $st->execute([current_employee_id()?:null,$period]);
        ok(['approved'=>$st->rowCount(),'period'=>$period]);
    }
    if ($_GET['bulk']==='pay') {
        $st=$db->prepare("UPDATE incentive_ledger SET status='paid', paid_at=NOW()
                          WHERE status='approved' AND period_month=? AND $scopeBare");
        $st->execute([$period]);
        ok(['paid'=>$st->rowCount(),'period'=>$period]);
    }
    fail('unknown bulk action');
}

if (method() === 'GET' && isset($_GET['summary'])) {
    $rows=$db->query("SELECT status, COUNT(*) c, COALESCE(SUM(amount),0) amt FROM incentive_ledger WHERE $scopeBare GROUP BY status")->fetchAll();
    $byStatus=[]; foreach($rows as $r){ $byStatus[$r['status']]=['count'=>(int)$r['c'],'amount'=>(float)$r['amt']]; }
    $month=(float)$db->query("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger
             WHERE period_month=DATE_FORMAT(CURDATE(),'%Y-%m') AND $scopeBare")->fetchColumn();
    ok(['by_status'=>$byStatus,
        'total'=>(float)$db->query("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE $scopeBare")->fetchColumn(),
        'this_month'=>$month,
        'pending_approval'=>(float)$db->query("SELECT COALESCE(SUM(amount),0) FROM incentive_ledger WHERE status='accrued' AND $scopeBare")->fetchColumn()]);
}

if (method() === 'GET' && isset($_GET['monthly'])) {
    if (!can_manage_all_records()) fail('Not authorized', 403);
    // month-by-month payout breakdown (paid vs pending), optionally per agent
    $out = ['by_month' => $db->query("
        SELECT period_month,
               COUNT(*) line_count,
               COALESCE(SUM(amount),0) total,
               COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) paid,
               COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) approved,
               COALESCE(SUM(CASE WHEN status='accrued' THEN amount ELSE 0 END),0) accrued
        FROM incentive_ledger WHERE $scopeBare GROUP BY period_month ORDER BY period_month DESC LIMIT 24")->fetchAll()];
    // per agent within a chosen month
    if (!empty($_GET['month'])) {
        $s = $db->prepare("SELECT e.full_name,
               COALESCE(SUM(il.amount),0) total,
               COALESCE(SUM(CASE WHEN il.status='paid' THEN il.amount ELSE 0 END),0) paid,
               COALESCE(SUM(CASE WHEN il.status<>'paid' THEN il.amount ELSE 0 END),0) pending
            FROM incentive_ledger il JOIN employees e ON e.id=il.employee_id
            WHERE il.period_month=? AND $scopeIl GROUP BY e.id ORDER BY total DESC");
        $s->execute([$_GET['month']]);
        $out['by_agent'] = $s->fetchAll();
    }
    ok($out);
}

if (method() === 'GET' && isset($_GET['leaderboard'])) {
    // reps only ever see their own row on the leaderboard (query scoped via $scopeIl below)
    ok($db->query("SELECT e.id, e.full_name,
            COUNT(il.id) AS payouts,
            COALESCE(SUM(il.amount),0) AS earned,
            COALESCE(SUM(CASE WHEN il.status='paid' THEN il.amount ELSE 0 END),0) AS paid,
            COALESCE(SUM(CASE WHEN il.status IN('accrued','approved') THEN il.amount ELSE 0 END),0) AS pending
        FROM employees e JOIN incentive_ledger il ON il.employee_id=e.id
        WHERE $scopeIl
        GROUP BY e.id, e.full_name ORDER BY earned DESC LIMIT 25")->fetchAll());
}

/* ledger list — always scoped to what the caller may see (Head/Admin=all, Manager=their line,
   exec=own). A manager may narrow to one report via ?employee=; $scopeIl still bounds it. */
if (method() === 'GET') {
    $where=[$scopeIl]; $args=[];
    if ($canManage && !empty($_GET['employee'])) { $where[]='il.employee_id=?'; $args[]=(int)$_GET['employee']; }
    if (!empty($_GET['status']))   { $where[]='il.status=?';      $args[]=$_GET['status']; }
    if (!empty($_GET['period']))   { $where[]='il.period_month=?'; $args[]=$_GET['period']; }
    $w='WHERE '.implode(' AND ',$where);
    $per=max(1,min(200,(int)($_GET['per']??50))); $page=max(1,(int)($_GET['page']??1)); $off=($page-1)*$per;
    $cnt=$db->prepare("SELECT COUNT(*) FROM incentive_ledger il $w"); $cnt->execute($args); $total=(int)$cnt->fetchColumn();
    $s=$db->prepare("SELECT il.*, e.full_name AS employee_name, m.legal_name AS merchant_name, m.merchant_code
                     FROM incentive_ledger il
                     LEFT JOIN employees e ON e.id=il.employee_id
                     LEFT JOIN merchants m ON m.id=il.merchant_id
                     $w ORDER BY il.created_at DESC LIMIT $per OFFSET $off");
    $s->execute($args);
    ok($s->fetchAll(), ['total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$per)]);
}

fail('Unsupported method', 405);
