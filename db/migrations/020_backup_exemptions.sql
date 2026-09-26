-- 1.10.0: mark devices or Veeam items as not needing a backup (with a reason, for the audit trail)
CREATE TABLE backup_exemptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  kind ENUM('device','workload','m365') NOT NULL,
  device_id INT UNSIGNED NULL,
  item_uid VARCHAR(190) NULL,
  item_name VARCHAR(255) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bkex_device (device_id),
  UNIQUE KEY uq_bkex_item (item_uid),
  KEY idx_bkex_client (client_id),
  CONSTRAINT fk_bkex_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_bkex_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE backup_jobs ADD COLUMN agent_uid VARCHAR(64) NULL AFTER source;
