<?php
/**
 * Convert a qualified lead -> Account + Contact + Deal (single transaction).
 *   POST lead-convert.php
 *   { lead_id, create_deal:true, deal_name, amount, expected_close_date, pipeline_id }
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
$db = db();

if (method() !== 'POST') fail('POST required', 405);
$b = body();
$leadId = (int)($b['lead_id'] ?? 0);
if (!$leadId) fail('lead_id required');

$stmt = $db->prepare("SELECT * FROM leads WHERE id=?");
$stmt->execute([$leadId]);
$lead = $stmt->fetch();
if (!$lead) fail('Lead not found', 404);
assert_can_see($lead['owner_id']);   // reps can only convert their own leads
if ((int)$lead['is_converted'] === 1) fail('Lead already converted');

try {
    $db->beginTransaction();

    // 1) Account — find by company name or create
    $accountId = null;
    if (!empty($lead['company'])) {
        $s = $db->prepare("SELECT id FROM accounts WHERE account_name=? LIMIT 1");
        $s->execute([$lead['company']]);
        $accountId = $s->fetchColumn() ?: null;
    }
    if (!$accountId) {
        $s = $db->prepare("INSERT INTO accounts (account_name,phone,industry,owner_id,created_by)
                           VALUES (?,?,?,?,?)");
        $s->execute([
            $lead['company'] ?: trim($lead['first_name'] . ' ' . $lead['last_name']),
            $lead['phone'], $lead['industry'], $lead['owner_id'], current_employee_id() ?: null
        ]);
        $accountId = (int)$db->lastInsertId();
    }

    // 2) Contact — find by email or create
    $contactId = null;
    if (!empty($lead['email'])) {
        $s = $db->prepare("SELECT id FROM contacts WHERE email=? LIMIT 1");
        $s->execute([$lead['email']]);
        $contactId = $s->fetchColumn() ?: null;
    }
    if (!$contactId) {
        $s = $db->prepare("INSERT INTO contacts
            (account_id,first_name,last_name,email,phone,mobile,title,owner_id,lead_source,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $s->execute([
            $accountId, $lead['first_name'] ?: 'Contact', $lead['last_name'],
            $lead['email'], $lead['phone'], $lead['mobile'], $lead['title'],
            $lead['owner_id'], $lead['lead_source'], current_employee_id() ?: null
        ]);
        $contactId = (int)$db->lastInsertId();
    }

    // 3) Deal — optional
    $dealId = null;
    if (!empty($b['create_deal'])) {
        $pipelineId = (int)($b['pipeline_id'] ?? 0)
            ?: (int)$db->query("SELECT id FROM pipelines WHERE is_default=1 LIMIT 1")->fetchColumn();
        $st = $db->prepare("SELECT id, probability FROM deal_stages WHERE pipeline_id=? ORDER BY sort_order ASC LIMIT 1");
        $st->execute([$pipelineId]);
        $stage = $st->fetch();
        if (!$stage) fail('No stages configured for the selected pipeline', 400);

        $s = $db->prepare("INSERT INTO deals
            (deal_name,account_id,contact_id,pipeline_id,stage_id,amount,probability,expected_close_date,lead_source,owner_id,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $s->execute([
            $b['deal_name'] ?? ($lead['company'] . ' — Onboarding'),
            $accountId, $contactId, $pipelineId, $stage['id'],
            (float)($b['amount'] ?? 0), (int)$stage['probability'],
            $b['expected_close_date'] ?? null, $lead['lead_source'],
            $lead['owner_id'], current_employee_id() ?: null
        ]);
        $dealId = (int)$db->lastInsertId();
    }

    // 4) Re-point lead activities to the new contact
    $db->prepare("UPDATE activities SET related_type='contact', related_id=?
                  WHERE related_type='lead' AND related_id=?")
       ->execute([$contactId, $leadId]);

    // 5) Mark lead converted
    $db->prepare("UPDATE leads SET is_converted=1, lead_status='converted', converted_at=NOW(),
                  converted_account_id=?, converted_contact_id=?, converted_deal_id=? WHERE id=?")
       ->execute([$accountId, $contactId, $dealId, $leadId]);

    $db->commit();
    ok(['account_id' => (int)$accountId, 'contact_id' => (int)$contactId, 'deal_id' => $dealId]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fail('Conversion failed: ' . $e->getMessage(), 500);
}
