<?php
declare(strict_types=1);

namespace Align;

use PDO;

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = Config::get('db.host', 'localhost');
            $name = Config::get('db.name', 'mountaineer_align');
            $dsn = str_starts_with($host, '/')
                ? "mysql:unix_socket=$host;dbname=$name;charset=utf8mb4"
                : "mysql:host=$host;dbname=$name;charset=utf8mb4";
            self::$pdo = new PDO($dsn, Config::get('db.user'), Config::get('db.pass'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
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
