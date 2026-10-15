<?php
declare(strict_types=1);

namespace Align\Templates\Backup;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Providers\Backup\BackupProvider;
use Align\Providers\Clean;
use Align\Settings;

/**
 * Fabrikam Backup as a backup source, read-only: customers, backup jobs with their last result, and protected
 * computers, as the snapshot Providers\Backup\BackupProvider describes (BackupSync stores it and sorts machines into
 * clients). Cloud storage and Microsoft 365 backups aren't read.
 *
 * template-only: begin
 * TEMPLATE (2.22.0). Fabrikam is a made-up product: `align make:connector backup "Name"` copies this file to
 * src/Providers/Backup/<Name>.php. The mock API it talks to in the tests is tests/mock/fabrikambackup.php.
 * template-only: end
 *
 * API: GET {address}/api/customers, /api/jobs and /api/machines, each paged with ?page=N and answering
 * {"items": [...], "next": <next page number or null>}, with the API key as a bearer token.
 *
 * SECURITY: the product's replies are untrusted. A reply without an "items" list is an error, never "no records"
 * (BackupSync prunes jobs and machines a readable list no longer has). Record uids are prefixed with the connector key
 * so they can't collide with another provider's, and kept exact (a customer uid decides which client a job or machine
 * belongs to; one too long for its column becomes a hash, never cut). Text is cleaned and cut, statuses are fixed
 * values, times must be real and sizes whole numbers. The address is an https admin setting checked by HttpClient on
 * every request; the key goes only in the Authorization header.
 */
final class FabrikamBackup implements BackupProvider
{
    /** Pages read from one list before giving up (a server that ignores ?page= would answer for ever). */
    private const MAX_PAGES = 2000;

    private HttpClient $http;

    /** $url: the API address; $apiKey: the key. */
    public function __construct(private string $url, private string $apiKey)
    {
        $this->http = new HttpClient(120, 3);
    }

    /** From the saved fabrikambackup_url and fabrikambackup_api_key. Throws when not set up. */
    public static function fromSettings(): self
    {
        $url = (string) Settings::get('fabrikambackup_url');
        $key = Settings::secret('fabrikambackup_api_key');
        if ($url === '' || !$key) {
            throw new \RuntimeException('Fabrikam Backup is not configured (Integrations).');
        }
        return new self($url, $key);
    }

    /** The connector key (fixed; stored with every record from this provider). */
    public function key(): string
    {
        return 'fabrikambackup';
    }

    /** Short display name. */
    public function name(): string
    {
        return 'Fabrikam Backup';
    }

    /** Computer (agent) backups only (see BackupProvider::CAPABILITIES). */
    public function supports(string $capability): bool
    {
        return $capability === 'agents';
    }

    /** Checks the address and key with one read (admin, Integrations page). */
    public function test(): string
    {
        $n = count(array_filter($this->all('/api/customers'), fn($c) => $this->uid($c['id'] ?? null) !== null)); // as snapshot() keeps them
        return "Connected. $n customer" . ($n === 1 ? '' : 's') . ' readable.';
    }

    /** Every item of a paged list; throws on an error, a reply without items, or a page number that doesn't move on. */
    private function all(string $path): array
    {
        $out = [];
        $page = 1;
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            try {
                $j = $this->http->getJson(rtrim($this->url, '/') . $path . '?page=' . $page, ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json']);
            } catch (HttpException $e) {
                throw new \RuntimeException(match (true) {
                    in_array($e->status, [401, 403], true) => 'Fabrikam Backup refused the API key (HTTP ' . $e->status . '). Check the key and its permissions.',
                    $e->status === 404 => 'Fabrikam Backup has no ' . $path . ' at that address (HTTP 404). Check the API address.',
                    default => 'Fabrikam Backup couldn\'t be read: ' . $e->getMessage(),
                }, 0, $e);
            }
            if (!is_array($j) || !is_array($j['items'] ?? null) || !array_is_list($j['items'])) {
                throw new \RuntimeException('Fabrikam Backup sent something other than a list for ' . $path . '. Check the API address.');
            }
            array_push($out, ...array_filter($j['items'], 'is_array'));
            $next = $j['next'] ?? null;
            if ($next === null || $next === '' || $next === false) {
                return $out;
            }
            if (!is_int($next) || $next <= $page) {
                throw new \RuntimeException('Fabrikam Backup sent a page number that doesn\'t move forward for ' . $path . '.');
            }
            $page = $next;
        }
        throw new \RuntimeException('Fabrikam Backup sent more than ' . self::MAX_PAGES . ' pages for ' . $path . '.');
    }

