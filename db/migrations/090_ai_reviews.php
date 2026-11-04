<?php
declare(strict_types=1);

/**
 * 2.30.0 AI compliance reviews (Align\Ai\Compliance): one row per review a person started from a client's
 * Compliance page or one of its documents. It holds the chosen frameworks, the sources AI read (client documents by
 * id, and uploaded files), the uploaded files themselves while the review waits (files: in the upload folder under
 * ai-reviews/, random names), the facts AI read in them (already checked and cleaned), the batch plan (groups of
 * controls; controls with the same tags share one answer), AI's answers per group (matches) and how many batches
 * are done (step), and how the review ended (applied with a summary, or discarded). The uploaded files are deleted
 * when it's applied or discarded (kept ones become client documents first); reviews left for 14 days are discarded.
 * The person's name is kept too, so a row still says who started it after their account is removed.
 *
 * Only creates a table; checks first, so it can safely run again.
 */
return function (): void {
    if (Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ai_reviews'")) {
        return;
    }
    Align\DB::pdo()->exec("CREATE TABLE ai_reviews (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        status ENUM('review','applied','discarded') NOT NULL DEFAULT 'review',
        frameworks JSON NOT NULL,
        sources JSON NOT NULL,
        files JSON NOT NULL,
        facts JSON NOT NULL,
        `groups` JSON NOT NULL,
        matches JSON NOT NULL,
        step INT UNSIGNED NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        created_name VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        done_by INT UNSIGNED NULL,
        done_at DATETIME NULL,
        summary TEXT NULL,
        KEY idx_ai_reviews_client (client_id, status, created_at),
        CONSTRAINT fk_ai_reviews_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
        CONSTRAINT fk_ai_reviews_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_ai_reviews_doer FOREIGN KEY (done_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
