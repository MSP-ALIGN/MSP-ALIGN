<?php
declare(strict_types=1);

namespace Align\Roadmap;

use Align\DB;
use Align\Lifecycle\Lifecycle;

/**
 * Builds a client's 3-year roadmap: planned items plus everything the data says
 * will happen (hardware reaching end of life, OS support ending, warranties
 * expiring, meetings, compliance remediation due dates).
 *
 * Security assumptions: read only. Callers check the staff role and pass a client id that exists; every query binds
 * it. Totals leave declined projects out, and a device a project replaces counts only through the project
 * (Lifecycle::evaluate leaves it out of replace_by), so no cost is counted twice.
 */
final class Roadmap
{
    /** Project categories: key => [label, Font Awesome icon, Bootstrap tone]. */
    public const CATEGORIES = [
        'hardware' => ['Hardware', 'fa-desktop', 'primary'],
        'infrastructure' => ['Infrastructure / network', 'fa-network-wired', 'info'],
        'security' => ['Security', 'fa-shield-halved', 'danger'],
        'compliance' => ['Compliance', 'fa-clipboard-check', 'warning'],
        'software' => ['Software / licensing', 'fa-cubes', 'purple'],
        'cloud' => ['Cloud / M365', 'fa-cloud', 'teal'],
        'backup' => ['Backup & DR', 'fa-database', 'success'],
        'project' => ['Project', 'fa-diagram-project', 'secondary'],
        'training' => ['Training / people', 'fa-user-graduate', 'dark'],
    ];

    /** Project statuses: key => [label, Bootstrap tone]. */
    public const STATUSES = [
        'proposed' => ['Proposed', 'light'],
        'approved' => ['Approved', 'primary'],
        'scheduled' => ['Scheduled', 'info'],
        'done' => ['Done', 'success'],
        'declined' => ['Declined', 'secondary'],
    ];

    /** Project priorities, most urgent first: key => [label, Bootstrap tone]. */
    public const PRIORITIES = [
        'critical' => ['Critical', 'danger'],
        'high' => ['High', 'warning'],
        'medium' => ['Medium', 'info'],
        'low' => ['Low', 'light'],
    ];

    /** Lanes shown on the roadmap (auto lanes can be toggled off). */
    public const LANES = [
        'items' => 'Planned items',
        'hardware' => 'Hardware replacements',
        'os' => 'OS end of support',
        'warranty' => 'Warranties expiring',
        'meetings' => 'Meetings',
        'compliance' => 'Compliance due dates',
    ];

