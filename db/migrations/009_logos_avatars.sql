-- 0.8.0: per-client logos and user profile pictures
ALTER TABLE clients ADD COLUMN logo_file VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN avatar_file VARCHAR(100) NULL;
