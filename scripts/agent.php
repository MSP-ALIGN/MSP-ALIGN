#!/usr/bin/env php
<?php
// Mountaineer Align. Copyright (C) 2026 Mountaineer IT Inc.
// SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
/**
 * Mountaineer Align system agent. Runs as root, started by systemd:
 *   mountaineer-align-agent.path/.service   when the web app drops a request in /run/mountaineer-align/requests
 *   mountaineer-align-update-check.timer    every 6 hours (check)
 *   mountaineer-align-nightly.timer         nightly clean-up and audit log checks
 *   sudo mountaineer-align-update           update from the command line
 *   sudo mountaineer-align-restore FILE     restore from the command line
 *
 * The web server (www-data) runs with proc_open & co disabled, so it can't run programs. It asks this
 * agent for a fixed set of jobs: check for updates, update, make a backup for download, test or
 * restore an uploaded backup, check a backup key. Requests are data only: every value is validated
 * here and nothing from a request is ever passed to a shell unquoted.
 *
 * Backups are never kept on the server. A backup is built, encrypted to the backup public key,
 * downloaded once through the browser and deleted. Only encrypted data touches the disk.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$env = static fn(string $k, string $d): string => (($v = getenv($k)) !== false && $v !== '') ? $v : $d;
define('APP', $env('ALIGN_APP_DIR', dirname(__DIR__)));
define('CONFIG', $env('ALIGN_CONFIG', '/etc/mountaineer-align/config.php'));
define('DATA', rtrim($env('ALIGN_DATA_DIR', '/var/lib/mountaineer-align'), '/'));
define('RUN', rtrim($env('ALIGN_RUN_DIR', '/run/mountaineer-align'), '/'));
define('RECIPIENT', $env('ALIGN_RECIPIENT', '/etc/mountaineer-align/backup-recipient.txt'));
define('LEGACY', $env('ALIGN_LEGACY_BACKUPS', '/var/backups/mountaineer-align'));
define('PRIVKEY_FILE', '/root/mountaineer-align-backup-key.txt');
define('RUNAS', $env('ALIGN_RUNAS', 'www-data'));
define('SYSTEMCTL', $env('ALIGN_SYSTEMCTL', 'systemctl'));   // "none" in tests
define('INSTALL_CMD', $env('ALIGN_INSTALL_CMD', ''));         // tests only: replaces install.sh --upgrade
define('BRANCH', preg_match('/^[A-Za-z0-9._\/-]{1,60}$/', $env('ALIGN_BRANCH', 'main')) ? $env('ALIGN_BRANCH', 'main') : 'main');
// Agent state lives in its own root-owned folder (not inside the www-data-owned data folder)
define('STATE', rtrim($env('ALIGN_AGENT_DIR', '/var/lib/mountaineer-align-agent'), '/'));
define('JOBS', STATE . '/jobs');
define('DOWNLOADS', DATA . '/downloads');
define('RESTORE', DATA . '/restore');
define('SAFETY', STATE . '/safety');
define('WORK', STATE . '/work');
define('REQ', RUN . '/requests');
define('KEYS', RUN . '/keys');
define('TIMERS', ['mountaineer-align-sync', 'mountaineer-align-itflow', 'mountaineer-align-mail']);
define('ID_RE', '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/');
define('KEY_RE', '/^AGE-SECRET-KEY-1[0-9A-Z]{58}$/');
define('TOKEN_RE', '/^[a-f0-9]{32}$/');
define('PARTS', ['manifest.json', 'db.sql.gz.age', 'uploads.tar.gz.age', 'app-key.age']);

require dirname(__DIR__) . '/src/System/Tar.php';

use Align\System\Tar;

final class JobFailed extends RuntimeException
{
}

// ------------------------------------------------------------------------------------------ utils

function conf(): array
{
    static $c = null;
    if ($c === null) {
        $c = require CONFIG;
    }
    return $c;
}

function q(string $s): string
{
    return escapeshellarg($s);
}

function asUser(string $cmd): string
{
    return RUNAS === 'root' ? $cmd : 'runuser -u ' . q(RUNAS) . ' -- ' . $cmd;
}

function owner(string $path, int $mode, bool $toRunAs = false, bool $groupOnly = false): void
{
    @chmod($path, $mode);
    if (posix_getuid() === 0 && RUNAS !== 'root') {
        if ($toRunAs && !$groupOnly) {
            @chown($path, RUNAS);
        }
        @chgrp($path, RUNAS);
    }
}

function ensureDirs(): void
{
    foreach ([[STATE, 0750, false], [JOBS, 0750, false], [DOWNLOADS, 0750, true], [RESTORE, 0750, true], [SAFETY, 0750, false], [WORK, 0700, null], [KEYS, 0700, null]] as [$d, $mode, $asRun]) {
        if (!is_dir($d)) {
            @mkdir($d, $mode, true);
        }
        if ($asRun === null) {
            @chmod($d, $mode);
            continue;
        }
        owner($d, $mode, $asRun);
    }
}

function writeJson(string $path, array $data, int $mode = 0640): void
{
    $tmp = $path . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    owner($tmp, $mode);
    rename($tmp, $path);
}

function readJson(string $path): ?array
{
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    return is_array($d) ? $d : null;
}

function now(): string
{
    return date('c');
}

function version(): string
{
    return trim((string) @file_get_contents(APP . '/VERSION')) ?: '0';
}

function rmTree(string $dir, bool $asRunAs = false): void
{
    if (is_dir($dir) && !is_link($dir)) {
        $c = 'rm -rf --one-file-system ' . q($dir);
        exec($asRunAs ? asUser($c) : $c);
    }
}

// ------------------------------------------------------------------------------------------- jobs

final class Job
{
    public array $s;
    public bool $echo = false;
    private $log;

    public function __construct(array $req)
    {
        $this->s = [
            'id' => $req['id'], 'action' => $req['action'], 'params' => $req['params'] ?? [],
            'user_id' => isset($req['user_id']) ? (int) $req['user_id'] : null, 'user' => mb_substr((string) ($req['user'] ?? 'system'), 0, 190),
            'state' => 'running', 'step' => 'Starting', 'created' => $req['created'] ?? now(), 'started' => now(), 'finished' => null,
            'message' => null, 'result' => [], 'version' => version(),
        ];
        $this->log = fopen(JOBS . '/' . $req['id'] . '.log', 'ab');
        owner(JOBS . '/' . $req['id'] . '.log', 0640);
        $this->save();
    }

    public function save(): void
    {
        writeJson(JOBS . '/' . $this->s['id'] . '.json', $this->s);
    }

    public function step(string $msg): void
    {
        $this->s['step'] = $msg;
        $this->s['step_at'] = now();
        $this->line('==> ' . $msg);
        $this->save();
        maintenanceMessage($msg);
    }

    public function line(string $l): void
    {
        $l = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $l) ?? $l;
        if ($this->log && ftell($this->log) < 5_000_000) {
            fwrite($this->log, '[' . date('H:i:s') . '] ' . $l . "\n");
        }
        if ($this->echo) {
            fwrite(STDOUT, $l . "\n");
        }
    }

    public function finish(bool $ok, string $message, array $result = []): void
    {
        $this->s['state'] = $ok ? 'succeeded' : 'failed';
        $this->s['finished'] = now();
        $this->s['message'] = $message;
        $this->s['result'] = $result + $this->s['result'];
        $this->s['step'] = $ok ? 'Done' : 'Failed';
        $this->line(($ok ? 'OK: ' : 'FAILED: ') . $message);
        $this->save();
        if ($this->log) {
            fclose($this->log);
            $this->log = null;
        }
    }

    /** Runs a shell command (bash, pipefail), logging its output. Returns [exit code, stdout]. */
    public function run(string $cmd, bool $capture = false, ?string $stdin = null, int $timeout = 3600): array
    {
        $p = proc_open(['bash', '-o', 'pipefail', '-c', $cmd], [0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
        if (!is_resource($p)) {
            throw new JobFailed('Could not start a program.');
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $buf = ['', ''];
        $start = time();
        while (true) {
            $r = [$pipes[1], $pipes[2]];
            $w = $e = null;
            if (@stream_select($r, $w, $e, 1) === false) {
                break;
            }
            foreach ($r as $pipe) {
                $chunk = fread($pipe, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $i = $pipe === $pipes[1] ? 0 : 1;
                if ($i === 0 && $capture) {
                    $out .= $chunk;
                    continue;
                }
                $buf[$i] .= $chunk;
                while (($nl = strpos($buf[$i], "\n")) !== false) {
                    $this->line(rtrim(substr($buf[$i], 0, $nl), "\r"));
                    $buf[$i] = substr($buf[$i], $nl + 1);
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($p);
                $this->line('Timed out.');
                break;
            }
        }
        foreach ($buf as $b) {
            if (trim($b) !== '') {
                $this->line(rtrim($b));
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        return [$code, $out];
    }

    public function must(string $cmd, string $error, bool $capture = false, ?string $stdin = null): string
    {
        [$code, $out] = $this->run($cmd, $capture, $stdin);
        if ($code !== 0) {
            throw new JobFailed($error);
        }
        return $out;
    }
}

// ----------------------------------------------------------------------------------- maintenance

function maintenanceOn(Job $job, string $message): void
{
    writeJson(STATE . '/maintenance.json', ['since' => now(), 'pid' => getmypid(), 'job' => $job->s['id'], 'action' => $job->s['action'], 'message' => $message], 0644);
}

function maintenanceMessage(string $msg): void
{
    $m = readJson(STATE . '/maintenance.json');
    if ($m && (int) $m['pid'] === getmypid()) {
        $m['step'] = $msg;
        $m['step_at'] = now();
        writeJson(STATE . '/maintenance.json', $m, 0644);
    }
}

function maintenanceOff(): void
{
    @unlink(STATE . '/maintenance.json');
}

function clearStaleMaintenance(): void
{
    $m = readJson(STATE . '/maintenance.json');
    if ($m && !file_exists('/proc/' . (int) $m['pid'])) {
        maintenanceOff();
    }
}

function timers(Job $job, bool $start): void
{
    if (SYSTEMCTL === 'none') {
        return;
    }
    $units = implode(' ', array_map(fn($t) => q("$t.timer"), TIMERS));
    if ($start) {
        $job->run(SYSTEMCTL . " start $units");
        return;
    }
    $job->run(SYSTEMCTL . " stop $units");
    // Let a running sync or mail run finish (up to 5 minutes) rather than kill it mid-write
    foreach (TIMERS as $t) {
        for ($i = 0; $i < 150 && $job->run(SYSTEMCTL . ' is-active --quiet ' . q("$t.service"))[0] === 0; $i++) {
            sleep(2);
        }
        $job->run(SYSTEMCTL . ' stop ' . q("$t.service"));
    }
}

// ----------------------------------------------------------------------------------------- backup

function recipients(): array
{
    $keys = [];
    foreach (@file(RECIPIENT, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        if (preg_match('/^age1[0-9a-z]{58}$/', trim($l))) {
            $keys[] = trim($l);
        }
    }
    return $keys;
}

/** A defaults file with the app's database login, readable by the user that runs the client. */
function dbDefaults(): string
{
    $c = conf()['db'];
    $f = RUN . '/db-' . bin2hex(random_bytes(6)) . '.cnf';
    $esc = fn($v) => '"' . addcslashes((string) $v, "\\\"") . '"';
    $old = umask(077);
    file_put_contents($f, "[client]\nuser=" . $esc($c['user']) . "\npassword=" . $esc($c['pass']) . "\nhost=" . $esc($c['host'] ?? 'localhost') . "\n");
    umask($old);
    owner($f, 0600, true);
    register_shutdown_function(fn() => @unlink($f));
    return $f;
}

function uploadDir(): string
{
    return rtrim((string) (conf()['upload_path'] ?? DATA . '/uploads'), '/');
}

/**
 * Builds a backup: a tar of manifest.json + age-encrypted parts. $extra = more age recipients
 * (a one-time key for automatic rollback). Returns the manifest.
 */
function makeBackup(Job $job, string $out, string $tag, array $extra = []): array
{
    $rec = recipients();
    if (!$rec) {
        throw new JobFailed('This server has no backup key (' . RECIPIENT . '). Run: sudo mountaineer-align-update');
    }
    $R = implode(' ', array_map(fn($k) => '-r ' . q($k), array_merge($rec, $extra)));
    $tmp = WORK . '/tmp-' . bin2hex(random_bytes(6));
    mkdir($tmp, 0700);
    try {
        $db = (string) conf()['db']['name'];
        $cnf = dbDefaults();
        $job->step('Backing up the database');
        $job->must('mariadb-dump --defaults-extra-file=' . q($cnf) . ' --single-transaction --quick --no-tablespaces --skip-dump-date ' . q($db)
            . " | gzip -6 | age $R -o " . q("$tmp/db.sql.gz.age"), 'The database backup failed.');
        $files = 0;
        $up = uploadDir();
        if (is_dir($up)) {
            $job->step('Backing up uploaded files');
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($up, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $files += $f->isFile() ? 1 : 0;
            }
            $job->must('tar -czf - -C ' . q(dirname($up)) . ' --transform ' . q('s#^' . basename($up) . '#uploads#') . ' ' . q(basename($up)) . " | age $R -o " . q("$tmp/uploads.tar.gz.age"), 'Backing up uploaded files failed.');
        }
        $job->must("age $R -o " . q("$tmp/app-key.age"), 'Could not save the encryption key.', false, (string) conf()['app_key']);
        $manifest = [
            'format' => 1, 'app' => 'Mountaineer Align', 'version' => version(), 'created' => now(), 'tag' => $tag,
            'host' => (string) (conf()['fqdn'] ?? gethostname()), 'database' => $db, 'uploads_files' => $files,
            'recipients' => $rec, 'bytes' => ['db' => filesize("$tmp/db.sql.gz.age"), 'uploads' => is_file("$tmp/uploads.tar.gz.age") ? filesize("$tmp/uploads.tar.gz.age") : 0],
        ];
        file_put_contents("$tmp/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $parts = ['manifest.json' => "$tmp/manifest.json", 'db.sql.gz.age' => "$tmp/db.sql.gz.age"];
        if (is_file("$tmp/uploads.tar.gz.age")) {
            $parts['uploads.tar.gz.age'] = "$tmp/uploads.tar.gz.age";
        }
        $parts['app-key.age'] = "$tmp/app-key.age";
        @unlink($out);
        Tar::write($out, $parts);
        return $manifest;
    } finally {
        rmTree($tmp);
    }
}

function nameFor(array $m): string
{
    return 'mountaineer-align-backup-' . preg_replace('/[^a-z0-9.-]+/', '-', strtolower($m['host'] ?: 'server')) . '-' . date('Ymd-His', strtotime($m['created'])) . '.tar';
}

// ------------------------------------------------------------------------------------- inspection

/** Shell snippet that outputs one member of the tar (or the whole file for a legacy backup). */
function slice(string $file, ?array $m): string
{
    return $m === null ? 'cat ' . q($file) : 'dd if=' . q($file) . ' iflag=skip_bytes,count_bytes skip=' . $m['offset'] . ' count=' . $m['size'] . ' bs=1M status=none';
}

function keyFile(string $id, string $key): string
{
    if (!preg_match(KEY_RE, $key)) {
        throw new JobFailed('That is not a backup key. It starts with AGE-SECRET-KEY-1 and is 74 characters long.');
    }
    $f = KEYS . "/$id.key";
    $old = umask(077);
    file_put_contents($f, $key . "\n");
    umask($old);
    $GLOBALS['keyFiles'][] = $f;
    return $f;
}

function ageError(string $log): string
{
    return 'This key can\'t open the backup. It was made with a different backup key (each server has its own; use the key saved when that server was installed).';
}

/** Opens and checks a backup without changing anything. Returns what's inside. */
function inspect(Job $job, string $file, string $keyFile): array
{
    $fh = fopen($file, 'rb');
    $head = (string) fread($fh, 21);
    fclose($fh);
    $info = ['kind' => 'bundle', 'manifest' => null, 'members' => [], 'tables' => 0, 'uploads_files' => null, 'app_key' => null];
    if ($head === 'age-encryption.org/v1') {
        $info['kind'] = 'legacy';
        $info['members'] = ['db.sql.gz.age' => null];
    } else {
        try {
            $members = Tar::members($file);
        } catch (RuntimeException $e) {
            throw new JobFailed($e->getMessage());
        }
        foreach (array_keys($members) as $n) {
            if (!in_array($n, PARTS, true)) {
                throw new JobFailed("This isn't a Mountaineer Align backup (unexpected part: $n).");
            }
        }
        if (!isset($members['manifest.json'], $members['db.sql.gz.age'])) {
            throw new JobFailed("This isn't a Mountaineer Align backup (no manifest or database).");
        }
        $m = json_decode(Tar::read($file, $members['manifest.json'], 65536), true);
        if (!is_array($m) || ($m['format'] ?? 0) !== 1 || !isset($m['version'], $m['created'])) {
            throw new JobFailed('The backup manifest is not readable.');
        }
        if (version_compare((string) $m['version'], version(), '>')) {
            throw new JobFailed("This backup was made by version {$m['version']}, which is newer than this server (" . version() . '). Update Align first, then restore.');
        }
        $info['manifest'] = $m;
        $info['members'] = $members;
    }
    $k = q($keyFile);
    $db = $info['members']['db.sql.gz.age'];
    $job->step('Checking the database backup');
    [$code, $out] = $job->run(slice($file, $db) . " | age -d -i $k | gunzip | grep -o '^CREATE TABLE `[a-z0-9_]*`'", true);
    if ($code !== 0) {
        [$c2] = $job->run(slice($file, $db) . " | age -d -i $k > /dev/null");
        throw new JobFailed($c2 !== 0 ? ageError('') : 'The database backup is damaged (it did not decompress cleanly).');
    }
    $tables = array_unique(array_filter(explode("\n", trim($out))));
    if (!in_array('CREATE TABLE `users`', $tables, true) || !in_array('CREATE TABLE `schema_migrations`', $tables, true)) {
        throw new JobFailed('The database in this backup is not a Mountaineer Align database.');
    }
    $info['tables'] = count($tables);
    if (isset($info['members']['uploads.tar.gz.age'])) {
        $job->step('Checking uploaded files');
        [$code, $out] = $job->run(slice($file, $info['members']['uploads.tar.gz.age']) . " | age -d -i $k | tar -tvzf - --quoting-style=escape", true);
        if ($code !== 0) {
            throw new JobFailed('The uploaded files in this backup are damaged.');
        }
        $n = 0;
        foreach (array_filter(explode("\n", $out)) as $line) {
            $f = preg_split('/\s+/', $line, 6);
            $name = $f[5] ?? '';
            if (!in_array($line[0], ['-', 'd'], true) || !preg_match('#^uploads(/|$)#', $name) || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                throw new JobFailed('The uploaded files in this backup contain an unsafe entry and were not restored.');
            }
            $n += $line[0] === '-' ? 1 : 0;
        }
        $info['uploads_files'] = $n;
    }
    if (isset($info['members']['app-key.age'])) {
        [$code, $out] = $job->run(slice($file, $info['members']['app-key.age']) . " | age -d -i $k", true);
        $key = trim($out);
        if ($code !== 0 || !preg_match('#^base64:[A-Za-z0-9+/]{43}=$#', $key)) {
            throw new JobFailed('The encryption key in this backup is not readable.');
        }
        $info['app_key'] = $key;
    }
    return $info;
}

function summary(array $info): array
{
    $m = $info['manifest'] ?? [];
    return [
        'kind' => $info['kind'], 'version' => $m['version'] ?? null, 'created' => $m['created'] ?? null, 'host' => $m['host'] ?? null,
        'tables' => $info['tables'], 'uploads_files' => $info['uploads_files'], 'has_uploads' => isset($info['members']['uploads.tar.gz.age']),
        'has_app_key' => $info['app_key'] !== null, 'app_key_differs' => $info['app_key'] !== null && $info['app_key'] !== (string) conf()['app_key'],
    ];
}

// ---------------------------------------------------------------------------------------- restore

function importDb(Job $job, string $file, ?array $member, string $keyFile): void
{
    $cnf = dbDefaults();
    $db = (string) conf()['db']['name'];
    $client = 'mariadb --defaults-extra-file=' . q($cnf) . (sandboxSupported() ? ' --sandbox' : '');
    $tables = trim($job->must(asUser("$client -N -e " . q('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()') . ' ' . q($db)), 'Could not read the current database.', true));
    if ($tables !== '') {
        $drop = 'SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS ' . implode(', ', array_map(fn($t) => '`' . str_replace('`', '``', $t) . '`', explode("\n", $tables))) . ';';
        $job->must(asUser("$client " . q($db)), 'Could not clear the current database.', false, $drop);
    }
    // Import as the app's own database user, in sandbox mode: a backup can't run shell commands,
    // read files or touch anything outside the Align database.
    $job->must(slice($file, $member) . ' | age -d -i ' . q($keyFile) . ' | gunzip | sed -E ' . q('/^\) ENGINE=/ s/ `?ENCRYPTED`?=YES//') . ' | ' . asUser("$client " . q($db)), 'Importing the database failed.');
}

function sandboxSupported(): bool
{
    static $s = null;
    return $s ??= str_contains((string) shell_exec('mariadb --help 2>/dev/null'), '--sandbox');
}

function setAppKey(string $key): void
{
    $txt = (string) file_get_contents(CONFIG);
    $new = preg_replace_callback("/('app_key'\s*=>\s*')[^']*(')/", fn($m) => $m[1] . $key . $m[2], $txt, 1, $n);
    if (!$n || $new === null) {
        throw new JobFailed('Could not update app_key in ' . CONFIG . '.');
    }
    writeConfig($new);
}

function writeConfig(string $txt): void
{
    $st = stat(CONFIG);
    $tmp = CONFIG . '.tmp' . getmypid();
    file_put_contents($tmp, $txt);
    chmod($tmp, $st['mode'] & 0777);
    if (posix_getuid() === 0) {
        chown($tmp, $st['uid']);
        chgrp($tmp, $st['gid']);
    }
    rename($tmp, CONFIG);
}

function audit(Job $job, string $event, string $detail): void
{
    $u = $job->s['user_id'] ? ' --user=' . (int) $job->s['user_id'] : '';
    $job->run(asUser('php ' . q(APP . '/bin/align') . ' system:audit --event=' . q($event) . ' --detail=' . q($detail) . $u));
}

function doRestore(Job $job, string $file, string $keyFile, bool $withDb, bool $withUploads): array
{
    $info = inspect($job, $file, $keyFile);
    $withUploads = $withUploads && isset($info['members']['uploads.tar.gz.age']);
    if (!$withDb && !$withUploads) {
        throw new JobFailed('Nothing to restore: choose the database, uploaded files or both.');
    }
    $job->s['result']['backup'] = summary($info);
    $up = uploadDir();
    $oldUp = $up . '.old-' . $job->s['id'];
    $stage = dirname($up) . '/.restore-' . $job->s['id'];
    $oldConfig = (string) file_get_contents(CONFIG);
    $safety = null;
    $rollKey = null;
    $changed = false;
    maintenanceOn($job, 'Restoring from a backup');
    try {
        timers($job, false);
        // Safety copy for automatic rollback: encrypted to this server's backup key and a one-time key
        $job->step('Making a safety copy of the current data');
        $rollKey = KEYS . '/' . $job->s['id'] . '-rollback.key';
        $job->must('age-keygen -o ' . q($rollKey) . ' 2>/dev/null', 'Could not create a rollback key.');
        $rollPub = trim($job->must('age-keygen -y ' . q($rollKey), 'Could not create a rollback key.', true));
        $safety = SAFETY . '/pre-restore-' . $job->s['id'] . '.tar';
        makeBackup($job, $safety, 'pre-restore', [$rollPub]);
        owner($safety, 0640);
        $changed = true;
        if ($withDb) {
            $job->step('Restoring the database');
            importDb($job, $file, $info['members']['db.sql.gz.age'], $keyFile);
            if ($info['app_key'] !== null && $info['app_key'] !== (string) conf()['app_key']) {
                $job->step('Using the encryption key from the backup (for saved API keys and two-factor secrets)');
                setAppKey($info['app_key']);
            }
        }
        if ($withUploads) {
            $job->step('Restoring uploaded files');
            // Everything inside the data folder is done as the web user, never as root
            rmTree($stage, true);
            $job->must(asUser('mkdir -m 750 ' . q($stage)), 'Could not prepare the uploaded files folder.');
            $job->must(slice($file, $info['members']['uploads.tar.gz.age']) . ' | age -d -i ' . q($keyFile) . ' | '
                . asUser('tar -xzf - --no-same-owner --no-same-permissions --no-overwrite-dir -C ' . q($stage)), 'Restoring uploaded files failed.');
            if (is_dir($up)) {
                $job->must(asUser('mv -T ' . q($up) . ' ' . q($oldUp)), 'Could not move the current uploaded files aside.');
            }
            $job->must(asUser('mv -T ' . q("$stage/uploads") . ' ' . q($up)), 'Could not put the restored files in place.');
        }
        $job->step('Updating the database structure');
        $job->must(asUser('php ' . q(APP . '/bin/align') . ' migrate'), 'Database migrations failed after the restore.');
        $job->step('Signing everyone out');
        clearSessions();
        rmTree($oldUp, true);
        rmTree($stage, true);
        @unlink($safety);
        $safety = null;
        $s = $job->s['result']['backup'];
        audit($job, 'backup.restored', 'Restored ' . implode(' and ', array_filter([$withDb ? 'database' : null, $withUploads ? 'uploaded files' : null]))
            . ' from a backup made ' . ($s['created'] ?? 'unknown') . ($s['version'] ? " (v{$s['version']})" : ''));
        return ['db' => $withDb, 'uploads' => $withUploads];
    } catch (Throwable $e) {
        if ($changed) {
            $job->step('Restore failed. Putting the previous data back');
            try {
                $sm = Tar::members($safety);
                importDb($job, $safety, $sm['db.sql.gz.age'], $rollKey);
                if (is_dir($oldUp)) {
                    rmTree($up, true);
                    $job->run(asUser('mv -T ' . q($oldUp) . ' ' . q($up)));
                }
                writeConfig($oldConfig);
                $job->run(asUser('php ' . q(APP . '/bin/align') . ' migrate'));
                $job->line('The previous data was put back.');
                $job->s['result']['rolled_back'] = true;
            } catch (Throwable $r) {
                $job->line('Rollback failed: ' . $r->getMessage());
                $job->s['result']['rolled_back'] = false;
                $job->s['result']['safety'] = $safety ? basename($safety) : null;
                $safety = null; // keep it for a manual restore
            }
        }
        rmTree($stage, true);
        throw $e;
    } finally {
        if ($safety && is_file($safety)) {
            @unlink($safety);
        }
        if ($rollKey) {
            @unlink($rollKey);
        }
        timers($job, true);
        maintenanceOff();
    }
}

function clearSessions(): void
{
    $dir = conf()['session_path'] ?? null;
    if ($dir && is_dir($dir)) {
        foreach (glob(rtrim($dir, '/') . '/sess_*') ?: [] as $f) {
            @unlink($f);
        }
    }
}

// ----------------------------------------------------------------------------------------- update

function check(?Job $job = null): array
{
    $prev = readJson(STATE . '/update.json') ?? [];
    $git = 'git -C ' . q(APP);
    $s = ['checked_at' => now(), 'current' => version(), 'latest' => $prev['latest'] ?? null, 'behind' => $prev['behind'] ?? 0, 'changes' => $prev['changes'] ?? [], 'error' => null, 'branch' => BRANCH];
    $run = function (string $cmd) use ($job): array {
        if ($job) {
            return $job->run($cmd, true, null, 120);
        }
        exec($cmd . ' 2>&1', $o, $c);
        return [$c, implode("\n", $o)];
    };
    [$c] = $run("timeout 90 $git fetch -q origin " . q(BRANCH));
    if ($c !== 0) {
        $s['error'] = 'Could not reach GitHub to check for updates. Check the server\'s internet access and the GitHub token in /etc/mountaineer-align/github-token.';
    } else {
        [, $latest] = $run("$git show " . q('origin/' . BRANCH . ':VERSION'));
        [, $behind] = $run("$git rev-list --count " . q('HEAD..origin/' . BRANCH));
        [, $log] = $run("$git log --no-merges -n 60 --format=%h%x1f%s%x1f%b%x1f%cI%x1e " . q('HEAD..origin/' . BRANCH));
        $s['latest'] = trim($latest) ?: null;
        $s['behind'] = (int) trim($behind);
        $s['changes'] = [];
        foreach (array_filter(explode("\x1e", $log), fn($x) => trim($x) !== '') as $entry) {
            [$sha, $subject, $body, $date] = array_pad(explode("\x1f", trim($entry)), 4, '');
            $body = trim(preg_replace('/^(Co-Authored-By|Claude-Session|Signed-off-by):.*$/mi', '', $body) ?? '');
            $s['changes'][] = ['sha' => $sha, 'subject' => $subject, 'body' => mb_substr($body, 0, 2000), 'date' => $date];
        }
    }
    $s['available'] = $s['latest'] !== null && (version_compare($s['latest'], $s['current'], '>') || $s['behind'] > 0);
    writeJson(STATE . '/update.json', $s);
    systemInfo();
    return $s;
}

function systemInfo(): void
{
    $legacy = ['count' => 0, 'bytes' => 0];
    foreach (glob(LEGACY . '/{db,config,uploads}-*', GLOB_BRACE) ?: [] as $f) {
        $legacy['count']++;
        $legacy['bytes'] += (int) filesize($f);
    }
    writeJson(STATE . '/system.json', [
        'updated_at' => now(), 'public_keys' => recipients(), 'private_key_on_server' => is_file(PRIVKEY_FILE),
        'legacy' => $legacy, 'disk_free' => @disk_free_space(DATA) ?: null, 'sandbox' => sandboxSupported(),
    ]);
}

function doUpdate(Job $job): array
{
    $from = version();
    $safety = SAFETY . '/pre-update-' . $job->s['id'] . '.tar';
    maintenanceOn($job, 'Updating Mountaineer Align');
    try {
        if (recipients()) {
            $job->step('Making a safety copy of the current data');
            makeBackup($job, $safety, 'pre-update');
            owner($safety, 0640);
        } else {
            $job->line('No backup key on this server yet, so no safety copy (the installer creates the key).');
        }
        $job->step('Downloading the latest version');
        $job->must('git -C ' . q(APP) . ' fetch -q origin ' . q(BRANCH), 'Could not download the update from GitHub.');
        $job->must('umask 022; git -C ' . q(APP) . ' reset -q --hard ' . q('origin/' . BRANCH), 'Could not apply the update.');
        $job->step('Installing ' . version() . ' (packages, database, services)');
        $job->must(INSTALL_CMD !== '' ? INSTALL_CMD : 'umask 022; ALIGN_BRANCH=' . q(BRANCH) . ' bash ' . q(APP . '/install.sh') . ' --upgrade',
            'The installer reported an error.' . (is_file($safety) ? ' A safety copy of the data was kept.' : ''));
        $to = version();
        @unlink($safety);
        $job->step('Checking for newer updates');
        check($job);
        audit($job, 'system.updated', "Updated from $from to $to");
        return ['from' => $from, 'to' => $to];
    } catch (Throwable $e) {
        if (is_file($safety)) {
            $job->s['result']['safety'] = basename($safety);
        }
        audit($job, 'system.update_failed', "Update from $from failed: " . $e->getMessage());
        throw $e;
    } finally {
        maintenanceOff();
    }
}

// ------------------------------------------------------------------------------------ dispatching

function process(array $req, bool $echo = false): Job
{
    $job = new Job($req);
    $job->echo = $echo;
    $p = $req['params'] ?? [];
    try {
        switch ($req['action']) {
            case 'check':
                $job->step('Checking GitHub for updates');
                $s = check($job);
                if ($s['error']) {
                    throw new JobFailed($s['error']);
                }
                $job->finish(true, $s['available'] ? "Version {$s['latest']} is available." : 'Mountaineer Align is up to date.', ['latest' => $s['latest']]);
                break;

            case 'update':
                $r = doUpdate($job);
                $job->finish(true, $r['from'] === $r['to'] ? "Updated (still {$r['to']})." : "Updated from {$r['from']} to {$r['to']}.", $r);
                break;

            case 'backup':
                $tmp = WORK . '/' . $req['id'] . '.tar';
                $m = makeBackup($job, $tmp, 'download');
                owner($tmp, 0640, true);
                $out = DOWNLOADS . '/' . $req['id'] . '.tar';
                if (!rename($tmp, $out)) {
                    @unlink($tmp);
                    throw new JobFailed('Could not hand the backup to the web server.');
                }
                $job->finish(true, 'Backup ready to download.', ['file' => basename($out), 'filename' => nameFor($m), 'size' => filesize($out), 'sha256' => hash_file('sha256', $out), 'uploads_files' => $m['uploads_files']]);
                break;

            case 'verify':
            case 'restore':
                $token = (string) ($p['token'] ?? '');
                $file = RESTORE . "/$token.tar";
                if (!preg_match(TOKEN_RE, $token) || !is_file($file) || is_link($file)) {
                    throw new JobFailed('The uploaded backup file is gone. Upload it again.');
                }
                $keyFile = keyFile($req['id'], (string) ($req['key'] ?? ''));
                if ($req['action'] === 'verify') {
                    $info = inspect($job, $file, $keyFile);
                    $job->finish(true, 'The backup opened with this key and every part checked out.', ['backup' => summary($info)]);
                } else {
                    $r = doRestore($job, $file, $keyFile, !empty($p['db']), !empty($p['uploads']));
                    @unlink($file);
                    $job->finish(true, 'Restore complete. Everyone has been signed out.', $r);
                }
                break;

            case 'keycheck':
                $keyFile = keyFile($req['id'], (string) ($req['key'] ?? ''));
                $pub = trim($job->must('age-keygen -y ' . q($keyFile), 'That key is not valid.', true));
                $match = in_array($pub, recipients(), true);
                $job->finish(true, $match ? 'This key matches this server\'s backup key. Backups from this server can be restored with it.' : 'This key does NOT match this server\'s backup key.', ['match' => $match, 'public_key' => $pub]);
                break;

            case 'purge_legacy':
                $n = 0;
                foreach (glob(LEGACY . '/{db,config,uploads}-*', GLOB_BRACE) ?: [] as $f) {
                    $n += @unlink($f) ? 1 : 0;
                }
                systemInfo();
                audit($job, 'backup.legacy_deleted', "Deleted $n old backup files from " . LEGACY);
                $job->finish(true, "Deleted $n old backup file" . ($n === 1 ? '' : 's') . ' from the server.');
                break;

            case 'delete_safety':
                $name = (string) ($p['name'] ?? '');
                if (!preg_match('/^pre-(update|restore)-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.tar$/', $name) || !is_file(SAFETY . "/$name")) {
                    throw new JobFailed('That safety copy no longer exists.');
                }
                unlink(SAFETY . "/$name");
                audit($job, 'backup.safety_deleted', $name);
                $job->finish(true, 'Safety copy deleted.');
                break;

            default:
                throw new JobFailed('Unknown request.');
        }
    } catch (Throwable $e) {
        $job->finish(false, $e instanceof JobFailed ? $e->getMessage() : 'Unexpected error: ' . $e->getMessage());
    } finally {
        foreach ($GLOBALS['keyFiles'] ?? [] as $f) {
            @unlink($f);
        }
        $GLOBALS['keyFiles'] = [];
    }
    return $job;
}

function cleanup(): void
{
    $old = fn(string $f, int $sec) => is_file($f) && filemtime($f) < time() - $sec;
    foreach (glob(DOWNLOADS . '/*') ?: [] as $f) {
        if ($old($f, 3600)) {
            @unlink($f); // never downloaded within an hour
        }
    }
    foreach (glob(RESTORE . '/*') ?: [] as $f) {
        if ($old($f, 86400)) {
            @unlink($f);
        }
    }
    foreach (glob(WORK . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 6 * 3600) {
            @unlink($f);
        } elseif (is_dir($f) && filemtime($f) < time() - 6 * 3600) {
            rmTree($f);
        }
    }
    foreach (glob(SAFETY . '/*.tar') ?: [] as $f) {
        if ($old($f, 14 * 86400)) {
            @unlink($f);
        }
    }
    $jobs = glob(JOBS . '/*.json') ?: [];
    rsort($jobs);
    foreach (array_slice($jobs, 100) as $f) {
        @unlink($f);
        @unlink(substr($f, 0, -5) . '.log');
    }
}

function newId(): string
{
    return date('Ymd-His') . '-' . bin2hex(random_bytes(3));
}

function readSecret(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    shell_exec('stty -echo 2>/dev/null');
    $v = trim((string) fgets(STDIN));
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
    return $v;
}

// ------------------------------------------------------------------------------------------- main

$cmd = $argv[1] ?? 'run';
if (posix_getuid() !== 0 && getenv('ALIGN_AGENT_TEST') !== '1') {
    fwrite(STDERR, "Run as root (sudo).\n");
    exit(1);
}
// 022: code checked out by an update must stay readable by the web server. Files that hold
// secrets are created under umask 077 or chmod'ed explicitly.
umask(022);
date_default_timezone_set((string) (conf()['timezone'] ?? 'UTC'));
ensureDirs();
$lock = fopen(STATE . '/agent.lock', 'c');
flock($lock, LOCK_EX);
clearStaleMaintenance();

switch ($cmd) {
    case 'run':
        // Web requests. Each file is read and deleted straight away (restore keys never stay on disk).
        for ($i = 0; $i < 50; $i++) {
            $files = glob(REQ . '/*.json') ?: [];
            sort($files);
            if (!$files) {
                break;
            }
            foreach ($files as $f) {
                $raw = is_link($f) || !is_file($f) || filesize($f) > 65536 ? '' : (string) @file_get_contents($f);
                @unlink($f);
                $req = json_decode($raw, true);
                if (!is_array($req) || !preg_match(ID_RE, (string) ($req['id'] ?? '')) || basename($f) !== $req['id'] . '.json' || is_file(JOBS . "/{$req['id']}.json")
                    || !in_array($req['action'] ?? '', ['check', 'update', 'backup', 'verify', 'restore', 'keycheck', 'purge_legacy', 'delete_safety'], true)) {
                    fwrite(STDERR, 'Ignored invalid request ' . basename($f) . "\n");
                    continue;
                }
                process($req);
            }
        }
        cleanup();
        break;

    case 'check':
        $s = check();
        echo $s['error'] ?? ($s['available'] ? "Update available: {$s['current']} -> {$s['latest']} ({$s['behind']} changes)\n" : "Up to date ({$s['current']})\n");
        break;

    case 'nightly':
        cleanup();
        foreach (['audit:verify', 'audit:prune'] as $c) {
            passthru(asUser('php ' . q(APP . '/bin/align') . ' ' . $c), $code);
            if ($code !== 0 && $c === 'audit:verify') {
                fwrite(STDERR, "ALERT: audit log verification failed\n");
            }
        }
        check();
        break;

    case 'update-cli':
        $job = process(['id' => newId(), 'action' => 'update', 'user' => 'command line (' . (getenv('SUDO_USER') ?: 'root') . ')'], true);
        exit($job->s['state'] === 'succeeded' ? 0 : 1);

    case 'restore-cli':
        $src = $argv[2] ?? '';
        if ($src === '' || !is_file($src)) {
            fwrite(STDERR, "Usage: sudo mountaineer-align-restore BACKUP-FILE [--db-only|--uploads-only]\n");
            exit(1);
        }
        $token = bin2hex(random_bytes(16));
        copy($src, RESTORE . "/$token.tar");
        $key = readSecret('Backup key (AGE-SECRET-KEY-1...): ');
        fwrite(STDOUT, "This replaces the current data and signs everyone out. Type RESTORE to continue: ");
        if (trim((string) fgets(STDIN)) !== 'RESTORE') {
            @unlink(RESTORE . "/$token.tar");
            exit(1);
        }
        $only = $argv[3] ?? '';
        $job = process(['id' => newId(), 'action' => 'restore', 'user' => 'command line (' . (getenv('SUDO_USER') ?: 'root') . ')', 'key' => $key,
            'params' => ['token' => $token, 'db' => $only !== '--uploads-only', 'uploads' => $only !== '--db-only']], true);
        @unlink(RESTORE . "/$token.tar");
        exit($job->s['state'] === 'succeeded' ? 0 : 1);

    default:
        fwrite(STDERR, "Usage: agent.php run|check|nightly|update-cli|restore-cli FILE\n");
        exit(1);
}
