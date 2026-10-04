<?php
declare(strict_types=1);

/**
 * 2.2.2 Ready to start without a ticket (no PSA, or a client not linked to it): pressing Ready to start marks the
 * project started. roadmap_items gains started_at and started_by: when and by whom Ready to start was first pressed,
 * whether it made the ticket or only marked the project started. They are kept apart from ticket_at / ticket_by (when
 * and by whom the ticket was made), so a ticket made later doesn't overwrite who started the project and when.
 *
 * Projects whose ticket Ready to start already made (ticket_at set, from 052) are copied over, so they count as
 * started too. Pretend tickets from a test server (TEST-…) aren't: nothing real happened.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    foreach (['started_at' => 'DATETIME NULL', 'started_by' => 'INT UNSIGNED NULL'] as $c => $def) {
        if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'roadmap_items' AND column_name = ?", [$c])) {
            $db->exec("ALTER TABLE roadmap_items ADD COLUMN $c $def");
        }
    }
    $db->exec("UPDATE roadmap_items SET started_at = ticket_at, started_by = ticket_by
        WHERE started_at IS NULL AND ticket_at IS NOT NULL AND psa_ticket_id IS NOT NULL AND psa_ticket_id NOT LIKE 'TEST-%'");
};
