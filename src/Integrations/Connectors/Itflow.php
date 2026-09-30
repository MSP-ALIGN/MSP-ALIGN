<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\PsaConnector;
use Align\Providers\Psa\ItflowPsa;
use Align\Providers\Psa\PsaProvider;
use Align\Settings;

final class Itflow extends PsaConnector
{
    public function key(): string { return 'itflow'; }
    public function name(): string { return 'ITFlow'; }
    public function icon(): string { return 'fas fa-screwdriver-wrench'; }
    public function summary(): string { return 'Clients, locations, contacts, assets, software licenses, invoices and ticket SLAs. Assets and contacts sync both ways.'; }

    public function capabilities(): array
    {
        return array_keys(PsaProvider::CAPABILITIES);
    }

    public function provider(bool $interactive = false): PsaProvider
    {
        return ItflowPsa::fromSettings($interactive);
    }

    public function configured(): bool
    {
        return (string) Settings::get('itflow_url') !== '' && Settings::hasSecret('itflow_api_key');
    }

    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In ITFlow open <b>Admin → API Keys → Create</b>. The key runs as the user you choose.</li>'
            . '<li>That user needs read access to Clients, Contacts, Locations, Software, Vendors, Invoices and Support (assets and tickets, for SLA reporting), and write access to Support and Contacts for two-way sync and warranty write-back.</li>'
            . '<li>Enter the ITFlow address and key, save, then press <b>Test</b> and run a sync.</li></ol>';
    }

    protected function connectionFields(): array
    {
        return [
            ['name' => 'itflow_url', 'label' => 'ITFlow URL', 'type' => 'url', 'placeholder' => 'https://itflow.example.com'],
            ['name' => 'itflow_api_key', 'label' => 'API key', 'type' => 'secret'],
        ];
    }

    protected function slaHelp(): string
    {
        return 'Needs ITFlow 26.08 or later with SLAs set up (Admin → SLAs). ' . parent::slaHelp();
    }

    public function slaSetupHint(): string
    {
        return 'Ticket SLAs arrived in ITFlow 26.08. Update ITFlow, set up SLAs under <i>Admin → SLAs</i>, and they appear here after the next sync.';
    }

    public function noSlaMessage(): string
    {
        return 'this ITFlow has no SLA fields; update it to 26.08 or later';
    }
}
