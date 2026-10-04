<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Crypto;
use Align\DB;
use Align\Totp;
use Align\View;

/**
 * Your account (/account): password, two-factor, remembered browsers, sign out everywhere, picture, theme, calendar
 * feed and email choices. Every staff role, viewers included.
 *
 * Security assumptions: each action works on the signed-in user's own row only (the id always comes from
 * Auth::require(), never from the request), and the Router has checked CSRF on every POST. These pages stay open
 * while a temporary password or 2FA set-up is pending (Auth::require() lets /account through), so nothing here may
 * show client data. Anything that weakens sign-in for the account needs a re-check: the current password to change
 * it, a code from the current authenticator to replace it.
 */
final class AccountController
{
    /** The account page. The pending 2FA secret (if set-up was started) is shown to its own user only. */
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
            'notifPrefs' => \Align\Mail\Notifications::prefsFor($u),
            'notifScope' => \Align\Mail\Notifications::scope($u),
            'mailOn' => \Align\Mail\Mail::on(),
            'vcioCount' => (int) \Align\DB::value('SELECT COUNT(*) FROM clients WHERE vcio_user_id = ? AND is_archived = 0 AND planning_excluded = 0', [$u['id']]),
        ]);
    }

    /**
     * Your own email notification choices. Only keys from the catalog are written (the posted notif[] array is only
     * looked up, never stored as is); a notification switched off for everyone keeps the user's old choice.
     */
    public static function notifications(): void
    {
        $u = Auth::require();
        $keys = array_keys(\Align\Mail\Notifications::prefsFor($u));
        $picked = (array) ($_POST['notif'] ?? []);
        \Align\DB::transaction(function () use ($u, $keys, $picked) {
            foreach ($keys as $k) {
                if (\Align\Settings::get("notif_$k", \Align\Mail\Notifications::CATALOG[$k][7] ? '1' : '0') !== '1') {
                    continue; // switched off for everyone: keep whatever the user had
                }
                \Align\DB::run('INSERT INTO user_notification_prefs (user_id, notif_key, enabled) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
                    [$u['id'], $k, isset($picked[$k]) ? 1 : 0]);
            }
            $scope = in_array(post('scope'), ['mine', 'all'], true) ? post('scope') : null;
            \Align\DB::run('UPDATE users SET notify_scope = ? WHERE id = ?', [$scope, $u['id']]);
        });
        \Align\Audit::log('account.notifications', implode(', ', array_keys(array_intersect_key(array_flip($keys), $picked))) ?: 'none');
        flash('success', 'Email notification choices saved.');
        redirect('/account#notifications');
    }

    /**
     * Forget one remembered browser, or all of them (1.45.1). Remember::forget() limits the delete to this user's
     * rows, so an id belonging to someone else matches nothing.
     */
    public static function remembered(): void
    {
        $u = Auth::require();
        $all = post('id') === 'all';
        $n = \Align\Remember::forget('staff', (int) $u['id'], $all ? null : (int) post('id'));
        Audit::log('account.remembered_forgotten', $all ? "all ($n)" : "#" . (int) post('id'));
        flash('success', $n ? ($all ? 'All remembered browsers forgotten. They ask for the code next time.' : 'Browser forgotten. It asks for the code next time.') : 'Nothing to forget.');
        redirect('/account');
    }

    /** Light, dark or match the computer (1.43), from a fixed list. Printed reports always stay light. */
    public static function appearance(): void
    {
        $u = Auth::require();
        $theme = in_array(post('theme'), ['auto', 'light', 'dark'], true) ? post('theme') : 'auto';
        DB::run('UPDATE users SET theme = ? WHERE id = ?', [$theme, $u['id']]);
        Audit::log('account.theme', $theme);
        flash('success', ['auto' => 'The app now matches your computer\'s light or dark setting.', 'light' => 'Light mode is on.', 'dark' => 'Dark mode is on.'][$theme]);
        redirect('/account#appearance');
    }

    /**
     * Upload or remove your profile picture. Any signed-in staff user, for their own account only (the id comes
     * from the session, CSRF from the router). Images::store checks and re-encodes the upload (EXIF dropped) and
     * picks the file name; staff see it through /users/{id}/avatar, the portal only its own vCIO's.
     */
    public static function avatar(): void
    {
        $u = Auth::require();
        if (post('action') === 'remove') {
            \Align\Images::delete('avatars', $u['avatar_file'] ?? null);
            DB::run('UPDATE users SET avatar_file = NULL WHERE id = ?', [$u['id']]);
            Audit::log('account.avatar_removed', $u['email']);
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

    /**
     * Changes your password. The current one is checked first, counted against the sign-in lockout (Auth::
     * checkPassword), so a borrowed session can't be used to guess it. The new one must pass the password rules and
     * differ from the old. Clears a temporary password and ends every other session (and with them any pending
     * code step and remembered browser).
     */
    public static function password(): void
    {
        $u = Auth::require();
        $check = Auth::checkPassword($u, (string) ($_POST['current'] ?? ''));
        if ($check !== 'ok') {
            flash('error', $check === 'locked' ? 'Too many wrong passwords. Try again in 15 minutes.' : 'Current password is incorrect.');
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

    /**
     * Two-factor set-up and "sign out everywhere else". Actions: begin (new secret, kept in this session only until
     * confirmed), confirm (a code from the new secret, plus one from the current authenticator when replacing it),
     * cancel, disable (refused: staff always need 2FA) and signout_all. Turning 2FA on or moving it ends every other
     * session. The new secret is stored encrypted, with the confirming code's step so that code can't sign in again.
     */
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
                // Moving 2FA to a new phone needs a code from the current one, so a borrowed session can't take it over (1.45)
                if ($replacing && !Auth::confirmCode(post('current_code'))) {
                    flash('error', 'The code from your current authenticator did not match. Enter a fresh code from the old phone, and one from the new.');
                    break;
                }
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
