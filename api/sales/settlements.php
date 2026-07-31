<?php
/**
 * Merchant settlements with a maker-checker-approver workflow.
 *
 * Lifecycle: draft -> pending_check -> checked -> approved -> paid
 *                          \-> rejected (by checker or approver) -> draft (reopen)
 *
 * Separation of duties (enforced server-side, not just in the UI):
 *   - the CHECKER must not be the maker,
 *   - the APPROVER must not be the maker or the checker,
 *   - minimum role tier per level comes from approval_configs (maker=exec+,
 *     checker=manager+, approver=head+/admin by default — admin-editable).
 *
 *   GET  settlements.php?list=1[&status=&page=&per=]  -> list + summary strip
 *   GET  settlements.php?id=N                         -> one row + audit trail
 *   GET  settlements.php?merchants=1                  -> merchant picker
 *   GET  settlements.php?config=1                     -> approval level config + my capabilities
 *   POST settlements.php                              -> create (draft, or submit=1 to file it)
 *   PATCH settlements.php?id=N                        -> maker edits own draft/rejected row
 *   POST settlements.php?action=submit|check|approve|reject|pay|reopen&id=N
 *   POST settlements.php?config=1                     -> admin edits level min-tiers
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
require_module('settlements');
$db = db();
$me = current_employee_id();
$role = current_role();
$tier = payg_role_tier($role);
$isManager = can_manage_all_records();

function settle_cfg(PDO $db): array
{
    $rows = $db->query("SELECT * FROM approval_configs WHERE entity_type='settlement' AND is_active=1 ORDER BY level")->fetchAll();
    $cfg = [];
    foreach ($rows as $r) $cfg[(int)$r['level']] = $r;
    return $cfg + [1 => ['min_tier' => 1], 2 => ['min_tier' => 2], 3 => ['min_tier' => 3]];
}

function settle_audit(PDO $db, int $id, string $action, ?string $remarks = null): void
{
    try {
        $db->prepare("INSERT INTO approval_actions (entity_type, entity_id, action, actor_id, actor_role, remarks)
                      VALUES ('settlement', ?, ?, ?, ?, ?)")
           ->execute([$id, $action, current_employee_id() ?: null, current_role(), $remarks !== null ? mb_substr($remarks, 0, 500) : null]);
    } catch (Throwable $e) {}
}

function settle_fetch(PDO $db, int $id): array
{
    $s = $db->prepare("SELECT s.*, m.legal_name AS merchant_name, m.merchant_code, m.owner_id AS merchant_owner_id
                         FROM settlements s JOIN merchants m ON m.id=s.merchant_id WHERE s.id=?");
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) fail('Settlement not found', 404);
    return $row;
}

function settle_notify(PDO $db, int $empId, string $title, string $msg): void
{
    if (!$empId) return;
    try {
        $db->prepare("INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)")
           ->execute([$empId, 'settlement', $title, $msg, 'sales-settlements.php']);
    } catch (Throwable $e) {}
}

/* -------------------------------- reads -------------------------------- */

if (method() === 'GET' && isset($_GET['config'])) {
    $cfg = settle_cfg($db);
    ok(['levels' => array_values(array_filter($cfg, 'is_array')),
        'me' => ['employee_id' => $me, 'role' => $role, 'tier' => $tier,
                 'can_check' => $tier >= (int)$cfg[2]['min_tier'],
                 'can_approve' => $tier >= (int)$cfg[3]['min_tier'],
                 'can_pay' => $tier >= (int)$cfg[3]['min_tier'],
                 'can_configure' => $role === 'admin']]);
}

