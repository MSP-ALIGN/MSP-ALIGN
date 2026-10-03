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
 *
 * Security assumptions: an attacker who can write to the database but doesn't have app_key can't make an entry,
 * a seal or a stored check result that verifies. What they can do, and what catches it: put back an older copy of
 * the markers (with its old seal) after removing the newest entries; the nightly outside checkpoint
 * (align audit:checkpoint) catches it for entries it had seen, and entries added since the last night are not
 * protected against that. They can also put back an older sealed check result, or edit an entry the last full
 * check already covered: the audit page only re-checks newer entries, so either shows up at the next full check
 * (nightly, or "Check the whole log"). Someone with app_key and database access can rewrite the whole log.
 */
final class AuditChain
{
    public const RETENTION_YEARS = 6;
    /** Entries read per query when walking the chain, so memory stays flat however long the log is. */
    private const BATCH = 2000;

    /** The HMAC key for the chain, derived from app_key (never stored). */
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

    /**
     * The HMAC over a stored check result (2.2.1), so quick() only builds on a result verify() really made:
     * without it, writing a made-up "checked up to the newest entry" result to settings hid earlier tampering
     * from the audit page until the nightly check.
     */
    private static function resultMac(array $r): string
    {
        // Every field (ok, broken_at, reason too), so a failed result can't be turned into a passing one
        unset($r['mac']);
        ksort($r);
        return hash_hmac('sha256', 'verified-v2|' . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::key());
    }

    /**
     * Seals the markers as they are now (after pruning or backfilling, and once by migration 044).
     * Only for code that has just changed the markers legitimately: sealing after tampering would hide it.
     */
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

    /**
     * An entry's hash: HMAC over the previous hash and the entry's fields.
     * Fields are joined with \x1f, which Audit::log strips from what it stores, so no field can shift into the next.
     */
    public static function hash(array $r, string $prev): string
    {
        $data = implode("\x1f", [$prev, (string) $r['id'], (string) $r['created_at'], (string) ($r['user_id'] ?? ''),
            (string) ($r['portal_user_id'] ?? ''), (string) $r['action'], (string) ($r['detail'] ?? ''), (string) ($r['ip'] ?? '')]);
        return hash_hmac('sha256', $data, self::key());
    }

    /**
     * Appends an entry under a row lock so the chain stays linear even with concurrent requests.
     * Only Audit::log calls it: the values must already fit their columns, as stored, or the hash won't match.
     * Audit::log calls it after any open DB::transaction() has committed (DB::afterCommit), so the head row's lock
     * is held only for this short transaction.
     */
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

    /**
     * Chains any entries that don't have a hash yet (used once when upgrading, by migration 016, while nothing
     * else is writing). It seals whatever it finds, so it must never run on a live log.
     */
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

    /**
     * Checks the whole chain from the anchor and stores the result (sealed) for quick().
     * Callers: the nightly job, `align audit:verify` and admins (Check the whole log).
     * @return array{ok:bool, checked:int, broken_at:?int, reason:?string, head_id:int, head_hash:string}
     */
    public static function verify(): array
    {
        $r = self::walk(0, null);
        // Remember the result so the audit page only has to check entries added since (see quick())
        $saved = $r + ['at' => date('Y-m-d H:i:s')];
        $saved['mac'] = self::resultMac($saved);
        \Align\Settings::set('audit_verified', json_encode($saved));
        return $r;
    }

    /**
     * For the audit page: the last full check (nightly, or "Check the whole log") plus a check of every
     * entry added since. Falls back to a full check when there is no earlier result, or when the stored result
     * isn't one verify() sealed.
     */
    public static function quick(): array
    {
        $last = json_decode((string) \Align\Settings::get('audit_verified', ''), true);
        if (!is_array($last) || empty($last['ok']) || !isset($last['head_id'], $last['head_hash'])
            || !is_string($last['mac'] ?? null) || !hash_equals(self::resultMac($last), $last['mac'])) {
            return self::verify() + ['at' => date('Y-m-d H:i:s'), 'full' => true];
        }
        $r = self::walk((int) $last['head_id'], (string) $last['head_hash']);
        $r['checked'] += (int) $last['checked'];
        return $r + ['at' => $last['at'] ?? null, 'full' => false, 'since' => (int) $last['head_id']];
    }

