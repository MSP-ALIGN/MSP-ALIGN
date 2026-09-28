<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Providers\ClientLinks;
use Align\Providers\Providers;
use Align\View;

final class MappingController
{
    public static function index(): void
    {
        Auth::requireRole('tech');
        $clients = DB::all('SELECT c.*, (SELECT COUNT(*) FROM devices d JOIN client_links l ON l.provider = d.rmm_provider AND l.external_id = d.rmm_org_id
                WHERE l.client_id = c.id AND d.client_id IS NULL AND d.removed_at IS NULL) AS device_count,
            (SELECT COUNT(*) FROM backup_workloads w WHERE w.client_id = c.id) AS veeam_machines,
            (SELECT COUNT(*) FROM backup_workloads w WHERE w.client_id = c.id AND w.client_how IN (\'device\',\'job\',\'machine\')) AS veeam_hosted,
            (SELECT COUNT(*) FROM backup_m365_objects m WHERE m.company_uid = c.veeam_company_uid AND m.object_type = \'user\') AS veeam_m365_users
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
        View::render('mapping/index', [
            'title' => 'Client mapping',
            'nav' => 'mapping',
            'clients' => $clients,
            'rmms' => $rmms,
            'unmappedOrgs' => $unmappedOrgs,
            'veeamConfigured' => \Align\Integrations\VeeamSpc::configured(),
            'veeam' => $veeam = DB::all('SELECT v.uid, v.name, c.id AS client_id,
                (SELECT COUNT(*) FROM backup_workloads w WHERE w.company_uid = v.uid) AS workloads
                FROM veeam_companies v LEFT JOIN clients c ON c.veeam_company_uid = v.uid ORDER BY v.name'),
            'unmappedVeeam' => array_values(array_filter($veeam, fn($v) => !$v['client_id'])),
        ]);
    }

    /**
     * Receives rmm[<rmm key>][<client id>] = <organization id | ''> and veeam[<client id>] = <VSPC company uid | ''>
     * for every client on the page. (org[<client id>] from pages before 1.29 counts as the first RMM.)
     */
    public static function save(): void
    {
        Auth::requireRole('tech');
        $postedRmm = $_POST['rmm'] ?? [];
        $postedVeeam = $_POST['veeam'] ?? [];
        $keys = array_keys(Providers::rmmConnectors());
        if (isset($_POST['org']) && is_array($_POST['org']) && $keys) {
            $postedRmm = [$keys[0] => $_POST['org']] + (is_array($postedRmm) ? $postedRmm : []);
        }
        if (!is_array($postedRmm) || !is_array($postedVeeam)) {
            redirect('/mapping');
        }
        // provider => [client id => org id ('' = not linked)], only organizations the provider has
        $wanted = [];
        foreach ($postedRmm as $key => $rows) {
            if (!in_array($key, $keys, true) || !is_array($rows)) {
                continue;
            }
            $known = array_flip(array_column(DB::all('SELECT org_id FROM rmm_orgs WHERE provider = ?', [$key]), 'org_id'));
            foreach ($rows as $clientId => $orgId) {
                $orgId = is_scalar($orgId) ? (string) $orgId : '';
                if ($orgId === '' || $orgId === '0') {
                    $wanted[$key][(int) $clientId] = '';
                } elseif (isset($known[$orgId])) {
                    $wanted[$key][(int) $clientId] = $orgId;
                } // an organization that's gone since the page loaded: leave that client as it is
            }
            $picked = array_filter($wanted[$key], fn($o) => $o !== '');
            if (count($picked) !== count(array_unique($picked))) {
                flash('error', 'Each ' . Providers::rmmName($key) . ' organization can only be linked to one client. Nothing was saved.');
                redirect('/mapping');
            }
        }
        $known = array_flip(array_column(DB::all('SELECT uid FROM veeam_companies'), 'uid'));
        $wantedV = [];
        foreach ($postedVeeam as $clientId => $uid) {
            $uid = (string) $uid;
            $wantedV[(int) $clientId] = $uid !== '' && isset($known[$uid]) ? $uid : '';
        }
        $pickedV = array_filter($wantedV);
        if (count($pickedV) !== count(array_unique($pickedV))) {
            flash('error', 'Each Veeam company can only be linked to one client. Nothing was saved.');
            redirect('/mapping');
        }

        $currentV = [];
        foreach (DB::all('SELECT id, veeam_company_uid FROM clients') as $c) {
            $currentV[(int) $c['id']] = (string) $c['veeam_company_uid'];
        }
        $changed = [];
        foreach ($wanted as $key => $rows) {
            $links = ClientLinks::forProvider($key);
            foreach ($rows as $cid => $org) {
                if (isset($currentV[$cid]) && (string) ($links[$cid]['external_id'] ?? '') !== $org) {
                    $changed[$key][$cid] = $org;
                }
            }
        }
        $changedV = array_filter($wantedV, fn($uid, $cid) => isset($currentV[$cid]) && $currentV[$cid] !== $uid, ARRAY_FILTER_USE_BOTH);
        if (!$changed && !$changedV) {
            flash('success', 'No changes.');
            redirect('/mapping');
        }

        DB::transaction(function () use ($changed, $changedV) {
            foreach ($changed as $key => $rows) {
                // Clear first so swapping organizations between clients doesn't hit the unique key.
                foreach (array_keys($rows) as $clientId) {
                    DB::run('UPDATE client_links SET external_id = NULL WHERE client_id = ? AND provider = ?', [$clientId, $key]);
                }
                foreach ($rows as $clientId => $orgId) {
                    // A manual link with no organization means "deliberately not linked": auto-match leaves it alone.
                    ClientLinks::set((int) $clientId, $key, $orgId === '' ? null : $orgId, 'manual');
                }
            }
            if ($changedV) {
                $ids = implode(',', array_map('intval', array_keys($changedV)));
                DB::run("UPDATE clients SET veeam_company_uid = NULL WHERE id IN ($ids)");
                foreach ($changedV as $clientId => $uid) {
                    if ($uid !== '') {
                        DB::run('UPDATE clients SET veeam_company_uid = NULL, veeam_match = NULL WHERE veeam_company_uid = ?', [$uid]);
                    }
                    DB::run("UPDATE clients SET veeam_company_uid = ?, veeam_match = 'manual' WHERE id = ?", [$uid ?: null, $clientId]);
                }
            }
        });
        if ($changedV || $changed) {
            \Align\Sync\VeeamSync::assign(); // re-sort hosted machines and jobs, re-link devices
        }
        $n = array_sum(array_map('count', $changed)) + count($changedV);
        Audit::log('mapping.save', "$n change(s): " . json_encode($changed + ['veeam' => $changedV]));
        flash('success', "Saved $n change(s).");
        redirect('/mapping');
    }

    /**
     * Backups on your own Veeam servers: machines and jobs from hosting companies (a Veeam company linked to
     * no client, or one flagged as hosting), how each was sorted into a client, and manual corrections.
     */
    public static function backups(): void
    {
        Auth::requireRole('tech');
        $pool = \Align\Sync\VeeamSync::hostingCompanies();
        $flagged = json_decode((string) \Align\Settings::get('veeam_hosting_companies', '[]'), true) ?: [];
        $companies = DB::all('SELECT v.uid, v.name, c.id AS client_id, c.name AS client_name,
            (SELECT COUNT(*) FROM backup_workloads w WHERE w.company_uid = v.uid) AS machines,
            (SELECT COUNT(*) FROM backup_jobs j WHERE j.company_uid = v.uid) AS jobs
            FROM veeam_companies v LEFT JOIN clients c ON c.veeam_company_uid = v.uid ORDER BY v.name');
        $in = $pool ? implode(',', array_fill(0, count($pool), '?')) : "''";
        $manual = ['job' => [], 'workload' => []];
        foreach (DB::all('SELECT item_type, item_uid, client_id FROM backup_assignments') as $a) {
            $manual[$a['item_type']][$a['item_uid']] = $a['client_id'] === null ? 'none' : (string) (int) $a['client_id'];
        }
        $jobs = DB::all("SELECT j.uid, j.name, j.status, j.job_type, j.source, j.last_run, j.company_uid, v.name AS company_name,
                (SELECT COUNT(*) FROM backup_workload_jobs x WHERE x.job_uid = j.uid) AS machines,
                (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR '|') FROM backup_job_clients jc JOIN clients c ON c.id = jc.client_id WHERE jc.job_uid = j.uid) AS client_names
            FROM backup_jobs j LEFT JOIN veeam_companies v ON v.uid = j.company_uid
            WHERE (j.company_uid IN ($in) OR j.company_uid IS NULL) AND j.source <> 'm365' ORDER BY j.name", $pool); // Microsoft 365 goes by tenant, not machine
        $machines = DB::all("SELECT w.uid, w.name, w.kind, w.last_point, w.company_uid, w.client_id, w.client_how, w.device_id,
                c.name AS client_name, d.display_name AS device_name, v.name AS company_name,
                (SELECT GROUP_CONCAT(j.name ORDER BY j.name SEPARATOR '|') FROM backup_workload_jobs x JOIN backup_jobs j ON j.uid = x.job_uid WHERE x.workload_uid = w.uid) AS job_names
            FROM backup_workloads w LEFT JOIN clients c ON c.id = w.client_id LEFT JOIN devices d ON d.id = w.device_id
            LEFT JOIN veeam_companies v ON v.uid = w.company_uid
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
            'configured' => \Align\Integrations\VeeamSpc::configured(),
            'companies' => $companies,
            'pool' => array_flip($pool),
            'flagged' => array_flip(array_map('strval', $flagged)),
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
        $known = array_flip(array_column(DB::all('SELECT uid FROM veeam_companies'), 'uid'));
        $hosting = array_values(array_filter(array_map('strval', (array) ($_POST['hosting'] ?? [])), fn($u) => isset($known[$u])));
        \Align\Settings::set('veeam_hosting_companies', json_encode($hosting));

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
        $r = \Align\Sync\VeeamSync::assign();
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
        \Align\Sync\VeeamSync::assign();
        $label = $to === 'none' ? 'yours (not a client\'s)' : ($to === 'auto' ? 'back to automatic' : $clientName);
        Audit::log('backup.assign', "$n machine(s) → $label (bulk)");
        flash('success', "$n machine" . ($n === 1 ? '' : 's') . " set to $label.");
        redirect($back);
    }
}
