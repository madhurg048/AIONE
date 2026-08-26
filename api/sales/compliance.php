<?php
/**
 * Compliance registry — RBI / regulatory policies, compliances, certifications
 * and licenses, each with expiry + reminder windows and named stakeholders.
 * Access is via the 'compliance' module: granted by role RBAC OR by membership
 * of a group that carries the module (e.g. "Compliance Team") — see
 * require_module()'s group union in _bootstrap.php.
 *
 *   GET  compliance.php?list=1[&type=&state=&q=]   -> items with computed state + days left
 *   GET  compliance.php?id=N                       -> item + stakeholders + activity log
 *   GET  compliance.php?stats=1                    -> KPI strip numbers
 *   GET  compliance.php?employees=1                -> stakeholder picker
 *   GET  compliance.php?groups=1                   -> owner-group picker
 *   POST compliance.php                            -> create {title,item_type,...,stakeholders:[{employee_id,role}]}
 *   PATCH compliance.php?id=N                      -> update fields
 *   POST compliance.php?id=N&stakeholders=1        -> {add:[{employee_id,role}], remove:[employee_ids]}
 *   POST compliance.php?action=renew&id=N          -> {issued_on, valid_till, notes}
 *   POST compliance.php?action=archive&id=N
 *   POST compliance.php?action=remind&id=N         -> send the reminder now
 *   POST compliance.php?action=run_reminders       -> run the daily sweep now
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_governance.php';
require_once __DIR__ . '/_escalation_engine.php';
require_once __DIR__ . '/_compliance_engine.php';
require_login();
require_module('compliance');
$db = db();
$me = current_employee_id();

const COMP_STATE_SQL = "CASE WHEN i.valid_till IS NULL THEN 'active'
                             WHEN i.valid_till < CURDATE() THEN 'expired'
                             WHEN DATE_SUB(i.valid_till, INTERVAL i.reminder_days DAY) <= CURDATE() THEN 'expiring'
                             ELSE 'active' END";

if (method() === 'GET' && isset($_GET['stats'])) {
    ok($db->query("SELECT COUNT(*) total,
                          SUM(i.valid_till IS NOT NULL AND i.valid_till < CURDATE()) expired,
                          SUM(i.valid_till IS NOT NULL AND i.valid_till >= CURDATE()
                              AND DATE_SUB(i.valid_till, INTERVAL i.reminder_days DAY) <= CURDATE()) expiring,
                          SUM(i.item_type='policy') policies, SUM(i.item_type='certification') certifications,
                          SUM(i.item_type='compliance') compliances, SUM(i.item_type='license') licenses
                     FROM compliance_items i WHERE i.status='active'")->fetch());
}

if (method() === 'GET' && isset($_GET['employees'])) {
    ok($db->query("SELECT id, full_name, designation FROM employees ORDER BY full_name")->fetchAll());
}

if (method() === 'GET' && isset($_GET['groups'])) {
    ok($db->query("SELECT id, name FROM emp_groups WHERE is_active=1 ORDER BY name")->fetchAll());
}

if (method() === 'GET' && isset($_GET['id'])) {
    $s = $db->prepare("SELECT i.*, " . COMP_STATE_SQL . " AS state,
                              DATEDIFF(i.valid_till, CURDATE()) AS days_left,
                              g.name AS owner_group_name, e.full_name AS owner_name
                         FROM compliance_items i
                    LEFT JOIN emp_groups g ON g.id=i.owner_group_id
                    LEFT JOIN employees e ON e.id=i.owner_employee_id
                        WHERE i.id=?");
    $s->execute([(int)$_GET['id']]);
    $item = $s->fetch();
    if (!$item) fail('Not found', 404);
    $st = $db->prepare("SELECT s.id, s.employee_id, s.role, e.full_name, e.designation
                          FROM compliance_stakeholders s JOIN employees e ON e.id=s.employee_id
                         WHERE s.item_id=? ORDER BY e.full_name");
    $st->execute([(int)$item['id']]);
    $item['stakeholders'] = $st->fetchAll();
    $lg = $db->prepare("SELECT l.*, e.full_name AS actor_name FROM compliance_log l
                   LEFT JOIN employees e ON e.id=l.actor_id WHERE l.item_id=? ORDER BY l.id DESC LIMIT 50");
    $lg->execute([(int)$item['id']]);
    $item['log'] = $lg->fetchAll();
    ok($item);
}

if (method() === 'GET') { // list
    $w = ["i.status='active'"]; $args = [];
    if (($_GET['state'] ?? '') === 'archived') { $w = ["i.status='archived'"]; }
    if (!empty($_GET['type']))  { $w[] = "i.item_type=?"; $args[] = $_GET['type']; }
    if (!empty($_GET['q']))     { $w[] = "(i.title LIKE ? OR i.category LIKE ? OR i.authority LIKE ? OR i.reference_no LIKE ?)";
                                  $q = '%' . $_GET['q'] . '%'; array_push($args, $q, $q, $q, $q); }
    $having = '';
    if (in_array($_GET['state'] ?? '', ['expiring','expired'], true)) { $having = "HAVING state=" . $db->quote($_GET['state']); }
    $s = $db->prepare("SELECT i.*, " . COMP_STATE_SQL . " AS state,
                              DATEDIFF(i.valid_till, CURDATE()) AS days_left,
                              g.name AS owner_group_name, e.full_name AS owner_name,
                              (SELECT COUNT(*) FROM compliance_stakeholders s WHERE s.item_id=i.id) AS stakeholder_count
                         FROM compliance_items i
                    LEFT JOIN emp_groups g ON g.id=i.owner_group_id
                    LEFT JOIN employees e ON e.id=i.owner_employee_id
                        WHERE " . implode(' AND ', $w) . " $having
                        ORDER BY (i.valid_till IS NULL), i.valid_till ASC, i.title");
    $s->execute($args);
    ok($s->fetchAll());
}

/* ------------------------------- writes -------------------------------- */

