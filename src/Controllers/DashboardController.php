<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\Compliance\Compliance;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Meetings\Meetings;
use Align\View;

final class DashboardController
{
    public static function index(): void
    {
        Auth::require();
        // A new install: admins go to the setup wizard once per sign-in until someone finishes or skips it
        if (Auth::can('admin') && SetupController::pending() && empty($_SESSION['setup_offered'])) {
            $_SESSION['setup_offered'] = 1;
            redirect('/setup');
        }
        $lc = new Lifecycle();
        $devices = array_filter($lc->devices(), fn($d) => $d['client_id'] !== null && !$d['client_inactive']);
        $summary = Lifecycle::summarize($devices);
        $forecast = \Align\Roadmap\Roadmap::withProjects($lc->forecast($devices));
        $unplanned = Lifecycle::unplanned($devices);

        $byClient = [];
        foreach ($devices as $d) {
            $c = &$byClient[$d['client_id']];
            $c ??= ['id' => $d['client_id'], 'name' => $d['client_name'], 'total' => 0, 'replace' => 0, 'attention' => 0];
            $c['total']++;
            if (in_array('replace', $d['flags'], true) || in_array('os_eos', $d['flags'], true)) {
                $c['replace']++;
            }
            if (in_array($d['status_tone'], ['bad', 'warn'], true)) {
                $c['attention']++;
            }
            unset($c);
        }
        usort($byClient, fn($a, $b) => [$b['replace'], $b['attention']] <=> [$a['replace'], $a['attention']]);

        $cadence = Meetings::cadence();
        $names = array_column(DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0'), 'name', 'id');
        $overdue = [];
        foreach ($cadence as $cid => $c) {
            if ($c['overdue'] && isset($names[$cid])) {
                $overdue[] = ['id' => $cid, 'name' => $names[$cid], 'last' => $c['last'], 'due' => $c['due']];
            }
        }
        usort($overdue, fn($a, $b) => strcmp((string) $a['last'], (string) $b['last']));

        $scores = [];
        foreach (array_intersect_key(Compliance::allScores(), $names) as $fws) {
            foreach ($fws as $s) {
                $scores[] = $s['score'];
            }
        }

        // Planning checklist per client (least complete first)
        $devsBy = [];
        foreach ($devices as $d) {
            $devsBy[$d['client_id']][] = $d;
        }
        $planning = [];
        $active = DB::all('SELECT * FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name');
        \Align\Workflow\Readiness::prefetch(array_column($active, 'id'));
        foreach ($active as $c) {
            $r = \Align\Workflow\Readiness::client($c, $devsBy[$c['id']] ?? []);
            $next = array_values(array_filter($r['steps'], fn($s) => $s['ok'] === false))[0] ?? null;
            $planning[] = ['client' => $c, 'done' => $r['done'], 'total' => $r['total'], 'next' => $next];
        }
        usort($planning, fn($a, $b) => [$a['done'] / max(1, $a['total']), $a['client']['name']] <=> [$b['done'] / max(1, $b['total']), $b['client']['name']]);
        $layout = \Align\Dashboard\Dashboard::layout();
        $show = array_flip(\Align\Dashboard\Dashboard::visible($layout));
        $lastSync = DB::one('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1');
        $unmapped = \Align\Providers\Providers::anyRmm()
            ? (int) DB::value("SELECT COUNT(*) FROM clients c WHERE NOT " . \Align\Providers\ClientLinks::rmmLinkedSql() . " AND c.is_archived = 0 AND c.planning_excluded = 0") : 0;
        $unassigned = (int) DB::value('SELECT COUNT(*) FROM devices d ' . Lifecycle::CLIENT_JOIN . ' WHERE d.removed_at IS NULL AND cm.id IS NULL AND cn.id IS NULL');
        $contract90 = isset($show['renewals']) || isset($show['kpis']) ? \Align\Budget\Contracts::upcoming(null, 90, date('Y-m-d')) : [];
        $sla = isset($show['sla']) || isset($show['kpis']) ? self::slaSummary() : null;
        $complianceAvg = $scores ? (int) round(array_sum($scores) / count($scores)) : null;
        $upcomingCount = (int) DB::value("SELECT COUNT(*) FROM meetings WHERE status = 'scheduled' AND starts_at >= NOW() AND starts_at < ?", [date('Y-m-d', strtotime('+30 days'))]);
        View::render('dashboard', [
            'layout' => $layout,
            'show' => $show,
            'planning' => $planning,
            'setup' => \Align\Workflow\Readiness::setup(),
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'summary' => $summary,
            'forecast' => $forecast,
            'unplanned' => $unplanned,
            'contractDates' => array_values(array_filter(\Align\Budget\Contracts::upcoming(null, 90), fn($d) => $d['urgency'] !== 'later')),
            'topClients' => array_slice(array_filter($byClient, fn($c) => $c['attention'] > 0), 0, 8),
            'lastSync' => $lastSync,
            'upcoming' => DB::all("SELECT m.*, c.name AS client_name FROM meetings m LEFT JOIN clients c ON c.id = m.client_id
                WHERE m.status = 'scheduled' AND m.starts_at >= NOW() AND m.starts_at < ? ORDER BY m.starts_at LIMIT 8", [date('Y-m-d', strtotime('+30 days'))]),
            'upcomingCount' => $upcomingCount,
            // Things clients did in the portal that staff should act on (last 30 days)
            'clientActivity' => DB::all("SELECT a.action, a.detail, a.created_at, p.name AS portal_name, p.client_id, c.name AS client_name FROM audit_log a
                JOIN portal_users p ON p.id = a.portal_user_id JOIN clients c ON c.id = p.client_id
                WHERE a.action IN ('portal.project_approved','portal.project_declined','portal.submission','portal.contact_added','portal.contact_updated','portal.contact_removed')
                AND a.created_at >= ? ORDER BY a.id DESC LIMIT 8", [date('Y-m-d', strtotime('-30 days'))]),
            'overdueMeetings' => array_slice($overdue, 0, 8),
            'overdueCount' => count($overdue),
            'clientCount' => count($names),
            'backupIssues' => isset($show['backups']) ? self::backupIssues() : [],
            'sla' => $sla,
            'attention' => isset($show['attention']) ? \Align\Dashboard\Dashboard::attention([
                'devices' => $devices, 'overdue' => $overdue, 'lastSync' => $lastSync, 'unmapped' => $unmapped, 'unassigned' => $unassigned,
            ]) : [],
            'kpis' => isset($show['kpis']) ? \Align\Dashboard\Dashboard::kpis([
                'summary' => $summary, 'overdueCount' => count($overdue), 'upcomingCount' => $upcomingCount, 'complianceAvg' => $complianceAvg,
                'complianceCount' => count($scores), 'forecast' => $forecast, 'planning' => $planning, 'contractDates90' => $contract90, 'sla' => $sla,
            ]) : [],
        ]);
    }

    /** Saves the signed-in user's dashboard layout (POST layout = JSON {order:{top,main,side}, hidden:[]}, or reset=1). */
    public static function saveLayout(): void
    {
        $u = Auth::require();
        header('Content-Type: application/json');
        $in = json_decode(post('layout'), true);
        if (!post('reset') && !is_array($in)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid layout.']);
            return;
        }
        \Align\Dashboard\Dashboard::save((int) $u['id'], post('reset') ? null : $in);
        echo json_encode(['ok' => true]);
    }

    /** Service levels across clients for the last 90 days, or null when SLA reporting isn't available. */
    private static function slaSummary(): ?array
    {
        if (!\Align\Service\Sla::enabled() || \Align\Service\Sla::supported() === false || !DB::value('SELECT 1 FROM psa_tickets LIMIT 1')) {
            return null;
        }
        [$from, $to] = \Align\Service\Sla::range('90');
        $target = \Align\Service\Sla::target();
        $clients = array_filter(\Align\Service\Sla::allClients($from, $to), fn($c) => $c['breached_open'] || ($c['overall_pct'] !== null && $c['overall_pct'] < $target));
        return ['total' => \Align\Service\Sla::stats(null, $from, $to), 'open' => \Align\Service\Sla::openCounts(null), 'clients' => $clients, 'target' => $target];
    }

    /** Clients whose backups have a failed job or an overdue machine, worst first. */
    private static function backupIssues(): array
    {
        if (!\Align\Backup\Backup::enabled()) {
            return [];
        }
        $names = array_column(DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0'), 'name', 'id');
        $out = [];
        foreach (\Align\Backup\Backup::summaries() as $id => $x) {
            if (isset($names[$id]) && ($x['failed'] || $x['overdue'] || $x['warning'])) {
                $out[] = $x + ['name' => $names[$id]];
            }
        }
        usort($out, fn($a, $b) => [$b['failed'], $b['overdue'], $b['warning']] <=> [$a['failed'], $a['overdue'], $a['warning']]);
        return $out;
    }
}
