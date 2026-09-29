<?php
declare(strict_types=1);

/**
 * 1.36: an install without a PSA can create its clients from the RMM's organizations. Each organization
 * remembers when a client was made from it, so a client deleted on purpose isn't made again on the next sync.
 */
return function (): void {
    $has = (bool) Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'rmm_orgs' AND column_name = 'client_created_at'");
    if (!$has) {
        Align\DB::pdo()->exec('ALTER TABLE rmm_orgs ADD COLUMN client_created_at DATETIME NULL');
    }
};
