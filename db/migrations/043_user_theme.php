<?php
declare(strict_types=1);

/** 1.43: each person picks light, dark or "match my computer" (auto) under Account. */
return function (): void {
    $has = (bool) Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'theme'");
    if (!$has) {
        Align\DB::pdo()->exec("ALTER TABLE users ADD COLUMN theme ENUM('auto','light','dark') NOT NULL DEFAULT 'auto' AFTER notify_scope");
    }
};
