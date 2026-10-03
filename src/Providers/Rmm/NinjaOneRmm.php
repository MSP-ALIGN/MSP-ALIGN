<?php
declare(strict_types=1);

namespace Align\Providers\Rmm;

use Align\Integrations\NinjaOne;
use Align\Settings;

/**
 * NinjaOne as an RMM provider: turns NinjaOne API rows into neutral records (see RmmProvider).
 *
 * SECURITY: NinjaOne's responses are untrusted. Organization and device ids must be positive whole numbers
 * (NinjaOne's own); a loose (int) cast would turn "abc", [7] or true into 0 or 1, folding unrelated records
 * into one and putting devices under another client's organization. Text is cut to the devices table's
 * column sizes with control characters removed, and timestamps must be valid, because syncRmmDevices stores
 * the whole list in one transaction: one bad device would otherwise stop every device update.
 */
final class NinjaOneRmm implements RmmProvider
{
    /** $api: null when only console links are needed. $instance: the saved ninja_instance (a host from NinjaOne::INSTANCES). */
    public function __construct(private ?NinjaOne $api, private string $instance)
    {
    }

    /** $forLinks: only building console links, so no credentials are needed. */
    public static function fromSettings(bool $forLinks = false): self
    {
        return new self($forLinks ? null : NinjaOne::fromSettings(), (string) Settings::get('ninja_instance', 'app.ninjarmm.com'));
    }

    /** The connector key (fixed; also stored with every record from this provider). */
    public function key(): string
    {
        return 'ninjaone';
    }

    /** Display name. */
    public function name(): string
    {
        return 'NinjaOne';
    }

    /** NinjaOne offers every RMM capability. */
    public function supports(string $capability): bool
    {
        return isset(self::CAPABILITIES[$capability]);
    }

    /** The API client; throws when this provider was built for links only. */
    private function api(): NinjaOne
    {
        return $this->api ?? throw new \RuntimeException('NinjaOne is not configured (Integrations).');
    }

    /** Checks the credentials (admin, Integrations page). */
    public function test(): string
    {
        return $this->api()->test();
    }

    /** A NinjaOne id as text, or null unless it's a positive whole number (an int, or a string of digits). */
    private static function id(mixed $v): ?string
    {
        if (is_int($v)) {
            return $v > 0 ? (string) $v : null;
        }
        return is_string($v) && preg_match('/^0*([1-9][0-9]{0,17})$/', trim($v), $m) ? $m[1] : null;
    }

    /** Text from NinjaOne on one line, without control characters, cut to $max; null when empty or not text. */
    private static function text(mixed $v, int $max): ?string
    {
        if (!is_scalar($v) || is_bool($v)) {
            return null;
        }
        $s = trim(mb_substr(preg_replace(['/[\t\r\n]/', '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'], [' ', ''], (string) $v) ?? '', 0, $max));
        return $s !== '' ? $s : null;
    }

    /** A Y-m-d H:i:s time (as NinjaOne::mapDevice makes them), or null when it isn't one a DATETIME column takes. */
    private static function dt(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[1-9]\d{3}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) ? $v : null;
    }

    /** Every organization with a valid id (name and description cleaned; rmm_orgs.name holds 255 characters). */
    public function organizations(): array
    {
        $out = [];
        foreach ($this->api()->organizations() as $o) {
            $id = is_array($o) ? self::id($o['id'] ?? null) : null;
            if ($id === null) {
                continue;
            }
            $desc = is_scalar($o['description'] ?? null) && !is_bool($o['description'])
                ? mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $o['description']) ?? '', 0, 5000) : null;
            $out[] = [
                'id' => $id,
                'name' => self::text($o['name'] ?? null, 255) ?? 'Org ' . $id,
                'description' => $desc,
            ];
        }
        return $out;
    }

    /** Every device with a valid id. A device whose organization id isn't valid is kept without an organization. */
    public function devices(): array
    {
        $out = [];
        foreach ($this->api()->devicesDetailed() as $d) {
            $id = is_array($d) ? self::id($d['id'] ?? null) : null;
            if ($id === null) {
                continue;
            }
            // mapDevice reads these as lists of fields; anything else is treated as missing
            foreach (['system', 'os'] as $k) {
                if (isset($d[$k]) && !is_array($d[$k])) {
                    unset($d[$k]);
                }
            }
            $m = NinjaOne::mapDevice($d);
            $out[] = [
                'id' => $id,
                'org_id' => self::id($d['organizationId'] ?? null),
                'display_name' => self::text($m['display_name'], 255),
                'system_name' => self::text($m['system_name'], 255),
                'node_class' => self::text($m['node_class'], 60),
                'device_type' => $m['device_type'],
                'manufacturer' => self::text($m['manufacturer'], 190),
                'model' => self::text($m['model'], 190),
                'serial' => self::text($m['serial'], 190),
                'chassis' => self::text($m['chassis'], 60),
                'is_virtual' => (bool) $m['is_virtual'],
                'os_name' => self::text($m['os_name'], 255),
                'os_build' => self::text($m['os_build'], 60),
                'os_release_id' => self::text($m['os_release_id'], 30),
                'last_contact' => self::dt($m['last_contact']),
                'created_at' => self::dt($m['ninja_created']),
                'last_user' => self::text($m['last_user'], 190),
                'offline' => (bool) $m['offline'],
            ];
        }
        return $out;
    }

    /** Link to the device's dashboard in NinjaOne; null for an id that isn't NinjaOne's or an instance that isn't http(s). */
    public function deviceUrl(string $deviceId): ?string
    {
        $id = self::id($deviceId);
        $base = str_contains($this->instance, '://') ? rtrim($this->instance, '/') : 'https://' . $this->instance;
        // The instance is normally one of NinjaOne::INSTANCES (tests point it at a local mock); only http(s) goes into an href
        if ($id === null || !preg_match('#^https?://[^\s"\'<>]+$#i', $base)) {
            return null;
        }
        return $base . '/#/deviceDashboard/' . $id . '/overview';
    }
}
