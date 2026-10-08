<?php
declare(strict_types=1);

namespace Align\Huntress;

use Align\DB;
use Align\Providers\ClientLinks;

/**
 * 2.7.0 The hourly Huntress sync: organizations (matched to clients by name like an RMM's, fixed on Client mapping),
 * agents, incident reports (open ones, and the rest updated in the last KEEP_DAYS), escalations, summary reports
 * (the last REPORT_MONTHS), external ports and, from ITDR, identity counts per organization. Each list is read for
 * the whole account (incidents and escalations once per status), never per organization (Huntress allows 60
 * requests a minute), then stored by organization.
 *
 * Organizations and agents must read; the rest are read one by one and a failure there is noted in the log line
 * (the step shows as done), so one product the account doesn't have (ITDR, external recon) doesn't stop the others.
 *
 * Security assumptions: everything Huntress returns is remote data: ids must be digits, text is cleaned of control
 * characters and cut to its column, dates parsed, counts clamped. Incident and escalation bodies aren't kept, and
 * ITDR identities only as counts; what Align shows is kept (subjects, which can name a person or a computer, host
 * names, severity, status, dates). An empty organization or agent list while Align has some changes nothing (a key
 * that suddenly sees nothing mustn't unlink every client or mark every device unprotected).
 */
final class Sync
{
    /** Provider key in client_links and huntress_orgs. */
    public const PROVIDER = 'huntress';
    /** Closed incidents and resolved escalations updated within this many days are kept (a year and a bit, for QBRs). */
    public const KEEP_DAYS = 400;
    /** Summary reports for periods ending within this many months are kept. */
    public const REPORT_MONTHS = 13;
    /** Incident statuses that mean "still open" (sent to you, or being fixed automatically). */
    public const OPEN = ['sent', 'auto_remediating'];

    /** Runs the sync. Returns a line for the sync log; throws when organizations or agents can't be read. */
    public static function run(): string
    {
        // Rows not seen in this run are removed by their synced_at: this run's time must be later than the last one's,
        // even when two syncs fall in the same second
        $last = (string) DB::value('SELECT GREATEST(COALESCE((SELECT MAX(synced_at) FROM huntress_orgs), 0), COALESCE((SELECT MAX(synced_at) FROM huntress_agents), 0))');
        $now = date('Y-m-d H:i:s', max(time(), $last !== '' && $last !== '0' ? (int) strtotime($last) + 1 : 0));
        $orgs = self::orgs($now);
        $matched = ClientLinks::autoMatch(self::PROVIDER, true);
        $agents = self::agents($now);
        $parts = [count($orgs) . ' organization' . (count($orgs) === 1 ? '' : 's'), "$agents agents"];
        if ($matched) {
            $parts[] = "$matched matched to clients";
        }
        // The rest: each on its own, so one the account doesn't have doesn't stop the others
        foreach (['incidents' => fn() => self::incidents($now), 'escalations' => fn() => self::escalations($now), 'reports' => fn() => self::reports($now),
                  'identities' => fn() => self::identities($now), 'external ports' => fn() => self::ports($now)] as $what => $fn) {
            try {
                $parts[] = $fn() . " $what";
            } catch (\Throwable $e) {
                $parts[] = "$what not read (" . mb_strimwidth($e->getMessage(), 0, 120, '…') . ')';
            }
        }
        return implode(', ', $parts);
    }

