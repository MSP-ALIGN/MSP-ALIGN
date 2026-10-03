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

/**
 * Client mapping (which RMM organization / backup company each client is linked to) and Hosted backups (which
 * client each machine and job on your own backup servers belongs to).
 *
 * SECURITY: every page and action needs tech or above (turning automatic client creation on or off needs admin);
 * the router checks CSRF on every POST. Staff aren't limited to clients, so any existing client id may be posted,
 * but every outside record id and machine/job uid from a form is checked against what Align has stored before it is
 * used. Names shown come from the RMM/backup product and are escaped in the views. Every change is audited.
 */
final class MappingController
{
    /** The mapping screen: one column per linking connector, for every active client in planning. */
    public static function index(): void
    {
        Auth::requireRole('tech');
        $clients = DB::all('SELECT id, name, source FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name');
        $ids = array_flip(array_map('intval', array_column($clients, 'id')));
        // One column per connector that links clients (each RMM, each backup product, ...)
        $providers = [];
        foreach (Providers::linkConnectors() as $key => $c) {
            $records = $c->linkRecords();
            $links = ClientLinks::forProvider($key);
            $linked = count(array_filter($links, fn($l, $cid) => $l['external_id'] !== null && isset($ids[$cid]), ARRAY_FILTER_USE_BOTH));
            $providers[$key] = [
                'name' => $c->linkName(),
                'noun' => $c->linkNoun(),
                'icon' => $c->icon(),
                'count_label' => $c->linkCountLabel(),
                'configured' => $c->configured(),
                'backup' => $c instanceof \Align\Integrations\BackupConnector,
                // No PSA: an RMM's organizations can become clients (once each; see ClientLinks::createClientsFromOrgs)
                'creates' => $c instanceof \Align\Integrations\RmmConnector && !Providers::psaConfigured(),
                'creatable' => $c instanceof \Align\Integrations\RmmConnector && !Providers::psaConfigured()
                    ? (int) DB::value('SELECT COUNT(*) FROM rmm_orgs o LEFT JOIN client_links l ON l.provider = o.provider AND l.external_id = o.org_id
                        WHERE o.provider = ? AND l.client_id IS NULL AND o.client_created_at IS NULL', [$key]) : 0,
                'connector_name' => $c->name(),
                'records' => $records,
                'unlinked' => array_values(array_filter($records, fn($r) => $r['client_id'] === null)),
                'links' => $links,
                'summary' => $c->linkClientSummary(),
                'linked' => $linked,
            ];
        }
        // "Missing a link": a client with no link (and no "kept unlinked" decision) for a provider that has records to link to
        $missing = fn(array $cl) => (bool) array_filter($providers, fn($p) => $p['records'] && !isset($p['links'][(int) $cl['id']]));
        $show = query('show', '') === 'missing' ? 'missing' : 'all';
        View::render('mapping/index', [
            'title' => 'Client mapping',
            'nav' => 'mapping',
            'clients' => $show === 'missing' ? array_values(array_filter($clients, $missing)) : $clients,
            'total' => count($clients),
            'missing' => count(array_filter($clients, $missing)),
            'show' => $show,
            'providers' => $providers,
            'anyBackupCompanies' => (bool) array_filter($providers, fn($p) => $p['backup'] && $p['records']),
            'autoCreate' => \Align\Settings::get('rmm_create_clients', '0') === '1',
        ]);
    }

    /**
     * Without a PSA: make a client for each unlinked organization of an RMM, and optionally keep doing it on sync.
     * Tech or above makes them; only an admin turns the automatic setting on or off. Refused (back to the page) when a
     * PSA is connected, since clients then come from the PSA, or for a key that isn't an RMM connector.
     */
    public static function createClients(): void
    {
        Auth::requireRole('tech');
        $key = (string) post('provider');
        if (Providers::psaConfigured() || !isset(Providers::rmmConnectors()[$key])) {
            redirect('/mapping');
        }
        if (post('action') === 'auto') {
            Auth::requireRole('admin');
            $on = post('auto') === '1';
            \Align\Settings::set('rmm_create_clients', $on ? '1' : '0');
            \Align\Audit::log('settings.rmm_create_clients', $on ? 'On' : 'Off');
            flash('success', $on ? 'New organizations will become clients on each sync.' : 'New organizations will no longer become clients automatically.');
            redirect('/mapping');
        }
        $made = ClientLinks::createClientsFromOrgs($key);
        \Align\Audit::log('client.create_from_rmm', count($made) . ' from ' . Providers::rmmConnectors()[$key]->name() . ($made ? ': ' . implode(', ', array_slice($made, 0, 20)) : ''));
        flash('success', $made ? 'Added ' . count($made) . ' client' . (count($made) === 1 ? '' : 's') . ', each linked to its organization.' : 'No new clients: every organization already has one (or a client with the same name).');
        redirect('/mapping');
    }

    /**
     * Receives link[<provider key>][<client id>] = <record id | ''> for every client on the page ('' = not
     * linked, kept that way). Older pages' fields still work: rmm[<key>][<id>] and backup[<key>][<id>]
     * (1.29, 1.30), org[<id>] (the first RMM, before 1.29) and veeam[<id>] (Veeam, before 1.30).
     * Tech or above. A record id is used only when the provider has it; one record can't go to two clients; a record
     * held by a client that isn't on the form is refused (naming that client) instead of being taken. Client ids
     * that don't exist are ignored. Everything changes in one transaction and is audited.
     */
    public static function save(): void
    {
        Auth::requireRole('tech');
        $connectors = Providers::linkConnectors();
        foreach (['link', 'rmm', 'backup', 'org', 'veeam'] as $field) {
            if (isset($_POST[$field]) && !is_array($_POST[$field])) {
                redirect('/mapping');
            }
        }
        // Older field names map onto the same providers, with the precedence they had before:
        // org[] over rmm[<first RMM>] (1.29), veeam[] over backup[veeam] (1.30); link[] over everything.
        $rmmKeys = array_keys(Providers::rmmConnectors());
        $legacy = ($_POST['rmm'] ?? []) + ($_POST['backup'] ?? []);
        if (isset($_POST['org']) && $rmmKeys) {
            $legacy = [$rmmKeys[0] => $_POST['org']] + $legacy;
        }
        if (isset($_POST['veeam']) && isset($connectors['veeam'])) {
            $legacy = ['veeam' => $_POST['veeam']] + $legacy;
        }
        $posted = ($_POST['link'] ?? []) + $legacy;
        // provider => [client id => record id ('' = not linked)], only records the provider has
        $wanted = [];
        foreach ($posted as $key => $rows) {
            $key = (string) $key;
            if (!isset($connectors[$key]) || !is_array($rows)) {
                continue;
            }
            $c = $connectors[$key];
            $records = $c->linkRecords(); // read once: it counts every record's devices or machines
            $known = array_flip(array_column($records, 'id'));
            $wanted[$key] = [];
            $isRmm = in_array($key, $rmmKeys, true);
            foreach ($rows as $clientId => $id) {
                if (!is_scalar($id)) {
                    continue; // malformed: leave that client as it is
                }
                $id = (string) $id;
                if ($id === '' || ($isRmm && $id === '0' && !isset($known['0']))) { // '0' meant "none" on RMM pages before 1.29
                    $wanted[$key][(int) $clientId] = '';
                } elseif (isset($known[$id])) {
                    $wanted[$key][(int) $clientId] = $id;
                } // a record that's gone since the page loaded: leave that client as it is
            }
            $picked = array_filter($wanted[$key], fn($o) => $o !== '');
            if (count($picked) !== count(array_unique($picked))) {
                flash('error', 'Each ' . $c->linkName() . ' ' . $c->linkNoun() . ' can only be linked to one client. Nothing was saved.');
                redirect('/mapping' . self::showQuery());
            }
            // A record already linked to a client that isn't on this form (Missing a link hides it): say where,
            // instead of quietly taking it away. Clients not on the screen at all (archived, not planned) lose it as before.
            $holders = [];
            foreach (DB::all("SELECT l.client_id, l.external_id, c.name FROM client_links l JOIN clients c ON c.id = l.client_id
                    WHERE l.provider = ? AND l.external_id IS NOT NULL AND c.is_archived = 0 AND c.planning_excluded = 0", [$key]) as $h) {
                $holders[(string) $h['external_id']] = $h;
            }
            $names = array_column($records, 'name', 'id');
            foreach ($picked as $cid => $id) {
                $h = $holders[$id] ?? null;
                if ($h && (int) $h['client_id'] !== $cid && !array_key_exists((int) $h['client_id'], $wanted[$key])) {
                    flash('error', ($names[$id] ?? 'That ' . $c->linkNoun()) . ' is already linked to ' . $h['name'] . '. Set ' . $h['name'] . ' to Not linked first (under All clients). Nothing was saved.');
                    redirect('/mapping' . self::showQuery());
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
            redirect('/mapping' . self::showQuery());
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
        redirect('/mapping' . self::showQuery());
    }

    /** "?show=missing" when the form was posted from the Missing a link tab (a fixed value, never the posted text). */
    private static function showQuery(): string
    {
        return post('show') === 'missing' ? '?show=missing' : '';
    }

    /**
     * Backups on your own backup servers: machines and jobs from hosting companies (a backup company linked to
     * no client, or one flagged as hosting), how each was sorted into a client, and manual corrections.
     * Tech or above. ?show= is checked against the tab names.
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

    /**
     * Saves hosting flags and job / machine assignments, then re-sorts everything. Tech or above. Only stored
     * company uids are kept as hosting flags and only stored job / machine uids are assigned; a client id must
     * exist. A value that isn't text (a field posted as an array) leaves that item as it is. Flags that changed
     * are named in the audit entry: a flag decides whose backups a whole company's machines count for.
     */
    public static function saveBackups(): void
    {
        Auth::requireRole('tech');
        $clientIds = array_flip(array_map('intval', array_column(DB::all('SELECT id FROM clients'), 'id')));
        // Hosting flags, kept per backup product ({key}_hosting_companies)
        $postedHosting = array_map('strval', array_filter((array) ($_POST['hosting'] ?? []), 'is_scalar'));
        $hosting = [];
        $flagLog = [];
        foreach (array_keys(Providers::backupConnectors()) as $key) {
            $companyNames = array_column(DB::all('SELECT uid, name FROM backup_companies WHERE provider = ?', [$key]), 'name', 'uid');
            $known = array_flip(array_map('strval', array_keys($companyNames)));
            $mine = array_values(array_unique(array_filter($postedHosting, fn($u) => isset($known[$u]))));
            $was = json_decode((string) \Align\Settings::get($key . '_hosting_companies', '[]'), true);
            $was = is_array($was) ? array_map('strval', $was) : [];
            foreach (array_diff($mine, $was) as $u) {
                $flagLog[] = ($companyNames[$u] ?? $u) . ' → hosting';
            }
            foreach (array_intersect(array_diff($was, $mine), array_keys($known)) as $u) {
                $flagLog[] = ($companyNames[$u] ?? $u) . ' → not hosting';
            }
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
                if (!isset($names[$type][$uid]) || !is_string($val)) {
                    continue; // a uid Align doesn't have, or a malformed value: leave that item alone (not reset to automatic)
                }
                $want = $val === 'none' ? 'none' : (ctype_digit($val) && isset($clientIds[(int) $val]) ? $val : 'auto');
                $have = $current[$uid] ?? 'auto';
                if ($want === $have) {
                    continue;
                }
                // Delete and insert together: two saves at once can't leave a duplicate or a half-made change
                DB::transaction(function () use ($type, $uid, $want, $names) {
                    DB::run('DELETE FROM backup_assignments WHERE item_type = ? AND item_uid = ?', [$type, $uid]);
                    if ($want !== 'auto') {
                        DB::insert('backup_assignments', ['item_type' => $type, 'item_uid' => $uid, 'client_id' => $want === 'none' ? null : (int) $want,
                            'item_name' => mb_substr((string) $names[$type][$uid], 0, 255), 'created_by' => Auth::user()['id'] ?? null]);
                    }
                });
                $changes++;
                $log[] = $names[$type][$uid] . ' → ' . $want;
            }
        }
        $r = BackupSync::assign();
        Audit::log('backup.assign', $changes . ' change(s)' . ($log ? ': ' . mb_strimwidth(implode('; ', $log), 0, 900, '…') : '') . '; hosting companies: ' . count($hosting)
            . ($flagLog ? ' (' . mb_strimwidth(implode('; ', $flagLog), 0, 600, '…') . ')' : ''));
        flash('success', ($changes ? "Saved $changes change(s). " : 'Saved. ') . $r['hosted'] . ' hosted machine' . ($r['hosted'] == 1 ? '' : 's') . ' sorted into clients'
            . ($r['unsorted'] ? ', ' . $r['unsorted'] . ' still not matched.' : '.'));
        $show = preg_replace('/[^a-z]/', '', post('show'));
        redirect('/mapping/backups' . ($show !== '' ? '?show=' . $show : ''));
    }

    /**
     * Assigns several machines at once (the bulk bar on the Hosted backups page). Tech or above. client: a client id
     * that exists, 'none' (ours) or 'auto'. Only machine uids Align has stored are changed; the names are read in one
     * query and the changes made in one transaction, however many ids are posted.
     */
    public static function bulkBackups(): void
    {
        Auth::requireRole('tech');
        $ids = array_values(array_unique(array_filter((array) ($_POST['ids'] ?? []), fn($v) => is_string($v) && $v !== '')));
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
        $names = array_column(DB::all('SELECT uid, name FROM backup_workloads'), 'name', 'uid');
        DB::transaction(function () use ($ids, $names, $to, &$n) {
            foreach ($ids as $uid) {
                $name = $names[$uid] ?? null;
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
        });
        BackupSync::assign();
        $label = $to === 'none' ? 'yours (not a client\'s)' : ($to === 'auto' ? 'back to automatic' : $clientName);
        Audit::log('backup.assign', "$n machine(s) → $label (bulk)");
        flash('success', "$n machine" . ($n === 1 ? '' : 's') . " set to $label.");
        redirect($back);
    }
}
