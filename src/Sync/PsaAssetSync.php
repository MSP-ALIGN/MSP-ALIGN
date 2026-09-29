<?php
declare(strict_types=1);

namespace Align\Sync;

use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Providers\Providers;
use Align\Providers\Psa\PsaProvider;
use Align\Settings;

/**
 * Two-way asset sync between Align devices and the PSA's assets.
 *
 * PSAs rarely have webhooks, so changes made there are picked up by polling (every 2 minutes via the
 * msp-align-psa timer, running `align psa:poll`). Changes made in Align are pushed the
 * moment they're saved.
 *
 * For every linked device and field we keep the last value both systems agreed on (the baseline).
 * A side changed a field when its value no longer matches the baseline:
 *   - only the PSA changed  -> copy into Align
 *   - only Align changed    -> push to the PSA
 *   - both changed          -> the most recent edit wins; the losing value is kept in device_changes
 * Fields Align doesn't own for a device (hardware facts on RMM devices) are never pushed.
 *
 * The PSA's assets are cached in psa_assets as neutral records (see PsaProvider).
 */
final class PsaAssetSync
{
    public const LOCK = 'mountaineer_align_itflow'; // name kept from before 1.34, so a poll still running during an update can't overlap the new one

    /** field => [label, asset field written to the PSA (null = pulled from the PSA only)] */
    public const FIELDS = [
        'name' => ['Name', 'name'],
        'type' => ['Type', 'type'],
        'make' => ['Make', 'make'],
        'model' => ['Model', 'model'],
        'serial' => ['Serial', 'serial'],
        'os' => ['OS / firmware', 'os'],
        'purchase' => ['Purchase date', 'purchase_date'],
        'warranty' => ['Warranty end', 'warranty_expire'],
        'retired' => ['Status', 'status'],
        'ip' => ['IP address', null],
        'location' => ['Location', null],
    ];

    /** Import categories a PSA asset can fall in (PsaProvider::mapAssetType), for the "bring in" setting. */
    public const IMPORT_CATEGORIES = [
        'network' => 'Network gear (firewalls, routers, switches, access points)',
        'printer' => 'Printers & copiers',
        'ups' => 'UPS / battery backup',
        'storage' => 'NAS / storage',
        'camera' => 'Cameras / NVR',
        'phone' => 'Phones',
        'server' => 'Servers & hosts not in your RMM',
        'workstation' => 'Desktops & laptops not in your RMM',
        'vm' => 'Virtual machines not in your RMM',
        'other' => 'Everything else → Unassigned, to categorize in Align (types Align doesn\'t know, displays, tablets…)',
    ];

    private const COMPUTER_CLASSES = ['desktop', 'laptop', 'server'];

    public static function twoWay(): bool
    {
        return Settings::get('psa_two_way', '1') === '1' && Providers::psaSupports('assets.write');
    }

    public static function createsAssets(): bool
    {
        return self::twoWay() && Settings::get('psa_create_assets', '1') === '1' && Providers::psaSupports('assets.create');
    }

    /** Align owns the hardware fields of hand-added and PSA-imported devices; the RMM owns them otherwise. */
    public static function owns(array $d): bool
    {
        return in_array($d['source'], ['manual', 'psa'], true);
    }

    // ---- Field access -------------------------------------------------------------------------

    private static function norm(mixed $v): string
    {
        return trim((string) ($v ?? ''));
    }

