-- Phase 36: distinguish WHY a chat_sessions row ended up 'missed' (still one status value for
-- existing tab/count plumbing) so the Missed tab in sales-chat.php can label a chat the visitor
-- gave up on while waiting in queue ('waiting_left') differently from any other missed reason
-- added later. Remarks/close (missed_remark/missed_close in api/sales/chat.php) already work off
-- status='missed' alone, so no change needed there — this is purely an extra label.
ALTER TABLE `chat_sessions`
  ADD COLUMN `missed_reason` VARCHAR(30) NULL DEFAULT NULL AFTER `status`;
