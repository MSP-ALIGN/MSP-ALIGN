-- 1.12.0: email through Microsoft 365 (Graph) and notifications

-- Outgoing mail. Bodies are cleared after the retention period; the row (who, what, when) stays as a log.
CREATE TABLE mail_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(40) NOT NULL,
  recipients TEXT NOT NULL,
  cc TEXT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NULL,
  attachments MEDIUMTEXT NULL,
  reply_to VARCHAR(255) NULL,
  client_id INT UNSIGNED NULL,
  dedupe_key VARCHAR(190) NULL,
  status ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(1000) NULL,
  send_after DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  purged TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_mail_dedupe (dedupe_key),
  KEY idx_mail_due (status, send_after),
  KEY idx_mail_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each staff member's own choices; a missing row means "use the default for my role"
CREATE TABLE user_notification_prefs (
  user_id INT UNSIGNED NOT NULL,
  notif_key VARCHAR(40) NOT NULL,
  enabled TINYINT(1) NOT NULL,
  PRIMARY KEY (user_id, notif_key),
  CONSTRAINT fk_unp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN notify_scope ENUM('mine','all') NULL AFTER role;

-- Small key/value store for scheduler state (last digest sent, last sync status seen, ...)
CREATE TABLE notify_state (
  k VARCHAR(190) NOT NULL PRIMARY KEY,
  v VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Meeting invitations sent through Outlook (Graph calendar) or as .ics email
ALTER TABLE meetings
  ADD COLUMN graph_event_id VARCHAR(255) NULL,
  ADD COLUMN graph_mailbox VARCHAR(190) NULL,
  ADD COLUMN online_join_url VARCHAR(1000) NULL,
  ADD COLUMN invite_sequence INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN invites_sent_at DATETIME NULL,
  ADD COLUMN reminder_sent_at DATETIME NULL;

INSERT IGNORE INTO settings (name, value, is_secret) VALUES
  ('mail_mode', 'off', 0), ('mail_log_days', '30', 0), ('notif_digest_hour', '7', 0), ('notif_weekly_day', '1', 0),
  ('notif_meeting_reminder_hours', '24', 0), ('mail_meeting_mode', 'calendar', 0);
