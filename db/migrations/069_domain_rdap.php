<?php
declare(strict_types=1);

/**
 * 2.10.0 domain_rdap: each client domain's registration as public RDAP reports it (the registrar and the expiry date),
 * read by Domains\Rdap every few days. One row per domain (a domain two clients share is read once). status: ok (read),
 * unknown (no RDAP service for its ending, or the lookup couldn't be made) or missing (the registry doesn't know it).
 *
 * rdap_services: IANA's bootstrap file (ending => registry RDAP address), kept in its own table (it's about a thousand
 * rows, too big for a setting that loads on every page).
 *
 * Safe to run again (CREATE TABLE IF NOT EXISTS).
 */
return function (): void {
    Align\DB::pdo()->exec("CREATE TABLE IF NOT EXISTS domain_rdap (
        domain VARCHAR(253) NOT NULL PRIMARY KEY,
        registrar VARCHAR(190) NULL,
        expires_on DATE NULL,
        status ENUM('ok','unknown','missing') NOT NULL DEFAULT 'unknown',
        detail VARCHAR(255) NULL,
        checked_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // IANA's bootstrap file: which registry's RDAP service answers for each ending, refreshed weekly
    Align\DB::pdo()->exec("CREATE TABLE IF NOT EXISTS rdap_services (
        tld VARCHAR(63) NOT NULL PRIMARY KEY,
        base_url VARCHAR(255) NOT NULL,
        fetched_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
