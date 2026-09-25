-- 1.1.0: contract terms, end dates and renegotiation dates on licenses and budget lines
ALTER TABLE licenses
  ADD COLUMN contract_start DATE NULL AFTER expire_date,
  ADD COLUMN contract_term_months SMALLINT UNSIGNED NULL AFTER contract_start,
  ADD COLUMN contract_end DATE NULL AFTER contract_term_months,
  ADD COLUMN notice_days SMALLINT UNSIGNED NULL AFTER contract_end,
  ADD COLUMN renegotiate_date DATE NULL AFTER notice_days;
ALTER TABLE budget_lines
  ADD COLUMN contract_term_months SMALLINT UNSIGNED NULL AFTER end_date,
  ADD COLUMN contract_end DATE NULL AFTER contract_term_months,
  ADD COLUMN notice_days SMALLINT UNSIGNED NULL AFTER contract_end,
  ADD COLUMN renegotiate_date DATE NULL AFTER notice_days,
  ADD COLUMN auto_renew TINYINT(1) NOT NULL DEFAULT 1 AFTER renegotiate_date;
