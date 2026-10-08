<?php
declare(strict_types=1);

namespace Align\Reports;

use Align\Budget\Budget;
use Align\Budget\Contracts;
use Align\Compliance\Compliance;
use Align\Contacts\Contacts;
use Align\DB;
use Align\Licensing\Licenses;
use Align\Lifecycle\Lifecycle;
use Align\Roadmap\Plan;
use Align\Roadmap\Roadmap;

/**
 * Builds the data behind every printed report, so the stand-alone reports and the QBR pack
 * show exactly the same numbers. Everything here is client-facing: no internal notes.
 *
 * Security assumptions: every function reads one client's data by the $clientId it is given; the caller (a staff
 * report, or the portal for its own client) has checked access. Text in the results (device names, serials, job
 * messages, project titles) is synced or typed and untrusted: the views escape it. Internal meetings never reach
 * a report.
 */
final class ReportData
{
    /**
     * How devices are grouped in reports: infrastructure first (servers with their hosts and
     * virtual servers, then storage, network and power), then computers, then everything else.
     * Family => types, in order.
     */
    public const FAMILIES = [
        'Servers & virtualization' => ['Hypervisor host', 'Server', 'Virtual server'],
        'Storage' => ['NAS / Storage'],
        'Network & security' => ['Firewall', 'Router', 'Switch', 'Access point'],
        'Power' => ['UPS'],
        'Computers' => ['Desktop', 'Laptop', 'VDI / virtual desktop'],
        'Printers, phones & other' => ['Printer', 'Phone', 'Camera / NVR', 'Other', 'Unassigned'],
    ];

    /** Rows of the "By device type" table, in report order (virtual machines next to their physical kind). */
    public const CLASS_ORDER = ['server', 'virtual_server', 'storage', 'network', 'power', 'desktop', 'laptop', 'virtual_desktop', 'printer', 'other'];

    /** Position of a device type in report order (unknown types go last, alphabetically). */
    public static function typeRank(string $type): array
    {
        $n = 0;
        foreach (self::FAMILIES as $types) {
            foreach ($types as $t) {
                if ($t === $type) {
                    return [$n, ''];
                }
                $n++;
            }
        }
        return [$n, strtolower($type)];
    }

    /** The report family a device type belongs to. */
    public static function family(string $type): string
    {
        foreach (self::FAMILIES as $f => $types) {
            if (in_array($type, $types, true)) {
                return $f;
            }
        }
        return 'Printers, phones & other';
    }

    /** Evaluated devices per client for this request (the QBR asks for them from several sections). */
    private static array $deviceCache = [];

    /** Evaluated devices for a client (optionally without virtual machines), excluding excluded ones. */
    public static function devices(int $clientId, bool $virtual = true): array
    {
        self::$deviceCache[$clientId] ??= (new Lifecycle())->devices($clientId);
        return array_values(array_filter(self::$deviceCache[$clientId], fn($d) => $d['status'] !== 'excluded' && ($virtual || !$d['is_virtual'])));
    }

    /** Sort rank of a device's status: replace first, then unsupported OS, plan, OS ending, warranty. */
    public static function severity(array $d): int
    {
        return ['replace' => 0, 'os_eos' => 1, 'plan' => 2, 'deferred' => 2, 'os_soon' => 3, 'warranty_expired' => 4, 'warranty_soon' => 5][$d['status']] ?? 9;
    }

    /** One plain sentence saying what's wrong with a device. */
    public static function issue(array $d): string
    {
        $parts = [];
        foreach ($d['flags'] as $f) {
            $parts[] = match ($f) {
                'replace' => !empty($d['replace_planned']) ? 'Replacement planned for ' . $d['replace_label'] : 'Past end of life (' . fmt_date($d['eol_date']) . ')',
                'plan' => !empty($d['replace_planned']) ? 'Replacement planned for ' . $d['replace_label'] : 'End of life ' . fmt_date($d['eol_date']),
                'deferred' => in_array('plan', $d['flags'], true) ? null : 'Past end of life (' . fmt_date($d['eol_date']) . '); replacement put off to ' . $d['replace_label'],
                'os_eos' => ($d['os_rule']['label'] ?? 'OS') . ' unsupported since ' . fmt_date($d['os_rule']['eos_date'] ?? null),
                'os_soon' => ($d['os_rule']['label'] ?? 'OS') . ' support ends ' . fmt_date($d['os_rule']['eos_date'] ?? null),
                'warranty_expired' => 'Warranty ended ' . fmt_date($d['warranty_end']),
                'warranty_soon' => 'Warranty ends ' . fmt_date($d['warranty_end']),
                default => null,
            };
        }
        return implode(' · ', array_filter($parts));
    }

