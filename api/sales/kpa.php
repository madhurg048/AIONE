<?php
/**
 * KPA / KRA goals — org-group definitions with manager → team inheritance.
 *
 *   GET    kpa.php?action=meta                 -> role, my group, my team (subtree), group list
 *   GET    kpa.php?action=org&group=Sales      -> governor: org KPAs for a group (editable)
 *   GET    kpa.php?action=all[&group=]         -> governor: every KPA in the org (oversight)
 *   GET    kpa.php?action=inherited            -> KPAs that apply to ME (org(my group)+ancestors), read-only
 *   GET    kpa.php?action=team                 -> manager: each team member + their resolved KPAs
 *   POST   kpa.php  {scope, org_group|owner_employee_id, title, kra, weight, target_value, unit, period}
 *   PATCH  kpa.php?id=<n>  {title?,kra?,weight?,target_value?,unit?,period?,is_active?}
 *   DELETE kpa.php?id=<n>
 *
 * Rules
 *   • Only managers and above may use this endpoint (require_module('kpa') + role gate).
 *   • Governors (admin + HR) define scope='org' KPAs for any group, see every KPA in the
 *     organisation — including what each department manager sets for their team — and may
 *     edit/delete any of them.
 *   • A department manager may create scope='team' KPAs only for members in their own line
 *     of control (subtree), and may edit/delete only KPAs they created.
 *   • A department manager can never edit an 'org' KPA, nor one set by a supervisor, HR or
 *     the admin (enforced in update/delete).
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_hr_approvals.php';
require_login();

$db   = db();
$role = current_role();
$meId = current_employee_id();

/* -------- goal picker (any logged-in user) --------
 * Returns ONLY the caller's own applicable goals (or a subordinate's, for a manager/admin)
 * so a team member can tag the tasks they submit with a KPA/KRA. Safe to expose before the
 * manager-only module gate because it never reveals goals the caller isn't subject to. */
if (method() === 'GET' && in_array(($_GET['action'] ?? ''), ['my_goals', 'goals_for'], true)) {
    $target = ($_GET['action'] === 'my_goals') ? $meId : (int)($_GET['employee_id'] ?? 0);
    if ($target <= 0) $target = $meId;
    if ($target !== $meId
        && !kpa_is_governor()
        && !(kpa_is_manager_plus() && in_array($target, kpa_subtree_ids($db, $meId), true))) {
        fail("Not authorized to view this employee's goals", 403);
    }
    $grp = ($target === $meId) ? kpa_group_for_role($role) : kpa_group_for_role(kpa_emp_roles($db)[$target] ?? null);
    $out = array_map(fn($g) => [
        'id' => (int)$g['id'], 'title' => $g['title'], 'kra' => $g['kra'],
        'scope' => $g['scope'], 'org_group' => $g['org_group'], 'relation' => $g['relation'] ?? null,
        'target_value' => $g['target_value'] ?? null, 'unit' => $g['unit'] ?? 'text',
        'weight' => isset($g['weight']) ? (float)$g['weight'] : 0, 'period' => $g['period'] ?? 'annual',
        'created_by_name' => $g['created_by_name'] ?? null,
    ], kpa_goals_for_employee($db, $target, $grp));
    ok(['employee_id' => $target, 'goals' => $out]);
}

/* Everything below is managers-and-above only (RBAC-gated). */
require_module('kpa');

/* ---------- role / group helpers ---------- */
const KPA_GROUPS = ['Sales', 'Service', 'HR', 'IT', 'Support'];

function kpa_is_admin(): bool { return current_role() === 'admin'; }

/**
 * KPA governors — full authority over the goal framework: define/edit/delete org KPAs for
 * ANY group, and see every KPA in the organisation (including what each department manager
 * sets for their own team). The Admin, HR (who own performance management company-wide) and
 * the Sales Head — see the 'kpi_govern' capability in _roles.php.
 *
 * Being a governor is about REACH, not final say: an HR Manager / HR Executive governor still
 * has their changes queued for HR Head approval (hr_submit(), _hr_approvals.php).
 */
function kpa_is_governor(): bool
{
    return can_govern_kpi();
}

/** Managers and above may access the page (admin + heads + any manager tier). */
function kpa_is_manager_plus(): bool
{
    return kpa_is_governor() || payg_role_tier(current_role()) >= PAYG_TIER_MANAGER;
}

