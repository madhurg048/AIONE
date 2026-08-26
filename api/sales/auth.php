<?php
/**
 * Single sign-on for the Sales CRM (role-based).
 *   GET  auth.php?action=me           -> current session
 *   GET  auth.php?action=logout       -> destroy session
 *   POST auth.php?action=login {username,password} -> {role, redirect}
 *   GET  auth.php?action=samples      -> sample logins for the test/login screen
 *
 * Auto-seeds a few sample accounts the first time so the login screen is usable.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../config/app.php';
$db = db();

function auth_client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function auth_ensure_rate_limit_table(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS auth_login_attempts (
        username varchar(191) NOT NULL,
        ip_address varchar(64) NOT NULL,
        attempts int NOT NULL DEFAULT 0,
        first_attempt_at timestamp NULL DEFAULT NULL,
        last_attempt_at timestamp NULL DEFAULT NULL,
        blocked_until timestamp NULL DEFAULT NULL,
        PRIMARY KEY (username, ip_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function auth_rate_limit_state(PDO $db, string $username, string $ip): ?array {
    auth_ensure_rate_limit_table($db);
    $s = $db->prepare("SELECT * FROM auth_login_attempts WHERE username=? AND ip_address=?");
    $s->execute([$username, $ip]);
    return $s->fetch() ?: null;
}

function auth_guard_rate_limit(PDO $db, string $username, string $ip): void {
    $row = auth_rate_limit_state($db, $username, $ip);
    if ($row && !empty($row['blocked_until']) && strtotime((string)$row['blocked_until']) > time()) {
        fail('Too many login attempts. Try again later.', 429);
    }
}

function auth_record_failed_login(PDO $db, string $username, string $ip): void {
    auth_ensure_rate_limit_table($db);
    $row = auth_rate_limit_state($db, $username, $ip);
    $attempts = 1;
    if ($row && !empty($row['last_attempt_at']) && strtotime((string)$row['last_attempt_at']) > (time() - 900)) {
        $attempts = ((int)$row['attempts']) + 1;
    }
    $blockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
    $db->prepare("INSERT INTO auth_login_attempts (username, ip_address, attempts, first_attempt_at, last_attempt_at, blocked_until)
                  VALUES (?, ?, ?, NOW(), NOW(), ?)
                  ON DUPLICATE KEY UPDATE
                    attempts=VALUES(attempts),
                    first_attempt_at=IF(last_attempt_at > NOW() - INTERVAL 15 MINUTE, first_attempt_at, VALUES(first_attempt_at)),
                    last_attempt_at=VALUES(last_attempt_at),
                    blocked_until=VALUES(blocked_until)")
       ->execute([$username, $ip, $attempts, $blockedUntil]);
}

function auth_clear_failed_logins(PDO $db, string $username, string $ip): void {
    auth_ensure_rate_limit_table($db);
    $db->prepare("DELETE FROM auth_login_attempts WHERE username=? AND ip_address=?")->execute([$username, $ip]);
}

// role -> landing screen
function landing_for(string $role): string {
    return ['admin'=>'sales-admin.php',
            'sales_head'=>'sales-dashboard.php','manager'=>'sales-dashboard.php','service_manager'=>'sales-dashboard.php',
            'sales_exec'=>'sales-leads.php','service_exec'=>'sales-requests-service.php',
            'hr_head'=>'sales-hr.php','hr_manager'=>'sales-hr.php','hr'=>'sales-hr.php',
            'it'=>'sales-it.php','it_manager'=>'sales-it.php',
            'cfo'=>'sales-cfo.php','finance_manager'=>'sales-cfo.php',
            'support'=>'sales-chat.php','support_manager'=>'sales-conversations.php'][$role] ?? 'sales-dashboard.php';
}
/**
 * Coarse session user_type. current_role() (from data.role) is the real authority — this only
 * feeds the legacy `user_type` checks. Heads share their department manager's coarse type so
 * any remaining user_type test treats a Head as at least a manager, never as a rank-and-file
 * employee.
 */
function user_type_for(string $role): string {
    if (in_array($role, ['sales_exec', 'service_exec'], true)) return 'employee';
    if (in_array($role, ['service_manager', 'sales_head'], true)) return 'manager';
    if ($role === 'hr_head') return 'hr_manager';
    return $role;   // admin|manager|hr|hr_manager|it|it_manager|support|support_manager
}

