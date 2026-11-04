<?php
declare(strict_types=1);

/**
 * 2.29.0 AI document reading (Align\Ai\Documents): one row per upload a person sent for AI to read on a client's
 * Licensing or Vendors page. It holds what AI found (result: vendors and lines, already checked and cleaned), the
 * files' names and where they are kept until the review is done (files: in the upload folder under ai/, random
 * names), and how the review ended (applied with a summary of what changed, or discarded). The files are deleted
 * when it's applied or discarded (those that can be kept become client documents first). The person's name is kept
 * too, so a row still says who sent it after their account is removed.
 *
 * Only creates a table; checks first, so it can safely run again.
 */
return function (): void {
    if (Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ai_documents'")) {
        return;
    }
    Align\DB::pdo()->exec("CREATE TABLE ai_documents (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        status ENUM('review','applied','discarded') NOT NULL DEFAULT 'review',
        files JSON NOT NULL,
        result JSON NOT NULL,
        notes JSON NULL,
        created_by INT UNSIGNED NULL,
        created_name VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        done_by INT UNSIGNED NULL,
        done_at DATETIME NULL,
        summary TEXT NULL,
        KEY idx_ai_documents_client (client_id, status, created_at),
        CONSTRAINT fk_ai_documents_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
        CONSTRAINT fk_ai_documents_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_ai_documents_doer FOREIGN KEY (done_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
