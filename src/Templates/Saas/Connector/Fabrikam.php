<?php
declare(strict_types=1);

namespace Align\Templates\Saas\Connector;

use Align\DB;
use Align\Integrations\Connector;
use Align\Integrations\HasLinkTable;
use Align\Integrations\LinksClients;
use Align\Integrations\ProvidesChecks;
use Align\Integrations\SaasStore;
use Align\Integrations\SyncsData;
use Align\Templates\Saas\Fabrikam\Api;

/**
 * Fabrikam Cloud, a security product (beta, community, read-only): its tenants are linked to clients on Client mapping,
 * and the hourly sync works out three automatic security checks for each, which compliance controls, alignment
 * standards, the health score, reports and the evidence check use like the built-in ones. Records and results are
 * kept by Integrations\SaasStore (no tables of its own).
 *
 * template-only: begin
 * TEMPLATE (2.22.0): `align make:connector saas "Name"` copies this to src/Integrations/Connectors/<Name>.php (and
 * Fabrikam/Api.php to src/<Name>/Api.php) and adds it to Integrations\Registry. See docs/CONNECTORS.md.
 * template-only: end
 *
 * Security assumptions: as Connector (admin-only form; the address is a url field, the key a secret). Tenant ids and
 * names come from the product (untrusted, escaped where shown); queries are bound and scoped to this connector's key;
 * the check details are plain text Align writes from numbers.
 */
final class Fabrikam extends Connector implements LinksClients, SyncsData, ProvidesChecks, HasLinkTable
{
    /** Days since the last scan before the scan check fails. */
    private const SCAN_DAYS = 7;

    /** Slug for the URL, the Registry, every stored record and the check prefix. Never change it once released. */
    public function key(): string { return 'fabrikam'; }
    /** Name on the card and page. */
    public function name(): string { return 'Fabrikam Cloud'; }
    /** Font Awesome classes for the card and the mapping column. */
    public function icon(): string { return 'fas fa-shield-halved'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'Security'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Beta (community). Multi-factor sign-in, device protection and scan checks for each client from Fabrikam Cloud.'; }

    /** Set up when the address and key are saved. */
    public function configured(): bool
    {
        return Api::configured();
    }

    /** Setup steps (trusted HTML: fixed text only). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In Fabrikam Cloud, create an API key with <b>read-only</b> access to every tenant.</li>'
            . '<li>Enter the API address and the key. Save, press <b>Test</b>, run a sync, then link tenants to Align clients on <a href="/mapping">Client mapping</a> (matching names link automatically).</li>'
            . '<li>The checks then appear with the other automatic checks: pick them in compliance controls and alignment standards.</li>'
            . '<li>This integration is in beta and only reads from Fabrikam Cloud. Something look wrong? Report it on GitHub with the field names (never the values) from one tenant.</li></ol>';
    }

    /** API address (url) and API key (secret). */
    public function fields(): array
    {
        return [
            ['name' => 'fabrikam_url', 'label' => 'API address', 'type' => 'url', 'placeholder' => 'https://fabrikam.example.com'],
            ['name' => 'fabrikam_api_key', 'label' => 'API key', 'type' => 'secret'],
        ];
    }

    /** It has a connection test. */
    public function hasTest(): bool
    {
        return true;
    }

    /** Reads the tenant list with the saved settings (one short try). */
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

    /** Reads the tenants, stores them, and works out and stores each one's checks. Returns the summary for the sync log. */
    public function sync(): string
    {
        $tenants = Api::fromSettings()->tenants();
        $kept = SaasStore::replaceRecords($this->key(), $tenants);
        $results = [];
        foreach (SaasStore::records($this->key()) as $r) {
            foreach (self::evaluate($r['data']) as $check => [$status, $detail]) {
                $results[] = ['record_id' => $r['id'], 'check_key' => $check, 'status' => $status, 'detail' => $detail];
            }
        }
        $n = SaasStore::replaceResults($this->key(), $this->checkPrefix(), $results);
        $linked = (int) DB::value('SELECT COUNT(*) FROM client_links WHERE provider = ? AND external_id IS NOT NULL', [$this->key()]);
        return "$kept tenants, $linked linked to clients, $n check results";
    }

    /**
     * The checks for one tenant's numbers: [key => [status, detail]]. A number the product didn't send makes that check
     * unknown, never a pass.
     */
    public static function evaluate(array $d): array
    {
        $out = [];
        [$u, $m] = [$d['users'] ?? null, $d['users_mfa'] ?? null];
        $out['fabrikam_mfa'] = !is_int($u) || !is_int($m) || $u === 0 ? ['unknown', 'Fabrikam Cloud didn\'t report its users.']
            : [$m >= $u ? 'pass' : 'fail', "$m of $u users sign in with multi-factor authentication."];
        [$t, $p] = [$d['devices'] ?? null, $d['devices_protected'] ?? null];
        $out['fabrikam_devices'] = !is_int($t) || !is_int($p) || $t === 0 ? ['unknown', 'Fabrikam Cloud didn\'t report any devices.']
            : [$p >= $t ? 'pass' : 'fail', "$p of $t devices are protected."];
        $scan = is_string($d['last_scan'] ?? null) ? $d['last_scan'] : null;
        $out['fabrikam_scan'] = $scan === null ? ['unknown', 'Fabrikam Cloud hasn\'t reported a scan.']
            : [strtotime($scan) >= time() - self::SCAN_DAYS * 86400 ? 'pass' : 'fail', 'Last scan ' . fmt_date($scan) . '.'];
        return $out;
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
        return [
            'fabrikam_mfa' => 'Fabrikam Cloud: every user signs in with multi-factor authentication',
            'fabrikam_devices' => 'Fabrikam Cloud: every device protected',
            'fabrikam_scan' => 'Fabrikam Cloud: scanned in the last ' . self::SCAN_DAYS . ' days',
        ];
    }

    /** One client's indicators (unknown ones say why: not linked, not read yet, too old). */
    public function checkIndicators(int $clientId): array
    {
        return SaasStore::indicators($this->key(), $this->name(), $this->checkLabels(), $clientId);
    }

    /** The stored results of the given clients. */
    public function checkResults(array $clientIds): array
    {
        return SaasStore::resultsFor($this->key(), $clientIds);
    }

    // ---- HasLinkTable and LinksClients: tenants linked to clients on Client mapping

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
        return 'tenant';
    }

