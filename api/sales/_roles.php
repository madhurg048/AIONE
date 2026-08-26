<?php
/**
 * Single source of truth for the CRM role model.
 *
 * Before this file the role list was copy-pasted into rbac.php, org.php, users.php and
 * auth.php, and the capability checks were ad-hoc `user_type === 'admin'` / `in_array(role,
 * [...])` tests scattered across endpoints. They drifted: role_access granted hr_manager the
 * Users tab while api/sales/users.php still demanded admin, so HR saw a menu item that 403'd
 * on every call. Everything role-shaped now derives from payg_roles() + payg_role_can().
 *
 * Hierarchy (per department):  head  →  manager  →  executive
 *   HR     : hr_head    → hr_manager      → hr
 *   Sales  : sales_head → manager         → sales_exec
 *   Service:              service_manager → service_exec
 *   Support:              support_manager → support
 *   IT     :              it_manager      → it
 *
 * 'manager' keeps its historic key (it is the Sales *Manager* tier) because
 * can_manage_all_records(), current_vertical(), scorecards, reimbursements, dashboard-kpis
 * and timesheets all branch on that exact string. sales_head is layered above it instead.
 *
 * This file is intentionally dependency-free (no session, no DB): every function takes the
 * role as an argument, so both the API (current_role()) and the page layer (sales_role())
 * can ask the same questions of the same table.
 */

/** Department a role belongs to (null = no fixed department). Matches KPA_GROUPS naming. */
const PAYG_TIER_EXEC    = 1;
const PAYG_TIER_MANAGER = 2;
const PAYG_TIER_HEAD    = 3;
const PAYG_TIER_ADMIN   = 4;

/**
 * Every role the system knows about, in display order.
 *   label => human name shown in every picker
 *   dept  => org group (Sales/Service/HR/IT/Support), null for admin
 *   tier  => exec < manager < head < admin
 */
function payg_roles(): array
{
    static $r = null;
    if ($r !== null) return $r;
    return $r = [
        'admin'           => ['label' => 'Admin',            'dept' => null,      'tier' => PAYG_TIER_ADMIN],

        'hr_head'         => ['label' => 'HR Head',          'dept' => 'HR',      'tier' => PAYG_TIER_HEAD],
        'hr_manager'      => ['label' => 'HR Manager',       'dept' => 'HR',      'tier' => PAYG_TIER_MANAGER],
        'hr'              => ['label' => 'HR Executive',     'dept' => 'HR',      'tier' => PAYG_TIER_EXEC],

        'sales_head'      => ['label' => 'Sales Head',       'dept' => 'Sales',   'tier' => PAYG_TIER_HEAD],
        'manager'         => ['label' => 'Sales Manager',    'dept' => 'Sales',   'tier' => PAYG_TIER_MANAGER],
        'sales_exec'      => ['label' => 'Sales Exec',       'dept' => 'Sales',   'tier' => PAYG_TIER_EXEC],

        'service_manager' => ['label' => 'Service Manager',  'dept' => 'Service', 'tier' => PAYG_TIER_MANAGER],
        'service_exec'    => ['label' => 'Service Exec',     'dept' => 'Service', 'tier' => PAYG_TIER_EXEC],

        'support_manager' => ['label' => 'Support Manager',  'dept' => 'Support', 'tier' => PAYG_TIER_MANAGER],
        'support'         => ['label' => 'Support Agent',    'dept' => 'Support', 'tier' => PAYG_TIER_EXEC],

        'it_manager'      => ['label' => 'IT Manager',       'dept' => 'IT',      'tier' => PAYG_TIER_MANAGER],
        'it'              => ['label' => 'IT',               'dept' => 'IT',      'tier' => PAYG_TIER_EXEC],

        // Finance. The CFO is a Head (owns the function, signs the returns); the
        // Finance Manager is the maker/checker tier under them.
        'cfo'             => ['label' => 'CFO',              'dept' => 'Finance', 'tier' => PAYG_TIER_HEAD],
        'finance_manager' => ['label' => 'Finance Manager',  'dept' => 'Finance', 'tier' => PAYG_TIER_MANAGER],
    ];
}

/** role => label map, for <select> pickers and API `roles` payloads. */
function payg_role_labels(): array
{
    return array_map(fn($m) => $m['label'], payg_roles());
}

