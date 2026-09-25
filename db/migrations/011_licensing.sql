-- 0.9.0: client licensing (synced from ITFlow Software, priced in Align)
CREATE TABLE licenses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  source ENUM('itflow','manual') NOT NULL DEFAULT 'manual',
  itflow_software_id INT UNSIGNED NULL,
  -- Details (managed in ITFlow for synced licenses)
  name VARCHAR(255) NOT NULL,
  version VARCHAR(100) NULL,
  software_type VARCHAR(60) NULL,
  license_type ENUM('user','device','site','other') NOT NULL DEFAULT 'user',
  seats INT UNSIGNED NULL,
  vendor VARCHAR(190) NULL,
  purchase_date DATE NULL,
  expire_date DATE NULL,
  notes TEXT NULL,
  -- Cost and planning (always Align)
  category VARCHAR(30) NOT NULL DEFAULT 'other',
  pricing ENUM('per_seat','flat') NOT NULL DEFAULT 'per_seat',
  unit_price DECIMAL(12,2) NULL,
  billing_cycle ENUM('monthly','quarterly','annual','one_time') NOT NULL DEFAULT 'monthly',
  seats_used INT UNSIGNED NULL,
  auto_renew TINYINT(1) NOT NULL DEFAULT 1,
  align_notes TEXT NULL,
  retired_at DATETIME NULL,
  retired_reason ENUM('align','itflow') NULL,
  synced_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_license_itflow (itflow_software_id),
  KEY idx_license_client (client_id, retired_at),
  CONSTRAINT fk_license_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
