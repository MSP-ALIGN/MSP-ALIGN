<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Portal\PortalAuth;
use Align\View;

/** Staff side of the client portal: invite client users, set what they can see and do, reset access. */
final class PortalAdminController
{
    private const PERMS = ['can_roadmap', 'can_budget', 'can_devices', 'can_documents', 'can_approve', 'can_contacts'];

    /** Every portal user across clients. */
    public static function index(): void
    {
        Auth::requireRole('tech');
        View::render('portal_admin/index', [
            'title' => 'Client portal users',
            'nav' => 'portal-users',
            'users' => DB::all('SELECT p.*, c.name AS client_name FROM portal_users p JOIN clients c ON c.id = p.client_id ORDER BY c.name, p.name'),
            'portalUrl' => PortalAuth::baseUrl() . '/portal',
        ]);
    }

    public static function show(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $link = $_SESSION['portal_link'] ?? null;
        unset($_SESSION['portal_link']);
        View::render('portal_admin/client', [
            'title' => $client['name'] . ' · Client portal',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'portal',
            'users' => DB::all('SELECT * FROM portal_users WHERE client_id = ? ORDER BY is_active DESC, name', [$id]),
            'link' => $link && (int) $link['client_id'] === $id ? $link : null,
            'portalUrl' => PortalAuth::baseUrl() . '/portal',
            'contacts' => DB::all("SELECT name, email FROM contacts WHERE client_id = ? AND archived_at IS NULL AND email IS NOT NULL
                AND email NOT IN (SELECT email FROM portal_users) ORDER BY decision_maker DESC, is_primary DESC, name", [$id]),
            'activity' => DB::all('SELECT a.*, p.name AS portal_name FROM audit_log a JOIN portal_users p ON p.id = a.portal_user_id
                WHERE p.client_id = ? AND a.action <> ? ORDER BY a.id DESC LIMIT 25', [$id, 'portal.view']),
        ]);
    }

    private static function perms(): array
    {
        $p = [];
        foreach (self::PERMS as $k) {
            $p[$k] = isset($_POST[$k]) ? 1 : 0;
        }
        // Actions need the section they act on
        if (!$p['can_roadmap']) {
            $p['can_approve'] = 0;
        }
        if (!$p['can_documents']) {
            $p['can_contacts'] = 0;
        }
        return $p;
    }

    /** Remembers a freshly issued link so the next page can show it once. */
    private static function showLink(array $u, string $url, string $kind): void
    {
        $_SESSION['portal_link'] = ['client_id' => (int) $u['client_id'], 'user_id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'url' => $url, 'kind' => $kind];
    }

    public static function create(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $email = strtolower(trim(post('email')));
        $name = mb_substr(trim(post('name')), 0, 190);
        $back = "/clients/$id/portal";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            flash('error', 'Enter a name and a valid email address.');
            redirect($back);
        }
        $existing = DB::one('SELECT p.id, c.name AS client_name FROM portal_users p JOIN clients c ON c.id = p.client_id WHERE p.email = ?', [$email]);
        if ($existing) {
            // Don't reveal other clients' users; just say the email is taken
            flash('error', "$email already has a client portal account" . ((int) DB::value('SELECT client_id FROM portal_users WHERE id = ?', [$existing['id']]) === $id ? ' here.' : ' with another client. Each email can belong to one client.'));
            redirect($back);
        }
        if (DB::value('SELECT id FROM users WHERE email = ?', [$email])) {
            flash('error', "$email is a staff account. Use a different email for the client portal.");
            redirect($back);
        }
        $uid = DB::insert('portal_users', ['client_id' => $id, 'email' => $email, 'name' => $name, 'invited_by' => Auth::id()] + self::perms());
        $u = DB::one('SELECT * FROM portal_users WHERE id = ?', [$uid]);
        self::showLink($u, PortalAuth::issueLink($uid), 'invite');
        Audit::log('portal_user.invite', "{$client['name']}: $email");
        flash('success', "Invited $name. Send them the link below.");
        redirect($back);
    }

    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $u = DB::one('SELECT p.*, c.name AS client_name FROM portal_users p JOIN clients c ON c.id = p.client_id WHERE p.id = ?', [$id]);
        if (!$u) {
            redirect('/portal-users');
        }
        $back = post('back') === 'list' ? '/portal-users' : "/clients/{$u['client_id']}/portal";
        $label = "{$u['client_name']}: {$u['email']}";
        switch (post('action')) {
            case 'link':
                self::showLink($u, PortalAuth::issueLink($id), $u['password_hash'] ? 'reset' : 'invite');
                Audit::log('portal_user.link', $label);
                flash('success', ($u['password_hash'] ? 'Password reset link' : 'New invite link') . " created for {$u['name']}. Any earlier link no longer works.");
                redirect("/clients/{$u['client_id']}/portal");
            case 'disable':
                DB::run('UPDATE portal_users SET is_active = 0, invite_token_hash = NULL, invite_expires_at = NULL WHERE id = ?', [$id]);
                PortalAuth::revokeSessions($id);
                Audit::log('portal_user.disable', $label);
                flash('success', "Disabled {$u['name']}. They are signed out and can't sign in.");
                redirect($back);
            case 'enable':
                DB::run('UPDATE portal_users SET is_active = 1 WHERE id = ?', [$id]);
                Audit::log('portal_user.enable', $label);
                flash('success', "Enabled {$u['name']}." . ($u['password_hash'] ? '' : ' Send them a new invite link.'));
                redirect($back);
            case 'reset2fa':
                DB::run('UPDATE portal_users SET totp_enabled = 0, totp_secret_enc = NULL, totp_last_step = NULL WHERE id = ?', [$id]);
                PortalAuth::revokeSessions($id);
                Audit::log('portal_user.reset_2fa', $label);
                flash('success', "Two-factor sign-in reset for {$u['name']} and their sessions ended. They'll set it up again at their next sign-in.");
                redirect($back);
            case 'delete':
                DB::run('DELETE FROM portal_users WHERE id = ?', [$id]);
                Audit::log('portal_user.delete', $label);
                flash('success', "Deleted {$u['name']}'s portal account. Their past decisions stay in the history.");
                redirect($back);
        }
        $name = mb_substr(trim(post('name')), 0, 190) ?: $u['name'];
        DB::run('UPDATE portal_users SET name = ?, ' . implode(', ', array_map(fn($k) => "$k = ?", self::PERMS)) . ' WHERE id = ?',
            [$name, ...array_values(self::perms()), $id]);
        Audit::log('portal_user.update', $label);
        flash('success', "Saved access for $name.");
        redirect($back);
    }
}
