-- 1.6.0: last logged-in user reported by NinjaOne
ALTER TABLE devices ADD COLUMN last_user VARCHAR(190) NULL AFTER last_contact;
