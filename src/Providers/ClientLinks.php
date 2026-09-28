<?php
declare(strict_types=1);

namespace Align\Providers;

use Align\DB;

/**
 * Which record in another system each client is linked to (an RMM organization today; more providers
 * later). One link per client per provider, and each outside record belongs to at most one client.
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
