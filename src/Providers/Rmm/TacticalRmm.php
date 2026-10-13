<?php
declare(strict_types=1);

namespace Align\Providers\Rmm;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * 2.11.0 Tactical RMM (open source, self-hosted) as an RMM provider, read-only. Its REST API is on the API host the
 * install was set up with (https://api.example.com), authenticated with an API key in the X-API-KEY header; the key
 * acts as the Tactical user it belongs to (Settings → Global Settings → API Keys), so a read-only user is advised.
 *
 * Read: GET /clients/ (organizations: Tactical's clients, each with its sites) and GET /agents/?detail=true (every
 * agent the key's user may see, with hostname, client and site names, monitoring type, platform, operating system,
 * make and model, serial number, last seen, status and logged-in user). An agent names its client only by name, so
 * its organization is the client with that exact name. Tactical has no link to one agent's page, so device_links isn't
 * offered.
 *
 * SECURITY: Tactical's replies are untrusted. A reply that isn't a JSON list is an error, never "no records" (the sync
 * marks devices the RMM no longer returns as removed). Ids must be Tactical's own shape (client ids positive whole
 * numbers, agent ids letters, digits and dashes), text is cut to the devices table's column sizes without control
 * characters, and times must be real. The URL is an https admin setting checked by HttpClient on every request; the
 * key goes only in the X-API-KEY header.
 */
final class TacticalRmm implements RmmProvider
{
    /** Tactical's placeholders when an agent couldn't read its hardware details. */
    private const NO_MAKE = ['error getting make/model', 'unknown make/model', ''];

    /** Manufacturer names as computers report them, longest first ("Dell Inc. OptiPlex 7090" => Dell Inc. + OptiPlex 7090). */
    private const MAKERS = ['Hewlett-Packard', 'Microsoft Corporation', 'VMware, Inc.', 'innotek GmbH', 'Dell Inc.', 'ASUSTeK COMPUTER INC.', 'Micro-Star International Co., Ltd.',
        'Gigabyte Technology Co., Ltd.', 'Intel Corporation', 'Supermicro', 'LENOVO', 'Lenovo', 'Dell', 'HP', 'Acer', 'Apple', 'ASUS', 'QEMU', 'Xen', 'Parallels', 'Google', 'Fujitsu', 'Panasonic', 'Samsung', 'Toshiba'];

    private HttpClient $http;

    /** $url: the Tactical API address (https://api.example.com); $apiKey: null when only building links. */
    public function __construct(private string $url, private ?string $apiKey)
    {
        $this->http = new HttpClient(120, 2);
    }

    /** From the saved trmm_url and trmm_api_key ($forLinks: no key needed). Throws when not set up. */
    public static function fromSettings(bool $forLinks = false): self
    {
        $url = (string) Settings::get('trmm_url');
        $key = $forLinks ? null : Settings::secret('trmm_api_key');
        if ($url === '' || (!$forLinks && !$key)) {
            throw new \RuntimeException('Tactical RMM is not configured (Integrations).');
        }
        return new self($url, $key);
    }

    /** The connector key (fixed; also stored with every record from this provider). */
    public function key(): string
    {
        return 'tacticalrmm';
    }

    /** Display name. */
    public function name(): string
    {
        return 'Tactical RMM';
    }

    /** Last user and last check-in; no links to agents (Tactical has no page address for one agent). */
    public function supports(string $capability): bool
    {
        return in_array($capability, ['last_user', 'last_contact'], true);
    }

    /** GET an API path; returns a JSON list or throws (an HTML page, an object or an error isn't "no records"). */
    private function list(string $path): array
    {
        if ($this->apiKey === null) {
            throw new \RuntimeException('Tactical RMM is not configured (Integrations).');
        }
        try {
            $j = $this->http->getJson(rtrim($this->url, '/') . $path, ['X-API-KEY' => $this->apiKey, 'Accept' => 'application/json']);
        } catch (HttpException $e) {
            throw new \RuntimeException(match (true) {
                in_array($e->status, [401, 403], true) => 'Tactical RMM refused the API key (HTTP ' . $e->status . '). Check the key, and that its user may view clients and agents.',
                $e->status === 404 => 'Tactical RMM has no ' . $path . ' at that address (HTTP 404). Enter the API address (often https://api.your-domain), not the dashboard\'s.',
                default => 'Tactical RMM couldn\'t be read: ' . $e->getMessage(),
            }, 0, $e);
        }
        if (!is_array($j) || !array_is_list($j)) {
            throw new \RuntimeException('Tactical RMM sent something other than a list for ' . $path . '. Check that the address is the API\'s (often https://api.your-domain), not the dashboard\'s.');
        }
        return array_values(array_filter($j, 'is_array'));
    }

    /** Checks the key with one small read (admin, Integrations page). */
    public function test(): string
    {
        $n = count($this->organizations());
        return "Connected. $n client" . ($n === 1 ? '' : 's') . ' readable.';
    }

    /** A Tactical client id as text, or null unless it's a positive whole number. */
    private static function clientId(mixed $v): ?string
    {
        if (is_int($v)) {
            return $v > 0 ? (string) $v : null;
        }
        return is_string($v) && preg_match('/^0*([1-9][0-9]{0,17})$/', trim($v), $m) ? $m[1] : null;
    }

