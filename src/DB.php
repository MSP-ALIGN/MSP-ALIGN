<?php
declare(strict_types=1);

namespace Align;

use PDO;

final class DB
{
    private static ?PDO $pdo = null;
    /** Query count and time for this request (shown by the 'profile' config option). */
    public static int $queries = 0;
    public static float $queryTime = 0.0;
    public static array $slow = [];
    public static array $seen = [];

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
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

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

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::run($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    /** INSERT ... ON DUPLICATE KEY UPDATE for every non-key column given. */
    public static function upsert(string $table, array $row, array $keyCols): void
    {
        $cols = array_keys($row);
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

    /** upsert() for many rows with the same columns, a few hundred per statement. */
    public static function upsertMany(string $table, array $rows, array $keyCols, int $chunk = 300): void
    {
        if (!$rows) {
            return;
        }
        $cols = array_keys(reset($rows));
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

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
