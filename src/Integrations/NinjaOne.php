<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\Http\HttpClient;
use Align\Settings;

/**
 * NinjaOne public API v2 (read-only use).
 * Auth: OAuth2 client credentials, scope "monitoring".
 * Create the app in NinjaOne: Administration > Apps > API > Client App IDs,
 * platform "API Services (machine-to-machine)", grant type "Client Credentials", scope "Monitoring".
 */
final class NinjaOne
{
    public const INSTANCES = [
        'app.ninjarmm.com' => 'US (app.ninjarmm.com)',
        'us2.ninjarmm.com' => 'US2 (us2.ninjarmm.com)',
        'ca.ninjarmm.com' => 'Canada (ca.ninjarmm.com)',
        'eu.ninjarmm.com' => 'EU (eu.ninjarmm.com)',
        'oc.ninjarmm.com' => 'Oceania (oc.ninjarmm.com)',
    ];

    private ?string $token = null;
    private int $tokenExpires = 0;
    private HttpClient $http;

    public function __construct(
        private string $instance,
        private string $clientId,
        private string $clientSecret,
    ) {
        $this->http = new HttpClient(90);
    }

    public static function fromSettings(): self
    {
        $id = Settings::get('ninja_client_id');
        $secret = Settings::secret('ninja_client_secret');
        if (!$id || !$secret) {
            throw new \RuntimeException('NinjaOne is not configured (Settings > Integrations).');
        }
        return new self(Settings::get('ninja_instance', 'app.ninjarmm.com'), $id, $secret);
    }

    private function base(): string
    {
        return str_contains($this->instance, '://') ? rtrim($this->instance, '/') : 'https://' . $this->instance;
    }

    private function token(): string
    {
        if ($this->token && time() < $this->tokenExpires - 60) {
            return $this->token;
        }
        $r = $this->http->request('POST', $this->base() . '/ws/oauth/token', ['Accept' => 'application/json'], [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'monitoring',
        ]);
        $tok = $r['json']['access_token'] ?? null;
        if (!$tok) {
            throw new \RuntimeException('NinjaOne did not return an access token.');
        }
        $this->token = $tok;
        $this->tokenExpires = time() + (int) ($r['json']['expires_in'] ?? 3600);
        return $tok;
    }

    private function get(string $path, array $query = []): mixed
    {
        $url = $this->base() . $path . ($query ? '?' . http_build_query($query) : '');
        return $this->http->getJson($url, ['Authorization' => 'Bearer ' . $this->token()]);
    }

    /** @return array<int, array> */
    public function organizations(): array
    {
        return $this->paginate('/v2/organizations', 500);
    }

    /** Detailed device list (includes system, os, nodeClass). */
    public function devicesDetailed(): array
    {
        return $this->paginate('/v2/devices-detailed', 1000);
    }

