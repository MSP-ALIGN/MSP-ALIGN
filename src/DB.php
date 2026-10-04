<?php
declare(strict_types=1);

namespace Align;

use PDO;

/**
 * The one database connection (MariaDB through PDO) and small helpers around it.
 *
 * Security assumptions: every value goes in as a bound parameter (native prepares, so values never become SQL).
 * Table and column names can't be bound, so the insert/update/upsert helpers only accept plain names (names()).
 * Callers that write their own SQL for DB::run/all/one/value must keep it a literal and bind every value. A
 * PDOException's message can hold SQL fragments and values: show it to people only through safe_error().
 */
final class DB
{
    private static ?PDO $pdo = null;
    /** Query count and time for this request (shown by the 'profile' config option). */
    public static int $queries = 0;
    public static float $queryTime = 0.0;
    public static array $slow = [];
    public static array $seen = [];
    /** How many nested DB::transaction() calls (savepoints) are open inside the outer one. */
    private static int $depth = 0;
    /** Whether an outer DB::transaction() is open. */
    private static bool $open = false;
    /** Work to do once the outer transaction commits: [savepoint depth it was queued at, callable]. */
    private static array $afterCommit = [];

    /**
     * Connects on first use and returns the connection. The session's charset is utf8mb4 and its time zone follows
     * PHP's (Settings → General wins over config.php), so NOW() in SQL and date() in PHP agree.
     * The time zone is fixed as an offset when connecting: a long-running CLI job that crosses a DST change keeps
     * the old offset until it reconnects.
     */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = Config::get('db.host', 'localhost');
            $name = Config::get('db.name', 'msp_align');
            $dsn = str_starts_with($host, '/')
                ? "mysql:unix_socket=$host;dbname=$name;charset=utf8mb4"
                : "mysql:host=$host;dbname=$name;charset=utf8mb4";
            self::$pdo = new PDO($dsn, Config::get('db.user'), Config::get('db.pass'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // The timezone chosen in Settings → General (1.38) wins over config.php's; the database session
            // follows it, so NOW() and PHP's date() agree
            try {
                $tz = self::$pdo->query("SELECT value FROM settings WHERE name = 'timezone' AND is_secret = 0")->fetchColumn();
                if (is_string($tz) && $tz !== '' && $tz !== date_default_timezone_get() && Fmt::validZone($tz)) {
                    date_default_timezone_set($tz);
                }
            } catch (\PDOException) {
                // before the first install step there's no settings table yet
            }
            // date('P') is always "+HH:MM", so it is safe to put in the SQL
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

    /**
     * Prepares and runs one statement with bound values. $sql must be written by the caller (never built from
     * input); only $params may hold outside data.
     */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $t = microtime(true);
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $d = microtime(true) - $t;
        self::$queries++;
        self::$queryTime += $d;
        if (Config::get('profile')) {
            $k = mb_substr(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 120);
            self::$seen[$k] = (self::$seen[$k] ?? 0) + 1;
        }
        if ($d > (float) (Config::get("profile_slow_ms", 50)) / 1000 && count(self::$slow) < 10) {
            self::$slow[] = round($d * 1000) . 'ms ' . mb_substr(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 160);
        }
        return $stmt;
    }

    /** Every row (as associative arrays). Same rules as run(). */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** The first row, or null. Same rules as run(). */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** The first column of the first row, or null when there is no row. Same rules as run(). */
    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * Table and column names are put into the SQL as identifiers, never as values: refuse anything that
     * isn't a plain name, so a key taken from outside data can never become SQL (1.45; callers use literal keys).
     */
    private static function names(string $table, array $cols): void
    {
        foreach ([$table, ...$cols] as $n) {
            if (!is_string($n) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $n)) {
                throw new \InvalidArgumentException('Not a table or column name: ' . mb_substr(preg_replace('/[^\x20-\x7e]/', '?', (string) $n) ?? '', 0, 40));
            }
        }
    }

