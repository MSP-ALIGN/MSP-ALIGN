<?php
declare(strict_types=1);

namespace Align\Templates\Psa\Connector;

use Align\Integrations\PsaConnector;
use Align\Providers\Psa\PsaProvider;
use Align\Settings;
use Align\Templates\Psa\FabrikamPsa as Provider;

/**
 * Fabrikam PSA as the PSA, read-only (beta, community): the card and setup page on the Integrations page. The
 * provider class does the calls and the mapping; PsaConnector adds the shared PSA settings this PSA can do, the sync
 * steps and the connection test. An install has one PSA: a second one set up shows "Not in use".
 *
 * template-only: begin
 * TEMPLATE (2.22.0): `align make:connector psa "Name"` copies this to src/Integrations/Connectors/<Name>.php and adds
 * it to Integrations\Registry. See docs/CONNECTORS.md.
 * template-only: end
 *
 * Security assumptions: as Connector (admin-only form). The API address is a url field (https, checked on save and on
 * every request; moving it needs the key typed again); the key is a secret field, encrypted and never shown again.
 */
final class FabrikamPsa extends PsaConnector
{
    /** Slug for the URL, the Registry and every stored record. Never change it once released. */
    public function key(): string { return 'fabrikampsa'; }
    /** Name on the card and page. */
    public function name(): string { return 'Fabrikam PSA'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-briefcase'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Beta (community). Clients, contacts and assets from Fabrikam PSA.'; }

    /** What it reads (see the provider's CAPS). */
    public function capabilities(): array
    {
        return Provider::CAPS;
    }

    /** The provider from the saved settings ($interactive: one short attempt). Throws when not set up. */
    public function provider(bool $interactive = false): PsaProvider
    {
        return Provider::fromSettings($interactive);
    }

    /** Set up when the address and key are saved. */
    public function configured(): bool
    {
        return (string) Settings::get('fabrikampsa_url') !== '' && Settings::hasSecret('fabrikampsa_api_key');
    }

    /** Read-only: assets come in with the hourly sync, so there's no 2-minute check. */
    public function polls(): bool
    {
        return false;
    }

    /** Setup steps (trusted HTML: fixed text only). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In Fabrikam PSA, create an API key with <b>read-only</b> access that can see <b>every client</b>: clients missing from its answer are archived here.</li>'
            . '<li>Enter the API address and the key. Save, press <b>Test</b>, then run a sync.</li>'
            . '<li>This integration is in beta and only reads from Fabrikam PSA (clients, contacts and assets). Something look wrong? Report it on GitHub with the field names (never the values) from one record.</li></ol>';
    }

    /** API address (url) and API key (secret). */
    protected function connectionFields(): array
    {
        return [
            ['name' => 'fabrikampsa_url', 'label' => 'API address', 'type' => 'url', 'placeholder' => 'https://fabrikampsa.example.com'],
            ['name' => 'fabrikampsa_api_key', 'label' => 'API key', 'type' => 'secret'],
        ];
    }
}
