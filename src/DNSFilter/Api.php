<?php
declare(strict_types=1);

namespace Align\DNSFilter;

use Align\Config;
use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Integrations\SaasStore;
use Align\Providers\Clean;
use Align\Settings;

/**
 * 2.37.0 DNSFilter's API (https://api.dnsfilter.com, OpenAPI spec at /docs/api.json, built against the 2026-11-06
 * version): the customer organizations an MSP's key can read, each organization's roaming clients and networks, and
 * the daily request, blocked and threat totals per organization. Read only: every call is a GET.
 *
 * API: the key goes in the Authorization header as it is (no "Bearer"). Lists are JSON:API ({"data": [{"id", "type",
 * "attributes", "relationships"}], "links": {"self"}}) paged with page[number] and page[size]; the links have no
 * "next", so a page shorter than the size asked for is the last. Traffic reports answer {"data": {"values": [{
 * "organization_id", "bucket", "total", "total_networks", "total_agents", "total_proxies"}]}} with one value per
 * organization and day.
 *
 * SECURITY: the key is a secret (Settings::setSecret, entered by admins on the integration page) and goes only in the
 * Authorization header of requests to DNSFilter's fixed HTTPS address; the test override (dnsfilter_api_base) applies
 * only with allow_insecure_integrations. Every reply is untrusted: a reply without the expected list is an error,
 * never "no records" (the sync would then clear stored data); ids must be 1–64 printable characters (SaasStore::id)
 * and are kept exact; text is cleaned (Clean::text) and cut to its column; numbers must be whole and not negative;
 * dates must be real; statuses outside DNSFilter's documented values are kept as null.
 */
final class Api
{
    /** Items asked for per page (DNSFilter's largest). */
    public const PAGE = 100;
    /** Pages read before giving up, so a server that ignores page[number] can't keep the sync reading for ever. */
    private const MAX_PAGES = 200;
    /** Organizations per traffic report request (they go comma-separated in the URL). */
    private const REPORT_CHUNK = 50;
    /** Documented roaming client statuses and states (UserAgent.status and the status filter, UserAgent.agent_state). */
    public const STATUSES = ['active', 'disabled', 'uninstalled', 'uninstalled_from_client', 'created', 'uninstall_queued', 'uninstalling'];
    public const STATES = ['protected', 'unprotected', 'bypassed', 'uninstalled', 'offline', 'pending_uninstall'];
    /** Documented agent types; proxies and relays aren't roaming clients on a computer. */
    public const TYPES = ['windows', 'macos', 'linux', 'android', 'ios', 'embedded', 'dnsmasq', 'fingbox', 'proxy'];

    private HttpClient $http;

    /** $interactive: a person waits (the Test button), so one short try. */
    public function __construct(private string $key, bool $interactive = false)
    {
        $this->http = $interactive ? new HttpClient(20, 1) : new HttpClient(60, 3);
    }

    /** Whether the API key is saved. */
    public static function configured(): bool
    {
        return Settings::hasSecret('dnsfilter_api_key');
    }

    /** From the saved key. Throws when it isn't set up. */
    public static function fromSettings(bool $interactive = false): self
    {
        $key = Settings::secret('dnsfilter_api_key');
        if (!$key) {
            throw new \RuntimeException('DNSFilter isn\'t set up: save the API key under Integrations → DNSFilter.');
        }
        return new self($key, $interactive);
    }

    /** The API's address (DNSFilter's; the test override only with allow_insecure_integrations). */
    public static function base(): string
    {
        $o = Config::get('allow_insecure_integrations', false) ? Settings::get('dnsfilter_api_base') : null;
        return rtrim((string) ($o ?: 'https://api.dnsfilter.com'), '/');
    }

    /** Checks the key: who it belongs to and how many organizations it reads. */
    public function test(): string
    {
        $me = $this->get('/v1/current_user');
        $name = Clean::text($me['data']['attributes']['name'] ?? null, 120);
        $n = count($this->organizations());
        return 'Connected to DNSFilter' . ($name !== null ? " as $name" : '') . ". $n organization" . ($n === 1 ? '' : 's') . ' readable.';
    }

    /**
     * One GET of $path (fixed by the caller) with $query encoded. Returns the JSON object. Throws \RuntimeException
     * with a message an admin can act on; the key never appears in it.
     */
    public function get(string $path, array $query = []): array
    {
        try {
            $j = $this->http->getJson(self::base() . $path . ($query ? '?' . http_build_query($query) : ''), ['Authorization' => $this->key]);
        } catch (HttpException $e) {
            throw new \RuntimeException(match (true) {
                $e->status === 401 => 'DNSFilter refused the API key (401). Create a new key under Account Settings → API Keys in DNSFilter and save it here.',
                $e->status === 403 => 'DNSFilter says this key can\'t read that (403). Create the key as a user who can see every customer organization.',
                $e->status === 429 => 'DNSFilter is limiting requests (429). Align tries again on the next sync.',
                default => 'DNSFilter couldn\'t be read: ' . $e->getMessage(),
            }, 0, $e);
        }
        if (!is_array($j)) {
            throw new \RuntimeException('DNSFilter didn\'t answer with JSON. Is the address right?');
        }
        return $j;
    }

