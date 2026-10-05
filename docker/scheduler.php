<?php
// MSP Align. Copyright (C) 2026 Mountaineer IT Inc. and MSP Align contributors
// SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
/**
 * Scheduler for the Docker image (1.44): runs, inside the container, what systemd runs on a dedicated server.
 *
 *   mail     every minute                    bin/align mail:run            (msp-align-mail.timer)
 *   psa      every 2 minutes                 bin/align psa:poll            (msp-align-psa.timer)
 *   sync     hourly, a few minutes past      bin/align sync                (msp-align-sync.timer)
 *   agent    when the web app queues a job   scripts/agent.php run         (msp-align-agent.path)
 *   check    10 minutes after start, 6-hourly scripts/agent.php check      (msp-align-update-check.timer)
 *   nightly  02:30 (+ up to 20 min)          scripts/agent.php nightly     (msp-align-nightly.timer)
 *
 * App jobs run as www-data, agent jobs as root (inside the container), each never overlapping itself. Each job
 * has the same time limit as its systemd unit (TimeoutStartSec): past it, it is stopped, so a job that hangs can't
 * hold up every later run of itself until the container restarts (2.2.1).
 * Started by docker/entrypoint.sh; its output goes to the container log. `php scheduler.php --list` prints the job
 * table as JSON and exits without running anything (for the tests).
 *
 * Security assumptions: runs as root, started only by the entrypoint. The commands are fixed here; nothing from
 * the web app reaches them except the presence of request files, which the agent itself validates. App commands
 * run as www-data through runuser, never as root. State files go to /run/msp-align, which only root can write.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const APP = '/opt/msp-align';
const REQUESTS = '/run/msp-align/requests';

/** An app command (bin/align) as www-data. The arguments are fixed strings from this file. */
$align = fn(string ...$args): array => ['runuser', '-u', 'www-data', '--', 'php', APP . '/bin/align', ...$args];
/** A root agent action (scripts/agent.php). */
$agent = fn(string $action): array => ['php', APP . '/scripts/agent.php', $action];

$start = time();
// 'timeout' (seconds) is the systemd unit's TimeoutStartSec (deploy/systemd); nightly has none there either
$jobs = [
    'mail' => ['cmd' => $align('mail:run', '--quiet'), 'next' => $start + 60, 'every' => 60, 'timeout' => 600],
    'psa' => ['cmd' => $align('psa:poll', '--quiet'), 'next' => $start + 120, 'every' => 120, 'timeout' => 600],
    'sync' => ['cmd' => $align('sync', '--trigger=schedule', '--quiet'), 'next' => $start + 300, 'hourly' => random_int(0, 300), 'timeout' => 2700],
    'check' => ['cmd' => $agent('check'), 'next' => $start + 600, 'every' => 21600, 'jitter' => 1800, 'timeout' => 300],
    'nightly' => ['cmd' => $agent('nightly'), 'next' => nightly(), 'daily' => true],
    // No limit for the agent (as before 2.2.1): stopping a restore mid-import would skip putting the safety copy
    // back; the agent limits each command itself (Job::run, an hour)
    'agent' => ['cmd' => $agent('run'), 'next' => $start, 'when' => fn() => (bool) glob(REQUESTS . '/*.json')],
];
foreach ($jobs as &$j) {
    // coreutils timeout runs the job in its own process group and signals all of it (runuser and the php under it):
    // TERM at the limit, KILL 30 seconds later. (A container stop doesn't go through it: tini stops the scheduler.)
    if (isset($j['timeout'])) {
        $j['cmd'] = ['timeout', '--kill-after=30', (string) $j['timeout'], ...$j['cmd']];
    }
}
unset($j);
if (in_array('--list', $argv, true)) {
    echo json_encode(array_map(fn(array $j): array => ['cmd' => $j['cmd'], 'timeout' => $j['timeout'] ?? null], $jobs), JSON_PRETTY_PRINT), "\n";
    exit(0);
}
$running = [];
$last = [];   // name => [started, finished, exit code]: written to /run/msp-align/scheduler.json for troubleshooting
$stop = false;
// TERM/INT (container stop) end the loop; running jobs then get the time below to finish
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, function () use (&$stop) { $stop = true; });
    }
}

