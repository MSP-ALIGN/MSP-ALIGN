<?php
declare(strict_types=1);

namespace Align;

/**
 * RFC 6238 TOTP (30s, 6 digits, SHA1) - compatible with standard authenticator apps. Pure functions: no database,
 * no session. Callers keep secrets encrypted at rest (Crypto) and enforce one use per code (totp_last_step) and the
 * sign-in lockout; this class only does the maths.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    /** Shortest key accepted for checking codes: RFC 4226 (R6) asks for at least 128 bits; ours are 160. */
    private const MIN_KEY_BYTES = 16;

    /** A new random secret (160 bits by default), base32 for the authenticator app. */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** The otpauth:// URI shown as a QR code at setup. $account (an email) and $issuer are URL-encoded. */
    public static function uri(string $secret, string $account, ?string $issuer = null): string
    {
        $issuer ??= Branding::name();
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer)
        );
    }

    /** The 6-digit code for $secret at $time (now by default). */
    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verifies a code and returns the time-step it matched, or null. Pass the last step accepted for
     * this user to refuse replays: a code (or an older one) can only be used once. $code is untrusted input; the
     * comparison is constant-time. $window steps either side allow for clock drift (one step = 30 seconds).
     * The caller must still store the returned step atomically (see Auth::useStep) for parallel requests.
     */
    public static function verifyStep(string $secret, string $code, ?int $lastStep = null, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        // A blank or garbled secret decodes to a short or empty key, whose codes anyone can work out: never accept them
        if (strlen($code) !== 6 || strlen(self::base32Decode($secret)) < self::MIN_KEY_BYTES) {
            return null;
        }
        $now = intdiv(time(), 30);
        for ($i = -$window; $i <= $window; $i++) {
            $step = $now + $i;
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step * 30), $code)) {
                return $step;
            }
        }
        return null;
    }

    /**
     * Whether $code is valid now, WITHOUT replay protection. Not used for sign-in or confirmations (they use
     * verifyStep with the last step); kept for callers that only test a secret.
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6 || strlen(self::base32Decode($secret)) < self::MIN_KEY_BYTES) {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $now + $i * 30), $code)) {
                return true;
            }
        }
        return false;
    }

    /** RFC 4648 base32 without padding. */
    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    /** RFC 4648 base32 to bytes; characters outside the alphabet (spaces, padding) are skipped, trailing bits dropped. */
    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
