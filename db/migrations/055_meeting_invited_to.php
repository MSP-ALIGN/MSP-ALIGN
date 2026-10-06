<?php
declare(strict_types=1);

/**
 * 2.2.6: meetings remember who the last invitation went to (invited_to, a JSON list of addresses), so that in .ics
 * mode someone taken off the attendee list gets a cancellation instead of keeping a meeting that no longer includes
 * them. Calendar mode (Outlook or Google) already does this itself; the list is kept there too, so a later switch
 * to .ics mode still knows who was invited. Empty for meetings invited before 2.2.6: they start remembering on their
 * next invitation.
 *
 * The step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'meetings' AND column_name = 'invited_to'")) {
        Align\DB::pdo()->exec('ALTER TABLE meetings ADD COLUMN invited_to TEXT NULL AFTER invites_sent_at');
    }
};
