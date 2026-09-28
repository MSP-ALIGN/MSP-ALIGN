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

    /** Link into the web app for a person to open. */
    public static function url(string $path): string
    {
        return rtrim((string) \Align\Config::get('base_url', ''), '/') . $path;
    }
}
