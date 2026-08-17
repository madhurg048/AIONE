<?php
/**
 * Governance codes, links and version control (phase64).
 *
 * Every governance object carries a quotable CODE derived from its id:
 *   KPA-0001 / KRA-0001  kpa_goals        SLA-0001  sla_policies (TAT policy)
 *   ESC-0001             escalation_matrix ESL-0001 task_escalations (event)
 *   POL/CRT/LIC/CMP-0001 compliance_items  LNK-0001 governance_links
 *
 * Every create/edit/delete/link is snapshotted in governance_revisions and the
 * object's `version` is bumped, so "who changed POL-0007, when, from what?" is
 * always answerable. Recording is non-fatal by design: an audit write must
 * never break the business action it is recording.
 */


/**
 * Domain-style codes (phase71): a short tag plus a sequence inside that tag, so
 * a code reads SLA-COMP-01 rather than SLA-0007. Derive the tag from what the
 * record is about, then take the next free number in that domain.
 */
function gov_domain_for(string $text, ?string $dept = null): string
{
    $t = strtolower($text . ' ' . (string)$dept);
    foreach ([
        'COMP'  => ['complaint', 'ticket', 'grievance'],
        'RECON' => ['recon', 'match'],
        'SETT'  => ['settle', 'payout', 'disburse'],
        'KYC'   => ['kyc', 'onboard'],
        'AUDIT' => ['audit', 'review', 'legal', 'compliance'],
        'EXCEP' => ['exception', 'dispute', 'chargeback'],
        'SALES' => ['sales', 'lead', 'revenue'],
        'SVC'   => ['service', 'support'],
        'HR'    => ['hr', 'people', 'hiring', 'talent'],
        'IT'    => ['it ', 'platform', 'integration'],
    ] as $tag => $needles) {
        foreach ($needles as $n) if (str_contains($t, $n)) return $tag;
    }
    return 'GEN';
}

/** Assign code_domain + the next code_seq for a freshly inserted row. */
function gov_assign_code(PDO $db, string $table, int $id, string $domain): void
{
    try {
        $s = $db->prepare("SELECT COALESCE(MAX(code_seq), 0) + 1 FROM `$table` WHERE code_domain = ?");
        $s->execute([$domain]);
        $seq = (int)$s->fetchColumn() ?: 1;
        $db->prepare("UPDATE `$table` SET code_domain = ?, code_seq = ? WHERE id = ?")->execute([$domain, $seq, $id]);
    } catch (Throwable $e) { /* falls back to the sequential code */ }
}

/** Object types this module knows about, and where they live. */
function gov_types(): array
{
    return [
        'kpa'    => ['label' => 'KPA',              'table' => 'kpa_goals',         'code' => 'kpa_ref',   'title' => 'title'],
        'kra'    => ['label' => 'KRA',              'table' => 'kpa_goals',         'code' => 'kra_ref',   'title' => 'kra'],
        'kpi'    => ['label' => 'KPI',              'table' => 'kpa_goals',         'code' => 'kpi_ref',   'title' => 'target_value'],
        'sla'    => ['label' => 'SLA / TAT policy', 'table' => 'sla_policies',      'code' => 'sla_ref',   'title' => 'name'],
        'esc'    => ['label' => 'Escalation rule',  'table' => 'escalation_matrix', 'code' => 'esc_ref',   'title' => null],
        'policy' => ['label' => 'Policy / cert',    'table' => 'compliance_items',  'code' => 'policy_no', 'title' => 'title'],
    ];
}

/**
 * Which fields the governance map may edit in place, per type. Deliberately a
 * whitelist: identity, ownership and workflow columns are NOT editable here —
 * those belong to their own screens with their own rules.
 */
