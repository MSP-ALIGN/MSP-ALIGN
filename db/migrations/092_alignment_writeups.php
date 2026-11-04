<?php
declare(strict_types=1);

/**
 * 2.32.0 Alignment write-ups (Align\Ai\Alignment\WriteUp): the recommendations a person saved for one finished
 * alignment review, written with AI or by hand. One per review, removed with the review (and with the client).
 * Plain text with a little Markdown; shown on the review page and in the staff QBR pack's Alignment section, never
 * in the client portal. Who wrote and last changed it is kept by name too, so it still says so after an account goes.
 *
 * Only creates the table; checks first, so it can safely run again.
 */
return function (): void {
    $has = (bool) Align\DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['alignment_writeups']);
    if (!$has) {
        Align\DB::pdo()->exec("CREATE TABLE alignment_writeups (
            review_id INT UNSIGNED NOT NULL PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            from_ai TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_name VARCHAR(190) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT UNSIGNED NULL,
            updated_name VARCHAR(190) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_alignment_writeups_client (client_id),
            CONSTRAINT fk_alignment_writeups_review FOREIGN KEY (review_id) REFERENCES alignment_reviews (id) ON DELETE CASCADE,
            CONSTRAINT fk_alignment_writeups_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
            CONSTRAINT fk_alignment_writeups_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_alignment_writeups_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
