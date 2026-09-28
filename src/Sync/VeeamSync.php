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
        $jobCompany = [];
        foreach ($api->serverJobs() as $j) {
            $row = self::mapJob($j, 'server');
            $jobs[] = $row;
            if ($row['company_uid']) {
                $jobCompany[$row['uid']] = $row['company_uid'];
            }
        }
        $fetched = ['server'];
        $agentJobs = $api->agentJobs();
        if ($agentJobs !== null) {
            $fetched[] = 'agent';
        }
        foreach ($agentJobs ?? [] as $j) {
            $au = (string) ($j['backupAgentUid'] ?? '');
            $row = self::mapJob($j, 'agent');
            $row['company_uid'] ??= $agentOrgs[$au] ?? null;
            if (!empty($agentNames[$au]) && stripos($row['name'], $agentNames[$au]) === false) {
                $row['name'] = mb_substr($agentNames[$au] . ' — ' . $row['name'], 0, 255);
            }
            $jobs[] = $row;
        }

        // Microsoft 365 (only when VSPC manages a Veeam Backup for Microsoft 365 server)
        $m365 = self::m365($api, $now);
        if ($m365['jobs'] !== null) {
            $fetched[] = 'm365';
            array_push($jobs, ...$m365['jobs']);
        }

        $jobs = array_filter($jobs, fn($r) => $r['uid'] !== '');
        DB::transaction(function () use ($jobs, $now, $fetched) {
            foreach ($jobs as $r) {
                DB::upsert('backup_jobs', $r + ['synced_at' => $now], ['uid']);
                if ($r['last_run'] && in_array($r['status'], ['success', 'warning', 'failed'], true)) {
                    DB::run('INSERT INTO backup_job_runs (job_uid, run_at, company_uid, status) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE status = VALUES(status), company_uid = VALUES(company_uid)',
                        [$r['uid'], $r['last_run'], $r['company_uid'], $r['status']]);
                }
            }
            // Jobs VSPC no longer reports (only for the lists that were readable this time)
            DB::run('DELETE FROM backup_jobs WHERE synced_at < ? AND source IN (' . self::in($fetched) . ')', [$now, ...$fetched]);
            DB::run('DELETE FROM backup_job_runs WHERE run_at < ?', [date('Y-m-d H:i:s', strtotime('-400 days'))]);
        });
        $failed = count(array_filter($jobs, fn($r) => $r['status'] === 'failed'));
        $parts[] = count($jobs) . ' jobs' . ($failed ? " ($failed failed)" : '');
        if ($m365['summary']) {
            $parts[] = $m365['summary'];
        }

        // Protected machines: one row per machine, newest restore point wins when it's in several jobs
        $wl = [];
        $wlJobs = [];
        $vms = $api->protectedVms();
        foreach ($vms ?? [] as $v) {
            // A VM on the provider's own server belongs to the company its job is mapped to (in VSPC)
            self::addWorkload($wl, $v, 'vm', $now, $jobCompany[(string) ($v['jobUid'] ?? '')] ?? null, $wlJobs);
        }
        foreach ($api->protectedComputers() as $c) {
            self::addWorkload($wl, $c, 'computer', $now, null, $wlJobs);
        }
        // Agent jobs name their computer
        foreach ($jobs as $j) {
            if (!empty($j['agent_uid']) && isset($wl['computer:' . $j['agent_uid']])) {
                $wlJobs['computer:' . $j['agent_uid']][$j['uid']] = true;
            }
        }
        DB::transaction(function () use ($wl, $wlJobs, $now) {
            foreach ($wl as $r) {
                DB::upsert('backup_workloads', $r, ['uid']);
            }
            DB::run('DELETE FROM backup_workloads WHERE synced_at < ?', [$now]);
            DB::run('DELETE FROM backup_workload_jobs');
            foreach ($wlJobs as $w => $js) {
                foreach (array_keys($js) as $ju) {
                    DB::run('INSERT IGNORE INTO backup_workload_jobs (workload_uid, job_uid) VALUES (?, ?)', [mb_substr($w, 0, 100), mb_substr((string) $ju, 0, 64)]);
                }
            }
        });
        $parts[] = count($wl) . ' protected machines';
        $a = self::assign();
        $parts[] = $a['devices'] . ' matched to devices' . ($a['hosted'] ? ', ' . $a['hosted'] . ' hosted machines sorted into clients' : '') . ($a['unsorted'] ? ', ' . $a['unsorted'] . ' hosted machines not matched to a client' : '');

        return implode(', ', $parts);
    }

    private static function mapJob(array $j, string $source): array
    {
        $dur = V::int(V::pick($j, ['lastDuration', 'lastRunDurationSec', 'lastSessionDuration']));
        return [
            'uid' => (string) ($j['instanceUid'] ?? ''),
            'company_uid' => V::orgOf($j),
            'source' => $source,
            'agent_uid' => $source === 'agent' && !empty($j['backupAgentUid']) ? mb_substr((string) $j['backupAgentUid'], 0, 64) : null,
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

    private static function addWorkload(array &$wl, array $r, string $kind, string $now, ?string $company = null, ?array &$wlJobs = null): void
    {
        $id = (string) V::pick($r, ['instanceUid', 'backupAgentUid', 'uid']);
        $name = trim((string) V::pick($r, ['name', 'hostName', 'computerName', 'guestDnsName']));
        if ($id === '' || $name === '') {
            return;
        }
        $key = "$kind:$id";
        if ($wlJobs !== null && ($ju = (string) ($r['jobUid'] ?? '')) !== '' && $ju !== V::ZERO_UID) {
            $wlJobs[$key][$ju] = true;
        }
        $row = [
            'uid' => mb_substr($key, 0, 100),
            'company_uid' => $company ?? V::orgOf($r),
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
            $row['company_uid'] = $company ?? $old['company_uid'] ?? $row['company_uid'];
            // Same machine in several jobs: keep the newest restore point, add up points and size
            $row['restore_points'] = ($old['restore_points'] ?? 0) + ($row['restore_points'] ?? 0) ?: null;
            $row['backup_bytes'] = ($old['backup_bytes'] ?? 0) + ($row['backup_bytes'] ?? 0) ?: null;
            if (($old['last_point'] ?? '') > ($row['last_point'] ?? '')) {
                $row['last_point'] = $old['last_point'];
            }
        }
        $wl[$key] = $row;
    }


    /**
     * Veeam Backup for Microsoft 365: organizations, jobs and protected objects.
     * Returns ['jobs' => rows for backup_jobs or null when unavailable, 'summary' => text].
     */
    private static function m365(V $api, string $now): array
    {
        $orgs = $api->m365Organizations();
        if ($orgs === null) {
            return ['jobs' => null, 'summary' => ''];
        }
        $map = $api->m365CompanyMappings();
        $orgCompany = [];
        foreach ($orgs as $o) {
            $uid = (string) ($o['instanceUid'] ?? '');
            if ($uid === '') {
                continue;
            }
            $company = V::orgOf(['mappedOrganizationUid' => $o['mappedOrganizationUid'] ?? null]) ?? $map[$uid] ?? null;
            $orgCompany[$uid] = $company;
            DB::upsert('backup_m365_orgs', [
                'uid' => $uid,
                'company_uid' => $company,
                'name' => mb_substr((string) ($o['name'] ?? $uid), 0, 255),
                'services' => is_array($o['protectedServices'] ?? null) ? mb_substr(implode(',', $o['protectedServices']), 0, 255) : null,
                'is_backed_up' => !empty($o['isBackedUp']) ? 1 : 0,
                'first_backup' => V::ts($o['firstBackupTime'] ?? null),
                'last_backup' => V::ts($o['lastBackupTime'] ?? null),
                'synced_at' => $now,
            ], ['uid']);
        }
        DB::run('DELETE FROM backup_m365_orgs WHERE synced_at < ?', [$now]);
        // Jobs name their company (vspcOrganizationUid); objects go by their tenant's company mapping first
        $companyOf = fn(array $r) => V::orgOf(['organizationUid' => $r['vspcOrganizationUid'] ?? null])
            ?? $orgCompany[(string) ($r['vb365OrganizationUid'] ?? '')] ?? $map[(string) ($r['vb365OrganizationUid'] ?? '')]
            ?? V::orgOf(['organizationUid' => $r['organizationUid'] ?? null]);

        $jobs = [];
        foreach ($api->m365Jobs() ?? [] as $j) {
            $errors = [];
            foreach ((array) ($j['lastErrorLogRecords'] ?? []) as $l) {
                if (is_array($l) && !empty($l['message']) && in_array($l['logType'] ?? '', ['Error', 'Warning'], true)) {
                    $errors[] = trim((string) $l['message']);
                }
            }
            $msg = trim((string) ($j['lastStatusDetails'] ?? '')) ?: implode(' ', array_slice(array_unique($errors), 0, 3));
            $jobs[] = [
                'uid' => (string) ($j['instanceUid'] ?? ''),
                'company_uid' => $companyOf($j),
                'source' => 'm365',
                'agent_uid' => null,
                'name' => mb_substr((string) ($j['name'] ?? 'Microsoft 365 backup'), 0, 255),
                'job_type' => mb_substr('Vb365' . ($j['jobType'] ?? 'BackupJob'), 0, 60),
                'status' => V::status($j['lastStatus'] ?? null),
                'is_enabled' => array_key_exists('isEnabled', $j) ? ($j['isEnabled'] ? 1 : 0) : 1,
                'last_run' => V::ts($j['lastRun'] ?? null),
                'last_end' => null,
                'duration_sec' => null,
                'failure_message' => $msg !== '' ? mb_substr($msg, 0, 2000) : null,
                'target' => isset($j['repositoryName']) ? mb_substr((string) $j['repositoryName'], 0, 255) : null,
                'chain_bytes' => null,
            ];
        }

        $types = ['user' => 'user', 'group' => 'group', 'teams' => 'team', 'team' => 'team', 'site' => 'site'];
        $objects = $api->m365ProtectedObjects();
        $n = 0;
        if ($objects !== null) {
            DB::transaction(function () use ($objects, $types, $companyOf, $now, &$n) {
                foreach ($objects as $o) {
                    $id = (string) ($o['id'] ?? '');
                    $name = trim((string) ($o['name'] ?? ''));
                    if ($id === '' || $name === '') {
                        continue;
                    }
                    DB::upsert('backup_m365_objects', [
                        'uid' => strlen($id) > 180 ? 'sha1:' . sha1($id) : $id,
                        'company_uid' => $companyOf($o),
                        'org_uid' => isset($o['vb365OrganizationUid']) ? mb_substr((string) $o['vb365OrganizationUid'], 0, 64) : null,
                        'name' => mb_substr($name, 0, 255),
                        'object_type' => $types[strtolower((string) ($o['protectedDataType'] ?? ''))] ?? 'other',
                        'restore_points' => V::int($o['restorePointsCount'] ?? null),
                        'last_point' => V::ts($o['latestRestorePointDate'] ?? null),
                        'licensed' => isset($o['consumesLicense']) ? ($o['consumesLicense'] ? 1 : 0) : null,
                        'synced_at' => $now,
                    ], ['uid']);
                    $n++;
                }
                DB::run('DELETE FROM backup_m365_objects WHERE synced_at < ?', [$now]);
            });
        }
        return ['jobs' => $jobs, 'summary' => 'Microsoft 365: ' . count($orgs) . ' organizations, ' . count($jobs) . ' jobs, ' . $n . ' protected objects'];
    }

    /** Links VSPC companies to clients with the same name (or the same name as the client's RMM organization). */
    public static function autoMatch(): string
    {
        $free = DB::all('SELECT v.uid, v.name FROM veeam_companies v LEFT JOIN clients c ON c.veeam_company_uid = v.uid WHERE c.id IS NULL');
        $byName = [];
        foreach ($free as $v) {
            $byName[SyncRunner::normalizeName($v['name'])][] = $v['uid'];
        }
        $matched = 0;
        $clients = DB::all('SELECT c.id, c.name, ' . \Align\Providers\ClientLinks::rmmOrgNamesSql() . ' AS org_name FROM clients c
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

    /** Veeam companies whose machines are sorted into clients one by one (hosting servers). */
    public static function hostingCompanies(): array
    {
        $flagged = json_decode((string) \Align\Settings::get('veeam_hosting_companies', '[]'), true);
        $flagged = is_array($flagged) ? array_map('strval', $flagged) : [];
        $unlinked = array_column(DB::all('SELECT v.uid FROM veeam_companies v LEFT JOIN clients c ON c.veeam_company_uid = v.uid WHERE c.id IS NULL'), 'uid');
        return array_values(array_unique([...$unlinked, ...$flagged]));
    }

    /**
     * Works out which client each protected machine and job belongs to, and links machines to devices.
     * Machines on a hosting server (a Veeam company linked to no client, or one flagged as hosting) go by:
     *   1. a machine assigned by hand, 2. its job assigned by hand, 3. a device with the same name at exactly
     *   one client, 4. the client the company is linked to (if any).
     * Everything else goes to the client its Veeam company is linked to, unless the machine was assigned by hand.
     * Jobs count for the client(s) their machines belong to; a job assigned by hand counts for that client only.
     * @return array{devices:int, hosted:int, unsorted:int}
     */
    public static function assign(): array
    {
        $companyClient = [];
        foreach (DB::all('SELECT id, veeam_company_uid FROM clients WHERE veeam_company_uid IS NOT NULL') as $c) {
            $companyClient[$c['veeam_company_uid']] = (int) $c['id'];
        }
        $pool = array_flip(self::hostingCompanies());
        $manual = ['job' => [], 'workload' => []];
        foreach (DB::all('SELECT item_type, item_uid, client_id FROM backup_assignments') as $a) {
            $manual[$a['item_type']][$a['item_uid']] = $a['client_id'] !== null ? (int) $a['client_id'] : 0; // 0 = ours
        }
        $jobsOf = [];
        foreach (DB::all('SELECT workload_uid, job_uid FROM backup_workload_jobs') as $r) {
            $jobsOf[$r['workload_uid']][] = $r['job_uid'];
        }

        // Device names per client, and which names belong to exactly one client
        $devByClient = [];
        $owners = [];
        foreach (DB::all('SELECT d.id, d.display_name, d.system_name, COALESCE(cm.id, cn.id) AS client_id FROM devices d ' . \Align\Lifecycle\Lifecycle::CLIENT_JOIN . '
                JOIN clients c ON c.id = COALESCE(cm.id, cn.id) AND c.is_archived = 0
                WHERE d.removed_at IS NULL') as $d) {
            foreach ([$d['system_name'], $d['display_name']] as $nm) {
                $k = V::hostKey((string) $nm);
                if ($k !== '') {
                    $devByClient[(int) $d['client_id']][$k] ??= (int) $d['id'];
                    $owners[$k][(int) $d['client_id']] = true;
                }
            }
        }
        $uniqueOwner = fn(string $k) => $k !== '' && isset($owners[$k]) && count($owners[$k]) === 1 ? (int) array_key_first($owners[$k]) : null;

        $devices = 0;
        $hosted = 0;
        $unsorted = 0;
        $clientsOfJob = [];
        $rows = DB::all('SELECT uid, company_uid, name, hostname FROM backup_workloads');
        $updates = [];
        foreach ($rows as $w) {
            $cu = (string) $w['company_uid'];
            $inPool = $cu === '' || isset($pool[$cu]);
            $keys = array_values(array_unique(array_filter([(string) $w['hostname'], V::hostKey((string) $w['name'])])));
            $client = null;
            $how = null;
            if (array_key_exists($w['uid'], $manual['workload'])) {
                [$client, $how] = [$manual['workload'][$w['uid']] ?: null, 'machine'];
            } elseif (!$inPool) {
                [$client, $how] = [$companyClient[$cu] ?? null, 'company'];
            } else {
                foreach ($jobsOf[$w['uid']] ?? [] as $ju) {
                    if (array_key_exists($ju, $manual['job'])) {
                        [$client, $how] = [$manual['job'][$ju] ?: null, 'job'];
                        break;
                    }
                }
                if ($how === null) {
                    foreach ($keys as $k) {
                        if ($o = $uniqueOwner($k)) {
                            [$client, $how] = [$o, 'device'];
                            break;
                        }
                    }
                }
                if ($how === null && isset($companyClient[$cu])) {
                    [$client, $how] = [$companyClient[$cu], 'company'];
                }
                if ($client !== null && ($companyClient[$cu] ?? null) !== $client) {
                    $hosted++;
                } elseif ($client === null && $how === null) {
                    $unsorted++;
                }
            }
            $dev = null;
            if ($client !== null) {
                foreach ($keys as $k) {
                    if (isset($devByClient[$client][$k])) {
                        $dev = $devByClient[$client][$k];
                        break;
                    }
                }
                foreach ($jobsOf[$w['uid']] ?? [] as $ju) {
                    $clientsOfJob[$ju][$client] = true;
                }
            }
            $devices += $dev ? 1 : 0;
            $updates[] = [$client, $client !== null ? $how : ($how === 'machine' || $how === 'job' ? $how : null), $dev, $w['uid']];
        }

        DB::transaction(function () use ($updates, $clientsOfJob, $companyClient, $pool, $manual) {
            foreach ($updates as $u) {
                DB::run('UPDATE backup_workloads SET client_id = ?, client_how = ?, device_id = ? WHERE uid = ?', $u);
            }
            DB::run('DELETE FROM backup_job_clients');
            foreach (DB::all('SELECT uid, company_uid FROM backup_jobs') as $j) {
                $cu = (string) $j['company_uid'];
                $set = [];
                if (array_key_exists($j['uid'], $manual['job'])) {
                    if ($manual['job'][$j['uid']]) {
                        $set[$manual['job'][$j['uid']]] = 'job';
                    }
                } elseif ($cu !== '' && !isset($pool[$cu])) {
                    if (isset($companyClient[$cu])) {
                        $set[$companyClient[$cu]] = 'company';
                    }
                } else {
                    foreach (array_keys($clientsOfJob[$j['uid']] ?? []) as $cid) {
                        $set[$cid] = 'machines';
                    }
                    if (!$set && isset($companyClient[$cu])) {
                        $set[$companyClient[$cu]] = 'company';
                    }
                }
                foreach ($set as $cid => $how) {
                    DB::run('INSERT INTO backup_job_clients (job_uid, client_id, how) VALUES (?, ?, ?)', [$j['uid'], $cid, $how]);
                }
            }
        });
        return ['devices' => $devices, 'hosted' => $hosted, 'unsorted' => $unsorted];
    }

    /** Re-sorts machines and jobs into clients (after mapping changes). Returns how many machines matched a device. */
    public static function linkDevices(): int
    {
        return self::assign()['devices'];
    }

    private static function in(array $vals): string
    {
        return implode(',', array_fill(0, count($vals), '?'));
    }
}
