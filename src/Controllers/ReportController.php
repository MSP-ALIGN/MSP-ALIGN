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

/**
 * Print-ready reports. Browsers save them as PDF via Print → Save as PDF.
 *
 * Security assumptions: staff actions start with Auth::require() (any staff role prints any client's reports,
 * archived ones included; the portfolio is internal). The render*() functions are shared with the client portal,
 * whose controller has checked its own client and permissions and passes only the sections the portal user may
 * see ($allowed, $people); everything they show is keyed by that one client id. Report options come from the query
 * string and are only switches (=== '1'). Every report is audited. Views escape everything synced or typed.
 */
final class ReportController
{
    /** The reports hub (any staff role): clients in planning with what each has (frameworks, documents, backups, SLA). */
    public static function index(): void
    {
        Auth::require();
        $clients = DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name');
        $fw = [];
        foreach (DB::all('SELECT cf.client_id, f.id, f.name FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id ORDER BY f.name') as $r) {
            $fw[(int) $r['client_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        $docs = [];
        foreach (DB::all("SELECT id, client_id, title, status FROM documents WHERE client_id IS NOT NULL ORDER BY status = 'active' DESC, title") as $r) {
            $docs[(int) $r['client_id']][] = ['id' => (int) $r['id'], 'name' => $r['title'] . ($r['status'] !== 'active' ? ' (draft)' : '')];
        }
        $slaOn = \Align\Service\Sla::enabled() && \Align\Service\Sla::supported() !== false;
        $withTickets = $slaOn ? array_flip(array_map('intval', array_column(DB::all('SELECT DISTINCT client_id FROM psa_tickets WHERE client_id IS NOT NULL'), 'client_id'))) : [];
        $meta = [];
        foreach ($clients as $c) {
            $meta[(int) $c['id']] = ['backup' => \Align\Backup\Backup::has($c), 'sla' => isset($withTickets[(int) $c['id']]), 'frameworks' => $fw[(int) $c['id']] ?? [], 'documents' => $docs[(int) $c['id']] ?? []];
        }
        View::render('reports/index', [
            'title' => 'Reports',
            'nav' => 'reports',
            'clients' => $clients,
            'meta' => $meta,
            'years' => \Align\Roadmap\Plan::years(),
            'currentYear' => \Align\Roadmap\Plan::quarters()[\Align\Roadmap\Plan::currentIndex()]['year'],
            'backupEnabled' => \Align\Backup\Backup::enabled(),
            'slaEnabled' => $slaOn,
            'preselect' => (int) query('client'),
        ]);
    }

    /** Common report switches from the query string (costs, inventory, users, virtual, notes). */
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

    /** Your company details for report headers and footers (Settings); "prepared by" is the signed-in staff member, or the client's vCIO in the portal. */
    public static function branding(?array $client = null): array
    {
        $portal = defined('IS_PORTAL') && IS_PORTAL;
        return [
            'company' => Settings::get('company_name') ?: 'Your company',
            'phone' => Settings::get('company_phone'),
            'email' => Settings::get('company_email'),
            'website' => Settings::get('company_website'),
            'footer' => Settings::get('report_footer'),
            // In the client portal the report is "prepared by" the client's advisor, not whoever is signed in
            'preparedBy' => $portal ? (string) ($client['vcio_name'] ?? '') : (Auth::user()['name'] ?? ''),
        ];
    }

    /** Asset & lifecycle report for a client (any staff role). */
    public static function assets(int $id): void
    {
        Auth::require();
        self::renderAssets(ClientController::load($id), self::options());
    }

    /** Asset & lifecycle report for one client; also used by the client portal (the caller checked access to $client). Audited. */
    public static function renderAssets(array $client, array $opt): void
    {
        $a = ReportData::assets((int) $client['id'], $opt);
        Audit::log('report.assets', $client['name']);
        View::render('reports/assets', [
            'title' => $client['name'] . ' — IT Asset & Lifecycle Report',
            'reportTitle' => 'IT Asset & Lifecycle Report',
            'reportSubtitle' => $a['summary']['total'] . ' devices · status as of ' . \Align\Fmt::date(time(), 'long'),
            'client' => $client,
            'opt' => $opt,
            'brand' => self::branding($client),
            'a' => $a,
        ], 'layout/print');
    }

    /** Roadmap report for a client (any staff role). */
    public static function roadmap(int $id): void
    {
        Auth::require();
        self::renderRoadmap(ClientController::load($id), array_intersect_key(self::options(), ['costs' => 1, 'notes' => 1]));
    }

    /**
     * Roadmap report for one client; also used by the client portal ('position' => false hides devices and
     * compliance, 'meetings' => false hides meetings in the timeline). The caller checked access to $client. Internal meetings are left out (ReportData::roadmap). Audited.
     */
    public static function renderRoadmap(array $client, array $opt): void
    {
        $id = (int) $client['id'];
        $position = $opt['position'] ?? true;
        $r = ReportData::roadmap($id, $opt['meetings'] ?? true, $position);
        $years = $r['plan']['years'];
        Audit::log('report.roadmap', $client['name']);
        View::render('reports/roadmap', [
            'title' => $client['name'] . ' — 3-Year Technology Roadmap',
            'reportTitle' => '3-Year Technology Roadmap',
            'reportSubtitle' => \Align\Fmt::date($years[0]['from'], 'month') . ' – ' . \Align\Fmt::date($years[2]['to'], 'month'),
            'client' => $client,
            'opt' => $opt,
            'brand' => self::branding($client),
            'r' => $r,
            'summary' => $position ? Lifecycle::summarize(ReportData::devices($id)) : null,
            'comp' => $position ? ReportData::compliance($id) : null,
        ], 'layout/print');
    }

    /** Business review pack for a client (any staff role); every section allowed, switched by the query string. */
    public static function qbr(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $opt = ['inventory' => query('inventory', '0') === '1', 'missed' => query('missed', '1') === '1'] + self::options() + self::qbrSections(true);
        self::renderQbr($client, $opt, array_keys(self::QBR_SECTIONS));
    }

    /** QBR sections, in the order the meeting runs (see views/reports/qbr.php). */
    public const QBR_SECTIONS = ['s_changes' => 'What changed', 's_sla' => 'Service levels', 's_assets' => 'Assets', 's_licensing' => 'Licensing', 's_backup' => 'Backups', 's_compliance' => 'Compliance', 's_alignment' => 'Alignment', 's_roadmap' => 'Roadmap', 's_budget' => 'Budget'];

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
     * Quarterly business review pack: cover, executive summary, then each section (from 2.4.0 starting with what
     * changed since the last review; $changeParts limits its parts, null = all).
     * $allowed limits the sections (the portal passes only what the user may see); $people false leaves out the key
     * contacts and meetings (a portal user without "Documents, contacts & meetings", 1.45). A section switched off
     * is also left out of the executive summary's tiles and highlights (2.2.1: unticking Compliance still printed
     * the compliance score and the frameworks below 80% on the first page).
     */
    public static function renderQbr(array $client, array $opt, array $allowed, bool $people = true, ?array $changeParts = null): void
    {
        $id = (int) $client['id'];
        // 2.4.0 "What changed since the last review": from ?since= (a review or a date), else the newest completed
        // review; the portal passes only the parts its user may see. No review to compare with = no switch.
        $ch = null;
        if (in_array('s_changes', $allowed, true) && ($base = \Align\Changes\Changes::resolve($id, is_string($_GET['since'] ?? null) ? $_GET['since'] : null))) {
            $parts = $changeParts ?? \Align\Changes\Changes::PARTS;
            $base = $changeParts !== null ? \Align\Changes\Changes::forPortal($base) : $base;
            // Switched off in the toolbar: keep the switch (a placeholder), skip the work
            $ch = !$parts ? null : (!empty($opt['s_changes']) ? \Align\Changes\Changes::compare($id, $base, $parts, !empty($opt['costs'])) : ['off' => true]);
        }
        if (!$ch) {
            $allowed = array_values(array_diff($allowed, ['s_changes']));
        }
        $on = fn(string $k) => in_array($k, $allowed, true) && !empty($opt[$k]);
        $q = \Align\Roadmap\Plan::quarters()[\Align\Roadmap\Plan::currentIndex()];
        $a = in_array('s_assets', $allowed, true) ? ReportData::assets($id, $opt) : null;
        $r = in_array('s_roadmap', $allowed, true) ? ReportData::roadmap($id, $people, in_array('s_compliance', $allowed, true)) : null;
        $bd = in_array('s_budget', $allowed, true) ? ReportData::budget($id, $q['year']) : null;
        $comp = in_array('s_compliance', $allowed, true) ? ReportData::compliance($id) : null;
        $lic = in_array('s_licensing', $allowed, true) ? ReportData::licensing($id) : null;
        $bk = in_array('s_backup', $allowed, true) ? ReportData::backup($id) : null;
        // 2.3.0 (staff packs only: the portal doesn't pass s_alignment); no review yet = no switch in the toolbar
        $al = in_array('s_alignment', $allowed, true) ? ReportData::alignment($id) : null;
        if (!$al) {
            $allowed = array_values(array_diff($allowed, ['s_alignment']));
        }
        if (!$bk) {
            // Client not linked to a backup product: no backup switch in the toolbar
            $allowed = array_values(array_diff($allowed, ['s_backup']));
        }
        $sla = in_array('s_sla', $allowed, true) && \Align\Service\Sla::enabled() ? \Align\Service\Sla::report($id, '90', 15) : null;
        if (!$sla) {
            $allowed = array_values(array_diff($allowed, ['s_sla']));
            unset($opt['missed']);
        }
        $vcio = $client['vcio_name'] ?? null;
        Audit::log('report.qbr', $client['name']);
        View::render('reports/qbr', [
            'title' => $client['name'] . ' — Business Review ' . $q['label'],
            'reportTitle' => 'Business Review',
            'noMasthead' => true,
            'client' => $client,
            'opt' => array_filter($opt, fn($v, $k) => !str_starts_with($k, 's_') || in_array($k, $allowed, true), ARRAY_FILTER_USE_BOTH),
            'optLabels' => array_intersect_key(self::QBR_SECTIONS, array_flip($allowed)) + ['missed' => 'Missed tickets'],
            'brand' => self::branding($client),
            'quarter' => $q,
            'on' => $on,
            'a' => $a, 'r' => $r, 'bd' => $bd, 'comp' => $comp, 'lic' => $lic, 'bk' => $on('s_backup') ? $bk : null, 'sla' => $on('s_sla') ? $sla : null,
            'al' => $on('s_alignment') ? $al : null,
            'ch' => $on('s_changes') ? $ch : null,
            'people' => $people ? ReportData::people($id) : ['contacts' => [], 'nextMeeting' => null, 'lastMeeting' => null, 'hidden' => true],
            'provider' => ['company' => Settings::get('company_name') ?: 'Your company', 'phone' => Settings::get('company_phone'),
                'email' => Settings::get('company_email'), 'vcio' => $vcio],
            'highlights' => ReportData::highlights($on('s_assets') ? $a ?? [] : [], $on('s_roadmap') ? $r ?? [] : [], $on('s_budget') ? $bd : null,
                $on('s_compliance') ? $comp : null, $on('s_licensing') ? $lic : null, (bool) $opt['costs'], $on('s_backup') ? $bk : null, $on('s_sla') ? $sla : null,
                $on('s_alignment') ? $al : null),
        ], 'layout/print');
    }

    /**
     * All clients in planning: asset counts, compliance, last review and 3-year hardware by year (internal; any
     * staff role). Planned project costs and last meetings come from two grouped queries and devices are grouped
     * once, instead of two queries and a pass over every device per client (2.2.1).
     */
    public static function portfolio(): void
    {
        Auth::require();
        $opt = array_intersect_key(self::options(), ['costs' => 1]);
        $lc = new Lifecycle();
        $clients = DB::all('SELECT id, name, industry FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name');
        $byClient = [];
        foreach ($lc->devices() as $d) {
            if ($d['client_id'] !== null && $d['status'] !== 'excluded' && !$d['is_virtual']) {
                $byClient[(int) $d['client_id']][] = $d;
            }
        }
        $planned = array_column(DB::all("SELECT client_id, SUM(cost) AS planned FROM roadmap_items WHERE status NOT IN ('declined','done') GROUP BY client_id"), 'planned', 'client_id');
        $lastMet = array_column(DB::all("SELECT client_id, MAX(starts_at) AS last FROM meetings WHERE status = 'completed' AND client_id IS NOT NULL GROUP BY client_id"), 'last', 'client_id');
        $scores = Compliance::allScores();
        $rows = [];
        foreach ($clients as $c) {
            $devs = $byClient[(int) $c['id']] ?? [];
            $f = $lc->forecast($devs);
            $fs = array_map(fn($s) => $s['score'], $scores[$c['id']] ?? []);
            $rows[] = $c + [
                'summary' => Lifecycle::summarize($devs),
                'healthy' => count(array_filter($devs, fn($d) => !in_array($d['status_tone'], ['bad', 'warn'], true))),
                'warn' => count(array_filter($devs, fn($d) => $d['status_tone'] === 'warn')),
                'bad' => count(array_filter($devs, fn($d) => $d['status_tone'] === 'bad')),
                'years' => Lifecycle::yearTotals($f),
                'planned' => (float) ($planned[$c['id']] ?? 0),
                'compliance' => $fs ? (int) round(array_sum($fs) / count($fs)) : null,
                'last_meeting' => $lastMet[$c['id']] ?? null,
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
