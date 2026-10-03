<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * ITFlow API v1.
 * - Reads:  GET  /api/v1/{resource}/read.php?api_key=...&limit=&offset=
 * - Writes: POST /api/v1/{resource}/update.php with a JSON body including api_key and client_id
 * The API key runs as an ITFlow user, so that user's role controls what we can read/write.
 *
 * Security assumptions: the base URL and key are admin settings (the URL checked by Connector::save and again by
 * HttpClient on every request). ITFlow's API takes the key in the query string or the JSON body, so it must never
 * be logged with the URL: HttpClient's messages name the host only, and remote error text is cut short and has the
 * key taken out before it reaches a page or the audit log. Everything ITFlow returns is untrusted: rows are only
 * passed on when they are arrays, IDs only when they are positive whole numbers, and paging stops on a server that
 * repeats itself, so a broken or hostile ITFlow can't loop a sync forever or link a record to the wrong ID.
 */
final class Itflow
{
    /** Request bodies: the fixed keys (api_key, ids) are on the left of every +, so a field can never replace them;
     * bad UTF-8 is replaced rather than making json_encode return false (a TypeError for request()). */
    private const JSON = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    private const PAGE = 100;
    /** Most pages read of one resource in a row (1,000,000 records): the stop for a server that never ends. */
    private const MAX_PAGES = 10_000;
    private HttpClient $http;
    /** Paging per resource: pages read in a row, the last offset and a hash of its rows. */
    private array $paging = [];

