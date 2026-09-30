<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\View;

final class AuthController
{
    private static function safeNext(string $next): string
    {
        return \Align\Security::safePath($next);
    }

    public static function loginForm(): void
    {
        if (Auth::user()) {
            redirect('/');
        }
        View::render('auth/login', ['title' => 'Sign in', 'next' => self::safeNext(query('next', '/'))], 'layout/bare');
    }

    public static function login(): void
    {
        $next = self::safeNext(post('next', '/'));
        $result = Auth::attempt(post('email'), (string) ($_POST['password'] ?? ''));
        switch ($result) {
            case 'ok':
                header('Location: ' . $next);
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

    public static function twoFactorForm(): void
    {
        if (empty($_SESSION['pending_2fa'])) {
            redirect('/login');
        }
        View::render('auth/twofactor', ['title' => 'Two-factor code'], 'layout/bare');
    }

    public static function twoFactor(): void
    {
        $result = Auth::verifySecondFactor(post('code'), post('remember') === '1');
        if ($result === 'ok') {
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

    /** Keep-alive / status for the automatic logoff timer in app.js. */
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

    public static function logout(): void
    {
        Audit::log('logout');
        Auth::logout();
        redirect('/login');
    }
}
