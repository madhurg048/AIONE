<?php
/**
 * Employee groups — multi-membership teams that GRANT module access on top of
 * the member's role (e.g. put someone in "Compliance Team" and they get the
 * compliance module regardless of their role's RBAC row).
 *
 *   GET  groups.php?list=1          -> groups + member counts + module grants
 *   GET  groups.php?id=N            -> one group (members + access)
 *   GET  groups.php?employees=1     -> employee picker (with roles)
 *   GET  groups.php?mine=1          -> group ids + modules of the current user
 *   POST groups.php                 -> create group {name, description, modules:[], members:[]}
 *   PATCH groups.php?id=N           -> update {name?, description?, is_active?}
 *   POST groups.php?id=N&members=1  -> {add:[employee_ids], remove:[employee_ids]}
 *   POST groups.php?id=N&access=1   -> {modules:[module_keys]}  (replaces the set)
 *   DELETE groups.php?id=N          -> deactivate
 *
 * Managing groups = managing who can see what -> admin + HR ('people' cap).
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
$db = db();

$canManage = current_role() === 'admin' || payg_role_can(current_role(), 'people');

if (method() === 'GET' && isset($_GET['mine'])) {
    $me = current_employee_id();
    $s = $db->prepare("SELECT g.id, g.name FROM emp_groups g
                         JOIN emp_group_members m ON m.group_id=g.id
                        WHERE m.employee_id=? AND g.is_active=1");
    $s->execute([$me]);
    ok($s->fetchAll());
}

if (!$canManage) fail('Managing groups requires admin or HR', 403);

if (method() === 'GET' && isset($_GET['memberships'])) {
    // employee_id -> [group ids]; feeds the People grid's Groups column
    $out = [];
    foreach ($db->query("SELECT m.employee_id, m.group_id FROM emp_group_members m
                           JOIN emp_groups g ON g.id=m.group_id WHERE g.is_active=1")->fetchAll() as $r) {
        $out[(int)$r['employee_id']][] = (int)$r['group_id'];
    }
    ok($out);
}

if (method() === 'GET' && isset($_GET['employees'])) {
    ok($db->query("SELECT e.id, e.full_name, e.designation, su.role
                     FROM employees e LEFT JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                    ORDER BY e.full_name")->fetchAll());
}

if (method() === 'GET' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $g = $db->prepare("SELECT * FROM emp_groups WHERE id=?"); $g->execute([$id]);
    $grp = $g->fetch();
    if (!$grp) fail('Group not found', 404);
    $m = $db->prepare("SELECT m.employee_id, e.full_name, e.designation, su.role
                         FROM emp_group_members m
                         JOIN employees e ON e.id=m.employee_id
                    LEFT JOIN sales_users su ON su.employee_id=e.id AND su.is_active=1
                        WHERE m.group_id=? ORDER BY e.full_name");
    $m->execute([$id]);
    $grp['members'] = $m->fetchAll();
    $a = $db->prepare("SELECT module_key FROM emp_group_access WHERE group_id=? AND can_access=1");
    $a->execute([$id]);
    $grp['modules'] = $a->fetchAll(PDO::FETCH_COLUMN);
    ok($grp);
}

if (method() === 'GET') { // list
    ok($db->query("SELECT g.*,
                          (SELECT COUNT(*) FROM emp_group_members m WHERE m.group_id=g.id) AS member_count,
                          (SELECT GROUP_CONCAT(a.module_key ORDER BY a.module_key SEPARATOR ',')
                             FROM emp_group_access a WHERE a.group_id=g.id AND a.can_access=1) AS modules
                     FROM emp_groups g ORDER BY g.is_active DESC, g.name")->fetchAll());
}

if (method() === 'POST' && !isset($_GET['id'])) { // create
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') fail('name required');
    try {
        $db->prepare("INSERT INTO emp_groups (name, description, created_by) VALUES (?,?,?)")
           ->execute([$name, $b['description'] ?? null, current_employee_id() ?: null]);
    } catch (Throwable $e) { fail('A group with that name already exists'); }
    $id = (int)$db->lastInsertId();
    foreach (array_unique(array_map('intval', $b['members'] ?? [])) as $emp) {
        if ($emp) $db->prepare("INSERT IGNORE INTO emp_group_members (group_id, employee_id, added_by) VALUES (?,?,?)")
                     ->execute([$id, $emp, current_employee_id() ?: null]);
    }
    foreach (array_unique((array)($b['modules'] ?? [])) as $mk) {
        if (preg_match('/^[a-z_]{2,40}$/', $mk))
            $db->prepare("INSERT IGNORE INTO emp_group_access (group_id, module_key) VALUES (?,?)")->execute([$id, $mk]);
    }
    ok(['id' => $id]);
}

if ((method() === 'PATCH' || method() === 'PUT') && isset($_GET['id'])) {
    $id = (int)$_GET['id']; $b = body();
    $set = []; $args = [];
    if (array_key_exists('name', $b) && trim($b['name']) !== '') { $set[] = "name=?"; $args[] = trim($b['name']); }
    if (array_key_exists('description', $b)) { $set[] = "description=?"; $args[] = ($b['description'] ?? null) ?: null; }
    if (array_key_exists('is_active', $b))   { $set[] = "is_active=?";   $args[] = (int)!!$b['is_active']; }
    if (array_key_exists('grant_all', $b)) {
        // FULL ACCESS is the platform's master key — only the admin may hand it out
        if (current_role() !== 'admin') fail('Only the admin can grant or revoke FULL ACCESS', 403);
        $set[] = "grant_all=?"; $args[] = (int)!!$b['grant_all'];
    }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE emp_groups SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    ok();
}

if (method() === 'POST' && isset($_GET['id'], $_GET['members'])) {
    $id = (int)$_GET['id']; $b = body();
    foreach (array_unique(array_map('intval', $b['add'] ?? [])) as $emp) {
        if ($emp) $db->prepare("INSERT IGNORE INTO emp_group_members (group_id, employee_id, added_by) VALUES (?,?,?)")
                     ->execute([$id, $emp, current_employee_id() ?: null]);
    }
    foreach (array_unique(array_map('intval', $b['remove'] ?? [])) as $emp) {
        $db->prepare("DELETE FROM emp_group_members WHERE group_id=? AND employee_id=?")->execute([$id, $emp]);
    }
    ok();
}

if (method() === 'POST' && isset($_GET['id'], $_GET['access'])) {
    $id = (int)$_GET['id']; $b = body();
    $mods = array_values(array_unique(array_filter((array)($b['modules'] ?? []),
        fn($m) => preg_match('/^[a-z_]{2,40}$/', $m))));
    $db->prepare("DELETE FROM emp_group_access WHERE group_id=?")->execute([$id]);
    foreach ($mods as $mk) {
        $db->prepare("INSERT INTO emp_group_access (group_id, module_key) VALUES (?,?)")->execute([$id, $mk]);
    }
    ok(['modules' => $mods]);
}

if (method() === 'DELETE' && isset($_GET['id'])) {
    $db->prepare("UPDATE emp_groups SET is_active=0 WHERE id=?")->execute([(int)$_GET['id']]);
    ok();
}

fail('Unsupported request', 405);
