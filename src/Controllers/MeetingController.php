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

final class MeetingController
{
    private const SELECT = 'SELECT m.*, c.name AS client_name, u.name AS owner_name
        FROM meetings m LEFT JOIN clients c ON c.id = m.client_id LEFT JOIN users u ON u.id = m.owner_id';

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

    public static function show(int $id): void
    {
        Auth::require();
        $m = self::load($id);
        $client = $m['client_id'] ? ClientController::load((int) $m['client_id']) : null;
        View::render('meetings/show', [
            'title' => $m['title'],
            'nav' => $client ? 'clients' : 'meetings',
            'client' => $client,
            'clientNav' => 'meetings',
            'm' => $m,
            'clients' => DB::all('SELECT id, name FROM clients WHERE (is_archived = 0 AND planning_excluded = 0) OR id = ? ORDER BY name', [(int) $m['client_id']]),
            'users' => ClientController::users(),
            'series' => $m['series_id'] ? DB::all("SELECT id, starts_at, status FROM meetings WHERE series_id = ? ORDER BY starts_at", [$m['series_id']]) : [],
        ]);
    }

    /** Validates the posted form. Returns [fields, error]. */
    private static function fields(): array
    {
        $date = post('date');
        $time = post('time') ?: '09:00';
        $mins = max(15, min(600, (int) (post('duration') ?: Settings::int('meeting_default_minutes', 60))));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
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
        $url = post('video_url');
        return [[
            'client_id' => $clientId,
            'title' => $title,
            'type' => $type,
            'starts_at' => date('Y-m-d H:i:s', $start),
            'ends_at' => date('Y-m-d H:i:s', $start + $mins * 60),
            'location' => mb_substr(post('location'), 0, 255) ?: null,
            'video_url' => preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 500) : null,
            'attendees' => mb_substr(post('attendees'), 0, 4000) ?: null,
            'agenda' => mb_substr(post('agenda'), 0, 20000) ?: null,
            'owner_id' => $owner,
        ], null];
    }

    private static function back(?int $clientId): string
    {
        $r = post('return');
        return (str_starts_with($r, '/') && !str_starts_with($r, '//')) ? $r : ($clientId ? "/clients/$clientId/meetings" : '/meetings');
    }

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
                $s = strtotime('+' . ($i * $months) . ' months', $start);
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
        flash('success', $count > 1 ? "Scheduled $count meetings." : 'Meeting scheduled.');
        redirect(post('return') ? self::back($f['client_id']) : '/meetings/' . $ids[0]);
    }

    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $m = self::load($id);
        $action = post('action', 'save');
        if (in_array($action, ['complete', 'cancel', 'reopen'], true)) {
            $status = ['complete' => 'completed', 'cancel' => 'cancelled', 'reopen' => 'scheduled'][$action];
            DB::run('UPDATE meetings SET status = ? WHERE id = ?', [$status, $id]);
            Audit::log("meeting.$action", $m['title']);
            flash('success', 'Meeting marked ' . $status . '.');
            redirect("/meetings/$id");
        }
        if ($action === 'notes') {
            DB::run('UPDATE meetings SET notes = ? WHERE id = ?', [mb_substr(post('notes'), 0, 50000) ?: null, $id]);
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
        flash('success', 'Meeting updated.');
        redirect("/meetings/$id");
    }

    public static function delete(int $id): void
    {
        Auth::requireRole('tech');
        $m = self::load($id);
        if (post('scope') === 'future' && $m['series_id']) {
            $n = DB::run("DELETE FROM meetings WHERE series_id = ? AND starts_at >= ? AND status <> 'completed'", [$m['series_id'], $m['starts_at']])->rowCount();
        } else {
            $n = DB::run('DELETE FROM meetings WHERE id = ?', [$id])->rowCount();
        }
        Audit::log('meeting.delete', $m['title'] . " ($n)");
        flash('success', $n > 1 ? "Deleted $n meetings." : 'Meeting deleted.');
        redirect($m['client_id'] ? "/clients/{$m['client_id']}/meetings" : '/meetings');
    }

    public static function ics(int $id): void
    {
        Auth::require();
        $m = self::load($id);
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', ($m['client_name'] ? $m['client_name'] . '-' : '') . $m['title']);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . trim($name, '-') . '.ics"');
        echo Ics::calendar([$m], $m['title']);
    }

    // ---- Calendar ----------------------------------------------------------

    public static function calendar(): void
    {
        $u = Auth::require();
        View::render('meetings/calendar', [
            'title' => 'Calendar',
            'nav' => 'calendar',
            'calendar' => true,
            'clients' => DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'),
            'users' => ClientController::users(),
            'feedUrl' => self::feedUrl($u),
        ]);
    }

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
        header('Content-Type: application/json');
        echo json_encode($out);
    }

    public static function feedUrl(array $u): ?string
    {
        if (empty($u['ics_token'])) {
            return null;
        }
        $base = rtrim((string) \Align\Config::get('base_url', ''), '/');
        return ($base ?: (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '/ics/' . $u['ics_token'] . '.ics';
    }

    public static function feedToken(): void
    {
        $u = Auth::require();
        $action = post('action');
        if ($action === 'revoke') {
            DB::run('UPDATE users SET ics_token = NULL WHERE id = ?', [$u['id']]);
            Audit::log('calendar.feed_revoked');
            flash('success', 'Calendar feed link turned off.');
        } else {
            DB::run('UPDATE users SET ics_token = ? WHERE id = ?', [bin2hex(random_bytes(24)), $u['id']]);
            Audit::log('calendar.feed_created');
            flash('success', 'New calendar feed link created. Any old link stops working.');
        }
        redirect(post('return') === '/calendar' ? '/calendar' : '/account');
    }

    /** Public, token-protected ICS feed for Outlook / Google / Apple calendar subscriptions. */
    public static function feed(string $token): void
    {
        $token = preg_replace('/\.ics$/', '', $token);
        $u = strlen($token) >= 32 ? DB::one('SELECT * FROM users WHERE ics_token = ? AND is_active = 1', [$token]) : null;
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
        echo Ics::calendar($rows, 'Mountaineer Align meetings');
    }
}
