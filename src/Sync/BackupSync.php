<?php
declare(strict_types=1);

namespace Align\Sync;

use Align\DB;
use Align\Providers\Backup\BackupProvider;
use Align\Providers\ClientLinks;
use Align\Providers\Providers;

/**
 * Stores what a backup provider reports (companies, jobs and results, protected machines, cloud storage,
 * Microsoft 365), links its companies to clients, and sorts machines and jobs into clients (including
 * machines hosted on your own backup servers). Works for every backup provider; each provider's rows are
 * pruned only by its own sync. Read-only: nothing is ever written back to the backup product.
 */
final class BackupSync
{
    /** @param callable(string):void $info */
    public static function run(BackupProvider $p, callable $info): string
    {
        $key = $p->key();
        $snap = $p->snapshot($info);
        $now = date('Y-m-d H:i:s');
        $parts = [];

        // Companies
        $uids = [];
        foreach ($snap['companies'] as $c) {
            $uid = (string) ($c['uid'] ?? '');
            if ($uid === '') {
                continue;
            }
            $uids[] = $uid;
            DB::run('INSERT INTO backup_companies (provider, uid, name, status, synced_at) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), synced_at = VALUES(synced_at)',
                [$key, $uid, mb_substr((string) ($c['name'] ?? $uid), 0, 255), $c['status'] ?? null, $now]);
        }
        if ($uids) {
            ClientLinks::prune($key, $uids);
            DB::run('DELETE FROM backup_companies WHERE provider = ? AND uid NOT IN (' . self::in($uids) . ')', [$key, ...$uids]);
        }
        $parts[] = count($uids) . ' companies';

        // Cloud storage (only when the provider reports it)
        if ($snap['cloud'] !== null) {
            DB::run('UPDATE backup_companies SET cloud_quota_bytes = NULL, cloud_used_bytes = NULL WHERE provider = ?', [$key]);
            foreach ($snap['cloud'] as $cu => [$q, $used]) {
                DB::run('UPDATE backup_companies SET cloud_quota_bytes = ?, cloud_used_bytes = ? WHERE provider = ? AND uid = ?', [$q, $used, $key, (string) $cu]);
            }
            $parts[] = count($snap['cloud']) . ' with cloud storage';
        }

        $parts[] = self::autoMatch($key);

        // Jobs and their latest result
        $jobs = array_values(array_filter($snap['jobs'], fn($r) => ($r['uid'] ?? '') !== ''));
        $lists = $snap['job_lists'] ?: ['server'];
        DB::transaction(function () use ($jobs, $now, $lists, $key) {
            foreach ($jobs as $r) {
                DB::upsert('backup_jobs', ['uid' => $r['uid'], 'provider' => $key] + $r + ['synced_at' => $now], ['uid']);
                if ($r['last_run'] && in_array($r['status'], ['success', 'warning', 'failed'], true)) {
                    DB::run('INSERT INTO backup_job_runs (job_uid, run_at, company_uid, status) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE status = VALUES(status), company_uid = VALUES(company_uid)',
                        [$r['uid'], $r['last_run'], $r['company_uid'], $r['status']]);
                }
            }
            // Jobs the provider no longer reports (only for the lists that were readable this time)
            DB::run('DELETE FROM backup_jobs WHERE provider = ? AND synced_at < ? AND source IN (' . self::in($lists) . ')', [$key, $now, ...$lists]);
            DB::run('DELETE FROM backup_job_runs WHERE run_at < ?', [date('Y-m-d H:i:s', strtotime('-400 days'))]);
        });
        $failed = count(array_filter($jobs, fn($r) => $r['status'] === 'failed'));
        $parts[] = count($jobs) . ' jobs' . ($failed ? " ($failed failed)" : '');

        // Microsoft 365
        if ($snap['m365'] !== null) {
            $m = $snap['m365'];
            foreach ($m['orgs'] as $o) {
                DB::upsert('backup_m365_orgs', ['uid' => $o['uid'], 'provider' => $key] + $o + ['synced_at' => $now], ['uid']);
            }
            DB::run('DELETE FROM backup_m365_orgs WHERE provider = ? AND synced_at < ?', [$key, $now]);
            $n = 0;
            if ($m['objects'] !== null) {
                DB::transaction(function () use ($m, $now, $key, &$n) {
                    foreach ($m['objects'] as $o) {
                        DB::upsert('backup_m365_objects', ['uid' => $o['uid'], 'provider' => $key] + $o + ['synced_at' => $now], ['uid']);
                        $n++;
                    }
                    DB::run('DELETE FROM backup_m365_objects WHERE provider = ? AND synced_at < ?', [$key, $now]);
                });
            }
            $m365Jobs = count(array_filter($snap['jobs'], fn($j) => ($j['source'] ?? '') === 'm365')); // as the product lists them
            $parts[] = 'Microsoft 365: ' . count($m['orgs']) . ' organizations, ' . $m365Jobs . ' jobs, ' . $n . ' protected objects';
        }

        // Protected machines and which jobs back them up
        $wl = $snap['workloads'];
        DB::transaction(function () use ($wl, $now, $key) {
            foreach ($wl as $r) {
                $row = $r;
                unset($row['job_uids']);
                DB::upsert('backup_workloads', ['uid' => $row['uid'], 'provider' => $key] + $row + ['device_id' => null, 'synced_at' => $now], ['uid']);
            }
            DB::run('DELETE FROM backup_workloads WHERE provider = ? AND synced_at < ?', [$key, $now]);
            DB::run('DELETE x FROM backup_workload_jobs x LEFT JOIN backup_workloads w ON w.uid = x.workload_uid WHERE w.uid IS NULL OR w.provider = ?', [$key]);
            foreach ($wl as $r) {
                foreach ($r['job_uids'] ?? [] as $ju) {
                    DB::run('INSERT IGNORE INTO backup_workload_jobs (workload_uid, job_uid) VALUES (?, ?)', [mb_substr((string) $r['uid'], 0, 100), mb_substr((string) $ju, 0, 64)]);
                }
            }
        });
        $parts[] = count($wl) . ' protected machines';
        $a = self::assign();
        $parts[] = $a['devices'] . ' matched to devices' . ($a['hosted'] ? ', ' . $a['hosted'] . ' hosted machines sorted into clients' : '') . ($a['unsorted'] ? ', ' . $a['unsorted'] . ' hosted machines not matched to a client' : '');

        return implode(', ', $parts);
    }

