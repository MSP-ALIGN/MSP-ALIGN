<?php
declare(strict_types=1);

namespace Align\Roadmap;

use Align\Audit;
use Align\DB;
use Align\Lifecycle\Lifecycle;

/**
 * 2.1: turn devices due for replacement into projects, one per device or one for several, each optionally with a
 * "QUOTE-" ticket in the PSA (2.2.2: made by ProjectTickets, now only when asked for; otherwise Ready to start makes it). A project's devices leave the automatic replacement plan while the project isn't
 * declined (Lifecycle::evaluate), so the roadmap and budget count the project's quarter and cost, never both.
 *
 * Security assumptions: callers check the tech role. create() keeps only devices of the client it is given, so ids
 * from a request can't pull in another client's devices, and locks them so two requests can't put one device in two
 * projects. The QUOTE- ticket is made by ProjectTickets::start() after the project is committed (see its notes).
 */
final class DeviceProjects
{
    /**
     * SQL for "this project (alias ri) replaces its devices now": not declined, and a done one only for two quarters
     * after its own quarter (by then the old devices are usually retired in the RMM; one still listed is planned again).
     * Used everywhere a project's devices are checked, so they always agree. @return array{0:string,1:list<string>}
     */
    public static function liveSql(string $alias = 'ri'): array
    {
        return ["($alias.status <> 'declined' AND ($alias.status <> 'done' OR COALESCE($alias.target_quarter, DATE($alias.created_at)) >= ?))",
            [date('Y-m-d', strtotime('-9 months'))]];
    }

    /** Whether the project replaces its devices now (see liveSql()). */
    public static function isLive(int $projectId): bool
    {
        [$live, $p] = self::liveSql();
        return (bool) DB::value("SELECT 1 FROM roadmap_items ri WHERE ri.id = ? AND $live", [$projectId, ...$p]);
    }

