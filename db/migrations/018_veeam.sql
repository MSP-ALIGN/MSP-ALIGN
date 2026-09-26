-- 1.8.0: Veeam Service Provider Console integration (backup status per client)
ALTER TABLE clients
  ADD COLUMN veeam_company_uid VARCHAR(64) NULL AFTER match_method,
  ADD COLUMN veeam_match ENUM('auto','manual') NULL AFTER veeam_company_uid,
  ADD UNIQUE KEY uq_clients_veeam (veeam_company_uid);

-- Companies (customers) as VSPC knows them, plus Cloud Connect storage use
CREATE TABLE veeam_companies (
  uid VARCHAR(64) NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(40) NULL,
  cloud_quota_bytes BIGINT UNSIGNED NULL,
  cloud_used_bytes BIGINT UNSIGNED NULL,
  synced_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backup jobs (Veeam Backup & Replication jobs and Veeam Agent jobs), latest state
CREATE TABLE backup_jobs (
  uid VARCHAR(64) NOT NULL PRIMARY KEY,
  company_uid VARCHAR(64) NULL,
  source ENUM('server','agent') NOT NULL,
  name VARCHAR(255) NOT NULL,
  job_type VARCHAR(60) NULL,
  status ENUM('success','warning','failed','running','none') NOT NULL DEFAULT 'none',
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_run DATETIME NULL,
  last_end DATETIME NULL,
  duration_sec INT UNSIGNED NULL,
  failure_message TEXT NULL,
  target VARCHAR(255) NULL,
  chain_bytes BIGINT UNSIGNED NULL,
  synced_at DATETIME NOT NULL,
  KEY idx_backup_jobs_company (company_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per job run seen by sync: builds the success history (kept about 13 months)
CREATE TABLE backup_job_runs (
  job_uid VARCHAR(64) NOT NULL,
  run_at DATETIME NOT NULL,
  company_uid VARCHAR(64) NULL,
  status ENUM('success','warning','failed') NOT NULL,
  PRIMARY KEY (job_uid, run_at),
  KEY idx_backup_runs_company (company_uid, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Protected machines (VMs and computers) with their newest restore point
CREATE TABLE backup_workloads (
  uid VARCHAR(100) NOT NULL PRIMARY KEY,
  company_uid VARCHAR(64) NULL,
  kind ENUM('vm','computer') NOT NULL,
  name VARCHAR(255) NOT NULL,
  hostname VARCHAR(190) NULL,
  device_id INT UNSIGNED NULL,
  last_point DATETIME NULL,
  restore_points INT UNSIGNED NULL,
  backup_bytes BIGINT UNSIGNED NULL,
  source_bytes BIGINT UNSIGNED NULL,
  synced_at DATETIME NOT NULL,
  KEY idx_backup_wl_company (company_uid),
  KEY idx_backup_wl_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (name, value, is_secret) VALUES ('backup_stale_hours', '48', 0);
