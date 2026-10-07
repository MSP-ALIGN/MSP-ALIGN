<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Settings;
use Align\View;

/**
 * Settings → General, Planning & lifecycle and OS support dates.
 *
 * Security assumptions: admins only; the Router has checked CSRF. Only the settings named here can be saved (never
 * a name from the request), each validated before anything is written: text is cut to a length, numbers are
 * clamped to their range, choices come from fixed lists, the timezone from PHP's list and the source link must be
 * http(s). No secret settings are read or written here. Changes are audited with the names of the settings changed.
 */
final class SettingsController
{
    public const TEXT = ['company_name', 'company_phone', 'company_email', 'company_website', 'report_footer', 'source_url'];
    /** Longest value kept for each TEXT setting (the setup wizard uses the same for the company fields). */
    private const TEXT_MAX = ['company_name' => 190, 'company_phone' => 60, 'company_email' => 190, 'company_website' => 190, 'report_footer' => 1000, 'source_url' => 500];
    /** setting => [min, max]. All are whole numbers except the cost_* amounts (see save()). */
    public const NUMBERS = [
        'lifespan_desktop' => [1, 20], 'lifespan_laptop' => [1, 20], 'lifespan_server' => [1, 20], 'lifespan_network' => [1, 20],
        'cost_desktop' => [0, 1000000], 'cost_laptop' => [0, 1000000], 'cost_server' => [0, 1000000], 'cost_network' => [0, 1000000],
        'lifespan_printer' => [1, 20], 'lifespan_storage' => [1, 20], 'lifespan_power' => [1, 20], 'lifespan_other' => [1, 20],
        'cost_printer' => [0, 1000000], 'cost_storage' => [0, 1000000], 'cost_power' => [0, 1000000], 'cost_other' => [0, 1000000],
        'meeting_default_minutes' => [15, 480], 'fiscal_year_start' => [1, 12],
        'warranty_warn_days' => [1, 730], 'eol_plan_months' => [1, 60], 'stale_days' => [1, 365], 'warranty_recheck_days' => [1, 365],
        'session_idle_minutes' => [5, 60], 'session_max_hours' => [1, 24], 'remember_2fa_days' => [0, \Align\Remember::MAX_DAYS],
        // 2.5.0 client health score: each area's weight (0 = left out) and where the bands start
        'health_weight_lifecycle' => [0, 100], 'health_weight_backups' => [0, 100], 'health_weight_compliance' => [0, 100],
        'health_weight_service' => [0, 100], 'health_weight_alignment' => [0, 100], 'health_good' => [2, 100], 'health_warn' => [1, 99],
    ];

    /** Numbers with no stored value until someone saves one: the value in use meanwhile (so saving it unchanged isn't a change). */
    private const NUMBER_DEFAULTS = [
        'health_weight_lifecycle' => \Align\Health\Health::DEFAULT_WEIGHT, 'health_weight_backups' => \Align\Health\Health::DEFAULT_WEIGHT,
        'health_weight_compliance' => \Align\Health\Health::DEFAULT_WEIGHT, 'health_weight_service' => \Align\Health\Health::DEFAULT_WEIGHT,
        'health_weight_alignment' => \Align\Health\Health::DEFAULT_WEIGHT, 'health_good' => \Align\Health\Health::DEFAULT_GOOD, 'health_warn' => \Align\Health\Health::DEFAULT_WARN,
    ];
    private const LOCALE_DEFAULTS = ['locale_currency' => 'USD', 'locale_currency_position' => '', 'locale_number' => 'comma', 'locale_date' => 'mdy', 'locale_time' => '12', 'locale_week_start' => '0'];

    /** Currency & dates (1.38): setting => allowed values (save() keeps nothing else). */
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

    /** The saved values the General and Planning forms show (plain settings only; no secrets). */
    private static function values(): array
    {
        $values = [];
        foreach (array_merge(self::TEXT, array_keys(self::NUMBERS), ['plan_start', 'timezone'], array_keys(self::localeChoices())) as $k) {
            $values[$k] = Settings::get($k, isset(self::NUMBER_DEFAULTS[$k]) ? (string) self::NUMBER_DEFAULTS[$k] : null);
        }
        return $values;
    }

