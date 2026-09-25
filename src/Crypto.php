<?php
declare(strict_types=1);

namespace Align;

/**
 * Symmetric encryption for stored secrets (API keys, TOTP secrets).
 * Key lives only in the server config file, never in the database.
 */
final class Crypto
{
    private static function key(): string
    {
        $raw = (string) Config::get('app_key', '');
        if (str_starts_with($raw, 'base64:')) {
            $raw = base64_decode(substr($raw, 7), true) ?: '';
        }
        if (strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('app_key in config must be 32 bytes (base64:...)');
        }
        return $raw;
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $blob): ?string
    {
        if ($blob === null || $blob === '') {
            return null;
        }
        if (!str_starts_with($blob, 'v1:')) {
            throw new \RuntimeException('Unknown ciphertext format');
        }
        $bin = base64_decode(substr($blob, 3), true);
        if ($bin === false || strlen($bin) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Corrupt ciphertext');
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        if ($plain === false) {
            throw new \RuntimeException('Unable to decrypt secret (was app_key changed?)');
        }
        return $plain;
    }
}
