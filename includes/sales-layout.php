<?php
/**
 * Shared layout for the PayG Sales CRM module.
 * Matches existing look & feel: Tailwind CDN, Font Awesome 6.5.1, Flatpickr,
 * indigo-600 primary, gray-100 bg, white rounded-xl shadow-md cards, text-xs.
 *
 * Usage at top of a page:
 *   require_once __DIR__ . '/includes/sales-layout.php';
 *   $me = sales_guard();                 // session guard, returns profile
 *   sales_head('Leads');                 // <head> + <body> open
 *   sales_header($me, 'Sales · Leads');  // top bar
 *   sales_nav('leads');                  // module tab bar
 *   ... page content ...
 *   sales_scripts();                     // shared JS + </body></html>
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../api/sales/_roles.php';

payg_start_session();

/**
 * Guard: any known role may enter; role_access decides which MODULES they actually see
 * (enforced below). Returns the profile array ($_SESSION['user']['data']).
 *
 * This used to allow-list the coarse `user_type` ('manager','employee','admin','hr',…), which
 * meant adding a role silently locked it out of every page until someone remembered to extend
 * the list here — a failure that looks like a broken login, not a missing grant. Membership is
 * now "is this a role the system knows?" (payg_roles()), and authorisation is left to the one
 * mechanism that is actually configurable: the RBAC matrix.
 *
 * $allow is retained for any caller that wants to pin a page to specific user_types.
 */
function sales_guard(?array $allow = null): array
{
    // Always serve fresh HTML/inline-JS so a browser never runs a stale (possibly broken) script.
    if (!headers_sent()) {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
    }
    $signedIn = isset($_SESSION['user']);
    $ok = $signedIn && ($allow === null
        ? payg_role_exists(sales_role())                                   // any known role
        : in_array($_SESSION['user']['user_type'] ?? '', $allow, true));   // pinned user_types
    if (!$ok) {
        header('Location: sales-login.php');
        exit;
    }
    // Hard-enforce admin-configured module access (RBAC): derive the module from the
    // page filename (sales-<module>.php) and bounce to the user's OWN home if it's not
    // allowed. Never bounce an authenticated user to the login page — that reads as a
    // surprise "logout" when a role opens a KPI box tied to a module they can't see.
    $allowedMods = sales_allowed_modules();
    if ($allowedMods) {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        // sales-requests-service.php is the Service dept's customer-requests queue — same RBAC
        // module as 'leads' (it replaces the Leads tab for Service, see sales_nav_href()).
        if ($script === 'sales-requests-service.php') $script = 'sales-leads.php';
        // sales-dashboard-service.php is the Service dept's Dashboard — same RBAC module as 'dashboard'.
        if ($script === 'sales-dashboard-service.php') $script = 'sales-dashboard.php';
        // sales-pipeline-service.php is the Service dept's Pipeline — same RBAC module as 'pipeline'.
        if ($script === 'sales-pipeline-service.php') $script = 'sales-pipeline.php';
        if (preg_match('/^sales-([a-z]+)\.php$/', $script, $mm)
            && $mm[1] !== 'dashboard' && !in_array($mm[1], $allowedMods, true)) {
            $home = sales_home();
            // guard against a redirect loop if home maps to this same disallowed page
            header('Location: ' . ($home === $script ? 'sales-dashboard.php' : $home));
            exit;
        }
    }

    $data = $_SESSION['user']['data'] ?? [];
    // Normalise the fields the layout needs regardless of role shape.
    $data['_full_name']   = $data['full_name'] ?? ($data['name'] ?? 'User');
    $data['_role']        = $_SESSION['user']['user_type'];
    $data['_employee_id'] = $data['employee']['id'] ?? ($data['id'] ?? 0);
    $data['_designation'] = $data['employee']['designation'] ?? ucfirst($data['_role']);
    return $data;
}

