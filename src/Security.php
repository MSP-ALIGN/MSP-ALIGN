<?php
declare(strict_types=1);

namespace Align;

/**
 * Shared security policy: session lifetimes, password rules, password hashing, safe redirects, CSV cells, and the
 * fail2ban log line. Used by both the staff app (Auth) and the client portal (Portal\PortalAuth). Static helpers with
 * no state of their own; the session limits come from Settings (Settings -> General -> Security).
 */
final class Security
{
    public const IDLE_DEFAULT_MIN = 15;
    public const MAX_DEFAULT_HOURS = 12;

    /** Automatic logoff after this many seconds without activity (Settings → Security), clamped to 5-60 minutes. */
    public static function idleSeconds(): int
    {
        $m = (int) (Settings::get('session_idle_minutes') ?: self::IDLE_DEFAULT_MIN);
        return max(5, min(60, $m)) * 60;
    }

    /** Hard limit on a signed-in session, however active, clamped to 1-24 hours. */
    public static function maxSeconds(): int
    {
        $h = (int) (Settings::get('session_max_hours') ?: self::MAX_DEFAULT_HOURS);
        return max(1, min(24, $h)) * 3600;
    }

    /**
     * Session cookie name: __Host-/__Secure- prefixes make browsers refuse the cookie over plain HTTP or from subdomains.
     * $hostOnlyRoot: the cookie is for path '/' with no Domain, so the stricter __Host- prefix can be used.
     */
    public static function cookieName(string $base, bool $hostOnlyRoot): string
    {
        if (!is_https()) {
            return $base;
        }
        return ($hostOnlyRoot ? '__Host-' : '__Secure-') . $base;
    }

    /**
     * Applies idle and absolute timeouts to the current session. Returns false if it just expired; the caller then
     * destroys it. $uidKey is the session key that marks a signed-in session ('uid' or 'portal_uid'). Must run right
     * after session_start(), before anything reads the user. Sessions without that key (signed out, or waiting for
     * the code, which has its own 5-minute limit) never expire here.
     */
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

    /** Whether this request is a background request that must not count as activity (keeps the idle timer honest). */
    private static function isPassivePing(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        // Background requests: the keep-alive ping (unless it reports activity) and document presence heartbeats
        return (str_ends_with($path, '/session/ping') && ($_GET['active'] ?? '') !== '1')
            || preg_match('#^/documents/\d+/presence$#', $path) === 1;
    }

