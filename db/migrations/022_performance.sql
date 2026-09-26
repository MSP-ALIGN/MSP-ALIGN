-- 1.15: indexes for the device list (warranty join by serial) and other frequent lookups
ALTER TABLE warranty_lookups ADD INDEX idx_warranty_serial (serial, status);
