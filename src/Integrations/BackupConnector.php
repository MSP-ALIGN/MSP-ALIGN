<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\DB;
use Align\Providers\Backup\BackupProvider;

/** An integration that supplies the backup data area. An install can have several. */
abstract class BackupConnector extends Connector implements LinksClients
{
    abstract public function provider(): BackupProvider;

    public function category(): string
    {
        return 'Backup';
    }

    public function areas(): array
    {
        return ['backup'];
    }

    /** Short name for sentences and columns ("Veeam"); the card name can be longer. */
    public function shortName(): string
    {
        return $this->name();
    }

    public function syncSteps(): array
    {
        return [$this->shortName()];
    }

    public function hasTest(): bool
    {
        return true;
    }

    public function test(): string
    {
        return $this->provider()->test();
    }

    // ---- Client links: each client links to one of this product's companies

    public function linkName(): string
    {
        return $this->shortName();
    }

    public function linkNoun(): string
    {
        return 'company';
    }

    public function linkRecords(): array
    {
        return array_map(fn($r) => ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['n'], 'client_id' => $r['client_id'] !== null ? (int) $r['client_id'] : null],
            DB::all('SELECT b.uid AS id, b.name, l.client_id,
                (SELECT COUNT(*) FROM backup_workloads w WHERE w.provider = b.provider AND w.company_uid = b.uid) AS n
                FROM backup_companies b LEFT JOIN client_links l ON l.provider = b.provider AND l.external_id = b.uid WHERE b.provider = ? ORDER BY b.name', [$this->key()]));
    }

    public function linkCountLabel(): string
    {
        return 'machines';
    }

    /** Protected machines the client has in this product (its own and hosted ones), and Microsoft 365 users. */
    public function linkClientSummary(): array
    {
        $out = [];
        $m365 = [];
        foreach (DB::all("SELECT l.client_id, COUNT(m.uid) AS n FROM client_links l
                JOIN backup_m365_objects m ON m.provider = l.provider AND m.company_uid = l.external_id AND m.object_type = 'user'
                WHERE l.provider = ? AND l.external_id IS NOT NULL GROUP BY l.client_id", [$this->key()]) as $r) {
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
