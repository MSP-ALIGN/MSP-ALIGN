<?php
declare(strict_types=1);

/**
 * 2.14.0 KnowBe4 per client.
 *  - client_knowbe4: each client's KnowBe4 console: its region, its Reporting API token (encrypted with Crypto, never
 *    shown again), and what the last read found (account name, subscription, seats, risk score) or its error.
 *  - sat_results gains provider ('curricula' or 'knowbe4' for rows read from an API; null for uploads), so each
 *    provider replaces only its own rows. Existing API rows are Curricula's.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS client_knowbe4 (
        client_id INT UNSIGNED NOT NULL PRIMARY KEY,
        region VARCHAR(4) NOT NULL,
        token_enc TEXT NOT NULL,
        account_name VARCHAR(255) NULL,
        subscription VARCHAR(60) NULL,
        seats INT UNSIGNED NULL,
        risk_score DECIMAL(6,2) NULL,
        synced_at DATETIME NULL,
        error VARCHAR(500) NULL,
        added_by INT UNSIGNED NULL,
        added_at DATETIME NOT NULL,
        CONSTRAINT fk_kb4_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sat_results' AND column_name = 'provider'")) {
        $db->exec("ALTER TABLE sat_results ADD COLUMN provider VARCHAR(40) NULL AFTER source");
    }
    $db->exec("UPDATE sat_results SET provider = 'curricula' WHERE source = 'api' AND provider IS NULL");
};
