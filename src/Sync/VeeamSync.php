<?php
declare(strict_types=1);

namespace Align\Sync;

use Align\DB;
use Align\Integrations\VeeamSpc as V;

/**
 * Pulls companies, backup jobs, protected machines and Cloud Connect usage from the
 * Veeam Service Provider Console, links companies to clients and machines to devices.
 * Read-only: nothing is ever written back to Veeam.
 */
final class VeeamSync
{
    /** @param callable(string):void $info */
    public static function run(V $api, callable $info): string
    {
        $now = date('Y-m-d H:i:s');
        $parts = [];

        // Companies
        $companies = $api->companies();
        $uids = [];
        foreach ($companies as $c) {
            $uid = (string) ($c['instanceUid'] ?? '');
            if ($uid === '') {
                continue;
            }
            $uids[] = $uid;
            DB::run('INSERT INTO veeam_companies (uid, name, status, synced_at) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), synced_at = VALUES(synced_at)',
                [$uid, mb_substr((string) ($c['name'] ?? $uid), 0, 255), $c['status'] ?? null, $now]);
        }
        if ($uids) {
            $in = self::in($uids);
            DB::run("UPDATE clients SET veeam_company_uid = NULL, veeam_match = NULL WHERE veeam_company_uid IS NOT NULL AND veeam_company_uid NOT IN ($in)", $uids);
            DB::run("DELETE FROM veeam_companies WHERE uid NOT IN ($in)", $uids);
        }
        $parts[] = count($uids) . ' companies';

        // Cloud Connect storage (only when the provider runs Cloud Connect and the key can see it)
        $usage = $api->cloudUsage();
        if ($usage !== null) {
            $sum = [];
            foreach ($usage as $u) {
                $cu = (string) ($u['companyUid'] ?? V::orgOf($u) ?? '');
                if ($cu === '') {
                    continue;
                }
                $sum[$cu][0] = ($sum[$cu][0] ?? 0) + (int) V::int($u['storageQuota'] ?? null);
                $sum[$cu][1] = ($sum[$cu][1] ?? 0) + (int) V::int($u['usedStorageQuota'] ?? $u['usedStorage'] ?? null);
            }
            DB::run('UPDATE veeam_companies SET cloud_quota_bytes = NULL, cloud_used_bytes = NULL');
            foreach ($sum as $cu => [$q, $used]) {
                DB::run('UPDATE veeam_companies SET cloud_quota_bytes = ?, cloud_used_bytes = ? WHERE uid = ?', [$q ?: null, $used, $cu]);
            }
            $parts[] = count($sum) . ' with cloud storage';
        }

        $parts[] = self::autoMatch();

        // Jobs
        $agentNames = [];
        $agentOrgs = [];
        foreach ($api->agents() ?? [] as $a) {
            $au = (string) ($a['instanceUid'] ?? '');
            if ($au !== '') {
                $agentNames[$au] = (string) V::pick($a, ['name', 'computerName', 'hostName']);
                $agentOrgs[$au] = V::orgOf($a);
            }
        }
        $jobs = [];
        foreach ($api->serverJobs() as $j) {
            $jobs[] = self::mapJob($j, 'server');
        }
        $agentJobs = $api->agentJobs();
        foreach ($agentJobs ?? [] as $j) {
            $au = (string) ($j['backupAgentUid'] ?? '');
            $row = self::mapJob($j, 'agent');
            $row['company_uid'] ??= $agentOrgs[$au] ?? null;
            if (!empty($agentNames[$au]) && stripos($row['name'], $agentNames[$au]) === false) {
                $row['name'] = mb_substr($agentNames[$au] . ' — ' . $row['name'], 0, 255);
            }
            $jobs[] = $row;
        }
        $jobs = array_filter($jobs, fn($r) => $r['uid'] !== '');
        DB::transaction(function () use ($jobs, $now, $agentJobs) {
            foreach ($jobs as $r) {
                DB::upsert('backup_jobs', $r + ['synced_at' => $now], ['uid']);
                if ($r['last_run'] && in_array($r['status'], ['success', 'warning', 'failed'], true)) {
                    DB::run('INSERT INTO backup_job_runs (job_uid, run_at, company_uid, status) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE status = VALUES(status), company_uid = VALUES(company_uid)',
                        [$r['uid'], $r['last_run'], $r['company_uid'], $r['status']]);
                }
            }
            // Jobs VSPC no longer reports (agent jobs only when that list was readable)
            DB::run("DELETE FROM backup_jobs WHERE synced_at < ?" . ($agentJobs === null ? " AND source = 'server'" : ''), [$now]);
            DB::run('DELETE FROM backup_job_runs WHERE run_at < ?', [date('Y-m-d H:i:s', strtotime('-400 days'))]);
        });
        $failed = count(array_filter($jobs, fn($r) => $r['status'] === 'failed'));
        $parts[] = count($jobs) . ' jobs' . ($failed ? " ($failed failed)" : '');

        // Protected machines: one row per machine, newest restore point wins when it's in several jobs
        $wl = [];
        $vms = $api->protectedVms();
        foreach ($vms ?? [] as $v) {
            self::addWorkload($wl, $v, 'vm', $now);
        }
        foreach ($api->protectedComputers() as $c) {
            self::addWorkload($wl, $c, 'computer', $now);
        }
        DB::transaction(function () use ($wl, $now) {
            foreach ($wl as $r) {
                DB::upsert('backup_workloads', $r, ['uid']);
            }
            DB::run('DELETE FROM backup_workloads WHERE synced_at < ?', [$now]);
        });
        $parts[] = count($wl) . ' protected machines';
        $parts[] = self::linkDevices() . ' matched to devices';

        return implode(', ', $parts);
    }

