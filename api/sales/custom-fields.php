<?php
/**
 * Custom fields — metadata-driven, no schema change (Zoho-style).
 * Definitions:
 *   GET  custom-fields.php?entity=lead              -> field defs[]
 *   POST custom-fields.php  {entity,field_key,label,field_type,options,is_required}
 *   PATCH custom-fields.php?id=5                     -> update def
 *   DELETE custom-fields.php?id=5
 * Values (per record):
 *   GET  custom-fields.php?entity=lead&entity_id=9&values=1  -> {field_key: value}
 *   POST custom-fields.php?values=1 {entity,entity_id,values:{field_key:value}}
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
$db = db();

/* -------- VALUES -------- (any logged-in user may read/save values on a record they can see;
   EMPLOYEE values are HR data, so they take the same 'people' capability as users.php) */
if (isset($_GET['values'])) {
    if (($_GET['entity'] ?? (body()['entity'] ?? '')) === 'employee') {
        require_cap('people', 'employee custom fields');
    }
    if (method() === 'GET') {
        $entity = $_GET['entity'] ?? ''; $eid = (int)($_GET['entity_id'] ?? 0);
        $s = $db->prepare("SELECT f.field_key, v.value
                           FROM custom_field_values v JOIN custom_fields f ON f.id=v.field_id
                           WHERE v.entity=? AND v.entity_id=?");
        $s->execute([$entity, $eid]);
        $map = [];
        foreach ($s->fetchAll() as $r) $map[$r['field_key']] = $r['value'];
        ok($map);
    }
    if (method() === 'POST') {
        $b = body();
        $entity = $b['entity'] ?? ''; $eid = (int)($b['entity_id'] ?? 0);
        $vals = $b['values'] ?? [];
        if (!$entity || !$eid) fail('entity and entity_id required');
        $defs = $db->prepare("SELECT id, field_key FROM custom_fields WHERE entity=?");
        $defs->execute([$entity]);
        $byKey = [];
        foreach ($defs->fetchAll() as $d) $byKey[$d['field_key']] = (int)$d['id'];
        $up = $db->prepare("INSERT INTO custom_field_values (field_id,entity,entity_id,value)
                            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
        $n = 0;
        foreach ($vals as $key => $val) {
            if (!isset($byKey[$key])) continue;
            $up->execute([$byKey[$key], $entity, $eid, is_array($val) ? json_encode($val) : $val]);
            $n++;
        }
        ok(['saved' => $n]);
    }
    fail('Unsupported', 405);
}

/* -------- DEFINITIONS -------- (reading the field list is fine for anyone; defining/editing/
   deleting fields changes forms for the whole team, so that's manager/admin only) */
if (method() === 'GET') {
    $entity = $_GET['entity'] ?? '';
    $s = $db->prepare("SELECT * FROM custom_fields WHERE entity=? AND is_active=1 ORDER BY sort_order, id");
    $s->execute([$entity]);
    $defs = $s->fetchAll();
    foreach ($defs as &$d) $d['options'] = $d['options'] ? json_decode($d['options'], true) : null;
    ok($defs);
}

if (!can_manage_all_records()) fail('Not authorized', 403);

if (method() === 'POST') {
    $b = body();
    foreach (['entity','field_key','label','field_type'] as $r) if (empty($b[$r])) fail("$r required");
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $b['field_key'])) fail('field_key must be snake_case');
    $opts = isset($b['options']) ? (is_array($b['options']) ? json_encode($b['options']) : $b['options']) : null;
    try {
        $db->prepare("INSERT INTO custom_fields (entity,field_key,label,field_type,options,is_required,sort_order)
                      VALUES (?,?,?,?,?,?,?)")
           ->execute([$b['entity'], $b['field_key'], $b['label'], $b['field_type'], $opts,
                      (int)($b['is_required'] ?? 0), (int)($b['sort_order'] ?? 0)]);
    } catch (Throwable $e) {
        fail('Duplicate field_key for this entity', 409);
    }
    ok(['id' => (int)$db->lastInsertId()]);
}

if (method() === 'PATCH' || method() === 'PUT') {
    $id = (int)($_GET['id'] ?? 0); if (!$id) fail('id required');
    $b = body();
    $allowed = ['label','field_type','options','is_required','sort_order','is_active'];
    $set = []; $args = [];
    foreach ($allowed as $f) {
        if (!array_key_exists($f, $b)) continue;
        $v = ($f === 'options' && is_array($b[$f])) ? json_encode($b[$f]) : $b[$f];
        $set[] = "$f=?"; $args[] = $v;
    }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE custom_fields SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    ok(['id' => $id]);
}

if (method() === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0); if (!$id) fail('id required');
    $db->prepare("UPDATE custom_fields SET is_active=0 WHERE id=?")->execute([$id]);
    ok(['deleted' => $id]);
}

fail('Unsupported method', 405);
