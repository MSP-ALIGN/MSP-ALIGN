<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Meetings\Ics;
use Align\Meetings\Meetings;
use Align\Settings;
use Align\View;

/**
 * Staff meeting pages: lists, a meeting's page, scheduling (one-off or a series), editing, status changes, notes,
 * deleting, the .ics download, the calendar and its JSON events, and each user's calendar feed link.
 *
 * Security assumptions: every action starts with its role check. Any staff role (viewers included) may read
 * meetings, agendas, notes and attendees, and download a meeting's .ics; techs and admins schedule, edit, change
 * status and delete, and only they get a calendar feed. The Router has checked CSRF on every POST. Invitations
 * and cancellations go out from a staff mailbox (Mail\Invites, which reduces the attendee text to valid addresses);
 * they are sent only for a scheduled meeting and only when asked, except a cancellation when a meeting whose
 * invitations went out is cancelled or deleted. The feed is public but looked up by the SHA-256 of a 256-bit token,
 * and carries titles, client names and times only.
 */
final class MeetingController
{
    private const SELECT = 'SELECT m.*, c.name AS client_name, u.name AS owner_name
        FROM meetings m LEFT JOIN clients c ON c.id = m.client_id LEFT JOIN users u ON u.id = m.owner_id';

    /** All meetings (upcoming, past or all; at most 300) and the clients due for one. Any staff role. */
    public static function index(): void
    {
        Auth::require();
        $view = query('view', 'upcoming');
        $where = match ($view) {
            'past' => "m.starts_at < NOW() ORDER BY m.starts_at DESC",
            'all' => "1=1 ORDER BY m.starts_at DESC",
            default => "m.starts_at >= CURDATE() AND m.status = 'scheduled' ORDER BY m.starts_at",
        };
        $clients = DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name');
        $cadence = Meetings::cadence();
        $overdue = array_filter($clients, fn($c) => $cadence[$c['id']]['overdue'] ?? false);
        View::render('meetings/index', [
            'title' => 'Meetings',
            'nav' => 'meetings',
            'meetings' => DB::all(self::SELECT . " WHERE $where LIMIT 300"),
            'view' => $view,
            'clients' => $clients,
            'users' => ClientController::users(),
            'overdue' => array_values($overdue),
            'cadence' => $cadence,
        ]);
    }

    /** One client's meetings and its cadence. Any staff role. */
    public static function clientIndex(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        View::render('meetings/client', [
            'title' => $client['name'] . ' · Meetings',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'meetings',
            'meetings' => DB::all(self::SELECT . ' WHERE m.client_id = ? ORDER BY m.starts_at DESC', [$id]),
            'cadence' => Meetings::cadence()[$id] ?? null,
            'clients' => [['id' => $client['id'], 'name' => $client['name']]],
            'users' => ClientController::users(),
        ]);
    }

    /** The meeting with its client and owner names, or a 404 page and exit. Callers have done their role check. */
    private static function load(int $id): array
    {
        $m = DB::one(self::SELECT . ' WHERE m.id = ?', [$id]);
        if (!$m) {
            http_response_code(404);
            View::render('error', ['title' => 'Meeting not found', 'message' => 'That meeting does not exist.']);
            exit;
        }
        return $m;
    }

    /**
     * A meeting's page with its series and what to prepare. Any staff role; audited as a view. A meeting of a
     * deleted client answers 404 (ClientController::load).
     */
    public static function show(int $id): void
    {
        Auth::require();
        $m = self::load($id);
        $client = $m['client_id'] ? ClientController::load((int) $m['client_id']) : null;
        Audit::access('meeting', "#$id {$m['title']}" . ($client ? " ({$client['name']})" : ''));
        View::render('meetings/show', [
            'title' => $m['title'],
            'nav' => $client ? 'clients' : 'meetings',
            'client' => $client,
            'clientNav' => 'meetings',
            'm' => $m,
            'clients' => DB::all('SELECT id, name FROM clients WHERE (is_archived = 0 AND planning_excluded = 0) OR id = ? ORDER BY name', [(int) $m['client_id']]),
            'users' => ClientController::users(),
            'series' => $m['series_id'] ? DB::all("SELECT id, starts_at, status FROM meetings WHERE series_id = ? ORDER BY starts_at", [$m['series_id']]) : [],
            'prep' => $client ? self::prep((int) $client['id'], $client) : null,
        ]);
    }