    private static function mapJob(array $j, string $source): array
    {
        $dur = V::int(V::pick($j, ['lastDuration', 'lastRunDurationSec', 'lastSessionDuration']));
        return [
            'uid' => (string) ($j['instanceUid'] ?? ''),
            'company_uid' => V::orgOf($j),
            'source' => $source,
            'name' => mb_substr((string) (V::pick($j, ['name']) ?? 'Backup job'), 0, 255),
            'job_type' => ($t = V::pick($j, ['type', 'subtype', 'jobKind'])) !== null ? mb_substr((string) $t, 0, 60) : null,
            'status' => V::status(V::pick($j, ['status', 'lastStatus', 'lastResult'])),
            'is_enabled' => array_key_exists('isEnabled', $j) ? ($j['isEnabled'] ? 1 : 0) : 1,
            'last_run' => V::ts(V::pick($j, ['lastRun', 'lastRunTime', 'lastStartTime'])),
            'last_end' => V::ts(V::pick($j, ['lastEndTime', 'lastEnd'])),
            'duration_sec' => $dur,
            'failure_message' => ($m = trim((string) V::pick($j, ['failureMessage', 'lastFailureMessage']))) !== '' ? mb_substr($m, 0, 2000) : null,
            'target' => ($d = V::pick($j, ['destination', 'targetRepository', 'repositoryName'])) !== null && is_string($d) ? mb_substr($d, 0, 255) : null,
            'chain_bytes' => V::int(V::pick($j, ['backupChainSize', 'totalBackupSize'])),
        ];
    }

