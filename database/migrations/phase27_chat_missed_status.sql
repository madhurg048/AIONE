-- Adds a 'missed' chat_sessions status: a waiting chat that nobody picked up in time.
-- chat.php's inbox action now auto-expires stale 'waiting' rows into 'missed' and exposes
-- a dedicated filter tab for them; pickup() accepts picking a missed chat back up too.
ALTER TABLE `chat_sessions`
  MODIFY COLUMN `status` ENUM('waiting','active','missed','closed') DEFAULT 'waiting';