/**
 * Org group a role belongs to (null = admin / no fixed group).
 * Reads straight off the role registry, whose department names are exactly KPA_GROUPS — so a
 * new role lands in the right group without another switch to remember.
 */
function kpa_group_for_role(?string $role): ?string
{
    return payg_role_dept($role);
}

/* ---------- hierarchy helpers (employees.manager_id) ---------- */
function kpa_all_employees(PDO $db): array
{
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    foreach ($db->query("SELECT id, full_name, designation, manager_id FROM employees")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c[(int)$r['id']] = $r;
    }
    return $c;
}

/** employee_id => active role (from sales_users). */
function kpa_emp_roles(PDO $db): array
{
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    $rows = $db->query("SELECT employee_id, role FROM sales_users WHERE is_active=1 AND employee_id IS NOT NULL")
               ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $c[(int)$r['employee_id']] = $r['role'];
    return $c;
}

/** All descendants of $rootId (the manager's line of control), excluding the root. */
function kpa_subtree_ids(PDO $db, int $rootId): array
{
    $all = kpa_all_employees($db);
    $children = [];
    foreach ($all as $id => $r) {
        $m = (int)($r['manager_id'] ?? 0);
        if ($m) $children[$m][] = (int)$id;
    }
    $out = [];
    $stack = $children[$rootId] ?? [];
    while ($stack) {
        $x = array_pop($stack);
        if (isset($out[$x])) continue;
        $out[$x] = true;
        foreach ($children[$x] ?? [] as $c) $stack[] = $c;
    }
    return array_map('intval', array_keys($out));
}

/** The management chain above $empId (direct manager, their manager, ...). */
function kpa_ancestor_ids(PDO $db, int $empId): array
{
    $all = kpa_all_employees($db);
    $out = [];
    $cur = (int)($all[$empId]['manager_id'] ?? 0);
    $guard = 0;
    while ($cur && !isset($out[$cur]) && $guard++ < 50) {
        $out[$cur] = true;
        $cur = (int)($all[$cur]['manager_id'] ?? 0);
    }
    return array_map('intval', array_keys($out));
}

/** decorate a goal row with creator/owner display names + group. */
function kpa_decorate(PDO $db, array $g): array
{
    $emps = kpa_all_employees($db);
    $g['created_by_name'] = $emps[(int)$g['created_by']]['full_name'] ?? '—';
    $g['owner_name'] = $g['owner_employee_id'] ? ($emps[(int)$g['owner_employee_id']]['full_name'] ?? '—') : null;
    return $g;
}

/**
 * All KPAs that apply to a given employee:
 *   org goals for their group  +  team goals from ancestors targeting them (or whole-team).
 */
function kpa_goals_for_employee(PDO $db, int $empId, ?string $group): array
{
    $rows = [];
    if ($group) {
        $s = $db->prepare("SELECT * FROM kpa_goals WHERE scope='org' AND org_group=? AND is_active=1 ORDER BY id");
        $s->execute([$group]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $g) { $g['relation'] = 'org'; $rows[] = $g; }
    }
    $anc = kpa_ancestor_ids($db, $empId);
    if ($anc) {
        $in = implode(',', array_fill(0, count($anc), '?'));
        $s = $db->prepare("SELECT * FROM kpa_goals
                            WHERE scope='team' AND is_active=1
                              AND created_by IN ($in)
                              AND (owner_employee_id = ? OR owner_employee_id IS NULL)
                            ORDER BY id");
        $s->execute(array_merge($anc, [$empId]));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $g) { $g['relation'] = 'manager'; $rows[] = $g; }
    }
    return array_map(fn($g) => kpa_decorate($db, $g), $rows);
}

/* -------- hours attribution (from tasks tagged to a goal) --------
 * $empScope: null = all employees (admin); [] = none; [ids] = restrict to these assignees. */
function kpa_hours_for_goal(PDO $db, int $goalId, ?array $empScope): float
{
    $sql = "SELECT COALESCE(SUM(hours_spent),0) FROM tasks WHERE kpa_goal_id=?";
    $args = [$goalId];
    if ($empScope !== null) {
        if (!$empScope) return 0.0;
        $sql .= " AND assigned_to IN (" . implode(',', array_fill(0, count($empScope), '?')) . ")";
        $args = array_merge($args, $empScope);
    }
    $s = $db->prepare($sql);
    $s->execute($args);
    return (float)$s->fetchColumn();
}

