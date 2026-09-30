<?php
declare(strict_types=1);

/** 1.45: onboarding links stop working 7 days after onboarding is complete, including onboardings completed earlier. */
return function (): void {
    Align\DB::run('UPDATE client_onboardings SET token_expires_at = LEAST(token_expires_at, GREATEST(NOW(), completed_at + INTERVAL 7 DAY))
        WHERE completed_at IS NOT NULL AND token_expires_at IS NOT NULL');
};
