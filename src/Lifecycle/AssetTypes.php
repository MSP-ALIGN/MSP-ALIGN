<?php
declare(strict_types=1);

namespace Align\Lifecycle;

use Align\DB;

/**
 * 2.25.0 Asset types an admin adds (Settings → Planning & lifecycle) next to the built-in ones in Lifecycle::TYPES:
 * a name, the lifecycle category it plans under (lifespan and replacement cost), an icon from a fixed list and whether
 * it's virtual (operating-system support only, no hardware end of life). Renaming one renames it on every device;
 * removing one sends its devices back to the type they'd have without it (Unassigned for PSA assets, to sort again).
 *
 * Security assumptions: callers check the admin role and CSRF. Names are plain text (control characters removed, at most
 * 40 characters, the column's size) and can't take a built-in name or another custom name (case-insensitive); the
 * category must be a key of Lifecycle::CLASSES and the icon one of ICONS, so nothing typed reaches a class attribute.
 * Every query binds its values.
 */
final class AssetTypes
{
    /** Icons an admin can pick: Font Awesome class => what it shows. */
    public const ICONS = [
        'fa-tag' => 'Tag', 'fa-tv' => 'Monitor / TV', 'fa-display' => 'Display', 'fa-tablet-screen-button' => 'Tablet',
        'fa-mobile-screen' => 'Mobile', 'fa-phone' => 'Desk phone', 'fa-headset' => 'Headset', 'fa-keyboard' => 'Keyboard',
        'fa-computer-mouse' => 'Mouse', 'fa-camera' => 'Camera', 'fa-video' => 'Video', 'fa-microphone' => 'Microphone',
        'fa-print' => 'Printer', 'fa-fax' => 'Fax', 'fa-barcode' => 'Scanner', 'fa-credit-card' => 'Card reader',
        'fa-cash-register' => 'Point of sale', 'fa-hard-drive' => 'Drive', 'fa-server' => 'Server', 'fa-network-wired' => 'Network',
        'fa-wifi' => 'Wireless', 'fa-tower-broadcast' => 'Radio', 'fa-plug' => 'Power', 'fa-door-closed' => 'Door / access',
        'fa-temperature-half' => 'Sensor', 'fa-house-signal' => 'Smart device', 'fa-microchip' => 'Appliance', 'fa-stethoscope' => 'Medical device',
        'fa-tooth' => 'Dental device', 'fa-x-ray' => 'Imaging', 'fa-flask' => 'Lab device', 'fa-cubes' => 'Other kit',
    ];

    /** Longest name kept (the device_type columns are 40 characters). */
    public const MAX_NAME = 40;

    /** Custom types for Lifecycle::types(): name => [class, icon, virtual], by name. */
    public static function custom(): array
    {
        $out = [];
        try {
            foreach (DB::all('SELECT name, class, icon, is_virtual FROM device_types ORDER BY name') as $r) {
                if (isset(\Align\Lifecycle\Lifecycle::CLASSES[$r['class']])) {
                    $out[(string) $r['name']] = [(string) $r['class'], isset(self::ICONS[$r['icon']]) ? (string) $r['icon'] : 'fa-tag', (bool) $r['is_virtual']];
                }
            }
        } catch (\PDOException) {
            // before migration 084 has run (an update in progress): only the built-in types
        }
        return $out;
    }

    /** Every custom type row with how many devices use it (Settings page). */
    public static function all(): array
    {
        $rows = DB::all('SELECT * FROM device_types ORDER BY name');
        foreach ($rows as &$r) {
            $r['devices'] = self::usage((string) $r['name']);
        }
        unset($r);
        return $rows;
    }

