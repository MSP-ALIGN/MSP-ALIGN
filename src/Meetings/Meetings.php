<?php
declare(strict_types=1);

namespace Align\Meetings;

use Align\DB;

final class Meetings
{
    public const TYPES = [
        'qbr' => ['Quarterly business review', 'primary'],
        'tbr' => ['Technology business review', 'info'],
        'strategy' => ['Strategy / roadmap', 'purple'],
        'onboarding' => ['Onboarding', 'success'],
        'compliance' => ['Compliance review', 'warning'],
        'project' => ['Project check-in', 'teal'],
        'internal' => ['Internal', 'secondary'],
        'other' => ['Other', 'dark'],
    ];

    /** Hex colors for the calendar, matching the Bootstrap/AdminLTE color names above. */
    public const COLORS = [
        'primary' => '#007bff', 'info' => '#17a2b8', 'purple' => '#6f42c1', 'success' => '#28a745',
        'warning' => '#e0a800', 'teal' => '#20c997', 'secondary' => '#6c757d', 'dark' => '#343a40',
    ];

    public const CADENCES = [
        'none' => ['No regular meetings', 0],
        'monthly' => ['Monthly', 1],
        'quarterly' => ['Quarterly', 3],
        'semiannual' => ['Every 6 months', 6],
        'annual' => ['Yearly', 12],
    ];

    public const REPEATS = ['none' => 0, 'monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12];

    public static function typeLabel(string $t): string
    {
        return self::TYPES[$t][0] ?? ucfirst($t);
    }

    public static function typeColor(string $t): string
    {
        return self::TYPES[$t][1] ?? 'secondary';
    }

    public static function newUid(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Meeting cadence status per client: last completed, next scheduled, and when one is due.
     * @return array<int, array{last:?string,next:?string,due:?string,overdue:bool,months:int}>
     */
    public static function cadence(): array
    {
        $rows = DB::all("SELECT c.id, c.meeting_cadence,
                (SELECT MAX(m.starts_at) FROM meetings m WHERE m.client_id = c.id AND m.status = 'completed') AS last_done,
                (SELECT MIN(m.starts_at) FROM meetings m WHERE m.client_id = c.id AND m.status = 'scheduled' AND m.starts_at >= NOW()) AS next_up
            FROM clients c WHERE c.is_archived = 0");
        $out = [];
        foreach ($rows as $r) {
            $months = self::CADENCES[$r['meeting_cadence']][1] ?? 0;
            $due = null;
            if ($months) {
                $due = $r['last_done'] ? date('Y-m-d', strtotime($r['last_done'] . " +$months months")) : date('Y-m-d');
            }
            $out[(int) $r['id']] = [
                'last' => $r['last_done'],
                'next' => $r['next_up'],
                'due' => $due,
                'months' => $months,
                'overdue' => $months > 0 && !$r['next_up'] && $due <= date('Y-m-d'),
            ];
        }
        return $out;
    }
}