    /** Settings → General. */
    public static function index(): void
    {
        Auth::requireRole('admin');
        View::render('settings/index', ['title' => 'Settings', 'nav' => 'settings', 'v' => self::values()]);
    }

    /** Settings → Planning & lifecycle. */
    public static function planning(): void
    {
        Auth::requireRole('admin');
        View::render('settings/planning', ['title' => 'Planning & lifecycle', 'nav' => 'settings', 'v' => self::values()]);
    }

    /**
     * Saves whichever settings the posted tab contains (fields not on the form are left alone). Also posted to by
     * the setup wizard's Currency & dates step, which it then goes back to (setup_return: /setup paths only).
     * Turning "remember this browser" off forgets every remembered browser, and any change to it raises a security
     * alert.
     */
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
        if (post('source_url') !== '' && (!filter_var(post('source_url'), FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', post('source_url'))
            || strlen(post('source_url')) > self::TEXT_MAX['source_url'])) {
            flash('error', 'The source code link must be a web address starting with https://.');
            redirect(setup_return($back));
        }
        // It goes into mailto: links, the terms and client emails: an address or nothing, as in the setup wizard (2.2.1)
        // Only when it changes: an address an older version accepted must not block saving the rest of the tab
        if (post('company_email') !== '' && post('company_email') !== (string) Settings::get('company_email')
            && (!filter_var(self::asciiEmail(post('company_email')), FILTER_VALIDATE_EMAIL) || strlen(post('company_email')) > self::TEXT_MAX['company_email'])) {
            flash('error', 'The company email must be an email address.');
            redirect(setup_return($back));
        }
        // 2.5.0: the health bands must stay in order (Needs attention starts below Healthy)
        if (is_numeric(post('health_good')) && is_numeric(post('health_warn')) && (int) post('health_warn') >= (int) post('health_good')) {
            flash('error', 'The health score\'s "Needs attention from" must be lower than "Healthy from".');
            redirect(setup_return($back));
        }
        // ...and at least one area must count, or no client would have a score
        $weights = array_map(fn($k) => post("health_weight_$k"), array_keys(\Align\Health\Health::PILLARS));
        if (count(array_filter($weights, 'is_numeric')) === count($weights) && !array_filter($weights, fn($w) => (int) $w > 0)) {
            flash('error', 'At least one area of the health score needs a weight above 0.');
            redirect(setup_return($back));
        }
        foreach (self::TEXT as $k) {
            // Cut to the column's purpose (2.2.1: any length was kept, up to the 64 KB the database refuses). A value
            // posted back unchanged is left as it is, even if an older version saved it longer.
            if (isset($_POST[$k]) && post($k) !== (string) Settings::get($k) && ($val = mb_substr(post($k), 0, self::TEXT_MAX[$k])) !== (string) Settings::get($k)) {
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
            $v = max($min, min($max, (float) $v));
            // Costs keep cents. Everything else is read back as a whole number (Settings::int), so it is stored as the
            // whole number that is used (2.2.1): "0.4" days was saved as typed, read as 0 (off), and skipped the
            // "off forgets every remembered browser" kill switch below, so the browsers came back when it was raised
            $v = str_starts_with($k, 'cost_') ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : (string) (int) $v;
            if ($v !== Settings::get($k, isset(self::NUMBER_DEFAULTS[$k]) ? (string) self::NUMBER_DEFAULTS[$k] : null)) {
                if ($k === 'remember_2fa_days') {
                    // It changes what a sign-in needs (1.45.1): old and new value in the log, a security alert, and
                    // turning it off forgets every remembered browser (a kill switch, not a pause)
                    $was = (string) Settings::get($k, (string) \Align\Remember::DEFAULT_DAYS);
                    Audit::log('settings.remember_2fa', "$was → $v days" . ($v === '0' ? ' (off; ' . \Align\Remember::forgetEveryone() . ' remembered browsers forgotten)' : ''));
                    \Align\Mail\Notify::security('Two-factor "remember this browser" changed', "$was → $v days by " . (\Align\Auth::user()['email'] ?? ''));
                }
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

    /** The integration Test buttons moved to Integrations (1.15). Only the fixed MOVED names make the redirect. */
    public static function test(): void
    {
        Auth::requireRole('admin');
        redirect('/integrations/' . (self::MOVED[post('target')] ?? ''));
    }

    /** Settings → OS support dates (the table devices are matched against). */
    public static function os(): void
    {
        Auth::requireRole('admin');
        View::render('settings/os', [
            'title' => 'OS support dates',
            'nav' => 'settings',
            'rows' => DB::all('SELECT * FROM os_support ORDER BY eos_date DESC, label'),
        ]);
    }

    /**
     * Saves the OS support table: rows[id][label|name_contains|build|eos_date|delete] and one new[] row, all
     * untrusted. A row is only written when every field is valid: label and text up to 190 characters, the build up
     * to 20 digits and a real calendar date (2.2.1: "2026-02-31", a longer build or a field sent as a list was a
     * server error). Ids that match no row change nothing. All in one transaction.
     */
    public static function osSave(): void
    {
        Auth::requireRole('admin');
        $str = fn(mixed $x): string => is_string($x) ? trim($x) : '';
        $valid = function (array $r) use ($str): bool {
            [$label, $text, $build, $date] = [$str($r['label'] ?? ''), $str($r['name_contains'] ?? ''), $str($r['build'] ?? ''), $str($r['eos_date'] ?? '')];
            return $label !== '' && mb_strlen($label) <= 190 && $text !== '' && mb_strlen($text) <= 190 && preg_match('/^\d{1,20}$/', $build)
                && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        };
        $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
        $new = is_array($_POST['new'] ?? null) ? $_POST['new'] : [];
        $n = ['updated' => 0, 'deleted' => 0, 'added' => 0, 'skipped' => 0];
        DB::transaction(function () use ($rows, $new, $str, $valid, &$n) {
            foreach ($rows as $id => $r) {
                if (!is_array($r)) {
                    continue;
                }
                if (!empty($r['delete'])) {
                    $n['deleted'] += DB::run('DELETE FROM os_support WHERE id = ?', [(int) $id])->rowCount();
                } elseif ($valid($r)) {
                    $n['updated'] += DB::run('UPDATE os_support SET label = ?, name_contains = ?, build = ?, eos_date = ? WHERE id = ?', [
                        $str($r['label']), $str($r['name_contains']), $str($r['build']), $str($r['eos_date']), (int) $id,
                    ])->rowCount();
                } else {
                    $n['skipped']++;
                }
            }
            if (array_filter(array_map($str, $new))) {
                if ($valid($new)) {
                    DB::insert('os_support', [
                        'label' => $str($new['label']),
                        'name_contains' => $str($new['name_contains']),
                        'build' => $str($new['build']),
                        'eos_date' => $str($new['eos_date']),
                    ]);
                    $n['added']++;
                } else {
                    flash('error', 'New row skipped: fill in every field (build is the number only, e.g. 26100).');
                }
            }
        });
        Audit::log('settings.os_support', "{$n['updated']} changed, {$n['added']} added, {$n['deleted']} deleted");
        if ($n['skipped']) {
            flash('warning', $n['skipped'] . ' row' . ($n['skipped'] === 1 ? ' was' : 's were') . ' not saved: fill in every field, with the build as a number and a real date.');
        }
        flash('success', 'OS support dates saved.');
        redirect('/settings/os');
    }

    /** The address with its domain in ASCII (punycode), so an international domain such as bücher.de passes the check. */
    private static function asciiEmail(string $e): string
    {
        $at = strrpos($e, '@');
        if ($at === false || !function_exists('idn_to_ascii')) {
            return $e;
        }
        $d = idn_to_ascii(substr($e, $at + 1), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return $d === false ? $e : substr($e, 0, $at + 1) . $d;
    }
}