    /**
     * Walks the chain from after $afterId (whose hash is $prevHash), or from the anchor when $prevHash is null.
     * The markers and every entry are read from one snapshot (2.2.1): before, an entry added while the check ran
     * could be read without the markers that cover it and was reported as "the newest entries were removed".
     * Called inside an already open transaction it uses that transaction's isolation level, which may not be one
     * snapshot: the callers (verify, quick) don't do that.
     */
    private static function walk(int $afterId, ?string $prevHash): array
    {
        if (!DB::pdo()->inTransaction()) {
            // One consistent snapshot for the whole walk, whatever the server's default isolation level
            DB::pdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        return DB::transaction(fn() => self::walkSnapshot($afterId, $prevHash));
    }

    /** walk() inside its read snapshot. Reads in batches by id, so a long log doesn't have to fit in memory. */
    private static function walkSnapshot(int $afterId, ?string $prevHash): array
    {
        $chain = DB::one('SELECT * FROM audit_chain WHERE id = 1') ?? ['anchor_hash' => '', 'last_id' => 0, 'last_hash' => ''];
        $prev = $prevHash ?? (string) $chain['anchor_hash'];
        $n = 0;
        // From the anchor, the last entry seen so far is the anchor itself: a log pruned down to nothing is intact
        $lastId = $prevHash === null ? (int) ($chain['anchor_id'] ?? 0) : $afterId;
        // Migration 044 seals the markers, and every entry, prune and backfill reseals them: a missing seal is tampering too
        if (array_key_exists('seal', $chain) && !hash_equals(self::sealOf($chain), (string) $chain['seal'])) {
            return ['ok' => false, 'checked' => 0, 'broken_at' => (int) ($prevHash === null ? ($chain['anchor_id'] ?? 0) : $afterId),
                'reason' => 'the markers for the start or end of the log were changed (entries cut off one end)', 'head_id' => $afterId, 'head_hash' => $prev];
        }
        $from = $afterId;
        do {
            $rows = DB::all('SELECT id, created_at, user_id, portal_user_id, action, detail, ip, prev_hash, row_hash FROM audit_log WHERE id > ? ORDER BY id LIMIT ' . self::BATCH, [$from]);
            foreach ($rows as $r) {
                $n++;
                $from = (int) $r['id'];
                if ((string) $r['prev_hash'] !== $prev) {
                    return ['ok' => false, 'checked' => $n, 'broken_at' => (int) $r['id'], 'reason' => 'an entry before this one was removed or changed', 'head_id' => $lastId, 'head_hash' => $prev];
                }
                if (!hash_equals(self::hash($r, $prev), (string) $r['row_hash'])) {
                    return ['ok' => false, 'checked' => $n, 'broken_at' => (int) $r['id'], 'reason' => 'this entry was changed', 'head_id' => $lastId, 'head_hash' => $prev];
                }
                $prev = (string) $r['row_hash'];
                $lastId = (int) $r['id'];
            }
        } while (count($rows) === self::BATCH);
        if ($lastId !== (int) $chain['last_id'] || $prev !== (string) $chain['last_hash']) {
            return ['ok' => false, 'checked' => $n, 'broken_at' => $lastId, 'reason' => 'the newest entries were removed', 'head_id' => $lastId, 'head_hash' => $prev];
        }
        return ['ok' => true, 'checked' => $n, 'broken_at' => null, 'reason' => null, 'head_id' => $lastId, 'head_hash' => $prev];
    }

    /**
     * Removes entries older than the retention period and re-anchors the chain. Returns rows removed.
     * Run by the nightly job (align audit:prune) only. The chain row is locked first, so no entry is added meanwhile.
     */
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