    /** Links a backup provider's companies to clients with the same name (or the same name as the client's RMM organization). */
    public static function autoMatch(string $key): string
    {
        $free = DB::all('SELECT b.uid, b.name FROM backup_companies b LEFT JOIN client_links l ON l.provider = b.provider AND l.external_id = b.uid
            WHERE b.provider = ? AND l.client_id IS NULL', [$key]);
        $byName = [];
        foreach ($free as $v) {
            $byName[SyncRunner::normalizeName($v['name'])][] = $v['uid'];
        }
        $matched = 0;
        $clients = DB::all('SELECT c.id, c.name, ' . ClientLinks::rmmOrgNamesSql() . ' AS org_name FROM clients c
            LEFT JOIN client_links l ON l.client_id = c.id AND l.provider = ?
            WHERE l.client_id IS NULL AND c.is_archived = 0 AND c.planning_excluded = 0', [$key]);
        foreach ($clients as $c) {
            foreach (array_unique(array_filter([SyncRunner::normalizeName($c['name']), SyncRunner::normalizeName((string) $c['org_name'])])) as $k) {
                if (isset($byName[$k]) && count($byName[$k]) === 1) {
                    ClientLinks::set((int) $c['id'], $key, $byName[$k][0], 'auto');
                    unset($byName[$k]);
                    $matched++;
                    break;
                }
            }
        }
        $linked = (int) DB::value('SELECT COUNT(*) FROM client_links WHERE provider = ? AND external_id IS NOT NULL', [$key]);
        return "$linked linked to clients" . ($matched ? " ($matched new)" : '');
    }

    /** Backup companies (any provider) whose machines are sorted into clients one by one (hosting servers). */
    public static function hostingCompanies(): array
    {
        $flagged = [];
        foreach (array_keys(Providers::backupConnectors()) as $key) {
            $f = json_decode((string) \Align\Settings::get($key . '_hosting_companies', '[]'), true);
            array_push($flagged, ...(is_array($f) ? array_map('strval', $f) : []));
        }
        $unlinked = array_column(DB::all('SELECT b.uid FROM backup_companies b LEFT JOIN client_links l ON l.provider = b.provider AND l.external_id = b.uid
            WHERE l.client_id IS NULL'), 'uid');
        return array_values(array_unique([...$unlinked, ...$flagged]));
    }

    /**
     * Works out which client each protected machine and job belongs to, and links machines to devices.
     * Machines on a hosting server (a backup company linked to no client, or one flagged as hosting) go by:
     *   1. a machine assigned by hand, 2. its job assigned by hand, 3. a device with the same name at exactly
     *   one client, 4. the client the company is linked to (if any).
     * Everything else goes to the client its backup company is linked to, unless the machine was assigned by hand.
     * Jobs count for the client(s) their machines belong to; a job assigned by hand counts for that client only.
     * @return array{devices:int, hosted:int, unsorted:int}
     */
    public static function assign(): array
    {
        $companyClient = [];
        foreach (DB::all('SELECT l.client_id, l.external_id FROM client_links l JOIN backup_companies b ON b.provider = l.provider AND b.uid = l.external_id') as $c) {
            $companyClient[$c['external_id']] = (int) $c['client_id'];
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
                $k = host_key((string) $nm);
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
            $keys = array_values(array_unique(array_filter([(string) $w['hostname'], host_key((string) $w['name'])])));
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
