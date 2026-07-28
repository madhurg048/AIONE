<?php
/**
 * ITSM pushes ticket status into the CRM.
 *   POST /api/integrations/ticket-update.php   Header: X-API-Key
 *   Body: { ticket_ref, status, assigned_to?, waiting_on?, priority?, subject?, note?,
 *           merchant_code?|merchant_id?, lead_id? }
 * Upserts by ticket_ref so ITSM can push repeated updates.
 */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../sales/_task_events.php';
$db = idb();
integration_auth($db, 'ticket_update');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ifail('POST required', 405);
$b = ibody();
if (empty($b['ticket_ref'])) ifail('ticket_ref required');
$m = resolve_merchant($db, $b);
$mid = $m['id'] ?? null;

$db->prepare("INSERT INTO crm_tickets (ticket_ref,source,subject,status,assigned_to_name,waiting_on,priority,merchant_id,lead_id,last_update_note)
              VALUES (?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE subject=VALUES(subject), status=VALUES(status),
                assigned_to_name=VALUES(assigned_to_name), waiting_on=VALUES(waiting_on),
                priority=VALUES(priority), merchant_id=VALUES(merchant_id), lead_id=VALUES(lead_id),
                last_update_note=VALUES(last_update_note)")
   ->execute([$b['ticket_ref'], $b['source'] ?? 'itsm', $b['subject'] ?? null, $b['status'] ?? null,
              $b['assigned_to'] ?? null, $b['waiting_on'] ?? null, $b['priority'] ?? null,
              $mid, $b['lead_id'] ?? null, $b['note'] ?? null]);

// system event -> auto task for the merchant owner (or auto-close when resolved)
on_ticket_update($db, [
    'ticket_ref' => $b['ticket_ref'], 'subject' => $b['subject'] ?? null, 'status' => $b['status'] ?? null,
    'waiting_on' => $b['waiting_on'] ?? null, 'priority' => $b['priority'] ?? null,
    'merchant_id' => $mid, 'last_update_note' => $b['note'] ?? null,
]);

iok(['ticket_ref' => $b['ticket_ref']], ['message' => 'ticket status updated']);
