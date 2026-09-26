<?php
declare(strict_types=1);

namespace Align\Backup;

use Align\DB;
use Align\Settings;

/** Backup status per client, built from what the Veeam sync stored. */
final class Backup
{
    public const STATUS = [
        'success' => ['Success', 'ok'], 'warning' => ['Warning', 'warn'], 'failed' => ['Failed', 'bad'],
        'running' => ['Running', 'info'], 'none' => ['Not run', 'muted'],
    ];

    /** A machine without a restore point newer than this many hours counts as overdue. */
    public static function staleHours(): int
    {
        return max(1, Settings::int('backup_stale_hours', 48));
    }

    public static function enabled(): bool
    {
        return \Align\Integrations\VeeamSpc::configured() || (int) DB::value('SELECT COUNT(*) FROM veeam_companies') > 0;
    }

    /**
     * Everything the client page and reports show. Null when the client isn't linked to a VSPC company.
     * $devices: Lifecycle::devices() for the client (used to find servers with no backup).
     */
    public static function forClient(array $client, ?array $devices = null): ?array
    {
        $uid = $client['veeam_company_uid'] ?? null;
        if (!$uid) {
            return null;
        }
        $company = DB::one('SELECT * FROM veeam_companies WHERE uid = ?', [$uid]);
        if (!$company) {
            return null;
        }
        $stale = self::staleHours();
        $now = time();

        $jobs = [];
        foreach (DB::all('SELECT * FROM backup_jobs WHERE company_uid = ? ORDER BY name', [$uid]) as $j) {
            [$label, $tone] = self::STATUS[$j['status']] ?? self::STATUS['none'];
            $age = $j['last_run'] ? ($now - strtotime($j['last_run'])) / 3600 : null;
            $note = '';
            if (!$j['is_enabled']) {
                [$label, $tone] = ['Disabled', 'muted'];
            } elseif ($j['status'] !== 'running' && $age !== null && $age > $stale * 2) {
                $note = 'Has not run for ' . self::ago($j['last_run']);
                if ($tone === 'ok') {
                    $tone = 'warn';
                }
            }
            $jobs[] = $j + ['label' => $label, 'tone' => $tone, 'note' => $note, 'kind' => self::jobKind($j)];
        }
        usort($jobs, fn($a, $b) => [self::rank($a['tone']), $a['name']] <=> [self::rank($b['tone']), $b['name']]);

        $workloads = [];
        foreach (DB::all('SELECT w.*, d.display_name AS device_name FROM backup_workloads w LEFT JOIN devices d ON d.id = w.device_id
                WHERE w.company_uid = ? ORDER BY w.name', [$uid]) as $w) {
            $age = $w['last_point'] ? ($now - strtotime($w['last_point'])) / 3600 : null;
            $tone = match (true) {
                $age === null => 'bad',
                $age <= $stale => 'ok',
                $age <= $stale * 2 => 'warn',
                default => 'bad',
            };
            $workloads[] = $w + ['age_h' => $age, 'tone' => $tone,
                'label' => $age === null ? 'No restore point' : ($tone === 'ok' ? 'Protected' : 'Overdue')];
        }
        usort($workloads, fn($a, $b) => [self::rank($a['tone']), -($a['age_h'] ?? 1e9), $a['name']] <=> [self::rank($b['tone']), -($b['age_h'] ?? 1e9), $b['name']]);

        // Servers NinjaOne/ITFlow know about that no backup covers
        $covered = array_flip(array_filter(array_map(fn($w) => (int) $w['device_id'], $workloads)));
        $unprotected = [];
        foreach ($devices ?? [] as $d) {
            if ($d['device_class'] === 'server' && $d['type'] !== 'Hypervisor host' && $d['status'] !== 'excluded' && !isset($covered[(int) $d['id']])) {
                $unprotected[] = $d;
            }
        }

        // Run history: last 30 days, one cell per day showing the worst result
        $since = date('Y-m-d', strtotime('-29 days'));
        $runs = DB::all('SELECT DATE(run_at) AS day, status, COUNT(*) AS n FROM backup_job_runs
            WHERE company_uid = ? AND run_at >= ? GROUP BY DATE(run_at), status', [$uid, $since]);
        $byDay = [];
        $tot = ['success' => 0, 'warning' => 0, 'failed' => 0];
        foreach ($runs as $r) {
            $byDay[$r['day']][$r['status']] = (int) $r['n'];
            $tot[$r['status']] += (int) $r['n'];
        }
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $c = $byDay[$d] ?? [];
            $days[] = ['date' => $d, 'tone' => !empty($c['failed']) ? 'bad' : (!empty($c['warning']) ? 'warn' : (!empty($c['success']) ? 'ok' : 'none')),
                'text' => date('M j', strtotime($d)) . ': ' . ($c ? implode(', ', array_map(fn($s, $n) => "$n " . strtolower(self::STATUS[$s][0]), array_keys($c), $c)) : 'no runs recorded')];
        }
        $runCount = array_sum($tot);

        $active = array_filter($jobs, fn($j) => $j['is_enabled']);
        $stats = [
            'jobs' => count($active),
            'failed' => count(array_filter($active, fn($j) => $j['status'] === 'failed')),
            'warning' => count(array_filter($active, fn($j) => $j['status'] === 'warning')),
            'protected' => count($workloads),
            'ok' => count(array_filter($workloads, fn($w) => $w['tone'] === 'ok')),
            'overdue' => count(array_filter($workloads, fn($w) => $w['tone'] !== 'ok')),
            'unprotected' => count($unprotected),
            'runs' => $runCount,
            'run_failed' => $tot['failed'],
            'rate' => $runCount ? (int) floor(($tot['success'] + $tot['warning']) / $runCount * 100) : null,
            'last_point' => $workloads ? max(array_map(fn($w) => (string) $w['last_point'], $workloads)) ?: null : null,
            'backup_bytes' => array_sum(array_map(fn($w) => (int) $w['backup_bytes'], $workloads)),
            'cloud_used' => $company['cloud_used_bytes'] !== null ? (int) $company['cloud_used_bytes'] : null,
            'cloud_quota' => $company['cloud_quota_bytes'] !== null ? (int) $company['cloud_quota_bytes'] : null,
        ];
        $stats['cloud_pct'] = $stats['cloud_quota'] ? (int) round($stats['cloud_used'] / $stats['cloud_quota'] * 100) : null;
        $stats['tone'] = $stats['failed'] || $stats['unprotected'] || count(array_filter($workloads, fn($w) => $w['tone'] === 'bad')) ? 'bad'
            : ($stats['warning'] || $stats['overdue'] || array_filter($jobs, fn($j) => $j['tone'] === 'warn') ? 'warn' : 'ok');

        return [
            'company' => $company,
            'jobs' => $jobs,
            'workloads' => $workloads,
            'unprotected' => $unprotected,
            'days' => $days,
            'stats' => $stats,
            'stale' => $stale,
            'synced' => $company['synced_at'],
        ];
    }

    /** One line per client for the portfolio report and dashboard. [client id => stats] */
    public static function summaries(): array
    {
        $stale = date('Y-m-d H:i:s', time() - self::staleHours() * 3600);
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        $out = [];
        foreach (DB::all("SELECT c.id,
                (SELECT COUNT(*) FROM backup_jobs j WHERE j.company_uid = c.veeam_company_uid AND j.is_enabled = 1) AS jobs,
                (SELECT COUNT(*) FROM backup_jobs j WHERE j.company_uid = c.veeam_company_uid AND j.is_enabled = 1 AND j.status = 'failed') AS failed,
                (SELECT COUNT(*) FROM backup_jobs j WHERE j.company_uid = c.veeam_company_uid AND j.is_enabled = 1 AND j.status = 'warning') AS warning,
                (SELECT COUNT(*) FROM backup_workloads w WHERE w.company_uid = c.veeam_company_uid) AS protected,
                (SELECT COUNT(*) FROM backup_workloads w WHERE w.company_uid = c.veeam_company_uid AND (w.last_point IS NULL OR w.last_point < ?)) AS overdue,
                (SELECT COUNT(*) FROM backup_job_runs r WHERE r.company_uid = c.veeam_company_uid AND r.run_at >= ?) AS runs,
                (SELECT COUNT(*) FROM backup_job_runs r WHERE r.company_uid = c.veeam_company_uid AND r.run_at >= ? AND r.status <> 'failed') AS runs_ok
            FROM clients c WHERE c.veeam_company_uid IS NOT NULL", [$stale, $since, $since]) as $r) {
            $r = array_map('intval', $r);
            $r['rate'] = $r['runs'] ? (int) floor($r['runs_ok'] / $r['runs'] * 100) : null;
            $r['tone'] = $r['failed'] || $r['overdue'] ? 'bad' : ($r['warning'] ? 'warn' : ($r['jobs'] || $r['protected'] ? 'ok' : 'muted'));
            $out[$r['id']] = $r;
        }
        return $out;
    }

    /** device id => [last restore point, tone] for one client's devices. */
    public static function deviceMap(?string $companyUid): array
    {
        if (!$companyUid) {
            return [];
        }
        $stale = self::staleHours();
        $out = [];
        foreach (DB::all('SELECT device_id, MAX(last_point) AS lp FROM backup_workloads WHERE company_uid = ? AND device_id IS NOT NULL GROUP BY device_id', [$companyUid]) as $r) {
            $age = $r['lp'] ? (time() - strtotime($r['lp'])) / 3600 : null;
            $out[(int) $r['device_id']] = ['last_point' => $r['lp'], 'tone' => $age === null ? 'bad' : ($age <= $stale ? 'ok' : ($age <= $stale * 2 ? 'warn' : 'bad'))];
        }
        return $out;
    }

    public static function jobKind(array $j): string
    {
        $t = strtolower((string) $j['job_type']);
        return match (true) {
            $j['source'] === 'agent' || str_contains($t, 'agent') => 'Agent backup',
            str_contains($t, 'replica') => 'Replication',
            str_contains($t, 'copy') => 'Backup copy',
            str_contains($t, 'file') || str_contains($t, 'nas') => 'File backup',
            str_contains($t, 'tape') => 'Tape',
            str_contains($t, 'vm') || $t === '' => 'VM backup',
            default => ucfirst(preg_replace('/(?<!^)([A-Z])/', ' $1', (string) $j['job_type']) ?? ''),
        };
    }

    /** "3 hr ago" style, but "2 days" (no "ago") for use in sentences. */
    public static function ago(?string $d): string
    {
        return $d ? preg_replace('/ ago$/', '', rel_time($d)) : 'never';
    }

    public static function age(?float $hours): string
    {
        return match (true) {
            $hours === null => 'never',
            $hours < 1 => 'under 1 hr',
            $hours < 48 => (int) round($hours) . ' hr',
            default => (int) floor($hours / 24) . ' days',
        };
    }

    private static function rank(string $tone): int
    {
        return ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3, 'muted' => 4][$tone] ?? 5;
    }
}
