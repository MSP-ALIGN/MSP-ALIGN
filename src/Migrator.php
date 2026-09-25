<?php
declare(strict_types=1);

namespace Align;

/** Applies db/migrations/NNN_name.sql files in order, once each. */
final class Migrator
{
    public static function run(callable $out): int
    {
        $pdo = DB::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $done = array_flip(array_column(DB::all('SELECT version FROM schema_migrations'), 'version'));
        $files = glob(APP_ROOT . '/db/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);
        $count = 0;
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (isset($done[$version])) {
                continue;
            }
            $out("Applying $version");
            foreach (self::splitStatements((string) file_get_contents($file)) as $stmt) {
                $pdo->exec($stmt);
            }
            DB::insert('schema_migrations', ['version' => $version]);
            $count++;
        }
        $out($count ? "Applied $count migration(s)." : 'Database is up to date.');
        return $count;
    }

    /** Splits on semicolons at end of line, skipping -- comment lines. */
    private static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            fn($l) => !str_starts_with(ltrim($l), '--')
        );
        $parts = preg_split('/;\s*$/m', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }
}
