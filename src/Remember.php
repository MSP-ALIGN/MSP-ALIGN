<?php
declare(strict_types=1);

namespace Align;

/**
 * "Remember this browser" (1.45.1): after the two-factor code, a browser can be remembered for a number of days
 * (Settings → General → Security; 0 turns it off). On that browser the password is still asked for at every
 * sign-in, only the code is skipped. The cookie holds 256 random bits; only its SHA-256 hash is stored. A remembered
 * browser stops working when it expires, is forgotten on the Account page, or the person's session_version moves
 * on: a password change, a new or reset authenticator, "sign out everywhere", being disabled.
 * Re-checks before a destructive action (a restore) always ask for the code.
 */
final class Remember
{
    public const MAX_DAYS = 30;
    public const DEFAULT_DAYS = 14;

    public static function days(): int
    {
        return max(0, min(self::MAX_DAYS, Settings::int('remember_2fa_days', self::DEFAULT_DAYS)));
    }

    /** @param 'staff'|'portal' $kind */
    private static function cookie(string $kind): array
    {
        return $kind === 'portal'
            ? [Security::cookieName('ALIGNPORTALTRUST', false), '/portal']
            : [Security::cookieName('ALIGNTRUST', true), '/'];
    }

    private static function table(string $kind): string
    {
        return $kind === 'portal' ? 'portal_users' : 'users';
    }

    /** The id of this browser's remembered row when it was remembered by this person and still counts, else null. */
    public static function valid(string $kind, int $userId): ?int
    {
        if (self::days() === 0) {
            return null;
        }
        $token = self::token($kind);
        if ($token === null) {
            return null;
        }
        $row = DB::one('SELECT r.id, r.session_version AS rv, u.session_version AS uv FROM remembered_browsers r JOIN ' . self::table($kind) . ' u ON u.id = r.user_id
            WHERE r.token_hash = ? AND r.kind = ? AND r.user_id = ? AND r.expires_at > NOW() AND r.created_at > NOW() - INTERVAL ? DAY',
            [hash('sha256', $token), $kind, $userId, self::days()]);
        if (!$row || (int) $row['rv'] !== (int) $row['uv']) {
            return null;
        }
        DB::run('UPDATE remembered_browsers SET last_used_at = NOW(), ip = ? WHERE id = ?', [mb_substr(client_ip(), 0, 64), $row['id']]);
        return (int) $row['id'];
    }

    /** This browser's cookie value, when it looks like one of ours. */
    private static function token(string $kind): ?string
    {
        [$name] = self::cookie($kind);
        $t = $_COOKIE[$name] ?? null;
        return is_string($t) && preg_match('/^[a-f0-9]{64}$/', $t) ? $t : null;
    }

    /** Everything remembered, for everyone (when an admin turns the feature off) or for one person (their account is deleted). */
    public static function forgetEveryone(?string $kind = null, ?int $userId = null): int
    {
        return $kind === null
            ? DB::run('DELETE FROM remembered_browsers')->rowCount()
            : DB::run('DELETE FROM remembered_browsers WHERE kind = ? AND user_id = ?', [$kind, (int) $userId])->rowCount();
    }

    /** Remembers this browser for the person who just entered their code. */
    public static function issue(string $kind, int $userId): void
    {
        $days = self::days();
        if ($days === 0) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $sv = (int) DB::value('SELECT session_version FROM ' . self::table($kind) . ' WHERE id = ?', [$userId]);
        DB::run('DELETE FROM remembered_browsers WHERE expires_at < NOW()');
        if ($old = self::token($kind)) { // remembered again: the earlier entry for this browser goes
            DB::run('DELETE FROM remembered_browsers WHERE token_hash = ?', [hash('sha256', $old)]);
        }
        DB::insert('remembered_browsers', [
            'kind' => $kind, 'user_id' => $userId, 'token_hash' => hash('sha256', $token), 'session_version' => $sv,
            'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400), 'last_used_at' => date('Y-m-d H:i:s'),
            'ip' => mb_substr(client_ip(), 0, 64), 'user_agent' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '', 0, 255) ?: null,
        ]);
        [$name, $path] = self::cookie($kind);
        setcookie($name, $token, ['expires' => time() + $days * 86400, 'path' => $path, 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    }

    /** This person's remembered browsers that still count, newest first. */
    public static function list(string $kind, int $userId): array
    {
        $mine = ($t = self::token($kind)) ? hash('sha256', $t) : '';
        return array_map(fn($r) => $r + ['this' => hash_equals((string) $r['token_hash'], $mine)], DB::all('SELECT r.* FROM remembered_browsers r JOIN ' . self::table($kind) . ' u ON u.id = r.user_id
            WHERE r.kind = ? AND r.user_id = ? AND r.expires_at > NOW() AND r.created_at > NOW() - INTERVAL ? DAY AND r.session_version = u.session_version ORDER BY r.id DESC',
            [$kind, $userId, self::days()]));
    }

    /** Forgets one remembered browser (by id), or all of them. Returns how many. */
    public static function forget(string $kind, int $userId, ?int $id = null): int
    {
        $n = $id === null
            ? DB::run('DELETE FROM remembered_browsers WHERE kind = ? AND user_id = ?', [$kind, $userId])->rowCount()
            : DB::run('DELETE FROM remembered_browsers WHERE kind = ? AND user_id = ? AND id = ?', [$kind, $userId, $id])->rowCount();
        [$name, $path] = self::cookie($kind);
        $t = self::token($kind);
        if ($id === null || $t === null || !DB::value('SELECT 1 FROM remembered_browsers WHERE token_hash = ?', [hash('sha256', $t)])) {
            setcookie($name, '', ['expires' => time() - 3600, 'path' => $path, 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
        }
        return $n;
    }

    /** "Chrome on Windows"-style label for the Account page. */
    public static function label(?string $ua): string
    {
        $ua = (string) $ua;
        $b = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'A browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'macOS', str_contains($ua, 'Linux') => 'Linux', default => '',
        };
        return $b . ($os ? " on $os" : '');
    }
}
