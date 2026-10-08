<?php
declare(strict_types=1);

namespace Align\Huntress;

use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Providers\ClientLinks;
use Align\Settings;

/**
 * 2.7.0 What Huntress says about one client (through its linked Huntress organization): agent coverage against the
 * client's RMM devices, Managed Antivirus (Defender), incidents, escalations, summary reports, external ports and
 * ITDR identity counts, and the automatic checks built from them (CHECKS), which count in the health score's
 * Security area and suggest answers for linked compliance controls and alignment standards (Health\SecurityChecks).
 *
 * Coverage: the client's workstations and servers that the RMM still sees (not excluded, retired or stale, not a
 * hypervisor that can't run an agent) should each have a Huntress agent that called in within OFFLINE_DAYS (a
 * setting). A device matches an agent by serial number, else by host name (without the domain).
 *
 * Security assumptions: reads stored data only (Sync wrote it, cleaned); callers check the viewer may see the client.
 * Host names, subjects and services are remote text: views escape them.
 */
final class Clients
{
    /** The checks: key => label (automatic checks for compliance and alignment). */
    public const CHECKS = [
        'huntress_agents' => 'Huntress agent on every workstation and server',
        'huntress_incidents' => 'No open critical or high Huntress incidents',
        'huntress_av' => 'Managed Antivirus (Defender) healthy on every Windows agent',
        'huntress_identities' => 'No high-risk identities (Huntress ITDR)',
        'huntress_ports' => 'No risky services open to the internet (Huntress external recon)',
    ];

    /** Huntress data older than this (the sync failing) makes every check unknown rather than judged on old data. */
    public const STALE_HOURS = 26;
    /** 2.7.1 Defender statuses that mean protected (Huntress's API example says "Healthy"; real agents report "Protected"). */
    private const GOOD_AV = ['healthy', 'protected'];
    /** Device classes that should run a Huntress agent. */
    private const NEEDS_AGENT = ['desktop', 'laptop', 'server'];
    /** Operating systems a Huntress agent can't run on (hypervisors). */
    private const NO_AGENT_OS = '/\b(esxi?|vmware|vsphere|proxmox|xenserver|citrix hypervisor)\b/i';
    /** Serial numbers that aren't real ones (placeholders some makers ship). */
    private const JUNK_SERIALS = ['TOBEFILLEDBYOEM', 'DEFAULTSTRING', 'SYSTEMSERIALNUMBER', 'NONE', 'NA', 'NULL', 'UNKNOWN', 'INVALID', 'CHASSISSERIALNUMBER', '0123456789'];

    /** Days without a call-in after which an agent counts as not checking in (setting huntress_offline_days). */
    public static function offlineDays(): int
    {
        return max(1, min(90, Settings::int('huntress_offline_days', 7)));
    }

    /** The client's linked Huntress organization row, or null (not linked, or Huntress not set up). */
    public static function org(int $clientId): ?array
    {
        if (!Api::configured()) {
            return null;
        }
        $id = ClientLinks::externalId($clientId, Sync::PROVIDER);
        return $id === null ? null : DB::one('SELECT * FROM huntress_orgs WHERE provider = ? AND org_id = ?', [Sync::PROVIDER, $id]);
    }

