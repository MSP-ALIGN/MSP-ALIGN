<?php
declare(strict_types=1);

/**
 * 2.25.0 Asset types:
 *  - device_types: types an admin adds (Settings → Planning & lifecycle; Align\Lifecycle\AssetTypes), each with the
 *    lifecycle category it plans under, an icon and whether it's virtual. Built-in types stay in code (Lifecycle::TYPES).
 *  - The new Monitors & displays category: its lifespan (6 years) and replacement cost (250) as settings, like the
 *    other categories, when they aren't there yet.
 *  - PSA assets: monitors used to come in under "everything else"; where that is ticked, the new Monitors & displays
 *    choice is ticked too, so they keep coming in, and devices still Unassigned whose PSA type says monitor, display,
 *    screen or TV become Monitors (the same whole words Itflow::mapType now reads, through Itflow::isMonitorType).
 *
 * It only reads Align's own tables and settings, and every value it writes is bound.
 *
 * Checks first, so the migration can safely run again.
 */
return function (): void {
    $has = fn(string $t) => (bool) Align\DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
    if (!$has('device_types')) {
        Align\DB::pdo()->exec("CREATE TABLE device_types (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(40) NOT NULL,
            class VARCHAR(20) NOT NULL,
            icon VARCHAR(40) NOT NULL DEFAULT 'fa-tag',
            is_virtual TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_device_types_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    foreach (['lifespan_display' => '6', 'cost_display' => '250'] as $k => $v) {
        Align\DB::run('INSERT IGNORE INTO settings (name, value) VALUES (?, ?)', [$k, $v]);
    }
    $import = Align\DB::value("SELECT value FROM settings WHERE name = 'psa_import_types'");
    if ($import !== null && $import !== false) {
        $list = array_filter(array_map('trim', explode(',', (string) $import)));
        if (in_array('other', $list, true) && !in_array('display', $list, true)) {
            $list[] = 'display';
            Align\DB::run("UPDATE settings SET value = ? WHERE name = 'psa_import_types'", [implode(',', $list)]);
        }
    }
    if ($has('psa_assets')) {
        Align\Lifecycle\Lifecycle::forgetTypes();
        // Candidates by SQL, then the exact words Itflow::isMonitorType reads (so "Monitoring appliance" stays as it is)
        $types = array_values(array_filter(array_map(fn($r) => (string) $r['type'], Align\DB::all("SELECT DISTINCT a.type FROM psa_assets a
            WHERE LOWER(a.type) LIKE '%monitor%' OR LOWER(a.type) LIKE '%display%' OR LOWER(a.type) LIKE '%screen%' OR LOWER(a.type) LIKE '%tv%' OR LOWER(a.type) LIKE '%television%'")),
            [Align\Integrations\Itflow::class, 'isMonitorType']));
        if ($types) {
            Align\Lifecycle\AssetTypes::sortUnassigned('Monitor', 'BINARY a.type IN (' . implode(',', array_fill(0, count($types), 'BINARY ?')) . ')', $types);
        }
    }
};
