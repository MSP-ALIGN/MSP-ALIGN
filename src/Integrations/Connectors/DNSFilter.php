<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\DB;
use Align\DNSFilter\Api;
use Align\DNSFilter\Clients;
use Align\Integrations\Connector;
use Align\Integrations\HasLinkTable;
use Align\Integrations\LinksClients;
use Align\Integrations\ProvidesChecks;
use Align\Integrations\SaasStore;
use Align\Integrations\SyncsData;
use Align\Providers\ClientLinks;

/**
 * 2.37.0 DNSFilter (DNS security and content filtering), read only: its customer organizations are linked to clients
 * on Client mapping (matching names link on every sync), and the hourly sync reads each organization's roaming
 * clients and networks and its daily request, blocked and threat totals. Two automatic checks come from them (see
 * DNSFilter\Clients): a roaming client on every RMM workstation and laptop, and office networks filtered. The client
 * overview has a DNSFilter card and the QBR's Security section shows threats blocked (staff side only; nothing in the
 * client portal). Organizations, their numbers and the networks check's results are kept by Integrations\SaasStore;
 * roaming clients in dnsfilter_agents (migration 098).
 *
 * Security assumptions: as Connector (admin-only form). The API key is a secret field (encrypted, never shown again)
 * sent only in the Authorization header to DNSFilter's fixed address (DNSFilter\Api). Organization, network and host
 * names come from DNSFilter (cleaned on the way in, escaped where shown); queries are bound and scoped to this
 * connector's key; check details are text Align writes. Stored data is only replaced after a complete, successful
 * read of every organization.
 */
final class DNSFilter extends Connector implements LinksClients, SyncsData, ProvidesChecks, HasLinkTable
{
    /** Slug for the URL, the Registry, every stored record and the check prefix. Never change it once released. */
    public function key(): string { return Clients::PROVIDER; }
    /** Name on the card and page. */
    public function name(): string { return 'DNSFilter'; }
    /** Font Awesome classes for the card and the mapping column. */
    public function icon(): string { return 'fas fa-filter'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'Security'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'DNSFilter roaming clients against each client\'s RMM workstations and laptops, office networks, and requests and threats blocked for the QBR.'; }

    /** Set up when the API key is saved. */
    public function configured(): bool
    {
        return Api::configured();
    }

    /** Setup steps (trusted HTML: fixed text only). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In DNSFilter, add a user with the <b>Read Only</b> role that can see every customer organization, and sign in as that user. (A key has the access of the user who creates it.)</li>'
            . '<li>Open <b>Account Settings → API Keys</b>, create a key and copy it. Paste it here, save and press <b>Test</b>. Align only reads (GET requests).</li>'
            . '<li>Run a sync, then check the DNSFilter column on <a href="/mapping">Client mapping</a> (organizations are matched to clients by name).</li>'
            . '<li>The checks appear with the other automatic checks: pick them in compliance controls and alignment standards. Each client\'s overview gets a DNSFilter card, and the QBR\'s Security section shows threats blocked.</li></ol>';
    }

    /** API key (secret) and how long a roaming client may go without syncing. */
    public function fields(): array
    {
        return [
            ['name' => 'dnsfilter_api_key', 'label' => 'API key', 'type' => 'secret'],
            ['name' => 'dnsfilter_offline_days', 'label' => 'Count a roaming client as offline after', 'type' => 'number', 'min' => 1, 'max' => 90, 'suffix' => 'days', 'default' => '7'],
        ];
    }

    /** It has a connection test. */
    public function hasTest(): bool
    {
        return true;
    }

    /** Reads who the key belongs to and the organizations (one short try). */
    public function test(): string
    {
        return Api::fromSettings(true)->test();
    }

    /** The sync step whose result shows as this integration's status. */
    public function syncSteps(): array
    {
        return [$this->syncStepName()];
    }

    // ---- SyncsData: the hourly sync

    /** Name of its step in the sync log. */
    public function syncStepName(): string
    {
        return $this->name();
    }

