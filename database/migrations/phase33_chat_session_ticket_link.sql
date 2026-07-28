-- Phase 33: Link a chat_sessions row to the specific crm_tickets row it was opened for.
-- Fixes cross-contamination: the same visitor phone/email reuses the same lead_id across many
-- separate chatbot submissions (dedupe window in chatbot-ticket.php), so a transaction-issue
-- attachment uploaded on ticket A was being shown on every later, unrelated chat session that
-- happened to share that lead_id (the old lookup only matched on lead_id, "most recent ticket
-- with any attachment"). Storing the exact ticket_id at chat-open time lets api/sales/chat.php's
-- a_poll fetch the attachment for THIS session's own ticket only.
ALTER TABLE `chat_sessions`
  ADD COLUMN `ticket_id` INT NULL DEFAULT NULL AFTER `lead_id`,
  ADD KEY `idx_chat_ticket` (`ticket_id`);
