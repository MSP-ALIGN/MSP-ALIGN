<?php
declare(strict_types=1);

/**
 * 2.20.0 Uploaded files as documents (PDF, Word, Excel, CSV, PNG, JPEG). A document is either a page written in
 * Align ('page', as before) or an uploaded file ('file'): the file's stored name (random, never from a request), its
 * original name, type, size and SHA-256. Each version in the history keeps its own file (kind 'upload'), so
 * replacing a file never loses the old one.
 *
 * Checks first, so the migration can safely run again.
 */
return function (): void {
    $has = fn(string $t, string $c) => (bool) Align\DB::value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
    $files = 'file_name VARCHAR(80) NULL, ADD COLUMN file_orig VARCHAR(190) NULL, ADD COLUMN file_type VARCHAR(10) NULL, '
        . 'ADD COLUMN file_size INT UNSIGNED NULL, ADD COLUMN file_hash CHAR(64) NULL';
    if (!$has('documents', 'kind')) {
        Align\DB::pdo()->exec("ALTER TABLE documents ADD COLUMN kind ENUM('page','file') NOT NULL DEFAULT 'page' AFTER category, ADD COLUMN $files");
    }
    if (!$has('document_versions', 'file_name')) {
        Align\DB::pdo()->exec("ALTER TABLE document_versions ADD COLUMN $files");
    }
    $type = (string) Align\DB::value("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'document_versions' AND column_name = 'kind'");
    if (!str_contains($type, "'upload'")) {
        Align\DB::pdo()->exec("ALTER TABLE document_versions MODIFY kind ENUM('auto','manual','restore','created','upload') NOT NULL DEFAULT 'auto'");
    }
};
