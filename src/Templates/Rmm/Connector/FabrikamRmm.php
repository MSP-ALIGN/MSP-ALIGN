<?php
declare(strict_types=1);

namespace Align\Templates\Rmm\Connector;

use Align\Integrations\RmmConnector;
use Align\Providers\Rmm\RmmProvider;
use Align\Settings;
use Align\Templates\Rmm\FabrikamRmm as Provider;

/**
 * Fabrikam RMM as an RMM: the card and setup page on the Integrations page (the provider class does the calls and
 * maps the data). Beta: community-contributed, it only reads. RmmConnector adds the sync step, the connection test
 * and the Client mapping column; RMM screens list its devices like any other RMM's.
 *
 * template-only: begin
 * TEMPLATE (2.22.0): `align make:connector rmm "Name"` copies this to src/Integrations/Connectors/<Name>.php and adds
 * it to Integrations\Registry. See docs/CONNECTORS.md.
 * template-only: end
 *
 * Security assumptions: as Connector (admin-only form). The API address is a url field (https, checked on save and on
 * every request; moving it to another server needs the key typed again); the key is a secret field, encrypted and
 * never shown again.
 */
final class FabrikamRmm extends RmmConnector
{
    /** Slug for the URL, the Registry and every stored record. Never change it once released. */
    public function key(): string { return 'fabrikamrmm'; }
    /** Name on the card and page. */
    public function name(): string { return 'Fabrikam RMM'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-desktop'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Beta (community). Workstations and servers from Fabrikam RMM: hardware, serial numbers, OS, last check-in and logged-in user.'; }

    /** Set up when the API address and key are saved. */
    public function configured(): bool
    {
        return (string) Settings::get('fabrikamrmm_url') !== '' && Settings::hasSecret('fabrikamrmm_api_key');
    }

    /** Setup steps (trusted HTML: fixed text only, nothing from settings or the product). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In Fabrikam RMM, create an API key with <b>read-only</b> access to <b>every organization</b>: devices the key can\'t see would be marked removed here.</li>'
            . '<li>Enter the API address and the key. The address\'s certificate must be trusted by this server.</li>'
            . '<li>Save, press <b>Test</b>, run a sync, then link organizations to Align clients on <a href="/mapping">Client mapping</a> (matching names link automatically).</li>'
            . '<li>This integration is in beta and only reads from Fabrikam RMM. Something look wrong? Report it on GitHub with the field names (never the values) from one device.</li></ol>';
    }

    /** API address (url) and API key (secret). */
    public function fields(): array
    {
        return [
            ['name' => 'fabrikamrmm_url', 'label' => 'API address', 'type' => 'url', 'placeholder' => 'https://fabrikamrmm.example.com'],
            ['name' => 'fabrikamrmm_api_key', 'label' => 'API key', 'type' => 'secret'],
        ];
    }

    /** The provider from the saved settings ($forLinks: no key needed). Throws when not set up. */
    public function provider(bool $forLinks = false): RmmProvider
    {
        return Provider::fromSettings($forLinks);
    }
}
