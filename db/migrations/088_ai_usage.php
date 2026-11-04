<?php
declare(strict_types=1);

/**
 * 2.29.0 AI: one row per request sent to the AI provider set up under Integrations → AI (Align\Ai\Ai). A row is
 * written as "pending" with the tokens it may use (reserved) before the request goes out, so the monthly limit holds
 * when several people use AI at once, then updated with the tokens the provider reports (input and output) and
 * whether it worked. The monthly total and the usage tables on the AI page are summed from these rows. The person's
 * name is kept too, so a row still says who used it after their account is removed. No prompt or answer is stored.
 *
 * Only creates a table; checks first, so it can safely run again.
 */
return function (): void {
    if (Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ai_usage'")) {
        return;
    }
    Align\DB::pdo()->exec("CREATE TABLE ai_usage (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        month CHAR(7) NOT NULL,
        user_id INT UNSIGNED NULL,
        user_name VARCHAR(190) NOT NULL DEFAULT '',
        feature VARCHAR(40) NOT NULL,
        provider VARCHAR(20) NOT NULL,
        model VARCHAR(120) NOT NULL DEFAULT '',
        status ENUM('pending','ok','error') NOT NULL DEFAULT 'pending',
        reserved INT UNSIGNED NOT NULL DEFAULT 0,
        input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        error VARCHAR(300) NULL,
        finished_at DATETIME NULL,
        KEY idx_ai_usage_month (month, status),
        KEY idx_ai_usage_user (user_id, created_at),
        CONSTRAINT fk_ai_usage_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
