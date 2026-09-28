<?php
declare(strict_types=1);

namespace Align\Providers\Backup;

use Align\Integrations\VeeamSpc as V;

/** Veeam Service Provider Console as a backup provider: turns VSPC API rows into neutral records (see BackupProvider). */
final class VeeamBackup implements BackupProvider
{
    public function __construct(private V $api)
    {
    }

    public static function fromSettings(): self
    {
        return new self(V::fromSettings());
    }

    public function key(): string
    {
        return 'veeam';
    }

    public function name(): string
    {
        return 'Veeam';
    }

    public function supports(string $capability): bool
    {
        return isset(self::CAPABILITIES[$capability]);
    }

    public function test(): string
    {
        return $this->api->test();
    }

    public function snapshot(callable $info): array
    {
        $api = $this->api;

        $companies = [];
        foreach ($api->companies() as $c) {
            $uid = (string) ($c['instanceUid'] ?? '');
            if ($uid !== '') {
                $companies[] = ['uid' => $uid, 'name' => mb_substr((string) ($c['name'] ?? $uid), 0, 255), 'status' => $c['status'] ?? null];
            }
        }

        // Cloud Connect storage (only when the provider runs Cloud Connect and the key can see it)
        $cloud = null;
        $usage = $api->cloudUsage();
        if ($usage !== null) {
            $cloud = [];
            foreach ($usage as $u) {
                $cu = (string) ($u['companyUid'] ?? V::orgOf($u) ?? '');
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
            self::addWorkload($wl, $vm, 'vm', $jobCompany[(string) ($vm['jobUid'] ?? '')] ?? null, $wlJobs);
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

    private static function addWorkload(array &$wl, array $r, string $kind, ?string $company, array &$wlJobs): void
    {
        $id = (string) V::pick($r, ['instanceUid', 'backupAgentUid', 'uid']);
        $name = trim((string) V::pick($r, ['name', 'hostName', 'computerName', 'guestDnsName']));
        if ($id === '' || $name === '') {
            return;
        }
        $key = "$kind:$id";
        if (($ju = (string) ($r['jobUid'] ?? '')) !== '' && $ju !== V::ZERO_UID) {
            $wlJobs[$key][$ju] = true;
        }
        $row = [
            'uid' => mb_substr($key, 0, 100),
            'company_uid' => $company ?? V::orgOf($r),
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
            $uid = (string) ($o['instanceUid'] ?? '');
            if ($uid === '') {
                continue;
            }
            $company = V::orgOf(['mappedOrganizationUid' => $o['mappedOrganizationUid'] ?? null]) ?? $map[$uid] ?? null;
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
        $objectsRaw = $api->m365ProtectedObjects();
        $objects = null;
        if ($objectsRaw !== null) {
            $objects = [];
            foreach ($objectsRaw as $o) {
                $id = (string) ($o['id'] ?? '');
                $name = trim((string) ($o['name'] ?? ''));
                if ($id === '' || $name === '') {
                    continue;
                }
                $objects[] = [
                    'uid' => strlen($id) > 180 ? 'sha1:' . sha1($id) : $id,
                    'company_uid' => $companyOf($o),
                    'org_uid' => isset($o['vb365OrganizationUid']) ? mb_substr((string) $o['vb365OrganizationUid'], 0, 64) : null,
                    'name' => mb_substr($name, 0, 255),
                    'object_type' => $types[strtolower((string) ($o['protectedDataType'] ?? ''))] ?? 'other',
                    'restore_points' => V::int($o['restorePointsCount'] ?? null),
                    'last_point' => V::ts($o['latestRestorePointDate'] ?? null),
                    'licensed' => isset($o['consumesLicense']) ? ($o['consumesLicense'] ? 1 : 0) : null,
                ];
            }
        }
        return ['orgs' => $orgs, 'jobs' => $jobs, 'objects' => $objects];
    }
}
