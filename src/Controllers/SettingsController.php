<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Settings;
use Align\View;

final class SettingsController
{
    public const TEXT = ['company_name', 'company_phone', 'company_email', 'company_website', 'report_footer', 'source_url'];
    public const NUMBERS = [
        'lifespan_desktop' => [1, 20], 'lifespan_laptop' => [1, 20], 'lifespan_server' => [1, 20], 'lifespan_network' => [1, 20],
        'cost_desktop' => [0, 1000000], 'cost_laptop' => [0, 1000000], 'cost_server' => [0, 1000000], 'cost_network' => [0, 1000000],
        'lifespan_printer' => [1, 20], 'lifespan_storage' => [1, 20], 'lifespan_power' => [1, 20], 'lifespan_other' => [1, 20],
        'cost_printer' => [0, 1000000], 'cost_storage' => [0, 1000000], 'cost_power' => [0, 1000000], 'cost_other' => [0, 1000000],
        'meeting_default_minutes' => [15, 480], 'fiscal_year_start' => [1, 12],
        'warranty_warn_days' => [1, 730], 'eol_plan_months' => [1, 60], 'stale_days' => [1, 365], 'warranty_recheck_days' => [1, 365],
        'session_idle_minutes' => [5, 60], 'session_max_hours' => [1, 24],
    ];
    private const LOCALE_DEFAULTS = ['locale_currency' => 'USD', 'locale_currency_position' => '', 'locale_number' => 'comma', 'locale_date' => 'mdy', 'locale_time' => '12', 'locale_week_start' => '0'];

    /** Currency & dates (1.38): setting => allowed values. */
    public static function localeChoices(): array
    {
        return [
            'locale_currency' => array_keys(\Align\Fmt::CURRENCIES), 'locale_currency_position' => ['', 'before', 'after'],
            'locale_number' => array_keys(\Align\Fmt::NUMBERS), 'locale_date' => array_keys(\Align\Fmt::DATES),
            'locale_time' => array_map('strval', array_keys(\Align\Fmt::TIMES)), 'locale_week_start' => array_map('strval', array_keys(\Align\Fmt::WEEK)),
        ];
    }

    /** Old settings-page addresses that moved in 1.15. */
    public const MOVED = ['ninja' => 'ninjaone', 'itflow' => 'itflow', 'veeam' => 'veeam', 'dell' => 'dell', 'lenovo' => 'lenovo'];

    private static function values(): array
    {
        $values = [];
        foreach (array_merge(self::TEXT, array_keys(self::NUMBERS), ['plan_start', 'timezone'], array_keys(self::localeChoices())) as $k) {
            $values[$k] = Settings::get($k);
        }
        return $values;
    }

    public static function index(): void
    {
        Auth::requireRole('admin');
        View::render('settings/index', ['title' => 'Settings', 'nav' => 'settings', 'v' => self::values()]);
    }

    public static function planning(): void
    {
        Auth::requireRole('admin');
        View::render('settings/planning', ['title' => 'Planning & lifecycle', 'nav' => 'settings', 'v' => self::values()]);
    }

    /** Saves whichever settings the posted tab contains (fields not on the form are left alone). */
    public static function save(): void
    {
        Auth::requireRole('admin');
        $back = post('_tab') === 'planning' ? '/settings/planning' : '/settings';
        $changed = [];
        // Checked before anything is saved, so a refused form changes nothing
        if (isset($_POST['timezone']) && post('timezone') !== '' && !\Align\Fmt::validZone(post('timezone'))) {
            flash('error', 'Choose a timezone from the list.');
            redirect(setup_return($back));
        }
        if (post('source_url') !== '' && (!filter_var(post('source_url'), FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', post('source_url')))) {
            flash('error', 'The source code link must be a web address starting with https://.');
            redirect(setup_return($back));
        }
        foreach (self::TEXT as $k) {
            if (isset($_POST[$k]) && ($val = post($k)) !== (string) Settings::get($k)) {
                Settings::set($k, $val);
                $changed[] = $k;
            }
        }
        foreach (self::localeChoices() as $k => $allowed) {
            // compared with what's in use (a first save of the defaults isn't a change)
            if (isset($_POST[$k]) && in_array(post($k), $allowed, true) && post($k) !== (string) Settings::get($k, self::LOCALE_DEFAULTS[$k])) {
                Settings::set($k, post($k));
                $changed[] = $k;
            }
        }
        if (isset($_POST['timezone'])) {
            $tz = post('timezone');
            if ($tz !== (string) Settings::get('timezone', '')) {
                Settings::set('timezone', $tz);
                $changed[] = 'timezone';
            }
        }
        $ps = post('plan_start');
        if (in_array($ps, ['current', 'next'], true) && $ps !== Settings::get('plan_start')) {
            Settings::set('plan_start', $ps);
            $changed[] = 'plan_start';
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
        if (setup_return('') !== '' && (isset($_POST['locale_currency']) || isset($_POST['timezone']))) {
            SetupController::markSeen('locale'); // saved from the wizard, even if the defaults were kept
        }
        flash('success', $changed ? 'Settings saved.' : 'No changes.');
        redirect(setup_return(setup_return($back), 'return_ok'));
    }

    /** The integration Test buttons moved to Integrations (1.15). */
    public static function test(): void
    {
        Auth::requireRole('admin');
        redirect('/integrations/' . (self::MOVED[post('target')] ?? ''));
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
