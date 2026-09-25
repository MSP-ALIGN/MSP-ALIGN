<?php
declare(strict_types=1);

namespace Align\Compliance;

use Align\DB;

final class Compliance
{
    public const STATUSES = [
        'not_assessed' => ['Not assessed', 'secondary', 'fa-circle-question'],
        'met' => ['Met', 'success', 'fa-circle-check'],
        'partial' => ['Partial', 'warning', 'fa-circle-half-stroke'],
        'not_met' => ['Not met', 'danger', 'fa-circle-xmark'],
        'na' => ['N/A', 'light', 'fa-circle-minus'],
    ];

    public const AUTO_CHECKS = [
        'os_supported' => 'Devices on a supported OS',
        'hw_lifecycle' => 'Hardware within lifecycle',
        'warranty' => 'Servers & network gear under warranty',
        'stale' => 'Devices checking in to RMM',
    ];

    /** Score for one client + framework. */
    public static function score(int $clientId, int $frameworkId): array
    {
        $rows = DB::all('SELECT COALESCE(s.status, \'not_assessed\') AS status, COUNT(*) AS n
            FROM compliance_controls c
            LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            WHERE c.framework_id = ? GROUP BY status', [$clientId, $frameworkId]);
        return self::fromCounts(array_column($rows, 'n', 'status'));
    }

    /** Scores for every assigned client/framework pair: [client_id][framework_id] => score */
    public static function allScores(): array
    {
        $rows = DB::all('SELECT cf.client_id, cf.framework_id, COALESCE(s.status, \'not_assessed\') AS status, COUNT(*) AS n
            FROM client_frameworks cf
            JOIN compliance_controls c ON c.framework_id = cf.framework_id
            LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = cf.client_id
            GROUP BY cf.client_id, cf.framework_id, status');
        $counts = [];
        foreach ($rows as $r) {
            $counts[$r['client_id']][$r['framework_id']][$r['status']] = (int) $r['n'];
        }
        $out = [];
        foreach ($counts as $cid => $fws) {
            foreach ($fws as $fid => $c) {
                $out[$cid][$fid] = self::fromCounts($c);
            }
        }
        return $out;
    }

    public static function fromCounts(array $c): array
    {
        $c = array_map('intval', $c) + ['met' => 0, 'partial' => 0, 'not_met' => 0, 'na' => 0, 'not_assessed' => 0];
        $total = array_sum($c);
        $applicable = $total - $c['na'];
        $score = $applicable > 0 ? (int) round(($c['met'] + 0.5 * $c['partial']) / $applicable * 100) : 0;
        $assessed = $total > 0 ? (int) round(($total - $c['not_assessed']) / $total * 100) : 0;
        return $c + [
            'total' => $total,
            'applicable' => $applicable,
            'score' => $score,
            'assessed' => $assessed,
            'tone' => $score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'danger'),
        ];
    }

    /**
     * Device-data indicators used beside controls that have an auto_check.
     * @param array $devices evaluated devices from Lifecycle::devices($clientId)
     */
    public static function indicators(array $devices): array
    {
        $active = array_filter($devices, fn($d) => $d['status'] !== 'excluded');
        $count = fn(callable $f) => count(array_filter($active, $f));
        $withOs = $count(fn($d) => $d['os_rule'] !== null);
        $hw = $count(fn($d) => $d['is_hardware']);
        $infra = $count(fn($d) => $d['is_hardware'] && in_array($d['device_class'], ['server', 'network'], true));
        $agents = $count(fn($d) => $d['source'] === 'ninja');

        $bad = [
            'os_supported' => $count(fn($d) => in_array('os_eos', $d['flags'], true)),
            'hw_lifecycle' => $count(fn($d) => in_array('replace', $d['flags'], true)),
            'warranty' => $count(fn($d) => $d['is_hardware'] && in_array($d['device_class'], ['server', 'network'], true)
                && (in_array('warranty_expired', $d['flags'], true) || !$d['warranty_end'])),
            'stale' => $count(fn($d) => $d['stale']),
        ];
        $of = ['os_supported' => $withOs, 'hw_lifecycle' => $hw, 'warranty' => $infra, 'stale' => $agents];
        $detail = [
            'os_supported' => '%d of %d devices on an unsupported OS',
            'hw_lifecycle' => '%d of %d hardware devices past end of life',
            'warranty' => '%d of %d servers / network devices out of warranty or with no warranty date',
            'stale' => '%d of %d RMM devices have not checked in recently',
        ];
        $out = [];
        foreach (self::AUTO_CHECKS as $key => $label) {
            $out[$key] = [
                'label' => $label,
                'bad' => $bad[$key],
                'of' => $of[$key],
                'ok' => $of[$key] > 0 && $bad[$key] === 0,
                'unknown' => $of[$key] === 0,
                'text' => $of[$key] === 0 ? 'No data yet' : sprintf($detail[$key], $bad[$key], $of[$key]),
                'suggest' => $of[$key] === 0 ? null : ($bad[$key] === 0 ? 'met' : 'not_met'),
            ];
        }
        return $out;
    }
}
