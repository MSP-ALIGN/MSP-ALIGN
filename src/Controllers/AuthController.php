<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\View;

/**
 * Staff sign-in pages: password, two-factor code, the idle-timer ping and sign-out. The checks themselves (lockout,
 * hashes, codes, session renewal) are in Auth; this class only reads the form, picks the message and redirects.
 *
 * Security assumptions: every route here is public on purpose (routes.php). The Router has checked the CSRF token on
 * each POST (the sign-in form's token comes from the pre-sign-in session and is replaced on success). Redirect
 * targets from the request ("next") are only ever used after Security::safePath(), so they stay on this site.
 */
final class AuthController
{
    /** A same-site path to go to after signing in: $next when safePath() accepts it, else "/". $next is untrusted. */
    private static function safeNext(string $next): string
    {
        return \Align\Security::safePath($next);
    }

    /** The sign-in form (anyone). A signed-in user goes to the dashboard instead. */
    public static function loginForm(): void
    {
        if (Auth::user()) {
            redirect('/');
        }
        View::render('auth/login', ['title' => 'Sign in', 'next' => self::safeNext(query('next', '/'))], 'layout/bare');
    }

    /**
     * Step 1 (anyone): email and password from the form, both untrusted. Auth::attempt() counts the attempt before
     * checking it. The same message is shown for an unknown email and a wrong password.
     */
    public static function login(): void
    {
        $next = self::safeNext(post('next', '/'));
        $result = Auth::attempt(post('email'), (string) ($_POST['password'] ?? ''));
        switch ($result) {
            case 'ok':
                header('Location: ' . $next); // safePath() above: a path on this site, no CR/LF
                exit;
            case '2fa':
                $_SESSION['login_next'] = $next;
                redirect('/login/2fa');
            case 'locked':
                flash('error', 'Too many failed attempts. Wait 15 minutes and try again.');
                break;
            default:
                flash('error', 'Email or password is incorrect.');
        }
        redirect('/login', ['next' => $next]);
    }

    /** The code form, only while this session has a password step waiting for its code. */
    public static function twoFactorForm(): void
    {
        if (empty($_SESSION['pending_2fa'])) {
            redirect('/login');
        }
        View::render('auth/twofactor', ['title' => 'Two-factor code'], 'layout/bare');
    }

    /**
     * Step 2: the code for the pending sign-in in this session (Auth::verifySecondFactor checks it belongs to the
     * user whose password was just accepted, counts it and refuses replays). "Remember this browser" is the
     * posted checkbox; Auth ignores it when the feature is off.
     */
    public static function twoFactor(): void
    {
        $result = Auth::verifySecondFactor(post('code'), post('remember') === '1');
        if ($result === 'ok') {
            // Set by login() from safePath(); checked again in case the session held something else
            $next = self::safeNext($_SESSION['login_next'] ?? '/');
            unset($_SESSION['login_next']);
            header('Location: ' . $next);
            exit;
        }
        if ($result === 'expired') {
            flash('error', 'That sign-in took too long. Start again.');
            redirect('/login');
        }
        flash('error', $result === 'locked' ? 'Too many failed attempts. Wait 15 minutes.' : 'That code is not valid.');
        redirect('/login/2fa');
    }

    /**
     * Keep-alive / status for the automatic logoff timer in app.js. Answers 401 when signed out; tells a signed-in
     * user only that they are signed in and the idle limit, nothing else.
     */
    public static function ping(): void
    {
        header('Content-Type: application/json');
        if (!Auth::user()) {
            http_response_code(401);
            echo '{"signedIn":false}';
            return;
        }
        echo json_encode(['signedIn' => true, 'idle' => \Align\Security::idleSeconds()]);
    }

    /**
     * Signs out this session (POST with CSRF). Audited only when someone is actually signed in (2.2.1): before, any
     * visitor could take a token from the sign-in page and add anonymous "logout" entries to the audit log at will.
     */
    public static function logout(): void
    {
        if (Auth::user()) {
            Audit::log('logout');
        }
        Auth::logout();
        redirect('/login');
    }
}
