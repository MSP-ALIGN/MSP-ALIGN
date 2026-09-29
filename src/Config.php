<?php
declare(strict_types=1);

namespace Align;

/**
 * Reads the server config file written by the installer.
 * Default path: /etc/msp-align/config.php (override with ALIGN_CONFIG env var). Before 1.35 it was
 * /etc/mountaineer-align/config.php, still used on a server where /etc/msp-align/config.php isn't there yet.
 */
final class Config
{
    private static array $data = [];

    public static function path(): string
    {
        return getenv('ALIGN_CONFIG') ?: (is_readable('/etc/msp-align/config.php') || !is_readable('/etc/mountaineer-align/config.php')
            ? '/etc/msp-align/config.php' : '/etc/mountaineer-align/config.php');
    }

    public static function load(): void
    {
        $path = self::path();
        if (!is_readable($path)) {
            throw new \RuntimeException("Config file not readable: $path");
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException("Config file must return an array: $path");
        }
        self::$data = $data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}