    /**
     * Makes the projects (and tickets). $client is the client's row; $deviceIds come from the request (any not this
     * client's are ignored); $o holds the form's choices, checked again here (status and quarter against fixed
     * lists; the caller has bounded cost to the column's range).
     * @param array{mode?:string, quarter?:string, status?:string, cost?:?float, title?:string, note?:string, ticket?:bool} $o
     *   mode 'each' (one project per device, the default) or 'together'; quarter '' = each project's replacement
     *   quarter (overdue ones this quarter), or a quarter's first day; cost and title apply to a single project.
     * @return array{projects: list<array{id:int,title:string,ticket:?string,ticket_error:?string}>, skipped: list<string>}
     */
    public static function create(array $client, array $deviceIds, array $o, ?int $userId): array
    {
        $byId = [];
        foreach ((new Lifecycle())->devices((int) $client['id']) as $d) {
            $byId[(int) $d['id']] = $d;
        }
        $devices = [];
        $skipped = [];
        foreach (array_unique(array_map('intval', $deviceIds)) as $id) {
            $d = $byId[$id] ?? null;
            if (!$d) {
                continue; // not this client's
            }
            if (!$d['is_hardware'] || $d['status'] === 'excluded') {
                $skipped[] = $d['name'] . ' (not replaced as hardware)';
            } elseif ($d['project']) {
                $skipped[] = $d['name'] . ' (already in "' . $d['project']['title'] . '")';
            } else {
                $devices[] = $d;
            }
        }
        if (!$devices) {
            return ['projects' => [], 'skipped' => $skipped];
        }
        $together = ($o['mode'] ?? 'each') === 'together' && count($devices) > 1;
        $groups = $together ? [$devices] : array_map(fn($d) => [$d], $devices);
        $status = in_array($o['status'] ?? '', ['proposed', 'approved', 'scheduled'], true) ? $o['status'] : 'approved';
        $quarter = !empty($o['quarter']) && isset(Plan::choices(5)[$o['quarter']]) ? $o['quarter'] : null;
        $single = count($groups) === 1;
        // Only where a ticket can be made (pretend on a test server); otherwise Ready to start handles it later
        $wantTicket = !empty($o['ticket']) && ProjectTickets::makesTicket((string) ($client['psa_id'] ?? ''));

        $out = [];
        foreach ($groups as $g) {
            $title = $single && trim((string) ($o['title'] ?? '')) !== '' ? mb_substr(trim($o['title']), 0, 255) : self::title($g);
            $cost = $single && isset($o['cost']) && $o['cost'] !== null ? round(max(0.0, (float) $o['cost']), 2)
                : round(array_sum(array_map(fn($d) => (float) $d['replacement_cost'], $g)), 2);
            $count = count($g);
            $id = DB::transaction(function () use ($client, &$g, $title, $cost, $quarter, $status, $o, $userId, &$skipped) {
                // Two clicks or two people at once: lock the devices, then check again that none is in a project yet
                $ids = array_map(fn($d) => (int) $d['id'], $g);
                $in = implode(',', array_fill(0, count($ids), '?'));
                DB::all("SELECT id FROM devices WHERE id IN ($in) FOR UPDATE", $ids);
                [$live, $lp] = self::liveSql();
                $taken = array_map('intval', array_column(DB::all("SELECT rid.device_id FROM roadmap_item_devices rid JOIN roadmap_items ri ON ri.id = rid.roadmap_item_id
                    WHERE rid.device_id IN ($in) AND ri.client_id = ? AND $live FOR UPDATE", [...$ids, (int) $client['id'], ...$lp]), 'device_id'));
                foreach ($g as $k => $d) {
                    if (in_array((int) $d['id'], $taken, true)) {
                        $skipped[] = $d['name'] . ' (already in a project)';
                        unset($g[$k]);
                    }
                }
                $g = array_values($g);
                if (!$g) {
                    return null;
                }
                $id = DB::insert('roadmap_items', [
                    'client_id' => (int) $client['id'], 'title' => $title, 'category' => 'hardware',
                    'description' => self::description($g, (string) ($o['note'] ?? '')),
                    'target_quarter' => $quarter ?? self::quarterOf($g), 'cost' => $cost,
                    'priority' => array_filter($g, fn($d) => $d['status'] === 'replace') ? 'high' : 'medium',
                    'status' => $status, 'created_by' => $userId,
                ]);
                foreach ($g as $d) {
                    DB::run('INSERT IGNORE INTO roadmap_item_devices (roadmap_item_id, device_id) VALUES (?, ?)', [$id, (int) $d['id']]);
                }
                return $id;
            });
            if ($id === null) {
                continue;
            }
            if (count($g) !== $count) { // some were taken meanwhile: the name and budget follow the devices left
                $title = $single && trim((string) ($o['title'] ?? '')) !== '' ? $title : self::title($g);
                $cost = $single && isset($o['cost']) && $o['cost'] !== null ? $cost : round(array_sum(array_map(fn($d) => (float) $d['replacement_cost'], $g)), 2);
                DB::run('UPDATE roadmap_items SET title = ?, cost = ?, description = ? WHERE id = ?', [$title, $cost, self::description($g, (string) ($o['note'] ?? '')), $id]);
            }
            Audit::log('roadmap.create', "{$client['name']}: $title (from " . count($g) . ' device' . (count($g) === 1 ? '' : 's') . ': '
                . mb_strimwidth(implode(', ', array_column($g, 'name')), 0, 300, '…') . ')');
            $ticket = null;
            $error = null;
            if ($wantTicket) {
                // "Make the QUOTE- ticket now" (2.2.2: off by default; otherwise Ready to start makes it later)
                $t = ProjectTickets::start($id, $userId, $g, true); // a ticket only, never "marked started"
                [$ticket, $error] = [$t['ticket'], $t['reason']];
            }
            $out[] = ['id' => $id, 'title' => $title, 'ticket' => $ticket, 'ticket_error' => $error];
        }
        return ['projects' => $out, 'skipped' => $skipped];
    }

    /** "Replace laptop FRONTDESK-01", or for several "Replace 3 laptops" / "Replace 4 devices (2 laptops, 2 desktops)". */
    public static function title(array $g): string
    {
        $types = array_count_values(array_map(fn($d) => mb_strtolower($d['type']), $g));
        if (count($g) === 1) {
            return 'Replace ' . mb_strtolower($g[0]['type']) . ' ' . $g[0]['name'];
        }
        if (count($types) === 1) {
            return 'Replace ' . count($g) . ' ' . self::plural(array_key_first($types), count($g));
        }
        arsort($types);
        return 'Replace ' . count($g) . ' devices (' . implode(', ', array_map(fn($t, $n) => $n . ' ' . self::plural($t, $n), array_keys($types), $types)) . ')';
    }

    /** "laptops", "switches": a lower-case type in the plural when $n isn't 1. */
    private static function plural(string $type, int $n): string
    {
        if ($n === 1) {
            return $type;
        }
        return preg_match('/(s|x|ch|sh)$/', $type) ? $type . 'es' : $type . 's';
    }

    /** The earliest replacement quarter among the devices (an overdue one: this quarter), or null when none has one. */
    private static function quarterOf(array $g): ?string
    {
        $dates = array_filter(array_column($g, 'replace_due'));
        if (!$dates) {
            return null;
        }
        $first = min($dates);
        $qs = Plan::quarters();
        $cur = $qs[Plan::currentIndex()]['start'];
        return $first < $cur ? $cur : (Plan::quarterFor($first)['start'] ?? null);
    }

    /** One device as a line of plain text for the project's description. */
    private static function line(array $d): string
    {
        return $d['name'] . ' · ' . trim(short_make($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))
            . (!empty($d['serial']) ? ' · serial ' . $d['serial'] : '') . (!empty($d['last_user']) ? ' · ' . short_user($d['last_user']) : '')
            . ' · budgeted ' . money($d['replacement_cost']);
    }

    /** The project's description: the note, then the devices it replaces (plain text, cut to 10,000 characters). */
    private static function description(array $g, string $note): string
    {
        $lines = array_map(fn($d) => '- ' . self::line($d), $g);
        return mb_substr(trim(($note !== '' ? trim($note) . "\n\n" : '') . 'Replaces:' . "\n" . implode("\n", $lines)), 0, 10000);
    }

    /**
     * Devices of this project that another live project already replaces, by name: moving a declined project back to
     * proposed, approved, scheduled or done must not count a device twice.
     */
    public static function conflicts(int $projectId): array
    {
        [$live, $p] = self::liveSql();
        return array_column(DB::all("SELECT DISTINCT COALESCE(NULLIF(d.display_name, ''), NULLIF(d.system_name, ''), CONCAT('Device ', d.id)) AS name
            FROM roadmap_item_devices mine JOIN roadmap_items me ON me.id = mine.roadmap_item_id JOIN devices d ON d.id = mine.device_id
            JOIN roadmap_item_devices other ON other.device_id = mine.device_id AND other.roadmap_item_id <> mine.roadmap_item_id
            JOIN roadmap_items ri ON ri.id = other.roadmap_item_id AND ri.client_id = me.client_id AND $live
            WHERE mine.roadmap_item_id = ? ORDER BY name", [...$p, $projectId]), 'name');
    }

    /** Devices each project replaces, for the project window: [project id => [[id, name], ...]], loaded once. */
    public static function devicesFor(int $projectId): array
    {
        static $all = null;
        if ($all === null) {
            $all = [];
            foreach (DB::all('SELECT rid.roadmap_item_id, d.id, COALESCE(NULLIF(d.display_name, \'\'), NULLIF(d.system_name, \'\'), CONCAT(\'Device \', d.id)) AS name, d.removed_at
                    FROM roadmap_item_devices rid JOIN devices d ON d.id = rid.device_id ORDER BY name') as $r) {
                $all[(int) $r['roadmap_item_id']][] = $r;
            }
        }
        return $all[$projectId] ?? [];
    }
}
