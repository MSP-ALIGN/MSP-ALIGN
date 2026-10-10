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
 *
 * SECURITY: everything from the PSA is untrusted. cacheRow() keeps only text and real dates, cut to their columns,
 * so one odd asset can't stop the sync. An asset is imported or moved only into the Align client whose PSA id is
 * the asset's client_id, and devices are linked only to assets of their own client. Pushes go to the PSA only when
 * two-way sync is on, which a test server (Staging) turns off; StagingPsa refuses writes as well. Every run and push
 * holds the LOCK named lock, so a poll, a full sync and a save on a device page never work on the same assets at
 * once. Each change is kept in device_changes, and a run that changed anything is in the audit log (1.45).
 * Error text shown to people or stored goes through safe_error().
 */
final class PsaAssetSync
{
    public const LOCK = 'mountaineer_align_itflow'; // name kept from before 1.34, so a poll still running during an update can't overlap the new one

    /** Changes a run made that device_changes doesn't record (imports, moves, links): they get the run audited too. */
    private static int $changed = 0;

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

    /** Whether Align edits are pushed to the PSA: the setting is on and the PSA can take them (never on a test server). */
    public static function twoWay(): bool
    {
        return Settings::get('psa_two_way', '1') === '1' && Providers::psaSupports('assets.write');
    }

    /** Whether hand-added devices get a PSA asset made for them (two-way sync and the PSA can create assets). */
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

    /** A stored value as trimmed text ('' for null). */
    private static function norm(mixed $v): string
    {
        return trim((string) ($v ?? ''));
    }

    /**
     * Text from an outside system as stored: a string or number only (anything else is null), control characters
     * replaced by spaces (new lines and tabs kept when $multiline), trimmed and cut to $len characters; null when
     * empty. Never trust a remote field's type or size: one value too long for its column would stop the whole
     * sync (2.2.1). Also used by SyncRunner for PSA client names.
     */
    public static function text(mixed $v, int $len, bool $multiline = false): ?string
    {
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            return null;
        }
        $s = trim(preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/' : '/[\x00-\x1F\x7F]+/', ' ', (string) $v) ?? '');
        return $s === '' ? null : mb_substr($s, 0, $len);
    }

