<?php
declare(strict_types=1);

namespace Align;

/**
 * Tamper-evident audit log. Each entry stores an HMAC over its own fields plus the previous entry's
 * hash, keyed with a key derived from app_key (kept in config.php, not the database). Editing,
 * inserting or deleting an entry breaks the chain from that point, and verify() reports where.
 * Entries older than the retention period (6 years, the HIPAA documentation period) can be pruned;
 * the hash of the last pruned entry becomes the new anchor so the rest still verifies.
 * The anchor and the head (the newest entry's id and hash) are sealed with their own HMAC (1.45), so entries
 * can't be cut off either end of the log with the markers moved to match.
 */
final class AuditChain
{
    public const RETENTION_YEARS = 6;

    private static function key(): string
    {
        return hash_hmac('sha256', 'audit-chain-v1', Crypto::key(), true);
    }

    /** The seal over the chain's start and end markers. */
    private static function sealOf(array $c): string
    {
        return hash_hmac('sha256', implode('|', ['seal-v1', (int) ($c['anchor_id'] ?? 0), (string) ($c['anchor_hash'] ?? ''),
            (int) ($c['last_id'] ?? 0), (string) ($c['last_hash'] ?? '')]), self::key());
    }

    /** Seals the markers as they are now (after pruning or backfilling, and once by migration 044). */
    public static function reseal(): void
    {
        $seal = function () {
            $c = DB::one('SELECT * FROM audit_chain WHERE id = 1 FOR UPDATE'); // no entry can be added in between
            if ($c && array_key_exists('seal', $c)) {
                DB::run('UPDATE audit_chain SET seal = ? WHERE id = 1', [self::sealOf($c)]);
            }
        };
        DB::pdo()->inTransaction() ? $seal() : DB::transaction($seal);
    }

    /** Fields are joined with \x1f, which Audit::log strips from what it stores, so no field can shift into the next. */
    public static function hash(array $r, string $prev): string
    {
        $data = implode("\x1f", [$prev, (string) $r['id'], (string) $r['created_at'], (string) ($r['user_id'] ?? ''),
            (string) ($r['portal_user_id'] ?? ''), (string) $r['action'], (string) ($r['detail'] ?? ''), (string) ($r['ip'] ?? '')]);
        return hash_hmac('sha256', $data, self::key());
    }

    /** Appends an entry under a row lock so the chain stays linear even with concurrent requests. */
    public static function append(array $row): void
    {
        DB::transaction(function () use ($row) {
            $chain = DB::one('SELECT * FROM audit_chain WHERE id = 1 FOR UPDATE');
            if (!$chain) {
                DB::run('INSERT IGNORE INTO audit_chain (id) VALUES (1)');
                $chain = DB::one('SELECT * FROM audit_chain WHERE id = 1 FOR UPDATE');
            }
            $row['created_at'] = date('Y-m-d H:i:s');
            $id = DB::insert('audit_log', $row);
            $row['id'] = $id;
            $h = self::hash($row, (string) $chain['last_hash']);
            DB::run('UPDATE audit_log SET prev_hash = ?, row_hash = ? WHERE id = ?', [$chain['last_hash'], $h, $id]);
            if (array_key_exists('seal', $chain)) {
                DB::run('UPDATE audit_chain SET last_id = ?, last_hash = ?, seal = ? WHERE id = 1',
                    [$id, $h, self::sealOf(['last_id' => $id, 'last_hash' => $h] + $chain)]);
            } else { // before migration 044
                DB::run('UPDATE audit_chain SET last_id = ?, last_hash = ? WHERE id = 1', [$id, $h]);
            }
        });
    }

    /** Chains any entries that don't have a hash yet (used once when upgrading). */
    public static function backfill(): void
    {
        $chain = DB::one('SELECT * FROM audit_chain WHERE id = 1');
        $prev = (string) ($chain['last_hash'] ?? '');
        $lastId = (int) ($chain['last_id'] ?? 0);
        foreach (DB::all('SELECT id, created_at, user_id, portal_user_id, action, detail, ip FROM audit_log WHERE row_hash IS NULL AND id > ? ORDER BY id', [$lastId]) as $r) {
            $h = self::hash($r, $prev);
            DB::run('UPDATE audit_log SET prev_hash = ?, row_hash = ? WHERE id = ?', [$prev, $h, $r['id']]);
            $prev = $h;
            $lastId = (int) $r['id'];
        }
        DB::run('UPDATE audit_chain SET last_id = ?, last_hash = ? WHERE id = 1', [$lastId, $prev]);
        self::reseal();
    }

