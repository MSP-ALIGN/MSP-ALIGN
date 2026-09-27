<?php
declare(strict_types=1);

namespace Align\Dashboard;

use Align\Auth;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Service\Sla;

/**
 * The home dashboard: a card registry, each user's own layout (order + hidden cards),
 * the "Needs attention" list and the portfolio health tiles.
 */
final class Dashboard
{
    public const ZONES = ['top', 'main', 'side'];

    /** key => [title, icon, default zone, what it shows] */
    public const CARDS = [
        'attention' => ['Needs attention', 'fa-bell', 'top', 'One list of what to act on across every client: SLA breaches, failed backups, sync errors, overdue meetings and compliance items, renewals and client decisions.'],
        'kpis' => ['Portfolio health', 'fa-gauge-high', 'top', 'Tiles for service levels and backups, lifecycle and security, client engagement, and money.'],
        'forecast' => ['3-year forecast', 'fa-chart-column', 'main', 'Hardware replacements and projects by quarter across all clients.'],
        'clients' => ['Clients needing attention', 'fa-triangle-exclamation', 'main', 'Clients with devices past end of life, on an unsupported OS or otherwise out of policy.'],
        'sla' => ['Service levels', 'fa-stopwatch', 'main', 'Last 90 days of ITFlow SLA results and the clients below goal.'],
        'planning' => ['Client planning', 'fa-list-check', 'main', 'Each client\'s planning checklist and the next step.'],
        'meetings' => ['Next 30 days', 'fa-calendar-days', 'side', 'Scheduled meetings for the next month.'],
        'due' => ['Due for a meeting', 'fa-clock', 'side', 'Clients past their meeting cadence with nothing scheduled.'],
        'backups' => ['Backups needing attention', 'fa-database', 'side', 'Clients with failed jobs, warnings or machines without a recent restore point.'],
        'renewals' => ['Contracts & renewals', 'fa-calendar-check', 'side', 'Contract ends, notice deadlines and license renewals in the next 90 days.'],
        'portal' => ['Client portal activity', 'fa-door-open', 'side', 'Project decisions and contact changes made by clients in the portal.'],
    ];

    // ---- Layout ---------------------------------------------------------------------

    public static function defaultLayout(): array
    {
        $order = array_fill_keys(self::ZONES, []);
        foreach (self::CARDS as $k => [, , $zone]) {
            $order[$zone][] = $k;
        }
        return ['order' => $order, 'hidden' => []];
    }

    /** The user's layout, cleaned up: unknown cards dropped, cards added since they saved it placed in their default zone. */
    public static function layout(?array $user = null): array
    {
        $user ??= Auth::user();
        $saved = json_decode((string) ($user['dashboard_layout'] ?? ''), true);
        return self::normalize(is_array($saved) ? $saved : []);
    }

    public static function normalize(array $in): array
    {
        $def = self::defaultLayout();
        $order = array_fill_keys(self::ZONES, []);
        $seen = [];
        foreach (self::ZONES as $z) {
            foreach ((array) ($in['order'][$z] ?? []) as $k) {
                if (is_string($k) && isset(self::CARDS[$k]) && !isset($seen[$k])) {
                    $order[$z][] = $k;
                    $seen[$k] = true;
                }
            }
        }
        if (!$seen) {
            $order = $def['order'];
        } else {
            foreach (self::CARDS as $k => [, , $zone]) {
                if (!isset($seen[$k])) {
                    $order[$zone][] = $k;
                }
            }
        }
        $hidden = array_values(array_unique(array_filter((array) ($in['hidden'] ?? []), fn($k) => is_string($k) && isset(self::CARDS[$k]))));
        return ['order' => $order, 'hidden' => $hidden];
    }

    public static function save(int $userId, ?array $layout): void
    {
        DB::run('UPDATE users SET dashboard_layout = ? WHERE id = ?', [$layout === null ? null : json_encode(self::normalize($layout)), $userId]);
    }

    /** Visible card keys in display order. */
    public static function visible(array $layout): array
    {
        $out = [];
        foreach (self::ZONES as $z) {
            foreach ($layout['order'][$z] as $k) {
                if (!in_array($k, $layout['hidden'], true)) {
                    $out[] = $k;
                }
            }
        }
        return $out;
    }

