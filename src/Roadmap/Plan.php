<?php
declare(strict_types=1);

namespace Align\Roadmap;

use Align\Settings;

/**
 * The 3-year planning calendar: 3 (fiscal) years x 4 quarters, starting with the
 * year that contains today. Anything overdue lands in the current quarter.
 */
final class Plan
{
    public const YEARS = 3;

    private static ?array $cache = null;

    public static function startMonth(): int
    {
        $m = Settings::int('fiscal_year_start', 1);
        return $m >= 1 && $m <= 12 ? $m : 1;
    }

    /** Fiscal year number = calendar year in which that fiscal year ends. */
    private static function fyOf(int $ts): int
    {
        $start = self::startMonth();
        $y = (int) date('Y', $ts);
        $m = (int) date('n', $ts);
        return $start === 1 ? $y : ($m >= $start ? $y + 1 : $y);
    }

    /** @return array<int, array{index:int,start:string,end:string,label:string,short:string,year:int,year_label:string,past:bool,current:bool}> */
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

    /** @return array<int, array{label:string,from:string,to:string}> */
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

    /** First day of the quarter containing $date (for storing roadmap targets). */
    public static function quarterStart(string $date): ?string
    {
        foreach (self::quarters() as $q) {
            if ($date >= $q['start'] && $date <= $q['end']) {
                return $q['start'];
            }
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    /** The (fiscal) quarter containing any date: ['start' => first day, 'label' => "Q3 2027" or "FY2027 Q1"]. */
    public static function quarterFor(string $date): ?array
    {
        $ts = strtotime(substr($date, 0, 10));
        if (!$ts) {
            return null;
        }
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

    /** Quarters to choose from for a planned replacement: this quarter and the next $years years. [start => label] */
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
