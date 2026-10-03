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
 *
 * Security assumptions: the URL and key are admin settings (the URL checked by Connector::save and by HttpClient on
 * every request); the key goes only in the Authorization header. Replies are untrusted: a page that isn't
 * {"data": [...]} or a plain list is an error, never "no records" (BackupSync deletes jobs and machines the
 * product no longer reports, so an HTML page read as empty would wipe them); rows that aren't arrays are dropped;
 * paging stops on a repeat or after 400 pages.
 */
final class VeeamSpc
{
    private const PAGE = 500;
    public const ZERO_UID = '00000000-0000-0000-0000-000000000000';

    private HttpClient $http;

    /** $url: the console address (with or without /api/v3). */
    public function __construct(private string $url, private string $apiKey)
    {
        $this->http = new HttpClient(120);
    }

    /** Whether the URL and key are saved. */
    public static function configured(): bool
    {
        return (string) Settings::get('veeam_url') !== '' && Settings::hasSecret('veeam_api_key');
    }

    /** From the saved veeam_url and veeam_api_key. Throws when either is missing. */
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

    /** GET an API path with the key; returns the decoded JSON (untrusted). */
    private function get(string $path, array $query = []): mixed
    {
        $url = $this->base() . $path . ($query ? '?' . http_build_query($query) : '');
        return $this->http->getJson($url, ['Authorization' => 'Bearer ' . $this->apiKey]);
    }

    /** Walks every page of a list endpoint. Throws when a page isn't a list of records or repeats the last one. */
    private function paginate(string $path): array
    {
        $all = [];
        $lastHash = null;
        for ($offset = 0, $page = 0; $page < 400; $page++) {
            $r = $this->get($path, ['limit' => self::PAGE, 'offset' => $offset]);
            if (is_array($r) && is_array($r['data'] ?? null) && array_is_list($r['data'])) {
                $rows = $r['data'];
            } elseif (is_array($r) && array_is_list($r)) {
                $rows = $r;
            } else {
                throw new \RuntimeException('Veeam sent something other than a list for ' . $path . '. Check the console URL.');
            }
            $rows = array_values(array_filter($rows, 'is_array'));
            // A console that ignores offset sends the same full page every time
            $hash = $rows ? md5(serialize($rows)) : null;
            if ($hash !== null && $hash === $lastHash) {
                throw new \RuntimeException('Veeam sent the same page twice for ' . $path . ', so the read was stopped.');
            }
            $lastHash = $hash;
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
        } catch (\RuntimeException $e) {
            // an optional endpoint answering with something other than a list (2.2.1) is skipped, as before
            return null;
        }
    }

    /** Companies (tenants) in the console. */
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

    /** Jobs of Veeam Agents managed by the console (null when this console or key can't list them). */
    public function agentJobs(): ?array
    {
        return $this->optional('/infrastructure/backupAgents/jobs');
    }

    /** Protected virtual machines (null when unavailable). */
    public function protectedVms(): ?array
    {
        return $this->optional('/protectedWorkloads/virtualMachines');
    }

    /** Protected computers, managed by the console or by a backup server (each list skipped when unavailable). */
    public function protectedComputers(): array
    {
        return array_merge(
            $this->optional('/protectedWorkloads/computersManagedByConsole') ?? [],
            $this->optional('/protectedWorkloads/computersManagedByBackupServer') ?? [],
        );
    }

    /** Cloud Connect backup storage quota and use per company (path moved in VSPC 9 / API 3.6). */
    public function cloudUsage(): ?array
    {
        return $this->optional('/infrastructure/sites/tenants/backupResources/usage')
            ?? $this->optional('/organizations/companies/sites/backupResources/usage');
    }

    // ---- Veeam Backup for Microsoft 365 ----------------------------------------------------------

    /** Microsoft 365 organizations (tenants); mappedOrganizationUid is the VSPC company. Null when no VB365 server is managed. */
    public function m365Organizations(): ?array
    {
        return $this->optional('/infrastructure/vb365Servers/organizations');
    }

    /** vb365OrganizationUid → companyUid, for organizations mapped to companies. */
    public function m365CompanyMappings(): array
    {
        $out = [];
        foreach ($this->optional('/infrastructure/vb365Servers/organizations/companyMappings') ?? [] as $m) {
            if (!empty($m['vb365OrganizationUid']) && !empty($m['companyUid'])) {
                $out[(string) $m['vb365OrganizationUid']] = (string) $m['companyUid'];
            }
        }
        return $out;
    }

    /** Backup and backup copy jobs (lastStatus, lastRun, vspcOrganizationUid = company). */
    public function m365Jobs(): ?array
    {
        return $this->optional('/infrastructure/vb365Servers/organizations/jobs');
    }

    /** Protected users, groups, teams and sites. */
    public function m365ProtectedObjects(): ?array
    {
        return $this->optional('/protectedWorkloads/vb365ProtectedObjects');
    }

    /** Reads one company to prove the address and key work. Returns a short message for the admin. */
    public function test(): string
    {
        $r = $this->get('/organizations/companies', ['limit' => 1, 'offset' => 0]);
        if (!is_array($r) || !is_array($r['data'] ?? null)) {
            throw new \RuntimeException('Unexpected response: this does not look like the VSPC REST API v3.');
        }
        $total = $r['meta']['pagingInfo']['total'] ?? null;
        $n = is_numeric($total) && $total >= 0 ? (int) $total : count($r['data']);
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

    /** A Veeam job or session status as success, warning, failed, running or none (anything unknown is none). */
    public static function status(mixed $s): string
    {
        $s = is_string($s) ? strtolower($s) : '';
        return match (true) {
            $s === 'success' => 'success',
            $s === 'warning' => 'warning',
            in_array($s, ['failed', 'error', 'failure'], true) => 'failed',
            in_array($s, ['running', 'starting', 'stopping', 'working', 'inprogress', 'queued', 'waitingtape', 'waitingrepository'], true) => 'running',
            in_array($s, ['disconnected', 'notconfigured'], true) => 'warning',
            default => 'none',
        };
    }

    /** A Veeam time as Y-m-d H:i:s, or null when missing, Veeam's "never" (0001-...) or outside years 1970-9999. */
    public static function ts(mixed $v): ?string
    {
        if (!is_string($v) || $v === '' || str_starts_with($v, '0001-')) {
            return null;
        }
        $t = strtotime($v);
        return $t && $t > 0 && $t < 253402300800 ? date('Y-m-d H:i:s', $t) : null;
    }

    /** A count or size that is a number from 0 up to what an integer holds, else null (never a wrapped value). */
    public static function int(mixed $v): ?int
    {
        return (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) && $v >= 0 && (float) $v < PHP_INT_MAX ? (int) $v : null;
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

    /** Kept for callers from before 1.30; see host_key(). */
    public static function hostKey(?string $name): string
    {
        return host_key($name);
    }
}