    // ---- Needs attention ----------------------------------------------------------------

    public const CATEGORIES = [
        'system' => ['Sync & integrations', 'fa-plug'],
        'service' => ['Service levels', 'fa-stopwatch'],
        'backup' => ['Backups', 'fa-database'],
        'decision' => ['Client decisions', 'fa-circle-question'],
        'meeting' => ['Meetings', 'fa-handshake'],
        'renewal' => ['Renewals', 'fa-calendar-check'],
        'compliance' => ['Compliance', 'fa-clipboard-check'],
        'lifecycle' => ['Lifecycle', 'fa-recycle'],
    ];

    /**
     * Everything to act on, most urgent first. Each item:
     * [tone bad|warn|info, category, title, detail, link, client_name|null, when|null]
     * @param array $ctx data the controller already has: devices, overdue (meetings), lastSync, unmapped, unassigned
     */
    public static function attention(array $ctx): array
    {
        $items = [];
        $add = function (string $tone, string $cat, string $title, string $detail, string $link, ?string $client = null, ?string $when = null) use (&$items) {
            $items[] = compact('tone', 'cat', 'title', 'detail', 'link', 'client', 'when');
        };
        $names = array_column(DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0'), 'name', 'id');

        // Sync & integrations
        $last = $ctx['lastSync'] ?? null;
        if ($last && in_array($last['status'], ['failed', 'partial'], true)) {
            $add($last['status'] === 'failed' ? 'bad' : 'warn', 'system', 'Last sync ' . ($last['status'] === 'failed' ? 'failed' : 'had errors'), 'Started ' . rel_time($last['started_at']) . '. Open it to see which step failed.', '/sync/' . (int) $last['id'], null, $last['started_at']);
        } elseif ($last && strtotime((string) $last['started_at']) < time() - 6 * 3600) {
            $add('warn', 'system', 'No sync for ' . rel_time($last['started_at']), 'The hourly sync hasn\'t run. Check the sync timer on the server, or run a sync now.', '/sync');
        }
        if (Auth::can('admin')) {
            foreach (\Align\Integrations\Registry::all() as $c) {
                [$tone, $label, $detail] = $c->status();
                if ($tone === 'danger') {
                    $add('bad', 'system', $c->name() . ': ' . strtolower($label), $detail ?: 'Open the integration and press Test connection.', $c->url());
                }
            }
        }
        if (!empty($ctx['unmapped']) || !empty($ctx['unassigned'])) {
            $add('info', 'system', 'Client mapping to review', trim(($ctx['unmapped'] ? $ctx['unmapped'] . ' ITFlow client(s) without a NinjaOne organization. ' : '') . ($ctx['unassigned'] ? $ctx['unassigned'] . ' NinjaOne device(s) in an unlinked organization.' : '')), '/mapping');
        }

        // Service levels: open tickets past or close to target
        if (Sla::enabled() && Sla::supported() !== false) {
            foreach (DB::all("SELECT t.client_id, SUM(t.response_met = 0 OR t.resolution_met = 0) AS b,
                    SUM(NOT (t.response_met <=> 0) AND NOT (t.resolution_met <=> 0) AND ((t.first_response_at IS NULL AND t.response_stage = 1) OR (t.first_response_at IS NOT NULL AND t.resolution_stage = 1))) AS w
                FROM itflow_tickets t WHERE t.client_id IS NOT NULL AND t.closed_at IS NULL AND t.resolved_at IS NULL AND t.archived_at IS NULL AND t.sla_id > 0
                GROUP BY t.client_id") as $r) {
                if (!isset($names[$r['client_id']])) {
                    continue;
                }
                if ((int) $r['b']) {
                    $add('bad', 'service', (int) $r['b'] . ' open ticket' . ((int) $r['b'] === 1 ? '' : 's') . ' past SLA target', ((int) $r['w'] ? (int) $r['w'] . ' more close to target. ' : '') . 'Follow up in ITFlow.', '/clients/' . (int) $r['client_id'] . '/service-levels', $names[$r['client_id']]);
                } elseif ((int) $r['w']) {
                    $add('warn', 'service', (int) $r['w'] . ' open ticket' . ((int) $r['w'] === 1 ? '' : 's') . ' close to SLA target', 'Due soon; respond or resolve to stay within target.', '/clients/' . (int) $r['client_id'] . '/service-levels', $names[$r['client_id']]);
                }
            }
        }

        // Backups
        if (\Align\Backup\Backup::enabled()) {
            foreach (\Align\Backup\Backup::summaries() as $id => $x) {
                if (!isset($names[$id])) {
                    continue;
                }
                if ($x['failed']) {
                    $add('bad', 'backup', $x['failed'] . ' backup job' . ($x['failed'] === 1 ? '' : 's') . ' failed', ($x['overdue'] ? $x['overdue'] . ' item(s) without a recent restore point. ' : '') . 'Check the job in Veeam, or mark items that don\'t need a backup.', '/clients/' . $id . '/backups', $names[$id]);
                } elseif ($x['overdue']) {
                    $add('warn', 'backup', $x['overdue'] . ' item' . ($x['overdue'] === 1 ? '' : 's') . ' without a recent backup', 'No restore point within ' . \Align\Backup\Backup::staleHours() . ' hours.', '/clients/' . $id . '/backups', $names[$id]);
                } elseif ($x['warning']) {
                    $add('info', 'backup', $x['warning'] . ' backup job' . ($x['warning'] === 1 ? '' : 's') . ' finished with a warning', 'The last run completed with warnings.', '/clients/' . $id . '/backups', $names[$id]);
                }
            }
        }

        // Client decisions: approved in the portal recently (schedule them), proposals waiting a long time
        foreach (DB::all("SELECT r.id, r.title, r.client_id, r.updated_at FROM roadmap_items r JOIN clients c ON c.id = r.client_id AND c.is_archived = 0 AND c.planning_excluded = 0
                WHERE r.status = 'approved' AND r.target_quarter IS NULL AND r.updated_at >= ? ORDER BY r.updated_at DESC LIMIT 10", [date('Y-m-d', strtotime('-30 days'))]) as $r) {
            $add('info', 'decision', 'Approved, needs a quarter: ' . $r['title'], 'Pick a quarter so it lands in the roadmap and budget.', '/clients/' . (int) $r['client_id'] . '/roadmap', $names[$r['client_id']] ?? null, $r['updated_at']);
        }
        $waiting = DB::all("SELECT r.client_id, COUNT(*) AS n, MIN(r.created_at) AS since FROM roadmap_items r JOIN clients c ON c.id = r.client_id AND c.is_archived = 0 AND c.planning_excluded = 0
            WHERE r.status = 'proposed' AND r.created_at < ? GROUP BY r.client_id", [date('Y-m-d', strtotime('-30 days'))]);
        foreach ($waiting as $w) {
            $add('info', 'decision', (int) $w['n'] . ' proposal' . ((int) $w['n'] === 1 ? '' : 's') . ' waiting over 30 days', 'Proposed ' . fmt_date($w['since']) . '. Follow up or bring to the next meeting.', '/clients/' . (int) $w['client_id'] . '/roadmap', $names[$w['client_id']] ?? null);
        }

        // Meetings overdue
        foreach ($ctx['overdue'] ?? [] as $c) {
            $add('warn', 'meeting', 'Due for a meeting', $c['last'] ? 'Last met ' . fmt_date($c['last']) . ', nothing scheduled.' : 'No meetings recorded yet.', '/clients/' . (int) $c['id'] . '/meetings', $c['name']);
        }

        // Renewals and notice deadlines within 30 days
        foreach (\Align\Budget\Contracts::upcoming(null, 30, date('Y-m-d', strtotime('-7 days'))) as $d) {
            $overdue = $d['date'] < date('Y-m-d');
            $add($d['kind'] === 'renegotiate' || $overdue ? 'warn' : 'info', 'renewal', $d['label'] . ' ' . fmt_date($d['date']) . ': ' . $d['name'],
                ($d['annual'] > 0 ? money($d['annual']) . '/yr' : 'No price recorded') . ($d['auto_renew'] ? ' · auto-renews' : ''), $d['link'], $d['client_name'], $d['date']);
        }

        // Compliance items past their due date
        foreach (DB::all("SELECT s.client_id, COUNT(*) AS n, MIN(s.due_date) AS oldest FROM client_control_status s
                JOIN compliance_controls c ON c.id = s.control_id JOIN client_frameworks cf ON cf.client_id = s.client_id AND cf.framework_id = c.framework_id
                WHERE s.status IN ('not_met','partial','not_assessed') AND s.due_date IS NOT NULL AND s.due_date < CURDATE() GROUP BY s.client_id") as $r) {
            if (isset($names[$r['client_id']])) {
                $add('warn', 'compliance', (int) $r['n'] . ' compliance item' . ((int) $r['n'] === 1 ? '' : 's') . ' past due', 'Oldest was due ' . fmt_date($r['oldest']) . '.', '/clients/' . (int) $r['client_id'] . '/compliance', $names[$r['client_id']]);
            }
        }
        foreach (DB::all("SELECT cf.client_id, f.name FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.next_review IS NOT NULL AND cf.next_review < CURDATE()") as $r) {
            if (isset($names[$r['client_id']])) {
                $add('info', 'compliance', 'Review due: ' . $r['name'], 'The framework\'s review date has passed.', '/clients/' . (int) $r['client_id'] . '/compliance', $names[$r['client_id']]);
            }
        }

        // Lifecycle: devices past end of life that aren't planned (one line per client)
        $per = [];
        foreach ($ctx['devices'] ?? [] as $d) {
            if ($d['status'] === 'replace' && empty($d['replace_planned']) && isset($names[$d['client_id']])) {
                $per[$d['client_id']] = ($per[$d['client_id']] ?? 0) + 1;
            }
        }
        arsort($per);
        foreach ($per as $cid => $n) {
            $add('warn', 'lifecycle', $n . ' device' . ($n === 1 ? '' : 's') . ' past end of life', 'Replace them, or set a replacement quarter if the client wants to wait.', '/clients/' . (int) $cid . '/devices?filter=replace', $names[$cid]);
        }

        $rank = ['bad' => 0, 'warn' => 1, 'info' => 2];
        $cat = array_flip(array_keys(self::CATEGORIES));
        usort($items, fn($a, $b) => [$rank[$a['tone']], $cat[$a['cat']], (string) $a['client']] <=> [$rank[$b['tone']], $cat[$b['cat']], (string) $b['client']]);
        return $items;
    }

    // ---- Portfolio health tiles -------------------------------------------------------------

    /**
     * Four groups of tiles: [title, icon, [[value, label, sub, tone, link], ...]]
     * @param array $ctx summary, overdueCount, upcomingCount, complianceAvg, forecast, planning, contractDates90, sla (or null)
     */
    public static function kpis(array $ctx): array
    {
        $s = $ctx['summary'];
        $groups = [];

        // Service & backups
        $tiles = [];
        if ($sla = $ctx['sla']) {
            $t = $sla['total'];
            $tiles[] = [Sla::pct($t['overall_pct']), 'SLA targets met', '90 days · goal ' . $sla['target'] . '%', ['success' => 'ok', 'warning' => 'warn', 'danger' => 'bad', 'secondary' => 'muted'][Sla::tone($t['overall_pct'])], '/reports/sla'];
            $tiles[] = [(string) $sla['open']['breached'], 'Open past target', $sla['open']['warning'] . ' close to target', $sla['open']['breached'] ? 'bad' : ($sla['open']['warning'] ? 'warn' : 'ok'), '/reports/sla'];
        }
        if (\Align\Backup\Backup::enabled()) {
            $sum = \Align\Backup\Backup::summaries();
            $failed = array_sum(array_column($sum, 'failed'));
            $overdue = array_sum(array_column($sum, 'overdue'));
            $runs = array_sum(array_column($sum, 'runs'));
            $ok = array_sum(array_column($sum, 'runs_ok'));
            $tiles[] = [(string) $failed, 'Failed backup jobs', count(array_filter($sum, fn($x) => $x['failed'])) . ' client(s)', $failed ? 'bad' : 'ok', '/reports/backups'];
            $tiles[] = [$runs ? (int) floor($ok / $runs * 100) . '%' : '—', 'Backup success', $overdue ? $overdue . ' item(s) overdue' : '30 days, all clients', $runs ? ($ok / $runs >= .95 ? 'ok' : ($ok / $runs >= .8 ? 'warn' : 'bad')) : 'muted', '/reports/backups'];
        }
        if ($tiles) {
            $groups[] = ['Service & backups', 'fa-life-ring', array_slice($tiles, 0, 4)];
        }

        // Lifecycle & security
        $ca = $ctx['complianceAvg'];
        $groups[] = ['Lifecycle & security', 'fa-shield-halved', [
            [number_format($s['replace']), 'Replace now', number_format($s['total']) . ' devices tracked', $s['replace'] ? 'bad' : 'ok', '/clients'],
            [number_format($s['os_eos']), 'Unsupported OS', $s['os_soon'] ? $s['os_soon'] . ' losing support soon' : 'no end dates coming up', $s['os_eos'] ? 'bad' : ($s['os_soon'] ? 'warn' : 'ok'), '/clients'],
            [number_format($s['warranty_soon']), 'Warranty expiring', number_format($s['warranty_expired']) . ' already expired', $s['warranty_soon'] ? 'warn' : 'ok', '/clients'],
            [$ca === null ? '—' : $ca . '%', 'Avg compliance', $ctx['complianceCount'] . ' framework assignment' . ($ctx['complianceCount'] == 1 ? '' : 's'), $ca === null ? 'muted' : ($ca >= 80 ? 'ok' : ($ca >= 50 ? 'warn' : 'bad')), '/compliance'],
        ]];

        // Client engagement
        $pl = $ctx['planning'];
        $done = count(array_filter($pl, fn($p) => $p['done'] >= $p['total']));
        $proposed = (int) DB::value("SELECT COUNT(*) FROM roadmap_items r JOIN clients c ON c.id = r.client_id AND c.is_archived = 0 AND c.planning_excluded = 0 WHERE r.status = 'proposed'");
        $groups[] = ['Client engagement', 'fa-handshake', [
            [(string) $ctx['overdueCount'], 'Due for a meeting', 'past cadence, nothing booked', $ctx['overdueCount'] ? 'warn' : 'ok', '/meetings'],
            [(string) $ctx['upcomingCount'], 'Meetings next 30 days', 'scheduled', 'muted', '/calendar'],
            [(string) $proposed, 'Awaiting decision', 'proposed projects', $proposed ? 'info' : 'muted', '/projects'],
            [$done . '/' . count($pl), 'Planning complete', 'clients with every step done', count($pl) && $done === count($pl) ? 'ok' : 'muted', '/clients'],
        ]];

        // Money
        $cur = \Align\Roadmap\Plan::quarters()[\Align\Roadmap\Plan::currentIndex()]['year'];
        $yt = Lifecycle::yearTotals($ctx['forecast'])[$cur];
        $ren = $ctx['contractDates90'];
        $renAnnual = array_sum(array_column($ren, 'annual'));
        $lic = \Align\Licensing\Licenses::totals(\Align\Licensing\Licenses::load(null));
        $groups[] = ['Money', 'fa-coins', [
            [(string) count($ren), 'Renewals in 90 days', $renAnnual > 0 ? money($renAnnual) . '/yr up for renewal' : 'contracts & licenses', count($ren) ? 'warn' : 'ok', '/renewals?days=90'],
            [\Align\Reports\Ui::k($yt['cost']), 'Hardware ' . $yt['label'], $yt['count'] . ' replacement' . ($yt['count'] == 1 ? '' : 's'), 'muted', '/budget'],
            [\Align\Reports\Ui::k($yt['proj_cost']), 'Projects ' . $yt['label'], $yt['proj_count'] . ' planned', 'muted', '/projects'],
            [\Align\Reports\Ui::k($lic['monthly']), 'Licenses per month', $lic['unpriced'] ? $lic['unpriced'] . ' without a price' : number_format($lic['count']) . ' licenses', $lic['unpriced'] ? 'warn' : 'muted', '/licenses'],
        ]];
        return $groups;
    }
}