    /**
     * Reads every organization, its roaming clients and networks, and the traffic totals; stores them only once all
     * of it was read (any error leaves what's stored alone); then works out the checks. Returns the sync log summary.
     */
    public function sync(): string
    {
        $api = Api::fromSettings();
        $orgs = $api->organizations();
        if (!$orgs && (int) DB::value('SELECT COUNT(*) FROM connector_records WHERE provider = ?', [$this->key()])) {
            throw new \RuntimeException('DNSFilter returned no organizations. Nothing was changed.');
        }
        $agents = $records = $seen = [];
        foreach ($orgs as $o) {
            $agents[$o['id']] = $api->agents($o['id']);
            // Roaming clients carry no organization, so a reply that ignored organization_ids (DNSFilter falls back to
            // the key's own organization) would file the same roaming clients under every client: refuse that
            foreach ($agents[$o['id']] as $a) {
                if (isset($seen[$a['agent_id']])) {
                    throw new \RuntimeException('DNSFilter answered with the same roaming client for two organizations. Nothing was changed.');
                }
                $seen[$a['agent_id']] = true;
            }
            $nets = $api->networks($o['id']);
            $records[$o['id']] = ['id' => $o['id'], 'name' => $o['name'], 'data' => [
                'agents' => count(array_filter($agents[$o['id']], fn($a) => Clients::installed($a))),
                'networks' => count($nets), 'networks_policy' => count(array_filter($nets, fn($n) => $n['policy'])),
            ]];
        }
        // Daily totals for the last 90 days (UTC days, today included: a bare "to" date is the whole day), one request
        // per report for up to 50 organizations. Threats count only blocked ones (the cards say "threats blocked").
        $ids = array_map('strval', array_keys($records));
        $to = gmdate('Y-m-d');
        $from = gmdate('Y-m-d', time() - 89 * 86400);
        $reports = $ids ? [
            'requests' => $api->daily('total_requests_organizations', $ids, $from, $to),
            'blocked' => $api->daily('total_requests_organizations', $ids, $from, $to, ['type' => 'blocked']),
            'threats' => $api->daily('total_threats_organizations', $ids, $from, $to, ['type' => 'blocked']),
        ] : [];
        $since = fn(int $days) => gmdate('Y-m-d', time() - ($days - 1) * 86400);
        foreach ($ids as $id) {
            // An organization DNSFilter wouldn't report on keeps its totals unknown (null), never 0
            $sum = fn(string $r, int $days, string $f = 'total') => in_array($id, $reports[$r]['refused'], true) ? null : array_sum(array_map(fn($d) => $d[$f],
                array_filter($reports[$r]['days'][$id] ?? [], fn($day) => $day >= $since($days), ARRAY_FILTER_USE_KEY)));
            $records[$id]['data'] += ['requests_30d' => $sum('requests', 30), 'blocked_30d' => $sum('blocked', 30), 'threats_30d' => $sum('threats', 30),
                'requests_90d' => $sum('requests', 90), 'blocked_90d' => $sum('blocked', 90), 'threats_90d' => $sum('threats', 90),
                'network_requests_7d' => $sum('requests', Clients::TRAFFIC_DAYS, 'networks'), 'report_to' => gmdate('Y-m-d')];
        }
        $this->storeAgents($agents);
        $kept = SaasStore::replaceRecords($this->key(), array_values($records));

        // The checks of every organization: the networks check from its numbers, the roaming client check against the
        // RMM devices of the client it's linked to (unknown while it isn't linked)
        $links = array_column(DB::all('SELECT external_id, client_id FROM client_links WHERE provider = ? AND external_id IS NOT NULL', [$this->key()]), 'client_id', 'external_id');
        $results = [];
        foreach (SaasStore::records($this->key()) as $r) {
            [$st, $detail] = isset($links[$r['id']]) ? Clients::agentsCheck(Clients::coverage((int) $links[$r['id']], $r['id'])) : ['unknown', 'Not linked to a client (Client mapping).'];
            $results[] = ['record_id' => $r['id'], 'check_key' => 'dnsfilter_agents', 'status' => $st, 'detail' => $detail];
            [$st, $detail] = Clients::networksCheck($r['data']);
            $results[] = ['record_id' => $r['id'], 'check_key' => 'dnsfilter_networks', 'status' => $st, 'detail' => $detail];
        }
        $n = $results ? SaasStore::replaceResults($this->key(), $this->checkPrefix(), $results) : 0;
        return "$kept organizations, " . count($links) . ' linked to clients, ' . array_sum(array_map('count', $agents)) . " roaming clients, $n check results";
    }

