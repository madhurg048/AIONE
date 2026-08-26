<?php
/**
 * KRA / KPI libraries — the reusable catalogue behind the goal records.
 *
 * One kpa_goals row carries all three faces of a goal (KPA = the area,
 * KRA = the outcome, KPI = the measure), so both libraries read the same table
 * through different lenses and share create/update. Counts come from
 * governance_links, which is where KRA→KPI→SLA→ESC traceability already lives.
 *
 *   GET  kra-library.php?view=kra|kpi[&status=&department=&type=&q=]
 *   GET  kra-library.php?meta=1          -> types, departments, roles, cycle
 *   POST kra-library.php?view=kra|kpi    -> create
 *   PATCH kra-library.php?id=N           -> edit (records a governance revision)
 *   POST kra-library.php?action=archive|restore&id=N
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_governance.php';
require_login();
require_module('kpa');
$db = db();

$KRA_TYPES = ['Operational', 'Service', 'Financial', 'Compliance', 'People'];
$KPI_TYPES = ['Efficiency', 'Service', 'Quality', 'Growth', 'Risk'];
// The KRA/KPI library is the COMPANY-WIDE master catalogue, so writing to it is a governance
// capability (Admin, HR, Sales Head, CFO), not a record-visibility one. can_manage_all_records()
// was also letting every Sales/Service/Finance Manager rewrite definitions that bind other
// departments; a Manager now consumes the library and applies it in their team's scorecards.
$canWrite = payg_role_can(current_role(), 'kpi_govern');

/** Appraisal cycle banner — Indian FY, derived from today. */
function kra_cycle(): array
{
    $y = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
    return ['label' => 'FY ' . $y . '-' . substr((string)($y + 1), 2)
                     . ' (Apr ' . $y . ' – Mar ' . ($y + 1) . ')', 'status' => 'OPEN'];
}

if (method() === 'GET' && isset($_GET['meta'])) {
    ok([
        'kra_types' => $KRA_TYPES,
        'kpi_types' => $KPI_TYPES,
        'units'     => ['inr', 'pct', 'count', 'flag', 'text'],
        'departments' => $db->query("SELECT DISTINCT department FROM org_departments WHERE is_active=1 ORDER BY department")->fetchAll(PDO::FETCH_COLUMN),
        'sub_departments' => $db->query("SELECT department, sub_department FROM org_departments WHERE is_active=1 AND sub_department IS NOT NULL ORDER BY department, sub_department")->fetchAll(),
        'roles'     => array_map(fn($k) => ['key' => $k, 'label' => payg_role_label($k)], array_keys(payg_roles())),
        'slas'      => $db->query("SELECT id, sla_ref, name FROM sla_policies WHERE status='active' ORDER BY sla_ref")->fetchAll(),
        // scorecard KPI catalog (api/sales/scorecards.php) — offered so a governor can tie a
        // library entry to the live KPI it represents. Informational only: linking does not
        // change how scorecards.php scores, weights or gates that KPI.
        'scorecard_kpis' => $db->query("SELECT code, name FROM kpi_definitions WHERE is_active=1 ORDER BY sort_order")->fetchAll(),
        'cycle'     => kra_cycle(),
        'can_write' => $canWrite,
    ]);
}

