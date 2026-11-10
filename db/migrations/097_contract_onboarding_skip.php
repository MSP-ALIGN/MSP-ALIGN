<?php
declare(strict_types=1);

/**
 * 2.35.2 "Not needed" on Onboarding → New clients: contracts.onboarding_skipped_at / onboarding_skipped_by mark a
 * signed contract whose client doesn't need onboarding (an existing client's renewal), so it leaves "Signed, ready to
 * onboard". Kept on the contract itself rather than its signing trail, which prints on the certificate. Undone by
 * clearing both.
 *
 * Checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contracts' AND column_name = 'onboarding_skipped_at'")) {
        Align\DB::pdo()->exec('ALTER TABLE contracts ADD COLUMN onboarding_skipped_at DATETIME NULL AFTER completed_at, ADD COLUMN onboarding_skipped_by INT UNSIGNED NULL AFTER onboarding_skipped_at');
    }
};
