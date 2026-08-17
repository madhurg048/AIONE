<?php
/**
 * PayG AI Assistant — natural-language Q&A over live CRM data, for every user.
 *
 *   POST assistant.php  {question}  ->  {answer, evidence, tools, source}
 *
 * Evidence-backed by design (same rule as insight.php): the question is routed
 * to a fixed registry of SAFE, parameterized, ROLE-SCOPED metric queries; the
 * box LLM (crm_ai_call: box -> cloud -> local bridge) only PHRASES the answer
 * from that evidence — it never writes SQL and never invents numbers. If AI is
 * down, a deterministic plain-text rendering of the evidence is returned
 * (evidence-only mode), so the chat always answers.
 *
 * Scoping: managers/heads/admin see org-wide numbers; an exec's revenue/tasks/
 * The "who has not filled timesheets" roster is management/HR-only — an exec
 * just gets their own status.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ai.php';
require_login();
$db = db();
$me = current_employee_id();
$role = current_role();
$isMgr = can_manage_all_records();
$isHr = payg_is_hr_role($role);

if (method() !== 'POST') fail('POST {question} required', 405);
$q = trim((string)(body()['question'] ?? ''));
if ($q === '') fail('question required');
if (mb_strlen($q) > 500) $q = mb_substr($q, 0, 500);
$ql = mb_strtolower($q);

/* ------------------------------- helpers -------------------------------- */

/** [label, startDate, endDate) parsed from the question; default = this month. */
function asst_period(string $ql): array
{
    $t = strtotime('today');
    if (preg_match('/yesterday/', $ql))            return ['yesterday', date('Y-m-d', $t - 86400), date('Y-m-d', $t)];
    if (preg_match('/today|since morning/', $ql))  return ['today', date('Y-m-d', $t), date('Y-m-d', $t + 86400)];
    if (preg_match('/this week|current week/', $ql)) return ['this week', date('Y-m-d', strtotime('monday this week')), date('Y-m-d', $t + 86400)];
    if (preg_match('/last week/', $ql))            return ['last week', date('Y-m-d', strtotime('monday last week')), date('Y-m-d', strtotime('monday this week'))];
    if (preg_match('/last month/', $ql))           return ['last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-01')];
    if (preg_match('/quarter/', $ql)) {
        $qStart = sprintf('%d-%02d-01', (int)date('Y'), (floor((date('n') - 1) / 3) * 3) + 1);
        return [preg_match('/last quarter/', $ql) ? 'this quarter' : 'this quarter', $qStart, date('Y-m-d', $t + 86400)];
    }
    if (preg_match('/this year|year/', $ql))       return ['this year', date('Y-01-01'), date('Y-m-d', $t + 86400)];
    if (preg_match('/week/', $ql))                 return ['this week', date('Y-m-d', strtotime('monday this week')), date('Y-m-d', $t + 86400)];
    return ['this month', date('Y-m-01'), date('Y-m-d', $t + 86400)];
}

/** "next N months/days" horizon in days; default 90. */
function asst_horizon_days(string $ql): int
{
    if (preg_match('/next\s+(\d{1,2})\s*month/', $ql, $m)) return (int)$m[1] * 30;
    if (preg_match('/next\s+(\d{1,3})\s*day/', $ql, $m))   return (int)$m[1];
    if (preg_match('/6\s*month|half year/', $ql)) return 183;
    if (preg_match('/(a|1|one)\s*year|12\s*month/', $ql)) return 365;
    return 90;
}

$inr = fn($n) => '₹' . number_format((float)$n, 0);

/* --------------------------- metric registry ----------------------------- */
/* Each tool: match() on the lowercased question; run() returns
   [evidenceArray, fallbackTextAnswer]. Everything is role-scoped inside. */

$tools = [];

