<?php
/**
 * KPA/KRA Scorecards — weighted quarterly KPI framework.
 *
 *   GET  scorecards.php                       -> list (team for mgr/admin, own for sales_exec) + computed scores
 *   GET  scorecards.php?id=<n>                -> one card with lines + computed detail
 *   GET  scorecards.php?catalog=1            -> KPI definitions + role-level target presets
 *   GET  scorecards.php?team=1               -> employees a manager/admin can assign to
 *   POST scorecards.php                       -> create/assign {employee_id, period, role_level, notes}
 *   POST scorecards.php?sync=1&id=<n>        -> auto-fill actuals from live transactions
 *   PATCH scorecards.php?id=<n>              -> update card {status|notes} OR a line {kpi_code, weight?, target_value?, threshold_value?, actual_value?}
 *   DELETE scorecards.php?id=<n>             -> remove a draft card
 *
 * Managers/admin define & manage; sales execs may only view (and acknowledge) their own.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_incentive_engine.php';
require_login();

$db   = db();
$role = current_role();
$meId = current_employee_id();
// Managing scorecards is goal-setting, so it follows the same 'kpi_govern' capability as org
// KPAs and dashboard tiles (Admin, HR, Sales Head — _roles.php) plus the Sales Manager, who
// owns their own team's cards. Without this HR could define a company KPA but not the
// scorecard that measures it — the same question answered two different ways.
$canManage = $role === 'manager' || can_govern_kpi();

/* ---- high-yield categories (segment mix) ---- */
const HIGH_YIELD = ['FI', 'NBFC', 'Insurance', 'Education', 'Enterprise'];

/* ---- role-level avg-monthly GMV target presets (₹) ---- */
function level_presets(): array {
    return [
        'Regional Sales Head'    => 7500000000, // ₹750 Cr avg monthly
        'Manager'                => 2250000000, // ₹225 Cr
        'Assistant Manager'      => 1620000000, // ₹162 Cr
        'Senior Sales Executive' => 1380000000, // ₹138 Cr
    ];
}
/* per-level active transacting-merchant targets (count) — gradeable, tunable per card */
function active_presets(): array {
    return ['Regional Sales Head'=>400, 'Manager'=>120, 'Assistant Manager'=>80, 'Senior Sales Executive'=>50];
}
/* fee-income eligibility slabs (₹) */
const FEE_MIN = 100000, FEE_SLAB2 = 200000, FEE_SLAB3 = 500000;

/* ---- period '2026-Q3' -> [start,end,months] ---- */
function quarter_range(string $period): array {
    if (!preg_match('/^(\d{4})-Q([1-4])$/', $period, $m)) return [null, null, 3];
    $y = (int)$m[1]; $q = (int)$m[2];
    $startMonth = ($q - 1) * 3 + 1;
    $start = sprintf('%04d-%02d-01', $y, $startMonth);
    $endMonth = $startMonth + 2;
    $end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $endMonth)));
    return [$start, $end, 3];
}

/* ---- compute live actuals for an employee over a quarter, keyed by auto_source ---- */
function compute_actuals(PDO $db, int $empId, string $period): array {
    [$start, $end] = quarter_range($period);
    $out = ['gmv' => 0, 'fee_income' => 0, 'active_merchants' => 0, 'segment_mix' => 0];
    if (!$start) return $out;
    // GMV total + fee income + active merchants from successful txns on merchants owned by this rep
    $hy = "'" . implode("','", HIGH_YIELD) . "'";
    $sql = "SELECT
              COALESCE(SUM(t.amount),0)                                             AS gmv_total,
              COALESCE(SUM(t.amount * COALESCE(m.mdr_percent,0) / 100),0)           AS fee_income,
              COUNT(DISTINCT t.merchant_id)                                          AS active_merchants,
              COALESCE(SUM(CASE WHEN m.category IN ($hy) THEN t.amount ELSE 0 END),0) AS hy_gmv
            FROM merchant_transactions t
            JOIN merchants m ON m.id = t.merchant_id
            WHERE m.owner_id = ? AND t.status='success' AND t.txn_date BETWEEN ? AND ?";
    $s = $db->prepare($sql);
    $s->execute([$empId, $start . ' 00:00:00', $end . ' 23:59:59']);
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    $gmvTotal = (float)($r['gmv_total'] ?? 0);
    // avg MONTHLY GMV over the ELAPSED months of the quarter (not a flat /3) — so mid-quarter
    // pace isn't understated and the 100% GMV gate isn't falsely failed early in the quarter.
    $today  = date('Y-m-d');
    $effEnd = $end < $today ? $end : $today;
    [$sy,$sm] = array_map('intval', explode('-', substr($start, 0, 7)));
    [$ey,$em] = array_map('intval', explode('-', substr($effEnd, 0, 7)));
    $monthsElapsed = max(1, min(3, ($ey - $sy) * 12 + ($em - $sm) + 1));
    $out['gmv']              = round($gmvTotal / $monthsElapsed, 2);    // avg monthly GMV
    $out['fee_income']       = round((float)($r['fee_income'] ?? 0), 2);
    $out['active_merchants'] = (int)($r['active_merchants'] ?? 0);
    $out['segment_mix']      = $gmvTotal > 0 ? round(((float)$r['hy_gmv']) * 100 / $gmvTotal, 1) : 0;
    return $out;
}

