<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Backup\Backup;
use Align\Lifecycle\Lifecycle;
use Align\Providers\Providers;
use Align\Reports\ReportData;
use Align\View;

/**
 * Backup status from the connected backup products.
 *
 * SECURITY: staff only (every role sees backup status; techs and admins change assignments and exemptions). The
 * router checks CSRF on every POST. Client ids come from the URL and are loaded with ClientController::load()
 * (404 when missing); an item posted for a client must belong to it (checked per kind in exempt()). Every change
 * is audited.
 */
final class BackupController
{
    /** The client's Backups page (any staff role, view audited); techs also see unmatched hosted machines to claim. */
    public static function client(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        Audit::access('backups', "#$id {$client['name']}");
        $devices = (new Lifecycle())->devices($id);
        $b = Backup::forClient($client, $devices);
        // Machines on your own backup server that aren't matched to anyone: offer them here, likeliest first
        $claim = null;
        if (Auth::can('tech') && Backup::hostedUnmatched() > 0) {
            $servers = $b ? array_column($b['unprotected'], 'name')
                : array_column(array_filter($devices, fn($d) => $d['device_class'] === 'server' && $d['status'] !== 'excluded'), 'name');
            $claim = Backup::claimable($client, $servers);
        }
        View::render('backups/client', [
            'title' => $client['name'] . ' · Backups',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'backups',
            'b' => $b,
            'claim' => $claim,
            'configured' => Providers::anyBackup(),
        ]);
    }

    /**
     * "This client's": assigns an unmatched hosted job or machine to this client from its Backups page. Techs and
     * admins, who can assign any hosted job or machine on the Hosted backups page anyway; a machine must not already
     * count for a client, and Microsoft 365 jobs (which follow their company) can't be assigned. Audited.
     */
    public static function claim(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $kind = post('kind') === 'job' ? 'job' : 'workload';
        $uid = post('uid');
        $row = $kind === 'job'
            ? \Align\DB::one("SELECT uid, name FROM backup_jobs WHERE uid = ? AND source <> 'm365'", [$uid])
            : \Align\DB::one('SELECT uid, name FROM backup_workloads WHERE uid = ? AND client_id IS NULL', [$uid]);
        if (!$row) {
            http_response_code(404);
            exit('Not found');
        }
        \Align\DB::run('DELETE FROM backup_assignments WHERE item_type = ? AND item_uid = ?', [$kind, $row['uid']]);
        \Align\DB::insert('backup_assignments', ['item_type' => $kind, 'item_uid' => $row['uid'], 'client_id' => $id,
            'item_name' => mb_substr($row['name'], 0, 255), 'created_by' => Auth::user()['id'] ?? null]);
        \Align\Sync\BackupSync::assign();
        Audit::log('backup.assign', "{$row['name']} → {$client['name']} (from the client's Backups page)");
        $n = $kind === 'job' ? (int) \Align\DB::value('SELECT COUNT(*) FROM backup_workloads WHERE client_id = ? AND uid IN (SELECT workload_uid FROM backup_workload_jobs WHERE job_uid = ?)', [$id, $row['uid']]) : 1;
        flash('success', ($kind === 'job' ? "Job {$row['name']} ($n machine" . ($n === 1 ? '' : 's') . ')' : $row['name']) . " now counts for {$client['name']}. Change it any time under Hosted backups.");
        redirect("/clients/$id/backups");
    }

    /** The printable backup report for one client (any staff role); details and machines can be left out. */
    public static function report(int $id): void
    {
        Auth::require();
        $opt = ['details' => query('details', '1') === '1', 'machines' => query('machines', '1') === '1'];
        self::renderReport(ClientController::load($id), $opt);
    }

