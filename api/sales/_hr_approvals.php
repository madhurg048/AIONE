<?php
/**
 * HR maker-checker — an HR Head signs off on what their team changes.
 *
 * PayG's HR department owns people, performance and governance company-wide, so all three
 * HR roles carry the same *capabilities* (see _roles.php). They are not equal in *authority*:
 * when an HR Manager or HR Executive makes a structural change — adding a person, changing
 * someone's role or reporting line, editing a global KPA, altering module access — the change
 * is captured here and applied only once the HR Head approves it. The HR Head acts directly,
 * and the Admin is above the workflow entirely.
 *
 * Routine day-to-day work is deliberately NOT gated: approving a timesheet is an HR
 * Executive's job, and making the HR Head counter-sign every one of them would stall the
 * department rather than govern it.
 *
 * How it stays honest: a submitted change is validated first, then stored as the exact
 * normalised payload the mutation function consumes. Approving it replays that payload
 * through the same people_*() function a direct edit would have called — so an approved
 * change and an immediate change cannot drift apart, because they are the same code.
 */

require_once __DIR__ . '/_people.php';

/**
 * change_type => [human label, applier].
 * The applier receives the stored payload and performs the write.
 */
function hr_change_appliers(): array
{
    return [
        'person.create'     => ['Add person',            fn(PDO $db, array $p) => people_create($db, $p)],
        'person.update'     => ['Edit person',           fn(PDO $db, array $p) => people_update($db, $p)],
        'person.deactivate' => ['Deactivate login',      fn(PDO $db, array $p) => people_deactivate($db, $p)],
        'org.manager'       => ['Change reporting line', fn(PDO $db, array $p) => people_set_manager($db, $p)],
        'org.role'          => ['Change role',           fn(PDO $db, array $p) => people_set_role($db, $p)],
        'kpa.create'        => ['Add KPA / KRA',         fn(PDO $db, array $p) => hr_apply_kpa_create($db, $p)],
        'kpa.update'        => ['Edit KPA / KRA',        fn(PDO $db, array $p) => hr_apply_kpa_update($db, $p)],
        'kpa.delete'        => ['Delete KPA / KRA',      fn(PDO $db, array $p) => hr_apply_kpa_delete($db, $p)],
        'rbac.set'          => ['Change module access',  fn(PDO $db, array $p) => hr_apply_rbac_set($db, $p)],
    ];
}

function hr_change_label(string $type): string
{
    return hr_change_appliers()[$type][0] ?? $type;
}

/** Run a change for real. Shared by the direct path and the approve path. */
function hr_apply_change(PDO $db, string $type, array $payload): array
{
    $a = hr_change_appliers()[$type] ?? null;
    if (!$a) fail("Unknown change type: $type", 400);
    return ($a[1])($db, $payload);
}

