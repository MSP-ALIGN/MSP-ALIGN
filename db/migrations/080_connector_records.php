<?php
declare(strict_types=1);

/**
 * 2.22.0 Shared storage for community security/SaaS connectors (Integrations\SaasStore), so a new connector needs
 * no migration of its own: its outside records (the organizations or accounts linked to clients, with any extra
 * details as JSON) and the check results worked out for each record. Rows carry the connector's key in `provider`;
 * one connector's sync never touches another's.
 *
 * Checks first, so the migration can safely run again.
 */
return function (): void {
    $has = fn(string $t) => (bool) Align\DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
    if (!$has('connector_records')) {
        Align\DB::pdo()->exec("CREATE TABLE connector_records (
            provider VARCHAR(40) NOT NULL,
            record_id VARCHAR(64) NOT NULL,
            name VARCHAR(255) NOT NULL,
            data MEDIUMTEXT NULL,
            synced_at DATETIME NOT NULL,
            PRIMARY KEY (provider, record_id),
            KEY idx_cr_name (provider, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!$has('connector_results')) {
        Align\DB::pdo()->exec("CREATE TABLE connector_results (
            provider VARCHAR(40) NOT NULL,
            record_id VARCHAR(64) NOT NULL,
            check_key VARCHAR(40) NOT NULL,
            status ENUM('pass','fail','unknown') NOT NULL,
            detail VARCHAR(300) NULL,
            synced_at DATETIME NOT NULL,
            PRIMARY KEY (provider, record_id, check_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
