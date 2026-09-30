<?php
declare(strict_types=1);

namespace Align\Api;

/** Response shapes: {"data": ...} for one item, {"data": [...], "meta": {...}} for lists, 204 for deletes. */
final class Out
{
    public static function one(array $data, int $status = 200): array
    {
        return [$status, ['data' => $data]];
    }

    public static function list(array $rows, int $total, int $page, int $per, array $extraMeta = []): array
    {
        return [200, ['data' => array_values($rows), 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total,
            'has_more' => $page * $per < $total] + $extraMeta]];
    }

    /** Paginates an array already filtered in PHP. */
    public static function slice(array $rows): array
    {
        [$page, $per, $off] = Input::page();
        return self::list(array_slice($rows, $off, $per), count($rows), $page, $per);
    }

    public static function none(): array
    {
        return [204, null];
    }

    /** ISO 8601 with the server's offset, or null. */
    public static function ts(?string $dt): ?string
    {
        return $dt ? date('c', strtotime($dt)) : null;
    }

    public static function num(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : round((float) $v, 2);
    }

    public static function int(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    /**
     * An outside system's id (PSA): a number when it is one (so v1 answers for ITFlow stay exactly as before
     * 1.34, when PSA ids were stored as numbers), otherwise the id as text (GUIDs and other text ids).
     */
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

    /** Link into the web app for a person to open. */
    public static function url(string $path): string
    {
        return rtrim((string) \Align\Config::get('base_url', ''), '/') . $path;
    }
}
