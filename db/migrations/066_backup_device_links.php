<?php
declare(strict_types=1);

/**
 * 2.7.5 backup_device_links: a backed-up machine linked by hand to the client device it is, for when the names are too
 * different to match (a VM named "Accounting server" for the computer FS01). The backup sync keeps the link as long as
 * the machine and device belong to the same client (a removed device's link is ignored); the link goes when the
 * machine is gone from the backup product or the device is deleted.
 *
 * Safe to run again (CREATE TABLE IF NOT EXISTS).
 */
return function (): void {
    Align\DB::pdo()->exec("CREATE TABLE IF NOT EXISTS backup_device_links (
        workload_uid VARCHAR(100) NOT NULL PRIMARY KEY,
        device_id INT UNSIGNED NOT NULL,
        linked_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bdl_device (device_id),
        CONSTRAINT fk_bdl_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
