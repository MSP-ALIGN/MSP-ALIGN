<?php
declare(strict_types=1);

namespace Align\Providers;

use Align\DB;

/**
 * Which record in another system each client is linked to (an RMM organization, a backup company; see
 * Integrations\LinksClients). One link per client per provider, and each outside record belongs to at most one client.
 * A row with external_id NULL and match_method 'manual' means "deliberately not linked": auto-match
 * leaves that client alone.
 */
final class ClientLinks
{
    /** The outside id a client is linked to for a provider, or null. */
    public static function externalId(int $clientId, string $provider): ?string
    {
        $v = DB::value('SELECT external_id FROM client_links WHERE client_id = ? AND provider = ?', [$clientId, $provider]);
        return $v === null || $v === false ? null : (string) $v;
    }

    /** @return array<string, array{external_id:?string, match_method:?string}> provider => link */
    public static function forClient(int $clientId): array
    {
        $out = [];
        foreach (DB::all('SELECT provider, external_id, match_method FROM client_links WHERE client_id = ?', [$clientId]) as $r) {
            $out[$r['provider']] = $r;
        }
        return $out;
    }

    /** @return array<int, array{external_id:?string, match_method:?string}> client id => link, for one provider */
    public static function forProvider(string $provider): array
    {
        $out = [];
        foreach (DB::all('SELECT client_id, external_id, match_method FROM client_links WHERE provider = ?', [$provider]) as $r) {
            $out[(int) $r['client_id']] = $r;
        }
        return $out;
    }

    /**
     * Links (or, with null, unlinks) a client. The outside record is taken away from any other client first.
     * $method: 'auto' (matched by name) or 'manual' (someone chose it; a manual null link stops auto-matching).
     */
    public static function set(int $clientId, string $provider, ?string $externalId, ?string $method): void
    {
        if ($externalId !== null && $externalId !== '') {
            DB::run('DELETE FROM client_links WHERE provider = ? AND external_id = ? AND client_id <> ?', [$provider, $externalId, $clientId]);
        } else {
            $externalId = null;
        }
        if ($externalId === null && $method === null) {
            DB::run('DELETE FROM client_links WHERE client_id = ? AND provider = ?', [$clientId, $provider]);
            return;
        }
        DB::run('INSERT INTO client_links (client_id, provider, external_id, match_method) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE external_id = VALUES(external_id), match_method = VALUES(match_method)', [$clientId, $provider, $externalId, $method]);
    }

