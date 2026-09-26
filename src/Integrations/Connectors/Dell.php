<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\Connector;
use Align\Settings;

final class Dell extends Connector
{
    public function key(): string { return 'dell'; }
    public function name(): string { return 'Dell TechDirect'; }
    public function icon(): string { return 'fas fa-laptop'; }
    public function category(): string { return 'Warranty'; }
    public function summary(): string { return 'Ship dates and warranty end dates for Dell hardware, looked up by serial number.'; }
    public function syncSteps(): array { return ['Warranty']; }
    public function hasTest(): bool { return true; }

    public function configured(): bool
    {
        return (bool) Settings::get('dell_client_id') && Settings::hasSecret('dell_client_secret');
    }

    public function setup(): string
    {
        return '<ol class="pl-3 mb-0"><li>In Dell TechDirect request access to the <b>Warranty API</b>.</li><li>Copy the client ID and secret here, save and press <b>Test</b>.</li>'
            . '<li>Warranties are looked up during the sync and re-checked on the schedule set under <a href="/settings/planning">Settings → Planning &amp; lifecycle</a>.</li></ol>';
    }

    public function fields(): array
    {
        return [
            ['name' => 'dell_client_id', 'label' => 'Client ID', 'type' => 'text'],
            ['name' => 'dell_client_secret', 'label' => 'Client secret', 'type' => 'secret'],
        ];
    }

    public function test(): string
    {
        $secret = Settings::secret('dell_client_secret');
        if (!$this->configured() || !$secret) {
            throw new \RuntimeException('Dell client ID and secret are not set.');
        }
        (new \Align\Integrations\Warranty\Dell((string) Settings::get('dell_client_id'), $secret, Settings::get('dell_api_base') ?: 'https://apigtwb2c.us.dell.com'))->lookup(['TEST000']);
        return 'Connected. Token issued and lookup endpoint responded.';
    }
}
