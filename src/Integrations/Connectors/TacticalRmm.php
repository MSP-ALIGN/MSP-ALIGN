<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\RmmConnector;
use Align\Providers\Rmm\RmmProvider;
use Align\Providers\Rmm\TacticalRmm as Provider;
use Align\Settings;

/**
 * 2.11.0 Tactical RMM as an RMM (Providers\Rmm\TacticalRmm does the calls and maps the data). Beta: built from
 * Tactical's API and source, not yet confirmed against many installs; it only reads.
 *
 * Security assumptions: as Connector. The API address is a url field (https, checked on save and on every request);
 * the key is a secret field, encrypted and never shown again. Tactical API keys skip Tactical's two-factor sign-in, so
 * the setup steps ask for a key on a user with a read-only role.
 */
final class TacticalRmm extends RmmConnector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'tacticalrmm'; }
    /** Name on the card and page. */
    public function name(): string { return 'Tactical RMM'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-satellite-dish'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Beta. Workstations and servers from your Tactical RMM server: hardware, serial numbers, OS, last check-in and logged-in user.'; }

    /** Set up when the API address and key are saved. */
    public function configured(): bool
    {
        return (string) Settings::get('trmm_url') !== '' && Settings::hasSecret('trmm_api_key');
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In Tactical RMM, make a user for Align with a role that has only <b>List clients</b> and <b>List agents</b> (Settings → Permissions Manager), covering <b>every client</b>: agents the key can\'t see would be marked removed here. API keys skip two-factor sign-in, so keep this user read-only.</li>'
            . '<li>Open <b>Settings → Global Settings → API Keys</b>, add a key for that user and copy it here.</li>'
            . '<li>Enter the <b>API address</b>, the one the agents report to (often <code>https://api.your-domain</code>), not the dashboard\'s. Its certificate must be trusted by this server.</li>'
            . '<li>Save, press <b>Test</b>, run a sync, then link Tactical clients to Align clients on <a href="/mapping">Client mapping</a> (matching names link automatically).</li>'
            . '<li>This integration is in beta and only reads from Tactical. Something look wrong? Report it on GitHub with the field names (never the values) from one agent.</li></ol>';
    }

    /** API address (url) and API key (secret). */
    public function fields(): array
    {
        return [
            ['name' => 'trmm_url', 'label' => 'API address', 'type' => 'url', 'placeholder' => 'https://api.example.com'],
            ['name' => 'trmm_api_key', 'label' => 'API key', 'type' => 'secret'],
        ];
    }

    /** The Tactical RMM provider from the saved settings ($forLinks: no key needed). */
    public function provider(bool $forLinks = false): RmmProvider
    {
        return Provider::fromSettings($forLinks);
    }
}
