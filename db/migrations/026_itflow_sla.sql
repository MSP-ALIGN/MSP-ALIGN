-- 1.20: ITFlow tickets (SLA fields only, never the ticket body) for service-level reporting
CREATE TABLE itflow_tickets (
  id INT UNSIGNED NOT NULL PRIMARY KEY,           -- ITFlow ticket_id
  itflow_client_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NULL,
  number VARCHAR(60) NULL,
  subject VARCHAR(500) NULL,
  category VARCHAR(200) NULL,
  source VARCHAR(100) NULL,
  priority VARCHAR(40) NULL,
  status_id INT NOT NULL DEFAULT 0,
  sla_id INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  first_response_at DATETIME NULL,
  response_due_at DATETIME NULL,
  resolution_due_at DATETIME NULL,
  resolved_at DATETIME NULL,
  closed_at DATETIME NULL,
  archived_at DATETIME NULL,
  response_met TINYINT(1) NULL,
  resolution_met TINYINT(1) NULL,
  response_stage TINYINT NOT NULL DEFAULT 0,
  resolution_stage TINYINT NOT NULL DEFAULT 0,
  synced_at DATETIME NOT NULL,
  KEY idx_itt_client (client_id, created_at),
  KEY idx_itt_itclient (itflow_client_id),
  KEY idx_itt_open (closed_at, resolved_at),
  KEY idx_itt_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (name, value) VALUES ('itflow_sla_sync', '1'), ('sla_target', '90');
