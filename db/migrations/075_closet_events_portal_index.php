<?php
declare(strict_types=1);

/**
 * 2.15.2 An index for the client portal's tech closet limits: each portal closet action counts the user's events in
 * the last hour (closet_events by portal_user_id and time), which otherwise reads the whole table.
 *
 * Checks first, so the migration can safely run again.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'closet_events' AND index_name = 'idx_ce_portal_at'")) {
        Align\DB::pdo()->exec('ALTER TABLE closet_events ADD KEY idx_ce_portal_at (portal_user_id, at)');
    }
};
