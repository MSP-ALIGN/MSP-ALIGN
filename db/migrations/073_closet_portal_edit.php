<?php
declare(strict_types=1);

/**
 * 2.15.1 Tech closet, kept up to date together: portal_users.can_closet_edit lets a client's portal user add items to
 * their own closet, change their details and counts, and remove (retire) them. Off for everyone until the MSP
 * turns it on per user; it needs Tech closet (can_closet), like the other portal actions need their section.
 *
 * Checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'portal_users' AND column_name = 'can_closet_edit'")) {
        Align\DB::pdo()->exec('ALTER TABLE portal_users ADD COLUMN can_closet_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER can_checkout');
    }
};
