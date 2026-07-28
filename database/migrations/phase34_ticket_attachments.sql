-- Phase 34: Ticket attachments
--   crm_tickets.attachment_data/attachment_name/attachment_type — added live via ALTER
--   ahead of this migration (chatbot-ticket.php's transaction-request flow already writes
--   to them); this file just makes that schema change reproducible on other environments.

ALTER TABLE crm_tickets
  ADD COLUMN attachment_data LONGBLOB NULL DEFAULT NULL AFTER created_at,
  ADD COLUMN attachment_name VARCHAR(255) NULL DEFAULT NULL AFTER attachment_data,
  ADD COLUMN attachment_type VARCHAR(100) NULL DEFAULT NULL AFTER attachment_name;
