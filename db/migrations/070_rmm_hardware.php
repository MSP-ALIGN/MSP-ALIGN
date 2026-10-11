<?php
declare(strict_types=1);

/**
 * 2.13.0 rmm_hardware: make, model and serial number for RMM devices whose RMM only gives them through a separate
 * hardware audit per device (Datto RMM). The provider keeps the last audit here so each sync asks only for devices
 * that are new or were audited again since (audit_at: the RMM's own last audit time), a limited number per run, rather
 * than one request per device every hour. checked_at: when Align last read it. A row for a device the RMM no longer
 * lists is deleted after a complete read.
 *
 * Safe to run again (CREATE TABLE IF NOT EXISTS).
 */
return function (): void {
    Align\DB::pdo()->exec("CREATE TABLE IF NOT EXISTS rmm_hardware (
        provider VARCHAR(40) NOT NULL,
        device_id VARCHAR(64) NOT NULL,
        audit_at DATETIME NULL,
        manufacturer VARCHAR(190) NULL,
        model VARCHAR(190) NULL,
        serial VARCHAR(190) NULL,
        checked_at DATETIME NOT NULL,
        PRIMARY KEY (provider, device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
