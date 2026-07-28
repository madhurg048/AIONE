-- Phase 36: Missed-chat remark authorship + edit tracking
--   chat_messages.created_by  -> which employee left this message (used for 'note' remarks,
--                                so only the author can edit their own remark)
--   chat_messages.edited_at   -> set when a note is edited, so the UI can show "(edited)"

ALTER TABLE chat_messages
  ADD COLUMN created_by INT NULL DEFAULT NULL AFTER sender,
  ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
  ADD KEY idx_chat_messages_created_by (created_by);
