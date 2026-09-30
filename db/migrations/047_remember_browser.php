<?php
declare(strict_types=1);

/**
 * 1.45.1: "Remember this browser" skips the two-factor code (never the password) on that browser for a number of
 * days (Settings → General → Security, 14 by default, 0 = off). Only a SHA-256 hash of the cookie is stored; a row
 * stops working when the person's session_version changes (password or 2FA change, sign out everywhere, disabled).
 */
return function (): void {
    Align\DB::pdo()->exec("CREATE TABLE IF NOT EXISTS remembered_browsers (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kind ENUM('staff','portal') NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        session_version INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        UNIQUE KEY uq_remember_token (token_hash),
        KEY idx_remember_user (kind, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Align\DB::run("INSERT IGNORE INTO settings (name, value, is_secret) VALUES ('remember_2fa_days', '14', 0)");
};
