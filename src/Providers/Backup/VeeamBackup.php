<?php
declare(strict_types=1);

namespace Align\Providers\Backup;

use Align\Integrations\VeeamSpc as V;

/**
 * Veeam Service Provider Console as a backup provider: turns VSPC API rows into neutral records (see BackupProvider).
 *
 * SECURITY: VSPC responses are untrusted. The company uid on a job, machine or Microsoft 365 record decides which
 * client it counts for, so uids are kept exact: one longer than its column (64 characters; 100 for a machine) is
 * replaced by "sha1:" and its hash instead of being cut, since two cut uids could become one record and mix two
 * clients' backups. Every record is built with fixed keys (BackupSync uses them as column names). Read-only: nothing
 * is ever sent to VSPC except reads.
 */
final class VeeamBackup implements BackupProvider
{
    /** $api is built from the admin's saved VSPC URL and key (https and TLS checks live in VeeamSpc / HttpClient). */
    public function __construct(private V $api)
    {
    }

    /** Throws when Veeam isn't set up. */
    public static function fromSettings(): self
    {
        return new self(V::fromSettings());
    }

    /** A uid as text that fits a $max-character column: null when missing or not text, a hash when too long. */
    private static function uid(mixed $v, int $max = 64): ?string
    {
        if (!is_string($v) && !is_int($v)) {
            return null;
        }
        $s = (string) $v;
        return $s === '' ? null : (strlen($s) > $max ? 'sha1:' . sha1($s) : $s);
    }

    /** V::orgOf with the result made to fit (see uid()). */
    private static function org(array $r): ?string
    {
        return self::uid(V::orgOf($r));
    }

    /** The connector key (fixed; also stored with every record from this provider). */
    public function key(): string
    {
        return 'veeam';
    }

    /** Display name. */
    public function name(): string
    {
        return 'Veeam';
    }

    /** VSPC offers every backup capability (what a key can actually read shows up as null lists). */
    public function supports(string $capability): bool
    {
        return isset(self::CAPABILITIES[$capability]);
    }

    /** Checks the URL and key (admin, Integrations page). */
    public function test(): string
    {
        return $this->api->test();
    }

