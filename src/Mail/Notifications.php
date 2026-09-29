<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\DB;
use Align\Settings;

/**
 * What Align can email, and who gets it.
 *
 * Staff notifications: an admin switches each one on or off and sets who gets it by default
 * (by role, plus "always the client's vCIO" and extra addresses such as a ticketing inbox).
 * Each staff member can then opt in or out on their Account page and choose all clients or only
 * the clients they're vCIO for. Client emails go to the people concerned (portal user, attendees).
 */
final class Notifications
{
    /** key => [label, group, audience, timing, description, default roles, vCIO by default, on by default] */
    public const CATALOG = [
        'backup_failed' => ['Backup job failed', 'Backups & sync', 'staff', 'immediate', 'When a sync finds a backup job that failed on its last run (once per failure).', ['admin', 'tech'], true, true],
        'backup_digest' => ['Daily backup summary', 'Backups & sync', 'staff', 'daily', 'Failed jobs, overdue machines and Microsoft 365 items, and servers with no backup. Skipped when everything is healthy.', ['admin'], true, true],
        'sync_failed' => ['Sync problems', 'Backups & sync', 'staff', 'immediate', 'When the hourly sync fails or partly fails, and again when it recovers.', ['admin'], false, true],
        'renewals' => ['Contracts & renewals', 'Planning', 'staff', 'weekly', 'Contract end dates, renegotiation deadlines and license renewals in the next 60 days.', ['admin', 'tech'], true, true],
        'meetings_due' => ['Meetings due', 'Planning', 'staff', 'weekly', 'Clients due for a review meeting with nothing scheduled, plus the week\'s meetings.', ['admin', 'tech'], true, true],
        'lifecycle' => ['Warranty & end of life', 'Planning', 'staff', 'monthly', 'Devices whose warranty ends or that reach end of life in the next 90 days.', ['admin', 'tech'], true, true],
        'weekly_digest' => ['Weekly vCIO digest', 'Planning', 'staff', 'weekly', 'One summary per vCIO: meetings this week, decisions waiting, renewals and backup problems for their clients.', ['admin'], true, true],
        'meeting_reminder' => ['Meeting reminders', 'Meetings', 'staff', 'before', 'A reminder to the meeting owner before each meeting, with the agenda and a link.', ['admin', 'tech', 'viewer'], false, true],
        'portal_activity' => ['Client portal activity', 'Client portal', 'staff', 'immediate', 'When a client approves or declines a project or suggests a license or budget item in the portal.', ['admin'], true, true],
        'security' => ['Security alerts', 'Security', 'staff', 'immediate', 'Account lockouts, new or changed staff accounts, two-factor resets, integration keys changed, data restored from a backup and audit log problems. Admins only.', ['admin'], false, true],
        'updates' => ['Updates', 'System', 'staff', 'immediate', 'When a new version of MSP-ALIGN is available (checked every 6 hours), and when an update finishes or fails.', ['admin'], false, true],
        'backup_reminder' => ['Backup reminder', 'System', 'staff', 'overdue', 'Backups are only kept where you download them. Reminds you when nobody has downloaded one for the number of days set on Updates & backups.', ['admin'], false, true],
        'client_portal_invite' => ['Portal invitations', 'Client emails', 'client', 'immediate', 'Email portal invite and password links straight to the client instead of copying the link.', [], false, true],
        'client_portal_reset' => ['Portal "Forgot password"', 'Client emails', 'client', 'immediate', 'Lets portal users reset their own password by email from the sign-in page (two-factor is still required).', [], false, true],
        'client_submission_decided' => ['Portal suggestions reviewed', 'Client emails', 'client', 'immediate', 'Tell the portal user who suggested a license or budget item when you add it or decline it (with your note).', [], false, true],
        'client_meeting_invite' => ['Meeting invitations', 'Client emails', 'client', 'immediate', 'Send invitations to attendees when a meeting is scheduled, changed or cancelled.', [], false, true],
        'client_meeting_reminder' => ['Meeting reminders to attendees', 'Client emails', 'client', 'before', 'A reminder email to external attendees before the meeting (Outlook invitations already remind people, so this is off by default).', [], false, false],
    ];

