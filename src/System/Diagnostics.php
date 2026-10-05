<?php
declare(strict_types=1);

namespace Align\System;

use Align\Config;
use Align\DB;
use Align\Settings;

/**
 * 2.2.3 Settings → Diagnostics: how the server, the app, the database and the background jobs are doing, how much
 * data there is, and recent problems, in one place, plus a plain-text report to paste into a support request.
 *
 * Every figure is read on the spot and every check is wrapped, so a reading that isn't possible here (a /proc file
 * that's missing, a database variable the app's user can't see) shows as "not available" instead of breaking the
 * page. Each check is ['label', 'value', 'status' => ok|warn|bad|info, 'note' => ?string].
 *
 * Security assumptions: admins only (DiagnosticsController checks first). Nothing secret is read or shown: no config
 * values beyond their presence (the site address aside), no keys, passwords or tokens, no record contents (counts
 * only). Recent problems show their error text, which can name a client or an email address, so the text report,
 * which is meant to be shared, leaves that text and the site address out. No folder paths are shown.
 */
final class Diagnostics
{
    /** How often each background job runs, in minutes, and how late it may be before it's flagged. */
    private const JOBS = [
        'sync' => [60, 150],          // hourly sync: flagged after 2.5 hours
        'psa' => [2, 15],             // PSA poll every 2 minutes: flagged after 15
        'mail' => [1, 15],            // mail every minute: a queued message older than 15 minutes means it isn't running
        'nightly' => [1440, 2160],    // nightly audit check: flagged after 36 hours
        'update' => [360, 1080],      // update check every 6 hours: flagged after 18
    ];

    /** One check row. */
    private static function row(string $label, string $value, string $status = 'info', ?string $note = null): array
    {
        return ['label' => $label, 'value' => $value, 'status' => $status, 'note' => $note];
    }

    /** $fn's result, or $fallback when it throws (a reading that isn't possible here). */
    private static function try(callable $fn, mixed $fallback = null): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** "1.2 GB" style size. */
    public static function bytes(int|float|null $b): string
    {
        if ($b === null) {
            return 'not available';
        }
        $u = ['bytes', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) {
            $b /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $b : number_format($b, $b < 10 ? 1 : 0)) . ' ' . $u[$i];
    }

    /** "3 days, 4 hours" style duration from seconds. */
    public static function duration(int $s): string
    {
        $parts = [];
        foreach (['day' => 86400, 'hour' => 3600, 'minute' => 60] as $name => $len) {
            if ($s >= $len) {
                $n = intdiv($s, $len);
                $s -= $n * $len;
                $parts[] = $n . ' ' . $name . ($n === 1 ? '' : 's');
            }
            if (count($parts) === 2) {
                break;
            }
        }
        return $parts ? implode(', ', $parts) : 'under a minute';
    }

    /** "5 minutes ago" style age of a date-time, or "never". */
    private static function ago(?string $at): string
    {
        if (!$at || !($t = strtotime($at))) {
            return 'never';
        }
        return self::duration(max(0, time() - $t)) . ' ago';
    }

    /** Minutes since $at, or null. */
    private static function minutes(?string $at): ?float
    {
        return $at && ($t = strtotime($at)) ? (time() - $t) / 60 : null;
    }

    /** ok / warn / bad from a used fraction (0-1): warn from $warn, bad from $bad. */
    private static function level(float $used, float $warn = 0.8, float $bad = 0.92): string
    {
        return $used >= $bad ? 'bad' : ($used >= $warn ? 'warn' : 'ok');
    }

