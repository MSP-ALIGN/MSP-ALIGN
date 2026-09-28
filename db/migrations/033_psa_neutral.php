<?php
declare(strict_types=1);

/**
 * 1.28: PSA data becomes provider-neutral. ITFlow is now one PSA provider, so ITFlow-named tables,
 * columns, source values and behavior settings get neutral names (psa_*). Connection settings
 * (itflow_url, itflow_api_key) stay with the ITFlow connector.
 *
 * DDL can't run in a transaction, so every step checks first and the migration can safely run again
 * after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $has = fn(string $table, ?string $col = null): bool => $col === null
        ? (bool) Align\DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])
        : (bool) Align\DB::value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $col]);
    $hasIndex = fn(string $table, string $index): bool
        => (bool) Align\DB::value('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $index]);
    $renameTable = function (string $from, string $to) use ($db, $has): void {
        if ($has($from) && !$has($to)) {
            $db->exec("RENAME TABLE `$from` TO `$to`");
        }
    };
    $renameCol = function (string $table, string $from, string $to) use ($db, $has): void {
        if ($has($table, $from) && !$has($table, $to)) {
            $db->exec("ALTER TABLE `$table` RENAME COLUMN `$from` TO `$to`");
        }
    };
    $renameIndex = function (string $table, string $from, string $to) use ($db, $hasIndex): void {
        if ($hasIndex($table, $from) && !$hasIndex($table, $to)) {
            $db->exec("ALTER TABLE `$table` RENAME INDEX `$from` TO `$to`");
        }
    };
    /** Replaces one value of an ENUM column: widen, update rows, narrow. */
    $enumSwap = function (string $table, string $col, array $final, string $old, string $new, ?string $default, bool $null) use ($db): void {
        $type = (string) Align\DB::value('SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $col]);
        $q = fn(array $vals) => 'ENUM(' . implode(',', array_map(fn($v) => "'$v'", $vals)) . ')';
        $tail = ($null ? ' NULL' : ' NOT NULL') . ($default !== null ? " DEFAULT '$default'" : ($null ? ' DEFAULT NULL' : ''));
        if (str_contains($type, "'$old'")) {
            $wide = array_values(array_unique([...$final, $old]));
            $db->exec("ALTER TABLE `$table` MODIFY `$col` " . $q($wide) . ($null ? ' NULL' : ' NOT NULL'));
            $db->exec("UPDATE `$table` SET `$col` = '$new' WHERE `$col` = '$old'");
        }
        $db->exec("ALTER TABLE `$table` MODIFY `$col` " . $q($final) . $tail);
    };

    // Tables
    $renameTable('itflow_assets', 'psa_assets');
    $renameTable('itflow_tickets', 'psa_tickets');
    $renameTable('itflow_billing', 'psa_billing');
    $renameTable('itflow_sync_state', 'psa_sync_state');
    $renameTable('itflow_poll_state', 'psa_poll_state');

    // Clients
    $enumSwap('clients', 'source', ['psa', 'manual'], 'itflow', 'psa', 'psa', false);
    $renameCol('clients', 'itflow_client_id', 'psa_id');
    if ($has('clients', 'itflow_fields')) {
        $db->exec("ALTER TABLE clients CHANGE itflow_fields psa_fields VARCHAR(255) NULL COMMENT 'fields last filled from the PSA (read-only in Align)'");
    }
    $renameIndex('clients', 'uq_clients_itflow', 'uq_clients_psa');

    // Contacts
    $enumSwap('contacts', 'source', ['psa', 'manual'], 'itflow', 'psa', 'manual', false);
    $enumSwap('contacts', 'archived_reason', ['align', 'psa'], 'itflow', 'psa', null, true);
    $renameCol('contacts', 'itflow_contact_id', 'psa_id');
    $renameCol('contacts', 'itflow_notes', 'psa_notes');
    $renameIndex('contacts', 'uq_contact_itflow', 'uq_contact_psa');

    // Devices (the NinjaOne source value is renamed with the RMM layer)
    $enumSwap('devices', 'source', ['ninja', 'manual', 'psa'], 'itflow', 'psa', 'ninja', false);
    $renameCol('devices', 'itflow_asset_id', 'psa_asset_id');
    $renameCol('devices', 'itflow_sync', 'psa_sync');
    $renameIndex('devices', 'idx_devices_itflow', 'idx_devices_psa');

    // Device sync history
    $dir = (string) Align\DB::value("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'device_changes' AND column_name = 'direction'");
    if (str_contains($dir, 'itflow')) {
        $db->exec("ALTER TABLE device_changes MODIFY direction ENUM('to_itflow','from_itflow','to_psa','from_psa','created') NOT NULL");
        $db->exec("UPDATE device_changes SET direction = 'to_psa' WHERE direction = 'to_itflow'");
        $db->exec("UPDATE device_changes SET direction = 'from_psa' WHERE direction = 'from_itflow'");
    }
    $db->exec("ALTER TABLE device_changes MODIFY direction ENUM('to_psa','from_psa','created') NOT NULL");

    // PSA asset cache
    $renameCol('psa_assets', 'itflow_asset_id', 'psa_asset_id');
    $renameCol('psa_assets', 'itflow_client_id', 'psa_client_id');
    $renameIndex('psa_assets', 'idx_ita_client', 'idx_psa_assets_client');
    $renameIndex('psa_assets', 'idx_ita_serial', 'idx_psa_assets_serial');

    // Tickets
    $renameCol('psa_tickets', 'itflow_client_id', 'psa_client_id');
    $renameIndex('psa_tickets', 'idx_itt_client', 'idx_psa_tickets_client');
    $renameIndex('psa_tickets', 'idx_itt_itclient', 'idx_psa_tickets_psaclient');
    $renameIndex('psa_tickets', 'idx_itt_open', 'idx_psa_tickets_open');
    $renameIndex('psa_tickets', 'idx_itt_created', 'idx_psa_tickets_created');

    // Licenses
    $enumSwap('licenses', 'source', ['psa', 'manual'], 'itflow', 'psa', 'manual', false);
    $enumSwap('licenses', 'retired_reason', ['align', 'psa'], 'itflow', 'psa', null, true);
    $renameCol('licenses', 'itflow_software_id', 'psa_id');
    $renameIndex('licenses', 'uq_license_itflow', 'uq_license_psa');

    // Client requests
    $renameCol('service_requests', 'itflow_ticket_id', 'psa_ticket_id');
    $db->exec("UPDATE service_requests SET delivery = 'psa' WHERE delivery = 'itflow'");

    // Behavior settings become PSA settings; the connection stays with the ITFlow connector
    foreach (['two_way', 'create_assets', 'import_types', 'sla_sync', 'sla_supported', 'tickets_state', 'writeback'] as $s) {
        if (!Align\DB::value('SELECT 1 FROM settings WHERE name = ?', ["psa_$s"])) {
            $db->prepare('UPDATE settings SET name = ? WHERE name = ?')->execute(["psa_$s", "itflow_$s"]);
        } else {
            $db->prepare('DELETE FROM settings WHERE name = ?')->execute(["itflow_$s"]);
        }
    }
    // ITFlow is the PSA if it's set up (or if clients came from it before)
    $itflow = (string) Align\DB::value("SELECT value FROM settings WHERE name = 'itflow_url'") !== ''
        || (bool) Align\DB::value("SELECT 1 FROM clients WHERE psa_id IS NOT NULL LIMIT 1");
    $db->prepare("INSERT IGNORE INTO settings (name, value) VALUES ('psa_provider', ?)")->execute([$itflow ? 'itflow' : '']);
};
