<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\DB;
use Align\Providers\Backup\BackupProvider;

/**
 * An integration that supplies the backup data area. An install can have several.
 *
 * Security assumptions: as Connector. Company ids and names come from the backup product (untrusted); the queries
 * here are bound and scoped to this provider's key, and the summary HTML holds only numbers and fixed text.
 */
abstract class BackupConnector extends Connector implements LinksClients
{
    /** The provider, ready to call. Throws when not set up. */
    abstract public function provider(): BackupProvider;

    /** Group on the Integrations page. */
    public function category(): string
    {
        return 'Backup';
    }

    /** Data areas this connector supplies. */
    public function areas(): array
    {
        return ['backup'];
    }

    /** Short name for sentences and columns ("Veeam"); the card name can be longer. */
    public function shortName(): string
    {
        return $this->name();
    }

    /** Sync steps whose results show as this integration's status. */
    public function syncSteps(): array
    {
        return [$this->shortName()];
    }

    /** Every backup product has a connection test. */
    public function hasTest(): bool
    {
        return true;
    }

    /** Runs the provider's own test with the saved settings (see Connector::test). */
    public function test(): string
    {
        return $this->provider()->test();
    }

    // ---- Client links: each client links to one of this product's companies

    /** See LinksClients::linkName(). */
    public function linkName(): string
    {
        return $this->shortName();
    }

    /** See LinksClients::linkNoun(). */
    public function linkNoun(): string
    {
        return 'company';
    }

    /** This product's companies with their machine counts and linked client (see LinksClients::linkRecords()). */
    public function linkRecords(): array
    {
        return array_map(fn($r) => ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['n'], 'client_id' => $r['client_id'] !== null ? (int) $r['client_id'] : null],
            DB::all('SELECT b.uid AS id, b.name, l.client_id,
                (SELECT COUNT(*) FROM backup_workloads w WHERE w.provider = b.provider AND w.company_uid = b.uid) AS n
                FROM backup_companies b LEFT JOIN client_links l ON l.provider = b.provider AND l.external_id = b.uid WHERE b.provider = ? ORDER BY b.name', [$this->key()]));
    }

    /** See LinksClients::linkCountLabel(). */
    public function linkCountLabel(): string
    {
        return 'machines';
    }

    /** 2.6.1 One company and the client's protected machines in this product (as linkClientSummary() counts; see LinksClients::linkRecord()). */
    public function linkRecord(string $id, int $clientId): ?array
    {
        $r = DB::one('SELECT b.name, (SELECT COUNT(*) FROM backup_workloads w WHERE w.provider = b.provider AND w.client_id = ?) AS n
            FROM backup_companies b WHERE b.provider = ? AND b.uid = ?', [$clientId, $this->key(), $id]);
        return $r ? ['name' => (string) $r['name'], 'count' => (int) $r['n']] : null;
    }

    /** Protected machines the client has in this product (its own and hosted ones), and Microsoft 365 users. */
    public function linkClientSummary(): array
    {
        $out = [];
        $m365 = [];
        foreach (DB::all("SELECT l.client_id, COUNT(m.uid) AS n FROM client_links l
                JOIN backup_m365_objects m ON m.provider = l.provider AND m.company_uid = l.external_id AND m.object_type = 'user'
                    AND (m.last_point IS NULL OR m.last_point >= ?)
                WHERE l.provider = ? AND l.external_id IS NOT NULL GROUP BY l.client_id", [\Align\Backup\Backup::m365RetiredSince(), $this->key()]) as $r) {
            $m365[(int) $r['client_id']] = (int) $r['n'];
        }
        $linked = array_flip(array_map('intval', array_column(DB::all('SELECT client_id FROM client_links WHERE provider = ? AND external_id IS NOT NULL', [$this->key()]), 'client_id')));
        $machines = [];
        foreach (DB::all("SELECT client_id, COUNT(*) AS n, SUM(client_how IN ('device','job','machine')) AS hosted FROM backup_workloads
                WHERE provider = ? AND client_id IS NOT NULL GROUP BY client_id", [$this->key()]) as $r) {
            $machines[(int) $r['client_id']] = [(int) $r['n'], (int) $r['hosted']];
        }
        foreach (array_keys($linked + $machines) as $cid) {
            [$n, $hosted] = $machines[$cid] ?? [0, 0];
            $users = $m365[$cid] ?? 0;
            $out[$cid] = ['n' => $n, 'html' => $n
                . ($hosted ? ' <span class="text-muted" title="Machines backed up on your own server">(' . $hosted . ' hosted)</span>' : '')
                . ($users ? ' · <i class="fab fa-microsoft text-muted" title="Microsoft 365 users"></i> ' . $users : '')];
        }
        return $out;
    }
}
