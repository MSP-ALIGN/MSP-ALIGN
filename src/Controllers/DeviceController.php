<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Settings;
use Align\Sync\ItflowSync;
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
        View::render('devices/show', [
            'title' => $d['name'],
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'devices',
            'd' => $d,
            'lookup' => $d['serial'] ? DB::one('SELECT * FROM warranty_lookups WHERE serial = ? ORDER BY looked_up_at DESC LIMIT 1', [$d['serial']]) : null,
            'itflowUrl' => Settings::get('itflow_url'),
            'ninjaInstance' => Settings::get('ninja_instance', 'app.ninjarmm.com'),
            'sync' => ItflowSync::status($id),
            'syncRow' => DB::one('SELECT itflow_sync, retired_at, updated_at FROM devices WHERE id = ?', [$id]),
            'twoWay' => ItflowSync::twoWay(),
            'clientInItflow' => $client && !empty($client['itflow_client_id']),
        ]);
    }

    /** Flashes the result of pushing a device to ITFlow. */
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
            'excluded' => isset($_POST['excluded']) ? 1 : 0,
            'notes' => mb_substr(post('notes'), 0, 5000) ?: null,
            'device_type' => isset(Lifecycle::TYPES[$type]) ? $type : null,
            'updated_by' => Auth::id(),
        ];
    }

    /** Hardware fields that only manual devices can edit (NinjaOne owns them otherwise). */
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
            DB::run('UPDATE devices SET itflow_sync = 0 WHERE id = ?', [$deviceId]);
        }
        self::flashPush(ItflowSync::pushDevice($deviceId, Auth::id()), "Added {$f['display_name']}.");
        redirect(post('again') === '1' ? "/clients/$id/devices?add=1" : "/devices/$deviceId");
    }

    public static function save(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $before = ItflowSync::snapshot($id);
        DB::transaction(function () use ($d, $id) {
            $o = self::overrideRow($id);
            if (ItflowSync::owns($d)) {
                $f = self::manualFields();
                if (!$f['display_name']) {
                    $f['display_name'] = $d['display_name'];
                }
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
                DB::run("UPDATE devices SET $sets WHERE id = ?", [...array_values($f), $id]);
                $o['device_type'] = null;
            } elseif ($o['device_type'] === $d['device_type']) {
                $o['device_type'] = null; // same as what NinjaOne says: no override needed
            }
            DB::upsert('device_overrides', $o, ['device_id']);
        });
        ItflowSync::recordAlignEdit($id, $before);
        Audit::log('device.update', $d['name']);
        self::flashPush(ItflowSync::pushDevice($id, Auth::id()), 'Saved. ' . self::planNote($id));
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
        if (ItflowSync::owns($d)) {
            DB::run('UPDATE devices SET device_type = ?, device_class = ?, is_virtual = ? WHERE id = ?', [$type, $class, $virtual ? 1 : 0, $d['id']]);
            DB::run('UPDATE device_overrides SET device_type = NULL WHERE device_id = ?', [$d['id']]);
        } else {
            DB::run('INSERT INTO device_overrides (device_id, device_type, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE device_type = VALUES(device_type), updated_by = VALUES(updated_by)',
                [$d['id'], $type === $d['device_type'] ? null : $type, Auth::id()]);
        }
    }

    public static function unassigned(): void
    {
        Auth::require();
        $rows = array_values(array_filter((new Lifecycle())->devices(), fn($d) => $d['type'] === Lifecycle::UNASSIGNED));
        $types = [];
        foreach (DB::all('SELECT itflow_asset_id, type FROM itflow_assets') as $a) {
            $types[(int) $a['itflow_asset_id']] = $a['type'];
        }
        View::render('devices/unassigned', [
            'title' => 'Unassigned hardware',
            'nav' => 'unassigned',
            'rows' => $rows,
            'itflowTypes' => $types,
        ]);
    }

    public static function bulkType(): void
    {
        Auth::requireRole('tech');
        $type = post('device_type');
        $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
        $back = str_starts_with(post('back'), '/') && !str_starts_with(post('back'), '//') ? post('back') : '/devices/unassigned';
        if (!isset(Lifecycle::TYPES[$type]) || !$ids) {
            flash('error', 'Pick one or more devices and a type.');
            redirect($back);
        }
        $done = 0;
        $queued = 0;
        foreach ($ids as $id) {
            $d = ItflowSync::loadDevice($id);
            if (!$d) {
                continue;
            }
            $before = ItflowSync::snapshot($id);
            self::setType($d, $type);
            ItflowSync::recordAlignEdit($id, $before);
            $r = ItflowSync::pushDevice($id, Auth::id());
            $queued += in_array($r['status'], ['queued', 'error'], true) ? 1 : 0;
            $done++;
        }
        Audit::log('device.bulk_type', "$done devices set to $type");
        flash($queued ? 'warning' : 'success', "Set $done device" . ($done === 1 ? '' : 's') . " to $type."
            . ($queued ? " $queued couldn't reach ITFlow yet and will be sent automatically." : (ItflowSync::twoWay() ? ' ITFlow updated.' : '')));
        redirect($back);
    }

    /** Push now / pull latest from ITFlow. */
    public static function push(int $id): void
    {
        Auth::requireRole('tech');
        self::find($id);
        self::flashPush(ItflowSync::pushDevice($id, Auth::id()), '');
        redirect("/devices/$id");
    }

    /** Turns ITFlow sync on or off for one device ("Align only"). */
    public static function toggleSync(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $on = post('on') === '1';
        DB::run('UPDATE devices SET itflow_sync = ? WHERE id = ?', [$on ? 1 : 0, $id]);
        DB::run('DELETE FROM itflow_sync_state WHERE device_id = ?', [$id]); // re-baseline when turned back on
        Audit::log('device.itflow_sync', $d['name'] . ($on ? ' synced with ITFlow' : ' set to Align only'));
        if ($on) {
            self::flashPush(ItflowSync::pushDevice($id, Auth::id()), 'ITFlow sync turned on.');
        } else {
            flash('success', 'This device is now Align-only. Changes won\'t be sent to or taken from ITFlow.');
        }
        redirect("/devices/$id");
    }

    /** Brings a retired device back (and marks the ITFlow asset Deployed). */
    public static function restore(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $before = ItflowSync::snapshot($id);
        DB::run('UPDATE devices SET retired_at = NULL, removed_at = NULL WHERE id = ?', [$id]);
        ItflowSync::recordAlignEdit($id, $before);
        Audit::log('device.restore', $d['name']);
        self::flashPush(ItflowSync::pushDevice($id, Auth::id()), "Restored {$d['name']}.");
        redirect("/devices/$id");
    }

    /**
     * Hand-added devices never sent to ITFlow are deleted. Anything linked to ITFlow is retired instead:
     * hidden in Align and marked Retired in ITFlow, so nothing is permanently removed by sync.
     */
    public static function delete(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        if ($d['source'] === 'ninja') {
            flash('error', 'NinjaOne devices disappear automatically when they leave NinjaOne. Use "Exclude" to hide one.');
            redirect("/devices/$id");
        }
        $back = $d['client_id'] ? "/clients/{$d['client_id']}/devices" : '/clients';
        if ($d['source'] === 'manual' && !$d['itflow_asset_id']) {
            DB::run('DELETE FROM devices WHERE id = ?', [$id]);
            Audit::log('device.delete', $d['name']);
            flash('success', "Deleted {$d['name']}.");
            redirect($back);
        }
        $before = ItflowSync::snapshot($id);
        DB::run('UPDATE devices SET retired_at = NOW(), removed_at = COALESCE(removed_at, NOW()) WHERE id = ?', [$id]);
        ItflowSync::recordAlignEdit($id, $before);
        Audit::log('device.retire', $d['name']);
        $r = ItflowSync::pushDevice($id, Auth::id());
        self::flashPush($r, "Retired {$d['name']}." . ($r['status'] === 'ok' ? ' The ITFlow asset is marked Retired.' : ''));
        redirect("/devices/$id");
    }
}
