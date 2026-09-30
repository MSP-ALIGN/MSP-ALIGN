<?php
declare(strict_types=1);

/**
 * 1.45: the audit chain's start (anchor) and end (head) markers are sealed with an HMAC too, so cutting entries
 * off either end and moving the markers to match is detected. The markers are sealed as they stand now.
 */
return function (): void {
    $has = (bool) Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'audit_chain' AND column_name = 'seal'");
    if (!$has) {
        Align\DB::pdo()->exec("ALTER TABLE audit_chain ADD COLUMN seal CHAR(64) NULL");
    }
    Align\DB::run('INSERT IGNORE INTO audit_chain (id) VALUES (1)');
    Align\AuditChain::reseal();
};