    /** $interactive: a user waits on a page save, so one short attempt (the poller retries later). */
    public function __construct(private string $baseUrl, private string $apiKey, bool $interactive = false)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        // Interactive = called while a user waits on a page save: fail fast, the poller retries later.
        $this->http = $interactive ? new HttpClient(12, 1) : new HttpClient(60);
    }

    /** From the saved itflow_url and itflow_api_key. Throws when either is missing. */
    public static function fromSettings(bool $interactive = false): self
    {
        $url = Settings::get('itflow_url');
        $key = Settings::secret('itflow_api_key');
        if (!$url || !$key) {
            throw new \RuntimeException('ITFlow is not configured (Settings > Integrations).');
        }
        return new self($url, $key, $interactive);
    }

    /** The ITFlow address without a trailing slash (for links into ITFlow). */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * One GET of a resource's read.php. Returns its rows (only those that are arrays). Throws on an HTTP error, a
     * reply that isn't JSON, or paging that repeats or never ends ($query with 'offset' counts as a page).
     */
    private function read(string $resource, array $query = []): array
    {
        $url = "{$this->baseUrl}/api/v1/$resource/read.php?" . http_build_query($query + ['api_key' => $this->apiKey]);
        try {
            $json = $this->http->getJson($url);
        } catch (HttpException $e) {
            $msg = $this->remoteMessage(json_decode($e->body, true), $e->getMessage());
            throw new \RuntimeException("ITFlow $resource read failed: $msg", 0, $e);
        }
        if (!is_array($json)) {
            throw new \RuntimeException("ITFlow $resource read returned a non-JSON response. Check the ITFlow URL.");
        }
        // ITFlow returns success "False" with no data when a query simply has no rows.
        $data = $json['data'] ?? [];
        $rows = is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
        if (isset($query['offset'])) {
            $offset = (int) $query['offset'];
            $last = $this->paging[$resource] ?? ['n' => 0, 'offset' => null, 'hash' => null];
            $n = $offset === 0 ? 1 : $last['n'] + 1;
            $hash = $rows ? md5(serialize($rows)) : null;
            // A server that ignores offset sends the same full page forever
            if ($hash !== null && $last['offset'] !== $offset && $last['hash'] === $hash) {
                throw new \RuntimeException("ITFlow sent the same $resource for two different pages (it may be ignoring the offset), so the read was stopped.");
            }
            if ($n > self::MAX_PAGES) {
                throw new \RuntimeException("ITFlow kept sending more $resource after " . num(self::MAX_PAGES) . ' pages, so the read was stopped.');
            }
            $this->paging[$resource] = ['n' => $n, 'offset' => $offset, 'hash' => $hash];
        }
        return $rows;
    }

    /** Every row of a resource, page by page (stops at a short page, or as read() says). */
    private function readAll(string $resource, array $query = []): array
    {
        $all = [];
        for ($offset = 0; $offset < 1_000_000; $offset += self::PAGE) {
            $rows = $this->read($resource, $query + ['limit' => self::PAGE, 'offset' => $offset]);
            array_push($all, ...$rows);
            if (count($rows) < self::PAGE) {
                break;
            }
        }
        return $all;
    }

    /** Every client (rows as ITFlow sends them: untrusted). */
    public function clients(): array
    {
        return $this->readAll('clients');
    }

    /** Every asset (untrusted rows). */
    public function assets(): array
    {
        return $this->readAll('assets');
    }

    /** Software / licenses (ITFlow's API only supports reading these). */
    public function software(): array
    {
        return $this->readAll('software');
    }

    /** Invoices (used to estimate each client's monthly managed-services amount). */
    public function invoices(): array
    {
        return $this->readAll('invoices');
    }

    /** One page of tickets, oldest first (ITFlow orders by ticket_id). Includes the SLA fields on ITFlow 26.08+. */
    public function ticketsPage(int $offset, int $limit = self::PAGE): array
    {
        return $this->read('tickets', ['limit' => $limit, 'offset' => $offset]);
    }

    /**
     * A single ticket, or null if it no longer exists (or the API user can't see it). Only a row with that
     * ticket_id counts: an ITFlow that ignores the filter returns its first page instead.
     */
    public function ticket(int $id): ?array
    {
        foreach ($this->read('tickets', ['ticket_id' => $id]) as $r) {
            if (self::id($r['ticket_id'] ?? null) === $id) {
                return $r;
            }
        }
        return null;
    }

    /** Rows per page (callers paging tickets use the same size). */
    public static function pageSize(): int
    {
        return self::PAGE;
    }

    /** Every vendor (untrusted rows). */
    public function vendors(): array
    {
        return $this->readAll('vendors');
    }

    /** Client contacts (the primary contact fills the client's contact details). */
    public function contacts(): array
    {
        return $this->readAll('contacts');
    }

    /** Client locations (primary location = client address and main phone; also where gear lives). */
    public function locations(): array
    {
        return $this->readAll('locations');
    }

    /**
     * Maps an ITFlow asset type to an Align device type and an import category.
     * ITFlow's built-in types: Laptop, Desktop, Server, Phone, Mobile Phone, Tablet, Firewall/Router,
     * Switch, Access Point, Printer, Display, Camera, Virtual Machine, Other. Unknown/custom types
     * are matched by keyword. UPS gear (usually typed "Other") is recognized by make/model/name.
     * @return array{0:string,1:string}  [Align type, category]
     */
    public static function mapType(string $itType, string $make, string $model, string $name, string $os): array
    {
        $t = strtolower(trim($itType));
        if (\Align\Lifecycle\Lifecycle::looksLikeUps($t === 'other' || $t === '' || str_contains($t, 'ups') ? "$t $make $model $name" : "$make $model")) {
            return ['UPS', 'ups'];
        }
        $isRouter = preg_match('/router|gateway|edgerouter|\busg\b|\budm\b|dream machine|mikrotik/i', "$name $model") === 1;
        return match (true) {
            $t === 'firewall/router', str_contains($t, 'firewall') => [$isRouter && !preg_match('/fortigate|sonicwall|firebox|pfsense|opnsense|meraki mx|sophos|palo alto|watchguard/i', "$make $model") ? 'Router' : 'Firewall', 'network'],
            str_contains($t, 'router') => ['Router', 'network'],
            str_contains($t, 'switch') => ['Switch', 'network'],
            str_contains($t, 'access point'), $t === 'ap', str_contains($t, 'wireless'), str_contains($t, 'wifi') => ['Access point', 'network'],
            str_contains($t, 'printer'), str_contains($t, 'copier'), str_contains($t, 'mfp'), str_contains($t, 'scanner') => ['Printer', 'printer'],
            str_contains($t, 'nas'), str_contains($t, 'storage'), $t === 'san' => ['NAS / Storage', 'storage'],
            str_contains($t, 'camera'), str_contains($t, 'nvr'), str_contains($t, 'dvr') => ['Camera / NVR', 'camera'],
            str_contains($t, 'phone') => ['Phone', 'phone'],
            str_contains($t, 'virtual') => [\Align\Lifecycle\Lifecycle::virtualType($os), 'vm'],
            $t === 'server' => ['Server', 'server'],
            str_contains($t, 'host'), str_contains($t, 'hypervisor') => ['Hypervisor host', 'server'],
            $t === 'desktop', $t === 'workstation' => ['Desktop', 'workstation'],
            $t === 'laptop', $t === 'notebook' => ['Laptop', 'workstation'],
            default => [\Align\Lifecycle\Lifecycle::UNASSIGNED, 'other'],
        };
    }

    /**
     * Align type => ITFlow asset type to write back. ITFlow has fewer types, so several Align
     * types share one (UPS and NAS become "Other"). Null = don't push (Unassigned).
     */
    public static function typeFor(string $alignType): ?string
    {
        return match ($alignType) {
            'Desktop' => 'Desktop',
            'Laptop' => 'Laptop',
            'Server', 'Hypervisor host' => 'Server',
            'VDI / virtual desktop', 'Virtual server' => 'Virtual Machine',
            'Firewall', 'Router' => 'Firewall/Router',
            'Switch' => 'Switch',
            'Access point' => 'Access Point',
            'Printer' => 'Printer',
            'Phone' => 'Phone',
            'Camera / NVR' => 'Camera',
            'NAS / Storage', 'UPS', 'Other' => 'Other',
            default => null,
        };
    }

    /** One asset, fresh from ITFlow (null if it no longer exists). Only a row with that asset_id counts. */
    public function asset(int $assetId): ?array
    {
        $rows = $this->read('assets', ['asset_id' => $assetId]);
        foreach ($rows as $r) {
            if (self::id($r['asset_id'] ?? null) === $assetId) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Creates an asset and returns its ITFlow ID. Not retried by HttpClient (a POST), so a slow ITFlow can't end up
     * with two. The caller checked the user may write to this client and that writes aren't blocked (Staging).
     */
    public function createAsset(int $clientId, array $fields): int
    {
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/assets/create.php", [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode(['api_key' => $this->apiKey, 'client_id' => $clientId] + $fields, self::JSON));
        $j = $r['json'] ?? [];
        if (($j['success'] ?? 'False') !== 'True') {
            throw new \RuntimeException('ITFlow refused the new asset: ' . $this->remoteMessage($j, 'unknown error'));
        }
        // A non-number here would otherwise become asset 1 (another client's)
        $id = self::id($j['data'][0]['insert_id'] ?? $j['data'][0]['asset_id'] ?? $j['insert_id'] ?? null);
        if (!$id) {
            throw new \RuntimeException('ITFlow created the asset but did not return its ID.');
        }
        return $id;
    }

    /** Updates only the given asset fields; ITFlow keeps existing values for fields not sent. True when ITFlow says so. */
    public function updateAsset(int $clientId, int $assetId, array $fields): bool
    {
        $body = json_encode([
            'api_key' => $this->apiKey,
            'client_id' => $clientId,
            'asset_id' => $assetId,
        ] + $fields, self::JSON);
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/assets/update.php", [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body);
        return ($r['json']['success'] ?? 'False') === 'True';
    }

    /** Updates a contact; ITFlow keeps existing values for fields not sent. True when ITFlow says so. */
    public function updateContact(int $clientId, int $contactId, array $fields): bool
    {
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/contacts/update.php", [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode(['api_key' => $this->apiKey, 'client_id' => $clientId, 'contact_id' => $contactId] + $fields, self::JSON));
        return ($r['json']['success'] ?? 'False') === 'True';
    }

    /**
     * Archives or restores a contact (ITFlow's contacts/archive.php and unarchive.php). ITFlow also clears the contact's
     * Important/Billing/Technical flags and archives their client-portal login when it archives them.
     * Throws when this ITFlow has no such endpoint (older versions) so the user is told to do it in ITFlow.
     */
    public function archiveContact(int $clientId, int $contactId, bool $archived = true): bool
    {
        try {
            $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/contacts/" . ($archived ? 'archive' : 'unarchive') . '.php', [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], json_encode(['api_key' => $this->apiKey, 'client_id' => $clientId, 'contact_id' => $contactId], self::JSON));
        } catch (\Align\Http\HttpException $e) {
            if ($e->getCode() === 404) {
                throw new \RuntimeException('this version of ITFlow can\'t ' . ($archived ? 'archive' : 'restore') . ' contacts from other apps; update ITFlow, or do it there');
            }
            // ITFlow explains a refusal (no write access for the key's user, an archived user...) in its JSON body
            $msg = $this->remoteMessage(json_decode($e->body, true), '');
            throw $msg !== '' ? new \RuntimeException('ITFlow: ' . $msg) : $e;
        }
        return ($r['json']['success'] ?? 'False') === 'True';
    }

    /** Creates a contact and returns its ITFlow ID (not retried, as createAsset). */
    public function createContact(int $clientId, array $fields): int
    {
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/contacts/create.php", [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode(['api_key' => $this->apiKey, 'client_id' => $clientId] + $fields, self::JSON));
        $j = $r['json'] ?? [];
        if (($j['success'] ?? 'False') !== 'True') {
            throw new \RuntimeException('ITFlow refused the new contact: ' . $this->remoteMessage($j, 'unknown error'));
        }
        $id = self::id($j['data'][0]['insert_id'] ?? $j['data'][0]['contact_id'] ?? $j['insert_id'] ?? null);
        if (!$id) {
            throw new \RuntimeException('ITFlow created the contact but did not return its ID.');
        }
        return $id;
    }

    /**
     * Creates a ticket for the client. Returns the ITFlow ticket_id (not retried, as createAsset). $detailsHtml is
     * stored by ITFlow as HTML: the caller escapes what it puts in.
     */
    public function createTicket(int $clientId, string $subject, string $detailsHtml, string $priority = 'Medium', ?int $contactId = null): int
    {
        $body = ['api_key' => $this->apiKey, 'client_id' => $clientId, 'ticket_subject' => $subject, 'ticket_details' => $detailsHtml,
            'ticket_priority' => in_array($priority, ['Low', 'Medium', 'High', 'Urgent'], true) ? $priority : 'Medium'];
        if ($contactId) {
            $body['ticket_contact_id'] = $contactId;
        }
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/tickets/create.php", ['Content-Type' => 'application/json', 'Accept' => 'application/json'], json_encode($body, self::JSON));
        $j = $r['json'] ?? [];
        if (($j['success'] ?? 'False') !== 'True') {
            throw new \RuntimeException('ITFlow refused the ticket: ' . $this->remoteMessage($j, 'unknown error'));
        }
        $id = self::id($j['data'][0]['insert_id'] ?? $j['insert_id'] ?? null);
        if (!$id) {
            throw new \RuntimeException('ITFlow created the ticket but did not return its ID.');
        }
        return $id;
    }

    /** Reads one client to prove the address and key work. Returns a short message for the admin. */
    public function test(): string
    {
        $rows = $this->read('clients', ['limit' => 1]);
        return 'Connected. API key accepted' . ($rows ? ' and clients are readable.' : ' (no clients returned - check the key user\'s client access).');
    }

    /** Link to the client in ITFlow (the base URL is an admin-set https address). */
    public function clientUrl(int $clientId): string
    {
        return "{$this->baseUrl}/agent/client_overview.php?client_id=$clientId";
    }

    /** Link to the asset in ITFlow. */
    public function assetUrl(int $clientId, int $assetId): string
    {
        return "{$this->baseUrl}/agent/asset.php?client_id=$clientId&asset_id=$assetId";
    }

    /** An ID from an ITFlow reply: a positive whole number (int or digit string), else 0. */
    private static function id(mixed $v): int
    {
        return (is_int($v) || (is_string($v) && preg_match('/^\d{1,18}$/', $v) === 1)) && (int) $v > 0 ? (int) $v : 0;
    }

    /**
     * The "message" of an ITFlow JSON reply (untrusted), as one line of at most 200 characters with the API key
     * taken out (a server could echo the request), or $fallback when there is none.
     */
    private function remoteMessage(mixed $json, string $fallback): string
    {
        $m = is_array($json) ? ($json['message'] ?? null) : null;
        if (!is_string($m)) {
            return $fallback;
        }
        if ($this->apiKey !== '') {
            $m = str_replace($this->apiKey, '[API key]', $m);
        }
        $m = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $m));
        return $m === '' ? $fallback : mb_substr($m, 0, 200);
    }
}
