<?php
declare(strict_types=1);

/**
 * 1.40: the setup wizard opens by itself only on a new install. An install that already has clients,
 * a sync or more than one staff account is marked as set up, so an update never sends anyone to it
 * (admins can still open it from Settings → General or Help).
 */
return function (): void {
    if (Align\DB::value("SELECT 1 FROM settings WHERE name = 'setup_state'")) {
        return;
    }
    $used = (int) Align\DB::value('SELECT COUNT(*) FROM clients') > 0
        || (int) Align\DB::value('SELECT COUNT(*) FROM sync_runs') > 0
        || (int) Align\DB::value('SELECT COUNT(*) FROM users') > 1;
    Align\DB::run("INSERT INTO settings (name, value, is_secret) VALUES ('setup_state', ?, 0)", [$used ? 'done' : 'pending']);
};
