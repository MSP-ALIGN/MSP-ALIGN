<?php
declare(strict_types=1);

namespace Align\Templates\Rmm;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Providers\Clean;
use Align\Providers\Rmm\RmmProvider;
use Align\Settings;

/**
 * Fabrikam RMM as an RMM provider, read-only: the calls to the product's API and the mapping to Align's neutral
 * organization and device records (see Providers\Rmm\RmmProvider).
 *
 * template-only: begin
 * TEMPLATE (2.22.0). Fabrikam is a made-up product: this file is the starting point `align make:connector rmm "Name"`
 * copies to src/Providers/Rmm/<Name>.php. Change the paths and field names to your product's API; keep the checks
 * (they are what docs/CONNECTOR_REVIEW.md asks a reviewer to look for). The mock API it talks to in the tests is
 * tests/mock/fabrikamrmm.php.
 * template-only: end
 *
 * API: GET {address}/api/organizations?page=N and GET {address}/api/devices?page=N, each answering
 * {"items": [...], "next": <next page number or null>}, with the API key as a bearer token.
 *
 * SECURITY: the product's replies are untrusted. A reply without an "items" list is an error, never "no records"
 * (the sync marks devices the RMM no longer returns as removed). Ids must be the product's own shape (a device's
 * organization id decides which client gets it), text is cut to the devices table's column sizes without control
 * characters, and times must be real. The address is an https admin setting checked by HttpClient on every request;
 * the key goes only in the Authorization header.
 */
final class FabrikamRmm implements RmmProvider
{
    /** Pages read from one list before giving up (a server that ignores ?page= would answer for ever). */
    private const MAX_PAGES = 2000;

    private HttpClient $http;

    /** $url: the API address (https://fabrikamrmm.example.com); $apiKey: null when only building links. */
    public function __construct(private string $url, private ?string $apiKey)
    {
        $this->http = new HttpClient(60, 2);
    }

    /** From the saved fabrikamrmm_url and fabrikamrmm_api_key ($forLinks: no key needed). Throws when not set up. */
    public static function fromSettings(bool $forLinks = false): self
    {
        $url = (string) Settings::get('fabrikamrmm_url');
        $key = $forLinks ? null : Settings::secret('fabrikamrmm_api_key');
        if ($url === '' || (!$forLinks && !$key)) {
            throw new \RuntimeException('Fabrikam RMM is not configured (Integrations).');
        }
        return new self($url, $key);
    }

    /** The connector key (fixed; stored with every record from this provider). */
    public function key(): string
    {
        return 'fabrikamrmm';
    }

    /** Display name. */
    public function name(): string
    {
        return 'Fabrikam RMM';
    }

    /** What this product reports (see RmmProvider::CAPABILITIES): last user, last check-in and a console link per device. */
    public function supports(string $capability): bool
    {
        return in_array($capability, ['last_user', 'last_contact', 'device_links'], true);
    }

    /** Checks the address and key with one small read (admin, Integrations page). */
    public function test(): string
    {
        $n = count($this->organizations());
        return "Connected. $n organization" . ($n === 1 ? '' : 's') . ' readable.';
    }

    /**
     * Every item of a paged list (GET $path?page=1, 2, … until "next" is empty). Throws on an error, a reply without
     * an items list, or a "next" that doesn't move forward: never a partial list passed off as complete.
     */
    private function all(string $path): array
    {
        if ($this->apiKey === null) {
            throw new \RuntimeException('Fabrikam RMM is not configured (Integrations).');
        }
        $out = [];
        $page = 1;
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            try {
                $j = $this->http->getJson(rtrim($this->url, '/') . $path . '?page=' . $page, ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json']);
            } catch (HttpException $e) {
                throw new \RuntimeException(match (true) {
                    in_array($e->status, [401, 403], true) => 'Fabrikam RMM refused the API key (HTTP ' . $e->status . '). Check the key and its permissions.',
                    $e->status === 404 => 'Fabrikam RMM has no ' . $path . ' at that address (HTTP 404). Check the API address.',
                    default => 'Fabrikam RMM couldn\'t be read: ' . $e->getMessage(),
                }, 0, $e);
            }
            if (!is_array($j) || !is_array($j['items'] ?? null) || !array_is_list($j['items'])) {
                throw new \RuntimeException('Fabrikam RMM sent something other than a list for ' . $path . '. Check the API address.');
            }
            array_push($out, ...array_filter($j['items'], 'is_array'));
            $next = $j['next'] ?? null;
            if ($next === null || $next === '' || $next === false) {
                return $out;
            }
            if (!is_int($next) || $next <= $page) {
                throw new \RuntimeException('Fabrikam RMM sent a page number that doesn\'t move forward for ' . $path . '.');
            }
            $page = $next;
        }
        throw new \RuntimeException('Fabrikam RMM sent more than ' . self::MAX_PAGES . ' pages for ' . $path . '.');
    }