    /** Remote text on one line, without control characters, cut to $n. */
    private static function text(mixed $v, int $n): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $t = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v) ?? ''), 0, $n);
        return $t === '' ? null : $t;
    }

    /** An ISO-8601 time from Huntress as Y-m-d H:i:s in the server's zone, or null. */
    private static function time(mixed $v): ?string
    {
        $t = is_string($v) && $v !== '' ? strtotime($v) : false;
        return $t === false ? null : date('Y-m-d H:i:s', $t);
    }

    /** A Huntress id: digits only (as a string, for BIGINT and client_links.external_id), or null. */
    private static function id(mixed $v): ?string
    {
        return (is_int($v) && $v > 0) || (is_string($v) && preg_match('/^\d{1,19}$/', $v)) ? (string) $v : null;
    }

    /** A count clamped to 0..10^7, or null when Huntress didn't send a number. */
    private static function count(mixed $v): ?int
    {
        return is_numeric($v) ? (int) max(0, min(10000000, (float) $v)) : null;
    }

    /** Organizations: stored and pruned (links to ones gone are removed). Returns their ids. */
    private static function orgs(string $now): array
    {
        $list = Api::all('/organizations', 'organizations');
        $ids = [];
        foreach ($list as $o) {
            $id = self::id($o['id'] ?? null);
            $name = self::text($o['name'] ?? null, 255);
            if ($id === null || $name === null) {
                continue;
            }
            $ids[] = $id;
            DB::run('INSERT INTO huntress_orgs (provider, org_id, name, org_key, agents_count, sat_learner_count, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), org_key = VALUES(org_key), agents_count = VALUES(agents_count), sat_learner_count = VALUES(sat_learner_count), synced_at = VALUES(synced_at)',
                [self::PROVIDER, $id, $name, self::text($o['key'] ?? null, 190), self::count($o['agents_count'] ?? 0) ?? 0, self::count($o['sat_learner_count'] ?? null), $now]);
        }
        if (!$ids) {
            if (DB::value('SELECT 1 FROM huntress_orgs LIMIT 1')) {
                throw new \RuntimeException('Huntress returned no organizations (Align has some). Nothing was changed.');
            }
            return [];
        }
        DB::run('DELETE FROM huntress_orgs WHERE provider = ? AND synced_at < ?', [self::PROVIDER, $now]);
        ClientLinks::prune(self::PROVIDER, $ids);
        return $ids;
    }

    /** Agents: the whole list replaces what's stored. Returns how many. */
    private static function agents(string $now): int
    {
        $list = Api::all('/agents', 'agents', [], 200);
        if (!$list && DB::value('SELECT 1 FROM huntress_agents LIMIT 1')) {
            throw new \RuntimeException('Huntress returned no agents (Align has some). Nothing was changed.');
        }
        $n = 0;
        foreach ($list as $a) {
            $id = self::id($a['id'] ?? null);
            $org = self::id($a['organization_id'] ?? null);
            if ($id === null || $org === null) {
                continue;
            }
            $tamper = $a['tamper_protection_actual'] ?? null;
            DB::run('REPLACE INTO huntress_agents (agent_id, org_id, hostname, serial, platform, os, last_callback_at, edr_version, defender_status, defender_substatus,
                defender_policy_status, firewall_status, tamper_protection, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $org, self::text($a['hostname'] ?? null, 255), self::text($a['serial_number'] ?? null, 190), self::text($a['platform'] ?? null, 20),
                    self::text($a['os'] ?? null, 255), self::time($a['last_callback_at'] ?? null), self::text($a['edr_version'] ?? null, 60),
                    self::text($a['defender_status'] ?? null, 60), self::text($a['defender_substatus'] ?? null, 120), self::text($a['defender_policy_status'] ?? null, 60),
                    self::text($a['firewall_status'] ?? null, 40), is_bool($tamper) ? (int) $tamper : null, $now]);
            $n++;
        }
        DB::run('DELETE FROM huntress_agents WHERE synced_at < ?', [$now]);
        return $n;
    }

    /** Incident reports: every open one, plus the rest updated within KEEP_DAYS (newest first). Drafts aren't kept. */
    private static function incidents(string $now): int
    {
        $cut = time() - self::KEEP_DAYS * 86400;
        $older = fn(array $items) => $items && min(array_map(fn($i) => is_string($i['updated_at'] ?? null) ? (int) strtotime($i['updated_at']) : PHP_INT_MAX, $items)) < $cut;
        $list = [];
        foreach (self::OPEN as $st) {
            array_push($list, ...Api::all('/incident_reports', 'incident_reports', ['status' => $st], 20));
        }
        array_push($list, ...Api::all('/incident_reports', 'incident_reports', ['sort_field' => 'updated_at', 'sort_direction' => 'desc'], 40, $older));
        $seen = [];
        foreach ($list as $i) {
            $id = self::id($i['id'] ?? null);
            $org = self::id($i['organization_id'] ?? null);
            $status = self::text($i['status'] ?? null, 30);
            $sev = in_array($i['severity'] ?? '', ['low', 'high', 'critical'], true) ? $i['severity'] : null;
            if ($id === null || $org === null || $status === null || $sev === null || $status === 'draft') {
                continue;
            }
            $types = array_slice(array_values(array_filter((array) ($i['indicator_types'] ?? []), fn($t) => is_string($t) && preg_match('/^[a-z_]{1,40}$/', $t))), 0, 12);
            DB::run('REPLACE INTO huntress_incidents (incident_id, org_id, agent_id, severity, status, platform, subject, indicator_types, sent_at, closed_at, status_updated_at, synced_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $org, self::id($i['agent_id'] ?? null), $sev, $status, self::text($i['platform'] ?? null, 30), self::text($i['subject'] ?? null, 500),
                    implode(',', $types) ?: null, self::time($i['sent_at'] ?? null), self::time($i['closed_at'] ?? null), self::time($i['status_updated_at'] ?? null), $now]);
            $seen[$id] = true; // an open one is in two of the lists: counted once
        }
        DB::run('DELETE FROM huntress_incidents WHERE synced_at < ?', [$now]);
        return count($seen);
    }

    /** Escalations: open and overdue ones, and those resolved within KEEP_DAYS; one row per organization they concern. */
    private static function escalations(string $now): int
    {
        $cut = time() - self::KEEP_DAYS * 86400;
        $list = [...Api::all('/escalations', 'escalations', ['status' => 'open'], 20), ...Api::all('/escalations', 'escalations', ['status' => 'overdue'], 20),
            ...Api::all('/escalations', 'escalations', ['status' => 'resolved', 'sort_field' => 'updated_at', 'sort_direction' => 'desc'], 20,
                fn(array $items) => $items && min(array_map(fn($i) => is_string($i['updated_at'] ?? null) ? (int) strtotime($i['updated_at']) : PHP_INT_MAX, $items)) < $cut)];
        $n = 0;
        foreach ($list as $e) {
            $id = self::id($e['id'] ?? null);
            $status = in_array($e['status'] ?? '', ['open', 'overdue', 'resolved'], true) ? $e['status'] : null;
            if ($id === null || $status === null) {
                continue;
            }
            foreach ((array) ($e['organizations'] ?? []) as $o) {
                $org = self::id(is_array($o) ? ($o['id'] ?? null) : $o); // objects in the examples, ids in the schema
                if ($org === null) {
                    continue;
                }
                DB::run('REPLACE INTO huntress_escalations (escalation_id, org_id, severity, status, type, subject, created_at, resolved_at, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $org, in_array($e['severity'] ?? '', ['low', 'high', 'critical'], true) ? $e['severity'] : null, $status, self::text($e['type'] ?? null, 120),
                        self::text($e['subject'] ?? null, 500), self::time($e['created_at'] ?? null), self::time($e['resolved_at'] ?? null), $now]);
                $n++;
            }
        }
        DB::run('DELETE FROM huntress_escalations WHERE synced_at < ?', [$now]);
        return $n;
    }

    /** Summary reports for periods ending within REPORT_MONTHS, with their PDF link (https only) and headline counts. */
    private static function reports(string $now): int
    {
        $n = 0;
        foreach (Api::all('/reports', 'reports', ['period_min' => date('Y-m-d', strtotime('-' . self::REPORT_MONTHS . ' months'))], 20) as $r) {
            $id = self::id($r['id'] ?? null);
            $org = self::id($r['organization_id'] ?? null);
            $type = in_array($r['type'] ?? '', ['monthly_summary', 'quarterly_summary', 'yearly_summary'], true) ? $r['type'] : null;
            if ($id === null || $org === null || $type === null) {
                continue;
            }
            // "start...end"
            $period = is_string($r['period'] ?? null) ? explode('...', $r['period']) : [];
            $day = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : null;
            $url = is_string($r['url'] ?? null) && preg_match('#^https://[^\s"<>]+$#', $r['url']) && strlen($r['url']) <= 1000 ? $r['url'] : null;
            DB::run('REPLACE INTO huntress_reports (report_id, org_id, type, period_start, period_end, url, agents_count, incidents_reported, incidents_resolved, signals_investigated, created_at, synced_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $org, $type, $day($period[0] ?? null), $day($period[1] ?? null), $url, self::count($r['agents_count'] ?? null), self::count($r['incidents_reported'] ?? null),
                    self::count($r['incidents_resolved'] ?? null), self::count($r['signals_investigated'] ?? null), self::time($r['created_at'] ?? null), $now]);
            $n++;
        }
        DB::run('DELETE FROM huntress_reports WHERE synced_at < ?', [$now]);
        return $n;
    }

    /**
     * ITDR identities, as counts per organization: enabled members (not external/guest), those without MFA, and those
     * at high risk. No names are kept. Organizations without any identities get NULL (ITDR not in use there).
     */
    private static function identities(string $now): int
    {
        $by = [];
        foreach (Api::all('/identities', 'identities', [], 100) as $i) {
            $org = self::id($i['organization']['id'] ?? ($i['organization_id'] ?? null));
            if ($org === null || ($i['enabled'] ?? true) === false || !empty($i['external'])) {
                continue;
            }
            $by[$org] ??= [0, 0, 0];
            $by[$org][0]++;
            $by[$org][1] += ($i['mfa_enabled'] ?? null) === false ? 1 : 0;
            $by[$org][2] += strtolower((string) ($i['risk_level'] ?? '')) === 'high' ? 1 : 0;
        }
        DB::run('UPDATE huntress_orgs SET identities_total = NULL, identities_no_mfa = NULL, identities_high_risk = NULL, identities_at = ? WHERE provider = ?', [$now, self::PROVIDER]);
        foreach ($by as $org => [$t, $noMfa, $risk]) {
            DB::run('UPDATE huntress_orgs SET identities_total = ?, identities_no_mfa = ?, identities_high_risk = ? WHERE provider = ? AND org_id = ?', [$t, $noMfa, $risk, self::PROVIDER, (string) $org]);
        }
        return array_sum(array_column($by, 0));
    }

    /** External ports (recon), one row per organization they belong to; risky services flagged. */
    private static function ports(string $now): int
    {
        $n = 0;
        foreach (Api::all('/external_ports', 'external_ports', [], 40) as $p) {
            $id = self::id($p['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $port = is_numeric($p['port'] ?? null) ? (int) max(0, min(65535, (int) $p['port'])) : null;
            $ip = is_string($p['ip_address'] ?? null) && filter_var($p['ip_address'], FILTER_VALIDATE_IP) ? $p['ip_address'] : null;
            foreach ((array) ($p['organization_ids'] ?? []) as $o) {
                $org = self::id($o);
                if ($org === null) {
                    continue;
                }
                DB::run('REPLACE INTO huntress_ports (port_id, org_id, ip_address, port, protocol, service, risky, last_scan_at, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $org, $ip, $port, self::text($p['protocol'] ?? null, 10), self::text($p['service'] ?? null, 190), !empty($p['risky_service']) ? 1 : 0,
                        self::time($p['last_external_scan_at'] ?? ($p['last_scan_at'] ?? null)), $now]);
                $n++;
            }
        }
        DB::run('DELETE FROM huntress_ports WHERE synced_at < ?', [$now]);
        DB::run('UPDATE huntress_orgs SET ports_at = ? WHERE provider = ?', [$now, self::PROVIDER]);
        return $n;
    }
}
