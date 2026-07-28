-- Phase 32: Missed-chat remarks
-- Adds a 'note' sender to chat_messages so agents can leave an internal remark (e.g. "called the
-- customer, no answer") on a chat that landed in the Missed tab without it looking like an actual
-- agent/visitor reply. Used by api/sales/chat.php's ?action=missed_remark.
ALTER TABLE `chat_messages`
  MODIFY COLUMN `sender` ENUM('visitor','agent','bot','system','note') NOT NULL;
