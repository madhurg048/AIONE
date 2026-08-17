<?php
/**
 * Team timesheet compliance report — "who has not filled, and who keeps not filling".
 *
 *   GET timesheet-compliance.php?from=YYYY-MM-DD&to=YYYY-MM-DD   (default: today)
 *
 * Scope follows the reporting HIERARCHY:
 *   admin / dept heads / HR  -> every employee with an active login
 *   managers (records_all)   -> themselves + their whole reporting line (manager_subtree_ids)
 *   executives               -> 403 (their own status lives on the My Timesheet day view)
 *
 * Returns:
 *   team_size, days[] {date, filled, missing, missing_names[]}   (Sundays excluded)
 *   offenders[] {employee, days_expected, days_missed, miss_pct, last_filled}  worst first
 *   today_missing[]                                              (convenience for the header)
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
$db = db();
$me = current_employee_id();
$role = current_role();

// Any manager-tier role leads a team (IT/Support leads included — they have reports
// even without the sales 'records_all' capability), so tier is the gate here.
$canSee = $role === 'admin'
    || payg_role_tier($role) >= PAYG_TIER_MANAGER
    || payg_role_can($role, 'timesheet_approve_all');   // HR
if (!$canSee) fail('Team compliance is for managers, HR and admins', 403);

/* ---- who is "my team" (hierarchy-scoped) ---- */
$seeAll = $role === 'admin' || payg_role_tier($role) >= PAYG_TIER_HEAD || payg_role_can($role, 'timesheet_approve_all');
$teamIds = null;
if (!$seeAll) {
    $teamIds = array_values(array_unique(array_merge([$me], manager_subtree_ids($me))));
}
$teamWhere = '1';
if ($teamIds !== null) $teamWhere = 'e.id IN (' . implode(',', array_map('intval', $teamIds)) . ')';

$team = $db->query("SELECT e.id, e.full_name, e.designation
                      FROM employees e
                      JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                     WHERE $teamWhere
                     GROUP BY e.id ORDER BY e.full_name")->fetchAll();
if (!$team) ok(['team_size' => 0, 'days' => [], 'offenders' => [], 'today_missing' => []]);
$ids = array_column($team, 'id');
$idIn = implode(',', array_map('intval', $ids));
$nameOf = array_column($team, 'full_name', 'id');

/* ---- date range (default today; capped at 92 days; Sundays excluded) ---- */
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))   ? $_GET['to']   : $from;
if (strtotime($to) < strtotime($from)) { [$from, $to] = [$to, $from]; }
if ((strtotime($to) - strtotime($from)) / 86400 > 92) $from = date('Y-m-d', strtotime($to) - 92 * 86400);
$today = date('Y-m-d');
if ($to > $today) $to = $today;                        // future days can't be "missing" yet
if ($from > $today) $from = $today;

/* ---- one query: who filled what day in range ---- */
$filled = [];   // date => set of employee ids
$rs = $db->query("SELECT DISTINCT entry_date, employee_id FROM timesheets
                   WHERE employee_id IN ($idIn) AND entry_date BETWEEN " . $db->quote($from) . " AND " . $db->quote($to));
foreach ($rs->fetchAll() as $r) $filled[$r['entry_date']][(int)$r['employee_id']] = true;

/* ---- walk the days ---- */
$days = [];
$missTally = array_fill_keys($ids, 0);
$expected = 0;
for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
    if ((int)date('w', $t) === 0) continue;            // Sunday
    $d = date('Y-m-d', $t);
    $expected++;
    $missNames = [];
    foreach ($ids as $eid) {
        if (empty($filled[$d][$eid])) { $missTally[$eid]++; $missNames[] = $nameOf[$eid]; }
    }
    $days[] = ['date' => $d, 'dow' => date('D', $t),
               'filled' => count($ids) - count($missNames), 'missing' => count($missNames),
               'missing_names' => $missNames];
}

/* ---- offenders: who misses most (worst first) ---- */
$lastFilled = [];
$lf = $db->query("SELECT employee_id, MAX(entry_date) d FROM timesheets WHERE employee_id IN ($idIn) GROUP BY employee_id");
foreach ($lf->fetchAll() as $r) $lastFilled[(int)$r['employee_id']] = $r['d'];
$offenders = [];
foreach ($team as $e) {
    $missed = $missTally[$e['id']];
    $offenders[] = ['employee_id' => (int)$e['id'], 'full_name' => $e['full_name'], 'designation' => $e['designation'],
                    'days_expected' => $expected, 'days_missed' => $missed,
                    'miss_pct' => $expected ? round($missed * 100 / $expected) : 0,
                    'last_filled' => $lastFilled[$e['id']] ?? null];
}
usort($offenders, fn($a, $b) => [$b['days_missed'], $a['full_name']] <=> [$a['days_missed'], $b['full_name']]);

/* ---- today's missing (independent of the selected range) ---- */
$todayMissing = [];
if ((int)date('w') !== 0) {
    $tf = $db->query("SELECT DISTINCT employee_id FROM timesheets WHERE employee_id IN ($idIn) AND entry_date=" . $db->quote($today))
             ->fetchAll(PDO::FETCH_COLUMN);
    $tfSet = array_flip(array_map('intval', $tf));
    foreach ($ids as $eid) if (!isset($tfSet[$eid])) $todayMissing[] = $nameOf[$eid];
}

ok([
    'scope'        => $seeAll ? 'everyone' : 'your reporting line',
    'from' => $from, 'to' => $to, 'workdays' => $expected,
    'team_size'    => count($ids),
    'today_missing'=> $todayMissing,
    'days'         => $days,
    'offenders'    => $offenders,
]);
