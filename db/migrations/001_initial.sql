-- MSP-ALIGN: initial schema

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','tech','viewer') NOT NULL DEFAULT 'tech',
  totp_secret_enc TEXT NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(190) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_ip (ip, created_at),
  KEY idx_login_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  name VARCHAR(100) NOT NULL PRIMARY KEY,
  value TEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  detail TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clients come from ITFlow and are linked to a NinjaOne organization
CREATE TABLE clients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  itflow_client_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  ninja_org_id INT UNSIGNED NULL,
  match_method ENUM('auto','manual') NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  synced_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clients_itflow (itflow_client_id),
  UNIQUE KEY uq_clients_ninja (ninja_org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ninja_orgs (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  synced_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ninja_device_id INT UNSIGNED NOT NULL,
  ninja_org_id INT UNSIGNED NULL,
  display_name VARCHAR(255) NULL,
  system_name VARCHAR(255) NULL,
  node_class VARCHAR(60) NULL,
  device_class VARCHAR(20) NOT NULL DEFAULT 'other',
  manufacturer VARCHAR(190) NULL,
  model VARCHAR(190) NULL,
  serial VARCHAR(190) NULL,
  chassis VARCHAR(60) NULL,
  is_virtual TINYINT(1) NOT NULL DEFAULT 0,
  os_name VARCHAR(255) NULL,
  os_build VARCHAR(60) NULL,
  os_release_id VARCHAR(30) NULL,
  last_contact DATETIME NULL,
  ninja_created DATETIME NULL,
  offline TINYINT(1) NOT NULL DEFAULT 0,
  itflow_asset_id INT UNSIGNED NULL,
  removed_at DATETIME NULL,
  synced_at DATETIME NULL,
  UNIQUE KEY uq_devices_ninja (ninja_device_id),
  KEY idx_devices_org (ninja_org_id),
  KEY idx_devices_serial (serial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE itflow_assets (
  itflow_asset_id INT UNSIGNED NOT NULL PRIMARY KEY,
  itflow_client_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NULL,
  type VARCHAR(100) NULL,
  make VARCHAR(190) NULL,
  model VARCHAR(190) NULL,
  serial VARCHAR(190) NULL,
  purchase_date DATE NULL,
  warranty_expire DATE NULL,
  install_date DATE NULL,
  status VARCHAR(100) NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  synced_at DATETIME NULL,
  KEY idx_ita_client (itflow_client_id),
  KEY idx_ita_serial (serial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE warranty_lookups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vendor VARCHAR(20) NOT NULL,
  serial VARCHAR(190) NOT NULL,
  ship_date DATE NULL,
  warranty_start DATE NULL,
  warranty_end DATE NULL,
  description TEXT NULL,
  status ENUM('ok','not_found','error') NOT NULL,
  message TEXT NULL,
  looked_up_at DATETIME NOT NULL,
  UNIQUE KEY uq_warranty (vendor, serial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE device_overrides (
  device_id INT UNSIGNED NOT NULL PRIMARY KEY,
  purchase_date DATE NULL,
  warranty_end DATE NULL,
  replacement_cost DECIMAL(10,2) NULL,
  lifespan_years TINYINT UNSIGNED NULL,
  excluded TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_override_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE os_support (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(190) NOT NULL,
  name_contains VARCHAR(190) NOT NULL,
  build VARCHAR(20) NOT NULL,
  eos_date DATE NOT NULL,
  notes VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sync_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status ENUM('running','success','partial','failed') NOT NULL DEFAULT 'running',
  triggered_by VARCHAR(20) NOT NULL DEFAULT 'schedule',
  user_id INT UNSIGNED NULL,
  summary TEXT NULL,
  log MEDIUMTEXT NULL,
  KEY idx_sync_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default lifecycle policy
INSERT INTO settings (name, value) VALUES
  ('lifespan_desktop', '5'),
  ('lifespan_laptop', '4'),
  ('lifespan_server', '6'),
  ('lifespan_network', '7'),
  ('cost_desktop', '1100'),
  ('cost_laptop', '1500'),
  ('cost_server', '9000'),
  ('cost_network', '1200'),
  ('warranty_warn_days', '90'),
  ('eol_plan_months', '12'),
  ('stale_days', '45'),
  ('ninja_instance', 'app.ninjarmm.com'),
  ('itflow_writeback', 'off'),
  ('warranty_recheck_days', '30');

-- Windows end-of-support dates. Editable under Settings > OS support.
-- Match rule: OS name contains name_contains AND build number equals build.
-- When several rows match, the longest name_contains wins.
INSERT INTO os_support (label, name_contains, build, eos_date, notes) VALUES
  ('Windows 7 SP1',                 'Windows 7',             '7601',  '2020-01-14', NULL),
  ('Windows 8.1',                   'Windows 8.1',           '9600',  '2023-01-10', NULL),
  ('Windows 10 22H2',               'Windows 10',            '19045', '2025-10-14', 'ESU available at extra cost'),
  ('Windows 10 21H2 (Home/Pro)',    'Windows 10',            '19044', '2023-06-13', NULL),
  ('Windows 10 Enterprise LTSC 2021','Windows 10 Enterprise LTSC', '19044', '2027-01-12', NULL),
  ('Windows 11 21H2 (Home/Pro)',    'Windows 11',            '22000', '2023-10-10', NULL),
  ('Windows 11 22H2 (Home/Pro)',    'Windows 11',            '22621', '2024-10-08', NULL),
  ('Windows 11 22H2 (Enterprise)',  'Windows 11 Enterprise', '22621', '2025-10-14', NULL),
  ('Windows 11 22H2 (Education)',   'Windows 11 Education',  '22621', '2025-10-14', NULL),
  ('Windows 11 23H2 (Home/Pro)',    'Windows 11',            '22631', '2025-11-11', NULL),
  ('Windows 11 23H2 (Enterprise)',  'Windows 11 Enterprise', '22631', '2026-11-10', NULL),
  ('Windows 11 23H2 (Education)',   'Windows 11 Education',  '22631', '2026-11-10', NULL),
  ('Windows 11 24H2 (Home/Pro)',    'Windows 11',            '26100', '2026-10-13', NULL),
  ('Windows 11 24H2 (Enterprise)',  'Windows 11 Enterprise', '26100', '2027-10-12', NULL),
  ('Windows 11 24H2 (Education)',   'Windows 11 Education',  '26100', '2027-10-12', NULL),
  ('Windows 11 25H2 (Home/Pro)',    'Windows 11',            '26200', '2027-10-12', NULL),
  ('Windows 11 25H2 (Enterprise)',  'Windows 11 Enterprise', '26200', '2028-10-10', NULL),
  ('Windows 11 25H2 (Education)',   'Windows 11 Education',  '26200', '2028-10-10', NULL),
  ('Windows Server 2008 R2',        'Server',                '7601',  '2020-01-14', NULL),
  ('Windows Server 2012',           'Server',                '9200',  '2023-10-10', NULL),
  ('Windows Server 2012 R2',        'Server',                '9600',  '2023-10-10', NULL),
  ('Windows Server 2016',           'Server',                '14393', '2027-01-12', NULL),
  ('Windows Server 2019',           'Server',                '17763', '2029-01-09', NULL),
  ('Windows Server 2022',           'Server',                '20348', '2031-10-14', NULL),
  ('Windows Server 2025',           'Server',                '26100', '2034-11-14', NULL);