    /**
     * Backup & recovery report for one client; also used by the client portal. The caller has checked the viewer
     * may see this client (the portal passes its own signed-in client, never one from the URL). Audited.
     */
    public static function renderReport(array $client, array $opt): void
    {
        $b = ReportData::backup((int) $client['id']);
        if (!$b) {
            http_response_code(404);
            if (defined('IS_PORTAL') && IS_PORTAL) {
                exit('Not found');
            }
            View::render('error', ['title' => 'No backup data', 'message' => 'This client is not linked to a ' . Providers::backupNames() . ' company yet.']);
            return;
        }
        Audit::log('report.backup', $client['name']);
        View::render('reports/backup', [
            'title' => $client['name'] . ' — Backup & Recovery Report',
            'reportTitle' => 'Backup & Recovery Report',
            'reportSubtitle' => $b['stats']['protected'] . ' protected machines · status as of ' . \Align\Fmt::date(time(), 'long'),
            'client' => $client,
            'opt' => $opt,
            'optLabels' => ['details' => 'Job details', 'machines' => 'Protected machines'],
            'brand' => ReportController::branding($client),
            'b' => $b,
        ], 'layout/print');
    }

    /**
     * 2.7.5 Links a backed-up machine of the client to one of its devices by hand (the names are too different to
     * match), or with unlink=1 removes the link. Applied at once (BackupSync::assign()); every sync keeps it while both
     * belong to the client. Techs and admins; both the machine and the device must be this client's.
     */
    public static function link(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $w = \Align\DB::one('SELECT uid, name FROM backup_workloads WHERE uid = ? AND client_id = ?', [post('workload'), $id]);
        if (!$w) {
            flash('error', 'Pick one of this client\'s backed-up machines.');
            redirect("/clients/$id/backups");
        }
        if (post('unlink') === '1') {
            \Align\DB::run('DELETE FROM backup_device_links WHERE workload_uid = ?', [$w['uid']]);
            \Align\Sync\BackupSync::assign(); // re-sorts under the sort's lock (a sync running now can't undo it)
            Audit::log('backup.unlink_device', "{$client['name']}: {$w['name']}");
            flash('success', "{$w['name']} is no longer linked to a device (the next sync matches it by name again, if it can).");
            redirect("/clients/$id/backups");
        }
        $ref = post('device');
        $d = ctype_digit($ref) ? (new Lifecycle())->devices($id, false, (int) $ref)[0] ?? null : null;
        if (!$d) {
            flash('error', 'Pick one of this client\'s devices.');
            redirect("/clients/$id/backups");
        }
        \Align\DB::run('REPLACE INTO backup_device_links (workload_uid, device_id, linked_by) VALUES (?, ?, ?)', [$w['uid'], (int) $d['id'], Auth::user()['id'] ?? null]);
        \Align\Sync\BackupSync::assign(); // applies it under the sort's lock, as claim() does
        Audit::log('backup.link_device', "{$client['name']}: {$w['name']} = {$d['name']}");
        flash('success', "{$d['name']} is backed up as {$w['name']}.");
        redirect("/clients/$id/backups");
    }

    /**
     * Marks a device, protected machine or Microsoft 365 item as not needing a backup, or undoes it.
     * POST action=add: kind (device|workload|m365), ref (device id or item uid), reason. action=remove: exemption.
     * Techs and admins. The item must be this client's: one of its devices, a machine sorted to it, or a Microsoft 365
     * object of its own backup company (404 otherwise); an exemption removed must be this client's too. A reason is
     * required (it is shown on the client's report). Both directions are audited. An item can be exempt at one
     * client at a time (unique key), so marking it here replaces a mark left at a client it moved from.
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
        $uids = \Align\Providers\ClientLinks::backupCompanyUids($id);
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
                    : ($uids ? \Align\DB::one('SELECT uid, name FROM backup_m365_objects WHERE uid = ? AND company_uid IN (' . implode(',', array_fill(0, count($uids), '?')) . ')', [$ref, ...$uids]) : null);
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

    /** Internal all-clients backup status (every client linked to a backup company). Any staff role; audited. */
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
            'reportSubtitle' => count($rows) . ' clients linked to ' . Providers::backupNames() . ' · internal · as of ' . \Align\Fmt::date(time(), 'long'),
            'opt' => ['all' => $all],
            'optLabels' => ['all' => 'Clients with no problems'],
            'brand' => ReportController::branding(),
            'rows' => $rows,
            'shown' => $shown,
            'unlinked' => $unlinked,
        ], 'layout/print');
    }
}
