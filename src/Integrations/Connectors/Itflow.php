<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\PsaConnector;
use Align\Providers\Psa\ItflowPsa;
use Align\Providers\Psa\PsaProvider;
use Align\Settings;

/**
 * ITFlow as the PSA (Integrations\Itflow does the calls, Providers\Psa\ItflowPsa maps the data).
 *
 * Security assumptions: as Connector. The URL is a url field, so it must be https (checked by Connector::save and
 * HttpClient) and moving it to another server needs the API key typed again (1.45).
 */
final class Itflow extends PsaConnector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'itflow'; }
    /** Name on the card and page. */
    public function name(): string { return 'ITFlow'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-screwdriver-wrench'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Clients, locations, contacts, assets, software licenses, invoices and ticket SLAs. Assets and contacts sync both ways.'; }

    /** ITFlow can do everything a PSA can. */
    public function capabilities(): array
    {
        return array_keys(PsaProvider::CAPABILITIES);
    }

    /** The ITFlow provider from the saved settings ($interactive: one short attempt). Throws when not set up. */
    public function provider(bool $interactive = false): PsaProvider
    {
        return ItflowPsa::fromSettings($interactive);
    }

    /** Set up when the URL and API key are saved. */
    public function configured(): bool
    {
        return (string) Settings::get('itflow_url') !== '' && Settings::hasSecret('itflow_api_key');
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In ITFlow open <b>Admin → API Keys → Create</b>. The key runs as the user you choose.</li>'
            . '<li>That user needs read access to Clients, Contacts, Locations, Software, Vendors, Invoices and Support (assets and tickets, for SLA reporting), and write access to Support and Contacts for two-way sync and warranty write-back.</li>'
            . '<li>Enter the ITFlow address and key, save, then press <b>Test</b> and run a sync.</li></ol>';
    }

    /** ITFlow URL (url) and API key (secret). */
    protected function connectionFields(): array
    {
        return [
            ['name' => 'itflow_url', 'label' => 'ITFlow URL', 'type' => 'url', 'placeholder' => 'https://itflow.example.com'],
            ['name' => 'itflow_api_key', 'label' => 'API key', 'type' => 'secret'],
        ];
    }

    /** SLA help with the ITFlow version it needs. */
    protected function slaHelp(): string
    {
        return 'Needs ITFlow 26.08 or later with SLAs set up (Admin → SLAs). ' . parent::slaHelp();
    }

    /** How to get SLAs from ITFlow (trusted HTML). */
    public function slaSetupHint(): string
    {
        return 'Ticket SLAs arrived in ITFlow 26.08. Update ITFlow, set up SLAs under <i>Admin → SLAs</i>, and they appear here after the next sync.';
    }

    /** Shown when this ITFlow sent tickets without SLA fields. */
    public function noSlaMessage(): string
    {
        return 'this ITFlow has no SLA fields; update it to 26.08 or later';
    }
}
