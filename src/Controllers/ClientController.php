<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Settings;
use Align\View;

final class ClientController
{
    public static function index(): void
    {
        Auth::require();
        $lc = new Lifecycle();
        $stats = [];
        foreach ($lc->devices() as $d) {
            if ($d['client_id'] === null) {
                continue;
            }
            $s = &$stats[$d['client_id']];
            $s ??= ['total' => 0, 'bad' => 0, 'warn' => 0, 'cost12' => 0.0];
            $s['total']++;
            if ($d['status_tone'] === 'bad') {
                $s['bad']++;
            } elseif ($d['status_tone'] === 'warn') {
                $s['warn']++;
            }
            if ($d['replace_by'] && $d['replace_by'] <= date('Y-m-d', strtotime('+12 months'))) {
                $s['cost12'] += $d['replacement_cost'];
            }
            unset($s);
        }
        $showArchived = query('archived') === '1';
        $clients = DB::all('SELECT c.*, o.name AS org_name FROM clients c LEFT JOIN ninja_orgs o ON o.id = c.ninja_org_id'
            . ($showArchived ? '' : ' WHERE c.is_archived = 0') . ' ORDER BY c.name');
        View::render('clients/index', [
            'title' => 'Clients',
            'nav' => 'clients',
            'clients' => $clients,
            'stats' => $stats,
            'showArchived' => $showArchived,
            'q' => query('q'),
        ]);
    }

    private static function load(int $id): array
    {
        $client = DB::one('SELECT c.*, o.name AS org_name FROM clients c LEFT JOIN ninja_orgs o ON o.id = c.ninja_org_id WHERE c.id = ?', [$id]);
        if (!$client) {
            http_response_code(404);
            View::render('error', ['title' => 'Client not found', 'message' => 'That client does not exist.']);
            exit;
        }
        return $client;
    }

    private static function filter(array $devices, string $filter, string $class): array
    {
        return array_values(array_filter($devices, function ($d) use ($filter, $class) {
            if ($class !== '' && $d['device_class'] !== $class) {
                return false;
            }
            return match ($filter) {
                'attention' => in_array($d['status_tone'], ['bad', 'warn'], true),
                'replace' => in_array('replace', $d['flags'], true) || in_array('plan', $d['flags'], true),
                'os' => (bool) array_intersect(['os_eos', 'os_soon'], $d['flags']),
                'warranty' => (bool) array_intersect(['warranty_expired', 'warranty_soon'], $d['flags']) || ($d['is_hardware'] && !$d['warranty_end']),
                'stale' => $d['stale'],
                default => true,
            };
        }));
    }

    public static function show(int $id): void
    {
        Auth::require();
        $client = self::load($id);
        $lc = new Lifecycle();
        $all = $lc->devices($id);
        $filter = query('filter');
        $class = query('class');
        View::render('clients/show', [
            'title' => $client['name'],
            'nav' => 'clients',
            'client' => $client,
            'devices' => self::filter($all, $filter, $class),
            'summary' => Lifecycle::summarize($all),
            'forecast' => $lc->forecast($all),
            'filter' => $filter,
            'class' => $class,
            'itflowUrl' => Settings::get('itflow_url'),
        ]);
    }

    public static function export(int $id): void
    {
        Auth::require();
        $client = self::load($id);
        $devices = (new Lifecycle())->devices($id);
        Audit::log('client.export', $client['name']);
        $fname = preg_replace('/[^A-Za-z0-9]+/', '-', $client['name']) . '-lifecycle-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Device', 'Type', 'Manufacturer', 'Model', 'Serial', 'OS', 'OS support ends', 'In service since',
            'Start date source', 'Age (years)', 'Warranty ends', 'Warranty source', 'End of life', 'Status',
            'Est. replacement cost', 'Last check-in', 'Notes'], escape: '');
        foreach ($devices as $d) {
            fputcsv($out, [
                $d['name'], $d['device_class'], $d['manufacturer'], $d['model'], $d['serial'], $d['os_name'],
                $d['os_rule']['eos_date'] ?? '', $d['start_date'], $d['start_source'], $d['age_years'],
                $d['warranty_end'], $d['warranty_source'], $d['eol_date'], $d['status_label'],
                $d['is_hardware'] ? $d['replacement_cost'] : '', $d['last_contact'], $d['o_notes'],
            ], escape: '');
        }
        fclose($out);
    }
}
