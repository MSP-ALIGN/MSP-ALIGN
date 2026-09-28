-- 1.26: backups that run on the provider's own Veeam server (hosted clients).
-- Each protected machine and job now carries the client(s) it belongs to, worked out on every sync:
-- a manual assignment, the machine's own Veeam company, its job's assignment, or a device with the same name.

ALTER TABLE backup_workloads
  ADD COLUMN client_id INT UNSIGNED NULL AFTER company_uid,
  ADD COLUMN client_how ENUM('company','machine','job','device') NULL AFTER client_id,
  ADD KEY idx_backup_wl_client (client_id);

-- Which jobs back up which machine (a machine can be in several jobs)
CREATE TABLE backup_workload_jobs (
  workload_uid VARCHAR(100) NOT NULL,
  job_uid VARCHAR(64) NOT NULL,
  PRIMARY KEY (workload_uid, job_uid),
  KEY idx_bwj_job (job_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The client(s) each job counts for; a job on a shared hosting server can count for several
CREATE TABLE backup_job_clients (
  job_uid VARCHAR(64) NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  how ENUM('company','job','machines') NOT NULL,
  PRIMARY KEY (job_uid, client_id),
  KEY idx_bjc_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignments made by hand (kept across syncs). client_id NULL = "ours, not a client's".
CREATE TABLE backup_assignments (
  item_type ENUM('job','workload') NOT NULL,
  item_uid VARCHAR(100) NOT NULL,
  client_id INT UNSIGNED NULL,
  item_name VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (item_type, item_uid),
  KEY idx_ba_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Veeam companies whose machines get sorted into clients even though the company is linked to a client
-- (e.g. your own company, when you're also set up as a client). Companies linked to no client always are.
INSERT IGNORE INTO settings (name, value, is_secret) VALUES ('veeam_hosting_companies', '[]', 0);
