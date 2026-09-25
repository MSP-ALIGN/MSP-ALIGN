-- 1.2.0: contacts per client (synced from ITFlow Contacts)
CREATE TABLE contacts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  source ENUM('itflow','manual') NOT NULL DEFAULT 'manual',
  itflow_contact_id INT UNSIGNED NULL,
  -- From ITFlow for synced contacts
  name VARCHAR(190) NOT NULL,
  title VARCHAR(190) NULL,
  department VARCHAR(190) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(60) NULL,
  extension VARCHAR(20) NULL,
  mobile VARCHAR(60) NULL,
  location VARCHAR(190) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  is_important TINYINT(1) NOT NULL DEFAULT 0,
  is_billing TINYINT(1) NOT NULL DEFAULT 0,
  is_technical TINYINT(1) NOT NULL DEFAULT 0,
  itflow_notes TEXT NULL,
  -- vCIO context (always Align)
  decision_maker TINYINT(1) NOT NULL DEFAULT 0,
  qbr TINYINT(1) NOT NULL DEFAULT 0,
  align_notes TEXT NULL,
  archived_at DATETIME NULL,
  archived_reason ENUM('align','itflow') NULL,
  synced_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contact_itflow (itflow_contact_id),
  KEY idx_contact_client (client_id, archived_at),
  CONSTRAINT fk_contact_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
