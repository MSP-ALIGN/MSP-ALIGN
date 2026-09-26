-- 1.9.0: Microsoft 365 backups (Veeam Backup for Microsoft 365 through VSPC)
ALTER TABLE backup_jobs MODIFY source ENUM('server','agent','m365') NOT NULL;

-- Microsoft 365 organizations (tenants) protected by Veeam Backup for Microsoft 365
CREATE TABLE backup_m365_orgs (
  uid VARCHAR(64) NOT NULL PRIMARY KEY,
  company_uid VARCHAR(64) NULL,
  name VARCHAR(255) NOT NULL,
  services VARCHAR(255) NULL,
  is_backed_up TINYINT(1) NOT NULL DEFAULT 0,
  first_backup DATETIME NULL,
  last_backup DATETIME NULL,
  synced_at DATETIME NOT NULL,
  KEY idx_m365_orgs_company (company_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Protected users, groups, teams and sites with their newest restore point
CREATE TABLE backup_m365_objects (
  uid VARCHAR(190) NOT NULL PRIMARY KEY,
  company_uid VARCHAR(64) NULL,
  org_uid VARCHAR(64) NULL,
  name VARCHAR(255) NOT NULL,
  object_type ENUM('user','group','team','site','other') NOT NULL,
  restore_points INT UNSIGNED NULL,
  last_point DATETIME NULL,
  licensed TINYINT(1) NULL,
  synced_at DATETIME NOT NULL,
  KEY idx_m365_obj_company (company_uid, object_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
