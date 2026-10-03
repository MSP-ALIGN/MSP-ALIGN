<?php
declare(strict_types=1);

namespace Align\Meetings;

use Align\DB;

/**
 * Meeting types, colors, client meeting cadences and series repeats, and each client's cadence status.
 * Plain data and date maths: no access checks here; callers have done them.
 */
final class Meetings
{
    public const TYPES = [
        'abr' => ['Annual business review', 'primary'],
        'qbr' => ['Quarterly business review', 'info'],
        'tbr' => ['Technology business review', 'teal'],
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

    /** "Quarterly business review" for "qbr"; an unknown type is shown as its key with a capital (escape it in HTML). */
    public static function typeLabel(string $t): string
    {
        return self::TYPES[$t][0] ?? ucfirst($t);
    }

    /** The Bootstrap color name of a type ("secondary" when unknown); always a key of COLORS. */
    public static function typeColor(string $t): string
    {
        return self::TYPES[$t][1] ?? 'secondary';
    }

    /** A new meeting UID for iCalendar (128 random bits, hex). Not a secret: it goes out in invitations. */
    public static function newUid(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * $start moved by $months calendar months at the same time of day. A day the target month doesn't have becomes
     * its last day (January 31 + 1 month = February 28 or 29), where strtotime('+1 month') rolls over into March
     * (2.2.1: a monthly series from the 31st drifted to the 3rd). Local time, so a DST change keeps the wall clock.
     */
    public static function addMonths(int $start, int $months): int
    {
        $m = (int) date('n', $start) - 1 + $months;
        $y = (int) date('Y', $start) + intdiv($m, 12) - ($m % 12 < 0 ? 1 : 0);
        $m = ($m % 12 + 12) % 12 + 1;
        $d = min((int) date('j', $start), (int) date('t', mktime(0, 0, 0, $m, 1, $y)));
        return mktime((int) date('G', $start), (int) date('i', $start), (int) date('s', $start), $m, $d, $y);
    }

    /**
     * Meeting cadence status per client in planning (not archived or removed from planning): last completed, next
     * scheduled, and when one is due. One query for every client (two correlated subqueries on the indexed
     * client_id, starts_at).
     * @return array<int, array{last:?string,next:?string,due:?string,overdue:bool,months:int}>
     */
    public static function cadence(): array
    {
        $rows = DB::all("SELECT c.id, c.meeting_cadence,
                (SELECT MAX(m.starts_at) FROM meetings m WHERE m.client_id = c.id AND m.status = 'completed') AS last_done,
                (SELECT MIN(m.starts_at) FROM meetings m WHERE m.client_id = c.id AND m.status = 'scheduled' AND m.starts_at >= NOW()) AS next_up
            FROM clients c WHERE c.is_archived = 0 AND c.planning_excluded = 0");
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
