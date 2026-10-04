<?php
declare(strict_types=1);

namespace Align\Api;

/**
 * Response shapes: {"data": ...} for one item, {"data": [...], "meta": {...}} for lists, 204 for deletes.
 *
 * Security: these only shape data. The resource must already have limited it to what the key may read (scope and
 * clients); nothing here filters.
 */
final class Out
{
    /** [status, {"data": $data}] for a handler to return. */
    public static function one(array $data, int $status = 200): array
    {
        return [$status, ['data' => $data]];
    }

    /** [200, {"data": [...], "meta": {page, per_page, total, has_more}}]. $total is the count before paging. */
    public static function list(array $rows, int $total, int $page, int $per, array $extraMeta = []): array
    {
        return [200, ['data' => array_values($rows), 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total,
            'has_more' => $page * $per < $total] + $extraMeta]];
    }

    /** Paginates an array already filtered in PHP (including by the key's clients) using ?page and ?per_page. */
    public static function slice(array $rows): array
    {
        [$page, $per, $off] = Input::page();
        return self::list(array_slice($rows, $off, $per), count($rows), $page, $per);
    }

    /** [204, null]: no body. */
    public static function none(): array
    {
        return [204, null];
    }

    /** ISO 8601 with the server's offset, or null (also for a value that isn't a date, instead of a TypeError). */
    public static function ts(?string $dt): ?string
    {
        $t = $dt ? strtotime($dt) : false;
        return $t !== false ? date('c', $t) : null;
    }

    /** A money or decimal value rounded to 2 places, or null. */
    public static function num(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : round((float) $v, 2);
    }

    /** An integer, or null for null or ''. */
    public static function int(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    /**
     * A record's id in another system (the PSA): always text (2.0), whatever the PSA uses, so every integration reads
     * one type ("57" for ITFlow, "0017R00002xYzAbQ" elsewhere). Null when there is none.
     */
    public static function extId(mixed $v): ?string
    {
        $s = ext_id($v);
        return $s === '' ? null : $s;
    }

    /** For the deprecated itflow_* aliases, which were always numbers: the id when it's a number, otherwise null. */
    public static function numId(mixed $v): ?int
    {
        $s = ext_id($v);
        return preg_match('/^[1-9][0-9]{0,17}$/', $s) ? (int) $s : null;
    }

    /**
     * A record's source as the API reports it, so v1 answers stay the same as before the data became
     * provider-neutral: the PSA's key ("itflow") for PSA records, "ninja" for NinjaOne devices, the
     * RMM's key for other RMMs, otherwise manual.
     */
    public static function source(?string $s, ?string $rmmProvider = null): ?string
    {
        return match ($s) {
            'psa' => \Align\Providers\Providers::psaKey() ?? 'psa',
            'rmm' => $rmmProvider === 'ninjaone' ? 'ninja' : ($rmmProvider ?? 'rmm'),
            default => $s,
        };
    }

    /** Link into the web app for a person to open: the configured base_url, never the request's Host header. */
    public static function url(string $path): string
    {
        return rtrim((string) \Align\Config::get('base_url', ''), '/') . $path;
    }
}
