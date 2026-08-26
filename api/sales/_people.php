<?php
/**
 * People — the person record (employees) and its login (sales_users) as ONE thing.
 *
 * The CRM models a person in two tables: `employees` is the human (33 columns across 20+
 * tables point at employees.id — leads.owner_id, tasks.assigned_to, timesheets.employee_id,
 * kpa_goals.created_by, scorecards.employee_id …), and `sales_users` is their credentials.
 * That split stays — moving those foreign keys would be a rewrite with no upside — but it is
 * an implementation detail. Above this line a person is one row with one optional login, and
 * the relationship is 1:1 (enforced by UNIQUE(employee_id) on sales_users, phase46).
 *
 * Every mutation lives here as a plain function taking a normalised payload, so the two
 * callers that must never disagree — a direct edit, and an HR Head approving a queued edit —
 * execute exactly the same code. See _hr_approvals.php.
 *
 * Contact rules: a login's email + mobile live on the employees row and are what
 * forgot-password.php OTPs against, so any person WITH a login must keep both, in a shape
 * PayG's Send_MobileOTP can actually deliver to.
 */

require_once __DIR__ . '/_roles.php';

/**
 * Store mobiles as bare 10-digit numbers: it's the only shape PayG's Send_MobileOTP accepts,
 * so anything else on file is a number no OTP can reach. Returns '' if it isn't one.
 */
function people_normalize_mobile($raw): string
{
    $d = preg_replace('/\D+/', '', (string)$raw) ?? '';
    if (strlen($d) > 10) $d = substr($d, -10);     // drop +91 / 0 prefixes
    return preg_match('/^[6-9]\d{9}$/', $d) ? $d : '';
}

/** Reject contact details the OTP service could never deliver to. $require -> must be present. */
function people_validate_contact(?string $email, $rawPhone, bool $require): array
{
    if ($email === null || $email === '') {
        if ($require) fail('An email address is required — password-reset OTPs are sent to it');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('That email address is not valid');
    }
    $phone = people_normalize_mobile($rawPhone);
    if ($phone === '') {
        if ($require) fail('A valid 10-digit mobile number is required — password-reset OTPs are sent to it');
        if ($rawPhone !== null && trim((string)$rawPhone) !== '') {
            fail('That mobile number is not valid — it must be a 10-digit Indian mobile (starting 6-9)');
        }
    }
    return [$email ?: null, $phone ?: null];
}

/** 409 unless $email is free (ignoring $exceptId). employees.email is UNIQUE. */
function people_assert_email_free(PDO $db, ?string $email, ?int $exceptId = null): void
{
    if (!$email) return;
    $sql = "SELECT id FROM employees WHERE email=?" . ($exceptId ? " AND id<>?" : "");
    $chk = $db->prepare($sql);
    $chk->execute($exceptId ? [$email, $exceptId] : [$email]);
    if ($chk->fetchColumn()) fail('Someone with that email already exists', 409);
}

/** 409 unless $username is free (ignoring $exceptUserId). */
function people_assert_username_free(PDO $db, string $username, ?int $exceptUserId = null): void
{
    $sql = "SELECT id FROM sales_users WHERE username=?" . ($exceptUserId ? " AND id<>?" : "");
    $chk = $db->prepare($sql);
    $chk->execute($exceptUserId ? [$username, $exceptUserId] : [$username]);
    if ($chk->fetchColumn()) fail('That username is already taken', 409);
}

/**
 * A person's login row — active or not.
 *
 * Deliberately NOT filtered to is_active=1. A person has exactly one login row (phase46 adds
 * UNIQUE(employee_id)) and deactivation just flips is_active, so a deactivated colleague must
 * still resolve to their existing row: an "active only" lookup would report "no login", make
 * people_update() INSERT a second one, and hit the unique index. Re-enabling someone updates
 * the row they already had, which also keeps their username and history.
 */
