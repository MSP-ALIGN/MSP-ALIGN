<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Compliance\Compliance;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Reports\ReportData;
use Align\Roadmap\Roadmap;
use Align\Settings;
use Align\View;

/** Print-ready reports. Browsers save them as PDF via Print → Save as PDF. */
final class ReportController
{
    public static function index(): void
    {
        Auth::require();
        $clients = DB::all('SELECT id, name, veeam_company_uid FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name');
        $fw = [];
        foreach (DB::all('SELECT cf.client_id, f.id, f.name FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id ORDER BY f.name') as $r) {
            $fw[(int) $r['client_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        $docs = [];
        foreach (DB::all("SELECT id, client_id, title, status FROM documents WHERE client_id IS NOT NULL ORDER BY status = 'active' DESC, title") as $r) {
            $docs[(int) $r['client_id']][] = ['id' => (int) $r['id'], 'name' => $r['title'] . ($r['status'] !== 'active' ? ' (draft)' : '')];
        }
        $meta = [];
        foreach ($clients as $c) {
            $meta[(int) $c['id']] = ['veeam' => !empty($c['veeam_company_uid']), 'frameworks' => $fw[(int) $c['id']] ?? [], 'documents' => $docs[(int) $c['id']] ?? []];
        }
        View::render('reports/index', [
            'title' => 'Reports',
            'nav' => 'reports',
            'clients' => $clients,
            'meta' => $meta,
            'years' => \Align\Roadmap\Plan::years(),
            'currentYear' => \Align\Roadmap\Plan::quarters()[\Align\Roadmap\Plan::currentIndex()]['year'],
            'backupEnabled' => \Align\Backup\Backup::enabled(),
            'preselect' => (int) query('client'),
        ]);
    }

    private static function options(): array
    {
        return [
            'costs' => query('costs', '1') === '1',
            'inventory' => query('inventory', '1') === '1',
            'users' => query('users', '1') === '1',
            'virtual' => query('virtual', '0') === '1',
            'notes' => query('notes', '1') === '1',
        ];
    }

    public static function branding(?array $client = null): array
    {
        $portal = defined('IS_PORTAL') && IS_PORTAL;
        return [
            'company' => Settings::get('company_name') ?: 'Mountaineer IT',
            'phone' => Settings::get('company_phone'),
            'email' => Settings::get('company_email'),
            'website' => Settings::get('company_website'),
            'footer' => Settings::get('report_footer'),
            // In the client portal the report is "prepared by" the client's advisor, not whoever is signed in
            'preparedBy' => $portal ? (string) ($client['vcio_name'] ?? '') : (Auth::user()['name'] ?? ''),
        ];
    }

    public static function assets(int $id): void
    {
        Auth::require();
        self::renderAssets(ClientController::load($id), self::options());
    }

    /** Asset & lifecycle report for one client; also used by the client portal. */
    public static function renderAssets(array $client, array $opt): void
    {
        $a = ReportData::assets((int) $client['id'], $opt);
        Audit::log('report.assets', $client['name']);
        View::render('reports/assets', [
            'title' => $client['name'] . ' — IT Asset & Lifecycle Report',
            'reportTitle' => 'IT Asset & Lifecycle Report',
            'reportSubtitle' => $a['summary']['total'] . ' devices · status as of ' . date('F j, Y'),
            'client' => $client,
            'opt' => $opt,
            'brand' => self::branding($client),
            'a' => $a,
        ], 'layout/print');
    }

    public static function roadmap(int $id): void
    {
        Auth::require();
        self::renderRoadmap(ClientController::load($id), array_intersect_key(self::options(), ['costs' => 1, 'notes' => 1]));
    }

    /** Roadmap report for one client; also used by the client portal ('position' => false hides devices and compliance). */
    public static function renderRoadmap(array $client, array $opt): void
    {
        $id = (int) $client['id'];
        $r = ReportData::roadmap($id);
        $years = $r['plan']['years'];
        $position = $opt['position'] ?? true;
        Audit::log('report.roadmap', $client['name']);
        View::render('reports/roadmap', [
            'title' => $client['name'] . ' — 3-Year Technology Roadmap',
            'reportTitle' => '3-Year Technology Roadmap',
            'reportSubtitle' => date('M Y', strtotime($years[0]['from'])) . ' – ' . date('M Y', strtotime($years[2]['to'])),
            'client' => $client,
            'opt' => $opt,
            'brand' => self::branding($client),
            'r' => $r,
            'summary' => $position ? Lifecycle::summarize(ReportData::devices($id)) : null,
            'comp' => $position ? ReportData::compliance($id) : null,
        ], 'layout/print');
    }

    public static function qbr(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $opt = ['inventory' => query('inventory', '0') === '1'] + self::options() + self::qbrSections(true);
        self::renderQbr($client, $opt, array_keys(self::QBR_SECTIONS));
    }

    public const QBR_SECTIONS = ['s_roadmap' => 'Roadmap', 's_budget' => 'Budget', 's_assets' => 'Assets', 's_backup' => 'Backups', 's_compliance' => 'Compliance', 's_licensing' => 'Licensing'];

    /** Section switches from the query string (all on by default). */
    public static function qbrSections(bool $default): array
    {
        $out = [];
        foreach (self::QBR_SECTIONS as $k => $l) {
            $out[$k] = query($k, $default ? '1' : '0') === '1';
        }
        return $out;
    }

    /**
     * Quarterly business review pack: cover, executive summary, then each section.
     * $allowed limits the sections (the portal passes only what the user may see).
     */
    public static function renderQbr(array $client, array $opt, array $allowed): void
    {
        $id = (int) $client['id'];
        $on = fn(string $k) => in_array($k, $allowed, true) && !empty($opt[$k]);
        $q = \Align\Roadmap\Plan::quarters()[\Align\Roadmap\Plan::currentIndex()];
        $a = in_array('s_assets', $allowed, true) ? ReportData::assets($id, $opt) : null;
        $r = in_array('s_roadmap', $allowed, true) ? ReportData::roadmap($id) : null;
        $bd = in_array('s_budget', $allowed, true) ? ReportData::budget($id, $q['year']) : null;
        $comp = in_array('s_compliance', $allowed, true) ? ReportData::compliance($id) : null;
        $lic = in_array('s_licensing', $allowed, true) ? ReportData::licensing($id) : null;
        $bk = in_array('s_backup', $allowed, true) ? ReportData::backup($id) : null;
        if (!$bk) {
            // Client not linked to Veeam: no backup switch in the toolbar
            $allowed = array_values(array_diff($allowed, ['s_backup']));
        }
        $vcio = $client['vcio_name'] ?? null;
        Audit::log('report.qbr', $client['name']);
        View::render('reports/qbr', [
            'title' => $client['name'] . ' — Business Review ' . $q['label'],
            'reportTitle' => 'Business Review',
            'noMasthead' => true,
            'client' => $client,
            'opt' => array_filter($opt, fn($v, $k) => !str_starts_with($k, 's_') || in_array($k, $allowed, true), ARRAY_FILTER_USE_BOTH),
            'optLabels' => array_intersect_key(self::QBR_SECTIONS, array_flip($allowed)),
            'brand' => self::branding($client),
            'quarter' => $q,
            'on' => $on,
            'a' => $a, 'r' => $r, 'bd' => $bd, 'comp' => $comp, 'lic' => $lic, 'bk' => $on('s_backup') ? $bk : null,
            'people' => ReportData::people($id),
            'provider' => ['company' => Settings::get('company_name') ?: 'Mountaineer IT', 'phone' => Settings::get('company_phone'),
                'email' => Settings::get('company_email'), 'vcio' => $vcio],
            'highlights' => ReportData::highlights($a ?? [], $r ?? [], $bd, $comp, $lic, (bool) $opt['costs'], $on('s_backup') ? $bk : null),
        ], 'layout/print');
    }

    /** All active clients: asset counts and 3-year budget by year. */
    public static function portfolio(): void
    {
        Auth::require();
        $opt = array_intersect_key(self::options(), ['costs' => 1]);
        $lc = new Lifecycle();
        $clients = DB::all('SELECT id, name, industry FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name');
        $all = $lc->devices();
        $scores = Compliance::allScores();
        $rows = [];
        foreach ($clients as $c) {
            $devs = array_values(array_filter($all, fn($d) => (int) $d['client_id'] === (int) $c['id'] && $d['status'] !== 'excluded' && !$d['is_virtual']));
            $f = $lc->forecast($devs);
            $fs = array_map(fn($s) => $s['score'], $scores[$c['id']] ?? []);
            $rows[] = $c + [
                'summary' => Lifecycle::summarize($devs),
                'healthy' => count(array_filter($devs, fn($d) => !in_array($d['status_tone'], ['bad', 'warn'], true))),
                'warn' => count(array_filter($devs, fn($d) => $d['status_tone'] === 'warn')),
                'bad' => count(array_filter($devs, fn($d) => $d['status_tone'] === 'bad')),
                'years' => Lifecycle::yearTotals($f),
                'planned' => (float) DB::value("SELECT COALESCE(SUM(cost),0) FROM roadmap_items WHERE client_id = ? AND status NOT IN ('declined','done')", [$c['id']]),
                'compliance' => $fs ? (int) round(array_sum($fs) / count($fs)) : null,
                'last_meeting' => DB::value("SELECT MAX(starts_at) FROM meetings WHERE client_id = ? AND status = 'completed'", [$c['id']]),
            ];
        }
        usort($rows, fn($x, $y) => [$y['bad'], $y['warn']] <=> [$x['bad'], $x['warn']]);
        Audit::log('report.portfolio');
        View::render('reports/portfolio', [
            'title' => 'Client Portfolio — Lifecycle & Budget Summary',
            'reportTitle' => 'Client Portfolio',
            'reportSubtitle' => count($rows) . ' clients in planning · internal',
            'rows' => $rows,
            'opt' => $opt,
            'brand' => self::branding(),
            'yearsMeta' => \Align\Roadmap\Plan::years(),
            'backups' => \Align\Backup\Backup::enabled() ? \Align\Backup\Backup::summaries() : null,
        ], 'layout/print');
    }
}
