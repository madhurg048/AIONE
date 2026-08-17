<?php
/**
 * Org chart — read-only staff directory tree, open to every logged-in user.
 * (Editing the reporting line stays in org.php, which is HR/admin gated.)
 *
 *   GET orgchart.php  -> { people[], roots[], me, stats }
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
$db = db();

$people = $db->query("
    SELECT e.id, e.full_name, e.designation, e.department, e.sub_department,
           e.manager_id, e.email, e.emp_type, e.governance,
           su.role, su.is_active AS login_active
      FROM employees e
      LEFT JOIN sales_users su ON su.employee_id = e.id
     ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);

$byId = [];
foreach ($people as $p) $byId[(int)$p['id']] = $p;

// direct-report counts + role labels
$kids = [];
foreach ($people as $p) {
    $m = (int)($p['manager_id'] ?? 0);
    if ($m && isset($byId[$m])) $kids[$m][] = (int)$p['id'];
}
foreach ($people as &$p) {
    $p['id'] = (int)$p['id'];
    $p['manager_id'] = $p['manager_id'] !== null ? (int)$p['manager_id'] : null;
    // a manager_id pointing at a deleted person would orphan the branch — treat as a root
    if ($p['manager_id'] !== null && !isset($byId[$p['manager_id']])) $p['manager_id'] = null;
    $p['reports'] = count($kids[$p['id']] ?? []);
    $p['role_label'] = $p['role'] ? payg_role_label($p['role']) : null;
}
unset($p);

$roots = array_values(array_map(fn($p) => $p['id'], array_filter($people, fn($p) => $p['manager_id'] === null)));

$orgName = 'Organisation';
try {
    $n = $db->query("SELECT org_name FROM org_profile WHERE id=1")->fetchColumn();
    if ($n) $orgName = $n;
} catch (Throwable $e) { /* org_profile is optional */ }

ok([
    'people'   => $people,
    'roots'    => $roots,
    'org_name' => $orgName,
    'me'       => current_employee_id(),
    'stats'  => [
        'headcount'   => count($people),
        'managers'    => count($kids),
        'departments' => count(array_unique(array_filter(array_column($people, 'department')))),
        'unassigned'  => count(array_filter($people, fn($p) => empty($p['department']))),
    ],
]);