/* ---- score a card given its lines; returns rich eligibility detail ---- */
function score_card(array $card, array $lines): array {
    $sumW = 0; $score = 0; $gatePass = true; $gmvAttain = 0;
    $feeActual = 0; $govActual = 0;
    $scored = [];
    foreach ($lines as $ln) {
        $w = (float)$ln['weight'];
        $t = (float)$ln['target_value'];
        $a = (float)$ln['actual_value'];
        // no target set => not gradeable (0%); never auto-100, which would silently inflate the score
        $attain = $t > 0 ? ($a / $t * 100) : 0;
        $capped = min($attain, 110);                    // accelerator ceiling for scoring
        $lineScore = $w * $capped / 100;
        $sumW += $w; $score += $lineScore;
        if ($ln['kpi_code'] === 'gmv')        { $gmvAttain = $attain; $gatePass = $attain >= 100; }
        if ($ln['kpi_code'] === 'fee_income') { $feeActual = $a; }
        if ($ln['kpi_code'] === 'governance') { $govActual = $a; }
        $scored[] = ['kpi_code'=>$ln['kpi_code'], 'attain'=>round($attain,1), 'line_score'=>round($lineScore,2)];
    }
    $weightedPct = $sumW > 0 ? round($score / $sumW * 100, 1) : 0;
    $rating = $weightedPct >= 90 ? 'Outstanding' : ($weightedPct >= 75 ? 'Exceeds'
             : ($weightedPct >= 60 ? 'Meets' : ($weightedPct >= 40 ? 'Partial' : 'Below')));
    $feeSlab = $feeActual >= FEE_SLAB3 ? '₹5L+' : ($feeActual >= FEE_SLAB2 ? '₹2L+'
             : ($feeActual >= FEE_MIN ? '₹1L+' : '—'));
    $govOk = $govActual >= 100;
    return [
        'weighted_score'   => round($score, 1),
        'weighted_pct'     => $weightedPct,
        'sum_weight'       => round($sumW, 1),
        'rating'           => $rating,
        'gmv_attainment'   => round($gmvAttain, 1),
        'gate_pass'        => $gatePass,                          // GMV >= 100%
        'accelerator'      => $gmvAttain > 110,                   // GMV > 110%
        'gmv_incentive_eligible' => $gatePass,                   // primary gate
        'fee_incentive_eligible' => $feeActual >= FEE_MIN,
        'fee_slab'         => $feeSlab,
        'governance_ok'    => $govOk,
        'payout_ready'     => $gatePass && $govOk && in_array($card['status'], ['published','acknowledged','locked'], true),
        'lines'            => $scored,
    ];
}

