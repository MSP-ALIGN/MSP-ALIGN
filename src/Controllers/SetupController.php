<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Branding;
use Align\DB;
use Align\Mail\Mail;
use Align\Providers\Providers;
use Align\Settings;
use Align\View;

/**
 * First-run setup wizard (1.40): company, currency & dates, PSA, RMM, other integrations, email, clients
 * and team, one step at a time. Every step can be skipped and come back to later. The forms are the
 * app's own (they post to the usual settings, integration, email and user pages and come back here), so
 * the wizard never saves anything differently from the rest of MSP-ALIGN.
 *
 * Settings: setup_state (pending = open it for the first admin; done), setup_seen / setup_skipped
 * (comma lists of step keys).
 */
final class SetupController
{
    public const STEPS = [
        'company' => ['Your company', 'fa-building'],
        'locale' => ['Currency & dates', 'fa-globe'],
        'psa' => ['PSA', 'fa-ticket'],
        'rmm' => ['RMM', 'fa-desktop'],
        'more' => ['Backups & warranty', 'fa-database'],
        'email' => ['Email', 'fa-envelope'],
        'clients' => ['Clients', 'fa-users'],
        'team' => ['Your team', 'fa-user-plus'],
    ];

    public static function pending(): bool
    {
        return Settings::get('setup_state', 'done') === 'pending';
    }

    private static function list(string $key): array
    {
        return array_values(array_filter(explode(',', (string) Settings::get($key, ''))));
    }

    /** A step saved from the wizard counts as done even when its values are the defaults (Currency & dates). */
    public static function markSeen(string $step): void
    {
        if (isset(self::STEPS[$step])) {
            self::mark('setup_seen', $step);
        }
    }

    private static function mark(string $key, string $step): void
    {
        $l = self::list($key);
        if (!in_array($step, $l, true)) {
            $l[] = $step;
            Settings::set($key, implode(',', $l));
        }
    }

    /** What's already done, from the real settings (so work done outside the wizard counts too). */
    public static function status(): array
    {
        $seen = self::list('setup_seen');
        $skipped = self::list('setup_skipped');
        $done = [
            'company' => trim((string) Settings::get('company_name')) !== '',
            'locale' => in_array('locale', $seen, true) || Settings::get('locale_currency') !== null || Settings::get('timezone') !== null,
            'psa' => Providers::psaConfigured(),
            'rmm' => Providers::anyRmm(),
            'more' => (bool) Providers::backupConfigured() || (bool) array_filter([\Align\Integrations\Registry::get('dell'), \Align\Integrations\Registry::get('lenovo')], fn($c) => $c && $c->configured()),
            'email' => Mail::ready(),
            'clients' => (int) DB::value('SELECT COUNT(*) FROM clients WHERE is_archived = 0') > 0,
            'team' => (int) DB::value('SELECT COUNT(*) FROM users WHERE is_active = 1') > 1,
        ];
        $out = [];
        foreach (self::STEPS as $k => [$label, $icon]) {
            $out[$k] = ['label' => $label, 'icon' => $icon, 'state' => $done[$k] ? 'done' : (in_array($k, $skipped, true) ? 'skipped' : 'todo')];
        }
        return $out;
    }

    private static function next(string $step): string
    {
        $keys = array_keys(self::STEPS);
        $i = array_search($step, $keys, true);
        return $i === false || $i === count($keys) - 1 ? '/setup/finish' : '/setup/' . $keys[$i + 1];
    }

    public static function index(): void
    {
        Auth::requireRole('admin');
        // Carry on where it makes sense: the first step not done or skipped
        foreach (self::status() as $k => $s) {
            if ($s['state'] === 'todo') {
                redirect('/setup/' . $k);
            }
        }
        redirect('/setup/finish');
    }