    /** A serial number for matching (upper case, letters and digits), or null when it's missing or a placeholder. */
    public static function serialKey(?string $s): ?string
    {
        $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $s) ?? '');
        if (strlen($k) < 4 || in_array($k, self::JUNK_SERIALS, true) || preg_match('/^(.)\1+$/', $k)) {
            return null;
        }
        return $k;
    }

    /** A host name for matching: lower case, without the domain. */
    public static function hostKey(?string $h): ?string
    {
        $k = strtolower(trim(explode('.', (string) $h)[0]));
        return $k === '' ? null : $k;
    }

    /**
     * Agent coverage for the client: ['needed' => devices that should have an agent, 'missing' => those without one,
     * 'offline' => those whose agent hasn't called in within offlineDays(), 'agents' => the organization's agents,
     * 'extra' => agents not matched to any of those devices]. Each device row is Lifecycle's; each missing/offline
     * entry is ['name', 'id', 'class', 'last' (agent's last call-in)]. $devices: Lifecycle::devices($clientId) when
     * the caller already has them.
     */
    public static function coverage(int $clientId, array $org, ?array $devices = null): array
    {
        $agents = DB::all('SELECT * FROM huntress_agents WHERE org_id = ? ORDER BY hostname', [$org['org_id']]);
        $bySerial = $byHost = [];
        // A reinstall can leave an old agent beside the new one: the one that called in last wins
        $newer = fn(?array $old, array $a) => $old === null || (string) $a['last_callback_at'] > (string) $old['last_callback_at'] ? $a : $old;
        foreach ($agents as $a) {
            if ($k = self::serialKey($a['serial'])) {
                $bySerial[$k] = $newer($bySerial[$k] ?? null, $a);
            }
            if ($k = self::hostKey($a['hostname'])) {
                $byHost[$k] = $newer($byHost[$k] ?? null, $a);
            }
        }
        $devices ??= (new Lifecycle())->devices($clientId);
        $cut = time() - self::offlineDays() * 86400;
        $needed = $missing = $offline = $used = [];
        foreach ($devices as $d) {
            if (($d['status'] ?? '') === 'excluded' || !empty($d['retired_at']) || ($d['source'] ?? '') !== 'rmm' || !empty($d['stale'])
                || !in_array($d['device_class'] ?? '', self::NEEDS_AGENT, true) || preg_match(self::NO_AGENT_OS, (string) ($d['os_name'] ?? ''))
                || (stripos((string) ($d['type'] ?? ''), 'hypervisor') !== false && stripos((string) ($d['os_name'] ?? ''), 'windows') === false)) { // e.g. Proxmox on Debian
                continue;
            }
            $needed[] = $d;
            // Serial first (host names get reused), then the RMM's system name or display name
            $a = (($k = self::serialKey($d['serial'] ?? null)) ? ($bySerial[$k] ?? null) : null)
                ?? (($k = self::hostKey($d['system_name'] ?? null)) ? ($byHost[$k] ?? null) : null)
                ?? (($k = self::hostKey($d['display_name'] ?? null)) ? ($byHost[$k] ?? null) : null);
            $row = ['name' => $d['name'] ?? ($d['display_name'] ?: $d['system_name']), 'id' => (int) $d['id'], 'class' => $d['device_class'], 'last' => $a['last_callback_at'] ?? null];
            if (!$a) {
                $missing[] = $row;
            } else {
                $used[(int) $a['agent_id']] = true;
                if (!$a['last_callback_at'] || strtotime($a['last_callback_at']) < $cut) {
                    $offline[] = $row;
                }
            }
        }
        return ['needed' => $needed, 'missing' => $missing, 'offline' => $offline, 'agents' => $agents,
            'extra' => array_values(array_filter($agents, fn($a) => !isset($used[(int) $a['agent_id']])))];
    }

    /**
     * Managed Antivirus on the organization's Windows agents that called in recently and that Huntress manages Defender
     * on (a policy status is reported; a client running another antivirus, Defender passive, has none): ['of',
     * 'bad' => [[hostname, status text]]]. Good: a status in GOOD_AV (2.7.1: Huntress reports "Protected" as well as
     * the "Healthy" its documentation shows), a sub-status that doesn't say out of date, and policy status Compliant.
     */
    public static function antivirus(array $agents): array
    {
        $cut = time() - self::offlineDays() * 86400;
        $of = 0;
        $bad = [];
        foreach ($agents as $a) {
            if ($a['platform'] !== 'windows' || $a['defender_status'] === null || $a['defender_policy_status'] === null || !$a['last_callback_at'] || strtotime($a['last_callback_at']) < $cut) {
                continue;
            }
            $of++;
            $ok = in_array(strtolower(trim($a['defender_status'])), self::GOOD_AV, true) && strtolower(trim($a['defender_policy_status'])) === 'compliant'
                && !preg_match('/out of date|outdated|not up to date|expired/i', (string) $a['defender_substatus']);
            if (!$ok) {
                $bad[] = [$a['hostname'], trim($a['defender_status'] . ($a['defender_substatus'] ? ' (' . $a['defender_substatus'] . ')' : '')
                    . ($a['defender_policy_status'] && strtolower($a['defender_policy_status']) !== 'compliant' ? ', policy ' . $a['defender_policy_status'] : ''))];
            }
        }
        return ['of' => $of, 'bad' => $bad];
    }

    /**
     * Everything the client pages show, or null when the client has no Huntress organization: ['org', 'coverage',
     * 'av', 'open' (open incidents, worst first), 'closed' (severity => count closed in the last 12 months),
     * 'escalations' (open and overdue), 'reports' (newest first), 'ports' (risky first), 'checks' (see checks())].
     */
    public static function forClient(int $clientId, ?array $devices = null): ?array
    {
        $org = self::org($clientId);
        if (!$org) {
            return null;
        }
        $id = $org['org_id'];
        $cov = self::coverage($clientId, $org, $devices);
        $data = [
            'org' => $org,
            'coverage' => $cov,
            'av' => self::antivirus($cov['agents']),
            'open' => DB::all("SELECT * FROM huntress_incidents WHERE org_id = ? AND status IN ('" . implode("','", Sync::OPEN) . "')
                ORDER BY FIELD(severity, 'critical', 'high', 'low'), sent_at DESC", [$id]),
            // Closed (resolved) ones only: dismissed reports were false positives
            'closed' => array_column(DB::all("SELECT severity, COUNT(*) AS n FROM huntress_incidents WHERE org_id = ? AND status = 'closed'
                AND COALESCE(closed_at, status_updated_at, sent_at) >= NOW() - INTERVAL 12 MONTH GROUP BY severity", [$id]), 'n', 'severity'),
            'escalations' => DB::all("SELECT * FROM huntress_escalations WHERE org_id = ? AND status IN ('open', 'overdue') ORDER BY status = 'overdue' DESC, created_at DESC", [$id]),
            'reports' => DB::all('SELECT * FROM huntress_reports WHERE org_id = ? ORDER BY period_end DESC, type LIMIT 12', [$id]),
            'ports' => DB::all('SELECT * FROM huntress_ports WHERE org_id = ? ORDER BY risky DESC, port', [$id]),
        ];
        // The sync stopped reading Huntress (a revoked key): the checks are unknown rather than judged on old data
        $data['stale'] = !$org['synced_at'] || strtotime($org['synced_at']) < time() - self::STALE_HOURS * 3600;
        $data['checks'] = self::checks($data);
        return $data;
    }

    /** The automatic checks from forClient()'s data: [key => ['status' => pass|fail|unknown, 'detail']]. */
    public static function checks(array $data): array
    {
        $out = [];
        $set = function (string $k, ?bool $pass, string $detail) use (&$out) {
            $out[$k] = ['status' => $pass === null ? 'unknown' : ($pass ? 'pass' : 'fail'), 'detail' => mb_substr($detail, 0, 300)];
        };
        $n = fn(int $c, string $w) => "$c $w" . ($c === 1 ? '' : 's');
        $names = fn(array $rows) => implode(', ', array_slice(array_map(fn($r) => (string) $r['name'], $rows), 0, 5)) . (count($rows) > 5 ? '…' : '');
        if (!empty($data['stale'])) {
            foreach (self::CHECKS as $k => $_) {
                $set($k, null, 'Huntress hasn\'t been read since ' . ($data['org']['synced_at'] ? fmt_date($data['org']['synced_at']) : 'it was set up') . ': check the Huntress step of the sync.');
            }
            return $out;
        }

        // Agents on every workstation and server the RMM sees
        $c = $data['coverage'];
        $need = count($c['needed']);
        if (!$need) {
            $set('huntress_agents', null, 'No RMM workstations or servers to compare with (' . $n(count($c['agents']), 'Huntress agent') . ').');
        } else {
            $ok = $need - count($c['missing']) - count($c['offline']);
            $set('huntress_agents', !$c['missing'] && !$c['offline'], "$ok of $need " . ($need === 1 ? 'workstation or server has' : 'workstations and servers have') . ' a Huntress agent checking in'
                . ($c['missing'] ? '; missing on ' . $names($c['missing']) : '') . ($c['offline'] ? '; not checked in for ' . self::offlineDays() . ' days: ' . $names($c['offline']) : ''));
        }

        // Open critical or high incidents
        $bad = array_filter($data['open'], fn($i) => in_array($i['severity'], ['critical', 'high'], true));
        $set('huntress_incidents', !$bad, $bad ? $n(count($bad), 'open critical or high incident') . ': ' . mb_strimwidth((string) reset($bad)['subject'], 0, 120, '…')
            : 'No open critical or high incidents' . ($data['open'] ? ' (' . $n(count($data['open']), 'open low-severity incident') . ')' : ''));

        // Managed Antivirus
        $av = $data['av'];
        $set('huntress_av', $av['of'] ? !$av['bad'] : null, !$av['of'] ? 'No Windows agent has Defender managed by Huntress (Managed Antivirus not in use, or another antivirus).'
            : ($av['of'] - count($av['bad'])) . " of {$av['of']} Windows agents have Defender healthy and compliant"
                . ($av['bad'] ? ': ' . implode(', ', array_map(fn($b) => "$b[0] ($b[1])", array_slice($av['bad'], 0, 4))) : ''));

        // ITDR identities
        $o = $data['org'];
        if ($o['identities_total'] === null) {
            $set('huntress_identities', null, 'No identities in Huntress ITDR for this organization.');
        } else {
            $risk = (int) $o['identities_high_risk'];
            $set('huntress_identities', $risk === 0, (int) $o['identities_total'] . ((int) $o['identities_total'] === 1 ? ' identity' : ' identities') . ' monitored, ' . ($risk ? "$risk at high risk" : 'none at high risk')
                . ((int) $o['identities_no_mfa'] ? ', ' . (int) $o['identities_no_mfa'] . ' without MFA' : ''));
        }

        // External ports
        $risky = array_filter($data['ports'], fn($p) => (int) $p['risky'] === 1);
        if (!$data['ports']) {
            $set('huntress_ports', null, 'Huntress lists no open ports for this organization (not scanned, or nothing found).');
        } else {
            $set('huntress_ports', !$risky, $risky ? $n(count($risky), 'risky service') . ' open: ' . implode(', ', array_map(fn($p) => trim(($p['service'] ?: 'port') . ' ' . $p['ip_address'] . ':' . $p['port']), array_slice($risky, 0, 4)))
                : $n(count($data['ports']), 'open port') . ', none risky');
        }
        return $out;
    }

    /** Compliance-style indicators for every check (unknown, saying why, when the client has no Huntress organization). */
    public static function indicators(?array $data): array
    {
        $out = [];
        foreach (self::CHECKS as $k => $label) {
            $c = $data['checks'][$k] ?? null;
            $st = (string) ($c['status'] ?? 'unknown');
            $out[$k] = ['label' => $label, 'ok' => $st === 'pass', 'unknown' => $st === 'unknown',
                'text' => $c ? (string) $c['detail'] : 'Not linked to a Huntress organization (see Client mapping).',
                'suggest' => $st === 'pass' ? 'met' : ($st === 'fail' ? 'not_met' : null)];
        }
        return $out;
    }

    /**
     * What needs attention, for the dashboard: open critical/high incidents and overdue escalations, per active
     * client. Each: ['tone', 'title', 'detail', 'link', 'client']. Staff only (every client).
     */
    public static function problems(): array
    {
        if (!Api::configured()) {
            return [];
        }
        $out = [];
        foreach (DB::all("SELECT c.id, c.name, i.severity, i.subject, i.sent_at FROM huntress_incidents i
                JOIN client_links l ON l.provider = ? AND l.external_id = i.org_id JOIN clients c ON c.id = l.client_id
                WHERE c.is_archived = 0 AND c.planning_excluded = 0 AND i.status IN ('" . implode("','", Sync::OPEN) . "') AND i.severity IN ('critical', 'high') ORDER BY i.severity = 'critical' DESC, i.sent_at", [Sync::PROVIDER]) as $r) {
            $out[] = ['tone' => 'bad', 'title' => 'Open ' . $r['severity'] . ' Huntress incident', 'detail' => (string) $r['subject'], 'link' => '/clients/' . (int) $r['id'] . '#huntress', 'client' => $r['name']];
        }
        foreach (DB::all("SELECT c.id, c.name, e.subject, e.status FROM huntress_escalations e
                JOIN client_links l ON l.provider = ? AND l.external_id = e.org_id JOIN clients c ON c.id = l.client_id
                WHERE c.is_archived = 0 AND c.planning_excluded = 0 AND e.status = 'overdue' ORDER BY e.created_at", [Sync::PROVIDER]) as $r) {
            $out[] = ['tone' => 'warn', 'title' => 'Overdue Huntress escalation', 'detail' => (string) $r['subject'], 'link' => '/clients/' . (int) $r['id'] . '#huntress', 'client' => $r['name']];
        }
        return $out;
    }
}
