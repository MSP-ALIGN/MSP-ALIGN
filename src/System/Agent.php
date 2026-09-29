<?php
declare(strict_types=1);

namespace Align\System;

use Align\Config;

/**
 * The web app's side of the system agent (scripts/agent.php, run as root by systemd).
 * The web server can't run programs, so it drops a small JSON request in /run/msp-align/requests
 * and reads the job's progress from /var/lib/msp-align-agent/jobs.
 */
final class Agent
{
    public const ID_RE = '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/';
    public const SAFETY_RE = '/^pre-(update|restore)-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.tar$/';
    public const ACTIONS = [
        'check' => 'Check for updates', 'update' => 'Update', 'backup' => 'Backup for download', 'verify' => 'Test a backup',
        'restore' => 'Restore', 'keycheck' => 'Check backup key', 'purge_legacy' => 'Delete old server backups', 'delete_safety' => 'Delete safety copy',
    ];

    /** The msp-align folder, or the mountaineer-align one on a server that hasn't moved yet (before 1.35). */
    private static function installPath(string $new, string $old): string
    {
        return is_dir($new) || !is_dir($old) ? $new : $old;
    }

    public static function dataDir(): string
    {
        return rtrim((string) Config::get('data_dir', self::installPath('/var/lib/msp-align', '/var/lib/mountaineer-align')), '/');
    }

    public static function runDir(): string
    {
        return rtrim((string) Config::get('run_dir', self::installPath('/run/msp-align', '/run/mountaineer-align')), '/');
    }

    public static function agentDir(): string
    {
        return rtrim((string) Config::get('agent_dir', self::installPath('/var/lib/msp-align-agent', '/var/lib/mountaineer-align-agent')), '/');
    }

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

    /** Queues a job. The key (restore/verify/keycheck) only ever sits in the RAM-backed request file until the agent reads it. */
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

    private static function json(string $path): ?array
    {
        $d = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        return is_array($d) ? $d : null;
    }

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

    public static function log(string $id, int $tail = 65536): string
    {
        $f = self::state("jobs/$id.log");
        if (!preg_match(self::ID_RE, $id) || !is_file($f)) {
            return '';
        }
        $size = (int) filesize($f);
        $fh = fopen($f, 'rb');
        if ($tail && $size > $tail) {
            fseek($fh, $size - $tail);
            fgets($fh);
        }
        $d = (string) stream_get_contents($fh);
        fclose($fh);
        return $d;
    }

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

    public static function downloadPath(string $id): ?string
    {
        return preg_match(self::ID_RE, $id) ? self::dataDir() . "/downloads/$id.tar" : null;
    }

    public static function restoreDir(): string
    {
        return self::dataDir() . '/restore';
    }

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

    public static function safetyPath(string $name): ?string
    {
        $f = self::state('safety/' . $name);
        return preg_match(self::SAFETY_RE, $name) && is_file($f) ? $f : null;
    }

    /** Reads what's in an uploaded backup without the key (the manifest is not encrypted). */
    public static function describeUpload(string $path): array
    {
        $fh = fopen($path, 'rb');
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
        return ['kind' => 'bundle', 'version' => (string) ($man['version'] ?? ''), 'created' => (string) ($man['created'] ?? ''), 'host' => (string) ($man['host'] ?? ''),
            'uploads_files' => (int) ($man['uploads_files'] ?? 0), 'has_uploads' => isset($m['uploads.tar.gz.age']), 'tag' => (string) ($man['tag'] ?? ''),
            'recipients' => array_values(array_filter((array) ($man['recipients'] ?? []), 'is_string'))];
    }
}
