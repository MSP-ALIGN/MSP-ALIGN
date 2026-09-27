-- 0.3.0: 3-year roadmap, removing clients from planning, yearly meeting cadence by default

ALTER TABLE clients
  ADD COLUMN planning_excluded TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN excluded_reason VARCHAR(255) NULL,
  MODIFY meeting_cadence ENUM('none','monthly','quarterly','semiannual','annual') NOT NULL DEFAULT 'annual';

-- Everyone got "quarterly" as the old default; switch them to the new yearly default.
UPDATE clients SET meeting_cadence = 'annual' WHERE meeting_cadence = 'quarterly';

CREATE TABLE roadmap_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'project',
  description TEXT NULL,
  target_quarter DATE NULL,
  cost DECIMAL(12,2) NULL,
  recurring_monthly DECIMAL(10,2) NULL,
  priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status ENUM('proposed','approved','scheduled','done','declined') NOT NULL DEFAULT 'proposed',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_roadmap_client (client_id, target_quarter),
  CONSTRAINT fk_roadmap_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (name, value) VALUES
  ('fiscal_year_start', '1'),
  ('plan_start', 'current'),
  ('company_name', ''),
  ('company_phone', NULL),
  ('company_email', NULL),
  ('company_website', NULL),
  ('report_footer', 'Prepared by your vCIO. Replacement costs are estimates for budgeting and exclude labor unless noted.');
