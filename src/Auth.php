<?php
declare(strict_types=1);

namespace Align;

final class Auth
{
    private const IDLE_TIMEOUT = 8 * 3600;
    private const MAX_FAILURES = 5;
    private const FAILURE_WINDOW_MIN = 15;

    private static ?array $user = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('ALIGNSESS');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        $savePath = Config::get('session_path');
        if ($savePath && is_dir($savePath)) {
            session_save_path($savePath);
        }
        session_start();

        if (isset($_SESSION['uid'], $_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > self::IDLE_TIMEOUT) {
            self::logout();
            session_start();
        }
        $_SESSION['last_seen'] = time();
    }

    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['uid'])) {
            $u = DB::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$_SESSION['uid']]);
            if (!$u) {
                self::logout();
                return null;
            }
            self::$user = $u;
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    public static function isLockedOut(string $email): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::FAILURE_WINDOW_MIN * 60);
        $byIp = (int) DB::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?',
            [client_ip(), $since]
        );
        $byEmail = (int) DB::value(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > ?',
            [$email, $since]
        );
        return $byIp >= self::MAX_FAILURES * 2 || $byEmail >= self::MAX_FAILURES;
    }

    private static function recordAttempt(string $email, bool $ok): void
    {
        DB::insert('login_attempts', ['ip' => client_ip(), 'email' => $email, 'success' => $ok ? 1 : 0]);
        DB::run('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 30)]);
    }

    /**
     * Step 1: password. Returns 'ok', '2fa', 'locked' or 'invalid'.
     */
    public static function attempt(string $email, string $password): string
    {
        $email = strtolower(trim($email));
        if (self::isLockedOut($email)) {
            return 'locked';
        }
        $u = DB::one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
        // Always run a hash check to keep response timing consistent.
        $hash = $u['password_hash'] ?? '$2y$12$r9fR6IH/X8FpJMzyfGkOxOMHqrRiq1mTm/lxmEvv6.SEc7AqboZZG';
        if (!password_verify($password, $hash) || !$u) {
            self::recordAttempt($email, false);
            Audit::log('login.failed', $email, $u['id'] ?? null);
            return 'invalid';
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            DB::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        if ($u['totp_enabled']) {
            session_regenerate_id(true);
            $_SESSION['pending_2fa'] = ['uid' => (int) $u['id'], 'at' => time()];
            return '2fa';
        }
        self::recordAttempt($email, true);
        self::completeLogin((int) $u['id']);
        return 'ok';
    }

    /** Step 2: TOTP code. */
    public static function verifySecondFactor(string $code): string
    {
        $p = $_SESSION['pending_2fa'] ?? null;
        if (!$p || time() - $p['at'] > 300) {
            unset($_SESSION['pending_2fa']);
            return 'expired';
        }
        $u = DB::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$p['uid']]);
        if (!$u || self::isLockedOut($u['email'])) {
            return 'locked';
        }
        $secret = Crypto::decrypt($u['totp_secret_enc']);
        if (!$secret || !Totp::verify($secret, $code)) {
            self::recordAttempt($u['email'], false);
            Audit::log('login.2fa_failed', $u['email'], (int) $u['id']);
            return 'invalid';
        }
        unset($_SESSION['pending_2fa']);
        self::recordAttempt($u['email'], true);
        self::completeLogin((int) $u['id']);
        return 'ok';
    }

    private static function completeLogin(int $uid): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        $_SESSION['last_seen'] = time();
        DB::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$uid]);
        Audit::log('login.success', '', $uid);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$user = null;
    }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) {
            redirect('/login', ['next' => $_SERVER['REQUEST_URI'] ?? '/']);
        }
        return $u;
    }

    /** Role hierarchy: viewer < tech < admin */
    public static function can(string $role): bool
    {
        $rank = ['viewer' => 1, 'tech' => 2, 'admin' => 3];
        $u = self::user();
        return $u && ($rank[$u['role']] ?? 0) >= ($rank[$role] ?? 99);
    }

    public static function requireRole(string $role): array
    {
        $u = self::require();
        if (!self::can($role)) {
            http_response_code(403);
            View::render('error', ['title' => 'Not allowed', 'message' => 'Your account does not have access to this page.']);
            exit;
        }
        return $u;
    }

    public static function validatePassword(string $pw): ?string
    {
        if (strlen($pw) < 12) {
            return 'Password must be at least 12 characters.';
        }
        return null;
    }
}
