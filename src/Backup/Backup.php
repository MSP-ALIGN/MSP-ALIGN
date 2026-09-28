<?php
declare(strict_types=1);

namespace Align\Backup;

use Align\DB;
use Align\Settings;

/** Backup status per client, built from what the Veeam sync stored. */
final class Backup
{
    public const STATUS = [
        'success' => ['Success', 'ok'], 'warning' => ['Warning', 'warn'], 'failed' => ['Failed', 'bad'],
        'running' => ['Running', 'info'], 'none' => ['Not run', 'muted'],
    ];

    public const M365_TYPES = ['user' => ['Users', 'Mailboxes and OneDrive'], 'group' => ['Groups', 'Group mailboxes and sites'],
        'team' => ['Teams', 'Channels, files and chats'], 'site' => ['SharePoint sites', 'Sites and libraries'], 'other' => ['Other', '']];

    public const M365_SERVICES = ['ExchangeOnline' => 'Exchange Online', 'SharePointOnlineAndOneDriveForBusiness' => 'SharePoint & OneDrive',
        'MicrosoftTeams' => 'Teams', 'MicrosoftTeamsChats' => 'Teams chats', 'MicrosoftExchangeServer' => 'Exchange Server', 'MicrosoftSharePointServer' => 'SharePoint Server'];

    /** A machine without a restore point newer than this many hours counts as overdue. */
    public static function staleHours(): int
    {
        return max(1, Settings::int('backup_stale_hours', 48));
    }

    public static function enabled(): bool
    {
        return \Align\Integrations\VeeamSpc::configured() || (int) DB::value('SELECT COUNT(*) FROM veeam_companies') > 0;
    }

    private static array $hasCache = [];
    private static ?int $unmatched = null;

    /** Machines on your own backup server(s) not matched to any client and not marked as yours. */
    public static function hostedUnmatched(): int
    {
        try {
            return self::$unmatched ??= (int) DB::value('SELECT COUNT(*) FROM backup_workloads WHERE client_id IS NULL AND client_how IS NULL');
        } catch (\Throwable) {
            return 0; // before the 1.26 migration
        }
    }