function people_login_for(PDO $db, int $employeeId): ?array
{
    $s = $db->prepare("SELECT * FROM sales_users WHERE employee_id=? ORDER BY is_active DESC, id ASC LIMIT 1");
    $s->execute([$employeeId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * One row per person: the employee joined to their login. This is what the Users page
 * renders — a single table, not an employee directory sitting next to an account list.
 */
function people_list(PDO $db): array
{
    // Join every login, not just active ones: a deactivated colleague must stay visible as
    // "inactive" so HR can re-enable them, instead of silently reappearing as a person with
    // no login. UNIQUE(employee_id) (phase46) keeps this join one-row-per-person.
    $rows = $db->query("
        SELECT e.id                AS employee_id,
               e.full_name, e.designation, e.department, e.sub_department,
               e.email, e.phone, e.manager_id, e.emp_type, e.governance,
               m.full_name         AS manager_name,
               su.id               AS user_id,
               su.username, su.role, su.is_active, su.last_login_at
        FROM employees e
        LEFT JOIN employees m    ON m.id = e.manager_id
        LEFT JOIN sales_users su ON su.employee_id = e.id
        ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['employee_id'] = (int)$r['employee_id'];
        // `id` is the employee id, kept as an alias because the person IS the employee record
        // here. sales-org.php addresses people as p.id; without this its role/reports-to
        // dropdowns would post setRole(undefined).
        $r['id']          = $r['employee_id'];
        $r['user_id']     = $r['user_id'] !== null ? (int)$r['user_id'] : null;
        $r['manager_id']  = $r['manager_id'] !== null ? (int)$r['manager_id'] : null;
        $r['is_active']   = $r['is_active'] !== null ? (int)$r['is_active'] : 0;
        $r['has_login']   = $r['user_id'] !== null;
        $r['role_label']  = $r['role'] ? payg_role_label($r['role']) : null;
        $r['dept_group']  = $r['role'] ? payg_role_dept($r['role']) : null;
    }
    return $rows;
}

/**
 * Validate + normalise a person payload from the single New/Edit Person form.
 * $existingEmployeeId is set when editing (so uniqueness checks skip the person's own row).
 *
 * Returns the payload that people_create()/people_update() consume — and that the approval
 * queue stores verbatim, so an HR Head approves precisely what was validated and submitted.
 */
function people_normalize_payload(PDO $db, array $b, ?int $existingEmployeeId = null): array
{
    $name = trim((string)($b['full_name'] ?? ''));
    if ($name === '') fail('Full name is required');

    $username = trim((string)($b['username'] ?? ''));
    $wantsLogin = $username !== '';
    $role = (string)($b['role'] ?? '');

    if ($wantsLogin) {
        if (!payg_role_exists($role)) fail('Pick a valid role for this login');
    } elseif ($role !== '') {
        fail('A role only applies to someone with a login — add a username, or clear the role');
    }

    // Contact is mandatory for anyone who can sign in: it is their only route back into the
    // account via a reset OTP. A person with no login has nothing to reset, so it's optional.
    [$email, $phone] = people_validate_contact(
        trim((string)($b['email'] ?? '')) ?: null,
        $b['phone'] ?? null,
        $wantsLogin
    );
    people_assert_email_free($db, $email, $existingEmployeeId);

    $out = [
        'employee_id'    => $existingEmployeeId,
        'full_name'      => $name,
        'department'     => trim((string)($b['department'] ?? '')) ?: null,
        'sub_department' => trim((string)($b['sub_department'] ?? '')) ?: null,
        'designation'    => trim((string)($b['designation'] ?? '')) ?: null,
        'email'          => $email,
        'phone'          => $phone,
        'manager_id'     => (isset($b['manager_id']) && $b['manager_id'] !== '' && $b['manager_id'] !== null)
                                ? (int)$b['manager_id'] : null,
        'username'       => $wantsLogin ? $username : null,
        'role'           => $wantsLogin ? $role : null,
        'is_active'      => array_key_exists('is_active', $b) ? (!empty($b['is_active']) ? 1 : 0) : 1,
        'emp_type'       => in_array($b['emp_type'] ?? '', ['employee', 'contractor'], true) ? $b['emp_type'] : 'employee',
        'governance'     => in_array($b['governance'] ?? '', ['board', 'advisory', 'executive', 'execution'], true) ? $b['governance'] : 'execution',
    ];

    // Never let a plaintext password reach the approval queue — hash at submit time, so the
    // stored payload is a hash whether it is applied now or by an HR Head next week.
    $pw = (string)($b['password'] ?? '');
    if ($pw !== '') {
        if (strlen($pw) < 6) fail('Password must be at least 6 characters');
        $out['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
    }
    // Admin-defined additional fields ride along in the payload, so they are saved by
    // whichever path creates/updates the person — immediately, or from the HR queue.
    $out['custom'] = people_filter_custom($db, $b['custom'] ?? null);
    return $out;
}

/**
 * Keep only values for DEFINED employee custom fields. Keys may be the field_key or
 * the human label (bulk CSV headers use labels) — both resolve case-insensitively.
 */
function people_filter_custom(PDO $db, $custom): array
{
    if (!is_array($custom) || !$custom) return [];
    $byKey = [];
    foreach ($db->query("SELECT field_key, label FROM custom_fields WHERE entity='employee'")->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $byKey[strtolower($d['field_key'])] = $d['field_key'];
        $byKey[strtolower(trim($d['label']))] = $d['field_key'];
    }
    $out = [];
    foreach ($custom as $k => $v) {
        $fk = $byKey[strtolower(trim((string)$k))] ?? null;
        if ($fk !== null && $v !== null && trim((string)$v) !== '') $out[$fk] = trim((string)$v);
    }
    return $out;
}

/** Upsert an employee's custom-field values (EAV, same store the lead/deal forms use). */
function people_save_custom(PDO $db, int $employeeId, array $values): void
{
    if (!$values) return;
    $map = [];
    foreach ($db->query("SELECT id, field_key FROM custom_fields WHERE entity='employee'")->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $map[$d['field_key']] = (int)$d['id'];
    }
    $up = $db->prepare("INSERT INTO custom_field_values (field_id, entity, entity_id, value) VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE value=VALUES(value)");
    foreach ($values as $k => $v) if (isset($map[$k])) $up->execute([$map[$k], 'employee', $employeeId, $v]);
}

/** Reporting line must exist and must not create a cycle. */
function people_assert_manager_ok(PDO $db, ?int $managerId, ?int $employeeId): void
{
    if ($managerId === null) return;
    if ($employeeId !== null && $managerId === $employeeId) fail("Someone can't report to themselves", 400);
    $chk = $db->prepare("SELECT id FROM employees WHERE id=?");
    $chk->execute([$managerId]);
    if (!$chk->fetchColumn()) fail('That manager does not exist', 404);
    if ($employeeId !== null && in_array($managerId, people_descendants($db, $employeeId), true)) {
        fail('That would create a reporting loop (the chosen manager reports under this person)', 400);
    }
}

/** ids of everyone below $rootId in the reporting tree (to prevent manager loops). */
function people_descendants(PDO $db, int $rootId): array
{
    $children = [];
    foreach ($db->query("SELECT id, manager_id FROM employees")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $children[(int)$r['manager_id']][] = (int)$r['id'];
    }
    $out = []; $stack = [$rootId];
    while ($stack) {
        $n = array_pop($stack);
        foreach ($children[$n] ?? [] as $c) if (!in_array($c, $out, true)) { $out[] = $c; $stack[] = $c; }
    }
    return $out;
}

/* ================================================================== *
 *  Mutations — the ONLY writers of employees/sales_users.
 *  Each takes a payload from people_normalize_payload() and is safe to
 *  call either immediately or later, from the approval queue.
 * ================================================================== */

/** Create a person, and their login in the same transaction if a username was given. */
function people_create(PDO $db, array $p): array
{
    people_assert_email_free($db, $p['email'] ?? null);
    if (!empty($p['username'])) {
        people_assert_username_free($db, $p['username']);
        if (empty($p['password_hash'])) fail('A password is required to create a login');
    }
    people_assert_manager_ok($db, $p['manager_id'] ?? null, null);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO employees (full_name, department, sub_department, designation, email, phone, manager_id, emp_type, governance)
                      VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$p['full_name'], $p['department'], $p['sub_department'], $p['designation'],
                      $p['email'], $p['phone'], $p['manager_id'] ?? null,
                      $p['emp_type'] ?? 'employee', $p['governance'] ?? 'execution']);
        $employeeId = (int)$db->lastInsertId();
        people_sync_governance_groups($db, $employeeId, $p['governance'] ?? 'execution');
        if (!empty($p['custom'])) people_save_custom($db, $employeeId, $p['custom']);

        $userId = null;
        if (!empty($p['username'])) {
            $db->prepare("INSERT INTO sales_users (username, password_hash, full_name, role, employee_id, is_active)
                          VALUES (?,?,?,?,?,?)")
               ->execute([$p['username'], $p['password_hash'], $p['full_name'], $p['role'],
                          $employeeId, (int)($p['is_active'] ?? 1)]);
            $userId = (int)$db->lastInsertId();
        }
        $db->commit();
        return ['employee_id' => $employeeId, 'user_id' => $userId];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Update a person: their employee fields, and their login (create/update it if a username
 * is supplied). Keeps the person and their login in step — renaming someone renames the
 * login too, which the old two-table screen let drift apart.
 */
function people_update(PDO $db, array $p): array
{
    $employeeId = (int)($p['employee_id'] ?? 0);
    if (!$employeeId) fail('employee_id required');
    $cur = $db->prepare("SELECT * FROM employees WHERE id=?");
    $cur->execute([$employeeId]);
    if (!$cur->fetch()) fail('That person no longer exists', 404);

    $login = people_login_for($db, $employeeId);
    people_assert_email_free($db, $p['email'] ?? null, $employeeId);
    people_assert_manager_ok($db, $p['manager_id'] ?? null, $employeeId);

    // Clearing contact on someone who signs in would strip their only way back into the
    // account, so refuse it rather than quietly breaking their password reset.
    $willHaveLogin = $login !== null || !empty($p['username']);
    if ($willHaveLogin && (empty($p['email']) || empty($p['phone']))) {
        fail('This person has a login — an email and mobile are required for password reset', 400);
    }
    if (!empty($p['username'])) {
        people_assert_username_free($db, $p['username'], $login['id'] ?? null);
        if ($login === null && empty($p['password_hash'])) {
            fail('Set a password to give this person a login');
        }
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE employees SET full_name=?, department=?, sub_department=?, designation=?,
                             email=?, phone=?, manager_id=?, emp_type=?, governance=? WHERE id=?")
           ->execute([$p['full_name'], $p['department'], $p['sub_department'], $p['designation'],
                      $p['email'], $p['phone'], $p['manager_id'] ?? null,
                      $p['emp_type'] ?? 'employee', $p['governance'] ?? 'execution', $employeeId]);
        people_sync_governance_groups($db, $employeeId, $p['governance'] ?? 'execution');

        if (!empty($p['username'])) {
            if ($login) {
                $set  = ['username=?', 'full_name=?', 'role=?', 'is_active=?'];
                $args = [$p['username'], $p['full_name'], $p['role'], (int)($p['is_active'] ?? 1)];
                if (!empty($p['password_hash'])) { $set[] = 'password_hash=?'; $args[] = $p['password_hash']; }
                $args[] = (int)$login['id'];
                $db->prepare("UPDATE sales_users SET " . implode(',', $set) . " WHERE id=?")->execute($args);
            } else {
                $db->prepare("INSERT INTO sales_users (username, password_hash, full_name, role, employee_id, is_active)
                              VALUES (?,?,?,?,?,?)")
                   ->execute([$p['username'], $p['password_hash'], $p['full_name'], $p['role'],
                              $employeeId, (int)($p['is_active'] ?? 1)]);
            }
        }
        if (!empty($p['custom'])) people_save_custom($db, $employeeId, $p['custom']);
        $db->commit();
        return ['employee_id' => $employeeId];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Governance tier -> access-profile group sync:
 *   board     -> member of the full-access "Board" group (all modules + all records)
 *   executive -> member of "C-Suite" (its area modules)
 * Any other tier removes the person from both. Non-fatal — a missing group just skips.
 */
function people_sync_governance_groups(PDO $db, int $employeeId, string $governance): void
{
    try {
        foreach (['board' => 'Board', 'executive' => 'C-Suite'] as $tier => $groupName) {
            $gid = $db->query("SELECT id FROM emp_groups WHERE name=" . $db->quote($groupName) . " AND is_active=1")->fetchColumn();
            if (!$gid) continue;
            if ($governance === $tier) {
                $db->prepare("INSERT IGNORE INTO emp_group_members (group_id, employee_id) VALUES (?,?)")
                   ->execute([(int)$gid, $employeeId]);
            } else {
                $db->prepare("DELETE FROM emp_group_members WHERE group_id=? AND employee_id=?")
                   ->execute([(int)$gid, $employeeId]);
            }
        }
    } catch (Throwable $e) { /* groups are an overlay — never break the person write */ }
}

/** Soft-deactivate a person's login (never hard-delete — their history must survive). */
function people_deactivate(PDO $db, array $p): array
{
    $employeeId = (int)($p['employee_id'] ?? 0);
    if (!$employeeId) fail('employee_id required');
    $db->prepare("UPDATE sales_users SET is_active=0 WHERE employee_id=?")->execute([$employeeId]);
    return ['employee_id' => $employeeId];
}

/** Change who someone reports to (org chart). */
function people_set_manager(PDO $db, array $p): array
{
    $employeeId = (int)($p['employee_id'] ?? 0);
    if (!$employeeId) fail('employee_id required');
    $mgr = isset($p['manager_id']) && $p['manager_id'] !== null && $p['manager_id'] !== ''
        ? (int)$p['manager_id'] : null;
    people_assert_manager_ok($db, $mgr, $employeeId);
    $db->prepare("UPDATE employees SET manager_id=? WHERE id=?")->execute([$mgr, $employeeId]);
    $name = null;
    if ($mgr) {
        $n = $db->prepare("SELECT full_name FROM employees WHERE id=?");
        $n->execute([$mgr]);
        $name = $n->fetchColumn() ?: null;
    }
    return ['employee_id' => $employeeId, 'manager_id' => $mgr, 'manager_name' => $name];
}

/** Change a person's login role. */
function people_set_role(PDO $db, array $p): array
{
    $employeeId = (int)($p['employee_id'] ?? 0);
    $role = (string)($p['role'] ?? '');
    if (!$employeeId) fail('employee_id required');
    if (!payg_role_exists($role)) fail('Unknown role');
    $login = people_login_for($db, $employeeId);
    if (!$login) fail('This person has no active login — create one first', 409);
    $db->prepare("UPDATE sales_users SET role=? WHERE id=?")->execute([$role, (int)$login['id']]);
    return ['employee_id' => $employeeId, 'role' => $role, 'role_label' => payg_role_label($role)];
}