    /**
     * NinjaOne list endpoints page with pageSize + after (= last id of the previous page).
     * Guards against a server that ignores "after" so we never loop forever.
     */
    private function paginate(string $path, int $pageSize): array
    {
        $all = [];
        $seen = [];
        $after = null;
        for ($page = 0; $page < 1000; $page++) {
            $q = ['pageSize' => $pageSize];
            if ($after !== null) {
                $q['after'] = $after;
            }
            $rows = $this->get($path, $q);
            if (!is_array($rows) || !$rows) {
                break;
            }
            $new = 0;
            foreach ($rows as $row) {
                $id = $row['id'] ?? null;
                if ($id === null || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $all[] = $row;
                $new++;
            }
            if ($new === 0 || count($rows) < $pageSize) {
                break;
            }
            $after = max(array_column($rows, 'id'));
        }
        return $all;
    }

    public function test(): string
    {
        $orgs = $this->get('/v2/organizations', ['pageSize' => 1]);
        return 'Connected. Token issued' . (is_array($orgs) ? ' and organizations are readable.' : '.');
    }

    /** Maps a NinjaOne device record to our device columns. */
    public static function mapDevice(array $d): array
    {
        $sys = $d['system'] ?? [];
        $os = $d['os'] ?? [];
        $nodeClass = (string) ($d['nodeClass'] ?? '');
        $manufacturer = trim((string) ($sys['manufacturer'] ?? ''));
        $model = trim((string) ($sys['model'] ?? ''));
        $isVirtual = !empty($sys['virtualMachine'])
            || preg_match('/vmware|virtualbox|kvm|qemu|xen|virtual machine|hvm domu/i', "$manufacturer $model") === 1;
        $chassis = strtoupper((string) ($sys['chassisType'] ?? ''));

        $build = (string) ($os['buildNumber'] ?? '');
        if (preg_match('/^\d+\.\d+\.(\d+)/', $build, $m)) {
            $build = $m[1];
        }

        return [
            'ninja_device_id' => (int) $d['id'],
            'ninja_org_id' => isset($d['organizationId']) ? (int) $d['organizationId'] : null,
            'display_name' => $d['displayName'] ?? null,
            'system_name' => $d['systemName'] ?? ($d['dnsName'] ?? null),
            'node_class' => $nodeClass ?: null,
            'device_type' => $type = self::type($nodeClass, $chassis, $manufacturer, $model, (string) ($d['displayName'] ?? ''), $isVirtual, (string) ($os['name'] ?? '')),
            'device_class' => \Align\Lifecycle\Lifecycle::TYPES[$type][0],
            'manufacturer' => $manufacturer ?: null,
            'model' => $model ?: null,
            'serial' => normalize_serial($sys['serialNumber'] ?? null) ?? normalize_serial($sys['biosSerialNumber'] ?? null),
            'chassis' => $chassis ?: null,
            'is_virtual' => $isVirtual ? 1 : 0,
            'os_name' => $os['name'] ?? null,
            'os_build' => $build ?: null,
            'os_release_id' => $os['releaseId'] ?? null,
            'last_contact' => self::ts($d['lastContact'] ?? null),
            'last_user' => self::lastUser($d),
            'ninja_created' => self::ts($d['created'] ?? null),
            'offline' => !empty($d['offline']) ? 1 : 0,
        ];
    }

    public static function classify(string $nodeClass, string $chassis, string $model, bool $isVirtual): string
    {
        $nc = strtoupper($nodeClass);
        if (str_contains($nc, 'SERVER') || in_array($nc, ['VMWARE_VM_HOST', 'HYPERV_VMM_HOST'], true)) {
            return 'server';
        }
        if (str_starts_with($nc, 'NMS_')) {
            return match ($nc) {
                'NMS_PRINTER', 'NMS_SCANNER' => 'printer',
                'NMS_SERVER', 'NMS_VM_HOST' => 'server',
                'NMS_COMPUTER' => 'desktop',
                'NMS_VIRTUAL_MACHINE' => 'server',
                'NMS_PHONE', 'NMS_OTHER', 'NMS_APPLIANCE' => 'other',
                default => 'network',
            };
        }
        if (str_contains($nc, 'WORKSTATION') || $nc === 'MAC') {
            if (in_array($chassis, ['LAPTOP', 'NOTEBOOK', 'PORTABLE', 'TABLET', 'CONVERTIBLE', 'DETACHABLE'], true)) {
                return 'laptop';
            }
            if (in_array($chassis, ['DESKTOP', 'TOWER', 'MINI_TOWER', 'ALL_IN_ONE', 'MINI_PC', 'SFF'], true)) {
                return 'desktop';
            }
            $laptopHints = '/latitude|thinkpad|elitebook|probook|zbook|xps 1[3-7]|inspiron 1[3-7]|vostro 1[3-7]|surface|macbook|precision [357]5\d\d|ideapad|yoga|travelmate|spectre|envy x360|dragonfly|galaxy book|vivobook|zenbook/i';
            return preg_match($laptopHints, $model) === 1 ? 'laptop' : 'desktop';
        }
        return 'other';
    }

    /** Device type (see Lifecycle::TYPES) from NinjaOne's node class and hardware details. */
    public static function type(string $nodeClass, string $chassis, string $manufacturer, string $model, string $name, bool $isVirtual, string $osName): string
    {
        $nc = strtoupper($nodeClass);
        if ($isVirtual || $nc === 'NMS_VIRTUAL_MACHINE') {
            return \Align\Lifecycle\Lifecycle::virtualType($osName, $nodeClass);
        }
        if (\Align\Lifecycle\Lifecycle::looksLikeUps($manufacturer, $model, $name)) {
            return 'UPS';
        }
        return match (true) {
            in_array($nc, ['VMWARE_VM_HOST', 'HYPERV_VMM_HOST', 'NMS_VM_HOST'], true) => 'Hypervisor host',
            $nc === 'NMS_FIREWALL' => 'Firewall',
            in_array($nc, ['NMS_ROUTER', 'NMS_PRIVATE_NETWORK_GATEWAY'], true) => 'Router',
            $nc === 'NMS_SWITCH' => 'Switch',
            $nc === 'NMS_WAP' => 'Access point',
            $nc === 'NMS_PHONE' => 'Phone',
            default => \Align\Lifecycle\Lifecycle::DEFAULT_TYPE[self::classify($nodeClass, $chassis, $model, false)] ?? 'Other',
        };
    }

    /** Last logged-in user as NinjaOne reports it (e.g. "CONTOSO\\jsmith"), or null. */
    public static function lastUser(array $d): ?string
    {
        $u = $d['lastLoggedInUser'] ?? $d['lastLoggedOnUser'] ?? ($d['system']['lastLoggedInUser'] ?? null);
        $u = is_string($u) ? trim($u) : '';
        return $u !== '' ? mb_substr($u, 0, 190) : null;
    }

    /** Shows "jsmith" for "CONTOSO\\jsmith" or "jsmith@contoso.com" when a short form is wanted. */
    public static function shortUser(?string $u): string
    {
        $u = (string) $u;
        if (str_contains($u, '\\')) {
            $u = substr($u, strrpos($u, '\\') + 1);
        }
        return $u;
    }

    private static function ts(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $n = (float) $v;
            if ($n > 1e11) {
                $n /= 1000; // milliseconds
            }
            return date('Y-m-d H:i:s', (int) $n);
        }
        $t = strtotime((string) $v);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }
}