    /**
     * Replaces the stored roaming clients with those just read ([org id => rows], every organization complete) in one
     * transaction. Refused when none were read while some are stored: an API change mustn't empty every client's
     * coverage.
     */
    private function storeAgents(array $byOrg): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($byOrg as $org => $list) {
            foreach ($list as $a) {
                $rows[] = ['org_id' => (string) $org] + $a + ['synced_at' => $now];
            }
        }
        if (!$rows && (int) DB::value('SELECT COUNT(*) FROM dnsfilter_agents')) {
            throw new \RuntimeException('DNSFilter returned no roaming clients for any organization. Nothing was changed.');
        }
        DB::transaction(function () use ($rows, $now) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::upsertMany('dnsfilter_agents', $chunk, ['org_id', 'agent_id']);
            }
            DB::run('DELETE FROM dnsfilter_agents WHERE synced_at < ?', [$now]);
        });
    }

    // ---- ProvidesChecks: automatic security checks

    /** Every check key starts with the connector key and an underscore. */
    public function checkPrefix(): string
    {
        return $this->key() . '_';
    }

    /** The system's name in the evidence check. */
    public function checkSystem(): string
    {
        return $this->name();
    }

    /** Every check: key => label. */
    public function checkLabels(): array
    {
        return Clients::CHECKS;
    }

    /**
     * One client's indicators. Worked out now from what's stored (so a link changed on Client mapping counts straight
     * away); unknown, saying why, when the client isn't linked or DNSFilter hasn't been read for a day.
     */
    public function checkIndicators(int $clientId): array
    {
        $data = Clients::forClient($clientId);
        if ($data === null) {
            return SaasStore::indicators($this->key(), $this->name(), $this->checkLabels(), $clientId);
        }
        $out = [];
        foreach (Clients::CHECKS as $k => $label) {
            $c = $data['checks'][$k];
            $out[$k] = ['label' => $label, 'ok' => $c['status'] === 'pass', 'unknown' => $c['status'] === 'unknown', 'text' => $c['detail'],
                'suggest' => $c['status'] === 'pass' ? 'met' : ($c['status'] === 'fail' ? 'not_met' : null)];
        }
        return $out;
    }

    /**
     * The given clients' results (health scores, reports across many clients), worked out now like checkIndicators()
     * so both always agree: the roaming client check compares against the devices of the client linked today, not
     * the one linked when the sync ran. Clients without a DNSFilter organization have none.
     */
    public function checkResults(array $clientIds): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $clientIds)) as $cid) {
            if ($d = Clients::forClient($cid)) {
                $out[$cid] = $d['checks'];
            }
        }
        return $out;
    }

    // ---- HasLinkTable and LinksClients: organizations linked to clients on Client mapping

    /** Its records are in SaasStore's shared table. */
    public function linkTable(): array
    {
        return ['connector_records', 'record_id'];
    }

    /** Name for the mapping column and sentences. */
    public function linkName(): string
    {
        return $this->name();
    }

    /** What one record is called. */
    public function linkNoun(): string
    {
        return 'organization';
    }

    /** What a record's count counts. */
    public function linkCountLabel(): string
    {
        return 'roaming clients';
    }

    /** The organizations with their roaming client counts and linked client, by name. */
    public function linkRecords(): array
    {
        return array_map(fn($r) => ['id' => (string) $r['record_id'], 'name' => (string) $r['name'], 'count' => self::agents($r['data']),
            'client_id' => $r['client_id'] !== null ? (int) $r['client_id'] : null],
            DB::all('SELECT r.record_id, r.name, r.data, l.client_id FROM connector_records r
                LEFT JOIN client_links l ON l.provider = r.provider AND l.external_id = r.record_id WHERE r.provider = ? ORDER BY r.name', [$this->key()]));
    }

    /** One organization and its roaming client count, or null when it isn't in Align. */
    public function linkRecord(string $id, int $clientId): ?array
    {
        $r = DB::one('SELECT name, data FROM connector_records WHERE provider = ? AND record_id = ?', [$this->key(), $id]);
        return $r ? ['name' => (string) $r['name'], 'count' => self::agents($r['data'])] : null;
    }

    /** Each linked client's roaming client count (the HTML is a number only). */
    public function linkClientSummary(): array
    {
        $out = [];
        foreach (DB::all('SELECT l.client_id, r.data FROM client_links l JOIN connector_records r ON r.provider = l.provider AND r.record_id = l.external_id
                WHERE l.provider = ?', [$this->key()]) as $r) {
            $n = self::agents($r['data']);
            $out[(int) $r['client_id']] = ['n' => $n, 'html' => (string) $n];
        }
        return $out;
    }

    /** The roaming client count in a stored record's data (0 when missing). */
    private static function agents(?string $json): int
    {
        $d = json_decode((string) $json, true);
        return is_array($d) && is_int($d['agents'] ?? null) ? $d['agents'] : 0;
    }
}
