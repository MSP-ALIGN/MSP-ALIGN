<?php
declare(strict_types=1);

/**
 * 2.23.0 Staff Outlook calendars, two-way (Calendar\Outlook):
 *  - user_calendars: each staff member who connected their Outlook calendar (their own sign-in to Align's email and
 *    calendars app), with their refresh token encrypted (Crypto) and the last sync's result.
 *  - calendar_events: their Outlook events from the last sync (a window around today), shown on Align's calendar.
 *    Private events keep only the time.
 *  - calendar_pushes: the copies of Align meetings Align put in the meeting owner's Outlook calendar, so they're kept
 *    up to date and removed when the meeting is cancelled, deleted or moves to another owner.
 * Deleting a user removes their rows (foreign keys); switching an account off disconnects it on the next sync (Outlook::syncAll).
 *
 * Checks first, so the migration can safely run again.
 */
return function (): void {
    $has = fn(string $t) => (bool) Align\DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
    $pdo = Align\DB::pdo();
    if (!$has('user_calendars')) {
        $pdo->exec("CREATE TABLE user_calendars (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            provider VARCHAR(20) NOT NULL DEFAULT 'microsoft',
            account VARCHAR(255) NOT NULL,
            account_name VARCHAR(255) NULL,
            token_enc TEXT NULL,
            status ENUM('connected','error') NOT NULL DEFAULT 'connected',
            error VARCHAR(300) NULL,
            connected_at DATETIME NOT NULL,
            synced_at DATETIME NULL,
            UNIQUE KEY uq_user_calendars_account (provider, account),
            CONSTRAINT fk_user_calendars_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!$has('calendar_events')) {
        $pdo->exec("CREATE TABLE calendar_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            event_key CHAR(40) NOT NULL,
            event_id VARCHAR(512) NOT NULL,
            meeting_id INT UNSIGNED NULL,
            subject VARCHAR(255) NULL,
            location VARCHAR(255) NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            all_day TINYINT(1) NOT NULL DEFAULT 0,
            show_as VARCHAR(20) NULL,
            private TINYINT(1) NOT NULL DEFAULT 0,
            organizer VARCHAR(255) NULL,
            attendees TEXT NULL,
            web_link VARCHAR(1000) NULL,
            synced_at DATETIME NOT NULL,
            UNIQUE KEY uq_calendar_events (user_id, event_key),
            KEY idx_calendar_events_time (starts_at, ends_at),
            CONSTRAINT fk_calendar_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!$has('calendar_pushes')) {
        $pdo->exec("CREATE TABLE calendar_pushes (
            meeting_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            event_id VARCHAR(512) NOT NULL,
            hash CHAR(40) NOT NULL,
            pushed_at DATETIME NOT NULL,
            PRIMARY KEY (meeting_id, user_id),
            KEY idx_calendar_pushes_user (user_id),
            CONSTRAINT fk_calendar_pushes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
