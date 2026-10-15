<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\DB;
use Align\Providers\ClientLinks;
use Align\Providers\Clean;

/**
 * 2.22.0 Storage for community security/SaaS connectors (tables connector_records and connector_results,
 * migration 080): a connector stores the product's records (organizations or accounts, linked to clients through
 * client_links) and the check results it works out per record, and reads them back per client. See
 * docs/CONNECTORS.md and the Security/SaaS template (Templates\Saas).
 *
 * Security assumptions: the records come from an outside product and are untrusted: ids must be 1–64 printable
 * characters, names and details are cleaned (Clean::text) and cut to their columns, statuses are fixed values and
 * check keys must start with the connector's prefix. An empty list never deletes what's stored (an outage or a
 * wrong answer must not wipe a client's data). $provider is a connector key from code, never from a request.
 */
final class SaasStore
{
    /** Results older than this many hours count as unknown (the product hasn't been read since). */
    public const STALE_HOURS = 26;

    /** Largest extra data kept per record (JSON bytes); more is dropped (inserts stay well under the server's packet size). */
    public const MAX_DATA = 65536;

    /**
     * Replaces the provider's records with $records ([['id', 'name', 'data' (array, optional, at most MAX_DATA as JSON)]]) in one transaction,
     * prunes client links to records that are gone, and links clients with the same name. Returns how many were kept.
     * Throws when the list is empty while records are stored (refused, nothing changed).
     */
    public static function replaceRecords(string $provider, array $records): int
    {
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($records as $r) {
            $id = is_array($r) ? self::id($r['id'] ?? null) : null;
            $name = is_array($r) ? Clean::text($r['name'] ?? null, 255) : null;
            if ($id === null || $name === null) {
                continue; // a record without a usable id or name is left out
            }
            $data = isset($r['data']) && is_array($r['data']) ? json_encode($r['data'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;
            $rows[$id] = ['provider' => $provider, 'record_id' => $id, 'name' => $name, 'data' => $data !== false && strlen((string) $data) <= self::MAX_DATA ? $data : null, 'synced_at' => $now];
        }
        $have = (int) DB::value('SELECT COUNT(*) FROM connector_records WHERE provider = ?', [$provider]);
        if (!$rows && $have) {
            throw new \RuntimeException("The product returned no records (Align has $have). Nothing was changed.");
        }
        DB::transaction(function () use ($provider, $rows, $now) {
            if ($rows) {
                DB::upsertMany('connector_records', array_values($rows), ['provider', 'record_id']);
            }
            DB::run('DELETE FROM connector_records WHERE provider = ? AND synced_at < ?', [$provider, $now]);
            DB::run('DELETE FROM connector_results WHERE provider = ? AND record_id NOT IN (SELECT record_id FROM connector_records WHERE provider = ?)', [$provider, $provider]);
        });
        ClientLinks::prune($provider, array_keys($rows));
        ClientLinks::autoMatch($provider);
        return count($rows);
    }

    /**
     * Replaces the provider's check results with $results ([['record_id', 'check_key', 'status', 'detail']]) in one
     * transaction. Results for unknown records, keys outside $prefix or unknown statuses are left out. Returns how
     * many were stored. Throws when none, or fewer than half, are usable while records are stored (refused, nothing
     * changed: a wrong answer mustn't wipe clients' results).
     */
    public static function replaceResults(string $provider, string $prefix, array $results): int
    {
        $now = date('Y-m-d H:i:s');
        $known = array_flip(array_column(DB::all('SELECT record_id FROM connector_records WHERE provider = ?', [$provider]), 'record_id'));
        $rows = [];
        foreach ($results as $r) {
            $id = is_array($r) ? self::id($r['record_id'] ?? null) : null;
            $key = is_array($r) && is_string($r['check_key'] ?? null) ? $r['check_key'] : '';
            $status = is_array($r) ? ($r['status'] ?? '') : '';
            if ($id === null || !isset($known[$id]) || !str_starts_with($key, $prefix) || strlen($key) > 40 || !preg_match('/^[a-z0-9_]+$/', $key)
                || !in_array($status, ['pass', 'fail', 'unknown'], true)) {
                continue;
            }
            $rows["$id|$key"] = ['provider' => $provider, 'record_id' => $id, 'check_key' => $key, 'status' => $status,
                'detail' => Clean::text($r['detail'] ?? null, 300), 'synced_at' => $now];
        }
        // Fewer than half usable means something is wrong with the answer or the code: keep what's stored
        if ($known && count($rows) * 2 < count($results)) {
            throw new \RuntimeException('Only ' . count($rows) . ' of ' . count($results) . ' check results were usable. Nothing was changed.');
        }
        if (!$rows && $known) {
            throw new \RuntimeException('No usable check results (' . count($results) . ' sent for ' . count($known) . ' records). Nothing was changed.');
        }
        DB::transaction(function () use ($provider, $rows, $now) {
            if ($rows) {
                DB::upsertMany('connector_results', array_values($rows), ['provider', 'record_id', 'check_key']);
            }
            DB::run('DELETE FROM connector_results WHERE provider = ? AND synced_at < ?', [$provider, $now]);
        });
        return count($rows);
    }

    /** The provider's records (id, name, data decoded, synced_at), by name. */
    public static function records(string $provider): array
    {
        return array_map(fn($r) => ['id' => (string) $r['record_id'], 'name' => (string) $r['name'], 'data' => json_decode((string) $r['data'], true) ?: [], 'synced_at' => $r['synced_at']],
            DB::all('SELECT * FROM connector_records WHERE provider = ? ORDER BY name', [$provider]));
    }

    /**
     * The check results of the clients linked to one of the provider's records: [client id => [key => ['status',
     * 'detail']]]. Results older than STALE_HOURS are unknown, saying when the product was last read.
     */
    public static function resultsFor(string $provider, array $clientIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $clientIds)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        $old = date('Y-m-d H:i:s', time() - self::STALE_HOURS * 3600);
        foreach (DB::all("SELECT l.client_id, r.check_key, r.status, r.detail, r.synced_at FROM client_links l
                JOIN connector_results r ON r.provider = l.provider AND r.record_id = l.external_id
                WHERE l.provider = ? AND l.client_id IN ($in)", [$provider, ...$ids]) as $r) {
            $stale = $r['synced_at'] < $old;
            $out[(int) $r['client_id']][$r['check_key']] = $stale
                ? ['status' => 'unknown', 'detail' => 'Not read since ' . fmt_date($r['synced_at']) . '.']
                : ['status' => $r['status'], 'detail' => (string) $r['detail']];
        }
        return $out;
    }

    /**
     * One client's indicators for every check in $labels (key => label), in SecurityChecks' shape: from its stored
     * results, or unknown saying why (not linked, not read yet).
     */
    public static function indicators(string $provider, string $productName, array $labels, int $clientId): array
    {
        $linked = ClientLinks::externalId($clientId, $provider) !== null;
        $res = self::resultsFor($provider, [$clientId])[$clientId] ?? [];
        $out = [];
        foreach ($labels as $k => $label) {
            $c = $res[$k] ?? ['status' => 'unknown', 'detail' => $linked ? "$productName hasn't reported this yet." : "Not linked to $productName (Client mapping)."];
            $out[$k] = ['label' => $label, 'ok' => $c['status'] === 'pass', 'unknown' => $c['status'] === 'unknown', 'text' => $c['detail'],
                'suggest' => $c['status'] === 'pass' ? 'met' : ($c['status'] === 'fail' ? 'not_met' : null)];
        }
        return $out;
    }

    /** An outside id: a string or number of 1–64 printable characters, else null. */
    public static function id(mixed $v): ?string
    {
        if (!is_string($v) && !is_int($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v !== '' && strlen($v) <= 64 && !preg_match('/[\x00-\x1F\x7F]/', $v) ? $v : null;
    }
}
