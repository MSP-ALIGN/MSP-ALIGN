<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\DB;
use Align\Providers\Rmm\RmmProvider;

/**
 * An integration that supplies the RMM data area (devices and organizations). An install can have several.
 *
 * Security assumptions: as Connector. Organization ids and names come from the RMM (untrusted); the queries here
 * are bound and scoped to this provider's key, and the summaries return numbers only.
 */
abstract class RmmConnector extends Connector implements LinksClients
{
    /** $forLinks: only building console links (no credentials needed). */
    abstract public function provider(bool $forLinks = false): RmmProvider;

    /** Group on the Integrations page. */
    public function category(): string
    {
        return 'RMM';
    }

    /** Data areas this connector supplies. */
    public function areas(): array
    {
        return ['rmm'];
    }

    /** Sync steps whose results show as this integration's status. */
    public function syncSteps(): array
    {
        return [$this->name(), 'Match clients'];
    }

    /** Every RMM has a connection test. */
    public function hasTest(): bool
    {
        return true;
    }

    /** Runs the provider's own test with the saved settings (see Connector::test). */
    public function test(): string
    {
        return $this->provider()->test();
    }

    // ---- Client links: each client links to one of this RMM's organizations

    /** See LinksClients::linkName(). */
    public function linkName(): string
    {
        return $this->name();
    }

    /** See LinksClients::linkNoun(). */
    public function linkNoun(): string
    {
        return 'organization';
    }

    /** This RMM's organizations with their device counts and linked client (see LinksClients::linkRecords()). */
    public function linkRecords(): array
    {
        return array_map(fn($r) => ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['n'], 'client_id' => $r['client_id'] !== null ? (int) $r['client_id'] : null],
            DB::all('SELECT o.org_id AS id, o.name, l.client_id,
                (SELECT COUNT(*) FROM devices d WHERE d.rmm_provider = o.provider AND d.rmm_org_id = o.org_id AND d.removed_at IS NULL) AS n
                FROM rmm_orgs o LEFT JOIN client_links l ON l.provider = o.provider AND l.external_id = o.org_id WHERE o.provider = ? ORDER BY o.name', [$this->key()]));
    }

    /** See LinksClients::linkCountLabel(). */
    public function linkCountLabel(): string
    {
        return 'devices';
    }

    /** 2.6.1 One organization and the devices the client gets from it (as linkClientSummary() counts; see LinksClients::linkRecord()). */
    public function linkRecord(string $id, int $clientId): ?array
    {
        $r = DB::one('SELECT o.name, (SELECT COUNT(*) FROM devices d WHERE d.rmm_provider = o.provider AND d.rmm_org_id = o.org_id
                AND d.client_id IS NULL AND d.removed_at IS NULL) AS n FROM rmm_orgs o WHERE o.provider = ? AND o.org_id = ?', [$this->key(), $id]);
        return $r ? ['name' => (string) $r['name'], 'count' => (int) $r['n']] : null;
    }

    /** Devices the client gets from its organization (ones not placed at another client by hand). */
    public function linkClientSummary(): array
    {
        $out = [];
        foreach (DB::all('SELECT l.client_id, COUNT(d.id) AS n FROM client_links l
                LEFT JOIN devices d ON d.rmm_provider = l.provider AND d.rmm_org_id = l.external_id AND d.client_id IS NULL AND d.removed_at IS NULL
                WHERE l.provider = ? AND l.external_id IS NOT NULL GROUP BY l.client_id', [$this->key()]) as $r) {
            $out[(int) $r['client_id']] = ['n' => (int) $r['n'], 'html' => (string) (int) $r['n']];
        }
        return $out;
    }
}
