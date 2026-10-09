<?php
declare(strict_types=1);

namespace Align\Sync;

use Align\DB;
use Align\Settings;
use Align\Providers\Backup\BackupProvider;
use Align\Providers\ClientLinks;
use Align\Providers\Providers;

/**
 * Stores what a backup provider reports (companies, jobs and results, protected machines, cloud storage,
 * Microsoft 365), links its companies to clients, and sorts machines and jobs into clients (including
 * machines hosted on your own backup servers). Works for every backup provider; each provider's rows are
 * pruned only by its own sync. Read-only: nothing is ever written back to the backup product.
 *
 * SECURITY: the provider's records are untrusted data, normalized by the provider (BackupProvider: fixed keys,
 * values cut to their columns); here they only ever go in as bound values. Which client sees a job, machine or
 * Microsoft 365 object is decided by client_links (a company linked to the client), a manual assignment, or a
 * device name owned by exactly one client, never by a name or id the backup product sends alone.
 * run() is called by SyncRunner under the sync lock; assign() is also called from web requests and the API and
 * serializes itself with ASSIGN_LOCK.
 */
final class BackupSync
{
    /** Named lock for assign(), so two re-sorts never interleave. */
    public const ASSIGN_LOCK = 'msp_align_backup_assign';

    /**
     * Stores one provider's snapshot and re-sorts machines and jobs into clients. Returns a short summary for the
     * sync log. Rows a provider no longer reports are pruned for that provider only (jobs: only the lists it could read).
     * @param callable(string):void $info
     */
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
                $multi = 0;
                $objects = self::stableUids($m['objects'], $key);
                DB::transaction(function () use ($objects, $now, $key, &$n, &$multi) {
                    foreach ($objects as $o) {
                        $others = array_values(array_diff($o['uids'] ?? [], [$o['uid']]));
                        unset($o['days'], $o['uids']);
                        DB::upsert('backup_m365_objects', ['uid' => $o['uid'], 'provider' => $key] + $o + ['synced_at' => $now], ['uid']);
                        if ($others) {
                            // the other copies' rows and days now belong to this one (pruning by synced_at alone
                            // misses a row from a sync in the same second)
                            $in = self::in($others);
                            DB::run("UPDATE IGNORE backup_m365_days SET object_uid = ? WHERE object_uid IN ($in)", [$o['uid'], ...$others]);
                            DB::run("DELETE FROM backup_m365_days WHERE object_uid IN ($in)", $others);
                            DB::run("DELETE FROM backup_m365_objects WHERE provider = ? AND uid IN ($in)", [$key, ...$others]);
                        }
                        $n++;
                        $multi += (int) (($o['repositories'] ?? 1) > 1);
                    }
                    DB::run('DELETE FROM backup_m365_objects WHERE provider = ? AND synced_at < ?', [$key, $now]);
                    self::countDays($objects, $key, $now);
                });
                self::pruneDays($now);
            }
            $m365Jobs = count(array_filter($snap['jobs'], fn($j) => ($j['source'] ?? '') === 'm365')); // as the product lists them
            $parts[] = 'Microsoft 365: ' . count($m['orgs']) . ' organizations, ' . $m365Jobs . ' jobs, ' . $n . ' protected objects'
                . (!empty($multi) ? " ($multi in more than one repository)" : '');
        }

        // Protected machines and which jobs back them up
        // Only the kinds whose list was readable are pruned (2.2.1): an unreadable list keeps what was there
        $wl = $snap['workloads'];
        $kinds = array_values(array_intersect($snap['workload_lists'] ?? ['vm', 'computer'], ['vm', 'computer']));
        DB::transaction(function () use ($wl, $now, $key, $kinds) {
            foreach ($wl as $r) {
                $row = $r;
                unset($row['job_uids']);
                DB::upsert('backup_workloads', ['uid' => $row['uid'], 'provider' => $key] + $row + ['device_id' => null, 'synced_at' => $now], ['uid']);
            }
            if ($kinds) {
                $kIn = implode(',', array_fill(0, count($kinds), '?'));
                DB::run("DELETE FROM backup_workloads WHERE provider = ? AND synced_at < ? AND kind IN ($kIn)", [$key, $now, ...$kinds]);
                // 2.7.5: links made by hand for machines that are gone
                DB::run('DELETE l FROM backup_device_links l LEFT JOIN backup_workloads w ON w.uid = l.workload_uid WHERE w.uid IS NULL');
                DB::run("DELETE x FROM backup_workload_jobs x LEFT JOIN backup_workloads w ON w.uid = x.workload_uid WHERE w.uid IS NULL OR (w.provider = ? AND w.kind IN ($kIn))", [$key, ...$kinds]);
            } else {
                DB::run('DELETE x FROM backup_workload_jobs x LEFT JOIN backup_workloads w ON w.uid = x.workload_uid WHERE w.uid IS NULL');
            }
            foreach ($wl as $r) {
                foreach ($r['job_uids'] ?? [] as $ju) {
                    DB::run('INSERT IGNORE INTO backup_workload_jobs (workload_uid, job_uid) VALUES (?, ?)', [mb_substr((string) $r['uid'], 0, 100), mb_substr((string) $ju, 0, 64)]);
                }
            }
        });
        $missing = array_diff(['vm', 'computer'], $kinds);
        $parts[] = count($wl) . ' protected machines' . ($missing ? ' (' . implode(' and ', array_map(fn($k) => $k === 'vm' ? 'virtual machine' : 'computer', $missing))
            . ' list unavailable; kept the ones from the last sync)' : '');
        $a = self::assign();
        $parts[] = $a['devices'] . ' matched to devices' . ($a['hosted'] ? ', ' . $a['hosted'] . ' hosted machines sorted into clients' : '') . ($a['unsorted'] ? ', ' . $a['unsorted'] . ' hosted machines not matched to a client' : '');

        return implode(', ', $parts);
    }

    /** Links a backup provider's companies to clients with the same name (or the same name as the client's RMM organization). */
    public static function autoMatch(string $key): string
    {
        $matched = ClientLinks::autoMatch($key, true);
        $linked = (int) DB::value('SELECT COUNT(*) FROM client_links WHERE provider = ? AND external_id IS NOT NULL', [$key]);
        return "$linked linked to clients" . ($matched ? " ($matched new)" : '');
    }

    /**
     * Backup companies (any provider) whose machines are sorted into clients one by one (hosting servers): those
     * linked to no client, and those an admin or tech flagged as hosting on the Hosted backups page.
     */
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
     * Callers hold ASSIGN_LOCK (see assign()).
     * @return array{devices:int, hosted:int, unsorted:int}
     */
    private static function sortIntoClients(): array
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
        $devClient = [];
        $owners = [];
        foreach (DB::all('SELECT d.id, d.display_name, d.system_name, COALESCE(cm.id, cn.id) AS client_id FROM devices d ' . \Align\Lifecycle\Lifecycle::CLIENT_JOIN . '
                JOIN clients c ON c.id = COALESCE(cm.id, cn.id) AND c.is_archived = 0
                WHERE d.removed_at IS NULL') as $d) {
            $devClient[(int) $d['id']] = (int) $d['client_id']; // 2.7.5: for links made by hand (any device, named or not)
            foreach ([$d['system_name'], $d['display_name']] as $nm) {
                $k = host_key((string) $nm);
                if ($k !== '') {
                    $devByClient[(int) $d['client_id']][$k] ??= (int) $d['id'];
                    $owners[$k][(int) $d['client_id']] = true;
                }
            }
        }
        $uniqueOwner = fn(string $k) => $k !== '' && isset($owners[$k]) && count($owners[$k]) === 1 ? (int) array_key_first($owners[$k]) : null;

        // 2.7.5 Machines linked to a device by hand (kept while both belong to the same client)
        $linked = array_column(DB::all('SELECT workload_uid, device_id FROM backup_device_links'), 'device_id', 'workload_uid');

        $devices = 0;
        $hosted = 0;
        $unsorted = 0;
        $clientsOfJob = [];
        $rows = DB::all('SELECT uid, company_uid, name, hostname FROM backup_workloads');
        $updates = [];
        foreach ($rows as $w) {
            $cu = (string) $w['company_uid'];
            $inPool = $cu === '' || isset($pool[$cu]);
            $keys = self::nameKeys((string) $w['hostname'], (string) $w['name']);              // the device, within the client
            $strict = self::nameKeys((string) $w['hostname'], (string) $w['name'], false);   // the client
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
                    foreach ($strict as $k) {
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
                $hand = isset($linked[$w['uid']]) ? (int) $linked[$w['uid']] : null;
                if ($hand !== null && ($devClient[$hand] ?? null) === $client) {
                    $dev = $hand;
                }
                foreach ($dev === null ? $keys : [] as $k) {
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

    /**
     * Works out which client each protected machine and job belongs to (see sortIntoClients()), one caller at a time:
     * a manual assignment saved while a sync is sorting would otherwise be overwritten by a result worked out from
     * what was there before it (2.2.1). Everything is read after the lock is taken. Waits up to 30 seconds, then
     * returns without sorting ('skipped'; the task holding the lock or the next sync sorts). Callers (sync, Hosted
     * backups, the client's Backups page, the API) check roles and audit the change.
     * @return array{devices:int, hosted:int, unsorted:int, skipped?:bool}
     */
    public static function assign(): array
    {
        if ((int) DB::value('SELECT GET_LOCK(?, 30)', [self::ASSIGN_LOCK]) !== 1) {
            // The caller's change is already saved (and still gets audited): the other task, or the next sync,
            // sorts with it. Throwing here would show an error and skip the caller's audit entry (2.2.1).
            error_log('[msp-align] backups not re-sorted now: another task holds ' . self::ASSIGN_LOCK);
            return ['devices' => 0, 'hosted' => 0, 'unsorted' => 0, 'skipped' => true];
        }
        try {
            return self::sortIntoClients();
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [self::ASSIGN_LOCK]);
        }
    }

    /** Re-sorts machines and jobs into clients (after mapping changes). Returns how many machines matched a device. */
    public static function linkDevices(): int
    {
        return self::assign()['devices'];
    }

    /**
     * An object combined from several repositories keeps the uid Align already stores for it, so "backup not
     * required" marks and its days with a backup stay attached when a repository appears or goes away. A uid that
     * carries a "backup not required" mark is passed over when another one is available: the mark was likely put on
     * a legacy copy that showed as overdue, and must not now hide the current mailbox. (An object that really
     * doesn't need a backup, such as a former employee's mailbox in both repositories, had every copy marked,
     * because every copy was overdue: it keeps one of those uids and stays marked.)
     */
    private static function stableUids(array $objects, string $key): array
    {
        $stored = array_flip(array_column(DB::all('SELECT uid FROM backup_m365_objects WHERE provider = ?', [$key]), 'uid'));
        $exempt = array_flip(array_column(DB::all('SELECT DISTINCT item_uid FROM backup_exemptions WHERE item_uid IS NOT NULL'), 'item_uid'));
        foreach ($objects as &$o) {
            $uids = $o['uids'] ?? [$o['uid']];
            if (count($uids) > 1) {
                $rank = fn(string $u) => (isset($stored[$u]) ? 0 : 1) + (isset($exempt[$u]) ? 2 : 0);
                usort($uids, fn($a, $b) => [$rank($a), $a] <=> [$rank($b), $b]);
                $o['uid'] = $uids[0];
            }
        }
        unset($o);
        return $objects;
    }

    /**
     * Days with a backup per Microsoft 365 object: each sync records the day of every repository's newest restore
     * point, so a day counts once however many restore points it has, and a day backed up in both a legacy and a
     * current repository counts once. Counting starts at the object's first sync after 2.0.1 (days_from: that day,
     * or the day before when its newest restore point is from yesterday): the console reports only each repository's
     * newest restore point, not the dates of older ones. A day passes uncounted only if Align didn't sync after that
     * day's last backup.
     */
    private static function countDays(array $objects, string $key, string $now): void
    {
        $today = substr($now, 0, 10);
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        $from = [];
        foreach (DB::all('SELECT o.uid, o.days_from, (SELECT MIN(d.day) FROM backup_m365_days d WHERE d.object_uid = o.uid) AS first_day
            FROM backup_m365_objects o WHERE o.provider = ?', [$key]) as $r) {
            $from[$r['uid']] = $r['days_from'] !== null ? (string) $r['days_from'] : null;
            if ($from[$r['uid']] === null && $r['first_day'] !== null) {
                $from[$r['uid']] = (string) $r['first_day'];   // an object that came back: carry on counting
            }
        }
        $pairs = [];
        $start = [];
        foreach ($objects as $o) {
            if (!array_key_exists($o['uid'], $from)) {
                continue;
            }
            $f = $from[$o['uid']];
            if ($f === null) {
                $f = ($o['days'] ?? []) && max($o['days']) === $yesterday ? $yesterday : $today;
                $start[$f][] = $o['uid'];
            }
            foreach ($o['days'] ?? [] as $day) {
                if ($day >= $f && $day <= $today) {
                    $pairs[] = [$o['uid'], $day];
                }
            }
        }
        foreach ($start as $day => $uids) {
            foreach (array_chunk($uids, 500) as $part) {
                DB::run('UPDATE backup_m365_objects SET days_from = ? WHERE uid IN (' . self::in($part) . ')', [$day, ...$part]);
            }
        }
        // Objects that came back after being removed: carry on counting from their first recorded day
        DB::run('UPDATE backup_m365_objects o SET o.days_from = (SELECT MIN(d.day) FROM backup_m365_days d WHERE d.object_uid = o.uid)
            WHERE o.provider = ? AND o.days_from IS NULL', [$key]);
        foreach (array_chunk($pairs, 300) as $part) {
            DB::run('INSERT IGNORE INTO backup_m365_days (object_uid, day) VALUES ' . implode(',', array_fill(0, count($part), '(?, ?)')),
                array_merge(...$part));
        }
        DB::run('UPDATE backup_m365_objects o SET o.restore_days = (SELECT COUNT(*) FROM backup_m365_days d WHERE d.object_uid = o.uid AND d.day >= o.days_from)
            WHERE o.provider = ?', [$key]);
    }

    /** Days of objects no longer reported are kept for a month, in case they come back; tidied once a day. */
    private static function pruneDays(string $now): void
    {
        $today = substr($now, 0, 10);
        if (Settings::get('m365_days_pruned') === $today) {
            return;
        }
        DB::run('DELETE d FROM backup_m365_days d JOIN (SELECT object_uid FROM backup_m365_days GROUP BY object_uid HAVING MAX(day) < ?) old ON old.object_uid = d.object_uid
            LEFT JOIN backup_m365_objects o ON o.uid = d.object_uid WHERE o.uid IS NULL', [date('Y-m-d', strtotime($today . ' -30 days'))]);
        Settings::set('m365_days_pruned', $today);
    }

    /** Placeholders for a bound IN (...) list; callers pass a non-empty list. */
    private static function in(array $vals): string
    {
        return implode(',', array_fill(0, count($vals), '?'));
    }

    /**
     * 2.7.5 The names a backed-up machine can match a device by, best first: the guest's host name, the machine's name
     * as is (host_key: lower case, no domain), the name without bracketed parts ("FS01 (DC/File)", "[Prod] FS01"),
     * and with $loose the part before a " - " ("FS01 - file server"). The loose form only picks a device within a
     * client already known (never the client itself), so an odd name can't move a machine to another client.
     */
    public static function nameKeys(string $hostname, string $name, bool $loose = true): array
    {
        $keys = [host_key($hostname), host_key($name)];
        $bare = trim(preg_replace('/\s*[\(\[\{][^\)\]\}]*[\)\]\}]\s*/u', ' ', $name) ?? '');
        $keys[] = host_key($bare);
        if ($loose) {
            $keys[] = host_key(trim(preg_split('/\s+[-–—|:]\s+/u', $bare)[0] ?? ''));
        }
        return array_values(array_unique(array_filter($keys, fn($k) => $k !== '')));
    }
}
