<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\Backup\Backup;
use Align\DB;

/** Backup status (from the backup products), "not required" exemptions and hosted backup assignments. */
final class Backups
{
    /** One line per client with backup data. */
    public static function index(): array
    {
        $names = array_column(DB::all('SELECT id, name FROM clients WHERE is_archived = 0'), 'name', 'id');
        $rows = [];
        foreach (Backup::summaries() as $id => $s) {
            if (!isset($names[$id]) || !Context::allowsClient((int) $id)) {
                continue;
            }
            $rows[] = ['client_id' => (int) $id, 'client_name' => $names[$id], 'health' => $s['tone'], 'jobs' => $s['jobs'], 'failed_jobs' => $s['failed'],
                'jobs_with_warnings' => $s['warning'], 'protected_machines' => $s['protected'], 'overdue' => $s['overdue'], 'success_rate_30d' => $s['rate']];
        }
        usort($rows, fn($a, $b) => [['bad' => 0, 'warn' => 1, 'ok' => 2][$a['health']] ?? 3, $a['client_name']] <=> [['bad' => 0, 'warn' => 1, 'ok' => 2][$b['health']] ?? 3, $b['client_name']]);
        return Out::slice($rows);
    }

    /** Everything for one client: stats, 30-day history, jobs, machines, Microsoft 365, servers with no backup. */
    public static function client(int $id): array
    {
        $client = Clients::load($id);
        $b = Backup::forClient($client, (new \Align\Lifecycle\Lifecycle())->devices($id));
        if (!$b) {
            throw new ApiError(404, 'no_backup_data', 'No backup data for this client (not linked to a' . (preg_match('/^[AEIOU]/i', $bn = \Align\Providers\Providers::backupNames()) ? 'n' : '') . ' ' . $bn . ' company and nothing on your own backup server is matched to it).');
        }
        $s = $b['stats'];
        return Out::one([
            'client_id' => $id,
            'health' => $s['tone'],
            'synced_at' => Out::ts($b['synced']),
            'stale_after_hours' => $b['stale'],
            'stats' => ['jobs' => $s['jobs'], 'failed_jobs' => $s['failed'], 'jobs_with_warnings' => $s['warning'], 'protected_machines' => $s['protected'],
                'current' => $s['ok'], 'overdue' => $s['overdue'], 'servers_without_backup' => $s['unprotected'], 'runs_30d' => $s['runs'],
                'success_rate_30d' => $s['rate'], 'backup_bytes' => $s['backup_bytes'], 'cloud_used_bytes' => $s['cloud_used'], 'cloud_quota_bytes' => $s['cloud_quota']],
            'history_30d' => array_map(fn($d) => ['date' => $d['date'], 'result' => ['ok' => 'success', 'warn' => 'warning', 'bad' => 'failed', 'none' => 'none'][$d['tone']] ?? $d['tone']], $b['days']),
            // A job shared by several clients can name other clients' machines: a key limited to certain clients doesn't
            // see it at all (its result still counts in stats) (1.45)
            'jobs' => array_map(function (array $j) {
                $hide = $j['shared'] && Context::clients() !== null;
                return [
                'uid' => $j['uid'], 'name' => $j['name'], 'kind' => $j['kind'], 'status' => $j['status'], 'label' => $j['label'], 'health' => $j['tone'],
                'enabled' => (bool) $j['is_enabled'], 'last_run' => Out::ts($j['last_run']), 'duration_seconds' => Out::int($j['duration_sec']),
                'message' => $hide ? null : ($j['failure_message'] ?: ($j['note'] ?: null)), 'target' => $hide ? null : $j['target'], 'size_bytes' => $hide ? null : Out::int($j['chain_bytes']),
                'hosted' => (bool) $j['hosted'], 'shared_with_other_clients' => (bool) $j['shared'],
                ];
            }, array_values(array_filter($b['jobs'], fn($j) => !($j['shared'] && Context::clients() !== null)))),
            'machines' => array_map(fn($w) => [
                'uid' => $w['uid'], 'name' => $w['name'], 'kind' => $w['kind'], 'status' => $w['label'], 'health' => $w['tone'],
                'newest_restore_point' => Out::ts($w['last_point']), 'restore_points' => Out::int($w['restore_points']), 'backup_bytes' => Out::int($w['backup_bytes']),
                'device_id' => Out::int($w['device_id']), 'hosted' => (bool) $w['hosted'], 'not_required' => (bool) $w['exempt'],
            ], $b['all_workloads']),
            // Which servers (names) needs devices:read; the count is in stats either way
            'servers_without_backup' => Context::can('devices:read') ? array_map(fn($d) => ['device_id' => (int) $d['id'], 'name' => $d['name'], 'type' => $d['type']], $b['unprotected']) : null,
            'microsoft_365' => $b['m365'] ? ['health' => $b['m365']['tone'], 'protected_objects' => $b['m365']['total'], 'users' => $b['m365']['users'],
                'overdue' => $b['m365']['overdue_count'], 'newest_restore_point' => Out::ts($b['m365']['last_point']),
                'by_type' => array_map(fn($t) => ['protected' => $t['total'], 'current' => $t['ok'], 'overdue' => $t['overdue'], 'newest' => Out::ts($t['last'])], $b['m365']['types']),
                'objects' => array_map(fn($o) => ['uid' => $o['uid'], 'name' => $o['name'], 'type' => $o['object_type'],
                    'health' => !empty($o['retired']) ? 'retired' : $o['tone'], 'newest_restore_point' => Out::ts($o['last_point']), 'restore_points' => Out::int($o['restore_points']),
                    'days_with_backup' => Out::int($o['restore_days']), 'counting_since' => $o['days_from'] ? (string) $o['days_from'] : null,
                    'repositories' => Out::int($o['repositories']), 'not_required' => !empty($o['exempt']),
                    'no_longer_backed_up' => !empty($o['retired'])], array_merge($b['m365']['objects'], $b['m365']['retired']))] : null,
            'url' => Out::url("/clients/$id/backups"),
        ]);
    }

