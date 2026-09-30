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
 */
final class Itflow
{
    private const PAGE = 100;
    private HttpClient $http;

    public function __construct(private string $baseUrl, private string $apiKey, bool $interactive = false)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        // Interactive = called while a user waits on a page save: fail fast, the poller retries later.
        $this->http = $interactive ? new HttpClient(12, 1) : new HttpClient(60);
    }

    public static function fromSettings(bool $interactive = false): self
    {
        $url = Settings::get('itflow_url');
        $key = Settings::secret('itflow_api_key');
        if (!$url || !$key) {
            throw new \RuntimeException('ITFlow is not configured (Settings > Integrations).');
        }
        return new self($url, $key, $interactive);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    private function read(string $resource, array $query = []): array
    {
        $url = "{$this->baseUrl}/api/v1/$resource/read.php?" . http_build_query($query + ['api_key' => $this->apiKey]);
        try {
            $json = $this->http->getJson($url);
        } catch (HttpException $e) {
            $msg = json_decode($e->body, true)['message'] ?? $e->getMessage();
            throw new \RuntimeException("ITFlow $resource read failed: $msg", 0, $e);
        }
        if (!is_array($json)) {
            throw new \RuntimeException("ITFlow $resource read returned a non-JSON response. Check the ITFlow URL.");
        }
        // ITFlow returns success "False" with no data when a query simply has no rows.
        return $json['data'] ?? [];
    }

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

    public function clients(): array
    {
        return $this->readAll('clients');
    }

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

    /** A single ticket, or null if it no longer exists (or the API user can't see it). */
    public function ticket(int $id): ?array
    {
        return $this->read('tickets', ['ticket_id' => $id])[0] ?? null;
    }

    public static function pageSize(): int
    {
        return self::PAGE;
    }

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

    /** One asset, fresh from ITFlow (null if it no longer exists). */
    public function asset(int $assetId): ?array
    {
        $rows = $this->read('assets', ['asset_id' => $assetId]);
        foreach ($rows as $r) {
            if ((int) ($r['asset_id'] ?? 0) === $assetId) {
                return $r;
            }
        }
        return null;
    }

    /** Creates an asset and returns its ITFlow ID. */
    public function createAsset(int $clientId, array $fields): int
    {
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/assets/create.php", [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode($fields + ['api_key' => $this->apiKey, 'client_id' => $clientId]));
        $j = $r['json'] ?? [];
        if (($j['success'] ?? 'False') !== 'True') {
            throw new \RuntimeException('ITFlow refused the new asset: ' . ($j['message'] ?? 'unknown error'));
        }
        $id = (int) ($j['data'][0]['insert_id'] ?? $j['data'][0]['asset_id'] ?? $j['insert_id'] ?? 0);
        if (!$id) {
            throw new \RuntimeException('ITFlow created the asset but did not return its ID.');
        }
        return $id;
    }

    /** Updates only the given asset fields; ITFlow keeps existing values for fields not sent. */
    public function updateAsset(int $clientId, int $assetId, array $fields): bool
    {
        $body = json_encode($fields + [
            'api_key' => $this->apiKey,
            'client_id' => $clientId,
            'asset_id' => $assetId,
        ]);
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/assets/update.php", [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body);
        return ($r['json']['success'] ?? 'False') === 'True';
    }

    /** Updates a contact; ITFlow keeps existing values for fields not sent. */
    public function updateContact(int $clientId, int $contactId, array $fields): bool
    {
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/contacts/update.php", [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode($fields + ['api_key' => $this->apiKey, 'client_id' => $clientId, 'contact_id' => $contactId]));
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
            ], json_encode(['api_key' => $this->apiKey, 'client_id' => $clientId, 'contact_id' => $contactId]));
        } catch (\Align\Http\HttpException $e) {
            if ($e->getCode() === 404) {
                throw new \RuntimeException('this version of ITFlow can\'t ' . ($archived ? 'archive' : 'restore') . ' contacts from other apps; update ITFlow, or do it there');
            }
            // ITFlow explains a refusal (no write access for the key's user, an archived user...) in its JSON body
            $msg = json_decode($e->body, true)['message'] ?? null;
            throw is_string($msg) && $msg !== '' ? new \RuntimeException('ITFlow: ' . mb_substr($msg, 0, 200)) : $e;
        }
        return ($r['json']['success'] ?? 'False') === 'True';
    }

    /** Creates a contact and returns its ITFlow ID. */
    public function createContact(int $clientId, array $fields): int
    {
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/contacts/create.php", [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode($fields + ['api_key' => $this->apiKey, 'client_id' => $clientId]));
        $j = $r['json'] ?? [];
        if (($j['success'] ?? 'False') !== 'True') {
            throw new \RuntimeException('ITFlow refused the new contact: ' . ($j['message'] ?? 'unknown error'));
        }
        $id = (int) ($j['data'][0]['insert_id'] ?? $j['data'][0]['contact_id'] ?? $j['insert_id'] ?? 0);
        if (!$id) {
            throw new \RuntimeException('ITFlow created the contact but did not return its ID.');
        }
        return $id;
    }

    /** Creates a ticket for the client. Returns the ITFlow ticket_id. */
    public function createTicket(int $clientId, string $subject, string $detailsHtml, string $priority = 'Medium', ?int $contactId = null): int
    {
        $body = ['api_key' => $this->apiKey, 'client_id' => $clientId, 'ticket_subject' => $subject, 'ticket_details' => $detailsHtml,
            'ticket_priority' => in_array($priority, ['Low', 'Medium', 'High', 'Urgent'], true) ? $priority : 'Medium'];
        if ($contactId) {
            $body['ticket_contact_id'] = $contactId;
        }
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/tickets/create.php", ['Content-Type' => 'application/json', 'Accept' => 'application/json'], json_encode($body));
        $j = $r['json'] ?? [];
        if (($j['success'] ?? 'False') !== 'True') {
            throw new \RuntimeException('ITFlow refused the ticket: ' . ($j['message'] ?? 'unknown error'));
        }
        $id = (int) ($j['data'][0]['insert_id'] ?? $j['insert_id'] ?? 0);
        if (!$id) {
            throw new \RuntimeException('ITFlow created the ticket but did not return its ID.');
        }
        return $id;
    }

    public function test(): string
    {
        $rows = $this->read('clients', ['limit' => 1]);
        return 'Connected. API key accepted' . ($rows ? ' and clients are readable.' : ' (no clients returned - check the key user\'s client access).');
    }

    public function clientUrl(int $clientId): string
    {
        return "{$this->baseUrl}/agent/client_overview.php?client_id=$clientId";
    }

    public function assetUrl(int $clientId, int $assetId): string
    {
        return "{$this->baseUrl}/agent/asset.php?client_id=$clientId&asset_id=$assetId";
    }
}