function sales_head(string $title): void
{
    $csrfToken = payg_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> · PayG <?= htmlspecialchars(sales_vertical_label()) ?> Cloud</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        /* ---- Dense CRM design system ---- */
        body{font-size:12px}
        .kpi-card{transition:transform .15s ease, box-shadow .15s ease}
        .kpi-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.10)}
        .stage-col{min-width:240px}
        .deal-card{cursor:grab}
        .deal-card.dragging{opacity:.5}
        .drop-active{background:#eef2ff;outline:2px dashed #6366f1}
        ::-webkit-scrollbar{height:8px;width:8px}
        ::-webkit-scrollbar-thumb{background:#c7d2fe;border-radius:8px}

        /* compact card (thin border instead of heavy shadow/box) */
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px}

        /* KPI strip — inline stats, no wasted boxes */
        .kstrip{display:flex;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow-x:auto}
        .kstrip>div{flex:1;min-width:92px;padding:6px 12px;border-right:1px solid #f1f5f9}
        .kstrip>div:last-child{border-right:0}
        .kstrip .kv{font-size:17px;font-weight:700;color:#1e293b;line-height:1.1}
        .kstrip .kl{font-size:9.5px;text-transform:uppercase;letter-spacing:.03em;color:#64748b;white-space:nowrap}

        /* dense data table */
        table.dense{width:100%;border-collapse:collapse}
        table.dense thead th{position:sticky;top:0;z-index:5;background:#f8fafc;font-size:10px;
            text-transform:uppercase;letter-spacing:.03em;color:#475569;text-align:left;
            padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap;cursor:default}
        table.dense thead th.sortable{cursor:pointer;user-select:none}
        table.dense thead th.sortable:hover{color:#4f46e5}
        table.dense tbody td{padding:5px 8px;border-bottom:1px solid #f1f5f9;white-space:nowrap;vertical-align:middle}
        table.dense tbody tr:hover{background:#f8fafc}
        table.dense tbody tr.sel{background:#eef2ff}
        .rowact{visibility:hidden;white-space:nowrap}
        table.dense tbody tr:hover .rowact{visibility:visible}
        .rowact button{padding:2px 5px}
        input[type=checkbox].chk{width:13px;height:13px;accent-color:#4f46e5;cursor:pointer}

        /* bulk action bar */
        .bulkbar{background:#4f46e5;color:#fff;border-radius:8px}
        .bulkbar button{background:rgba(255,255,255,.15);border-radius:5px;padding:3px 9px}
        .bulkbar button:hover{background:rgba(255,255,255,.28)}

        /* toolbar controls */
        .ctl{height:28px;font-size:12px;border:1px solid #d1d5db;border-radius:6px;padding:0 8px;background:#fff}
        .btn-p{height:28px;background:#4f46e5;color:#fff;border-radius:6px;padding:0 10px;font-size:12px}
        .btn-p:hover{background:#4338ca}
        .pager button{border:1px solid #d1d5db;border-radius:5px;padding:2px 7px}
        .pager button:hover{background:#f1f5f9;color:#4f46e5}
        .pager button:disabled{opacity:.4;cursor:not-allowed}
    </style>
</head>
<body class="bg-gray-100 text-gray-800">
<?php }

/** Canonical page titles — keeps every screen's title consistent and in a fixed position. */
function sales_page_title(): string
{
    $t = [
        'sales-dashboard.php' => 'Dashboard', 'sales-dashboard-service.php' => 'Dashboard',
        'sales-leads.php' => 'Leads', 'sales-requests-service.php' => 'Customer Requests',
        'sales-pipeline.php' => 'Pipeline', 'sales-pipeline-service.php' => 'Pipeline',
        'sales-merchants.php' => 'Merchants', 'sales-chat.php' => 'Live Chat', 'sales-inbox.php' => 'AI Inbox', 'sales-conversations.php' => 'Live Monitor',
        'sales-hr.php' => 'HR Dashboard', 'sales-it.php' => 'IT Dashboard', 'sales-activity.php' => 'My Activity',
        'sales-timesheet.php' => 'Timesheet', 'sales-incentives.php' => 'Incentives', 'sales-reports.php' => 'Reports & Analytics',
        'sales-scorecards.php' => 'KPA / KRA Scorecards',
        'sales-transactions.php' => 'Transactions', 'sales-tickets.php' => 'Support Tickets',
        'sales-reimbursements.php' => 'Reimbursements',
        'sales-automation.php' => 'Automation', 'sales-settings.php' => 'Settings', 'sales-admin.php' => 'Incentive Setup',
        'sales-kpa.php' => 'KPA / KRA',
        'sales-users.php' => 'User Management', 'sales-integrations.php' => 'Integrations',
        'sales-org.php' => 'Org & Roles',
    ];
    return $t[basename($_SERVER['SCRIPT_NAME'] ?? '')] ?? 'Sales';
}

define('APP_NAME', 'PayG Sales Cloud');

/** Home page for the current role (brand click / default landing). */
function sales_home(): string
{
    $map = ['admin'=>'sales-admin.php',
            'sales_head'=>'sales-dashboard.php', 'manager'=>'sales-dashboard.php', 'sales_exec'=>'sales-leads.php',
            'service_exec'=>'sales-requests-service.php', 'service_manager'=>'sales-dashboard-service.php',
            'hr_head'=>'sales-hr.php', 'hr_manager'=>'sales-hr.php', 'hr'=>'sales-hr.php',
            'it'=>'sales-it.php', 'it_manager'=>'sales-it.php',
            'support'=>'sales-chat.php', 'support_manager'=>'sales-conversations.php'];
    return $map[sales_role()] ?? 'sales-dashboard.php';
}

function sales_header(array $me, string $centerTitle = ''): void
{
    $page = sales_page_title(); ?>
<header class="bg-white shadow-sm h-12 flex items-center gap-3 px-4 sticky top-0 z-40">
    <!-- brand (links to the role's home) -->
    <a href="<?= sales_home() ?>" class="flex items-center gap-2 shrink-0 no-underline">
        <img src="/payg/public/images/PayG-Dark.svg" alt="PayG" class="h-6 object-contain" onerror="this.style.display='none'">
        <span class="font-bold text-indigo-700 text-lg tracking-tight leading-none">PayG</span>
        <span class="text-gray-400 font-normal text-lg leading-none hidden sm:inline"><?= htmlspecialchars(sales_vertical_label()) ?>&nbsp;Cloud</span>
    </a>
    <!-- page title (fixed position, right after brand) -->
    <span class="text-gray-300 shrink-0">/</span>
    <h1 class="text-gray-800 font-semibold text-base shrink-0 leading-none"><?= htmlspecialchars($page) ?></h1>
    <div class="flex-1"></div>
    <div class="flex items-center justify-end gap-3 text-sm whitespace-nowrap shrink-0">
        <span class="hidden lg:flex items-center gap-3">
            <span class="text-gray-700 font-medium"><?= htmlspecialchars($me['_full_name']) ?></span>
            <span class="text-gray-400">|</span>
            <span class="text-gray-500"><?= htmlspecialchars($me['_designation']) ?></span>
            <span class="text-gray-400">|</span>
        </span>
        <?php if (sales_role() === 'service_exec'): ?>
        <button id="ticketBellBtn" onclick="location.href='sales-tickets.php'" title="Unclaimed tickets — click first to claim one"
            class="relative text-gray-700 hover:text-indigo-600 flex items-center gap-1 font-medium">
            <i class="fa-solid fa-ticket"></i> <span class="hidden sm:inline">Tickets</span>
            <span id="ticketBadge" class="hidden absolute -top-2 -right-2 bg-red-500 text-white text-[9px] rounded-full px-1.5 leading-4"></span>
        </button>
        <?php endif; ?>
        <button id="myTasksBtn" onclick="openTaskPanel()"
            class="relative text-gray-700 hover:text-indigo-600 flex items-center gap-1 font-medium">
            <i class="fa-solid fa-list-check"></i> <span class="hidden sm:inline">My Tasks</span>
            <span id="taskBadge" class="hidden absolute -top-2 -right-2 bg-red-500 text-white text-[9px] rounded-full px-1.5 leading-4"></span>
        </button>
        <button onclick="location.reload()" class="text-green-600 hover:text-green-700 flex items-center gap-1" title="Refresh">
            <i class="fa-solid fa-rotate"></i></button>
        <button onclick="salesLogout()" class="text-gray-700 hover:text-red-600 flex items-center gap-1">
            <i class="fa-solid fa-right-from-bracket"></i> <span class="hidden sm:inline">Logout</span></button>
    </div>
</header>
<?php if (!empty($_SESSION['impersonator'])):
    $impName = $_SESSION['impersonator']['data']['full_name'] ?? 'admin'; ?>
<div class="bg-amber-500 text-white text-xs px-4 py-1.5 flex items-center justify-center gap-2 sticky top-12 z-30 shadow">
    <i class="fa-solid fa-user-secret"></i>
    Viewing as <b><?= htmlspecialchars($me['_full_name']) ?></b> (<?= htmlspecialchars($me['_designation']) ?>) — impersonated from <b><?= htmlspecialchars($impName) ?></b>.
    <button onclick="stopImpersonate()" class="ml-2 bg-white/20 hover:bg-white/30 rounded px-2 py-0.5 font-semibold">
        <i class="fa-solid fa-arrow-left"></i> Return to admin</button>
</div>
<?php endif; ?>
<?php }

/** Effective role for access checks (admin|manager|sales_exec|service_exec). */
function sales_role(): string
{
    $d = $_SESSION['user']['data'] ?? [];
    if (!empty($d['role'])) return $d['role'];
    $ut = $_SESSION['user']['user_type'] ?? '';
    return $ut === 'employee' ? 'sales_exec' : $ut;
}

/** "Sales" for everyone except the Service dept (service_exec/service_manager), rebranded "Service". */
function sales_vertical_label(): string
{
    return in_array(sales_role(), ['service_exec', 'service_manager'], true) ? 'Service' : 'Sales';
}

/** Modules the current role may access (admin-configurable). Empty => allow all (backward-compat). */
function sales_allowed_modules(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $db = getDB();
        $s = $db->prepare("SELECT module_key FROM role_access WHERE role=? AND can_access=1");
        $s->execute([sales_role()]);
        $cache = $s->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $cache = []; }
    return $cache;
}

/** Full nav catalog: key => [label, icon, href]. */
function sales_catalog(): array
{
    return [
        'dashboard'  => ['Dashboard',  'fa-gauge-high',      'sales-dashboard.php'],
        'hr'         => ['HR',          'fa-user-group',      'sales-hr.php'],
        'it'         => ['IT',          'fa-server',          'sales-it.php'],
        'activity'   => ['My Activity', 'fa-wave-square',     'sales-activity.php'],
        'leads'      => ['Leads',       'fa-user-plus',       'sales-leads.php'],
        'pipeline'   => ['Pipeline',    'fa-diagram-project', 'sales-pipeline.php'],
        'merchants'  => ['Merchants',   'fa-store',           'sales-merchants.php'],
        // Service-only: every lead the Service team has captured (service-lead.php), draft or
        // already handed to Sales — the Requests tab drops a lead the moment it's sent (it's
        // vertical-scoped to 'service'), so this is where Service can still find it and see
        // exactly who in Sales it landed on. See sales_groups() for why Sales doesn't get this tab.
        'genleads'   => ['Generated Leads', 'fa-right-left',  'sales-genleads.php'],
        'transactions'=>['Transactions','fa-receipt',         'sales-transactions.php'],
        'chat'       => ['Live Chat',   'fa-comments',        'sales-chat.php'],
        'whatsapp'   => ['WhatsApp',    'fa-comment-dots',    'sales-whatsapp.php'],
        'inbox'      => ['AI Inbox',     'fa-inbox',          'sales-inbox.php'],
        'conversations'=>['Live Monitor','fa-headset',        'sales-conversations.php'],
        'calls'      => ['Calls',       'fa-phone',           'sales-calls.php'],
        'tickets'    => ['Tickets',     'fa-ticket',          'sales-tickets.php'],
        'timesheet'  => ['Timesheet',   'fa-clock',           'sales-timesheet.php'],
        'incentives' => ['Incentives',  'fa-sack-dollar',     'sales-incentives.php'],
        'reimbursements'=>['Reimbursements','fa-hand-holding-dollar','sales-reimbursements.php'],
        'scorecards' => ['Scorecards',  'fa-bullseye',        'sales-scorecards.php'],
        'reports'    => ['Reports',     'fa-chart-column',    'sales-reports.php'],
        'automation' => ['Automation',  'fa-robot',           'sales-automation.php'],
        'settings'   => ['Settings',    'fa-gear',            'sales-settings.php'],
        'kpa'        => ['KPA / KRA',   'fa-crosshairs',      'sales-kpa.php'],
        'admin'      => ['Incentive Setup', 'fa-sliders',     'sales-admin.php'],
        'org'        => ['Org & Roles', 'fa-sitemap',         'sales-org.php'],
        'users'      => ['Users',       'fa-users-gear',      'sales-users.php'],
        'integrations'=>['Integrations','fa-plug',            'sales-integrations.php'],
    ];
}

/** Grouped nav layout: [k] single link, or [label, icon, [keys...]] dropdown. */
function sales_groups(): array
{
    $deptModules = ['leads', 'pipeline', 'merchants', 'transactions', 'incentives', 'reimbursements', 'scorecards', 'reports'];
    // Sales has no equivalent tab — 'genleads' only ever lists leads Service itself captured.
    $serviceModules = array_merge($deptModules, ['genleads']);

    // Admin has no fixed vertical: give it BOTH a "Sales" and a "Service" dropdown, hrefs
    // tagged ?vertical=sales|service so current_vertical()'s admin override (_bootstrap.php)
    // scopes the data the same way a dept manager sees it. Service's dropdown gets the extra
    // 'genleads' tab; Sales' doesn't.
    if (sales_role() === 'admin') {
        // Each dept dropdown starts with that dept's own dashboard (the 'service' tag routes
        // to sales-dashboard-service.php via sales_nav_href()).
        return [
            ['dashboard'],
            ['activity'],
            ['Sales',   'fa-briefcase', array_merge(['dashboard'], $deptModules), 'sales'],
            ['Service', 'fa-headset',   array_merge(['dashboard'], $serviceModules), 'service'],
            ['Conversations',  'fa-comments',    ['chat', 'whatsapp', 'inbox', 'conversations', 'calls', 'tickets']],
            ['timesheet'],
            ['Admin',          'fa-user-shield', ['admin', 'org', 'users', 'settings', 'kpa', 'automation', 'integrations']],
        ];
    }

    // HR is a people function, not a sales one. Its nav is built from what HR actually owns —
    // the team, the goal framework, performance and time — with no Sales/Service/IT dropdowns:
    // those tabs made an HR login look like a sales login, and every one of them was a module
    // HR has no business in. ('hr' here is the HR Dashboard, which carries the approvals queue.)
    if (payg_is_hr_role(sales_role())) {
        return [
            ['hr'],
            ['People',      'fa-users-gear', ['users', 'org']],
            ['Performance', 'fa-bullseye',   ['kpa', 'scorecards', 'reports']],
            ['timesheet'],
            ['activity'],
        ];
    }

    $isServiceDept = payg_role_dept(sales_role()) === 'Service';
    return [
        ['dashboard'],
        ['hr'],
        ['it'],
        ['activity'],
        [sales_vertical_label(), 'fa-briefcase',   $isServiceDept ? $serviceModules : $deptModules],
        ['Conversations',  'fa-comments',    ['chat', 'whatsapp', 'inbox', 'conversations', 'calls', 'tickets']],
        ['timesheet'],
        ['Admin',          'fa-user-shield', ['admin', 'org', 'users', 'settings', 'kpa', 'automation', 'integrations']],
    ];
}

/** Pages the current role may access (for the command palette). */
function sales_accessible_pages(): array
{
    $tabs = sales_catalog();
    $allowed = sales_allowed_modules();
    $out = [];
    foreach ($tabs as $k => [$label, $icon]) {
        if (!$allowed || in_array($k, $allowed, true)) $out[] = ['label' => sales_nav_label($k, null, $tabs), 'icon' => $icon, 'href' => sales_nav_href($k, null, $tabs)];
    }
    return $out;
}

/**
 * "My Goal" strip — shows the logged-in user's own current-quarter role-level sales
 * goal (GMV target, attainment, weighted score, gate + payout eligibility) on their
 * individual dashboard. Self-hides if the role has no scorecards module or no card yet.
 * Emits a placeholder + a self-contained script (uses shared apiGet/inr/badge/esc from
 * sales_scripts()); the fetch runs on DOMContentLoaded so those helpers are defined.
 */
/**
 * Read-only "My KPA / KRA Goals" widget — the goals set for the logged-in user by HR / their
 * manager (org + team goals they inherit). This is what a Sales Exec sees in place of the
 * management scorecard: their performance areas and targets, not any payout figure. Any role
 * with goals sees it; self-hides when the user has none. Uses kpa.php?action=my_goals, which
 * only ever returns the caller's OWN applicable goals (safe for every role).
 */
function sales_my_goals_widget(): void
{
    ?>
    <div id="myKpaGoals" class="hidden"></div>
    <script>
    window.addEventListener('DOMContentLoaded', async function () {
      try {
        const r = await apiGet('kpa.php', { action: 'my_goals' });
        const goals = (r && r.data && r.data.goals) || [];
        if (!goals.length) return;                         // no goals set -> stay hidden
        const U = { inr:'₹', pct:'%', count:'#', flag:'', text:'' };
        const rows = goals.map(g => {
          const tgt = g.target_value ? ('<span class="text-gray-500">Target:</span> <b>' + esc(g.target_value) + (U[g.unit]||'') + '</b>') : '';
          const src = g.relation === 'org' ? badge(esc(g.org_group||'Org'), 'indigo') : badge('Manager', 'blue');
          return `<div class="border-b border-gray-100 py-1.5">
            <div class="flex items-center justify-between gap-2">
              <span class="font-medium text-gray-800">${esc(g.title)}</span>
              <span class="whitespace-nowrap text-[11px]">${src}${g.weight?(' <span class="text-gray-400">'+g.weight+'%</span>'):''}</span>
            </div>
            ${g.kra?`<div class="text-[11px] text-gray-500">${esc(g.kra)}</div>`:''}
            <div class="text-[11px] mt-0.5 flex gap-3">${tgt}<span class="text-gray-400">${esc(g.period||'')}</span></div>
          </div>`;
        }).join('');
        const el = document.getElementById('myKpaGoals');
        el.className = 'card p-3';
        el.innerHTML = `<div class="flex items-center justify-between mb-1">
            <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide"><i class="fa-solid fa-crosshairs text-indigo-500"></i> My Goals — KPA / KRA</span>
            <span class="text-[10px] text-gray-400">Set by HR / your manager</span>
          </div>${rows}`;
        el.classList.remove('hidden');
      } catch (e) { /* keep hidden on any error */ }
    });
    </script>
    <?php
}

function sales_goal_widget(): void
{
    // Admin is an oversight role with no personal sales quota — they manage everyone's
    // scorecards from the Scorecards page, so don't show a personal "My Goal" tile.
    // (In the demo the admin login is also linked to employee #3 / Meena Manager, which
    //  is why admin was inheriting the manager's Q3 goal.)
    if (sales_role() === 'admin') return;
    $allowed = sales_allowed_modules();
    if ($allowed && !in_array('scorecards', $allowed, true)) return; // role can't have goals
    // Payout/incentive readiness is management information — a rep sees their KPIs, not their pay.
    $seeIncentive = payg_role_can(sales_role(), 'incentive_manage');
    ?>
    <div id="myGoal" class="hidden"></div>
    <script>
    const GOAL_SEE_INCENTIVE = <?= $seeIncentive ? 'true' : 'false' ?>;
    window.addEventListener('DOMContentLoaded', async function () {
      try {
        const r = await apiGet('scorecards.php', { mine: 1 });
        const c = r && r.data && r.data.card;
        if (!c) return;                                   // no goal set yet -> stay hidden
        const s = c.score;
        const fmtBig = v => { v = +v || 0; return Math.abs(v) >= 1e7 ? '₹' + (v/1e7).toFixed(2) + ' Cr'
                              : Math.abs(v) >= 1e5 ? '₹' + (v/1e5).toFixed(2) + ' L' : inr(v); };
        const RC = { Outstanding:'green', Exceeds:'teal', Meets:'blue', Partial:'yellow', Below:'red' };
        const a = s.gmv_attainment;
        const barCol = a>=110?'bg-teal-500':a>=100?'bg-green-500':a>=70?'bg-yellow-500':'bg-red-500';
        // how much they've met so far (absolute achieved values)
        const L = c.lines || [];
        const line = code => L.find(x => x.kpi_code === code) || {};
        const gmvActual = +line('gmv').actual_value || 0;
        const feeActual = +line('fee_income').actual_value || 0;
        const activeActual = +line('active_merchants').actual_value || 0;
        const el = document.getElementById('myGoal');
        el.className = 'card p-2';
        el.innerHTML = `
          <div class="flex items-center justify-between px-1">
            <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">
              <i class="fa-solid fa-bullseye text-indigo-500"></i> My Goal · ${c.period} · <span class="normal-case text-gray-500">${esc(c.role_level)}</span></span>
            <a href="sales-scorecards.php" class="text-[11px] text-indigo-600 hover:underline">View scorecard <i class="fa-solid fa-arrow-right text-[9px]"></i></a>
          </div>
          <!-- headline: how much met so far vs target -->
          <div class="px-1 mt-1">
            <div class="flex items-baseline justify-between text-xs">
              <span class="text-gray-500">GMV achieved so far (avg/mo)</span>
              <span><b class="text-sm ${a>=100?'text-green-600':'text-gray-800'}">${fmtBig(gmvActual)}</b>
                <span class="text-gray-400">of ${fmtBig(c.gmv_target)} · ${a}%</span></span>
            </div>
            <div class="w-full h-2 bg-gray-100 rounded mt-1"><div class="${barCol} h-2 rounded" style="width:${Math.min(100,Math.max(2,a))}%"></div></div>
            ${a<100?`<div class="text-[10px] text-red-500 mt-0.5">${fmtBig(Math.max(0,c.gmv_target-gmvActual))} to go to clear the GMV gate</div>`
                   :`<div class="text-[10px] text-green-600 mt-0.5">GMV gate cleared${s.accelerator?' · in accelerator zone':''}</div>`}
          </div>
          <div class="kstrip mt-1">
            <div><div class="kv text-${RC[s.rating]||'gray'}-700">${s.weighted_pct}%</div><div class="kl">Weighted · ${s.rating}</div></div>
            <div><div class="kv">${fmtBig(feeActual)}</div><div class="kl">Fee income · ${s.fee_slab}</div></div>
            <div><div class="kv">${activeActual}</div><div class="kl">Active merchants</div></div>
            <div><div class="kv ${s.gate_pass?'text-green-600':'text-red-600'}">${s.gate_pass?'PASS':'FAIL'}</div><div class="kl">GMV gate</div></div>
            ${GOAL_SEE_INCENTIVE?`<div><div class="kv ${s.payout_ready?'text-green-600':'text-yellow-600'}">${s.payout_ready?'READY':'ON HOLD'}</div><div class="kl">Payout</div></div>`:''}
          </div>`;
        el.classList.remove('hidden');
      } catch (e) { /* keep hidden on any error */ }
    });
    </script>
    <?php
}

/**
 * Href for a nav key, optionally tagged with an admin-only ?vertical= (see sales_groups()'s
 * admin "Sales"/"Service" dropdowns). 'leads' swaps to Service's own customer-requests queue
 * (chats/calls/tickets from the chatbot and other channels — not sales prospects) whenever the
 * vertical tag or the caller's own dept is 'service'; see sales_nav_label() for its display name.
 */
function sales_nav_href(string $key, ?string $vertical, array $tabs): string
{
    $href = $tabs[$key][2];
    $isService = $vertical === 'service' || (!$vertical && in_array(sales_role(), ['service_exec', 'service_manager'], true));
    if ($key === 'leads' && $isService) $href = 'sales-requests-service.php';
    if ($key === 'dashboard' && $isService) $href = 'sales-dashboard-service.php';
    // Service works an issue queue, not a lead→deal funnel: swap the Sales board (Lead pipeline
    // + Deal pipeline) for the Service one (New Issues → Pending → Resolved / Transferred).
    if ($key === 'pipeline' && $isService) $href = 'sales-pipeline-service.php';
    return $vertical ? ($href . '?vertical=' . $vertical) : $href;
}

/** Display label for a nav key, swapped per-vertical the same way sales_nav_href() swaps the href. */
function sales_nav_label(string $key, ?string $vertical, array $tabs): string
{
    $isService = $vertical === 'service' || (!$vertical && in_array(sales_role(), ['service_exec', 'service_manager'], true));
    if ($key === 'leads' && $isService) return 'Requests';
    return $tabs[$key][0];
}

function sales_nav(string $active): void
{
    $tabs = sales_catalog();
    $allowed = sales_allowed_modules();
    $can = fn($k) => !$allowed || in_array($k, $allowed, true);
    $nav = sales_groups();
    $topCls = fn($on) => $on ? 'text-indigo-600 border-indigo-600 font-semibold' : 'text-gray-500 border-transparent hover:text-indigo-600';
    $link = function ($k, $vertical = null) use ($tabs, $active, $topCls) {
        [, $icon] = $tabs[$k];
        $label = sales_nav_label($k, $vertical, $tabs);
        $href = sales_nav_href($k, $vertical, $tabs);
        return '<a href="' . $href . '" class="flex items-center gap-2 px-3 py-2 border-b-2 text-xs ' . $topCls($k === $active) . '">'
             . '<i class="fa-solid ' . $icon . '"></i>' . $label . '</a>';
    };

    echo '<nav class="bg-white border-b px-2 sm:px-4 relative">';

    // ---- mobile bar (hamburger + palette) ----
    echo '<div class="md:hidden flex items-center justify-between py-2">';
    echo   '<button type="button" onclick="toggleMobileNav()" class="flex items-center gap-2 text-xs text-gray-600"><i class="fa-solid fa-bars"></i> Menu</button>';
    echo   '<button type="button" onclick="openPalette()" class="text-gray-400"><i class="fa-solid fa-magnifying-glass"></i></button>';
    echo '</div>';

    // ---- desktop grouped dropdowns ----
    echo '<div class="hidden md:flex items-center gap-1">';
    foreach ($nav as $entry) {
        if (count($entry) === 1) { if ($can($entry[0])) echo $link($entry[0]); continue; }
        [$glabel, $gicon, $keys] = $entry;
        $vertical = $entry[3] ?? null;
        $items = array_values(array_filter($keys, $can));
        if (!$items) continue;
        if (count($items) === 1) { echo $link($items[0], $vertical); continue; }
        $groupOn = in_array($active, $items, true);
        echo '<div class="relative group">';
        echo   '<button type="button" class="flex items-center gap-2 px-3 py-2 border-b-2 text-xs ' . $topCls($groupOn) . '">'
             . '<i class="fa-solid ' . $gicon . '"></i>' . $glabel . ' <i class="fa-solid fa-chevron-down text-[8px] opacity-60"></i></button>';
        echo   '<div class="absolute left-0 top-full hidden group-hover:block group-focus-within:block bg-white shadow-lg border rounded-b-lg min-w-[180px] z-50 py-1">';
        foreach ($items as $k) {
            [, $icon] = $tabs[$k];
            $label = sales_nav_label($k, $vertical, $tabs);
            $href = sales_nav_href($k, $vertical, $tabs);
            $icls = $k === $active ? 'text-indigo-600 bg-indigo-50 font-semibold' : 'text-gray-600 hover:bg-gray-50';
            echo '<a href="' . $href . '" class="flex items-center gap-2 px-3 py-1.5 text-xs ' . $icls . '">'
               . '<i class="fa-solid ' . $icon . ' w-4 text-center text-gray-400"></i>' . $label . '</a>';
        }
        echo   '</div></div>';
    }
    // desktop palette trigger
    echo '<button type="button" onclick="openPalette()" class="ml-2 flex items-center gap-1.5 text-[11px] text-gray-400 border rounded px-2 py-1 hover:bg-gray-50" title="Search (Ctrl/Cmd K)"><i class="fa-solid fa-magnifying-glass"></i> <span class="hidden lg:inline">Search</span> <kbd class="hidden lg:inline text-[9px] bg-gray-100 rounded px-1">⌘K</kbd></button>';
    echo '</div>';

    // ---- mobile expanded menu (vertical, grouped) ----
    echo '<div id="mobileNav" class="hidden md:hidden pb-2">';
    foreach ($nav as $entry) {
        if (count($entry) === 1) { if ($can($entry[0])) { [, $i]=$tabs[$entry[0]]; $l=sales_nav_label($entry[0], null, $tabs); $h=sales_nav_href($entry[0], null, $tabs); echo '<a href="'.$h.'" class="flex items-center gap-2 px-2 py-2 text-xs '.($entry[0]===$active?'text-indigo-600 font-semibold':'text-gray-600').'"><i class="fa-solid '.$i.' w-4 text-center"></i>'.$l.'</a>'; } continue; }
        [$glabel, , $keys] = $entry;
        $vertical = $entry[3] ?? null;
        $items = array_values(array_filter($keys, $can));
        if (!$items) continue;
        echo '<div class="px-2 pt-2 pb-1 text-[10px] uppercase tracking-wide text-gray-400">' . $glabel . '</div>';
        foreach ($items as $k) { [, $i]=$tabs[$k]; $l=sales_nav_label($k, $vertical, $tabs); $h=sales_nav_href($k, $vertical, $tabs); echo '<a href="'.$h.'" class="flex items-center gap-2 px-3 py-1.5 text-xs '.($k===$active?'text-indigo-600 bg-indigo-50 font-semibold':'text-gray-600 hover:bg-gray-50').'"><i class="fa-solid '.$i.' w-4 text-center text-gray-400"></i>'.$l.'</a>'; }
    }
    echo '</div>';
    echo '</nav>';
}

function sales_scripts(): void
{ ?>
<div id="toast" class="fixed top-4 right-4 hidden px-4 py-2 rounded-md shadow-lg text-sm z-50 text-white"></div>

<!-- ===== Command palette (Ctrl/Cmd K) ===== -->
<div id="palette" class="hidden fixed inset-0 z-[60] bg-black/40 flex items-start justify-center pt-24 px-4" onclick="if(event.target===this)closePalette()">
  <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden">
    <div class="flex items-center gap-2 px-3 border-b">
      <i class="fa-solid fa-magnifying-glass text-gray-400"></i>
      <input id="palInput" placeholder="Jump to… (type a page name)" class="flex-1 py-3 text-sm outline-none" autocomplete="off">
      <kbd class="text-[10px] text-gray-400 bg-gray-100 rounded px-1.5 py-0.5">esc</kbd>
    </div>
    <div id="palResults" class="max-h-80 overflow-y-auto py-1"></div>
  </div>
</div>
<script>
const SALES_PAGES = <?= json_encode(sales_accessible_pages()) ?>;
let _palIdx = 0, _palList = [];
function openPalette(){ document.getElementById('palette').classList.remove('hidden'); const i=document.getElementById('palInput'); i.value=''; palRender(''); i.focus(); }
function closePalette(){ document.getElementById('palette').classList.add('hidden'); }
function palRender(q){
  q=(q||'').toLowerCase().trim();
  _palList = SALES_PAGES.filter(p=>!q || p.label.toLowerCase().includes(q));
  _palIdx = 0;
  document.getElementById('palResults').innerHTML = _palList.map((p,idx)=>`
    <a href="${p.href}" data-i="${idx}" class="palrow flex items-center gap-3 px-3 py-2 text-sm ${idx===0?'bg-indigo-50 text-indigo-700':'text-gray-700 hover:bg-gray-50'}">
      <i class="fa-solid ${p.icon} w-4 text-center text-gray-400"></i>${p.label}</a>`).join('')
    || '<div class="px-3 py-4 text-center text-gray-400 text-sm">No pages match</div>';
}
function palMove(d){ if(!_palList.length)return; _palIdx=(_palIdx+d+_palList.length)%_palList.length;
  document.querySelectorAll('#palResults .palrow').forEach((el,i)=>el.className='palrow flex items-center gap-3 px-3 py-2 text-sm '+(i===_palIdx?'bg-indigo-50 text-indigo-700':'text-gray-700 hover:bg-gray-50'));
  document.querySelector(`#palResults .palrow[data-i="${_palIdx}"]`)?.scrollIntoView({block:'nearest'}); }
function _inField(el){ return el && (el.tagName==='INPUT'||el.tagName==='TEXTAREA'||el.tagName==='SELECT'||el.isContentEditable); }
document.addEventListener('keydown', e=>{
  if((e.metaKey||e.ctrlKey) && e.key.toLowerCase()==='k'){ e.preventDefault(); openPalette(); return; }
  // "/" opens search when not typing in a field (Ctrl/Cmd-K is often grabbed by the browser)
  if(e.key==='/' && !_inField(document.activeElement) && document.getElementById('palette').classList.contains('hidden')){ e.preventDefault(); openPalette(); return; }
  const open = !document.getElementById('palette').classList.contains('hidden');
  if(!open) return;
  if(e.key==='Escape') closePalette();
  else if(e.key==='ArrowDown'){ e.preventDefault(); palMove(1); }
  else if(e.key==='ArrowUp'){ e.preventDefault(); palMove(-1); }
  else if(e.key==='Enter'){ e.preventDefault(); const p=_palList[_palIdx]; if(p) location.href=p.href; }
});
document.addEventListener('input', e=>{ if(e.target.id==='palInput') palRender(e.target.value); });
function toggleMobileNav(){ document.getElementById('mobileNav')?.classList.toggle('hidden'); }
</script>

<!-- ===== My Tasks slide-over panel (present on every page) ===== -->
<div id="taskPanelOverlay" onclick="closeTaskPanel()" class="hidden fixed inset-0 bg-black/30 z-40"></div>
<aside id="taskPanel" class="fixed top-0 right-0 h-full w-full max-w-md bg-gray-50 shadow-2xl z-50 translate-x-full transition-transform duration-200 flex flex-col">
  <div class="h-12 bg-white border-b flex items-center justify-between px-4">
    <h3 class="font-semibold text-gray-800"><i class="fa-solid fa-list-check text-indigo-600"></i> My Tasks</h3>
    <div class="flex items-center gap-2">
      <button onclick="openAssignModal()" class="bg-indigo-600 text-white text-xs px-2 py-1 rounded hover:bg-indigo-700"><i class="fa-solid fa-plus"></i> Assign</button>
      <button onclick="closeTaskPanel()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>
  <div class="flex text-xs border-b bg-white">
    <button id="tabMine" onclick="switchTaskTab('mine')" class="flex-1 py-2 border-b-2 border-indigo-600 text-indigo-600 font-semibold">Assigned to me</button>
    <button id="tabAssigned" onclick="switchTaskTab('assigned')" class="flex-1 py-2 border-b-2 border-transparent text-gray-500">I assigned</button>
  </div>
  <div id="taskList" class="flex-1 overflow-y-auto p-3 space-y-2"></div>
</aside>

<!-- assign-task modal -->
<div id="taskAssignModal" class="hidden fixed inset-0 bg-black/40 z-[60] items-center justify-center p-4">
  <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
    <div class="flex items-center justify-between px-4 py-3 border-b">
      <h3 class="font-semibold text-gray-800">Assign Task</h3>
      <button onclick="closeAssignModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form onsubmit="submitTask(event)" class="p-4 space-y-3 text-xs">
      <input name="title" placeholder="Task title (e.g. Validate KYC for Patel Traders)" required class="border rounded px-2 py-1.5 w-full">
      <div class="grid grid-cols-2 gap-3">
        <select name="assigned_to" required class="border rounded px-2 py-1.5"><option value="">Assign to…</option></select>
        <select name="task_type" class="border rounded px-2 py-1.5">
          <option value="kyc_validation">KYC Validation</option>
          <option value="document_collection">Document Collection</option>
          <option value="follow_up">Follow-up</option>
          <option value="callback">Callback</option>
          <option value="review">Review</option>
          <option value="onboarding">Onboarding</option>
          <option value="general">General</option>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <select name="priority" class="border rounded px-2 py-1.5">
          <option value="medium">Medium</option><option value="high">High</option>
          <option value="urgent">Urgent</option><option value="low">Low</option>
        </select>
        <input name="due_at" type="date" class="border rounded px-2 py-1.5">
      </div>
      <div class="grid grid-cols-2 gap-3">
        <select name="kpa_goal_id" class="border rounded px-2 py-1.5" title="Attribute this task's effort to a goal">
          <option value="">KPA / KRA (optional)…</option>
        </select>
        <input name="hours_spent" type="number" step="0.25" min="0" placeholder="Hours spent" class="border rounded px-2 py-1.5">
      </div>
      <textarea name="description" placeholder="Details / context" rows="2" class="border rounded px-2 py-1.5 w-full"></textarea>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="closeAssignModal()" class="px-3 py-1.5 border rounded">Cancel</button>
        <button class="bg-indigo-600 text-white px-3 py-1.5 rounded hover:bg-indigo-700">Assign Task</button>
      </div>
    </form>
  </div>
</div>

<script src="js/sales.js"></script>
<script>
/* presence heartbeat — keeps sales_users.last_heartbeat_at fresh so the chatbot can tell
   whether a Sales/Service exec is online before opening a live chat (see chatbot-ticket.php) */
(function(){ const ping=()=>apiSend('auth.php?action=heartbeat', {}).catch(()=>{}); ping(); setInterval(ping, 30000); })();
</script>
<?php if (sales_role() === 'service_exec'): ?>
<style>
@keyframes svcNotifyIn { from { opacity:0; transform:translateX(24px); } to { opacity:1; transform:translateX(0); } }
</style>
<script>
/* ============================================================================================
   UNIFIED SERVICE NOTIFIER — present on EVERY page, Service execs only (never admin/manager).
   One poller drives an on-screen popup stack + an audible alarm for every kind of unclaimed work
   that needs a Service exec RIGHT NOW: a waiting live chat (chatbot service/transaction), an
   unassigned WhatsApp conversation with a fresh inbound, a ringing inbound call, and a chatbot
   "call me back" request. See api/sales/notifications.php.

   Each popup carries an Accept / Pick up button that claims the item (first-to-click-wins, atomic
   server-side) and jumps straight into that customer's chat/thread/call. The alarm is DECLARATIVE:
   it rings while the latest poll still contains items and stops the instant the list is empty. So
   the moment ANY agent claims an item, it drops out of every agent's next poll — their popup
   vanishes and, once nothing is left, the sound stops on every screen. That is the fix for the old
   "sound kept ringing after accepting" bug: nothing depends on catching a one-off "accepted" event.
   ============================================================================================ */
(function () {
  const openMap = new Map();            // key -> { kind, el }
  let alarmTimer = null, currentTone = null, audioCtx = null;

  const stack = document.createElement('div');
  stack.id = 'svcNotifyStack';
  stack.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;display:flex;flex-direction:column;gap:10px;max-width:340px;';
  document.body.appendChild(stack);

  /* ---- audio: high-pitch tones, unlocked on the first real user gesture ---- */
  function getCtx() {
    if (!audioCtx) { try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { return null; } }
    if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
    return audioCtx;
  }
  ['click', 'keydown', 'touchstart'].forEach(ev => document.addEventListener(ev, getCtx, { once: true, passive: true }));
  function playTone(profile) {
    const ctx = getCtx(); if (!ctx) return;
    try {
      const beep = (freq, start, dur, vol) => {
        const o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'square'; o.frequency.value = freq;
        o.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.0001, start);
        g.gain.exponentialRampToValueAtTime(vol, start + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
        o.start(start); o.stop(start + dur + 0.03);
      };
      const now = ctx.currentTime;
      if (profile === 'call') {
        // Phone-style urgent double-ring — distinct from chat, but same high register.
        beep(1600, now, 0.18, 0.38); beep(1950, now + 0.14, 0.24, 0.38);
        beep(1600, now + 0.46, 0.18, 0.38); beep(1950, now + 0.60, 0.24, 0.38);
      } else {
        // Bright high-pitch alert chime for chat / whatsapp / callback.
        beep(1850, now, 0.16, 0.32); beep(2300, now + 0.14, 0.24, 0.32);
      }
    } catch (e) { }
  }
  function startAlarm(profile) {
    if (alarmTimer && currentTone === profile) return;   // already ringing this tone
    if (alarmTimer) { clearInterval(alarmTimer); alarmTimer = null; }
    currentTone = profile;
    playTone(profile);
    alarmTimer = setInterval(() => playTone(profile), profile === 'call' ? 2400 : 3500);
  }
  function stopAlarm() { if (alarmTimer) { clearInterval(alarmTimer); alarmTimer = null; } currentTone = null; }
  function refreshAlarm() {
    if (openMap.size === 0) { stopAlarm(); return; }
    let hasCall = false; openMap.forEach(v => { if (v.kind === 'call') hasCall = true; });
    startAlarm(hasCall ? 'call' : 'alert');       // a ringing call takes the distinct tone
  }

  /* ---- popup cards ---- */
  const ICON = { chat: 'fa-comments', whatsapp: 'fa-whatsapp', call: 'fa-phone-volume', callback: 'fa-phone-flip' };
  const ACCENT = { chat: '#4f46e5', whatsapp: '#16a34a', call: '#dc2626', callback: '#d97706' };
  function dropCard(key) { const v = openMap.get(key); if (v) { v.el.remove(); openMap.delete(key); } refreshAlarm(); }

  async function accept(it, card) {
    const btn = card.querySelector('[data-act="accept"]');
    if (btn) { btn.disabled = true; btn.style.opacity = 0.6; }
    const gone = (msg) => { toast(msg || 'Already taken by another agent', false); dropCard(it.key); };
    try {
      if (it.kind === 'chat') {
        const r = await apiSend('chat.php?action=pickup', { session_id: it.ref_id });
        if (!r.success) return gone(r.error);
        location.href = 'sales-chat.php?open=' + it.ref_id + '&tab=active';
      } else if (it.kind === 'whatsapp') {
        const r = await apiGet('whatsapp-chat.php', { action: 'open_by_phone', phone: it.phone || '', name: it.name || '' });
        if (!r.success) return gone(r.error);
        location.href = 'sales-whatsapp.php?phone=' + encodeURIComponent(it.phone || '');
      } else if (it.kind === 'call') {
        const r = await apiSend('calls.php?action=claim', { call_id: it.ref_id });
        if (!r.success) return gone(r.error || 'Already picked up');
        location.href = 'sales-calls.php';
      } else if (it.kind === 'callback') {
        const r = await apiSend('tickets.php?action=claim', { id: it.ref_id });
        if (!r.success) return gone(r.error || 'Already claimed');
        location.href = 'sales-requests-service.php';
      }
    } catch (e) {
      if (btn) { btn.disabled = false; btn.style.opacity = 1; }
      toast('Network error — please try again', false);
    }
  }

  function renderCard(it) {
    const accent = ACCENT[it.kind] || '#4f46e5';
    const card = document.createElement('div');
    card.dataset.key = it.key;
    card.style.cssText = `background:#fff;border-left:5px solid ${accent};border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.18);padding:12px 14px;animation:svcNotifyIn .18s ease-out;`;
    card.innerHTML = `
      <div style="display:flex;align-items:flex-start;gap:10px;">
        <div style="width:34px;height:34px;border-radius:50%;flex:0 0 auto;display:flex;align-items:center;justify-content:center;color:#fff;background:${accent};"><i class="fa-solid ${ICON[it.kind] || 'fa-bell'}"></i></div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:11px;font-weight:700;color:${accent};text-transform:uppercase;letter-spacing:.03em;">${esc(it.title)}</div>
          <div style="font-size:13px;font-weight:600;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(it.name)}</div>
          <div style="font-size:11px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(it.subtitle)}</div>
        </div>
        <button data-act="dismiss" title="Dismiss on my screen only" style="background:none;border:none;color:#9ca3af;cursor:pointer;font-size:16px;line-height:1;">&times;</button>
      </div>
      <div style="margin-top:10px;">
        <button data-act="accept" style="width:100%;background:${accent};color:#fff;border:none;border-radius:6px;padding:7px 0;font-size:12px;font-weight:600;cursor:pointer;">
          ${it.kind === 'call' ? '<i class="fa-solid fa-phone"></i> Pick up' : '<i class="fa-solid fa-check"></i> Accept'}
        </button>
      </div>`;
    card.querySelector('[data-act="accept"]').onclick = () => accept(it, card);
    card.querySelector('[data-act="dismiss"]').onclick = () => dropCard(it.key);
    return card;
  }

  /* ---- poll + reconcile ---- */
  async function poll() {
    let data;
    try { const res = await apiGet('notifications.php', {}); data = res && res.data; } catch (e) { return; }
    if (!data) return;
    const items = data.items || [];
    const seen = new Set();
    items.forEach(it => {
      seen.add(it.key);
      if (!openMap.has(it.key)) {
        const card = renderCard(it);
        openMap.set(it.key, { kind: it.kind, el: card });
        stack.appendChild(card);
        // If the tab is in the background, also nudge with a desktop notification (click = focus).
        if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
          const n = new Notification(it.title, { body: it.name + ' — ' + it.subtitle });
          n.onclick = () => { window.focus(); n.close(); };
        }
      }
    });
    // Any card whose item is no longer returned was claimed elsewhere (or expired) — drop it. This
    // is what makes the popup vanish and the alarm fall silent on every OTHER agent's screen.
    openMap.forEach((v, key) => { if (!seen.has(key)) dropCard(key); });
    refreshAlarm();
  }

  /* Nav "Requests" badge — full unclaimed-ticket count (numeric only, no sound; the audible
     alerting is the popup notifier above). Kept as its own slow poll so the badge still reflects
     every unclaimed ticket, not just the callback subset the notifier pops. */
  async function pollTicketBadge() {
    try {
      const { data } = await apiGet('tickets.php', { action: 'unclaimed_count' });
      const n = (data && data.unclaimed) || 0;
      const badge = document.getElementById('ticketBadge');
      if (badge) { badge.textContent = n; badge.classList.toggle('hidden', n === 0); }
    } catch (e) { }
  }

  if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
  poll();
  setInterval(poll, 4000);
  pollTicketBadge();
  setInterval(pollTicketBadge, 10000);
})();
</script>
<?php endif; ?>
</body>
</html>
<?php }
