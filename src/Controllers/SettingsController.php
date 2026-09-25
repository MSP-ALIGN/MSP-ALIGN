<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Integrations\Itflow;
use Align\Integrations\NinjaOne;
use Align\Integrations\Warranty\Dell;
use Align\Integrations\Warranty\Lenovo;
use Align\Settings;
use Align\View;

final class SettingsController
{
    public const TEXT = ['ninja_client_id', 'itflow_url', 'dell_client_id'];
    public const SECRETS = ['ninja_client_secret', 'itflow_api_key', 'dell_client_secret', 'lenovo_client_id'];
    public const NUMBERS = [
        'lifespan_desktop' => [1, 20], 'lifespan_laptop' => [1, 20], 'lifespan_server' => [1, 20], 'lifespan_network' => [1, 20],
        'cost_desktop' => [0, 1000000], 'cost_laptop' => [0, 1000000], 'cost_server' => [0, 1000000], 'cost_network' => [0, 1000000],
        'warranty_warn_days' => [1, 730], 'eol_plan_months' => [1, 60], 'stale_days' => [1, 365], 'warranty_recheck_days' => [1, 365],
    ];

    public static function index(): void
    {
        Auth::requireRole('admin');
        $values = [];
        foreach (array_merge(self::TEXT, array_keys(self::NUMBERS), ['ninja_instance', 'itflow_writeback']) as $k) {
            $values[$k] = Settings::get($k);
        }
        $secrets = [];
        foreach (self::SECRETS as $k) {
            $secrets[$k] = Settings::hasSecret($k);
        }
        View::render('settings/index', [
            'title' => 'Settings',
            'nav' => 'settings',
            'v' => $values,
            'secrets' => $secrets,
        ]);
    }

    public static function save(): void
    {
        Auth::requireRole('admin');
        $changed = [];
        foreach (self::TEXT as $k) {
            $val = post($k);
            if ($k === 'itflow_url' && $val !== '') {
                $val = rtrim($val, '/');
                if (!filter_var($val, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $val)) {
                    flash('error', 'ITFlow URL must look like https://itflow.example.com');
                    redirect('/settings');
                }
            }
            if ($val !== (string) Settings::get($k)) {
                Settings::set($k, $val);
                $changed[] = $k;
            }
        }
        $instance = post('ninja_instance');
        if (isset(NinjaOne::INSTANCES[$instance]) && $instance !== Settings::get('ninja_instance')) {
            Settings::set('ninja_instance', $instance);
            $changed[] = 'ninja_instance';
        }
        $wb = post('itflow_writeback');
        if (in_array($wb, ['off', 'fill_empty', 'overwrite'], true) && $wb !== Settings::get('itflow_writeback')) {
            Settings::set('itflow_writeback', $wb);
            $changed[] = 'itflow_writeback';
        }
        foreach (self::SECRETS as $k) {
            if (isset($_POST["clear_$k"])) {
                Settings::clearSecret($k);
                $changed[] = "$k (cleared)";
            } elseif (($v = (string) ($_POST[$k] ?? '')) !== '') {
                Settings::setSecret($k, trim($v));
                $changed[] = $k;
            }
        }
        foreach (self::NUMBERS as $k => [$min, $max]) {
            $v = post($k);
            if ($v === '' || !is_numeric($v)) {
                continue;
            }
            $v = (string) max($min, min($max, (float) $v));
            $v = rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
            if ($v !== Settings::get($k)) {
                Settings::set($k, $v);
                $changed[] = $k;
            }
        }
        if ($changed) {
            Audit::log('settings.save', implode(', ', $changed));
        }
        flash('success', $changed ? 'Settings saved.' : 'No changes.');
        redirect('/settings');
    }

    public static function test(): void
    {
        Auth::requireRole('admin');
        $target = post('target');
        $label = ['ninja' => 'NinjaOne', 'itflow' => 'ITFlow', 'dell' => 'Dell', 'lenovo' => 'Lenovo'][$target] ?? $target;
        try {
            $msg = match ($target) {
                'ninja' => NinjaOne::fromSettings()->test(),
                'itflow' => Itflow::fromSettings()->test(),
                'dell' => self::testDell(),
                'lenovo' => self::testLenovo(),
                default => throw new \RuntimeException('Unknown integration'),
            };
            flash('success', "$label: $msg");
        } catch (\Throwable $e) {
            flash('error', "$label test failed: " . $e->getMessage());
        }
        redirect('/settings');
    }

    private static function testDell(): string
    {
        $id = Settings::get('dell_client_id');
        $secret = Settings::secret('dell_client_secret');
        if (!$id || !$secret) {
            throw new \RuntimeException('Dell client ID and secret are not set.');
        }
        (new Dell($id, $secret, Settings::get('dell_api_base') ?: 'https://apigtwb2c.us.dell.com'))->lookup(['TEST000']);
        return 'Connected. Token issued and lookup endpoint responded.';
    }

    private static function testLenovo(): string
    {
        $id = Settings::secret('lenovo_client_id');
        if (!$id) {
            throw new \RuntimeException('Lenovo ClientID is not set.');
        }
        $r = (new Lenovo($id, Settings::get('lenovo_api_base') ?: 'https://supportapi.lenovo.com'))->lookup(['TEST0000']);
        $res = $r['TEST0000'] ?? null;
        if ($res && $res->status === 'error') {
            throw new \RuntimeException((string) $res->message);
        }
        return 'Lookup endpoint responded.';
    }

    public static function os(): void
    {
        Auth::requireRole('admin');
        View::render('settings/os', [
            'title' => 'OS support dates',
            'nav' => 'settings',
            'rows' => DB::all('SELECT * FROM os_support ORDER BY eos_date DESC, label'),
        ]);
    }

    public static function osSave(): void
    {
        Auth::requireRole('admin');
        $valid = fn(array $r) => trim($r['label'] ?? '') !== '' && trim($r['name_contains'] ?? '') !== ''
            && preg_match('/^\d+$/', trim($r['build'] ?? '')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['eos_date'] ?? '');
        $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
        foreach ($rows as $id => $r) {
            if (!is_array($r)) {
                continue;
            }
            if (!empty($r['delete'])) {
                DB::run('DELETE FROM os_support WHERE id = ?', [(int) $id]);
            } elseif ($valid($r)) {
                DB::run('UPDATE os_support SET label = ?, name_contains = ?, build = ?, eos_date = ? WHERE id = ?', [
                    trim($r['label']), trim($r['name_contains']), trim($r['build']), $r['eos_date'], (int) $id,
                ]);
            }
        }
        $new = is_array($_POST['new'] ?? null) ? $_POST['new'] : [];
        if (array_filter(array_map('trim', array_map('strval', $new)))) {
            if ($valid($new)) {
                DB::insert('os_support', [
                    'label' => trim($new['label']),
                    'name_contains' => trim($new['name_contains']),
                    'build' => trim($new['build']),
                    'eos_date' => $new['eos_date'],
                ]);
            } else {
                flash('error', 'New row skipped: fill in every field (build is the number only, e.g. 26100).');
            }
        }
        Audit::log('settings.os_support');
        flash('success', 'OS support dates saved.');
        redirect('/settings/os');
    }
}
