<?php
declare(strict_types=1);

/**
 * 2.27.0 Feedback: bug reports and feature requests sent by staff from the top bar (Align\Feedback\Feedback).
 * One row per report: what kind, a title and description, the page it was sent from, a priority, its status (open,
 * planned, done, won't do), an admin's note to the sender, and an optional screenshot kept in the upload folder
 * (uploads/feedback) under a random name. The sender's name is kept too, so a report still says who sent it after
 * their account is removed.
 *
 * Only creates a table; checks first, so it can safely run again.
 */
return function (): void {
    if (Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'feedback'")) {
        return;
    }
    Align\DB::pdo()->exec("CREATE TABLE feedback (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kind ENUM('bug','feature') NOT NULL,
        title VARCHAR(150) NOT NULL,
        body TEXT NULL,
        page VARCHAR(500) NULL,
        priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
        status ENUM('open','planned','done','declined') NOT NULL DEFAULT 'open',
        admin_note TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_name VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        closed_at DATETIME NULL,
        closed_by INT UNSIGNED NULL,
        file_name VARCHAR(64) NULL,
        file_type VARCHAR(8) NULL,
        file_size INT UNSIGNED NULL,
        KEY idx_feedback_status (status, created_at),
        KEY idx_feedback_creator (created_by, created_at),
        CONSTRAINT fk_feedback_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_feedback_closer FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
