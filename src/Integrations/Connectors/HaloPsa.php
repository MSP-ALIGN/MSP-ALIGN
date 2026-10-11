<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\PsaConnector;
use Align\Providers\Psa\HaloPsa as Provider;
use Align\Providers\Psa\PsaProvider;
use Align\Settings;

/**
 * 2.12.0 HaloPSA as the PSA, read-only (beta). Providers\Psa\HaloPsa does the calls and the mapping.
 *
 * Security assumptions: as Connector. Both addresses are url fields, so they must be https (checked by Connector::save
 * and HttpClient), and moving one to another server needs the secret typed again (1.45). The client ID is plain text
 * (Halo shows it openly); the secret is encrypted and never shown again.
 */
final class HaloPsa extends PsaConnector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'halopsa'; }
    /** Name on the card and page. */
    public function name(): string { return 'HaloPSA'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-circle-notch'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Beta. Clients, sites, contacts, assets and invoices from HaloPSA.'; }

    /** What the beta reads (see Providers\Psa\HaloPsa). */
    public function capabilities(): array
    {
        return Provider::CAPS;
    }

    /** The provider from the saved settings ($interactive: one short attempt). Throws when not set up. */
    public function provider(bool $interactive = false): PsaProvider
    {
        return Provider::fromSettings($interactive);
    }

    /** Set up when the address, client ID and secret are saved. */
    public function configured(): bool
    {
        return (string) Settings::get('halo_url') !== '' && (string) Settings::get('halo_client_id') !== '' && Settings::hasSecret('halo_client_secret');
    }

    /** Read-only: assets come in with the hourly sync, so there's no 2-minute check. */
    public function polls(): bool
    {
        return false;
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In Halo open <b>Configuration → Integrations → HaloPSA API → View Applications → New</b>. Tick <b>Active</b> and set the authentication method to <b>Client ID and Secret (Services)</b> first (changing it makes a new secret).</li>'
            . '<li>Choose an agent for it to log in as that can see <b>every client</b>: what the agent can\'t see doesn\'t come in, and clients missing from Halo\'s answer are archived here.</li>'
            . '<li>On <b>Permissions</b> allow only <b>read:customers</b>, <b>read:assets</b> and <b>read:invoices</b>. Align never writes to Halo.</li>'
            . '<li>Enter your Halo web address (the Resource Server without <code>/api</code>), the client ID and secret. Fill in the authorisation server and tenant only if Halo\'s API page shows ones that differ from your Halo address. Save, press <b>Test</b>, then run a sync.</li>'
            . '<li>This integration is in beta and only reads from Halo (clients, sites, contacts, assets and invoices; tickets and SLAs not yet). Something look wrong? Report it on GitHub with the field names (never the values) from one record.</li></ol>';
    }

    /** Halo address, authorisation server, tenant (optional), client ID, secret. */
    protected function connectionFields(): array
    {
        return [
            ['name' => 'halo_url', 'label' => 'Halo web address', 'type' => 'url', 'placeholder' => 'https://yourco.halopsa.com'],
            ['name' => 'halo_client_id', 'label' => 'Client ID', 'type' => 'text'],
            ['name' => 'halo_client_secret', 'label' => 'Client secret', 'type' => 'secret'],
            ['name' => 'halo_auth_url', 'label' => 'Authorisation server (optional)', 'type' => 'url', 'placeholder' => 'https://yourco.halopsa.com/auth',
                'help' => 'Leave empty unless Halo\'s API page shows a different one (empty: your Halo address + /auth).'],
            ['name' => 'halo_tenant', 'label' => 'Tenant (optional)', 'type' => 'text', 'help' => 'Only if Halo\'s API page shows one.'],
        ];
    }

    /**
     * Saves the form (Connector::save), but first: the secret goes to the authorisation server, so pointing that at
     * another address (including setting or clearing it) needs the secret typed again, like a moved Halo address does,
     * and so does a first Halo address for a secret saved without one (1.45: a borrowed admin session can't send the
     * saved secret somewhere new).
     */
    public function save(array $post): array
    {
        $keeps = Settings::hasSecret('halo_client_secret') && trim((string) ($post['halo_client_secret'] ?? '')) === '' && empty($post['clear_halo_client_secret']);
        $auth = is_string($post['halo_auth_url'] ?? null) ? rtrim(trim($post['halo_auth_url']), '/') : null;
        if ($keeps && $auth !== null && $auth !== (string) Settings::get('halo_auth_url', '')) {
            throw new \InvalidArgumentException('You changed the authorisation server. Enter the client secret again too: a saved secret is only sent to the server it was entered for.');
        }
        // Connector::save only checks an address that had a value; a secret saved with no address yet mustn't go to the first one set
        $url = is_string($post['halo_url'] ?? null) ? trim($post['halo_url']) : '';
        if ($keeps && $url !== '' && (string) Settings::get('halo_url', '') === '') {
            throw new \InvalidArgumentException('Enter the client secret again with the Halo web address: a saved secret is only sent to the server it was entered for.');
        }
        return parent::save($post);
    }

    /** A tenant or client ID is one token: letters, digits, dots, dashes and underscores. */
    public function validate(string $name, string $value): ?string
    {
        if (in_array($name, ['halo_tenant', 'halo_client_id'], true) && $value !== '' && !preg_match('/^[A-Za-z0-9._-]{1,200}$/', $value)) {
            return ($name === 'halo_tenant' ? 'The tenant' : 'The client ID') . ' can only hold letters, digits, dots, dashes and underscores.';
        }
        return null;
    }
}
