<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Backup\Backup;
use Align\Lifecycle\Lifecycle;
use Align\Reports\ReportData;
use Align\View;

/** Backup status from the Veeam Service Provider Console. */
final class BackupController
{
    public static function client(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        Audit::access('backups', "#$id {$client['name']}");
        View::render('backups/client', [
            'title' => $client['name'] . ' · Backups',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'backups',
            'b' => Backup::forClient($client, (new Lifecycle())->devices($id)),
            'configured' => \Align\Integrations\VeeamSpc::configured(),
        ]);
    }

    public static function report(int $id): void
    {
        Auth::require();
        $opt = ['details' => query('details', '1') === '1', 'machines' => query('machines', '1') === '1'];
        self::renderReport(ClientController::load($id), $opt);
    }

    /** Backup & recovery report for one client; also used by the client portal. */
    public static function renderReport(array $client, array $opt): void
    {
        $b = ReportData::backup((int) $client['id']);
        if (!$b) {
            http_response_code(404);
            if (defined('IS_PORTAL') && IS_PORTAL) {
                exit('Not found');
            }
            View::render('error', ['title' => 'No backup data', 'message' => 'This client is not linked to a Veeam company yet.']);
            return;
        }
        Audit::log('report.backup', $client['name']);
        View::render('reports/backup', [
            'title' => $client['name'] . ' — Backup & Recovery Report',
            'reportTitle' => 'Backup & Recovery Report',
            'reportSubtitle' => $b['stats']['protected'] . ' protected machines · status as of ' . date('F j, Y'),
            'client' => $client,
            'opt' => $opt,
            'optLabels' => ['details' => 'Job details', 'machines' => 'Protected machines'],
            'brand' => ReportController::branding($client),
            'b' => $b,
        ], 'layout/print');
    }
}
