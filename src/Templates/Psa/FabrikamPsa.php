<?php
declare(strict_types=1);

namespace Align\Templates\Psa;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Providers\Clean;
use Align\Providers\Psa\PsaProvider;
use Align\Settings;

/**
 * Fabrikam PSA as the PSA, read-only: clients, contacts and assets mapped to Align's neutral records (see
 * Providers\Psa\PsaProvider). Everything else (locations, licenses, vendors, invoices, tickets) isn't read, and
 * every write method refuses.
 *
 * template-only: begin
 * TEMPLATE (2.22.0). Fabrikam is a made-up product: `align make:connector psa "Name"` copies this file to
 * src/Providers/Psa/<Name>.php. Add a capability to CAPS only once its method really reads it. The mock API it talks
 * to in the tests is tests/mock/fabrikampsa.php.
 * template-only: end
 *
 * API: GET {address}/api/clients, /api/contacts and /api/assets, each paged with ?page=N and answering
 * {"items": [...], "next": <next page number or null>}, with the API key as a bearer token.
 *
 * SECURITY: the product's replies are untrusted. A reply without an "items" list is an error, never "no records" (the
 * sync archives clients the PSA no longer lists). Ids must be positive whole numbers kept exact (a contact's or
 * asset's client id decides which client it is stored under), text is cleaned of control characters and cut to
 * Align's column sizes, emails must be valid and dates real. The address is an https admin setting checked by
 * HttpClient on every request; the key goes only in the Authorization header.
 */
final class FabrikamPsa implements PsaProvider
{
    /** What this connector reads (keys of PsaProvider::CAPABILITIES). */
    public const CAPS = ['contacts', 'assets'];

    /** Pages read from one list before giving up (a server that ignores ?page= would answer for ever). */
    private const MAX_PAGES = 5000;

    private HttpClient $http;

    /** $url: the API address; $apiKey: the key; $interactive: a person waits, so one short try. */
    public function __construct(private string $url, private string $apiKey, bool $interactive = false)
    {
        $this->http = $interactive ? new HttpClient(20, 1) : new HttpClient(120, 3);
    }

    /** From the saved fabrikampsa_url and fabrikampsa_api_key. Throws when not set up. */
    public static function fromSettings(bool $interactive = false): self
    {
        $url = (string) Settings::get('fabrikampsa_url');
        $key = Settings::secret('fabrikampsa_api_key');
        if ($url === '' || !$key) {
            throw new \RuntimeException('Fabrikam PSA is not configured (Integrations).');
        }
        return new self($url, $key, $interactive);
    }

    /** The connector key (fixed; stored with every record from this provider). */
    public function key(): string
    {
        return 'fabrikampsa';
    }

    /** Display name. */
    public function name(): string
    {
        return 'Fabrikam PSA';
    }

    /** Whether it reads one of PsaProvider::CAPABILITIES (only those in CAPS). */
    public function supports(string $capability): bool
    {
        return in_array($capability, self::CAPS, true);
    }

