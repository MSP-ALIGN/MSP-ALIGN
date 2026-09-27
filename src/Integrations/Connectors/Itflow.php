<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\Connector;
use Align\Settings;

final class Itflow extends Connector
{
    public function key(): string { return 'itflow'; }
    public function name(): string { return 'ITFlow'; }
    public function icon(): string { return 'fas fa-screwdriver-wrench'; }
    public function category(): string { return 'PSA & documentation'; }
    public function summary(): string { return 'Clients, locations, contacts, assets, software licenses, invoices and ticket SLAs. Assets and contacts sync both ways.'; }
    public function direction(): string { return Settings::get('itflow_two_way', '1') === '1' ? 'Two-way' : 'Read only'; }
    public function syncSteps(): array { return ['ITFlow', 'Managed-services', 'Write warranty']; }
    public function hasTest(): bool { return true; }

    public function configured(): bool
    {
        return (string) Settings::get('itflow_url') !== '' && Settings::hasSecret('itflow_api_key');
    }

    public function setup(): string
    {
        return '<ol class="pl-3 mb-0"><li>In ITFlow open <b>Admin → API Keys → Create</b>. The key runs as the user you choose.</li>'
            . '<li>That user needs read access to Clients, Contacts, Locations, Software, Vendors, Invoices and Support (assets and tickets, for SLA reporting), and write access to Support and Contacts for two-way sync and warranty write-back.</li>'
            . '<li>Enter the ITFlow address and key, save, then press <b>Test</b> and run a sync.</li></ol>';
    }

    public function fields(): array
    {
        return [
            ['name' => 'itflow_url', 'label' => 'ITFlow URL', 'type' => 'url', 'placeholder' => 'https://itflow.example.com'],
            ['name' => 'itflow_api_key', 'label' => 'API key', 'type' => 'secret'],
            ['name' => 'itflow_two_way', 'label' => 'Two-way asset sync', 'type' => 'switch', 'default' => '1',
                'help' => 'Edits in Align go to ITFlow straight away; ITFlow edits come in every 2 minutes. If both sides change the same field, the newest edit wins and the other is kept in the device\'s sync history. Nothing is ever deleted by sync.'],
            ['name' => 'itflow_create_assets', 'label' => 'Create devices added in Align as ITFlow assets (for clients linked to ITFlow)', 'type' => 'switch', 'default' => '1'],
            ['name' => 'itflow_import_types', 'label' => 'Bring in ITFlow assets that NinjaOne doesn\'t manage', 'type' => 'checkboxes', 'options' => \Align\Integrations\Itflow::IMPORT_CATEGORIES,
                'help' => 'Assets already matched to a NinjaOne or hand-added device (by serial or name) are skipped. Types Align doesn\'t recognize go to Unassigned hardware.'],
            ['name' => 'itflow_sla_sync', 'label' => 'Service levels: bring in ticket SLA results', 'type' => 'switch', 'default' => '1',
                'help' => 'Needs ITFlow 26.08 or later with SLAs set up (Admin → SLAs). Align copies each ticket\'s number, subject, priority and SLA times and results, never the ticket body, and reports response and resolution SLA performance per client.'],
            ['name' => 'sla_target', 'label' => 'SLA goal (share of targets met)', 'type' => 'number', 'min' => 50, 'max' => 100, 'default' => '90', 'suffix' => '%',
                'help' => 'At or above the goal shows green, up to 10 points below shows amber, lower shows red.'],
            ['name' => 'itflow_writeback', 'label' => 'Write warranty dates back to ITFlow assets', 'type' => 'select', 'default' => 'off',
                'options' => ['off' => 'Off (read only)', 'fill_empty' => 'Fill empty fields only', 'overwrite' => 'Keep ITFlow in sync (overwrite)']],
        ];
    }

    public function notes(): string
    {
        $p = \Align\DB::one('SELECT * FROM itflow_poll_state WHERE id = 1');
        $out = $p ? 'Last 2-minute ITFlow check: ' . ($p['last_run'] ? e(rel_time($p['last_run'])) . ' · ' . e((string) $p['last_result']) : 'not run yet') : '';
        if (Settings::get('itflow_sla_sync', '1') === '1') {
            $n = (int) \Align\DB::value('SELECT COUNT(*) FROM itflow_tickets');
            $sup = \Align\Service\Sla::supported();
            $out .= '<br>Tickets for SLA reporting: ' . number_format($n)
                . (($l = \Align\Service\Sla::lastSync()) ? ' · last read ' . e(rel_time($l)) : ' · not read yet (runs with the hourly sync)')
                . ($sup === false ? ' · <span class="text-danger">this ITFlow has no SLA fields; update it to 26.08 or later</span>' : '');
        }
        return $out;
    }

    public function test(): string
    {
        return \Align\Integrations\Itflow::fromSettings()->test();
    }
}
