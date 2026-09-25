-- 0.2.0: manual clients and devices, meetings/calendar, compliance

-- Clients can now be created in Align (source = manual) as well as synced from ITFlow
ALTER TABLE clients
  MODIFY itflow_client_id INT UNSIGNED NULL,
  ADD COLUMN source ENUM('itflow','manual') NOT NULL DEFAULT 'itflow' AFTER id,
  ADD COLUMN contact_name VARCHAR(190) NULL,
  ADD COLUMN contact_email VARCHAR(190) NULL,
  ADD COLUMN contact_phone VARCHAR(60) NULL,
  ADD COLUMN website VARCHAR(255) NULL,
  ADD COLUMN address TEXT NULL,
  ADD COLUMN industry VARCHAR(100) NULL,
  ADD COLUMN notes TEXT NULL,
  ADD COLUMN meeting_cadence ENUM('none','monthly','quarterly','semiannual','annual') NOT NULL DEFAULT 'quarterly',
  ADD COLUMN vcio_user_id INT UNSIGNED NULL;

-- Devices can now be added by hand (printers, switches, firewalls, hosts...)
ALTER TABLE devices
  MODIFY ninja_device_id INT UNSIGNED NULL,
  ADD COLUMN source ENUM('ninja','manual') NOT NULL DEFAULT 'ninja' AFTER id,
  ADD COLUMN client_id INT UNSIGNED NULL AFTER ninja_org_id,
  ADD COLUMN device_type VARCHAR(40) NULL AFTER device_class,
  ADD COLUMN ip_address VARCHAR(64) NULL,
  ADD COLUMN location VARCHAR(190) NULL,
  ADD COLUMN firmware VARCHAR(190) NULL,
  ADD COLUMN created_by INT UNSIGNED NULL,
  ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  ADD KEY idx_devices_client (client_id);

ALTER TABLE device_overrides
  ADD COLUMN device_type VARCHAR(40) NULL AFTER device_id;

ALTER TABLE users
  ADD COLUMN ics_token VARCHAR(64) NULL,
  ADD UNIQUE KEY uq_users_ics (ics_token);

INSERT IGNORE INTO settings (name, value) VALUES
  ('lifespan_printer', '5'),
  ('lifespan_storage', '5'),
  ('lifespan_power', '4'),
  ('lifespan_other', '5'),
  ('cost_printer', '800'),
  ('cost_storage', '2500'),
  ('cost_power', '600'),
  ('cost_other', '500'),
  ('meeting_default_minutes', '60');

CREATE TABLE meetings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(64) NOT NULL,
  client_id INT UNSIGNED NULL,
  series_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'qbr',
  status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  location VARCHAR(255) NULL,
  video_url VARCHAR(500) NULL,
  attendees TEXT NULL,
  agenda TEXT NULL,
  notes TEXT NULL,
  owner_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_meetings_uid (uid),
  KEY idx_meetings_start (starts_at),
  KEY idx_meetings_client (client_id, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compliance_frameworks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL,
  name VARCHAR(190) NOT NULL,
  description TEXT NULL,
  is_builtin TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fw_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compliance_controls (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  framework_id INT UNSIGNED NOT NULL,
  ref VARCHAR(40) NULL,
  section VARCHAR(190) NULL,
  title VARCHAR(255) NOT NULL,
  guidance TEXT NULL,
  auto_check VARCHAR(40) NULL,
  sort INT NOT NULL DEFAULT 0,
  KEY idx_ctrl_fw (framework_id, sort),
  CONSTRAINT fk_ctrl_fw FOREIGN KEY (framework_id) REFERENCES compliance_frameworks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_frameworks (
  client_id INT UNSIGNED NOT NULL,
  framework_id INT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_reviewed DATE NULL,
  next_review DATE NULL,
  PRIMARY KEY (client_id, framework_id),
  CONSTRAINT fk_cf_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_cf_fw FOREIGN KEY (framework_id) REFERENCES compliance_frameworks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_control_status (
  client_id INT UNSIGNED NOT NULL,
  control_id INT UNSIGNED NOT NULL,
  status ENUM('not_assessed','met','partial','not_met','na') NOT NULL DEFAULT 'not_assessed',
  notes TEXT NULL,
  evidence TEXT NULL,
  owner VARCHAR(190) NULL,
  due_date DATE NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id, control_id),
  CONSTRAINT fk_ccs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_ccs_ctrl FOREIGN KEY (control_id) REFERENCES compliance_controls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
