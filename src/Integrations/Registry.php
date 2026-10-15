<?php
declare(strict_types=1);

namespace Align\Integrations;

/**
 * Every integration on the Integrations page, in display order. Add new connectors here (`align make:connector`
 * does it for you; see docs/CONNECTORS.md).
 *
 * Security assumptions: the list is code, so a key from the URL can only ever pick one of these classes.
 */
final class Registry
{
    public const CONNECTORS = [
        Connectors\Itflow::class,
        Connectors\HaloPsa::class, // 2.12.0 (beta)
        Connectors\NinjaOne::class,
        Connectors\TacticalRmm::class, // 2.11.0 (beta)
        Connectors\DattoRmm::class, // 2.13.0 (beta)
        Connectors\Veeam::class,
        Connectors\Email::class,
        Connectors\Microsoft365::class, // 2.6.0
        Connectors\GoogleWorkspace::class, // 2.6.3
        Connectors\Huntress::class, // 2.7.0
        Connectors\Curricula::class, // 2.7.2
        Connectors\KnowBe4::class, // 2.14.0 (beta)
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
            foreach ([...self::CONNECTORS, ...self::templates()] as $c) {
                $o = new $c();
                $all[$o->key()] ??= $o; // a key already taken keeps its first connector
            }
        }
        return $all;
    }

    /**
     * 2.22.0 The connector templates (src/Templates, the starting point of `align make:connector`) switched on in
     * config.php for development and the test suites ('dev_connectors' => [class, ...]). Only classes under
     * Align\Templates\ that are connectors are taken; an install never lists them unless its config file does.
     */
    private static function templates(): array
    {
        $list = \Align\Config::get('dev_connectors', []);
        return array_values(array_filter(is_array($list) ? $list : [], fn($c) => is_string($c) && str_starts_with($c, 'Align\\Templates\\')
            && class_exists($c) && is_subclass_of($c, Connector::class) && (new \ReflectionClass($c))->isInstantiable()));
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