    /** A company name reduced for matching: lower case, "&" as "and", no punctuation or Inc/LLC/Corp. */
    public static function normalizeName(string $name): string
    {
        $n = strtolower($name);
        $n = str_replace('&', ' and ', $n);
        $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n) ?? '';
        $n = preg_replace('/\b(the|inc|incorporated|llc|l l c|ltd|limited|co|corp|corporation|company|pllc|pc|lp|llp)\b/', ' ', $n) ?? '';
        return trim(preg_replace('/\s+/', ' ', $n) ?? '');
    }

    /**
     * Links clients that have no link (and no "deliberately not linked" decision) for $provider to the
     * provider's unlinked record with the same name. A name shared by two records matches neither.
     * $alsoRmmNames: a client's RMM organization names count as its names too.
     * Only active clients that are planned for. Returns how many were linked.
     */
    public static function autoMatch(string $provider, bool $alsoRmmNames = false): int
    {
        $table = self::recordTable($provider);
        if ($table === null) {
            return 0;
        }
        [$t, $id] = $table;
        $byName = [];
        foreach (DB::all("SELECT r.$id AS id, r.name FROM $t r LEFT JOIN client_links l ON l.provider = r.provider AND l.external_id = r.$id
                WHERE r.provider = ? AND l.client_id IS NULL", [$provider]) as $r) {
            $byName[self::normalizeName((string) $r['name'])][] = (string) $r['id'];
        }
        $matched = 0;
        foreach (DB::all('SELECT c.id, c.name' . ($alsoRmmNames ? ', ' . self::rmmOrgNamesSql() . ' AS org_name' : '') . ' FROM clients c
                LEFT JOIN client_links l ON l.client_id = c.id AND l.provider = ?
                WHERE (l.client_id IS NULL OR (l.external_id IS NULL AND COALESCE(l.match_method, \'\') <> \'manual\')) AND c.is_archived = 0 AND c.planning_excluded = 0', [$provider]) as $c) {
            $names = [self::normalizeName((string) $c['name'])];
            if ($alsoRmmNames) {
                $names[] = self::normalizeName((string) $c['org_name']);
            }
            // (the backup match has always skipped names that reduce to "0" as well as empty ones)
            foreach (array_unique($alsoRmmNames ? array_filter($names) : array_filter($names, fn($k) => $k !== '')) as $k) {
                if (isset($byName[$k]) && count($byName[$k]) === 1) {
                    self::set((int) $c['id'], $provider, $byName[$k][0], 'auto');
                    unset($byName[$k]);
                    $matched++;
                    break;
                }
            }
        }
        return $matched;
    }

    /**
     * Makes a client for each of an RMM's organizations that no client is linked to, named after it and linked to
     * it (source 'manual', so a PSA connected later adopts it by name). Organizations a client was already made
     * from are skipped, so deleting that client keeps it gone. $orgIds limits it to those organizations.
     * Returns the names of the clients made.
     */
    public static function createClientsFromOrgs(string $provider, ?array $orgIds = null): array
    {
        if (!isset(Providers::rmmConnectors()[$provider])) {
            return [];
        }
        self::autoMatch($provider); // an existing client with the same name gets linked instead
        $made = [];
        $taken = [];
        foreach (DB::all('SELECT name FROM clients WHERE is_archived = 0') as $c) {
            $taken[self::normalizeName((string) $c['name'])] = true;
        }
        $rows = DB::all('SELECT o.org_id, o.name FROM rmm_orgs o LEFT JOIN client_links l ON l.provider = o.provider AND l.external_id = o.org_id
            WHERE o.provider = ? AND l.client_id IS NULL AND o.client_created_at IS NULL ORDER BY o.name', [$provider]);
        foreach ($rows as $o) {
            if ($orgIds !== null && !in_array((string) $o['org_id'], array_map('strval', $orgIds), true)) {
                continue;
            }
            $name = mb_substr(trim((string) $o['name']), 0, 255);
            if ($name === '' || isset($taken[self::normalizeName($name)])) {
                continue; // same name as a client that is linked elsewhere or kept unlinked: leave it for a person
            }
            DB::transaction(function () use ($provider, $o, $name, &$made, &$taken) {
                // the button and a sync can run at once: take the organization first, then check it's still free
                $still = DB::one('SELECT o.client_created_at, l.client_id FROM rmm_orgs o LEFT JOIN client_links l ON l.provider = o.provider AND l.external_id = o.org_id
                    WHERE o.provider = ? AND o.org_id = ? FOR UPDATE', [$provider, $o['org_id']]);
                if (!$still || $still['client_created_at'] !== null || $still['client_id'] !== null) {
                    return;
                }
                $taken[self::normalizeName($name)] = true;
                $id = DB::insert('clients', ['name' => $name, 'source' => 'manual']);
                self::set($id, $provider, (string) $o['org_id'], 'auto');
                DB::run('UPDATE rmm_orgs SET client_created_at = NOW() WHERE provider = ? AND org_id = ?', [$provider, $o['org_id']]);
                $made[] = $name;
            });
        }
        return $made;
    }

    /** Whether new RMM organizations become clients on every sync (installs without a PSA). */
    public static function autoCreates(): bool
    {
        return \Align\Settings::get('rmm_create_clients', '0') === '1' && !Providers::psaConfigured();
    }

    /** Where a provider's linkable records live: [table, id column]. */
    private static function recordTable(string $provider): ?array
    {
        return match (true) {
            isset(Providers::rmmConnectors()[$provider]) => ['rmm_orgs', 'org_id'],
            isset(Providers::backupConnectors()[$provider]) => ['backup_companies', 'uid'],
            default => null,
        };
    }

    /** Removes links to outside records that no longer exist (keeps "deliberately not linked" rows). */
    public static function prune(string $provider, array $existingIds): void
    {
        if (!$existingIds) {
            return;
        }
        $in = implode(',', array_fill(0, count($existingIds), '?'));
        DB::run("DELETE FROM client_links WHERE provider = ? AND external_id IS NOT NULL AND external_id NOT IN ($in)", [$provider, ...array_map('strval', $existingIds)]);
    }

    /** Quoted, comma-separated connector keys for SQL IN lists (keys are fixed class constants). */
    private static function sqlKeys(array $keys): string
    {
        return $keys ? implode(',', array_map(fn($k) => "'" . preg_replace('/[^a-z0-9_-]/', '', (string) $k) . "'", $keys)) : "''";
    }

    private static function rmmKeys(): string
    {
        return self::sqlKeys(array_keys(Providers::rmmConnectors()));
    }

    private static function backupKeys(): string
    {
        return self::sqlKeys(array_keys(Providers::backupConnectors()));
    }

    /** SQL condition: the client (alias $c) is linked to a company in some backup product. */
    public static function backupLinkedSql(string $c = 'c'): string
    {
        return "EXISTS (SELECT 1 FROM client_links bl WHERE bl.client_id = $c.id AND bl.external_id IS NOT NULL AND bl.provider IN (" . self::backupKeys() . '))';
    }

    /** SQL subquery for `x IN (...)`: the client's (alias $c) backup company uids. */
    public static function backupCompaniesSql(string $c = 'c'): string
    {
        return "(SELECT bl.external_id FROM client_links bl WHERE bl.client_id = $c.id AND bl.external_id IS NOT NULL AND bl.provider IN (" . self::backupKeys() . '))';
    }

    /** The client's backup company uids. @return string[] */
    public static function backupCompanyUids(int $clientId): array
    {
        return array_map('strval', array_column(self::backupCompanies($clientId), 'uid'));
    }

    /** The client's backup companies: [[provider, uid], ...]. */
    public static function backupCompanies(int $clientId): array
    {
        return DB::all('SELECT provider, external_id AS uid FROM client_links WHERE client_id = ? AND external_id IS NOT NULL AND provider IN (' . self::backupKeys() . ')', [$clientId]);
    }

    /** SQL condition: the client (alias $c) is linked to an organization in some RMM. */
    public static function rmmLinkedSql(string $c = 'c'): string
    {
        return "EXISTS (SELECT 1 FROM client_links rl WHERE rl.client_id = $c.id AND rl.external_id IS NOT NULL AND rl.provider IN (" . self::rmmKeys() . '))';
    }

    /** SQL expression: the names of the client's (alias $c) RMM organizations, comma-separated, or NULL. */
    public static function rmmOrgNamesSql(string $c = 'c'): string
    {
        return "(SELECT GROUP_CONCAT(ro.name ORDER BY ro.name SEPARATOR ', ') FROM client_links rl
            JOIN rmm_orgs ro ON ro.provider = rl.provider AND ro.org_id = rl.external_id WHERE rl.client_id = $c.id)";
    }
}
