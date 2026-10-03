<?php
declare(strict_types=1);

namespace Align;

/**
 * Install-wide settings (the settings table): plain values, and secrets stored encrypted with app_key (Crypto).
 *
 * Security assumptions: setting names are written in code, never taken from a request. get() never returns a
 * secret (it gives the default instead), so a plain lookup can't put a password or API key into a page; only
 * secret() decrypts, and its callers must not echo the result. Values from get() are untrusted text for output
 * purposes: escape them in HTML and validate them before use (Branding::color(), LegalController::sourceUrl()).
 */
final class Settings
{
    /** Every row, read once per request (and again after a write). */
    private static ?array $cache = null;

    /** All settings rows by name (values of secrets still encrypted). */
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

    /** A plain setting's value, or $default when it is unset or is a secret. */
    public static function get(string $name, ?string $default = null): ?string
    {
        $all = self::loadAll();
        if (!isset($all[$name]) || $all[$name]['is_secret']) {
            return $default;
        }
        return $all[$name]['value'] ?? $default;
    }

    /** A setting as a whole number (a decimal is cut), or $default when unset or not numeric. */
    public static function int(string $name, int $default = 0): int
    {
        $v = self::get($name);
        return is_numeric($v) ? (int) $v : $default;
    }

    /** A setting as a number, or $default when unset or not numeric. */
    public static function float(string $name, float $default = 0.0): float
    {
        $v = self::get($name);
        return is_numeric($v) ? (float) $v : $default;
    }

    /**
     * Saves a plain value (null clears it). Writing a secret's name this way replaces the secret with plain text, so
     * callers use only names that are never secrets. The caller validates the value and audits the change.
     */
    public static function set(string $name, ?string $value): void
    {
        DB::upsert('settings', ['name' => $name, 'value' => $value, 'is_secret' => 0], ['name']);
        self::$cache = null;
    }

    /** A secret's decrypted value, or null when unset or not a secret (Crypto throws on a tampered value). Never print it. */
    public static function secret(string $name): ?string
    {
        $all = self::loadAll();
        if (!isset($all[$name]) || !$all[$name]['is_secret']) {
            return null;
        }
        return Crypto::decrypt($all[$name]['value']);
    }

    /** Whether a secret is saved (what forms show instead of the value). */
    public static function hasSecret(string $name): bool
    {
        $all = self::loadAll();
        return isset($all[$name]) && $all[$name]['is_secret'] && $all[$name]['value'] !== '';
    }

    /** Saves a secret, encrypted with app_key (libsodium). */
    public static function setSecret(string $name, string $value): void
    {
        DB::upsert('settings', ['name' => $name, 'value' => Crypto::encrypt($value), 'is_secret' => 1], ['name']);
        self::$cache = null;
    }

    /** Deletes a setting (used for secrets; works for any name). */
    public static function clearSecret(string $name): void
    {
        DB::run('DELETE FROM settings WHERE name = ?', [$name]);
        self::$cache = null;
    }
}
