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
            'feedUrl' => MeetingController::freshFeedUrl(),
            'feedOn' => !empty($u['ics_token']),
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
        if ($err = Auth::validatePassword($new, [$u['email'], $u['name']])) {
            flash('error', $err);
            redirect('/account');
        }
        if ($new !== ($_POST['confirm'] ?? '')) {
            flash('error', 'New passwords do not match.');
            redirect('/account');
        }
        if (password_verify($new, $u['password_hash'])) {
            flash('error', 'Choose a password different from your current one.');
            redirect('/account');
        }
        DB::run('UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?', [\Align\Security::hashPassword($new), $u['id']]);
        Auth::revokeSessions((int) $u['id'], true);
        Audit::log('account.password');
        flash('success', 'Password changed. Any other signed-in sessions were signed out.');
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
                $step = $secret ? Totp::verifyStep($secret, post('code')) : null;
                if ($step === null) {
                    flash('error', 'That code did not match. Check the time on your phone and try again.');
                    break;
                }
                $replacing = (bool) $u['totp_enabled'];
                DB::run('UPDATE users SET totp_secret_enc = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?', [Crypto::encrypt($secret), $step, $u['id']]);
                unset($_SESSION['totp_setup']);
                Auth::revokeSessions((int) $u['id'], true);
                Audit::log($replacing ? 'account.2fa_replaced' : 'account.2fa_enabled');
                flash('success', $replacing ? 'Your new authenticator is set up. The old one no longer works.' : 'Two-factor sign-in is on.');
                break;
            case 'cancel':
                unset($_SESSION['totp_setup']);
                break;
            case 'disable':
                flash('error', 'Two-factor sign-in is required for staff accounts. Use "Replace authenticator" to move it to a new phone.');
                break;
            case 'signout_all':
                Auth::revokeSessions((int) $u['id'], true);
                Audit::log('account.signout_all');
                flash('success', 'Signed out of every other browser and device.');
                break;
        }
        redirect('/account');
    }
}
