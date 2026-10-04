<?php
declare(strict_types=1);

/**
 * 2.2.1 security counters:
 * - users.totp_failures / portal_users.totp_failures: wrong two-factor codes in a row. A code is only asked for after
 *   the correct password, so a high count means the password is known to someone guessing codes: Security::
 *   secondFactorFailed() alerts admins early and replaces the password after 50 in a row.
 * - client_onboardings.contact_saves / contact_saves_day: contact saves from the (sign-in free) onboarding page today,
 *   capped by WelcomeController::contacts() at Onboarding::CONTACT_SAVES_PER_DAY.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $has = fn(string $t, string $c) => (bool) Align\DB::value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
    foreach (['users', 'portal_users'] as $t) {
        if (!$has($t, 'totp_failures')) {
            $db->exec("ALTER TABLE $t ADD COLUMN totp_failures INT UNSIGNED NOT NULL DEFAULT 0");
        }
    }
    if (!$has('client_onboardings', 'contact_saves')) {
        $db->exec('ALTER TABLE client_onboardings ADD COLUMN contact_saves SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN contact_saves_day DATE NULL');
    }
};