/** Park a change for the HR Head. Returns the new request id. */
function hr_queue_change(PDO $db, string $type, array $payload, string $summary, ?string $target): int
{
    $db->prepare("INSERT INTO hr_change_requests
                    (change_type, summary, target_label, payload, status, requested_by, requested_by_role)
                  VALUES (?,?,?,?,'pending',?,?)")
       ->execute([$type, $summary, $target, json_encode($payload), current_employee_id(), current_role()]);
    return (int)$db->lastInsertId();
}

/**
 * The single entry point every HR-governed write goes through.
 *
 * Applies the change immediately for an HR Head / Admin; for an HR Manager or Executive it
 * queues the change and RESPONDS (ok() exits) — the caller never has to know which happened.
 * Validate before calling: whatever payload arrives here is what eventually gets executed.
 */
function hr_submit(PDO $db, string $type, array $payload, string $summary, ?string $target = null): array
{
    if (!payg_role_needs_hr_approval(current_role())) {
        return hr_apply_change($db, $type, $payload);
    }
    $id = hr_queue_change($db, $type, $payload, $summary, $target);
    ok(
        ['request_id' => $id, 'pending' => true, 'summary' => $summary],
        ['message' => 'Sent to the HR Head for approval — it takes effect once approved.']
    );
    return [];   // unreachable: ok() exits
}

/** Pending requests waiting on the HR Head. */
function hr_pending_requests(PDO $db, int $limit = 50): array
{
    $s = $db->prepare("
        SELECT r.*, e.full_name AS requested_by_name
        FROM hr_change_requests r
        LEFT JOIN employees e ON e.id = r.requested_by
        WHERE r.status='pending'
        ORDER BY r.requested_at ASC
        LIMIT $limit");
    $s->execute();
    return array_map(fn($r) => $r + ['type_label' => hr_change_label($r['change_type'])], $s->fetchAll(PDO::FETCH_ASSOC));
}

/** Recently decided requests — the audit trail HR shows when asked "who changed this?". */
function hr_decided_requests(PDO $db, int $limit = 25): array
{
    $s = $db->prepare("
        SELECT r.*, e.full_name AS requested_by_name, d.full_name AS decided_by_name
        FROM hr_change_requests r
        LEFT JOIN employees e ON e.id = r.requested_by
        LEFT JOIN employees d ON d.id = r.decided_by
        WHERE r.status <> 'pending'
        ORDER BY r.decided_at DESC
        LIMIT $limit");
    $s->execute();
    return array_map(fn($r) => $r + ['type_label' => hr_change_label($r['change_type'])], $s->fetchAll(PDO::FETCH_ASSOC));
}

function hr_pending_count(PDO $db): int
{
    try { return (int)$db->query("SELECT COUNT(*) FROM hr_change_requests WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/**
 * Approve (and execute) or reject a queued change.
 * Only the HR Head or the Admin may decide — never the person who requested it.
 */
function hr_decide(PDO $db, int $id, string $decision, ?string $note): array
{
    if (!in_array(current_role(), ['hr_head', 'admin'], true)) {
        fail('Only the HR Head can approve or reject HR change requests', 403);
    }
    $s = $db->prepare("SELECT * FROM hr_change_requests WHERE id=?");
    $s->execute([$id]);
    $req = $s->fetch(PDO::FETCH_ASSOC);
    if (!$req) fail('Request not found', 404);
    if ($req['status'] !== 'pending') fail('That request has already been ' . $req['status'], 409);

    if ($decision === 'reject') {
        $db->prepare("UPDATE hr_change_requests SET status='rejected', decided_by=?, decided_at=NOW(), decision_note=? WHERE id=?")
           ->execute([current_employee_id(), $note, $id]);
        return ['id' => $id, 'status' => 'rejected'];
    }

    // Apply FIRST, mark approved second. If the world moved on since it was queued (the
    // username got taken, the person was deleted), the applier fails, nothing is marked
    // approved, and the request stays pending for the HR Head to reject or have redone.
    $payload = json_decode((string)$req['payload'], true) ?: [];
    $result  = hr_apply_change($db, $req['change_type'], $payload);

    $db->prepare("UPDATE hr_change_requests SET status='approved', decided_by=?, decided_at=NOW(), decision_note=? WHERE id=?")
       ->execute([current_employee_id(), $note, $id]);

    return ['id' => $id, 'status' => 'approved', 'result' => $result];
}

/* ---------------- appliers for the non-people change types ---------------- */

function hr_apply_kpa_create(PDO $db, array $p): array
{
    $db->prepare("INSERT INTO kpa_goals
        (scope, org_group, owner_employee_id, created_by, created_by_role, title, kra, weight, target_value, unit, period, is_active)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,1)")
       ->execute([$p['scope'], $p['org_group'], $p['owner_employee_id'], $p['created_by'], $p['created_by_role'],
                  $p['title'], $p['kra'], $p['weight'], $p['target_value'], $p['unit'], $p['period']]);
    return ['id' => (int)$db->lastInsertId()];
}

function hr_apply_kpa_update(PDO $db, array $p): array
{
    $id = (int)($p['id'] ?? 0);
    $fields = $p['fields'] ?? [];
    if (!$id || !$fields) fail('Nothing to update');
    $set = []; $args = [];
    foreach ($fields as $col => $val) {
        if (!in_array($col, ['title', 'kra', 'target_value', 'period', 'weight', 'unit', 'is_active'], true)) continue;
        $set[] = "$col=?"; $args[] = $val;
    }
    if (!$set) fail('Nothing to update');
    $args[] = $id;
    $db->prepare("UPDATE kpa_goals SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    return ['id' => $id];
}

function hr_apply_kpa_delete(PDO $db, array $p): array
{
    $id = (int)($p['id'] ?? 0);
    if (!$id) fail('Missing id');
    $db->prepare("DELETE FROM kpa_goals WHERE id=?")->execute([$id]);
    return ['deleted' => $id];
}

function hr_apply_rbac_set(PDO $db, array $p): array
{
    $db->prepare("INSERT INTO role_access (role, module_key, can_access) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE can_access=VALUES(can_access)")
       ->execute([$p['role'], $p['module_key'], (int)$p['can_access']]);
    return ['role' => $p['role'], 'module' => $p['module_key'], 'can_access' => (int)$p['can_access']];
}
