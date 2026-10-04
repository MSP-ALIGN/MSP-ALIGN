<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\Roadmap\Plan;
use Align\Roadmap\ProjectTickets;
use Align\Roadmap\Roadmap;
use Align\View;

/**
 * All planned projects across clients, grouped by plan quarter.
 *
 * Security assumptions: read only; any staff role (adding and editing go to RoadmapController, techs and admins; the
 * Ready to start button, techs and admins only, to ProjectTicketController).
 * Every filter from the query string is checked against a fixed list or cast to int and bound; the ORDER BY is fixed.
 */
final class ProjectController
{
    /** The status tabs: ?status= value => label ('open' is proposed, approved and scheduled). */
    public const VIEWS = [
        'open' => 'Open',
        'proposed' => 'Proposed',
        'approved' => 'Approved',
        'scheduled' => 'Scheduled',
        'done' => 'Done',
        'declined' => 'Declined',
        'all' => 'All',
    ];

    /** The Ticket filter (2.2.2): ?ticket= value => label. */
    public const TICKET_VIEWS = ['ready' => 'Ready to start', 'waiting' => 'Not yet', 'has' => 'Has a ticket'];

    /**
     * The Projects page: totals per plan year and per quarter (declined projects left out of the money), overdue
     * open projects counted in the current quarter, then the ones beyond the plan and the unscheduled.
     */
    public static function index(): void
    {
        Auth::require();
        $status = isset(self::VIEWS[query('status')]) ? query('status') : 'open';
        $clientId = (int) query('client');
        $category = isset(Roadmap::CATEGORIES[query('category')]) ? query('category') : '';
        $year = query('year') !== '' && ctype_digit(query('year')) && (int) query('year') < 3 ? (int) query('year') : null;

        $where = ['1=1'];
        $params = [];
        if ($clientId) {
            $where[] = 'r.client_id = ?';
            $params[] = $clientId;
        } else {
            $where[] = 'c.is_archived = 0 AND c.planning_excluded = 0';
        }
        if ($status === 'open') {
            $where[] = "r.status IN ('proposed','approved','scheduled')";
        } elseif ($status !== 'all') {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }
        if ($category) {
            $where[] = 'r.category = ?';
            $params[] = $category;
        }
        $items = DB::all('SELECT r.*, c.name AS client_name, c.psa_id AS client_psa_id FROM roadmap_items r JOIN clients c ON c.id = r.client_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY r.target_quarter IS NULL, r.target_quarter, c.name, r.title', $params);
        $search = \Align\Paging::q();
        $items = \Align\Paging::search($items, $search, ['title', 'description', 'client_name', 'category']);
        // 2.2.2: where each project stands with its QUOTE- ticket, and the Ticket filter
        $ticketsOn = ProjectTickets::enabled();
        $ticket = $ticketsOn && isset(self::TICKET_VIEWS[query('ticket')]) ? query('ticket') : '';
        foreach ($items as &$it) {
            $it['ticket_state'] = ProjectTickets::state($it, (string) $it['client_psa_id']);
        }
        unset($it);
        // Each filter covers the states it means: Not yet is everything startable but not due yet (a later quarter,
        // snoozed, not approved, no quarter)
        if ($ticket !== '') {
            $keys = ['ready' => ['due'], 'waiting' => ['later', 'snoozed', 'proposed', 'unscheduled'], 'has' => ['ticket']][$ticket];
            $items = array_values(array_filter($items, fn($it) => in_array($it['ticket_state']['key'], $keys, true)));
        }

        $quarters = [];
        foreach (Plan::quarters() as $q) {
            $quarters[$q['index']] = $q + ['items' => [], 'cost' => 0.0, 'recurring' => 0.0];
        }
        $curStart = $quarters[Plan::currentIndex()]['start'];
        $unscheduled = [];
        $beyond = [];
        foreach ($items as $it) {
            $i = $it['target_quarter'] ? Plan::indexFor($it['target_quarter'], $it['status'] !== 'done') : null;
            if ($i === null) {
                if (!$it['target_quarter']) {
                    $unscheduled[] = $it;
                } elseif ($it['target_quarter'] > $quarters[count($quarters) - 1]['end']) {
                    $beyond[] = $it;
                }
                continue;
            }
            $it['overdue'] = $it['target_quarter'] < $curStart && !in_array($it['status'], ['done', 'declined'], true);
            $quarters[$i]['items'][] = $it;
            if ($it['status'] !== 'declined') {
                $quarters[$i]['cost'] += (float) $it['cost'];
                $quarters[$i]['recurring'] += (float) $it['recurring_monthly'];
            }
        }
        $years = Plan::years();
        foreach ($years as $y => &$yr) {
            $yr['cost'] = 0.0;
            $yr['count'] = 0;
            foreach ($quarters as $q) {
                if ($q['year'] === $y) {
                    $yr['cost'] += $q['cost'];
                    $yr['count'] += count(array_filter($q['items'], fn($i) => $i['status'] !== 'declined'));
                }
            }
        }
        unset($yr);
        if ($year !== null) {
            $quarters = array_filter($quarters, fn($q) => $q['year'] === $year);
        }

        View::render('projects/index', [
            'title' => 'Projects',
            'nav' => 'projects',
            'quarters' => $quarters,
            'unscheduled' => $year === null ? $unscheduled : [],
            'beyond' => $year === null ? $beyond : [],
            'years' => $years,
            // What the page lists (a year picked leaves out the other years and the unscheduled)
            'count' => array_sum(array_map(fn($qt) => count($qt['items']), $year === null ? $quarters : array_filter($quarters, fn($qt) => $qt['year'] === $year)))
                + ($year === null ? count($unscheduled) + count($beyond) : 0),
            'q' => $search,
            'limit' => \Align\Paging::limit(),
            'status' => $status,
            'clientId' => $clientId,
            'category' => $category,
            'year' => $year,
            'ticketsOn' => $ticketsOn,
            'ticket' => $ticket,
            'clients' => array_column(DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'), 'name', 'id'),
        ]);
    }
}