    /**
     * Unmatched hosted jobs and machines for one client's Backups page, likeliest first.
     * A name "looks like" the client when it contains the client's initials (Harbor Point Law Group -> HPLG),
     * a distinctive word from its name (Veterinary -> VET…), or the name of one of its servers with no backup.
     * @param string[] $serverNames names of the client's servers with no backup
     * @return array{jobs: array, machines: array, suggested: int}
     */
    public static function claimable(array $client, array $serverNames = []): array
    {
        $stop = ['inc', 'llc', 'llp', 'ltd', 'the', 'and', 'of', 'co', 'corp', 'company', 'group', 'services', 'pc', 'pllc', 'dds', 'md'];
        $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower((string) $client['name'])) ?: [], fn($w) => $w !== '' && !in_array($w, $stop, true)));
        $allWords = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower((string) $client['name'])) ?: [], fn($w) => $w !== '' && !in_array($w, ['inc', 'llc', 'llp', 'ltd', 'the', 'and', 'of'], true)));
        $keys = [];
        if (count($allWords) >= 2) {
            $keys[] = implode('', array_map(fn($w) => $w[0], $allWords)); // initials
        }
        if (count($words) >= 2) {
            $keys[] = implode('', array_map(fn($w) => $w[0], $words));
        }
        foreach ($words as $w) {
            if (strlen($w) >= 4) {
                $keys[] = $w;                 // dental -> DENTAL-SRV
            }
            if (strlen($w) >= 8) {
                $keys[] = substr($w, 0, 3);   // veterinary -> VET-APP01
            }
        }
        $keys = array_values(array_unique(array_filter($keys, fn($k) => strlen($k) >= 3)));
        $servers = array_values(array_filter(array_map(fn($n) => \Align\Integrations\VeeamSpc::hostKey((string) $n), $serverNames)));
        $score = function (string $name) use ($keys, $servers): int {
            $n = strtolower($name);
            $host = \Align\Integrations\VeeamSpc::hostKey($name);
            foreach ($servers as $sv) {
                if ($sv !== '' && ($host === $sv || str_contains($host, $sv) || str_contains($sv, $host))) {
                    return 3;
                }
            }
            $tokens = preg_split('/[^a-z0-9]+/', $n) ?: [];
            foreach ($keys as $k) {
                foreach ($tokens as $t) {
                    if ($t !== '' && str_starts_with($t, $k)) {
                        return 2;
                    }
                }
            }
            return 0;
        };
        $machines = DB::all("SELECT w.uid, w.name, w.kind, w.last_point,
                (SELECT GROUP_CONCAT(j.name ORDER BY j.name SEPARATOR ', ') FROM backup_workload_jobs x JOIN backup_jobs j ON j.uid = x.job_uid WHERE x.workload_uid = w.uid) AS job_names
            FROM backup_workloads w WHERE w.client_id IS NULL AND w.client_how IS NULL ORDER BY w.name");
        foreach ($machines as &$m) {
            $m['score'] = max($score($m['name']), $score((string) $m['job_names']) ? 1 : 0);
        }
        unset($m);
        // Jobs whose machines are all unmatched
        $jobs = DB::all("SELECT j.uid, j.name, j.last_run, COUNT(x.workload_uid) AS machines, GROUP_CONCAT(w.name ORDER BY w.name SEPARATOR ', ') AS machine_names
            FROM backup_jobs j JOIN backup_workload_jobs x ON x.job_uid = j.uid JOIN backup_workloads w ON w.uid = x.workload_uid
            WHERE j.source <> 'm365' AND NOT EXISTS (SELECT 1 FROM backup_job_clients jc WHERE jc.job_uid = j.uid)
              AND NOT EXISTS (SELECT 1 FROM backup_assignments a WHERE a.item_type = 'job' AND a.item_uid = j.uid)
            GROUP BY j.uid, j.name, j.last_run HAVING SUM(w.client_id IS NULL AND w.client_how IS NULL) = COUNT(*) ORDER BY j.name");
        foreach ($jobs as &$j) {
            $j['score'] = max($score($j['name']), $score((string) $j['machine_names']));
        }
        unset($j);
        $by = fn($a, $b) => [-$a['score'], strtolower($a['name'])] <=> [-$b['score'], strtolower($b['name'])];
        usort($machines, $by);
        usort($jobs, $by);
        return ['jobs' => $jobs, 'machines' => $machines,
            'suggested' => count(array_filter($machines, fn($m) => $m['score'] > 0)) + count(array_filter($jobs, fn($j) => $j['score'] > 0))];
    }

    /** Whether a client has any backup data: its own Veeam company, or machines / jobs sorted to it from a hosting server. */
    public static function has(array|int $client): bool
    {
        $id = (int) (is_array($client) ? $client['id'] : $client);
        if (is_array($client) && !empty($client['veeam_company_uid'])) {
            return true;
        }
        return self::$hasCache[$id] ??= (bool) DB::value('SELECT 1 FROM clients c WHERE c.id = ? AND (c.veeam_company_uid IS NOT NULL
            OR EXISTS (SELECT 1 FROM backup_workloads w WHERE w.client_id = c.id) OR EXISTS (SELECT 1 FROM backup_job_clients j WHERE j.client_id = c.id))', [$id]);
    }

    /**
     * Everything the client page and reports show. Null when the client has no backup data at all.
     * Machines and jobs come from the client's own Veeam company and from hosting servers (see VeeamSync::assign()).
     * $devices: Lifecycle::devices() for the client (used to find servers with no backup).
     */
    public static function forClient(array $client, ?array $devices = null): ?array
    {
        $cid = (int) $client['id'];
        $uid = $client['veeam_company_uid'] ?? null;
        $company = $uid ? DB::one('SELECT * FROM veeam_companies WHERE uid = ?', [$uid]) : null;
        $jobRows = DB::all('SELECT j.*, jc.how AS client_how, (SELECT COUNT(*) FROM backup_job_clients x WHERE x.job_uid = j.uid) AS client_count
            FROM backup_jobs j JOIN backup_job_clients jc ON jc.job_uid = j.uid AND jc.client_id = ? ORDER BY j.name', [$cid]);
        $wlRows = DB::all('SELECT w.*, d.display_name AS device_name FROM backup_workloads w LEFT JOIN devices d ON d.id = w.device_id
            WHERE w.client_id = ? ORDER BY w.name', [$cid]);
        if (!$company && !$jobRows && !$wlRows) {
            return null;
        }
        $stale = self::staleHours();
        $now = time();
        $ex = self::exemptions($cid);

        $jobs = [];
        foreach ($jobRows as $j) {
            // A job on a hosting server that backs up several clients: its messages can name other clients' machines
            $j['shared'] = (int) $j['client_count'] > 1;
            $j['hosted'] = $j['client_how'] !== 'company';
            [$label, $tone] = self::STATUS[$j['status']] ?? self::STATUS['none'];
            $age = $j['last_run'] ? ($now - strtotime($j['last_run'])) / 3600 : null;
            $note = '';
            if (!$j['is_enabled']) {
                [$label, $tone] = ['Disabled', 'muted'];
            } elseif ($j['status'] !== 'running' && $age !== null && $age > $stale * 2) {
                $note = 'Has not run for ' . self::ago($j['last_run']);
                if ($tone === 'ok') {
                    $tone = 'warn';
                }
            }
            $jobs[] = $j + ['label' => $label, 'tone' => $tone, 'note' => $note, 'kind' => self::jobKind($j)];
        }
        // An agent job for a machine marked "not required" doesn't count either
        if ($ex['list'] && ($agentJobs = array_filter($jobs, fn($j) => $j['agent_uid']))) {
            $agentDevice = [];
            foreach (DB::all("SELECT uid, device_id FROM backup_workloads WHERE client_id = ? AND kind = 'computer'", [$cid]) as $w) {
                $agentDevice[$w['uid']] = $w['device_id'];
            }
            foreach ($agentJobs as $i => $j) {
                $wu = 'computer:' . $j['agent_uid'];
                $dev = $agentDevice[$wu] ?? null;
                if (isset($ex['items'][$wu]) || ($dev && isset($ex['devices'][(int) $dev]))) {
                    $jobs[$i]['label'] = 'Not required';
                    $jobs[$i]['tone'] = 'muted';
                    $jobs[$i]['status_counted'] = false;
                    $jobs[$i]['note'] = '';
                }
            }
        }
        usort($jobs, fn($a, $b) => [self::rank($a['tone']), $a['name']] <=> [self::rank($b['tone']), $b['name']]);

        $workloads = [];
        foreach ($wlRows as $w) {
            $w['hosted'] = in_array($w['client_how'], ['device', 'job', 'machine'], true) && (string) $w['company_uid'] !== (string) $uid;
            $age = $w['last_point'] ? ($now - strtotime($w['last_point'])) / 3600 : null;
            $tone = match (true) {
                $age === null => 'bad',
                $age <= $stale => 'ok',
                $age <= $stale * 2 => 'warn',
                default => 'bad',
            };
            $exempt = $ex['items'][$w['uid']] ?? ($w['device_id'] ? $ex['devices'][(int) $w['device_id']] ?? null : null);
            $workloads[] = $w + ['age_h' => $age, 'tone' => $exempt ? 'muted' : $tone, 'exempt' => $exempt,
                'label' => $exempt ? 'Not required' : ($age === null ? 'No restore point' : ($tone === 'ok' ? 'Protected' : 'Overdue'))];
        }
        // Problems first (oldest restore point first); healthy machines grouped: virtual machines (servers) together, then computers
        $key = fn($w) => [self::rank($w['tone']), $w['tone'] === 'ok' ? 0 : -($w['age_h'] ?? 1e9), $w['kind'] === 'vm' ? 0 : 1, strtolower((string) $w['name'])];
        usort($workloads, fn($a, $b) => $key($a) <=> $key($b));

        // Servers the RMM or the PSA know about that no backup covers
        $covered = array_flip(array_filter(array_map(fn($w) => (int) $w['device_id'], $workloads)));
        $unprotected = [];
        foreach ($devices ?? [] as $d) {
            if ($d['device_class'] === 'server' && $d['type'] !== 'Hypervisor host' && $d['status'] !== 'excluded' && !isset($covered[(int) $d['id']])
                && !isset($ex['devices'][(int) $d['id']])) {
                $unprotected[] = $d;
            }
        }

        // Run history: last 30 days, one cell per day showing the worst result
        $since = date('Y-m-d', strtotime('-29 days'));
        $runs = DB::all('SELECT DATE(r.run_at) AS day, r.status, COUNT(*) AS n FROM backup_job_runs r
            JOIN backup_job_clients jc ON jc.job_uid = r.job_uid AND jc.client_id = ?
            WHERE r.run_at >= ? GROUP BY DATE(r.run_at), r.status', [$cid, $since]);
        $byDay = [];
        $tot = ['success' => 0, 'warning' => 0, 'failed' => 0];
        foreach ($runs as $r) {
            $byDay[$r['day']][$r['status']] = (int) $r['n'];
            $tot[$r['status']] += (int) $r['n'];
        }
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $c = $byDay[$d] ?? [];
            $days[] = ['date' => $d, 'tone' => !empty($c['failed']) ? 'bad' : (!empty($c['warning']) ? 'warn' : (!empty($c['success']) ? 'ok' : 'none')),
                'text' => date('M j', strtotime($d)) . ': ' . ($c ? implode(', ', array_map(fn($s, $n) => "$n " . strtolower(self::STATUS[$s][0]), array_keys($c), $c)) : 'no runs recorded')];
        }
        $runCount = array_sum($tot);

        $m365 = $uid ? self::m365($uid, $stale, $jobs, $ex['items']) : null;
        $all = $workloads;
        $workloads = array_values(array_filter($all, fn($w) => !$w['exempt']));

        $active = array_filter($jobs, fn($j) => $j['is_enabled'] && ($j['status_counted'] ?? true));
        $stats = [
            'jobs' => count($active),
            'failed' => count(array_filter($active, fn($j) => $j['status'] === 'failed')),
            'warning' => count(array_filter($active, fn($j) => $j['status'] === 'warning')),
            'protected' => count($workloads),
            'ok' => count(array_filter($workloads, fn($w) => $w['tone'] === 'ok')),
            'overdue' => count(array_filter($workloads, fn($w) => $w['tone'] !== 'ok')),
            'unprotected' => count($unprotected),
            'runs' => $runCount,
            'run_failed' => $tot['failed'],
            'rate' => $runCount ? (int) floor(($tot['success'] + $tot['warning']) / $runCount * 100) : null,
            'last_point' => $workloads ? max(array_map(fn($w) => (string) $w['last_point'], $workloads)) ?: null : null,
            'backup_bytes' => array_sum(array_map(fn($w) => (int) $w['backup_bytes'], $workloads)),
            'cloud_used' => ($company['cloud_used_bytes'] ?? null) !== null ? (int) $company['cloud_used_bytes'] : null,
            'cloud_quota' => ($company['cloud_quota_bytes'] ?? null) !== null ? (int) $company['cloud_quota_bytes'] : null,
        ];
        $stats['cloud_pct'] = $stats['cloud_quota'] ? (int) round($stats['cloud_used'] / $stats['cloud_quota'] * 100) : null;
        $stats['m365_overdue'] = $m365 ? $m365['overdue_count'] : 0;
        $stats['tone'] = $stats['failed'] || $stats['unprotected'] || count(array_filter($workloads, fn($w) => $w['tone'] === 'bad')) ? 'bad'
            : ($stats['warning'] || $stats['overdue'] || $stats['m365_overdue'] || array_filter($jobs, fn($j) => $j['tone'] === 'warn') ? 'warn' : 'ok');

        return [
            'company' => $company,
            'jobs' => $jobs,
            'workloads' => $workloads,
            'all_workloads' => array_merge($workloads, array_values(array_filter($all, fn($w) => $w['exempt']))),
            'exemptions' => $ex['list'],
            'm365' => $m365,
            'unprotected' => $unprotected,
            'days' => $days,
            'stats' => $stats,
            'stale' => $stale,
            'synced' => $company['synced_at'] ?? (max(array_merge(array_column($wlRows, 'synced_at'), array_column($jobRows, 'synced_at'))) ?: null),
            'hosted' => count(array_filter($all, fn($w) => $w['hosted'])),
        ];
    }

    /**
     * Microsoft 365 backup for one company: tenants, counts per object type, overdue objects.
     * Null when the company has no Veeam Backup for Microsoft 365 data.
     */
    private static function m365(string $uid, int $stale, array $jobs, array $exempt = []): ?array
    {
        $orgs = DB::all('SELECT * FROM backup_m365_orgs WHERE company_uid = ? ORDER BY name', [$uid]);
        $objects = DB::all('SELECT * FROM backup_m365_objects WHERE company_uid = ? ORDER BY name', [$uid]);
        $m365Jobs = array_values(array_filter($jobs, fn($j) => $j['source'] === 'm365'));
        if (!$orgs && !$objects && !$m365Jobs) {
            return null;
        }
        $now = time();
        $types = [];
        $overdue = [];
        $last = null;
        foreach ($objects as $o) {
            if (isset($exempt[$o['uid']])) {
                continue; // marked "backup not required": not counted either way
            }
            $age = $o['last_point'] ? ($now - strtotime($o['last_point'])) / 3600 : null;
            $tone = $age === null ? 'bad' : ($age <= $stale ? 'ok' : ($age <= $stale * 2 ? 'warn' : 'bad'));
            $t = $o['object_type'];
            $types[$t] ??= ['total' => 0, 'ok' => 0, 'overdue' => 0, 'last' => null];
            $types[$t]['total']++;
            $tone === 'ok' ? $types[$t]['ok']++ : $types[$t]['overdue']++;
            if ($o['last_point'] && $o['last_point'] > (string) $types[$t]['last']) {
                $types[$t]['last'] = $o['last_point'];
            }
            if ($o['last_point'] && $o['last_point'] > (string) $last) {
                $last = $o['last_point'];
            }
            if ($tone !== 'ok') {
                $overdue[] = $o + ['age_h' => $age, 'tone' => $tone, 'type_label' => rtrim(self::M365_TYPES[$t][0], 's')];
            }
        }
        $order = array_keys(self::M365_TYPES);
        uksort($types, fn($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
        usort($overdue, fn($a, $b) => [$a['tone'] === 'bad' ? 0 : 1, -($a['age_h'] ?? 1e9)] <=> [$b['tone'] === 'bad' ? 0 : 1, -($b['age_h'] ?? 1e9)]);
        foreach ($orgs as &$o) {
            $o['service_labels'] = array_map(fn($x) => self::M365_SERVICES[$x] ?? $x, array_filter(explode(',', (string) $o['services'])));
        }
        unset($o);
        return [
            'orgs' => $orgs,
            'types' => $types,
            'overdue' => $overdue,
            'overdue_count' => count($overdue),
            'total' => count($objects),
            'users' => $types['user']['total'] ?? 0,
            'licensed' => count(array_filter($objects, fn($o) => $o['object_type'] === 'user' && (int) $o['licensed'] === 1)),
            'last_point' => $last ?? (max(array_map(fn($o) => (string) $o['last_backup'], $orgs ?: [['last_backup' => '']])) ?: null),
            'jobs' => $m365Jobs,
            'tone' => array_filter($m365Jobs, fn($j) => $j['is_enabled'] && $j['tone'] === 'bad') || array_filter($overdue, fn($o) => $o['tone'] === 'bad') ? 'bad'
                : (array_filter($m365Jobs, fn($j) => $j['tone'] === 'warn') || $overdue ? 'warn' : 'ok'),
        ];
    }

    public const EXEMPT_KINDS = ['device' => 'Device', 'workload' => 'Protected machine', 'm365' => 'Microsoft 365 item'];

    /** Exemptions for one client: ['devices' => [id => row], 'items' => [uid => row], 'list' => rows]. */
    public static function exemptions(int $clientId): array
    {
        $out = ['devices' => [], 'items' => [], 'list' => []];
        foreach (DB::all('SELECT e.*, u.name AS created_by_name FROM backup_exemptions e LEFT JOIN users u ON u.id = e.created_by
                WHERE e.client_id = ? ORDER BY e.item_name', [$clientId]) as $e) {
            $out['list'][] = $e;
            if ($e['device_id']) {
                $out['devices'][(int) $e['device_id']] = $e;
            } elseif ($e['item_uid'] !== null) {
                $out['items'][$e['item_uid']] = $e;
            }
        }
        return $out;
    }

    /** One line per client for the portfolio report and dashboard. [client id => stats] */
    public static function summaries(): array
    {
        $stale = date('Y-m-d H:i:s', time() - self::staleHours() * 3600);
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        $out = [];
        $jc = 'FROM backup_jobs j JOIN backup_job_clients jc ON jc.job_uid = j.uid AND jc.client_id = c.id';
        $agentExempt = "NOT EXISTS (SELECT 1 FROM backup_exemptions e JOIN backup_workloads w2 ON w2.uid = CONCAT('computer:', j.agent_uid) WHERE e.client_id = c.id AND (e.item_uid = w2.uid OR e.device_id = w2.device_id))";
        foreach (DB::all("SELECT c.id,
                (SELECT COUNT(*) $jc WHERE j.is_enabled = 1) AS jobs,
                (SELECT COUNT(*) $jc WHERE j.is_enabled = 1 AND j.status = 'failed' AND $agentExempt) AS failed,
                (SELECT COUNT(*) $jc WHERE j.is_enabled = 1 AND j.status = 'warning' AND $agentExempt) AS warning,
                (SELECT COUNT(*) FROM backup_workloads w WHERE w.client_id = c.id
                    AND NOT EXISTS (SELECT 1 FROM backup_exemptions e WHERE e.client_id = c.id AND (e.item_uid = w.uid OR e.device_id = w.device_id))) AS protected,
                (SELECT COUNT(*) FROM backup_workloads w WHERE w.client_id = c.id AND (w.last_point IS NULL OR w.last_point < ?)
                    AND NOT EXISTS (SELECT 1 FROM backup_exemptions e WHERE e.client_id = c.id AND (e.item_uid = w.uid OR e.device_id = w.device_id))) AS overdue,
                (SELECT COUNT(*) FROM backup_m365_objects m WHERE m.company_uid = c.veeam_company_uid AND (m.last_point IS NULL OR m.last_point < ?)
                    AND NOT EXISTS (SELECT 1 FROM backup_exemptions e WHERE e.client_id = c.id AND e.item_uid = m.uid)) AS m365_overdue,
                (SELECT COUNT(*) FROM backup_job_runs r JOIN backup_job_clients rc ON rc.job_uid = r.job_uid AND rc.client_id = c.id WHERE r.run_at >= ?) AS runs,
                (SELECT COUNT(*) FROM backup_job_runs r JOIN backup_job_clients rc ON rc.job_uid = r.job_uid AND rc.client_id = c.id WHERE r.run_at >= ? AND r.status <> 'failed') AS runs_ok
            FROM clients c WHERE c.veeam_company_uid IS NOT NULL OR EXISTS (SELECT 1 FROM backup_workloads w WHERE w.client_id = c.id)
                OR EXISTS (SELECT 1 FROM backup_job_clients x WHERE x.client_id = c.id)", [$stale, $stale, $since, $since]) as $r) {
            $r = array_map('intval', $r);
            $r['overdue'] += $r['m365_overdue'];
            $r['rate'] = $r['runs'] ? (int) floor($r['runs_ok'] / $r['runs'] * 100) : null;
            $r['tone'] = $r['failed'] || $r['overdue'] ? 'bad' : ($r['warning'] ? 'warn' : ($r['jobs'] || $r['protected'] ? 'ok' : 'muted'));
            $out[$r['id']] = $r;
        }
        return $out;
    }

    /** device id => [last restore point, tone] for one client's devices ($companyUid is no longer needed; kept for callers). */
    public static function deviceMap(?string $companyUid, ?int $clientId = null): array
    {
        $out = [];
        if ($clientId) {
            foreach (DB::all("SELECT device_id FROM backup_exemptions WHERE client_id = ? AND kind = 'device'", [$clientId]) as $e) {
                $out[(int) $e['device_id']] = ['last_point' => null, 'tone' => 'muted', 'exempt' => true];
            }
        }
        if (!$clientId) {
            return $out;
        }
        $stale = self::staleHours();
        foreach (DB::all('SELECT device_id, MAX(last_point) AS lp FROM backup_workloads WHERE client_id = ? AND device_id IS NOT NULL GROUP BY device_id', [$clientId]) as $r) {
            $age = $r['lp'] ? (time() - strtotime($r['lp'])) / 3600 : null;
            if (isset($out[(int) $r['device_id']])) {
                $out[(int) $r['device_id']]['last_point'] = $r['lp'];
                continue;
            }
            $out[(int) $r['device_id']] = ['exempt' => false, 'last_point' => $r['lp'], 'tone' => $age === null ? 'bad' : ($age <= $stale ? 'ok' : ($age <= $stale * 2 ? 'warn' : 'bad'))];
        }
        if ($clientId) {
            // A protected machine marked "not required" also clears its device
            foreach (DB::all('SELECT w.device_id FROM backup_exemptions e JOIN backup_workloads w ON w.uid = e.item_uid WHERE e.client_id = ? AND w.device_id IS NOT NULL', [$clientId]) as $r) {
                $out[(int) $r['device_id']] = ['last_point' => $out[(int) $r['device_id']]['last_point'] ?? null, 'tone' => 'muted', 'exempt' => true];
            }
        }
        return $out;
    }

    public static function jobKind(array $j): string
    {
        $t = strtolower((string) $j['job_type']);
        return match (true) {
            $j['source'] === 'm365' => str_contains($t, 'copy') ? 'Microsoft 365 copy' : 'Microsoft 365',
            $j['source'] === 'agent' || str_contains($t, 'agent') => 'Agent backup',
            str_contains($t, 'replica') => 'Replication',
            str_contains($t, 'copy') => 'Backup copy',
            str_contains($t, 'file') || str_contains($t, 'nas') => 'File backup',
            str_contains($t, 'tape') => 'Tape',
            str_contains($t, 'vm') || $t === '' => 'VM backup',
            default => ucfirst(preg_replace('/(?<!^)([A-Z])/', ' $1', (string) $j['job_type']) ?? ''),
        };
    }

    /** "3 hr ago" style, but "2 days" (no "ago") for use in sentences. */
    public static function ago(?string $d): string
    {
        return $d ? preg_replace('/ ago$/', '', rel_time($d)) : 'never';
    }

    public static function age(?float $hours): string
    {
        return match (true) {
            $hours === null => 'never',
            $hours < 1 => 'under 1 hr',
            $hours < 48 => (int) round($hours) . ' hr',
            default => (int) floor($hours / 24) . ' days',
        };
    }

    private static function rank(string $tone): int
    {
        return ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3, 'muted' => 4][$tone] ?? 5;
    }
}