if (method() === 'GET') {
    $view = ($_GET['view'] ?? 'kra') === 'kpi' ? 'kpi' : 'kra';
    $w = ['1']; $args = [];
    if (!empty($_GET['status']))     { $w[] = 'k.status=?';   $args[] = $_GET['status']; }
    if (!empty($_GET['department'])) { $w[] = 'k.org_group=?'; $args[] = $_GET['department']; }
    if (!empty($_GET['type']))       { $w[] = ($view === 'kpi' ? 'k.kpi_type=?' : 'k.kra_type=?'); $args[] = $_GET['type']; }
    if (!empty($_GET['q'])) {
        $w[] = '(k.title LIKE ? OR k.kra LIKE ? OR k.description LIKE ?)';
        $like = '%' . $_GET['q'] . '%'; array_push($args, $like, $like, $like);
    }
    if ($view === 'kpi') $w[] = "(k.target_value IS NOT NULL AND k.target_value <> '')";
    $where = implode(' AND ', $w);

    $s = $db->prepare("
        SELECT k.*, e.full_name AS owner_name,
               (SELECT COUNT(*) FROM governance_links l
                 WHERE l.from_type='kra' AND l.from_id=k.id AND l.to_type='kpi') AS kpi_count,
               (SELECT COUNT(*) FROM governance_links l
                 WHERE l.to_type='kpi' AND l.to_id=k.id AND l.from_type='kra') AS kra_count,
               (SELECT COUNT(*) FROM governance_links l
                 WHERE l.from_type='kpi' AND l.from_id=k.id AND l.to_type='sla') AS sla_count,
               (SELECT COUNT(*) FROM governance_links l
                 WHERE l.from_id=k.id AND l.from_type IN('kpi','kra') AND l.to_type='esc') AS esc_direct,
               (SELECT COUNT(DISTINCT l2.to_id) FROM governance_links l1
                  JOIN governance_links l2 ON l2.from_type='sla' AND l2.from_id=l1.to_id AND l2.to_type='esc'
                 WHERE l1.from_type='kpi' AND l1.from_id=k.id AND l1.to_type='sla') AS esc_via_sla,
               (SELECT r.created_at FROM governance_revisions r
                 WHERE r.obj_type='kpa' AND r.obj_id=k.id ORDER BY r.id DESC LIMIT 1) AS last_change,
               (SELECT emp.full_name FROM governance_revisions r LEFT JOIN employees emp ON emp.id=r.changed_by
                 WHERE r.obj_type='kpa' AND r.obj_id=k.id ORDER BY r.id DESC LIMIT 1) AS last_change_by,
               COALESCE(a.status,'draft') AS approval_status
          FROM kpa_goals k
          LEFT JOIN employees e ON e.id = k.owner_employee_id
          LEFT JOIN governance_approvals a ON a.obj_type='kpa' AND a.obj_id=k.id
         WHERE $where ORDER BY k.id");
    $s->execute($args);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['code']        = $view === 'kpi' ? $r['kpi_ref'] : $r['kra_ref'];
        $r['name']        = $view === 'kpi' ? ($r['target_value'] . ' ' . $r['unit']) : ($r['kra'] ?: $r['title']);
        $r['type']        = $view === 'kpi' ? $r['kpi_type'] : $r['kra_type'];
        $r['esc_count']   = (int)$r['esc_direct'] + (int)$r['esc_via_sla'];
        $r['linked_kpi_code'] = $r['linked_kpi_code'] ?? null;
        $r['roles']       = array_values(array_filter(array_map('trim', explode(',', (string)$r['linked_roles']))));
        $r['role_labels'] = array_map(fn($x) => payg_role_label($x), $r['roles']);
    }
    unset($r);

    // the KPI strip counts the whole library, not the filtered page
    $tot = $db->query("SELECT COUNT(*) total,
                              SUM(status='active') active_cnt,
                              SUM(status='draft') draft_cnt,
                              SUM(status='archived') archived_cnt
                         FROM kpa_goals" . ($view === 'kpi' ? " WHERE target_value IS NOT NULL AND target_value <> ''" : ''))
              ->fetch(PDO::FETCH_ASSOC);
    ok(['view' => $view, 'rows' => $rows, 'totals' => $tot, 'cycle' => kra_cycle(), 'can_write' => $canWrite]);
}

if (method() === 'POST' && !isset($_GET['action'])) {
    if (!$canWrite) fail('You are not authorized to define KRAs or KPIs', 403);
    $b = body();
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') fail('A name is required');
    $roles = is_array($b['linked_roles'] ?? null) ? implode(',', array_filter($b['linked_roles'], 'payg_role_exists')) : null;
    // Optional tie to the live Sales scorecard KPI catalog — validated against kpi_definitions
    // so a stray code can't be saved; NULL (unlinked) stays the default and is unaffected.
    $linkedKpi = trim((string)($b['linked_kpi_code'] ?? ''));
    if ($linkedKpi !== '') {
        $chk = $db->prepare("SELECT 1 FROM kpi_definitions WHERE code=?");
        $chk->execute([$linkedKpi]);
        if (!$chk->fetchColumn()) fail('Unknown scorecard KPI code', 400);
    } else { $linkedKpi = null; }
    $db->prepare("INSERT INTO kpa_goals
                    (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight,
                     target_value, unit, period, is_active, kra_type, kpi_type, description, status,
                     tat_deadline, linked_roles, sla_policy_id, linked_kpi_code)
                  VALUES ('org',?,?,?,?,?,?,?,?,?,?,1,?,?,?,?,?,?,?,?)")
       ->execute([($b['department'] ?? null) ?: null, ($b['owner_employee_id'] ?? null) ?: null,
                  current_employee_id() ?: null, current_role(),
                  $title, ($b['kra'] ?? null) ?: null, (float)($b['weight'] ?? 0),
                  ($b['target_value'] ?? null) ?: null,
                  in_array($b['unit'] ?? '', ['inr','pct','count','flag','text'], true) ? $b['unit'] : 'text',
                  ($b['period'] ?? 'annual') ?: 'annual',
                  ($b['kra_type'] ?? null) ?: null, ($b['kpi_type'] ?? null) ?: null,
                  ($b['description'] ?? null) ?: null,
                  in_array($b['status'] ?? 'draft', ['active','draft','archived'], true) ? ($b['status'] ?? 'draft') : 'draft',
                  ($b['tat_deadline'] ?? null) ?: null, $roles ?: null,
                  ($b['sla_policy_id'] ?? null) ?: null, $linkedKpi]);
    $id = (int)$db->lastInsertId();
    gov_assign_code($db, 'kpa_goals', $id,
        gov_domain_for($title . ' ' . (string)($b['kra'] ?? '') . ' ' . (string)($b['kra_type'] ?? ''), $b['department'] ?? null));
    gov_approval($db, 'kpa', $id, true);
    gov_revise($db, 'kpa', $id, 'created', 'library entry "' . $title . '" created');
    ok(['id' => $id, 'kra_ref' => gov_code($db, 'kra', $id), 'kpi_ref' => gov_code($db, 'kpi', $id)]);
}

if (method() === 'PATCH' || method() === 'PUT') {
    if (!$canWrite) fail('You are not authorized to edit KRAs or KPIs', 403);
    $id = (int)($_GET['id'] ?? 0); if (!$id) fail('id required');
    $b = body();
    $set = []; $args = [];
    foreach (['title','kra','description','kra_type','kpi_type','target_value','unit','period','tat_deadline','org_group'] as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = ($b[$f] === '' ? null : $b[$f]); }
    }
    if (array_key_exists('weight', $b)) { $set[] = 'weight=?'; $args[] = (float)$b['weight']; }
    if (array_key_exists('sla_policy_id', $b)) { $set[] = 'sla_policy_id=?'; $args[] = $b['sla_policy_id'] ?: null; }
    if (array_key_exists('linked_kpi_code', $b)) {
        $lk = trim((string)($b['linked_kpi_code'] ?? ''));
        if ($lk !== '') {
            $chk = $db->prepare("SELECT 1 FROM kpi_definitions WHERE code=?");
            $chk->execute([$lk]);
            if (!$chk->fetchColumn()) fail('Unknown scorecard KPI code', 400);
        } else { $lk = null; }
        $set[] = 'linked_kpi_code=?'; $args[] = $lk;
    }
    if (array_key_exists('status', $b) && in_array($b['status'], ['active','draft','archived'], true)) {
        $set[] = 'status=?'; $args[] = $b['status'];
        $set[] = 'is_active=?'; $args[] = $b['status'] === 'active' ? 1 : 0;
    }
    if (array_key_exists('linked_roles', $b)) {
        $set[] = 'linked_roles=?';
        $args[] = is_array($b['linked_roles']) ? (implode(',', array_filter($b['linked_roles'], 'payg_role_exists')) ?: null) : null;
    }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE kpa_goals SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    gov_revise($db, 'kpa', $id, 'updated', 'library entry edited: ' . implode(', ', array_map(fn($x) => explode('=', $x)[0], $set)));
    gov_touch($db, 'kpa', $id);
    ok(['id' => $id]);
}

if (method() === 'POST' && isset($_GET['action'])) {
    if (!$canWrite) fail('Not authorized', 403);
    $id = (int)($_GET['id'] ?? 0); if (!$id) fail('id required');
    $to = $_GET['action'] === 'restore' ? 'active' : 'archived';
    $db->prepare("UPDATE kpa_goals SET status=?, is_active=? WHERE id=?")->execute([$to, $to === 'active' ? 1 : 0, $id]);
    gov_revise($db, 'kpa', $id, 'updated', $to === 'active' ? 'restored from archive' : 'archived');
    ok(['status' => $to]);
}

fail('Unsupported request', 405);