    /** Everything on the page: summary, server, app, database, storage, data, jobs, problems. */
    public static function all(): array
    {
        $d = [
            'server' => self::server(),
            'app' => self::app(),
            'database' => self::database(),
            'storage' => self::storage(),
            'data' => self::data(),
            'jobs' => self::jobs(),
            'problems' => self::problems(),
            'tables' => self::tables(),
            'at' => date('Y-m-d H:i:s'),
        ];
        // The summary tiles take the worst status of their section
        $worst = function (array $rows): string {
            $s = array_column($rows, 'status');
            return in_array('bad', $s, true) ? 'bad' : (in_array('warn', $s, true) ? 'warn' : 'ok');
        };
        $d['summary'] = [
            'server' => $worst($d['server']),
            'app' => $worst($d['app']),
            'database' => $worst($d['database']),
            'storage' => $worst($d['storage']),
            'jobs' => $worst($d['jobs']),
        ];
        return $d;
    }

    /** The machine: OS, uptime, load, memory, time. Readings from /proc are skipped where /proc isn't there. */
    public static function server(): array
    {
        $rows = [];
        $os = self::try(function () {
            $r = @parse_ini_file('/etc/os-release');
            return is_array($r) && !empty($r['PRETTY_NAME']) ? (string) $r['PRETTY_NAME'] : null;
        });
        $rows[] = self::row('Operating system', ($os ?: PHP_OS_FAMILY) . ' · ' . php_uname('m'));
        $rows[] = self::row('Install type', Agent::docker() ? 'Docker container' : 'Dedicated server (install.sh)');
        if (\Align\Staging::on()) {
            $rows[] = self::row('Test server', 'Yes: email and PSA changes are held back', 'info');
        }
        $up = self::try(fn() => is_readable('/proc/uptime') ? (int) (float) explode(' ', (string) file_get_contents('/proc/uptime'))[0] : null);
        $rows[] = self::row('Uptime', $up !== null ? self::duration($up) : 'not available');
        $cpus = self::try(function () {
            if (!is_readable('/proc/cpuinfo')) {
                return null;
            }
            return max(1, preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo')));
        });
        $load = self::try(fn() => function_exists('sys_getloadavg') ? sys_getloadavg() : null);
        if (is_array($load)) {
            $per = $cpus ? $load[1] / $cpus : null;
            $rows[] = self::row('Load (1, 5, 15 min)', implode(' · ', array_map(fn($l) => number_format((float) $l, 2), $load)) . ($cpus ? " on $cpus CPU" . ($cpus === 1 ? '' : 's') : ''),
                $per === null ? 'info' : ($per >= 1.5 ? 'bad' : ($per >= 0.9 ? 'warn' : 'ok')),
                $per !== null && $per >= 0.9 ? 'The server is busy: more than its CPUs can keep up with over 5 minutes.' : null);
        }
        $mem = self::try(function () {
            if (!is_readable('/proc/meminfo')) {
                return null;
            }
            preg_match_all('/^(\w+):\s+(\d+) kB/m', (string) file_get_contents('/proc/meminfo'), $m);
            return array_combine($m[1], array_map(fn($v) => (int) $v * 1024, $m[2]));
        });
        if (!empty($mem['MemTotal']) && isset($mem['MemAvailable'])) {
            $used = 1 - $mem['MemAvailable'] / $mem['MemTotal'];
            $rows[] = self::row('Memory', self::bytes($mem['MemTotal'] - $mem['MemAvailable']) . ' used of ' . self::bytes($mem['MemTotal']) . ' (' . round($used * 100) . '%)',
                self::level($used, 0.85, 0.95), $used >= 0.85 ? 'Little memory is free: the server may slow down. 4 GB is recommended.' : null);
            if (!empty($mem['SwapTotal'])) {
                $swap = 1 - ($mem['SwapFree'] ?? 0) / $mem['SwapTotal'];
                $rows[] = self::row('Swap', self::bytes($mem['SwapTotal'] - ($mem['SwapFree'] ?? 0)) . ' used of ' . self::bytes($mem['SwapTotal']), self::level($swap, 0.5, 0.8));
            }
        }
        // The clock: the app's and the database's should agree (two-factor codes and the audit log depend on it)
        $skew = self::try(fn() => abs(time() - (int) DB::value('SELECT UNIX_TIMESTAMP()')));
        $rows[] = self::row('Time', date('M j, Y g:i:s a T') . ' (' . date_default_timezone_get() . ')', $skew !== null && $skew > 30 ? 'warn' : 'ok',
            $skew !== null && $skew > 30 ? "The database's clock is $skew seconds off the app's." : null);
        return $rows;
    }

    /** The app: version, PHP and its settings, required extensions, configuration checks, database updates. */
    public static function app(): array
    {
        $rows = [];
        $upd = self::try(fn() => Agent::updateAvailable());
        $rows[] = self::row('Version', APP_VERSION, $upd ? 'warn' : 'ok', $upd ? 'Version ' . ($upd['latest'] ?? '') . ' is available under Updates & backups.' : null);
        $rows[] = self::row('PHP', PHP_VERSION . ' (' . PHP_SAPI . ')', version_compare(PHP_VERSION, '8.2', '>=') ? 'ok' : 'warn');
        $need = ['pdo_mysql', 'sodium', 'curl', 'mbstring', 'json', 'openssl', 'gd', 'zlib', 'intl', 'fileinfo', 'dom'];
        $missing = array_values(array_filter($need, fn($e) => !extension_loaded($e)));
        $rows[] = self::row('PHP extensions', $missing ? 'Missing: ' . implode(', ', $missing) : 'All ' . count($need) . ' needed are loaded', $missing ? 'bad' : 'ok',
            $missing ? 'Run the installer again (sudo msp-align-update) to put them back.' : null);
        $mem = (string) ini_get('memory_limit');
        $rows[] = self::row('PHP memory limit', $mem, $mem !== '-1' && self::iniBytes($mem) < 256 * 1048576 ? 'warn' : 'ok');
        $rows[] = self::row('Largest upload', self::bytes(\Align\Controllers\SystemController::maxUpload()));
        $op = self::try(fn() => function_exists('opcache_get_status') ? @opcache_get_status(false) : null);
        if (is_array($op) && !empty($op['opcache_enabled'])) {
            $st = $op['opcache_statistics'] ?? [];
            $rows[] = self::row('OPcache', 'On · ' . number_format((float) ($st['opcache_hit_rate'] ?? 0), 1) . '% hits · ' . self::bytes($op['memory_usage']['used_memory'] ?? 0) . ' used', 'ok');
        } else {
            $rows[] = self::row('OPcache', 'Off', 'warn', 'Pages load faster with OPcache on (the installer turns it on).');
        }
        $base = (string) Config::get('base_url', '');
        $rows[] = self::row('Site address (base_url)', $base !== '' ? $base : 'not set', $base === '' ? 'bad' : (str_starts_with($base, 'https://') ? 'ok' : 'warn'),
            $base === '' ? 'Links in emails need it: set base_url in config.php.' : (!str_starts_with($base, 'https://') ? 'Use https:// so sign-ins and links are encrypted.' : null));
        $debug = (bool) Config::get('debug', false);
        $rows[] = self::row('Debug mode', $debug ? 'On' : 'Off', $debug ? 'bad' : 'ok', $debug ? 'Turn debug off in config.php: it can show error details to visitors.' : null);
        $key = (string) Config::get('app_key', '');
        $rows[] = self::row('Encryption key (app_key)', $key !== '' ? 'Set' : 'Missing', $key !== '' ? 'ok' : 'bad');
        // Database updates: every migration file applied?
        $pending = self::try(function () {
            // Migrator records each file by its name without the extension
            $files = array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), glob(APP_ROOT . '/db/migrations/*.{sql,php}', GLOB_BRACE) ?: []);
            return array_values(array_diff($files, array_column(DB::all('SELECT version FROM schema_migrations'), 'version')));
        });
        if (is_array($pending)) {
            $rows[] = self::row('Database updates', $pending ? count($pending) . ' not applied yet' : 'All applied', $pending ? 'bad' : 'ok',
                $pending ? 'Run sudo align migrate (or update again) to apply: ' . implode(', ', array_slice($pending, 0, 5)) : null);
        }
        return $rows;
    }

    /** "512M" style PHP size in bytes. */
    private static function iniBytes(string $v): int
    {
        $n = (int) $v;
        return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    }

    /** MariaDB: version, size, connections, encryption at rest, memory for the data, slow queries, tables. */
    public static function database(): array
    {
        $rows = [];
        $var = self::try(fn() => array_column(DB::all("SHOW VARIABLES WHERE Variable_name IN ('version', 'max_connections', 'innodb_buffer_pool_size',
            'innodb_encrypt_tables', 'innodb_encrypt_log', 'encrypt_tmp_files', 'character_set_database')"), 'Value', 'Variable_name'), []);
        $st = self::try(fn() => array_column(DB::all("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected', 'Max_used_connections', 'Slow_queries',
            'Aborted_connects', 'Questions')"), 'Value', 'Variable_name'), []);
        $ok = self::try(fn() => DB::value('SELECT 1') !== null, false);
        $rows[] = self::row('Connection', $ok ? 'Connected' : 'Not connected', $ok ? 'ok' : 'bad');
        if (!empty($var['version'])) {
            $rows[] = self::row('Server', 'MariaDB ' . preg_replace('/-.*$/', '', (string) $var['version']));
        }
        if (!empty($st['Uptime'])) {
            $rows[] = self::row('Running for', self::duration((int) $st['Uptime']));
        }
        $size = self::try(fn() => DB::one('SELECT SUM(data_length) AS data, SUM(index_length) AS idx, SUM(data_free) AS free, COUNT(*) AS n,
            SUM(engine IS NULL) AS broken FROM information_schema.tables WHERE table_schema = DATABASE()'));
        if ($size) {
            $total = (int) $size['data'] + (int) $size['idx'];
            $rows[] = self::row('Size', self::bytes($total) . ' (data ' . self::bytes((int) $size['data']) . ', indexes ' . self::bytes((int) $size['idx']) . ') in ' . (int) $size['n'] . ' tables');
            $rows[] = self::row('Tables', (int) $size['broken'] ? (int) $size['broken'] . ' unreadable' : 'All readable', (int) $size['broken'] ? 'bad' : 'ok',
                (int) $size['broken'] ? 'A table the database can\'t open usually means a damaged or missing file: restore from a backup or ask for help.' : null);
            if (!empty($var['innodb_buffer_pool_size'])) {
                $pool = (int) $var['innodb_buffer_pool_size'];
                $rows[] = self::row('Memory for data (buffer pool)', self::bytes($pool) . ' for ' . self::bytes($total) . ' of data', $pool >= $total ? 'ok' : 'warn',
                    $pool < $total ? 'The data no longer fits in the database\'s memory, so pages read from disk. Give the server more memory and run the installer again.' : null);
            }
        }
        if (isset($st['Threads_connected'], $var['max_connections'])) {
            $used = (int) $st['Threads_connected'] / max(1, (int) $var['max_connections']);
            $rows[] = self::row('Connections', (int) $st['Threads_connected'] . ' open of ' . (int) $var['max_connections'] . ' allowed (most at once: ' . (int) ($st['Max_used_connections'] ?? 0) . ')', self::level($used));
        }
        if (isset($var['innodb_encrypt_tables'])) {
            $enc = strtoupper((string) $var['innodb_encrypt_tables']);
            $on = in_array($enc, ['ON', 'FORCE'], true);
            $rows[] = self::row('Encryption at rest', $on ? 'On (tables' . (($var['innodb_encrypt_log'] ?? '') === 'ON' ? ', redo log' : '') . (($var['encrypt_tmp_files'] ?? '') === 'ON' ? ', temp files' : '') . ')' : 'Off',
                $on ? 'ok' : 'warn', $on ? null : 'The installer sets this up; Docker installs set it up in the database container.');
        }
        if (isset($st['Slow_queries'])) {
            $rows[] = self::row('Slow queries', number_format((int) $st['Slow_queries']) . ' since it started', (int) $st['Slow_queries'] > 100 ? 'warn' : 'ok');
        }
        if (isset($st['Aborted_connects']) && (int) $st['Aborted_connects'] > 0) {
            $rows[] = self::row('Refused connections', number_format((int) $st['Aborted_connects']) . ' since it started', (int) $st['Aborted_connects'] > 50 ? 'warn' : 'info',
                'Connections that failed to sign in to the database, for example after a password change.');
        }
        return $rows;
    }

    /** The 12 largest tables: name, rows (estimated by the database), data and index size. */
    public static function tables(): array
    {
        return self::try(fn() => DB::all('SELECT table_name AS name, table_rows AS rows_est, data_length AS data, index_length AS idx, data_free AS free
            FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY data_length + index_length DESC LIMIT 12'), []);
    }

    /** Disk space where the data lives and where the app lives, and the uploaded files' total. */
    public static function storage(): array
    {
        $rows = [];
        $up = \Align\Branding::uploadDir();
        foreach (['Data disk' => is_dir($up) ? $up : dirname($up), 'App disk' => APP_ROOT] as $label => $dir) {
            $free = self::try(fn() => @disk_free_space($dir) ?: null);
            $total = self::try(fn() => @disk_total_space($dir) ?: null);
            if ($free !== null && $total) {
                $used = 1 - $free / $total;
                $rows[] = self::row($label, self::bytes($free) . ' free of ' . self::bytes($total) . ' (' . round($used * 100) . '% used)', self::level($used, 0.8, 0.9),
                    $used >= 0.8 ? 'Free up or add space: updates, backups and the database need room to work.' : null);
            }
        }
        $files = self::folderSize($up);
        if ($files) {
            $rows[] = self::row('Uploaded files', self::bytes($files['bytes']) . ' in ' . number_format($files['files']) . ' files' . ($files['partial'] ? ' (at least: stopped counting)' : '')
                . ' · logos, pictures, documents, contract PDFs');
        }
        $sess = (string) Config::get('session_path', '');
        if ($sess !== '' && is_dir($sess)) {
            $n = self::try(function () use ($sess) {
                $c = 0;
                $since = time() - 15 * 60;
                foreach (new \DirectoryIterator($sess) as $f) {
                    if ($f->isFile() && $f->getMTime() >= $since && ++$c >= 5000) {
                        break;
                    }
                }
                return $c;
            });
            if ($n !== null) {
                $rows[] = self::row('Active sessions', $n . ' used in the last 15 minutes', 'info', 'Staff and client portal sign-ins, plus visitors on a sign-in page.');
            }
        }
        return $rows;
    }

    /** Total bytes and files under $dir, counting at most 50,000 files or 2 seconds (then 'partial'). */
    private static function folderSize(string $dir): ?array
    {
        if (!is_dir($dir)) {
            return null;
        }
        return self::try(function () use ($dir) {
            $bytes = 0;
            $files = 0;
            $stop = microtime(true) + 2;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && !$f->isLink()) {
                    $bytes += $f->getSize();
                    if (++$files >= 50000 || microtime(true) > $stop) {
                        return ['bytes' => $bytes, 'files' => $files, 'partial' => true];
                    }
                }
            }
            return ['bytes' => $bytes, 'files' => $files, 'partial' => false];
        });
    }

    /** How much of each kind of record there is: [label, count, detail]. Counts only, never contents. */
    public static function data(): array
    {
        $n = fn(string $sql, array $p = []) => (int) self::try(fn() => DB::value($sql, $p), 0);
        $oldest = self::try(fn() => DB::value('SELECT MIN(created_at) FROM audit_log'));
        return [
            ['Clients', $n('SELECT COUNT(*) FROM clients WHERE is_archived = 0'), $n('SELECT COUNT(*) FROM clients WHERE is_archived = 1') . ' archived'],
            ['Devices', $n('SELECT COUNT(*) FROM devices'), null],
            ['Contacts', $n('SELECT COUNT(*) FROM contacts'), null],
            ['Licenses', $n('SELECT COUNT(*) FROM licenses'), null],
            ['Projects', $n('SELECT COUNT(*) FROM roadmap_items'), null],
            ['Documents', $n('SELECT COUNT(*) FROM documents'), $n('SELECT COUNT(*) FROM document_versions') . ' versions'],
            ['Contracts', $n('SELECT COUNT(*) FROM contracts'), null],
            ['Meetings', $n('SELECT COUNT(*) FROM meetings'), null],
            ['Staff users', $n('SELECT COUNT(*) FROM users WHERE is_active = 1'), $n('SELECT COUNT(*) FROM users WHERE is_active = 0') . ' disabled'],
            ['Client portal users', $n('SELECT COUNT(*) FROM portal_users WHERE is_active = 1'), null],
            ['Audit log entries', $n('SELECT COUNT(*) FROM audit_log'), $oldest ? 'since ' . fmt_date((string) $oldest) . ' (kept 6 years)' : null],
            ['API requests logged', $n('SELECT COUNT(*) FROM api_requests'), 'kept 30 days'],
            ['Emails kept', $n('SELECT COUNT(*) FROM mail_queue'), 'bodies cleared after the retention period'],
            ['Sync runs kept', $n('SELECT COUNT(*) FROM sync_runs'), null],
        ];
    }

    /** The background jobs and whether each ran when it should: sync, PSA check, email, nightly check, update check. */
    public static function jobs(): array
    {
        $rows = [];
        $late = fn(string $job, ?float $min) => $min !== null && $min > self::JOBS[$job][1];
        $integrations = self::try(fn() => \Align\Providers\Providers::psaConfigured() || (bool) \Align\Providers\Providers::rmmNames() || (bool) \Align\Providers\Providers::backupNames(), false);
        // Sync
        $sync = self::try(fn() => DB::one("SELECT * FROM sync_runs WHERE status <> 'running' ORDER BY id DESC LIMIT 1"));
        $running = self::try(fn() => DB::one("SELECT started_at FROM sync_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1"));
        $fails = (int) self::try(fn() => DB::value("SELECT COUNT(*) FROM sync_runs WHERE status = 'failed' AND started_at >= ?", [date('Y-m-d H:i:s', time() - 86400)]), 0);
        if ($sync || $integrations) {
            $m = self::minutes($sync['finished_at'] ?? null);
            $took = $sync && $sync['finished_at'] ? strtotime((string) $sync['finished_at']) - strtotime((string) $sync['started_at']) : null;
            $status = !$sync ? 'warn' : ($sync['status'] === 'failed' ? 'bad' : (($late('sync', $m) || $sync['status'] === 'partial') ? 'warn' : 'ok'));
            $rows[] = self::row('Sync (hourly)', $sync ? ucfirst((string) $sync['status']) . ' ' . self::ago($sync['finished_at']) . ($took !== null ? ' · took ' . self::duration(max(0, $took)) : '') . ($running ? ' · one running now' : '') : 'Hasn\'t run yet',
                $status, !$sync ? 'Run it from Sync, or check the msp-align-sync timer.' : ($late('sync', $m) ? 'The last sync is older than expected: the timer may have stopped.' : ($fails ? "$fails failed in the last 24 hours: see Sync for the log." : null)));
        }
        // PSA check every 2 minutes
        if (self::try(fn() => \Align\Providers\Providers::psaConfigured(), false)) {
            $p = self::try(fn() => DB::one('SELECT * FROM psa_poll_state WHERE id = 1'));
            $m = self::minutes($p['last_run'] ?? null);
            $err = $p && str_starts_with((string) $p['last_result'], 'ERROR');
            $rows[] = self::row('PSA check (every 2 minutes)', $p && $p['last_run'] ? 'Ran ' . self::ago($p['last_run']) . ($err ? ' with an error' : '') : 'Hasn\'t run yet',
                !$p || !$p['last_run'] || $late('psa', $m) ? 'warn' : ($err ? 'bad' : 'ok'), $err ? mb_strimwidth((string) $p['last_result'], 0, 200, '…') : ($late('psa', $m) ? 'Check the msp-align-psa timer.' : null));
        }
        // Email: anything waiting longer than it should means the sender isn't running
        $q = self::try(fn() => DB::one("SELECT COUNT(*) AS n, MIN(GREATEST(created_at, COALESCE(send_after, created_at))) AS oldest FROM mail_queue WHERE status IN ('queued','sending')"));
        $failed = (int) self::try(fn() => DB::value("SELECT COUNT(*) FROM mail_queue WHERE status = 'failed' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 7 * 86400)]), 0);
        $mailOn = self::try(fn() => \Align\Mail\Mail::on(), false);
        $m = self::minutes($q['oldest'] ?? null);
        $rows[] = self::row('Email (every minute)', !$mailOn ? 'Email isn\'t set up' : ((int) ($q['n'] ?? 0) ? (int) $q['n'] . ' waiting, oldest ' . self::ago($q['oldest']) : 'Nothing waiting') . ($failed ? " · $failed failed this week" : ''),
            !$mailOn ? 'info' : ($late('mail', $m) ? 'bad' : ($failed ? 'warn' : 'ok')),
            $late('mail', $m) ? 'Emails are waiting longer than they should: the msp-align-mail timer may have stopped.' : ($failed ? 'See Notifications → Email log for why.' : null));
        // Nightly audit check
        $v = json_decode((string) self::try(fn() => Settings::get('audit_verified', ''), ''), true);
        $m = self::minutes(is_array($v) ? ($v['at'] ?? null) : null);
        $rows[] = self::row('Audit log check (nightly)', is_array($v) && !empty($v['at']) ? (!empty($v['ok']) ? 'Intact' : 'PROBLEM FOUND') . ', checked ' . self::ago($v['at']) . ' (' . number_format((int) ($v['checked'] ?? 0)) . ' entries)' : 'Not checked yet',
            is_array($v) && isset($v['ok']) && !$v['ok'] ? 'bad' : (!is_array($v) || $late('nightly', $m) ? 'warn' : 'ok'),
            is_array($v) && isset($v['ok']) && !$v['ok'] ? 'Open Admin → Audit log now.' : ($late('nightly', $m) ? 'The nightly job hasn\'t checked it lately: check the msp-align-nightly timer.' : null));
        // Update check and the root agent (dedicated installs)
        if (!Agent::docker()) {
            $u = self::try(fn() => Agent::update());
            $m = self::minutes($u['checked_at'] ?? null);
            $rows[] = self::row('Update check (every 6 hours)', $u && !empty($u['checked_at']) ? 'Checked ' . self::ago($u['checked_at']) . (!empty($u['error']) ? ' with an error' : '') : 'Not checked yet',
                !$u || $late('update', $m) ? 'warn' : (!empty($u['error']) ? 'warn' : 'ok'), !empty($u['error']) ? mb_strimwidth((string) $u['error'], 0, 200, '…') : null);
            $rows[] = self::row('System agent', self::try(fn() => Agent::available(), false) ? 'Running' : 'Not reachable', self::try(fn() => Agent::available(), false) ? 'ok' : 'bad',
                self::try(fn() => Agent::available(), false) ? null : 'Updates, backups and restores need it: run the installer again (sudo msp-align-update).');
        }
        return $rows;
    }

    /** Recent problems in the last 7 days, newest first: failed syncs, failed emails, failed jobs. */
    public static function problems(): array
    {
        $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        $out = [];
        foreach (self::try(fn() => DB::all("SELECT started_at, status, summary FROM sync_runs WHERE status IN ('failed','partial') AND started_at >= ? ORDER BY id DESC LIMIT 10", [$since]), []) as $r) {
            $out[] = ['at' => $r['started_at'], 'what' => 'Sync ' . $r['status'], 'detail' => mb_strimwidth(trim(strip_tags((string) $r['summary'])), 0, 240, '…'), 'link' => '/sync'];
        }
        foreach (self::try(fn() => DB::all("SELECT created_at, kind, last_error FROM mail_queue WHERE status = 'failed' AND created_at >= ? ORDER BY id DESC LIMIT 10", [$since]), []) as $r) {
            $out[] = ['at' => $r['created_at'], 'what' => 'Email not sent (' . $r['kind'] . ')', 'detail' => mb_strimwidth((string) $r['last_error'], 0, 240, '…'), 'link' => '/settings/notifications/log'];
        }
        foreach (self::try(fn() => Agent::jobs(15), []) as $j) {
            $at = (string) ($j['finished'] ?? $j['created'] ?? '');
            if (($j['state'] ?? '') === 'failed' && $at >= $since) {
                $out[] = ['at' => $at, 'what' => (Agent::ACTIONS[$j['action'] ?? ''] ?? 'Job') . ' failed', 'detail' => mb_strimwidth((string) ($j['message'] ?? ''), 0, 240, '…'), 'link' => '/settings/system'];
            }
        }
        usort($out, fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return array_slice($out, 0, 15);
    }

    /**
     * The same facts as plain text, to paste into a support request or a GitHub issue. Leaves out the site address
     * and the problems' error text; no keys or settings values are in it to begin with.
     */
    public static function report(array $d): string
    {
        $lines = ['MSP Align diagnostics, ' . $d['at'] . ' (' . date_default_timezone_get() . ')', ''];
        $section = function (string $title, array $rows) use (&$lines) {
            $lines[] = '== ' . $title;
            foreach ($rows as $r) {
                if ($r['label'] === 'Site address (base_url)') {
                    $r['value'] = str_starts_with($r['value'], 'https://') ? 'https (set)' : ($r['value'] === 'not set' ? 'not set' : 'http (set)');
                }
                $lines[] = sprintf('%-32s %s%s', $r['label'] . ':', $r['value'], $r['status'] === 'ok' || $r['status'] === 'info' ? '' : '  [' . strtoupper($r['status']) . ']');
            }
            $lines[] = '';
        };
        $section('Server', $d['server']);
        $section('App', $d['app']);
        $section('Database', $d['database']);
        $section('Storage', $d['storage']);
        $section('Background jobs', $d['jobs']);
        $lines[] = '== Data';
        foreach ($d['data'] as [$label, $count, $detail]) {
            $lines[] = sprintf('%-32s %s%s', $label . ':', number_format($count), $detail ? " ($detail)" : '');
        }
        $lines[] = '';
        $lines[] = '== Largest tables';
        foreach ($d['tables'] as $t) {
            $lines[] = sprintf('%-32s %s rows, %s', $t['name'], number_format((int) $t['rows_est']), self::bytes((int) $t['data'] + (int) $t['idx']));
        }
        $lines[] = '';
        // Without their details: an error message can hold a client's name or an email address
        $lines[] = '== Problems in the last 7 days';
        foreach ($d['problems'] ?: [['at' => '', 'what' => 'None']] as $p) {
            $lines[] = trim($p['at'] . '  ' . $p['what']);
        }
        return implode("\n", $lines) . "\n";
    }
}
