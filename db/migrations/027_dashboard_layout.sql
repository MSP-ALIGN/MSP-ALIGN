-- 1.21: each user's own dashboard layout (card order and hidden cards), as JSON
ALTER TABLE users ADD COLUMN dashboard_layout TEXT NULL AFTER ics_created_at;
