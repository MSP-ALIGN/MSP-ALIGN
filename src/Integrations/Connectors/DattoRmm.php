<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\RmmConnector;
use Align\Providers\Rmm\DattoRmm as Provider;
use Align\Providers\Rmm\RmmProvider;
use Align\Settings;

/**
 * 2.13.0 Datto RMM as an RMM (Providers\Rmm\DattoRmm does the calls and maps the data). Beta: built from Datto's API
 * documentation and open-source clients, not yet confirmed against many accounts; it only reads.
 *
 * Security assumptions: as Connector. The platform is a select from a fixed list of Datto hosts, so no address can be
 * typed; moving to another platform needs the secret key typed again anyway (save()). The API key is plain text (it is
 * the user name half of the pair); the secret key is encrypted and never shown again. Datto API keys act as the Datto
 * user they belong to, so the setup steps ask for a user with a read-only security level.
 */
final class DattoRmm extends RmmConnector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'dattormm'; }
    /** Name on the card and page. */
    public function name(): string { return 'Datto RMM'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-tower-broadcast'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Beta. Computers and servers from Datto RMM: make, model, serial numbers, OS, last check-in and logged-in user.'; }

    /** Set up when the platform, API key and secret key are saved. */
    public function configured(): bool
    {
        return (string) Settings::get('drmm_platform') !== '' && (string) Settings::get('drmm_api_key') !== '' && Settings::hasSecret('drmm_api_secret');
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In Datto RMM turn on <b>Setup → Global Settings → Access Control → Enable API Access</b>.</li>'
            . '<li>Make a user for Align with a <b>read-only security level</b> that can see <b>every site</b>: devices it can\'t see would be marked removed here. Under <b>Setup → Users</b> open it, press <b>Generate API Keys</b> and save; copy the API key and secret key (the secret isn\'t shown again).</li>'
            . '<li>Pick the <b>platform</b>: the name in the API URL shown on that user\'s page (for example <code>merlot</code> in https://merlot-api.centrastage.net).</li>'
            . '<li>Save, press <b>Test</b>, run a sync, then link Datto sites to Align clients on <a href="/mapping">Client mapping</a> (matching names link automatically).</li>'
            . '<li>Datto gives make, model and serial number one device at a time and limits requests for the whole account, so Align reads up to ' . Provider::MAX_AUDITS . ' devices\' hardware per hourly sync: a large account fills in over a few hours, and after that only devices Datto audited again are read.</li>'
            . '<li>This integration is in beta and only reads from Datto. Something look wrong? Report it on GitHub with the field names (never the values) from one device.</li></ol>';
    }

    /** Platform (select), API key (text), secret key (secret). */
    public function fields(): array
    {
        return [
            ['name' => 'drmm_platform', 'label' => 'Platform', 'type' => 'select', 'options' => ['' => 'Choose…'] + Provider::PLATFORMS, 'default' => ''],
            ['name' => 'drmm_api_key', 'label' => 'API key', 'type' => 'text'],
            ['name' => 'drmm_api_secret', 'label' => 'Secret key', 'type' => 'secret'],
        ];
    }

    /** An API key is one token: letters, digits and a few symbols. */
    public function validate(string $name, string $value): ?string
    {
        return $name === 'drmm_api_key' && !preg_match('/^[A-Za-z0-9._-]{1,200}$/', $value) ? 'The API key can only hold letters, digits, dots, dashes and underscores.' : null;
    }

    /**
     * Saves the form (Connector::save), but a different platform with a saved secret key needs the secret typed again
     * (1.45: a saved secret is only sent to the server it was entered for).
     */
    public function save(array $post): array
    {
        $p = is_string($post['drmm_platform'] ?? null) ? $post['drmm_platform'] : null;
        $old = (string) Settings::get('drmm_platform', '');
        if ($p !== null && $old !== '' && $p !== $old && Settings::hasSecret('drmm_api_secret')
            && trim((string) ($post['drmm_api_secret'] ?? '')) === '' && empty($post['clear_drmm_api_secret'])) {
            throw new \InvalidArgumentException('You changed the platform. Enter the secret key again too: a saved secret is only sent to the server it was entered for.');
        }
        return parent::save($post);
    }

    /** The Datto RMM provider from the saved settings ($forLinks: no keys needed). */
    public function provider(bool $forLinks = false): RmmProvider
    {
        return Provider::fromSettings($forLinks);
    }
}
