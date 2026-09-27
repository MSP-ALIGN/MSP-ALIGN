<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Service\Sla;
use Align\View;

/** Service levels from ITFlow ticket SLAs: client page, client report, all-clients report. */
final class ServiceController
{
    private static function period(): string
    {
        $p = query('period', '90');
        return isset(Sla::PERIODS[$p]) || $p === 'quarter' ? $p : '90';
    }

    public static function periodChoices(): array
    {
        return Sla::PERIODS + ['quarter' => 'Last full quarter'];
    }

    public static function client(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $period = self::period();
        View::render('service/client', [
            'title' => $client['name'] . ' · Service levels',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'service',
            'period' => $period,
            's' => Sla::report($id, $period, 50),
            'enabled' => Sla::enabled(),
            'supported' => Sla::supported(),
            'linked' => !empty($client['itflow_client_id']),
        ]);
    }

    public static function report(int $id): void
    {
        Auth::require();
        self::renderReport(ClientController::load($id), ['missed' => query('missed', '1') === '1'], self::period());
    }

    /** Printable service-level report for one client; also used by the client portal (no ticket list there). */
    public static function renderReport(array $client, array $opt, string $period): void
    {
        $s = Sla::report((int) $client['id'], $period);
        if (!$s) {
            http_response_code(404);
            if (defined('IS_PORTAL') && IS_PORTAL) {
                exit('Not found');
            }
            View::render('error', ['title' => 'No service-level data', 'message' => 'There are no ITFlow tickets for this client yet. Service levels come from ITFlow ticket SLAs (Integrations → ITFlow).']);
            return;
        }
        Audit::log('report.sla', $client['name']);
        View::render('reports/sla', [
            'title' => $client['name'] . ' — Service Level Report',
            'reportTitle' => 'Service Level Report',
            'reportSubtitle' => $s['label'] . ' · ' . fmt_date($s['from']) . ' – ' . fmt_date($s['to']),
            'client' => $client,
            'opt' => $opt,
            'optLabels' => ['missed' => 'Missed tickets'],
            'periodChoices' => self::periodChoices(),
            'period' => $period,
            'brand' => ReportController::branding($client),
            's' => $s,
        ], 'layout/print');
    }

    /** Internal: every client's service levels for a period, worst first. */
    public static function portfolio(): void
    {
        Auth::require();
        $period = self::period();
        [$from, $to, $label] = Sla::range($period);
        $rows = Sla::allClients($from, $to);
        $total = Sla::stats(null, $from, $to);
        Audit::log('report.slas');
        View::render('reports/slas', [
            'title' => 'Service Levels — All Clients',
            'reportTitle' => 'Service Levels',
            'reportSubtitle' => $label . ' · ' . count($rows) . ' clients with tickets · internal',
            'opt' => [],
            'periodChoices' => self::periodChoices(),
            'period' => $period,
            'brand' => ReportController::branding(),
            'rows' => $rows,
            'total' => $total,
            'open' => Sla::openCounts(null),
            'openList' => Sla::open(null, 30),
            'monthly' => Sla::monthly(null, 12),
            'target' => Sla::target(),
            'supported' => Sla::supported(),
        ], 'layout/print');
    }
}
