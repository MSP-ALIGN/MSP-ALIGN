<?php
declare(strict_types=1);

/**
 * 1.30: backup data becomes provider-neutral. Veeam Service Provider Console is now one backup provider,
 * and an install can run several. Veeam companies become backup_companies (per provider), each client's
 * Veeam company link moves to client_links (provider 'veeam'), and every stored job, machine and
 * Microsoft 365 record notes the provider it came from, so one provider's sync never prunes another's.
 * Connection settings (veeam_url, veeam_api_key) and the hosting-company flags (veeam_hosting_companies)
 * stay with the Veeam connector.
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

    // Backup companies (replaces veeam_companies)
    $db->exec("CREATE TABLE IF NOT EXISTS backup_companies (
        provider VARCHAR(40) NOT NULL,
        uid VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        status VARCHAR(40) NULL,
        cloud_quota_bytes BIGINT UNSIGNED NULL,
        cloud_used_bytes BIGINT UNSIGNED NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY (provider, uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($has('veeam_companies')) {
        $db->exec("INSERT IGNORE INTO backup_companies (provider, uid, name, status, cloud_quota_bytes, cloud_used_bytes, synced_at)
            SELECT 'veeam', uid, name, status, cloud_quota_bytes, cloud_used_bytes, synced_at FROM veeam_companies");
        $db->exec('DROP TABLE veeam_companies');
    }

    // Client <-> Veeam company links move to client_links (created in 1.29)
    if ($has('clients', 'veeam_company_uid')) {
        $db->exec("INSERT IGNORE INTO client_links (client_id, provider, external_id, match_method)
            SELECT id, 'veeam', veeam_company_uid, veeam_match FROM clients
            WHERE veeam_company_uid IS NOT NULL OR veeam_match IS NOT NULL");
        if ($hasIndex('clients', 'uq_clients_veeam')) {
            $db->exec('ALTER TABLE clients DROP INDEX uq_clients_veeam');
        }
        $db->exec('ALTER TABLE clients DROP COLUMN veeam_company_uid');
    }
    if ($has('clients', 'veeam_match')) {
        $db->exec('ALTER TABLE clients DROP COLUMN veeam_match');
    }

    // Which provider each stored record came from (everything so far came from Veeam)
    foreach (['backup_jobs', 'backup_workloads', 'backup_m365_orgs', 'backup_m365_objects'] as $t) {
        if (!$has($t, 'provider')) {
            $db->exec("ALTER TABLE `$t` ADD COLUMN provider VARCHAR(40) NOT NULL DEFAULT 'veeam' AFTER uid");
            $db->exec("ALTER TABLE `$t` ALTER COLUMN provider DROP DEFAULT");
        }
        if (!$hasIndex($t, "idx_{$t}_provider")) {
            $db->exec("ALTER TABLE `$t` ADD KEY idx_{$t}_provider (provider)");
        }
    }
};
