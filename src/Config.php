<?php
declare(strict_types=1);

namespace Align;

/**
 * Reads the server config file written by the installer.
 * Default path: /etc/msp-align/config.php (override with ALIGN_CONFIG env var). Before 1.35 it was
 * /etc/mountaineer-align/config.php, still used on a server where /etc/msp-align/config.php isn't there yet.
 *
 * Security assumptions: the file is PHP run with require, so it is trusted code. The installer and the Docker
 * entrypoint make it root:www-data 0640, because it holds app_key and the database password. Nothing from a
 * request ever chooses the path.
 */
final class Config
{
    /** The loaded config array. */
    private static array $data = [];

    /** Where the config file is: ALIGN_CONFIG (set by the server, the CLI or the tests), else the default path. */
    public static function path(): string
    {
        return getenv('ALIGN_CONFIG') ?: (is_readable('/etc/msp-align/config.php') || !is_readable('/etc/mountaineer-align/config.php')
            ? '/etc/msp-align/config.php' : '/etc/mountaineer-align/config.php');
    }

    /** Loads the config file. Throws when it isn't readable or doesn't return an array (bootstrap stops there). */
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

    /** A value by dotted key ("db.name"), or $default when any part of the key is missing. */
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
