-- 0.6.0: in-app documents with autosave + version history, templates, evidence links

CREATE TABLE documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'policy',
  status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  body_html MEDIUMTEXT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  template_id INT UNSIGNED NULL,
  review_due DATE NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_docs_client (client_id, status),
  CONSTRAINT fk_docs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_versions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NULL,
  note VARCHAR(255) NULL,
  kind ENUM('auto','manual','restore','created') NOT NULL DEFAULT 'auto',
  saved_by INT UNSIGNED NULL,
  saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_docv (document_id, saved_at),
  CONSTRAINT fk_docv_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_presence (
  document_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  last_seen DATETIME NOT NULL,
  editing TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (document_id, user_id),
  CONSTRAINT fk_docp_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NULL,
  name VARCHAR(190) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'policy',
  description TEXT NULL,
  body_html MEDIUMTEXT NULL,
  is_builtin TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tpl_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A compliance answer can point at a document as its evidence
ALTER TABLE client_control_status
  ADD COLUMN document_id INT UNSIGNED NULL AFTER evidence,
  ADD KEY idx_ccs_doc (document_id);
