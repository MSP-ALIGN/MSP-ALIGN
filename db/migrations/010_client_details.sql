-- 0.8.1: pull client address, primary contact and phone numbers from ITFlow
ALTER TABLE clients
  ADD COLUMN contact_title VARCHAR(190) NULL AFTER contact_name,
  ADD COLUMN contact_mobile VARCHAR(60) NULL AFTER contact_phone,
  ADD COLUMN main_phone VARCHAR(60) NULL AFTER contact_mobile,
  ADD COLUMN itflow_fields VARCHAR(255) NULL COMMENT 'fields last filled from ITFlow (read-only in Align)';
