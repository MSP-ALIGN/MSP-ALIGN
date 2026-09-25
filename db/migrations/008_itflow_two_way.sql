-- 0.7.0: two-way ITFlow asset sync, Unassigned hardware, projects in the IT plan

ALTER TABLE devices
  ADD COLUMN retired_at DATETIME NULL AFTER removed_at,
  ADD COLUMN itflow_sync TINYINT(1) NOT NULL DEFAULT 1 AFTER itflow_asset_id,
  ADD COLUMN updated_at DATETIME NULL;

ALTER TABLE itflow_assets
  ADD COLUMN updated_at DATETIME NULL AFTER location_name;

-- Last value both systems agreed on, per device and field. A side "changed" a field when its
-- value no longer matches this baseline; if both changed, the newest edit wins.
CREATE TABLE itflow_sync_state (
  device_id INT UNSIGNED NOT NULL,
  field VARCHAR(20) NOT NULL,
  base_value TEXT NULL,
  align_changed_at DATETIME NULL,
  pending TINYINT(1) NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  PRIMARY KEY (device_id, field),
  CONSTRAINT fk_iss_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What sync changed on each side (and conflicts it resolved)
CREATE TABLE device_changes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id INT UNSIGNED NOT NULL,
  field VARCHAR(20) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  direction ENUM('to_itflow','from_itflow','created') NOT NULL,
  conflict TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dc_device (device_id, created_at),
  CONSTRAINT fk_dc_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE itflow_poll_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  last_run DATETIME NULL,
  last_ok DATETIME NULL,
  last_result VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO itflow_poll_state (id) VALUES (1);

-- ITFlow "Other"/unknown types now land in Unassigned so they can be categorized.
UPDATE devices d LEFT JOIN device_overrides o ON o.device_id = d.id
  SET d.device_type = 'Unassigned'
  WHERE d.source = 'itflow' AND d.device_type = 'Other' AND o.device_type IS NULL;

-- Everything in ITFlow shows up in Align now (types can still be turned off in Settings).
UPDATE settings SET value = 'network,printer,ups,storage,camera,phone,server,workstation,vm,other' WHERE name = 'itflow_import_types';
INSERT IGNORE INTO settings (name, value) VALUES
  ('itflow_import_types', 'network,printer,ups,storage,camera,phone,server,workstation,vm,other'),
  ('itflow_two_way', '1'),
  ('itflow_create_assets', '1');
