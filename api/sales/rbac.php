<?php
/**
 * Role → module access control.
 *   GET  rbac.php            -> { roles, modules, matrix }
 *   POST rbac.php  {role, module_key, can_access}   -> set one cell
 *
 * Access: the Admin and the HR department (capability 'rbac' — see _roles.php). An HR Manager
 * / HR Executive's changes are queued for HR Head approval (_hr_approvals.php).
 *
 * The module catalogue is derived from sales_catalog() rather than hand-copied. The old copy
 * had drifted — it never listed 'kpa', so the KPA rows phase44 inserted were invisible to the
 * matrix and could not be granted or revoked from this screen.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_hr_approvals.php';
require_once __DIR__ . '/../../includes/sales-layout.php';   // sales_catalog() — nav is the source of truth

require_login();
require_cap('rbac', 'changing module access');
$db = db();

/** module_key => label, straight from the nav catalogue so the two can never disagree. */
function rbac_modules(): array
{
    return array_map(fn($t) => $t[0], sales_catalog());
}

if (method() === 'POST') {
    $b = body();
    $role = (string)($b['role'] ?? '');
    $mod  = (string)($b['module_key'] ?? '');
    $modules = rbac_modules();
    if (!payg_role_exists($role) || !isset($modules[$mod])) fail('Unknown role or module');

    $can = (int)!empty($b['can_access']);
    $res = hr_submit(
        $db, 'rbac.set',
        ['role' => $role, 'module_key' => $mod, 'can_access' => $can],
        ($can ? 'Grant ' : 'Revoke ') . $modules[$mod] . ' access for ' . payg_role_label($role),
        payg_role_label($role)
    );
    ok($res);
}

// GET matrix
$modules = rbac_modules();
$roles   = payg_role_labels();
$rows = $db->query("SELECT role, module_key, can_access FROM role_access")->fetchAll();
$matrix = [];
foreach ($roles as $r => $_) foreach ($modules as $m => $__) $matrix[$r][$m] = 0;
foreach ($rows as $row) {
    // ignore rows for roles/modules that no longer exist rather than inventing matrix cells
    if (isset($matrix[$row['role']]) && isset($modules[$row['module_key']])) {
        $matrix[$row['role']][$row['module_key']] = (int)$row['can_access'];
    }
}
ok([
    'roles'          => $roles,
    'modules'        => $modules,
    'matrix'         => $matrix,
    'needs_approval' => payg_role_needs_hr_approval(current_role()),
]);