    /** Everything the asset report shows for a client: summary, classes, OS, issues, attention list, forecast, inventory. $opt['virtual'] includes VMs. */
    public static function assets(int $clientId, array $opt): array
    {
        $lc = new Lifecycle();
        $devices = self::devices($clientId, (bool) ($opt['virtual'] ?? false));
        $forecast = $lc->forecast($devices);
        $summary = Lifecycle::summarize($devices);

        // Fleet by lifecycle class
        $classes = [];
        foreach ($devices as $d) {
            $cls = Lifecycle::TYPES[$d['type']][0] ?? 'other';
            if ($d['is_virtual']) {
                $cls = $cls === 'desktop' ? 'virtual_desktop' : 'virtual_server';
            }
            $c = &$classes[$cls];
            $c ??= ['label' => ['virtual_server' => 'Virtual servers', 'virtual_desktop' => 'Virtual desktops'][$cls] ?? Lifecycle::CLASSES[$cls], 'count' => 0, 'ok' => 0, 'warn' => 0, 'bad' => 0, 'ages' => [], 'value' => 0.0, 'overdue_cost' => 0.0];
            $c['count']++;
            $tone = $d['status_tone'] === 'bad' ? 'bad' : ($d['status_tone'] === 'warn' ? 'warn' : 'ok');
            $c[$tone]++;
            if ($d['age_years'] !== null) {
                $c['ages'][] = (float) $d['age_years'];
            }
            if ($d['is_hardware']) {
                $c['value'] += $d['replacement_cost'];
                if ($d['status'] === 'replace' && empty($d['project'])) { // a project's devices: counted as the project
                    $c['overdue_cost'] += $d['replacement_cost'];
                }
            }
            unset($c);
        }
        $order = array_flip(self::CLASS_ORDER);
        uksort($classes, fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        foreach ($classes as &$c) {
            $c['avg_age'] = $c['ages'] ? round(array_sum($c['ages']) / count($c['ages']), 1) : null;
            unset($c['ages']);
        }
        unset($c);

        // Operating systems
        $os = [];
        foreach ($devices as $d) {
            if (!$d['os_name']) {
                continue;
            }
            $key = $d['os_rule']['label'] ?? short_os($d['os_name']);
            $o = &$os[$key];
            $o ??= ['label' => $key, 'count' => 0, 'eos' => $d['os_rule']['eos_date'] ?? null, 'state' => 'ok'];
            $o['count']++;
            if (in_array('os_eos', $d['flags'], true)) {
                $o['state'] = 'bad';
            } elseif (in_array('os_soon', $d['flags'], true) && $o['state'] !== 'bad') {
                $o['state'] = 'warn';
            }
            unset($o);
        }
        uasort($os, fn($a, $b) => [['bad' => 0, 'warn' => 1, 'ok' => 2][$a['state']], -$a['count']] <=> [['bad' => 0, 'warn' => 1, 'ok' => 2][$b['state']], -$b['count']]);

        // What needs doing, grouped
        $sumCost = fn(array $list) => array_sum(array_map(fn($d) => $d['is_hardware'] ? $d['replacement_cost'] : 0, $list));
        $has = fn(string $f) => array_values(array_filter($devices, fn($d) => in_array($f, $d['flags'], true)));
        $unplanned = Lifecycle::unplanned($devices);
        $issues = array_filter([
            ['key' => 'replace', 'tone' => 'bad', 'title' => 'Past end of life', 'desc' => 'Due for replacement now', 'list' => $has('replace')],
            ['key' => 'os_eos', 'tone' => 'bad', 'title' => 'Unsupported operating system', 'desc' => 'No longer receiving security updates', 'list' => $has('os_eos')],
            ['key' => 'plan', 'tone' => 'warn', 'title' => 'Reach end of life within 12 months', 'desc' => 'Plan and budget replacements', 'list' => $has('plan')],
            ['key' => 'os_soon', 'tone' => 'warn', 'title' => 'OS support ending within 12 months', 'desc' => 'Upgrade or replace', 'list' => $has('os_soon')],
            ['key' => 'warranty_expired', 'tone' => 'warn', 'title' => 'Out of warranty', 'desc' => 'Repairs are billable', 'list' => $has('warranty_expired')],
            ['key' => 'warranty_soon', 'tone' => 'muted', 'title' => 'Warranty expiring soon', 'desc' => 'Decide whether to extend', 'list' => $has('warranty_soon')],
            ['key' => 'unplanned', 'tone' => 'muted', 'title' => 'No in-service date', 'desc' => "Can't be planned until a purchase date is recorded", 'list' => $unplanned],
        ], fn($i) => $i['list']);
        foreach ($issues as &$i) {
            $i['count'] = count($i['list']);
            $i['cost'] = $sumCost($i['list']);
            unset($i['list']);
        }
        unset($i);

        $attention = array_values(array_filter($devices, fn($d) => in_array($d['status_tone'], ['bad', 'warn'], true)));
        // Most urgent first; within the same urgency, servers and infrastructure before computers
        usort($attention, fn($a, $b) => [self::severity($a), self::typeRank($a['type']), (string) $a['eol_date'], strtolower($a['name'])]
            <=> [self::severity($b), self::typeRank($b['type']), (string) $b['eol_date'], strtolower($b['name'])]);

        $byType = [];
        foreach ($devices as $d) {
            $byType[$d['type']][] = $d;
        }
        uksort($byType, fn($a, $b) => self::typeRank((string) $a) <=> self::typeRank((string) $b));
        foreach ($byType as &$list) {
            usort($list, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        }
        unset($list);

        return [
            'devices' => $devices,
            'summary' => $summary,
            'healthy' => count(array_filter($devices, fn($d) => !in_array($d['status_tone'], ['bad', 'warn'], true))),
            'actNow' => count(array_filter($devices, fn($d) => $d['status_tone'] === 'bad')),
            'classes' => $classes,
            'os' => array_values($os),
            'issues' => array_values($issues),
            'attention' => $attention,
            'forecast' => $forecast,
            'years' => Lifecycle::yearTotals($forecast),
            'byType' => $byType,
            'policy' => $lc->policy(),
            'fleetValue' => $sumCost($devices),
            'withUser' => count(array_filter($devices, fn($d) => !empty($d['last_user']))),
        ];
    }

    /**
     * The client's roadmap plan and its projects in decision order, without internal meetings. $meetings false
     * leaves out the meetings and $compliance false the compliance due dates in the timeline: the portal passes
     * the user's "Documents, contacts & meetings" and "Devices" permissions (2.2.1: a roadmap-only portal user
     * saw meeting titles and dates the rest of the portal withholds).
     */
    public static function roadmap(int $clientId, bool $meetings = true, bool $compliance = true): array
    {
        $plan = Roadmap::build($clientId, self::devices($clientId));
        foreach ($plan['quarters'] as &$q) { // internal meetings never go to the client
            $q['meetings'] = $meetings ? array_values(array_filter($q['meetings'], fn($m) => $m['type'] !== 'internal')) : [];
            if (!$compliance) {
                $q['compliance'] = [];
            }
        }
        unset($q);
        $projects = [];
        foreach ($plan['quarters'] as $q) {
            foreach ($q['items'] as $it) {
                $projects[] = $it + ['when' => $q['label'], 'q_index' => $q['index']];
            }
        }
        foreach ($plan['backlog'] as $it) {
            $projects[] = $it + ['when' => $it['target_quarter'] ? quarter_label($it['target_quarter']) : 'To be scheduled', 'q_index' => 99];
        }
        $order = ['proposed' => 0, 'approved' => 1, 'scheduled' => 2, 'done' => 3, 'declined' => 4];
        usort($projects, fn($a, $b) => [$order[$a['status']] ?? 9, $a['q_index'], $a['title']] <=> [$order[$b['status']] ?? 9, $b['q_index'], $b['title']]);
        return [
            'plan' => $plan,
            'projects' => $projects,
            'pending' => array_values(array_filter($projects, fn($p) => $p['status'] === 'proposed')),
            'active' => array_values(array_filter($projects, fn($p) => in_array($p['status'], ['approved', 'scheduled'], true))),
            'currentIndex' => Plan::currentIndex(),
        ];
    }

    /** The client's budget for plan year $year (0-2, checked by the caller) and the contract dates in it. */
    public static function budget(int $clientId, int $year): array
    {
        $b = Budget::build($clientId, self::devices($clientId));
        $yr = $b['years'][$year];
        return [
            'b' => $b,
            'year' => $year,
            'yr' => $yr,
            'qIdx' => array_keys(array_filter($b['quarters'], fn($q) => $q['year'] === $year)),
            'dates' => array_values(array_filter(Contracts::upcoming($clientId, 3 * 366, $yr['from']), fn($d) => $d['date'] <= $yr['to'])),
        ];
    }

    /** Assigned frameworks with scores, the average, and up to 25 open items (only of assigned frameworks). */
    public static function compliance(int $clientId): array
    {
        $fws = DB::all('SELECT f.id, f.name, f.description FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$clientId]);
        foreach ($fws as &$fw) {
            $fw['score'] = Compliance::score($clientId, (int) $fw['id']);
        }
        unset($fw);
        $open = DB::all("SELECT c.ref, c.title, f.name AS framework, s.status, s.owner, s.due_date
            FROM client_control_status s JOIN compliance_controls c ON c.id = s.control_id JOIN compliance_frameworks f ON f.id = c.framework_id
            JOIN client_frameworks cf ON cf.framework_id = f.id AND cf.client_id = s.client_id
            WHERE s.client_id = ? AND s.status IN ('not_met','partial') ORDER BY s.due_date IS NULL, s.due_date, f.name, c.sort LIMIT 25", [$clientId]);
        $avg = $fws ? (int) round(array_sum(array_map(fn($f) => $f['score']['score'], $fws)) / count($fws)) : null;
        return ['frameworks' => $fws, 'open' => $open, 'avg' => $avg];
    }

    /** The client's licenses by category and cost, with totals. */
    public static function licensing(int $clientId): array
    {
        $ls = Licenses::load($clientId);
        usort($ls, fn($a, $b) => [$a['category'], -$a['annual']] <=> [$b['category'], -$b['annual']]);
        return ['licenses' => $ls, 'totals' => Licenses::totals($ls)];
    }

    /**
     * Key contacts and the next and last client meetings (internal ones left out). Contacts are personal data:
     * the portal leaves this out for users without the contacts permission (ReportController::renderQbr).
     */
    public static function people(int $clientId): array
    {
        return [
            'contacts' => Contacts::key($clientId),
            'nextMeeting' => DB::one("SELECT m.*, u.name AS owner_name FROM meetings m LEFT JOIN users u ON u.id = m.owner_id
                WHERE m.client_id = ? AND m.type <> 'internal' AND m.status = 'scheduled' AND m.starts_at >= NOW() ORDER BY m.starts_at LIMIT 1", [$clientId]),
            'lastMeeting' => DB::one("SELECT * FROM meetings WHERE client_id = ? AND type <> 'internal' AND status = 'completed' ORDER BY starts_at DESC LIMIT 1", [$clientId]),
        ];
    }

    /**
     * 2.3.0: the client's latest alignment review for reports (client-facing: no staff notes, no "how to check"), or
     * null when it was never reviewed. Gaps keep why each matters and the plan (the roadmap project made for it).
     */
    public static function alignment(int $clientId): ?array
    {
        $s = \Align\Alignment\Alignment::summary($clientId);
        if (!$s['review']) {
            return null;
        }
        $gaps = array_map(fn($g) => ['title' => $g['title'], 'priority' => $g['priority'], 'why' => $g['why'], 'project' => $g['project']], $s['gaps']);
        return ['review' => $s['review'], 'score' => $s['score'], 'delta' => $s['delta'], 'previous' => $s['previous'],
            'history' => array_slice($s['history'], 0, 4), 'gaps' => $gaps, 'na' => count($s['na'])];
    }

    /**
     * 2.7.0 The QBR's Security section: Huntress (coverage, incidents, summary reports), security awareness training
     * and every automatic security check that has a result (Microsoft 365, Google Workspace, email, Huntress, SAT).
     * Null when the client has none of them. ['huntress' => Huntress\Clients::forClient() or null, 'sat' => uploads,
     * 'checks' => [label => [status, detail]] (known ones only), 'incidents90' => severity => count in 90 days].
     */
    public static function security(int $clientId): ?array
    {
        $devices = self::devices($clientId);
        $h = \Align\Huntress\Clients::forClient($clientId, $devices);
        $checks = [];
        foreach (\Align\Health\SecurityChecks::indicators($clientId, $devices, $h) as $i) {
            if (!$i['unknown']) {
                $checks[$i['label']] = [$i['ok'] ? 'pass' : 'fail', $i['text']];
            }
        }
        if (!$h && !$checks) {
            return null;
        }
        $inc = $h ? array_column(DB::all('SELECT severity, COUNT(*) AS n FROM huntress_incidents WHERE org_id = ? AND sent_at >= NOW() - INTERVAL 90 DAY GROUP BY severity',
            [$h['org']['org_id']]), 'n', 'severity') : [];
        // SAT figures from the same source as the checks (2.7.2: Curricula's API, else uploads)
        $src = \Align\Sat\Sat::source($clientId);
        return ['huntress' => $h, 'checks' => $checks, 'incidents90' => $inc,
            'training' => DB::one("SELECT * FROM sat_results WHERE client_id = ? AND source = ? AND kind = 'training' ORDER BY covers_to DESC, id DESC LIMIT 1", [$clientId, $src]),
            'phishing' => DB::one("SELECT SUM(sent) AS sent, SUM(clicked) AS clicked, SUM(reported) AS reported, MAX(covers_to) AS last FROM sat_results
                WHERE client_id = ? AND source = ? AND kind = 'phishing' AND covers_to >= CURDATE() - INTERVAL 12 MONTH HAVING SUM(sent) > 0", [$clientId, $src])];
    }

    /** Backup status for a client, or null when it has no backup data. */
    public static function backup(int $clientId): ?array
    {
        $client = DB::one('SELECT id FROM clients WHERE id = ?', [$clientId]);
        return $client ? \Align\Backup\Backup::forClient($client, self::devices($clientId)) : null;
    }

    /**
     * Plain-language highlights for the QBR executive summary, most important first. Each input is a section's data,
     * or empty/null when that section is off (the caller decides). Amounts only when $costs. Plain text: escape it.
     * @return array<int, array{tone:string, title:string, text:string}>
     */
    public static function highlights(array $a, array $r, ?array $bud, ?array $comp, ?array $lic, bool $costs, ?array $bk = null, ?array $sla = null, ?array $al = null): array
    {
        $out = [];
        $bkOut = [];
        if ($bk) {
            $s = $bk['stats'];
            $list = fn(array $rows, string $key) => implode(', ', array_slice(array_column($rows, $key), 0, 3)) . (count($rows) > 3 ? '…' : '');
            $failed = array_values(array_filter($bk['jobs'], fn($j) => $j['is_enabled'] && ($j['status_counted'] ?? true) && $j['status'] === 'failed'));
            if ($failed) {
                $bkOut[] = ['tone' => 'bad', 'title' => count($failed) . ' backup job' . (count($failed) == 1 ? '' : 's') . ' failed on the last run', 'text' => $list($failed, 'name') . '. We are working to get ' . (count($failed) == 1 ? 'it' : 'them') . ' running again.'];
            }
            if ($bk['unprotected']) {
                $n = count($bk['unprotected']);
                $bkOut[] = ['tone' => 'bad', 'title' => $n . ' server' . ($n == 1 ? ' has' : 's have') . ' no backup', 'text' => $list($bk['unprotected'], 'name') . '. Add ' . ($n == 1 ? 'it' : 'them') . ' to a backup job or confirm ' . ($n == 1 ? 'it isn\'t' : 'they aren\'t') . ' needed.'];
            }
            $overdue = array_values(array_filter($bk['workloads'], fn($w) => $w['tone'] !== 'ok'));
            if ($overdue) {
                $bkOut[] = ['tone' => 'warn', 'title' => count($overdue) . ' machine' . (count($overdue) == 1 ? '' : 's') . ' without a recent backup', 'text' => $list($overdue, 'name') . ' ha' . (count($overdue) == 1 ? 's' : 've') . ' no restore point from the last ' . $bk['stale'] . ' hours.'];
            }
            $m365Over = $bk['m365']['overdue'] ?? [];
            if ($m365Over) {
                $bkOut[] = ['tone' => 'warn', 'title' => count($m365Over) . ' Microsoft 365 item' . (count($m365Over) == 1 ? '' : 's') . ' without a recent backup', 'text' => $list($m365Over, 'name') . '.'];
            }
            if (!$failed && !$bk['unprotected'] && !$overdue && !$m365Over && ($s['protected'] || !empty($bk['m365']['total']))) {
                $bkOut[] = ['tone' => 'ok', 'title' => 'Backups are healthy', 'text' => ($s['protected'] ? 'All ' . $s['protected'] . ' protected machines' . (!empty($bk['m365']['total']) ? ' and your Microsoft 365 data' : '') . ' have' : 'Your Microsoft 365 data has') . ' a recent restore point' . ($s['rate'] !== null ? ' and ' . $s['rate'] . '% of backup runs succeeded in the last 30 days' : '') . '.'];
            }
        }
        $m = fn(float $v) => $costs && $v > 0 ? ' (about ' . money($v) . ')' : '';
        foreach ($a['issues'] ?? [] as $i) {
            if ($i['key'] === 'replace') {
                $out[] = ['tone' => 'bad', 'title' => $i['count'] . ' device' . ($i['count'] == 1 ? ' is' : 's are') . ' past end of life', 'text' => 'Replacing them now reduces downtime and security risk' . $m($i['cost']) . '.'];
            } elseif ($i['key'] === 'os_eos') {
                $out[] = ['tone' => 'bad', 'title' => $i['count'] . ' device' . ($i['count'] == 1 ? ' runs' : 's run') . ' an unsupported operating system', 'text' => 'They no longer receive security updates. Upgrade or replace them.'];
            } elseif ($i['key'] === 'plan') {
                $out[] = ['tone' => 'warn', 'title' => $i['count'] . ' more reach end of life within a year', 'text' => 'Already scheduled in the roadmap and budget' . $m($i['cost']) . '.'];
            }
        }
        if (!empty($r['pending'])) {
            $n = count($r['pending']);
            $sum = array_sum(array_map(fn($p) => (float) $p['cost'], $r['pending']));
            $out[] = ['tone' => 'info', 'title' => $n . ' project' . ($n == 1 ? '' : 's') . ' waiting for a decision', 'text' => implode(', ', array_slice(array_column($r['pending'], 'title'), 0, 3)) . ($n > 3 ? '…' : '') . $m($sum) . '.'];
        }
        if ($comp && $comp['frameworks']) {
            $low = array_filter($comp['frameworks'], fn($f) => $f['score']['score'] < 80);
            $out[] = $low
                ? ['tone' => 'warn', 'title' => 'Compliance: ' . $comp['avg'] . '% average across ' . count($comp['frameworks']) . ' framework' . (count($comp['frameworks']) == 1 ? '' : 's'), 'text' => count($comp['open']) . ' open item' . (count($comp['open']) == 1 ? '' : 's') . ' to close; ' . implode(', ', array_column($low, 'name')) . ' below 80%.']
                : ['tone' => 'ok', 'title' => 'Compliance in good shape (' . $comp['avg'] . '% average)', 'text' => 'Keep reviews and evidence current.'];
        }
        if ($al && $al['score']['score'] !== null) {
            // 2.3.0: the alignment score, its change since the review before and how many gaps already have a plan
            $sc = (int) $al['score']['score'];
            $planned = count(array_filter($al['gaps'], fn($g) => $g['project']));
            $out[] = ['tone' => $sc >= 80 ? 'ok' : ($sc >= 60 ? 'warn' : 'bad'),
                'title' => 'Alignment with our standards: ' . $sc . '% (' . strtolower($al['score']['band']) . ')',
                'text' => ($al['delta'] !== null && $al['delta'] !== 0 ? ($al['delta'] > 0 ? 'Up ' : 'Down ') . abs($al['delta']) . ' points since ' . fmt_date($al['previous']['finished_at']) . '. ' : '')
                    . ($al['gaps'] ? count($al['gaps']) . ' gap' . (count($al['gaps']) == 1 ? '' : 's') . ' to close' . ($planned ? ', ' . $planned . ' already planned' : '') . '.' : 'Every standard that applies is met.')];
        }
        if ($sla && ($h = \Align\Service\Sla::headline($sla))) {
            $out[] = $h;
        }
        if ($bud) {
            $soon = array_filter($bud['dates'], fn($d) => $d['urgency'] !== 'later');
            if ($soon) {
                $out[] = ['tone' => 'warn', 'title' => count($soon) . ' contract' . (count($soon) == 1 ? '' : 's') . ' or renewal' . (count($soon) == 1 ? '' : 's') . ' coming up', 'text' => implode(', ', array_slice(array_map(fn($d) => $d['name'] . ' (' . fmt_date($d['date']) . ')', array_values($soon)), 0, 3)) . '.'];
            }
        }
        if (!$out) {
            $out[] = ['tone' => 'ok', 'title' => 'Everything is within policy', 'text' => 'No devices past end of life, no unsupported systems and nothing waiting on a decision.'];
        }
        // Backup problems lead; a healthy-backups note goes last
        $bad = array_filter($bkOut, fn($h) => $h['tone'] === 'bad');
        return array_merge($bad, $out, array_diff_key($bkOut, $bad));
    }
}