function gov_editable(string $type): array
{
    $F = fn($k, $l, $t = 'text', $opts = null) => ['key' => $k, 'label' => $l, 'type' => $t, 'options' => $opts];
    switch ($type) {
        case 'kpa': return [$F('title', 'KPA — the focus area'), $F('period', 'Period'),
                            $F('weight', 'Weight (%)', 'number')];
        case 'kra': return [$F('kra', 'KRA — the outcome to deliver', 'textarea')];
        case 'kpi': return [$F('target_value', 'KPI — the target'),
                            $F('unit', 'Unit', 'select', ['inr', 'pct', 'count', 'flag', 'text'])];
        case 'sla': return [$F('name', 'Policy name'), $F('tat_hours', 'TAT (hours)', 'number'),
                            $F('escalate_every_hours', 'Re-escalate every (hours)', 'number'),
                            $F('department', 'Department'), $F('sub_department', 'Sub-department'),
                            $F('priority', 'Priority', 'select', ['', 'urgent', 'high', 'medium', 'low'])];
        case 'esc': return [$F('level', 'Level', 'number'), $F('tat_hours', 'TAT at this level (hours)', 'number')];
        case 'policy': return [$F('title', 'Title'), $F('category', 'Category'), $F('authority', 'Authority'),
                               $F('reference_no', 'External reference no.'),
                               $F('valid_till', 'Valid till', 'date'), $F('reminder_days', 'Remind before (days)', 'number')];
    }
    return [];
}