    /**
     * Every item of a paged list. The links have no "next", so the list ends at an empty page or one shorter than the
     * first (not shorter than PAGE: DNSFilter may lower its cap, and a cut list would remove roaming clients). Throws
     * when a page isn't a list, when a page repeats the one before (page[number] ignored) or after MAX_PAGES, so a
     * half list is never taken as the whole.
     */
    public function all(string $path, array $query = [], string $what = 'records'): array
    {
        $out = [];
        $prev = null;
        $full = null; // the first page's length: what a full page holds
        for ($n = 1; $n <= self::MAX_PAGES; $n++) {
            $j = $this->get($path, $query + ['page' => ['number' => $n, 'size' => self::PAGE]]);
            $list = $j['data'] ?? null;
            if (!is_array($list) || !array_is_list($list)) {
                throw new \RuntimeException("DNSFilter didn't answer with a list of $what.");
            }
            $first = $list ? json_encode($list[0]) : null;
            if ($first !== null && $first === $prev) {
                throw new \RuntimeException("DNSFilter sent the same page of $what twice.");
            }
            $prev = $first;
            array_push($out, ...array_values(array_filter($list, 'is_array')));
            $full ??= count($list);
            if (!$list || count($list) < $full) {
                return $out;
            }
        }
        throw new \RuntimeException("DNSFilter sent more than " . self::MAX_PAGES . " pages of $what.");
    }

    /**
     * The organizations the key reads, without canceled ones: [['id', 'name']]. An organization without a usable id
     * or name is left out.
     */
    public function organizations(): array
    {
        $out = [];
        foreach ($this->all('/v1/organizations', [], 'organizations') as $o) {
            $a = is_array($o['attributes'] ?? null) ? $o['attributes'] : [];
            $id = self::orgId($o['id'] ?? null);
            $name = Clean::text($a['name'] ?? null, 255);
            if ($id === null || $name === null || ($a['canceled'] ?? false) === true) {
                continue;
            }
            $out[$id] = ['id' => $id, 'name' => $name];
        }
        return array_values($out);
    }

