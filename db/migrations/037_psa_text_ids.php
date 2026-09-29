<?php
declare(strict_types=1);

/**
 * 1.34: PSA ids are stored as text, as RMM ids already are (034). ITFlow uses numbers, but other PSAs use
 * GUIDs or other text ids. Existing numbers are kept as they are (123 becomes '123'); indexes and keys stay.
 *
 * Each column is only changed while it is still a number, so the migration can safely run again.
 */
return function (): void {
    $db = Align\DB::pdo();
    $type = fn(string $table, string $col): ?string => Align\DB::value(
        'SELECT data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $col]);

    $columns = [
        ['clients', 'psa_id', 'NULL'],
        ['contacts', 'psa_id', 'NULL'],
        ['licenses', 'psa_id', 'NULL'],
        ['devices', 'psa_asset_id', 'NULL'],
        ['psa_assets', 'psa_asset_id', 'NOT NULL'],
        ['psa_assets', 'psa_client_id', 'NOT NULL'],
        ['psa_assets', 'location_id', 'NULL'],
        ['psa_tickets', 'id', 'NOT NULL'],
        ['psa_tickets', 'psa_client_id', 'NOT NULL'],
        ['service_requests', 'psa_ticket_id', 'NULL'],
    ];
    foreach ($columns as [$table, $col, $null]) {
        $t = $type($table, $col);
        if ($t !== null && $t !== 'varchar') {
            $db->exec("ALTER TABLE `$table` MODIFY `$col` VARCHAR(64) $null");
        }
    }
};
