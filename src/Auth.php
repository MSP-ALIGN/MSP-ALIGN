<?php
declare(strict_types=1);

namespace Align;

/**
 * Staff sign-in, sessions and roles. The client portal has its own copy of this flow (Portal\PortalAuth) with its
 * own cookie and user table; the two never share a session.
 *
 * Security assumptions: every POST reaching these methods has passed the router's CSRF check. Sign-in attempts are
 * counted in login_attempts before any password or code is checked, so parallel requests can't beat the lockout.
 * A signed-in session is only as good as its session_version: bumping it in the database ends the session on its
 * next request.
 */
final class Auth
{
    private const MAX_FAILURES = 5;
    private const FAILURE_WINDOW_MIN = 15;

    /** The signed-in user's row for this request, once Auth::user() has checked it. */
    private static ?array $user = null;

    /**
     * Starts the staff session (cookie-only, strict mode, HttpOnly, SameSite=Lax, __Host- and Secure over HTTPS) and
     * applies the idle and absolute timeouts. A timed-out session is destroyed and replaced by a fresh one that only
     * carries the "you were signed out" notice. Called once per request by public/index.php for non-portal paths.
     */
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

    /**
     * The signed-in, active staff user's row, or null. Ends the session when the account was disabled or its
     * session_version moved on (password change, 2FA change, "sign out everywhere", admin action). Callers that
     * need a signed-in user use require()/requireRole(), which also enforce the password change and 2FA setup.
     */
    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['uid'])) {
            $u = DB::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$_SESSION['uid']]);
            // A password change, 2FA reset or "sign out everywhere" bumps session_version and ends other sessions
            if (!$u || (int) $u['session_version'] !== (int) ($_SESSION['sv'] ?? -1)) {
                self::logout();
                // A fresh, empty session, as after a timeout: without it the sign-in form shown next keeps its CSRF
                // token in the destroyed session, and the first sign-in attempt fails with "session expired".
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    session_start();
                }
                return null;
            }
            self::$user = $u;
        }
        return self::$user;
    }

    /**
     * The session's staff user id, without checking the account. Only meaningful after require()/requireRole() (or
     * user()) ran in this request: those end a revoked session first. Used for "created_by" style columns and audit.
     */
    public static function id(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    /**
     * Whether sign-in is locked for this email (5 failures in 15 minutes) or this client IP (10, across accounts).
     * $pending: attempts already recorded for the request being checked (see beginAttempt()). The email must be
     * normalized the way attempt() does it; the IP comes from rate_ip() (client_ip(), IPv6 per /64).
     */
    public static function isLockedOut(string $email, int $pending = 0): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::FAILURE_WINDOW_MIN * 60);
        $byIp = (int) DB::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?',
            [rate_ip(), $since]
        );
        $byEmail = (int) DB::value(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > ?',
            [self::attemptKey($email), $since]
        );
        return $byIp >= self::MAX_FAILURES * 2 + $pending || $byEmail >= self::MAX_FAILURES + $pending;
    }

    /**
     * The login_attempts key for a typed email: the email itself, or a hash when it wouldn't fit the 190-character
     * column (2.2.1: a longer typed address made the insert fail with a server error). Invalid UTF-8 is replaced.
     */
    private static function attemptKey(string $email): string
    {
        $k = mb_scrub($email, 'UTF-8');
        return mb_strlen($k) <= 190 ? $k : 'sha256:' . hash('sha256', $k);
    }

    /** Records one sign-in attempt for this email and IP and prunes rows older than 30 days. Returns the row id. */
    private static function recordAttempt(string $email, bool $ok): int
    {
        $id = DB::insert('login_attempts', ['ip' => rate_ip(), 'email' => self::attemptKey($email), 'success' => $ok ? 1 : 0]);
        DB::run('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 30)]);
        return $id;
    }

    /**
     * Every attempt is counted as a failure BEFORE the slow check, then cleared if it succeeds: parallel
     * requests can't each see a count under the limit and all get a guess in (1.45). Null when over the limit.
     */
    private static function beginAttempt(string $email): ?int
    {
        $id = self::recordAttempt($email, false);
        return self::isLockedOut($email, 1) ? null : $id;
    }

    /**
     * One use per code, even for parallel requests: only the request that moves totp_last_step forward wins.
     * $step must come from Totp::verifyStep() against this user's own secret.
     */
    private static function useStep(int $uid, int $step): bool
    {
        return DB::run('UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$step, $uid, $step])->rowCount() === 1;
    }

    /**
     * Step 1: password. Returns 'ok' (signed in: no 2FA yet, or a remembered browser), '2fa' (code needed next),
     * 'locked' or 'invalid'. $email and $password are untrusted form input. 'invalid' is the same for an unknown
     * email and a wrong password, and both run one password hash check (a dummy Argon2id hash for unknown emails), so
     * the timing stays close. On '2fa' the session id is renewed and only a short-lived pending marker is stored.
     */
    public static function attempt(string $email, string $password): string
    {
        $email = strtolower(trim($email));
        if (self::isLockedOut($email) || ($attemptId = self::beginAttempt($email)) === null) {
            return 'locked';
        }
        $u = DB::one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
        // Always run a hash check to keep response timing consistent.
        $hash = ($u['password_hash'] ?? null) ?: \Align\Security::dummyHash();
        // An account without a password hash never signs in (as in the portal): it must not fall back to the dummy hash
        if (!password_verify($password, $hash) || !$u || !$u['password_hash']) {
            // (already counted by beginAttempt) Only log the email when it is one (never whatever was typed, which could be a password)
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
        if ($u['totp_enabled'] && ($rid = Remember::valid('staff', (int) $u['id']))) {
            // A browser remembered after an earlier code (1.45.1): the password was just checked, the code is skipped
            DB::run('UPDATE login_attempts SET success = 1 WHERE id = ?', [$attemptId]);
            self::completeLogin((int) $u['id'], "remembered browser #$rid, no code asked", (int) $u['session_version']);
            return 'ok';
        }
        if ($u['totp_enabled']) {
            DB::run('DELETE FROM login_attempts WHERE id = ?', [$attemptId]); // the code step counts on its own
            session_regenerate_id(true);
            // 'sv': the password was checked against this session_version; the code step refuses a newer one
            $_SESSION['pending_2fa'] = ['uid' => (int) $u['id'], 'at' => time(), 'sv' => (int) $u['session_version']];
            return '2fa';
        }
        DB::run('UPDATE login_attempts SET success = 1 WHERE id = ?', [$attemptId]);
        self::completeLogin((int) $u['id'], '', (int) $u['session_version']);
        return 'ok';
    }

    /**
     * Step 2: the TOTP code for the user whose password attempt() just accepted in this session. Returns 'ok',
     * 'expired' (no pending sign-in, older than 5 minutes, or the account's password, authenticator or sessions
     * changed since the password was checked), 'locked' or 'invalid'. Codes are counted like passwords and each works
     * once. $remember remembers this browser (Remember::issue) when the feature is on.
     */
    public static function verifySecondFactor(string $code, bool $remember = false): string
    {
        $p = $_SESSION['pending_2fa'] ?? null;
        if (!$p || time() - $p['at'] > 300) {
            unset($_SESSION['pending_2fa']);
            return 'expired';
        }
        $u = DB::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$p['uid']]);
        // A password reset or "sign out everywhere" after the password step voids it: otherwise the old password
        // plus one code would still sign in for up to 5 minutes, and completeLogin() would pick up the new version
        if ($u && (int) $u['session_version'] !== (int) ($p['sv'] ?? -1)) {
            unset($_SESSION['pending_2fa']);
            return 'expired';
        }
        if (!$u || self::isLockedOut($u['email']) || ($attemptId = self::beginAttempt($u['email'])) === null) {
            return 'locked';
        }
        $secret = Crypto::decrypt($u['totp_secret_enc']);
        $step = $secret ? Totp::verifyStep($secret, $code, $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null) : null;
        if ($step === null || !self::useStep((int) $u['id'], $step)) {
            Audit::log('login.2fa_failed', $u['email'], (int) $u['id']);
            Security::logAuthFailure('staff-2fa');
            Security::secondFactorFailed('staff', $u); // alerts early; replaces the password at 50 in a row (2.2.1)
            return 'invalid';
        }
        unset($_SESSION['pending_2fa']);
        Security::resetSecondFactorFailures('staff', (int) $u['id']);
        DB::run('UPDATE login_attempts SET success = 1 WHERE id = ?', [$attemptId]);
        if ($remember && Remember::days() > 0) {
            Remember::issue('staff', (int) $u['id']);
        }
        self::completeLogin((int) $u['id'], $remember && Remember::days() > 0 ? 'browser remembered for ' . Remember::days() . ' days' : '', (int) $u['session_version']);
        return 'ok';
    }

    /**
     * Re-checks the signed-in user's password (changing it): counted and locked out like a sign-in, so a session
     * someone else got hold of can't be used to guess the password (1.45). 'ok', 'invalid' or 'locked'.
     * $u must be the signed-in user's own row from require().
     */
    public static function checkPassword(array $u, string $password): string
    {
        if (self::isLockedOut($u['email']) || ($attemptId = self::beginAttempt($u['email'])) === null) {
            return 'locked';
        }
        if (!password_verify($password, (string) $u['password_hash'])) {
            Audit::log('reauth.failed', 'Password confirmation failed');
            return 'invalid';
        }
        DB::run('DELETE FROM login_attempts WHERE id = ?', [$attemptId]);
        return 'ok';
    }

    /**
     * Re-checks the signed-in user's current two-factor code before a destructive action (replays refused). Counted
     * against the lockout like a sign-in. Never satisfied by a remembered browser. False when not signed in.
     */
    public static function confirmCode(string $code): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        $row = DB::one('SELECT email, totp_secret_enc, totp_last_step FROM users WHERE id = ?', [$u['id']]);
        if (!$row || self::isLockedOut($row['email']) || ($attemptId = self::beginAttempt($row['email'])) === null) {
            return false;
        }
        $secret = $row['totp_secret_enc'] ? Crypto::decrypt($row['totp_secret_enc']) : null;
        $step = $secret ? Totp::verifyStep($secret, preg_replace('/\s+/', '', $code) ?? '', $row['totp_last_step'] !== null ? (int) $row['totp_last_step'] : null) : null;
        if ($step === null || !self::useStep((int) $u['id'], $step)) {
            Audit::log('reauth.failed', 'Two-factor confirmation failed');
            return false;
        }
        DB::run('DELETE FROM login_attempts WHERE id = ?', [$attemptId]);
        return true;
    }

    /**
     * Turns this session into a signed-in one for $uid: new session id (no fixation), new CSRF token, the current
     * session_version, and the clocks for the idle and absolute timeouts. Only called after the password and, where
     * needed, the code (or a remembered browser) were accepted. $note goes into the audit entry.
     */
    private static function completeLogin(int $uid, string $note = '', ?int $sv = null): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_csrf'], $_SESSION['timed_out']); // fresh CSRF token for the signed-in session
        $_SESSION['uid'] = $uid;
        // $sv: the version the password (and code) were checked against, so a reset landing in between ends this session
        $_SESSION['sv'] = $sv ?? (int) DB::value('SELECT session_version FROM users WHERE id = ?', [$uid]);
        $_SESSION['last_seen'] = time();
        $_SESSION['login_at'] = time();
        DB::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$uid]);
        Audit::log('login.success', $note, $uid);
    }

    /**
     * Ends this session: clears and destroys it server-side. The old cookie is left in place; strict mode refuses
     * its id next time. A remembered-browser cookie is kept on purpose (it only skips the code, never the password).
     */
    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$user = null;
    }

    /**
     * The signed-in staff user, or a redirect to /login (with ?next= the current URI; AuthController makes it a safe
     * path). Until the user has changed a temporary password and set up 2FA, only /account pages and the session ping
     * are allowed. Every staff route calls this or requireRole() before touching data.
     */
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

    /**
     * Ends every other session for this user (after a password change, 2FA change or admin action), and with it any
     * pending code step and remembered browser (both are tied to session_version). The calendar feed link is turned
     * off too (2.2.1): someone who briefly had the session could have made one and kept reading every client's
     * meetings after the password was reset; the user makes a new link on the Meetings page. $keepCurrent keeps
     * this session signed in, on a new id, when it belongs to $uid. The caller must have checked it may act on $uid.
     */
    public static function revokeSessions(int $uid, bool $keepCurrent = false): void
    {
        DB::run('UPDATE users SET session_version = session_version + 1, ics_token = NULL WHERE id = ?', [$uid]);
        if ($keepCurrent && (int) ($_SESSION['uid'] ?? 0) === $uid) {
            $_SESSION['sv'] = (int) DB::value('SELECT session_version FROM users WHERE id = ?', [$uid]);
            session_regenerate_id(true);
            self::$user = null;
        }
    }

    /** Role hierarchy: viewer < tech < admin. False when not signed in; an unknown role name is never granted. */
    public static function can(string $role): bool
    {
        $rank = ['viewer' => 1, 'tech' => 2, 'admin' => 3];
        $u = self::user();
        return $u && ($rank[$u['role']] ?? 0) >= ($rank[$role] ?? 99);
    }

    /** require(), then a 403 page and exit unless the user has at least $role. */
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

    /** Why a new password isn't allowed, or null (see Security::passwordProblem; $context: the user's email and name). */
    public static function validatePassword(string $pw, array $context = []): ?string
    {
        return Security::passwordProblem($pw, $context);
    }
}
