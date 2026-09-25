<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Compliance\Compliance;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Roadmap\Roadmap;
use Align\Settings;
use Align\View;

/** Print-ready reports. Browsers save them as PDF via Print → Save as PDF. */
final class ReportController
{
    public static function index(): void
    {
        Auth::require();
        View::render('reports/index', [
            'title' => 'Reports',
            'nav' => 'reports',
            'clients' => DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'),
        ]);
    }

    private static function options(): array
    {
        return [
            'costs' => query('costs', '1') === '1',
            'inventory' => query('inventory', '1') === '1',
            'virtual' => query('virtual', '0') === '1',
            'notes' => query('notes', '1') === '1',
        ];
    }

    public static function branding(): array
    {
        return [
            'company' => Settings::get('company_name') ?: 'Mountaineer IT',
            'phone' => Settings::get('company_phone'),
            'email' => Settings::get('company_email'),
            'website' => Settings::get('company_website'),
            'footer' => Settings::get('report_footer'),
            'preparedBy' => defined('IS_PORTAL') && IS_PORTAL ? '' : (Auth::user()['name'] ?? ''),
        ];
    }

    public static function assets(int $id): void
    {
        Auth::require();
        self::renderAssets(ClientController::load($id), self::options());
    }

    /** Asset report for one client; also used by the client portal. */
    public static function renderAssets(array $client, array $opt): void
    {
        $id = (int) $client['id'];
        $lc = new Lifecycle();
        $devices = $lc->devices($id);
        if (!$opt['virtual']) {
            $devices = array_values(array_filter($devices, fn($d) => !$d['is_virtual']));
        }
        $active = array_values(array_filter($devices, fn($d) => $d['status'] !== 'excluded'));
        $forecast = $lc->forecast($active);
        $byType = [];
        foreach ($active as $d) {
            $byType[$d['type']][] = $d;
        }
        ksort($byType);
        Audit::log('report.assets', $client['name']);
        View::render('reports/assets', [
            'title' => $client['name'] . ' — IT Asset & Lifecycle Report',
            'reportTitle' => 'IT Asset & Lifecycle Report',
            'client' => $client,
            'opt' => $opt,
            'brand' => self::branding(),
            'summary' => Lifecycle::summarize($active),
            'forecast' => $forecast,
            'years' => Lifecycle::yearTotals($forecast),
            'attention' => array_values(array_filter($active, fn($d) => in_array($d['status_tone'], ['bad', 'warn'], true))),
            'byType' => $byType,
            'policy' => $lc->policy(),
        ], 'layout/print');
    }

    public static function roadmap(int $id): void
    {
        Auth::require();
        self::renderRoadmap(ClientController::load($id), array_intersect_key(self::options(), ['costs' => 1, 'notes' => 1]));
    }

    /** Reports go to the client: leave internal meetings off the roadmap. */
    private static function clientFacing(array $plan): array
    {
        foreach ($plan['quarters'] as &$q) {
            $q['meetings'] = array_values(array_filter($q['meetings'], fn($m) => $m['type'] !== 'internal'));
        }
        return $plan;
    }

    /** Roadmap report for one client; also used by the client portal. */
    public static function renderRoadmap(array $client, array $opt): void
    {
        $id = (int) $client['id'];
        $devices = (new Lifecycle())->devices($id);
        $frameworks = DB::all('SELECT f.id, f.name FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$id]);
        foreach ($frameworks as &$fw) {
            $fw['score'] = Compliance::score($id, (int) $fw['id']);
        }
        unset($fw);
        Audit::log('report.roadmap', $client['name']);
        View::render('reports/roadmap', [
            'title' => $client['name'] . ' — 3-Year Technology Roadmap',
            'reportTitle' => '3-Year Technology Roadmap',
            'client' => $client,
            'opt' => $opt,
            'brand' => self::branding(),
            'plan' => self::clientFacing(Roadmap::build($id, $devices)),
            'summary' => Lifecycle::summarize($devices),
            'frameworks' => $frameworks,
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
        $rows = [];
        foreach ($clients as $c) {
            $devs = array_values(array_filter($all, fn($d) => (int) $d['client_id'] === (int) $c['id'] && $d['status'] !== 'excluded' && !$d['is_virtual']));
            $f = $lc->forecast($devs);
            $rows[] = $c + [
                'summary' => Lifecycle::summarize($devs),
                'years' => Lifecycle::yearTotals($f),
                'planned' => (float) DB::value("SELECT COALESCE(SUM(cost),0) FROM roadmap_items WHERE client_id = ? AND status NOT IN ('declined','done')", [$c['id']]),
            ];
        }
        Audit::log('report.portfolio');
        View::render('reports/portfolio', [
            'title' => 'Client Portfolio — Lifecycle & Budget Summary',
            'reportTitle' => 'Client Portfolio — Lifecycle & Budget',
            'reportSubtitle' => count($rows) . ' clients in planning',
            'rows' => $rows,
            'opt' => $opt,
            'brand' => self::branding(),
            'yearsMeta' => \Align\Roadmap\Plan::years(),
        ], 'layout/print');
    }
}
