<?php
/**
 * Organisation masters (from the All-In-One design's Organization section):
 *   GET  org-masters.php                 -> {profile, departments, designations} (any logged-in user — feeds pickers)
 *   POST org-masters.php?profile=1       -> save "My Organisation" profile
 *   POST org-masters.php?department=1    -> add {department, sub_department?}
 *   DELETE org-masters.php?department_id -> deactivate
 *   POST org-masters.php?designation=1   -> add/update {id?, title, level?, department?, exec_leadership?}
 *   DELETE org-masters.php?designation_id-> deactivate
 * Writes: admin + HR ('people' capability) — same owners as the People screen.
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
$db = db();

if (method() === 'GET') {
    ok([
        'profile'      => $db->query("SELECT * FROM org_profile WHERE id=1")->fetch() ?: null,
        'departments'  => $db->query("SELECT * FROM org_departments WHERE is_active=1 ORDER BY department, sub_department")->fetchAll(),
        'designations' => $db->query("SELECT * FROM org_designations WHERE is_active=1 ORDER BY level IS NULL, level DESC, title")->fetchAll(),
        'can_edit'     => current_role() === 'admin' || payg_role_can(current_role(), 'people'),
    ]);
}

if (!(current_role() === 'admin' || payg_role_can(current_role(), 'people'))) {
    fail('Organisation masters are managed by admin and HR', 403);
}

if (method() === 'POST' && isset($_GET['profile'])) {
    $b = body();
    $set = []; $args = [];
    foreach (['org_name' => 160, 'legal_name' => 200, 'industry' => 100, 'gstin' => 20, 'pan' => 15, 'cin' => 25,
              'website' => 200, 'email' => 150, 'phone' => 30, 'hq_address' => 400, 'locations' => 5000] as $f => $len) {
        if (array_key_exists($f, $b)) { $set[] = "$f=?"; $args[] = ($b[$f] === '' ? null : mb_substr((string)$b[$f], 0, $len)); }
    }
    if (array_key_exists('founded_year', $b)) { $set[] = "founded_year=?"; $args[] = $b['founded_year'] ? (int)$b['founded_year'] : null; }
    if (!$set) fail('Nothing to save');
    $db->prepare("UPDATE org_profile SET " . implode(',', $set) . " WHERE id=1")->execute($args);
    ok();
}

if (method() === 'POST' && isset($_GET['department'])) {
    $b = body();
    $dept = trim((string)($b['department'] ?? ''));
    if ($dept === '') fail('department required');
    $sub = trim((string)($b['sub_department'] ?? '')) ?: null;
    $db->prepare("INSERT INTO org_departments (department, sub_department) VALUES (?,?)
                  ON DUPLICATE KEY UPDATE is_active=1")->execute([$dept, $sub]);
    ok();
}

if (method() === 'DELETE' && isset($_GET['department_id'])) {
    $db->prepare("UPDATE org_departments SET is_active=0 WHERE id=?")->execute([(int)$_GET['department_id']]);
    ok();
}

if (method() === 'POST' && isset($_GET['designation'])) {
    $b = body();
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') fail('title required');
    $level = ($b['level'] ?? '') !== '' ? max(1, min(20, (int)$b['level'])) : null;
    $dept = trim((string)($b['department'] ?? '')) ?: null;
    $exec = (int)!empty($b['exec_leadership']);
    if (!empty($b['id'])) {
        $db->prepare("UPDATE org_designations SET title=?, level=?, department=?, exec_leadership=?, is_active=1 WHERE id=?")
           ->execute([$title, $level, $dept, $exec, (int)$b['id']]);
    } else {
        $db->prepare("INSERT INTO org_designations (title, level, department, exec_leadership) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE level=VALUES(level), department=VALUES(department),
                                              exec_leadership=VALUES(exec_leadership), is_active=1")
           ->execute([$title, $level, $dept, $exec]);
    }
    ok();
}

if (method() === 'DELETE' && isset($_GET['designation_id'])) {
    $db->prepare("UPDATE org_designations SET is_active=0 WHERE id=?")->execute([(int)$_GET['designation_id']]);
    ok();
}

fail('Unsupported request', 405);
