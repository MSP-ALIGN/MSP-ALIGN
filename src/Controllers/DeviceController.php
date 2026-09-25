<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Settings;
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
        ]);
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
            'is_virtual' => Lifecycle::TYPES[$type][0] === 'virtual' ? 1 : 0,
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
        flash('success', "Added {$f['display_name']}.");
        redirect(post('again') === '1' ? "/clients/$id/devices?add=1" : "/devices/$deviceId");
    }

    public static function save(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        DB::transaction(function () use ($d, $id) {
            $o = self::overrideRow($id);
            if ($d['source'] === 'manual') {
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
        Audit::log('device.update', $d['name']);
        flash('success', 'Saved.');
        redirect("/devices/$id");
    }

    public static function delete(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        if ($d['source'] !== 'manual') {
            flash('error', 'NinjaOne devices are removed automatically when they leave NinjaOne. Use "Exclude" to hide one.');
            redirect("/devices/$id");
        }
        DB::run('DELETE FROM devices WHERE id = ?', [$id]);
        Audit::log('device.delete', $d['name']);
        flash('success', "Deleted {$d['name']}.");
        redirect($d['client_id'] ? "/clients/{$d['client_id']}/devices" : '/clients');
    }
}
