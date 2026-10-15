<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Calendar\Outlook;

/**
 * 2.23.0 Connecting your own Outlook calendar to Align (two-way, Calendar\Outlook): connect, the sign-in's return,
 * sync now and disconnect, from Your account or the Calendar page; and admins disconnecting someone (Integrations →
 * Email lists who is connected).
 *
 * Security assumptions: every staff role may connect their own calendar; the user id always comes from the session
 * (Auth::require()), never the request, except the admin-only disconnect, which takes a user id and checks the role.
 * POSTs are CSRF-checked by the router. The sign-in's state and PKCE verifier stay in the person's session: single
 * use, 15 minutes, and tied to the user who started it, so another site can't connect its own account here or replay
 * a response. Microsoft's error text is shown only for a response to this session's own sign-in. Only same-site
 * return paths are used (/calendar or /account).
 */
final class CalendarController
{
    /** Where to go back to: the Calendar page or Your account (fixed choices). */
    private static function back(): string
    {
        return in_array($_SESSION['cal_return'] ?? '', ['/calendar', '/account'], true) ? $_SESSION['cal_return'] : '/account';
    }

    /**
     * $u (the signed-in person), once their account is fully set up: these pages are under /account, which Auth::require()
     * lets through before a temporary password is changed and 2FA is on, so that's checked here.
     */
    private static function finished(array $u): array
    {
        if ($u['must_change_password'] || !$u['totp_enabled']) {
            flash('warning', 'Finish setting up your account (new password, two-factor sign-in) first.');
            redirect('/account');
        }
        return $u;
    }

    /** Starts the sign-in for the signed-in person. */
    public static function connect(): void
    {
        $u = self::finished(Auth::require());
        $_SESSION['cal_return'] = query('return') === '/calendar' ? '/calendar' : '/account';
        if (!Outlook::available()) {
            flash('error', 'Outlook calendars need email set up with Microsoft 365 first (an admin does this under Integrations → Email).');
            redirect(self::back());
        }
        [$url, $state, $verifier] = Outlook::authorizeUrl((string) $u['email']);
        $_SESSION['cal_oauth'] = ['state' => $state, 'verifier' => $verifier, 'at' => time(), 'user' => (int) $u['id']];
        header('Location: ' . $url, true, 302);
        exit;
    }

    /** The sign-in's return: checks the state, stores the connection and syncs once straight away. */
    public static function callback(): void
    {
        $u = self::finished(Auth::require());
        set_time_limit(300); // a first sync or removing every copy can take a while on a big calendar
        $saved = $_SESSION['cal_oauth'] ?? null;
        unset($_SESSION['cal_oauth']);
        $matches = is_array($saved) && time() - (int) ($saved['at'] ?? 0) <= 900 && (int) ($saved['user'] ?? 0) === (int) $u['id']
            && hash_equals((string) ($saved['state'] ?? ''), query('state'));
        if (query('error') !== '') {
            flash('error', $matches ? 'The sign-in wasn\'t completed: ' . mb_strimwidth(query('error_description') ?: query('error'), 0, 300, '…') : 'The sign-in wasn\'t completed. Connect again.');
            redirect(self::back());
        }
        if (!$matches || query('code') === '') {
            flash('error', 'That sign-in response didn\'t match this browser session. Connect again.');
            redirect(self::back());
        }
        try {
            $me = Outlook::complete((int) $u['id'], query('code'), (string) $saved['verifier']);
            Audit::log('calendar.connected', 'Outlook calendar ' . $me['account']);
            $r = Outlook::syncUser((int) $u['id']);
            flash(str_starts_with($r, 'failed') ? 'warning' : 'success', 'Connected your Outlook calendar (' . $me['account'] . ').' . match (true) {
                str_starts_with($r, 'failed') => ' The first sync didn\'t work yet: ' . substr($r, 8),
                $r === 'already syncing' => ' It syncs within a minute.',
                default => ' Your Outlook events show on Align\'s calendar, and meetings you own in Align go into Outlook.',
            });
        } catch (\Throwable $e) {
            flash('error', 'Couldn\'t connect: ' . safe_error($e));
        }
        redirect(self::back());
    }

    /** Syncs the signed-in person's calendar now. */
    public static function sync(): void
    {
        $u = self::finished(Auth::require());
        set_time_limit(300); // a first sync or removing every copy can take a while on a big calendar
        $r = Outlook::connection((int) $u['id']) ? Outlook::syncUser((int) $u['id']) : 'not connected';
        flash(match (true) { str_starts_with($r, 'failed'), $r === 'not connected' => 'error', $r === 'already syncing' => 'info', default => 'success' }, 'Outlook calendar: ' . $r . '.');
        redirect(post('return') === '/calendar' ? '/calendar' : '/account');
    }

    /** Disconnects the signed-in person's own calendar (Align's copies of their meetings are removed from Outlook). */
    public static function disconnect(): void
    {
        $u = self::finished(Auth::require());
        set_time_limit(300); // a first sync or removing every copy can take a while on a big calendar
        $c = Outlook::connection((int) $u['id']);
        if ($c) {
            Outlook::disconnect((int) $u['id']);
            Audit::log('calendar.disconnected', 'Outlook calendar ' . $c['account']);
            flash('success', 'Disconnected your Outlook calendar. Align\'s copies of your meetings were removed from it.');
        }
        redirect(post('return') === '/calendar' ? '/calendar' : '/account');
    }

    /** Admins: disconnects someone's calendar (for example when they leave). */
    public static function adminDisconnect(int $id): void
    {
        Auth::requireRole('admin');
        set_time_limit(300);
        $c = Outlook::connection($id);
        if ($c) {
            Outlook::disconnect($id);
            Audit::log('calendar.disconnected', 'Outlook calendar ' . $c['account'] . ' (by an admin)');
            flash('success', 'Disconnected ' . $c['account'] . '.');
        }
        redirect('/integrations/email#calendars');
    }
}
