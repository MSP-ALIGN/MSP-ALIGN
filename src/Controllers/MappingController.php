<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Providers\ClientLinks;
use Align\Providers\Providers;
use Align\Sync\BackupSync;
use Align\View;

final class MappingController
{
    public static function index(): void
    {
        Auth::requireRole('tech');
        $clients = DB::all('SELECT c.*, (SELECT COUNT(*) FROM devices d JOIN client_links l ON l.provider = d.rmm_provider AND l.external_id = d.rmm_org_id
                WHERE l.client_id = c.id AND d.client_id IS NULL AND d.removed_at IS NULL) AS device_count,
            ' . ClientLinks::backupLinkedSql() . ' AS backup_linked,
            (SELECT COUNT(*) FROM backup_workloads w WHERE w.client_id = c.id) AS backup_machines,
            (SELECT COUNT(*) FROM backup_workloads w WHERE w.client_id = c.id AND w.client_how IN (\'device\',\'job\',\'machine\')) AS backup_hosted,
            (SELECT COUNT(*) FROM backup_m365_objects m WHERE m.company_uid IN ' . ClientLinks::backupCompaniesSql() . ' AND m.object_type = \'user\') AS backup_m365_users
            FROM clients c WHERE c.is_archived = 0 AND c.planning_excluded = 0 ORDER BY c.name');
        // One column per RMM: its organizations and each client's link
        $rmms = [];
        foreach (Providers::rmmConnectors() as $key => $c) {
            $rmms[$key] = [
                'name' => $c->name(),
                'orgs' => DB::all('SELECT o.org_id AS id, o.name, (SELECT COUNT(*) FROM devices d WHERE d.rmm_provider = o.provider AND d.rmm_org_id = o.org_id AND d.removed_at IS NULL) AS device_count,
                    l.client_id FROM rmm_orgs o LEFT JOIN client_links l ON l.provider = o.provider AND l.external_id = o.org_id WHERE o.provider = ? ORDER BY o.name', [$key]),
                'links' => ClientLinks::forProvider($key),
            ];
        }
        $unmappedOrgs = [];
        foreach ($rmms as $r) {
            foreach ($r['orgs'] as $o) {
                if (!$o['client_id']) {
                    $unmappedOrgs[] = $o + ['rmm' => $r['name']];
                }
            }
        }
        // One column per backup product: its companies and each client's link
        $backups = [];
        $unmappedCompanies = [];
        foreach (Providers::backupConnectors() as $key => $c) {
            $backups[$key] = [
                'name' => $c->shortName(),
                'configured' => $c->configured(),
                'companies' => DB::all('SELECT b.uid, b.name, l.client_id,
                    (SELECT COUNT(*) FROM backup_workloads w WHERE w.provider = b.provider AND w.company_uid = b.uid) AS workloads
                    FROM backup_companies b LEFT JOIN client_links l ON l.provider = b.provider AND l.external_id = b.uid WHERE b.provider = ? ORDER BY b.name', [$key]),
                'links' => ClientLinks::forProvider($key),
            ];
            foreach ($backups[$key]['companies'] as $o) {
                if (!$o['client_id']) {
                    $unmappedCompanies[] = $o + ['backup' => $c->shortName()];
                }
            }
        }
        View::render('mapping/index', [
            'title' => 'Client mapping',
            'nav' => 'mapping',
            'clients' => $clients,
            'rmms' => $rmms,
            'unmappedOrgs' => $unmappedOrgs,
            'backups' => $backups,
            'unmappedCompanies' => $unmappedCompanies,
        ]);
    }

    /**
     * Receives rmm[<rmm key>][<client id>] = <organization id | ''> and backup[<backup key>][<client id>] =
     * <company uid | ''> for every client on the page. (Older pages' org[<client id>] counts as the first RMM,
     * and veeam[<client id>] as Veeam.)
     */
    public static function save(): void
    {
        Auth::requireRole('tech');
        $rmmKeys = array_keys(Providers::rmmConnectors());
        $backupKeys = array_keys(Providers::backupConnectors());
        $posted = ['rmm' => $_POST['rmm'] ?? [], 'backup' => $_POST['backup'] ?? []];
        if (!is_array($posted['rmm']) || !is_array($posted['backup'])) {
            redirect('/mapping');
        }
        if (isset($_POST['org']) && is_array($_POST['org']) && $rmmKeys) {
            $posted['rmm'] = [$rmmKeys[0] => $_POST['org']] + $posted['rmm'];
        }
        if (isset($_POST['veeam']) && in_array('veeam', $backupKeys, true)) {
            if (!is_array($_POST['veeam'])) {
                redirect('/mapping');
            }
            $posted['backup'] = ['veeam' => $_POST['veeam']] + $posted['backup'];
        }
        // provider => [client id => outside id ('' = not linked)], only records the provider has
        $wanted = [];
        foreach (['rmm' => $rmmKeys, 'backup' => $backupKeys] as $kind => $keys) {
            foreach ($posted[$kind] as $key => $rows) {
                if (!in_array($key, $keys, true) || !is_array($rows)) {
                    continue;
                }
                $known = array_flip(array_map('strval', array_column($kind === 'rmm'
                    ? DB::all('SELECT org_id AS id FROM rmm_orgs WHERE provider = ?', [$key])
                    : DB::all('SELECT uid AS id FROM backup_companies WHERE provider = ?', [$key]), 'id')));
                $wanted[$key] = [];
                foreach ($rows as $clientId => $id) {
                    $id = is_scalar($id) ? (string) $id : '';
                    if ($id === '' || ($kind === 'rmm' && $id === '0')) {
                        $wanted[$key][(int) $clientId] = '';
                    } elseif (isset($known[$id])) {
                        $wanted[$key][(int) $clientId] = $id;
                    } // a record that's gone since the page loaded: leave that client as it is
                }
                $picked = array_filter($wanted[$key], fn($o) => $o !== '');
                if (count($picked) !== count(array_unique($picked))) {
                    flash('error', $kind === 'rmm'
                        ? 'Each ' . Providers::rmmName($key) . ' organization can only be linked to one client. Nothing was saved.'
                        : 'Each ' . Providers::backupName($key) . ' company can only be linked to one client. Nothing was saved.');
                    redirect('/mapping');
                }
            }
        }

        $clientIds = array_flip(array_map('intval', array_column(DB::all('SELECT id FROM clients'), 'id')));
        $changed = [];
        foreach ($wanted as $key => $rows) {
            $links = ClientLinks::forProvider($key);
            foreach ($rows as $cid => $id) {
                if (isset($clientIds[$cid]) && (string) ($links[$cid]['external_id'] ?? '') !== $id) {
                    $changed[$key][$cid] = $id;
                }
            }
        }
        if (!$changed) {
            flash('success', 'No changes.');
            redirect('/mapping');
        }

        DB::transaction(function () use ($changed) {
            foreach ($changed as $key => $rows) {
                // Clear first so swapping records between clients doesn't hit the unique key.
                foreach (array_keys($rows) as $clientId) {
                    DB::run('UPDATE client_links SET external_id = NULL WHERE client_id = ? AND provider = ?', [$clientId, $key]);
                }
                foreach ($rows as $clientId => $id) {
                    // A manual link with no record means "deliberately not linked": auto-match leaves it alone.
                    ClientLinks::set((int) $clientId, $key, $id === '' ? null : $id, 'manual');
                }
            }
        });
        BackupSync::assign(); // re-sort hosted machines and jobs, re-link devices (RMM links decide which devices a client has)
        $n = array_sum(array_map('count', $changed));
        Audit::log('mapping.save', "$n change(s): " . json_encode($changed));
        flash('success', "Saved $n change(s).");
        redirect('/mapping');
    }

    /**
     * Backups on your own backup servers: machines and jobs from hosting companies (a backup company linked to
     * no client, or one flagged as hosting), how each was sorted into a client, and manual corrections.
     */
    public static function backups(): void
    {
        Auth::requireRole('tech');
        $pool = BackupSync::hostingCompanies();
        $flagged = [];
        foreach (array_keys(Providers::backupConnectors()) as $key) {
            $f = json_decode((string) \Align\Settings::get($key . '_hosting_companies', '[]'), true);
            array_push($flagged, ...(is_array($f) ? array_map('strval', $f) : []));
        }
        $companies = DB::all('SELECT b.provider, b.uid, b.name, c.id AS client_id, c.name AS client_name,
            (SELECT COUNT(*) FROM backup_workloads w WHERE w.provider = b.provider AND w.company_uid = b.uid) AS machines,
            (SELECT COUNT(*) FROM backup_jobs j WHERE j.provider = b.provider AND j.company_uid = b.uid) AS jobs
            FROM backup_companies b LEFT JOIN client_links l ON l.provider = b.provider AND l.external_id = b.uid
            LEFT JOIN clients c ON c.id = l.client_id ORDER BY b.name');
        $in = $pool ? implode(',', array_fill(0, count($pool), '?')) : "''";
        $manual = ['job' => [], 'workload' => []];
        foreach (DB::all('SELECT item_type, item_uid, client_id FROM backup_assignments') as $a) {
            $manual[$a['item_type']][$a['item_uid']] = $a['client_id'] === null ? 'none' : (string) (int) $a['client_id'];
        }
        $jobs = DB::all("SELECT j.uid, j.provider, j.name, j.status, j.job_type, j.source, j.last_run, j.company_uid, v.name AS company_name,
                (SELECT COUNT(*) FROM backup_workload_jobs x WHERE x.job_uid = j.uid) AS machines,
                (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR '|') FROM backup_job_clients jc JOIN clients c ON c.id = jc.client_id WHERE jc.job_uid = j.uid) AS client_names
            FROM backup_jobs j LEFT JOIN backup_companies v ON v.provider = j.provider AND v.uid = j.company_uid
            WHERE (j.company_uid IN ($in) OR j.company_uid IS NULL) AND j.source <> 'm365' ORDER BY j.name", $pool); // Microsoft 365 goes by tenant, not machine
        $machines = DB::all("SELECT w.uid, w.provider, w.name, w.kind, w.last_point, w.company_uid, w.client_id, w.client_how, w.device_id,
                c.name AS client_name, d.display_name AS device_name, v.name AS company_name,
                (SELECT GROUP_CONCAT(j.name ORDER BY j.name SEPARATOR '|') FROM backup_workload_jobs x JOIN backup_jobs j ON j.uid = x.job_uid WHERE x.workload_uid = w.uid) AS job_names
            FROM backup_workloads w LEFT JOIN clients c ON c.id = w.client_id LEFT JOIN devices d ON d.id = w.device_id
            LEFT JOIN backup_companies v ON v.provider = w.provider AND v.uid = w.company_uid
            WHERE w.company_uid IN ($in) OR w.company_uid IS NULL
               OR w.uid IN (SELECT item_uid FROM backup_assignments WHERE item_type = 'workload')
            ORDER BY (w.client_id IS NULL AND w.client_how IS NULL) DESC, c.name, w.name", $pool);
        $isUnmatched = fn($m) => $m['client_id'] === null && $m['client_how'] === null;
        $isOurs = fn($m) => $m['client_id'] === null && $m['client_how'] !== null;
        $counts = ['unmatched' => count(array_filter($machines, $isUnmatched)), 'ours' => count(array_filter($machines, $isOurs))];
        $counts['sorted'] = count($machines) - $counts['unmatched'] - $counts['ours'];
        $counts['all'] = count($machines);
        $show = query('show', '');
        if (!isset($counts[$show])) {
            $show = $counts['unmatched'] ? 'unmatched' : 'all';
        }
        $shown = array_values(array_filter($machines, fn($m) => match ($show) {
            'unmatched' => $isUnmatched($m), 'ours' => $isOurs($m), 'sorted' => $m['client_id'] !== null, default => true,
        }));
        View::render('mapping/backups', [
            'title' => 'Hosted backups',
            'show' => $show,
            'counts' => $counts,
            'shown' => $shown,
            'nav' => 'hosted-backups',
            'configured' => Providers::anyBackup(),
            'several' => count(Providers::backupConnectors()) > 1,
            'companies' => $companies,
            'pool' => array_flip($pool),
            'flagged' => array_flip($flagged),
            'jobs' => $jobs,
            'machines' => $machines,
            'manual' => $manual,
            'clients' => DB::all('SELECT id, name FROM clients WHERE is_archived = 0 ORDER BY name'),
        ]);
    }

    /** Saves hosting flags and job / machine assignments, then re-sorts everything. */
    public static function saveBackups(): void
    {
        Auth::requireRole('tech');
        $clientIds = array_flip(array_map('intval', array_column(DB::all('SELECT id FROM clients'), 'id')));
        // Hosting flags, kept per backup product ({key}_hosting_companies)
        $postedHosting = array_map('strval', array_filter((array) ($_POST['hosting'] ?? []), 'is_scalar'));
        $hosting = [];
        foreach (array_keys(Providers::backupConnectors()) as $key) {
            $known = array_flip(array_map('strval', array_column(DB::all('SELECT uid FROM backup_companies WHERE provider = ?', [$key]), 'uid')));
            $mine = array_values(array_filter($postedHosting, fn($u) => isset($known[$u])));
            \Align\Settings::set($key . '_hosting_companies', json_encode($mine));
            array_push($hosting, ...$mine);
        }

        $names = ['job' => array_column(DB::all('SELECT uid, name FROM backup_jobs'), 'name', 'uid'),
            'workload' => array_column(DB::all('SELECT uid, name FROM backup_workloads'), 'name', 'uid')];
        $changes = 0;
        $log = [];
        foreach (['job' => 'job', 'workload' => 'wl'] as $type => $field) {
            $posted = $_POST[$field] ?? [];
            if (!is_array($posted)) {
                continue;
            }
            $current = [];
            foreach (DB::all('SELECT item_uid, client_id FROM backup_assignments WHERE item_type = ?', [$type]) as $a) {
                $current[$a['item_uid']] = $a['client_id'] === null ? 'none' : (string) (int) $a['client_id'];
            }
            foreach ($posted as $uid => $val) {
                $uid = (string) $uid;
                $val = (string) $val;
                if (!isset($names[$type][$uid])) {
                    continue;
                }
                $want = $val === 'none' ? 'none' : (ctype_digit($val) && isset($clientIds[(int) $val]) ? $val : 'auto');
                $have = $current[$uid] ?? 'auto';
                if ($want === $have) {
                    continue;
                }
                DB::run('DELETE FROM backup_assignments WHERE item_type = ? AND item_uid = ?', [$type, $uid]);
                if ($want !== 'auto') {
                    DB::insert('backup_assignments', ['item_type' => $type, 'item_uid' => $uid, 'client_id' => $want === 'none' ? null : (int) $want,
                        'item_name' => mb_substr((string) $names[$type][$uid], 0, 255), 'created_by' => Auth::user()['id'] ?? null]);
                }
                $changes++;
                $log[] = $names[$type][$uid] . ' → ' . $want;
            }
        }
        $r = BackupSync::assign();
        Audit::log('backup.assign', $changes . ' change(s)' . ($log ? ': ' . mb_strimwidth(implode('; ', $log), 0, 900, '…') : '') . '; hosting companies: ' . count($hosting));
        flash('success', ($changes ? "Saved $changes change(s). " : 'Saved. ') . $r['hosted'] . ' hosted machine' . ($r['hosted'] == 1 ? '' : 's') . ' sorted into clients'
            . ($r['unsorted'] ? ', ' . $r['unsorted'] . ' still not matched.' : '.'));
        $show = preg_replace('/[^a-z]/', '', post('show'));
        redirect('/mapping/backups' . ($show !== '' ? '?show=' . $show : ''));
    }

    /** Assigns several machines at once (the bulk bar on the Hosted backups page). */
    public static function bulkBackups(): void
    {
        Auth::requireRole('tech');
        $ids = array_values(array_filter(array_map('strval', (array) ($_POST['ids'] ?? []))));
        $to = post('client');
        $show = preg_replace('/[^a-z]/', '', post('show'));
        $back = '/mapping/backups' . ($show !== '' ? '?show=' . $show : '');
        $clientName = null;
        if ($to !== 'none' && $to !== 'auto') {
            $clientName = ctype_digit($to) ? DB::value('SELECT name FROM clients WHERE id = ?', [(int) $to]) : null;
            if ($clientName === null) {
                flash('error', 'Pick a client (or Ours) for the selected machines.');
                redirect($back);
            }
        }
        $n = 0;
        foreach ($ids as $uid) {
            $name = DB::value('SELECT name FROM backup_workloads WHERE uid = ?', [$uid]);
            if ($name === null) {
                continue;
            }
            DB::run("DELETE FROM backup_assignments WHERE item_type = 'workload' AND item_uid = ?", [$uid]);
            if ($to !== 'auto') {
                DB::insert('backup_assignments', ['item_type' => 'workload', 'item_uid' => $uid, 'client_id' => $to === 'none' ? null : (int) $to,
                    'item_name' => mb_substr((string) $name, 0, 255), 'created_by' => Auth::user()['id'] ?? null]);
            }
            $n++;
        }
        BackupSync::assign();
        $label = $to === 'none' ? 'yours (not a client\'s)' : ($to === 'auto' ? 'back to automatic' : $clientName);
        Audit::log('backup.assign', "$n machine(s) → $label (bulk)");
        flash('success', "$n machine" . ($n === 1 ? '' : 's') . " set to $label.");
        redirect($back);
    }
}