    /** A field's value in a psa_assets row. */
    public static function psaValue(string $f, array $a, ?PsaProvider $p = null): string
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
            'retired' => ((int) $a['is_archived'] === 1 || ($p ?? self::provider())?->statusRetired($a['status'])) ? '1' : '0',
            'ip' => self::norm($a['ip_address']),
            'location' => self::norm($a['location_name']),
        };
    }

    /** The PSA provider for reading type and status rules (no network calls). */
    private static function provider(): ?PsaProvider
    {
        static $p = false;
        if ($p === false) {
            try {
                $p = Providers::psa();
            } catch (\Throwable) {
                $p = null;
            }
        }
        return $p;
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

    /** Whether an Align value and a PSA value mean the same thing. */
    private static function same(PsaProvider $p, string $f, string $al, string $psa, array $a): bool
    {
        if ($f === 'type') {
            if ($al === Lifecycle::UNASSIGNED) {
                return true; // not categorized yet: nothing to tell the PSA
            }
            if (strcasecmp((string) $p->assetTypeFor($al), $psa) === 0) {
                return true;
            }
            return $p->mapAssetType(['type' => $psa] + $a)[0] === $al;
        }
        if ($f === 'serial') {
            return (string) normalize_serial($al) === (string) normalize_serial($psa);
        }
        return $al === $psa;
    }

    /** Value sent to the PSA for a field, and the PSA-side value it becomes (the new baseline). */
    private static function toPsa(PsaProvider $p, string $f, string $al): array
    {
        return match ($f) {
            'type' => [$p->assetTypeFor($al) ?? $p->assetTypeFor('Other') ?? 'Other', $p->assetTypeFor($al) ?? $p->assetTypeFor('Other') ?? 'Other'],
            'retired' => [$p->assetStatus($al === '1'), $al],
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

    // ---- Applying PSA values in Align -----------------------------------------------------------

    private static function setType(int $id, string $type): void
    {
        [$class, , $virtual] = Lifecycle::TYPES[$type] ?? Lifecycle::TYPES['Other'];
        DB::run('UPDATE devices SET device_type = ?, device_class = ?, is_virtual = ? WHERE id = ?', [$type, $class, $virtual ? 1 : 0, $id]);
        DB::run('UPDATE device_overrides SET device_type = NULL WHERE device_id = ?', [$id]);
    }

    /** Writes a PSA value into Align. Returns false when nothing needed to change. */
    private static function apply(PsaProvider $p, string $f, array $d, string $v, array $a): bool
    {
        $id = (int) $d['id'];
        $own = self::owns($d);
        $col = fn(string $c, ?string $val) => DB::run("UPDATE devices SET `$c` = ? WHERE id = ?", [$val === '' ? null : $val, $id]);
        switch ($f) {
            case 'type':
                $mapped = $p->mapAssetType(['type' => $v] + $a)[0];
                if ($mapped === Lifecycle::UNASSIGNED && self::effectiveType($d) !== Lifecycle::UNASSIGNED) {
                    return false; // the PSA's type is vaguer than what Align already knows
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
                // Clear Align's own date so the PSA date is the one used.
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
        foreach (DB::all('SELECT * FROM psa_sync_state WHERE device_id = ?', [$deviceId]) as $r) {
            $out[$r['field']] = $r;
        }
        return $out;
    }

    /** Records a value Align itself wrote to the PSA (e.g. warranty write-back) so it isn't seen as a PSA edit. */
    public static function acknowledge(int $deviceId, string $f, string $value): void
    {
        DB::run('INSERT INTO psa_sync_state (device_id, field, base_value) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE base_value = IF(pending = 1, base_value, VALUES(base_value))', [$deviceId, $f, $value]);
    }

    private static function setBase(int $deviceId, string $f, string $base): void
    {
        DB::run('INSERT INTO psa_sync_state (device_id, field, base_value, align_changed_at, pending, last_error) VALUES (?, ?, ?, NULL, 0, NULL)
            ON DUPLICATE KEY UPDATE base_value = VALUES(base_value), align_changed_at = NULL, pending = 0, last_error = NULL', [$deviceId, $f, $base]);
    }

    private static function markPending(int $deviceId, string $f, ?string $error): void
    {
        DB::run('INSERT INTO psa_sync_state (device_id, field, base_value, align_changed_at, pending, last_error) VALUES (?, ?, NULL, NOW(), 1, ?)
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

    /** After a save in Align: stamp the fields that changed so a newer PSA edit can't silently beat them. */
    public static function recordAlignEdit(int $deviceId, array $before): void
    {
        $after = self::snapshot($deviceId);
        foreach ($after as $f => $v) {
            if ($v !== ($before[$f] ?? null)) {
                DB::run('INSERT INTO psa_sync_state (device_id, field, base_value, align_changed_at, pending) VALUES (?, ?, NULL, NOW(), 1)
                    ON DUPLICATE KEY UPDATE align_changed_at = NOW(), pending = 1', [$deviceId, $f]);
            }
        }
        DB::run('UPDATE devices SET updated_at = NOW() WHERE id = ?', [$deviceId]);
    }

    public static function loadDevice(int $id): ?array
    {
        return DB::one('SELECT d.*, o.device_type AS o_type, o.purchase_date AS o_purchase, o.warranty_end AS o_warranty, o.updated_at AS o_updated,
                c.psa_id AS client_psa_id
            FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
            LEFT JOIN clients c ON c.id = d.client_id WHERE d.id = ?', [$id]);
    }

    // ---- Reconciling one device --------------------------------------------------------------

    /**
     * @param PsaProvider $p  used for type and status rules
     * @param bool $online    whether pushes can be sent (false = queue them)
     * @return array{pulled:int,pushed:int,conflicts:int,error:?string}
     */
    public static function reconcileDevice(array $d, array $a, PsaProvider $p, bool $online = true, ?int $userId = null): array
    {
        $id = (int) $d['id'];
        $n = $p->name();
        $twoWay = self::twoWay();
        $state = self::state($id);
        $res = ['pulled' => 0, 'pushed' => 0, 'conflicts' => 0, 'error' => null];
        $payload = [];
        $pushes = [];
        $alignTsFirst = max((string) ($d['updated_at'] ?? ''), (string) ($d['o_updated'] ?? '')) ?: (string) $d['created_at'];

        foreach (self::FIELDS as $f => [$label, $key]) {
            $psa = self::psaValue($f, $a, $p);
            $st = $state[$f] ?? null;
            $al = $key === null ? null : self::alignValue($f, $d);

            if ($key === null) { // pulled from the PSA only
                // On first link an empty PSA value never wipes what Align already has.
                if (($st === null ? $psa !== '' : $psa !== (string) $st['base_value']) && self::owns($d) && self::alignCurrent($f, $d) !== $psa) {
                    $old = self::alignCurrent($f, $d);
                    if (self::apply($p, $f, $d, $psa, $a)) {
                        if ($st !== null) { // don't log the first fill-in
                            self::log($id, $f, $old, $psa, 'from_psa');
                            $res['pulled']++;
                        }
                        $d = self::loadDevice($id) ?? $d;
                    }
                }
                self::setBase($id, $f, $psa);
                continue;
            }

            $conflict = false;
            if ($st === null || $st['base_value'] === null && !$st['pending']) {
                // First time this device and asset are compared.
                if ($al === null || self::same($p, $f, $al, $psa, $a)) {
                    self::setBase($id, $f, $psa);
                    continue;
                }
                $psaTs = (string) ($a['updated_at'] ?? '');
                $alignWins = $twoWay && ($psaTs === '' || $alignTsFirst >= $psaTs);
                $note = 'Values differed when first linked; ' . ($alignWins ? 'Align' : $n) . ' had the newer edit';
            } else {
                $base = $st['base_value'];
                $psaChanged = $base === null ? false : $psa !== (string) $base;
                $alChanged = $al !== null && ($base === null ? (bool) $st['pending'] : !self::same($p, $f, $al, (string) $base, $a));
                if ($base === null && $alChanged && self::same($p, $f, (string) $al, $psa, $a)) {
                    $alChanged = false;
                }
                if (!$psaChanged && !$alChanged) {
                    if ($st['pending'] || $base === null) {
                        self::setBase($id, $f, $psa);
                    }
                    continue;
                }
                if ($psaChanged && !$alChanged) {
                    $old = $al ?? (string) $base;
                    if (self::apply($p, $f, $d, $psa, $a) || in_array($f, ['purchase', 'warranty'], true)) {
                        self::log($id, $f, $old, $psa, 'from_psa');
                        $res['pulled']++;
                        $d = self::loadDevice($id) ?? $d;
                    }
                    self::setBase($id, $f, $psa);
                    continue;
                }
                if ($alChanged && !$psaChanged) {
                    if (!$twoWay) {
                        continue; // two-way sync is off: keep Align's edit, don't push it
                    }
                    $alignWins = true;
                    $note = null;
                } else {
                    if (self::same($p, $f, (string) $al, $psa, $a)) {
                        self::setBase($id, $f, $psa);
                        continue;
                    }
                    $alTs = (string) ($st['align_changed_at'] ?? date('Y-m-d H:i:s'));
                    $psaTs = (string) ($a['updated_at'] ?? '');
                    $alignWins = $twoWay && ($psaTs === '' || $alTs >= $psaTs);
                    $conflict = true;
                    $note = $alignWins
                        ? "Changed in both; Align edit was newer. $n value \"" . self::display($f, $psa) . '" was replaced'
                        : "Changed in both; $n edit was newer. Align value \"" . self::display($f, $al) . '" was replaced';
                }
            }

            if ($alignWins) {
                [$send, $newBase] = self::toPsa($p, $f, (string) $al);
                $payload[$key] = $send;
                $pushes[$f] = ['old' => $psa, 'new' => $al, 'base' => $newBase, 'send' => $send, 'conflict' => $conflict, 'note' => $note];
            } else {
                if (self::apply($p, $f, $d, $psa, $a) || in_array($f, ['purchase', 'warranty'], true)) {
                    self::log($id, $f, $al, $psa, 'from_psa', $conflict, $note);
                    $res['pulled']++;
                    $res['conflicts'] += $conflict ? 1 : 0;
                    $d = self::loadDevice($id) ?? $d;
                }
                self::setBase($id, $f, $psa);
            }
        }

        if ($pushes) {
            try {
                if (!$online) {
                    throw new \RuntimeException("$n not reachable");
                }
                if (!$p->updateAsset((string) $a['psa_client_id'], (string) $a['psa_asset_id'], $payload)) {
                    throw new \RuntimeException("$n rejected the update (does the API key user have write access to assets?)");
                }
                foreach ($pushes as $f => $push) {
                    self::setBase($id, $f, $push['base']);
                    DB::run('UPDATE psa_assets SET `' . self::FIELDS[$f][1] . '` = ? WHERE psa_asset_id = ?', [$push['send'] === '' ? null : $push['send'], $a['psa_asset_id']]);
                    self::log($id, $f, $push['old'], $push['new'], 'to_psa', $push['conflict'], $push['note'], $userId);
                    $res['pushed']++;
                    $res['conflicts'] += $push['conflict'] ? 1 : 0;
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
     * Called right after a device is saved in Align. Creates or updates the PSA asset.
     * @return array{status:string,message:string}  status: ok|off|queued|error
     */
    public static function pushDevice(int $id, ?int $userId = null): array
    {
        if (!self::twoWay()) {
            return ['status' => 'off', 'message' => ''];
        }
        $d = self::loadDevice($id);
        if (!$d || (int) $d['psa_sync'] !== 1) {
            return ['status' => 'off', 'message' => ''];
        }
        if (!$d['psa_asset_id'] && !($d['source'] === 'manual' && self::createsAssets() && $d['client_psa_id'] && !$d['removed_at'])) {
            return ['status' => 'off', 'message' => ''];
        }
        $n = Providers::psaName();
        if ((int) DB::value('SELECT GET_LOCK(?, 8)', [self::LOCK]) !== 1) {
            return ['status' => 'queued', 'message' => "$n sync is busy; your change will be sent within 2 minutes."];
        }
        try {
            $p = Providers::psa(true);
            if (!$d['psa_asset_id']) {
                $assetId = self::createAsset($d, $p, $userId);
                return ['status' => 'ok', 'message' => "Created in $n (asset #$assetId)."];
            }
            $asset = $p->asset((string) $d['psa_asset_id']);
            if (!$asset) {
                return ['status' => 'error', 'message' => "The linked $n asset no longer exists. The next sync will retire or relink this device."];
            }
            $row = self::cacheOne($asset);
            $r = self::reconcileDevice(self::loadDevice($id), $row, $p, true, $userId);
            if ($r['error']) {
                return ['status' => 'queued', 'message' => "Couldn't reach $n (" . $r['error'] . '). The change is queued and will be retried automatically.'];
            }
            $bits = [];
            if ($r['pushed']) {
                $bits[] = $r['pushed'] . ' field' . ($r['pushed'] === 1 ? '' : 's') . " sent to $n";
            }
            if ($r['pulled']) {
                $bits[] = $r['pulled'] . " newer $n change" . ($r['pulled'] === 1 ? '' : 's') . ' pulled in';
            }
            return ['status' => 'ok', 'message' => $bits ? ucfirst(implode(', ', $bits)) . '.' : "$n is already up to date."];
        } catch (\Throwable $e) {
            return ['status' => 'queued', 'message' => "Couldn't reach $n (" . $e->getMessage() . '). The change is queued and will be retried automatically.'];
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    private static function createAsset(array $d, PsaProvider $p, ?int $userId): string
    {
        $fields = ['name' => (string) $d['display_name'], 'status' => $p->assetStatus(false)];
        foreach (['type', 'make', 'model', 'serial', 'os', 'purchase', 'warranty'] as $f) {
            $v = self::alignValue($f, $d);
            if ($v !== null && $v !== '') {
                $fields[self::FIELDS[$f][1]] = self::toPsa($p, $f, $v)[0];
            }
        }
        $fields['type'] ??= $p->assetTypeFor('Other') ?? 'Other';
        $assetId = $p->createAsset((string) $d['client_psa_id'], $fields);
        DB::run('UPDATE devices SET psa_asset_id = ? WHERE id = ?', [$assetId, $d['id']]);
        $row = [
            'psa_asset_id' => $assetId, 'psa_client_id' => (string) $d['client_psa_id'],
            'name' => $fields['name'], 'type' => $fields['type'], 'make' => $fields['make'] ?? null,
            'model' => $fields['model'] ?? null, 'serial' => normalize_serial($fields['serial'] ?? null),
            'os' => $fields['os'] ?? null, 'purchase_date' => $fields['purchase_date'] ?? null,
            'warranty_expire' => $fields['warranty_expire'] ?? null, 'status' => $fields['status'], 'is_archived' => 0,
            'ip_address' => $d['ip_address'], 'location_name' => $d['location'], 'updated_at' => date('Y-m-d H:i:s'), 'synced_at' => date('Y-m-d H:i:s'),
        ];
        DB::upsert('psa_assets', $row, ['psa_asset_id']);
        $full = DB::one('SELECT * FROM psa_assets WHERE psa_asset_id = ?', [$assetId]);
        foreach (array_keys(self::FIELDS) as $f) {
            self::setBase((int) $d['id'], $f, self::psaValue($f, $full, $p));
        }
        self::log((int) $d['id'], 'name', null, (string) $d['display_name'], 'created', false, 'Created ' . $p->name() . " asset #$assetId", $userId);
        return $assetId;
    }

    /** Stores one freshly read asset in the cache and returns the cache row. */
    private static function cacheOne(array $a): array
    {
        $prev = DB::one('SELECT location_id, location_name FROM psa_assets WHERE psa_asset_id = ?', [ext_id($a['id'])]);
        $row = self::cacheRow($a, date('Y-m-d H:i:s'));
        $loc = $row['location_id'];
        $row['location_name'] = $prev && ext_id($prev['location_id']) === ext_id($loc) ? $prev['location_name'] : ($loc ? ($prev['location_name'] ?? null) : null);
        DB::upsert('psa_assets', $row, ['psa_asset_id']);
        return DB::one('SELECT * FROM psa_assets WHERE psa_asset_id = ?', [ext_id($a['id'])]);
    }

    /** A psa_assets row from a neutral asset record. */
    private static function cacheRow(array $a, string $now, array $locations = []): array
    {
        return [
            'psa_asset_id' => ext_id($a['id']),
            'psa_client_id' => ext_id($a['client_id'] ?? null),
            'name' => $a['name'] ?? null,
            'type' => $a['type'] ?? null,
            'make' => $a['make'] ?? null,
            'model' => $a['model'] ?? null,
            'serial' => normalize_serial($a['serial'] ?? null),
            'purchase_date' => $a['purchase_date'] ?? null,
            'warranty_expire' => $a['warranty_expire'] ?? null,
            'install_date' => $a['install_date'] ?? null,
            'status' => $a['status'] ?? null,
            'is_archived' => !empty($a['archived']) ? 1 : 0,
            'ip_address' => $a['ip_address'] ?? null,
            'mac' => $a['mac'] ?? null,
            'os' => $a['os'] ?? null,
            'description' => $a['description'] ?? null,
            'location_id' => ext_id($a['location_id'] ?? null) ?: null,
            'location_name' => $locations[ext_id($a['location_id'] ?? null)] ?? null,
            'updated_at' => $a['updated_at'] ?? null,
            'synced_at' => $now,
        ];
    }

    /**
     * Full cycle: read all PSA assets, link and import them, reconcile every linked device,
     * push anything queued, and create PSA assets for hand-added devices.
     */
    public static function run(PsaProvider $p, ?callable $log = null): string
    {
        $say = $log ?? fn(string $m) => null;
        $n = $p->name();
        if ((int) DB::value('SELECT GET_LOCK(?, 60)', [self::LOCK]) !== 1) {
            throw new \RuntimeException("Another $n sync is still running.");
        }
        try {
            $assets = $p->assets();
            $cached = (int) DB::value('SELECT COUNT(*) FROM psa_assets');
            if (!$assets && $cached > 0) {
                throw new \RuntimeException("$n returned no assets (Align has $cached). Nothing was changed; check the API key's permissions.");
            }
            $locations = [];
            if ($p->supports('locations')) {
                try {
                    foreach ($p->locations() as $l) {
                        $locations[ext_id($l['id'] ?? null)] = (string) ($l['name'] ?? '');
                    }
                } catch (\Throwable $e) {
                    $say("$n locations not readable (" . $e->getMessage() . '); continuing without them');
                }
            }
            $now = date('Y-m-d H:i:s');
            DB::transaction(function () use ($assets, $now, $locations) {
                $ids = [];
                foreach ($assets as $a) {
                    if (ext_id($a['id'] ?? null) !== '') {
                        DB::upsert('psa_assets', self::cacheRow($a, $now, $locations), ['psa_asset_id']);
                        $ids[] = ext_id($a['id']);
                    }
                }
                // Anything the PSA no longer returns is gone (by ID, so two runs in the same second can't miss it)
                if ($ids) {
                    DB::run('DELETE FROM psa_assets WHERE psa_asset_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
                }
            });
            $parts = [count($assets) . ' assets read'];
            try {
                $parts[] = SyncRunner::syncClientDetails($p);
            } catch (\Throwable $e) {
                $say('Client details not refreshed: ' . $e->getMessage());
            }
            if ($p->supports('licenses')) {
                try {
                    $parts[] = \Align\Licensing\Licenses::syncFromPsa($p);
                } catch (\Throwable $e) {
                    $say('Licenses not refreshed: ' . $e->getMessage());
                    $parts[] = 'licenses not refreshed (' . $e->getMessage() . ')';
                }
            }
            $parts[] = self::linkDevices();
            $parts[] = self::importAssets($p);
            $parts[] = self::reconcileAll($p);
            if (self::createsAssets()) {
                $parts[] = self::createMissing($p);
            }
            $result = implode('; ', array_filter($parts));
            DB::run('UPDATE psa_poll_state SET last_run = NOW(), last_ok = NOW(), last_result = ? WHERE id = 1', [mb_substr($result, 0, 500)]);
            return $result;
        } catch (\Throwable $e) {
            DB::run('UPDATE psa_poll_state SET last_run = NOW(), last_result = ? WHERE id = 1', [mb_substr('ERROR: ' . $e->getMessage(), 0, 500)]);
            throw $e;
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /** Links RMM / hand-added devices to PSA assets by serial, then by name. Existing links are kept. */
    private static function linkDevices(): string
    {
        $claimed = array_flip(array_map('strval', array_column(DB::all(
            "SELECT DISTINCT d.psa_asset_id FROM devices d JOIN psa_assets a ON a.psa_asset_id = d.psa_asset_id
             WHERE d.source IN ('rmm','manual') AND (d.removed_at IS NULL OR d.retired_at IS NOT NULL)"), 'psa_asset_id')));
        $bySerial = [];
        $byName = [];
        foreach (DB::all('SELECT psa_asset_id, psa_client_id, name, serial FROM psa_assets WHERE is_archived = 0') as $a) {
            if (isset($claimed[(string) $a['psa_asset_id']])) {
                continue;
            }
            if ($a['serial']) {
                $bySerial[$a['psa_client_id']][$a['serial']][] = (string) $a['psa_asset_id'];
            }
            if ($a['name']) {
                $byName[$a['psa_client_id']][strtolower(trim($a['name']))][] = (string) $a['psa_asset_id'];
            }
        }
        $devices = DB::all('SELECT d.id, d.serial, d.display_name, d.system_name, COALESCE(cm.psa_id, cn.psa_id) AS psa_client_id
            FROM devices d ' . Lifecycle::CLIENT_JOIN . "
            LEFT JOIN psa_assets a ON a.psa_asset_id = d.psa_asset_id
            WHERE d.removed_at IS NULL AND d.source IN ('rmm','manual') AND a.psa_asset_id IS NULL");
        $linked = 0;
        foreach ($devices as $dv) {
            $cid = ext_id($dv['psa_client_id']);
            if ($cid === '') {
                continue;
            }
            $assetId = null;
            if ($dv['serial'] && count($bySerial[$cid][$dv['serial']] ?? []) === 1) {
                $assetId = $bySerial[$cid][$dv['serial']][0];
            } else {
                foreach ([$dv['system_name'], $dv['display_name']] as $nm) {
                    $k = strtolower(trim((string) $nm));
                    if ($k !== '' && count($byName[$cid][$k] ?? []) === 1) {
                        $assetId = $byName[$cid][$k][0];
                        break;
                    }
                }
            }
            if ($assetId !== null && !isset($claimed[$assetId])) {
                DB::run('UPDATE devices SET psa_asset_id = ? WHERE id = ?', [$assetId, $dv['id']]);
                DB::run('DELETE FROM psa_sync_state WHERE device_id = ?', [$dv['id']]);
                $claimed[$assetId] = true;
                $linked++;
            }
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM devices WHERE source IN ('rmm','manual') AND removed_at IS NULL AND psa_asset_id IS NOT NULL");
        return "$total devices linked" . ($linked ? " ($linked new)" : '');
    }

    /** Adds PSA assets that no Align device covers. Unknown types land in Unassigned. */
    private static function importAssets(PsaProvider $p): string
    {
        $cats = array_filter(array_map('trim', explode(',', (string) Settings::get('psa_import_types', 'network,printer,ups,storage,camera,phone,server,workstation,vm,other'))));
        $claimed = array_flip(array_map('strval', array_column(DB::all(
            "SELECT DISTINCT psa_asset_id FROM devices WHERE source IN ('rmm','manual') AND psa_asset_id IS NOT NULL
             AND (removed_at IS NULL OR retired_at IS NOT NULL)"), 'psa_asset_id')));
        $clients = array_column(DB::all('SELECT id, psa_id FROM clients WHERE psa_id IS NOT NULL'), 'id', 'psa_id');
        // Serials already covered by an RMM / hand-added device, per client (avoids importing duplicates)
        $serials = [];
        foreach (DB::all("SELECT COALESCE(cm.id, cn.id) AS cid, d.serial FROM devices d " . Lifecycle::CLIENT_JOIN . "
                WHERE d.source IN ('rmm','manual') AND d.removed_at IS NULL AND d.serial IS NOT NULL") as $r) {
            $serials[$r['cid']][$r['serial']] = true;
        }
        $existing = [];
        foreach (DB::all("SELECT id, psa_asset_id, client_id, removed_at, retired_at FROM devices WHERE source = 'psa'") as $r) {
            $existing[(string) $r['psa_asset_id']] = $r;
        }
        $now = date('Y-m-d H:i:s');
        $added = [];
        $hidden = 0;
        $unassigned = 0;
        $n = $p->name();
        DB::transaction(function () use ($p, $n, $cats, $claimed, $clients, $serials, $existing, $now, &$added, &$hidden, &$unassigned) {
            foreach (DB::all('SELECT * FROM psa_assets') as $a) {
                $aid = (string) $a['psa_asset_id'];
                [$type, $cat] = $p->mapAssetType($a);
                $clientId = $clients[$a['psa_client_id']] ?? null;
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
                if (!$eligible || (int) $a['is_archived'] === 1 || $p->statusRetired($a['status'])) {
                    continue;
                }
                [$class, , $virtual] = Lifecycle::TYPES[$type];
                $computer = in_array($class, self::COMPUTER_CLASSES, true);
                DB::insert('devices', [
                    'source' => 'psa',
                    'psa_asset_id' => $aid,
                    'client_id' => (int) $clientId,
                    'display_name' => $a['name'] ?: "$n asset $aid",
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
        $count = array_sum($added);
        $waiting = (int) DB::value("SELECT COUNT(*) FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
            WHERE d.removed_at IS NULL AND COALESCE(o.device_type, d.device_type) = 'Unassigned'");
        return ($count ? "$count new asset" . ($count === 1 ? '' : 's') . ' imported' : 'no new assets')
            . ($hidden ? ", $hidden hidden (type turned off or client not mapped)" : '')
            . ($waiting ? ", $waiting unassigned waiting to be categorized" : '');
    }

    /** Reconciles every linked device; retires devices whose asset was deleted in the PSA. */
    private static function reconcileAll(PsaProvider $p): string
    {
        $n = $p->name();
        $rows = DB::all("SELECT d.id, d.source, d.psa_asset_id, a.psa_asset_id AS present
            FROM devices d LEFT JOIN psa_assets a ON a.psa_asset_id = d.psa_asset_id
            WHERE d.psa_asset_id IS NOT NULL AND d.psa_sync = 1 AND (d.removed_at IS NULL OR d.retired_at IS NOT NULL)");
        $tot = ['pulled' => 0, 'pushed' => 0, 'conflicts' => 0, 'errors' => 0, 'gone' => 0];
        $lastError = null;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (!$r['present']) {
                $d = self::loadDevice($id);
                if (self::owns($d)) {
                    if ($d['retired_at'] && $d['source'] === 'psa') {
                        continue; // already retired on an earlier run
                    }
                    if (!$d['retired_at']) {
                        DB::run('UPDATE devices SET retired_at = NOW(), removed_at = COALESCE(removed_at, NOW()) WHERE id = ?', [$id]);
                        self::log($id, 'retired', '0', '1', 'from_psa', false, "$n asset #{$r['psa_asset_id']} was deleted in $n");
                    }
                    if ($d['source'] === 'manual') {
                        DB::run('UPDATE devices SET psa_asset_id = NULL WHERE id = ?', [$id]);
                    }
                } else {
                    DB::run('UPDATE devices SET psa_asset_id = NULL WHERE id = ?', [$id]);
                }
                DB::run('DELETE FROM psa_sync_state WHERE device_id = ?', [$id]);
                $tot['gone']++;
                continue;
            }
            $d = self::loadDevice($id);
            $a = DB::one('SELECT * FROM psa_assets WHERE psa_asset_id = ?', [$r['psa_asset_id']]);
            $res = self::reconcileDevice($d, $a, $p);
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
            $bits[] = "{$tot['pulled']} changes from $n";
        }
        if ($tot['pushed']) {
            $bits[] = "{$tot['pushed']} changes to $n";
        }
        if ($tot['conflicts']) {
            $bits[] = "{$tot['conflicts']} conflicts resolved (newest wins)";
        }
        if ($tot['gone']) {
            $bits[] = "{$tot['gone']} assets deleted in $n";
        }
        if ($tot['errors']) {
            $bits[] = "{$tot['errors']} devices couldn't be pushed ($lastError) and will be retried";
        }
        return $out . ($bits ? ' (' . implode(', ', $bits) . ')' : ', all in step');
    }

    /** Creates PSA assets for hand-added devices whose client is linked to the PSA. */
    private static function createMissing(PsaProvider $p): string
    {
        $rows = DB::all("SELECT d.id FROM devices d JOIN clients c ON c.id = d.client_id
            WHERE d.source = 'manual' AND d.psa_asset_id IS NULL AND d.psa_sync = 1 AND d.removed_at IS NULL AND c.psa_id IS NOT NULL AND c.is_demo = 0");
        $made = 0;
        $failed = 0;
        foreach ($rows as $r) {
            try {
                self::createAsset(self::loadDevice((int) $r['id']), $p, null);
                $made++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }
        if (!$made && !$failed) {
            return '';
        }
        return "$made hand-added device" . ($made === 1 ? '' : 's') . ' created in ' . $p->name() . ($failed ? ", $failed failed" : '');
    }

    /** Sync status for a device page. */
    public static function status(int $deviceId): array
    {
        $st = DB::all('SELECT field, pending, last_error, align_changed_at FROM psa_sync_state WHERE device_id = ? AND pending = 1', [$deviceId]);
        return [
            'pending' => $st,
            'history' => DB::all('SELECT c.*, u.name AS user_name FROM device_changes c LEFT JOIN users u ON u.id = c.user_id
                WHERE c.device_id = ? ORDER BY c.id DESC LIMIT 25', [$deviceId]),
            'poll' => DB::one('SELECT * FROM psa_poll_state WHERE id = 1'),
        ];
    }
}
