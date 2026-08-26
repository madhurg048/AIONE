<?php
/**
 * Org & Roles — reassign anyone's login role and reporting line.
 *   GET   org.php                                   -> { people, roles }
 *   PATCH org.php?id=<employee_id>&action=role      { role }        -> change that person's login role
 *   PATCH org.php?id=<employee_id>&action=manager   { manager_id }  -> change who they report to (cycle-safe)
 *
 * Access: the Admin and the HR department (capability 'people' — see _roles.php); HR owns the
 * org chart. An HR Manager / HR Executive's changes are queued for HR Head approval, and only
 * the Admin may grant or revoke admin / *_head.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_people.php';
require_once __DIR__ . '/_hr_approvals.php';

require_login();
require_cap('people', 'managing the org chart');
$db = db();

if (method() === 'GET') {
    ok([
        'people'               => people_list($db),
        'roles'                => payg_role_labels(),
        'can_grant_privileged' => can_grant_privileged_role(),
        'needs_approval'       => payg_role_needs_hr_approval(current_role()),
        'my_employee_id'       => current_employee_id(),
    ]);
}

if (method() === 'PATCH') {
    $id = (int)($_GET['id'] ?? 0);
    $action = $_GET['action'] ?? '';
    if (!$id) fail('employee id required');

    $emp = $db->prepare("SELECT * FROM employees WHERE id=?");
    $emp->execute([$id]);
    $emp = $emp->fetch(PDO::FETCH_ASSOC);
    if (!$emp) fail('Employee not found', 404);
    $b = body();

    if ($action === 'role') {
        $role = (string)($b['role'] ?? '');
        if (!payg_role_exists($role)) fail('Unknown role');

        $login = people_login_for($db, $id);
        if (!$login) fail('This person has no active login — create one in User Management first', 409);

        // A Head/Admin may only be minted by the Admin — otherwise HR could promote itself
        // past the very approval gate that governs it.
        if ((payg_is_privileged_role($role) || payg_is_privileged_role($login['role'])) && !can_grant_privileged_role()) {
            $which = payg_is_privileged_role($role) ? payg_role_label($role) : payg_role_label($login['role']);
            fail("Only an Admin can grant or remove the $which role", 403);
        }
        // don't let someone lock themselves out by demoting their own account
        if ($id === current_employee_id() && $role !== $login['role']) fail("You can't change your own role", 400);

        $res = hr_submit(
            $db, 'org.role', ['employee_id' => $id, 'role' => $role],
            "Change {$emp['full_name']}'s role to " . payg_role_label($role), $emp['full_name']
        );
        ok($res);
    }

    if ($action === 'manager') {
        $mgr = ($b['manager_id'] === null || $b['manager_id'] === '') ? null : (int)$b['manager_id'];
        people_assert_manager_ok($db, $mgr, $id);   // validate before queueing, not after approval

        $mgrName = null;
        if ($mgr) {
            $n = $db->prepare("SELECT full_name FROM employees WHERE id=?");
            $n->execute([$mgr]);
            $mgrName = $n->fetchColumn() ?: null;
        }
        $res = hr_submit(
            $db, 'org.manager', ['employee_id' => $id, 'manager_id' => $mgr],
            "Change who {$emp['full_name']} reports to → " . ($mgrName ?: 'nobody'), $emp['full_name']
        );
        ok($res);
    }

    fail('Unknown action');
}

fail('Unsupported', 405);