    /**
     * INSERT one row (column => value) and return its new id. Column names are checked by names(); the caller
     * decides which columns may be set (never pass a request's array straight in: that would let it set any column).
     */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        self::names($table, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::run($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * UPDATE table SET row... WHERE every $where column equals its value (not null). Returns rows changed (not just matched).
     * An empty $row or $where does nothing (so a missing WHERE can never update the whole table).
     */
    public static function update(string $table, array $row, array $where): int
    {
        if (!$row || !$where) {
            return 0;
        }
        if (in_array(null, $where, true)) {
            throw new \InvalidArgumentException('DB::update: a null in WHERE never matches; use DB::run with IS NULL');
        }
        self::names($table, array_merge(array_keys($row), array_keys($where)));
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table,
            implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($row))),
            implode(' AND ', array_map(fn($c) => "`$c` = ?", array_keys($where))));
        return self::run($sql, [...array_values($row), ...array_values($where)])->rowCount();
    }

    /** INSERT ... ON DUPLICATE KEY UPDATE for every non-key column given. Same column rules as insert(). */
    public static function upsert(string $table, array $row, array $keyCols): void
    {
        $cols = array_keys($row);
        self::names($table, [...$cols, ...$keyCols]);
        $updates = array_diff($cols, $keyCols);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?')),
            implode(',', array_map(fn($c) => "`$c` = VALUES(`$c`)", $updates ?: $keyCols))
        );
        self::run($sql, array_values($row));
    }

    /**
     * upsert() for many rows with the same columns, a few hundred per statement. The first row's keys are the
     * columns: a later row's missing column is stored as null and its extra keys are ignored.
     */
    public static function upsertMany(string $table, array $rows, array $keyCols, int $chunk = 300): void
    {
        if (!$rows) {
            return;
        }
        $cols = array_keys(reset($rows));
        self::names($table, [...$cols, ...$keyCols]);
        $updates = array_diff($cols, $keyCols);
        $one = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        foreach (array_chunk($rows, $chunk) as $part) {
            $params = [];
            foreach ($part as $r) {
                foreach ($cols as $c) {
                    $params[] = $r[$c] ?? null;
                }
            }
            self::run(sprintf(
                'INSERT INTO `%s` (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
                $table,
                implode(',', array_map(fn($c) => "`$c`", $cols)),
                implode(',', array_fill(0, count($part), $one)),
                implode(',', array_map(fn($c) => "`$c` = VALUES(`$c`)", $updates ?: $keyCols))
            ), $params);
        }
    }

    /**
     * Runs $fn in a transaction: commits when it returns, rolls back and rethrows when it throws.
     * Called inside another DB::transaction() (2.2.1), it runs in a savepoint of the outer one instead of failing
     * with "There is already an active transaction": its changes are undone alone if it throws, and are committed
     * or rolled back with the outer transaction.
     * Work queued with afterCommit() inside it runs once the outer transaction commits, and is dropped with a
     * rollback (of the outer transaction, or of the savepoint it was queued in).
     * No automatic retry on deadlock: $fn may have side effects (mail, files) that must not run twice.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$open) {
            $d = ++self::$depth;
            $sp = 'align_sp' . $d;
            $pdo->exec("SAVEPOINT $sp");
            try {
                $result = $fn();
                $pdo->exec("RELEASE SAVEPOINT $sp");
                // its queued work now belongs to the enclosing level
                foreach (self::$afterCommit as $i => [$at]) {
                    if ($at >= $d) {
                        self::$afterCommit[$i][0] = $d - 1;
                    }
                }
                return $result;
            } catch (\Throwable $e) {
                try {
                    $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
                } catch (\PDOException) {
                    // the server already rolled the whole transaction back (deadlock): the outer one sees the error
                }
                self::$afterCommit = array_values(array_filter(self::$afterCommit, fn($q) => $q[0] < $d));
                throw $e;
            } finally {
                self::$depth--;
            }
        }
        $pdo->beginTransaction();
        self::$open = true;
        try {
            $result = $fn();
            $pdo->commit();
        } catch (\Throwable $e) {
            self::$open = false;
            self::$afterCommit = [];
            try {
                $pdo->rollBack();
            } catch (\PDOException) {
                // nothing left to roll back (an implicit commit or a lost connection): keep the original error
            }
            throw $e;
        }
        self::$open = false;
        $queued = self::$afterCommit;
        self::$afterCommit = [];
        foreach ($queued as [, $work]) {
            try {
                $work();
            } catch (\Throwable $e) {
                // the transaction is already committed: report, don't fail the caller
                error_log('[msp-align] after-commit work failed: ' . $e->getMessage());
            }
        }
        return $result;
    }

    /**
     * Runs $work now, or, inside DB::transaction(), right after the outer transaction commits (never if it rolls
     * back). Used for the audit log (2.2.1): appending to the chain locks its head row, and doing that in the middle
     * of a longer transaction could make MariaDB 11.8's snapshot isolation roll the whole transaction back.
     */
    public static function afterCommit(callable $work): void
    {
        if (!self::$open) {
            $work();
            return;
        }
        self::$afterCommit[] = [self::$depth, $work];
    }
}