    // ---- Exemptions ("backup not required") ----

    public static function exemptions(int $id): array
    {
        Clients::load($id);
        $rows = DB::all('SELECT * FROM backup_exemptions WHERE client_id = ? ORDER BY item_name', [$id]);
        return Out::slice(array_map([self::class, 'exemptionShape'], $rows));
    }

    public static function exemptionShape(array $e): array
    {
        return ['id' => (int) $e['id'], 'client_id' => (int) $e['client_id'], 'kind' => $e['kind'], 'device_id' => Out::int($e['device_id']),
            'item_uid' => $e['item_uid'], 'name' => $e['item_name'], 'reason' => $e['reason'], 'created_at' => Out::ts($e['created_at'])];
    }

    public static function exempt(int $id): array
    {
        $client = Clients::load($id);
        $in = Input::clean(Context::$body, [
            'kind' => ['string', ['required' => true, 'enum' => ['device', 'workload', 'm365'], 'desc' => 'device (a server with no backup), workload (a protected machine) or m365 (a Microsoft 365 user, group, team or site).']],
            'device_id' => ['int', ['min' => 1, 'desc' => 'For kind=device.']],
            'item_uid' => ['string', ['max' => 190, 'desc' => 'For kind=workload or m365 (the uid from GET /clients/{id}/backups).']],
            'reason' => ['string', ['required' => true, 'max' => 255, 'desc' => 'Why it doesn\'t need a backup (kept in the audit trail).']],
        ], true);
        $row = ['client_id' => $id, 'kind' => $in['kind'], 'device_id' => null, 'item_uid' => null, 'reason' => $in['reason'], 'created_by' => null];
        if ($in['kind'] === 'device') {
            $d = !empty($in['device_id']) ? ((new \Align\Lifecycle\Lifecycle())->devices($id, false, $in['device_id'])[0] ?? null) : null;
            if (!$d) {
                throw ApiError::invalid(['device_id' => 'No device with that id for this client.']);
            }
            $row['device_id'] = (int) $d['id'];
            $row['item_name'] = $d['name'];
        } else {
            $item = empty($in['item_uid']) ? null : ($in['kind'] === 'workload'
                ? DB::one('SELECT uid, name FROM backup_workloads WHERE uid = ? AND client_id = ?', [$in['item_uid'], $id])
                : (($uids = \Align\Providers\ClientLinks::backupCompanyUids($id)) ? DB::one('SELECT uid, name FROM backup_m365_objects WHERE uid = ? AND company_uid IN (' . implode(',', array_fill(0, count($uids), '?')) . ')', [$in['item_uid'], ...$uids]) : null));
            if (!$item) {
                throw ApiError::invalid(['item_uid' => 'No such item in this client\'s backups.']);
            }
            $row['item_uid'] = $item['uid'];
            $row['item_name'] = mb_substr($item['name'], 0, 255);
        }
        $key = $row['device_id'] !== null ? ['device_id = ?', $row['device_id']] : ['item_uid = ?', $row['item_uid']];
        DB::run("DELETE FROM backup_exemptions WHERE client_id = ? AND {$key[0]}", [$id, $key[1]]);
        $eid = DB::insert('backup_exemptions', $row);
        \Align\Audit::log('backup.exempt', "{$client['name']}: {$row['item_name']} — {$in['reason']}");
        return Out::one(self::exemptionShape(DB::one('SELECT * FROM backup_exemptions WHERE id = ?', [$eid])), 201);
    }

