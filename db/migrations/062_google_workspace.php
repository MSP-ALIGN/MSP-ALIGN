<?php
declare(strict_types=1);

/**
 * 2.6.3 Google Workspace for clients, and email authentication (SPF, DKIM, DMARC) for every client with a domain.
 *  - client_gws: one row per client connected to Google Workspace: its primary domain, the admin account Align acts
 *    as (domain-wide delegation, read-only scopes plus licensing), how it's reached ('msp' = the MSP's service account,
 *    allowed in the client's Admin console; 'own' = a service account in the client's own Google Cloud project, its
 *    key encrypted here), the customer's name and id as Google reports them, the last sync and its error, and the
 *    stored security checks (as client_m365), and whether the one-time duplicate check was done. A Google customer
 *    can be connected to one client only (unique customer_id).
 *  - gws_prices: the MSP's price list per Google Workspace edition (SKU id), shared by every client, seeded with the
 *    editions Google documents (digits; older G Suite customers can also have ids like Google-Apps-For-Business);
 *    skip leaves one out of Licensing.
 *  - client_email_auth: the last SPF, DKIM and DMARC result for a client's domain (read from public DNS, daily).
 *  - licenses: source and retired_reason gain 'gws'; gws_sku_id ties a license to the client's edition.
 *  - built-in controls about SPF, DKIM and DMARC are linked to the new email checks where nothing was linked yet (the
 *    Microsoft 365 baseline's M365-EXO-01 to 03, CIS IG2 9.5), as 061 did for the Microsoft 365 checks.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS client_gws (
        client_id INT UNSIGNED NOT NULL PRIMARY KEY,
        status ENUM('pending','connected') NOT NULL DEFAULT 'pending',
        mode ENUM('msp','own') NOT NULL DEFAULT 'msp',
        domain VARCHAR(255) NOT NULL,
        admin_email VARCHAR(255) NOT NULL,
        customer_id VARCHAR(64) NULL,
        org_name VARCHAR(255) NULL,
        key_enc TEXT NULL,
        key_client_id VARCHAR(64) NULL,
        connected_at DATETIME NULL,
        connected_by INT UNSIGNED NULL,
        last_sync_at DATETIME NULL,
        last_error VARCHAR(1000) NULL,
        security_json MEDIUMTEXT NULL,
        security_at DATETIME NULL,
        dupes_checked TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cg_domain (domain),
        UNIQUE KEY uq_cg_customer (customer_id),
        CONSTRAINT fk_cg_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS gws_prices (
        sku_id VARCHAR(64) NOT NULL PRIMARY KEY,
        name VARCHAR(190) NULL,
        unit_price DECIMAL(12,2) NULL,
        billing_cycle ENUM('monthly','quarterly','annual') NOT NULL DEFAULT 'monthly',
        skip TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS client_email_auth (
        client_id INT UNSIGNED NOT NULL PRIMARY KEY,
        domain VARCHAR(255) NOT NULL,
        provider VARCHAR(20) NULL,
        result_json TEXT NULL,
        checked_at DATETIME NULL,
        CONSTRAINT fk_cea_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // The editions Google documents (Enterprise License Manager, product Google-Apps), with no price yet
    foreach (\Align\Google\Skus::NAMES as $id => $name) {
        Align\DB::run('INSERT IGNORE INTO gws_prices (sku_id, name) VALUES (?, ?)', [$id, $name]);
    }

    // licenses: widen the two enums (keeping any value a later migration may have added), then the new column
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
    $widen('source', 'gws');
    $widen('retired_reason', 'gws');
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'licenses' AND column_name = 'gws_sku_id'")) {
        $db->exec('ALTER TABLE licenses ADD COLUMN gws_sku_id VARCHAR(64) NULL AFTER m365_sku_id, ADD UNIQUE KEY uq_license_gws (client_id, gws_sku_id)');
    }

    // Built-in controls linked to the email checks (matched by framework and reference; one an MSP linked is left alone)
    foreach ([['m365-baseline', 'M365-EXO-01', 'email_spf'], ['m365-baseline', 'M365-EXO-02', 'email_dkim'], ['m365-baseline', 'M365-EXO-03', 'email_dmarc'],
              ['cis-v81-ig2', '9.5', 'email_dmarc']] as [$fw, $ref, $check]) {
        Align\DB::run("UPDATE compliance_controls c JOIN compliance_frameworks f ON f.id = c.framework_id SET c.auto_check = ?
            WHERE f.slug = ? AND c.ref = ? AND c.auto_check IS NULL", [$check, $fw, $ref]);
    }
};
