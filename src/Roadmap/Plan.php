<?php
declare(strict_types=1);

namespace Align\Roadmap;

use Align\Settings;

/**
 * The 3-year planning calendar: 3 (fiscal) years x 4 quarters, starting with the
 * year that contains today. Anything overdue lands in the current quarter.
 *
 * Security assumptions: pure date arithmetic on trusted settings (fiscal_year_start, plan_start). Dates from
 * requests reach it through quarterStart()/quarterFor(), which refuse a day that doesn't exist (2.2.1), so what
 * they return can be stored in a DATE column.
 */
final class Plan
{
    public const YEARS = 3;

    /** The quarters for this request (the settings and today don't change during one). */
    private static ?array $cache = null;

    /** The month (1-12) the fiscal year starts in (Settings → Planning; January when unset or out of range). */
    public static function startMonth(): int
    {
        $m = Settings::int('fiscal_year_start', 1);
        return $m >= 1 && $m <= 12 ? $m : 1;
    }

    /** Fiscal year number = calendar year in which that fiscal year ends ($ts: a Unix time). */
    private static function fyOf(int $ts): int
    {
        $start = self::startMonth();
        $y = (int) date('Y', $ts);
        $m = (int) date('n', $ts);
        return $start === 1 ? $y : ($m >= $start ? $y + 1 : $y);
    }

    /**
     * The plan's 12 quarters, oldest first. Each starts on the 1st of a month, so adding months never overflows.
     * @return array<int, array{index:int,start:string,end:string,label:string,short:string,year:int,year_label:string,past:bool,current:bool}>
     */
    public static function quarters(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $startMonth = self::startMonth();
        $now = time();
        $fy = self::fyOf($now);
        // First day of the current fiscal year
        $fyStartYear = $startMonth === 1 ? $fy : $fy - 1;
        $cursor = mktime(0, 0, 0, $startMonth, 1, $fyStartYear);
        if (Settings::get('plan_start', 'current') === 'next') {
            $cursor = strtotime('+12 months', $cursor);
            $fy++;
        }
        $out = [];
        for ($i = 0; $i < self::YEARS * 4; $i++) {
            $qStart = strtotime('+' . ($i * 3) . ' months', $cursor);
            $qEnd = strtotime('+3 months -1 day', $qStart);
            $yearIdx = intdiv($i, 4);
            $qNum = $i % 4 + 1;
            $fyNum = $fy + $yearIdx;
            $out[] = [
                'index' => $i,
                'start' => date('Y-m-d', $qStart),
                'end' => date('Y-m-d', $qEnd),
                'label' => $startMonth === 1 ? "Q$qNum $fyNum" : "FY$fyNum Q$qNum",
                'short' => "Q$qNum",
                'months' => date('M', $qStart) . '–' . date('M', $qEnd),
                'year' => $yearIdx,
                'year_label' => $startMonth === 1 ? (string) $fyNum : "FY$fyNum",
                'past' => date('Y-m-d', $qEnd) < date('Y-m-d', $now),
                'current' => date('Y-m-d', $qStart) <= date('Y-m-d', $now) && date('Y-m-d', $now) <= date('Y-m-d', $qEnd),
            ];
        }
        return self::$cache = $out;
    }

    /** Index of the quarter containing today (0 when the plan starts next year, Settings plan_start = next). */
    public static function currentIndex(): int
    {
        foreach (self::quarters() as $q) {
            if ($q['current']) {
                return $q['index'];
            }
        }
        return 0;
    }

    /**
     * Quarter index for a date. Dates before the current quarter return the current
     * quarter when $rollOverdue is true (otherwise null); dates after the plan return null.
     * $date is a stored DATE or DATETIME (compared as text, which orders YYYY-MM-DD correctly).
     */
    public static function indexFor(?string $date, bool $rollOverdue = true): ?int
    {
        if (!$date) {
            return null;
        }
        $d = substr($date, 0, 10);
        $qs = self::quarters();
        $cur = self::currentIndex();
        if ($d < $qs[$cur]['start']) {
            return $rollOverdue ? $cur : null;
        }
        foreach ($qs as $q) {
            if ($d >= $q['start'] && $d <= $q['end']) {
                return $q['index'];
            }
        }
        return null;
    }

    /** The plan's 3 years with their labels and date ranges. @return array<int, array{label:string,from:string,to:string}> */
    public static function years(): array
    {
        $qs = self::quarters();
        $out = [];
        for ($y = 0; $y < self::YEARS; $y++) {
            $out[$y] = [
                'label' => $qs[$y * 4]['year_label'],
                'from' => $qs[$y * 4]['start'],
                'to' => $qs[$y * 4 + 3]['end'],
                'range' => date('M Y', strtotime($qs[$y * 4]['start'])) . ' – ' . date('M Y', strtotime($qs[$y * 4 + 3]['end'])),
            ];
        }
        return $out;
    }

    /**
     * First day of the quarter containing $date (for storing roadmap targets), or null when $date isn't a real day.
     * 2.2.1: a date outside the plan used to come back as it was, so 2031-02-31 reached the database (a failed save)
     * and 2031-05-17 was stored as a "quarter"; now it is checked and gives its own quarter's first day.
     */
    public static function quarterStart(string $date): ?string
    {
        if (self::day($date) === null) {
            return null;
        }
        foreach (self::quarters() as $q) {
            if ($date >= $q['start'] && $date <= $q['end']) {
                return $q['start'];
            }
        }
        return self::quarterFor($date)['start'] ?? null;
    }

    /** [year, month, day] of "YYYY-MM-DD" (a time after it is ignored) when it is a real day from year 1000 on, else null. */
    private static function day(string $date): ?array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[ T])/', $date, $m)) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        return $y >= 1000 && checkdate($mo, $d, $y) ? [$y, $mo, $d] : null;
    }

    /**
     * The (fiscal) quarter containing any date: ['start' => first day, 'label' => "Q3 2027" or "FY2027 Q1"], or
     * null when $date isn't a real day (2.2.1: strtotime() moved 2027-02-31 into March and read 0000-00-00 as year -1).
     */
    public static function quarterFor(string $date): ?array
    {
        $day = self::day($date);
        if ($day === null) {
            return null;
        }
        $ts = mktime(0, 0, 0, $day[1], $day[2], $day[0]);
        $startMonth = self::startMonth();
        $m = (int) date('n', $ts);
        $off = ($m - $startMonth + 12) % 12;
        $q = intdiv($off, 3) + 1;
        $qStartMonth = ($startMonth - 1 + ($q - 1) * 3) % 12 + 1;
        $y = (int) date('Y', $ts);
        $qStartYear = $qStartMonth > $m ? $y - 1 : $y;
        $fy = self::fyOf($ts);
        return ['start' => sprintf('%04d-%02d-01', $qStartYear, $qStartMonth), 'label' => $startMonth === 1 ? "Q$q $fy" : "FY$fy Q$q"];
    }

    /** Quarters to choose from for a planned replacement or a device project: this quarter and the next $years years. [start => label] */
    public static function choices(int $years = 5): array
    {
        $out = [];
        $cur = self::quarterFor(date('Y-m-d'));
        $ts = strtotime($cur['start']);
        for ($i = 0; $i <= $years * 4; $i++) {
            $q = self::quarterFor(date('Y-m-d', strtotime('+' . ($i * 3) . ' months', $ts)));
            $out[$q['start']] = $q['label'];
        }
        return $out;
    }
}