/* ---- hydrate a card row with its lines + KPI meta + score ---- */
function card_detail(PDO $db, array $card): array {
    $s = $db->prepare("SELECT l.*, k.name, k.unit, k.measurement_basis, k.description, k.sort_order
                       FROM scorecard_lines l JOIN kpi_definitions k ON k.code=l.kpi_code
                       WHERE l.scorecard_id=? ORDER BY k.sort_order");
    $s->execute([$card['id']]);
    $lines = $s->fetchAll(PDO::FETCH_ASSOC);
    $score = score_card($card, $lines);
    $card['score'] = $score;
    $card['lines'] = $lines;
    $card['payout'] = payout_for_card($db, $card, $lines, $score);
    return $card;
}

/** Compute the quarterly ₹ incentive for a card from its scorecard actuals + editable config. */
function payout_for_card(PDO $db, array $card, array $lines, array $score): array {
    $by = [];
    foreach ($lines as $l) $by[$l['kpi_code']] = (float)$l['actual_value'];
    $gmvAvgMonthly = $by['gmv'] ?? 0;                    // scorecard stores avg-monthly GMV
    $calc = incentive_quarterly_calc(incentive_config($db), [
        'quarterly_volume' => $gmvAvgMonthly * 3,        // memo works in quarterly volume
        'gmv_attain_pct'   => $score['gmv_attainment'],
        'fee_income'       => $by['fee_income'] ?? 0,
        'governance_ok'    => $score['governance_ok'],
    ]);
    // whether it has actually been booked to the ledger yet
    try {
        $q = $db->prepare("SELECT COALESCE(SUM(amount),0) booked, MAX(status) st, COUNT(*) n
                           FROM incentive_ledger WHERE scorecard_id=?");
        $q->execute([(int)$card['id']]);
        $b = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $b = []; }
    $calc['booked_amount'] = (float)($b['booked'] ?? 0);
    $calc['booked_count']  = (int)($b['n'] ?? 0);
    $calc['booked_status'] = $b['st'] ?? null;
    return $calc;
}

$method = method();

/* ============================ GET ============================ */
if ($method === 'GET') {

    // editable incentive STRUCTURE (memo §7/§8 rates) — any scorecard manager may view it
    if (isset($_GET['config'])) {
        if (!$canManage) fail('Managers only', 403);
        ok(['config' => incentive_config($db, true), 'can_edit' => can_manage_incentives()]);
    }

    // the logged-in user's OWN current (else latest) published scorecard — for dashboard goal widgets
    if (isset($_GET['mine'])) {
        $sql = "SELECT sc.*, e.full_name AS employee_name, e.designation, ab.full_name AS assigned_by_name
                FROM scorecards sc
                JOIN employees e ON e.id=sc.employee_id
                LEFT JOIN employees ab ON ab.id=sc.assigned_by
                WHERE sc.employee_id=? AND sc.status<>'draft'";
        $args = [$meId];
        if (!empty($_GET['period'])) { $sql .= " AND sc.period=?"; $args[] = $_GET['period']; }
        $sql .= " ORDER BY sc.period DESC LIMIT 1";
        $st = $db->prepare($sql); $st->execute($args);
        $card = $st->fetch(PDO::FETCH_ASSOC);
        ok(['card' => $card ? card_detail($db, $card) : null]);
    }

    // live business-category GMV mix for the pie chart — scoped like the scorecard list.
    // Governors/admin see the whole book; a manager sees merchants owned by their reports (+self).
    if (isset($_GET['mix'])) {
        if (!$canManage) fail('Managers only', 403);
        $period = $_GET['period'] ?? '';
        [$start, $end] = quarter_range($period);
        if (!$start) fail('period (YYYY-Qn) required');

        $sql = "SELECT COALESCE(NULLIF(TRIM(m.category),''),'Other') AS category,
                       COALESCE(SUM(t.amount),0) AS gmv
                FROM merchant_transactions t
                JOIN merchants m ON m.id = t.merchant_id
                WHERE t.status='success' AND t.txn_date BETWEEN ? AND ?";
        $args = [$start . ' 00:00:00', $end . ' 23:59:59'];
        // a plain manager is scoped to their own team's merchants; governors/admin are unrestricted
        if ($role === 'manager' && !can_govern_kpi()) {
            $own = $db->prepare("SELECT e.id FROM employees e
                                 JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                                 WHERE e.manager_id=? OR e.id=?");
            $own->execute([$meId, $meId]);
            $ids = array_map('intval', $own->fetchAll(PDO::FETCH_COLUMN));
            if (!$ids) { ok(['period' => $period, 'high_yield' => HIGH_YIELD, 'slices' => []]); }
            $sql .= " AND m.owner_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
            $args = array_merge($args, $ids);
        }
        $sql .= " GROUP BY category HAVING gmv > 0 ORDER BY gmv DESC";
        $st = $db->prepare($sql); $st->execute($args);
        $slices = array_map(fn($r) => [
            'category'   => $r['category'],
            'gmv'        => (float)$r['gmv'],
            'high_yield' => in_array($r['category'], HIGH_YIELD, true),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        ok(['period' => $period, 'high_yield' => HIGH_YIELD, 'slices' => $slices]);
    }

    if (isset($_GET['catalog'])) {
        $defs = $db->query("SELECT * FROM kpi_definitions WHERE is_active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
        ok(['kpis' => $defs, 'levels' => level_presets(),
            'high_yield' => HIGH_YIELD,
            'fee_slabs' => ['min'=>FEE_MIN, 'slab2'=>FEE_SLAB2, 'slab3'=>FEE_SLAB3]]);
    }

    if (isset($_GET['team'])) {
        if (!$canManage) fail('Managers only', 403);
        // assignable = employees with an active sales login; managers see direct reports + unassigned sales execs, admin sees all
        $sql = "SELECT e.id, e.full_name, e.designation, su.role
                FROM employees e JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1";
        $args = [];
        if ($role === 'manager') {
            $sql .= " WHERE (e.manager_id=? OR su.role='sales_exec')";
            $args[] = $meId;
        }
        $sql .= " ORDER BY e.full_name";
        $st = $db->prepare($sql); $st->execute($args);
        ok(['team' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // single card
    if (isset($_GET['id'])) {
        $st = $db->prepare("SELECT sc.*, e.full_name AS employee_name, e.designation,
                                   ab.full_name AS assigned_by_name
                            FROM scorecards sc
                            JOIN employees e ON e.id=sc.employee_id
                            LEFT JOIN employees ab ON ab.id=sc.assigned_by
                            WHERE sc.id=?");
        $st->execute([(int)$_GET['id']]);
        $card = $st->fetch(PDO::FETCH_ASSOC);
        if (!$card) fail('Not found', 404);
        if (!$canManage && (int)$card['employee_id'] !== $meId) fail('Forbidden', 403);
        ok(['card' => card_detail($db, $card)]);
    }

    // list
    $sql = "SELECT sc.*, e.full_name AS employee_name, e.designation, ab.full_name AS assigned_by_name
            FROM scorecards sc
            JOIN employees e ON e.id=sc.employee_id
            LEFT JOIN employees ab ON ab.id=sc.assigned_by";
    $args = [];
    if (!$canManage) { $sql .= " WHERE sc.employee_id=?"; $args[] = $meId; }
    elseif ($role === 'manager') { $sql .= " WHERE (sc.assigned_by=? OR e.manager_id=? OR sc.employee_id=?)"; $args = [$meId,$meId,$meId]; }
    $sql .= " ORDER BY sc.period DESC, e.full_name";
    $st = $db->prepare($sql); $st->execute($args);
    $cards = array_map(fn($c) => card_detail($db, $c), $st->fetchAll(PDO::FETCH_ASSOC));
    ok(['cards' => $cards, 'can_manage' => $canManage]);
}

/* ============================ POST ============================ */
if ($method === 'POST') {

    // update the incentive STRUCTURE (memo rates/slabs) — Sales Head / Sales Manager / Admin
    if (isset($_GET['config'])) {
        if (!can_manage_incentives()) fail('You are not authorized to edit the incentive structure', 403);
        $b = body();
        $allowed = ['gmv_base_bps','gmv_base_share_pct','gmv_gate_min_pct','gmv_accel_pct',
                    'gmv_pass_mult_pct','gmv_accel_mult_pct','fee_min','fee_slab2','fee_slab3',
                    'fee_rate1_pct','fee_rate2_pct','fee_overachieve_pct'];
        $set = []; $args = [];
        foreach ($allowed as $f) if (array_key_exists($f, $b)) { $set[] = "`$f`=?"; $args[] = (float)$b[$f]; }
        if (!$set) fail('Nothing to update');
        $set[] = "updated_by=?"; $args[] = $meId;
        // ensure the row exists, then update
        $db->prepare("INSERT INTO incentive_config (id) VALUES (1) ON DUPLICATE KEY UPDATE id=id")->execute();
        $args[] = 1;
        $db->prepare("UPDATE incentive_config SET " . implode(',', $set) . " WHERE id=?")->execute($args);
        ok(['config' => incentive_config($db, true)]);
    }

    // compute & book this scorecard's quarterly payout to the incentive ledger
    if (isset($_GET['book'])) {
        if (!can_manage_incentives()) fail('You are not authorized to book incentive payouts', 403);
        $id = (int)($_GET['id'] ?? 0);
        $st = $db->prepare("SELECT sc.*, e.full_name employee_name FROM scorecards sc JOIN employees e ON e.id=sc.employee_id WHERE sc.id=?");
        $st->execute([$id]); $card = $st->fetch(PDO::FETCH_ASSOC);
        if (!$card) fail('Scorecard not found', 404);
        if (!in_array($card['status'], ['published','acknowledged','locked'], true)) {
            fail('Publish the scorecard before booking its payout', 400);
        }
        $full = card_detail($db, $card);                    // recomputes score + payout from current actuals
        $res  = incentive_book_quarterly($db, $card, $full['payout']);
        if (!$res['booked']) fail($res['reason'], 400);
        ok(['booked' => true, 'total' => $res['total'], 'lines' => $res['lines'], 'card' => card_detail($db, $card)]);
    }

    // sync live actuals into a card's auto lines
    if (isset($_GET['sync'])) {
        if (!$canManage) fail('Managers only', 403);
        $id = (int)($_GET['id'] ?? 0);
        $card = $db->prepare("SELECT * FROM scorecards WHERE id=?");
        $card->execute([$id]); $card = $card->fetch(PDO::FETCH_ASSOC);
        if (!$card) fail('Not found', 404);
        $act = compute_actuals($db, (int)$card['employee_id'], $card['period']);
        // map auto_source -> actual for each line that is auto-sourced
        $defs = $db->query("SELECT code, auto_source FROM kpi_definitions")->fetchAll(PDO::FETCH_KEY_PAIR);
        $upd = $db->prepare("UPDATE scorecard_lines SET actual_value=?, actual_source='auto' WHERE scorecard_id=? AND kpi_code=?");
        $n = 0;
        foreach ($defs as $code => $src) {
            if ($src && isset($act[$src])) { $upd->execute([$act[$src], $id, $code]); $n++; }
        }
        $card = $db->prepare("SELECT sc.*, e.full_name employee_name FROM scorecards sc JOIN employees e ON e.id=sc.employee_id WHERE sc.id=?");
        $card->execute([$id]);
        ok(['synced' => $n, 'actuals' => $act, 'card' => card_detail($db, $card->fetch(PDO::FETCH_ASSOC))]);
    }

    if (!$canManage) fail('Managers only', 403);
    $b = body();
    $empId  = (int)($b['employee_id'] ?? 0);
    $period = trim($b['period'] ?? '');
    $level  = trim($b['role_level'] ?? 'Senior Sales Executive');
    if (!$empId || !preg_match('/^\d{4}-Q[1-4]$/', $period)) fail('employee_id and period (YYYY-Qn) required');

    $presets = level_presets();
    $gmvTarget = $presets[$level] ?? 0;

    try {
        $db->beginTransaction();
        $ins = $db->prepare("INSERT INTO scorecards (employee_id, period, role_level, assigned_by, gmv_target, notes)
                             VALUES (?,?,?,?,?,?)");
        $ins->execute([$empId, $period, $level, $meId, $gmvTarget, $b['notes'] ?? null]);
        $cardId = (int)$db->lastInsertId();
        // seed one line per active KPI with default weights; GMV target from the level preset
        $defs = $db->query("SELECT * FROM kpi_definitions WHERE is_active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
        $li = $db->prepare("INSERT INTO scorecard_lines (scorecard_id, kpi_code, weight, target_value, threshold_value, is_gate, actual_source)
                            VALUES (?,?,?,?,?,?, 'manual')");
        $activeTarget = active_presets()[$level] ?? 50;
        foreach ($defs as $d) {
            $target = 0; $threshold = 0;
            if ($d['code'] === 'gmv')                 { $target = $gmvTarget; $threshold = $gmvTarget; }
            elseif ($d['code'] === 'fee_income')      { $target = FEE_SLAB2; $threshold = FEE_MIN; }   // aim ₹2L, floor ₹1L
            elseif ($d['code'] === 'segment_mix')     { $target = 70; $threshold = 70; }
            elseif ($d['code'] === 'active_merchants'){ $target = $activeTarget; $threshold = (int)ceil($activeTarget / 2); }
            elseif ($d['code'] === 'governance')      { $target = 100; $threshold = 100; }
            $li->execute([$cardId, $d['code'], $d['default_weight'], $target, $threshold, $d['is_gate']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        if (stripos($e->getMessage(), 'Duplicate') !== false) fail('A scorecard already exists for this employee & period', 409);
        fail('Could not create scorecard: ' . $e->getMessage(), 500);
    }
    $card = $db->prepare("SELECT sc.*, e.full_name employee_name FROM scorecards sc JOIN employees e ON e.id=sc.employee_id WHERE sc.id=?");
    $card->execute([$cardId]);
    ok(['card' => card_detail($db, $card->fetch(PDO::FETCH_ASSOC))]);
}

/* ============================ PATCH ============================ */
if ($method === 'PATCH') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT * FROM scorecards WHERE id=?"); $st->execute([$id]);
    $card = $st->fetch(PDO::FETCH_ASSOC);
    if (!$card) fail('Not found', 404);
    $b = body();

    // sales_exec may only acknowledge their own published card
    if (!$canManage) {
        if ((int)$card['employee_id'] !== $meId) fail('Forbidden', 403);
        if (($b['status'] ?? '') !== 'acknowledged' || $card['status'] !== 'published') fail('You can only acknowledge a published scorecard', 403);
        $db->prepare("UPDATE scorecards SET status='acknowledged', acknowledged_at=NOW() WHERE id=?")->execute([$id]);
        ok(['id' => $id, 'status' => 'acknowledged']);
    }

    // manager/admin: update a line
    if (!empty($b['kpi_code'])) {
        $fields = []; $args = [];
        foreach (['weight','target_value','threshold_value','actual_value'] as $f) {
            if (array_key_exists($f, $b)) { $fields[] = "$f=?"; $args[] = (float)$b[$f]; }
        }
        if (array_key_exists('actual_value', $b)) { $fields[] = "actual_source='manual'"; }
        if (!$fields) fail('Nothing to update');
        $args[] = $id; $args[] = $b['kpi_code'];
        $db->prepare("UPDATE scorecard_lines SET " . implode(',', $fields) . " WHERE scorecard_id=? AND kpi_code=?")->execute($args);
        // keep the card's gmv_target mirror in sync
        if ($b['kpi_code'] === 'gmv' && array_key_exists('target_value', $b)) {
            $db->prepare("UPDATE scorecards SET gmv_target=? WHERE id=?")->execute([(float)$b['target_value'], $id]);
        }
        ok(['id' => $id, 'updated' => $b['kpi_code']]);
    }

    // manager/admin: update card status / notes
    $fields = []; $args = [];
    if (isset($b['status']) && in_array($b['status'], ['draft','published','acknowledged','locked'], true)) { $fields[]="status=?"; $args[]=$b['status']; }
    if (array_key_exists('notes', $b)) { $fields[]="notes=?"; $args[]=$b['notes']; }
    if (array_key_exists('role_level', $b)) { $fields[]="role_level=?"; $args[]=$b['role_level']; }
    if (!$fields) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE scorecards SET " . implode(',', $fields) . " WHERE id=?")->execute($args);
    ok(['id' => $id, 'status' => $b['status'] ?? $card['status']]);
}

/* ============================ DELETE ============================ */
if ($method === 'DELETE') {
    if (!$canManage) fail('Managers only', 403);
    $id = (int)($_GET['id'] ?? 0);
    $db->prepare("DELETE FROM scorecard_lines WHERE scorecard_id=?")->execute([$id]);
    $db->prepare("DELETE FROM scorecards WHERE id=?")->execute([$id]);
    ok(['deleted' => $id]);
}

fail('Unsupported method', 405);
