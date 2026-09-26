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
        foreach (DB::all('SELECT * FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name') as $c) {
            $r = \Align\Workflow\Readiness::client($c, $devsBy[$c['id']] ?? []);
            $next = array_values(array_filter($r['steps'], fn($s) => $s['ok'] === false))[0] ?? null;
            $planning[] = ['client' => $c, 'done' => $r['done'], 'total' => $r['total'], 'next' => $next];
        }
        usort($planning, fn($a, $b) => [$a['done'] / max(1, $a['total']), $a['client']['name']] <=> [$b['done'] / max(1, $b['total']), $b['client']['name']]);
        View::render('dashboard', [
            'planning' => $planning,
            'setup' => \Align\Workflow\Readiness::setup(),
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'summary' => $summary,
            'forecast' => $forecast,
            'unplanned' => $unplanned,
            'contractDates' => array_values(array_filter(\Align\Budget\Contracts::upcoming(null, 90), fn($d) => $d['urgency'] !== 'later')),
            'topClients' => array_slice(array_filter($byClient, fn($c) => $c['attention'] > 0), 0, 8),
            'lastSync' => DB::one('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1'),
            'unmapped' => (int) DB::value("SELECT COUNT(*) FROM clients WHERE ninja_org_id IS NULL AND is_archived = 0 AND planning_excluded = 0 AND source = 'itflow'"),
            'unassigned' => (int) DB::value('SELECT COUNT(*) FROM devices d ' . Lifecycle::CLIENT_JOIN . ' WHERE d.removed_at IS NULL AND cm.id IS NULL AND cn.id IS NULL'),
            'configured' => DB::value("SELECT COUNT(*) FROM settings WHERE name IN ('ninja_client_secret','itflow_api_key')") == 2,
            'upcoming' => DB::all("SELECT m.*, c.name AS client_name FROM meetings m LEFT JOIN clients c ON c.id = m.client_id
                WHERE m.status = 'scheduled' AND m.starts_at >= NOW() AND m.starts_at < ? ORDER BY m.starts_at LIMIT 8", [date('Y-m-d', strtotime('+30 days'))]),
            // Things clients did in the portal that staff should act on (last 30 days)
            'clientActivity' => DB::all("SELECT a.action, a.detail, a.created_at, p.name AS portal_name, p.client_id, c.name AS client_name FROM audit_log a
                JOIN portal_users p ON p.id = a.portal_user_id JOIN clients c ON c.id = p.client_id
                WHERE a.action IN ('portal.project_approved','portal.project_declined','portal.contact_added','portal.contact_updated','portal.contact_removed')
                AND a.created_at >= ? ORDER BY a.id DESC LIMIT 8", [date('Y-m-d', strtotime('-30 days'))]),
            'overdueMeetings' => array_slice($overdue, 0, 8),
            'overdueCount' => count($overdue),
            'clientCount' => count($names),
            'complianceAvg' => $scores ? (int) round(array_sum($scores) / count($scores)) : null,
            'complianceCount' => count($scores),
            'backupIssues' => self::backupIssues(),
        ]);
    }

    /** Clients whose Veeam backups have a failed job or an overdue machine, worst first. */
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
