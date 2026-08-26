<?php
/**
 * Governance traceability map: the code catalogue, the links between codes,
 * and the version history behind both.
 *
 *   GET  governance-map.php                  -> {catalog[], links[], counts}
 *   GET  governance-map.php?history=1&type=policy&id=7   -> revisions of one object
 *   GET  governance-map.php?changelog=1[&limit=]         -> recent changes, everything
 *   POST governance-map.php        {from_type,from_id,to_type,to_id,relation,note}
 *   DELETE governance-map.php?id=N -> remove a link (recorded as 'unlinked')
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_governance.php';
require_login();
require_module('govmap');
$db = db();

$RELATIONS = ['delivers', 'measured_by', 'governed_by', 'escalates_via', 'implements', 'depends_on', 'relates_to'];

if (method() === 'GET' && isset($_GET['history'])) {
    $type = (string)($_GET['type'] ?? '');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id || !isset((gov_types() + ['link' => 1])[$type])) fail('type and id required');
    $s = $db->prepare("SELECT r.id, r.rev_ref, r.version, r.action, r.summary, r.created_at,
                              (r.snapshot IS NOT NULL) AS restorable, e.full_name AS changed_by_name
                         FROM governance_revisions r LEFT JOIN employees e ON e.id = r.changed_by
                        WHERE r.obj_type=? AND r.obj_id=? ORDER BY r.id DESC LIMIT 50");
    $s->execute([$type, $id]);
    ok(['code' => gov_code($db, $type, $id), 'history' => $s->fetchAll(),
        'approval' => gov_approval($db, $type, $id), 'can_edit' => can_manage_all_records()]);
}

if (method() === 'GET' && isset($_GET['changelog'])) {
    $limit = max(1, min(300, (int)($_GET['limit'] ?? 100)));
    ok($db->query("SELECT r.rev_ref, r.obj_type, r.obj_code, r.version, r.action, r.summary,
                          r.created_at, e.full_name AS changed_by_name
                     FROM governance_revisions r LEFT JOIN employees e ON e.id = r.changed_by
                    ORDER BY r.id DESC LIMIT $limit")->fetchAll());
}

/* ---- read one record's editable fields (for the map's inline editor) ---- */
if (method() === 'GET' && isset($_GET['record'])) {
    $type = (string)($_GET['type'] ?? ''); $id = (int)($_GET['id'] ?? 0);
    $t = gov_types()[$type] ?? null;
    if (!$t || !$id) fail('type and id required');
    $s = $db->prepare("SELECT * FROM {$t['table']} WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Not found', 404);
    ok(['type' => $type, 'id' => $id, 'code' => gov_code($db, $type, $id), 'row' => $row,
        'fields' => gov_editable($type), 'can_edit' => can_manage_all_records()]);
}

if (method() === 'GET') {
    $catalog = gov_catalog($db);
    $links = gov_links($db);
    $counts = [];
    foreach ($catalog as $c) $counts[$c['type']] = ($counts[$c['type']] ?? 0) + 1;
    ok([
        'catalog'   => $catalog,
        'links'     => $links,
        'counts'    => $counts,
        'relations' => $RELATIONS,
        'can_edit'  => can_manage_all_records(),
        // SLA -> escalation events already carry both codes; surface the live pairs
        'sla_escalations' => $db->query("
                SELECT te.esl_ref, te.level, te.status, te.created_at,
                       p.sla_ref, p.name AS sla_name,
                       m.esc_ref, COALESCE(eb.full_name, IF(te.to_board=1,'THE BOARD','—')) AS escalated_to,
                       COALESCE(t.title, ct.subject) AS subject
                  FROM task_escalations te
                  LEFT JOIN sla_policies p ON p.id = te.sla_policy_id
                  LEFT JOIN escalation_matrix m ON m.id = te.matrix_id
                  LEFT JOIN employees eb ON eb.id = te.to_employee_id
                  LEFT JOIN tasks t ON te.entity='task' AND t.id = te.task_id
                  LEFT JOIN crm_tickets ct ON te.entity='ticket' AND ct.id = te.task_id
                 ORDER BY te.id DESC LIMIT 40")->fetchAll(),
    ]);
}

/* ---- reviewer / approver workflow + revert (phase65) ---- */
if (method() === 'POST' && isset($_GET['workflow'])) {
    $b = body();
    $type = (string)($b['type'] ?? ''); $id = (int)($b['id'] ?? 0);
    $action = (string)($b['action'] ?? '');
    if (!isset(gov_types()[$type]) || !$id) fail('type and id required');
    [$okd, $msg] = gov_transition($db, $type, $id, $action, $b['reason'] ?? null);
    if (!$okd) fail($msg, 403);
    ok(['message' => $msg, 'approval' => gov_approval($db, $type, $id)]);
}

if (method() === 'POST' && isset($_GET['revert'])) {
    if (!can_manage_all_records()) fail('Only managers and admins can restore a previous version', 403);
    $b = body();
    $type = (string)($b['type'] ?? ''); $id = (int)($b['id'] ?? 0); $rev = (int)($b['revision_id'] ?? 0);
    if (!isset(gov_types()[$type]) || !$id || !$rev) fail('type, id and revision_id required');
    [$okd, $msg] = gov_revert($db, $type, $id, $rev);
    if (!$okd) fail($msg);
    ok(['message' => $msg]);
}

/* ---- edit a record straight from the map ---- */
if (method() === 'PATCH' && isset($_GET['record'])) {
    if (!can_manage_all_records()) fail('Only managers and admins can edit governance records', 403);
    $b = body();
    $type = (string)($b['type'] ?? ''); $id = (int)($b['id'] ?? 0);
    $t = gov_types()[$type] ?? null;
    if (!$t || !$id) fail('type and id required');
    $allowed = array_column(gov_editable($type), 'key');
    $set = []; $args = [];
    foreach ((array)($b['values'] ?? []) as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $set[] = "`$k`=?"; $args[] = ($v === '' ? null : $v);
    }
    if (!$set) fail('Nothing to change');
    $args[] = $id;
    $db->prepare("UPDATE {$t['table']} SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    $code = gov_code($db, $type, $id);
    gov_revise($db, $type, $id, 'updated', "$code edited from the governance map: "
        . implode(', ', array_map(fn($x) => trim($x, '`='), array_map(fn($x) => explode('=', $x)[0], $set))));
    gov_touch($db, $type, $id);            // an edit sends it back for review + approval
    ok(['code' => $code, 'message' => "$code updated — it is back in draft for review and approval"]);
}

if (method() === 'POST') {
    if (!can_manage_all_records()) fail('Only managers and admins can link governance records', 403);
    $b = body();
    $ft = (string)($b['from_type'] ?? ''); $fi = (int)($b['from_id'] ?? 0);
    $tt = (string)($b['to_type'] ?? '');   $ti = (int)($b['to_id'] ?? 0);
    $rel = in_array($b['relation'] ?? '', $RELATIONS, true) ? $b['relation'] : 'relates_to';
    $types = gov_types();
    if (!isset($types[$ft]) || !isset($types[$tt]) || !$fi || !$ti) fail('Pick both records to link');
    if ($ft === $tt && $fi === $ti) fail('A record cannot be linked to itself');
    $fc = gov_code($db, $ft, $fi); $tc = gov_code($db, $tt, $ti);
    if (!$fc || !$tc) fail('One of those records no longer exists', 404);
    try {
        $db->prepare("INSERT INTO governance_links (from_type, from_id, to_type, to_id, relation, note, created_by)
                      VALUES (?,?,?,?,?,?,?)")
           ->execute([$ft, $fi, $tt, $ti, $rel, trim((string)($b['note'] ?? '')) ?: null, current_employee_id() ?: null]);
    } catch (Throwable $e) { fail('Those two are already linked that way'); }
    $id = (int)$db->lastInsertId();
    $summary = "$fc {$rel} $tc";
    gov_revise($db, 'link', $id, 'linked', $summary);
    gov_revise($db, $ft, $fi, 'linked', "linked to $tc ($rel)");
    gov_revise($db, $tt, $ti, 'linked', "linked from $fc ($rel)");
    ok(['id' => $id, 'summary' => $summary]);
}

if (method() === 'DELETE') {
    if (!can_manage_all_records()) fail('Only managers and admins can remove links', 403);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');
    $s = $db->prepare("SELECT * FROM governance_links WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Link not found', 404);
    $fc = gov_code($db, $row['from_type'], (int)$row['from_id']);
    $tc = gov_code($db, $row['to_type'], (int)$row['to_id']);
    gov_revise($db, 'link', $id, 'unlinked', "$fc {$row['relation']} $tc removed", $row);
    $db->prepare("DELETE FROM governance_links WHERE id=?")->execute([$id]);
    ok(['deleted' => 1]);
}

fail('Unsupported request', 405);