/** Per-person hours on a goal. $onlyEmp restricts to one person; else $empScope applies. */
function kpa_contrib(PDO $db, int $goalId, ?array $empScope, ?int $onlyEmp): array
{
    $sql = "SELECT t.assigned_to AS employee_id, e.full_name AS name,
                   COALESCE(SUM(t.hours_spent),0) AS hours, COUNT(*) AS task_count
            FROM tasks t LEFT JOIN employees e ON e.id=t.assigned_to
            WHERE t.kpa_goal_id=?";
    $args = [$goalId];
    if ($onlyEmp !== null) {
        $sql .= " AND t.assigned_to=?"; $args[] = $onlyEmp;
    } elseif ($empScope !== null) {
        if (!$empScope) return [];
        $sql .= " AND t.assigned_to IN (" . implode(',', array_fill(0, count($empScope), '?')) . ")";
        $args = array_merge($args, $empScope);
    }
    $sql .= " GROUP BY t.assigned_to, e.full_name ORDER BY hours DESC";
    $s = $db->prepare($sql);
    $s->execute($args);
    return array_map(fn($r) => ['employee_id' => (int)$r['employee_id'], 'name' => $r['name'],
        'hours' => (float)$r['hours'], 'task_count' => (int)$r['task_count']], $s->fetchAll(PDO::FETCH_ASSOC));
}

