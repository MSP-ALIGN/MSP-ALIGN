<?php
declare(strict_types=1);

namespace Align;

/**
 * How money, numbers, dates and times are shown (1.38, Settings → General → Currency & dates). One choice
 * for the whole install, so reports and client emails look the same whoever sends them. It only changes
 * how values are written: amounts are never converted, and stored dates are unchanged. The defaults are
 * the US style MSP-ALIGN always used ($1,234 · Sep 29, 2026 · 2:30 pm).
 *
 * Security assumptions: the choices come from Settings but only values in the lists below are used (anything else
 * falls back to the default), so the formats are always these literals. Results are plain text: escape them in
 * HTML. Dates that can't be read give '' rather than a wrong date.
 */
final class Fmt
{
    /** code => [label, symbol, usual position, decimals] */
    public const CURRENCIES = [
        'USD' => ['US dollar', '$', 'before', 2], 'CAD' => ['Canadian dollar', '$', 'before', 2], 'AUD' => ['Australian dollar', '$', 'before', 2],
        'NZD' => ['New Zealand dollar', '$', 'before', 2], 'GBP' => ['Pound sterling', '£', 'before', 2], 'EUR' => ['Euro', '€', 'before', 2],
        'CHF' => ['Swiss franc', 'CHF', 'before', 2], 'SEK' => ['Swedish krona', 'kr', 'after', 2], 'NOK' => ['Norwegian krone', 'kr', 'after', 2],
        'DKK' => ['Danish krone', 'kr', 'after', 2], 'PLN' => ['Polish złoty', 'zł', 'after', 2], 'ZAR' => ['South African rand', 'R', 'before', 2],
        'INR' => ['Indian rupee', '₹', 'before', 2], 'SGD' => ['Singapore dollar', '$', 'before', 2], 'HKD' => ['Hong Kong dollar', '$', 'before', 2],
        'JPY' => ['Japanese yen', '¥', 'before', 0], 'MXN' => ['Mexican peso', '$', 'before', 2], 'BRL' => ['Brazilian real', 'R$', 'before', 2],
        'AED' => ['UAE dirham', 'AED', 'before', 2], 'PHP' => ['Philippine peso', '₱', 'before', 2],
    ];
    /** key => [label, thousands, decimal] (the space is a narrow no-break space so amounts never wrap) */
    public const NUMBERS = ['comma' => ['1,234.56', ',', '.'], 'dot' => ['1.234,56', '.', ','], 'space' => ['1 234,56', "\u{202F}", ','], 'apostrophe' => ["1'234.56", "'", '.']];
    public const DATES = ['mdy' => 'Sep 29, 2026', 'dmy' => '29 Sep 2026', 'iso' => '2026-09-29'];
    public const TIMES = ['12' => '2:30 pm', '24' => '14:30'];
    public const WEEK = ['0' => 'Sunday', '1' => 'Monday'];

    /** Date styles => [mdy, dmy, iso] PHP date() formats. */
    private const DATE_STYLES = [
        'date' => ['M j, Y', 'j M Y', 'Y-m-d'],                 // Sep 29, 2026
        'long' => ['F j, Y', 'j F Y', 'j F Y'],                 // September 29, 2026 (reports, letters)
        'day' => ['D M j, Y', 'D j M Y', 'D Y-m-d'],            // Tue Sep 29, 2026
        'dayfull' => ['l, F j, Y', 'l j F Y', 'l j F Y'],       // Tuesday, September 29, 2026
        'weekday' => ['l, F j', 'l j F', 'l j F'],              // Tuesday, September 29
        'short' => ['M j', 'j M', 'j M'],                       // Sep 29
        'dayshort' => ['D M j', 'D j M', 'D j M'],              // Tue Sep 29
        'month' => ['M Y', 'M Y', 'M Y'],                       // Sep 2026
        'monthlong' => ['F Y', 'F Y', 'F Y'],                   // September 2026
    ];

    private static ?array $cfg = null;

