<?php
declare(strict_types=1);

namespace Align\Sync;

use Align\DB;
use Align\Integrations\Itflow;
use Align\Lifecycle\Lifecycle;
use Align\Settings;

/**
 * Two-way asset sync between Align devices and ITFlow assets.
 *
 * ITFlow has no webhooks, so changes made there are picked up by polling (every 2 minutes via
 * the mountaineer-align-itflow timer). Changes made in Align are pushed the moment they're saved.
 *
 * For every linked device and field we keep the last value both systems agreed on (the baseline).
 * A side changed a field when its value no longer matches the baseline:
 *   - only ITFlow changed  -> copy into Align
 *   - only Align changed   -> push to ITFlow
 *   - both changed         -> the most recent edit wins; the losing value is kept in device_changes
 * Fields Align doesn't own for a device (hardware facts on NinjaOne devices) are never pushed.
 */
final class ItflowSync
{
    public const LOCK = 'mountaineer_align_itflow';

    /** field => [label, ITFlow API key to push (null = pulled from ITFlow only)] */
    public const FIELDS = [
        'name' => ['Name', 'asset_name'],
        'type' => ['Type', 'asset_type'],
        'make' => ['Make', 'asset_make'],
        'model' => ['Model', 'asset_model'],
        'serial' => ['Serial', 'asset_serial'],
        'os' => ['OS / firmware', 'asset_os'],
        'purchase' => ['Purchase date', 'asset_purchase_date'],
        'warranty' => ['Warranty end', 'asset_warranty_expire'],
        'retired' => ['Status', 'asset_status'],
        'ip' => ['IP address', null],
        'location' => ['Location', null],
    ];

    /** Column in the itflow_assets cache for each field. */
    private const CACHE_COL = [
        'name' => 'name', 'type' => 'type', 'make' => 'make', 'model' => 'model', 'serial' => 'serial', 'os' => 'os',
        'purchase' => 'purchase_date', 'warranty' => 'warranty_expire', 'retired' => 'status',
    ];

    private const COMPUTER_CLASSES = ['desktop', 'laptop', 'server'];

    private static array $pollLog = [];

    public static function twoWay(): bool
    {
        return Settings::get('itflow_two_way', '1') === '1';
    }

    public static function createsAssets(): bool
    {
        return self::twoWay() && Settings::get('itflow_create_assets', '1') === '1';
    }

    /** Align owns the hardware fields of hand-added and ITFlow-imported devices; NinjaOne owns them otherwise. */
    public static function owns(array $d): bool
    {
        return in_array($d['source'], ['manual', 'itflow'], true);
    }

    // ---- Field access -------------------------------------------------------------------------

    private static function norm(mixed $v): string
    {
        return trim((string) ($v ?? ''));
    }

    public static function itflowValue(string $f, array $a): string
    {
        return match ($f) {
            'name' => self::norm($a['name']),
            'type' => self::norm($a['type']),
            'make' => self::norm($a['make']),
            'model' => self::norm($a['model']),
            'serial' => self::norm($a['serial']),
            'os' => self::norm($a['os']),
            'purchase' => self::norm($a['purchase_date']),
            'warranty' => self::norm($a['warranty_expire']),
            'retired' => ((int) $a['is_archived'] === 1 || strtolower(self::norm($a['status'])) === 'retired') ? '1' : '0',
            'ip' => self::norm($a['ip_address']),
            'location' => self::norm($a['location_name']),
        };
    }

    private static function effectiveType(array $d): string
    {
        return (string) ($d['o_type'] ?? $d['device_type'] ?? 'Other');
    }

    private static function isComputer(array $d): bool
    {
        return in_array(Lifecycle::TYPES[self::effectiveType($d)][0] ?? 'other', self::COMPUTER_CLASSES, true);
    }

    /** Align's value, or null when Align has no say on this field for this device. */
    public static function alignValue(string $f, array $d): ?string
    {
        $own = self::owns($d);
        return match ($f) {
            'name' => $own ? self::norm($d['display_name']) : null,
            'type' => $own ? self::effectiveType($d) : ($d['o_type'] !== null ? self::norm($d['o_type']) : null),
            'make' => $own ? self::norm($d['manufacturer']) : null,
            'model' => $own ? self::norm($d['model']) : null,
            'serial' => $own ? self::norm($d['serial']) : null,
            'os' => $own ? self::norm(self::isComputer($d) ? $d['os_name'] : $d['firmware']) : null,
            'purchase' => $d['o_purchase'] !== null ? self::norm($d['o_purchase']) : null,
            'warranty' => $d['o_warranty'] !== null ? self::norm($d['o_warranty']) : null,
            'retired' => $own ? ($d['retired_at'] ? '1' : '0') : null,
            default => null,
        };
    }

    /** Current Align value for a pull-only field (only meaningful for devices Align owns). */
    private static function alignCurrent(string $f, array $d): string
    {
        return match ($f) {
            'ip' => self::norm($d['ip_address']),
            'location' => self::norm($d['location']),
            default => (string) self::alignValue($f, $d),
        };
    }