    /** Checks the address and key with one read (admin, Integrations page). */
    public function test(): string
    {
        $n = count($this->clients());
        return "Connected. $n client" . ($n === 1 ? '' : 's') . ' readable.';
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
                    in_array($e->status, [401, 403], true) => 'Fabrikam PSA refused the API key (HTTP ' . $e->status . '). Check the key and its permissions.',
                    $e->status === 404 => 'Fabrikam PSA has no ' . $path . ' at that address (HTTP 404). Check the API address.',
                    default => 'Fabrikam PSA couldn\'t be read: ' . $e->getMessage(),
                }, 0, $e);
            }
            if (!is_array($j) || !is_array($j['items'] ?? null) || !array_is_list($j['items'])) {
                throw new \RuntimeException('Fabrikam PSA sent something other than a list for ' . $path . '. Check the API address.');
            }
            array_push($out, ...array_filter($j['items'], 'is_array'));
            $next = $j['next'] ?? null;
            if ($next === null || $next === '' || $next === false) {
                return $out;
            }
            if (!is_int($next) || $next <= $page) {
                throw new \RuntimeException('Fabrikam PSA sent a page number that doesn\'t move forward for ' . $path . '.');
            }
            $page = $next;
        }
        throw new \RuntimeException('Fabrikam PSA sent more than ' . self::MAX_PAGES . ' pages for ' . $path . '.');
    }

    /** A positive whole-number id as exact text ('' when it isn't one: never a loose cast). */
    private static function id(mixed $v): string
    {
        if (is_int($v)) {
            return $v > 0 ? (string) $v : '';
        }
        return is_string($v) && preg_match('/^[1-9][0-9]{0,17}$/', $v) ? $v : '';
    }

    /** Remote text on one line, cut to $max, or null. */
    private static function text(mixed $v, int $max = 255): ?string
    {
        return Clean::text($v, $max);
    }

    /** True only for a real true (or 1 / "true"): anything else is false. */
    private static function flag(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true');
    }

    /** A real date (Y-m-d) from "2026-01-31" or the start of an ISO time, or null. */
    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return "$m[1]-$m[2]-$m[3]";
    }

    /** An email address, or null when it isn't a valid one. */
    private static function email(mixed $v): ?string
    {
        $e = self::text($v, 190);
        return $e !== null && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    // ---- Reads ----

    /** Every client, inactive ones too (archived in Align). */
    public function clients(): array
    {
        $out = [];
        foreach ($this->all('/api/clients') as $c) {
            $id = self::id($c['id'] ?? null);
            if ($id === '') {
                continue;
            }
            $site = self::text($c['website'] ?? null);
            $out[] = ['id' => $id, 'name' => self::text($c['name'] ?? null) ?? "Client $id", 'archived' => self::flag($c['inactive'] ?? false),
                'website' => $site !== null && preg_match('#^(https?://)?[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$#i', $site) ? $site : null,
                'email' => self::email($c['email'] ?? null), 'phone' => self::text($c['phone'] ?? null, 60)];
        }
        return $out;
    }

    /** Every contact, inactive ones too (a contact without a valid id, client or name is left out). */
    public function contacts(): array
    {
        $out = [];
        foreach ($this->all('/api/contacts') as $u) {
            $id = self::id($u['id'] ?? null);
            $cid = self::id($u['client_id'] ?? null);
            $name = self::text($u['name'] ?? null);
            if ($id === '' || $cid === '' || $name === null) {
                continue;
            }
            $out[] = [
                'id' => $id, 'client_id' => $cid, 'name' => $name,
                'title' => self::text($u['title'] ?? null, 190), 'department' => self::text($u['department'] ?? null, 190),
                'email' => self::email($u['email'] ?? null), 'phone' => self::text($u['phone'] ?? null, 60), 'extension' => null,
                'mobile' => self::text($u['mobile'] ?? null, 60), 'location_id' => null,
                'primary' => self::flag($u['primary'] ?? false), 'important' => false, 'billing' => false, 'technical' => false,
                'notes' => null, 'archived' => self::flag($u['inactive'] ?? false),
            ];
        }
        return $out;
    }

    /** Locations aren't read (not in CAPS). */
    public function locations(): array
    {
        return [];
    }

    /** A product asset as a neutral asset (text cut to psa_assets' column sizes). */
    private function asNeutralAsset(array $a): array
    {
        $id = self::id($a['id'] ?? null);
        $serial = self::text($a['serial'] ?? null, 190);
        $inactive = self::flag($a['inactive'] ?? false);
        return [
            'id' => $id,
            'client_id' => self::id($a['client_id'] ?? null),
            'name' => self::text($a['name'] ?? null) ?? "Asset $id",
            'type' => self::text($a['type'] ?? null, 100),
            'make' => self::text($a['make'] ?? null, 190),
            'model' => self::text($a['model'] ?? null, 190),
            'serial' => $serial !== null && !preg_match('/^(to be filled by o\.?e\.?m\.?|default string|not specified|n\/?a|none|0+)$/i', $serial) ? $serial : null,
            'os' => self::text($a['os'] ?? null, 190),
            'description' => Clean::text($a['notes'] ?? null, 5000),
            'purchase_date' => self::date($a['purchase_date'] ?? null),
            'warranty_expire' => self::date($a['warranty_end'] ?? null),
            'install_date' => null,
            'status' => $inactive ? 'Inactive' : 'Active',
            'archived' => $inactive,
            'ip_address' => null,
            'mac' => null,
            'location_id' => null,
            'updated_at' => null,
        ];
    }

    /** Every asset, inactive ones too (rows without a valid id are dropped). */
    public function assets(): array
    {
        $out = [];
        foreach ($this->all('/api/assets') as $a) {
            if (self::id($a['id'] ?? null) !== '') {
                $out[] = $this->asNeutralAsset($a);
            }
        }
        return $out;
    }

    /** One asset: read with the full list (the template's API has no single-asset call); null when it's gone. */
    public function asset(string $assetId): ?array
    {
        foreach ($this->assets() as $a) {
            if ($a['id'] === $assetId) {
                return $a;
            }
        }
        return null;
    }

    /** Align type and import category from the asset's type name (the same rules as ITFlow's: Workstation, Laptop, Server, Printer…). */
    public function mapAssetType(array $asset): array
    {
        return \Align\Integrations\Itflow::mapType((string) ($asset['type'] ?? ''), (string) ($asset['make'] ?? ''), (string) ($asset['model'] ?? ''),
            (string) ($asset['name'] ?? ''), (string) ($asset['os'] ?? ''));
    }

    /** Never written: null. */
    public function assetTypeFor(string $alignType): ?string
    {
        return null;
    }

    /** Active or Inactive. */
    public function assetStatus(bool $retired): string
    {
        return $retired ? 'Inactive' : 'Active';
    }

    /** An inactive asset is retired. */
    public function statusRetired(?string $status): bool
    {
        return strtolower(trim((string) $status)) === 'inactive';
    }

    /** Software licenses aren't read (not in CAPS). */
    public function licenses(): array
    {
        return [];
    }

    /** Vendors aren't read (not in CAPS). */
    public function vendors(): array
    {
        return [];
    }

    /** Invoices aren't read (not in CAPS). */
    public function invoices(): array
    {
        return [];
    }

    /** Tickets aren't read (not in CAPS): nothing stored, nothing reported complete. */
    public function tickets(string $since, array $state, callable $store): array
    {
        return ['complete' => false, 'sla' => null, 'state' => $state];
    }

    /** Tickets aren't read. */
    public function ticket(string $ticketId): ?array
    {
        return null;
    }

    // ---- Writes: none (read-only) ----

    /** Stops any write: the capabilities never offer one, so this is the last line of defence. */
    private function readOnly(): never
    {
        throw new \RuntimeException('Fabrikam PSA is read-only in Align: nothing is sent to it.');
    }

    /** Refused (read-only). */
    public function createAsset(string $clientId, array $fields): string { $this->readOnly(); }
    /** Refused (read-only). */
    public function updateAsset(string $clientId, string $assetId, array $fields): bool { $this->readOnly(); }
    /** Refused (read-only). */
    public function updateContact(string $clientId, string $contactId, array $fields): bool { $this->readOnly(); }
    /** Refused (read-only). */
    public function createContact(string $clientId, array $fields): string { $this->readOnly(); }
    /** Refused (read-only). */
    public function archiveContact(string $clientId, string $contactId, bool $archived = true): bool { $this->readOnly(); }
    /** Refused (read-only). */
    public function createTicket(string $clientId, string $subject, string $detailsHtml, string $priority = 'Medium', ?string $contactId = null): string { $this->readOnly(); }

    // ---- Links into the product ----

    /** The client's page in the product, from the saved address and a checked id (it goes into an href). */
    public function clientUrl(string $clientId): ?string
    {
        return self::id($clientId) !== '' && preg_match('#^https?://#i', $this->url) ? rtrim($this->url, '/') . '/clients/' . $clientId : null;
    }

    /** The asset's page in the product, from the saved address and a checked id (it goes into an href). */
    public function assetUrl(string $clientId, string $assetId): ?string
    {
        return self::id($assetId) !== '' && preg_match('#^https?://#i', $this->url) ? rtrim($this->url, '/') . '/assets/' . $assetId : null;
    }

    /** Tickets aren't read, so no link. */
    public function ticketUrl(string $ticketId): ?string
    {
        return null;
    }
}
