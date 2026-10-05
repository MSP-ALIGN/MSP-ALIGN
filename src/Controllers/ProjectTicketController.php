<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\Roadmap\ProjectTickets;
use Align\Security;
use Align\View;

/**
 * 2.2.2 "Ready to start": the confirm window, making a project's QUOTE- ticket (one project or every one on To do)
 * and "Not yet" (hide from To do for 1, 2, 3 or 6 months). Without a ticket (no PSA, a PSA that can't make them, a client not
 * linked to the PSA) Ready to start marks the project started instead. The rules live in Roadmap\ProjectTickets.
 *
 * Security assumptions: techs and admins only (each action checks first); POSTs are CSRF-checked by the router.
 * Projects are found by id with their own client; ids posted for "all" are only acted on when they are still ready
 * to start (ProjectTickets::due()), so a stale page can't make a ticket for something that changed meanwhile.
 * ?back / back are checked by Security::safePath (same-site paths only).
 */
final class ProjectTicketController
{
    /** The project with its client's name and PSA id, or null. */
    private static function project(int $id): ?array
    {
        return DB::one('SELECT r.*, c.name AS client_name, c.psa_id AS client_psa_id FROM roadmap_items r JOIN clients c ON c.id = r.client_id WHERE r.id = ?', [$id]);
    }

    /** The Ready to start window for one project (loaded when opened), with what the ticket will hold. */
    public static function form(int $id): void
    {
        Auth::requireRole('tech');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $it = self::project($id);
        if (!$it) {
            http_response_code(404);
            echo '<p>Not found.</p>';
            return;
        }
        $devices = ProjectTickets::devices($it);
        echo View::fetch('roadmap/_start', [
            'it' => $it,
            'state' => ProjectTickets::state($it, (string) $it['client_psa_id']),
            'why' => ProjectTickets::noTicketReason((string) $it['client_psa_id']),
            'devices' => $devices,
            'note' => ProjectTickets::note($it, (bool) $devices),
            'back' => Security::safePath(query('back'), '/todo'),
        ]);
    }

    /** Ready to start: makes the project's ticket (or marks it started), then back where it came from with what happened. */
    public static function start(int $id): void
    {
        Auth::requireRole('tech');
        $back = Security::safePath(post('back'), '/todo');
        // mode: what the window offered (ticket or mark); start() refuses if that changed since it was opened
        $r = ProjectTickets::start($id, Auth::id(), null, self::mode(post('mode')));
        flash($r['ok'] ? 'success' : 'error', match (true) {
            !$r['ok'] => ($r['title'] !== '' ? '"' . $r['title'] . '": ' : '') . $r['error'],
            $r['ticket'] !== null && ProjectTickets::isPretend($r['ticket']) => 'Started "' . $r['title'] . '": pretend ticket ' . $r['ticket'] . ' saved (test server, nothing sent to ' . psa_name() . ').',
            $r['ticket'] !== null => 'Started "' . $r['title'] . '": ' . psa_name() . ' ticket #' . $r['ticket'] . ' made.',
            default => 'Started "' . $r['title'] . '". It has left To do.',
        });
        redirect($back);
    }

    /** A posted mode as start()'s $expectTicket: "ticket" true, "mark" false, anything else null (no expectation). */
    private static function mode(mixed $m): ?bool
    {
        return match ($m) { 'ticket' => true, 'mark' => false, default => null };
    }

    /** Projects one "Ready to start: all" makes at most (each is a call to the PSA); the rest wait for the next press. */
    private const ALL_MAX = 20;

    /**
     * Ready to start for every project ticked on To do that is still ready to start, up to ALL_MAX per press: a ticket
     * each, or marked started where no ticket can be made. The request keeps going if the browser or a proxy gives up
     * waiting, so no project is left half-made.
     */
    public static function startAll(): void
    {
        Auth::requireRole('tech');
        $back = Security::safePath(post('back'), '/todo');
        $want = array_flip(array_map('intval', (array) ($_POST['ids'] ?? [])));
        $modes = (array) ($_POST['mode'] ?? []); // project id => ticket|mark, as the window listed it
        if (!$want) {
            flash('warning', 'Tick at least one project.');
            redirect($back);
        }
        // Each ticket is a call to the PSA: finish the batch even if the browser stops waiting, so no project is
        // left with a ticket made but not saved
        ignore_user_abort(true);
        @set_time_limit(0);
        $made = [];
        $started = 0; // marked started (no ticket)
        $failed = [];
        $left = 0;
        // Walk what is ready to start NOW, not the posted ids: one started, snoozed or moved since the page was
        // opened is skipped instead of getting a second ticket
        foreach (ProjectTickets::due() as $it) {
            if (!isset($want[(int) $it['id']])) {
                continue;
            }
            if (count($made) + $started + count($failed) >= self::ALL_MAX) {
                $left++;
                continue;
            }
            $r = ProjectTickets::start((int) $it['id'], Auth::id(), null, self::mode($modes[(int) $it['id']] ?? null));
            if ($r['ok'] && $r['ticket'] === null) {
                $started++;
            } elseif ($r['ok']) {
                $made[] = ProjectTickets::isPretend($r['ticket']) ? $r['ticket'] : '#' . $r['ticket'];
            } else {
                $failed[] = $r['title'] . ' (' . $r['reason'] . ')';
            }
        }
        if (!$made && !$started && !$failed) {
            flash('warning', 'Nothing was started: those projects changed meanwhile. The list is up to date now.');
        } else {
            flash($failed ? 'warning' : 'success', trim(($made ? 'Made ' . count($made) . ' ' . psa_name() . ' ticket' . (count($made) === 1 ? '' : 's') . ': ' . implode(', ', $made) . '.' : '')
                . ($started ? ' Started ' . $started . ' project' . ($started === 1 ? '' : 's') . ' without a ticket.' : '')
                . ($failed ? ' Not made, still on To do: ' . implode('; ', $failed) . '.' : '')
                . ($left ? " $left more to go: press Ready to start: all again." : '')));
        }
        redirect($back);
    }

    /** Not yet: hides a project from To do for the months chosen (1 when the plain button is used). */
    public static function snooze(int $id): void
    {
        Auth::requireRole('tech');
        $back = Security::safePath(post('back'), '/todo');
        $it = self::project($id);
        if (!$it || ProjectTickets::hasTicket($it) || !empty($it['started_at'])) {
            flash('error', 'That project was started already or no longer exists.');
            redirect($back);
        }
        $until = ProjectTickets::snooze($it, (int) post('months'));
        flash('success', '"' . $it['title'] . '" is back on To do ' . fmt_date($until) . '.');
        redirect($back);
    }

    /** Not started: undoes marking a project started (one without a ticket), e.g. ticked by mistake in Ready to start: all. */
    public static function unstart(int $id): void
    {
        Auth::requireRole('tech');
        $back = Security::safePath(post('back'), '/projects');
        $it = self::project($id);
        if (!$it || !ProjectTickets::unstart($it)) {
            flash('error', 'That project has a ticket, wasn\'t marked started, or no longer exists.');
            redirect($back);
        }
        flash('success', '"' . $it['title'] . '" is marked not started. It goes back on To do when its quarter is here.');
        redirect($back);
    }
}