/* WHO HAS NOT FILLED TIMESHEETS (today / date-period aware) */
$tools['timesheets_missing'] = [
    'match' => fn($ql) => preg_match('/timesheet|time sheet/', $ql) === 1,
    'run' => function () use ($db, $me, $isMgr, $isHr, $ql) {
        [$plabel, $from] = asst_period($ql);
        $day = preg_match('/yesterday/', $ql) ? $from : date('Y-m-d');
        if ($isMgr || $isHr) {
            $rows = $db->query("SELECT e.id, e.full_name, e.designation, su.role
                                  FROM employees e
                                  JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                                 WHERE NOT EXISTS (SELECT 1 FROM timesheets ts WHERE ts.employee_id=e.id AND ts.entry_date=" . $db->quote($day) . ")
                                 ORDER BY e.full_name")->fetchAll();
            $filled = (int)$db->query("SELECT COUNT(DISTINCT employee_id) FROM timesheets WHERE entry_date=" . $db->quote($day))->fetchColumn();
            $names = array_map(fn($r) => $r['full_name'] . ' (' . ($r['designation'] ?: $r['role']) . ')', $rows);
            $ev = ['date' => $day, 'not_filled_count' => count($rows), 'filled_count' => $filled, 'not_filled' => $names];
            $fb = count($rows)
                ? "Timesheets NOT filled for $day (" . count($rows) . "): " . implode(', ', $names) . ". $filled employee(s) have filled."
                : "Everyone with an active login has filled their timesheet for $day.";
            return [$ev, $fb];
        }
        $mine = (int)$db->query("SELECT COUNT(*) FROM timesheets WHERE employee_id=$me AND entry_date=" . $db->quote($day))->fetchColumn();
        $ev = ['date' => $day, 'you_filled' => $mine > 0, 'note' => 'The full roster is visible to managers/HR only'];
        return [$ev, $mine > 0 ? "You have filled your timesheet for $day. (The full team roster is visible to managers/HR.)"
                               : "You have NOT filled your timesheet for $day yet. (The full team roster is visible to managers/HR.)"];
    },
];

/* REVENUE / TRANSACTION VOLUME by period (owner-scoped for execs) */
$tools['revenue'] = [
    'match' => fn($ql) => preg_match('/revenue|transaction|gmv|volume|turnover|collections?|business (this|last|done)/', $ql) === 1,
    'run' => function () use ($db, $ql, $inr) {
        [$plabel, $from, $to] = asst_period($ql);
        $args = [$from, $to];
        $scope = owner_scope('m.owner_id', $args);
        $s = $db->prepare("SELECT COUNT(*) txns, COALESCE(SUM(t.amount),0) revenue, COUNT(DISTINCT t.merchant_id) merchants
                             FROM merchant_transactions t JOIN merchants m ON m.id=t.merchant_id
                            WHERE t.status='success' AND t.txn_date >= ? AND t.txn_date < ? AND $scope");
        $s->execute($args);
        $sum = $s->fetch();
        $args2 = [$from, $to];
        $scope2 = owner_scope('m.owner_id', $args2);
        $top = $db->prepare("SELECT m.legal_name, COALESCE(SUM(t.amount),0) amt
                               FROM merchant_transactions t JOIN merchants m ON m.id=t.merchant_id
                              WHERE t.status='success' AND t.txn_date >= ? AND t.txn_date < ? AND $scope2
                              GROUP BY t.merchant_id ORDER BY amt DESC LIMIT 3");
        $top->execute($args2);
        $ev = ['period' => $plabel, 'from' => $from, 'to_exclusive' => $to,
               'revenue' => (float)$sum['revenue'], 'successful_txns' => (int)$sum['txns'],
               'active_merchants' => (int)$sum['merchants'], 'top_merchants' => $top->fetchAll(),
               'scope' => visible_owner_ids() === null ? 'organisation-wide' : 'your merchants only'];
        $fb = "Revenue logged $plabel: " . $inr($sum['revenue']) . " across {$sum['txns']} successful transaction(s) from {$sum['merchants']} merchant(s) ({$ev['scope']}).";
        return [$ev, $fb];
    },
];

/* DOCUMENTS: files / folders */
$tools['documents'] = [
    'match' => fn($ql) => preg_match('/document|file|folder|dms/', $ql) === 1,
    'run' => function () use ($db, $me) {
        $g = $db->query("SELECT (SELECT COUNT(*) FROM doc_folders) folders,
                                (SELECT COUNT(*) FROM doc_files WHERE is_deleted=0) files,
                                (SELECT COALESCE(SUM(size_bytes),0) FROM doc_files WHERE is_deleted=0) bytes")->fetch();
        $mine = $db->query("SELECT COUNT(DISTINCT f.id) folders, COUNT(df.id) files
                              FROM doc_folders f LEFT JOIN doc_files df ON df.folder_id=f.id AND df.is_deleted=0
                             WHERE f.owner_employee_id=$me")->fetch();
        $mb = round($g['bytes'] / 1048576, 1);
        $ev = ['system' => ['folders' => (int)$g['folders'], 'files' => (int)$g['files'], 'total_mb' => $mb],
               'yours' => ['folders' => (int)$mine['folders'], 'files' => (int)$mine['files']]];
        $fb = "The document system holds {$g['files']} file(s) in {$g['folders']} folder(s) ({$mb} MB). You own {$mine['folders']} folder(s) with {$mine['files']} file(s).";
        return [$ev, $fb];
    },
];

/* COMPLIANCE / CERTIFICATIONS up for renewal */
$tools['compliance'] = [
    'match' => fn($ql) => preg_match('/certificat|complian|polic|licen|renewal|expir|rbi/', $ql) === 1,
    'run' => function () use ($db, $ql) {
        $days = asst_horizon_days($ql);
        $list = $db->query("SELECT title, item_type, category, valid_till, DATEDIFF(valid_till, CURDATE()) days_left
                              FROM compliance_items
                             WHERE status='active' AND valid_till IS NOT NULL
                               AND valid_till < DATE_ADD(CURDATE(), INTERVAL $days DAY)
                             ORDER BY valid_till LIMIT 15")->fetchAll();
        $tot = $db->query("SELECT COUNT(*) total, SUM(item_type='policy') policies, SUM(item_type='certification') certs,
                                  SUM(item_type='license') licenses, SUM(item_type='compliance') compliances,
                                  SUM(valid_till IS NOT NULL AND valid_till < CURDATE()) expired
                             FROM compliance_items WHERE status='active'")->fetch();
        $ev = ['registry_totals' => $tot, 'horizon_days' => $days, 'due_within_horizon' => $list];
        $names = array_map(fn($r) => "{$r['title']} ({$r['item_type']}, " . ($r['days_left'] < 0 ? abs($r['days_left']) . 'd OVERDUE' : 'due ' . $r['valid_till']) . ")", $list);
        $months = round($days / 30);
        $fb = count($list)
            ? count($list) . " item(s) up for renewal in the next ~$months month(s): " . implode('; ', $names)
              . ". Registry has {$tot['total']} active item(s)" . ($tot['expired'] > 0 ? ", {$tot['expired']} EXPIRED" : '') . "."
            : "Nothing is due for renewal in the next ~$months month(s). Registry has {$tot['total']} active item(s).";
        return [$ev, $fb];
    },
];

/* SLA / TAT / ESCALATIONS */
$tools['sla'] = [
    'match' => fn($ql) => preg_match('/\bsla\b|\btat\b|breach|overdue|escalat/', $ql) === 1,
    'run' => function () use ($db, $me, $isMgr) {
        if ($isMgr) {
            $t = $db->query("SELECT SUM(status IN('open','in_progress') AND sla_due_at IS NOT NULL AND sla_due_at<NOW()) breached,
                                    SUM(status IN('open','in_progress')) open_total FROM tasks")->fetch();
            $worst = $db->query("SELECT e.full_name, COUNT(*) n FROM tasks t LEFT JOIN employees e ON e.id=t.assigned_to
                                  WHERE t.status IN('open','in_progress') AND t.sla_due_at<NOW()
                                  GROUP BY t.assigned_to ORDER BY n DESC LIMIT 3")->fetchAll();
            $esc = $db->query("SELECT COUNT(*) FROM task_escalations WHERE status='open'")->fetchColumn();
            $ev = ['open_tasks' => (int)$t['open_total'], 'sla_breached_open' => (int)$t['breached'],
                   'open_escalations' => (int)$esc, 'most_breaches' => $worst];
            $names = implode(', ', array_map(fn($w) => "{$w['full_name']} ({$w['n']})", $worst));
            return [$ev, "{$t['breached']} of {$t['open_total']} open task(s) have breached their SLA; $esc escalation(s) are open. Most breaches: $names."];
        }
        $t = $db->query("SELECT SUM(status IN('open','in_progress')) open_total,
                                SUM(status IN('open','in_progress') AND sla_due_at IS NOT NULL AND sla_due_at<NOW()) breached
                           FROM tasks WHERE assigned_to=$me")->fetch();
        $ev = ['your_open_tasks' => (int)$t['open_total'], 'your_breached' => (int)$t['breached']];
        return [$ev, "You have {$t['open_total']} open task(s), {$t['breached']} past their SLA."];
    },
];

/* SETTLEMENTS pipeline — removed from the product 2026-08-06 (module disabled) */

/* FUNNEL: leads / deals / merchants in a period */
$tools['funnel'] = [
    'match' => fn($ql) => preg_match('/\blead|deal|pipeline|funnel|merchant(s| count| onboard)|conversion/', $ql) === 1,
    'run' => function () use ($db, $ql) {
        [$plabel, $from, $to] = asst_period($ql);
        $a1 = [$from, $to]; $s1 = owner_scope('owner_id', $a1);
        $leads = $db->prepare("SELECT COUNT(*) FROM leads WHERE created_at >= ? AND created_at < ? AND $s1"); $leads->execute($a1);
        $a2 = [$from, $to]; $s2 = owner_scope('owner_id', $a2);
        $won = $db->prepare("SELECT COUNT(*) FROM deals WHERE closed_at >= ? AND closed_at < ? AND stage_id IN (SELECT id FROM deal_stages WHERE stage_type='won') AND $s2");
        try { $won->execute($a2); $wonN = (int)$won->fetchColumn(); } catch (Throwable $e) { $wonN = null; }
        $a3 = [$from, $to]; $s3 = owner_scope('owner_id', $a3);
        $act = $db->prepare("SELECT COUNT(*) FROM merchants WHERE activated_at >= ? AND activated_at < ? AND $s3"); $act->execute($a3);
        $tot = $db->query("SELECT (SELECT COUNT(*) FROM leads) leads, (SELECT COUNT(*) FROM merchants WHERE is_active=1) merchants")->fetch();
        $ev = ['period' => $plabel, 'new_leads' => (int)$leads->fetchColumn(), 'deals_won' => $wonN,
               'merchants_activated' => (int)$act->fetchColumn(),
               'all_time' => $tot, 'scope' => visible_owner_ids() === null ? 'organisation-wide' : 'your records only'];
        $fb = "$plabel ({$ev['scope']}): {$ev['new_leads']} new lead(s), " . ($wonN !== null ? "$wonN deal(s) won, " : '')
            . "{$ev['merchants_activated']} merchant(s) activated. All-time: {$tot['leads']} leads, {$tot['merchants']} active merchants.";
        return [$ev, $fb];
    },
];

/* MY TASKS */
$tools['my_tasks'] = [
    'match' => fn($ql) => preg_match('/my (open |pending )?task|to-?do|what.*(do|pending) today/', $ql) === 1,
    'run' => function () use ($db, $me) {
        $rows = $db->query("SELECT title, priority, status, due_at, sla_due_at FROM tasks
                             WHERE assigned_to=$me AND status IN('open','in_progress')
                             ORDER BY FIELD(priority,'urgent','high','medium','low'), sla_due_at IS NULL, sla_due_at LIMIT 8")->fetchAll();
        $ev = ['open_count' => count($rows), 'tasks' => $rows];
        $names = array_map(fn($r) => "{$r['title']} [{$r['priority']}" . ($r['sla_due_at'] && strtotime($r['sla_due_at']) < time() ? ', SLA BREACHED' : '') . "]", $rows);
        return [$ev, count($rows) ? "You have " . count($rows) . " open task(s): " . implode('; ', $names) : "You have no open tasks. 🎉"];
    },
];

/* HEADCOUNT / USERS */
$tools['headcount'] = [
    'match' => fn($ql) => preg_match('/headcount|how many (users|employees|people)|team size|active users/', $ql) === 1,
    'run' => function () use ($db) {
        $rows = $db->query("SELECT role, COUNT(*) n FROM sales_users WHERE is_active=1 GROUP BY role ORDER BY n DESC")->fetchAll();
        $tot = array_sum(array_column($rows, 'n'));
        $ev = ['active_users' => $tot, 'by_role' => $rows];
        return [$ev, "$tot active user(s): " . implode(', ', array_map(fn($r) => "{$r['n']} {$r['role']}", $rows)) . "."];
    },
];

/* ------------------------------- routing --------------------------------- */

$matched = [];
foreach ($tools as $key => $t) {
    if ($t['match']($ql)) $matched[] = $key;
    if (count($matched) >= 3) break;                    // cap the evidence size
}
if (!$matched) {
    // gentle overview so the assistant can still say something useful
    $matched = ['funnel', 'sla'];
}

$evidence = []; $fallbacks = [];
foreach ($matched as $key) {
    try {
        [$ev, $fb] = $tools[$key]['run']();
        $evidence[$key] = $ev;
        $fallbacks[] = $fb;
    } catch (Throwable $e) {
        $evidence[$key] = ['error' => 'metric unavailable'];
    }
}

/* ----------------------------- phrase answer ----------------------------- */

session_write_close();                                   // don't block other tabs during the LLM call

$prompt = "You are the PayG CRM assistant chatting with a " . payg_role_label($role) . ". "
    . "Answer the user's question in natural language using ONLY the EVIDENCE JSON — never invent numbers or names. "
    . "Be concise (2-6 sentences or a short list). Use ₹ with Indian digit grouping for money. "
    . "If the evidence doesn't fully answer the question, answer what you can and say what is out of scope for their role.\n\n"
    . "QUESTION: " . $q . "\n\nEVIDENCE: " . json_encode($evidence, JSON_UNESCAPED_UNICODE);

$answer = null; $source = 'evidence';
$r = crm_ai_call($prompt, 'short');
if ($r && trim((string)$r['response']) !== '') {
    $answer = trim($r['response']);
    $source = $r['source'];
} else {
    $answer = implode("\n\n", $fallbacks) ?: 'I could not compute an answer for that yet.';
}

ok(['answer' => $answer, 'tools' => $matched, 'evidence' => $evidence, 'source' => $source]);