    /**
     * One organization's roaming clients (proxies and relays aren't asked for): [['agent_id', 'hostname',
     * 'friendly_name', 'agent_type', 'os_name', 'agent_version', 'status', 'agent_state', 'last_sync']].
     */
    public function agents(string $orgId): array
    {
        $out = [];
        foreach ($this->all('/v1/user_agents', ['organization_ids' => $orgId, 'type' => 'agents'], 'roaming clients') as $u) {
            $a = is_array($u['attributes'] ?? null) ? $u['attributes'] : [];
            $id = SaasStore::id($u['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $type = is_string($a['agent_type'] ?? null) && in_array($a['agent_type'], self::TYPES, true) ? $a['agent_type'] : null;
            if ($type === 'proxy') {
                continue; // a LAN proxy relays a network's traffic: not a roaming client on a computer
            }
            $out[$id] = ['agent_id' => $id,
                'hostname' => Clean::text($a['hostname'] ?? null, 255), 'friendly_name' => Clean::text($a['friendly_name'] ?? null, 255),
                'agent_type' => $type, 'os_name' => Clean::text($a['os_name'] ?? null, 120), 'agent_version' => Clean::text($a['agent_version'] ?? null, 60),
                'status' => is_string($a['status'] ?? null) && in_array($a['status'], self::STATUSES, true) ? $a['status'] : null,
                'agent_state' => is_string($a['agent_state'] ?? null) && in_array($a['agent_state'], self::STATES, true) ? $a['agent_state'] : null,
                'last_sync' => self::time($a['last_sync'] ?? null)];
        }
        return array_values($out);
    }

    /**
     * One organization's networks (sites), without deleted ones: [['id', 'name', 'policy' (has a filtering policy, a
     * scheduled policy or policies: DNSFilter's "protected")]]. Throws when a network says it belongs to another
     * organization.
     */
    public function networks(string $orgId): array
    {
        $out = [];
        foreach ($this->all('/v1/networks/msp', ['organization_id' => $orgId, 'force_truncate_ips' => 'true'], 'networks') as $n) {
            $a = is_array($n['attributes'] ?? null) ? $n['attributes'] : [];
            $id = SaasStore::id($n['id'] ?? null);
            if ($id === null || !empty($a['deleted_at'])) {
                continue;
            }
            // A network of another organization means the filter wasn't applied: never file it under this one
            $owner = $n['relationships']['organization']['data']['id'] ?? null;
            if ($owner !== null && self::orgId($owner) !== $orgId) {
                throw new \RuntimeException('DNSFilter answered with networks of another organization. Nothing was changed.');
            }
            // Filtered by a policy, a scheduled policy or a set of policies
            $rel = is_array($n['relationships'] ?? null) ? $n['relationships'] : [];
            $has = fn($r) => is_array($r) && SaasStore::id($r['id'] ?? null) !== null;
            $policy = $has($rel['policy']['data'] ?? null) || $has($rel['scheduled_policy']['data'] ?? null)
                || (is_array($rel['policies']['data'] ?? null) && array_filter($rel['policies']['data'], $has));
            $out[$id] = ['id' => $id, 'name' => Clean::text($a['name'] ?? null, 255), 'policy' => $policy];
        }
        return array_values($out);
    }

    /**
     * Daily totals of one traffic report for the given organizations between $from and $to (Y-m-d, UTC, both days
     * included): ['days' => [org id => [Y-m-d => ['total', 'networks', 'agents']]], 'refused' => [org ids]].
     * $report: total_requests_organizations or total_threats_organizations; $extra: more query values (type=blocked).
     * DNSFilter refuses a whole request (403) when it can't report on one of its organizations, so a refused request
     * is asked again one organization at a time and those it still refuses are listed (their totals stay unknown).
     * Values without a known organization, a real day or whole numbers are left out; a reply without the values list
     * throws.
     */
    public function daily(string $report, array $orgIds, string $from, string $to, array $extra = []): array
    {
        if (!in_array($report, ['total_requests_organizations', 'total_threats_organizations'], true)) {
            throw new \InvalidArgumentException('Unknown report.');
        }
        $orgIds = array_values(array_filter($orgIds, fn($i) => self::orgId($i) !== null));
        $known = array_flip($orgIds);
        $out = $refused = [];
        $ask = fn(array $ids) => $this->get("/v1/traffic_reports/$report", ['organization_ids' => implode(',', $ids), 'from' => $from, 'to' => $to,
            'show_individual_organizations' => 'true', 'bucket_size' => '1day'] + $extra);
        $replies = [];
        foreach (array_chunk($orgIds, self::REPORT_CHUNK) as $chunk) {
            try {
                $replies[] = $ask($chunk);
            } catch (\RuntimeException $e) {
                if (!($e->getPrevious() instanceof HttpException) || $e->getPrevious()->status !== 403) {
                    throw $e;
                }
                foreach ($chunk as $one) {
                    try {
                        $replies[] = $ask([$one]);
                    } catch (\RuntimeException $e1) {
                        if (!($e1->getPrevious() instanceof HttpException) || $e1->getPrevious()->status !== 403) {
                            throw $e1;
                        }
                        $refused[] = $one;
                    }
                }
            }
        }
        foreach ($replies as $j) {
            $vals = $j['data']['values'] ?? null;
            if (!is_array($vals) || !array_is_list($vals)) {
                throw new \RuntimeException('DNSFilter didn\'t answer with report values.');
            }
            foreach ($vals as $v) {
                $org = is_array($v) ? self::orgId($v['organization_id'] ?? null) : null;
                $day = is_array($v) && is_string($v['bucket'] ?? null) ? self::day($v['bucket']) : null;
                $total = is_array($v) ? self::int($v['total'] ?? null) : null;
                if ($org === null || !isset($known[$org]) || $day === null || $total === null) {
                    continue;
                }
                $cur = $out[$org][$day] ?? ['total' => 0, 'networks' => 0, 'agents' => 0];
                $out[$org][$day] = ['total' => $cur['total'] + $total, 'networks' => $cur['networks'] + (self::int($v['total_networks'] ?? null) ?? 0),
                    'agents' => $cur['agents'] + (self::int($v['total_agents'] ?? null) ?? 0)];
            }
        }
        return ['days' => $out, 'refused' => $refused];
    }

    /** An organization id: DNSFilter's are integers (sent as text in JSON:API), so digits only, else null. */
    public static function orgId(mixed $v): ?string
    {
        $v = is_int($v) ? (string) $v : $v;
        return is_string($v) && preg_match('/^[1-9][0-9]{0,18}$/', $v) ? $v : null;
    }

    /** A whole number of 0 or more (an int, or digits in a string), or null. */
    public static function int(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 ? $v : (is_string($v) && preg_match('/^[0-9]{1,15}$/', $v) ? (int) $v : null);
    }

    /** A date or ISO time as Y-m-d H:i:s in this server's timezone, or null when it isn't a real one. */
    public static function time(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $v)) {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($v, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
        // Reject dates PHP rolled over (2026-02-30)
        if ($d->format('Y-m-d') !== substr($v, 0, 10)) {
            return null;
        }
        return $d->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /** A report bucket's day (UTC, Y-m-d), or null. */
    private static function day(string $v): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return "$m[1]-$m[2]-$m[3]";
    }
}
