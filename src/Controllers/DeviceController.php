<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Settings;
use Align\Sync\PsaAssetSync;
use Align\View;

final class DeviceController
{
    public static function find(int $id): array
    {
        $rows = (new Lifecycle())->devices(null, true, $id);
        if (!$rows) {
            http_response_code(404);
            View::render('error', ['title' => 'Device not found', 'message' => 'That device does not exist.']);
            exit;
        }
        return $rows[0];
    }

    public static function show(int $id): void
    {
        Auth::require();
        $d = self::find($id);
        $client = $d['client_id'] ? ClientController::load((int) $d['client_id']) : null;
        \Align\Audit::access('device', "#$id {$d['name']}" . ($client ? " ({$client['name']})" : ''));
        View::render('devices/show', [
            'title' => $d['name'],
            'nav' => $client ? 'clients' : 'devices',
            'client' => $client,
            'clientNav' => 'devices',
            'd' => $d,
            'lookup' => $d['serial'] ? DB::one('SELECT * FROM warranty_lookups WHERE serial = ? ORDER BY looked_up_at DESC LIMIT 1', [$d['serial']]) : null,
            'rmmUrl' => \Align\Providers\Providers::rmmDeviceLink($d['rmm_provider'] ?? null, $d['rmm_device_id'] ?? null),
            'rmmName' => \Align\Providers\Providers::rmmName($d['rmm_provider'] ?? null),
            'sync' => PsaAssetSync::status($id),
            'syncRow' => DB::one('SELECT psa_sync, retired_at, updated_at FROM devices WHERE id = ?', [$id]),
            'twoWay' => PsaAssetSync::twoWay(),
            'clientInPsa' => $client && !empty($client['psa_id']),
            'backups' => $client && \Align\Backup\Backup::has($client) ? DB::all('SELECT * FROM backup_workloads WHERE device_id = ? ORDER BY last_point DESC', [$id]) : null,
            'backupExempt' => DB::one('SELECT e.*, u.name AS created_by_name FROM backup_exemptions e LEFT JOIN users u ON u.id = e.created_by
                WHERE e.device_id = ? OR e.item_uid IN (SELECT uid FROM backup_workloads WHERE device_id = ?) LIMIT 1', [$id, $id]),
        ]);
    }

    /** Flashes the result of pushing a device to the PSA. */
    private static function flashPush(array $r, string $saved = 'Saved.'): void
    {
        match ($r['status']) {
            'ok' => flash('success', trim("$saved " . $r['message'])),
            'queued', 'error' => flash('warning', trim("$saved " . $r['message'])),
            default => flash('success', $saved),
        };
    }

    private static function date(string $k): ?string
    {
        $v = post($k);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    /** A chosen replacement quarter, stored as the quarter's first day (or null for "automatic"). */
    private static function quarter(string $v): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? (\Align\Roadmap\Plan::quarterFor($v)['start'] ?? null) : null;
    }

    /** Saves just the planned replacement for some devices. */
    private static function setReplacement(array $ids, ?string $on, ?string $note): void
    {
        foreach ($ids as $id) {
            DB::run('INSERT INTO device_overrides (device_id, replace_on, replace_note, updated_by) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE replace_on = VALUES(replace_on), replace_note = VALUES(replace_note), updated_by = VALUES(updated_by)',
                [(int) $id, $on, $on ? $note : null, Auth::id()]);
        }
    }

    private static function replacementInput(): array
    {
        $on = self::quarter(post('replace_on'));
        return [$on, $on ? (mb_substr(post('replace_note'), 0, 255) ?: null) : null, $on ? \Align\Roadmap\Plan::quarterFor($on)['label'] : null];
    }

    /** Device page: set or clear the planned replacement quarter. */
    public static function replacement(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        [$on, $note, $label] = self::replacementInput();
        self::setReplacement([$id], $on, $note);
        Audit::log('device.replacement', $d['name'] . ': ' . ($label ? "planned for $label" . ($note ? " ($note)" : '') : 'back to end of life'));
        flash('success', ($label ? "Replacement planned for $label. " : 'Replacement follows the end-of-life date again. ') . self::planNote($id));
        redirect("/devices/$id");
    }

    /** Client devices list: set or clear the planned replacement for the ticked devices. */
    public static function bulkReplacement(int $id): void
    {
        $clientId = $id;
        Auth::requireRole('tech');
        $client = ClientController::load($clientId);
        $mine = array_column((new Lifecycle())->devices($clientId), 'name', 'id');
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($i) => isset($mine[$i])));
        $back = '/clients/' . $clientId . '/devices' . (post('return_query') !== '' && preg_match('/^[a-z0-9=&_%.-]*$/i', post('return_query')) ? '?' . post('return_query') : '');
        // The roadmap's drag and drop calls this with fetch() and wants JSON back
        $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        $fail = function (string $msg) use ($json, $back): never {
            if ($json) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $msg]);
                exit;
            }
            flash('error', $msg);
            redirect($back);
        };
        if (!$ids) {
            $fail('Tick the devices first.');
        }
        [$on, $note, $label] = self::replacementInput();
        if (post('replace_on') !== '' && !$on) {
            $fail('Choose a quarter.');
        }
        self::setReplacement($ids, $on, $note);
        $n = count($ids);
        Audit::log('device.replacement', $client['name'] . ': ' . $n . ' device' . ($n === 1 ? '' : 's') . ' ' . ($label ? "planned for $label" . ($note ? " ($note)" : '') : 'back to end of life') . ' (' . mb_strimwidth(implode(', ', array_map(fn($i) => $mine[$i], $ids)), 0, 400, '…') . ')');
        $msg = $label ? ($n === 1 ? $mine[$ids[0]] : "$n devices") . " will be replaced in $label. The roadmap and budget now use that quarter." : ($n === 1 ? $mine[$ids[0]] : "$n devices") . ' back on the end-of-life schedule.';
        flash('success', $msg);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'label' => $label, 'message' => $msg]);
            exit;
        }
        redirect($back);
    }

    private static function overrideRow(int $id): array
    {
        $cost = post('replacement_cost');
        $life = post('lifespan_years');
        $type = post('device_type');
        return [
            'device_id' => $id,
            'purchase_date' => self::date('purchase_date'),
            'warranty_end' => self::date('warranty_end'),
            'replacement_cost' => is_numeric($cost) && (float) $cost >= 0 ? round((float) $cost, 2) : null,
            'lifespan_years' => ctype_digit($life) && (int) $life > 0 && (int) $life < 30 ? (int) $life : null,
            'replace_on' => self::quarter(post('replace_on')),
            'replace_note' => self::quarter(post('replace_on')) ? (mb_substr(post('replace_note'), 0, 255) ?: null) : null,
            'excluded' => isset($_POST['excluded']) ? 1 : 0,
            'notes' => mb_substr(post('notes'), 0, 5000) ?: null,
            'device_type' => isset(Lifecycle::TYPES[$type]) ? $type : null,
            'updated_by' => Auth::id(),
        ];
    }

    /** Hardware fields that only manual devices can edit (the RMM owns them otherwise). */
    private static function manualFields(): array
    {
        $type = post('device_type');
        $type = isset(Lifecycle::TYPES[$type]) ? $type : 'Other';
        $s = fn(string $k, int $len = 190) => mb_substr(post($k), 0, $len) ?: null;
        $build = preg_replace('/\D/', '', post('os_build')) ?: null;
        return [
            'display_name' => $s('display_name', 255),
            'device_type' => $type,
            'device_class' => Lifecycle::TYPES[$type][0],
            'is_virtual' => Lifecycle::TYPES[$type][2] ? 1 : 0,
            'manufacturer' => $s('manufacturer'),
            'model' => $s('model'),
            'serial' => normalize_serial(post('serial')),
            'ip_address' => $s('ip_address', 64),
            'location' => $s('location'),
            'firmware' => $s('firmware'),
            'os_name' => $s('os_name', 255),
            'os_build' => $build,
        ];
    }

    public static function create(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = self::manualFields();
        if (!$f['display_name']) {
            flash('error', 'Device name is required.');
            redirect("/clients/$id/devices");
        }
        $deviceId = DB::transaction(function () use ($f, $id) {
            $deviceId = DB::insert('devices', $f + [
                'source' => 'manual',
                'client_id' => $id,
                'created_by' => Auth::id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $o = self::overrideRow($deviceId);
            $o['device_type'] = null; // type is stored on the device itself
            DB::upsert('device_overrides', $o, ['device_id']);
            return $deviceId;
        });
        Audit::log('device.create', "{$f['display_name']} ({$f['device_type']}) for {$client['name']}");
        if (isset($_POST['align_only'])) {
            DB::run('UPDATE devices SET psa_sync = 0 WHERE id = ?', [$deviceId]);
        }
        self::flashPush(PsaAssetSync::pushDevice($deviceId, Auth::id()), "Added {$f['display_name']}.");
        redirect(post('again') === '1' ? "/clients/$id/devices?add=1" : "/devices/$deviceId");
    }

    public static function save(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $before = PsaAssetSync::snapshot($id);
        DB::transaction(function () use ($d, $id) {
            $o = self::overrideRow($id);
            if (PsaAssetSync::owns($d)) {
                $f = self::manualFields();
                if (!$f['display_name']) {
                    $f['display_name'] = $d['display_name'];
                }
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
                DB::run("UPDATE devices SET $sets WHERE id = ?", [...array_values($f), $id]);
                $o['device_type'] = null;
            } elseif ($o['device_type'] === $d['device_type']) {
                $o['device_type'] = null; // same as what the RMM says: no override needed
            }
            DB::upsert('device_overrides', $o, ['device_id']);
        });
        PsaAssetSync::recordAlignEdit($id, $before);
        Audit::log('device.update', $d['name']);
        self::flashPush(PsaAssetSync::pushDevice($id, Auth::id()), 'Saved. ' . self::planNote($id));
        redirect("/devices/$id");
    }

    /** One line saying where the device's replacement cost now sits in the IT plan. */
    private static function planNote(int $id): string
    {
        $rows = (new Lifecycle())->devices(null, true, $id);
        if (!$rows || !$rows[0]['is_hardware']) {
            return '';
        }
        $d = $rows[0];
        $p = Lifecycle::placement($d);
        return $p['in_plan']
            ? money($d['replacement_cost']) . ' is budgeted in ' . $p['label'] . ' of the IT plan.'
            : 'Its ' . money($d['replacement_cost']) . ' replacement cost is not in the IT plan: ' . lcfirst($p['reason']) . ($p['fix'] ? '. ' . $p['fix'] . '.' : '.');
    }

    /** Sets a device's type (used by the Unassigned triage page). */
    public static function setType(array $d, string $type): void
    {
        [$class, , $virtual] = Lifecycle::TYPES[$type];
        if (PsaAssetSync::owns($d)) {
            DB::run('UPDATE devices SET device_type = ?, device_class = ?, is_virtual = ? WHERE id = ?', [$type, $class, $virtual ? 1 : 0, $d['id']]);
            DB::run('UPDATE device_overrides SET device_type = NULL WHERE device_id = ?', [$d['id']]);
        } else {
            DB::run('INSERT INTO device_overrides (device_id, device_type, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE device_type = VALUES(device_type), updated_by = VALUES(updated_by)',
                [$d['id'], $type === $d['device_type'] ? null : $type, Auth::id()]);
        }
    }

    /** Every client's devices (1.42): the same table and filters as a client's page, with a Client column. */
    public static function index(): void
    {
        Auth::require();
        $all = self::allClients();
        $filter = query('filter') === 'itflow' ? 'psa' : query('filter');
        $class = query('class');
        $cid = (int) query('client');
        if ($cid) {
            $all = array_values(array_filter($all, fn($d) => (int) $d['client_id'] === $cid));
        }
        $q = \Align\Paging::q();
        $rows = \Align\Paging::search(ClientController::filter($all, $filter, $class), $q, ClientController::DEVICE_SEARCH);
        $limit = \Align\Paging::limit();
        $count = fn(string $f) => count(ClientController::filter($all, $f, ''));
        View::render('devices/index', [
            'title' => 'Devices & assets',
            'nav' => 'devices',
            'devices' => array_slice($rows, 0, $limit),
            'matched' => count($rows),
            'total' => count($all),
            'limit' => $limit,
            'q' => $q,
            'filter' => $filter,
            'class' => $class,
            'clientId' => $cid,
            'clients' => DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'),
            // Each tile counts exactly what its view lists
            'tiles' => ['attention' => $count('attention'), 'replace' => $count('replace'), 'os' => $count('os'), 'warranty' => $count('warranty'), 'stale' => $count('stale')],
            'counts' => ['attention' => $count('attention'), 'unassigned' => $count('unassigned')],
        ]);
    }

    /** Devices of clients in planning, then devices whose RMM organization isn't linked to a client (last, so they can still be found). */
    private static function allClients(): array
    {
        $linked = $unlinked = [];
        foreach ((new Lifecycle())->devices() as $d) {
            if (!$d['client_id']) {
                $unlinked[] = $d;
            } elseif (!$d['client_inactive']) {
                $linked[] = $d;
            }
        }
        return array_merge($linked, $unlinked);
    }

    /** CSV of every client's devices, as filtered on the list. */
    public static function export(): void
    {
        Auth::require();
        $all = self::allClients();
        if ($cid = (int) query('client')) {
            $all = array_values(array_filter($all, fn($d) => (int) $d['client_id'] === $cid));
        }
        $filter = query('filter') === 'itflow' ? 'psa' : query('filter');
        $rows = \Align\Paging::search(ClientController::filter($all, $filter, query('class')), \Align\Paging::q(), ClientController::DEVICE_SEARCH);
        Audit::log('devices.export', count($rows) . ' devices');
        ClientController::csv($rows, 'devices-' . date('Y-m-d') . '.csv', true);
    }

    public static function unassigned(): void
    {
        Auth::require();
        $rows = array_values(array_filter((new Lifecycle())->devices(null, false, null, true), fn($d) => $d['type'] === Lifecycle::UNASSIGNED));
        $types = [];
        foreach (DB::all('SELECT psa_asset_id, type FROM psa_assets') as $a) {
            $types[(string) $a['psa_asset_id']] = $a['type'];
        }
        View::render('devices/unassigned', [
            'title' => 'Unassigned hardware',
            'nav' => 'unassigned',
            'rows' => $rows,
            'psaTypes' => $types,
        ]);
    }

    public static function bulkType(): void
    {
        Auth::requireRole('tech');
        $type = post('device_type');
        $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
        $back = \Align\Security::safePath(post('back'), '/devices/unassigned');
        if (!isset(Lifecycle::TYPES[$type]) || !$ids) {
            flash('error', 'Pick one or more devices and a type.');
            redirect($back);
        }
        $done = 0;
        $queued = 0;
        foreach ($ids as $id) {
            $d = PsaAssetSync::loadDevice($id);
            if (!$d) {
                continue;
            }
            $before = PsaAssetSync::snapshot($id);
            self::setType($d, $type);
            PsaAssetSync::recordAlignEdit($id, $before);
            $r = PsaAssetSync::pushDevice($id, Auth::id());
            $queued += in_array($r['status'], ['queued', 'error'], true) ? 1 : 0;
            $done++;
        }
        Audit::log('device.bulk_type', "$done devices set to $type");
        flash($queued ? 'warning' : 'success', "Set $done device" . ($done === 1 ? '' : 's') . " to $type."
            . ($queued ? " $queued couldn't reach " . psa_name() . " yet and will be sent automatically." : (PsaAssetSync::twoWay() ? ' ' . psa_name() . ' updated.' : '')));
        redirect($back);
    }

    /** Push now / pull latest from the PSA. */
    public static function push(int $id): void
    {
        Auth::requireRole('tech');
        self::find($id);
        self::flashPush(PsaAssetSync::pushDevice($id, Auth::id()), '');
        redirect("/devices/$id#sync");
    }

    /** Turns the PSA sync on or off for one device ("Align only"). */
    public static function toggleSync(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $on = post('on') === '1';
        DB::run('UPDATE devices SET psa_sync = ? WHERE id = ?', [$on ? 1 : 0, $id]);
        DB::run('DELETE FROM psa_sync_state WHERE device_id = ?', [$id]); // re-baseline when turned back on
        Audit::log('device.psa_sync', $d['name'] . ($on ? ' synced with ' . psa_name() : ' set to Align only'));
        if ($on) {
            self::flashPush(PsaAssetSync::pushDevice($id, Auth::id()), psa_name() . ' sync turned on.');
        } else {
            flash('success', 'This device is now Align-only. Changes won\'t be sent to or taken from ' . psa_name() . '.');
        }
        redirect("/devices/$id#sync");
    }

    /** Brings a retired device back (and marks the PSA asset Deployed). */
    public static function restore(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $before = PsaAssetSync::snapshot($id);
        DB::run('UPDATE devices SET retired_at = NULL, removed_at = NULL WHERE id = ?', [$id]);
        PsaAssetSync::recordAlignEdit($id, $before);
        Audit::log('device.restore', $d['name']);
        self::flashPush(PsaAssetSync::pushDevice($id, Auth::id()), "Restored {$d['name']}.");
        redirect("/devices/$id");
    }

    /**
     * Hand-added devices never sent to the PSA are deleted. Anything linked to the PSA is retired instead:
     * hidden in Align and marked Retired in the PSA, so nothing is permanently removed by sync.
     */
    public static function delete(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        if ($d['source'] === 'rmm') {
            $n = \Align\Providers\Providers::rmmName($d['rmm_provider']);
            flash('error', "$n devices disappear automatically when they leave $n. Use \"Exclude\" to hide one.");
            redirect("/devices/$id");
        }
        $back = $d['client_id'] ? "/clients/{$d['client_id']}/devices" : '/clients';
        if ($d['source'] === 'manual' && !$d['psa_asset_id']) {
            DB::run('DELETE FROM devices WHERE id = ?', [$id]);
            Audit::log('device.delete', $d['name']);
            flash('success', "Deleted {$d['name']}.");
            redirect($back);
        }
        $before = PsaAssetSync::snapshot($id);
        DB::run('UPDATE devices SET retired_at = NOW(), removed_at = COALESCE(removed_at, NOW()) WHERE id = ?', [$id]);
        PsaAssetSync::recordAlignEdit($id, $before);
        Audit::log('device.retire', $d['name']);
        $r = PsaAssetSync::pushDevice($id, Auth::id());
        self::flashPush($r, "Retired {$d['name']}." . ($r['status'] === 'ok' ? ' The ' . psa_name() . ' asset is marked Retired.' : ''));
        redirect("/devices/$id");
    }
}
