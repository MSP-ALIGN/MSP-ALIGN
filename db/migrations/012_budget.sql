-- 1.0.0: technology budget per client
CREATE TABLE budget_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'other',
  vendor VARCHAR(190) NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  frequency ENUM('monthly','quarterly','annual','one_time') NOT NULL DEFAULT 'monthly',
  start_date DATE NULL,
  end_date DATE NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_budget_client (client_id),
  CONSTRAINT fk_budget_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Monthly managed-services estimate from ITFlow invoices (ITFlow's API has no recurring-invoice module)
CREATE TABLE itflow_billing (
  client_id INT UNSIGNED NOT NULL PRIMARY KEY,
  monthly DECIMAL(12,2) NOT NULL DEFAULT 0,
  method VARCHAR(40) NOT NULL,
  months TINYINT UNSIGNED NOT NULL DEFAULT 0,
  invoices SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  computed_at DATETIME NOT NULL,
  CONSTRAINT fk_billing_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (name, value) VALUES ('budget_msp_estimate', '1');