    /**
     * The product's id as a uid unique across providers ("fabrikambackup:42"), fitting a $max-character column: null
     * when missing or not text or a whole number, a hash when too long (never cut, so two records can't become one).
     */
    private function uid(mixed $v, int $max = 64): ?string
    {
        if (!(is_string($v) && trim($v) !== '' && !preg_match('/[\x00-\x1F\x7F]/', $v)) && !is_int($v)) {
            return null;
        }
        $s = $this->key() . ':' . $v;
        return strlen($s) > $max ? 'sha1:' . sha1($s) : $s;
    }

    /** A whole number of 0 or more (sizes, counts, seconds), or null. */
    private static function int(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 ? $v : (is_string($v) && preg_match('/^[0-9]{1,18}$/', $v) ? (int) $v : null);
    }

    /** An ISO time in this server's timezone (Y-m-d H:i:s), or null. */
    private static function ts(mixed $v): ?string
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

    /** The product's job result as one of BackupSync's statuses (anything unknown is none). */
    private static function status(mixed $v): string
    {
        return match (strtolower((string) Clean::text($v, 20))) {
            'success', 'succeeded', 'ok' => 'success',
            'warning' => 'warning',
            'failed', 'error' => 'failed',
            'running' => 'running',
            default => 'none',
        };
    }

    /** Reads everything (see BackupProvider). Called by the sync only. */
    public function snapshot(callable $info): array
    {
        $companies = [];
        foreach ($this->all('/api/customers') as $c) {
            if (($uid = $this->uid($c['id'] ?? null)) !== null) {
                $companies[] = ['uid' => $uid, 'name' => Clean::text($c['name'] ?? null, 255) ?? $uid, 'status' => Clean::text($c['status'] ?? null, 40)];
            }
        }
        $jobs = [];
        foreach ($this->all('/api/jobs') as $j) {
            if (($uid = $this->uid($j['id'] ?? null)) === null) {
                continue;
            }
            $jobs[] = [
                'uid' => $uid,
                'company_uid' => $this->uid($j['customer_id'] ?? null),
                'source' => 'agent',
                'agent_uid' => $this->uid($j['machine_id'] ?? null),
                'name' => Clean::text($j['name'] ?? null, 255) ?? 'Backup job',
                'job_type' => Clean::text($j['type'] ?? null, 60),
                'status' => self::status($j['last_result'] ?? null),
                'is_enabled' => ($j['enabled'] ?? true) === false ? 0 : 1,
                'last_run' => self::ts($j['last_run'] ?? null),
                'last_end' => self::ts($j['last_end'] ?? null),
                'duration_sec' => self::int($j['duration_seconds'] ?? null),
                'failure_message' => Clean::text($j['message'] ?? null, 2000),
                'target' => Clean::text($j['destination'] ?? null, 255),
                'chain_bytes' => self::int($j['size_bytes'] ?? null),
            ];
        }
        $info(count($jobs) . ' Fabrikam Backup jobs read');
        $byMachine = []; // machine uid => its jobs' uids
        foreach ($jobs as $j) {
            if ($j['agent_uid'] !== null) {
                $byMachine[$j['agent_uid']][] = $j['uid'];
            }
        }
        $workloads = [];
        foreach ($this->all('/api/machines') as $m) {
            $uid = $this->uid(isset($m['id']) && (is_string($m['id']) || is_int($m['id'])) ? 'computer:' . $m['id'] : null, 100);
            $name = Clean::text($m['name'] ?? null, 255);
            if ($uid === null || $name === null) {
                continue;
            }
            $agent = $this->uid($m['id']);
            $workloads[] = [
                'uid' => $uid,
                'company_uid' => $this->uid($m['customer_id'] ?? null),
                'kind' => 'computer',
                'name' => $name,
                'hostname' => mb_substr(host_key((string) (Clean::text($m['hostname'] ?? null, 255) ?? $name)), 0, 190) ?: null,
                'last_point' => self::ts($m['last_backup'] ?? null),
                'restore_points' => self::int($m['restore_points'] ?? null),
                'backup_bytes' => self::int($m['backup_bytes'] ?? null),
                'source_bytes' => self::int($m['source_bytes'] ?? null),
                'job_uids' => $agent !== null ? $byMachine[$agent] ?? [] : [],
            ];
        }
        return [
            'companies' => $companies,
            'cloud' => null,
            'jobs' => $jobs,
            'job_lists' => ['agent'],
            'workloads' => $workloads,
            'workload_lists' => ['computer'],
            'm365' => null,
        ];
    }
}