    /** What a record's count counts. */
    public function linkCountLabel(): string
    {
        return 'devices';
    }

    /** The tenants with their device counts and linked client, by name. */
    public function linkRecords(): array
    {
        return array_map(fn($r) => ['id' => (string) $r['record_id'], 'name' => (string) $r['name'], 'count' => self::devices($r['data']),
            'client_id' => $r['client_id'] !== null ? (int) $r['client_id'] : null],
            DB::all('SELECT r.record_id, r.name, r.data, l.client_id FROM connector_records r
                LEFT JOIN client_links l ON l.provider = r.provider AND l.external_id = r.record_id WHERE r.provider = ? ORDER BY r.name', [$this->key()]));
    }

    /** One tenant and its device count, or null when it isn't in Align. */
    public function linkRecord(string $id, int $clientId): ?array
    {
        $r = DB::one('SELECT name, data FROM connector_records WHERE provider = ? AND record_id = ?', [$this->key(), $id]);
        return $r ? ['name' => (string) $r['name'], 'count' => self::devices($r['data'])] : null;
    }

    /** Each linked client's device count (the HTML is a number only). */
    public function linkClientSummary(): array
    {
        $out = [];
        foreach (DB::all('SELECT l.client_id, r.data FROM client_links l JOIN connector_records r ON r.provider = l.provider AND r.record_id = l.external_id
                WHERE l.provider = ?', [$this->key()]) as $r) {
            $n = self::devices($r['data']);
            $out[(int) $r['client_id']] = ['n' => $n, 'html' => (string) $n];
        }
        return $out;
    }

    /** The device count in a stored record's data (0 when missing). */
    private static function devices(?string $json): int
    {
        $d = json_decode((string) $json, true);
        return is_array($d) && is_int($d['devices'] ?? null) ? $d['devices'] : 0;
    }
}