    /** Whether an Align value and an ITFlow value mean the same thing. */
    private static function same(string $f, string $al, string $itf, array $a): bool
    {
        if ($f === 'type') {
            if ($al === Lifecycle::UNASSIGNED) {
                return true; // not categorized yet: nothing to tell ITFlow
            }
            if (strcasecmp((string) Itflow::typeFor($al), $itf) === 0) {
                return true;
            }
            return Itflow::mapType($itf, (string) $a['make'], (string) $a['model'], (string) $a['name'], (string) $a['os'])[0] === $al;
        }
        if ($f === 'serial') {
            return (string) normalize_serial($al) === (string) normalize_serial($itf);
        }
        return $al === $itf;
    }

    /** Value sent to ITFlow for a field, and the ITFlow-side value it becomes (the new baseline). */
    private static function toItflow(string $f, string $al): array
    {
        return match ($f) {
            'type' => [Itflow::typeFor($al) ?? 'Other', Itflow::typeFor($al) ?? 'Other'],
            'retired' => [$al === '1' ? 'Retired' : 'Deployed', $al],
            'serial' => [$al, (string) normalize_serial($al)],
            default => [$al, $al],
        };
    }

    public static function display(string $f, ?string $v): string
    {
        if ($f === 'retired') {
            return $v === '1' ? 'Retired' : ($v === '0' ? 'Active' : '');
        }
        return (string) $v;
    }

    // ---- Applying ITFlow values in Align --------------------------------------------------------

    private static function setType(int $id, string $type): void
    {
        [$class, , $virtual] = Lifecycle::TYPES[$type] ?? Lifecycle::TYPES['Other'];
        DB::run('UPDATE devices SET device_type = ?, device_class = ?, is_virtual = ? WHERE id = ?', [$type, $class, $virtual ? 1 : 0, $id]);
        DB::run('UPDATE device_overrides SET device_type = NULL WHERE device_id = ?', [$id]);
    }

    /** Writes an ITFlow value into Align. Returns false when nothing needed to change. */
    private static function apply(string $f, array $d, string $v, array $a): bool
    {
        $id = (int) $d['id'];
        $own = self::owns($d);
        $col = fn(string $c, ?string $val) => DB::run("UPDATE devices SET `$c` = ? WHERE id = ?", [$val === '' ? null : $val, $id]);
        switch ($f) {
            case 'type':
                $mapped = Itflow::mapType($v, (string) $a['make'], (string) $a['model'], (string) $a['name'], (string) $a['os'])[0];
                if ($mapped === Lifecycle::UNASSIGNED && self::effectiveType($d) !== Lifecycle::UNASSIGNED) {
                    return false; // ITFlow's type is vaguer than what Align already knows
                }
                if ($mapped === self::effectiveType($d)) {
                    return false;
                }
                if ($own) {
                    self::setType($id, $mapped);
                } else {
                    DB::run('INSERT INTO device_overrides (device_id, device_type) VALUES (?, ?) ON DUPLICATE KEY UPDATE device_type = VALUES(device_type)',
                        [$id, $mapped === $d['device_type'] ? null : $mapped]);
                }
                return true;
            case 'purchase':
            case 'warranty':
                // Clear Align's own date so the ITFlow date is the one used.
                $c = $f === 'purchase' ? 'purchase_date' : 'warranty_end';
                if (($f === 'purchase' ? $d['o_purchase'] : $d['o_warranty']) === null) {
                    return false;
                }
                DB::run("UPDATE device_overrides SET `$c` = NULL WHERE device_id = ?", [$id]);
                return true;
            case 'retired':
                if (!$own) {
                    return false;
                }
                if ($v === '1') {
                    DB::run('UPDATE devices SET retired_at = COALESCE(retired_at, NOW()), removed_at = COALESCE(removed_at, NOW()) WHERE id = ?', [$id]);
                } else {
                    DB::run('UPDATE devices SET retired_at = NULL, removed_at = NULL WHERE id = ?', [$id]);
                }
                return true;
        }
        if (!$own) {
            return false;
        }
        match ($f) {
            'name' => $v !== '' ? $col('display_name', $v) : null,
            'make' => $col('manufacturer', $v),
            'model' => $col('model', $v),
            'serial' => $col('serial', normalize_serial($v)),
            'os' => $col(self::isComputer($d) ? 'os_name' : 'firmware', $v),
            'ip' => $col('ip_address', mb_substr($v, 0, 64)),
            'location' => $col('location', mb_substr($v, 0, 190)),
        };
        return !($f === 'name' && $v === '');
    }

    // ---- State + history -----------------------------------------------------------------------

