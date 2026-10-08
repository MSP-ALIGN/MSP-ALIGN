<?php
declare(strict_types=1);

namespace Align\Integrations;

/**
 * Every integration on the Integrations page, in display order. Add new connectors here.
 *
 * Security assumptions: the list is code, so a key from the URL can only ever pick one of these classes.
 */
final class Registry
{
    public const CONNECTORS = [
        Connectors\Itflow::class,
        Connectors\NinjaOne::class,
        Connectors\Veeam::class,
        Connectors\Email::class,
        Connectors\Microsoft365::class, // 2.6.0
        Connectors\GoogleWorkspace::class, // 2.6.3
        Connectors\Huntress::class, // 2.7.0
        Connectors\Dell::class,
        Connectors\Lenovo::class,
    ];

    public const CATEGORIES = ['PSA & documentation', 'RMM', 'Backup', 'Microsoft 365', 'Google Workspace', 'Security', 'Email & calendar', 'Warranty'];

    /**
     * One instance of each connector, keyed by key() (made once per request).
     * @return array<string, Connector>
     */
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

    /** The connector for a key from the URL (untrusted: only an exact match is returned), or null. */
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