    /**
     * Password rules (NIST 800-63B style): length, not a common or obvious password, not the user's name or email.
     * Returns the problem in plain words, or null. $context: the user's email and name (untrusted strings).
     */
    public static function passwordProblem(string $pw, array $context = []): ?string
    {
        // Characters, not bytes: six Cyrillic letters are 12 bytes but only 6 characters
        if (mb_strlen($pw, 'UTF-8') < 12) {
            return 'Password must be at least 12 characters.';
        }
        // Bytes: bcrypt (used when Argon2id isn't available) ignores everything after byte 72
        if (strlen($pw) > 72) {
            return 'Password is too long: at most 72 bytes (letters outside A–Z can count as 2 or more).';
        }
        $low = strtolower($pw);
        if (count(array_unique(mb_str_split($low, 1, 'UTF-8'))) < 5) {
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
            // The whole name or email name ("averyquill") and each part of it ("avery", "quill"): a password built
            // on the surname alone also contains the user's name
            $parts = preg_split('/[^a-z0-9]+/', $w, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ([preg_replace('/[^a-z0-9]/', '', $w) ?? '', ...$parts] as $part) {
                if (strlen($part) >= 4 && str_contains($squash, $part)) {
                    return 'Password must not contain your name or email.';
                }
            }
        }
        return null;
    }

    /**
     * One line per failed sign-in in the web server error log, for fail2ban (ip= is the real client IP, even behind a
     * proxy). $kind is a fixed word from the caller, never user input, so the line can't be forged through it.
     */
    public static function logAuthFailure(string $kind): void
    {
        if (PHP_SAPI !== 'cli') {
            error_log('[msp-align] auth failure kind=' . $kind . ' ip=' . client_ip());
        }
    }

    /** Wrong two-factor codes in a row (after the correct password) that replace the password. */
    public const TOTP_FAILURE_LIMIT = 50;

    /**
     * Counts a wrong two-factor code for a staff ('staff') or portal ('portal') account. Codes are only asked for
     * after the correct password, so the count can't be run up by a stranger to lock someone out; a high count means
     * someone knows the password and is guessing codes (5 per 15 minutes under the lockout, ~4% success in a month
     * without this). Admins get an alert at 5 and every 25 after; at TOTP_FAILURE_LIMIT the password is replaced with
     * a random one and every session ended, so the guessing stops until an admin or a reset link sets a new one.
     * A successful code resets the count (resetSecondFactorFailures). Added in 2.2.1; NIST SP 800-63B caps
     * consecutive failures per account at 100.
     * Security: $kind picks the table from a fixed list (never request input); $u is the account's own row.
     */
    public static function secondFactorFailed(string $kind, array $u): void
    {
        $table = match ($kind) { 'staff' => 'users', 'portal' => 'portal_users' };
        $id = (int) $u['id'];
        // One statement adds and reads the count (LAST_INSERT_ID(expr) is per connection), so two wrong codes at
        // the same moment each get their own number and the alert at 5 can't be skipped
        DB::run("UPDATE $table SET totp_failures = LAST_INSERT_ID(totp_failures + 1) WHERE id = ?", [$id]);
        $n = (int) DB::value('SELECT LAST_INSERT_ID()');
        $who = ($kind === 'portal' ? 'Portal user ' : '') . $u['email'];
        if ($n >= self::TOTP_FAILURE_LIMIT) {
            DB::run("UPDATE $table SET password_hash = ?, totp_failures = 0 WHERE id = ?", [self::hashPassword(bin2hex(random_bytes(32))), $id]);
            $kind === 'portal' ? Portal\PortalAuth::revokeSessions($id) : Auth::revokeSessions($id);
            $kind === 'portal' ? Audit::log('portal.2fa_password_reset', $u['email'], null, $id) : Audit::log('login.2fa_password_reset', $u['email'], $id);
            Mail\Notify::security('Password replaced after wrong two-factor codes', "$who: $n wrong two-factor codes in a row after the correct password. "
                . 'Someone else probably knows this password. It was replaced with a random one and every session ended; '
                . ($kind === 'portal' ? 'the user can set a new one with Forgot password, or staff can send a reset link.' : 'an admin needs to set a new one.'));
        } elseif ($n === 5 || $n % 25 === 0) {
            Mail\Notify::security('Wrong two-factor codes after a correct password', "$who: $n wrong codes in a row. Someone may know this password.");
        }
    }

    /** Clears the wrong-code count after a correct code (see secondFactorFailed). $kind as there. */
    public static function resetSecondFactorFailures(string $kind, int $id): void
    {
        $table = match ($kind) { 'staff' => 'users', 'portal' => 'portal_users' };
        DB::run("UPDATE $table SET totp_failures = 0 WHERE id = ? AND totp_failures <> 0", [$id]);
    }

    /** Argon2id (memory-hard) when PHP supports it, bcrypt otherwise. Old hashes upgrade at next sign-in (needsRehash). */
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

    /** Whether a stored hash uses an older algorithm or cost than hashPassword(); checked after a correct password. */
    public static function needsRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1])
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * A same-site path to redirect to, or $default. Blocks //host, /\host, control characters and schemes. $path is
     * untrusted (a ?next= or back= field); $default must be a path the caller wrote. The D modifier makes $ the true
     * end: without it "/x\n" passed, and header() then refused the Location line.
     */
    public static function safePath(string $path, string $default = '/'): string
    {
        return preg_match('#^/(?![/\\\\])[^\x00-\x1f\x7f\\\\]*$#D', $path) ? $path : $default;
    }

    /** Neutralizes spreadsheet formulas in exported CSV cells: a leading = + - @ tab or CR gets a ' (numbers are left as they are). */
    public static function csvCell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        if (is_numeric($s)) {
            return $s;
        }
        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
    }
}
