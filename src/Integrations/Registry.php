<?php
declare(strict_types=1);

namespace Align\Integrations;

/** Every integration on the Integrations page, in display order. Add new connectors here. */
final class Registry
{
    public const CONNECTORS = [
        Connectors\Itflow::class,
        Connectors\NinjaOne::class,
        Connectors\Veeam::class,
        Connectors\Email::class,
        Connectors\Dell::class,
        Connectors\Lenovo::class,
    ];

    public const CATEGORIES = ['PSA & documentation', 'RMM', 'Backup', 'Email & calendar', 'Warranty'];

    /** @return array<string, Connector> */
    public static function all(): array
    {
        static $all = null;
        if ($all === null) {
            $all = [];
            foreach (self::CONNECTORS as $c) {
                $o = new $c();
                $all[$o->key()] = $o;
            }
        }
        return $all;
    }

    public static function get(string $key): ?Connector
    {
        return self::all()[$key] ?? null;
    }

    /** Number of integrations in an error state (for the menu badge). */
    public static function problems(): int
    {
        return count(array_filter(self::all(), fn(Connector $c) => in_array($c->status()[0], ['danger'], true)));
    }
}
