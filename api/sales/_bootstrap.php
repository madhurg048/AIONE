<?php
/**
 * Shared bootstrap for Sales CRM API endpoints.
 * Handles: DB, JSON headers, session, input parsing, uniform responses.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/_roles.php';

payg_start_session();

header('Content-Type: application/json');

/**
 * chat.php doubles as both the agent-authenticated CRM endpoint and the PUBLIC visitor
 * side of a live chat (anonymous website visitor sending/closing messages via a token
 * issued by chatbot-ticket.php — see chat.php/v_post/v_close/v_call and the public chatbot
 * widget at /chat.php). Those specific visitor actions never have a logged-in session, so
 * they can't carry the CSRF token every other write endpoint here requires; everything
 * else in chat.php (agent actions, ?action=start used by an authenticated Leads page) still
 * goes through the normal CSRF gate below.
 */
$__publicChatActions = ['start', 'v_post', 'v_close', 'v_call'];
$__isPublicChatWrite = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'chat.php'
    && in_array($_GET['action'] ?? ($_POST['action'] ?? ''), $__publicChatActions, true);

if (!$__isPublicChatWrite
    && in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
    && !payg_verify_csrf_request()) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}

function db(): PDO
{
    $db = getDB();
    if (!$db) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database unavailable']);
        exit;
    }
    return $db;
}

// next_ticket_ref_and_insert() lives in _ticket_ref.php — it's dependency-free (no
// session/CSRF) so the public, unauthenticated chatbot-ticket.php can require it directly
// without pulling in this file's session-start + CSRF gate.
require_once __DIR__ . '/_ticket_ref.php';

/** Current logged-in employee id (owner for new records). 0 if none. */
function current_employee_id(): int
{
    $d = $_SESSION['user']['data'] ?? [];
    return (int)($d['employee']['id'] ?? ($d['id'] ?? 0));
}

function require_login(): void
{
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
}

/** Row-level "sees everyone [in their scope], not just their own records" — admin, dept heads + dept managers, and Board members. */
function can_manage_all_records(): bool
{
    return payg_role_can(current_role(), 'records_all') || employee_in_full_access_group();
}

/**
 * True when the current employee belongs to an active FULL-ACCESS group (emp_groups.grant_all,
 * e.g. "Board"). Such members get every module and org-wide record visibility regardless of
 * their role — the group is the access profile. Cached per request.
 */
function employee_in_full_access_group(): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $me = current_employee_id();
    if (!$me) return $cache = false;
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM emp_group_members m
                              JOIN emp_groups g ON g.id=m.group_id
                             WHERE m.employee_id=? AND g.is_active=1 AND g.grant_all=1");
        $s->execute([$me]);
        return $cache = ((int)$s->fetchColumn() > 0);
    } catch (Throwable $e) { return $cache = false; }
}

/* ---------------------------------------------------------------- *
 *  Capability gates — see _roles.php for the role → capability map.
 *  Always ask these, never `user_type === 'admin'`: that string test
 *  is what let role_access grant HR the Users tab while the endpoint
 *  behind it still 403'd.
 * ---------------------------------------------------------------- */

/** Manage employees, login accounts and reporting lines (admin + the HR department). */
function can_manage_people(): bool
{
    return payg_role_can(current_role(), 'people');
}

/** Define org KPAs, dashboard KPI tiles and scorecards company-wide (admin + HR + Sales Head). */
function can_govern_kpi(): bool
{
    return payg_role_can(current_role(), 'kpi_govern');
}

/** Change the role → module access matrix (admin + the HR department). */
function can_manage_rbac(): bool
{
    return payg_role_can(current_role(), 'rbac');
}

/** Edit the incentive structure (payout config, plans/rules) + book payouts (admin + Sales Head + Sales Manager). */
function can_manage_incentives(): bool
{
    return payg_role_can(current_role(), 'incentive_manage');
}

/** Approve/reject ANY timesheet, not just direct reports (admin + HR + Sales Head). */
function can_approve_all_timesheets(): bool
{
    return payg_role_can(current_role(), 'timesheet_approve_all');
}

/** Only the admin may grant or revoke the admin / *_head roles. */
function can_grant_privileged_role(): bool
{
    return payg_role_can(current_role(), 'grant_privileged_role');
}

/** 403 unless the current user holds $cap. */
function require_cap(string $cap, string $what = 'this action'): void
{
    if (!payg_role_can(current_role(), $cap)) fail("Your role is not authorized for $what", 403);
}

/** Effective role of the logged-in user — any key in payg_roles(). */
function current_role(): string
{
    $d = $_SESSION['user']['data'] ?? [];
    if (!empty($d['role'])) return $d['role'];
    $ut = $_SESSION['user']['user_type'] ?? '';
    return $ut === 'employee' ? 'sales_exec' : ($ut ?: 'sales_exec');
}

/**
 * All employee ids in $rootId's line of control (self excluded) via employees.manager_id.
 * Iterative walk down the tree; guarded against cycles. Cached per request.
 */
