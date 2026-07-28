-- Phase 33: track pushes of PIMS merchants to the external PayG Merchant Onboarding API.
ALTER TABLE merchants
  ADD COLUMN payg_merchant_id VARCHAR(64) DEFAULT NULL AFTER merchant_code,
  ADD COLUMN payg_push_status ENUM('not_pushed','pushed','failed') NOT NULL DEFAULT 'not_pushed' AFTER payg_merchant_id,
  ADD COLUMN payg_pushed_at TIMESTAMP NULL DEFAULT NULL AFTER payg_push_status,
  ADD COLUMN payg_push_error VARCHAR(500) DEFAULT NULL AFTER payg_pushed_at;
