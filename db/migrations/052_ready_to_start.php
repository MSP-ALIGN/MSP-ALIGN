<?php
declare(strict_types=1);

/**
 * 2.2.2 "Ready to start": a project's QUOTE- ticket is made when someone says the project is ready (or when the
 * project is made, if they ask), not ahead of time. roadmap_items gains:
 * - ticket_at, ticket_by: when and by whom the ticket was made (psa_ticket_id already holds its number);
 * - ticket_claimed_at: set while a ticket is being made, so two clicks at once make one ticket (a claim older than
 *   10 minutes is from a request that died and may be taken again);
 * - ticket_snooze_until: "Not yet" on To do hides the project until this day;
 * - ticket_error, ticket_error_at: why the last try failed (shown on To do until the next try works).
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $cols = [
        'ticket_at' => 'DATETIME NULL',
        'ticket_by' => 'INT UNSIGNED NULL',
        'ticket_claimed_at' => 'DATETIME NULL',
        'ticket_snooze_until' => 'DATE NULL',
        'ticket_error' => 'VARCHAR(300) NULL',
        'ticket_error_at' => 'DATETIME NULL',
    ];
    foreach ($cols as $c => $def) {
        if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'roadmap_items' AND column_name = ?", [$c])) {
            $db->exec("ALTER TABLE roadmap_items ADD COLUMN $c $def");
        }
    }
};
