<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\View;

final class UserController
{
    public const ROLES = [
        'admin' => 'Admin — everything, including settings and users',
        'tech' => 'Tech — edit devices, mapping, run sync',
        'viewer' => 'Viewer — read-only',
    ];

    public static function index(): void
    {
        Auth::requireRole('admin');
        View::render('users/index', [
            'title' => 'Users',
            'nav' => 'users',
            'users' => DB::all('SELECT * FROM users ORDER BY is_active DESC, name'),
            'roles' => self::ROLES,
            'newPassword' => $_SESSION['new_password'] ?? null,
        ]);
        unset($_SESSION['new_password']);
    }

    public static function create(): void
    {
        Auth::requireRole('admin');
        $email = strtolower(post('email'));
        $name = post('name');
        $role = post('role');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || !isset(self::ROLES[$role])) {
            flash('error', 'Enter a name, a valid email, and a role.');
            redirect(setup_return('/users'));
        }
        if (DB::value('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
            flash('error', 'A user with that email already exists.');
            redirect(setup_return('/users'));
        }
        $password = self::randomPassword();
        DB::insert('users', [
            'email' => $email,
            'name' => $name,
            'role' => $role,
            'password_hash' => \Align\Security::hashPassword($password),
            'must_change_password' => 1,
        ]);
        Audit::log('user.create', "$email ($role)");
        \Align\Mail\Notify::security('Staff account created', "$email ($role) by " . (Auth::user()['email'] ?? ''));
        $_SESSION['new_password'] = ['email' => $email, 'password' => $password];
        redirect(setup_return('/users'));
    }

    public static function update(int $id): void
    {
        $me = Auth::requireRole('admin');
        // One user change at a time (held until the request ends): two admins demoting or disabling each other at
        // once can't both pass the last-admin check (1.45)
        if ((int) DB::value("SELECT GET_LOCK('msp_align_users', 10)") !== 1) {
            flash('error', 'Another change to the team is in progress. Try again.');
            redirect('/users');
        }
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) {
            redirect('/users');
        }
        $action = post('action');
        $isSelf = (int) $me['id'] === $id;
        $activeAdmins = (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
        $lastAdmin = $u['role'] === 'admin' && $u['is_active'] && $activeAdmins <= 1;

        if ($isSelf && in_array($action, ['reset', 'reset_2fa'], true)) {
            // It would end your own session before the one-time password is shown (1.45): use your Account page
            flash('error', 'Change your own password or authenticator on your Account page.');
            redirect('/users');
        }
        switch ($action) {
            case 'role':
                $role = post('role');
                if (!isset(self::ROLES[$role]) || ($lastAdmin && $role !== 'admin')) {
                    flash('error', 'You cannot remove the last admin.');
                    break;
                }
                DB::run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
                Audit::log('user.role', "{$u['email']} -> $role");
                \Align\Mail\Notify::security('Staff role changed', "{$u['email']}: {$u['role']} → $role by " . (Auth::user()['email'] ?? ''));
                flash('success', 'Role updated.');
                break;
            case 'toggle':
                if ($isSelf || ($lastAdmin && $u['is_active'])) {
                    flash('error', 'You cannot disable yourself or the last admin.');
                    break;
                }
                DB::run('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
                Auth::revokeSessions($id);
                Audit::log($u['is_active'] ? 'user.disable' : 'user.enable', $u['email']);
                \Align\Mail\Notify::security($u['is_active'] ? 'Staff account disabled' : 'Staff account enabled', "{$u['email']} by " . (Auth::user()['email'] ?? ''));
                flash('success', $u['is_active'] ? 'User disabled.' : 'User enabled.');
                break;
            case 'reset':
                $password = self::randomPassword();
                DB::run('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [\Align\Security::hashPassword($password), $id]);
                Auth::revokeSessions($id);
                Audit::log('user.reset_password', $u['email']);
                \Align\Mail\Notify::security('Staff password reset', "{$u['email']} by " . (Auth::user()['email'] ?? ''));
                $_SESSION['new_password'] = ['email' => $u['email'], 'password' => $password];
                break;
            case 'reset_2fa':
                // A one-time password too, as with `align user:reset-password --clear-2fa`: whoever knew the old password
                // must not be the one who sets up the new authenticator (1.45)
                $password = self::randomPassword();
                DB::run('UPDATE users SET totp_enabled = 0, totp_secret_enc = NULL, totp_last_step = NULL, password_hash = ?, must_change_password = 1 WHERE id = ?',
                    [\Align\Security::hashPassword($password), $id]);
                Auth::revokeSessions($id);
                Audit::log('user.reset_2fa', $u['email'] . ' (with a one-time password)');
                \Align\Mail\Notify::security('Staff two-factor reset', "{$u['email']} by " . (Auth::user()['email'] ?? ''));
                $_SESSION['new_password'] = ['email' => $u['email'], 'password' => $password];
                flash('success', 'Two-factor removed, a one-time password issued and their sessions ended. Give them the password below: they choose their own and set up two-factor at the next sign-in.');
                break;
        }
        redirect('/users');
    }

    /** Serves a user's profile picture (signed-in users only). */
    public static function avatar(int $id): void
    {
        Auth::require();
        \Align\Images::serve('avatars', DB::value('SELECT avatar_file FROM users WHERE id = ?', [$id]) ?: null);
    }

    public static function randomPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 20; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return implode('-', str_split($out, 5));
    }
}
