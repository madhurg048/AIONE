-- ============================================================
-- PayG CRM — Phase 45: freeze customer identity onto each ticket
--
-- Bug: crm_tickets has no customer_name/company/email/phone of its own — the Requests/
-- Tickets/Service-pipeline lists all derive "customer_name" via a LIVE JOIN to leads
-- (l.first_name/last_name/company/email/mobile). chatbot-ticket.php's repeat-visitor path
-- deliberately overwrites the shared lead row's identity fields with whatever the visitor
-- typed on their latest submission (see the "latest submission's identity wins" comment
-- there) — so editing one ticket's name retroactively changes what EVERY historical ticket
-- for that same lead displays, even though those old tickets should keep showing the name
-- as it was at the time they were raised.
--
-- Fix: snapshot the customer's identity onto crm_tickets at INSERT time; reads then prefer
-- the ticket's own snapshot over the live lead join. The lead record itself keeps updating
-- (that's correct — it's "who this contact currently is"), but each ticket's frontend
-- display becomes immutable history.
-- ============================================================

ALTER TABLE `crm_tickets`
  ADD COLUMN IF NOT EXISTS `customer_name` varchar(160) DEFAULT NULL AFTER `lead_id`,
  ADD COLUMN IF NOT EXISTS `customer_company` varchar(160) DEFAULT NULL AFTER `customer_name`,
  ADD COLUMN IF NOT EXISTS `customer_email` varchar(160) DEFAULT NULL AFTER `customer_company`,
  ADD COLUMN IF NOT EXISTS `customer_mobile` varchar(30) DEFAULT NULL AFTER `customer_email`;

-- Best-effort backfill for existing tickets: freeze whatever the lead currently shows.
-- This can't recover the true historical value (it was never stored), but it stops any
-- FUTURE lead edit from moving these old tickets around, and is a strict improvement over
-- "keeps drifting forever."
UPDATE `crm_tickets` ct
JOIN `leads` l ON l.id = ct.lead_id
SET ct.customer_name    = NULLIF(TRIM(CONCAT(COALESCE(l.first_name,''),' ',COALESCE(l.last_name,''))), ''),
    ct.customer_company = l.company,
    ct.customer_email   = l.email,
    ct.customer_mobile  = COALESCE(l.mobile, l.phone)
WHERE ct.customer_name IS NULL;
