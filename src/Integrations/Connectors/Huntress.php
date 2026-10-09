<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\DB;
use Align\Huntress\Api;
use Align\Huntress\Sync;
use Align\Integrations\Connector;
use Align\Integrations\LinksClients;

/**
 * 2.7.0 Huntress (managed EDR, Managed Antivirus, ITDR, external recon) as a security source: the card and form on
 * the Integrations page, and the Huntress column on Client mapping (each client links to one Huntress organization,
 * matched by name on every sync, like an RMM's). The sync is Huntress\Sync; per-client data and checks are
 * Huntress\Clients. Also holds the security awareness training (SAT) thresholds, since Huntress Managed SAT is
 * where those results come from (uploaded per client, Sat\Sat, or read from Curricula since 2.7.2, Sat\Curricula).
 *
 * Security assumptions: as Connector. The API key and secret are secret fields (encrypted, never shown again) and
 * only ever sent to Huntress's fixed host (Huntress\Api). Organization names come from Huntress (escape them); the
 * summary HTML holds only numbers.
 */
final class Huntress extends Connector implements LinksClients
{
    /** Slug for the URL and the Registry; also the client_links provider. */
    public function key(): string { return Sync::PROVIDER; }
    /** Name on the card and page. */
    public function name(): string { return 'Huntress'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-shield-virus'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'Security'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Huntress agents against each client\'s RMM devices, Managed Antivirus, incidents and escalations, ITDR identities, external ports and summary reports, plus the thresholds for security awareness training results.'; }
    /** Sync steps whose results show as this integration's status. */
    public function syncSteps(): array { return ['Huntress']; }
    /** Has a Test button. */
    public function hasTest(): bool { return true; }

    /** Set up when the API key and secret are saved. */
    public function configured(): bool
    {
        return Api::configured();
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In Huntress open the menu at the top right → <b>API Credentials</b> → Setup → <b>Generate</b>. The secret is shown once.</li>'
            . '<li>Paste the key and secret here, save and press <b>Test</b>. Align only reads (GET requests), whatever the key allows.</li>'
            . '<li>Run a sync, then check the Huntress column on <a href="/mapping">Client mapping</a> (organizations are matched to clients by name).</li>'
            . '<li>Security awareness training: set up <a href="/integrations/curricula">Huntress SAT (Curricula)</a> to read results automatically, or upload each client\'s Huntress SAT exports on its overview page (the Huntress API has no SAT results).</li></ol>';
    }

    /** API key and secret (secrets), how long an agent may go quiet, and the SAT thresholds. */
    public function fields(): array
    {
        return [
            ['name' => 'huntress_api_key', 'label' => 'API key', 'type' => 'secret'],
            ['name' => 'huntress_api_secret', 'label' => 'API secret', 'type' => 'secret'],
            ['name' => 'huntress_offline_days', 'label' => 'Count an agent as not checking in after', 'type' => 'number', 'min' => 1, 'max' => 90, 'suffix' => 'days', 'default' => '7'],
            ['name' => 'sat_training_pass', 'label' => 'Security awareness training passes when at least this share of learners completed it', 'type' => 'number', 'min' => 1, 'max' => 100, 'suffix' => '%', 'default' => '90'],
            ['name' => 'sat_click_max', 'label' => 'Phishing passes when the click rate over the last 12 months is under', 'type' => 'number', 'min' => 1, 'max' => 100, 'suffix' => '%', 'default' => '10'],
            ['name' => 'sat_campaign_months', 'label' => '…and the last phishing campaign is within', 'type' => 'number', 'min' => 1, 'max' => 24, 'suffix' => 'months', 'default' => '6'],
        ];
    }

    /** Reads the account the key belongs to. */
    public function test(): string
    {
        $a = Api::get('/account')['account'] ?? [];
        $name = is_string($a['name'] ?? null) ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $a['name']) ?? '', 0, 120) : '';
        return 'Connected to Huntress' . ($name !== '' ? " ($name)" : '') . '.';
    }

    // ---- Client links: each client links to one Huntress organization

    /** See LinksClients::linkName(). */
    public function linkName(): string { return 'Huntress'; }
    /** See LinksClients::linkNoun(). */
    public function linkNoun(): string { return 'organization'; }
    /** See LinksClients::linkCountLabel(). */
    public function linkCountLabel(): string { return 'agents'; }

    /** Huntress organizations with their agent counts and linked client. */
    public function linkRecords(): array
    {
        return array_map(fn($r) => ['id' => (string) $r['org_id'], 'name' => (string) $r['name'], 'count' => (int) $r['n'], 'client_id' => $r['client_id'] !== null ? (int) $r['client_id'] : null],
            DB::all('SELECT o.org_id, o.name, l.client_id, (SELECT COUNT(*) FROM huntress_agents a WHERE a.org_id = o.org_id) AS n
                FROM huntress_orgs o LEFT JOIN client_links l ON l.provider = o.provider AND l.external_id = o.org_id WHERE o.provider = ? ORDER BY o.name', [Sync::PROVIDER]));
    }

    /** Agents each linked client has in Huntress. */
    public function linkClientSummary(): array
    {
        $out = [];
        foreach (DB::all('SELECT l.client_id, COUNT(a.agent_id) AS n FROM client_links l LEFT JOIN huntress_agents a ON a.org_id = l.external_id
                WHERE l.provider = ? AND l.external_id IS NOT NULL GROUP BY l.client_id', [Sync::PROVIDER]) as $r) {
            $out[(int) $r['client_id']] = ['n' => (int) $r['n'], 'html' => (string) (int) $r['n']];
        }
        return $out;
    }

    /** One organization and its agents (see LinksClients::linkRecord()). */
    public function linkRecord(string $id, int $clientId): ?array
    {
        $r = DB::one('SELECT o.name, (SELECT COUNT(*) FROM huntress_agents a WHERE a.org_id = o.org_id) AS n FROM huntress_orgs o WHERE o.provider = ? AND o.org_id = ?', [Sync::PROVIDER, $id]);
        return $r ? ['name' => (string) $r['name'], 'count' => (int) $r['n']] : null;
    }
}
