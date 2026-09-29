<?php
declare(strict_types=1);

/**
 * 1.41: demo clients are marked, so syncs never merge them with real clients and "Remove demo data" can
 * only ever delete clients that were made as demo.
 */
return function (): void {
    $has = (bool) Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'clients' AND column_name = 'is_demo'");
    if (!$has) {
        Align\DB::pdo()->exec('ALTER TABLE clients ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0');
    }
};
