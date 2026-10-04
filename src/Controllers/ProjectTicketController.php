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
 * and "Not yet" (hide from To do for 1, 2, 3 or 6 months). The rules live in Roadmap\ProjectTickets.
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
            'devices' => $devices,
            'note' => ProjectTickets::note($it, (bool) $devices),
            'back' => Security::safePath(query('back'), '/todo'),
        ]);
    }

    /** Ready to start: makes the project's ticket, then back where it came from with what happened. */
    public static function start(int $id): void
    {
        Auth::requireRole('tech');
        $back = Security::safePath(post('back'), '/todo');
        $r = ProjectTickets::start($id, Auth::id());
        flash($r['ok'] ? 'success' : 'error', $r['ok']
            ? 'Started "' . $r['title'] . '": ' . psa_name() . ' ticket #' . $r['ticket'] . ' made.'
            : ($r['title'] !== '' ? '"' . $r['title'] . '": ' : '') . $r['error']);
        redirect($back);
    }

    /** Projects one "Ready to start: all" makes at most (each is a call to the PSA); the rest wait for the next press. */
    private const ALL_MAX = 20;

    /**
     * Ready to start for every project ticked on To do that is still ready to start, up to ALL_MAX per press. The
     * request keeps going if the browser or a proxy gives up waiting, so no project is left half-made.
     */
    public static function startAll(): void
    {
        Auth::requireRole('tech');
        $back = Security::safePath(post('back'), '/todo');
        $want = array_flip(array_map('intval', (array) ($_POST['ids'] ?? [])));
        if (!$want) {
            flash('warning', 'Tick at least one project.');
            redirect($back);
        }
        if (!ProjectTickets::enabled()) {
            flash('error', 'Tickets can\'t be made here: ' . (psa_on() ? psa_name() . ' isn\'t set up to create them.' : 'no PSA is connected.'));
            redirect($back);
        }
        // Each ticket is a call to the PSA: finish the batch even if the browser stops waiting, so no project is
        // left with a ticket made but not saved
        ignore_user_abort(true);
        @set_time_limit(0);
        $made = [];
        $failed = [];
        $left = 0;
        // Walk what is ready to start NOW, not the posted ids: one started, snoozed or moved since the page was
        // opened is skipped instead of getting a second ticket
        foreach (ProjectTickets::due() as $it) {
            if (!isset($want[(int) $it['id']])) {
                continue;
            }
            if (count($made) + count($failed) >= self::ALL_MAX) {
                $left++;
                continue;
            }
            $r = ProjectTickets::start((int) $it['id'], Auth::id());
            if ($r['ok']) {
                $made[] = '#' . $r['ticket'];
            } else {
                $failed[] = $r['title'] . ' (' . $r['reason'] . ')';
            }
        }
        if (!$made && !$failed) {
            flash('warning', 'Nothing was started: those projects changed meanwhile. The list is up to date now.');
        } else {
            flash($failed ? 'warning' : 'success', trim(($made ? 'Made ' . count($made) . ' ' . psa_name() . ' ticket' . (count($made) === 1 ? '' : 's') . ': ' . implode(', ', $made) . '.' : '')
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
        if (!$it || !empty($it['psa_ticket_id'])) {
            flash('error', 'That project has a ticket already or no longer exists.');
            redirect($back);
        }
        $until = ProjectTickets::snooze($it, (int) post('months'));
        flash('success', '"' . $it['title'] . '" is back on To do ' . fmt_date($until) . '.');
        redirect($back);
    }
}
