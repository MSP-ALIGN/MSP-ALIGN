<?php
declare(strict_types=1);

/**
 * 2.39.0 A client's document facts (Docs\Facts): the people its documents name again and again (security lead,
 * privacy official, executive sponsor, ...), entered once and used to fill the matching "[name, title]" blanks in its
 * documents. One row per client and fact; the fact keys are Docs\Facts::FIELDS. Values are plain text (150 characters
 * at most, checked by Facts::clean). Removed with the client.
 *
 * Checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'client_document_facts'")) {
        Align\DB::pdo()->exec("CREATE TABLE client_document_facts (
            client_id INT UNSIGNED NOT NULL,
            fact VARCHAR(40) NOT NULL,
            value VARCHAR(255) NOT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (client_id, fact),
            CONSTRAINT fk_doc_facts_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
