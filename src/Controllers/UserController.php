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
            redirect('/users');
        }
        if (DB::value('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
            flash('error', 'A user with that email already exists.');
            redirect('/users');
        }
        $password = self::randomPassword();
        DB::insert('users', [
            'email' => $email,
            'name' => $name,
            'role' => $role,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        Audit::log('user.create', "$email ($role)");
        $_SESSION['new_password'] = ['email' => $email, 'password' => $password];
        redirect('/users');
    }

    public static function update(int $id): void
    {
        $me = Auth::requireRole('admin');
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) {
            redirect('/users');
        }
        $action = post('action');
        $isSelf = (int) $me['id'] === $id;
        $activeAdmins = (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
        $lastAdmin = $u['role'] === 'admin' && $u['is_active'] && $activeAdmins <= 1;

        switch ($action) {
            case 'role':
                $role = post('role');
                if (!isset(self::ROLES[$role]) || ($lastAdmin && $role !== 'admin')) {
                    flash('error', 'You cannot remove the last admin.');
                    break;
                }
                DB::run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
                Audit::log('user.role', "{$u['email']} -> $role");
                flash('success', 'Role updated.');
                break;
            case 'toggle':
                if ($isSelf || ($lastAdmin && $u['is_active'])) {
                    flash('error', 'You cannot disable yourself or the last admin.');
                    break;
                }
                DB::run('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
                Audit::log($u['is_active'] ? 'user.disable' : 'user.enable', $u['email']);
                flash('success', $u['is_active'] ? 'User disabled.' : 'User enabled.');
                break;
            case 'reset':
                $password = self::randomPassword();
                DB::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                Audit::log('user.reset_password', $u['email']);
                $_SESSION['new_password'] = ['email' => $u['email'], 'password' => $password];
                break;
            case 'reset_2fa':
                DB::run('UPDATE users SET totp_enabled = 0, totp_secret_enc = NULL WHERE id = ?', [$id]);
                Audit::log('user.reset_2fa', $u['email']);
                flash('success', 'Two-factor removed. They can set it up again under Account.');
                break;
        }
        redirect('/users');
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