    private static function state(int $deviceId): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM itflow_sync_state WHERE device_id = ?', [$deviceId]) as $r) {
            $out[$r['field']] = $r;
        }
        return $out;
    }

    /** Records a value Align itself wrote to ITFlow (e.g. warranty write-back) so it isn't seen as an ITFlow edit. */
    public static function acknowledge(int $deviceId, string $f, string $value): void
    {
        DB::run('INSERT INTO itflow_sync_state (device_id, field, base_value) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE base_value = IF(pending = 1, base_value, VALUES(base_value))', [$deviceId, $f, $value]);
    }

    private static function setBase(int $deviceId, string $f, string $base): void
    {
        DB::run('INSERT INTO itflow_sync_state (device_id, field, base_value, align_changed_at, pending, last_error) VALUES (?, ?, ?, NULL, 0, NULL)
            ON DUPLICATE KEY UPDATE base_value = VALUES(base_value), align_changed_at = NULL, pending = 0, last_error = NULL', [$deviceId, $f, $base]);
    }

    private static function markPending(int $deviceId, string $f, ?string $error): void
    {
        DB::run('INSERT INTO itflow_sync_state (device_id, field, base_value, align_changed_at, pending, last_error) VALUES (?, ?, NULL, NOW(), 1, ?)
            ON DUPLICATE KEY UPDATE align_changed_at = COALESCE(align_changed_at, NOW()), pending = 1, last_error = VALUES(last_error)',
            [$deviceId, $f, $error === null ? null : mb_substr($error, 0, 255)]);
    }

    private static function log(int $deviceId, string $f, ?string $old, ?string $new, string $dir, bool $conflict = false, ?string $note = null, ?int $userId = null): void
    {
        DB::insert('device_changes', [
            'device_id' => $deviceId, 'field' => $f, 'old_value' => $old, 'new_value' => $new, 'direction' => $dir,
            'conflict' => $conflict ? 1 : 0, 'note' => $note === null ? null : mb_substr($note, 0, 255), 'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Snapshot of Align's syncable values, taken before a save (see recordAlignEdit). */
    public static function snapshot(int $deviceId): array
    {
        $d = self::loadDevice($deviceId);
        if (!$d) {
            return [];
        }
        $out = [];
        foreach (self::FIELDS as $f => [, $key]) {
            if ($key !== null) {
                $out[$f] = self::alignValue($f, $d);
            }
        }
        return $out;
    }

    /** After a save in Align: stamp the fields that changed so a newer ITFlow edit can't silently beat them. */
    public static function recordAlignEdit(int $deviceId, array $before): void
    {
        $after = self::snapshot($deviceId);
        foreach ($after as $f => $v) {
            if ($v !== ($before[$f] ?? null)) {
                DB::run('INSERT INTO itflow_sync_state (device_id, field, base_value, align_changed_at, pending) VALUES (?, ?, NULL, NOW(), 1)
                    ON DUPLICATE KEY UPDATE align_changed_at = NOW(), pending = 1', [$deviceId, $f]);
            }
        }
        DB::run('UPDATE devices SET updated_at = NOW() WHERE id = ?', [$deviceId]);
    }

    public static function loadDevice(int $id): ?array
    {
        return DB::one('SELECT d.*, o.device_type AS o_type, o.purchase_date AS o_purchase, o.warranty_end AS o_warranty, o.updated_at AS o_updated,
                c.itflow_client_id AS client_itflow_id
            FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
            LEFT JOIN clients c ON c.id = d.client_id WHERE d.id = ?', [$id]);
    }

    // ---- Reconciling one device --------------------------------------------------------------

    /**
     * @return array{pulled:int,pushed:int,conflicts:int,error:?string}
     */
    public static function reconcileDevice(array $d, array $a, ?Itflow $it, ?int $userId = null): array
    {
        $id = (int) $d['id'];
        $twoWay = self::twoWay();
        $state = self::state($id);
        $res = ['pulled' => 0, 'pushed' => 0, 'conflicts' => 0, 'error' => null];
        $payload = [];
        $pushes = [];
        $alignTsFirst = max((string) ($d['updated_at'] ?? ''), (string) ($d['o_updated'] ?? '')) ?: (string) $d['created_at'];

        foreach (self::FIELDS as $f => [$label, $key]) {
            $itf = self::itflowValue($f, $a);
            $st = $state[$f] ?? null;
            $al = $key === null ? null : self::alignValue($f, $d);

            if ($key === null) { // pulled from ITFlow only
                // On first link an empty ITFlow value never wipes what Align already has.
                if (($st === null ? $itf !== '' : $itf !== (string) $st['base_value']) && self::owns($d) && self::alignCurrent($f, $d) !== $itf) {
                    $old = self::alignCurrent($f, $d);
                    if (self::apply($f, $d, $itf, $a)) {
                        if ($st !== null) { // don't log the first fill-in
                            self::log($id, $f, $old, $itf, 'from_itflow');
                            $res['pulled']++;
                        }
                        $d = self::loadDevice($id) ?? $d;
                    }
                }
                self::setBase($id, $f, $itf);
                continue;
            }

            $conflict = false;
            if ($st === null || $st['base_value'] === null && !$st['pending']) {
                // First time this device and asset are compared.
                if ($al === null || self::same($f, $al, $itf, $a)) {
                    self::setBase($id, $f, $itf);
                    continue;
                }
                $itfTs = (string) ($a['updated_at'] ?? '');
                $alignWins = $twoWay && ($itfTs === '' || $alignTsFirst >= $itfTs);
                $note = 'Values differed when first linked; ' . ($alignWins ? 'Align' : 'ITFlow') . ' had the newer edit';
            } else {
                $base = $st['base_value'];
                $itfChanged = $base === null ? false : $itf !== (string) $base;
                $alChanged = $al !== null && ($base === null ? (bool) $st['pending'] : !self::same($f, $al, (string) $base, $a));
                if ($base === null && $alChanged && self::same($f, (string) $al, $itf, $a)) {
                    $alChanged = false;
                }
                if (!$itfChanged && !$alChanged) {
                    if ($st['pending'] || $base === null) {
                        self::setBase($id, $f, $itf);
                    }
                    continue;
                }
                if ($itfChanged && !$alChanged) {
                    $old = $al ?? (string) $base;
                    if (self::apply($f, $d, $itf, $a) || in_array($f, ['purchase', 'warranty'], true)) {
                        self::log($id, $f, $old, $itf, 'from_itflow');
                        $res['pulled']++;
                        $d = self::loadDevice($id) ?? $d;
                    }
                    self::setBase($id, $f, $itf);
                    continue;
                }
                if ($alChanged && !$itfChanged) {
                    if (!$twoWay) {
                        continue; // two-way sync is off: keep Align's edit, don't push it
                    }
                    $alignWins = true;
                    $note = null;
                } else {
                    if (self::same($f, (string) $al, $itf, $a)) {
                        self::setBase($id, $f, $itf);
                        continue;
                    }
                    $alTs = (string) ($st['align_changed_at'] ?? date('Y-m-d H:i:s'));
                    $itfTs = (string) ($a['updated_at'] ?? '');
                    $alignWins = $twoWay && ($itfTs === '' || $alTs >= $itfTs);
                    $conflict = true;
                    $note = $alignWins
                        ? 'Changed in both; Align edit was newer. ITFlow value "' . self::display($f, $itf) . '" was replaced'
                        : 'Changed in both; ITFlow edit was newer. Align value "' . self::display($f, $al) . '" was replaced';
                }
            }

            if ($alignWins) {
                [$send, $newBase] = self::toItflow($f, (string) $al);
                $payload[$key] = $send;
                $pushes[$f] = ['old' => $itf, 'new' => $al, 'base' => $newBase, 'send' => $send, 'conflict' => $conflict, 'note' => $note];
            } else {
                if (self::apply($f, $d, $itf, $a) || in_array($f, ['purchase', 'warranty'], true)) {
                    self::log($id, $f, $al, $itf, 'from_itflow', $conflict, $note);
                    $res['pulled']++;
                    $res['conflicts'] += $conflict ? 1 : 0;
                    $d = self::loadDevice($id) ?? $d;
                }
                self::setBase($id, $f, $itf);
            }
        }

        if ($pushes) {
            try {
                if (!$it) {
                    throw new \RuntimeException('ITFlow not reachable');
                }
                if (!$it->updateAsset((int) $a['itflow_client_id'], (int) $a['itflow_asset_id'], $payload)) {
                    throw new \RuntimeException('ITFlow rejected the update (does the API key user have write access to assets?)');
                }
                foreach ($pushes as $f => $p) {
                    self::setBase($id, $f, $p['base']);
                    DB::run('UPDATE itflow_assets SET `' . self::CACHE_COL[$f] . '` = ? WHERE itflow_asset_id = ?', [$p['send'] === '' ? null : $p['send'], $a['itflow_asset_id']]);
                    self::log($id, $f, $p['old'], $p['new'], 'to_itflow', $p['conflict'], $p['note'], $userId);
                    $res['pushed']++;
                    $res['conflicts'] += $p['conflict'] ? 1 : 0;
                }
            } catch (\Throwable $e) {
                $res['error'] = $e->getMessage();
                foreach (array_keys($pushes) as $f) {
                    self::markPending($id, $f, $e->getMessage());
                }
            }
        }
        return $res;
    }

    // ---- Entry points --------------------------------------------------------------------------

    /**
     * Called right after a device is saved in Align. Creates or updates the ITFlow asset.
     * @return array{status:string,message:string}  status: ok|off|queued|error
     */
    public static function pushDevice(int $id, ?int $userId = null): array
    {
        if (!self::twoWay()) {
            return ['status' => 'off', 'message' => ''];
        }
        $d = self::loadDevice($id);
        if (!$d || (int) $d['itflow_sync'] !== 1) {
            return ['status' => 'off', 'message' => ''];
        }
        if (!$d['itflow_asset_id'] && !($d['source'] === 'manual' && self::createsAssets() && $d['client_itflow_id'] && !$d['removed_at'])) {
            return ['status' => 'off', 'message' => ''];
        }
        if ((int) DB::value('SELECT GET_LOCK(?, 8)', [self::LOCK]) !== 1) {
            return ['status' => 'queued', 'message' => 'ITFlow sync is busy; your change will be sent within 2 minutes.'];
        }
        try {
            $it = Itflow::fromSettings(true);
            if (!$d['itflow_asset_id']) {
                $assetId = self::createAsset($d, $it, $userId);
                return ['status' => 'ok', 'message' => "Created in ITFlow (asset #$assetId)."];
            }
            $asset = $it->asset((int) $d['itflow_asset_id']);
            if (!$asset) {
                return ['status' => 'error', 'message' => 'The linked ITFlow asset no longer exists. The next sync will retire or relink this device.'];
            }
            $row = self::cacheOne($asset);
            $r = self::reconcileDevice(self::loadDevice($id), $row, $it, $userId);
            if ($r['error']) {
                return ['status' => 'queued', 'message' => 'Couldn\'t reach ITFlow (' . $r['error'] . '). The change is queued and will be retried automatically.'];
            }
            $bits = [];
            if ($r['pushed']) {
                $bits[] = $r['pushed'] . ' field' . ($r['pushed'] === 1 ? '' : 's') . ' sent to ITFlow';
            }
            if ($r['pulled']) {
                $bits[] = $r['pulled'] . ' newer ITFlow change' . ($r['pulled'] === 1 ? '' : 's') . ' pulled in';
            }
            return ['status' => 'ok', 'message' => $bits ? ucfirst(implode(', ', $bits)) . '.' : 'ITFlow is already up to date.'];
        } catch (\Throwable $e) {
            return ['status' => 'queued', 'message' => 'Couldn\'t reach ITFlow (' . $e->getMessage() . '). The change is queued and will be retried automatically.'];
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    private static function createAsset(array $d, Itflow $it, ?int $userId): int
    {
        $fields = ['asset_name' => (string) $d['display_name'], 'asset_status' => 'Deployed'];
        foreach (['type', 'make', 'model', 'serial', 'os', 'purchase', 'warranty'] as $f) {
            $v = self::alignValue($f, $d);
            if ($v !== null && $v !== '') {
                $fields[self::FIELDS[$f][1]] = self::toItflow($f, $v)[0];
            }
        }
        $fields['asset_type'] ??= 'Other';
        $assetId = $it->createAsset((int) $d['client_itflow_id'], $fields);
        DB::run('UPDATE devices SET itflow_asset_id = ? WHERE id = ?', [$assetId, $d['id']]);
        $row = [
            'itflow_asset_id' => $assetId, 'itflow_client_id' => (int) $d['client_itflow_id'],
            'name' => $fields['asset_name'], 'type' => $fields['asset_type'], 'make' => $fields['asset_make'] ?? null,
            'model' => $fields['asset_model'] ?? null, 'serial' => normalize_serial($fields['asset_serial'] ?? null),
            'os' => $fields['asset_os'] ?? null, 'purchase_date' => $fields['asset_purchase_date'] ?? null,
            'warranty_expire' => $fields['asset_warranty_expire'] ?? null, 'status' => 'Deployed', 'is_archived' => 0,
            'ip_address' => $d['ip_address'], 'location_name' => $d['location'], 'updated_at' => date('Y-m-d H:i:s'), 'synced_at' => date('Y-m-d H:i:s'),
        ];
        DB::upsert('itflow_assets', $row, ['itflow_asset_id']);
        $full = DB::one('SELECT * FROM itflow_assets WHERE itflow_asset_id = ?', [$assetId]);
        foreach (array_keys(self::FIELDS) as $f) {
            self::setBase((int) $d['id'], $f, self::itflowValue($f, $full));
        }
        self::log((int) $d['id'], 'name', null, (string) $d['display_name'], 'created', false, "Created ITFlow asset #$assetId", $userId);
        return $assetId;
    }

    /** Stores one freshly read asset in the cache and returns the cache row. */
    private static function cacheOne(array $a): array
    {
        $prev = DB::one('SELECT location_id, location_name FROM itflow_assets WHERE itflow_asset_id = ?', [(int) $a['asset_id']]);
        $row = self::cacheRow($a, date('Y-m-d H:i:s'));
        $loc = $row['location_id'];
        $row['location_name'] = $prev && (int) $prev['location_id'] === (int) $loc ? $prev['location_name'] : ($loc ? ($prev['location_name'] ?? null) : null);
        DB::upsert('itflow_assets', $row, ['itflow_asset_id']);
        return DB::one('SELECT * FROM itflow_assets WHERE itflow_asset_id = ?', [(int) $a['asset_id']]);
    }

    private static function cacheRow(array $a, string $now, array $locations = []): array
    {
        $d = fn($v) => ($v && !str_starts_with((string) $v, '0000')) ? substr((string) $v, 0, 10) : null;
        $ts = fn($v) => ($v && !str_starts_with((string) $v, '0000')) ? substr((string) $v, 0, 19) : null;
        return [
            'itflow_asset_id' => (int) $a['asset_id'],
            'itflow_client_id' => (int) ($a['asset_client_id'] ?? 0),
            'name' => $a['asset_name'] ?? null,
            'type' => $a['asset_type'] ?? null,
            'make' => $a['asset_make'] ?? null,
            'model' => $a['asset_model'] ?? null,
            'serial' => normalize_serial($a['asset_serial'] ?? null),
            'purchase_date' => $d($a['asset_purchase_date'] ?? null),
            'warranty_expire' => $d($a['asset_warranty_expire'] ?? null),
            'install_date' => $d($a['asset_install_date'] ?? null),
            'status' => $a['asset_status'] ?? null,
            'is_archived' => empty($a['asset_archived_at']) ? 0 : 1,
            'ip_address' => mb_substr((string) ($a['interface_ip'] ?? $a['asset_ip'] ?? ''), 0, 64) ?: null,
            'mac' => mb_substr((string) ($a['interface_mac'] ?? $a['asset_mac'] ?? ''), 0, 64) ?: null,
            'os' => mb_substr((string) ($a['asset_os'] ?? ''), 0, 255) ?: null,
            'description' => $a['asset_description'] ?? null,
            'location_id' => (int) ($a['asset_location_id'] ?? 0) ?: null,
            'location_name' => $locations[(int) ($a['asset_location_id'] ?? 0)] ?? null,
            'updated_at' => $ts($a['asset_updated_at'] ?? null) ?? $ts($a['asset_created_at'] ?? null),
            'synced_at' => $now,
        ];
    }

    /**
     * Full cycle: read all ITFlow assets, link and import them, reconcile every linked device,
     * push anything queued, and create ITFlow assets for hand-added devices.
     */
    public static function run(Itflow $it, ?callable $log = null): string
    {
        $say = $log ?? fn(string $m) => null;
        if ((int) DB::value('SELECT GET_LOCK(?, 60)', [self::LOCK]) !== 1) {
            throw new \RuntimeException('Another ITFlow sync is still running.');
        }
        try {
            $assets = $it->assets();
            $cached = (int) DB::value('SELECT COUNT(*) FROM itflow_assets');
            if (!$assets && $cached > 0) {
                throw new \RuntimeException("ITFlow returned no assets (Align has $cached). Nothing was changed; check the API key's permissions.");
            }
            $locations = [];
            try {
                foreach ($it->locations() as $l) {
                    $locations[(int) ($l['location_id'] ?? 0)] = (string) ($l['location_name'] ?? '');
                }
            } catch (\Throwable $e) {
                $say('ITFlow locations not readable (' . $e->getMessage() . '); continuing without them');
            }
            $now = date('Y-m-d H:i:s');
            DB::transaction(function () use ($assets, $now, $locations) {
                foreach ($assets as $a) {
                    if (!empty($a['asset_id'])) {
                        DB::upsert('itflow_assets', self::cacheRow($a, $now, $locations), ['itflow_asset_id']);
                    }
                }
                DB::run('DELETE FROM itflow_assets WHERE synced_at < ?', [$now]);
            });
            $parts = [count($assets) . ' assets read'];
            try {
                $parts[] = SyncRunner::syncClientDetails($it);
            } catch (\Throwable $e) {
                $say('Client details not refreshed: ' . $e->getMessage());
            }
            try {
                $parts[] = \Align\Licensing\Licenses::syncFromItflow($it);
            } catch (\Throwable $e) {
                $say('Licenses not refreshed: ' . $e->getMessage());
                $parts[] = 'licenses not refreshed (' . $e->getMessage() . ')';
            }
            $parts[] = self::linkDevices();
            $parts[] = self::importAssets();
            $parts[] = self::reconcileAll($it);
            if (self::createsAssets()) {
                $parts[] = self::createMissing($it);
            }
            $result = implode('; ', array_filter($parts));
            DB::run('UPDATE itflow_poll_state SET last_run = NOW(), last_ok = NOW(), last_result = ? WHERE id = 1', [mb_substr($result, 0, 500)]);
            return $result;
        } catch (\Throwable $e) {
            DB::run('UPDATE itflow_poll_state SET last_run = NOW(), last_result = ? WHERE id = 1', [mb_substr('ERROR: ' . $e->getMessage(), 0, 500)]);
            throw $e;
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /** Links NinjaOne / hand-added devices to ITFlow assets by serial, then by name. Existing links are kept. */
    private static function linkDevices(): string
    {
        $claimed = array_flip(array_map('intval', array_column(DB::all(
            "SELECT DISTINCT d.itflow_asset_id FROM devices d JOIN itflow_assets a ON a.itflow_asset_id = d.itflow_asset_id
             WHERE d.source IN ('ninja','manual') AND (d.removed_at IS NULL OR d.retired_at IS NOT NULL)"), 'itflow_asset_id')));
        $bySerial = [];
        $byName = [];
        foreach (DB::all('SELECT itflow_asset_id, itflow_client_id, name, serial FROM itflow_assets WHERE is_archived = 0') as $a) {
            if (isset($claimed[(int) $a['itflow_asset_id']])) {
                continue;
            }
            if ($a['serial']) {
                $bySerial[$a['itflow_client_id']][$a['serial']][] = (int) $a['itflow_asset_id'];
            }
            if ($a['name']) {
                $byName[$a['itflow_client_id']][strtolower(trim($a['name']))][] = (int) $a['itflow_asset_id'];
            }
        }
        $devices = DB::all('SELECT d.id, d.serial, d.display_name, d.system_name, COALESCE(cm.itflow_client_id, cn.itflow_client_id) AS itflow_client_id
            FROM devices d ' . Lifecycle::CLIENT_JOIN . "
            LEFT JOIN itflow_assets a ON a.itflow_asset_id = d.itflow_asset_id
            WHERE d.removed_at IS NULL AND d.source IN ('ninja','manual') AND a.itflow_asset_id IS NULL");
        $linked = 0;
        foreach ($devices as $dv) {
            $cid = $dv['itflow_client_id'];
            if (!$cid) {
                continue;
            }
            $assetId = null;
            if ($dv['serial'] && count($bySerial[$cid][$dv['serial']] ?? []) === 1) {
                $assetId = $bySerial[$cid][$dv['serial']][0];
            } else {
                foreach ([$dv['system_name'], $dv['display_name']] as $n) {
                    $k = strtolower(trim((string) $n));
                    if ($k !== '' && count($byName[$cid][$k] ?? []) === 1) {
                        $assetId = $byName[$cid][$k][0];
                        break;
                    }
                }
            }
            if ($assetId && !isset($claimed[$assetId])) {
                DB::run('UPDATE devices SET itflow_asset_id = ? WHERE id = ?', [$assetId, $dv['id']]);
                DB::run('DELETE FROM itflow_sync_state WHERE device_id = ?', [$dv['id']]);
                $claimed[$assetId] = true;
                $linked++;
            }
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM devices WHERE source IN ('ninja','manual') AND removed_at IS NULL AND itflow_asset_id IS NOT NULL");
        return "$total devices linked" . ($linked ? " ($linked new)" : '');
    }

    /** Adds ITFlow assets that no Align device covers. Unknown types land in Unassigned. */
    private static function importAssets(): string
    {
        $cats = array_filter(array_map('trim', explode(',', (string) Settings::get('itflow_import_types', 'network,printer,ups,storage,camera,phone,server,workstation,vm,other'))));
        $claimed = array_flip(array_map('intval', array_column(DB::all(
            "SELECT DISTINCT itflow_asset_id FROM devices WHERE source IN ('ninja','manual') AND itflow_asset_id IS NOT NULL
             AND (removed_at IS NULL OR retired_at IS NOT NULL)"), 'itflow_asset_id')));
        $clients = array_column(DB::all('SELECT id, itflow_client_id FROM clients WHERE itflow_client_id IS NOT NULL'), 'id', 'itflow_client_id');
        // Serials already covered by a NinjaOne / hand-added device, per client (avoids importing duplicates)
        $serials = [];
        foreach (DB::all("SELECT COALESCE(cm.id, cn.id) AS cid, d.serial FROM devices d " . Lifecycle::CLIENT_JOIN . "
                WHERE d.source IN ('ninja','manual') AND d.removed_at IS NULL AND d.serial IS NOT NULL") as $r) {
            $serials[$r['cid']][$r['serial']] = true;
        }
        $existing = [];
        foreach (DB::all("SELECT id, itflow_asset_id, client_id, removed_at, retired_at FROM devices WHERE source = 'itflow'") as $r) {
            $existing[(int) $r['itflow_asset_id']] = $r;
        }
        $now = date('Y-m-d H:i:s');
        $added = [];
        $hidden = 0;
        $unassigned = 0;
        DB::transaction(function () use ($cats, $claimed, $clients, $serials, $existing, $now, &$added, &$hidden, &$unassigned) {
            foreach (DB::all('SELECT * FROM itflow_assets') as $a) {
                $aid = (int) $a['itflow_asset_id'];
                [$type, $cat] = Itflow::mapType((string) $a['type'], (string) $a['make'], (string) $a['model'], (string) $a['name'], (string) $a['os']);
                $clientId = $clients[$a['itflow_client_id']] ?? null;
                $eligible = in_array($cat, $cats, true) && $clientId && !isset($claimed[$aid])
                    && !($a['serial'] && isset($serials[$clientId][$a['serial']]));
                $ex = $existing[$aid] ?? null;
                if ($ex) {
                    if (!$eligible) {
                        if (!$ex['removed_at']) {
                            DB::run('UPDATE devices SET removed_at = ? WHERE id = ?', [$now, $ex['id']]);
                            $hidden++;
                        }
                    } else {
                        if ($ex['removed_at'] && !$ex['retired_at']) {
                            DB::run('UPDATE devices SET removed_at = NULL WHERE id = ?', [$ex['id']]);
                        }
                        if ((int) $ex['client_id'] !== (int) $clientId) {
                            DB::run('UPDATE devices SET client_id = ? WHERE id = ?', [$clientId, $ex['id']]);
                        }
                        DB::run('UPDATE devices SET synced_at = ? WHERE id = ?', [$now, $ex['id']]);
                    }
                    continue;
                }
                if (!$eligible || (int) $a['is_archived'] === 1 || strtolower((string) $a['status']) === 'retired') {
                    continue;
                }
                [$class, , $virtual] = Lifecycle::TYPES[$type];
                $computer = in_array($class, self::COMPUTER_CLASSES, true);
                DB::insert('devices', [
                    'source' => 'itflow',
                    'itflow_asset_id' => $aid,
                    'client_id' => (int) $clientId,
                    'display_name' => $a['name'] ?: "ITFlow asset $aid",
                    'device_type' => $type,
                    'device_class' => $class,
                    'is_virtual' => $virtual ? 1 : 0,
                    'manufacturer' => $a['make'] ?: null,
                    'model' => $a['model'] ?: null,
                    'serial' => $a['serial'] ?: null,
                    'ip_address' => $a['ip_address'],
                    'location' => $a['location_name'],
                    'firmware' => $computer ? null : $a['os'],
                    'os_name' => $computer ? $a['os'] : null,
                    'synced_at' => $now,
                    'created_at' => $now,
                ]);
                $added[$type] = ($added[$type] ?? 0) + 1;
                if ($type === Lifecycle::UNASSIGNED) {
                    $unassigned++;
                }
            }
        });
        $n = array_sum($added);
        $waiting = (int) DB::value("SELECT COUNT(*) FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
            WHERE d.removed_at IS NULL AND COALESCE(o.device_type, d.device_type) = 'Unassigned'");
        return ($n ? "$n new asset" . ($n === 1 ? '' : 's') . ' imported' : 'no new assets')
            . ($hidden ? ", $hidden hidden (type turned off or client not mapped)" : '')
            . ($waiting ? ", $waiting unassigned waiting to be categorized" : '');
    }

    /** Reconciles every linked device; retires devices whose asset was deleted in ITFlow. */
    private static function reconcileAll(Itflow $it): string
    {
        $rows = DB::all("SELECT d.id, d.source, d.itflow_asset_id, a.itflow_asset_id AS present
            FROM devices d LEFT JOIN itflow_assets a ON a.itflow_asset_id = d.itflow_asset_id
            WHERE d.itflow_asset_id IS NOT NULL AND d.itflow_sync = 1 AND (d.removed_at IS NULL OR d.retired_at IS NOT NULL)");
        $tot = ['pulled' => 0, 'pushed' => 0, 'conflicts' => 0, 'errors' => 0, 'gone' => 0];
        $lastError = null;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (!$r['present']) {
                $d = self::loadDevice($id);
                if (self::owns($d)) {
                    if (!$d['retired_at']) {
                        DB::run('UPDATE devices SET retired_at = NOW(), removed_at = COALESCE(removed_at, NOW()) WHERE id = ?', [$id]);
                        self::log($id, 'retired', '0', '1', 'from_itflow', false, "ITFlow asset #{$r['itflow_asset_id']} was deleted in ITFlow");
                    }
                    if ($d['source'] === 'manual') {
                        DB::run('UPDATE devices SET itflow_asset_id = NULL WHERE id = ?', [$id]);
                    }
                } else {
                    DB::run('UPDATE devices SET itflow_asset_id = NULL WHERE id = ?', [$id]);
                }
                DB::run('DELETE FROM itflow_sync_state WHERE device_id = ?', [$id]);
                $tot['gone']++;
                continue;
            }
            $d = self::loadDevice($id);
            $a = DB::one('SELECT * FROM itflow_assets WHERE itflow_asset_id = ?', [$r['itflow_asset_id']]);
            $res = self::reconcileDevice($d, $a, $it);
            foreach (['pulled', 'pushed', 'conflicts'] as $k) {
                $tot[$k] += $res[$k];
            }
            if ($res['error']) {
                $tot['errors']++;
                $lastError = $res['error'];
            }
        }
        $out = count($rows) . ' linked devices checked';
        $bits = [];
        if ($tot['pulled']) {
            $bits[] = "{$tot['pulled']} changes from ITFlow";
        }
        if ($tot['pushed']) {
            $bits[] = "{$tot['pushed']} changes to ITFlow";
        }
        if ($tot['conflicts']) {
            $bits[] = "{$tot['conflicts']} conflicts resolved (newest wins)";
        }
        if ($tot['gone']) {
            $bits[] = "{$tot['gone']} assets deleted in ITFlow";
        }
        if ($tot['errors']) {
            $bits[] = "{$tot['errors']} devices couldn't be pushed ($lastError) and will be retried";
        }
        return $out . ($bits ? ' (' . implode(', ', $bits) . ')' : ', all in step');
    }

    /** Creates ITFlow assets for hand-added devices whose client is linked to ITFlow. */
    private static function createMissing(Itflow $it): string
    {
        $rows = DB::all("SELECT d.id FROM devices d JOIN clients c ON c.id = d.client_id
            WHERE d.source = 'manual' AND d.itflow_asset_id IS NULL AND d.itflow_sync = 1 AND d.removed_at IS NULL AND c.itflow_client_id IS NOT NULL");
        $made = 0;
        $failed = 0;
        foreach ($rows as $r) {
            try {
                self::createAsset(self::loadDevice((int) $r['id']), $it, null);
                $made++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }
        if (!$made && !$failed) {
            return '';
        }
        return "$made hand-added device" . ($made === 1 ? '' : 's') . ' created in ITFlow' . ($failed ? ", $failed failed" : '');
    }

    /** Sync status for a device page. */
    public static function status(int $deviceId): array
    {
        $st = DB::all('SELECT field, pending, last_error, align_changed_at FROM itflow_sync_state WHERE device_id = ? AND pending = 1', [$deviceId]);
        return [
            'pending' => $st,
            'history' => DB::all('SELECT c.*, u.name AS user_name FROM device_changes c LEFT JOIN users u ON u.id = c.user_id
                WHERE c.device_id = ? ORDER BY c.id DESC LIMIT 25', [$deviceId]),
            'poll' => DB::one('SELECT * FROM itflow_poll_state WHERE id = 1'),
        ];
    }
}
