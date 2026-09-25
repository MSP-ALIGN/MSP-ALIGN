<?php
// 1.5.0 security hardening: session revocation, TOTP replay protection, forced password change,
// hashed calendar tokens, tamper-evident (hash-chained) audit log.
use Align\DB;

return function (): void {
    $pdo = DB::pdo();
    $pdo->exec('ALTER TABLE users
        ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0,
        ADD COLUMN totp_last_step BIGINT UNSIGNED NULL,
        ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN password_changed_at DATETIME NULL,
        ADD COLUMN ics_created_at DATETIME NULL');
    $pdo->exec('ALTER TABLE portal_users
        ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0,
        ADD COLUMN totp_last_step BIGINT UNSIGNED NULL,
        ADD COLUMN password_changed_at DATETIME NULL');
    // Calendar feed tokens: keep only a SHA-256 hash (existing subscriptions keep working)
    $pdo->exec('ALTER TABLE users MODIFY ics_token VARCHAR(64) NULL');
    $pdo->exec("UPDATE users SET ics_token = SHA2(ics_token, 256), ics_created_at = NOW() WHERE ics_token IS NOT NULL AND LENGTH(ics_token) <> 64");

    // Hash-chained audit log
    $pdo->exec('ALTER TABLE audit_log ADD COLUMN prev_hash CHAR(64) NULL, ADD COLUMN row_hash CHAR(64) NULL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS audit_chain (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_hash CHAR(64) NOT NULL DEFAULT \'\',
        anchor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        anchor_hash CHAR(64) NOT NULL DEFAULT \'\'
    ) ENGINE=InnoDB');
    $pdo->exec("INSERT IGNORE INTO audit_chain (id) VALUES (1)");
    \Align\AuditChain::backfill();
};
