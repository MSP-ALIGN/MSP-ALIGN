<?php
declare(strict_types=1);

namespace Align\Providers\Psa;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * 2.12.0 HaloPSA as the PSA, read-only (beta). Its REST API is at <Halo address>/api and takes an OAuth2 bearer token
 * from the authorisation server (<Halo address>/auth unless the admin enters another), got with an API application's
 * client ID and secret ("Client ID and Secret (Services)" in Halo, acting as one agent). Only read scopes are asked for.
 *
 * Read: GET /Client (clients), /Site (locations: the client's main site fills its address and phone), /Users (contacts;
 * Halo's per-site "General User" placeholder is skipped), /Asset (assets: make, model, serial and OS come from the asset
 * type's fields, which each Halo sets up its own way) and /Invoice (the managed-services estimate). Lists are read 100
 * at a time with Halo's pageinate / page_size / page_no until record_count rows have come back. Tickets, SLAs, software
 * licences and suppliers aren't read yet, and nothing is ever written: every write method refuses.
 *
 * SECURITY: Halo's replies are untrusted. A reply without the expected list is an error, never "no records" (the sync
 * archives clients the PSA no longer lists). Ids must be positive whole numbers (a loose cast would file a record under
 * the wrong client), text is cleaned of control characters and cut to Align's column sizes, and dates must be real.
 * The token URL is built from the admin's saved address, never from anything Halo sends (Halo's /api/authinfo names
 * an auth server, but following it would send the secret wherever a reply pointed). Both addresses are https admin
 * settings checked by HttpClient on every request; the secret only goes to the token URL, the token only in the
 * Authorization header. Links into Halo aren't offered (its page addresses aren't documented).
 */
final class HaloPsa implements PsaProvider
{
    /** Rows per page: Halo caps some lists at 100 whatever is asked for. */
    private const PAGE = 100;

    /** Pages read from one list before giving up (a server that ignores page_no would answer for ever). */
    private const MAX_PAGES = 5000;

    /** The read-only scopes asked for: clients, sites and users; assets; invoices. */
    private const SCOPE = 'read:customers read:assets read:invoices';

    /** What this beta reads. */
    public const CAPS = ['contacts', 'locations', 'assets', 'invoices'];

    private HttpClient $http;

    /** The bearer token for this run, once fetched. */
    private ?string $token = null;

    /** Halo's sites as sent (untrusted), read once: locations, contacts and assets all use them. */
    private ?array $siteRows = null;

    /** Client id => main site id, filled by clients() (marks a client's primary location). */
    private array $mainSite = [];

    /** Site id => client id, built once by clientOf(). */
    private ?array $siteClient = null;

    /**
     * $url: the Halo web address (https://yourco.halopsa.com); $authUrl: the authorisation server ('' = $url/auth);
     * $tenant: the tenant name a hosted auth server asks for ('' = none); $interactive: a person waits (one short try).
     */
    public function __construct(private string $url, private string $authUrl, private string $tenant, private string $clientId, private string $secret, bool $interactive = false)
    {
        $this->http = $interactive ? new HttpClient(20, 1) : new HttpClient(120, 3);
    }

    /** From the saved halo_* settings. Throws when not set up. */
    public static function fromSettings(bool $interactive = false): self
    {
        $url = (string) Settings::get('halo_url');
        $id = (string) Settings::get('halo_client_id');
        $secret = (string) Settings::secret('halo_client_secret');
        if ($url === '' || $id === '' || $secret === '') {
            throw new \RuntimeException('HaloPSA is not configured (Integrations).');
        }
        return new self($url, (string) Settings::get('halo_auth_url'), (string) Settings::get('halo_tenant'), $id, $secret, $interactive);
    }

    /** The connector key (fixed; also stored with every record from this provider). */
    public function key(): string
    {
        return 'halopsa';
    }

    /** Display name. */
    public function name(): string
    {
        return 'HaloPSA';
    }

    /** Contacts, locations, assets and invoices; nothing that writes, no tickets yet. */
    public function supports(string $capability): bool
    {
        return in_array($capability, self::CAPS, true);
    }

    // ---- Requests ----