function manager_subtree_ids(int $rootId): array
{
    static $childrenCache = null;
    if ($childrenCache === null) {
        $childrenCache = [];
        try {
            foreach (db()->query("SELECT id, manager_id FROM employees WHERE manager_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $childrenCache[(int)$r['manager_id']][] = (int)$r['id'];
            }
        } catch (Throwable $e) { $childrenCache = []; }
    }
    $out = [];
    $stack = $childrenCache[$rootId] ?? [];
    while ($stack) {
        $x = (int)array_pop($stack);
        if (isset($out[$x])) continue;
        $out[$x] = true;
        foreach ($childrenCache[$x] ?? [] as $c) $stack[] = $c;
    }
    return array_map('intval', array_keys($out));
}

/**
 * Row-level visibility. Returns the owner ids the current user may see:
 *   null            -> everyone (dept head, admin)
 *   [self, ...team] -> a dept manager: their own records + everyone reporting up to them
 *   [self]          -> an executive: only their own records
 *
 * A department Head oversees the whole function (sees all); a Manager sees their line of
 * control (self + subtree); an Exec sees only their own. This is the single source of truth
 * for record scoping — owner_scope()/owner_scope_inline()/assert_can_see() all read it.
 */
/**
 * Per-request scope override: an endpoint that is legitimately org-wide for a
 * capability its callers hold (e.g. reports.php for HR's kpi_govern) can lift
 * row scoping for THIS request only, instead of widening the role globally.
 */
function payg_scope_override(?bool $set = null): bool
{
    static $v = false;
    if ($set !== null) $v = $set;
    return $v;
}

/**
 * Everyone in a department: employees filed under it (org-master string) plus anyone
 * holding one of that department's roles (covers staff whose department field is
 * still blank). Cached per request.
 */
function payg_dept_staff_ids(string $dept): array
{
    static $cache = [];
    if (isset($cache[$dept])) return $cache[$dept];
    $roles = array_keys(array_filter(payg_roles(), fn($r) => ($r['dept'] ?? null) === $dept));
    $roleIn = $roles ? implode(',', array_map(fn($r) => db()->quote($r), $roles)) : "''";
    $s = db()->prepare("SELECT DISTINCT e.id FROM employees e
                          LEFT JOIN sales_users su ON su.employee_id = e.id AND su.is_active = 1
                         WHERE e.department = ? OR su.role IN ($roleIn)");
    $s->execute([$dept]);
    return $cache[$dept] = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The data-abstraction ladder (who sees whose records):
 *   admin / Board (full-access group)  -> everything, org-wide
 *   *_head                             -> their whole DEPARTMENT's staff
 *   dept manager / team lead           -> themselves + their reporting line
 *   executive                          -> own records only
 */
function visible_owner_ids(): ?array
{
    if (payg_scope_override()) return null;                     // endpoint-declared org-wide view
    $role = current_role();
    if ($role === 'admin') return null;                         // admin: global
    if (employee_in_full_access_group()) return null;           // Board: full record visibility
    $me = current_employee_id() ?: -1;
    if (payg_role_tier($role) >= PAYG_TIER_HEAD) {              // dept head: their department
        $dept = payg_role_dept($role);
        if (!$dept) return null;
        return array_values(array_unique(array_merge([$me], payg_dept_staff_ids($dept))));
    }
    if (payg_role_can($role, 'records_all')) {                  // dept manager: own + reports
        return array_values(array_unique(array_merge([$me], manager_subtree_ids($me))));
    }
    return [$me];                                               // exec: own only
}

/** SQL fragment "col IN (?,?)" scoped to the current user; '1' if unrestricted. Appends to $args. */
function owner_scope(string $col, array &$args): string
{
    $ids = visible_owner_ids();
    if ($ids === null) return '1';
    $args = array_merge($args, $ids);
    return "$col IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
}

/** Inline (int-safe) scope fragment for paramless ->query() strings; '1' if unrestricted. */
function owner_scope_inline(string $col): string
{
    $ids = visible_owner_ids();
    if ($ids === null) return '1';
    return "$col IN (" . implode(',', array_map('intval', $ids)) . ")";
}

/** Guard a single record: 403 unless the current user may see this owner. */
function assert_can_see($ownerId): void
{
    $ids = visible_owner_ids();
    if ($ids !== null && !in_array((int)$ownerId, $ids, true)) {
        fail('Not authorized to view this record', 403);
    }
}

/**
 * Which vertical (sales|service) the current user is scoped to.
 * null -> unrestricted (only true admin sees both verticals; Sales Manager and
 * Service Manager each still only see their own dept).
 */
function current_vertical(): ?string
{
    $role = current_role();
    if ($role === 'admin') {
        // Admin has no fixed vertical, but the "Service" nav tab (sales-layout.php)
        // links with ?vertical=service so admin can view either dept's data scoped
        // the same way a dept manager would, instead of always seeing both merged.
        $v = $_GET['vertical'] ?? $_POST['vertical'] ?? null;
        return in_array($v, ['sales', 'service'], true) ? $v : null;
    }
    // Derived from the role's department so a new role (e.g. sales_head) is scoped the day
    // it is added, instead of falling through to "no vertical" and quietly seeing both depts.
    switch (payg_role_dept($role)) {
        case 'Sales':   return 'sales';
        case 'Service': return 'service';
        default:        return null;   // HR/IT/Support have no vertical-scoped data anyway
    }
}

/** SQL fragment "col = ?" scoped to the current user's vertical; '1' if unrestricted. Appends to $args. */
function vertical_scope(string $col, array &$args): string
{
    $v = current_vertical();
    if ($v === null) return '1';
    $args[] = $v;
    return "$col = ?";
}

/** Inline (no bound params) vertical fragment for paramless ->query() strings; '1' if unrestricted. */
function vertical_scope_inline(string $col): string
{
    $v = current_vertical();
    if ($v === null) return '1';
    return "$col = " . ($v === 'service' ? "'service'" : "'sales'");
}

/** Guard a single record: 403 unless the current user's vertical matches (admin/manager exempt). */
function assert_can_see_vertical(?string $vertical): void
{
    $v = current_vertical();
    if ($v !== null && $vertical !== null && $vertical !== $v) {
        fail('Not authorized to view this record', 403);
    }
}

function assert_can_assign_owner($ownerId): void
{
    if ($ownerId === null || $ownerId === '') {
        return;
    }
    // Full admin can hand any record to anyone, in any department — never restricted by
    // current_vertical() (which for admin only reflects whichever ?vertical= tab they happen to
    // be browsing, not an actual scope limit).
    if (current_role() === 'admin') {
        return;
    }
    if (can_manage_all_records()) {
        // A dept head/manager (not full admin) may still only hand records to their own dept.
        $v = current_vertical();
        if ($v !== null) {
            $wantDept = $v === 'service' ? 'Service' : 'Sales';
            $s = db()->prepare("SELECT su.role FROM sales_users su WHERE su.employee_id=? AND su.is_active=1 LIMIT 1");
            $s->execute([(int)$ownerId]);
            $targetRole = $s->fetchColumn();
            // Compare departments rather than an explicit role list, so every tier of the
            // dept (head/manager/exec) is assignable without touching this check again.
            if ($targetRole !== false && $targetRole !== 'admin' && payg_role_dept($targetRole) !== $wantDept) {
                fail('Not authorized to assign records outside your department', 403);
            }
        }
        return;
    }
    if ((int)$ownerId !== current_employee_id()) {
        fail('Not authorized to assign records to another owner', 403);
    }
}

/**
 * Server-side RBAC guard for JSON APIs — 403 unless the current role's role_access
 * grants $moduleKey. Mirrors sales_allowed_modules(): a role with NO role_access rows
 * is unrestricted (backward-compat). Use in endpoints that back a nav module so RBAC
 * is enforced at the data layer, not just the page.
 */
function require_module(string $moduleKey): void
{
    $role = current_role();
    try {
        $db = db();
        $any = (int)$db->query("SELECT COUNT(*) FROM role_access WHERE role=" . $db->quote($role))->fetchColumn();
        if ($any === 0) return;   // unconfigured role -> allow (matches nav behaviour)
        // a full-access group (Board) opens every module
        if (employee_in_full_access_group()) return;
        $s = $db->prepare("SELECT can_access FROM role_access WHERE role=? AND module_key=?");
        $s->execute([$role, $moduleKey]);
        if ((int)$s->fetchColumn() === 1) return;
        // Group grants are ADDITIVE on top of role RBAC: membership of an active
        // group that carries this module (e.g. "Compliance Team") is enough.
        if (employee_group_module_allowed($moduleKey)) return;
        fail('Your role does not have access to this module', 403);
    } catch (Throwable $e) { /* fail open on RBAC infrastructure error */ }
}

/** True when one of the current employee's active groups grants $moduleKey. */
function employee_group_module_allowed(string $moduleKey): bool
{
    $me = current_employee_id();
    if (!$me) return false;
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM emp_group_access a
                              JOIN emp_group_members m ON m.group_id=a.group_id
                              JOIN emp_groups g ON g.id=a.group_id
                             WHERE m.employee_id=? AND a.module_key=? AND a.can_access=1 AND g.is_active=1");
        $s->execute([$me, $moduleKey]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

/** Parsed JSON body for POST/PATCH. Falls back to $_POST. */
function body(): array
{
    $raw = payg_raw_input();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $_POST;
}

function ok($data = [], array $extra = []): void
{
    echo json_encode(array_merge(['success' => true, 'data' => $data], $extra));
    exit;
}

function fail(string $msg, int $code = 400): void
{
    // Bulk endpoints set this flag to turn hard exits into catchable per-row errors
    // (a single bad CSV row must not abort the whole batch).
    if (!empty($GLOBALS['PAYG_FAIL_THROWS'])) throw new RuntimeException($msg);
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function method(): string
{
    return $_SERVER['REQUEST_METHOD'] ?? 'GET';
}