    /**
     * A date (Y-m-d), or with $time a date and time (Y-m-d H:i:s), from an outside system; null unless it is a real
     * one in that form (the column would refuse anything else, and with it the whole batch).
     */
    public static function when(mixed $v, bool $time): ?string
    {
        $re = $time ? '/^(\d{4})-(\d{2})-(\d{2}) (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D' : '/^(\d{4})-(\d{2})-(\d{2})$/D';
        return is_string($v) && preg_match($re, $v, $m) && (int) $m[1] >= 1000 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    /**
     * An outside id as stored (see ext_id()); '' when it isn't text or a number, or is longer than the 64-character
     * id columns. Never cut: a cut client id could equal another client's id. Also used by SyncRunner for RMM ids.
     */
    public static function id(mixed $v): string
    {
        $s = is_string($v) || is_int($v) ? ext_id($v) : '';
        return strlen($s) <= 64 ? $s : '';
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

    /** A device's type: its override, else its own type. */
    private static function effectiveType(array $d): string
    {
        return (string) ($d['o_type'] ?? $d['device_type'] ?? 'Other');
    }

    /** Desktops, laptops and servers keep an OS; everything else keeps firmware in that field. */
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
        if ($f === 'os' && mb_strlen($psa) > 190 && $al === mb_substr($psa, 0, 190)) {
            return true; // firmware is kept to its 190-character column (apply()): not an edit to push back
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

    /** A field value as people read it (Status: Retired / Active). Plain text: escape it in HTML. */
    public static function display(string $f, ?string $v): string
    {
        if ($f === 'retired') {
            return $v === '1' ? 'Retired' : ($v === '0' ? 'Active' : '');
        }
        return (string) $v;
    }

    // ---- Applying PSA values in Align -----------------------------------------------------------

    /** Sets an Align-owned device's type (and class and virtual flag), clearing any type override. */
    private static function setType(int $id, string $type): void
    {
        [$class, , $virtual] = Lifecycle::TYPES[$type] ?? Lifecycle::TYPES['Other'];
        DB::run('UPDATE devices SET device_type = ?, device_class = ?, is_virtual = ? WHERE id = ?', [$type, $class, $virtual ? 1 : 0, $id]);
        DB::run('UPDATE device_overrides SET device_type = NULL WHERE device_id = ?', [$id]);
    }

    /**
     * Writes a PSA value into Align. Returns false when nothing needed to change.
     * Hardware fields change only on devices Align owns; on RMM devices only the type override and the date
     * overrides do. $v comes from psa_assets (already cleaned); it is cut again to the narrower devices columns.
     */
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
            // devices.firmware holds 190 characters, the PSA's OS field up to 255 (a longer one stopped every poll)
            'os' => self::isComputer($d) ? $col('os_name', $v) : $col('firmware', mb_substr($v, 0, 190)),
            'ip' => $col('ip_address', mb_substr($v, 0, 64)),
            'location' => $col('location', mb_substr($v, 0, 190)),
        };
        return !($f === 'name' && $v === '');
    }

    // ---- State + history -----------------------------------------------------------------------

    /** A device's sync state rows by field. */
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

    /** Records the value both sides now agree on and clears any pending push or error for the field. */
    private static function setBase(int $deviceId, string $f, string $base): void
    {
        DB::run('INSERT INTO psa_sync_state (device_id, field, base_value, align_changed_at, pending, last_error) VALUES (?, ?, ?, NULL, 0, NULL)
            ON DUPLICATE KEY UPDATE base_value = VALUES(base_value), align_changed_at = NULL, pending = 0, last_error = NULL', [$deviceId, $f, $base]);
    }

    /** setBase(), skipped when the stored state already says exactly that (most fields on most runs). */
    private static function rebase(int $deviceId, string $f, string $base, ?array $st): void
    {
        if ($st !== null && $st['base_value'] === $base && !(int) $st['pending'] && $st['last_error'] === null && $st['align_changed_at'] === null) {
            return;
        }
        self::setBase($deviceId, $f, $base);
    }

    /** Queues a push that couldn't be sent; $error (safe text, shown on the device page) says why. */
    private static function markPending(int $deviceId, string $f, ?string $error): void
    {
        DB::run('INSERT INTO psa_sync_state (device_id, field, base_value, align_changed_at, pending, last_error) VALUES (?, ?, NULL, NOW(), 1, ?)
            ON DUPLICATE KEY UPDATE align_changed_at = COALESCE(align_changed_at, NOW()), pending = 1, last_error = VALUES(last_error)',
            [$deviceId, $f, $error === null ? null : mb_substr($error, 0, 255)]);
    }

    /**
     * One line of a device's sync history (device_changes): $dir is from_psa, to_psa or created; $userId is the
     * person whose save caused it (null for the poll, which run() audits as a whole).
     */
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

    /**
     * After a save in Align: stamp the fields that changed so a newer PSA edit can't silently beat them.
     * The caller has checked the person may edit the device and audits the save itself.
     */
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

    /** A device with its overrides (o_*) and its client's PSA id (client_psa_id), or null. */
    public static function loadDevice(int $id): ?array
    {
        return DB::one('SELECT d.*, o.device_type AS o_type, o.purchase_date AS o_purchase, o.warranty_end AS o_warranty, o.updated_at AS o_updated,
                c.psa_id AS client_psa_id
            FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
            LEFT JOIN clients c ON c.id = d.client_id WHERE d.id = ?', [$id]);
    }

    // ---- Reconciling one device --------------------------------------------------------------

    /**
     * Compares one linked device with its psa_assets row field by field against the baseline and copies each change
     * the way the class comment describes; pushes are sent together in one update. A push that fails is queued
     * (pending) with a safe error and retried by the next poll.
     * Callers hold LOCK. $state: the device's sync state when already read (null reads it).
     * @param PsaProvider $p  used for type and status rules, and to push
     * @param bool $online    whether pushes can be sent (false = queue them)
     * @return array{pulled:int,pushed:int,conflicts:int,error:?string}  error: safe text for people
     */
    public static function reconcileDevice(array $d, array $a, PsaProvider $p, bool $online = true, ?int $userId = null, ?array $state = null): array
    {
        $id = (int) $d['id'];
        $n = $p->name();
        $twoWay = self::twoWay();
        $state ??= self::state($id);
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
                self::rebase($id, $f, $psa, $state[$f] ?? null);
                continue;
            }

            $conflict = false;
            if ($st === null || $st['base_value'] === null && !$st['pending']) {
                // First time this device and asset are compared.
                if ($al === null || self::same($p, $f, $al, $psa, $a)) {
                    self::rebase($id, $f, $psa, $state[$f] ?? null);
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
                        self::rebase($id, $f, $psa, $state[$f] ?? null);
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
                    self::rebase($id, $f, $psa, $state[$f] ?? null);
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
                        self::rebase($id, $f, $psa, $state[$f] ?? null);
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
                self::rebase($id, $f, $psa, $state[$f] ?? null);
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
                    self::rebase($id, $f, $push['base'], $state[$f] ?? null);
                    DB::run('UPDATE psa_assets SET `' . self::FIELDS[$f][1] . '` = ? WHERE psa_asset_id = ?', [$push['send'] === '' ? null : $push['send'], $a['psa_asset_id']]);
                    self::log($id, $f, $push['old'], $push['new'], 'to_psa', $push['conflict'], $push['note'], $userId);
                    $res['pushed']++;
                    $res['conflicts'] += $push['conflict'] ? 1 : 0;
                }
            } catch (\Throwable $e) {
                $res['error'] = safe_error($e); // shown on the device page and in the sync log
                foreach (array_keys($pushes) as $f) {
                    self::markPending($id, $f, $res['error']);
                }
            }
        }
        return $res;
    }

    // ---- Entry points --------------------------------------------------------------------------

    /**
     * Called right after a device is saved in Align. Creates or updates the PSA asset.
     * The caller has checked the person may edit the device (tech or admin) and recorded the edit
     * (recordAlignEdit) and audit entry; $userId is that person, kept in the device's history.
     * @return array{status:string,message:string}  status: ok|off|queued|error; message: safe text for a flash
     */
    public static function pushDevice(int $id, ?int $userId = null): array
    {
        if (!self::twoWay()) {
            return ['status' => 'off', 'message' => ''];
        }
        $eligible = fn(?array $d) => $d && (int) $d['psa_sync'] === 1
            && ($d['psa_asset_id'] || ($d['source'] === 'manual' && self::createsAssets() && $d['client_psa_id'] && !$d['removed_at']));
        if (!$eligible(self::loadDevice($id))) {
            return ['status' => 'off', 'message' => ''];
        }
        $n = Providers::psaName();
        if ((int) DB::value('SELECT GET_LOCK(?, 8)', [self::LOCK]) !== 1) {
            return ['status' => 'queued', 'message' => "$n sync is busy; your change will be sent within 2 minutes."];
        }
        try {
            // Read again with the lock held: while this waited, a poll (or a second save) may have created the
            // asset or retired the device, and a stale copy would create a second asset in the PSA (2.2.1)
            $d = self::loadDevice($id);
            if (!$eligible($d)) {
                return ['status' => 'off', 'message' => ''];
            }
            $p = Providers::psa(true);
            if (!$d['psa_asset_id']) {
                $assetId = self::createAsset($d, $p, $userId);
                return ['status' => 'ok', 'message' => "Created in $n (asset #$assetId)."];
            }
            $asset = $p->asset((string) $d['psa_asset_id']);
            // Only the asset asked for: another record in the answer must not be cached or written to as this one
            if (!$asset || self::id($asset['id'] ?? null) !== (string) $d['psa_asset_id']) {
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
            return ['status' => 'queued', 'message' => "Couldn't reach $n (" . safe_error($e) . '). The change is queued and will be retried automatically.'];
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /**
     * Creates the PSA asset for a hand-added device in its own client's PSA client, links it and records the
     * baseline. Callers hold LOCK and have checked createsAssets() and that the device has no asset yet.
     * Returns the new asset id.
     */
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
        $assetId = self::id($p->createAsset((string) $d['client_psa_id'], $fields));
        if ($assetId === '') {
            throw new \RuntimeException($p->name() . ' did not return a usable id for the new asset.');
        }
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

    /** Stores one freshly read asset (its id already checked by the caller) in the cache and returns the cache row. */
    private static function cacheOne(array $a): array
    {
        $aid = self::id($a['id'] ?? null);
        $prev = DB::one('SELECT location_id, location_name FROM psa_assets WHERE psa_asset_id = ?', [$aid]);
        $row = self::cacheRow($a, date('Y-m-d H:i:s'));
        $loc = $row['location_id'];
        $row['location_name'] = $prev && ext_id($prev['location_id']) === ext_id($loc) ? $prev['location_name'] : ($loc ? ($prev['location_name'] ?? null) : null);
        DB::upsert('psa_assets', $row, ['psa_asset_id']);
        return DB::one('SELECT * FROM psa_assets WHERE psa_asset_id = ?', [$aid]);
    }

    /**
     * A psa_assets row from a neutral asset record. The record is untrusted: text is cut to its column (and loses
     * control characters), dates that aren't real dates become null, and an id that isn't a plain id becomes ''
     * (an asset with no usable client id is never imported) (2.2.1).
     */
    private static function cacheRow(array $a, string $now, array $locations = []): array
    {
        $loc = self::id($a['location_id'] ?? null);
        return [
            'psa_asset_id' => self::id($a['id'] ?? null),
            'psa_client_id' => self::id($a['client_id'] ?? null),
            'name' => self::text($a['name'] ?? null, 255),
            'type' => self::text($a['type'] ?? null, 100),
            'make' => self::text($a['make'] ?? null, 190),
            'model' => self::text($a['model'] ?? null, 190),
            'serial' => normalize_serial(self::text($a['serial'] ?? null, 190)),
            'purchase_date' => self::when($a['purchase_date'] ?? null, false),
            'warranty_expire' => self::when($a['warranty_expire'] ?? null, false),
            'install_date' => self::when($a['install_date'] ?? null, false),
            'status' => self::text($a['status'] ?? null, 100),
            'is_archived' => !empty($a['archived']) ? 1 : 0,
            'ip_address' => self::text($a['ip_address'] ?? null, 64),
            'mac' => self::text($a['mac'] ?? null, 64),
            'os' => self::text($a['os'] ?? null, 255),
            'description' => self::text($a['description'] ?? null, 16000, true), // TEXT: 64 KB, up to 4 bytes a character
            'location_id' => $loc !== '' ? $loc : null,
            'location_name' => $loc !== '' ? self::text($locations[$loc] ?? null, 255) : null,
            'updated_at' => self::when($a['updated_at'] ?? null, true),
            'synced_at' => $now,
        ];
    }

    /**
     * Full cycle: read all PSA assets, link and import them, reconcile every linked device,
     * push anything queued, and create PSA assets for hand-added devices.
     * Runs from the CLI only (`align psa:poll` every 2 minutes, and the full sync). Waits up to a minute for LOCK,
     * then throws. An empty read changes nothing. $log gets progress notes (safe text). Returns a short summary.
     */
    public static function run(PsaProvider $p, ?callable $log = null): string
    {
        $say = $log ?? fn(string $m) => null;
        $n = $p->name();
        if ((int) DB::value('SELECT GET_LOCK(?, 60)', [self::LOCK]) !== 1) {
            throw new \RuntimeException("Another $n sync is still running.");
        }
        $changesBefore = (int) DB::value('SELECT COALESCE(MAX(id), 0) FROM device_changes');
        self::$changed = 0;
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
                        $locations[self::id($l['id'] ?? null)] = self::text($l['name'] ?? null, 255) ?? '';
                    }
                } catch (\Throwable $e) {
                    $say("$n locations not readable (" . safe_error($e) . '); continuing without them');
                }
            }
            $now = date('Y-m-d H:i:s');
            DB::transaction(function () use ($assets, $now, $locations) {
                $ids = [];
                $rows = [];
                foreach ($assets as $a) {
                    $row = is_array($a) ? self::cacheRow($a, $now, $locations) : null;
                    if ($row && $row['psa_asset_id'] !== '') {
                        $rows[] = $row;
                        $ids[] = $row['psa_asset_id'];
                    }
                }
                DB::upsertMany('psa_assets', $rows, ['psa_asset_id']);
                // Anything the PSA no longer returns is gone (by ID, so two runs in the same second can't miss it)
                if ($ids) {
                    DB::run('DELETE FROM psa_assets WHERE psa_asset_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
                }
            });
            $parts = [count($assets) . ' assets read'];
            SyncRunner::$detailChanges = 0;
            \Align\Licensing\Licenses::$changes = 0;
            \Align\Vendors\Vendors::$changes = 0;
            try {
                $parts[] = SyncRunner::syncClientDetails($p);
            } catch (\Throwable $e) {
                $say('Client details not refreshed: ' . safe_error($e));
            }
            // 2.8.0 vendors before licenses, so a license links to a vendor that arrived in the same run
            if ($p->supports('vendors')) {
                try {
                    $parts[] = \Align\Vendors\Vendors::syncFromPsa($p);
                } catch (\Throwable $e) {
                    $msg = safe_error($e);
                    $say('Vendors not refreshed: ' . $msg);
                    $parts[] = 'vendors not refreshed (' . $msg . ')';
                }
            }
            if ($p->supports('licenses')) {
                try {
                    $parts[] = \Align\Licensing\Licenses::syncFromPsa($p);
                } catch (\Throwable $e) {
                    $msg = safe_error($e);
                    $say('Licenses not refreshed: ' . $msg);
                    $parts[] = 'licenses not refreshed (' . $msg . ')';
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
            // In the audit log when it changed something (every 2 minutes otherwise would bury the rest) (1.45).
            // Imports, client moves and new links count too: device_changes doesn't record them (2.2.1). So do
            // client contact details, contacts and licenses the poll changed: a compromised PSA account could
            // otherwise retire a client's software or change its primary contact with no trace (2.2.1)
            $changes = (int) DB::value('SELECT COUNT(*) FROM device_changes WHERE id > ? AND user_id IS NULL', [$changesBefore]) + self::$changed;
            $other = SyncRunner::$detailChanges + \Align\Licensing\Licenses::$changes + \Align\Vendors\Vendors::$changes;
            if ($changes + $other > 0) {
                \Align\Audit::log('sync.psa_poll', "$changes device change" . ($changes === 1 ? '' : 's')
                    . ($other ? ", $other client, contact, vendor or license change" . ($other === 1 ? '' : 's') : '') . " to or from $n: $result");
            }
            return $result;
        } catch (\Throwable $e) {
            $msg = safe_error($e); // last_result is shown on device pages and the PSA integration page
            $wasOk = !str_starts_with((string) DB::value('SELECT last_result FROM psa_poll_state WHERE id = 1'), 'ERROR');
            DB::run('UPDATE psa_poll_state SET last_run = NOW(), last_result = ? WHERE id = 1', [mb_substr('ERROR: ' . $msg, 0, 500)]);
            if ($wasOk) { // when it starts failing, not every 2 minutes after
                \Align\Audit::log('sync.psa_poll_failed', mb_substr($msg, 0, 300));
            }
            throw $e;
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /**
     * Links RMM / hand-added devices to PSA assets by serial, then by name. Existing links are kept.
     * Only within one client: a device is matched against the assets of its own client's PSA id, and only when
     * exactly one asset there has that serial or name. An asset is linked to one device at most.
     */
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
                self::$changed++;
            }
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM devices WHERE source IN ('rmm','manual') AND removed_at IS NULL AND psa_asset_id IS NOT NULL");
        return "$total devices linked" . ($linked ? " ($linked new)" : '');
    }

    /**
     * Adds PSA assets that no Align device covers. Unknown types land in Unassigned.
     * An asset is imported only for the Align client linked to its PSA client id; a device imported earlier follows
     * its asset to another client, and is hidden when its type is turned off or its client isn't linked any more.
     */
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
            $touched = [];
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
                            self::$changed++;
                        }
                    } else {
                        if ($ex['removed_at'] && !$ex['retired_at']) {
                            DB::run('UPDATE devices SET removed_at = NULL WHERE id = ?', [$ex['id']]);
                            self::$changed++;
                        }
                        if ((int) $ex['client_id'] !== (int) $clientId) {
                            // moved to another client in the PSA: in the audit log with the run (no device history line)
                            DB::run('UPDATE devices SET client_id = ? WHERE id = ?', [$clientId, $ex['id']]);
                            self::$changed++;
                        }
                        $touched[] = (int) $ex['id'];
                    }
                    continue;
                }
                if (!$eligible || (int) $a['is_archived'] === 1 || $p->statusRetired($a['status'])) {
                    continue;
                }
                [$class, , $virtual] = Lifecycle::TYPES[$type] ?? Lifecycle::TYPES['Other'];
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
                    // narrower than their psa_assets columns (190 against 255)
                    'location' => $a['location_name'] !== null ? mb_substr($a['location_name'], 0, 190) : null,
                    'firmware' => $computer || $a['os'] === null ? null : mb_substr($a['os'], 0, 190),
                    'os_name' => $computer ? $a['os'] : null,
                    'synced_at' => $now,
                    'created_at' => $now,
                ]);
                $added[$type] = ($added[$type] ?? 0) + 1;
                self::$changed++;
                if ($type === Lifecycle::UNASSIGNED) {
                    $unassigned++;
                }
            }
            foreach (array_chunk($touched, 1000) as $ids) {
                DB::run('UPDATE devices SET synced_at = ? WHERE id IN (' . implode(',', $ids) . ')', [$now]);
            }
        });
        $count = array_sum($added);
        $waiting = Lifecycle::unassignedCount(true);
        return ($count ? "$count new asset" . ($count === 1 ? '' : 's') . ' imported' : 'no new assets')
            . ($hidden ? ", $hidden hidden (type turned off or client not mapped)" : '')
            . ($waiting ? ", $waiting unassigned waiting to be categorized" : '');
    }

    /**
     * Reconciles every linked device; retires devices whose asset was deleted in the PSA.
     * A device imported from the PSA keeps its asset id and remembers that the PSA retired it, so if the asset
     * comes back (restored, or missing from one read) the PSA's status wins and Align never pushes "Retired" for it.
     */
    private static function reconcileAll(PsaProvider $p): string
    {
        $n = $p->name();
        $rows = DB::all("SELECT d.id, d.source, d.psa_asset_id, a.psa_asset_id AS present
            FROM devices d LEFT JOIN psa_assets a ON a.psa_asset_id = d.psa_asset_id
            WHERE d.psa_asset_id IS NOT NULL AND d.psa_sync = 1 AND (d.removed_at IS NULL OR d.retired_at IS NOT NULL)");
        $tot = ['pulled' => 0, 'pushed' => 0, 'conflicts' => 0, 'errors' => 0, 'gone' => 0];
        $lastError = null;
        // Everything the loop needs, read once (a query or three per device adds up at thousands of devices)
        $assets = [];
        foreach (DB::all('SELECT a.* FROM psa_assets a JOIN devices d ON d.psa_asset_id = a.psa_asset_id') as $a) {
            $assets[(string) $a['psa_asset_id']] = $a;
        }
        $states = [];
        $loadedAt = date('Y-m-d H:i:s');
        foreach (DB::all('SELECT s.* FROM psa_sync_state s JOIN devices d ON d.id = s.device_id WHERE d.psa_sync = 1') as $st) {
            $states[(int) $st['device_id']][$st['field']] = $st;
        }
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
                if (self::owns($d) && $d['source'] === 'psa') {
                    // Retired because of the PSA, not by an edit in Align: the baseline says so (2.2.1). Without it, an
                    // asset seen again was a first comparison, and a device edited after the asset pushed "Retired" to it
                    self::setBase($id, 'retired', '1');
                }
                $tot['gone']++;
                self::$changed++;
                continue;
            }
            $d = self::loadDevice($id);
            $a = $assets[(string) $r['psa_asset_id']] ?? DB::one('SELECT * FROM psa_assets WHERE psa_asset_id = ?', [$r['psa_asset_id']]);
            if (!$d || !$a) {
                continue;
            }
            // Edited in Align while this run was going (a push then waits for the lock): read its state fresh
            $fresh = max((string) ($d['updated_at'] ?? ''), (string) ($d['o_updated'] ?? '')) >= $loadedAt;
            $res = self::reconcileDevice($d, $a, $p, true, null, $fresh ? null : ($states[$id] ?? []));
            if ($res['pushed']) { // another device linked to the same asset must see what was just sent
                $assets[(string) $r['psa_asset_id']] = DB::one('SELECT * FROM psa_assets WHERE psa_asset_id = ?', [$r['psa_asset_id']]) ?? $a;
            }
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

    /**
     * Creates PSA assets for hand-added devices whose client is linked to the PSA (never demo clients). Callers hold
     * LOCK and have checked createsAssets(). A failure is counted and retried on the next run.
     */
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

    /** Sync status for a device page: pending pushes (with safe error text), the last 25 changes, and the poll state. */
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
