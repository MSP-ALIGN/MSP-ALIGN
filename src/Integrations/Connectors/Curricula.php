<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\DB;
use Align\Integrations\Connector;
use Align\Integrations\LinksClients;
use Align\Sat\Curricula as Api;

/**
 * 2.7.2 Huntress SAT through the Curricula API: the card and form on the Integrations page, and the Curricula column
 * on Client mapping (each client links to one Curricula account, matched through its Huntress organization or by
 * name on every sync). With it, a linked client's training and phishing results are read from Curricula rather than
 * uploaded; the pass thresholds stay on the Huntress integration. The work is done by Sat\Curricula.
 *
 * Security assumptions: as Connector. The client ID and secret are secret fields (encrypted, never shown again) and
 * only ever sent to Curricula's fixed host (Sat\Curricula). Account names come from Curricula (escape them).
 */
final class Curricula extends Connector implements LinksClients
{
    /** Slug for the URL and the Registry; also the client_links provider. */
    public function key(): string { return Api::PROVIDER; }
    /** Name on the card and page. */
    public function name(): string { return 'Huntress SAT (Curricula)'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-graduation-cap'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'Security'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Security awareness training completion, phishing simulation results and summary reports for each client, read from Curricula (the platform behind Huntress Managed SAT) instead of uploaded.'; }
    /** Sync steps whose results show as this integration's status. */
    public function syncSteps(): array { return ['Curricula']; }
    /** Has a Test button. */
    public function hasTest(): bool { return true; }

    /** Set up when the client ID and secret are saved. */
    public function configured(): bool
    {
        return Api::configured();
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>Sign in to Curricula (Huntress SAT) as your partner administrator and register an <b>OAuth application</b> for the API (Curricula API documentation → Authentication) using the <b>Client Credentials</b> grant.</li>'
            . '<li>Give it the read scopes <code>' . e(Api::SCOPES) . '</code>. The application sees what its owner sees, so the partner administrator sees every client.</li>'
            . '<li>Paste the client ID and secret here, save and press <b>Test</b>.</li>'
            . '<li>Run a sync, then check the Curricula column on <a href="/mapping">Client mapping</a>. Accounts match the client already linked to the same Huntress organization, else by name.</li>'
            . '<li>Results are read once a day. A linked client\'s uploads stop counting while Curricula has results for it.</li></ol>';
    }

    /** Client ID and secret (both kept as secrets). */
    public function fields(): array
    {
        return [
            ['name' => 'curricula_client_id', 'label' => 'Client ID', 'type' => 'secret'],
            ['name' => 'curricula_client_secret', 'label' => 'Client secret', 'type' => 'secret'],
        ];
    }

    /** Where the pass thresholds live (trusted HTML). */
    public function notes(): string
    {
        return '<p class="small text-muted mb-0">The pass thresholds for training and phishing are on the <a href="/integrations/huntress">Huntress</a> integration.</p>';
    }

    /** Gets a token and counts the accounts it can see. */
    public function test(): string
    {
        return Api::test();
    }

    // ---- Client links: each client links to one Curricula account

    /** See LinksClients::linkName(). */
    public function linkName(): string { return 'Curricula'; }
    /** See LinksClients::linkNoun(). */
    public function linkNoun(): string { return 'account'; }
    /** See LinksClients::linkCountLabel(). */
    public function linkCountLabel(): string { return 'licenses'; }

    /** Curricula accounts with their licenses and linked client. */
    public function linkRecords(): array
    {
        return array_map(fn($r) => ['id' => (string) $r['account_id'], 'name' => (string) $r['name'], 'count' => (int) $r['licenses'], 'client_id' => $r['client_id'] !== null ? (int) $r['client_id'] : null],
            DB::all('SELECT a.account_id, a.name, a.licenses, l.client_id FROM sat_accounts a LEFT JOIN client_links l ON l.provider = a.provider AND l.external_id = a.account_id
                WHERE a.provider = ? ORDER BY a.name', [Api::PROVIDER]));
    }

    /** Licenses of each linked client's account. */
    public function linkClientSummary(): array
    {
        $out = [];
        foreach (DB::all('SELECT l.client_id, a.licenses FROM client_links l JOIN sat_accounts a ON a.provider = l.provider AND a.account_id = l.external_id
                WHERE l.provider = ?', [Api::PROVIDER]) as $r) {
            $out[(int) $r['client_id']] = ['n' => (int) $r['licenses'], 'html' => (string) (int) $r['licenses']];
        }
        return $out;
    }

    /** One account (see LinksClients::linkRecord()). */
    public function linkRecord(string $id, int $clientId): ?array
    {
        $r = DB::one('SELECT name, licenses FROM sat_accounts WHERE provider = ? AND account_id = ?', [Api::PROVIDER, $id]);
        return $r ? ['name' => (string) $r['name'], 'count' => (int) $r['licenses']] : null;
    }
}