    /** @return array{ok:bool, checked:int, broken_at:?int, reason:?string, head_id:int, head_hash:string} */
    public static function verify(): array
    {
        $r = self::walk(0, null);
        // Remember the result so the audit page only has to check entries added since (see quick())
        \Align\Settings::set('audit_verified', json_encode($r + ['at' => date('Y-m-d H:i:s')]));
        return $r;
    }

    /**
     * For the audit page: the last full check (nightly, or "Check the whole log") plus a check of every
     * entry added since. Falls back to a full check when there is no earlier result.
     */
    public static function quick(): array
    {
        $last = json_decode((string) \Align\Settings::get('audit_verified', ''), true);
        if (!is_array($last) || empty($last['ok']) || !isset($last['head_id'], $last['head_hash'])) {
            return self::verify() + ['at' => date('Y-m-d H:i:s'), 'full' => true];
        }
        $r = self::walk((int) $last['head_id'], (string) $last['head_hash']);
        $r['checked'] += (int) $last['checked'];
        return $r + ['at' => $last['at'] ?? null, 'full' => false, 'since' => (int) $last['head_id']];
    }

    /** Walks the chain from after $afterId (whose hash is $prevHash), or from the anchor when $prevHash is null. */
    private static function walk(int $afterId, ?string $prevHash): array
    {
        $chain = DB::one('SELECT * FROM audit_chain WHERE id = 1') ?? ['anchor_hash' => '', 'last_id' => 0, 'last_hash' => ''];
        $prev = $prevHash ?? (string) $chain['anchor_hash'];
        $n = 0;
        $lastId = $afterId;
        // Migration 044 seals the markers, and every entry, prune and backfill reseals them: a missing seal is tampering too
        if (array_key_exists('seal', $chain) && !hash_equals(self::sealOf($chain), (string) $chain['seal'])) {
            return ['ok' => false, 'checked' => 0, 'broken_at' => (int) ($prevHash === null ? ($chain['anchor_id'] ?? 0) : $afterId),
                'reason' => 'the markers for the start or end of the log were changed (entries cut off one end)', 'head_id' => $afterId, 'head_hash' => $prev];
        }
        $stmt = DB::run('SELECT id, created_at, user_id, portal_user_id, action, detail, ip, prev_hash, row_hash FROM audit_log WHERE id > ? ORDER BY id', [$afterId]);
        while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $n++;
            if ((string) $r['prev_hash'] !== $prev) {
                return ['ok' => false, 'checked' => $n, 'broken_at' => (int) $r['id'], 'reason' => 'an entry before this one was removed or changed', 'head_id' => $lastId, 'head_hash' => $prev];
            }
            if (!hash_equals(self::hash($r, $prev), (string) $r['row_hash'])) {
                return ['ok' => false, 'checked' => $n, 'broken_at' => (int) $r['id'], 'reason' => 'this entry was changed', 'head_id' => $lastId, 'head_hash' => $prev];
            }
            $prev = (string) $r['row_hash'];
            $lastId = (int) $r['id'];
        }
        if ($lastId !== (int) $chain['last_id'] || $prev !== (string) $chain['last_hash']) {
            return ['ok' => false, 'checked' => $n, 'broken_at' => $lastId, 'reason' => 'the newest entries were removed', 'head_id' => $lastId, 'head_hash' => $prev];
        }
        return ['ok' => true, 'checked' => $n, 'broken_at' => null, 'reason' => null, 'head_id' => $lastId, 'head_hash' => $prev];
    }

    /** Removes entries older than the retention period and re-anchors the chain. Returns rows removed. */
    public static function prune(): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::RETENTION_YEARS . ' years'));
        return (int) DB::transaction(function () use ($cutoff) {
            DB::one('SELECT id FROM audit_chain WHERE id = 1 FOR UPDATE');
            $last = DB::one('SELECT id, row_hash FROM audit_log WHERE created_at < ? ORDER BY id DESC LIMIT 1', [$cutoff]);
            if (!$last) {
                return 0;
            }
            $n = DB::run('DELETE FROM audit_log WHERE id <= ?', [$last['id']])->rowCount();
            DB::run('UPDATE audit_chain SET anchor_id = ?, anchor_hash = ? WHERE id = 1', [$last['id'], $last['row_hash']]);
            self::reseal();
            return $n;
        });
    }
}
