<?php
declare(strict_types=1);

/**
 * 2.6.0 Microsoft 365 for clients: each client's tenant connected read-only, and its Microsoft subscriptions kept in
 * Licensing.
 *  - client_m365: one row per client that is connected or being connected: the tenant, how it's reached ('msp' =
 *    the MSP's own multi-tenant app, consented in the client's tenant; 'own' = an app in the client's tenant, its
 *    secret encrypted here), the last sync and its error, a single-use nonce for a consent link that is still out,
 *    and (pending_*) a tenant whose admin approved through a link and that staff haven't confirmed yet: kept apart,
 *    so it never replaces a working connection until it's confirmed.
 *  - m365_prices: the MSP's price list per Microsoft subscription (by skuPartNumber), shared by every client: name,
 *    price, billing cycle, and whether to leave it out of Licensing (free and trial subscriptions).
 *  - licenses: source and retired_reason gain 'm365'; m365_sku_id ties a license to the client's subscription;
 *    price_source says whether its price follows the price list ('list') or was set on the license ('custom').
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS client_m365 (
        client_id INT UNSIGNED NOT NULL PRIMARY KEY,
        status ENUM('pending','connected') NOT NULL DEFAULT 'pending',
        mode ENUM('msp','own') NOT NULL DEFAULT 'msp',
        tenant_id CHAR(36) NULL,
        tenant_name VARCHAR(255) NULL,
        tenant_domain VARCHAR(255) NULL,
        app_id CHAR(36) NULL,
        secret_enc TEXT NULL,
        secret_expires DATE NULL,
        consent_nonce CHAR(32) NULL,
        pending_tenant_id CHAR(36) NULL,
        pending_tenant_name VARCHAR(255) NULL,
        pending_tenant_domain VARCHAR(255) NULL,
        pending_at DATETIME NULL,
        pending_note VARCHAR(500) NULL,
        connected_at DATETIME NULL,
        connected_by INT UNSIGNED NULL,
        last_sync_at DATETIME NULL,
        last_error VARCHAR(1000) NULL,
        dupes_checked TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cm_tenant (tenant_id),
        CONSTRAINT fk_cm_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS m365_prices (
        sku_part VARCHAR(100) NOT NULL PRIMARY KEY,
        sku_id CHAR(36) NULL,
        name VARCHAR(190) NULL,
        unit_price DECIMAL(12,2) NULL,
        billing_cycle ENUM('monthly','quarterly','annual') NOT NULL DEFAULT 'monthly',
        skip TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // licenses: widen the two enums (keeping any value a later migration may have added), then the new columns
    $widen = function (string $col, string $add) use ($db): void {
        $type = (string) Align\DB::value("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'licenses' AND column_name = ?", [$col]);
        if ($type === '' || str_contains($type, "'$add'")) {
            return;
        }
        preg_match_all("/'([^']*)'/", $type, $m);
        $vals = implode(',', array_map(fn($v) => "'$v'", [...$m[1], $add]));
        $null = Align\DB::value("SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'licenses' AND column_name = ?", [$col]) === 'YES';
        $default = Align\DB::value("SELECT column_default FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'licenses' AND column_name = ?", [$col]);
        $tail = $null ? ' NULL DEFAULT NULL' : ' NOT NULL' . (is_string($default) && $default !== '' && strtoupper($default) !== 'NULL' ? ' DEFAULT ' . (str_starts_with($default, "'") ? $default : "'$default'") : '');
        $db->exec("ALTER TABLE licenses MODIFY `$col` ENUM($vals)$tail");
    };
    $widen('source', 'm365');
    $widen('retired_reason', 'm365');
    $has = fn(string $col) => (bool) Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'licenses' AND column_name = ?", [$col]);
    if (!$has('m365_sku_id')) {
        $db->exec('ALTER TABLE licenses ADD COLUMN m365_sku_id CHAR(36) NULL AFTER psa_id, ADD UNIQUE KEY uq_license_m365 (client_id, m365_sku_id)');
    }
    if (!$has('price_source')) {
        $db->exec("ALTER TABLE licenses ADD COLUMN price_source ENUM('list','custom') NULL AFTER unit_price");
    }
};
