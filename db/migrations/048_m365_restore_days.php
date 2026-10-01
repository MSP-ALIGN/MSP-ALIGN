<?php
declare(strict_types=1);

/**
 * 2.0.1: Microsoft 365 backups kept in more than one repository (a legacy one and a current one) are one object,
 * and Align counts the days with a backup for each user, group, team and site.
 *   repositories  how many repositories hold restore points for the object
 *   days_from     the day Align started counting (its first sync of the object after this update)
 *   restore_days  days with at least one restore point since days_from, all repositories together
 * backup_m365_days holds one row per object per day with a restore point.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $has = fn(string $col): bool => (bool) Align\DB::value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = ? AND column_name = ?', ['backup_m365_objects', $col]);
    foreach ([
        'repositories' => 'TINYINT UNSIGNED NULL AFTER licensed',
        'days_from' => 'DATE NULL AFTER repositories',
        'restore_days' => 'INT UNSIGNED NULL AFTER days_from',
    ] as $col => $def) {
        if (!$has($col)) {
            $db->exec("ALTER TABLE backup_m365_objects ADD COLUMN $col $def");
        }
    }
    $db->exec("CREATE TABLE IF NOT EXISTS backup_m365_days (
        object_uid VARCHAR(190) NOT NULL,
        day DATE NOT NULL,
        PRIMARY KEY (object_uid, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
