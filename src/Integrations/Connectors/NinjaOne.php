<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\RmmConnector;
use Align\Providers\Rmm\NinjaOneRmm;
use Align\Providers\Rmm\RmmProvider;
use Align\Settings;

/**
 * NinjaOne as an RMM (Integrations\NinjaOne does the calls, Providers\Rmm\NinjaOneRmm maps the data).
 *
 * Security assumptions: as Connector. The instance is a select of NinjaOne's own hosts, so the client secret can't
 * be pointed at another server from the form (a value already saved is kept, never a new one typed in).
 */
final class NinjaOne extends RmmConnector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'ninjaone'; }
    /** Name on the card and page. */
    public function name(): string { return 'NinjaOne'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-user-ninja'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Computers, servers and VMs with hardware, OS, last check-in and serial numbers.'; }

    /** Set up when the client ID and secret are saved. */
    public function configured(): bool
    {
        return (bool) Settings::get('ninja_client_id') && Settings::hasSecret('ninja_client_secret');
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In NinjaOne open <b>Administration → Apps → API → Client app IDs → Add</b>.</li>'
            . '<li>Application platform <b>API Services (machine-to-machine)</b>, scope <b>Monitoring</b>, grant type <b>Client credentials</b>.</li>'
            . '<li>Copy the client ID and secret here, save, then press <b>Test</b>.</li>'
            . '<li>Run a sync, then link organizations to clients on <a href="/mapping">Client mapping</a> (matching names link automatically).</li></ol>';
    }

    /** Instance (select of NinjaOne hosts), client ID (text) and client secret (secret). */
    public function fields(): array
    {
        return [
            ['name' => 'ninja_instance', 'label' => 'Instance', 'type' => 'select', 'options' => \Align\Integrations\NinjaOne::INSTANCES, 'default' => 'app.ninjarmm.com'],
            ['name' => 'ninja_client_id', 'label' => 'Client ID', 'type' => 'text'],
            ['name' => 'ninja_client_secret', 'label' => 'Client secret', 'type' => 'secret'],
        ];
    }

    /** The NinjaOne provider from the saved settings ($forLinks: links only, no credentials needed). */
    public function provider(bool $forLinks = false): RmmProvider
    {
        return NinjaOneRmm::fromSettings($forLinks);
    }
}