    /**
     * Reads everything (see BackupProvider). Called by the sync only; the records are stored by BackupSync, which
     * prunes only this provider's rows.
     */
    public function snapshot(callable $info): array
    {
        $api = $this->api;

        $companies = [];
        foreach ($api->companies() as $c) {
            $uid = self::uid($c['instanceUid'] ?? null) ?? '';
            if ($uid !== '') {
                $companies[] = ['uid' => $uid, 'name' => mb_substr((string) ($c['name'] ?? $uid), 0, 255),
                    'status' => is_string($c['status'] ?? null) ? mb_substr($c['status'], 0, 40) : null]; // backup_companies.status is VARCHAR(40)
            }
        }

        // Cloud Connect storage (only when the provider runs Cloud Connect and the key can see it)
        $cloud = null;
        $usage = $api->cloudUsage();
        if ($usage !== null) {
            $cloud = [];
            foreach ($usage as $u) {
                $cu = self::uid($u['companyUid'] ?? null) ?? self::org($u) ?? '';
                if ($cu === '') {
                    continue;
                }
                $cloud[$cu][0] = ($cloud[$cu][0] ?? 0) + (int) V::int($u['storageQuota'] ?? null);
                $cloud[$cu][1] = ($cloud[$cu][1] ?? 0) + (int) V::int($u['usedStorageQuota'] ?? $u['usedStorage'] ?? null);
            }
            $cloud = array_map(fn($x) => [($x[0] ?? 0) ?: null, $x[1] ?? 0], $cloud);
        }

        // Jobs
        $agentNames = [];
        $agentOrgs = [];
        foreach ($api->agents() ?? [] as $a) {
            $au = (string) ($a['instanceUid'] ?? '');
            if ($au !== '') {
                $agentNames[$au] = (string) V::pick($a, ['name', 'computerName', 'hostName']);
                $agentOrgs[$au] = self::org($a);
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
        $lists = ['server'];
        $agentJobs = $api->agentJobs();
        if ($agentJobs !== null) {
            $lists[] = 'agent';
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
        $m365 = $this->m365();
        if ($m365 !== null) {
            $lists[] = 'm365';
            array_push($jobs, ...$m365['jobs']);
        }
        $jobs = array_values(array_filter($jobs, fn($r) => $r['uid'] !== ''));

        // Protected machines: one row per machine, newest restore point wins when it's in several jobs
        $wl = [];
        $wlJobs = [];
        foreach ($api->protectedVms() ?? [] as $vm) {
            // A VM on the provider's own server belongs to the company its job is mapped to (in VSPC)
            self::addWorkload($wl, $vm, 'vm', $jobCompany[self::uid($vm['jobUid'] ?? null) ?? ''] ?? null, $wlJobs);
        }
        foreach ($api->protectedComputers() as $c) {
            self::addWorkload($wl, $c, 'computer', null, $wlJobs);
        }
        // Agent jobs name their computer
        foreach ($jobs as $j) {
            if (!empty($j['agent_uid']) && isset($wl['computer:' . $j['agent_uid']])) {
                $wlJobs['computer:' . $j['agent_uid']][$j['uid']] = true;
            }
        }
        $workloads = [];
        foreach ($wl as $key => $row) {
            $workloads[] = $row + ['job_uids' => array_map('strval', array_keys($wlJobs[$key] ?? []))];
        }

        return [
            'companies' => $companies,
            'cloud' => $cloud,
            'jobs' => $jobs,
            'job_lists' => $lists,
            'workloads' => $workloads,
            'm365' => $m365 === null ? null : ['orgs' => $m365['orgs'], 'objects' => $m365['objects']],
        ];
    }

    /** A server or agent job as a neutral job row (fixed keys). */
    private static function mapJob(array $j, string $source): array
    {
        $dur = V::int(V::pick($j, ['lastDuration', 'lastRunDurationSec', 'lastSessionDuration']));
        return [
            'uid' => self::uid($j['instanceUid'] ?? null) ?? '',
            'company_uid' => self::org($j),
            'source' => $source,
            'agent_uid' => $source === 'agent' ? self::uid($j['backupAgentUid'] ?? null) : null,
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

    /**
     * Adds a protected machine to $wl (one row per machine) and notes its job in $wlJobs. $company: the company of
     * the machine's job when VSPC files the machine under the provider's own server.
     */
    private static function addWorkload(array &$wl, array $r, string $kind, ?string $company, array &$wlJobs): void
    {
        $id = V::pick($r, ['instanceUid', 'backupAgentUid', 'uid']);
        $id = is_string($id) || is_int($id) ? (string) $id : '';
        $name = trim((string) V::pick($r, ['name', 'hostName', 'computerName', 'guestDnsName']));
        if ($id === '' || $name === '') {
            return;
        }
        $key = self::uid("$kind:$id", 100);
        if (($ju = self::uid($r['jobUid'] ?? null) ?? '') !== '' && $ju !== V::ZERO_UID) {
            $wlJobs[$key][$ju] = true;
        }
        $row = [
            'uid' => $key,
            'company_uid' => $company ?? self::org($r),
            'kind' => $kind,
            'name' => mb_substr($name, 0, 255),
            'hostname' => mb_substr(host_key((string) (V::pick($r, ['guestDnsName', 'hostName']) ?? $name)), 0, 190) ?: null,
            'last_point' => V::ts(V::pick($r, ['latestRestorePointDate', 'latestRestorePoint', 'lastRestorePointDate'])),
            'restore_points' => V::int(V::pick($r, ['restorePoints', 'restorePointsCount', 'restorePointCount'])),
            'backup_bytes' => V::int(V::pick($r, ['totalRestorePointSize', 'backupSize', 'totalBackupSize'])),
            'source_bytes' => V::int(V::pick($r, ['usedSourceSize', 'provisionedSourceSize', 'sourceSize'])),
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

    /** Veeam Backup for Microsoft 365: organizations, jobs and protected objects; null when VSPC manages none. */
    private function m365(): ?array
    {
        $api = $this->api;
        $orgsRaw = $api->m365Organizations();
        if ($orgsRaw === null) {
            return null;
        }
        $map = $api->m365CompanyMappings();
        $orgCompany = [];
        $orgs = [];
        foreach ($orgsRaw as $o) {
            $uid = self::uid($o['instanceUid'] ?? null) ?? '';
            if ($uid === '') {
                continue;
            }
            $company = self::org(['mappedOrganizationUid' => $o['mappedOrganizationUid'] ?? null]) ?? self::uid($map[(string) ($o['instanceUid'] ?? '')] ?? null);
            $orgCompany[$uid] = $company;
            $orgs[] = [
                'uid' => $uid,
                'company_uid' => $company,
                'name' => mb_substr((string) ($o['name'] ?? $uid), 0, 255),
                'services' => is_array($o['protectedServices'] ?? null) ? mb_substr(implode(',', $o['protectedServices']), 0, 255) : null,
                'is_backed_up' => !empty($o['isBackedUp']) ? 1 : 0,
                'first_backup' => V::ts($o['firstBackupTime'] ?? null),
                'last_backup' => V::ts($o['lastBackupTime'] ?? null),
            ];
        }
        // Jobs name their company (vspcOrganizationUid); objects go by their tenant's company mapping first
        $tenant = fn(array $r) => self::uid($r['vb365OrganizationUid'] ?? null) ?? '';
        $companyOf = fn(array $r) => self::org(['organizationUid' => $r['vspcOrganizationUid'] ?? null])
            ?? $orgCompany[$tenant($r)] ?? self::uid($map[is_scalar($r['vb365OrganizationUid'] ?? null) ? (string) $r['vb365OrganizationUid'] : ''] ?? null)
            ?? self::org(['organizationUid' => $r['organizationUid'] ?? null]);

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
                'uid' => self::uid($j['instanceUid'] ?? null) ?? '',
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
        $objectsRaw = $api->m365ProtectedObjects();
        $objects = null;
        if ($objectsRaw !== null) {
            $rows = [];
            foreach ($objectsRaw as $o) {
                $id = (string) ($o['id'] ?? '');
                $name = trim((string) ($o['name'] ?? ''));
                if ($id === '' || $name === '') {
                    continue;
                }
                $repo = V::pick($o, ['repositoryUid', 'backupRepositoryUid', 'repositoryId', 'repositoryName']);
                $rows[] = [
                    'uid' => strlen($id) > 180 ? 'sha1:' . sha1($id) : $id,
                    'company_uid' => $companyOf($o),
                    'org_uid' => $tenant($o) ?: null,
                    'name' => mb_substr($name, 0, 255),
                    'object_type' => $types[strtolower((string) ($o['protectedDataType'] ?? ''))] ?? 'other',
                    'restore_points' => V::int($o['restorePointsCount'] ?? null),
                    'last_point' => V::ts($o['latestRestorePointDate'] ?? null),
                    'licensed' => isset($o['consumesLicense']) ? ($o['consumesLicense'] ? 1 : 0) : null,
                    'repo' => $repo !== null && $repo !== '' ? (string) $repo : null,
                ];
            }
            $objects = self::mergeRepositories($rows);
        }
        return ['orgs' => $orgs, 'jobs' => $jobs, 'objects' => $objects];
    }

    /**
     * One record per Microsoft 365 user, group, team or site. The console lists an object once for each repository
     * that holds restore points for it: a tenant moved from a legacy repository to a new one has two rows for the
     * same mailbox, the old one stopped months ago and the new one current. The rows are combined, and the object is
     * current when its newest restore point, from any repository, is recent:
     *   - rows with the same id (in the same tenant and of the same type) are one object;
     *   - rows with the same name, type and tenant are one object too when both name a repository and the
     *     repositories differ (without repository details, two rows with one name stay two objects: two staff
     *     called John Smith, two sites called Documents).
     * (A job that stops writing to one of two live repositories still shows as failed or overdue under Jobs.)
     * Restore points add up; `repositories` says how many were combined; `uids` lists every row's id (the sync keeps
     * the one it already stores); `days` holds the day of each row's newest restore point.
     *
     * @param list<array<string, mixed>> $rows one per console row, with 'repo' (null when not reported)
     * @return list<array<string, mixed>>
     */
    public static function mergeRepositories(array $rows): array
    {
        $groups = [];          // group index => rows
        $byId = [];            // tenant|type|uid => group index
        $byName = [];          // tenant|type|name => group indexes
        foreach ($rows as $r) {
            $scope = ($r['company_uid'] ?? '') . '|' . ($r['org_uid'] ?? '') . '|' . $r['object_type'] . '|';
            $idKey = $scope . $r['uid'];
            if (isset($byId[$idKey])) {
                $groups[$byId[$idKey]][] = $r;
                continue;
            }
            $nameKey = $scope . mb_strtolower($r['name']);
            $into = null;
            if ($r['repo'] !== null) {
                foreach ($byName[$nameKey] ?? [] as $g) {
                    $repos = array_column($groups[$g], 'repo');
                    if (!in_array(null, $repos, true) && !in_array($r['repo'], $repos, true)) {
                        $into = $g;   // the same object in another repository
                        break;
                    }
                }
            }
            if ($into === null) {
                $into = count($groups);
                $groups[$into] = [];
                $byName[$nameKey][] = $into;
            }
            $groups[$into][] = $r;
            $byId[$idKey] = $into;
        }
        $out = [];
        foreach ($groups as $g) {
            usort($g, fn($a, $b) => strcmp($a['uid'], $b['uid']));
            $newest = $g[0];
            foreach ($g as $r) {
                if ((string) $r['last_point'] > (string) $newest['last_point']) {
                    $newest = $r;
                }
            }
            $counts = array_filter(array_column($g, 'restore_points'), fn($v) => $v !== null);
            $licensed = array_filter(array_column($g, 'licensed'), fn($v) => $v !== null);
            $repos = count(array_unique(array_map(fn($i) => $g[$i]['repo'] ?? "row:$i", array_keys($g))));
            $out[] = [
                'uid' => $g[0]['uid'],
                'uids' => array_values(array_unique(array_column($g, 'uid'))),
                'company_uid' => $g[0]['company_uid'],
                'org_uid' => $g[0]['org_uid'],
                'name' => $newest['name'],
                'object_type' => $g[0]['object_type'],
                'restore_points' => $counts ? array_sum($counts) : null,
                'last_point' => $newest['last_point'],
                'licensed' => $licensed ? max($licensed) : null,
                'repositories' => min(255, $repos),
                'days' => array_values(array_unique(array_filter(array_map(fn($r) => $r['last_point'] ? substr($r['last_point'], 0, 10) : null, $g)))),
            ];
        }
        return $out;
    }
}
