<?php
/**
 * Merchants (PIMS) API.
 *   GET  merchants.php?metrics=1
 *   GET  merchants.php?stage=activated&q=..&page=1
 *   GET  merchants.php?id=5   -> merchant + documents + incentives
 *   POST merchants.php        -> create merchant manually
 *   PATCH merchants.php?id=5   -> update fields
 *   POST merchants.php?activate=5  -> activate + book incentives (idempotent)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_incentive_engine.php';
require_login();
require_module('merchants');
$db = db();

/* ---- ACTIVATE (release incentive) ---- */
if (method() === 'POST' && isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];
    $s = $db->prepare("SELECT * FROM merchants WHERE id=?");
    $s->execute([$id]); $m = $s->fetch();
    if (!$m) fail('Merchant not found', 404);
    assert_can_see($m['owner_id']);   // reps can only activate their own merchants
    if ($m['kyc_status'] !== 'verified') fail('KYC must be verified before activation');

    try {
        $db->beginTransaction();
        $db->prepare("UPDATE merchants SET onboarding_stage='activated', activated_at=NOW(),
                      go_live_at=COALESCE(go_live_at,NOW()) WHERE id=?")->execute([$id]);
        $m['onboarding_stage'] = 'activated';
        $booked = incentive_book($db, $m);
        $db->commit();
        ok(['merchant_id' => $id, 'incentive_booked' => $booked],
           ['message' => $booked > 0 ? 'Activated — ₹' . number_format($booked) . ' incentive accrued to agent'
                                     : 'Activated — incentive is assessed on the quarterly scorecard']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        fail('Activation failed: ' . $e->getMessage(), 500);
    }
}

/* ---- METRICS ---- */
if (method() === 'GET' && isset($_GET['metrics'])) {
    $sc = owner_scope_inline('owner_id');   // reps only see their own merchants
    $byStage = $db->query("SELECT onboarding_stage AS stage, COUNT(*) c FROM merchants WHERE $sc GROUP BY onboarding_stage")->fetchAll();
    $ok = ['total'=>(int)$db->query("SELECT COUNT(*) FROM merchants WHERE $sc")->fetchColumn(),
           'activated'=>(int)$db->query("SELECT COUNT(*) FROM merchants WHERE onboarding_stage='activated' AND $sc")->fetchColumn(),
           'kyc_pending'=>(int)$db->query("SELECT COUNT(*) FROM merchants WHERE kyc_status IN('pending','partial') AND $sc")->fetchColumn(),
           'volume'=>(float)$db->query("SELECT COALESCE(SUM(expected_monthly_volume),0) FROM merchants WHERE onboarding_stage='activated' AND $sc")->fetchColumn(),
           'by_stage'=>$byStage];
    ok($ok);
}

/* ---- SINGLE ---- */
if (method() === 'GET' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $s = $db->prepare("SELECT m.*, e.full_name AS owner_name, a.account_name
                       FROM merchants m LEFT JOIN employees e ON e.id=m.owner_id
                       LEFT JOIN accounts a ON a.id=m.account_id WHERE m.id=?");
    $s->execute([$id]); $m = $s->fetch();
    if (!$m) fail('Merchant not found', 404);
    assert_can_see($m['owner_id']);
    $d = $db->prepare("SELECT * FROM merchant_documents WHERE merchant_id=?"); $d->execute([$id]);
    $m['documents'] = $d->fetchAll();
    $i = $db->prepare("SELECT * FROM incentive_ledger WHERE merchant_id=?"); $i->execute([$id]);
    $m['incentives'] = $i->fetchAll();
    ok($m);
}

/* ---- LIST ---- */
if (method() === 'GET') {
    $where=[]; $args=[];
    $where[] = owner_scope('m.owner_id', $args);   // row-level visibility
    if (!empty($_GET['stage'])) { $where[]='m.onboarding_stage=?'; $args[]=$_GET['stage']; }
    if (!empty($_GET['owner'])) { $where[]='m.owner_id=?'; $args[]=(int)$_GET['owner']; }
    if (!empty($_GET['q'])) {
        $where[]='(m.legal_name LIKE ? OR m.merchant_code LIKE ? OR m.email LIKE ?)';
        $l='%'.$_GET['q'].'%'; array_push($args,$l,$l,$l);
    }
    $w = $where ? 'WHERE '.implode(' AND ',$where) : '';
    $per=max(1,min(100,(int)($_GET['per']??20))); $page=max(1,(int)($_GET['page']??1)); $off=($page-1)*$per;
    $cnt=$db->prepare("SELECT COUNT(*) FROM merchants m $w"); $cnt->execute($args); $total=(int)$cnt->fetchColumn();
    $s=$db->prepare("SELECT m.*, e.full_name AS owner_name FROM merchants m
                     LEFT JOIN employees e ON e.id=m.owner_id $w ORDER BY m.created_at DESC LIMIT $per OFFSET $off");
    $s->execute($args);
    ok($s->fetchAll(), ['total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$per)]);
}

/* ---- CREATE ---- */
if (method() === 'POST') {
    $b = body();
    if (empty($b['legal_name'])) fail('legal_name required');
    $ownerId = (int)($b['owner_id'] ?? current_employee_id());
    assert_can_assign_owner($ownerId);
    $code = $b['merchant_code'] ?? ('PAYG'.date('ym').str_pad((string)random_int(1,99999),5,'0',STR_PAD_LEFT));
    $s=$db->prepare("INSERT INTO merchants
        (merchant_code,legal_name,display_name,business_type,category,email,phone,website,
         city,state,pincode,owner_id,mdr_percent,expected_monthly_volume,products,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $s->execute([$code,$b['legal_name'],$b['display_name']??null,$b['business_type']??'proprietorship',
        $b['category']??null,$b['email']??null,$b['phone']??null,$b['website']??null,$b['city']??null,
        $b['state']??null,$b['pincode']??null,$ownerId?:null,
        $b['mdr_percent']??null,$b['expected_monthly_volume']??null,$b['products']??null,current_employee_id()?:null]);
    ok(['id'=>(int)$db->lastInsertId(),'merchant_code'=>$code]);
}

/* ---- UPDATE ---- */
if (method() === 'PATCH' || method() === 'PUT') {
    $id=(int)($_GET['id']??0); if(!$id) fail('id required');
    $own=$db->prepare("SELECT owner_id FROM merchants WHERE id=?"); $own->execute([$id]);
    $cur=$own->fetchColumn();
    if ($cur===false) fail('Merchant not found', 404);
    assert_can_see($cur);   // reps can only edit their own merchants
    $b=body();
    $current = $db->prepare("SELECT id, owner_id FROM merchants WHERE id=?");
    $current->execute([$id]);
    $merchant = $current->fetch();
    if (!$merchant) fail('Merchant not found', 404);
    assert_can_see($merchant['owner_id']);
    if (array_key_exists('owner_id', $b)) {
        assert_can_assign_owner((int)$b['owner_id']);
    }
    $allowed=['legal_name','display_name','business_type','category','email','phone','website',
              'address','city','state','pincode','mdr_percent','expected_monthly_volume','products',
              'onboarding_stage','kyc_status','risk_rating','owner_id'];
    $set=[]; $args=[];
    foreach ($allowed as $f) if (array_key_exists($f,$b)) { $set[]="$f=?"; $args[]=$b[$f]; }
    if (!$set) fail('Nothing to update');
    $args[]=$id;
    $db->prepare("UPDATE merchants SET ".implode(',',$set)." WHERE id=?")->execute($args);
    ok(['id'=>$id]);
}

fail('Unsupported method', 405);
