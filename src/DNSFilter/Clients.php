<?php
declare(strict_types=1);

namespace Align\DNSFilter;

use Align\DB;
use Align\Huntress\Clients as Hosts;
use Align\Integrations\SaasStore;
use Align\Lifecycle\Lifecycle;
use Align\Providers\ClientLinks;
use Align\Settings;

/**
 * 2.37.0 What DNSFilter says about one client (through its linked DNSFilter organization): roaming client coverage
 * against the client's RMM workstations and laptops, its networks, and its request and threat totals, plus the two
 * automatic checks worked out from them (CHECKS), which Connectors\DNSFilter gives to compliance controls, alignment
 * standards, the health score, reports and the evidence check.
 *
 * Coverage: the client's desktops and laptops that the RMM still sees (in use, not retired or stale) should each have
 * a roaming client that is installed, synced with DNSFilter within offlineDays() (a setting) and filtering (state not
 * unprotected or bypassed). A device matches a roaming client by host name, without the domain (Huntress\Clients's
 * rule), from the RMM's system name or display name to the roaming client's host name or friendly name. Servers are
 * left out: they usually sit behind a filtered network rather than run a roaming client.
 *
 * Security assumptions: reads stored data only (the sync wrote it, cleaned); callers check the viewer may see the
 * client. Host names and network names are remote text: views escape them. Check details are text Align writes
 * from counts, with at most a few device names (the client's own, as the RMM names them).
 */
final class Clients
{
    /** Slug for stored records, links and check keys. */
    public const PROVIDER = 'dnsfilter';

    /** The checks: key => label. */
    public const CHECKS = [
        'dnsfilter_agents' => 'DNSFilter roaming client on every workstation and laptop',
        'dnsfilter_networks' => 'Office networks filtered by DNSFilter',
    ];

    /** Device classes that should run a roaming client. */
    private const NEEDS_AGENT = ['desktop', 'laptop'];
    /** Roaming client statuses and states that mean it's no longer on the computer, or is being removed. */
    private const GONE = ['uninstalled', 'uninstalled_from_client', 'disabled', 'uninstall_queued', 'uninstalling', 'pending_uninstall'];
    /** States that mean the roaming client isn't filtering. */
    private const NOT_FILTERING = ['unprotected', 'bypassed'];
    /** Days of network traffic the networks check looks at. */
    public const TRAFFIC_DAYS = 7;

    /** Whether a stored roaming client is still on its computer (neither its status nor its state says it's gone). */
    public static function installed(array $a): bool
    {
        return !in_array($a['status'] ?? null, self::GONE, true) && !in_array($a['agent_state'] ?? null, self::GONE, true);
    }

    /** Days without a sync after which a roaming client counts as offline (setting dnsfilter_offline_days). */
    public static function offlineDays(): int
    {
        return max(1, min(90, Settings::int('dnsfilter_offline_days', 7)));
    }

    /** The client's linked DNSFilter organization (SaasStore record: id, name, data, synced_at), or null. */
    public static function org(int $clientId): ?array
    {
        if (!Api::configured()) {
            return null;
        }
        $id = ClientLinks::externalId($clientId, self::PROVIDER);
        if ($id === null) {
            return null;
        }
        $r = DB::one('SELECT record_id, name, data, synced_at FROM connector_records WHERE provider = ? AND record_id = ?', [self::PROVIDER, $id]);
        return $r ? ['id' => (string) $r['record_id'], 'name' => (string) $r['name'], 'data' => json_decode((string) $r['data'], true) ?: [], 'synced_at' => $r['synced_at']] : null;
    }

    /**
     * Roaming client coverage for the client: ['needed' => devices that should have one, 'missing' => those without,
     * 'offline' => those whose roaming client hasn't synced within offlineDays(), 'unfiltered' => those whose roaming
     * client is unprotected or bypassed, 'agents' => the organization's installed roaming clients, 'extra' => installed
     * roaming clients matched to none of those devices]. Missing/offline/unfiltered entries: ['name', 'id', 'last',
     * 'state']. $devices: Lifecycle::devices($clientId) when the caller has them.
     */
    public static function coverage(int $clientId, string $orgId, ?array $devices = null): array
    {
        $agents = array_values(array_filter(DB::all('SELECT * FROM dnsfilter_agents WHERE org_id = ? ORDER BY hostname', [$orgId]),
            fn($a) => self::installed($a)));
        $byHost = [];
        // A reinstall can leave an old roaming client beside the new one: the one that synced last wins
        $newer = fn(?array $old, array $a) => $old === null || (string) $a['last_sync'] > (string) $old['last_sync'] ? $a : $old;
        foreach ($agents as $a) {
            foreach (array_unique(array_filter([Hosts::hostKey($a['hostname']), Hosts::hostKey($a['friendly_name'])])) as $k) {
                $byHost[$k] = $newer($byHost[$k] ?? null, $a);
            }
        }
        $devices ??= (new Lifecycle())->devices($clientId);
        $cut = time() - self::offlineDays() * 86400;
        $needed = $missing = $offline = $unfiltered = $used = [];
        foreach ($devices as $d) {
            if (Lifecycle::notInUse($d) || !empty($d['retired_at']) || ($d['source'] ?? '') !== 'rmm' || !empty($d['stale'])
                || !in_array($d['device_class'] ?? '', self::NEEDS_AGENT, true)) {
                continue;
            }
            $needed[] = $d;
            $a = (($k = Hosts::hostKey($d['system_name'] ?? null)) ? ($byHost[$k] ?? null) : null)
                ?? (($k = Hosts::hostKey($d['display_name'] ?? null)) ? ($byHost[$k] ?? null) : null);
            $row = ['name' => $d['name'] ?? ($d['display_name'] ?: $d['system_name']), 'id' => (int) $d['id'], 'last' => $a['last_sync'] ?? null, 'state' => $a['agent_state'] ?? null];
            if (!$a) {
                $missing[] = $row;
                continue;
            }
            $used[$a['agent_id']] = true;
            if (!$a['last_sync'] || strtotime($a['last_sync']) < $cut) {
                $offline[] = $row;
            } elseif (in_array($a['agent_state'], self::NOT_FILTERING, true)) {
                $unfiltered[] = $row;
            }
        }
        return ['needed' => $needed, 'missing' => $missing, 'offline' => $offline, 'unfiltered' => $unfiltered, 'agents' => $agents,
            'extra' => array_values(array_filter($agents, fn($a) => !isset($used[$a['agent_id']])))];
    }