function comp_log(PDO $db, int $itemId, string $action, ?string $notes = null): void
{
    try {
        $db->prepare("INSERT INTO compliance_log (item_id, action, actor_id, notes) VALUES (?,?,?,?)")
           ->execute([$itemId, $action, current_employee_id() ?: null, $notes !== null ? mb_substr($notes, 0, 500) : null]);
    } catch (Throwable $e) {}
}

if (method() === 'POST' && !isset($_GET['action']) && !isset($_GET['id'])) { // create
    $b = body();
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') fail('title required');
    $type = in_array($b['item_type'] ?? '', ['policy','compliance','certification','license'], true) ? $b['item_type'] : 'compliance';
    $freq = in_array($b['renewal_frequency'] ?? '', ['once','monthly','quarterly','half_yearly','yearly','custom'], true) ? $b['renewal_frequency'] : 'yearly';
    $db->prepare("INSERT INTO compliance_items
                    (title, item_type, category, reference_no, authority, description, doc_url,
                     owner_group_id, owner_employee_id, issued_on, valid_till, reminder_days, renewal_frequency, created_by)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$title, $type, ($b['category'] ?? null) ?: null, ($b['reference_no'] ?? null) ?: null, ($b['authority'] ?? null) ?: null,
                  ($b['description'] ?? null) ?: null, ($b['doc_url'] ?? null) ?: null,
                  !empty($b['owner_group_id']) ? (int)$b['owner_group_id'] : null,
                  !empty($b['owner_employee_id']) ? (int)$b['owner_employee_id'] : null,
                  ($b['issued_on'] ?? null) ?: null, ($b['valid_till'] ?? null) ?: null,
                  max(1, (int)($b['reminder_days'] ?? 30)), $freq, $me ?: null]);
    $id = (int)$db->lastInsertId();
    foreach ((array)($b['stakeholders'] ?? []) as $s) {
        $emp = (int)($s['employee_id'] ?? 0);
        if ($emp) $db->prepare("INSERT IGNORE INTO compliance_stakeholders (item_id, employee_id, role) VALUES (?,?,?)")
                     ->execute([$id, $emp, mb_substr((string)($s['role'] ?? 'stakeholder'), 0, 60) ?: 'stakeholder']);
    }
    comp_log($db, $id, 'created', $type . ' · ' . (($b['category'] ?? '') ?: 'uncategorised'));
    gov_approval($db, 'policy', $id, true);                        // needs review + approval
    gov_revise($db, 'policy', $id, 'created', 'policy "' . $title . '" created');
    ok(['id' => $id, 'policy_no' => gov_code($db, 'policy', $id)]);
}