// -------- optional one-time seed of sample users --------
$cnt = (int)$db->query("SELECT COUNT(*) FROM sales_users")->fetchColumn();
if ($cnt === 0 && payg_sample_login_enabled()) {
    // link to existing employees where present
    $emp = [];
    foreach ($db->query("SELECT id, full_name FROM employees")->fetchAll() as $e) $emp[$e['full_name']] = $e['id'];
    $seed = [
        ['admin',   'admin123',   'Admin User',    'admin',      $emp['Meena Manager'] ?? null],
        ['manager', 'manager123', 'Meena Manager', 'manager',    $emp['Meena Manager'] ?? null],
        ['neha',    'neha123',    'Neha Kapoor',   'service_manager', $emp['Neha Kapoor'] ?? null],
        ['asha',    'asha123',    'Asha Sales',    'sales_exec', $emp['Asha Sales'] ?? null],
        ['ravi',    'ravi123',    'Ravi Sales',    'sales_exec', $emp['Ravi Sales'] ?? null],
        ['priya',   'priya123',   'Priya Service', 'service_exec', $emp['Priya Service'] ?? null],
        ['zoya',    'zoya123',    'Zoya Service',  'service_exec', $emp['Zoya Service'] ?? null],
    ];
    $ins = $db->prepare("INSERT INTO sales_users (username,password_hash,full_name,role,employee_id) VALUES (?,?,?,?,?)");
    foreach ($seed as [$u,$p,$n,$r,$e]) $ins->execute([$u, password_hash($p, PASSWORD_DEFAULT), $n, $r, $e]);
}

$action = $_GET['action'] ?? 'me';

// Current session's CSRF token (creating the session if the old one expired).
// Lets a long-idle tab re-sync instead of dying with "CSRF validation failed":
// the login page fetches this just before submitting, and js/sales.js retries
// a failed write once with a fresh token. GET + no state change = safe to expose.
if ($action === 'csrf') {
    ok(['csrf' => payg_csrf_token()]);
}

if ($action === 'heartbeat') {
    if (empty($_SESSION['user'])) fail('Not authenticated', 401);
    $uid = (int)($_SESSION['user']['data']['user_id'] ?? 0);
    if ($uid) $db->prepare("UPDATE sales_users SET last_heartbeat_at=NOW() WHERE id=?")->execute([$uid]);
    ok(['ok' => true]);
}

