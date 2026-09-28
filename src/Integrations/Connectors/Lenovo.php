<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\Connector;
use Align\Settings;

final class Lenovo extends Connector
{
    public function key(): string { return 'lenovo'; }
    public function name(): string { return 'Lenovo'; }
    public function icon(): string { return 'fas fa-laptop'; }
    public function category(): string { return 'Warranty'; }
    public function summary(): string { return 'Warranty dates for Lenovo hardware, looked up by serial number.'; }
    public function syncSteps(): array { return ['Warranty']; }
    public function hasTest(): bool { return true; }

    public function configured(): bool
    {
        return Settings::hasSecret('lenovo_client_id');
    }

    public function setup(): string
    {
        return '<ol class="pl-3 mb-0"><li>Lenovo issues a <b>ClientID</b> token to partners for its warranty API.</li><li>Paste it here, save and press <b>Test</b>.</li>'
            . '<li>Other brands use the ' . psa_name() . ' warranty date or a date entered on the device.</li></ol>';
    }

    public function fields(): array
    {
        return [['name' => 'lenovo_client_id', 'label' => 'ClientID', 'type' => 'secret']];
    }

    public function test(): string
    {
        $id = Settings::secret('lenovo_client_id');
        if (!$id) {
            throw new \RuntimeException('Lenovo ClientID is not set.');
        }
        $r = (new \Align\Integrations\Warranty\Lenovo($id, Settings::get('lenovo_api_base') ?: 'https://supportapi.lenovo.com'))->lookup(['TEST0000']);
        $res = $r['TEST0000'] ?? null;
        if ($res && $res->status === 'error') {
            throw new \RuntimeException((string) $res->message);
        }
        return 'Lookup endpoint responded.';
    }
}