    /**
     * Validates the posted form. Returns [fields, error]. Untrusted input: the date must be a real date (checkdate,
     * years 1970-2999) and the time a real time (2.2.1: "2026-13-45" or "25:99" became a meeting in 1970, and
     * "2026-02-30" quietly moved to March 2); attendees up to 4,000 characters, refused rather than cut; agenda cut to
     * what its column holds; the type is one of TYPES; the client must exist; the video link must be
     * http(s); the owner is an active tech or admin (invitations can go out from their mailbox), else the editor.
     */
    private static function fields(): array
    {
        $date = post('date');
        $time = post('time') ?: '09:00';
        $mins = max(15, min(600, (int) (post('duration') ?: Settings::int('meeting_default_minutes', 60))));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $d) || (int) $d[1] < 1970 || (int) $d[1] > 2999
            || !checkdate((int) $d[2], (int) $d[3], (int) $d[1]) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            return [null, 'Pick a date and time.'];
        }
        $start = strtotime("$date $time");
        $title = mb_substr(post('title'), 0, 255);
        $type = isset(Meetings::TYPES[post('type')]) ? post('type') : 'other';
        $clientId = (int) post('client_id') ?: null;
        if ($clientId && !DB::value('SELECT id FROM clients WHERE id = ?', [$clientId])) {
            $clientId = null;
        }
        if ($title === '') {
            $title = Meetings::typeLabel($type);
        }
        $owner = (int) post('owner_id') ?: Auth::id();
        // The organizer is an active staff member who can run meetings (invitations may go out from their mailbox) (1.45)
        if ($owner !== Auth::id() && !DB::value("SELECT 1 FROM users WHERE id = ? AND is_active = 1 AND role IN ('tech','admin')", [$owner])) {
            $owner = Auth::id();
        }
        $url = post('video_url');
        // Never cut the attendee list: a cut address can still look valid (bob@example.co) and the invitation would
        // go to someone else (2.2.1; the API refuses a longer list the same way)
        if (mb_strlen(post('attendees')) > 4000) {
            return [null, 'The attendee list is too long (4,000 characters at most).'];
        }
        return [[
            'client_id' => $clientId,
            'title' => $title,
            'type' => $type,
            'starts_at' => date('Y-m-d H:i:s', $start),
            'ends_at' => date('Y-m-d H:i:s', $start + $mins * 60),
            'location' => mb_substr(post('location'), 0, 255) ?: null,
            'video_url' => preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 500) : null,
            'attendees' => post('attendees') ?: null,
            'agenda' => self::text(post('agenda'), 20000),
            'owner_id' => $owner,
        ], null];
    }

    /**
     * $s cut to $chars characters and to the 65,535 bytes a TEXT column holds (whole characters only), or null when
     * empty. 20,000 characters of emoji are 80,000 bytes: strict MariaDB refused them with a 500 (2.2.1).
     */
    private static function text(string $s, int $chars): ?string
    {
        return mb_strcut(mb_substr($s, 0, $chars), 0, 65535, 'UTF-8') ?: null;
    }

    /** Where to go after a form: the posted return path if it is a same-site path, else the meetings list. */
    private static function back(?int $clientId): string
    {
        $r = post('return');
        return \Align\Security::safePath($r, ($clientId ? "/clients/$clientId/meetings" : '/meetings'));
    }

    /**
     * Schedules a meeting, or a series of 2-24 (tech). A series repeats every 1, 3, 6 or 12 months from the first
     * date; a day the month doesn't have becomes its last day (2.2.1: monthly from January 31 gave March 3, then
     * March 31). Invitations go out for each meeting only when send_invites is ticked.
     */
    public static function create(): void
    {
        Auth::requireRole('tech');
        [$f, $err] = self::fields();
        if ($err) {
            flash('error', $err);
            redirect(self::back((int) post('client_id') ?: null));
        }
        $months = Meetings::REPEATS[post('repeat')] ?? 0;
        $count = $months ? max(2, min(24, (int) post('repeat_count') ?: 4)) : 1;
        $ids = DB::transaction(function () use ($f, $months, $count) {
            $ids = [];
            $seriesId = null;
            $start = strtotime($f['starts_at']);
            $len = strtotime($f['ends_at']) - $start;
            for ($i = 0; $i < $count; $i++) {
                $s = Meetings::addMonths($start, $i * $months);
                $id = DB::insert('meetings', array_merge($f, [
                    'uid' => Meetings::newUid(),
                    'series_id' => $seriesId,
                    'starts_at' => date('Y-m-d H:i:s', $s),
                    'ends_at' => date('Y-m-d H:i:s', $s + $len),
                    'created_by' => Auth::id(),
                ]));
                if ($i === 0 && $count > 1) {
                    $seriesId = $id;
                    DB::run('UPDATE meetings SET series_id = ? WHERE id = ?', [$id, $id]);
                }
                $ids[] = $id;
            }
            return $ids;
        });
        Audit::log('meeting.create', $f['title'] . ($count > 1 ? " (series of $count)" : ''));
        $sent = [];
        if (post('send_invites') === '1') {
            foreach ($ids as $mid) {
                if ($r = \Align\Mail\Invites::send((int) $mid)) {
                    $sent[] = $r;
                }
            }
        }
        flash(str_contains(implode(' ', $sent), 'not sent') ? 'warning' : 'success', ($count > 1 ? "Scheduled $count meetings." : 'Meeting scheduled.') . ($sent ? ' ' . $sent[0] . ($count > 1 && count($sent) > 1 ? " (for each of the $count meetings)" : '') : ''));
        redirect(post('return') ? self::back($f['client_id']) : '/meetings/' . $ids[0]);
    }

    /**
     * Edits a meeting (tech): action complete | cancel | reopen | notes, or save (the edit form). A status change
     * happens only from the state the page offers it in: complete and cancel a scheduled meeting, reopen one that
     * isn't. The state is checked in the UPDATE itself, so a double submit or a second tab changes it once, and
     * attendees get one cancellation, not one per click; cancelling a completed meeting no longer emails them
     * (2.2.1). Reopening sends the invitation again only to undo a cancellation that went out; reopening a
     * completed meeting emails nobody.
     */
    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $m = self::load($id);
        $action = post('action', 'save');
        if (in_array($action, ['complete', 'cancel', 'reopen'], true)) {
            $status = ['complete' => 'completed', 'cancel' => 'cancelled', 'reopen' => 'scheduled'][$action];
            $changed = $action === 'reopen'
                ? DB::run("UPDATE meetings SET status = 'scheduled' WHERE id = ? AND status <> 'scheduled'", [$id])->rowCount()
                : DB::run("UPDATE meetings SET status = ? WHERE id = ? AND status = 'scheduled'", [$status, $id])->rowCount();
            if (!$changed) {
                flash('info', 'Nothing changed: the meeting is ' . DB::value('SELECT status FROM meetings WHERE id = ?', [$id]) . '.');
                redirect("/meetings/$id");
            }
            Audit::log("meeting.$action", $m['title']);
            if ($action === 'complete' && in_array($m['type'], \Align\Changes\Changes::REVIEW_TYPES, true) && $m['client_id']) {
                \Align\Alignment\Snapshot::take((int) $m['client_id'], $id); // 2.3.0, every kind of business review from 2.4.0: "what changed since the last review" compares with it
            }
            $inv = $action === 'cancel' ? \Align\Mail\Invites::send($id, 'cancel')
                : ($action === 'reopen' && $m['invites_sent_at'] && $m['status'] === 'cancelled' ? \Align\Mail\Invites::send($id) : null);
            flash($inv && str_contains($inv, 'not sent') ? 'warning' : 'success', 'Meeting marked ' . $status . '.' . ($inv ? ' ' . $inv : ''));
            redirect("/meetings/$id");
        }
        if ($action === 'notes') {
            DB::run('UPDATE meetings SET notes = ? WHERE id = ?', [self::text(post('notes'), 50000), $id]);
            Audit::log('meeting.notes', $m['title']);
            flash('success', 'Notes saved.');
            redirect("/meetings/$id");
        }
        [$f, $err] = self::fields();
        if ($err) {
            flash('error', $err);
            redirect("/meetings/$id");
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        DB::run("UPDATE meetings SET $sets WHERE id = ?", [...array_values($f), $id]);
        Audit::log('meeting.update', $f['title']);
        $inv = post('send_invites') === '1' && $m['status'] === 'scheduled' ? \Align\Mail\Invites::send($id) : null;
        flash($inv && str_contains($inv, 'not sent') ? 'warning' : 'success', 'Meeting updated.' . ($inv ? ' ' . $inv : ''));
        redirect("/meetings/$id");
    }

    /**
     * Deletes a meeting, or it and the later meetings of its series that aren't completed (tech). Attendees of
     * scheduled meetings whose invitations went out get a cancellation first.
     */
    public static function delete(int $id): void
    {
        Auth::requireRole('tech');
        $m = self::load($id);
        // Tell attendees before the meeting disappears
        $targets = post('scope') === 'future' && $m['series_id']
            ? array_column(DB::all("SELECT id FROM meetings WHERE series_id = ? AND starts_at >= ? AND status = 'scheduled' AND invites_sent_at IS NOT NULL", [$m['series_id'], $m['starts_at']]), 'id')
            : ($m['invites_sent_at'] && $m['status'] === 'scheduled' ? [$id] : []);
        foreach ($targets as $tid) {
            \Align\Mail\Invites::send((int) $tid, 'cancel');
        }
        if (post('scope') === 'future' && $m['series_id']) {
            $n = DB::run("DELETE FROM meetings WHERE series_id = ? AND starts_at >= ? AND status <> 'completed'", [$m['series_id'], $m['starts_at']])->rowCount();
        } else {
            $n = DB::run('DELETE FROM meetings WHERE id = ?', [$id])->rowCount();
        }
        Audit::log('meeting.delete', $m['title'] . " ($n)");
        flash('success', $n > 1 ? "Deleted $n meetings." : 'Meeting deleted.');
        redirect($m['client_id'] ? "/clients/{$m['client_id']}/meetings" : '/meetings');
    }

    /**
     * The meeting as an .ics download (any staff role, who can read the meeting page anyway; audited). The file
     * name keeps only letters and digits, so nothing from the title reaches the header.
     */
    public static function ics(int $id): void
    {
        Auth::require();
        $m = self::load($id);
        Audit::log('meeting.export_ics', $m['title']);
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', ($m['client_name'] ? $m['client_name'] . '-' : '') . $m['title']);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . trim($name, '-') . '.ics"');
        echo Ics::calendar([$m], $m['title']);
    }

    // ---- Calendar ----------------------------------------------------------

    /** The calendar page, and the feed link once right after it was made. Any staff role. */
    public static function calendar(): void
    {
        $u = Auth::require();
        View::render('meetings/calendar', [
            'title' => 'Calendar',
            'nav' => 'meetings',
            'calendar' => true,
            'clients' => DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'),
            'users' => ClientController::users(),
            'feedUrl' => self::freshFeedUrl(),
            'feedOn' => !empty($u['ics_token']),
        ]);
    }

    /** What to bring to a client meeting: reports, readiness and talking points. $client is a loaded row. */
    private static function prep(int $clientId, array $client): array
    {
        $proposed = DB::all("SELECT title, cost FROM roadmap_items WHERE client_id = ? AND status = 'proposed' ORDER BY target_quarter IS NULL, target_quarter LIMIT 5", [$clientId]);
        $gaps = (int) DB::value("SELECT COUNT(*) FROM client_control_status s JOIN client_frameworks cf ON cf.client_id = s.client_id
            JOIN compliance_controls c ON c.id = s.control_id AND c.framework_id = cf.framework_id WHERE s.client_id = ? AND s.status IN ('not_met','partial')", [$clientId]);
        return [
            'readiness' => \Align\Workflow\Readiness::client($client),
            'dates' => array_values(array_filter(\Align\Budget\Contracts::upcoming($clientId, 120), fn($d) => $d['urgency'] !== 'later')),
            'proposed' => $proposed,
            'gaps' => $gaps,
            'sla' => \Align\Service\Sla::overview($clientId),
        ];
    }

    /**
     * Calendar events as JSON for FullCalendar (any staff role): meetings overlapping start..end, and contract dates
     * as all-day events. Titles are plain text (FullCalendar sets them as text, not HTML); links are same-site paths.
     */
    public static function events(): void
    {
        Auth::require();
        $start = strtotime(query('start')) ?: strtotime('-1 month');
        $end = strtotime(query('end')) ?: strtotime('+2 months');
        $params = [date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end)];
        $sql = self::SELECT . ' WHERE m.starts_at < ? AND m.ends_at > ?';
        $params = [$params[1], $params[0]];
        if ($cid = (int) query('client')) {
            $sql .= ' AND m.client_id = ?';
            $params[] = $cid;
        }
        $out = [];
        foreach (DB::all($sql, $params) as $m) {
            $color = Meetings::COLORS[Meetings::typeColor($m['type'])];
            $out[] = [
                'id' => (string) $m['id'],
                'title' => ($m['client_name'] ? $m['client_name'] . ': ' : '') . $m['title'],
                'start' => date('c', strtotime($m['starts_at'])),
                'end' => date('c', strtotime($m['ends_at'])),
                'url' => '/meetings/' . $m['id'],
                'backgroundColor' => $m['status'] === 'cancelled' ? '#adb5bd' : $color,
                'borderColor' => $m['status'] === 'cancelled' ? '#adb5bd' : $color,
                'classNames' => ['status-' . $m['status']],
            ];
        }
        // Contract dates as all-day events
        if (query('contracts', '1') === '1') {
            $colors = ['renegotiate' => '#eb6834', 'contract_end' => '#e34948', 'expires' => '#6c757d'];
            $from = date('Y-m-d', $start);
            $days = (int) ceil(($end - strtotime('today')) / 86400);
            foreach (\Align\Budget\Contracts::upcoming($cid ?: null, max(1, $days), $from) as $d) {
                if ($d['date'] > date('Y-m-d', $end)) {
                    continue;
                }
                $out[] = [
                    'id' => 'c-' . md5($d['kind'] . $d['name'] . $d['date'] . $d['client_id']),
                    'title' => ($cid ? '' : $d['client_name'] . ': ') . $d['label'] . ' – ' . $d['name'],
                    'start' => $d['date'], 'allDay' => true, 'url' => $d['link'],
                    'backgroundColor' => $colors[$d['kind']], 'borderColor' => $colors[$d['kind']],
                    'classNames' => ['contract-event'],
                ];
            }
        }
        header('Content-Type: application/json');
        echo json_encode($out);
    }

    /** The feed link, shown once right after it's created (only a hash of the token is stored). */
    public static function freshFeedUrl(): ?string
    {
        $url = $_SESSION['new_feed_url'] ?? null;
        unset($_SESSION['new_feed_url']);
        return $url;
    }

    /**
     * Makes a new calendar feed link for the signed-in user, or turns it off (tech and admin only, as the feed).
     * 256 random bits; only the SHA-256 is stored, and the link is shown once. A new link replaces the old one.
     */
    public static function feedToken(): void
    {
        $u = Auth::requireRole('tech');
        $action = post('action');
        if ($action === 'revoke') {
            DB::run('UPDATE users SET ics_token = NULL WHERE id = ?', [$u['id']]);
            Audit::log('calendar.feed_revoked');
            flash('success', 'Calendar feed link turned off.');
        } else {
            $token = bin2hex(random_bytes(32));
            DB::run('UPDATE users SET ics_token = ?, ics_created_at = NOW() WHERE id = ?', [hash('sha256', $token), $u['id']]);
            $_SESSION['new_feed_url'] = \Align\Portal\PortalAuth::baseUrl() . '/ics/' . $token . '.ics';
            Audit::log('calendar.feed_created');
            flash('success', 'New calendar feed link created. Any old link stops working.');
        }
        redirect(post('return') === '/calendar' ? '/calendar' : '/account');
    }

    /**
     * Public, token-protected ICS feed for Outlook / Google / Apple calendar subscriptions. No session: the token
     * (hex, 192 bits for links made before 1.45, else 256) is looked up by its SHA-256, so the comparison leaks
     * nothing useful; the user must be active and tech or admin. Only titles, client names and times go out
     * (Ics::calendar minimal), from 90 days back to 18 months ahead. Unknown tokens get a plain 404.
     */
    public static function feed(string $token): void
    {
        $token = preg_replace('/\.ics$/', '', $token);
        $u = preg_match('/^[a-f0-9]{48,64}$/', $token) ? DB::one("SELECT * FROM users WHERE ics_token = ? AND is_active = 1 AND role IN ('tech','admin')", [hash('sha256', $token)]) : null;
        if (!$u) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Not found';
            return;
        }
        $rows = DB::all(self::SELECT . " WHERE m.starts_at >= ? AND m.starts_at <= ? ORDER BY m.starts_at", [
            date('Y-m-d H:i:s', strtotime('-90 days')),
            date('Y-m-d H:i:s', strtotime('+18 months')),
        ]);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Cache-Control: private, max-age=900');
        // Calendar providers store feeds, so the feed carries only titles and times: no agendas or attendees
        echo Ics::calendar($rows, \Align\Branding::name() . ' meetings', true);
    }
}