    private static function addWorkload(array &$wl, array $r, string $kind, string $now): void
    {
        $id = (string) V::pick($r, ['instanceUid', 'backupAgentUid', 'uid']);
        $name = trim((string) V::pick($r, ['name', 'hostName', 'computerName', 'guestDnsName']));
        if ($id === '' || $name === '') {
            return;
        }
        $key = "$kind:$id";
        $row = [
            'uid' => mb_substr($key, 0, 100),
            'company_uid' => V::orgOf($r),
            'kind' => $kind,
            'name' => mb_substr($name, 0, 255),
            'hostname' => mb_substr(V::hostKey((string) (V::pick($r, ['guestDnsName', 'hostName']) ?? $name)), 0, 190) ?: null,
            'device_id' => null,
            'last_point' => V::ts(V::pick($r, ['latestRestorePointDate', 'latestRestorePoint', 'lastRestorePointDate'])),
            'restore_points' => V::int(V::pick($r, ['restorePoints', 'restorePointsCount', 'restorePointCount'])),
            'backup_bytes' => V::int(V::pick($r, ['totalRestorePointSize', 'backupSize', 'totalBackupSize'])),
            'source_bytes' => V::int(V::pick($r, ['usedSourceSize', 'provisionedSourceSize', 'sourceSize'])),
            'synced_at' => $now,
        ];
        $old = $wl[$key] ?? null;
        if ($old) {
            // Same machine in several jobs: keep the newest restore point, add up points and size
            $row['restore_points'] = ($old['restore_points'] ?? 0) + ($row['restore_points'] ?? 0) ?: null;
            $row['backup_bytes'] = ($old['backup_bytes'] ?? 0) + ($row['backup_bytes'] ?? 0) ?: null;
            if (($old['last_point'] ?? '') > ($row['last_point'] ?? '')) {
                $row['last_point'] = $old['last_point'];
            }
        }
        $wl[$key] = $row;
    }

    /** Links VSPC companies to clients with the same name (or the same name as the client's NinjaOne org). */
    public static function autoMatch(): string
    {
        $free = DB::all('SELECT v.uid, v.name FROM veeam_companies v LEFT JOIN clients c ON c.veeam_company_uid = v.uid WHERE c.id IS NULL');
        $byName = [];
        foreach ($free as $v) {
            $byName[SyncRunner::normalizeName($v['name'])][] = $v['uid'];
        }
        $matched = 0;
        $clients = DB::all('SELECT c.id, c.name, o.name AS org_name FROM clients c LEFT JOIN ninja_orgs o ON o.id = c.ninja_org_id
            WHERE c.veeam_company_uid IS NULL AND c.veeam_match IS NULL AND c.is_archived = 0 AND c.planning_excluded = 0');
        foreach ($clients as $c) {
            foreach (array_unique(array_filter([SyncRunner::normalizeName($c['name']), SyncRunner::normalizeName((string) $c['org_name'])])) as $key) {
                if (isset($byName[$key]) && count($byName[$key]) === 1) {
                    DB::run("UPDATE clients SET veeam_company_uid = ?, veeam_match = 'auto' WHERE id = ?", [$byName[$key][0], $c['id']]);
                    unset($byName[$key]);
                    $matched++;
                    break;
                }
            }
        }
        $linked = (int) DB::value('SELECT COUNT(*) FROM clients WHERE veeam_company_uid IS NOT NULL');
        return "$linked linked to clients" . ($matched ? " ($matched new)" : '');
    }

    /** Points each protected machine at the client's device with the same host name. */
    public static function linkDevices(): int
    {
        DB::run('UPDATE backup_workloads SET device_id = NULL');
        $n = 0;
        foreach (DB::all('SELECT id, veeam_company_uid FROM clients WHERE veeam_company_uid IS NOT NULL') as $c) {
            $map = [];
            $devs = DB::all('SELECT d.id, d.display_name, d.system_name FROM devices d ' . \Align\Lifecycle\Lifecycle::CLIENT_JOIN . '
                WHERE d.removed_at IS NULL AND COALESCE(cm.id, cn.id) = ?', [$c['id']]);
            foreach ($devs as $d) {
                foreach ([$d['system_name'], $d['display_name']] as $nm) {
                    $k = V::hostKey($nm);
                    if ($k !== '') {
                        $map[$k] ??= (int) $d['id'];
                    }
                }
            }
            foreach (DB::all('SELECT uid, name, hostname FROM backup_workloads WHERE company_uid = ?', [$c['veeam_company_uid']]) as $w) {
                $id = $map[(string) $w['hostname']] ?? $map[V::hostKey($w['name'])] ?? null;
                if ($id) {
                    DB::run('UPDATE backup_workloads SET device_id = ? WHERE uid = ?', [$id, $w['uid']]);
                    $n++;
                }
            }
        }
        return $n;
    }

    private static function in(array $vals): string
    {
        return implode(',', array_fill(0, count($vals), '?'));
    }
}
