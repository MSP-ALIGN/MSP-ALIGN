<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\BackupConnector;
use Align\Providers\Backup\BackupProvider;
use Align\Providers\Backup\VeeamBackup;

/**
 * Veeam Service Provider Console as a backup source (Integrations\VeeamSpc does the calls, Providers\Backup\
 * VeeamBackup maps the data).
 *
 * Security assumptions: as Connector. The console URL is a url field (https, checked on save and on every request)
 * and moving it to another server needs the API key typed again (1.45).
 */
final class Veeam extends BackupConnector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'veeam'; }
    /** Name on the card and page. */
    public function name(): string { return 'Veeam Service Provider Console'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-database'; }
    /** Short name for sentences and the mapping column. */
    public function shortName(): string { return 'Veeam'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Backup jobs and results, protected machines, Microsoft 365 backups and Cloud Connect storage for each client.'; }

    /** Set up when the console URL and API key are saved. */
    public function configured(): bool
    {
        return \Align\Integrations\VeeamSpc::configured();
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In VSPC open <b>Configuration → Security → REST API Keys</b> and create a key for a <b>read-only</b> portal administrator.</li>'
            . '<li>Enter the portal address (the REST API is <code>/api/v3</code> on the same host). Its certificate must be trusted by this server.</li>'
            . '<li>Save, press <b>Test</b>, run a sync, then check the Veeam column on <a href="/mapping">Client mapping</a>.</li>'
            . '<li>Host clients\' servers and back them up on your own Veeam server? Those are matched to clients by device name; review and fix them under <a href="/mapping/backups">Hosted backups</a>.</li></ol>';
    }

    /** Console URL (url), API key (secret) and two thresholds for the backup pages. */
    public function fields(): array
    {
        return [
            ['name' => 'veeam_url', 'label' => 'Console URL', 'type' => 'url', 'placeholder' => 'https://vspc.example.com'],
            ['name' => 'veeam_api_key', 'label' => 'API key', 'type' => 'secret'],
            ['name' => 'backup_stale_hours', 'label' => 'Flag a machine as overdue when its newest restore point is older than', 'type' => 'number', 'min' => 1, 'max' => 720, 'suffix' => 'hours', 'default' => '48'],
            ['name' => 'm365_retired_days', 'label' => 'Treat a Microsoft 365 user, group, team or site as no longer backed up (its old backups kept, not counted) after no new backup for', 'type' => 'number', 'min' => 0, 'max' => 3650, 'suffix' => 'days (0 = never)', 'default' => '30'],
        ];
    }

    /** The Veeam provider from the saved settings. Throws when not set up. */
    public function provider(): BackupProvider
    {
        return VeeamBackup::fromSettings();
    }
}
