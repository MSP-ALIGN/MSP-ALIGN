<?php
declare(strict_types=1);

/**
 * 2.5.0 Client health score: one row per client per day (client_health) with the score of each area (lifecycle,
 * backups, compliance, service levels, alignment; null = no data for that area that day), written once a day by the
 * mail timer and refreshed when someone opens the client. The overall score is worked out from these with the weights
 * in use when it is read (Align\Health\Health::combine), so changing the weights changes the history too and the trend
 * stays comparable. clients.portal_health: whether the client's portal users see the score (off by default).
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS client_health (
        client_id INT UNSIGNED NOT NULL,
        day DATE NOT NULL,
        lifecycle TINYINT UNSIGNED NULL,
        backups TINYINT UNSIGNED NULL,
        compliance TINYINT UNSIGNED NULL,
        service TINYINT UNSIGNED NULL,
        alignment TINYINT UNSIGNED NULL,
        computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (client_id, day),
        KEY idx_ch_day (day),
        CONSTRAINT fk_ch_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'clients' AND column_name = 'portal_health'")) {
        $db->exec('ALTER TABLE clients ADD COLUMN portal_health TINYINT(1) NOT NULL DEFAULT 0');
    }
};
