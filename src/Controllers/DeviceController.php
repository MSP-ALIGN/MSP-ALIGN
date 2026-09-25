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
    private static function find(int $id): array
    {
        $row = DB::one('SELECT id FROM devices WHERE id = ?', [$id]);
        if (!$row) {
            http_response_code(404);
            View::render('error', ['title' => 'Device not found', 'message' => 'That device does not exist.']);
            exit;
        }
        foreach ((new Lifecycle())->devices(null, true) as $d) {
            if ((int) $d['id'] === $id) {
                return $d;
            }
        }
        throw new \RuntimeException('Device could not be evaluated');
    }

    public static function show(int $id): void
    {
        Auth::require();
        $d = self::find($id);
        View::render('devices/show', [
            'title' => $d['name'],
            'nav' => 'clients',
            'd' => $d,
            'lookup' => $d['serial'] ? DB::one('SELECT * FROM warranty_lookups WHERE serial = ? ORDER BY looked_up_at DESC LIMIT 1', [$d['serial']]) : null,
            'itflowUrl' => Settings::get('itflow_url'),
            'ninjaInstance' => Settings::get('ninja_instance', 'app.ninjarmm.com'),
        ]);
    }

    public static function save(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $date = function (string $k): ?string {
            $v = post($k);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        };
        $cost = post('replacement_cost');
        $life = post('lifespan_years');
        $row = [
            'device_id' => $id,
            'purchase_date' => $date('purchase_date'),
            'warranty_end' => $date('warranty_end'),
            'replacement_cost' => is_numeric($cost) ? round((float) $cost, 2) : null,
            'lifespan_years' => ctype_digit($life) && (int) $life > 0 && (int) $life < 30 ? (int) $life : null,
            'excluded' => isset($_POST['excluded']) ? 1 : 0,
            'notes' => mb_substr(post('notes'), 0, 2000) ?: null,
            'updated_by' => Auth::id(),
        ];
        DB::upsert('device_overrides', $row, ['device_id']);
        Audit::log('device.override', $d['name'] . ' ' . json_encode(array_diff_key($row, ['device_id' => 1, 'updated_by' => 1])));
        flash('success', 'Saved.');
        redirect("/devices/$id");
    }
}
