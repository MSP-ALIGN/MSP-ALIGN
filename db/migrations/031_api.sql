-- 1.27: REST API (Settings -> API). Keys are stored as a SHA-256 hash; the token is shown once.
CREATE TABLE api_keys (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  prefix CHAR(8) NOT NULL,                 -- public part of the token (msa_<prefix>_...), for lookup and display
  token_hash CHAR(64) NOT NULL,            -- sha256 of the whole token
  scopes TEXT NOT NULL,                    -- JSON list, e.g. ["projects:read","projects:write"]
  client_ids TEXT NULL,                    -- JSON list of client ids the key is limited to; NULL = all clients
  rate_limit INT UNSIGNED NOT NULL DEFAULT 120,  -- requests per minute
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  last_used_at DATETIME NULL,
  last_ip VARCHAR(64) NULL,
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_api_keys_prefix (prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Requests per key per minute (rate limiting); old windows are pruned as they go
CREATE TABLE api_rate (
  key_id INT UNSIGNED NOT NULL,
  window_start INT UNSIGNED NOT NULL,      -- unix minute
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (key_id, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recent requests (kept 30 days) for Settings -> API
CREATE TABLE api_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_id INT UNSIGNED NULL,
  request_id CHAR(16) NOT NULL,
  method VARCHAR(8) NOT NULL,
  path VARCHAR(255) NOT NULL,
  status SMALLINT UNSIGNED NOT NULL,
  ms INT UNSIGNED NOT NULL DEFAULT 0,
  ip VARCHAR(64) NULL,
  error_code VARCHAR(60) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_api_requests_key (key_id, created_at),
  KEY idx_api_requests_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotency-Key replay for POST (kept 24 hours)
CREATE TABLE api_idempotency (
  key_id INT UNSIGNED NOT NULL,
  idem_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,  -- case-sensitive
  request_hash CHAR(64) NOT NULL,
  status SMALLINT UNSIGNED NOT NULL,
  body MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (key_id, idem_key),
  KEY idx_api_idem_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (name, value, is_secret) VALUES ('api_enabled', '0', 0);
