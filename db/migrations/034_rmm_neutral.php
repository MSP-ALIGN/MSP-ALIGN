<?php
declare(strict_types=1);

/**
 * 1.29: RMM data becomes provider-neutral. NinjaOne is now one RMM provider, and an install can run
 * several RMMs. Devices record which RMM they came from and that RMM's ids (as text: some RMMs use
 * non-numeric ids); client <-> RMM organization links move to client_links (one link per client per
 * provider). Connection settings (ninja_*) stay with the NinjaOne connector.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $v = fn(string $sql, array $a = []) => Align\DB::value($sql, $a);
    $has = fn(string $table, ?string $col = null): bool => $col === null
        ? (bool) $v('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])
        : (bool) $v('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $col]);
    $hasIndex = fn(string $table, string $index): bool
        => (bool) $v('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $index]);

    // RMM organizations (replaces ninja_orgs)
    $db->exec("CREATE TABLE IF NOT EXISTS rmm_orgs (
        provider VARCHAR(40) NOT NULL,
        org_id VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        synced_at DATETIME NULL,
        PRIMARY KEY (provider, org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($has('ninja_orgs')) {
        $db->exec("INSERT IGNORE INTO rmm_orgs (provider, org_id, name, description, synced_at)
            SELECT 'ninjaone', CAST(id AS CHAR), name, description, synced_at FROM ninja_orgs");
        $db->exec('DROP TABLE ninja_orgs');
    }

    // Client <-> provider links. external_id NULL + match_method 'manual' = deliberately not linked (auto-match skips it).
    $db->exec("CREATE TABLE IF NOT EXISTS client_links (
        client_id INT UNSIGNED NOT NULL,
        provider VARCHAR(40) NOT NULL,
        external_id VARCHAR(64) NULL,
        match_method ENUM('auto','manual') NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (client_id, provider),
        UNIQUE KEY uq_client_links_ext (provider, external_id),
        CONSTRAINT fk_client_links_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($has('clients', 'ninja_org_id')) {
        $db->exec("INSERT IGNORE INTO client_links (client_id, provider, external_id, match_method)
            SELECT id, 'ninjaone', CAST(ninja_org_id AS CHAR), match_method FROM clients
            WHERE ninja_org_id IS NOT NULL OR match_method IS NOT NULL");
        if ($hasIndex('clients', 'uq_clients_ninja')) {
            $db->exec('ALTER TABLE clients DROP INDEX uq_clients_ninja');
        }
        $db->exec('ALTER TABLE clients DROP COLUMN ninja_org_id');
    }
    if ($has('clients', 'match_method')) {
        $db->exec('ALTER TABLE clients DROP COLUMN match_method');
    }

    // Devices
    if (!$has('devices', 'rmm_provider')) {
        $db->exec('ALTER TABLE devices ADD COLUMN rmm_provider VARCHAR(40) NULL AFTER source');
    }
    if ($has('devices', 'ninja_device_id')) {
        if ($hasIndex('devices', 'uq_devices_ninja')) {
            $db->exec('ALTER TABLE devices DROP INDEX uq_devices_ninja');
        }
        $db->exec('ALTER TABLE devices CHANGE ninja_device_id rmm_device_id VARCHAR(64) NULL');
    }
    if ($has('devices', 'ninja_org_id')) {
        if ($hasIndex('devices', 'idx_devices_org')) {
            $db->exec('ALTER TABLE devices DROP INDEX idx_devices_org');
        }
        $db->exec('ALTER TABLE devices CHANGE ninja_org_id rmm_org_id VARCHAR(64) NULL');
    }
    if ($has('devices', 'ninja_created')) {
        $db->exec("ALTER TABLE devices CHANGE ninja_created rmm_created DATETIME NULL COMMENT 'when the RMM first saw the device'");
    }
    $type = (string) $v("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'devices' AND column_name = 'source'");
    if (str_contains($type, "'ninja'")) {
        $db->exec("ALTER TABLE devices MODIFY source ENUM('ninja','manual','psa','rmm') NOT NULL DEFAULT 'ninja'");
        $db->exec("UPDATE devices SET source = 'rmm', rmm_provider = 'ninjaone' WHERE source = 'ninja'");
    }
    $db->exec("ALTER TABLE devices MODIFY source ENUM('rmm','manual','psa') NOT NULL DEFAULT 'rmm'");
    if (!$hasIndex('devices', 'uq_devices_rmm')) {
        $db->exec('ALTER TABLE devices ADD UNIQUE KEY uq_devices_rmm (rmm_provider, rmm_device_id)');
    }
    if (!$hasIndex('devices', 'idx_devices_rmm_org')) {
        $db->exec('ALTER TABLE devices ADD KEY idx_devices_rmm_org (rmm_provider, rmm_org_id)');
    }
};
