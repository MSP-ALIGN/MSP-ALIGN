-- 1.17: a replacement quarter set by hand overrides the end-of-life date for planning and budgets
ALTER TABLE device_overrides
  ADD COLUMN replace_on DATE NULL AFTER lifespan_years,
  ADD COLUMN replace_note VARCHAR(255) NULL AFTER replace_on;
