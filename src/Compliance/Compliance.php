<?php
declare(strict_types=1);

namespace Align\Compliance;

use Align\DB;

/**
 * Compliance scoring, device-data indicators, crosswalk tags and matches between frameworks.
 *
 * Security assumptions: every per-client query joins answers on the given client id, so one client's answers never
 * show for another; callers have checked access to that client. allScores() covers every client (staff overview,
 * dashboard and portfolio filter it to clients in planning). Tags are normalized to [a-z0-9_] by cleanTags().
 */
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

    /**
     * 2.6.1 Every automatic check a control can use: the device checks and the client's security checks (Microsoft
     * 365; 2.6.3 also Google Workspace and email authentication, Health\SecurityChecks). key => label
     */
    public static function checks(): array
    {
        return self::AUTO_CHECKS + \Align\Health\SecurityChecks::labels();
    }

    /**
     * 2.6.1 Indicators for one client's checklist: the device checks (indicators()) and its security checks from the
     * last sync (Microsoft 365; 2.6.3 also Google Workspace and email authentication; unknown when not connected).
     * $devices: Lifecycle::devices($clientId). The caller has checked access to the client.
     */
    public static function forClient(int $clientId, array $devices): array
    {
        return self::indicators($devices) + \Align\Health\SecurityChecks::indicators($clientId, $devices);
    }

    /** Score for one client + framework (counts by status; partial counts half, N/A is left out). */
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

    /** Score, share assessed and tone from counts per status. */
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
        $agents = $count(fn($d) => $d['source'] === 'rmm');

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

    /** Normalizes typed tags ("IAM MFA, cfg_baseline") to "iam_mfa,cfg_baseline"; at most 8. */
    public static function cleanTags(string $raw): ?string
    {
        $tags = [];
        foreach (preg_split('/[,;\s]+/', strtolower($raw)) ?: [] as $t) {
            $t = trim(preg_replace('/[^a-z0-9_]/', '', $t) ?? '', '_');
            if ($t !== '' && strlen($t) <= 40) {
                $tags[$t] = true;
            }
        }
        return $tags ? implode(',', array_slice(array_keys($tags), 0, 8)) : null;
    }

    /** Every tag in use, for the tag picker (plain text: the view escapes it). */
    public static function allTags(): array
    {
        $all = [];
        foreach (DB::all('SELECT DISTINCT tags FROM compliance_controls WHERE tags IS NOT NULL') as $r) {
            foreach (self::tagList($r['tags']) as $t) {
                $all[$t] = true;
            }
        }
        ksort($all);
        return array_keys($all);
    }

    /** Tags stored on a control ("a,b,c") as a list, primary tag first. */
    public static function tagList(?string $tags): array
    {
        return $tags ? array_values(array_filter(array_map('trim', explode(',', $tags)))) : [];
    }

    /**
     * Crosswalk: for each control in $controls (rows with id, tags), the matching controls in the
     * client's OTHER assigned frameworks, with the client's answers there.
     * A match needs a strong overlap: its primary tag is one of ours, ours is one of its, or they share
     * two or more tags. Returns [control_id => ['matches' => [...], 'suggest' => match|null]], where
     * 'suggest' is set when the best answered match is strong and every equally ranked answered match agrees on the status (used by
     * "Fill from matching answers").
     */
    public static function crosswalk(int $clientId, int $frameworkId, array $controls): array
    {
        $mine = [];
        foreach ($controls as $c) {
            if ($t = self::tagList($c['tags'] ?? null)) {
                $mine[(int) $c['id']] = $t;
            }
        }
        if (!$mine) {
            return [];
        }
        $others = DB::all("SELECT c.id, c.ref, c.title, c.tags, f.id AS fw_id, f.name AS fw_name,
                COALESCE(s.status, 'not_assessed') AS status, s.notes, s.evidence, s.document_id
            FROM client_frameworks cf
            JOIN compliance_frameworks f ON f.id = cf.framework_id
            JOIN compliance_controls c ON c.framework_id = f.id AND c.tags IS NOT NULL
            LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = cf.client_id
            WHERE cf.client_id = ? AND cf.framework_id <> ?
            ORDER BY f.name, c.sort, c.id", [$clientId, $frameworkId]);
        if (!$others) {
            return [];
        }
        $byTag = [];
        foreach ($others as $k => $o) {
            $others[$k]['tag_list'] = self::tagList($o['tags']);
            foreach ($others[$k]['tag_list'] as $t) {
                $byTag[$t][] = $k;
            }
        }
        $out = [];
        foreach ($mine as $cid => $tags) {
            $cand = [];
            foreach ($tags as $t) {
                foreach ($byTag[$t] ?? [] as $k) {
                    $cand[$k] = true;
                }
            }
            $matches = [];
            foreach (array_keys($cand) as $k) {
                $o = $others[$k];
                $shared = count(array_intersect($tags, $o['tag_list']));
                $score = $shared + (in_array($tags[0], $o['tag_list'], true) ? 1 : 0) + (in_array($o['tag_list'][0], $tags, true) ? 1 : 0);
                if ($score < 2) {
                    continue;
                }
                unset($o['tags'], $o['tag_list']);
                $o['score'] = $score;
                $o['answered'] = $o['status'] !== 'not_assessed';
                $matches[] = $o;
            }
            if (!$matches) {
                continue;
            }
            usort($matches, fn($a, $b) => [$b['answered'], $b['score'], $a['fw_name']] <=> [$a['answered'], $a['score'], $b['fw_name']]);
            $answered = array_values(array_filter($matches, fn($m) => $m['answered']));
            $suggest = null;
            if ($answered && $answered[0]['score'] >= 3) { // bulk fill only on a strong match (shared primary tags)
                $top = array_filter($answered, fn($m) => $m['score'] === $answered[0]['score']);
                if (count(array_unique(array_column($top, 'status'))) === 1) {
                    $suggest = $answered[0];
                }
            }
            $out[$cid] = ['matches' => $matches, 'answered' => count($answered), 'suggest' => $suggest];
        }
        return $out;
    }
}
