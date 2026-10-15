<?php
declare(strict_types=1);

namespace Align\Templates\Backup\Connector;

use Align\Integrations\BackupConnector;
use Align\Providers\Backup\BackupProvider;
use Align\Settings;
use Align\Templates\Backup\FabrikamBackup as Provider;

/**
 * Fabrikam Backup as a backup source (beta, community, read-only): the card and setup page on the Integrations page.
 * The provider class does the calls and the mapping; BackupConnector adds the sync step, the connection test and the
 * Client mapping column, and the backup pages show its jobs and machines like any other product's.
 *
 * template-only: begin
 * TEMPLATE (2.22.0): `align make:connector backup "Name"` copies this to src/Integrations/Connectors/<Name>.php and
 * adds it to Integrations\Registry. See docs/CONNECTORS.md.
 * template-only: end
 *
 * Security assumptions: as Connector (admin-only form). The API address is a url field (https, checked on save and on
 * every request; moving it needs the key typed again); the key is a secret field, encrypted and never shown again.
 */
final class FabrikamBackup extends BackupConnector
{
    /** Slug for the URL, the Registry and every stored record. Never change it once released. */
    public function key(): string { return 'fabrikambackup'; }
    /** Name on the card and page. */
    public function name(): string { return 'Fabrikam Backup'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-box-archive'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Beta (community). Backup jobs and results and protected computers from Fabrikam Backup.'; }

    /** Set up when the address and key are saved. */
    public function configured(): bool
    {
        return (string) Settings::get('fabrikambackup_url') !== '' && Settings::hasSecret('fabrikambackup_api_key');
    }

    /** Setup steps (trusted HTML: fixed text only). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In Fabrikam Backup, create an API key with <b>read-only</b> access to <b>every customer</b>: jobs and machines it can\'t see would be removed here.</li>'
            . '<li>Enter the API address and the key. Save, press <b>Test</b>, run a sync, then check its column on <a href="/mapping">Client mapping</a>.</li>'
            . '<li>This integration is in beta and only reads from Fabrikam Backup. Something look wrong? Report it on GitHub with the field names (never the values) from one record.</li></ol>';
    }

    /** API address (url) and API key (secret). */
    public function fields(): array
    {
        return [
            ['name' => 'fabrikambackup_url', 'label' => 'API address', 'type' => 'url', 'placeholder' => 'https://fabrikambackup.example.com'],
            ['name' => 'fabrikambackup_api_key', 'label' => 'API key', 'type' => 'secret'],
        ];
    }

    /** The provider from the saved settings. Throws when not set up. */
    public function provider(): BackupProvider
    {
        return Provider::fromSettings();
    }
}
