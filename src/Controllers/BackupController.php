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

    /**
     * Marks a device, protected machine or Microsoft 365 item as not needing a backup, or undoes it.
     * POST action=add: kind (device|workload|m365), ref (device id or item uid), reason. action=remove: exemption.
     */
    public static function exempt(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $back = \Align\Security::safePath(post('back'), "/clients/$id/backups");
        $action = post('action');
        if ($action === 'remove') {
            $e = \Align\DB::one('SELECT * FROM backup_exemptions WHERE id = ? AND client_id = ?', [(int) post('exemption'), $id]);
            if ($e) {
                \Align\DB::run('DELETE FROM backup_exemptions WHERE id = ?', [$e['id']]);
                Audit::log('backup.exempt_remove', "{$client['name']}: {$e['item_name']}");
                flash('success', "{$e['item_name']} is monitored for backups again.");
            }
            redirect($back);
        }
        $kind = post('kind');
        $ref = post('ref');
        $reason = mb_substr(trim(post('reason')), 0, 255);
        if ($reason === '') {
            flash('error', 'Add a reason so the audit trail shows why this doesn\'t need a backup.');
            redirect($back);
        }
        $row = ['client_id' => $id, 'kind' => $kind, 'device_id' => null, 'item_uid' => null, 'reason' => $reason, 'created_by' => Auth::user()['id'] ?? null];
        $uid = (string) ($client['veeam_company_uid'] ?? '');
        switch ($kind) {
            case 'device':
                $d = ctype_digit($ref) ? (new Lifecycle())->devices($id, false, (int) $ref)[0] ?? null : null;
                if (!$d) {
                    http_response_code(404);
                    exit('Not found');
                }
                $row['device_id'] = (int) $d['id'];
                $row['item_name'] = $d['name'];
                break;
            case 'workload':
            case 'm365':
                $table = $kind === 'workload' ? 'backup_workloads' : 'backup_m365_objects';
                $item = $kind === 'workload'
                    ? \Align\DB::one('SELECT uid, name FROM backup_workloads WHERE uid = ? AND client_id = ?', [$ref, $id])
                    : ($uid !== '' ? \Align\DB::one('SELECT uid, name FROM backup_m365_objects WHERE uid = ? AND company_uid = ?', [$ref, $uid]) : null);
                if (!$item) {
                    http_response_code(404);
                    exit('Not found');
                }
                $row['item_uid'] = $item['uid'];
                $row['item_name'] = mb_substr($item['name'], 0, 255);
                break;
            default:
                http_response_code(400);
                exit('Bad request');
        }
        $key = $row['device_id'] !== null ? ['device_id = ?', $row['device_id']] : ['item_uid = ?', $row['item_uid']];
        \Align\DB::run("DELETE FROM backup_exemptions WHERE {$key[0]}", [$key[1]]);
        \Align\DB::insert('backup_exemptions', $row);
        Audit::log('backup.exempt', "{$client['name']}: {$row['item_name']} — {$reason}");
        flash('success', "{$row['item_name']} marked as not needing a backup.");
        redirect($back);
    }

    /** Internal all-clients backup status (every client linked to a Veeam company). */
    public static function portfolio(): void
    {
        Auth::require();
        $all = query('all', '1') === '1';
        $clients = \Align\DB::all('SELECT * FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name');
        $byClient = [];
        foreach ((new Lifecycle())->devices() as $d) {
            if ($d['client_id']) {
                $byClient[(int) $d['client_id']][] = $d;
            }
        }
        $rows = [];
        $unlinked = [];
        foreach ($clients as $c) {
            if (!\Align\Backup\Backup::has($c)) {
                $unlinked[] = $c['name'];
                continue;
            }
            $devs = array_values(array_filter($byClient[(int) $c['id']] ?? [], fn($d) => $d['status'] !== 'excluded'));
            $b = \Align\Backup\Backup::forClient($c, $devs);
            if ($b) {
                $rows[] = ['client' => $c, 'b' => $b];
            }
        }
        $rank = ['bad' => 0, 'warn' => 1, 'ok' => 2];
        usort($rows, fn($x, $y) => [$rank[$x['b']['stats']['tone']] ?? 3, $x['client']['name']] <=> [$rank[$y['b']['stats']['tone']] ?? 3, $y['client']['name']]);
        $shown = $all ? $rows : array_values(array_filter($rows, fn($r) => $r['b']['stats']['tone'] !== 'ok'));
        Audit::log('report.backups');
        View::render('reports/backups', [
            'title' => 'Backup Status — All Clients',
            'reportTitle' => 'Backup Status',
            'reportSubtitle' => count($rows) . ' clients linked to Veeam · internal · as of ' . date('F j, Y'),
            'opt' => ['all' => $all],
            'optLabels' => ['all' => 'Clients with no problems'],
            'brand' => ReportController::branding(),
            'rows' => $rows,
            'shown' => $shown,
            'unlinked' => $unlinked,
        ], 'layout/print');
    }
}
