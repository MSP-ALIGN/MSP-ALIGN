#!/usr/bin/env php
<?php
// MSP Align. Copyright (C) 2026 Mountaineer IT Inc. and MSP Align contributors
// SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
/**
 * MSP Align system agent. Runs as root, started by systemd:
 *   msp-align-agent.path/.service   when the web app drops a request in /run/msp-align/requests
 *   msp-align-update-check.timer    every 6 hours (check)
 *   msp-align-nightly.timer         nightly clean-up and audit log checks
 * Before 1.35 the folders and units were named mountaineer-align; a server that hasn't moved yet still works.
 *   sudo msp-align-update           update from the command line
 *   sudo msp-align-restore FILE     restore from the command line
 *
 * The web server (www-data) runs with proc_open & co disabled, so it can't run programs. It asks this
 * agent for a fixed set of jobs: check for updates, update, make a backup for download, test or
 * restore an uploaded backup, check a backup key. Requests are data only: every value is validated
 * here and nothing from a request is ever passed to a shell unquoted.
 *
 * Backups are never kept on the server. A backup is built, encrypted to the backup public key,
 * downloaded once through the browser and deleted. Only encrypted data touches the disk.
 *
 * SECURITY: the attacker this file defends against is code running as the web user (www-data). It can write the
 * request folder, the data folder (uploads, downloads, restore, sessions) and the database, and it can read the job
 * files and state here (group www-data). It can't write this agent's own folder (STATE), the run and keys folders,
 * the config folder or the code. So: root never opens, follows, extracts or deletes anything in the data folder itself
 * (webDir/toWeb/fromWeb, and every tar/find/rm there runs as the web user, 1.45); every value from a request is
 * validated; every value in a command goes through q(); a file a root program reads options from is never one the web
 * user can change; and what GitHub or a backup file returns is untrusted data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$env = static fn(string $k, string $d): string => (($v = getenv($k)) !== false && $v !== '') ? $v : $d;
// The msp-align path, or the mountaineer-align one on a server that hasn't moved yet (see scripts/move-install.sh)
$path = static fn(string $new, string $old): string => file_exists($new) || !file_exists($old) ? $new : $old;
define('APP', $env('ALIGN_APP_DIR', dirname(__DIR__)));
define('CONFIG', $env('ALIGN_CONFIG', $path('/etc/msp-align/config.php', '/etc/mountaineer-align/config.php')));
define('DATA', rtrim($env('ALIGN_DATA_DIR', $path('/var/lib/msp-align', '/var/lib/mountaineer-align')), '/'));
define('RUN', rtrim($env('ALIGN_RUN_DIR', $path('/run/msp-align', '/run/mountaineer-align')), '/'));
define('RECIPIENT', $env('ALIGN_RECIPIENT', $path('/etc/msp-align/backup-recipient.txt', '/etc/mountaineer-align/backup-recipient.txt')));
define('LEGACY', $env('ALIGN_LEGACY_BACKUPS', '/var/backups/mountaineer-align'));   // old nightly backups keep their folder name
define('PRIVKEY_FILE', $env('ALIGN_PRIVKEY_FILE', $path('/root/msp-align-backup-key.txt', '/root/mountaineer-align-backup-key.txt')));
define('RUNAS', $env('ALIGN_RUNAS', 'www-data'));
define('SYSTEMCTL', $env('ALIGN_SYSTEMCTL', 'systemctl'));   // "none" in tests
define('INSTALL_CMD', $env('ALIGN_INSTALL_CMD', ''));         // tests only: replaces install.sh --upgrade
// Which branch updates come from: ALIGN_BRANCH, else 'update_branch' in config.php (a test server: 'develop'), else main
$branch = $env('ALIGN_BRANCH', (static function (): string {
    $c = is_readable(CONFIG) ? @include CONFIG : null;
    return is_array($c) && is_string($c['update_branch'] ?? null) && $c['update_branch'] !== '' ? $c['update_branch'] : 'main';
})());
define('BRANCH', preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,59}$/', $branch) && !str_contains($branch, '..') ? $branch : 'main');
// Optional: a small file listing the latest version, asked before GitHub (config 'update_check_url', e.g.
// https://mspalign.org/updates, which serves main.json). Unset: servers ask GitHub directly, as always.
define('CHECK_URL', $env('ALIGN_UPDATE_CHECK_URL', (static function (): string {
    $c = is_readable(CONFIG) ? @include CONFIG : null;
    return is_array($c) && is_string($c['update_check_url'] ?? null) ? rtrim($c['update_check_url'], '/') : '';
})()));
// Docker image (1.44): the code comes with the image, so updates mean pulling a new image; the check asks GitHub over HTTPS
define('DOCKER', $env('ALIGN_DOCKER', '') === '1' || (static function (): bool {
    $c = is_readable(CONFIG) ? @include CONFIG : null;
    return is_array($c) && ($c['install_type'] ?? '') === 'docker';
})());
define('REPO', preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $r = $env('ALIGN_REPO', 'MSP-ALIGN/MSP-ALIGN')) ? $r : 'MSP-ALIGN/MSP-ALIGN');
// Where the Docker check asks (tests point these at a local stand-in; only https or this machine are accepted)
$gh = static fn(string $k, string $d): string => preg_match('#^(https://|http://127\.0\.0\.1[:/])[^?\#\s]*$#', $v = rtrim($env($k, $d), '/')) ? $v : $d;
define('GITHUB_API', $gh('ALIGN_GITHUB_API', 'https://api.github.com'));
define('GITHUB_RAW', $gh('ALIGN_GITHUB_RAW', 'https://raw.githubusercontent.com'));
/**
 * Signed releases (2.0): with a key in deploy/release-signers (the copy installed here) and the main branch, updates
 * come only from release tags signed with that key (scripts/release.sh checks them). A test server following another
 * branch (update_branch), a fork or a development copy without a key follows its branch, unsigned, as before.
 * ALIGN_RELEASE_SIGNERS (tests only, with ALIGN_AGENT_TEST=1): another signers file, or "none".
 *
 * Returns the signers file when it holds at least one key line, else null.
 * SECURITY: the file is the installed one (root-owned code), never one from a download, so a release can only add a key
 * when it is signed by a key trusted now. The test override is ignored unless ALIGN_AGENT_TEST=1 (only root sets the
 * environment of a root service).
 */
function releaseSigners(): ?string
{
    $f = getenv('ALIGN_AGENT_TEST') === '1' ? getenv('ALIGN_RELEASE_SIGNERS') : false;
    if ($f === 'none') {
        return null;
    }
    $f = is_string($f) && $f !== '' ? $f : APP . '/deploy/release-signers';
    foreach (is_file($f) ? (file($f) ?: []) : [] as $l) {
        if (trim($l) !== '' && !str_starts_with(ltrim($l), '#')) {
            return $f;
        }
    }
    return null;
}

/** Signed releases apply: the main branch and a release key installed (see releaseSigners). */
function signedMode(): bool
{
    return BRANCH === 'main' && releaseSigners() !== null;
}

/** The release keys' fingerprints, for the page ("SHA256:..."). Display only: release.sh does the checking. */
function signerPrints(): array
{
    $f = releaseSigners();
    $out = [];
    foreach ($f ? (file($f) ?: []) : [] as $l) {
        // OpenSSH's fingerprint: SHA256 of the key blob, base64 without padding
        if (!str_starts_with(ltrim($l), '#') && preg_match('/\b((?:ssh|ecdsa|sk)-[a-z0-9@.-]+)\s+([A-Za-z0-9+\/]+={0,2})/', $l, $m) && ($blob = base64_decode($m[2], true))) {
            $out[] = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        }
    }
    return $out;
}

/**
 * The newest signed release newer than $current ('0': any), as [tag, commit, unsigned newer tags, error].
 * The tag is checked by scripts/release.sh from the installed code, against the installed signers file.
 * SECURITY: the caller holds the agent lock and fetched the tags. Only a line that is exactly "vX.Y.Z <hex commit>" on
 * exit 0 is taken; the caller installs that commit, not the tag name (a later fetch could move the tag).
 * @param callable(string): array{0: int, 1: string} $run runs a command, returns [exit code, output]
 * @return array{0: ?string, 1: ?string, 2: string[], 3: ?string}
 */
