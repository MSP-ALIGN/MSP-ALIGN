<?php
declare(strict_types=1);

namespace Align\Clients;

use Align\DB;

/**
 * (2.32.1) One client per name, even when two people add the same name at the same moment. Every path that makes a
 * client by hand or from a file (Clients → Add, the CSV import, a lead's signed contract, RMM organizations made into
 * clients) takes a MariaDB named lock for the name, checks the name is still free and inserts while holding it. The
 * second request waits, then finds the first one's client. The PSA sync is left out: it runs under its own sync lock
 * and makes clients by PSA id, so the PSA decides there.
 *
 * Security assumptions: names are untrusted, but only an md5 of them goes into the lock name (a fixed-length,
 * hex-only key, well under MariaDB's 64-character limit), passed as a bound parameter. The lock belongs to this
 * database connection, so it is freed when the process ends however it ends. Callers must not be inside a
 * transaction: the lock is released when with() returns, and the insert must be committed before that, or the next
 * request would not see it.
 */
final class NameLock
{
    /** How long to wait for another request adding the same name (seconds). */
    private const WAIT = 10;

    /**
     * The lock name for a client name. The name check (`name = ?` under utf8mb4_unicode_ci) ignores case, accents
     * and trailing spaces, so the key leaves them out too: "Café Dental" and "cafe dental " take the same lock
     * (2.32.1). A key broader than the collation only makes a few more requests wait for each other.
     */
    public static function key(string $name): string
    {
        $n = mb_strtolower(trim(mb_scrub($name, 'UTF-8')), 'UTF-8');
        if (class_exists(\Normalizer::class) && ($d = \Normalizer::normalize($n, \Normalizer::FORM_D)) !== false) {
            $n = preg_replace('/\p{Mn}+/u', '', $d) ?? $n; // the accent marks split off by FORM_D
        }
        return 'align_client_name_' . md5($n);
    }

    /**
     * Runs $fn while holding the lock for each name in $names (one name, or many for an import) and returns what it
     * returns. Locks are taken in a fixed order (sorted keys), so two imports can't each wait for the other. Throws
     * \InvalidArgumentException (a message for the user) when a name stays busy, after releasing what it took.
     */
    public static function with(string|array $names, callable $fn): mixed
    {
        $keys = array_values(array_unique(array_map(fn($n) => self::key((string) $n), (array) $names)));
        sort($keys);
        $held = [];
        try {
            foreach ($keys as $k) {
                if ((int) DB::value('SELECT GET_LOCK(?, ' . self::WAIT . ')', [$k]) !== 1) {
                    throw new \InvalidArgumentException('Someone is adding this client right now. Try again in a moment.');
                }
                $held[] = $k;
            }
            return $fn();
        } finally {
            foreach ($held as $k) {
                try {
                    DB::value('SELECT RELEASE_LOCK(?)', [$k]);
                } catch (\Throwable) {
                    // a failed release must not hide the error that got us here; the lock goes with the connection
                }
            }
        }
    }

    /** Whether an active (not archived) client already has this exact name. Read inside with(), it is final. */
    public static function taken(string $name): bool
    {
        return (bool) DB::value('SELECT 1 FROM clients WHERE name = ? AND is_archived = 0 LIMIT 1', [$name]);
    }
}
