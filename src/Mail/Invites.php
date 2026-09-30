<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\DB;
use Align\Mail\Notifications as N;
use Align\Mail\Template as T;
use Align\Settings;

/**
 * Meeting invitations and reminders.
 *  - calendar mode (default): the meeting is created in Outlook through Graph, so Exchange sends real
 *    invitations, updates and cancellations, attendees can accept, and a Teams link can be added.
 *  - ics mode: an email with an .ics invitation attached (works with any mail system). Always used with
 *    SMTP, where the invitation is also sent as a text/calendar part so mail apps show Accept / Decline.
 */
final class Invites
{
    public const MODES = ['calendar' => 'Calendar invitations: Outlook or Google Calendar (recommended)', 'ics' => 'Email with .ics attachment'];

    public static function enabled(): bool
    {
        return N::enabled('client_meeting_invite') && Mail::ready();
    }

    /** Email addresses found in the attendees field ("Name <a@b.com>, c@d.com, Jane"). */
    public static function attendees(?string $text): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', (string) $text) ?: [] as $part) {
            if (preg_match('/([A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,})/i', $part, $m)) {
                $name = trim(preg_replace('/<[^>]*>|\([^)]*\)/', '', str_replace($m[1], '', $part)) ?? '', " \t\"'<>");
                $out[] = ['address' => $m[1], 'name' => $name];
            }
        }
        return Mailer::recipients($out);
    }

    private static function load(int $id): ?array
    {
        return DB::one('SELECT m.*, c.name AS client_name, u.email AS owner_email, u.name AS owner_name FROM meetings m
            LEFT JOIN clients c ON c.id = m.client_id LEFT JOIN users u ON u.id = m.owner_id WHERE m.id = ?', [$id]);
    }

    /**
     * Sends (or updates / cancels) the invitation for a meeting. $action: save | cancel.
     * Returns a short result for the flash message, or null when nothing was sent.
     */
    public static function send(int $meetingId, string $action = 'save'): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $m = self::load($meetingId);
        if (!$m) {
            return null;
        }
        $to = self::attendees($m['attendees']);
        if ($action === 'cancel' && !$m['invites_sent_at']) {
            return null;
        }
        if (!$to && !$m['graph_event_id']) {
            return null;
        }
        try {
            $ics = Settings::get('mail_meeting_mode', 'calendar') === 'ics' || !Mail::hasCalendar()
                || (Mail::provider() === 'google' && Mail::mode() === 'delegated' && Settings::get('g_calendar_granted') === '0');
            $note = !Mail::hasCalendar() && $m['graph_event_id'] ? ' It was first sent as a calendar invitation, so also ' . ($action === 'cancel' ? 'cancel' : 'update') . ' it in that calendar.' : '';
            return ($ics ? self::viaIcs($m, $to, $action) : self::viaCalendar($m, $to, $action)) . $note;
        } catch (\Throwable $e) {
            \Align\Audit::log('meeting.invite_failed', $m['title'] . ': ' . safe_error($e));
            return 'Invitations were not sent: ' . safe_error($e);
        }
    }

    private static function subject(array $m): string
    {
        return ($m['client_name'] ? $m['client_name'] . ' — ' : '') . $m['title'];
    }

    private static function bodyHtml(array $m): string
    {
        $h = '';
        if ($m['agenda']) {
            $h .= '<p><b>Agenda</b></p><p>' . nl2br(e($m['agenda'])) . '</p>';
        }
        if ($m['video_url']) {
            $h .= '<p><b>Join:</b> <a href="' . e($m['video_url']) . '">' . e($m['video_url']) . '</a></p>';
        }
        return $h . '<p style="color:#7b8594">' . e(Settings::get('company_name') ?: 'Your company') . '</p>';
    }

    /** Where an existing calendar event lives: [provider, mailbox]. */
    private static function stored(array $m): array
    {
        $mb = (string) $m['graph_mailbox'];
        return str_starts_with($mb, 'google:') ? ['google', substr($mb, 7)] : ['microsoft', $mb];
    }

    private static function viaCalendar(array $m, array $to, string $action): string
    {
        $c = Mail::client();
        [$evProvider, $mailbox] = self::stored($m);
        // (never reached with SMTP: send() uses .ics emails there)
        // An event made with the other provider (settings changed since) can't be updated from here
        $eventId = $m['graph_event_id'] && $evProvider === Mail::provider() ? $m['graph_event_id'] : null;
        if ($action === 'cancel') {
            if ($eventId) {
                $c->calendarCancel($mailbox, $eventId, 'This meeting has been cancelled.');
                DB::run('UPDATE meetings SET invite_sequence = invite_sequence + 1 WHERE id = ?', [$m['id']]);
                return 'Cancellation sent to attendees.';
            }
            return self::viaIcs($m, $to, 'cancel');
        }
        $info = [
            'uid' => (string) $m['uid'],
            'subject' => self::subject($m),
            'html' => self::bodyHtml($m),
            'text' => trim(($m['agenda'] ? "Agenda:\n" . $m['agenda'] . "\n\n" : '') . ($m['video_url'] ? 'Join: ' . $m['video_url'] : '')),
            'start' => $m['starts_at'],
            'end' => $m['ends_at'],
            'location' => $m['location'] ?: ($m['video_url'] ? 'Online' : null),
            'online' => !$m['video_url'] && Settings::get('mail_teams_links', '1') === '1',
        ];
        if ($eventId) {
            $r = $c->calendarUpdate($mailbox, $eventId, $info, $to);
            $verb = 'Updated invitation sent';
        } else {
            // In unattended mode the meeting owner is the organizer when they have a mailbox in the tenant/domain
            $organizers = [];
            if (Mail::mode() === 'app' && Settings::get('mail_meeting_organizer', 'owner') === 'owner' && $m['owner_email']) {
                $organizers[] = strtolower($m['owner_email']);
            }
            $organizers[] = null; // the sending mailbox
            $r = null;
            foreach ($organizers as $i => $org) {
                try {
                    $r = $c->calendarCreate($org, $info, $to);
                    break;
                } catch (GraphException $e) {
                    if ($i === count($organizers) - 1 || !in_array($e->getCode(), [400, 401, 403, 404], true)) {
                        throw $e;
                    }
                }
            }
            $verb = 'Invitation sent';
        }
        $join = $r['join'] ?? null;
        DB::run('UPDATE meetings SET graph_event_id = ?, graph_mailbox = ?, online_join_url = COALESCE(?, online_join_url),
            video_url = COALESCE(video_url, ?), invites_sent_at = NOW(), invite_sequence = invite_sequence + 1 WHERE id = ?',
            [$r['id'] ?? $eventId, $r['mailbox'] ?? $m['graph_mailbox'], $join, $join, $m['id']]);
        \Align\Audit::log('meeting.invite', self::subject($m) . ' → ' . implode(', ', array_column($to, 'address')));
        return $verb . ' to ' . count($to) . ' attendee' . (count($to) === 1 ? '' : 's') . ' through ' . $c->calendarLabel() . ($join ? ' with a ' . $c->meetingLabel() . ' link' : '') . '.';
    }

    private static function viaIcs(array $m, array $to, string $action): string
    {
        $cancel = $action === 'cancel';
        $organizer = Mail::fromAddress();
        $ics = self::ics($m, $cancel ? 'CANCEL' : 'REQUEST', $organizer, $to, (int) $m['invite_sequence'] + 1);
        $when = \Align\Fmt::dateTime($m['starts_at'], 'dayfull') . ' – ' . \Align\Fmt::time($m['ends_at']) . ' ' . date('T', strtotime($m['ends_at']));
        $blocks = [T::p($cancel ? 'This meeting has been cancelled.' : ($m['invites_sent_at'] ? 'This meeting has been updated.' : 'You\'re invited to a meeting.')),
            T::facts(['Meeting' => self::subject($m), 'When' => $when, 'Where' => $m['location'], 'Join' => $m['video_url'], 'Organizer' => $m['owner_name']])];
        if ($m['agenda'] && !$cancel) {
            $blocks[] = T::h2('Agenda');
            $blocks[] = T::p($m['agenda']);
        }
        $blocks[] = T::p('The calendar invitation is attached.', true);
        Mailer::queue('client_meeting_invite', $to, ($cancel ? 'Cancelled: ' : ($m['invites_sent_at'] ? 'Updated: ' : 'Invitation: ')) . self::subject($m),
            T::render(self::subject($m), $blocks), [
                'client_id' => $m['client_id'] ? (int) $m['client_id'] : null, 'immediate' => true,
                'attachments' => [['name' => $cancel ? 'cancel.ics' : 'invite.ics', 'type' => 'text/calendar; charset=utf-8; method=' . ($cancel ? 'CANCEL' : 'REQUEST'), 'content' => $ics]],
            ]);
        DB::run('UPDATE meetings SET invites_sent_at = NOW(), invite_sequence = invite_sequence + 1 WHERE id = ?', [$m['id']]);
        \Align\Audit::log($cancel ? 'meeting.invite_cancel' : 'meeting.invite', self::subject($m) . ' → ' . implode(', ', array_column($to, 'address')));
        return ($cancel ? 'Cancellation' : 'Invitation') . ' emailed to ' . count($to) . ' attendee' . (count($to) === 1 ? '' : 's') . '.';
    }

    /** iTIP invitation (RFC 5546): METHOD REQUEST or CANCEL with organizer, attendees and sequence. */
    public static function ics(array $m, string $method, string $organizer, array $to, int $seq): string
    {
        $host = (string) (\Align\Config::get('fqdn') ?: parse_url((string) \Align\Config::get('base_url', ''), PHP_URL_HOST) ?: 'align.local');
        $esc = fn(string $s) => str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
        $utc = fn(string $t) => gmdate('Ymd\THis\Z', (int) strtotime($t));
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//MSP-ALIGN//MSP-ALIGN//EN', 'METHOD:' . $method, 'BEGIN:VEVENT',
            'UID:' . $m['uid'] . '@' . $host, 'SEQUENCE:' . $seq, 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $utc($m['starts_at']), 'DTEND:' . $utc($m['ends_at']), 'SUMMARY:' . $esc(self::subject($m)),
            'STATUS:' . ($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED')];
        if ($organizer !== '') {
            $lines[] = 'ORGANIZER;CN=' . $esc((string) (Settings::get('mail_from_name') ?: Settings::get('company_name') ?: 'Your company')) . ':mailto:' . $organizer;
        }
        foreach ($to as $r) {
            $lines[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE' . ($r['name'] ? ';CN=' . $esc($r['name']) : '') . ':mailto:' . $r['address'];
        }
        if ($m['location'] || $m['video_url']) {
            $lines[] = 'LOCATION:' . $esc((string) ($m['location'] ?: $m['video_url']));
        }
        $desc = trim(($m['video_url'] ? 'Join: ' . $m['video_url'] . "\n\n" : '') . (string) $m['agenda']);
        if ($desc !== '') {
            $lines[] = 'DESCRIPTION:' . $esc($desc);
        }
        array_push($lines, 'END:VEVENT', 'END:VCALENDAR');
        $fold = function (string $l) {
            $out = '';
            while (strlen($l) > 74) {
                $cut = 74;
                while ($cut > 1 && (ord($l[$cut]) & 0xC0) === 0x80) {
                    $cut--; // don't split a UTF-8 character
                }
                $out .= substr($l, 0, $cut) . "\r\n ";
                $l = substr($l, $cut);
            }
            return $out . $l;
        };
        return implode("\r\n", array_map($fold, $lines)) . "\r\n";
    }

    /** Reminders before meetings: to the owner (staff) and, if switched on, to external attendees. */
    public static function reminders(): int
    {
        $staff = N::enabled('meeting_reminder');
        $client = N::enabled('client_meeting_reminder');
        if (!$staff && !$client) {
            return 0;
        }
        $hours = max(1, min(168, Settings::int('notif_meeting_reminder_hours', 24)));
        $rows = DB::all("SELECT m.id FROM meetings m WHERE m.status = 'scheduled' AND m.reminder_sent_at IS NULL AND m.starts_at > NOW() AND m.starts_at <= ?",
            [date('Y-m-d H:i:s', time() + $hours * 3600)]);
        $n = 0;
        foreach ($rows as $r) {
            DB::run('UPDATE meetings SET reminder_sent_at = NOW() WHERE id = ?', [$r['id']]);
            $m = self::load((int) $r['id']);
            $when = \Align\Fmt::dateTime($m['starts_at'], 'weekday') . ' – ' . \Align\Fmt::time($m['ends_at']) . ' ' . date('T', strtotime($m['ends_at']));
            if ($staff && $m['owner_email']) {
                $owner = DB::one('SELECT id, email, name, role, notify_scope FROM users WHERE id = ? AND is_active = 1', [$m['owner_id']]);
                if ($owner && (N::prefsFor($owner)['meeting_reminder']['on'] ?? false)) {
                    $pending = $m['client_id'] ? DB::all("SELECT title FROM roadmap_items WHERE client_id = ? AND status = 'proposed' ORDER BY title", [$m['client_id']]) : [];
                    $blocks = [T::facts(['Meeting' => self::subject($m), 'When' => $when, 'Where' => $m['location'], 'Join' => $m['video_url'], 'Attendees' => $m['attendees']])];
                    if ($m['agenda']) {
                        $blocks[] = T::h2('Agenda');
                        $blocks[] = T::p($m['agenda']);
                    }
                    if ($pending) {
                        $blocks[] = T::h2('Waiting for a client decision');
                        $blocks[] = T::items(array_map(fn($p) => [$p['title'], ''], $pending));
                    }
                    $blocks[] = T::button('Open meeting', N::url('/meetings/' . $m['id']));
                    if ($m['client_id']) {
                        $blocks[] = T::link('Business review pack for ' . $m['client_name'], N::url('/clients/' . $m['client_id'] . '/report/qbr'));
                    }
                    $n += Mailer::queue('meeting_reminder', [['address' => $owner['email'], 'name' => $owner['name']]], 'Reminder: ' . self::subject($m) . ' — ' . date('D ', strtotime($m['starts_at'])) . \Align\Fmt::time($m['starts_at']),
                        T::render('Upcoming meeting', $blocks, N::footer()), ['dedupe' => 'mr:' . $m['id'] . ':' . $m['starts_at'], 'created_by' => null]) ? 1 : 0;
                }
            }
            if ($client && $m['type'] !== 'internal' && ($to = self::attendees($m['attendees']))) {
                $n += Mailer::queue('client_meeting_reminder', $to, 'Reminder: ' . self::subject($m),
                    T::render('Meeting reminder', [T::p('A reminder about your upcoming meeting.'), T::facts(['Meeting' => self::subject($m), 'When' => $when, 'Where' => $m['location'], 'Join' => $m['video_url']])]),
                    ['dedupe' => 'mrc:' . $m['id'] . ':' . $m['starts_at'], 'client_id' => $m['client_id'] ? (int) $m['client_id'] : null, 'created_by' => null]) ? 1 : 0;
            }
        }
        return $n;
    }
}