    public static function show(string $step): void
    {
        Auth::requireRole('admin');
        if ($step !== 'finish' && !isset(self::STEPS[$step])) {
            redirect('/setup');
        }
        $vars = ['title' => 'Set up MSP-ALIGN', 'nav' => 'settings', 'step' => $step, 'steps' => self::status(), 'next' => self::next($step),
            'return' => '/setup/' . $step, 'pending' => self::pending()];
        $vars += match ($step) {
            'company' => ['v' => ['company_name' => Settings::get('company_name'), 'company_phone' => Settings::get('company_phone'), 'company_email' => Settings::get('company_email'),
                'company_website' => Settings::get('company_website'), 'brand_primary' => Settings::get('brand_primary')], 'hasLogo' => Branding::hasLogo(), 'logoUrl' => Branding::logoUrl()],
            'locale' => ['v' => ['timezone' => Settings::get('timezone'), 'locale_currency_position' => Settings::get('locale_currency_position')]],
            'psa' => ['connectors' => self::forms(Providers::psaConnectors())],
            'rmm' => ['connectors' => self::forms(Providers::rmmConnectors())],
            'more' => ['connectors' => self::forms(array_filter(\Align\Integrations\Registry::all(), fn($c) => in_array($c->category(), ['Backup', 'Warranty'], true)))],
            'email' => ['ready' => Mail::ready(), 'provider' => Mail::provider(), 'smtp' => ['host' => Settings::get('smtp_host'), 'port' => Settings::get('smtp_port'), 'security' => \Align\Mail\Smtp::security(),
                'user' => Settings::get('smtp_user'), 'from' => Settings::get('mail_from'), 'from_name' => Settings::get('mail_from_name'), 'pass' => Settings::hasSecret('smtp_pass')]],
            'clients' => ['clients' => (int) DB::value('SELECT COUNT(*) FROM clients WHERE is_archived = 0'), 'psa' => Providers::psaConfigured(), 'rmm' => Providers::anyRmm(),
                'running' => ($running = \Align\Sync\SyncRunner::isRunning()) || query('started') === '1', 'refresh' => $running || query('started') === '1', 'lastSync' => DB::one('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1'),
                'orgs' => Providers::anyRmm() ? (int) DB::value('SELECT COUNT(*) FROM rmm_orgs') : 0],
            'team' => ['users' => DB::all('SELECT name, email, role, is_active FROM users ORDER BY name'), 'newPassword' => $_SESSION['new_password'] ?? null],
            default => [],
        };
        if ($step === 'team') {
            unset($_SESSION['new_password']); // shown once, as on the Users page
        }
        View::render('setup/index', $vars);
    }

    /** Connector, its saved values (secrets: only whether one is saved) and its status, for the inline forms. */
    private static function forms(array $connectors): array
    {
        $out = [];
        foreach ($connectors as $c) {
            $values = [];
            foreach ($c->fields() as $f) {
                $values[$f['name']] = $f['type'] === 'secret' ? Settings::hasSecret($f['name']) : Settings::get($f['name'], isset($f['default']) ? (string) $f['default'] : null);
            }
            $out[] = ['c' => $c, 'values' => $values, 'status' => $c->status()];
        }
        return $out;
    }

    /** Step 1 is the wizard's own form: company details, logo and colour in one go. */
    public static function saveCompany(): void
    {
        Auth::requireRole('admin');
        $changed = [];
        if (trim(post('company_name')) === '') {
            flash('error', 'Enter your company name (or skip this step for now).');
            redirect('/setup/company');
        }
        if (trim(post('company_email')) !== '' && !filter_var(trim(post('company_email')), FILTER_VALIDATE_EMAIL)) {
            flash('error', 'The company email must be an email address.'); // checked first, so nothing is half saved
            redirect('/setup/company');
        }
        foreach (['company_name' => 190, 'company_phone' => 60, 'company_email' => 190, 'company_website' => 190] as $k => $len) {
            $val = mb_substr(trim(post($k)), 0, $len);
            if ($val !== (string) Settings::get($k, '')) {
                Settings::set($k, $val);
                $changed[] = $k;
            }
        }
        $color = strtolower(post('brand_primary'));
        // The colour picker always sends a value: only a change from the colour in use counts
        if (preg_match('/^#[0-9a-f]{6}$/', $color) && $color !== strtolower(Branding::color())) {
            Settings::set('brand_primary', $color);
            $changed[] = 'brand_primary';
        }
        if (!empty($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($err = Branding::saveLogo($_FILES['logo'])) {
                flash('error', $err);
                redirect('/setup/company');
            }
            $changed[] = 'logo';
            Audit::log('branding.logo_uploaded');
        }
        if ($changed) {
            Audit::log('settings.save', 'setup: ' . implode(', ', $changed));
        }
        redirect(self::next('company'));
    }

    /** "Skip for now": the step shows as skipped and the wizard moves on. */
    public static function skip(string $step): void
    {
        Auth::requireRole('admin');
        if (isset(self::STEPS[$step])) {
            self::mark('setup_skipped', $step);
        }
        redirect(self::next($step));
    }

    /** Finish (or "Skip setup" from any step): the wizard stops opening by itself. */
    public static function finish(): void
    {
        Auth::requireRole('admin');
        $was = self::pending();
        Settings::set('setup_state', 'done');
        if ($was) {
            Audit::log('settings.setup', post('how') === 'skip' ? 'setup wizard skipped' : 'setup wizard finished');
        }
        flash('success', post('how') === 'skip' ? 'Setup skipped. You can open the setup wizard any time from Settings → General.'
            : 'You\'re set up. The "Getting set up" list on the dashboard shows anything left, and the wizard is under Settings → General.');
        redirect('/');
    }
}
