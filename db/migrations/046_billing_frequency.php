<?php
declare(strict_types=1);

/** 1.45.1: the managed-services estimate says how it was worked out per recurring schedule (monthly, yearly...). */
return function (): void {
    $has = (bool) Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'psa_billing' AND column_name = 'detail'");
    if (!$has) {
        Align\DB::pdo()->exec('ALTER TABLE psa_billing ADD COLUMN detail VARCHAR(255) NULL AFTER invoices');
    }
};
