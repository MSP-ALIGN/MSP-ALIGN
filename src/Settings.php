<?php
declare(strict_types=1);

namespace Align;

final class Settings
{
    private static ?array $cache = null;

    private static function loadAll(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (DB::all('SELECT name, value, is_secret FROM settings') as $r) {
                self::$cache[$r['name']] = $r;
            }
        }
        return self::$cache;
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $all = self::loadAll();
        if (!isset($all[$name]) || $all[$name]['is_secret']) {
            return $default;
        }
        return $all[$name]['value'] ?? $default;
    }

    public static function int(string $name, int $default = 0): int
    {
        $v = self::get($name);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function float(string $name, float $default = 0.0): float
    {
        $v = self::get($name);
        return is_numeric($v) ? (float) $v : $default;
    }

    public static function set(string $name, ?string $value): void
    {
        DB::upsert('settings', ['name' => $name, 'value' => $value, 'is_secret' => 0], ['name']);
        self::$cache = null;
    }

    public static function secret(string $name): ?string
    {
        $all = self::loadAll();
        if (!isset($all[$name]) || !$all[$name]['is_secret']) {
            return null;
        }
        return Crypto::decrypt($all[$name]['value']);
    }

    public static function hasSecret(string $name): bool
    {
        $all = self::loadAll();
        return isset($all[$name]) && $all[$name]['is_secret'] && $all[$name]['value'] !== '';
    }

    public static function setSecret(string $name, string $value): void
    {
        DB::upsert('settings', ['name' => $name, 'value' => Crypto::encrypt($value), 'is_secret' => 1], ['name']);
        self::$cache = null;
    }

    public static function clearSecret(string $name): void
    {
        DB::run('DELETE FROM settings WHERE name = ?', [$name]);
        self::$cache = null;
    }
}
