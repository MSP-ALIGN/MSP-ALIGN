<?php
declare(strict_types=1);

/**
 * 2.31.0 AI for business reviews and meetings (Align\Ai\Meetings):
 *  - qbr_summaries: the executive summary a person saved for a client's QBR pack ("Use in report"), one per client.
 *    Written with AI or by hand; plain text with a little Markdown, shown in the staff QBR pack only (never in the
 *    client portal). Who wrote and last changed it is kept by name too, so it still says so after an account goes.
 *  - ai_meeting_actions: what AI found in a meeting's notes (new roadmap items, decisions on the client's open items,
 *    a follow-up meeting), already checked and cleaned, waiting for a person to tick and apply it. How it ended
 *    (applied with a summary, or discarded) is kept; ones left for 14 days are discarded by the hourly sync.
 *
 * Only creates tables; checks each first, so it can safely run again.
 */
return function (): void {
    $has = fn(string $t) => (bool) Align\DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
    if (!$has('qbr_summaries')) {
        Align\DB::pdo()->exec("CREATE TABLE qbr_summaries (
            client_id INT UNSIGNED NOT NULL PRIMARY KEY,
            body TEXT NOT NULL,
            from_ai TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_name VARCHAR(190) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT UNSIGNED NULL,
            updated_name VARCHAR(190) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_qbr_summaries_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
            CONSTRAINT fk_qbr_summaries_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_qbr_summaries_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!$has('ai_meeting_actions')) {
        Align\DB::pdo()->exec("CREATE TABLE ai_meeting_actions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            status ENUM('review','applied','discarded') NOT NULL DEFAULT 'review',
            result JSON NOT NULL,
            created_by INT UNSIGNED NULL,
            created_name VARCHAR(190) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            done_by INT UNSIGNED NULL,
            done_at DATETIME NULL,
            summary TEXT NULL,
            KEY idx_ai_meeting_actions_meeting (meeting_id, status),
            KEY idx_ai_meeting_actions_status (status, created_at),
            CONSTRAINT fk_ai_meeting_actions_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id) ON DELETE CASCADE,
            CONSTRAINT fk_ai_meeting_actions_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
            CONSTRAINT fk_ai_meeting_actions_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_ai_meeting_actions_doer FOREIGN KEY (done_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
