<?php
/**
 * People — the Users screen's single resource: one person, one optional login, one row.
 *
 * This endpoint used to expose employees and sales_users as two separate things (a login
 * list plus an employee directory, with `?action=employee` variants and a "link to existing
 * employee" dropdown). Callers had to know the CRM's internal table split to add a
 * colleague. Now a person IS their login: POST creates both atomically, PATCH keeps them in
 * step, and the employees/sales_users split stays behind _people.php.
 *
 *   GET    users.php                    -> { people, roles, managers, can_grant_privileged, needs_approval }
 *   POST   users.php                    -> add a person   { full_name, department?, sub_department?,
 *                                            designation?, email, phone, manager_id?,
 *                                            username?, role?, password?, is_active? }
 *   PATCH  users.php?id=<employee_id>   -> edit that person (same shape)
 *   DELETE users.php?id=<employee_id>   -> deactivate their login (never hard-delete)
 *
 * Access: the Admin and the HR department (capability 'people' — see _roles.php).
 * An HR Manager / HR Executive's changes are queued for HR Head approval (_hr_approvals.php).
 * Granting or revoking admin / *_head stays with the Admin alone.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_people.php';
require_once __DIR__ . '/_hr_approvals.php';

require_login();
require_cap('people', 'managing people');
$db = db();

/** The person the caller is signed in as — used to stop self-lockout. */
function people_me(): int { return current_employee_id(); }

/**
 * A Head sets the direction for a whole function and an Admin owns the platform, so neither
 * may be minted or removed by HR: without this an HR Executive could grant themselves
 * hr_head and approve their own queued changes.
 */
function assert_may_set_role(?string $newRole, ?string $oldRole = null): void
{
    $touchesPrivileged = payg_is_privileged_role($newRole) || payg_is_privileged_role($oldRole);
    if ($touchesPrivileged && !can_grant_privileged_role()) {
        $which = payg_is_privileged_role($newRole) ? payg_role_label($newRole) : payg_role_label($oldRole);
        fail("Only an Admin can grant or remove the $which role", 403);
    }
}

if (method() === 'GET') {
    $people = people_list($db);
    ok([
        'people'  => $people,
        'roles'   => payg_role_labels(),
        // everyone who can be someone's manager (a person, login or not)
        'managers' => array_map(
            fn($p) => ['id' => $p['employee_id'], 'name' => $p['full_name'], 'role' => $p['role_label']],
            $people
        ),
        'can_grant_privileged' => can_grant_privileged_role(),
        'privileged_roles'     => array_values(array_filter(array_keys(payg_roles()), 'payg_is_privileged_role')),
        'needs_approval'       => payg_role_needs_hr_approval(current_role()),
        'my_employee_id'       => people_me(),
    ]);
}

if (method() === 'POST' && isset($_GET['bulk'])) {
    // Bulk employee upload (CSV rows parsed client-side). Each row is validated and
    // submitted independently; one bad row never sinks the batch. HR-tier callers'
    // rows queue for HR Head approval exactly like single adds (hr_submit).
    $rows = (array)(body()['rows'] ?? []);
    if (!$rows) fail('rows required');
    if (count($rows) > 500) fail('Max 500 rows per upload');
    $results = [];
    foreach ($rows as $i => $row) {
        $label = trim((string)($row['full_name'] ?? '')) ?: ('row ' . ($i + 1));
        $GLOBALS['PAYG_FAIL_THROWS'] = true;
        try {
            $p = people_normalize_payload($db, (array)$row, null);
            assert_may_set_role($p['role'], null);
            $res = hr_submit($db, 'person.create', $p,
                "Bulk add {$p['full_name']}" . ($p['role'] ? ' (' . payg_role_label($p['role']) . ')' : ''), $p['full_name']);
            $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => true,
                          'queued' => !empty($res['queued'])];
        } catch (Throwable $e) {
            $results[] = ['row' => $i + 1, 'name' => $label, 'ok' => false, 'error' => $e->getMessage()];
        } finally {
            $GLOBALS['PAYG_FAIL_THROWS'] = false;
        }
    }
    $okCnt = count(array_filter($results, fn($r) => $r['ok']));
    ok(['results' => $results, 'created' => $okCnt, 'failed' => count($results) - $okCnt]);
}

if (method() === 'POST') {
    $b = body();
    $p = people_normalize_payload($db, $b, null);
    assert_may_set_role($p['role'], null);

    $summary = $p['username']
        ? "Add {$p['full_name']} as " . payg_role_label($p['role']) . " (login: {$p['username']})"
        : "Add {$p['full_name']} (no login)";
    $res = hr_submit($db, 'person.create', $p, $summary, $p['full_name']);
    ok($res, ['message' => 'Person added']);
}

if (method() === 'PATCH' || method() === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');

    $cur = $db->prepare("SELECT * FROM employees WHERE id=?");
    $cur->execute([$id]);
    $emp = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$emp) fail('That person no longer exists', 404);
    $login = people_login_for($db, $id);

    $b = body();
    // The form always posts the whole person; fall back to what's on file for anything omitted
    // so a partial PATCH can't blank a column it never meant to touch.
    $b += [
        'full_name'      => $emp['full_name'],
        'department'     => $emp['department'],
        'sub_department' => $emp['sub_department'],
        'designation'    => $emp['designation'],
        'email'          => $emp['email'],
        'phone'          => $emp['phone'],
        'manager_id'     => $emp['manager_id'],
        'emp_type'       => $emp['emp_type'] ?? 'employee',
        'governance'     => $emp['governance'] ?? 'execution',
        'username'       => $login['username'] ?? '',
        'role'           => $login['role'] ?? '',
        'is_active'      => $login['is_active'] ?? 1,
    ];

    $p = people_normalize_payload($db, $b, $id);
    assert_may_set_role($p['role'], $login['role'] ?? null);

    // Don't let an admin lock themselves out by demoting or disabling their own account.
    if ($id === people_me() && $login) {
        if ((string)$p['role'] !== (string)$login['role']) fail("You can't change your own role", 400);
        if (!$p['is_active']) fail("You can't deactivate your own account", 400);
    }

    $summary = "Edit {$p['full_name']}" . ($p['role'] ? ' (' . payg_role_label($p['role']) . ')' : '');
    $res = hr_submit($db, 'person.update', $p, $summary, $p['full_name']);
    ok($res, ['message' => 'Person updated']);
}

if (method() === 'DELETE') {
    if (current_role() !== 'admin') fail('Only an Admin can delete or deactivate user accounts', 403);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');
    if ($id === people_me()) fail("You can't deactivate your own account", 400);

    $login = people_login_for($db, $id);
    if (!$login) fail('That person has no active login', 404);
    assert_may_set_role(null, $login['role']);

    $name = $db->prepare("SELECT full_name FROM employees WHERE id=?");
    $name->execute([$id]);
    $who = $name->fetchColumn() ?: ('#' . $id);

    $res = hr_submit($db, 'person.deactivate', ['employee_id' => $id], "Deactivate the login for $who", $who);
    ok($res, ['message' => 'Login deactivated']);
}

fail('Unsupported', 405);