    public const TIMING = ['immediate' => 'As it happens', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly (1st)', 'before' => 'Before each meeting', 'overdue' => 'When overdue'];

    public static function staffKeys(): array
    {
        return array_keys(array_filter(self::CATALOG, fn($c) => $c[2] === 'staff'));
    }

    /** Switched on by an admin (and email is set up). */
    public static function enabled(string $key): bool
    {
        if (!isset(self::CATALOG[$key]) || !Mail::on()) {
            return false;
        }
        return Settings::get("notif_$key", self::CATALOG[$key][7] ? '1' : '0') === '1';
    }

    public static function roles(string $key): array
    {
        if ($key === 'security') {
            return ['admin'];
        }
        $v = Settings::get("notif_{$key}_roles");
        return $v === null ? self::CATALOG[$key][5] : array_values(array_intersect(['admin', 'tech', 'viewer'], explode(',', $v)));
    }

    public static function toVcio(string $key): bool
    {
        return Settings::get("notif_{$key}_vcio", self::CATALOG[$key][6] ? '1' : '0') === '1';
    }

    public static function extra(string $key): array
    {
        return Mailer::recipients(preg_split('/[\s,;]+/', (string) Settings::get("notif_{$key}_extra", '')) ?: []);
    }

    /** A user's own choice for each staff notification: true/false, or the role default. */
    public static function prefsFor(array $user): array
    {
        $rows = [];
        foreach (DB::all('SELECT notif_key, enabled FROM user_notification_prefs WHERE user_id = ?', [$user['id']]) as $r) {
            $rows[$r['notif_key']] = (bool) $r['enabled'];
        }
        $out = [];
        foreach (self::staffKeys() as $k) {
            if ($k === 'security' && $user['role'] !== 'admin') {
                continue;
            }
            $out[$k] = ['on' => $rows[$k] ?? in_array($user['role'], self::roles($k), true), 'explicit' => isset($rows[$k])];
        }
        return $out;
    }

    public static function scope(array $user): string
    {
        return $user['notify_scope'] ?? ($user['role'] === 'admin' ? 'all' : 'mine');
    }

    /** Active staff who want $key, each with the client ids they cover. [['user' => row, 'clients' => ids|null (all)]] */
    public static function subscribers(string $key): array
    {
        if (!self::enabled($key)) {
            return [];
        }
        $out = [];
        foreach (DB::all("SELECT id, email, name, role, notify_scope FROM users WHERE is_active = 1 AND email <> ''") as $u) {
            $p = self::prefsFor($u)[$key] ?? null;
            if (!$p) {
                continue;
            }
            $vcioOf = array_map('intval', array_column(DB::all('SELECT id FROM clients WHERE vcio_user_id = ? AND is_archived = 0 AND planning_excluded = 0', [$u['id']]), 'id'));
            $wants = $p['on'] || (!$p['explicit'] && self::toVcio($key) && $vcioOf);
            if (!$wants) {
                continue;
            }
            // Opted in: their scope. Only here as vCIO: just their clients.
            $out[] = ['user' => $u, 'clients' => $p['on'] && self::scope($u) === 'all' ? null : $vcioOf];
        }
        return $out;
    }

    /** Everyone to tell about one client's event (plus extra addresses). */
    public static function recipientsFor(string $key, ?int $clientId): array
    {
        $to = [];
        foreach (self::subscribers($key) as $s) {
            if ($s['clients'] === null || ($clientId !== null && in_array($clientId, $s['clients'], true))) {
                $to[] = ['address' => $s['user']['email'], 'name' => $s['user']['name']];
            }
        }
        return Mailer::recipients(array_merge($to, self::enabled($key) ? self::extra($key) : []));
    }

    public static function url(string $path = ''): string
    {
        return \Align\Portal\PortalAuth::baseUrl() . $path;
    }

    public static function footer(): string
    {
        return 'You receive this from MSP-ALIGN. Change which emails you get under Account → Email notifications.';
    }

    public static function state(string $k): ?string
    {
        $v = DB::value('SELECT v FROM notify_state WHERE k = ?', [$k]);
        return $v === null || $v === false ? null : (string) $v;
    }

    public static function setState(string $k, ?string $v): void
    {
        DB::run('INSERT INTO notify_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [mb_substr($k, 0, 190), $v]);
    }
}
