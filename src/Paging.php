<?php
declare(strict_types=1);

namespace Align;

/**
 * Long lists (1.42) show 100 rows, then 100 more at a time (?limit=), with search over every row on the
 * server (?q=). Filtering and totals always use every row; only what's drawn is cut.
 *
 * Security assumptions: ?limit= and ?q= are untrusted. The limit is always a whole number from STEP to MAX and the
 * search text at most 100 characters, matched in PHP (never put into SQL here). moreUrl() keeps the current path,
 * which the Router has already matched to a route, and needs e() in HTML like any URL.
 */
final class Paging
{
    public const STEP = 100;
    public const MAX = 5000;

    /** Rows to draw: ?limit= when it is digits, kept within STEP-MAX; STEP otherwise. */
    public static function limit(): int
    {
        $l = query('limit');
        return ctype_digit($l) ? max(self::STEP, min(self::MAX, (int) $l)) : self::STEP;
    }

    /** The search text from ?q=, trimmed, at most 100 characters. */
    public static function q(): string
    {
        return mb_substr(trim(query('q')), 0, 100);
    }

    /**
     * Rows whose text fields contain every word of $q (case-insensitive). @param string[] $fields
     * A word "0" counts like any other (2.2.1: it was dropped as falsy, so searching for 0 showed every row).
     */
    public static function search(array $rows, string $q, array $fields): array
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower($q)) ?: [], fn(string $w) => $w !== '');
        if (!$words) {
            return $rows;
        }
        return array_values(array_filter($rows, function ($r) use ($words, $fields) {
            $hay = mb_strtolower(implode(' ', array_map(fn($f) => (string) ($r[$f] ?? ''), $fields)));
            foreach ($words as $w) {
                if (!str_contains($hay, $w)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** The current URL with ?limit= raised by one step (other query fields kept as they were). */
    public static function moreUrl(int $limit): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $qs = $_GET;
        $qs['limit'] = $limit + self::STEP;
        return $path . '?' . http_build_query($qs);
    }
}
