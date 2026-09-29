<?php
declare(strict_types=1);

namespace Align;

/** Shared security policy: session lifetimes, password rules, safe redirects. */
final class Security
{
    public const IDLE_DEFAULT_MIN = 15;
    public const MAX_DEFAULT_HOURS = 12;

    /** Automatic logoff after this many seconds without activity (Settings → Security). */
    public static function idleSeconds(): int
    {
        $m = (int) (Settings::get('session_idle_minutes') ?: self::IDLE_DEFAULT_MIN);
        return max(5, min(60, $m)) * 60;
    }

    /** Hard limit on a signed-in session, however active. */
    public static function maxSeconds(): int
    {
        $h = (int) (Settings::get('session_max_hours') ?: self::MAX_DEFAULT_HOURS);
        return max(1, min(24, $h)) * 3600;
    }

    /** Session cookie name: __Host-/__Secure- prefixes make browsers refuse the cookie over plain HTTP or from subdomains. */
    public static function cookieName(string $base, bool $hostOnlyRoot): string
    {
        if (!is_https()) {
            return $base;
        }
        return ($hostOnlyRoot ? '__Host-' : '__Secure-') . $base;
    }

    /** Applies idle and absolute timeouts to the current session. Returns false if it just expired. */
    public static function enforceTimeouts(string $uidKey): bool
    {
        $now = time();
        if (isset($_SESSION[$uidKey])) {
            $idle = isset($_SESSION['last_seen']) && $now - (int) $_SESSION['last_seen'] > self::idleSeconds();
            $tooOld = isset($_SESSION['login_at']) && $now - (int) $_SESSION['login_at'] > self::maxSeconds();
            if ($idle || $tooOld) {
                return false;
            }
        }
        // Background pings don't count as activity unless the page reports the user was active
        if (!self::isPassivePing()) {
            $_SESSION['last_seen'] = $now;
        }
        return true;
    }

    private static function isPassivePing(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        // Background requests: the keep-alive ping (unless it reports activity) and document presence heartbeats
        return (str_ends_with($path, '/session/ping') && ($_GET['active'] ?? '') !== '1')
            || preg_match('#^/documents/\d+/presence$#', $path) === 1;
    }

    /** Password rules (NIST 800-63B style): length, not a common or obvious password. */
    public static function passwordProblem(string $pw, array $context = []): ?string
    {
        if (strlen($pw) < 12) {
            return 'Password must be at least 12 characters.';
        }
        if (strlen($pw) > 72) {
            return 'Password must be 72 characters or fewer.';
        }
        $low = strtolower($pw);
        if (count(array_unique(str_split($low))) < 5) {
            return 'Password is too repetitive. Use a mix of words or characters.';
        }
        $squash = preg_replace('/[^a-z0-9]/', '', $low) ?? '';
        foreach (['password', 'passw0rd', 'qwerty', 'letmein', 'welcome', 'changeme', 'admin', '12345678', 'abcdefgh', 'iloveyou', 'monkey', 'dragon',
                     'football', 'baseball', 'sunshine', 'princess', 'trustno1', 'mspalign', 'align', 'summer20', 'winter20', 'spring20', 'fall20'] as $bad) {
            // A common word plus a few digits or symbols ("Password123!") is still a common password
            if (str_contains($squash, $bad) && strlen(preg_replace('/[^a-z]/', '', str_replace($bad, '', $squash)) ?? '') < 4) {
                return 'That password is too common or easy to guess.';
            }
        }
        foreach ($context as $word) {
            $w = strtolower(preg_replace('/@.*/', '', (string) $word) ?? '');
            $w = preg_replace('/[^a-z0-9]/', '', $w) ?? '';
            if (strlen($w) >= 4 && str_contains($squash, $w)) {
                return 'Password must not contain your name or email.';
            }
        }
        return null;
    }

    /** One line per failed sign-in in the web server error log, for fail2ban (ip= is the real client IP, even behind a proxy). */
    public static function logAuthFailure(string $kind): void
    {
        if (PHP_SAPI !== 'cli') {
            error_log('[msp-align] auth failure kind=' . $kind . ' ip=' . client_ip());
        }
    }

    /** Argon2id (memory-hard) when PHP supports it, bcrypt otherwise. Old hashes upgrade at next sign-in. */
    public static function hashPassword(string $pw): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_hash($pw, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1])
            : password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** A hash of a random password with the same algorithm, so unknown emails take as long as real ones. */
    public static function dummyHash(): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? '$argon2id$v=19$m=65536,t=3,p=1$Y2d1S3llMXlsSzFCaG9PWA$b6iWI5eJ4wdWLJFftQnDismYrL9H/bGDdnPP38/3R2M'
            : '$2y$12$r9fR6IH/X8FpJMzyfGkOxOMHqrRiq1mTm/lxmEvv6.SEc7AqboZZG';
    }

    public static function needsRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1])
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** A same-site path to redirect to, or $default. Blocks //host, /\host, control characters and schemes. */
    public static function safePath(string $path, string $default = '/'): string
    {
        return preg_match('#^/(?![/\\\\])[^\x00-\x1f\x7f\\\\]*$#', $path) ? $path : $default;
    }

    /** Neutralizes spreadsheet formulas in exported CSV cells. */
    public static function csvCell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        if (is_numeric($s)) {
            return $s;
        }
        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
    }
}