/** The next 02:30 local time, plus up to 20 minutes. */
function nightly(): int
{
    $t = strtotime('today 02:30');
    if ($t <= time()) {
        $t = strtotime('tomorrow 02:30');
    }
    return $t + random_int(0, 1200);
}

/**
 * Which jobs are running now, for the agent: a restore waits until no app job is writing (scripts/agent.php timers()).
 * Written by root into /run/msp-align (root's own folder), so the web user can't fake it.
 */
function saveRunning(array $running): void
{
    @file_put_contents('/run/msp-align/scheduler-running.json', json_encode(array_keys($running)), LOCK_EX);
}

/** A line for the container log, with the time. Job output goes here too, so jobs must not print secrets. */
function say(string $msg): void
{
    fwrite(STDOUT, '[scheduler] ' . date('Y-m-d H:i:s') . ' ' . $msg . "\n");
}

say('started');
while (!$stop) {
    $now = time();
    // Reap finished jobs
    foreach ($running as $name => $r) {
        [$proc, $pipes, $began] = $r;
        $st = proc_get_status($proc);
        if ($st['running']) {
            continue;
        }
        $out = trim(($r[3] ?? '') . (string) stream_get_contents($pipes[1]));
        foreach ($pipes as $p) {
            fclose($p);
        }
        proc_close($proc);
        unset($running[$name]);
        saveRunning($running);
        $code = $st['exitcode'];
        $last[$name] = ['started' => date('c', $began), 'finished' => date('c'), 'exit' => $code];
        @file_put_contents('/run/msp-align/scheduler.json', json_encode($last, JSON_PRETTY_PRINT), LOCK_EX);
        if ($code !== 0 || $out !== '') {
            // timeout exits 124 when it stopped the job at its limit, 137 when the job then had to be killed
            $why = in_array($code, [124, 137], true) && isset($jobs[$name]['timeout']) ? ', stopped at its ' . intdiv($jobs[$name]['timeout'], 60) . '-minute limit' : '';
            say("$name finished (exit $code$why) after " . (time() - $began) . 's' . ($out !== '' ? ":\n" . mb_substr($out, 0, 4000) : ''));
        }
    }
    // Start due jobs
    foreach ($jobs as $name => &$j) {
        if (isset($running[$name]) || $now < $j['next'] || (isset($j['when']) && !($j['when'])())) {
            continue;
        }
        $proc = proc_open($j['cmd'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (is_resource($proc)) {
            stream_set_blocking($pipes[1], false);
            $running[$name] = [$proc, $pipes, $now];
            saveRunning($running);
        } else {
            say("could not start $name");
        }
        if (!empty($j['daily'])) {
            $j['next'] = nightly();
        } elseif (isset($j['hourly'])) {
            $j['next'] = (intdiv($now, 3600) + 1) * 3600 + $j['hourly'];
        } elseif (isset($j['every'])) {
            $j['next'] = $now + $j['every'] + (isset($j['jitter']) ? random_int(0, $j['jitter']) : 0);
        } else {
            $j['next'] = $now + 2;   // the agent: look for new requests again in a moment
        }
    }
    unset($j);
    // Keep long-running jobs' output from filling their pipe
    foreach ($running as $name => [$proc, $pipes]) {
        while (($chunk = fread($pipes[1], 65536)) !== false && $chunk !== '') {
            $running[$name][3] = mb_substr(($running[$name][3] ?? '') . $chunk, -4000);
        }
    }
    sleep(2);
}
say('stopping');
// A backup or restore (the agent) gets up to 110 seconds to finish (compose.yaml gives the container 2 minutes);
// email, sync and the PSA check are safe to stop part-way and get 15.
$began = time();
while ($running) {
    foreach ($running as $name => [$proc]) {
        $limit = in_array($name, ['agent', 'check', 'nightly'], true) ? 110 : 15;
        if (!proc_get_status($proc)['running']) {
            unset($running[$name]);
        } elseif (time() - $began >= $limit) {
            say("stopping $name part-way");
            proc_terminate($proc);
            unset($running[$name]);
        }
    }
    saveRunning($running);
    sleep(1);
}
