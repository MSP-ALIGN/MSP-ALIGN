<?php
declare(strict_types=1);

/**
 * 2.18.0 Evidence checks (the Phase 4 gate): a tech's own answer for each automatic security check of a client,
 * given without seeing Align's result, kept next to the result Align had at that moment, so the two can be compared.
 * Each round adds rows; the newest per client and check counts. The note says how the tech checked (never exported).
 *
 * Checks first, so the migration can safely run again.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'evidence_checks'")) {
        Align\DB::pdo()->exec("CREATE TABLE evidence_checks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            check_key VARCHAR(40) NOT NULL,
            manual ENUM('pass','fail','unsure') NOT NULL,
            auto_status ENUM('pass','fail','unknown') NOT NULL,
            auto_detail VARCHAR(500) NULL,
            note VARCHAR(1000) NULL,
            user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_ec_client_key (client_id, check_key, id),
            CONSTRAINT fk_ec_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