    /** A Tactical agent id (letters, digits, dots, dashes and underscores, at most 64: the devices column), or null. */
    private static function agentId(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $v) ? $v : null;
    }

    /** Remote text on one line, cut to $max (2.17.1: the shared Clean::text()). */
    private static function text(mixed $v, int $max): ?string
    {
        return \Align\Providers\Clean::text($v, $max);
    }

    /** A client name compared as Tactical stores it (exact, but surrounding spaces and case aside). */
    private static function nameKey(?string $n): string
    {
        return mb_strtolower(trim((string) $n));
    }

    /**
     * Every Tactical client with a valid id. The description lists its sites ("Sites: Main office, Warehouse"), so a
     * client linked on Client mapping shows what it covers.
     */
    public function organizations(): array
    {
        $out = [];
        foreach ($this->list('/clients/') as $c) {
            $id = self::clientId($c['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $sites = array_values(array_filter(array_map(fn($s) => is_array($s) ? self::text($s['name'] ?? null, 120) : null, is_array($c['sites'] ?? null) ? $c['sites'] : [])));
            $out[] = ['id' => $id, 'name' => self::text($c['name'] ?? null, 255) ?? 'Client ' . $id,
                'description' => $sites ? mb_substr('Sites: ' . implode(', ', $sites), 0, 5000) : null];
        }
        return $out;
    }

    /**
     * Every agent with a valid id, as a neutral device. Its organization is the Tactical client with its client name
     * (null when none matches). Servers are servers; workstations are laptops or desktops by their model, Macs too.
     */
    public function devices(): array
    {
        // An agent names its client: the exact name first; else one that differs only in case, when just one does
        // (Tactical's names are unique as typed, so "Acme" and "ACME" can both exist: neither gets the other's agents)
        $exact = $folded = [];
        foreach ($this->organizations() as $o) {
            $exact[$o['name']] = $o['id'];
            $folded[self::nameKey($o['name'])][] = $o['id'];
        }
        $orgFor = function (?string $name) use ($exact, $folded): ?string {
            if ($name === null) {
                return null;
            }
            $f = $folded[self::nameKey($name)] ?? [];
            return $exact[$name] ?? (count($f) === 1 ? $f[0] : null);
        };
        $out = [];
        foreach ($this->list('/agents/?detail=true') as $a) {
            $id = self::agentId($a['agent_id'] ?? null);
            if ($id === null) {
                continue;
            }
            [$make, $model] = self::makeModel($a['make_model'] ?? null);
            $plat = strtolower((string) self::text($a['plat'] ?? null, 20));
            $mon = strtolower((string) self::text($a['monitoring_type'] ?? null, 20));
            $isVirtual = preg_match('/vmware|virtualbox|kvm|qemu|xen|virtual machine|hyper-v|parallels|innotek/i', "$make $model") === 1;
            [$osName, $osBuild] = self::os($a['operating_system'] ?? null);
            $host = self::text($a['hostname'] ?? null, 255);
            // NinjaOne's node classes give the type rules Align already has (server, workstation, Mac)
            $nodeClass = $mon === 'server' ? 'WINDOWS_SERVER' : ($plat === 'darwin' ? 'MAC' : 'WINDOWS_WORKSTATION');
            $type = \Align\Integrations\NinjaOne::type($nodeClass, '', (string) $make, (string) $model, (string) $host, $isVirtual, (string) $osName);
            $user = self::text($a['logged_username'] ?? null, 190);
            $out[] = [
                'id' => $id,
                'org_id' => $orgFor(self::text($a['client_name'] ?? null, 255)),
                'display_name' => $host,
                'system_name' => $host,
                'node_class' => self::text(trim("$mon $plat"), 60),
                'device_type' => $type,
                'manufacturer' => $make,
                'model' => $model,
                'serial' => self::text($a['serial_number'] ?? null, 190),
                'chassis' => null,
                'is_virtual' => $isVirtual,
                'os_name' => $osName,
                'os_build' => $osBuild,
                'os_release_id' => null,
                'last_contact' => self::time($a['last_seen'] ?? null),
                'created_at' => null,
                'last_user' => $user === '-' ? null : $user,
                'offline' => strtolower((string) self::text($a['status'] ?? null, 20)) !== 'online',
            ];
        }
        return $out;
    }

    /**
     * Manufacturer and model from Tactical's one "make model" text ("Dell Inc. OptiPlex 7090"): a known maker's name
     * at the start, else the first word. [null, null] for Tactical's "couldn't read it" placeholders.
     */
    public static function makeModel(mixed $v): array
    {
        $s = self::text($v, 380);
        if ($s === null || in_array(strtolower($s), self::NO_MAKE, true)) {
            return [null, null];
        }
        foreach (self::MAKERS as $m) {
            if (stripos($s, $m . ' ') === 0) {
                return [mb_substr($m, 0, 190), self::text(substr($s, strlen($m)), 190)];
            }
        }
        $parts = preg_split('/\s+/', $s, 2) ?: [$s];
        return [mb_substr($parts[0], 0, 190), isset($parts[1]) ? self::text($parts[1], 190) : null];
    }

    /** OS name and build from Tactical's text ("Windows 11 Pro, 64 bit (build 22631)" => Windows 11 Pro, 22631). */
    public static function os(mixed $v): array
    {
        $s = self::text($v, 255);
        if ($s === null) {
            return [null, null];
        }
        $build = preg_match('/\(build (\d{3,6})(?:\.\d+)?\)/i', $s, $m) ? $m[1] : null;
        $name = trim((string) preg_replace(['/\s*\(build [^)]*\)/i', '/,\s*(32|64)[ -]?bit\b/i'], '', $s));
        return [self::text($name, 255), $build];
    }

    /** Tactical's ISO time ("2026-10-10T17:13:34.123456Z") in this server's timezone (Y-m-d H:i:s), or null. */
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

    /** Tactical has no page address for one agent: no link. */
    public function deviceUrl(string $deviceId): ?string
    {
        return null;
    }
}