if ($action === 'logout') {
    if (method() !== 'POST') fail('POST required', 405);
    if (!empty($_SESSION['user']['data']['user_id'])) {
        $db->prepare("UPDATE sales_users SET last_heartbeat_at=NULL WHERE id=?")->execute([(int)$_SESSION['user']['data']['user_id']]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    ok(['loggedOut' => true]);
}

if ($action === 'me') {
    if (empty($_SESSION['user'])) fail('Not authenticated', 401);
    ok($_SESSION['user']);
}

if ($action === 'samples') {
    if (!payg_sample_login_enabled()) {
        ok([]);
    }
    // DEV ONLY: every active login in sales_users, one card each. Passwords are NOT
    // real — every account's password is reset to "<username>123" by
    // reset-dev-passwords.php so these buttons always work. Remove before prod
    // (payg_sample_login_enabled() must return false there).
    // Labels come from the role registry (_roles.php) so a new role shows its proper name here
    // the moment it exists; only the one-line blurb is per-role copy.
    $roleDesc = [
        'admin'            => 'Define incentive structure, settings',
        'hr_head'          => 'HR Head — people, global KPIs, approves HR changes',
        'hr_manager'       => 'HR Manager — people & goals (changes need HR Head approval)',
        'hr'               => 'HR Executive — people & goals (changes need HR Head approval)',
        'sales_head'       => 'Sales Head — whole sales dept, goals, approvals',
        'manager'          => 'Sales dept — dashboard, pipeline, approvals',
        'service_manager'  => 'Service dept — dashboard, tickets, approvals',
        'sales_exec'       => 'Leads, follow-ups, my tasks',
        'service_exec'     => 'Service tickets, live chat, callbacks',
        'it'               => 'IT module — users, integrations',
        'it_manager'       => 'IT module — users, integrations',
        'support'          => 'Live Chat console',
        'support_manager'  => 'Live Monitor, reports, support users',
    ];
    $order = implode(',', array_map(fn($r) => "'" . $r . "'", array_keys(payg_roles())));
    $rows = $db->query("SELECT username, full_name, role FROM sales_users WHERE is_active=1
                        ORDER BY FIELD(role,$order), username")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['username'=>$r['username'], 'password'=>$r['username'].'123',
                  'label'=>payg_role_label($r['role']).' ('.$r['full_name'].')',
                  'role'=>$r['role'], 'desc'=>$roleDesc[$r['role']] ?? ''];
    }
    ok($out);
}

if ($action === 'login') {
    if (method() !== 'POST') fail('POST required', 405);
    $b = body();
    $u = trim($b['username'] ?? '');
    $p = (string)($b['password'] ?? '');
    if ($u === '' || $p === '') fail('Username and password required');
    $ip = auth_client_ip();
    auth_guard_rate_limit($db, $u, $ip);
    // One field, three identifiers: email (default), 10-digit phone, or plain
    // username (kept for existing accounts + the dev sample buttons). Email/phone
    // resolve through the linked employee; an ambiguous match (two employees on
    // one phone) falls through to the username lookup and fails closed.
    $user = null;
    if (strpos($u, '@') !== false) {
        $s = $db->prepare("SELECT su.* FROM sales_users su JOIN employees e ON e.id=su.employee_id
                            WHERE LOWER(e.email)=LOWER(?) AND su.is_active=1 LIMIT 2");
        $s->execute([$u]);
        $rows = $s->fetchAll();
        if (count($rows) === 1) $user = $rows[0];
    } else {
        $digits = preg_replace('/\D+/', '', $u);
        if ($digits !== '' && strlen($digits) >= 8 && !preg_match('/[a-z]/i', $u)) {
            $s = $db->prepare("SELECT su.* FROM sales_users su JOIN employees e ON e.id=su.employee_id
                                WHERE RIGHT(REGEXP_REPLACE(COALESCE(e.phone,''), '[^0-9]', ''), 10)=? AND su.is_active=1 LIMIT 2");
            $s->execute([substr($digits, -10)]);
            $rows = $s->fetchAll();
            if (count($rows) === 1) $user = $rows[0];
        }
    }
    if (!$user) {
        $s = $db->prepare("SELECT * FROM sales_users WHERE username=? AND is_active=1");
        $s->execute([$u]);
        $user = $s->fetch();
    }
    if (!$user || !password_verify($p, $user['password_hash'])) {
        auth_record_failed_login($db, $u, $ip);
        fail('Invalid credentials', 401);
    }

    session_regenerate_id(true);
    payg_rotate_csrf_token();
    auth_clear_failed_logins($db, $u, $ip);
    $db->prepare("UPDATE sales_users SET last_login_at=NOW(), last_heartbeat_at=NOW() WHERE id=?")->execute([$user['id']]);
    // session in the shape the module expects
    $_SESSION['user'] = [
        'user_type' => user_type_for($user['role']),
        'data' => [
            'user_id' => (int)$user['id'],
            'full_name' => $user['full_name'],
            'id' => (int)($user['employee_id'] ?: $user['id']),
            'role' => $user['role'],
            'employee' => ['id' => (int)($user['employee_id'] ?: 0), 'designation' => ucfirst(str_replace('_',' ',$user['role']))],
        ],
    ];
    ok(['role' => $user['role'], 'redirect' => landing_for($user['role']), 'name' => $user['full_name'], 'csrf' => payg_csrf_token()]);
}

/* ---- admin: impersonate a user (view their dashboard) ---- */
if ($action === 'impersonate') {
    $realType = $_SESSION['impersonator']['user_type'] ?? ($_SESSION['user']['user_type'] ?? '');
    if ($realType !== 'admin') fail('Admin only', 403);   // only a real admin may impersonate
    $uid = (int)(body()['user_id'] ?? $_GET['user_id'] ?? 0);
    $s = $db->prepare("SELECT * FROM sales_users WHERE id=? AND is_active=1");
    $s->execute([$uid]); $user = $s->fetch();
    if (!$user) fail('User not found', 404);
    if (empty($_SESSION['impersonator'])) $_SESSION['impersonator'] = $_SESSION['user'];   // remember the real admin
    $_SESSION['user'] = [
        'user_type' => user_type_for($user['role']),
        'data' => [
            'full_name' => $user['full_name'],
            'id' => (int)($user['employee_id'] ?: $user['id']),
            'role' => $user['role'],
            'employee' => ['id' => (int)($user['employee_id'] ?: 0), 'designation' => ucfirst(str_replace('_',' ',$user['role']))],
        ],
    ];
    ok(['role' => $user['role'], 'redirect' => landing_for($user['role']), 'name' => $user['full_name']]);
}

/* ---- return to the real admin session ---- */
if ($action === 'stop_impersonate') {
    if (!empty($_SESSION['impersonator'])) {
        $_SESSION['user'] = $_SESSION['impersonator'];
        unset($_SESSION['impersonator']);
    }
    ok(['redirect' => landing_for($_SESSION['user']['data']['role'] ?? 'admin')]);
}

fail('Unknown action', 400);