    /**
     * The roadmap's quarters with their lanes and money, the plan years' totals and the backlog (unscheduled
     * projects, and open ones beyond the plan). Overdue open items roll into the current quarter.
     * @param array $devices evaluated devices for the client (Lifecycle::devices)
     * @return array{quarters:array,years:array,backlog:array,totals:array}
     */
    public static function build(int $clientId, array $devices): array
    {
        $quarters = [];
        foreach (Plan::quarters() as $q) {
            $quarters[$q['index']] = $q + [
                'items' => [], 'hardware' => [], 'os' => [], 'warranty' => [], 'meetings' => [], 'compliance' => [],
                'hw_cost' => 0.0, 'item_cost' => 0.0, 'recurring' => 0.0,
            ];
        }
        $curStart = $quarters[Plan::currentIndex()]['start'];

        // Hardware replacements, OS end of support, warranty expirations
        $osGroups = [];
        foreach ($devices as $d) {
            if ($d['status'] === 'excluded') {
                continue;
            }
            if ($d['replace_by'] !== null && ($i = Plan::indexFor($d['replace_by'])) !== null) {
                $quarters[$i]['hardware'][] = $d + ['overdue' => $d['replace_by'] < $curStart];
                $quarters[$i]['hw_cost'] += $d['replacement_cost'];
            }
            if ($d['os_rule'] && ($i = Plan::indexFor($d['os_rule']['eos_date'])) !== null) {
                $key = $i . '|' . $d['os_rule']['label'];
                $osGroups[$key] ??= ['index' => $i, 'label' => $d['os_rule']['label'], 'date' => $d['os_rule']['eos_date'], 'devices' => []];
                $osGroups[$key]['devices'][] = $d['name'];
            }
            if ($d['is_hardware'] && $d['warranty_end'] && ($i = Plan::indexFor($d['warranty_end'], false)) !== null) {
                $quarters[$i]['warranty'][] = $d;
            }
        }
        foreach ($osGroups as $g) {
            $g['overdue'] = $g['date'] < $curStart;
            $quarters[$g['index']]['os'][] = $g;
        }
        foreach ($quarters as &$q) {
            usort($q['hardware'], fn($a, $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);
        }
        unset($q);

        // Meetings
        $last = end($quarters);
        foreach (DB::all("SELECT id, title, type, status, starts_at FROM meetings WHERE client_id = ? AND status <> 'cancelled'
                AND starts_at >= ? AND starts_at <= ? ORDER BY starts_at", [$clientId, $quarters[0]['start'], $last['end'] . ' 23:59:59']) as $m) {
            if (($i = Plan::indexFor($m['starts_at'], false)) !== null) {
                $quarters[$i]['meetings'][] = $m;
            }
        }

        // Compliance remediation with due dates
        foreach (DB::all("SELECT c.ref, c.title, s.status, s.owner, s.due_date, f.name AS framework, f.id AS framework_id
                FROM client_control_status s JOIN compliance_controls c ON c.id = s.control_id
                JOIN compliance_frameworks f ON f.id = c.framework_id
                JOIN client_frameworks cf ON cf.client_id = s.client_id AND cf.framework_id = f.id
                WHERE s.client_id = ? AND s.due_date IS NOT NULL AND s.status IN ('not_met','partial')", [$clientId]) as $c) {
            if (($i = Plan::indexFor($c['due_date'])) !== null) {
                $quarters[$i]['compliance'][] = $c + ['overdue' => $c['due_date'] < $curStart];
            }
        }

        // Planned (custom) items
        $backlog = [];
        $prio = array_flip(array_keys(self::PRIORITIES));
        $items = DB::all('SELECT r.*, u.name AS created_by_name FROM roadmap_items r LEFT JOIN users u ON u.id = r.created_by WHERE r.client_id = ?', [$clientId]);
        usort($items, fn($a, $b) => [$prio[$a['priority']], $a['title']] <=> [$prio[$b['priority']], $b['title']]);
        foreach ($items as $it) {
            $i = $it['target_quarter'] ? Plan::indexFor($it['target_quarter'], $it['status'] !== 'done') : null;
            if ($i === null) {
                if (!$it['target_quarter'] || $it['status'] !== 'done') {
                    $backlog[] = $it;
                }
                continue;
            }
            $quarters[$i]['items'][] = $it + ['overdue' => $it['target_quarter'] < $curStart && $it['status'] !== 'done'];
            if ($it['status'] !== 'declined') {
                $quarters[$i]['item_cost'] += (float) $it['cost'];
                $quarters[$i]['recurring'] += (float) $it['recurring_monthly'];
            }
        }

        $years = Plan::years();
        foreach ($years as $y => &$yr) {
            $yr += ['hw_cost' => 0.0, 'item_cost' => 0.0, 'total' => 0.0, 'hw_count' => 0, 'item_count' => 0, 'recurring' => 0.0];
            foreach ($quarters as $q) {
                if ($q['year'] !== $y) {
                    continue;
                }
                $yr['hw_cost'] += $q['hw_cost'];
                $yr['item_cost'] += $q['item_cost'];
                $yr['hw_count'] += count($q['hardware']);
                $yr['item_count'] += count(array_filter($q['items'], fn($i) => $i['status'] !== 'declined'));
            }
            $yr['total'] = $yr['hw_cost'] + $yr['item_cost'];
        }
        unset($yr);
        // Recurring monthly costs: running total of approved/scheduled/done recurring items by the end of each year
        foreach ($years as $y => &$yr) {
            foreach ($items as $it) {
                if (in_array($it['status'], ['approved', 'scheduled', 'done'], true) && $it['recurring_monthly']
                    && (!$it['target_quarter'] || $it['target_quarter'] <= $yr['to'])) {
                    $yr['recurring'] += (float) $it['recurring_monthly'];
                }
            }
        }
        unset($yr);

        return [
            'quarters' => $quarters,
            'years' => $years,
            'backlog' => $backlog,
            'grand' => array_sum(array_column($years, 'total')),
        ];
    }

    /**
     * Adds planned projects (roadmap items with a budget) to Lifecycle::forecast() buckets.
     * $clientId null = every client in planning. Declined items are left out; overdue open items
     * roll into the current quarter, the same way the roadmap shows them.
     */
    public static function withProjects(array $forecast, ?int $clientId = null): array
    {
        foreach ($forecast as &$b) {
            $b += ['proj_cost' => 0.0, 'proj_count' => 0];
        }
        unset($b);
        $sql = "SELECT r.target_quarter, r.cost, r.status FROM roadmap_items r JOIN clients c ON c.id = r.client_id
            WHERE r.status <> 'declined' AND r.target_quarter IS NOT NULL";
        $params = [];
        if ($clientId !== null) {
            $sql .= ' AND r.client_id = ?';
            $params[] = $clientId;
        } else {
            $sql .= ' AND c.is_archived = 0 AND c.planning_excluded = 0';
        }
        foreach (DB::all($sql, $params) as $it) {
            $i = Plan::indexFor($it['target_quarter'], $it['status'] !== 'done');
            if ($i === null || !isset($forecast[$i])) {
                continue;
            }
            $forecast[$i]['proj_cost'] += (float) $it['cost'];
            $forecast[$i]['proj_count']++;
        }
        return $forecast;
    }

    /** A category's [label, icon, tone]; an unknown one shows as Other. */
    public static function category(string $c): array
    {
        return self::CATEGORIES[$c] ?? ['Other', 'fa-tag', 'secondary'];
    }
}