function latestSigned(callable $run, string $current): array
{
    [$c, $out] = $run('bash ' . q(APP . '/scripts/release.sh') . ' ' . q(APP) . ' ' . q((string) releaseSigners()) . ' ' . q($current) . ' 2>&1');
    $unsigned = [];
    $tag = $commit = null;
    foreach (preg_split('/\R/', (string) $out) ?: [] as $l) {
        if (preg_match('/^UNSIGNED (v\d+\.\d+\.\d+)$/', trim($l), $m)) {
            $unsigned[] = $m[1];
        } elseif ($c === 0 && preg_match('/^(v\d+\.\d+\.\d+) ([0-9a-f]{40,64})$/', trim($l), $m)) {
            [, $tag, $commit] = $m;
        }
    }
    $error = match ($c) {
        0, 3 => null,
        4 => 'ssh-keygen is missing, so release signatures can\'t be checked. Run: sudo apt install openssh-client',
        default => 'The release signatures could not be checked (' . mb_substr(trim((string) $out), 0, 200) . ').',
    };
    return [$tag, $commit, $error ? [] : $unsigned, $error];
}

/**
 * Is the code here a signed release (HEAD is a checked release tag's commit, with a matching VERSION)?
 * Only call in signed mode: release.sh exits 2 without a signers file, which reads as "not signed".
 */
function headSigned(): bool
{
    exec('bash ' . q(APP . '/scripts/release.sh') . ' ' . q(APP) . ' ' . q((string) releaseSigners()) . ' --head 2>/dev/null', $o, $c);
    return $c === 0;
}

const DOCKER_UPDATE = 'This server runs in Docker, so it updates by pulling the new image. On the Docker host, in the MSP Align folder: docker compose pull && docker compose up -d';
// Agent state lives in its own root-owned folder (not inside the www-data-owned data folder)
define('STATE', rtrim($env('ALIGN_AGENT_DIR', $path('/var/lib/msp-align-agent', '/var/lib/mountaineer-align-agent')), '/'));
define('JOBS', STATE . '/jobs');
define('DOWNLOADS', DATA . '/downloads');
define('RESTORE', DATA . '/restore');
define('SAFETY', STATE . '/safety');
define('WORK', STATE . '/work');
define('REQ', RUN . '/requests');
define('KEYS', RUN . '/keys');
define('UNIT', $path('/etc/systemd/system/msp-align-sync.timer', '/etc/systemd/system/mountaineer-align-sync.timer') === '/etc/systemd/system/msp-align-sync.timer' ? 'msp-align' : 'mountaineer-align');
define('TIMERS', [UNIT . '-sync', UNIT . '-psa', UNIT . '-mail']);   // -psa was -itflow before 1.34
define('ID_RE', '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/');
define('KEY_RE', '/^AGE-SECRET-KEY-1[0-9A-Z]{58}$/');
define('TOKEN_RE', '/^[a-f0-9]{32}$/');
define('PARTS', ['manifest.json', 'db.sql.gz.age', 'uploads.tar.gz.age', 'app-key.age']);

require dirname(__DIR__) . '/src/System/Tar.php';

use Align\System\Tar;

/** A job step failed: its message is shown to the admin as is, so it must never carry a secret. */
final class JobFailed extends RuntimeException
{
}

// ------------------------------------------------------------------------------------------ utils

/**
 * The server config (config.php), loaded once.
 * SECURITY: config.php is PHP run as root here; it is root-owned and only group-readable by the web user (install.sh,
 * docker/entrypoint.sh), so the web user can't change what root runs.
 */
function conf(): array
{
    static $c = null;
    if ($c === null) {
        $c = require CONFIG;
    }
    return $c;
}

/** Quotes one value for the shell. Every value put in a command string goes through this. */
function q(string $s): string
{
    return escapeshellarg($s);
}

/**
 * Wraps a command (already quoted with q()) so it runs as the web user. Used for every step that touches the data
 * folder, the app's database login or bin/align, so nothing the web user planted can act with root's rights.
 */
function asUser(string $cmd): string
{
    return RUNAS === 'root' ? $cmd : 'runuser -u ' . q(RUNAS) . ' -- ' . $cmd;
}

/**
 * Sets a mode and hands the group (and with $toRunAs the owner) to the web user.
 * SECURITY: only for paths in root's own folders (STATE, JOBS, SAFETY, RUN): chmod/chown follow symlinks, so never
 * call it on anything inside the data folder (see webDir).
 */
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

/**
 * Creates the agent's folders with their modes: STATE, JOBS and SAFETY readable by the web user's group, WORK and
 * KEYS root only. The data folder's downloads and restore folders are made by the web user itself (webDir).
 */
function ensureDirs(): void
{
    foreach ([[STATE, 0750, false], [JOBS, 0750, false], [SAFETY, 0750, false], [WORK, 0700, null], [KEYS, 0700, null]] as [$d, $mode, $asRun]) {
        if (!is_dir($d)) {
            @mkdir($d, $mode, true);
        }
        if ($asRun === null) {
            @chmod($d, $mode);
            continue;
        }
        owner($d, $mode, $asRun);
    }
    webDir(DOWNLOADS, 0750);
    webDir(RESTORE, 0750);
}

/**
 * The web user owns the data folder, so it could swap anything in it for a symlink: root never makes, changes
 * ownership of, writes, reads or deletes anything in there itself; it runs those steps as the web user (1.45).
 */
function webDir(string $d, int $mode): void
{
    exec(asUser('install -d -m ' . sprintf('%o', $mode) . ' ' . q($d)) . ' 2>/dev/null');
}

/**
 * Copies a file into the web user's data folder, written by the web user (see webDir).
 * SECURITY: root only opens $src (its own WORK folder) for reading; $dest is opened by the web user.
 */
function toWeb(Job $job, string $src, string $dest, string $error): void
{
    $job->must(asUser('sh -c ' . q('umask 027 && cat > "$1.part" && mv -f "$1.part" "$1"') . ' sh ' . q($dest)) . ' < ' . q($src), $error);
}

/**
 * Copies a file out of the web user's data folder into the agent's own work folder, read as the web user.
 * SECURITY: $src is opened by the web user, so a symlink there reaches only what that user can read; root writes
 * $dest in WORK (root only, 0700), created 0600.
 */
function fromWeb(Job $job, string $src, string $dest, string $error): void
{
    $old = umask(077);
    $job->must(asUser('cat -- ' . q($src)) . ' > ' . q($dest), $error);
    umask($old);
}

/** Writes agent state atomically (temp file and rename), group-readable by the web user. Only for root's own folders. */
function writeJson(string $path, array $data, int $mode = 0640): void
{
    $tmp = $path . '.tmp' . getmypid();
    // invalid UTF-8 (from git output, say) is replaced: otherwise json_encode gives false and the file ends up empty
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    owner($tmp, $mode);
    rename($tmp, $path);
}

/**
 * Reads a JSON state file, or null.
 * SECURITY: only for files root wrote in its own folders (STATE, RUN); never for anything the web user writes.
 */
function readJson(string $path): ?array
{
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    return is_array($d) ? $d : null;
}

/** The current time, ISO 8601, in the app's timezone (set from config.php at start). */
function now(): string
{
    return date('c');
}

/** The installed code's version (VERSION), or '0' when unreadable. */
function version(): string
{
    return trim((string) @file_get_contents(APP . '/VERSION')) ?: '0';
}

/**
 * Deletes a folder tree. $asRunAs: as the web user, for anything in the data folder; root only deletes in its own
 * folders. A symlink is never followed (checked here, and rm doesn't follow links inside the tree).
 */
function rmTree(string $dir, bool $asRunAs = false): void
{
    if (is_dir($dir) && !is_link($dir)) {
        $c = 'rm -rf --one-file-system ' . q($dir);
        exec($asRunAs ? asUser($c) : $c);
    }
}

// ------------------------------------------------------------------------------------------- jobs

/**
 * One job: its state file (JOBS/<id>.json) and log (JOBS/<id>.log), both readable by the web user's group so the page
 * can show progress. SECURITY: nothing secret goes into either: the restore key from the request is never copied into
 * the state, commands carry key file paths, not keys, and messages are written for admins.
 */
final class Job
{
    public array $s;
    public bool $echo = false;
    private $log;

    /**
     * Starts the state from a request. The caller checked id (ID_RE, not seen before) and action (the fixed list);
     * the other fields are only displayed (the web page escapes them) or cast.
     */
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

