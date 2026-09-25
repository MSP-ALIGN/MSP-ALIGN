<?php
declare(strict_types=1);

namespace Align\Portal;

use Align\Audit;
use Align\Crypto;
use Align\DB;
use Align\Totp;

/**
 * Sign-in for client portal users. Deliberately separate from staff Auth: its own session cookie
 * (ALIGNPORTAL, scoped to /portal), its own user table, and every page is limited to the
 * signed-in user's own client. A portal session can never reach staff pages, and vice versa.
 */
final class PortalAuth
{
    private const MAX_FAILURES = 5;
    private const FAILURE_WINDOW_MIN = 15;
    public const INVITE_DAYS = 7;
    public const SECTIONS = [
        'can_roadmap' => 'Roadmap & projects',
        'can_budget' => 'Budget & licensing',
        'can_devices' => 'Devices & compliance',
        'can_documents' => 'Documents, contacts & meetings',
    ];
    public const ACTIONS = [
        'can_approve' => 'Approve or decline proposed projects',
        'can_contacts' => 'Add and update contacts',
    ];

    private static ?array $user = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(\Align\Security::cookieName('ALIGNPORTAL', false));
        session_set_cookie_params(['lifetime' => 0, 'path' => '/portal', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        $savePath = \Align\Config::get('session_path');
        if ($savePath && is_dir($savePath)) {
            session_save_path($savePath);
        }
        session_start();
        if (!\Align\Security::enforceTimeouts('portal_uid')) {
            $id = (int) $_SESSION['portal_uid'];
            self::logout();
            session_start();
            $_SESSION['timed_out'] = true;
            Audit::log('portal.logout_timeout', '', null, $id);
        }
    }

    /** The signed-in portal user, joined with their client (null if not signed in, disabled, or the client is archived). */
    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['portal_uid'])) {
            $u = DB::one('SELECT p.*, c.name AS client_name, c.logo_file, c.portal_require_2fa, c.is_archived AS client_archived
                FROM portal_users p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND p.is_active = 1', [$_SESSION['portal_uid']]);
            if (!$u || $u['client_archived'] || (int) $u['session_version'] !== (int) ($_SESSION['sv'] ?? -1)) {
                self::logout();
                return null;
            }
            self::$user = $u;
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['portal_uid']) ? (int) $_SESSION['portal_uid'] : null;
    }

    public static function require(?string $permission = null): array
    {
        $u = self::user();
        if (!$u) {
            if (!empty($_SESSION['timed_out'])) {
                unset($_SESSION['timed_out']);
                flash('info', 'You were signed out after a period of inactivity.');
            }
            redirect('/portal/login');
        }
        // Two-factor sign-in is required for every portal user: nothing else until it's set up
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if (!$u['totp_enabled'] && !str_starts_with($path, '/portal/account') && $path !== '/portal/session/ping') {
            flash('warning', 'Two-factor sign-in is required to protect your organization\'s information. Set it up to continue.');
            redirect('/portal/account');
        }
        if ($permission !== null && empty($u[$permission])) {
            http_response_code(403);
            \Align\View::render('portal/error', ['title' => 'Not available', 'message' => 'Your account does not have access to this section. Ask your IT provider if you need it.', 'pu' => $u], 'portal/layout');
            exit;
        }
        return $u;
    }

    private static function key(string $email): string
    {
        return 'portal:' . strtolower(trim($email));
    }

    public static function isLockedOut(string $email): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::FAILURE_WINDOW_MIN * 60);
        $byIp = (int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [client_ip(), $since]);
        $byEmail = (int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > ?', [self::key($email), $since]);
        return $byIp >= self::MAX_FAILURES * 2 || $byEmail >= self::MAX_FAILURES;
    }

    private static function recordAttempt(string $email, bool $ok): void
    {
        DB::insert('login_attempts', ['ip' => client_ip(), 'email' => self::key($email), 'success' => $ok ? 1 : 0]);
    }

    /** 'ok', '2fa', 'locked' or 'invalid' */
    public static function attempt(string $email, string $password): string
    {
        $email = strtolower(trim($email));
        if (self::isLockedOut($email)) {
            return 'locked';
        }
        $u = DB::one('SELECT p.*, c.is_archived AS client_archived FROM portal_users p JOIN clients c ON c.id = p.client_id WHERE p.email = ? AND p.is_active = 1', [$email]);
        $hash = ($u['password_hash'] ?? null) ?: \Align\Security::dummyHash();
        if (!password_verify($password, $hash) || !$u || !$u['password_hash'] || $u['client_archived']) {
            self::recordAttempt($email, false);
            Audit::log('portal.login_failed', filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '(not an email address)', null, $u['id'] ?? null);
            \Align\Security::logAuthFailure('portal');
            return 'invalid';
        }
        if (\Align\Security::needsRehash($u['password_hash'])) {
            DB::run('UPDATE portal_users SET password_hash = ? WHERE id = ?', [\Align\Security::hashPassword($password), $u['id']]);
        }
        if ($u['totp_enabled']) {
            session_regenerate_id(true);
            $_SESSION['portal_pending_2fa'] = ['uid' => (int) $u['id'], 'at' => time()];
            return '2fa';
        }
        self::recordAttempt($email, true);
        self::completeLogin((int) $u['id']);
        return 'ok';
    }

    public static function verifySecondFactor(string $code): string
    {
        $p = $_SESSION['portal_pending_2fa'] ?? null;
        if (!$p || time() - $p['at'] > 300) {
            unset($_SESSION['portal_pending_2fa']);
            return 'expired';
        }
        $u = DB::one('SELECT * FROM portal_users WHERE id = ? AND is_active = 1', [$p['uid']]);
        if (!$u || self::isLockedOut($u['email'])) {
            return 'locked';
        }
        $secret = Crypto::decrypt((string) $u['totp_secret_enc']);
        $step = $secret ? Totp::verifyStep($secret, $code, $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null) : null;
        if ($step === null) {
            self::recordAttempt($u['email'], false);
            Audit::log('portal.2fa_failed', $u['email'], null, (int) $u['id']);
            \Align\Security::logAuthFailure('portal-2fa');
            return 'invalid';
        }
        DB::run('UPDATE portal_users SET totp_last_step = ? WHERE id = ?', [$step, $u['id']]);
        unset($_SESSION['portal_pending_2fa']);
        self::recordAttempt($u['email'], true);
        self::completeLogin((int) $u['id']);
        return 'ok';
    }

    public static function completeLogin(int $id): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_csrf'], $_SESSION['timed_out']);
        $_SESSION['portal_uid'] = $id;
        $_SESSION['sv'] = (int) DB::value('SELECT session_version FROM portal_users WHERE id = ?', [$id]);
        $_SESSION['last_seen'] = time();
        $_SESSION['login_at'] = time();
        DB::run('UPDATE portal_users SET last_login_at = NOW() WHERE id = ?', [$id]);
        self::$user = null;
        Audit::log('portal.login', '', null, $id);
    }

    /** Ends every session of a portal user (password change, 2FA reset, disabled). */
    public static function revokeSessions(int $id, bool $keepCurrent = false): void
    {
        DB::run('UPDATE portal_users SET session_version = session_version + 1 WHERE id = ?', [$id]);
        if ($keepCurrent && (int) ($_SESSION['portal_uid'] ?? 0) === $id) {
            $_SESSION['sv'] = (int) DB::value('SELECT session_version FROM portal_users WHERE id = ?', [$id]);
            session_regenerate_id(true);
            self::$user = null;
        }
    }

    /** Invite link used on an account that already has two-factor: continue to the code step, don't sign in. */
    public static function beginSecondFactor(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION['portal_pending_2fa'] = ['uid' => $id, 'at' => time()];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$user = null;
    }

    /** Creates a one-time invite / password link. Only a hash is stored. Returns the full URL. */
    public static function issueLink(int $portalUserId): string
    {
        $token = bin2hex(random_bytes(32));
        DB::run('UPDATE portal_users SET invite_token_hash = ?, invite_expires_at = ? WHERE id = ?',
            [hash('sha256', $token), date('Y-m-d H:i:s', time() + self::INVITE_DAYS * 86400), $portalUserId]);
        return self::baseUrl() . '/portal/invite/' . $token;
    }

    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $u = DB::one('SELECT p.*, c.name AS client_name FROM portal_users p JOIN clients c ON c.id = p.client_id
            WHERE p.invite_token_hash = ? AND p.is_active = 1 AND c.is_archived = 0', [hash('sha256', $token)]);
        return $u && $u['invite_expires_at'] >= date('Y-m-d H:i:s') ? $u : null;
    }

    /** Configured site address (never the request's Host header, which a client could spoof). */
    public static function baseUrl(): string
    {
        $base = (string) \Align\Config::get('base_url', '');
        if ($base !== '') {
            return rtrim($base, '/');
        }
        $host = preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        return (is_https() ? 'https://' : 'http://') . $host;
    }
}
