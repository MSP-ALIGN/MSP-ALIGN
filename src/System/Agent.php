<?php
declare(strict_types=1);

namespace Align\System;

use Align\Config;

/**
 * The web app's side of the system agent (scripts/agent.php, run as root by systemd).
 * The web server can't run programs, so it drops a small JSON request in /run/msp-align/requests
 * and reads the job's progress from /var/lib/msp-align-agent/jobs.
 *
 * Security assumptions: only admin actions (SystemController) call request(); the agent treats every request as
 * untrusted and checks each field again. Job and state files are written by the agent (root) and read here as data:
 * ids and names from the URL are checked against ID_RE / SAFETY_RE before they become part of a path. Folders come
 * from config.php only. describeUpload() reads an uploaded file that anyone with an admin session could have
 * crafted, so it only parses the unencrypted manifest, with size limits, and never trusts a field's type.
 */
final class Agent
{
    public const ID_RE = '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/';
    public const SAFETY_RE = '/^pre-(update|restore)-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.tar$/';
    public const ACTIONS = [
        'check' => 'Check for updates', 'update' => 'Update', 'backup' => 'Backup for download', 'verify' => 'Test a backup',
        'restore' => 'Restore', 'keycheck' => 'Check backup key', 'purge_legacy' => 'Delete old server backups', 'delete_safety' => 'Delete safety copy',
    ];

    /** The msp-align folder, or the mountaineer-align one on a server that hasn't moved yet (before 1.35). Fixed paths only. */
    private static function installPath(string $new, string $old): string
    {
        return is_dir($new) || !is_dir($old) ? $new : $old;
    }

    /** Runs from the Docker image (1.44): updates come by pulling a new image instead of from the agent. */
    public static function docker(): bool
    {
        return Config::get('install_type') === 'docker';
    }

    /** The app's data folder (downloads, restore uploads), from config.php or the install default. */
    public static function dataDir(): string
    {
        return rtrim((string) Config::get('data_dir', self::installPath('/var/lib/msp-align', '/var/lib/mountaineer-align')), '/');
    }

    /** The RAM-backed folder requests are dropped in (the agent picks them up). */
    public static function runDir(): string
    {
        return rtrim((string) Config::get('run_dir', self::installPath('/run/msp-align', '/run/mountaineer-align')), '/');
    }

    /** The agent's own folder (jobs, logs, update and system info, safety copies); readable, not writable, by the web server. */
    public static function agentDir(): string
    {
        return rtrim((string) Config::get('agent_dir', self::installPath('/var/lib/msp-align-agent', '/var/lib/mountaineer-align-agent')), '/');
    }

    /** A path under the agent's folder. $f must be a literal or already checked against ID_RE / SAFETY_RE. */
    private static function state(string $f = ''): string
    {
        return self::agentDir() . ($f !== '' ? '/' . $f : '');
    }

    /** The agent is installed (the request folder exists and we can write to it). */
    public static function available(): bool
    {
        $d = self::runDir() . '/requests';
        return is_dir($d) && is_writable($d);
    }