    /** Writes the job's state file (atomically, see writeJson). */
    public function save(): void
    {
        writeJson(JOBS . '/' . $this->s['id'] . '.json', $this->s);
    }

    /** Starts a named step: shown on the page and the maintenance page, and used for the progress bar (Agent::STAGES). */
    public function step(string $msg): void
    {
        $this->s['step'] = $msg;
        $this->s['step_at'] = now();
        $this->line('==> ' . $msg);
        $this->save();
        maintenanceMessage($msg);
    }

    /** Adds a line to the log (terminal colour codes removed; the log stops growing at about 5 MB). */
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

    /** Marks the job done (succeeded or failed) with the message shown to the admin, and closes the log. */
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

    /**
     * Runs a shell command (bash, pipefail), logging its output. Returns [exit code, stdout].
     * $capture: stdout is returned instead of logged (stderr is always logged). $stdin: fed to the command (keeps a
     * secret such as app_key off the command line). Stopped after $timeout seconds.
     * SECURITY: $cmd is a shell string: the caller quotes every value in it with q().
     */
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

    /** run(), failing the job with $error (shown to the admin) when the command fails. Returns the captured stdout. */
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

/**
 * Turns on maintenance mode: the web app shows a "being updated/restored" page to everyone and scheduled app jobs
 * skip their runs while the file exists (Agent::maintenance). World-readable on purpose: no secrets in it.
 */
function maintenanceOn(Job $job, string $message): void
{
    writeJson(STATE . '/maintenance.json', ['since' => now(), 'pid' => getmypid(), 'job' => $job->s['id'], 'action' => $job->s['action'], 'message' => $message], 0644);
}

/** Shows the current step on the maintenance page, if this process turned maintenance on. */
function maintenanceMessage(string $msg): void
{
    $m = readJson(STATE . '/maintenance.json');
    if ($m && (int) $m['pid'] === getmypid()) {
        $m['step'] = $msg;
        $m['step_at'] = now();
        writeJson(STATE . '/maintenance.json', $m, 0644);
    }
}

/** Ends maintenance mode. */
function maintenanceOff(): void
{
    @unlink(STATE . '/maintenance.json');
}

/** Removes maintenance mode left by an agent that died (its process is gone). Runs under the agent lock. */
function clearStaleMaintenance(): void
{
    $m = readJson(STATE . '/maintenance.json');
    if ($m && !file_exists('/proc/' . (int) $m['pid'])) {
        maintenanceOff();
    }
}

/**
 * Stops ($start false) or starts the app's scheduled jobs (sync, PSA poll, mail) around a restore, so nothing writes
 * to the database while it is replaced. Unit names are fixed constants, quoted anyway.
 */
function timers(Job $job, bool $start): void
{
    if (DOCKER && !$start) {
        // Docker: no timers to stop. Maintenance mode (already on) stops new runs; wait for email, sync or the PSA
        // check to finish if one is mid-run (up to 5 minutes), as systemd's services are waited for below.
        for ($i = 0; $i < 150; $i++) {
            $busy = array_intersect(readJson(RUN . '/scheduler-running.json') ?? [], ['mail', 'psa', 'sync']);
            if (!$busy) {
                break;
            }
            if ($i === 0) {
                $job->line('Waiting for ' . implode(', ', $busy) . ' to finish');
            }
            sleep(2);
        }
    }
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

/**
 * The backup public keys (age1...) from the recipient file; lines that aren't one are skipped.
 * SECURITY: the file is root-owned in the config folder; each key is checked against age's format before it reaches
 * a command line (and quoted).
 */
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

/**
 * A defaults file with the app's database login, readable by the user that runs the client. Removed when the agent
 * exits normally; one left by a killed agent is removed by the next 'run' (sweepLeftovers()).
 * SECURITY: it stays root's (0640, group of the web user): the web user may read it (it runs the clients, and has the
 * same login in config.php) but never change it. Before 2.2.1 it was handed to the web user, which could then add client
 * options (plugin-dir with default-auth loads a library; result-file writes a file) to the mariadb-dump that ran as
 * root. It is written in RUN, where the web user can't create or swap files.
 */
function dbDefaults(): string
{
    $c = conf()['db'];
    $f = RUN . '/db-' . bin2hex(random_bytes(6)) . '.cnf';
    $esc = fn($v) => '"' . addcslashes((string) $v, "\\\"") . '"';
    $old = umask(077);
    file_put_contents($f, "[client]\nuser=" . $esc($c['user']) . "\npassword=" . $esc($c['pass']) . "\nhost=" . $esc($c['host'] ?? 'localhost') . "\n");
    umask($old);
    @chmod($f, 0640);
    if (posix_getuid() === 0 && RUNAS !== 'root' && ($pw = posix_getpwnam(RUNAS))) {
        @chgrp($f, $pw['gid']);   // the web user's own group, by number (its name can differ from the user's)
    }
    register_shutdown_function(fn() => @unlink($f));
    return $f;
}

/** The uploads folder from config.php (inside the data folder, owned by the web user: root only stats it). */
function uploadDir(): string
{
    return rtrim((string) (conf()['upload_path'] ?? DATA . '/uploads'), '/');
}

/**
 * Builds a backup: a tar of manifest.json + age-encrypted parts. $extra = more age recipients
 * (a one-time key for automatic rollback). Returns the manifest.
 * SECURITY: the database dump, the file count and the uploads tar run as the web user; root only encrypts their
 * output (age, to the server's public keys) into WORK and writes the tar there. $out must be in root's own folders.
 * Unencrypted data never touches the disk; app_key goes to age on stdin, never on a command line.
 */
function makeBackup(Job $job, string $out, string $tag, array $extra = []): array
{
    $rec = recipients();
    if (!$rec) {
        throw new JobFailed('This server has no backup key (' . RECIPIENT . '). ' . (DOCKER ? 'Restart the container to create one.' : 'Run: sudo msp-align-update'));
    }
    $R = implode(' ', array_map(fn($k) => '-r ' . q($k), array_merge($rec, $extra)));
    $tmp = WORK . '/tmp-' . bin2hex(random_bytes(6));
    mkdir($tmp, 0700);
    try {
        $db = (string) conf()['db']['name'];
        $cnf = dbDefaults();
        $job->step('Backing up the database');
        // As the web user, like every other database client here (2.2.1): the dump needs only the app's own login
        $job->must(asUser('mariadb-dump --defaults-extra-file=' . q($cnf) . ' --single-transaction --quick --no-tablespaces --skip-dump-date ' . q($db))
            . " | gzip -6 | age $R -o " . q("$tmp/db.sql.gz.age"), 'The database backup failed.');
        $files = 0;
        $up = uploadDir();
        if (is_dir($up) && !is_link($up)) {
            $job->step('Backing up uploaded files');
            // counted and read as the web user (see webDir); a file it can't read fails the backup, and says so
            $files = (int) trim($job->must(asUser('find ' . q($up) . ' -type f') . ' | wc -l', 'Could not list the uploaded files.', true));
            $job->must(asUser('tar -czf - -C ' . q(dirname($up)) . ' --transform ' . q('s#^' . basename($up) . '#uploads#') . ' ' . q(basename($up))) . " | age $R -o " . q("$tmp/uploads.tar.gz.age"), 'Backing up uploaded files failed (a file the web server can\'t read? Run sudo msp-align-update to fix permissions).');
        }
        $job->must("age $R -o " . q("$tmp/app-key.age"), 'Could not save the encryption key.', false, (string) conf()['app_key']);
        $manifest = [
            'format' => 1, 'app' => 'MSP-ALIGN', 'version' => version(), 'created' => now(), 'tag' => $tag,
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

/** The download's file name, from this server's own manifest (host reduced to [a-z0-9.-]). */
function nameFor(array $m): string
{
    return 'msp-align-backup-' . preg_replace('/[^a-z0-9.-]+/', '-', strtolower($m['host'] ?: 'server')) . '-' . date('Ymd-His', strtotime($m['created'])) . '.tar';
}

// ------------------------------------------------------------------------------------- inspection

/**
 * Shell snippet that outputs one member of the tar (or the whole file for a legacy backup).
 * $m comes from Tar::members() (offset and size are ints it computed), $file is root's copy in WORK or SAFETY.
 */
function slice(string $file, ?array $m): string
{
    return $m === null ? 'cat ' . q($file) : 'dd if=' . q($file) . ' iflag=skip_bytes,count_bytes skip=' . (int) $m['offset'] . ' count=' . (int) $m['size'] . ' bs=1M status=none';
}

/**
 * Writes a pasted backup private key to a root-only file in KEYS (RAM) for age -i, and returns its path. Deleted at the
 * end of the job (process()). SECURITY: the key is checked against age's format first, so nothing else is written,
 * and it never appears on a command line or in the log.
 */
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

/** The message for a backup the key can't open. $log is unused: age's own text isn't shown. */
function ageError(string $log): string
{
    return 'This key can\'t open the backup. It was made with a different backup key (each server has its own; use the key saved when that server was installed).';
}

/**
 * Opens and checks a backup without changing anything. Returns what's inside.
 * SECURITY: $file is root's own copy in WORK (the upload can't change under it). Everything in the backup is untrusted:
 * anyone with the server's public key can make one (a restore trusting any backup that opens with the key is an
 * accepted risk), so the parts are limited to the known names, the manifest is size-limited and only its version is
 * acted on, the database must look like an MSP Align one, the uploaded files may only be plain files and folders under
 * uploads/, and the app key must have app_key's format.
 */
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
                throw new JobFailed("This isn't an MSP Align backup (unexpected part: $n).");
            }
        }
        if (!isset($members['manifest.json'], $members['db.sql.gz.age'])) {
            throw new JobFailed("This isn't an MSP Align backup (no manifest or database).");
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
    // Root only decrypts (the key file is root's); the backup's own bytes are decompressed and parsed by the web user
    [$code, $out] = $job->run(slice($file, $db) . " | age -d -i $k | " . asUser('gunzip') . " | grep -o '^CREATE TABLE `[a-z0-9_]*`'", true);
    if ($code !== 0) {
        [$c2] = $job->run(slice($file, $db) . " | age -d -i $k > /dev/null");
        throw new JobFailed($c2 !== 0 ? ageError('') : 'The database backup is damaged (it did not decompress cleanly).');
    }
    $tables = array_unique(array_filter(explode("\n", trim($out))));
    if (!in_array('CREATE TABLE `users`', $tables, true) || !in_array('CREATE TABLE `schema_migrations`', $tables, true)) {
        throw new JobFailed('The database in this backup is not an MSP Align database.');
    }
    $info['tables'] = count($tables);
    if (isset($info['members']['uploads.tar.gz.age'])) {
        $job->step('Checking uploaded files');
        // --numeric-owner: owner and group print as numbers. With names, an owner name with spaces in it (the archive
        // sets it) shifted the columns, so a name like ../x was read as "uploads/... ../x" and passed (2.2.1).
        // Listed by the web user too: tar's parser reads whatever the archive holds (listing alone has had tar CVEs)
        [$code, $out] = $job->run(slice($file, $info['members']['uploads.tar.gz.age']) . " | age -d -i $k | " . asUser('tar -tvzf - --quoting-style=escape --numeric-owner'), true);
        if ($code !== 0) {
            throw new JobFailed('The uploaded files in this backup are damaged.');
        }
        $n = 0;
        foreach (array_filter(explode("\n", $out)) as $line) {
            // type and mode, uid/gid, size, date, time, name: only plain files (-) and folders (d); links, devices and
            // anything unexpected fail, and so does a line that doesn't have exactly this shape
            $ok = preg_match('#^([-d])\S{9} \d+/\d+ +\d+ -?\d+-\d\d-\d\d \d\d:\d\d(?::\d\d)? (.+)$#', $line, $f) === 1;
            $name = $ok ? $f[2] : '';
            if (!$ok || !preg_match('#^uploads(/|$)#', $name) || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                throw new JobFailed('The uploaded files in this backup contain an unsafe entry and were not restored.');
            }
            $n += $f[1] === '-' ? 1 : 0;
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

/** What the page shows about a checked backup (manifest values are the backup's own claims, displayed escaped). */
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

/**
 * Replaces the app's database with the dump in $member of $file: drops the current tables, then imports.
 * SECURITY: both the drop and the import run as the web user with the app's own database login, in sandbox mode when
 * the client supports it, so a hostile dump can't run shell commands, read files or reach other databases. Table
 * names from the server are backtick-quoted. Root only decrypts (its key file); decompressing runs as the web user too.
 */
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
    $job->must(slice($file, $member) . ' | age -d -i ' . q($keyFile) . ' | ' . asUser('gunzip') . ' | sed -E ' . q('/^\) ENGINE=/ s/ `?ENCRYPTED`?=YES//') . ' | ' . asUser("$client " . q($db)), 'Importing the database failed.');
}

/** The mariadb client has --sandbox (newer MariaDB releases; older ones import without it), checked once. */
function sandboxSupported(): bool
{
    static $s = null;
    return $s ??= str_contains((string) shell_exec('mariadb --help 2>/dev/null'), '--sandbox');
}

/**
 * Puts a restored app_key into config.php (and, in Docker, the key file the container builds config.php from).
 * SECURITY: the caller checked $key against app_key's format (inspect()), so it can't break out of the PHP string.
 */
function setAppKey(string $key): void
{
    $txt = (string) file_get_contents(CONFIG);
    $new = preg_replace_callback("/('app_key'\s*=>\s*')[^']*(')/", fn($m) => $m[1] . $key . $m[2], $txt, 1, $n);
    if (!$n || $new === null) {
        throw new JobFailed('Could not update app_key in ' . CONFIG . '.');
    }
    writeConfig($new);
    if (DOCKER && getenv('ALIGN_APP_KEY_FROM_ENV') !== '1') {
        // The container rewrites config.php on every start: keep the restored key where it reads it from
        saveDockerKey($key);
    }
}

/** Docker: the key file in the config volume that docker/entrypoint.sh builds config.php from (root only, 0600). */
function saveDockerKey(string $key): void
{
    $f = dirname(CONFIG) . '/app-key';
    $tmp = $f . '.tmp' . getmypid();
    $old = umask(077);
    file_put_contents($tmp, $key . "\n");
    umask($old);
    if (!rename($tmp, $f)) {
        @unlink($tmp);
        throw new JobFailed('Could not save the encryption key in ' . $f . '.');
    }
}

/**
 * Replaces config.php atomically, keeping its owner, group and mode.
 * SECURITY: config.php holds app_key and the database password: the temp file is created 0600 (not 0644 under the
 * agent's umask) and only takes the config's mode once written. A short write (a full disk) fails the job and leaves
 * config.php as it was, instead of putting a cut-off config (and with it the only copy of app_key) in its place.
 */
function writeConfig(string $txt): void
{
    $st = stat(CONFIG);
    $tmp = CONFIG . '.tmp' . getmypid();
    $old = umask(077);
    $written = file_put_contents($tmp, $txt);
    umask($old);
    if ($written !== strlen($txt)) {
        @unlink($tmp);
        throw new JobFailed('Could not write ' . CONFIG . ' (is the disk full?). It was not changed.');
    }
    chmod($tmp, $st['mode'] & 0777);
    if (posix_getuid() === 0) {
        chown($tmp, $st['uid']);
        chgrp($tmp, $st['gid']);
    }
    rename($tmp, CONFIG);
}

/**
 * Writes an audit log entry through bin/align (as the web user), attributed to the job's user. bin/align checks the
 * event name and raises the matching alert emails (restore, update result, unsigned release).
 */
function audit(Job $job, string $event, string $detail): void
{
    $u = $job->s['user_id'] ? ' --user=' . (int) $job->s['user_id'] : '';
    $job->run(asUser('php ' . q(APP . '/bin/align') . ' system:audit --event=' . q($event) . ' --detail=' . q($detail) . $u));
}

/**
 * Restores the database and/or uploaded files from a checked backup, with a safety copy put back on failure.
 * Returns what was restored.
 * SECURITY: the web app checked the admin's role, two-factor code and RESTORE confirmation before queueing; here the
 * backup is checked again in full (inspect()) before anything changes. In the data folder every step (staging,
 * extracting, moving folders, deleting) runs as the web user; the tar extraction keeps no owners or modes. The safety
 * copy is encrypted to the server's key and a one-time key that lives in KEYS only for this job.
 */
function doRestore(Job $job, string $file, string $keyFile, bool $withDb, bool $withUploads): array
{
    $info = inspect($job, $file, $keyFile);
    $withUploads = $withUploads && isset($info['members']['uploads.tar.gz.age']);
    if (!$withDb && !$withUploads) {
        throw new JobFailed('Nothing to restore: choose the database, uploaded files or both.');
    }
    $job->s['result']['backup'] = summary($info);
    if (DOCKER && $withDb && getenv('ALIGN_APP_KEY_FROM_ENV') === '1' && $info['app_key'] !== null && $info['app_key'] !== (string) conf()['app_key']) {
        // The container would put its own key back on the next start, leaving the restored secrets unreadable
        throw new JobFailed('This backup was made with a different encryption key, and this container takes its key from ALIGN_APP_KEY (or ALIGN_APP_KEY_FILE). '
            . 'Nothing was changed. Remove that setting and restart the container, then restore again: the key comes from the backup.');
    }
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
        if ($withDb) {
            auditHeadSave();   // the restored log is the real one now: the nightly check starts from it, not from before
        }
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
                if (DOCKER && getenv('ALIGN_APP_KEY_FROM_ENV') !== '1' && preg_match("/'app_key'\s*=>\s*'([^']+)'/", $oldConfig, $km)) {
                    saveDockerKey($km[1]);
                }
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

/** Signs everyone out after a restore: deletes the PHP session files, as the web user (they are in its data folder). */
function clearSessions(): void
{
    $dir = conf()['session_path'] ?? null;
    if ($dir && is_dir($dir)) {
        exec(asUser('find ' . q(rtrim($dir, '/')) . ' -mindepth 1 -maxdepth 1 -type f -name ' . q('sess_*') . ' -delete') . ' 2>/dev/null');
    }
}

// ----------------------------------------------------------------------------------------- update

/**
 * Checks for an update and writes update.json for the page and the update email. $job: "Check now" from the page
 * (always asks GitHub, output in its log); null: the timer, the nightly run or the command line.
 * SECURITY: in signed mode only a release tag that release.sh accepted is looked at (its commit, not the tag name);
 * on a branch, GitHub is trusted as before. Commit messages and the README are text for display only (the page and
 * email escape them); they are size-limited here.
 */
function check(?Job $job = null): array
{
    if (DOCKER) {
        return checkDocker($job);
    }
    $prev = readJson(STATE . '/update.json') ?? [];
    $git = 'git -C ' . q(APP);
    $signed = signedMode();
    $s = ['checked_at' => now(), 'current' => version(), 'latest' => $prev['latest'] ?? null, 'behind' => $prev['behind'] ?? 0, 'changes' => $prev['changes'] ?? [], 'notes' => $prev['notes'] ?? [], 'error' => null, 'branch' => BRANCH,
        'mode' => $signed ? 'signed' : 'branch', 'signers' => $signed ? signerPrints() : [], 'release_tag' => $prev['release_tag'] ?? null, 'unsigned' => [],
        'head_signed' => $signed ? headSigned() : null];
    $run = function (string $cmd) use ($job): array {
        if ($job) {
            return $job->run($cmd, true, null, 120);
        }
        exec($cmd . ' 2>&1', $o, $c);
        return [$c, implode("\n", $o)];
    };
    // Scheduled checks only ("Check now" always asks GitHub): if the version file lists exactly this version, nothing was
    // pending last time and GitHub was asked within a day, skip GitHub. The day's limit means a stale or wrong version
    // file (or a fix pushed without a version change) can delay an update by a day at most.
    $fetched = strtotime((string) ($prev['fetched_at'] ?? '')) ?: 0;
    $s['fetched_at'] = $prev['fetched_at'] ?? null;
    $listed = $job === null && time() - $fetched < 86400 && (int) ($prev['behind'] ?? 0) === 0 && ($prev['current'] ?? null) === $s['current'] ? listedVersion() : null;
    if ($listed !== null && version_compare($listed, $s['current'], '==')) {
        $s['latest'] = $listed;
        $s['behind'] = 0;
        $s['changes'] = [];
        $s['notes'] = [];
        $s['source'] = 'version file';
        $c = null;
    } else {
        [$c] = $run($signed ? "timeout 90 $git fetch -q --force --tags origin" : "timeout 90 $git fetch -q origin " . q(BRANCH));
        if ($c === 0) {
            $s['fetched_at'] = now();
        }
    }
    if ($c === null) {
        // answered by the version file
    } elseif ($c !== 0) {
        $s['error'] = 'Could not reach GitHub to check for updates. Check the server\'s internet access and the GitHub token in /etc/msp-align/github-token.';
    } else {
        if ($signed) {
            // Only a release tag signed with a release key counts; newer tags without one are named, never offered.
            // Code that isn't a signed release yet (the first update from 1.x) is offered the signed one of its version.
            [$tag, $commit, $s['unsigned'], $err] = latestSigned($run, $s['head_signed'] ? $s['current'] : preg_replace('/^(\d+)\.(\d+)\.(\d+).*$/', '$1.$2.$3-0', $s['current']));
            $s['release_tag'] = $tag;
            $s['error'] = $err;
            $target = $commit ?: 'HEAD';
            if ($s['unsigned']) {
                $s['warning'] = 'Newer release tag' . (count($s['unsigned']) === 1 ? ' ' : 's ') . implode(', ', $s['unsigned'])
                    . ' on GitHub ' . (count($s['unsigned']) === 1 ? 'isn\'t' : 'aren\'t') . ' signed with the MSP Align release key, so ' . (count($s['unsigned']) === 1 ? 'it isn\'t' : 'they aren\'t')
                    . ' offered. If a release was expected, check mspalign.org before doing anything else.';
            }
        } else {
            $target = 'origin/' . BRANCH;
        }
        [, $latest] = $run("$git show " . q($target . ':VERSION'));
        [, $behind] = $run("$git rev-list --count " . q('HEAD..' . $target));
        [, $log] = $run("$git log --no-merges -n 60 --format=%h%x1f%s%x1f%b%x1f%cI%x1e " . q('HEAD..' . $target));
        $s['latest'] = trim($latest) ?: null;
        $s['behind'] = (int) trim($behind);
        [$rc, $readme] = $run("$git show " . q($target . ':README.md'));
        $s['notes'] = $rc === 0 && $s['latest'] !== null ? releaseNotes($readme, $s['current'], $s['latest']) : [];
        $s['changes'] = [];
        foreach (array_filter(explode("\x1e", $log), fn($x) => trim($x) !== '') as $entry) {
            [$sha, $subject, $body, $date] = array_pad(explode("\x1f", trim($entry)), 4, '');
            $body = trim(preg_replace('/^(Co-Authored-By|Claude-Session|Signed-off-by):.*$/mi', '', $body) ?? '');
            // the subject is limited too: update.json is read on every admin page (the update banner)
            $s['changes'][] = ['sha' => $sha, 'subject' => mb_substr($subject, 0, 300), 'body' => mb_substr($body, 0, 2000), 'date' => $date];
        }
    }
    // Never offer an older version (e.g. a test server switched from develop back to main): its code could meet newer tables
    $s['available'] = $s['latest'] !== null && (version_compare($s['latest'], $s['current'], '>')
        || ($s['behind'] > 0 && version_compare($s['latest'], $s['current'], '>=') && !$signed)
        // code that isn't a signed release (the first update from 1.x): the signed release of its version replaces it
        || ($signed && !$s['head_signed'] && !empty($s['release_tag']) && version_compare($s['latest'], $s['current'], '>=')));
    if ($s['latest'] !== null && version_compare($s['latest'], $s['current'], '<')) {
        $s['error'] = 'The ' . BRANCH . ' branch has ' . $s['latest'] . ', older than this server (' . $s['current'] . '). Not updating to an older version.';
    }
    // A newer release tag without a valid signature: in the audit log and a security alert, once per tag
    if ($s['unsigned'] && $s['unsigned'] !== ($prev['unsigned'] ?? [])) {
        exec(asUser('php ' . q(APP . '/bin/align') . ' system:audit --event=system.update_unsigned --detail='
            . q('Release tag(s) on GitHub without a valid release signature, not offered: ' . implode(', ', $s['unsigned']))) . ' 2>/dev/null');
    }
    writeJson(STATE . '/update.json', $s);
    systemInfo();
    return $s;
}

/**
 * Docker: no git checkout in the image, so ask GitHub over HTTPS for the VERSION file on the update branch and,
 * when it's newer, the commits since this version's tag (the "What's new" list). Same update.json as check().
 * SECURITY: a notice only (the image itself is pulled and checked by the operator), but everything GitHub returns is
 * untrusted: sizes are capped, the version must be X.Y.Z, and a JSON field of the wrong type is skipped (before 2.2.1
 * a non-list "parents" stopped the check with a TypeError). TLS is verified (PHP's default for https).
 */
function checkDocker(?Job $job = null): array
{
    $prev = readJson(STATE . '/update.json') ?? [];
    $s = ['checked_at' => now(), 'current' => version(), 'latest' => $prev['latest'] ?? null, 'behind' => $prev['behind'] ?? 0, 'changes' => $prev['changes'] ?? [],
        'notes' => $prev['notes'] ?? [], 'error' => null, 'branch' => BRANCH, 'fetched_at' => $prev['fetched_at'] ?? null, 'source' => 'github', 'docker' => true];
    $get = function (string $url, int $max = 4096): ?string {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1, 'max_redirects' => 3, 'user_agent' => 'MSP-ALIGN update check',
            'header' => "Accept: application/vnd.github+json\r\n", 'ignore_errors' => false]]);
        $body = @file_get_contents($url, false, $ctx, 0, $max);
        return $body === false ? null : $body;
    };
    $branchPath = str_replace('%2F', '/', rawurlencode(BRANCH));
    // With signed releases, images are published only from release tags, so the newest tag is the newest image
    // (for the notice only: the image itself is checked with cosign, see docs/DOCKER.md). Otherwise the branch.
    $latest = null;
    if (signedMode()) {
        $tags = json_decode((string) $get(GITHUB_API . '/repos/' . REPO . '/tags?per_page=100', 1 << 20), true);
        foreach (is_array($tags) ? $tags : [] as $t) {
            if (is_array($t) && is_string($t['name'] ?? null) && preg_match('/^v(\d+\.\d+\.\d+)$/', $t['name'], $m) && ($latest === null || version_compare($m[1], $latest, '>'))) {
                $latest = $m[1];
            }
        }
        $s['mode'] = 'signed';
    } else {
        $latest = listedVersion() ?? (($v = $get(GITHUB_RAW . '/' . REPO . '/' . $branchPath . '/VERSION', 64)) !== null ? trim($v) : null);
    }
    if ($latest === null || !preg_match('/^\d+\.\d+\.\d+$/', $latest)) {
        $s['error'] = 'Could not reach GitHub to check for updates. Check that the container can reach github.com.';
    } else {
        $s['fetched_at'] = now();
        $s['latest'] = $latest;
        $s['changes'] = [];
        $s['notes'] = [];
        $s['behind'] = 0;
        if (version_compare($latest, $s['current'], '>')) {
            // The release notes: "What's new" in the README of that release (the tag; the branch without release keys)
            $ref = signedMode() ? 'v' . $latest : $branchPath;
            $s['notes'] = releaseNotes((string) $get(GITHUB_RAW . '/' . REPO . '/' . $ref . '/README.md', 1 << 20), $s['current'], $latest);
            // "What's new": the branch's commits, newest first, back to this version's release commit ("v1.2.3: ...")
            $list = json_decode((string) $get(GITHUB_API . '/repos/' . REPO . '/commits?per_page=100&sha=' . rawurlencode(BRANCH), 2 << 20), true);
            // Fields of the wrong type count as missing (a string "parents" made count() throw)
            $str = static fn($v): string => is_string($v) ? $v : '';
            foreach (is_array($list) ? $list : [] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $commit = is_array($c['commit'] ?? null) ? $c['commit'] : [];
                $msg = $str($commit['message'] ?? null);
                [$subject, $body] = array_pad(explode("\n", $msg, 2), 2, '');
                if (preg_match('/^v' . preg_quote($s['current'], '/') . '\b/', $subject)) {
                    break;
                }
                if ((is_array($c['parents'] ?? null) && count($c['parents']) > 1) || $msg === '') {
                    continue;   // merge commits
                }
                $body = trim(preg_replace('/^(Co-Authored-By|Claude-Session|Signed-off-by):.*$/mi', '', $body) ?? '');
                $date = is_array($commit['committer'] ?? null) ? $str($commit['committer']['date'] ?? null) : '';
                $s['changes'][] = ['sha' => substr($str($c['sha'] ?? null), 0, 7), 'subject' => mb_substr($subject, 0, 300), 'body' => mb_substr($body, 0, 2000), 'date' => mb_substr($date, 0, 40)];
                if (count($s['changes']) >= 60) {
                    break;
                }
            }
            $s['behind'] = max(1, count($s['changes']));
        }
    }
    $s['available'] = $s['latest'] !== null && version_compare($s['latest'], $s['current'], '>');
    $job?->line($s['error'] ?? ('Latest: ' . $s['latest']));
    writeJson(STATE . '/update.json', $s);
    systemInfo();
    return $s;
}

/**
 * The release notes for the versions after $from up to $to: the README's "What's new" entries ("- **Title (1.2):** text",
 * with their indented sub-points), newest first. Written for people, unlike the commits in between, which are the
 * "What's new" list only when there are no notes (a test channel's changes within one version).
 * SECURITY: $readme is untrusted text (in Docker it comes straight from GitHub, unsigned). Output is capped (20 notes,
 * 40 sub-points, lengths) and only displayed, escaped; parsing takes linear time (see below).
 */
function releaseNotes(string $readme, string $from, string $to): array
{
    $v3 = fn(string $v) => implode('.', array_pad(explode('.', $v), 3, '0'));
    $notes = [];
    $in = false;
    $cur = null;
    foreach (preg_split('/\r?\n/', $readme) ?: [] as $line) {
        if (str_starts_with($line, '## ')) {
            if ($in) {
                break;
            }
            $in = (bool) preg_match('/^## What.s new\s*$/u', $line);
            continue;
        }
        if (!$in) {
            continue;
        }
        // Matched with runs of white space made single spaces: over a long run the lazy title backtracked at every
        // position (quadratic: one 1 MB line kept the check busy for over 20 minutes, 2.2.1). Displayed as HTML,
        // where runs of spaces show as one anyway.
        if (str_starts_with($line, '- **') && preg_match('/^- \*\*(.+?)\s*\((\d+\.\d+(?:\.\d+)?)\)[:.]?\*\*[:.]?\s*(.*)$/u', preg_replace('/\s+/', ' ', $line) ?? '', $m)) {
            $v = $v3($m[2]);
            $cur = null;
            if (version_compare($v, $from, '>') && version_compare($v, $to, '<=') && count($notes) < 20) {
                $cur = count($notes);
                $notes[] = ['version' => $v, 'title' => mb_substr($m[1], 0, 200), 'text' => mb_substr(trim($m[3]), 0, 3000), 'items' => []];
            }
        } elseif (str_starts_with($line, '- ')) {
            $cur = null;   // an entry without a version (the feature list further down)
        } elseif ($cur !== null && preg_match('/^( {2,})- (.+)$/u', $line, $m) && count($notes[$cur]['items']) < 40) {
            $notes[$cur]['items'][] = ['level' => strlen($m[1]) >= 4 ? 2 : 1, 'text' => mb_substr(trim($m[2]), 0, 3000)];
        }
    }
    return $notes;
}

/**
 * The latest version according to update_check_url ({url}/{branch}.json: {"version": "1.33.0"}), or null (unset, unreachable or unreadable).
 * SECURITY: the URL comes from root's config (https, or this machine for tests); no redirects, 4 KB at most, and only an
 * X.Y.Z answer counts. It can only skip a GitHub check for a day when it names the installed version, never offer one.
 */
function listedVersion(): ?string
{
    if (CHECK_URL === '' || !preg_match('#^(https://|http://127\.0\.0\.1[:/])[^?\#]*$#', CHECK_URL)) {
        return null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 0, 'user_agent' => 'MSP-ALIGN update check', 'ignore_errors' => false]]);
    $body = @file_get_contents(CHECK_URL . '/' . rawurlencode(str_replace('/', '-', BRANCH)) . '.json', false, $ctx, 0, 4096);
    $j = $body !== false ? json_decode($body, true) : null;
    $v = is_array($j) ? ($j['version'] ?? null) : null;
    return is_string($v) && preg_match('/^\d+\.\d+\.\d+$/', $v) ? $v : null;
}

/** Writes system.json for the page: backup public keys, whether the private key is still on the server, old backups, free space. */
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

/**
 * Updates the code and runs its installer (install.sh --upgrade). Returns [from, to] (and repair/refused for a reinstall).
 * SECURITY: in signed mode only the commit of the newest release tag release.sh accepted (checked against the signers
 * file installed now) is installed, by its hash; with none, the installed signed release is reinstalled, and unsigned
 * code is never installed. An older version is refused in both modes. Git runs as root on the root-owned checkout;
 * the GitHub token is read by git's credential helper (install.sh), never put in a command or the log. A safety copy
 * of the data is made first and kept when the installer fails; the previous code is put back when the update had no
 * database migrations.
 */
function doUpdate(Job $job): array
{
    if (DOCKER) {
        throw new JobFailed(DOCKER_UPDATE);
    }
    $from = version();
    $safety = SAFETY . '/pre-update-' . $job->s['id'] . '.tar';
    maintenanceOn($job, 'Updating MSP Align');
    try {
        if (recipients()) {
            $job->step('Making a safety copy of the current data');
            makeBackup($job, $safety, 'pre-update');
            owner($safety, 0640);
        } else {
            $job->line('No backup key on this server yet, so no safety copy (the installer creates the key).');
        }
        $job->step('Downloading the latest version');
        if (signedMode()) {
            // Signed releases: the newest release tag signed with a key this server already trusts (see release.sh)
            $job->must('git -C ' . q(APP) . ' fetch -q --force --tags origin', 'Could not download the update from GitHub.');
            // not a signed release yet (the first update from 1.x): the signed release of this same version counts too
            [$tag, $commit, $unsigned, $err] = latestSigned(fn(string $c) => $job->run($c, true), headSigned() ? $from : preg_replace('/^(\d+)\.(\d+)\.(\d+).*$/', '$1.$2.$3-0', $from));
            if ($err) {
                throw new JobFailed($err . ' Nothing was changed.');
            }
            if ($unsigned) {
                $job->line('Not signed with the release key, so not installed: ' . implode(', ', $unsigned));
                audit($job, 'system.update_unsigned', 'Release tag(s) without a valid release signature were refused: ' . implode(', ', $unsigned));
            }
            if ($tag === null) {
                if (!headSigned()) {
                    throw new JobFailed('There is no newer release signed with the MSP Align release key. Nothing was changed.');
                }
                // Up to date: run this signed release's installer again, which repairs packages, permissions and
                // services (what "sudo msp-align-update" is for when nothing is new), as on a branch
                [, $head] = $job->run('git -C ' . q(APP) . ' rev-parse HEAD', true);
                $ref = trim($head);
                $job->line("No newer signed release: installing $from again (repairs packages, permissions and services).");
            } else {
                $ref = $commit; // the commit that was checked, not the tag's name (which a later fetch could move)
                $job->line("Signed release $tag ($commit) checked against the release key.");
            }
        } else {
            $job->must('git -C ' . q(APP) . ' fetch -q origin ' . q(BRANCH), 'Could not download the update from GitHub.');
            $ref = 'origin/' . BRANCH;
        }
        [, $target] = $job->run('git -C ' . q(APP) . ' show ' . q($ref . ':VERSION'), true);
        if (trim($target) === '') {
            throw new JobFailed('Could not read the version on the ' . BRANCH . ' branch. Not updating.');
        }
        if (version_compare(trim($target), $from, '<')) {
            throw new JobFailed('The ' . BRANCH . ' branch has ' . trim($target) . ', older than this server (' . $from . '). Not updating to an older version.');
        }
        [, $previous] = $job->run('git -C ' . q(APP) . ' rev-parse HEAD', true);
        [, $migrations] = $job->run('git -C ' . q(APP) . ' diff --name-only HEAD ' . q($ref) . ' -- db/migrations', true);
        $job->s['result']['previous_commit'] = trim($previous);
        $job->must('umask 022; git -C ' . q(APP) . ' reset -q --hard ' . q(str_starts_with($ref, 'origin/') ? $ref : $ref . '^{commit}'), 'Could not apply the update.');
        $job->step('Installing ' . version() . ' (packages, database, services)');
        // ALIGN_CODE_READY: the code is in place (and checked), so the installer doesn't fetch it again
        [$rc] = $job->run(INSTALL_CMD !== '' ? INSTALL_CMD : 'umask 022; ALIGN_CODE_READY=1 ALIGN_BRANCH=' . q(BRANCH) . ' bash ' . q(APP . '/install.sh') . ' --upgrade');
        if ($rc !== 0) {
            $kept = is_file($safety) ? ' A safety copy of the data was kept.' : '';
            if (trim($migrations) === '' && trim($previous) !== '') {
                // No database changes in this update, so the previous code fits the database as it is: put it back
                [$back] = $job->run('umask 022; git -C ' . q(APP) . ' reset -q --hard ' . q(trim($previous)));
                throw new JobFailed('The installer reported an error.' . ($back === 0 ? " The previous version's code ($from) was put back." : ' The code is now ' . version() . '.') . $kept);
            }
            throw new JobFailed("The installer reported an error. The code is now " . version() . " and the database may be partly updated; running the update again usually completes it.$kept");
        }
        $to = version();
        @unlink($safety);
        $job->step('Checking for newer updates');
        check($job);
        [, $now] = $job->run('git -C ' . q(APP) . ' rev-parse HEAD', true);
        if (trim($now) !== '' && trim($now) === trim($previous)) {
            // nothing new: the same code installed again (a repair), not an update, so no "updated" email
            audit($job, 'system.reinstalled', "Installed $to again (packages, permissions and services repaired)");
            return ['from' => $from, 'to' => $to, 'repair' => true, 'refused' => $unsigned ?? []];
        }
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

/**
 * Runs one job from a request (the web app's, or the command line's). $echo: also print the log (command line).
 * SECURITY: the caller checked id and action (main loop); every parameter is checked here before use: the upload token
 * (32 hex), the key (age's format, in keyFile()), the safety copy's name (fixed pattern), the restore choices (booleans).
 * Key files are deleted whatever happens.
 */
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
                $job->finish(true, $s['available'] ? "Version {$s['latest']} is available." : 'MSP Align is up to date.', ['latest' => $s['latest']]);
                break;

            case 'update':
                $r = doUpdate($job);
                $job->finish(true, !empty($r['repair']) ? "No newer release: {$r['to']} was installed again." . (!empty($r['refused']) ? ' Refused because not signed with the MSP Align release key: ' . implode(', ', $r['refused']) . '.' : '') : ($r['from'] === $r['to'] ? "Updated (still {$r['to']})." : "Updated from {$r['from']} to {$r['to']}."), $r);
                break;

            case 'backup':
                $tmp = WORK . '/' . $req['id'] . '.tar';
                $m = makeBackup($job, $tmp, 'download');
                $out = DOWNLOADS . '/' . $req['id'] . '.tar';
                $size = filesize($tmp);
                $sha = hash_file('sha256', $tmp);
                try {
                    toWeb($job, $tmp, $out, 'Could not hand the backup to the web server.');
                } finally {
                    @unlink($tmp);
                }
                $job->finish(true, 'Backup ready to download.', ['file' => basename($out), 'filename' => nameFor($m), 'size' => $size, 'sha256' => $sha, 'uploads_files' => $m['uploads_files']]);
                break;

            case 'verify':
            case 'restore':
                $token = (string) ($p['token'] ?? '');
                $upload = RESTORE . "/$token.tar";
                if (!preg_match(TOKEN_RE, $token) || !is_file($upload) || is_link($upload)) {
                    throw new JobFailed('The uploaded backup file is gone. Upload it again.');
                }
                // Work on a copy in the agent's own folder, read as the web user (see webDir)
                $file = WORK . '/' . $req['id'] . '-upload.tar';
                try {
                    fromWeb($job, $upload, $file, 'The uploaded backup file could not be read. Upload it again.');
                    $keyFile = keyFile($req['id'], (string) ($req['key'] ?? ''));
                    if ($req['action'] === 'verify') {
                        $info = inspect($job, $file, $keyFile);
                        $job->finish(true, 'The backup opened with this key and every part checked out.', ['backup' => summary($info)]);
                    } else {
                        $r = doRestore($job, $file, $keyFile, !empty($p['db']), !empty($p['uploads']));
                        exec(asUser('rm -f -- ' . q($upload)));
                        $job->finish(true, 'Restore complete. Everyone has been signed out.', $r);
                    }
                } finally {
                    @unlink($file);
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

/**
 * Outside checkpoint of the audit log (1.45): the head the last night saw is kept here, where the web user and the
 * database can't change it. An entry it saw must still be there with the same hash (or have been pruned for age),
 * so writing back an old copy of the chain's markers after removing newer entries is caught too.
 * SECURITY: the check runs as the web user against a database the web user can change, so only exit 0 (checked) moves
 * the checkpoint on. Exit 2 (tampering found, already in the audit log and emailed) alerts and moves on, so it isn't
 * raised again every night. Anything else (the check failed to run, e.g. its own alert path made to throw) alerts and
 * keeps the old checkpoint for the next night; before 2.2.1 it was replaced silently, losing the evidence.
 */
function auditCheckpoint(): void
{
    $file = STATE . '/audit-head.json';
    $prev = readJson($file);
    if ($prev && isset($prev['id'], $prev['hash'])) {
        exec(asUser('php ' . q(APP . '/bin/align') . ' audit:checkpoint --id=' . (int) $prev['id'] . ' --hash=' . q((string) $prev['hash'])) . ' 2>&1', $o, $code);
        if ($code === 2) {
            fwrite(STDERR, "ALERT: audit log verification failed: " . implode(' ', $o) . "\n");
        } elseif ($code !== 0) {
            fwrite(STDERR, "ALERT: the audit log checkpoint (entry #" . (int) $prev['id'] . ") could not be checked (exit $code); it is kept for the next run: "
                . mb_substr(implode(' ', $o), 0, 500) . "\n");
            return;
        }
    }
    auditHeadSave();
}

/**
 * Keeps the audit log's newest entry as the checkpoint (nightly, and after a restore replaced the log on purpose).
 * The answer comes from the web user's side (bin/align), so it is checked: an int id and a 64-hex (or empty) hash.
 */
function auditHeadSave(): void
{
    exec(asUser('php ' . q(APP . '/bin/align') . ' audit:head') . ' 2>/dev/null', $h, $code);
    $head = json_decode(implode('', $h), true);
    if ($code === 0 && is_array($head) && isset($head['id'], $head['hash']) && preg_match('/^[0-9a-f]{64}$|^$/', (string) $head['hash'])) {
        writeJson(STATE . '/audit-head.json', ['id' => (int) $head['id'], 'hash' => (string) $head['hash'], 'at' => now()]);
    }
}

/**
 * Removes what was left behind: unclaimed downloads (1 hour), uploads and import previews (1 day), as the web user;
 * WORK leftovers (6 hours), safety copies (14 days) and all but the newest 100 jobs, as root in its own folders.
 */
function cleanup(): void
{
    // Backups never downloaded within an hour, and uploads left for a day, removed as the web user (see webDir)
    exec(asUser('find ' . q(DOWNLOADS) . ' -mindepth 1 -maxdepth 1 -type f -mmin +60 -delete') . ' 2>/dev/null');
    exec(asUser('find ' . q(RESTORE) . ' -mindepth 1 -maxdepth 1 -type f -mmin +1440 -delete') . ' 2>/dev/null');
    // Import previews nobody imported (client and contact details) go after a day, even if the importer isn't used again (1.45)
    exec(asUser('find ' . q(DATA . '/imports') . ' -mindepth 1 -maxdepth 1 -type f -name ' . q('*.json') . ' -mmin +1440 -delete') . ' 2>/dev/null');
    foreach (glob(WORK . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 6 * 3600) {
            @unlink($f);
        } elseif (is_dir($f) && filemtime($f) < time() - 6 * 3600) {
            rmTree($f);
        }
    }
    foreach (glob(SAFETY . '/*.tar') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 14 * 86400) {
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

/** A job id for command-line jobs, in the web app's format (ID_RE). */
function newId(): string
{
    return date('Ymd-His') . '-' . bin2hex(random_bytes(3));
}

/**
 * Takes one request file out of the request folder and returns its contents ('' when it isn't a usable file); the
 * file is gone afterwards either way.
 * SECURITY: the web user can write the request folder, so it could swap a checked file for a symlink (to a root-only
 * file, or /dev/zero), a FIFO (the agent waits forever) or keep writing to it after the size check. So the file is first
 * moved into KEYS (root only, same RAM disk): rename never follows a symlink, and once there nothing can be swapped.
 * Then it must be a regular file with one link (not a hard link to another file) of at most 64 KiB, and no more than
 * that is read. Requests can carry a restore key, which is why they are moved there and deleted straight away.
 */
function takeRequest(string $f): string
{
    $own = KEYS . '/request-' . bin2hex(random_bytes(8));
    // Across filesystems rename() would copy (following a symlink, blocking on a FIFO): only a real rename is safe
    if ((@stat(REQ)['dev'] ?? -1) !== (@stat(KEYS)['dev'] ?? -2)) {
        fwrite(STDERR, 'The requests and keys folders are on different filesystems; request ' . basename($f) . " ignored\n");
        @unlink($f);
        return '';
    }
    if (!@rename($f, $own)) {
        @unlink($f);
        return '';
    }
    try {
        $st = @lstat($own);
        if (!$st || ($st['mode'] & 0170000) !== 0100000 || $st['nlink'] !== 1 || $st['size'] > 65536) {
            return '';
        }
        return (string) @file_get_contents($own, false, null, 0, 65536);
    } finally {
        if (is_dir($own) && !is_link($own)) {
            rmTree($own);   // a folder named like a request: in root's own folder now, so removing it is safe
        } else {
            @unlink($own);
        }
    }
}

/**
 * Removes what a killed agent leaves behind (its shutdown function never ran): request files taken into KEYS (one
 * can hold a restore key) and database login files in RUN. Only called from 'run', under the agent lock, so no
 * other agent is using them.
 */
function sweepLeftovers(): void
{
    foreach ([...(glob(KEYS . '/request-*') ?: []), ...(glob(RUN . '/db-*.cnf') ?: [])] as $f) {
        if (is_dir($f) && !is_link($f)) {
            rmTree($f);
        } else {
            @unlink($f);
        }
    }
}

/** Reads a line from the terminal without echoing it (the backup key for restore-cli). */
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
        sweepLeftovers();
        // Web requests. Each file is read and deleted straight away (restore keys never stay on disk).
        for ($i = 0; $i < 50; $i++) {
            $files = glob(REQ . '/*.json') ?: [];
            sort($files);
            if (!$files) {
                break;
            }
            foreach ($files as $f) {
                $raw = takeRequest($f);
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
        auditCheckpoint();
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
            fwrite(STDERR, "Usage: sudo msp-align-restore BACKUP-FILE [--db-only|--uploads-only]\n");
            exit(1);
        }
        $token = bin2hex(random_bytes(16));
        ensureDirs();
        exec('bash -o pipefail -c ' . q('cat -- ' . q($src) . ' | ' . asUser('sh -c ' . q('umask 027 && cat > "$1"') . ' sh ' . q(RESTORE . "/$token.tar"))), $o, $rc);
        if ($rc !== 0) {
            fwrite(STDERR, "Could not copy the backup into place.\n");
            exit(1);
        }
        $key = readSecret('Backup key (AGE-SECRET-KEY-1...): ');
        fwrite(STDOUT, "This replaces the current data and signs everyone out. Type RESTORE to continue: ");
        if (trim((string) fgets(STDIN)) !== 'RESTORE') {
            exec(asUser('rm -f -- ' . q(RESTORE . "/$token.tar")));
            exit(1);
        }
        $only = $argv[3] ?? '';
        $job = process(['id' => newId(), 'action' => 'restore', 'user' => 'command line (' . (getenv('SUDO_USER') ?: 'root') . ')', 'key' => $key,
            'params' => ['token' => $token, 'db' => $only !== '--uploads-only', 'uploads' => $only !== '--db-only']], true);
        exec(asUser('rm -f -- ' . q(RESTORE . "/$token.tar")));
        exit($job->s['state'] === 'succeeded' ? 0 : 1);

    default:
        fwrite(STDERR, "Usage: agent.php run|check|nightly|update-cli|restore-cli FILE\n");
        exit(1);
}
