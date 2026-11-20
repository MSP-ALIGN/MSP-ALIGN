<?php
declare(strict_types=1);

/**
 * 2.38.1 Update with AI keeps one draft per document: documents.ai_source_id is the document an AI draft copy was
 * written from (an Active page or an uploaded file, which AI never changes itself). The next update of that document
 * goes into its copy while the copy is still a Draft, instead of adding another one. NULL for every other document.
 * No foreign key: a deleted source only means the copy is no longer found as its draft (it stays a normal document).
 *
 * Checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'documents' AND column_name = 'ai_source_id'")) {
        Align\DB::pdo()->exec('ALTER TABLE documents ADD COLUMN ai_source_id INT UNSIGNED NULL AFTER template_id, ADD KEY idx_docs_ai_source (ai_source_id)');
    }
};