/** Contributing task rows for a goal, restricted to $empIds. */
function kpa_tasks_for(PDO $db, int $goalId, array $empIds): array
{
    if (!$empIds) return [];
    $in = implode(',', array_fill(0, count($empIds), '?'));
    $s = $db->prepare("SELECT t.id, t.title, t.status, t.hours_spent, t.assigned_to,
                              e.full_name AS name, t.completed_at, t.updated_at
                       FROM tasks t LEFT JOIN employees e ON e.id=t.assigned_to
                       WHERE t.kpa_goal_id=? AND t.assigned_to IN ($in)
                       ORDER BY t.hours_spent DESC, t.updated_at DESC");
    $s->execute(array_merge([$goalId], $empIds));
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Attach a scoped `hours` total to each goal row. */
function kpa_with_hours(PDO $db, array $rows, ?array $empScope): array
{
    foreach ($rows as &$g) $g['hours'] = kpa_hours_for_goal($db, (int)$g['id'], $empScope);
    return $rows;
}

/* ============================ GET ============================ */
if (method() === 'GET') {
    $action = $_GET['action'] ?? 'meta';

    if ($action === 'contrib') {                 // hours breakdown for one goal (who spent how much)
        if (!kpa_is_manager_plus()) fail('Managers only', 403);
        $goalId = (int)($_GET['goal_id'] ?? 0);
        if (!$goalId) fail('Missing goal_id');
        $onlyEmp = (isset($_GET['employee_id']) && $_GET['employee_id'] !== '') ? (int)$_GET['employee_id'] : null;
        // scope: a governor sees all contributors; a manager sees their own unit (self + subtree)
        $scope = kpa_is_governor() ? null : array_values(array_unique(array_merge([$meId], kpa_subtree_ids($db, $meId))));
        if ($onlyEmp !== null && $scope !== null && !in_array($onlyEmp, $scope, true)) {
            fail('That employee is outside your team', 403);
        }
        $people = kpa_contrib($db, $goalId, $scope, $onlyEmp);
        $empIds = $onlyEmp !== null ? [$onlyEmp]
            : ($scope === null ? array_map(fn($p) => $p['employee_id'], $people) : $scope);
        $tasks = kpa_tasks_for($db, $goalId, $empIds ?: [-1]);
        ok([
            'goal_id'     => $goalId,
            'total_hours' => array_sum(array_map(fn($p) => $p['hours'], $people)),
            'people'      => $people,
            'tasks'       => $tasks,
        ]);
    }

    if ($action === 'meta') {
        $isAdmin = kpa_is_admin();
        $myGroup = kpa_group_for_role($role);
        $emps = kpa_all_employees($db);
        $roles = kpa_emp_roles($db);
        $team = [];
        if (!$isAdmin) {   // HR governors still lead a team of their own — keep it
            foreach (kpa_subtree_ids($db, $meId) as $id) {
                $r = $roles[$id] ?? null;
                $team[] = [
                    'id'          => $id,
                    'name'        => $emps[$id]['full_name'] ?? ('#' . $id),
                    'designation' => $emps[$id]['designation'] ?? '',
                    'role'        => $r,
                    'group'       => kpa_group_for_role($r),
                ];
            }
        }
        ok([
            'role'            => $role,
            'is_admin'        => $isAdmin,
            'is_governor'     => kpa_is_governor(),
            'is_manager_plus' => kpa_is_manager_plus(),
            'my_employee_id'  => $meId,
            'my_group'        => $myGroup,
            'groups'          => KPA_GROUPS,
            'team'            => $team,
        ]);
    }

    if ($action === 'org') {                 // governor: org KPAs for one group (editable)
        if (!kpa_is_governor()) fail('Only an admin or HR can view organization KPAs by group', 403);
        $group = $_GET['group'] ?? '';
        if (!in_array($group, KPA_GROUPS, true)) fail('Unknown org group');
        $s = $db->prepare("SELECT * FROM kpa_goals WHERE scope='org' AND org_group=? ORDER BY id");
        $s->execute([$group]);
        $rows = array_map(fn($g) => kpa_decorate($db, $g) + ['can_edit' => true], $s->fetchAll(PDO::FETCH_ASSOC));
        ok(['group' => $group, 'goals' => kpa_with_hours($db, $rows, null)]);   // admin: hours across everyone
    }

    if ($action === 'all') {                 // governor: entire org (oversight)
        if (!kpa_is_governor()) fail('Only an admin or HR can view the whole organization', 403);
        $group = $_GET['group'] ?? '';
        $sql = "SELECT * FROM kpa_goals";
        $args = [];
        if (in_array($group, KPA_GROUPS, true)) {
            // group filter applies to org goals directly, and to team goals whose owner is in that group
            $roleEmps = [];
            foreach (kpa_emp_roles($db) as $eid => $r) if (kpa_group_for_role($r) === $group) $roleEmps[] = (int)$eid;
            $ownerIn = $roleEmps ? (' OR owner_employee_id IN (' . implode(',', $roleEmps) . ')') : '';
            $sql .= " WHERE (scope='org' AND org_group=?)" . " OR (scope='team' AND (1=0$ownerIn))";
            $args[] = $group;
        }
        $sql .= " ORDER BY scope, org_group, id";
        $s = $db->prepare($sql);
        $s->execute($args);
        // a governor may act on anything here — including the team KPAs a department manager set
        $rows = array_map(fn($g) => kpa_decorate($db, $g) + ['can_edit' => true], $s->fetchAll(PDO::FETCH_ASSOC));
        ok(['goals' => kpa_with_hours($db, $rows, null)]);   // admin: hours across everyone
    }

    if ($action === 'inherited') {           // KPAs that apply to ME (read-only view)
        $rows = kpa_goals_for_employee($db, $meId, kpa_group_for_role($role));
        foreach ($rows as &$g) $g['can_edit'] = false;
        unset($g);
        ok(['goals' => kpa_with_hours($db, $rows, [$meId])]);   // my own hours on each goal
    }

    if ($action === 'team') {                // manager: each team member + resolved KPAs
        if (!kpa_is_manager_plus()) fail('Managers only', 403);
        $emps = kpa_all_employees($db);
        $roles = kpa_emp_roles($db);
        $members = [];
        foreach (kpa_subtree_ids($db, $meId) as $id) {
            $r = $roles[$id] ?? null;
            $goals = kpa_goals_for_employee($db, $id, kpa_group_for_role($r));
            foreach ($goals as &$g) {
                // a manager may edit only the team KPAs they themselves created
                $g['can_edit'] = ($g['scope'] === 'team' && (int)$g['created_by'] === $meId);
            }
            unset($g);
            $goals = kpa_with_hours($db, $goals, [$id]);   // this member's hours on each goal
            $members[] = [
                'id'          => $id,
                'name'        => $emps[$id]['full_name'] ?? ('#' . $id),
                'designation' => $emps[$id]['designation'] ?? '',
                'role'        => $r,
                'group'       => kpa_group_for_role($r),
                'goals'       => $goals,
            ];
        }
        ok(['members' => $members]);
    }

    fail('Unknown action');
}

/* ============================ POST (create) ============================ */
if (method() === 'POST') {
    if (!kpa_is_manager_plus()) fail('You do not have permission to define KPAs', 403);
    $b = body();
    $scope = ($b['scope'] ?? 'team') === 'org' ? 'org' : 'team';
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') fail('Title (the KPA) is required');

    $orgGroup = null;
    $owner = null;

    if ($scope === 'org') {
        if (!kpa_is_governor()) fail('Only an admin or HR can define organization (org-group) KPAs', 403);
        $orgGroup = $b['org_group'] ?? '';
        if (!in_array($orgGroup, KPA_GROUPS, true)) fail('Choose a valid org group');
    } else {
        // team KPA — target one member (must be in my line of control) or my whole team (null)
        $ownerRaw = $b['owner_employee_id'] ?? null;
        if ($ownerRaw !== null && $ownerRaw !== '' && (int)$ownerRaw !== 0) {
            $owner = (int)$ownerRaw;
            if (!kpa_is_governor() && !in_array($owner, kpa_subtree_ids($db, $meId), true)) {
                fail('You can only set KPAs for members of your own team', 403);
            }
        }
    }

    $unit = in_array($b['unit'] ?? '', ['inr', 'pct', 'count', 'flag', 'text'], true) ? $b['unit'] : 'text';
    $payload = [
        'scope'             => $scope,
        'org_group'         => $orgGroup,
        'owner_employee_id' => $owner,
        'created_by'        => $meId,
        'created_by_role'   => $role,
        'title'             => $title,
        'kra'               => trim((string)($b['kra'] ?? '')) ?: null,
        'weight'            => (float)($b['weight'] ?? 0),
        'target_value'      => trim((string)($b['target_value'] ?? '')) ?: null,
        'unit'              => $unit,
        'period'            => trim((string)($b['period'] ?? 'annual')) ?: 'annual',
    ];
    $summary = ($scope === 'org' ? "Add $orgGroup org KPA: " : 'Add team KPA: ') . $title;
    ok(hr_submit($db, 'kpa.create', $payload, $summary, $orgGroup ?: $title));
}

/* ============================ PATCH (update) ============================ */
if (method() === 'PATCH') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('Missing id');
    $s = $db->prepare("SELECT * FROM kpa_goals WHERE id=?");
    $s->execute([$id]);
    $goal = $s->fetch(PDO::FETCH_ASSOC);
    if (!$goal) fail('KPA not found', 404);

    // Permission: org KPAs only by admin; team KPAs only by their creator (or admin).
    if ($goal['scope'] === 'org') {
        if (!kpa_is_governor()) fail('Only an admin or HR can edit an organization KPA', 403);
    } else {
        if (!kpa_is_governor() && (int)$goal['created_by'] !== $meId) {
            fail('You can only edit KPAs you created — not those set by a supervisor, HR or admin', 403);
        }
    }

    $b = body();
    $fields = [];
    foreach (['title', 'kra', 'target_value', 'period'] as $f) {
        if (array_key_exists($f, $b)) $fields[$f] = trim((string)$b[$f]) ?: null;
    }
    if (array_key_exists('weight', $b))    $fields['weight']    = (float)$b['weight'];
    if (array_key_exists('is_active', $b)) $fields['is_active'] = ((int)$b['is_active']) ? 1 : 0;
    if (array_key_exists('unit', $b) && in_array($b['unit'], ['inr','pct','count','flag','text'], true)) {
        $fields['unit'] = $b['unit'];
    }
    if (!$fields) fail('Nothing to update');

    $what = ($goal['scope'] === 'org' ? $goal['org_group'] . ' org KPA' : 'team KPA');
    ok(hr_submit($db, 'kpa.update', ['id' => $id, 'fields' => $fields],
        "Edit $what: " . $goal['title'], $goal['title']));
}

/* ============================ DELETE ============================ */
if (method() === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('Missing id');
    $s = $db->prepare("SELECT * FROM kpa_goals WHERE id=?");
    $s->execute([$id]);
    $goal = $s->fetch(PDO::FETCH_ASSOC);
    if (!$goal) fail('KPA not found', 404);

    if ($goal['scope'] === 'org') {
        if (!kpa_is_governor()) fail('Only an admin or HR can delete an organization KPA', 403);
    } else {
        if (!kpa_is_governor() && (int)$goal['created_by'] !== $meId) {
            fail('You can only delete KPAs you created', 403);
        }
    }
    $what = ($goal['scope'] === 'org' ? $goal['org_group'] . ' org KPA' : 'team KPA');
    ok(hr_submit($db, 'kpa.delete', ['id' => $id],
        "Delete $what: " . $goal['title'], $goal['title']));
}

fail('Unsupported method', 405);