    /** The current choices (read once per request), each checked against its list. */
    public static function cfg(): array
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }
        $cur = strtoupper((string) Settings::get('locale_currency', 'USD'));
        $cur = isset(self::CURRENCIES[$cur]) ? $cur : 'USD';
        $pick = fn(string $k, array $list, string $def) => isset($list[$v = (string) Settings::get($k, $def)]) ? $v : $def;
        $pos = (string) Settings::get('locale_currency_position', '');
        return self::$cfg = [
            'currency' => $cur,
            'symbol' => self::CURRENCIES[$cur][1],
            'position' => in_array($pos, ['before', 'after'], true) ? $pos : self::CURRENCIES[$cur][2],
            'decimals' => self::CURRENCIES[$cur][3],
            'number' => $pick('locale_number', self::NUMBERS, 'comma'),
            'date' => $pick('locale_date', self::DATES, 'mdy'),
            'time' => $pick('locale_time', self::TIMES, '12'),
            'week' => $pick('locale_week_start', self::WEEK, '0'),
        ];
    }

    /** Forget the cached choices (after saving them, and in tests). */
    public static function reset(): void
    {
        self::$cfg = null;
    }

    /** Font Awesome icon for money in the chosen currency. */
    public static function icon(): string
    {
        return ['$' => 'fa-dollar-sign', '£' => 'fa-sterling-sign', '€' => 'fa-euro-sign', '¥' => 'fa-yen-sign', '₹' => 'fa-indian-rupee-sign'][self::symbol()] ?? 'fa-coins';
    }

    /** The chosen currency's symbol ($, €, CHF...). */
    public static function symbol(): string
    {
        return self::cfg()['symbol'];
    }

    /** Whether the symbol goes after the amount (1.234 €) rather than before ($1,234). */
    public static function symbolAfter(): bool
    {
        return self::cfg()['position'] === 'after';
    }

    /** A number with the chosen separators; anything not numeric counts as 0. */
    public static function number(float|int|string|null $v, int $decimals = 0): string
    {
        [, $th, $dec] = self::NUMBERS[self::cfg()['number']];
        return number_format((float) $v, $decimals, $dec, $th);
    }

    /** Up to $decimals decimals, trailing zeros dropped: 97.5, 98, 1,234.5 (percentages, sizes). */
    public static function trim(float|int|string|null $v, int $decimals = 1): string
    {
        $dec = self::NUMBERS[self::cfg()['number']][2];
        $n = self::number($v, $decimals);
        return $decimals > 0 && str_contains($n, $dec) ? rtrim(rtrim($n, '0'), $dec) : $n;
    }

    /** $1,234 (whole units), or with cents: $4.50 when $exact and there are any. */
    public static function money(float|int|string|null $v, bool $exact = false): string
    {
        $f = (float) $v;
        $dec = 0;
        if ($exact && self::cfg()['decimals'] > 0) {
            $f = round($f, 2);
            $dec = fmod($f, 1.0) == 0.0 ? 0 : 2;
        }
        return self::withSymbol(self::number($f, $dec));
    }

    /**
     * Short amounts for charts: $950, $12.5k, $1.2M. Below 1,000 (and negative amounts) it's the full amount.
     * $millions false keeps thousands only ($1,250k); $group false leaves out the thousands separator ($1250k).
     */
    public static function moneyShort(float|int|string|null $v, bool $millions = true, bool $group = true): string
    {
        $f = (float) $v;
        $dec = self::NUMBERS[self::cfg()['number']][2];
        $short = function (float $n, string $unit) use ($group, $dec) {
            if ($group) {
                return self::trim($n, 1) . $unit;
            }
            $t = number_format($n, 1, $dec, '');
            return rtrim(rtrim($t, '0'), $dec) . $unit;
        };
        return match (true) {
            $millions && $f >= 1000000 => self::withSymbol($short($f / 1000000, 'M')),
            $f >= 1000 => self::withSymbol($short($f / 1000, 'k')),
            default => self::money($f),
        };
    }

    /** The symbol goes in front of the number as written, sign included ($-980, as MSP-ALIGN always showed it), or after it (-980 €). */
    private static function withSymbol(string $n): string
    {
        $s = self::symbol();
        if (self::symbolAfter()) {
            return $n . "\u{00A0}" . $s;
        }
        // A letter symbol (CHF, AED, kr) needs a space; $ £ € ¥ sit next to the number
        return $s . (preg_match('/\p{L}$/u', $s) ? "\u{00A0}" : '') . $n;
    }

    /** A date in one of the styles above, from a date string or a timestamp; '' for none. */
    public static function date(string|int|null $d, string $style = 'date'): string
    {
        $ts = is_int($d) ? $d : ($d !== null && $d !== '' ? strtotime($d) : false);
        if ($ts === false || $ts === null) {
            return '';
        }
        $i = array_search(self::cfg()['date'], array_keys(self::DATES), true);
        return date((self::DATE_STYLES[$style] ?? self::DATE_STYLES['date'])[$i], $ts);
    }

    /** 2:30 pm or 14:30 (with seconds: 2:30:05 pm / 14:30:05). */
    public static function time(string|int|null $d, bool $seconds = false): string
    {
        $ts = is_int($d) ? $d : ($d !== null && $d !== '' ? strtotime($d) : false);
        if ($ts === false || $ts === null) {
            return '';
        }
        return date(self::cfg()['time'] === '24' ? ($seconds ? 'H:i:s' : 'H:i') : ($seconds ? 'g:i:s a' : 'g:i a'), $ts);
    }

    /** An hour of the day for pickers: 7 am / 07:00. */
    public static function hour(int $h): string
    {
        return self::cfg()['time'] === '24' ? sprintf('%02d:00', $h) : date('g a', mktime($h, 0));
    }

    /** Date and time: Tue Sep 29, 2026 · 2:30 pm. */
    public static function dateTime(string|int|null $d, string $style = 'day', string $sep = ' · ', bool $seconds = false): string
    {
        $date = self::date($d, $style);
        return $date === '' ? '' : $date . $sep . self::time($d, $seconds);
    }

    /** What the browser needs (license cost preview, contract dates, the calendar). Put it in a data attribute with e(json_encode()). */
    public static function forJs(): array
    {
        $c = self::cfg();
        [, $th, $dec] = self::NUMBERS[$c['number']];
        return ['symbol' => $c['symbol'], 'after' => $c['position'] === 'after', 'decimals' => $c['decimals'], 'thousands' => $th, 'decimal' => $dec,
            'date' => $c['date'], 'hour24' => $c['time'] === '24', 'weekStart' => (int) $c['week']];
    }

    // ---- Timezone ---------------------------------------------------------------------------

    /** The zones offered in Settings, grouped by region (PHP's list). */
    public static function zones(): array
    {
        $out = [];
        foreach (\DateTimeZone::listIdentifiers() as $z) {
            $out[strstr($z, '/', true) ?: 'Other'][] = $z;
        }
        return $out;
    }

    /** Whether $z (untrusted) is one of PHP's timezone names. */
    public static function validZone(string $z): bool
    {
        return in_array($z, \DateTimeZone::listIdentifiers(), true) || $z === 'UTC';
    }
}