/** The code for one object, e.g. gov_code($db,'policy',7) -> "POL-0007". */
function gov_code(PDO $db, string $type, int $id): ?string
{
    $t = gov_types()[$type] ?? null;
    if (!$t || !$id) return null;
    try {
        $s = $db->prepare("SELECT {$t['code']} FROM {$t['table']} WHERE id=?");
        $s->execute([$id]);
        return $s->fetchColumn() ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Record a revision and bump the object's version.
 * $action: created | updated | deleted | linked | unlinked
 */
function gov_revise(PDO $db, string $type, int $id, string $action, ?string $summary = null, ?array $snapshot = null): void
{
    try {
        $tables = gov_types() + ['link' => ['table' => 'governance_links', 'code' => 'link_ref']];
        $t = $tables[$type] ?? null;
        $code = null; $version = 1;
        if ($t) {
            // bump first so the stored version matches what the object now shows
            if ($action !== 'deleted') {
                try { $db->prepare("UPDATE {$t['table']} SET version = version + 1 WHERE id=?")->execute([$id]); }
                catch (Throwable $e) { /* table without version column */ }
            }
            $s = $db->prepare("SELECT {$t['code']} AS c, version FROM {$t['table']} WHERE id=?");
            $s->execute([$id]);
            if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $code = $r['c']; $version = (int)$r['version']; }
        }
        if ($snapshot === null && $t) {
            $s = $db->prepare("SELECT * FROM {$t['table']} WHERE id=?");
            $s->execute([$id]);
            $snapshot = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $db->prepare("INSERT INTO governance_revisions
                        (obj_type, obj_id, obj_code, version, action, summary, snapshot, changed_by)
                      VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$type, $id, $code, $version, $action, $summary ? mb_substr($summary, 0, 400) : null,
                      $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null,
                      function_exists('current_employee_id') ? (current_employee_id() ?: null) : null]);
    } catch (Throwable $e) {
        // auditing must never break the action being audited
    }
}

/** Revision history for one object (newest first). */
function gov_history(PDO $db, string $type, int $id, int $limit = 50): array
{
    $s = $db->prepare("SELECT r.rev_ref, r.version, r.action, r.summary, r.created_at, e.full_name AS changed_by_name
                         FROM governance_revisions r LEFT JOIN employees e ON e.id = r.changed_by
                        WHERE r.obj_type=? AND r.obj_id=? ORDER BY r.id DESC LIMIT " . (int)$limit);
    $s->execute([$type, $id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every coded object in one list — the "codes catalogue" the traceability
 * screen and its pickers render. Includes the PEOPLE attached to each object
 * (escalation rules name both sides; policies name their owner).
 */
function gov_catalog(PDO $db): array
{
    $out = [];
    $add = function (string $type, $rows) use (&$out) { foreach ($rows as $r) { $r['type'] = $type; $out[] = $r; } };

    $add('kpa', $db->query("SELECT k.id, k.kpa_ref AS code, k.title AS name, k.version,
                                   CONCAT(k.scope, COALESCE(CONCAT(' · ', k.org_group), '')) AS context,
                                   COALESCE(e.full_name,'') AS person, k.is_active
                              FROM kpa_goals k LEFT JOIN employees e ON e.id = k.owner_employee_id
                             ORDER BY k.id")->fetchAll(PDO::FETCH_ASSOC));

    $add('kra', $db->query("SELECT k.id, k.kra_ref AS code, LEFT(COALESCE(k.kra, k.title), 120) AS name, k.version,
                                   CONCAT('for ', k.kpa_ref) AS context,
                                   COALESCE(e.full_name,'') AS person, k.is_active
                              FROM kpa_goals k LEFT JOIN employees e ON e.id = k.owner_employee_id
                             WHERE k.kra IS NOT NULL AND k.kra <> '' ORDER BY k.id")->fetchAll(PDO::FETCH_ASSOC));

    $add('kpi', $db->query("SELECT k.id, k.kpi_ref AS code,
                                   CONCAT(COALESCE(k.target_value,'—'), ' ', COALESCE(k.unit,'')) AS name, k.version,
                                   CONCAT('measures ', k.kra_ref, COALESCE(CONCAT(' · ', k.period), '')) AS context,
                                   COALESCE(e.full_name,'') AS person, k.is_active
                              FROM kpa_goals k LEFT JOIN employees e ON e.id = k.owner_employee_id
                             WHERE k.target_value IS NOT NULL AND k.target_value <> '' ORDER BY k.id")->fetchAll(PDO::FETCH_ASSOC));

    $add('sla', $db->query("SELECT id, sla_ref AS code, name, version,
                                   CONCAT(entity, ' · TAT ', tat_hours, 'h',
                                          COALESCE(CONCAT(' · ', department), ''),
                                          COALESCE(CONCAT(' › ', sub_department), '')) AS context,
                                   '' AS person, is_active
                              FROM sla_policies ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));

    $add('esc', $db->query("SELECT m.id, m.esc_ref AS code,
                                   CONCAT('L', m.level, ': ', COALESCE(e.full_name,'?'), ' -> ', COALESCE(t.full_name,'?')) AS name,
                                   m.version,
                                   CONCAT('TAT ', COALESCE(m.tat_hours,'—'), 'h') AS context,
                                   CONCAT(COALESCE(e.full_name,''), ' / ', COALESCE(t.full_name,'')) AS person,
                                   1 AS is_active
                              FROM escalation_matrix m
                              LEFT JOIN employees e ON e.id = m.employee_id
                              LEFT JOIN employees t ON t.id = m.escalate_to
                             ORDER BY m.employee_id, m.level")->fetchAll(PDO::FETCH_ASSOC));

    $add('policy', $db->query("SELECT c.id, c.policy_no AS code, c.title AS name, c.version,
                                      CONCAT(c.item_type, COALESCE(CONCAT(' · valid till ', c.valid_till), '')) AS context,
                                      COALESCE(e.full_name, g.name, '') AS person,
                                      (c.status='active') AS is_active
                                 FROM compliance_items c
                                 LEFT JOIN employees e ON e.id = c.owner_employee_id
                                 LEFT JOIN emp_groups g ON g.id = c.owner_group_id
                                ORDER BY c.id")->fetchAll(PDO::FETCH_ASSOC));

    // attach the reviewer/approver state (absence = never submitted -> draft)
    $appr = [];
    foreach ($db->query("SELECT a.*, ra.full_name AS reviewed_by_name, ap.full_name AS approved_by_name
                           FROM governance_approvals a
                           LEFT JOIN employees ra ON ra.id = a.reviewed_by
                           LEFT JOIN employees ap ON ap.id = a.approved_by")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $appr[$a['obj_type'] . ':' . $a['obj_id']] = $a;
    }
    foreach ($out as &$o) {
        $a = $appr[$o['type'] . ':' . $o['id']] ?? null;
        $o['status'] = $a['status'] ?? 'draft';
        $o['reviewed_by_name'] = $a['reviewed_by_name'] ?? null;
        $o['approved_by_name'] = $a['approved_by_name'] ?? null;
        $o['reject_reason'] = $a['reject_reason'] ?? null;
    }
    unset($o);
    return $out;
}

/* ================================================================== *
 *  Reviewer / approver workflow (phase65) + revert to a version
 * ================================================================== */

/** Minimum role tier per step. Author writes, manager reviews, head/admin approves. */
function gov_step_tier(string $step): int
{
    return ['review' => PAYG_TIER_MANAGER, 'approve' => PAYG_TIER_HEAD][$step] ?? PAYG_TIER_MANAGER;
}

/** The approval row for an object; creates a draft row on first touch. */
function gov_approval(PDO $db, string $type, int $id, bool $create = false): ?array
{
    $s = $db->prepare("SELECT * FROM governance_approvals WHERE obj_type=? AND obj_id=?");
    $s->execute([$type, $id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row || !$create) return $row ?: null;
    $db->prepare("INSERT INTO governance_approvals (obj_type, obj_id, status, author_id) VALUES (?,?, 'draft', ?)")
       ->execute([$type, $id, current_employee_id() ?: null]);
    return gov_approval($db, $type, $id);
}

/** Mark a record as edited: an approved record that changes goes back to draft. */
function gov_touch(PDO $db, string $type, int $id): void
{
    try {
        $a = gov_approval($db, $type, $id, true);
        if ($a && in_array($a['status'], ['approved', 'reviewed', 'rejected'], true)) {
            $db->prepare("UPDATE governance_approvals
                             SET status='draft', reviewed_by=NULL, reviewed_at=NULL,
                                 approved_by=NULL, approved_at=NULL, rejected_by=NULL, rejected_at=NULL,
                                 reject_reason=NULL
                           WHERE obj_type=? AND obj_id=?")->execute([$type, $id]);
        }
    } catch (Throwable $e) { /* never block the edit */ }
}

/**
 * Move a record through the workflow. Returns [ok, message].
 * Separation of duties: reviewer != author, approver != author and != reviewer.
 */
function gov_transition(PDO $db, string $type, int $id, string $action, ?string $reason = null): array
{
    $me = (int)(current_employee_id() ?: 0);
    $tier = payg_role_tier(current_role());
    $a = gov_approval($db, $type, $id, true);
    if (!$a) return [false, 'That record no longer exists'];
    $code = gov_code($db, $type, $id) ?: strtoupper($type) . "-$id";
    $set = null; $summary = null;

    switch ($action) {
        case 'submit':
            if (!in_array($a['status'], ['draft', 'rejected'], true)) return [false, 'Only a draft can be submitted for review'];
            $set = "status='pending_review', submitted_by=$me, submitted_at=NOW(), author_id=COALESCE(author_id,$me)";
            $summary = "$code submitted for review";
            break;
        case 'review':
            if ($a['status'] !== 'pending_review') return [false, 'That record is not awaiting review'];
            if ($tier < gov_step_tier('review') && current_role() !== 'admin') return [false, 'Reviewing needs a manager or above'];
            if ((int)$a['submitted_by'] === $me || (int)$a['author_id'] === $me) return [false, 'The reviewer must be someone other than the author'];
            $set = "status='reviewed', reviewed_by=$me, reviewed_at=NOW()";
            $summary = "$code reviewed";
            break;
        case 'approve':
            if ($a['status'] !== 'reviewed') return [false, 'A record must be reviewed before it is approved'];
            if ($tier < gov_step_tier('approve') && current_role() !== 'admin') return [false, 'Approving needs a department head or admin'];
            if ((int)$a['reviewed_by'] === $me) return [false, 'The approver must be someone other than the reviewer'];
            if ((int)$a['author_id'] === $me) return [false, 'The approver must be someone other than the author'];
            $set = "status='approved', approved_by=$me, approved_at=NOW()";
            $summary = "$code approved";
            break;
        case 'reject':
            if (!in_array($a['status'], ['pending_review', 'reviewed'], true)) return [false, 'Nothing to reject at this stage'];
            if ($tier < gov_step_tier('review') && current_role() !== 'admin') return [false, 'Rejecting needs a manager or above'];
            $set = "status='rejected', rejected_by=$me, rejected_at=NOW(), reject_reason=" . $db->quote((string)$reason);
            $summary = "$code rejected" . ($reason ? ": $reason" : '');
            break;
        case 'reopen':
            if ($a['status'] === 'draft') return [false, 'Already a draft'];
            $set = "status='draft', reviewed_by=NULL, reviewed_at=NULL, approved_by=NULL, approved_at=NULL,
                    rejected_by=NULL, rejected_at=NULL, reject_reason=NULL";
            $summary = "$code reopened for editing";
            break;
        default:
            return [false, 'Unknown action'];
    }
    $db->prepare("UPDATE governance_approvals SET $set WHERE obj_type=? AND obj_id=?")->execute([$type, $id]);
    gov_revise($db, $type, $id, $action === 'reject' ? 'updated' : 'updated', $summary);
    return [true, $summary];
}

/**
 * Restore a record to an earlier revision's snapshot. Generated/derived columns
 * and the identity are never written back; the restore itself becomes a new
 * version (nothing in the history is rewritten).
 */
function gov_revert(PDO $db, string $type, int $id, int $revId): array
{
    $t = gov_types()[$type] ?? null;
    if (!$t) return [false, 'Unknown record type'];
    $s = $db->prepare("SELECT * FROM governance_revisions WHERE id=? AND obj_type=? AND obj_id=?");
    $s->execute([$revId, $type, $id]);
    $rev = $s->fetch(PDO::FETCH_ASSOC);
    if (!$rev || !$rev['snapshot']) return [false, 'That version has no stored snapshot to restore'];
    $snap = json_decode($rev['snapshot'], true);
    if (!is_array($snap)) return [false, 'That version could not be read'];

    // only real, writable columns of the table — skip id, generated codes, version, timestamps
    $cols = $db->query("SHOW COLUMNS FROM {$t['table']}")->fetchAll(PDO::FETCH_ASSOC);
    $skip = ['id', 'version', 'created_at', 'updated_at'];
    $set = []; $args = [];
    foreach ($cols as $c) {
        $name = $c['Field'];
        if (in_array($name, $skip, true)) continue;
        if (stripos((string)($c['Extra'] ?? ''), 'VIRTUAL') !== false
            || stripos((string)($c['Extra'] ?? ''), 'GENERATED') !== false) continue;   // derived code columns
        if (!array_key_exists($name, $snap)) continue;
        $set[] = "`$name`=?"; $args[] = $snap[$name];
    }
    if (!$set) return [false, 'Nothing restorable in that version'];
    $args[] = $id;
    $db->prepare("UPDATE {$t['table']} SET " . implode(',', $set) . " WHERE id=?")->execute($args);
    $code = gov_code($db, $type, $id);
    gov_revise($db, $type, $id, 'updated', "$code reverted to version {$rev['version']} ({$rev['rev_ref']})");
    gov_touch($db, $type, $id);   // a reverted record needs re-approval
    return [true, "$code restored to version {$rev['version']} — it is back in draft and needs review + approval"];
}

/** Links resolved to codes + names, newest first. */
function gov_links(PDO $db): array
{
    $cat = [];
    foreach (gov_catalog($db) as $c) $cat[$c['type'] . ':' . $c['id']] = $c;
    $rows = $db->query("SELECT l.*, e.full_name AS created_by_name
                          FROM governance_links l LEFT JOIN employees e ON e.id = l.created_by
                         ORDER BY l.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $f = $cat[$r['from_type'] . ':' . $r['from_id']] ?? null;
        $t = $cat[$r['to_type'] . ':' . $r['to_id']] ?? null;
        $r['from_code'] = $f['code'] ?? strtoupper($r['from_type']) . '-?';
        $r['from_name'] = $f['name'] ?? '(deleted)';
        $r['from_person'] = $f['person'] ?? '';
        $r['to_code']   = $t['code'] ?? strtoupper($r['to_type']) . '-?';
        $r['to_name']   = $t['name'] ?? '(deleted)';
        $r['to_person'] = $t['person'] ?? '';
    }
    return $rows;
}