if ((method() === 'PATCH' || method() === 'PUT') && isset($_GET['id'])) {
    $id = (int)$_GET['id']; $b = body();
    $set = []; $args = [];
    foreach (['title','category','reference_no','authority','description','doc_url','issued_on','valid_till'] as $f) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = ($b[$f] === '' ? null : $b[$f]); }
    }
    if (array_key_exists('item_type', $b) && in_array($b['item_type'], ['policy','compliance','certification','license'], true)) { $set[] = "item_type=?"; $args[] = $b['item_type']; }
    if (array_key_exists('renewal_frequency', $b) && in_array($b['renewal_frequency'], ['once','monthly','quarterly','half_yearly','yearly','custom'], true)) { $set[] = "renewal_frequency=?"; $args[] = $b['renewal_frequency']; }
    if (array_key_exists('reminder_days', $b)) { $set[] = "reminder_days=?"; $args[] = max(1, (int)$b['reminder_days']); }
    if (array_key_exists('owner_group_id', $b)) { $set[] = "owner_group_id=?"; $args[] = $b['owner_group_id'] ? (int)$b['owner_group_id'] : null; }
    if (array_key_exists('owner_employee_id', $b)) { $set[] = "owner_employee_id=?"; $args[] = $b['owner_employee_id'] ? (int)$b['owner_employee_id'] : null; }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE compliance_items SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    comp_log($db, $id, 'updated', null);
    gov_revise($db, 'policy', $id, 'updated', 'edited: ' . implode(', ', array_map(fn($x) => explode('=', $x)[0], $set)));
    gov_touch($db, 'policy', $id);                                 // an edited policy needs re-approval
    ok(['id' => $id, 'policy_no' => gov_code($db, 'policy', $id)]);
}

if (method() === 'POST' && isset($_GET['id'], $_GET['stakeholders'])) {
    $id = (int)$_GET['id']; $b = body();
    foreach ((array)($b['add'] ?? []) as $s) {
        $emp = (int)($s['employee_id'] ?? 0);
        if ($emp) $db->prepare("INSERT INTO compliance_stakeholders (item_id, employee_id, role) VALUES (?,?,?)
                                ON DUPLICATE KEY UPDATE role=VALUES(role)")
                     ->execute([$id, $emp, mb_substr((string)($s['role'] ?? 'stakeholder'), 0, 60) ?: 'stakeholder']);
    }
    foreach (array_map('intval', (array)($b['remove'] ?? [])) as $emp) {
        if ($emp) $db->prepare("DELETE FROM compliance_stakeholders WHERE item_id=? AND employee_id=?")->execute([$id, $emp]);
    }
    comp_log($db, $id, 'stakeholders_changed', null);
    ok();
}

if (method() === 'POST' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'run_reminders') {
        ok(run_compliance_reminders($db));
    }

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');
    $s = $db->prepare("SELECT * FROM compliance_items WHERE id=?"); $s->execute([$id]);
    $item = $s->fetch();
    if (!$item) fail('Not found', 404);
    $b = body();

    switch ($action) {
        case 'renew':
            if (empty($b['valid_till'])) fail('New valid_till date required');
            $db->prepare("UPDATE compliance_items SET issued_on=?, valid_till=?, last_reminded_at=NULL, status='active' WHERE id=?")
               ->execute([($b['issued_on'] ?? '') ?: date('Y-m-d'), $b['valid_till'], $id]);
            comp_log($db, $id, 'renewed', 'Valid till ' . $b['valid_till'] . (!empty($b['notes']) ? ' — ' . $b['notes'] : ''));
            // close any open renewal task for this item
            try {
                $db->prepare("UPDATE tasks SET status='done', completed_at=NOW() WHERE title=? AND status IN('open','in_progress')")
                   ->execute(['Renew ' . $item['item_type'] . ': ' . $item['title']]);
            } catch (Throwable $e) {}
            ok();

        case 'archive':
            $db->prepare("UPDATE compliance_items SET status='archived' WHERE id=?")->execute([$id]);
            comp_log($db, $id, 'archived', $b['notes'] ?? null);
            ok();

        case 'remind':
            $n = compliance_remind_item($db, $item, true);
            ok(['notified' => $n]);
    }
    fail('Unknown action');
}

fail('Unsupported request', 405);
