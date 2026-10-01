<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\BackupConnector;
use Align\Providers\Backup\BackupProvider;
use Align\Providers\Backup\VeeamBackup;

final class Veeam extends BackupConnector
{
    public function key(): string { return 'veeam'; }
    public function name(): string { return 'Veeam Service Provider Console'; }
    public function icon(): string { return 'fas fa-database'; }
    public function shortName(): string { return 'Veeam'; }
    public function summary(): string { return 'Backup jobs and results, protected machines, Microsoft 365 backups and Cloud Connect storage for each client.'; }

    public function configured(): bool
    {
        return \Align\Integrations\VeeamSpc::configured();
    }

    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In VSPC open <b>Configuration → Security → REST API Keys</b> and create a key for a <b>read-only</b> portal administrator.</li>'
            . '<li>Enter the portal address (the REST API is <code>/api/v3</code> on the same host). Its certificate must be trusted by this server.</li>'
            . '<li>Save, press <b>Test</b>, run a sync, then check the Veeam column on <a href="/mapping">Client mapping</a>.</li>'
            . '<li>Host clients\' servers and back them up on your own Veeam server? Those are matched to clients by device name; review and fix them under <a href="/mapping/backups">Hosted backups</a>.</li></ol>';
    }

    public function fields(): array
    {
        return [
            ['name' => 'veeam_url', 'label' => 'Console URL', 'type' => 'url', 'placeholder' => 'https://vspc.example.com'],
            ['name' => 'veeam_api_key', 'label' => 'API key', 'type' => 'secret'],
            ['name' => 'backup_stale_hours', 'label' => 'Flag a machine as overdue when its newest restore point is older than', 'type' => 'number', 'min' => 1, 'max' => 720, 'suffix' => 'hours', 'default' => '48'],
            ['name' => 'm365_retired_days', 'label' => 'Treat a Microsoft 365 user, group, team or site as no longer backed up (its old backups kept, not counted) after no new backup for', 'type' => 'number', 'min' => 0, 'max' => 3650, 'suffix' => 'days (0 = never)', 'default' => '30'],
        ];
    }

    public function provider(): BackupProvider
    {
        return VeeamBackup::fromSettings();
    }
}
