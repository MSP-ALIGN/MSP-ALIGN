<?php
declare(strict_types=1);

namespace Align;

/**
 * Applies db/migrations/NNN_name.sql (and .php) files in order, once each, recording each in schema_migrations.
 *
 * Security assumptions: run only from `align migrate` (installer, Docker entrypoint, the agent after an update or
 * restore), never from a web request. The files are part of the release and trusted; nothing here takes input. A
 * migration that fails stops the run with its exception: it isn't recorded, so the next run tries it again (MariaDB
 * commits each schema change on its own, so a file that failed half-way may need fixing by hand first).
 */
final class Migrator
{
    /** How long a second `align migrate` waits for one already running, in seconds. */
    private const LOCK_WAIT = 600;

    /**
     * Applies every migration not yet recorded, in file-name order, and returns how many ran. $out gets one line per
     * step. One run at a time (2.2.1): two at once (an entrypoint and the agent, or two containers on one
     * database) both saw a migration as pending and both applied it. The second now waits, then finds it done.
     */
    public static function run(callable $out): int
    {
        $pdo = DB::pdo();
        // Named per database (a lock is server-wide: a test copy and production on one server mustn't wait on each
        // other). A server that doesn't support named locks (e.g. Galera) runs without one, as before 2.2.1.
        $lock = 'msp_align_migrate:' . substr(hash('sha256', (string) DB::value('SELECT DATABASE()')), 0, 32);
        try {
            $got = DB::value('SELECT GET_LOCK(?, ?)', [$lock, self::LOCK_WAIT]);
        } catch (\PDOException) {
            $got = null;
            $lock = null;
        }
        if ($lock !== null && (int) $got !== 1) {
            throw new \RuntimeException('Another database update is still running. Try again when it has finished.');
        }
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $done = array_flip(array_column(DB::all('SELECT version FROM schema_migrations'), 'version'));
            $files = glob(APP_ROOT . '/db/migrations/*.{sql,php}', GLOB_BRACE) ?: [];
            // Natural order, so 1000_x will come after 999_x (plain string order would put it before 101_x)
            usort($files, fn($a, $b) => strnatcmp(basename($a), basename($b)));
            $count = 0;
            foreach ($files as $file) {
                $version = pathinfo($file, PATHINFO_FILENAME);
                if (isset($done[$version])) {
                    continue;
                }
                $out("Applying $version");
                if (str_ends_with($file, '.php')) {
                    $fn = require $file;
                    $fn();
                } else {
                    foreach (self::splitStatements((string) file_get_contents($file)) as $stmt) {
                        $pdo->exec($stmt);
                    }
                }
                DB::insert('schema_migrations', ['version' => $version]);
                $count++;
            }
        } finally {
            try {
                if ($lock !== null) {
                    DB::value('SELECT RELEASE_LOCK(?)', [$lock]);
                }
            } catch (\PDOException) {
                // the connection is gone (and the lock with it): keep the migration's own error
            }
        }
        $out($count ? "Applied $count migration(s)." : 'Database is up to date.');
        return $count;
    }

    /**
     * Splits on semicolons at end of line, skipping -- comment lines. Only for the release's own .sql files: a ";"
     * at the end of a line inside a string would split it (none of them have one).
     */
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