if (method() === 'GET' && isset($_GET['merchants'])) {
    ok($db->query("SELECT id, legal_name, merchant_code, expected_monthly_volume, mdr_percent
                     FROM merchants WHERE is_active=1 ORDER BY legal_name")->fetchAll());
}

if (method() === 'GET' && isset($_GET['id'])) {
    $row = settle_fetch($db, (int)$_GET['id']);
    if (!$isManager && (int)$row['maker_id'] !== $me && (int)$row['merchant_owner_id'] !== $me) {
        fail('Not authorized to view this settlement', 403);
    }
    $a = $db->prepare("SELECT aa.*, e.full_name AS actor_name FROM approval_actions aa
                  LEFT JOIN employees e ON e.id=aa.actor_id
                      WHERE aa.entity_type='settlement' AND aa.entity_id=? ORDER BY aa.id");
    $a->execute([(int)$row['id']]);
    $row['audit'] = $a->fetchAll();
    ok($row);
}

if (method() === 'GET') { // list (default)
    $w = []; $args = [];
    if (!$isManager) { $w[] = "(s.maker_id=? OR m.owner_id=?)"; $args[] = $me; $args[] = $me; }
    if (!empty($_GET['status'])) { $w[] = "s.status=?"; $args[] = $_GET['status']; }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

    $per = max(5, min(100, (int)($_GET['per'] ?? 25)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $cnt = $db->prepare("SELECT COUNT(*) FROM settlements s JOIN merchants m ON m.id=s.merchant_id $where");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();

    $sum = $db->prepare("SELECT COUNT(*) cnt,
                                SUM(s.status='pending_check') to_check, SUM(s.status='checked') to_approve,
                                SUM(s.status='approved') to_pay, SUM(s.status='rejected') rejected,
                                SUM(CASE WHEN s.status IN('pending_check','checked','approved') THEN s.net_amount ELSE 0 END) in_flight,
                                SUM(CASE WHEN s.status='paid' THEN s.net_amount ELSE 0 END) paid_amt
                           FROM settlements s JOIN merchants m ON m.id=s.merchant_id $where");
    $sum->execute($args);

    $s = $db->prepare("SELECT s.*, m.legal_name AS merchant_name, m.merchant_code,
                              mk.full_name AS maker_name, ck.full_name AS checker_name, ap.full_name AS approver_name
                         FROM settlements s
                         JOIN merchants m ON m.id=s.merchant_id
                    LEFT JOIN employees mk ON mk.id=s.maker_id
                    LEFT JOIN employees ck ON ck.id=s.checked_by
                    LEFT JOIN employees ap ON ap.id=s.approved_by
                        $where
                        ORDER BY FIELD(s.status,'pending_check','checked','approved','draft','rejected','paid'), s.updated_at DESC
                        LIMIT " . (($page - 1) * $per) . ", $per");
    $s->execute($args);
    ok($s->fetchAll(), ['total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per)),
                        'summary' => $sum->fetch()]);
}

/* -------------------------------- writes ------------------------------- */

if (method() === 'POST' && isset($_GET['config'])) {
    if ($role !== 'admin') fail('Only the admin can change approval levels', 403);
    $b = body();
    foreach (($b['levels'] ?? []) as $lv) {
        $lvl = (int)($lv['level'] ?? 0);
        if ($lvl < 1 || $lvl > 3) continue;
        $db->prepare("UPDATE approval_configs SET min_tier=? WHERE entity_type='settlement' AND level=?")
           ->execute([max(1, min(4, (int)($lv['min_tier'] ?? 1))), $lvl]);
    }
    settle_audit($db, 0, 'config_changed', json_encode($b['levels'] ?? []));
    ok();
}

if (method() === 'POST' && !isset($_GET['action'])) {  // create
    $b = body();
    $mid = (int)($b['merchant_id'] ?? 0);
    if (!$mid) fail('merchant_id required');
    $chk = $db->prepare("SELECT id FROM merchants WHERE id=? AND is_active=1"); $chk->execute([$mid]);
    if (!$chk->fetchColumn()) fail('Merchant not found');

    $gross = round((float)($b['gross_amount'] ?? 0), 2);
    $mdr   = round((float)($b['mdr_amount'] ?? 0), 2);
    $tax   = round((float)($b['tax_amount'] ?? 0), 2);
    $adj   = round((float)($b['adjustments'] ?? 0), 2);
    if ($gross <= 0) fail('gross_amount must be positive');
    $net = round($gross - $mdr - $tax + $adj, 2);
    if ($net < 0) fail('Net amount is negative — check deductions');

    $ref = 'SETL' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $submit = !empty($b['submit']);
    $db->prepare("INSERT INTO settlements (settlement_ref, merchant_id, period_from, period_to,
                    gross_amount, mdr_amount, tax_amount, adjustments, net_amount, bank_account, remarks, status, maker_id)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$ref, $mid, ($b['period_from'] ?? null) ?: null, ($b['period_to'] ?? null) ?: null,
                  $gross, $mdr, $tax, $adj, $net,
                  $b['bank_account'] ?? null, $b['remarks'] ?? null,
                  $submit ? 'pending_check' : 'draft', $me]);
    $id = (int)$db->lastInsertId();
    settle_audit($db, $id, 'created', $submit ? 'Created and submitted for checking' : 'Created as draft');
    if ($submit) settle_audit($db, $id, 'submitted', null);
    ok(['id' => $id, 'settlement_ref' => $ref]);
}

if ((method() === 'PATCH' || method() === 'PUT') && isset($_GET['id'])) {
    $row = settle_fetch($db, (int)$_GET['id']);
    if ((int)$row['maker_id'] !== $me && $role !== 'admin') fail('Only the maker may edit this settlement', 403);
    if (!in_array($row['status'], ['draft', 'rejected'], true)) fail('Only draft or rejected settlements can be edited');
    $b = body();
    $set = []; $args = [];
    foreach (['period_from','period_to','bank_account','remarks'] as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = ($b[$f] === '' ? null : $b[$f]); }
    }
    foreach (['gross_amount','mdr_amount','tax_amount','adjustments'] as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = round((float)$b[$f], 2); }
    }
    if (!$set) fail('Nothing to update');
    $args[] = (int)$row['id'];
    $db->prepare("UPDATE settlements SET " . implode(',', $set) . ",
                    net_amount = ROUND(gross_amount - mdr_amount - tax_amount + adjustments, 2)
                  WHERE id=?")->execute($args);
    settle_audit($db, (int)$row['id'], 'edited', null);
    ok(['id' => (int)$row['id']]);
}

if (method() === 'POST' && isset($_GET['action'], $_GET['id'])) {
    $row = settle_fetch($db, (int)$_GET['id']);
    $id = (int)$row['id'];
    $b = body();
    $cfg = settle_cfg($db);
    $remarks = trim((string)($b['remarks'] ?? ''));

    switch ($_GET['action']) {
        case 'submit':
            if ((int)$row['maker_id'] !== $me) fail('Only the maker may submit', 403);
            if (!in_array($row['status'], ['draft', 'rejected'], true)) fail('Already submitted');
            $db->prepare("UPDATE settlements SET status='pending_check', rejected_by=NULL, rejected_at=NULL, reject_reason=NULL WHERE id=?")->execute([$id]);
            settle_audit($db, $id, 'submitted', $remarks ?: null);
            ok(['status' => 'pending_check']);

        case 'check':
            if ($tier < (int)$cfg[2]['min_tier']) fail('Your role tier cannot check settlements', 403);
            if ((int)$row['maker_id'] === $me) fail('Maker and checker must be different people (four-eyes rule)', 403);
            if ($row['status'] !== 'pending_check') fail('Not awaiting check');
            $db->prepare("UPDATE settlements SET status='checked', checked_by=?, checked_at=NOW() WHERE id=?")->execute([$me, $id]);
            settle_audit($db, $id, 'checked', $remarks ?: null);
            settle_notify($db, (int)$row['maker_id'], 'Settlement checked: ' . $row['settlement_ref'],
                'Verified by the checker; now awaiting final approval.');
            ok(['status' => 'checked']);

        case 'approve':
            if ($tier < (int)$cfg[3]['min_tier']) fail('Your role tier cannot approve settlements', 403);
            if ((int)$row['maker_id'] === $me)   fail('Approver must be different from the maker', 403);
            if ((int)$row['checked_by'] === $me) fail('Approver must be different from the checker (three-role rule)', 403);
            if ($row['status'] !== 'checked') fail('Must be checked before approval');
            $db->prepare("UPDATE settlements SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$me, $id]);
            settle_audit($db, $id, 'approved', $remarks ?: null);
            settle_notify($db, (int)$row['maker_id'], 'Settlement approved: ' . $row['settlement_ref'],
                'Approved for payout of ₹' . number_format((float)$row['net_amount'], 2) . '.');
            ok(['status' => 'approved']);

        case 'reject':
            $canReject = ($row['status'] === 'pending_check' && $tier >= (int)$cfg[2]['min_tier'] && (int)$row['maker_id'] !== $me)
                      || ($row['status'] === 'checked'       && $tier >= (int)$cfg[3]['min_tier'] && (int)$row['maker_id'] !== $me && (int)$row['checked_by'] !== $me);
            if (!$canReject) fail('Not authorized to reject at this stage', 403);
            if ($remarks === '') fail('A rejection reason is required');
            $db->prepare("UPDATE settlements SET status='rejected', rejected_by=?, rejected_at=NOW(), reject_reason=? WHERE id=?")
               ->execute([$me, mb_substr($remarks, 0, 500), $id]);
            settle_audit($db, $id, 'rejected', $remarks);
            settle_notify($db, (int)$row['maker_id'], 'Settlement rejected: ' . $row['settlement_ref'], $remarks);
            ok(['status' => 'rejected']);

        case 'pay':
            if ($tier < (int)$cfg[3]['min_tier']) fail('Your role tier cannot mark payouts', 403);
            if ($row['status'] !== 'approved') fail('Only approved settlements can be paid');
            $utr = trim((string)($b['utr'] ?? ''));
            if ($utr === '') fail('UTR / payment reference is required');
            $db->prepare("UPDATE settlements SET status='paid', paid_by=?, paid_at=NOW(), utr=? WHERE id=?")
               ->execute([$me, mb_substr($utr, 0, 60), $id]);
            settle_audit($db, $id, 'paid', 'UTR ' . $utr);
            settle_notify($db, (int)$row['maker_id'], 'Settlement paid: ' . $row['settlement_ref'], 'UTR ' . $utr);
            ok(['status' => 'paid']);

        case 'reopen':
            if ((int)$row['maker_id'] !== $me && $role !== 'admin') fail('Only the maker may reopen', 403);
            if ($row['status'] !== 'rejected') fail('Only rejected settlements can be reopened');
            $db->prepare("UPDATE settlements SET status='draft' WHERE id=?")->execute([$id]);
            settle_audit($db, $id, 'reopened', $remarks ?: null);
            ok(['status' => 'draft']);
    }
    fail('Unknown action');
}

fail('Unsupported request', 405);
