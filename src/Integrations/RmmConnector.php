<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\DB;
use Align\Providers\Rmm\RmmProvider;

/** An integration that supplies the RMM data area (devices and organizations). An install can have several. */
abstract class RmmConnector extends Connector implements LinksClients
{
    /** $forLinks: only building console links (no credentials needed). */
    abstract public function provider(bool $forLinks = false): RmmProvider;

    public function category(): string
    {
        return 'RMM';
    }

    public function areas(): array
    {
        return ['rmm'];
    }

    public function syncSteps(): array
    {
        return [$this->name(), 'Match clients'];
    }

    public function hasTest(): bool
    {
        return true;
    }

    public function test(): string
    {
        return $this->provider()->test();
    }

    // ---- Client links: each client links to one of this RMM's organizations

    public function linkName(): string
    {
        return $this->name();
    }

    public function linkNoun(): string
    {
        return 'organization';
    }

    public function linkRecords(): array
    {
        return array_map(fn($r) => ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['n'], 'client_id' => $r['client_id'] !== null ? (int) $r['client_id'] : null],
            DB::all('SELECT o.org_id AS id, o.name, l.client_id,
                (SELECT COUNT(*) FROM devices d WHERE d.rmm_provider = o.provider AND d.rmm_org_id = o.org_id AND d.removed_at IS NULL) AS n
                FROM rmm_orgs o LEFT JOIN client_links l ON l.provider = o.provider AND l.external_id = o.org_id WHERE o.provider = ? ORDER BY o.name', [$this->key()]));
    }

    public function linkCountLabel(): string
    {
        return 'devices';
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
