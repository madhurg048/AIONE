-- Adds recording storage for click-to-call (Exotel). Populated by call-status.php once the
-- provider's status callback fires and we fetch the call's RecordingUrl from the Exotel API.
ALTER TABLE `call_logs`
  ADD COLUMN `recording_url` VARCHAR(500) DEFAULT NULL AFTER `duration_sec`;
