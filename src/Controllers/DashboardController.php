<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\View;

final class DashboardController
{
    public static function index(): void
    {
        Auth::require();
        $lc = new Lifecycle();
        $devices = array_filter($lc->devices(), fn($d) => $d['client_id'] !== null);
        $summary = Lifecycle::summarize($devices);
        $forecast = $lc->forecast($devices);

        $byClient = [];
        foreach ($devices as $d) {
            $c = &$byClient[$d['client_id']];
            $c ??= ['id' => $d['client_id'], 'name' => $d['client_name'], 'devices' => [], 'replace' => 0, 'attention' => 0];
            $c['devices'][] = $d;
            if (in_array('replace', $d['flags'], true) || in_array('os_eos', $d['flags'], true)) {
                $c['replace']++;
            }
            if ($d['status_tone'] !== 'ok' && $d['status_tone'] !== 'muted') {
                $c['attention']++;
            }
            unset($c);
        }
        foreach ($byClient as &$c) {
            $c['total'] = count($c['devices']);
            unset($c['devices']);
        }
        unset($c);
        usort($byClient, fn($a, $b) => [$b['replace'], $b['attention']] <=> [$a['replace'], $a['attention']]);

        View::render('dashboard', [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'summary' => $summary,
            'forecast' => $forecast,
            'topClients' => array_slice(array_filter($byClient, fn($c) => $c['attention'] > 0), 0, 10),
            'lastSync' => DB::one('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1'),
            'unmapped' => (int) DB::value('SELECT COUNT(*) FROM clients WHERE ninja_org_id IS NULL AND is_archived = 0'),
            'unassigned' => (int) DB::value('SELECT COUNT(*) FROM devices d LEFT JOIN clients c ON c.ninja_org_id = d.ninja_org_id WHERE d.removed_at IS NULL AND c.id IS NULL'),
            'configured' => DB::value("SELECT COUNT(*) FROM settings WHERE name IN ('ninja_client_secret','itflow_api_key')") == 2,
        ]);
    }
}