    /** The token URL: the saved auth server (or the Halo address + /auth) + /token, with the tenant when one is set. */
    private function tokenUrl(): string
    {
        $base = rtrim($this->authUrl !== '' ? $this->authUrl : rtrim($this->url, '/') . '/auth', '/');
        return $base . '/token' . ($this->tenant !== '' ? '?tenant=' . rawurlencode($this->tenant) : '');
    }

    /** A bearer token (client credentials, read scopes only), fetched once per run. */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        try {
            $r = $this->http->request('POST', $this->tokenUrl(), ['Accept' => 'application/json'],
                ['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->secret, 'scope' => self::SCOPE], true);
        } catch (HttpException $e) {
            $err = is_array($j = json_decode($e->body, true)) && is_string($j['error'] ?? null) ? $j['error'] : '';
            throw new \RuntimeException(match (true) {
                $err === 'invalid_scope' => 'HaloPSA refused the read permissions Align asks for (invalid_scope). On the API application\'s Permissions tab allow read:customers, read:assets and read:invoices.',
                in_array($e->status, [400, 401], true) => 'HaloPSA refused the client ID or secret (HTTP ' . $e->status . ($err !== '' ? ', ' . preg_replace('/[^a-z_]/', '', $err) : '') . '). Check them, that the application is active and uses "Client ID and Secret (Services)", and the tenant if your Halo shows one.',
                $e->status === 404 => 'HaloPSA has no token address at ' . parse_url($this->tokenUrl(), PHP_URL_HOST) . ' (HTTP 404). Leave the authorisation server empty unless Halo shows a different one under Configuration → Integrations → HaloPSA API.',
                default => 'HaloPSA couldn\'t be signed in to: ' . $e->getMessage(),
            }, 0, $e);
        }
        $t = is_array($r['json']) ? ($r['json']['access_token'] ?? null) : null;
        if (!is_string($t) || !preg_match('/^[A-Za-z0-9._~+\/=-]{10,8000}$/', $t)) {
            throw new \RuntimeException('HaloPSA\'s sign-in didn\'t return a token. Check the authorisation server address.');
        }
        return $this->token = $t;
    }

    /**
     * One GET from the API; the decoded JSON or an explained error. A 401 gets one new token and one retry (a long
     * read can outlast Halo's hour-long token); a second 401 is an error.
     */
    private function get(string $path, array $query): mixed
    {
        $url = rtrim($this->url, '/') . '/api' . $path . ($query ? '?' . http_build_query($query) : '');
        for ($try = 1; ; $try++) {
            try {
                return $this->http->request('GET', $url, ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'])['json'];
            } catch (HttpException $e) {
                if ($e->status === 401 && $try === 1) {
                    $this->token = null; // expired: sign in again
                    continue;
                }
                throw new \RuntimeException(match (true) {
                    $e->status === 401 => 'HaloPSA refused Align\'s token for ' . $path . ' (HTTP 401). Check the application is active.',
                    $e->status === 403 => 'HaloPSA won\'t let the API application read ' . $path . ' (HTTP 403). Give it the read permissions, and make sure the agent it logs in as can see every client.',
                    $e->status === 404 => 'HaloPSA has no /api' . $path . ' at that address (HTTP 404). Enter your Halo web address (https://yourco.halopsa.com), without /api.',
                    default => 'HaloPSA couldn\'t be read: ' . $e->getMessage(),
                }, 0, $e);
            }
        }
    }

    /** Halo's record_count (a whole number, or digits as text), or null when it didn't send one. */
    private static function count(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 ? $v : (is_string($v) && preg_match('/^[0-9]{1,9}$/', $v) ? (int) $v : null);
    }

    /**
     * Every row of a list, read a page at a time. $key: the list's name in Halo's reply ("clients").
     *
     * A short list would archive clients (the sync archives any the PSA no longer lists), so it is an error, never a
     * shorter answer: Halo may send fewer rows per page than asked for, so with a record_count the read goes on until
     * that many different records have come back, and an empty page before then throws (records added or removed
     * during the read move Halo's pages; the next sync tries again). Without a record_count it reads until an empty
     * page. Also throws when a page isn't that list, when a page starts with the same record as the one before
     * (page_no ignored) or after MAX_PAGES pages. Rows are kept once per id.
     */
    private function all(string $path, string $key, array $query = []): array
    {
        $byId = [];
        $noId = [];
        $total = null;
        $prevFirst = null;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $j = $this->get($path, $query + ['pageinate' => 'true', 'page_size' => self::PAGE, 'page_no' => $page]);
            if (!is_array($j) || !isset($j[$key]) || !is_array($j[$key]) || !array_is_list($j[$key])) {
                throw new \RuntimeException("HaloPSA sent something other than a list of $key for $path. Check the address is your Halo web address.");
            }
            $total ??= self::count($j['record_count'] ?? null);
            $list = array_values(array_filter($j[$key], 'is_array'));
            $first = $list ? self::id($list[0]['id'] ?? null) : null;
            if ($first !== null && $first !== '' && $first === $prevFirst) {
                throw new \RuntimeException("HaloPSA returned the same $key again (it may be ignoring page_no); the read was stopped.");
            }
            $prevFirst = $first;
            foreach ($list as $r) {
                if (($id = self::id($r['id'] ?? null)) !== '') {
                    $byId[$id] = $r;
                } else {
                    $noId[] = $r;
                }
            }
            $have = count($byId) + count($noId);
            if ($total !== null && $have >= $total) {
                break;
            }
            if (!$j[$key]) {
                if ($total !== null) {
                    throw new \RuntimeException("HaloPSA's list of $key ended early ($have of $total); nothing was changed and the next sync tries again.");
                }
                break;
            }
            if ($page === self::MAX_PAGES) {
                throw new \RuntimeException("HaloPSA returned more than " . (self::MAX_PAGES * self::PAGE) . " $key; the read was stopped.");
            }
        }
        return [...array_values($byId), ...$noId];
    }

    /** Checks the sign-in and the client permission with one small read (admin, Integrations page). */
    public function test(): string
    {
        $j = $this->get('/Client', ['pageinate' => 'true', 'page_size' => 1, 'page_no' => 1, 'includeinactive' => 'true', 'includeactive' => 'true']);
        if (!is_array($j) || !isset($j['clients']) || !is_array($j['clients'])) {
            throw new \RuntimeException('HaloPSA sent something other than a list of clients. Check the address is your Halo web address.');
        }
        $n = is_int($j['record_count'] ?? null) ? $j['record_count'] : count($j['clients']);
        return "Connected. $n client" . ($n === 1 ? '' : 's') . ' readable.';
    }

    // ---- Value helpers ----

    /** A Halo id as text ('' unless it's a positive whole number: a loose cast would read [5], true or "1abc" as an id). */
    private static function id(mixed $v): string
    {
        if (is_string($v)) {
            $v = trim($v);
            $n = preg_match('/^[0-9]{1,18}$/', $v) ? (int) $v : 0;
        } else {
            $n = is_int($v) ? $v : 0;
        }
        return $n > 0 ? (string) $n : '';
    }

    /** Text on one line (or, $multiline, keeping line breaks) without control characters, cut to $max; null when empty or not text. */
    private static function text(mixed $v, int $max = 255, bool $multiline = false): ?string
    {
        if (!is_scalar($v) || is_bool($v)) {
            return null;
        }
        $s = $multiline
            ? preg_replace(['/\r\n?/', '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'], ["\n", ''], (string) $v)
            : preg_replace(['/[\t\r\n]/', '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'], [' ', ''], (string) $v);
        $s = trim(mb_substr($s ?? '', 0, $max));
        return $s !== '' ? $s : null;
    }

    /** Halo's true/false flags (sent as booleans, numbers or text). */
    private static function flag(mixed $v): bool
    {
        return $v === true || $v === 1 || (is_string($v) && in_array(strtolower($v), ['1', 'true'], true));
    }

    /**
     * A Y-m-d date, or null (Halo writes "no date" as years like 0001 or 1900). A value with a time other than midnight
     * is a moment in UTC (a local midnight ahead of UTC is stored as the evening before), so it is moved to this
     * server's timezone first.
     */
    private static function date(mixed $v): ?string
    {
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}[T ](\d{2}:\d{2}:\d{2})/', $v, $tm) && $tm[1] !== '00:00:00' && ($local = self::ts($v)) !== null) {
            return substr($local, 0, 10);
        }
        return self::day($v);
    }

    /** The Y-m-d a value starts with, when it's a real day from 1971 on (no timezone change); else null. */
    private static function day(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || (int) $m[1] < 1971 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $m[0];
    }

    /** A Halo time (UTC unless it says otherwise) in this server's timezone (Y-m-d H:i:s), or null. */
    private static function ts(mixed $v): ?string
    {
        if (self::day($v) === null || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', (string) $v)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** A record's client: its own client_id, else its site's client (Halo's user list names the site, not always the client). */
    private function clientOf(array $r): string
    {
        $cid = self::id($r['client_id'] ?? null);
        if ($cid !== '') {
            return $cid;
        }
        $sid = self::id($r['site_id'] ?? null);
        if ($sid === '') {
            return '';
        }
        if ($this->siteClient === null) { // site id => client id, built once (thousands of users and assets each ask)
            $this->siteClient = [];
            foreach ($this->sites() as $s) {
                if (($i = self::id($s['id'] ?? null)) !== '') {
                    $this->siteClient[$i] = self::id($s['client_id'] ?? null);
                }
            }
        }
        return $this->siteClient[$sid] ?? '';
    }


    // ---- Clients, locations, contacts ----

    /**
     * Every Halo client, inactive ones too (archived in Align). Halo keeps phone and address on sites, email on users.
     * Also notes each client's main site for locations().
     */
    public function clients(): array
    {
        $out = [];
        foreach ($this->all('/Client', 'clients', ['includeinactive' => 'true', 'includeactive' => 'true']) as $r) {
            $id = self::id($r['id'] ?? null);
            if ($id === '') {
                continue;
            }
            if (($ms = self::id($r['main_site_id'] ?? null)) !== '') {
                $this->mainSite[$id] = $ms;
            }
            $site = self::text($r['website'] ?? null, 255);
            $out[] = ['id' => $id, 'name' => self::text($r['name'] ?? null) ?? "Client $id", 'archived' => self::flag($r['inactive'] ?? false),
                'website' => $site !== null && preg_match('#^(https?://)?[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$#i', $site) ? $site : null];
        }
        return $out;
    }

    /** Halo's sites as sent, read once per run (see $siteRows). */
    private function sites(): array
    {
        return $this->siteRows ??= $this->all('/Site', 'sites', ['includeinactive' => 'true', 'includeactive' => 'true']);
    }

    /**
     * Every Halo site as a location. The address is the delivery address's lines one per line (Halo lets each MSP use
     * them its own way, so they aren't guessed into city and state), with the postcode as the zip. The client's main
     * site is its primary location (its first active site when Halo names none). Needs clients() read first on this
     * provider for the main sites (the sync does that); otherwise it reads the clients itself.
     */
    public function locations(): array
    {
        if (!$this->mainSite) {
            $this->clients(); // fills mainSite
        }
        $out = [];
        // A client Halo names no main site for: its first active site is the primary one
        $fallback = [];
        foreach ($this->sites() as $s) {
            $cid = self::id($s['client_id'] ?? null);
            if ($cid !== '' && !isset($this->mainSite[$cid]) && !isset($fallback[$cid]) && !self::flag($s['inactive'] ?? false) && ($sid = self::id($s['id'] ?? null)) !== '') {
                $fallback[$cid] = $sid;
            }
        }
        foreach ($this->sites() as $s) {
            $id = self::id($s['id'] ?? null);
            $cid = self::id($s['client_id'] ?? null);
            if ($id === '' || $cid === '') {
                continue;
            }
            $addr = is_array($s['delivery_address'] ?? null) ? $s['delivery_address'] : [];
            $lines = [];
            foreach (['line1', 'line2', 'line3', 'line4'] as $k) {
                if (($l = self::text($addr[$k] ?? $s['delivery_address_' . $k] ?? null, 190)) !== null) {
                    $lines[] = $l;
                }
            }
            $out[] = [
                'id' => $id,
                'client_id' => $cid,
                'name' => self::text($s['name'] ?? null) ?? "Site $id",
                'address' => $lines ? mb_substr(implode("\n", $lines), 0, 500) : null,
                'city' => null,
                'state' => null,
                'zip' => self::text($addr['postcode'] ?? $s['delivery_address_line5'] ?? null, 20),
                'country' => self::text($addr['country'] ?? null, 60),
                'phone' => self::text($s['phonenumber'] ?? null, 60),
                'primary' => ($this->mainSite[$cid] ?? $fallback[$cid] ?? null) === $id,
                'important' => false,
                'archived' => self::flag($s['inactive'] ?? false),
            ];
        }
        return $out;
    }

    /**
     * Every Halo end user as a contact (Halo's "General User" placeholder on each site isn't a person, so it's skipped).
     * The main contact is the primary one; "important contact" is important.
     */
    public function contacts(): array
    {
        $out = [];
        foreach ($this->all('/Users', 'users', ['includeinactive' => 'true', 'includeactive' => 'true']) as $u) {
            $id = self::id($u['id'] ?? null);
            $name = self::text($u['name'] ?? null) ?? self::text(trim(((string) self::text($u['firstname'] ?? null)) . ' ' . ((string) self::text($u['surname'] ?? null))));
            $cid = $this->clientOf($u);
            if ($id === '' || $cid === '' || $name === null || strcasecmp($name, 'General User') === 0) {
                continue;
            }
            $email = self::text($u['emailaddress'] ?? null, 190);
            $out[] = [
                'id' => $id,
                'client_id' => $cid,
                'name' => $name,
                'title' => self::text($u['title'] ?? $u['jobtitle'] ?? null, 190),
                'department' => null,
                'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'phone' => self::text($u['phonenumber_preferred'] ?? null, 60) ?? self::text($u['phonenumber'] ?? null, 60),
                'extension' => null,
                'mobile' => self::text($u['mobilenumber'] ?? null, 60),
                'location_id' => self::id($u['site_id'] ?? null) ?: null,
                'primary' => self::flag($u['ismaincontact'] ?? false),
                'important' => self::flag($u['isimportantcontact'] ?? false),
                'billing' => false,
                'technical' => false,
                'notes' => null,
                'archived' => self::flag($u['inactive'] ?? false),
            ];
        }
        return $out;
    }

    // ---- Assets ----

    /** The value of the first asset-type field named one of $names (Halo's fields are a list of name / value pairs). */
    private static function field(array $a, array $names, int $max = 190): ?string
    {
        foreach (['fields', 'customfields'] as $list) {
            foreach (is_array($a[$list] ?? null) ? $a[$list] : [] as $f) {
                $n = is_array($f) ? strtolower((string) self::text($f['name'] ?? $f['label'] ?? null, 100)) : '';
                if ($n !== '' && in_array($n, $names, true)) {
                    $v = self::text($f['value'] ?? $f['display'] ?? null, $max);
                    if ($v !== null) {
                        return $v;
                    }
                }
            }
        }
        return null;
    }

    /** A Halo asset as a neutral asset (text cut to psa_assets' column sizes). */
    private function asNeutralAsset(array $a): array
    {
        $id = self::id($a['id'] ?? null);
        $serial = self::field($a, ['serial number', 'serial', 'serial no', 'serialnumber', 'service tag']);
        return [
            'id' => $id,
            'client_id' => $this->clientOf($a),
            'name' => self::text($a['inventory_number'] ?? null) ?? self::text($a['key_field'] ?? null) ?? "Asset $id",
            'type' => self::text($a['assettype_name'] ?? null, 100),
            'make' => self::text($a['manufacturer_name'] ?? null, 190) ?? self::field($a, ['manufacturer', 'make']),
            'model' => self::field($a, ['model', 'model number']),
            'serial' => $serial !== null && !preg_match('/^(to be filled by o\.?e\.?m\.?|default string|not specified|n\/?a|none|0+)$/i', $serial) ? $serial : null,
            'os' => self::field($a, ['operating system', 'os']),
            'description' => self::text($a['notes'] ?? null, 5000, true),
            'purchase_date' => self::date(self::field($a, ['purchase date', 'date purchased'])),
            'warranty_expire' => self::date($a['warranty_end'] ?? null),
            'install_date' => null,
            'status' => self::flag($a['inactive'] ?? false) ? 'Inactive' : 'Active',
            'archived' => self::flag($a['inactive'] ?? false),
            'ip_address' => self::field($a, ['ip address', 'ip'], 64),
            'mac' => self::field($a, ['mac address', 'mac'], 64),
            'location_id' => self::id($a['site_id'] ?? null) ?: null,
            'updated_at' => self::ts($a['dlastupdate'] ?? null),
        ];
    }

    /** Every Halo asset, inactive ones too (rows without an id are dropped). */
    public function assets(): array
    {
        $out = [];
        foreach ($this->all('/Asset', 'assets', ['includeinactive' => 'true', 'includeactive' => 'true']) as $a) {
            if (self::id($a['id'] ?? null) !== '') {
                $out[] = $this->asNeutralAsset($a);
            }
        }
        return $out;
    }

    /** One asset fresh from Halo; null for an id that isn't Halo's or an asset that's gone. */
    public function asset(string $assetId): ?array
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/', $assetId)) {
            return null;
        }
        try {
            $a = $this->get('/Asset/' . $assetId, []);
        } catch (\RuntimeException $e) {
            if ($e->getPrevious() instanceof HttpException && $e->getPrevious()->status === 404) {
                return null;
            }
            throw $e;
        }
        return is_array($a) && self::id($a['id'] ?? null) === $assetId ? $this->asNeutralAsset($a) : null;
    }

    /** Align type and import category from Halo's asset type name (the same rules as ITFlow's: Workstation, Laptop, Server, Printer…). */
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

    /** Halo's active / inactive. */
    public function assetStatus(bool $retired): string
    {
        return $retired ? 'Inactive' : 'Active';
    }

    /** An inactive Halo asset is retired. */
    public function statusRetired(?string $status): bool
    {
        return strtolower(trim((string) $status)) === 'inactive';
    }

    // ---- Writes: none (read-only beta) ----

    /** Stops any write: this integration only reads (the capabilities never offer one, so this is a last line of defence). */
    private function readOnly(): never
    {
        throw new \RuntimeException('HaloPSA is read-only in Align: nothing is sent to it.');
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

    // ---- Not read yet ----

    /** Software licences aren't read yet (not in CAPS). */
    public function licenses(): array
    {
        return [];
    }

    /** Suppliers aren't read yet (not in CAPS). */
    public function vendors(): array
    {
        return [];
    }

    /** Tickets aren't read yet (not in CAPS): nothing stored, nothing reported complete. */
    public function tickets(string $since, array $state, callable $store): array
    {
        return ['complete' => false, 'sla' => null, 'state' => $state];
    }

    /** Tickets aren't read yet. */
    public function ticket(string $ticketId): ?array
    {
        return null;
    }

    // ---- Invoices ----

    /**
     * Halo invoices for the managed-services estimate: the total, the invoice date, and the recurring invoice that made
     * it (which marks it recurring and names its schedule). Halo has no draft status on an invoice, so status is empty.
     */
    public function invoices(): array
    {
        $out = [];
        $priced = 0;
        foreach ($this->all('/Invoice', 'invoices') as $r) {
            $rec = self::id($r['recurring_invoice_id'] ?? null);
            $t = $r['total'] ?? null;
            $amount = is_int($t) || is_float($t) || (is_string($t) && is_numeric($t)) ? (float) $t : null;
            $amount = $amount !== null && is_finite($amount) && abs($amount) < 1e12 ? $amount : null; // "1e999" is numeric but infinite
            $priced += $amount !== null ? 1 : 0;
            $out[] = [
                'client_id' => self::id($r['client_id'] ?? null),
                'date' => self::date($r['invoice_date'] ?? null) ?? '',
                'status' => '',
                'amount' => $amount ?? 0.0,
                'recurring' => $rec !== '',
                'schedule' => $rec ?: null,
            ];
        }
        // No total on any invoice means Halo named the field differently: an estimate of zero for every client would be wrong
        if ($out && $priced === 0) {
            throw new \RuntimeException('HaloPSA\'s invoices came back without totals, so the managed-services estimate wasn\'t changed. Please report this on GitHub.');
        }
        return $out;
    }

    // ---- Links: none (Halo's page addresses aren't documented) ----

    /** No link. */
    public function clientUrl(string $clientId): ?string
    {
        return null;
    }

    /** No link. */
    public function assetUrl(string $clientId, string $assetId): ?string
    {
        return null;
    }

    /** No link. */
    public function ticketUrl(string $ticketId): ?string
    {
        return null;
    }
}
