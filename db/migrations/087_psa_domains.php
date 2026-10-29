<?php
declare(strict_types=1);

/**
 * 2.28.0 Domains from the PSA (ITFlow 26.10+ keeps each client's domains): psa_domains holds the PSA's active domains of
 * linked clients, refreshed on every sync (Align\Domains\PsaDomainSync). They join each client's domain list (email
 * authentication checks and RDAP registrar / expiry, EmailAuth::domains) like a connection's, so they can be left out
 * per domain; the PSA's expiry date is shown where RDAP has none.
 *
 * Only creates a table; checks first, so it can safely run again.
 */
return function (): void {
    if (Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'psa_domains'")) {
        return;
    }
    Align\DB::pdo()->exec("CREATE TABLE psa_domains (
        provider VARCHAR(30) NOT NULL,
        psa_id VARCHAR(64) NOT NULL,
        client_id INT UNSIGNED NOT NULL,
        domain VARCHAR(253) NOT NULL,
        expires_on DATE NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY (provider, psa_id),
        KEY idx_psa_domains_client (client_id, domain),
        CONSTRAINT fk_psa_domains_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