    /** Devices (not removed) that have $name as their type, from a sync or set by hand. */
    public static function usage(string $name): int
    {
        // BINARY: exactly this name, never a built-in one the table's accent-blind collation counts as equal
        return (int) DB::value('SELECT COUNT(*) FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
            WHERE d.removed_at IS NULL AND BINARY COALESCE(NULLIF(o.device_type, \'\'), d.device_type) = BINARY ?', [$name]);
    }

    /** $name tidied: one line, no control or invisible formatting characters (zero-width, bidi), spaces collapsed. */
    public static function clean(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name);
        $name = (string) preg_replace('/\p{Cf}/u', '', $name);
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * What's wrong with these values, or null. $exceptId: the custom type being edited (its own name is fine).
     * $name is already clean().
     */
    public static function problem(string $name, string $class, string $icon, ?int $exceptId = null): ?string
    {
        if ($name === '') {
            return 'Give the type a name.';
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            return 'Keep the name to ' . self::MAX_NAME . ' characters.';
        }
        if (preg_match('/^[+-]?\d+$/', $name)) {
            return 'Use a name with letters in it.'; // a number-only name would turn into a number as a list key
        }
        // Compared the way the database compares (accent- and width-blind), so "Swítch" counts as "Switch"
        $built = array_keys(Lifecycle::TYPES);
        $same = DB::value('SELECT ? COLLATE utf8mb4_unicode_ci IN (' . implode(',', array_fill(0, count($built), '?')) . ')', [$name, ...$built]);
        if ((int) $same === 1) {
            foreach ($built as $b) {
                if ((int) DB::value('SELECT ? COLLATE utf8mb4_unicode_ci = ?', [$name, $b]) === 1) {
                    return "\"$b\" is already a built-in type.";
                }
            }
        }
        $clash = DB::value('SELECT id FROM device_types WHERE name = ? AND id <> ?', [$name, $exceptId ?? 0]);
        if ($clash) {
            return "There's already a type called \"$name\".";
        }
        if (!isset(Lifecycle::CLASSES[$class])) {
            return 'Pick the category it plans under.';
        }
        if (!isset(self::ICONS[$icon])) {
            return 'Pick an icon from the list.';
        }
        return null;
    }

    /**
     * Adds a type (values checked with problem() first) and gives it to Unassigned devices whose PSA asset type has
     * exactly that name. Returns [its id, the number of devices sorted].
     */
    public static function create(string $name, string $class, string $icon, bool $virtual, ?int $userId): array
    {
        $id = DB::insert('device_types', ['name' => $name, 'class' => $class, 'icon' => $icon, 'is_virtual' => $virtual ? 1 : 0, 'created_by' => $userId]);
        Lifecycle::forgetTypes();
        return [$id, self::sortUnassigned($name, 'LOWER(TRIM(a.type)) = LOWER(?)', [$name])];
    }

    /**
     * Gives $type to devices that are still Unassigned (no type of their own) and are linked to a PSA asset matching
     * $where (SQL on psa_assets a, written in code, with bound $params). Devices Align owns get the type itself, RMM
     * devices a type override, as a PSA sync would. Returns how many changed.
     */
    public static function sortUnassigned(string $type, string $where, array $params): int
    {
        [$class, , $virtual] = Lifecycle::types()[$type] ?? [null, null, false];
        if ($class === null) {
            return 0;
        }
        $rows = DB::all("SELECT d.id, d.source FROM devices d JOIN psa_assets a ON a.psa_asset_id = d.psa_asset_id
            LEFT JOIN device_overrides o ON o.device_id = d.id
            WHERE d.removed_at IS NULL AND COALESCE(NULLIF(o.device_type, ''), d.device_type) = ? AND ($where)", [Lifecycle::UNASSIGNED, ...$params]);
        foreach ($rows as $r) {
            if (in_array($r['source'], ['manual', 'psa'], true)) {
                DB::run('UPDATE devices SET device_type = ?, device_class = ?, is_virtual = ? WHERE id = ?', [$type, $class, $virtual ? 1 : 0, $r['id']]);
                DB::run('UPDATE device_overrides SET device_type = NULL WHERE device_id = ?', [$r['id']]);
            } else {
                DB::run('INSERT INTO device_overrides (device_id, device_type) VALUES (?, ?) ON DUPLICATE KEY UPDATE device_type = VALUES(device_type)', [$r['id'], $type]);
            }
        }
        return count($rows);
    }

    /**
     * Changes a type (values checked with problem() first). A new name is given to every device that had the old one,
     * in the same transaction. Returns the number of devices renamed.
     */
    public static function update(array $row, string $name, string $class, string $icon, bool $virtual): int
    {
        $n = DB::transaction(function () use ($row, $name, $class, $icon, $virtual) {
            DB::run('UPDATE device_types SET name = ?, class = ?, icon = ?, is_virtual = ? WHERE id = ?', [$name, $class, $icon, $virtual ? 1 : 0, $row['id']]);
            $n = 0;
            if ($name !== $row['name']) {
                $n += DB::run('UPDATE devices SET device_type = ? WHERE BINARY device_type = BINARY ?', [$name, $row['name']])->rowCount();
                $n += DB::run('UPDATE device_overrides SET device_type = ? WHERE BINARY device_type = BINARY ?', [$name, $row['name']])->rowCount();
            }
            // Devices Align owns keep their category and virtual flag in their own row: follow the type's
            DB::run("UPDATE devices SET device_class = ?, is_virtual = ? WHERE BINARY device_type = BINARY ? AND source IN ('manual', 'psa')", [$class, $virtual ? 1 : 0, $name]);
            return $n;
        });
        Lifecycle::forgetTypes();
        return $n;
    }

    /**
     * Removes a type. Devices set to it by hand go back to their synced type; a device whose synced type it was
     * (a PSA asset) becomes Unassigned, to be sorted again. Returns the number of devices changed.
     */
    public static function delete(array $row): int
    {
        $n = DB::transaction(function () use ($row) {
            DB::run('DELETE FROM device_types WHERE id = ?', [$row['id']]);
            $n = DB::run('UPDATE device_overrides SET device_type = NULL WHERE BINARY device_type = BINARY ?', [$row['name']])->rowCount();
            return $n + DB::run("UPDATE devices SET device_type = ?, device_class = 'other', is_virtual = 0 WHERE BINARY device_type = BINARY ?", [Lifecycle::UNASSIGNED, $row['name']])->rowCount();
        });
        Lifecycle::forgetTypes();
        return $n;
    }

    /** The custom type whose name is exactly $name (any case), for matching a PSA asset type; null if none. */
    public static function byName(string $name): ?string
    {
        $name = mb_strtolower(self::clean($name));
        if ($name === '') {
            return null;
        }
        foreach (array_keys(array_diff_key(Lifecycle::types(), Lifecycle::TYPES)) as $t) {
            if (mb_strtolower((string) $t) === $name) {
                return (string) $t;
            }
        }
        return null;
    }
}