    /** An id in the product's shape (letters, digits, dots, dashes and underscores, at most 64), or null. */
    private static function id(mixed $v): ?string
    {
        if (is_int($v) && $v > 0) {
            return (string) $v;
        }
        return is_string($v) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $v) ? $v : null;
    }

    /** Remote text on one line, cut to $max, or null. */
    private static function text(mixed $v, int $max): ?string
    {
        return Clean::text($v, $max);
    }

    /** Every organization with a valid id. */
    public function organizations(): array
    {
        $out = [];
        foreach ($this->all('/api/organizations') as $o) {
            $id = self::id($o['id'] ?? null);
            if ($id !== null) {
                $out[] = ['id' => $id, 'name' => self::text($o['name'] ?? null, 255) ?? 'Organization ' . $id,
                    'description' => self::text($o['description'] ?? null, 5000)];
            }
        }
        return $out;
    }

    /** Every device with a valid id, as a neutral device record. */
    public function devices(): array
    {
        $out = [];
        foreach ($this->all('/api/devices') as $d) {
            $id = self::id($d['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $make = self::text($d['manufacturer'] ?? null, 190);
            $model = self::text($d['model'] ?? null, 190);
            $host = self::text($d['hostname'] ?? null, 255);
            $os = self::text($d['os'] ?? null, 255);
            $kind = strtolower((string) self::text($d['type'] ?? null, 40));
            $isVirtual = $kind === 'virtual' || preg_match('/vmware|virtualbox|kvm|qemu|xen|virtual machine|hyper-v/i', "$make $model") === 1;
            $serial = self::text($d['serial'] ?? null, 190);
            $out[] = [
                'id' => $id,
                'org_id' => self::id($d['organization_id'] ?? null),
                'display_name' => self::text($d['name'] ?? null, 255) ?? $host,
                'system_name' => $host,
                'node_class' => $kind !== '' ? $kind : null,
                'device_type' => self::type($kind, (string) $make, (string) $model, (string) $host, $isVirtual, (string) $os),
                'manufacturer' => $make,
                'model' => $model,
                // placeholders some hardware reports instead of a serial number
                'serial' => $serial !== null && !preg_match('/^(to be filled by o\.?e\.?m\.?|default string|not specified|system serial number|n\/?a|none|0+)$/i', $serial) ? $serial : null,
                'chassis' => null,
                'is_virtual' => $isVirtual,
                'os_name' => $os,
                'os_build' => self::text($d['os_build'] ?? null, 60),
                'os_release_id' => null,
                'last_contact' => self::time($d['last_seen'] ?? null),
                'created_at' => self::time($d['created'] ?? null),
                'last_user' => self::text($d['last_user'] ?? null, 190),
                'offline' => ($d['online'] ?? null) !== true,
            ];
        }
        return $out;
    }

    /**
     * The Align device type (Lifecycle::TYPES) from the product's own type. Servers, workstations and Macs use the
     * rules Align already has for NinjaOne's device classes (laptop or desktop by model, virtual by OS).
     */
    private static function type(string $kind, string $make, string $model, string $host, bool $isVirtual, string $os): string
    {
        $class = match ($kind) {
            'server' => 'WINDOWS_SERVER',
            'mac' => 'MAC',
            'firewall' => 'NMS_FIREWALL',
            'switch' => 'NMS_SWITCH',
            default => 'WINDOWS_WORKSTATION',
        };
        if ($kind === 'laptop') {
            return 'Laptop';
        }
        return \Align\Integrations\NinjaOne::type($class, '', $make, $model, $host, $isVirtual, $os);
    }

    /** An ISO time ("2026-10-10T17:13:34Z") in this server's timezone (Y-m-d H:i:s), or null. */
    private static function time(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $v)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** The device's page in the product's console, built from the saved address and a checked id (it goes into an href). */
    public function deviceUrl(string $deviceId): ?string
    {
        $base = rtrim($this->url, '/');
        return self::id($deviceId) !== null && preg_match('#^https?://#i', $base) ? $base . '/devices/' . rawurlencode($deviceId) : null;
    }
}
