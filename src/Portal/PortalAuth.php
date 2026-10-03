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
 *
 * Security assumptions: every POST reaching these methods passed the router's CSRF check. A signed-in session
 * is only good while the user is active, their client isn't archived and their session_version is unchanged;
 * user() checks all three on every request. Sign-in attempts are counted before any password or code is
 * checked (as in staff Auth), so parallel guesses can't get past the lockout. Invite/reset tokens are 256-bit,
 * stored only as a SHA-256 hash, and expire.
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
        'can_submit' => 'Suggest licenses and budget items',
        // Contacts are view-only in the portal since 1.39; this permission now only sends requests
        'can_contacts' => 'Send new user and termination requests',
    ];

    /** The signed-in user's row for this request, once user() has checked it. */
    private static ?array $user = null;

    /**
     * Starts the portal session (cookie-only, strict mode, HttpOnly, SameSite=Lax, path /portal, __Secure- over
     * HTTPS) and applies the idle and absolute timeouts. A timed-out session is destroyed and replaced by an empty
     * one that only carries the "you were signed out" notice. Called once per request for /portal paths.
     */
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

    /**
     * The signed-in portal user, joined with their client (null if not signed in, disabled, the client is archived,
     * or the session_version moved on). Ends a session that no longer counts. Does not check 2FA or permissions:
     * pages use require().
     */
    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['portal_uid'])) {
            $u = DB::one('SELECT p.*, c.name AS client_name, c.logo_file, c.portal_require_2fa, c.is_archived AS client_archived
                FROM portal_users p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND p.is_active = 1', [$_SESSION['portal_uid']]);
            if (!$u || $u['client_archived'] || (int) $u['session_version'] !== (int) ($_SESSION['sv'] ?? -1)) {
                self::logout();
                // a fresh session, so a sign-in form shown on this request gets a CSRF token that works (2.2.1)
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    session_start();
                }
                return null;
            }
            self::$user = $u;
        }
        return self::$user;
    }

    /** The session's portal user id without checking the account (only meaningful after user()/require()). For audit. */
    public static function id(): ?int
    {
        return isset($_SESSION['portal_uid']) ? (int) $_SESSION['portal_uid'] : null;
    }

    /**
     * The signed-in portal user, or a redirect to the sign-in page. Until two-factor is set up only the account
     * pages and the session ping are allowed. $permission (one of the SECTIONS/ACTIONS columns) gives a 403 page
     * when missing. Every portal page calls this first; callers still scope every query to $u['client_id'].
     */
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

    /**
     * The login_attempts key for an email (portal and staff attempts never share one). The column holds 190
     * characters: a longer email (or anything typed into the field) is keyed by its hash, so the insert can't fail
     * and stop a long but real address from ever signing in (2.2.1).
     */
    private static function key(string $email): string
    {
        $k = 'portal:' . mb_scrub(strtolower(trim($email)), 'UTF-8');
        return mb_strlen($k) <= 190 ? $k : 'portal:sha256:' . hash('sha256', $k);
    }

    /**
     * Whether sign-in is locked for this email (5 failures in 15 minutes) or this client IP (10, across accounts).
     * $pending: attempts already recorded for the request being checked (see beginAttempt()).
     */
    public static function isLockedOut(string $email, int $pending = 0): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::FAILURE_WINDOW_MIN * 60);
        $byIp = (int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [rate_ip(), $since]);
        $byEmail = (int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > ?', [self::key($email), $since]);
        return $byIp >= self::MAX_FAILURES * 2 + $pending || $byEmail >= self::MAX_FAILURES + $pending;
    }

    /** Records one attempt for this email and the client IP. Returns the row id. */
    private static function recordAttempt(string $email, bool $ok): int
    {
        return DB::insert('login_attempts', ['ip' => rate_ip(), 'email' => self::key($email), 'success' => $ok ? 1 : 0]);
    }

    /** Counted as a failure before the slow check, cleared on success (see Auth::beginAttempt). Null when over the limit. */
    private static function beginAttempt(string $email): ?int
    {
        $id = self::recordAttempt($email, false);
        return self::isLockedOut($email, 1) ? null : $id;
    }

    /**
     * Step 1: password. Returns 'ok' (signed in: no 2FA yet, or a remembered browser), '2fa' (code next), 'locked'
     * or 'invalid'. $email and $password are untrusted form input. 'invalid' is the same for an unknown email, a
     * wrong password, a disabled user and an archived client, and each runs one password hash check, so the timing
     * stays close. On '2fa' the session id is renewed and only a short-lived pending marker is stored.
     */
    public static function attempt(string $email, string $password): string
    {
        // invalid UTF-8 can't match an account and would make the attempt's insert fail (2.2.1)
        $email = mb_scrub(strtolower(trim($email)), 'UTF-8');
        if (self::isLockedOut($email) || ($attemptId = self::beginAttempt($email)) === null) {
            return 'locked';
        }
        $u = DB::one('SELECT p.*, c.is_archived AS client_archived FROM portal_users p JOIN clients c ON c.id = p.client_id WHERE p.email = ? AND p.is_active = 1', [$email]);
        $hash = ($u['password_hash'] ?? null) ?: \Align\Security::dummyHash();
        if (!password_verify($password, $hash) || !$u || !$u['password_hash'] || $u['client_archived']) {
            Audit::log('portal.login_failed', filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '(not an email address)', null, $u['id'] ?? null);
            \Align\Security::logAuthFailure('portal');
            if ($u && self::isLockedOut($email)) {
                \Align\Mail\Notify::security('Client portal account locked out', "$email ({$u['client_id']}): too many failed sign-ins");
            }
            return 'invalid';
        }
        if (\Align\Security::needsRehash($u['password_hash'])) {
            DB::run('UPDATE portal_users SET password_hash = ? WHERE id = ?', [\Align\Security::hashPassword($password), $u['id']]);
        }
        if ($u['totp_enabled'] && ($rid = \Align\Remember::valid('portal', (int) $u['id']))) {
            DB::run('UPDATE login_attempts SET success = 1 WHERE id = ?', [$attemptId]); // remembered browser: no code (1.45.1)
            self::completeLogin((int) $u['id'], "remembered browser #$rid, no code asked", (int) $u['session_version']);
            return 'ok';
        }
        if ($u['totp_enabled']) {
            session_regenerate_id(true);
            DB::run('DELETE FROM login_attempts WHERE id = ?', [$attemptId]);
            // sv: a password reset or "sign out everywhere" during the code step voids it (2.2.1)
            $_SESSION['portal_pending_2fa'] = ['uid' => (int) $u['id'], 'at' => time(), 'sv' => (int) $u['session_version']];
            return '2fa';
        }
        DB::run('UPDATE login_attempts SET success = 1 WHERE id = ?', [$attemptId]);
        self::completeLogin((int) $u['id'], '', (int) $u['session_version']);
        return 'ok';
    }

    /**
     * Step 2: the code for the user whose password attempt() accepted in this session. Returns 'ok', 'expired' (no
     * pending sign-in, older than 5 minutes, or the account's sessions were ended since the password step), 'locked'
     * or 'invalid'. Codes are counted like passwords and each works once. $remember remembers this browser.
     */
    public static function verifySecondFactor(string $code, bool $remember = false): string
    {
        $p = $_SESSION['portal_pending_2fa'] ?? null;
        if (!$p || time() - $p['at'] > 300) {
            unset($_SESSION['portal_pending_2fa']);
            return 'expired';
        }
        $u = DB::one('SELECT * FROM portal_users WHERE id = ? AND is_active = 1', [$p['uid']]);
        if ($u && (int) $u['session_version'] !== (int) ($p['sv'] ?? -1)) {
            unset($_SESSION['portal_pending_2fa']);
            return 'expired';
        }
        if (!$u || self::isLockedOut($u['email']) || ($attemptId = self::beginAttempt($u['email'])) === null) {
            return 'locked';
        }
        $secret = Crypto::decrypt((string) $u['totp_secret_enc']);
        $step = $secret ? Totp::verifyStep($secret, $code, $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null) : null;
        // one use per code, even for parallel requests
        $used = $step !== null && DB::run('UPDATE portal_users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$step, $u['id'], $step])->rowCount() === 1;
        if (!$used) {
            Audit::log('portal.2fa_failed', $u['email'], null, (int) $u['id']);
            \Align\Security::logAuthFailure('portal-2fa');
            \Align\Security::secondFactorFailed('portal', $u); // alerts early; replaces the password at 50 in a row (2.2.1)
            return 'invalid';
        }
        unset($_SESSION['portal_pending_2fa']);
        \Align\Security::resetSecondFactorFailures('portal', (int) $u['id']);
        DB::run('UPDATE login_attempts SET success = 1 WHERE id = ?', [$attemptId]);
        $remember = $remember && \Align\Remember::days() > 0;
        if ($remember) {
            \Align\Remember::issue('portal', (int) $u['id']);
        }
        self::completeLogin((int) $u['id'], $remember ? 'browser remembered for ' . \Align\Remember::days() . ' days' : '', (int) $u['session_version']);
        return 'ok';
    }

    /**
     * Re-checks the signed-in portal user's password, counted and locked out like a sign-in (1.45). 'ok', 'invalid'
     * or 'locked'. $pu must be the signed-in user's own row from require().
     */
    public static function checkPassword(array $pu, string $password): string
    {
        if (self::isLockedOut($pu['email']) || ($attemptId = self::beginAttempt($pu['email'])) === null) {
            return 'locked';
        }
        if (!password_verify($password, (string) $pu['password_hash'])) {
            Audit::log('portal.reauth_failed', 'Password confirmation failed', null, (int) $pu['id']);
            return 'invalid';
        }
        DB::run('DELETE FROM login_attempts WHERE id = ?', [$attemptId]);
        return 'ok';
    }

    /**
     * Re-checks a portal user's current two-factor code (each code works once), counted against the lockout. $pu is
     * the signed-in user from require(), or the user an invite/reset token was just matched to. Never satisfied by
     * a remembered browser.
     */
    public static function confirmCode(array $pu, string $code): bool
    {
        $u = DB::one('SELECT email, totp_secret_enc, totp_last_step FROM portal_users WHERE id = ?', [$pu['id']]);
        if (!$u || self::isLockedOut($u['email']) || ($attemptId = self::beginAttempt($u['email'])) === null) {
            return false;
        }
        $secret = $u['totp_secret_enc'] ? Crypto::decrypt((string) $u['totp_secret_enc']) : null;
        $step = $secret ? Totp::verifyStep($secret, preg_replace('/\s+/', '', $code) ?? '', $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null) : null;
        if ($step === null || DB::run('UPDATE portal_users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$step, $pu['id'], $step])->rowCount() !== 1) {
            Audit::log('portal.reauth_failed', 'Two-factor confirmation failed', null, (int) $pu['id']);
            return false;
        }
        DB::run('DELETE FROM login_attempts WHERE id = ?', [$attemptId]);
        return true;
    }

    /**
     * Turns this session into a signed-in one for portal user $id: new session id (no fixation), new CSRF token, the
     * session_version the password (and code) were checked against, and the timeout clocks. Only call it after the
     * password and, where needed, the code, a remembered browser or a valid invite/reset token were accepted.
     */
    public static function completeLogin(int $id, string $note = '', ?int $sv = null): void
    {
        session_regenerate_id(true);
        // Nothing half-done by whoever used this browser before carries over: an authenticator key being set up
        // would otherwise be offered to the new user, and a pending code step would still be open (2.2.1)
        unset($_SESSION['_csrf'], $_SESSION['timed_out'], $_SESSION['portal_totp_setup'], $_SESSION['portal_pending_2fa']);
        $_SESSION['portal_uid'] = $id;
        // $sv: the version the password (and code) were checked against, so a reset landing in between ends this session
        $_SESSION['sv'] = $sv ?? (int) DB::value('SELECT session_version FROM portal_users WHERE id = ?', [$id]);
        $_SESSION['last_seen'] = time();
        $_SESSION['login_at'] = time();
        DB::run('UPDATE portal_users SET last_login_at = NOW() WHERE id = ?', [$id]);
        self::$user = null;
        Audit::log('portal.login', $note, null, $id);
    }

    /**
     * Ends every session of a portal user (password change, 2FA change, reset link used, disabled), and with them
     * any pending code step and remembered browser (both tied to session_version). $keepCurrent keeps this session
     * signed in, on a new id, when it is $id's. The caller must have checked it may act on $id.
     */
    public static function revokeSessions(int $id, bool $keepCurrent = false): void
    {
        DB::run('UPDATE portal_users SET session_version = session_version + 1 WHERE id = ?', [$id]);
        if ($keepCurrent && (int) ($_SESSION['portal_uid'] ?? 0) === $id) {
            $_SESSION['sv'] = (int) DB::value('SELECT session_version FROM portal_users WHERE id = ?', [$id]);
            session_regenerate_id(true);
            self::$user = null;
        }
    }

    /** Ends this session: clears and destroys it server-side. A remembered-browser cookie is kept on purpose. */
    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$user = null;
    }

    /**
     * Creates a one-time invite / password link (256 random bits; only the SHA-256 is stored) and voids any earlier
     * one. Valid $ttlSeconds, default 7 days. Returns the full URL, to show once or email; never log it. The caller
     * must be staff allowed to manage this user, or the self-service reset.
     */
    public static function issueLink(int $portalUserId, ?int $ttlSeconds = null): string
    {
        $token = bin2hex(random_bytes(32));
        DB::run('UPDATE portal_users SET invite_token_hash = ?, invite_expires_at = ? WHERE id = ?',
            [hash('sha256', $token), date('Y-m-d H:i:s', time() + ($ttlSeconds ?? self::INVITE_DAYS * 86400)), $portalUserId]);
        return self::baseUrl() . '/portal/invite/' . $token;
    }

    /**
     * The active user (of a client that isn't archived) a still-valid invite/reset token belongs to, or null.
     * $token comes from the URL: anything but 64 hex characters is refused before the lookup by hash.
     */
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
        // Without base_url (install.sh and Docker always set it), the Host header is only trusted from a signed-in
        // staff member: anyone can send /portal/forgot with a Host of their choosing, which would put their site in
        // a genuine reset email (1.45). Everyone else gets the configured server name.
        $staff = !(defined('IS_PORTAL') && IS_PORTAL) && PHP_SAPI !== 'cli' && \Align\Auth::id() !== null;
        $host = $staff ? ($_SERVER['HTTP_HOST'] ?? '') : (string) \Align\Config::get('fqdn', '');
        $host = preg_replace('/[^A-Za-z0-9.:-]/', '', $host) ?: 'localhost';
        return (is_https() || !$staff ? 'https://' : 'http://') . $host;
    }
}
