<?php
declare(strict_types=1);

/**
 * 2.26.0 "Doesn't need backups": on Client mapping (and a client's Connectors page) a client can be set to not need
 * backups in a backup product. Stored like "kept unlinked" (client_links with no outside record) with its own
 * match_method, so sync never links it by name and nothing counts it as missing a backup.
 *
 * Only changes a column's list of allowed values; checks first, so it can safely run again.
 */
return function (): void {
    $type = (string) Align\DB::value("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE()
        AND table_name = 'client_links' AND column_name = 'match_method'");
    if ($type !== '' && !str_contains($type, "'not_needed'")) {
        Align\DB::pdo()->exec("ALTER TABLE client_links MODIFY match_method ENUM('auto','manual','not_needed') NULL");
    }
};
