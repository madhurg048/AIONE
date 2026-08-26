<?php
/**
 * Incentive structure admin (define plans + flexible rules).
 *   GET    incentive-admin.php                 -> plans[] each with rules[]
 *   POST   incentive-admin.php?plan            -> create plan
 *   PATCH  incentive-admin.php?plan=ID         -> update plan
 *   DELETE incentive-admin.php?plan=ID
 *   POST   incentive-admin.php?rule            -> create rule
 *   PATCH  incentive-admin.php?rule=ID         -> update rule
 *   DELETE incentive-admin.php?rule=ID
 *   POST   incentive-admin.php?preview {volume,deal_amount,products,category,business_type}
 *          -> what a merchant with those attributes would earn (dry-run of the engine)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_incentive_engine.php';
require_login();
// Incentive structure is Sales-owned: Admin, Sales Head and Sales Managers (capability
// 'incentive_manage'). Was hard-gated to user_type==='admin', which blocked the Sales Head /
// Manager from tuning their own team's payout rules — see _roles.php.
if (!can_manage_incentives()) fail('You are not authorized to manage incentive plans', 403);
$db = db();

$RULE_FIELDS = ['plan_id','rule_type','label','product','category','business_type','applies_per',
                'flat_amount','percent_rate','slab_min','slab_max','slab_amount','priority','is_active'];
$PLAN_FIELDS = ['name','description','effective_from','effective_to','stack_rules','is_active'];

/* ---- PREVIEW (dry-run calculator) ---- */
if (method() === 'POST' && isset($_GET['preview'])) {
    $b = body();
    $merchant = [
        'id' => 0, 'owner_id' => 0, 'deal_id' => null,
        'expected_monthly_volume' => (float)($b['volume'] ?? 0),
        'products' => $b['products'] ?? '', 'category' => $b['category'] ?? '',
        'business_type' => $b['business_type'] ?? '',
    ];
    // deal amount: engine reads it from deals by deal_id; for preview pass it via a temp shim
    $calc = incentive_calculate_preview($db, $merchant, (float)($b['deal_amount'] ?? 0));
    ok($calc);
}

/* ---- PLANS ---- */
if (method() === 'GET') {
    $plans = $db->query("SELECT * FROM incentive_plans ORDER BY id")->fetchAll();
    foreach ($plans as &$p) {
        $r = $db->prepare("SELECT * FROM incentive_rules WHERE plan_id=? ORDER BY priority, id");
        $r->execute([$p['id']]);
        $p['rules'] = $r->fetchAll();
    }
    ok($plans);
}

if (method() === 'POST' && isset($_GET['plan'])) {
    $b = body();
    if (empty($b['name'])) fail('name required');
    $db->prepare("INSERT INTO incentive_plans (name,description,effective_from,effective_to,stack_rules,is_active)
                  VALUES (?,?,?,?,?,?)")
       ->execute([$b['name'], $b['description'] ?? null, $b['effective_from'] ?: null, $b['effective_to'] ?: null,
                  isset($b['stack_rules']) ? (int)$b['stack_rules'] : 1, isset($b['is_active']) ? (int)$b['is_active'] : 1]);
    ok(['id' => (int)$db->lastInsertId()]);
}
if (method() === 'PATCH' && isset($_GET['plan'])) {
    patch_row($db, 'incentive_plans', (int)$_GET['plan'], $PLAN_FIELDS, body()); ok(['id' => (int)$_GET['plan']]);
}
if (method() === 'DELETE' && isset($_GET['plan'])) {
    $id = (int)$_GET['plan'];
    $db->prepare("DELETE FROM incentive_rules WHERE plan_id=?")->execute([$id]);
    $db->prepare("DELETE FROM incentive_plans WHERE id=?")->execute([$id]);
    ok(['deleted' => $id]);
}

/* ---- RULES ---- */
if (method() === 'POST' && isset($_GET['rule'])) {
    $b = body();
    if (empty($b['plan_id']) || empty($b['rule_type'])) fail('plan_id and rule_type required');
    $cols = []; $ph = []; $args = [];
    foreach (['plan_id','rule_type','label','product','category','business_type','applies_per',
              'flat_amount','percent_rate','slab_min','slab_max','slab_amount','priority','is_active'] as $f) {
        if (array_key_exists($f, $b)) { $cols[] = "`$f`"; $ph[] = '?'; $args[] = $b[$f] === '' ? null : $b[$f]; }
    }
    $db->prepare("INSERT INTO incentive_rules (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")")->execute($args);
    ok(['id' => (int)$db->lastInsertId()]);
}
if (method() === 'PATCH' && isset($_GET['rule'])) {
    patch_row($db, 'incentive_rules', (int)$_GET['rule'], $RULE_FIELDS, body()); ok(['id' => (int)$_GET['rule']]);
}
if (method() === 'DELETE' && isset($_GET['rule'])) {
    $db->prepare("DELETE FROM incentive_rules WHERE id=?")->execute([(int)$_GET['rule']]);
    ok(['deleted' => (int)$_GET['rule']]);
}

fail('Unsupported', 405);

function patch_row(PDO $db, string $table, int $id, array $allowed, array $b): void {
    $set = []; $args = [];
    foreach ($allowed as $f) if (array_key_exists($f, $b)) { $set[] = "`$f`=?"; $args[] = $b[$f] === '' ? null : $b[$f]; }
    if (!$set) return;
    $args[] = $id;
    $db->prepare("UPDATE `$table` SET " . implode(',', $set) . " WHERE id=?")->execute($args);
}