function payg_role_exists(?string $role): bool { return $role !== null && isset(payg_roles()[$role]); }
function payg_role_label(?string $role): string { return payg_roles()[$role]['label'] ?? (string)$role; }
function payg_role_dept(?string $role): ?string { return payg_roles()[$role]['dept'] ?? null; }
function payg_role_tier(?string $role): int { return payg_roles()[$role]['tier'] ?? 0; }

/** Every role in a department, e.g. payg_roles_in_dept('HR') => [hr_head, hr_manager, hr]. */
function payg_roles_in_dept(string $dept): array
{
    return array_keys(array_filter(payg_roles(), fn($m) => $m['dept'] === $dept));
}

function payg_is_hr_role(?string $role): bool { return payg_role_dept($role) === 'HR'; }

/**
 * Roles the admin alone may grant or revoke.
 * A department Head sets the direction for a whole function and an Admin owns the platform,
 * so neither may be minted by HR — otherwise an HR Executive could promote themselves past
 * the very approval gate that governs them.
 */
function payg_is_privileged_role(?string $role): bool
{
    return $role === 'admin' || payg_role_tier($role) === PAYG_TIER_HEAD;
}

/* ------------------------------------------------------------------ *
 *  Capabilities
 * ------------------------------------------------------------------ *
 * Ask payg_role_can($role, 'people') — never `$role === 'admin'`. Adding a role means
 * adding one row here, and the nav, the pages and the APIs all follow automatically.
 *
 *   people                : create/edit/deactivate employees + logins, set reporting lines
 *   rbac                  : change the role → module access matrix
 *   kpi_govern            : org KPAs, dashboard KPI tiles, scorecard definitions — company-wide
 *   incentive_manage      : edit the incentive STRUCTURE (payout config, plans/rules) + book
 *                           quarterly incentive payouts. Sales owns its payout policy, so the
 *                           Sales Head and Sales Managers hold it (plus Admin). Not HR — HR
 *                           governs the KPA/scorecard framework, Sales owns the money on top.
 *   timesheet_approve_all : approve/reject any timesheet, not just direct reports
 *   records_all           : row-level visibility of every owner's records (leads/deals/merchants)
 *   grant_privileged_role : hand out the admin / *_head roles
 *
 * PayG's HR department owns people, performance and governance company-wide, so all three
 * HR roles carry the same capabilities. They are NOT equal in authority: an HR Manager or
 * HR Executive's structural changes are queued for HR Head approval rather than applied —
 * see payg_role_needs_hr_approval() and _hr_approvals.php.
 */
function payg_role_caps(): array
{
    static $c = null;
    if ($c !== null) return $c;

    $hr = ['people', 'rbac', 'kpi_govern', 'timesheet_approve_all'];

    return $c = [
        'admin'           => ['people', 'rbac', 'kpi_govern', 'incentive_manage', 'timesheet_approve_all', 'records_all', 'grant_privileged_role'],

        'hr_head'         => $hr,
        'hr_manager'      => $hr,
        'hr'              => $hr,

        'sales_head'      => ['records_all', 'kpi_govern', 'incentive_manage', 'timesheet_approve_all'],
        'manager'         => ['records_all', 'incentive_manage'],
        'sales_exec'      => [],

        'service_manager' => ['records_all'],
        'service_exec'    => [],

        'support_manager' => [],
        'support'         => [],

        'it_manager'      => [],
        'it'              => [],

        // The CFO signs off the compliance cockpit and owns the company's numbers, so
        // they see every owner's records; the Finance Manager prepares and checks.
        'cfo'             => ['records_all', 'kpi_govern', 'timesheet_approve_all'],
        'finance_manager' => ['records_all'],
    ];
}

function payg_role_can(?string $role, string $cap): bool
{
    return in_array($cap, payg_role_caps()[$role] ?? [], true);
}

/**
 * True when this role's structural changes must be approved by the HR Head instead of
 * taking effect immediately: the HR tiers below Head (Manager, Executive).
 * The HR Head is the approver, and the Admin is above the workflow entirely.
 */
function payg_role_needs_hr_approval(?string $role): bool
{
    return payg_is_hr_role($role) && payg_role_tier($role) < PAYG_TIER_HEAD;
}
