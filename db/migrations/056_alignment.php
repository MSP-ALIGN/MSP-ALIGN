<?php
declare(strict_types=1);

/**
 * 2.3.0 Alignment reviews: the MSP's own standards (alignment_categories, alignment_standards), reviews of a client
 * against them (alignment_reviews, alignment_answers), a link from a roadmap project to the standard it fixes
 * (roadmap_items.alignment_standard_id) and quiet snapshots of a client's numbers at each completed QBR
 * (qbr_snapshots, for the later "what changed since the last review" pages).
 *
 * The starter standards (Align\Alignment\Starter) are added once, when there are no standards yet, so an install
 * that deleted them doesn't get them back on a re-run. Each answer keeps the standard's title and priority as they
 * were when the review was finished, so an edited or removed standard doesn't change an old review's score.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS alignment_categories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        section VARCHAR(120) NULL,
        sort INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS alignment_standards (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        category_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        why TEXT NULL,
        how TEXT NULL,
        priority ENUM('critical','high','medium','low') NOT NULL DEFAULT 'medium',
        auto_check VARCHAR(40) NULL,
        tags VARCHAR(500) NULL,
        fix_title VARCHAR(255) NULL,
        fix_category VARCHAR(40) NULL,
        fix_cost DECIMAL(12,2) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_as_cat (category_id, sort),
        CONSTRAINT fk_as_cat FOREIGN KEY (category_id) REFERENCES alignment_categories (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS alignment_reviews (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        status ENUM('draft','done') NOT NULL DEFAULT 'draft',
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_by INT UNSIGNED NULL,
        finished_at DATETIME NULL,
        finished_by INT UNSIGNED NULL,
        score TINYINT UNSIGNED NULL,
        aligned SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        misaligned SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        na SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        unanswered SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        KEY idx_ar_client (client_id, status, finished_at),
        CONSTRAINT fk_ar_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS alignment_answers (
        review_id INT UNSIGNED NOT NULL,
        standard_id INT UNSIGNED NOT NULL,
        answer ENUM('aligned','misaligned','na') NULL,
        note TEXT NULL,
        title VARCHAR(255) NULL,
        priority ENUM('critical','high','medium','low') NULL,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (review_id, standard_id),
        KEY idx_aa_std (standard_id),
        CONSTRAINT fk_aa_review FOREIGN KEY (review_id) REFERENCES alignment_reviews (id) ON DELETE CASCADE,
        CONSTRAINT fk_aa_std FOREIGN KEY (standard_id) REFERENCES alignment_standards (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS qbr_snapshots (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        meeting_id INT UNSIGNED NULL,
        taken_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        data MEDIUMTEXT NOT NULL,
        KEY idx_qs_client (client_id, taken_at),
        CONSTRAINT fk_qs_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'roadmap_items' AND column_name = 'alignment_standard_id'")) {
        $db->exec('ALTER TABLE roadmap_items ADD COLUMN alignment_standard_id INT UNSIGNED NULL, ADD KEY idx_ri_std (client_id, alignment_standard_id)');
    }
    // The starter standards, once
    if (!Align\DB::value('SELECT 1 FROM alignment_standards LIMIT 1') && !Align\DB::value('SELECT 1 FROM alignment_categories LIMIT 1')) {
        Align\Alignment\Standards::addStarter();
    }
};
