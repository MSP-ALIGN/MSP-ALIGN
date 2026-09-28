<?php
declare(strict_types=1);

namespace Align\Providers\Rmm;

use Align\Integrations\NinjaOne;
use Align\Settings;

/** NinjaOne as an RMM provider: turns NinjaOne API rows into neutral records (see RmmProvider). */
final class NinjaOneRmm implements RmmProvider
{
    public function __construct(private ?NinjaOne $api, private string $instance)
    {
    }

    /** $forLinks: only building console links, so no credentials are needed. */
    public static function fromSettings(bool $forLinks = false): self
    {
        return new self($forLinks ? null : NinjaOne::fromSettings(), (string) Settings::get('ninja_instance', 'app.ninjarmm.com'));
    }

    public function key(): string
    {
        return 'ninjaone';
    }

    public function name(): string
    {
        return 'NinjaOne';
    }

    public function supports(string $capability): bool
    {
        return isset(self::CAPABILITIES[$capability]);
    }

    private function api(): NinjaOne
    {
        return $this->api ?? throw new \RuntimeException('NinjaOne is not configured (Integrations).');
    }

    public function test(): string
    {
        return $this->api()->test();
    }

    public function organizations(): array
    {
        return array_map(fn(array $o) => [
            'id' => (string) (int) $o['id'],
            'name' => (string) ($o['name'] ?? 'Org ' . $o['id']),
            'description' => $o['description'] ?? null,
        ], array_filter($this->api()->organizations(), fn($o) => isset($o['id'])));
    }

    public function devices(): array
    {
        $out = [];
        foreach ($this->api()->devicesDetailed() as $d) {
            if (!isset($d['id'])) {
                continue;
            }
            $m = NinjaOne::mapDevice($d);
            $out[] = [
                'id' => (string) $m['ninja_device_id'],
                'org_id' => $m['ninja_org_id'] !== null ? (string) $m['ninja_org_id'] : null,
                'display_name' => $m['display_name'],
                'system_name' => $m['system_name'],
                'node_class' => $m['node_class'],
                'device_type' => $m['device_type'],
                'manufacturer' => $m['manufacturer'],
                'model' => $m['model'],
                'serial' => $m['serial'],
                'chassis' => $m['chassis'],
                'is_virtual' => (bool) $m['is_virtual'],
                'os_name' => $m['os_name'],
                'os_build' => $m['os_build'],
                'os_release_id' => $m['os_release_id'],
                'last_contact' => $m['last_contact'],
                'created_at' => $m['ninja_created'],
                'last_user' => $m['last_user'],
                'offline' => (bool) $m['offline'],
            ];
        }
        return $out;
    }

    public function deviceUrl(string $deviceId): ?string
    {
        $base = str_contains($this->instance, '://') ? rtrim($this->instance, '/') : 'https://' . $this->instance;
        return $base . '/#/deviceDashboard/' . (int) $deviceId . '/overview';
    }
}