    /** The roaming client check from coverage(): [status, detail]. */
    public static function agentsCheck(array $cov): array
    {
        $need = count($cov['needed']);
        if (!$need) {
            return ['unknown', 'No workstations or laptops from the RMM to compare with.'];
        }
        $names = fn(array $rows) => implode(', ', array_slice(array_column($rows, 'name'), 0, 3)) . (count($rows) > 3 ? ' and ' . (count($rows) - 3) . ' more' : '');
        $bad = [];
        if ($cov['missing']) {
            $bad[] = count($cov['missing']) . ' without one (' . $names($cov['missing']) . ')';
        }
        if ($cov['offline']) {
            $bad[] = count($cov['offline']) . ' not synced in ' . self::offlineDays() . ' days (' . $names($cov['offline']) . ')';
        }
        if ($cov['unfiltered']) {
            $bad[] = count($cov['unfiltered']) . ' not filtering (' . $names($cov['unfiltered']) . ')';
        }
        $ok = $need - count($cov['missing']) - count($cov['offline']) - count($cov['unfiltered']);
        return $bad ? ['fail', "$ok of $need workstations and laptops filtered; " . implode('; ', $bad) . '.']
            : ['pass', "All $need workstations and laptops have a roaming client that is filtering."];
    }

    /** The networks check from an organization record's data: [status, detail]. A number missing makes it unknown. */
    public static function networksCheck(array $d): array
    {
        $n = $d['networks'] ?? null;
        $pol = $d['networks_policy'] ?? null;
        $traffic = $d['network_requests_7d'] ?? null;
        if (!is_int($n) || !is_int($pol)) {
            return ['unknown', 'DNSFilter didn\'t report the networks.'];
        }
        if ($n === 0) {
            return ['unknown', 'No networks are set up in DNSFilter (roaming clients only).'];
        }
        if ($pol < $n) {
            return ['fail', ($n - $pol) . " of $n networks have no filtering policy."];
        }
        if (!is_int($traffic)) {
            return ['unknown', "$n network" . ($n === 1 ? '' : 's') . ' with a policy, but DNSFilter didn\'t report their traffic.'];
        }
        return $traffic > 0 ? ['pass', "$n network" . ($n === 1 ? '' : 's') . ' with a filtering policy; ' . number_format($traffic) . ' requests filtered in the last ' . self::TRAFFIC_DAYS . ' days.']
            : ['fail', "$n network" . ($n === 1 ? '' : 's') . ' with a policy, but no traffic in the last ' . self::TRAFFIC_DAYS . ' days: the office may not be pointed at DNSFilter.'];
    }

    /**
     * Everything the client card and the QBR show, or null when the client has no DNSFilter organization: ['org',
     * 'coverage', 'stale' (not read for SaasStore::STALE_HOURS), 'checks' => [key => ['status', 'detail']]].
     */
    public static function forClient(int $clientId, ?array $devices = null): ?array
    {
        $org = self::org($clientId);
        if (!$org) {
            return null;
        }
        $cov = self::coverage($clientId, $org['id'], $devices);
        $stale = !$org['synced_at'] || strtotime($org['synced_at']) < time() - SaasStore::STALE_HOURS * 3600;
        $checks = [];
        foreach (['dnsfilter_agents' => self::agentsCheck($cov), 'dnsfilter_networks' => self::networksCheck($org['data'])] as $k => [$st, $detail]) {
            // The sync stopped reading DNSFilter (a revoked key): unknown rather than judged on old data
            $checks[$k] = $stale ? ['status' => 'unknown', 'detail' => 'Not read since ' . fmt_date((string) $org['synced_at']) . '.'] : ['status' => $st, 'detail' => $detail];
        }
        return ['org' => $org, 'coverage' => $cov, 'stale' => $stale, 'checks' => $checks];
    }
}