    public static function unexempt(int $id, int $exemption): array
    {
        $client = Clients::load($id);
        $e = DB::one('SELECT * FROM backup_exemptions WHERE id = ? AND client_id = ?', [$exemption, $id]);
        if (!$e) {
            throw ApiError::notFound('Exemption');
        }
        DB::run('DELETE FROM backup_exemptions WHERE id = ?', [$exemption]);
        \Align\Audit::log('backup.unexempt', "{$client['name']}: {$e['item_name']}");
        return Out::none();
    }

    // ---- Hosted backups (machines and jobs on your own backup server) ----

    private static function requireAllClients(): void
    {
        if (Context::clients() !== null) {
            throw new ApiError(403, 'all_clients_required', 'Hosted backups span every client, so this needs a key that isn\'t limited to certain clients.');
        }
    }

    public static function hosted(): array
    {
        self::requireAllClients();
        $pool = \Align\Sync\BackupSync::hostingCompanies();
        $in = $pool ? implode(',', array_fill(0, count($pool), '?')) : "''";
        $show = Input::queryStr('show', ['unmatched', 'sorted', 'ours', 'all']) ?? 'all';
        $manual = [];
        foreach (DB::all('SELECT item_type, item_uid, client_id FROM backup_assignments') as $a) {
            $manual[$a['item_type'] . ':' . $a['item_uid']] = $a['client_id'] === null ? 'ours' : (int) $a['client_id'];
        }
        $machines = DB::all("SELECT w.uid, w.name, w.kind, w.last_point, w.client_id, w.client_how, w.device_id, c.name AS client_name
            FROM backup_workloads w LEFT JOIN clients c ON c.id = w.client_id
            WHERE w.company_uid IN ($in) OR w.company_uid IS NULL OR w.uid IN (SELECT item_uid FROM backup_assignments WHERE item_type = 'workload') ORDER BY w.name", $pool);
        $jobsOf = [];
        foreach (DB::all('SELECT workload_uid, job_uid FROM backup_workload_jobs') as $r) {
            $jobsOf[$r['workload_uid']][] = $r['job_uid'];
        }
        $machines = array_values(array_filter($machines, fn($m) => match ($show) {
            'unmatched' => $m['client_id'] === null && $m['client_how'] === null,
            'ours' => $m['client_id'] === null && $m['client_how'] !== null,
            'sorted' => $m['client_id'] !== null,
            default => true,
        }));
        [$page, $per, $off] = Input::page();
        $jobs = DB::all("SELECT j.uid, j.name, j.status, j.last_run FROM backup_jobs j WHERE (j.company_uid IN ($in) OR j.company_uid IS NULL) AND j.source <> 'm365' ORDER BY j.name", $pool);
        $jobClients = [];
        foreach (DB::all('SELECT job_uid, client_id FROM backup_job_clients') as $r) {
            $jobClients[$r['job_uid']][] = (int) $r['client_id'];
        }
        return Out::list(array_map(fn($m) => [
            'uid' => $m['uid'], 'name' => $m['name'], 'kind' => $m['kind'], 'newest_restore_point' => Out::ts($m['last_point']),
            'client_id' => Out::int($m['client_id']), 'client_name' => $m['client_name'],
            'matched_by' => $m['client_how'] === null ? null : (['device' => 'device_name', 'job' => 'job', 'machine' => 'manual', 'company' => 'company'][$m['client_how']] ?? $m['client_how']),
            'state' => $m['client_id'] !== null ? 'sorted' : ($m['client_how'] !== null ? 'ours' : 'unmatched'),
            'assignment' => $manual['workload:' . $m['uid']] ?? 'auto',
            'device_id' => Out::int($m['device_id']), 'job_uids' => $jobsOf[$m['uid']] ?? [],
        ], array_slice($machines, $off, $per)), count($machines), $page, $per, ['jobs' => array_map(fn($j) => [
            'uid' => $j['uid'], 'name' => $j['name'], 'status' => $j['status'], 'last_run' => Out::ts($j['last_run']),
            'client_ids' => $jobClients[$j['uid']] ?? [], 'assignment' => $manual['job:' . $j['uid']] ?? 'auto',
        ], $jobs), 'unmatched' => Backup::hostedUnmatched()]);
    }

    private static function assignment(): int|string|null
    {
        $v = Context::$body['assign'] ?? null;
        if (array_diff(array_keys(Context::$body), ['assign']) || !(is_int($v) || in_array($v, ['auto', 'ours'], true))) {
            throw ApiError::invalid(['assign' => 'Send {"assign": <client id>}, {"assign": "ours"} (not a client\'s) or {"assign": "auto"} (match automatically).']);
        }
        if (is_int($v) && !DB::value('SELECT 1 FROM clients WHERE id = ? AND is_archived = 0', [$v])) {
            throw ApiError::invalid(['assign' => 'No client with that id.']);
        }
        return $v;
    }

    private static function setAssignment(string $type, string $uid, string $name, int|string $v): void
    {
        DB::run('DELETE FROM backup_assignments WHERE item_type = ? AND item_uid = ?', [$type, $uid]);
        if ($v !== 'auto') {
            DB::insert('backup_assignments', ['item_type' => $type, 'item_uid' => $uid, 'client_id' => $v === 'ours' ? null : (int) $v, 'item_name' => mb_substr($name, 0, 255), 'created_by' => null]);
        }
        \Align\Sync\BackupSync::assign();
        \Align\Audit::log('backup.assign', "$name → " . ($v === 'ours' ? 'ours' : ($v === 'auto' ? 'automatic' : "client #$v")));
    }

    public static function assignMachine(string $uid): array
    {
        self::requireAllClients();
        $w = DB::one('SELECT uid, name FROM backup_workloads WHERE uid = ?', [$uid]);
        if (!$w) {
            throw ApiError::notFound('Machine');
        }
        self::setAssignment('workload', $w['uid'], $w['name'], self::assignment());
        $r = DB::one('SELECT uid, name, client_id, client_how, device_id FROM backup_workloads WHERE uid = ?', [$uid]);
        return Out::one(['uid' => $r['uid'], 'name' => $r['name'], 'client_id' => Out::int($r['client_id']), 'device_id' => Out::int($r['device_id']),
            'state' => $r['client_id'] !== null ? 'sorted' : ($r['client_how'] !== null ? 'ours' : 'unmatched')]);
    }

    public static function assignJob(string $uid): array
    {
        self::requireAllClients();
        $j = DB::one("SELECT uid, name FROM backup_jobs WHERE uid = ? AND source <> 'm365'", [$uid]);
        if (!$j) {
            throw ApiError::notFound('Job');
        }
        self::setAssignment('job', $j['uid'], $j['name'], self::assignment());
        return Out::one(['uid' => $j['uid'], 'name' => $j['name'],
            'client_ids' => array_map('intval', array_column(DB::all('SELECT client_id FROM backup_job_clients WHERE job_uid = ?', [$uid]), 'client_id')),
            'machines' => (int) DB::value('SELECT COUNT(*) FROM backup_workload_jobs WHERE job_uid = ?', [$uid])]);
    }
}
