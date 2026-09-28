<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\RmmConnector;
use Align\Providers\Rmm\NinjaOneRmm;
use Align\Providers\Rmm\RmmProvider;
use Align\Settings;

final class NinjaOne extends RmmConnector
{
    public function key(): string { return 'ninjaone'; }
    public function name(): string { return 'NinjaOne'; }
    public function icon(): string { return 'fas fa-user-ninja'; }
    public function summary(): string { return 'Computers, servers and VMs with hardware, OS, last check-in and serial numbers.'; }

    public function configured(): bool
    {
        return (bool) Settings::get('ninja_client_id') && Settings::hasSecret('ninja_client_secret');
    }

    public function setup(): string
    {
        return '<ol class="pl-3 mb-0"><li>In NinjaOne open <b>Administration → Apps → API → Client app IDs → Add</b>.</li>'
            . '<li>Application platform <b>API Services (machine-to-machine)</b>, scope <b>Monitoring</b>, grant type <b>Client credentials</b>.</li>'
            . '<li>Copy the client ID and secret here, save, then press <b>Test</b>.</li>'
            . '<li>Run a sync, then link organizations to clients on <a href="/mapping">Client mapping</a> (matching names link automatically).</li></ol>';
    }

    public function fields(): array
    {
        return [
            ['name' => 'ninja_instance', 'label' => 'Instance', 'type' => 'select', 'options' => \Align\Integrations\NinjaOne::INSTANCES, 'default' => 'app.ninjarmm.com'],
            ['name' => 'ninja_client_id', 'label' => 'Client ID', 'type' => 'text'],
            ['name' => 'ninja_client_secret', 'label' => 'Client secret', 'type' => 'secret'],
        ];
    }

    public function provider(bool $forLinks = false): RmmProvider
    {
        return NinjaOneRmm::fromSettings($forLinks);
    }
}
