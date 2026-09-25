<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\Roadmap\Plan;
use Align\Roadmap\Roadmap;
use Align\View;

/** All planned projects across clients, grouped by plan quarter. */
final class ProjectController
{
    public const VIEWS = [
        'open' => 'Open',
        'proposed' => 'Proposed',
        'approved' => 'Approved',
        'scheduled' => 'Scheduled',
        'done' => 'Done',
        'declined' => 'Declined',
        'all' => 'All',
    ];

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
        $items = DB::all('SELECT r.*, c.name AS client_name FROM roadmap_items r JOIN clients c ON c.id = r.client_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY r.target_quarter IS NULL, r.target_quarter, c.name, r.title', $params);

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
            'count' => count($items),
            'status' => $status,
            'clientId' => $clientId,
            'category' => $category,
            'year' => $year,
            'clients' => array_column(DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'), 'name', 'id'),
        ]);
    }
}
