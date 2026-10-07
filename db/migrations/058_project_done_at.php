<?php
declare(strict_types=1);

/**
 * 2.4.0: projects remember when they were marked done (roadmap_items.done_at) and when their status last changed
 * (status_changed_at, with status_seen = the status it changed to), so "What changed since the last QBR" can list
 * projects finished, approved and declined since then, whoever changed them. Projects already done get their
 * last-changed time as the best guess for done_at; earlier status changes stay unknown (null), apart from client
 * decisions in the portal (decided_at). Roadmap::stampStatus() keeps them current. updated_at is left as it was.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $has = fn(string $col) => (bool) Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'roadmap_items' AND column_name = ?", [$col]);
    if (!$has('done_at')) {
        Align\DB::pdo()->exec('ALTER TABLE roadmap_items ADD COLUMN done_at DATETIME NULL AFTER started_by');
    }
    if (!$has('status_changed_at')) {
        Align\DB::pdo()->exec('ALTER TABLE roadmap_items ADD COLUMN status_changed_at DATETIME NULL AFTER done_at, ADD COLUMN status_seen VARCHAR(20) NULL AFTER status_changed_at');
    }
    Align\DB::run("UPDATE roadmap_items SET done_at = COALESCE(updated_at, created_at), updated_at = updated_at WHERE status = 'done' AND done_at IS NULL");
    Align\DB::run("UPDATE roadmap_items SET status_seen = status, status_changed_at = IF(status IN ('approved','declined'), decided_at, NULL), updated_at = updated_at WHERE status_seen IS NULL");
};