    /**
     * Queues a job and returns its id. The key (restore/verify/keycheck) only ever sits in the RAM-backed request
     * file until the agent reads it. The file is written with umask 077 under a temporary name, then renamed, so the
     * agent never reads half a request. $action must be an ACTIONS key; $params must already be checked by the caller.
     */
    public static function request(string $action, array $params = [], ?string $key = null): string
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new \InvalidArgumentException('Unknown action');
        }
        if (!self::available()) {
            throw new \RuntimeException('The update and backup service is not installed on this server yet. Run once on the server: sudo msp-align-update');
        }
        $u = \Align\Auth::user();
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $req = ['id' => $id, 'action' => $action, 'params' => $params, 'created' => date('c'), 'user_id' => $u['id'] ?? null, 'user' => $u ? $u['name'] . ' <' . $u['email'] . '>' : 'system'];
        if ($key !== null) {
            $req['key'] = $key;
        }
        $dir = self::runDir() . '/requests';
        $tmp = "$dir/.$id.tmp";
        $old = umask(077);
        try {
            if (file_put_contents($tmp, json_encode($req)) === false || !rename($tmp, "$dir/$id.json")) {
                @unlink($tmp);
                throw new \RuntimeException('Could not queue the request.');
            }
        } finally {
            umask($old);
        }
        return $id;
    }

    /** A JSON file's array, or null when it is missing or isn't a JSON object or list. */
    private static function json(string $path): ?array
    {
        $d = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        return is_array($d) ? $d : null;
    }

    /**
     * The job with id $id (untrusted: anything but ID_RE gives null): its job file, or a "queued" placeholder while
     * the request still waits for the agent (the key in it is never copied out).
     */
    public static function job(string $id): ?array
    {
        if (!preg_match(self::ID_RE, $id)) {
            return null;
        }
        $j = self::json(self::state("jobs/$id.json"));
        if ($j === null && is_file($rf = self::runDir() . "/requests/$id.json")) {
            $req = self::json($rf) ?? [];
            $j = ['id' => $id, 'state' => 'queued', 'step' => 'Waiting for the server to pick this up', 'action' => $req['action'] ?? null, 'user' => $req['user'] ?? '', 'result' => []];
        }
        return $j;
    }

    /** The newest $limit jobs, newest first (job ids start with the date, so names sort by time). */
    public static function jobs(int $limit = 25): array
    {
        $files = glob(self::state('jobs/*.json')) ?: [];
        rsort($files);
        return array_values(array_filter(array_map(fn($f) => self::json($f), array_slice($files, 0, $limit))));
    }

    /** The newest job that hasn't finished (queued or running), if any. */
    public static function active(): ?array
    {
        foreach (glob(self::runDir() . '/requests/*.json') ?: [] as $f) {
            return self::job(basename($f, '.json'));
        }
        foreach (self::jobs(5) as $j) {
            if ($j['state'] === 'running') {
                return $j;
            }
        }
        return null;
    }

    /** The end of job $id's log ($tail bytes from a line start; 0 = all), or '' for an unknown or invalid id. */
    public static function log(string $id, int $tail = 65536): string
    {
        $f = self::state("jobs/$id.log");
        if (!preg_match(self::ID_RE, $id) || !is_file($f)) {
            return '';
        }
        $size = (int) filesize($f);
        $fh = @fopen($f, 'rb');
        if (!$fh) {
            return '';
        }
        if ($tail && $size > $tail) {
            fseek($fh, $size - $tail);
            fgets($fh);
        }
        $d = (string) stream_get_contents($fh);
        fclose($fh);
        return $d;
    }

    /** What the agent last found when checking for updates (update.json), or null. */
    public static function update(): ?array
    {
        return self::json(self::state('update.json'));
    }

    /** Update info when a newer version is available. */
    public static function updateAvailable(): ?array
    {
        $u = self::update();
        if (!$u || empty($u['latest'])) {
            return null;
        }
        // Newer version number, or new changes on the same version (checked against the running code)
        $newer = version_compare((string) $u['latest'], APP_VERSION, '>') || (!empty($u['available']) && ($u['current'] ?? '') === APP_VERSION);
        return $newer ? $u : null;
    }

    /** The server facts the agent reports (system.json: backup public keys, old backups...), or null. */
    public static function system(): ?array
    {
        return self::json(self::state('system.json'));
    }

    /**
     * Stages of the long-running jobs as [step prefix, from %, to %, seconds to cover most of the range].
     * The bar moves through each stage over time (it slows down near the end of a stage, never passes it),
     * so it keeps moving during long steps like installing, and jumps ahead when the agent starts the next one.
     */
    private const STAGES = [
        'update' => [
            ['Starting', 1, 6, 5], ['Making a safety copy', 6, 10, 5], ['Backing up the database', 10, 26, 25], ['Backing up uploaded files', 26, 34, 15],
            ['Downloading the latest version', 34, 44, 15], ['Installing', 44, 93, 70], ['Checking for newer updates', 93, 98, 8],
        ],
        'restore' => [
            ['Starting', 1, 4, 5], ['Checking', 4, 8, 10], ['Making a safety copy', 8, 10, 5], ['Backing up the database', 10, 22, 25], ['Backing up uploaded files', 22, 28, 15],
            ['Restoring the database', 28, 70, 60], ['Using the encryption key', 70, 72, 5], ['Restoring uploaded files', 72, 86, 30],
            ['Updating the database structure', 86, 94, 20], ['Signing everyone out', 94, 98, 5],
        ],
        'backup' => [['Starting', 1, 5, 5], ['Backing up the database', 5, 70, 40], ['Backing up uploaded files', 70, 96, 25]],
        'verify' => [['Starting', 1, 5, 5], ['Checking the database backup', 5, 70, 30], ['Checking uploaded files', 70, 96, 20]],
    ];

    /** Estimated percent done for a job (0-100), from its action, current step and how long that step has run. */
    public static function progress(string $action, string $state, string $step, ?string $stepAt, ?string $startedAt = null): int
    {
        if ($state === 'succeeded') {
            return 100;
        }
        $stages = self::STAGES[$action] ?? [['', 2, 95, 30]];
        $stage = $stages[0];
        foreach ($stages as $s) {
            if ($s[0] !== '' && str_starts_with($step, $s[0])) {
                $stage = $s;
            }
        }
        [, $from, $to, $tau] = $stage;
        $since = strtotime((string) ($stepAt ?: $startedAt ?: 'now')) ?: time();
        $t = max(0, time() - $since);
        return (int) min($to, round($from + ($to - $from) * (1 - exp(-$t / max(1, $tau)))));
    }

    /** Set while the agent restores or updates. Ignored if older than 3 hours (the agent clears stale ones too). */
    public static function maintenance(): ?array
    {
        $m = self::json(self::state('maintenance.json'));
        return $m && strtotime((string) ($m['since'] ?? '')) > time() - 3 * 3600 ? $m : null;
    }

    /** Where job $id's finished backup waits for its one download, or null for an invalid id. */
    public static function downloadPath(string $id): ?string
    {
        return preg_match(self::ID_RE, $id) ? self::dataDir() . "/downloads/$id.tar" : null;
    }

    /** The folder uploaded backups are saved in (random names, made by SystemController::upload). */
    public static function restoreDir(): string
    {
        return self::dataDir() . '/restore';
    }

    /** Safety copies kept after a failed update or restore, newest first (only names matching SAFETY_RE). */
    public static function safetyCopies(): array
    {
        $out = [];
        foreach (glob(self::state('safety/*.tar')) ?: [] as $f) {
            if (preg_match(self::SAFETY_RE, basename($f))) {
                $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f), 'kind' => str_contains(basename($f), 'update') ? 'Before update' : 'Before restore'];
            }
        }
        usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    /** The path of safety copy $name (untrusted: it must match SAFETY_RE, so it can't leave the folder), or null. */
    public static function safetyPath(string $name): ?string
    {
        $f = self::state('safety/' . $name);
        return preg_match(self::SAFETY_RE, $name) && is_file($f) ? $f : null;
    }

    /**
     * Reads what's in an uploaded backup without the key (the manifest is not encrypted). Throws RuntimeException
     * (a message for the admin) unless it is an age file (a backup from before 1.14) or a tar holding exactly the
     * expected parts with a manifest of format 1. The file is untrusted: the manifest is read up to 64 KB and only
     * scalar values are kept (2.2.1: a list there became the text "Array" with a PHP warning).
     */
    public static function describeUpload(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Cannot open the backup file.');
        }
        $head = (string) fread($fh, 21);
        fclose($fh);
        if ($head === 'age-encryption.org/v1') {
            return ['kind' => 'legacy'];
        }
        $m = Tar::members($path);
        if (!isset($m['manifest.json'], $m['db.sql.gz.age'])) {
            throw new \RuntimeException("This isn't an MSP-ALIGN backup.");
        }
        foreach (array_keys($m) as $n) {
            if (!in_array($n, ['manifest.json', 'db.sql.gz.age', 'uploads.tar.gz.age', 'app-key.age'], true)) {
                throw new \RuntimeException("This isn't an MSP-ALIGN backup (unexpected part: $n).");
            }
        }
        $man = json_decode(Tar::read($path, $m['manifest.json'], 65536), true);
        if (!is_array($man) || ($man['format'] ?? 0) !== 1) {
            throw new \RuntimeException('The backup manifest is not readable.');
        }
        $str = fn(string $k): string => is_scalar($man[$k] ?? null) ? mb_substr((string) $man[$k], 0, 200) : '';
        return ['kind' => 'bundle', 'version' => $str('version'), 'created' => $str('created'), 'host' => $str('host'),
            'uploads_files' => is_numeric($man['uploads_files'] ?? null) ? max(0, (int) $man['uploads_files']) : 0, 'has_uploads' => isset($m['uploads.tar.gz.age']), 'tag' => $str('tag'),
            'recipients' => array_values(array_filter((array) ($man['recipients'] ?? []), 'is_string'))];
    }
}
