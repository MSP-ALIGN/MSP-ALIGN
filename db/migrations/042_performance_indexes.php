<?php
declare(strict_types=1);

/**
 * 1.42: indexes for the counts that run on every page (the Unassigned hardware badge and the email
 * status in the menu), which scanned every device and every queued email. Each is added only if missing.
 */
return function (): void {
    $add = function (string $table, string $name, string $cols): void {
        $has = (bool) Align\DB::value('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $name]);
        if (!$has) {
            Align\DB::pdo()->exec("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
        }
    };
    $add('devices', 'idx_devices_type', 'device_type, removed_at');
    $add('device_overrides', 'idx_overrides_type', 'device_type');
    $add('mail_queue', 'idx_mail_sent', 'status, sent_at');
};
