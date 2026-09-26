<?php
declare(strict_types=1);

namespace Align;

final class Auth
{
    private const MAX_FAILURES = 5;
    private const FAILURE_WINDOW_MIN = 15;

    private static ?array $user = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(Security::cookieName('ALIGNSESS', true));
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

        if (!Security::enforceTimeouts('uid')) {
            $uid = (int) $_SESSION['uid'];
            self::logout();
            session_start();
            $_SESSION['timed_out'] = true;
            Audit::log('logout.timeout', '', $uid);
        }
    }

    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['uid'])) {
            $u = DB::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$_SESSION['uid']]);
            // A password change, 2FA reset or "sign out everywhere" bumps session_version and ends other sessions
            if (!$u || (int) $u['session_version'] !== (int) ($_SESSION['sv'] ?? -1)) {
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
        $hash = ($u['password_hash'] ?? null) ?: \Align\Security::dummyHash();
        if (!password_verify($password, $hash) || !$u) {
            self::recordAttempt($email, false);
            // Only log the email when it is one (never whatever was typed, which could be a password)
            Audit::log('login.failed', filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '(not an email address)', $u['id'] ?? null);
            Security::logAuthFailure('staff');
            if ($u && self::isLockedOut($email)) {
                \Align\Mail\Notify::security('Staff account locked out', "$email: too many failed sign-ins");
            }
            return 'invalid';
        }
        if (Security::needsRehash($u['password_hash'])) {
            DB::run('UPDATE users SET password_hash = ? WHERE id = ?', [\Align\Security::hashPassword($password), $u['id']]);
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
        $step = $secret ? Totp::verifyStep($secret, $code, $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null) : null;
        if ($step === null) {
            self::recordAttempt($u['email'], false);
            Audit::log('login.2fa_failed', $u['email'], (int) $u['id']);
            Security::logAuthFailure('staff-2fa');
            return 'invalid';
        }
        DB::run('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $u['id']]);
        unset($_SESSION['pending_2fa']);
        self::recordAttempt($u['email'], true);
        self::completeLogin((int) $u['id']);
        return 'ok';
    }

    /** Re-checks the signed-in user's current two-factor code before a destructive action (replays refused). */
    public static function confirmCode(string $code): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        $row = DB::one('SELECT email, totp_secret_enc, totp_last_step FROM users WHERE id = ?', [$u['id']]);
        if (!$row || self::isLockedOut($row['email'])) {
            return false;
        }
        $secret = $row['totp_secret_enc'] ? Crypto::decrypt($row['totp_secret_enc']) : null;
        $step = $secret ? Totp::verifyStep($secret, preg_replace('/\s+/', '', $code) ?? '', $row['totp_last_step'] !== null ? (int) $row['totp_last_step'] : null) : null;
        if ($step === null) {
            self::recordAttempt($row['email'], false);
            Audit::log('reauth.failed', 'Two-factor confirmation failed');
            return false;
        }
        DB::run('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $u['id']]);
        return true;
    }

    private static function completeLogin(int $uid): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_csrf'], $_SESSION['timed_out']); // fresh CSRF token for the signed-in session
        $_SESSION['uid'] = $uid;
        $_SESSION['sv'] = (int) DB::value('SELECT session_version FROM users WHERE id = ?', [$uid]);
        $_SESSION['last_seen'] = time();
        $_SESSION['login_at'] = time();
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
            if (!empty($_SESSION['timed_out'])) {
                flash('info', 'You were signed out after a period of inactivity.');
                unset($_SESSION['timed_out']);
            }
            redirect('/login', ['next' => $_SERVER['REQUEST_URI'] ?? '/']);
        }
        // Before anything else: a temporary password must be changed, and every staff account needs 2FA
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $setupPage = $path === '/account' || str_starts_with($path, '/account/') || $path === '/session/ping';
        if (!$setupPage && ($u['must_change_password'] || !$u['totp_enabled'])) {
            flash('warning', $u['must_change_password']
                ? 'Set a new password to continue (your current one was issued by an administrator).'
                : 'Two-factor sign-in is required for all staff accounts. Set it up to continue.');
            redirect('/account');
        }
        return $u;
    }

    /** Ends every other session for this user (after a password change, 2FA change or admin action). */
    public static function revokeSessions(int $uid, bool $keepCurrent = false): void
    {
        DB::run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$uid]);
        if ($keepCurrent && (int) ($_SESSION['uid'] ?? 0) === $uid) {
            $_SESSION['sv'] = (int) DB::value('SELECT session_version FROM users WHERE id = ?', [$uid]);
            session_regenerate_id(true);
            self::$user = null;
        }
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

    public static function validatePassword(string $pw, array $context = []): ?string
    {
        return Security::passwordProblem($pw, $context);
    }
}
