<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Crypto;
use Align\DB;
use Align\Totp;
use Align\View;

final class AccountController
{
    public static function show(): void
    {
        $u = Auth::require();
        $pending = $_SESSION['totp_setup'] ?? null;
        View::render('users/account', [
            'title' => 'Your account',
            'nav' => 'account',
            'u' => $u,
            'setupSecret' => $pending,
            'setupUri' => $pending ? Totp::uri($pending, $u['email']) : null,
            'feedUrl' => MeetingController::feedUrl($u),
        ]);
    }

    /** Upload or remove your profile picture. */
    public static function avatar(): void
    {
        $u = Auth::require();
        if (post('action') === 'remove') {
            \Align\Images::delete('avatars', $u['avatar_file'] ?? null);
            DB::run('UPDATE users SET avatar_file = NULL WHERE id = ?', [$u['id']]);
            flash('success', 'Profile picture removed.');
            redirect('/account');
        }
        [$err, $name] = \Align\Images::store($_FILES['avatar'] ?? [], 'avatars', 'user' . (int) $u['id'], 256, 256, true);
        if ($err) {
            flash('error', $err);
            redirect('/account');
        }
        DB::run('UPDATE users SET avatar_file = ? WHERE id = ?', [$name, $u['id']]);
        \Align\Images::delete('avatars', $u['avatar_file'] ?? null);
        Audit::log('account.avatar', $u['email']);
        flash('success', 'Profile picture updated.');
        redirect('/account');
    }

    public static function password(): void
    {
        $u = Auth::require();
        if (!password_verify((string) ($_POST['current'] ?? ''), $u['password_hash'])) {
            flash('error', 'Current password is incorrect.');
            redirect('/account');
        }
        $new = (string) ($_POST['new'] ?? '');
        if ($err = Auth::validatePassword($new)) {
            flash('error', $err);
            redirect('/account');
        }
        if ($new !== ($_POST['confirm'] ?? '')) {
            flash('error', 'New passwords do not match.');
            redirect('/account');
        }
        DB::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        Audit::log('account.password');
        flash('success', 'Password changed.');
        redirect('/account');
    }

    public static function twoFactor(): void
    {
        $u = Auth::require();
        switch (post('action')) {
            case 'begin':
                $_SESSION['totp_setup'] = Totp::generateSecret();
                break;
            case 'confirm':
                $secret = $_SESSION['totp_setup'] ?? null;
                if (!$secret || !Totp::verify($secret, post('code'))) {
                    flash('error', 'That code did not match. Check the time on your phone and try again.');
                    break;
                }
                DB::run('UPDATE users SET totp_secret_enc = ?, totp_enabled = 1 WHERE id = ?', [Crypto::encrypt($secret), $u['id']]);
                unset($_SESSION['totp_setup']);
                Audit::log('account.2fa_enabled');
                flash('success', 'Two-factor sign-in is on.');
                break;
            case 'cancel':
                unset($_SESSION['totp_setup']);
                break;
            case 'disable':
                if (!password_verify((string) ($_POST['password'] ?? ''), $u['password_hash'])) {
                    flash('error', 'Password is incorrect.');
                    break;
                }
                DB::run('UPDATE users SET totp_secret_enc = NULL, totp_enabled = 0 WHERE id = ?', [$u['id']]);
                Audit::log('account.2fa_disabled');
                flash('success', 'Two-factor sign-in is off.');
                break;
        }
        redirect('/account');
    }
}
