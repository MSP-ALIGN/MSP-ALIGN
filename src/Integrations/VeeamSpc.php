<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * Veeam Service Provider Console REST API v3 (read-only use).
 * Auth: an API key sent as a Bearer token. Create it in VSPC under
 * Configuration → Security → REST API Keys (or on a read-only portal administrator account).
 * Every list endpoint returns {"meta": {"pagingInfo": {"total", "count", "offset"}}, "data": [...]}
 * and pages with limit + offset.
 */
final class VeeamSpc
{
    private const PAGE = 500;
    private const ZERO_UID = '00000000-0000-0000-0000-000000000000';

    private HttpClient $http;

    public function __construct(private string $url, private string $apiKey)
    {
        $this->http = new HttpClient(120);
    }

    public static function configured(): bool
    {
        return (string) Settings::get('veeam_url') !== '' && Settings::hasSecret('veeam_api_key');
    }

    public static function fromSettings(): self
    {
        $url = (string) Settings::get('veeam_url');
        $key = Settings::secret('veeam_api_key');
        if ($url === '' || !$key) {
            throw new \RuntimeException('Veeam Service Provider Console is not configured (Settings > Integrations).');
        }
        return new self($url, $key);
    }

    /** https://vspc.example.com[:1280] → https://vspc.example.com[:1280]/api/v3 */
    private function base(): string
    {
        $u = rtrim($this->url, '/');
        return preg_match('#/api/v3$#i', $u) ? $u : $u . '/api/v3';
    }

    private function get(string $path, array $query = []): mixed
    {
        $url = $this->base() . $path . ($query ? '?' . http_build_query($query) : '');
        return $this->http->getJson($url, ['Authorization' => 'Bearer ' . $this->apiKey]);
    }

    /** Walks every page of a list endpoint. */
    private function paginate(string $path): array
    {
        $all = [];
        for ($offset = 0, $page = 0; $page < 400; $page++) {
            $r = $this->get($path, ['limit' => self::PAGE, 'offset' => $offset]);
            $rows = is_array($r['data'] ?? null) ? $r['data'] : (array_is_list((array) $r) ? (array) $r : []);
            $all = array_merge($all, $rows);
            $total = (int) ($r['meta']['pagingInfo']['total'] ?? 0);
            $offset += count($rows);
            if (count($rows) < self::PAGE || ($total && $offset >= $total)) {
                break;
            }
        }
        return $all;
    }

    /** Same as paginate(), but an endpoint this VSPC version or key can't use returns null instead of failing. */
    private function optional(string $path): ?array
    {
        try {
            return $this->paginate($path);
        } catch (HttpException $e) {
            if (in_array($e->status, [400, 403, 404, 405], true)) {
                return null;
            }
            throw $e;
        }
    }

    public function companies(): array
    {
        return $this->paginate('/organizations/companies');
    }

    /** Jobs on Veeam Backup & Replication servers (VM backup, replication, copy, file, agent jobs). */
    public function serverJobs(): array
    {
        return $this->paginate('/infrastructure/backupServers/jobs');
    }

    /** Veeam Agents managed by the console (name = computer name). */
    public function agents(): ?array
    {
        return $this->optional('/infrastructure/backupAgents');
    }

    public function agentJobs(): ?array
    {
        return $this->optional('/infrastructure/backupAgents/jobs');
    }

    public function protectedVms(): ?array
    {
        return $this->optional('/protectedWorkloads/virtualMachines');
    }

    public function protectedComputers(): array
    {
        return array_merge(
            $this->optional('/protectedWorkloads/computersManagedByConsole') ?? [],
            $this->optional('/protectedWorkloads/computersManagedByBackupServer') ?? [],
        );
    }

    /** Cloud Connect backup storage quota and use per company. */
    public function cloudUsage(): ?array
    {
        return $this->optional('/organizations/companies/sites/backupResources/usage');
    }

    public function test(): string
    {
        $r = $this->get('/organizations/companies', ['limit' => 1, 'offset' => 0]);
        if (!is_array($r) || !array_key_exists('data', $r)) {
            throw new \RuntimeException('Unexpected response: this does not look like the VSPC REST API v3.');
        }
        $n = (int) ($r['meta']['pagingInfo']['total'] ?? count($r['data']));
        return "Connected. $n compan" . ($n === 1 ? 'y' : 'ies') . ' visible to this API key.';
    }

    // ---- Mapping helpers ----------------------------------------------------------------------

    /** The customer a job or workload belongs to: the mapped company when set, else the owner. */
    public static function orgOf(array $r): ?string
    {
        foreach (['mappedOrganizationUid', 'organizationUid', 'companyUid'] as $k) {
            $v = $r[$k] ?? null;
            if (is_string($v) && $v !== '' && $v !== self::ZERO_UID) {
                return $v;
            }
        }
        return null;
    }

    public static function status(mixed $s): string
    {
        $s = strtolower((string) $s);
        return match (true) {
            $s === 'success' => 'success',
            $s === 'warning' => 'warning',
            in_array($s, ['failed', 'error', 'failure'], true) => 'failed',
            in_array($s, ['running', 'starting', 'stopping', 'working', 'inprogress'], true) => 'running',
            default => 'none',
        };
    }

    public static function ts(mixed $v): ?string
    {
        if (!is_string($v) || $v === '' || str_starts_with($v, '0001-')) {
            return null;
        }
        $t = strtotime($v);
        return $t && $t > 0 ? date('Y-m-d H:i:s', $t) : null;
    }

    public static function int(mixed $v): ?int
    {
        return is_numeric($v) && $v >= 0 ? (int) $v : null;
    }

    /** First non-empty value among the given keys. */
    public static function pick(array $r, array $keys): mixed
    {
        foreach ($keys as $k) {
            if (isset($r[$k]) && $r[$k] !== '') {
                return $r[$k];
            }
        }
        return null;
    }

    /** "DC01.contoso.local" → "dc01"; used to line workloads up with NinjaOne devices. */
    public static function hostKey(?string $name): string
    {
        $n = strtolower(trim((string) $name));
        if (str_contains($n, '\\')) {
            $n = substr($n, strrpos($n, '\\') + 1);
        }
        if (!filter_var($n, FILTER_VALIDATE_IP) && str_contains($n, '.')) {
            $n = substr($n, 0, strpos($n, '.'));
        }
        return $n;
    }
}
