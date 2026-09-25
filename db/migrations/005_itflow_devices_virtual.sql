-- 0.4.0: devices imported from ITFlow assets (network gear, printers, UPS...),
-- virtual machines grouped with servers (server OS) or desktops (VDI).

ALTER TABLE devices
  MODIFY source ENUM('ninja','manual','itflow') NOT NULL DEFAULT 'ninja',
  ADD KEY idx_devices_itflow (itflow_asset_id);

ALTER TABLE itflow_assets
  ADD COLUMN ip_address VARCHAR(64) NULL,
  ADD COLUMN mac VARCHAR(64) NULL,
  ADD COLUMN os VARCHAR(255) NULL,
  ADD COLUMN description TEXT NULL,
  ADD COLUMN location_id INT UNSIGNED NULL,
  ADD COLUMN location_name VARCHAR(255) NULL;

INSERT IGNORE INTO settings (name, value) VALUES ('itflow_import_types', 'network,printer,ups,storage');

-- Old "Virtual machine" type becomes Virtual server or VDI depending on the OS
UPDATE devices SET is_virtual = 1 WHERE device_class = 'virtual' OR device_type = 'Virtual machine';
UPDATE devices SET device_type = CASE
    WHEN LOWER(COALESCE(os_name, '')) LIKE '%server%' OR COALESCE(node_class, '') LIKE '%SERVER%' THEN 'Virtual server'
    WHEN COALESCE(node_class, '') LIKE '%WORKSTATION%' OR node_class = 'MAC'
      OR LOWER(COALESCE(os_name, '')) REGEXP 'windows (7|8|10|11)' THEN 'VDI / virtual desktop'
    ELSE 'Virtual server' END
  WHERE is_virtual = 1 AND (device_type IS NULL OR device_type IN ('Virtual machine', 'Server', 'Desktop'));
UPDATE devices SET device_class = IF(device_type = 'VDI / virtual desktop', 'desktop', 'server')
  WHERE device_type IN ('Virtual server', 'VDI / virtual desktop');
UPDATE device_overrides SET device_type = 'Virtual server' WHERE device_type = 'Virtual machine';
