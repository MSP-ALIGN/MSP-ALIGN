-- 1.4.0: client portal (client users see and act on their own client only)
CREATE TABLE portal_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  -- sections this user can see
  can_roadmap TINYINT(1) NOT NULL DEFAULT 1,
  can_budget TINYINT(1) NOT NULL DEFAULT 0,
  can_devices TINYINT(1) NOT NULL DEFAULT 1,
  can_documents TINYINT(1) NOT NULL DEFAULT 1,
  -- actions
  can_approve TINYINT(1) NOT NULL DEFAULT 0,
  can_contacts TINYINT(1) NOT NULL DEFAULT 0,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  totp_secret_enc TEXT NULL,
  invite_token_hash CHAR(64) NULL,
  invite_expires_at DATETIME NULL,
  last_login_at DATETIME NULL,
  invited_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_portal_email (email),
  UNIQUE KEY uq_portal_invite (invite_token_hash),
  KEY idx_portal_client (client_id),
  CONSTRAINT fk_portal_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE clients ADD COLUMN portal_require_2fa TINYINT(1) NOT NULL DEFAULT 0;

-- Project decisions made by the client in the portal
ALTER TABLE roadmap_items
  ADD COLUMN decided_by_portal_user_id INT UNSIGNED NULL,
  ADD COLUMN decided_by_name VARCHAR(190) NULL,
  ADD COLUMN decided_at DATETIME NULL,
  ADD COLUMN decision_comment TEXT NULL;

ALTER TABLE contacts ADD COLUMN created_by_portal_user_id INT UNSIGNED NULL;
ALTER TABLE audit_log ADD COLUMN portal_user_id INT UNSIGNED NULL;

-- Which client documents appear in the portal (policies and plans by default; network notes stay internal)
ALTER TABLE documents ADD COLUMN portal_shared TINYINT(1) NOT NULL DEFAULT 0;
UPDATE documents SET portal_shared = 1 WHERE client_id IS NOT NULL AND category IN ('wisp','policy','procedure','plan');
