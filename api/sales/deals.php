<?php
/**
 * Deals API.
 *   GET  deals.php?pipeline=1   -> stages[] + deals[] grouped for the Kanban board
 *   GET  deals.php?id=5         -> single deal
 *   POST deals.php              -> create
 *   PATCH deals.php?id=5         -> update fields
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
require_module('pipeline');
$db = db();

if (method() === 'GET' && isset($_GET['id'])) {
    $s = $db->prepare("SELECT d.*, a.account_name, c.first_name, c.last_name, e.full_name AS owner_name,
                              st.name AS stage_name, st.stage_type
                       FROM deals d
                       LEFT JOIN accounts a ON a.id=d.account_id
                       LEFT JOIN contacts c ON c.id=d.contact_id
                       LEFT JOIN employees e ON e.id=d.owner_id
                       LEFT JOIN deal_stages st ON st.id=d.stage_id
                       WHERE d.id=?");
    $s->execute([(int)$_GET['id']]);
    $deal = $s->fetch();
    if (!$deal) fail('Deal not found', 404);
    assert_can_see($deal['owner_id']);
    ok($deal);
}

if (method() === 'GET') {
    $pid = (int)($_GET['pipeline'] ?? 0)
        ?: (int)$db->query("SELECT id FROM pipelines WHERE is_default=1 LIMIT 1")->fetchColumn();

    $stages = $db->prepare("SELECT * FROM deal_stages WHERE pipeline_id=? ORDER BY sort_order ASC");
    $stages->execute([$pid]);
    $stages = $stages->fetchAll();

    $dargs = [$pid];
    $dscope = owner_scope('d.owner_id', $dargs);   // reps only see their own deals
    $deals = $db->prepare("SELECT d.id,d.deal_name,d.amount,d.currency,d.probability,d.stage_id,
                                  d.expected_close_date,d.owner_id, a.account_name, e.full_name AS owner_name,
                                  COALESCE(c.mobile,c.phone) AS contact_phone,
                                  TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) AS contact_name
                           FROM deals d
                           LEFT JOIN accounts a ON a.id=d.account_id
                           LEFT JOIN contacts c ON c.id=d.contact_id
                           LEFT JOIN employees e ON e.id=d.owner_id
                           WHERE d.pipeline_id=? AND $dscope ORDER BY d.updated_at DESC");
    $deals->execute($dargs);
    ok(['pipeline_id' => $pid, 'stages' => $stages, 'deals' => $deals->fetchAll()]);
}

if (method() === 'POST') {
    $b = body();
    if (empty($b['deal_name'])) fail('deal_name required');
    $ownerId = (int)($b['owner_id'] ?? current_employee_id());
    assert_can_assign_owner($ownerId);
    $pid = (int)($b['pipeline_id'] ?? 0)
        ?: (int)$db->query("SELECT id FROM pipelines WHERE is_default=1 LIMIT 1")->fetchColumn();
    $st = $db->prepare("SELECT id,probability FROM deal_stages WHERE pipeline_id=? ORDER BY sort_order ASC LIMIT 1");
    $st->execute([$pid]); $stage = $st->fetch();
    if (!$stage) fail('No stages configured for the selected pipeline', 400);
    $s = $db->prepare("INSERT INTO deals
        (deal_name,account_id,contact_id,pipeline_id,stage_id,amount,probability,expected_close_date,owner_id,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $s->execute([
        $b['deal_name'], $b['account_id'] ?? null, $b['contact_id'] ?? null, $pid, $stage['id'],
        (float)($b['amount'] ?? 0), (int)$stage['probability'], $b['expected_close_date'] ?? null,
        $ownerId ?: null, current_employee_id() ?: null
    ]);
    ok(['id' => (int)$db->lastInsertId()]);
}

if (method() === 'PATCH' || method() === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');
    $own = $db->prepare("SELECT owner_id FROM deals WHERE id=?"); $own->execute([$id]);
    $cur = $own->fetchColumn();
    if ($cur === false) fail('Deal not found', 404);
    assert_can_see($cur);   // reps can only edit their own deals
    $b = body();
    $current = $db->prepare("SELECT id, owner_id FROM deals WHERE id=?");
    $current->execute([$id]);
    $deal = $current->fetch();
    if (!$deal) fail('Deal not found', 404);
    assert_can_see($deal['owner_id']);
    if (array_key_exists('owner_id', $b)) {
        assert_can_assign_owner((int)$b['owner_id']);
    }
    $allowed = ['deal_name','account_id','contact_id','amount','probability',
                'expected_close_date','owner_id','description','lost_reason'];
    $set=[]; $args=[];
    foreach ($allowed as $f) if (array_key_exists($f,$b)) { $set[]="$f=?"; $args[]=$b[$f]; }
    if (!$set) fail('Nothing to update');
    $args[]=$id;
    $db->prepare("UPDATE deals SET ".implode(',',$set)." WHERE id=?")->execute($args);
    ok(['id'=>$id]);
}

fail('Unsupported method', 405);
