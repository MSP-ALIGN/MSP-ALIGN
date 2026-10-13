<?php
declare(strict_types=1);

namespace Align\Providers\Rmm;

use Align\DB;
use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * 2.13.0 Datto RMM as an RMM provider, read-only (beta). Its API v2 is on the account's platform host
 * (https://<platform>-api.centrastage.net: pinotage, merlot, concord, vidal, zinfandel or syrah). A bearer token comes
 * from POST /auth/oauth/token (password grant: the API key and secret key a Datto user generates, with Datto's fixed
 * public client), and lasts 100 hours; one is fetched per run.
 *
 * Read: GET /api/v2/account/sites (organizations; Datto's built-in "Deleted Devices" site is left out) and
 * GET /api/v2/account/devices (devices: host name, site, category, OS, last seen, logged-in user, online). Make, model
 * and serial number aren't in the device list; they come from GET /api/v2/audit/device/{uid}, one request per device,
 * so the answers are kept in rmm_hardware and a device is audited again only when Datto audited it again since. At
 * most MAX_AUDITS audits are tried per sync, spaced AUDIT_GAP_MS apart (about 120 a minute; Datto allows 600 a minute
 * for the whole account, shared with every other tool using it), so a large account fills in over a few hourly syncs. Datto RMM
 * device page links aren't documented, so device_links isn't offered.
 *
 * SECURITY: Datto's replies are untrusted. A list that isn't Datto's (an HTML page, an error, a short read) is an error,
 * never "no devices" (the sync marks devices the RMM no longer returns as removed): pages are read by number until
 * pageDetails.totalCount different records have come back. Ids must be Datto's uid shape, text is cleaned of control
 * characters and cut to the devices table's sizes, times must be real. Paging never follows Datto's nextPageUrl (the
 * token would go wherever it pointed). Datto blocks the caller's IP for a while after a burst past its limit, so a 429
 * is never retried quickly: a list waits a minute once, and audits stop for this run. The platform is a fixed list
 * (a full URL only from the database, as the tests do), checked by HttpClient on every request; the secret goes only
 * to the token URL, the token only in the Authorization header.
 */
final class DattoRmm implements RmmProvider
{
    /** Datto's platforms (the host of the web portal an admin signs in to). */
    public const PLATFORMS = [
        'pinotage' => 'Pinotage', 'merlot' => 'Merlot', 'concord' => 'Concord',
        'vidal' => 'Vidal', 'zinfandel' => 'Zinfandel', 'syrah' => 'Syrah',
    ];

    /** Rows per page (Datto's most). */
    private const PAGE = 250;

    /** Pages read from one list before giving up. */
    private const MAX_PAGES = 2000;

    /** Hardware audits per sync, and the pause between them. */
    public const MAX_AUDITS = 200;
    private const AUDIT_GAP_MS = 500;

    /** Failed audits in a row that end this run's audits (something is wrong with the audit endpoint, not one device). */
    private const MAX_FAILS = 5;

    /** Exception code for a failed sign-in (audits stop: every further request would sign in again). */
    private const SIGN_IN_FAILED = 1;

    /** Datto's fixed OAuth client for API keys. */
    private const OAUTH_CLIENT = 'public-client:public';

    private HttpClient $http;

    /** The bearer token for this run, once fetched. */
    private ?string $token = null;

    /** A person is waiting (Test): a 429 isn't waited out. */
    private bool $interactive = false;

    /** Sites as read (untrusted), once per run. */
    private ?array $siteRows = null;

    /** What the last devices() call audited, for the sync log (see syncNote). */
    private string $auditNote = '';

    /** $base: the platform's API address; $key/$secret: null when only building links. $waitSeconds: the pause after a 429. */
    public function __construct(private string $base, private ?string $key, private ?string $secret, private int $waitSeconds = 60)
    {
        $this->http = new HttpClient(120, 1); // no quick retries: a burst after a 429 gets the IP blocked
    }

    /** The API address for a saved platform: https://<platform>-api.centrastage.net (or a full URL from the database). */
    public static function baseFor(string $platform): string
    {
        return str_contains($platform, '://') ? rtrim($platform, '/') : 'https://' . (isset(self::PLATFORMS[$platform]) ? $platform : 'pinotage') . '-api.centrastage.net';
    }

    /** From the saved drmm_platform, drmm_api_key and drmm_api_secret ($forLinks: no keys needed). Throws when not set up. */
    public static function fromSettings(bool $forLinks = false): self
    {
        $platform = (string) Settings::get('drmm_platform', '');
        $key = $forLinks ? null : (string) Settings::get('drmm_api_key', '');
        $secret = $forLinks ? null : (string) Settings::secret('drmm_api_secret');
        if ($platform === '' || (!$forLinks && ($key === '' || $secret === ''))) {
            throw new \RuntimeException('Datto RMM is not configured (Integrations).');
        }
        // ALIGN_DRMM_WAIT shortens the pause after a 429 for the test suite only: a short pause gets Datto to block the
        // server's address, so anything under 30 seconds counts only on a development copy (allow_insecure_integrations)
        $wait = getenv('ALIGN_DRMM_WAIT') !== false ? (int) getenv('ALIGN_DRMM_WAIT') : 60;
        $wait = \Align\Config::get('allow_insecure_integrations', false) ? max(0, $wait) : max(30, $wait);
        return new self(self::baseFor($platform), $key, $secret, $wait);
    }

    /** The connector key (fixed; also stored with every record from this provider). */
    public function key(): string
    {
        return 'dattormm';
    }

    /** Display name. */
    public function name(): string
    {
        return 'Datto RMM';
    }

    /** Last user and last check-in; no device links (Datto's page addresses aren't documented). */
    public function supports(string $capability): bool
    {
        return in_array($capability, ['last_user', 'last_contact'], true);
    }

    // ---- Requests ----

    /** A bearer token (password grant with the API keys), fetched once per run. */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        if ($this->key === null || $this->secret === null) {
            throw new \RuntimeException('Datto RMM is not configured (Integrations).');
        }
        try {
            $r = $this->http->request('POST', $this->base . '/auth/oauth/token',
                ['Authorization' => 'Basic ' . base64_encode(self::OAUTH_CLIENT), 'Accept' => 'application/json'],
                ['grant_type' => 'password', 'username' => $this->key, 'password' => $this->secret]);
        } catch (HttpException $e) {
            throw new \RuntimeException(match (true) {
                in_array($e->status, [400, 401], true) => 'Datto RMM refused the API key or secret key (HTTP ' . $e->status . '). Check both, that API access is on (Setup → Global Settings → Access Control) and that the user isn\'t disabled.',
                $e->status === 404 => 'Datto RMM has no sign-in at ' . parse_url($this->base, PHP_URL_HOST) . ' (HTTP 404). Pick the platform shown as the API URL on the user\'s page in Datto.',
                $e->status === 429 => 'Datto RMM\'s request limit for your account was reached. Try again in a few minutes.',
                default => 'Datto RMM couldn\'t be signed in to: ' . $e->getMessage(),
            }, self::SIGN_IN_FAILED, $e);
        }
        $t = is_array($r['json']) ? ($r['json']['access_token'] ?? null) : null;
        if (!is_string($t) || !preg_match('/^[A-Za-z0-9._~+\/=-]{10,8000}$/', $t)) {
            throw new \RuntimeException('Datto RMM\'s sign-in didn\'t return a token. Check the platform.', self::SIGN_IN_FAILED);
        }
        return $this->token = $t;
    }

    /**
     * One GET; the decoded JSON or an explained error. A 401 gets one new token and one retry; a 429 waits
     * $waitSeconds once when $wait (lists) and otherwise throws at once (audits), as does a second 429; a 504 (which
     * Datto sends now and then) is tried once more after 10 seconds.
     */
    private function get(string $path, array $query = [], bool $wait = true): mixed
    {
        $url = $this->base . '/api/v2' . $path . ($query ? '?' . http_build_query($query) : '');
        $retried = [];
        while (true) {
            try {
                return $this->http->request('GET', $url, ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'])['json'];
            } catch (HttpException $e) {
                $s = $e->status;
                if (!isset($retried[$s]) && ($s === 401 || $s === 504 || ($s === 429 && $wait))) {
                    $retried[$s] = true;
                    if ($s === 401) {
                        $this->token = null;
                    } else {
                        sleep($s === 429 ? $this->waitSeconds : min(10, $this->waitSeconds));
                    }
                    continue;
                }
                throw new \RuntimeException(match (true) {
                    $s === 401 => 'Datto RMM refused Align\'s token (HTTP 401).',
                    $s === 403 => 'Datto RMM refused ' . $path . ' (HTTP 403): the API user\'s security level must allow viewing sites and devices, or Datto has paused requests from this server after too many (wait a few minutes).',
                    $s === 404 => 'Datto RMM has no ' . $path . ' at ' . parse_url($this->base, PHP_URL_HOST) . ' (HTTP 404). Check the platform.',
                    $s === 429 => 'Datto RMM\'s request limit for your account was reached; the rest waits for the next sync.',
                    default => 'Datto RMM couldn\'t be read: ' . $e->getMessage(),
                }, $s, $e);
            }
        }
    }

    /**
     * Every record of a list, read 250 at a time by page number (from 0) until Datto says there's no next page. $key:
     * the list's name ("sites"). Throws when a page isn't that list, when it repeats the page before, when the pages
     * end before pageDetails.totalCount records came back (records added or removed during the read; the next sync
     * tries again) or end on a full page with no total, or after MAX_PAGES pages. Records are kept once per uid; ones without a usable uid are skipped but counted.
     */
    private function all(string $path, string $key): array
    {
        $byId = [];
        $other = 0; // records without a usable uid: skipped, but they count towards totalCount
        $total = null;
        $prevFirst = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $j = $this->get($path, ['page' => $page, 'max' => self::PAGE], !$this->interactive);
            if (!is_array($j) || !isset($j[$key]) || !is_array($j[$key]) || !array_is_list($j[$key]) || !is_array($j['pageDetails'] ?? null)) {
                throw new \RuntimeException("Datto RMM sent something other than a list of $key for $path. Check the platform.");
            }
            $pd = $j['pageDetails'];
            $total ??= is_int($pd['totalCount'] ?? null) && $pd['totalCount'] >= 0 ? $pd['totalCount'] : null;
            $list = array_values(array_filter($j[$key], 'is_array'));
            $first = $list ? self::uid($list[0]['uid'] ?? null) : null;
            if ($first !== null && $first === $prevFirst) {
                throw new \RuntimeException("Datto RMM returned the same $key again; the read was stopped.");
            }
            $prevFirst = $first;
            foreach ($list as $r) {
                if (($u = self::uid($r['uid'] ?? null)) !== null) {
                    $byId[$u] = $r;
                } else {
                    $other++;
                }
            }
            $other += count($j[$key]) - count($list); // entries that aren't records at all
            $have = count($byId) + $other;
            // The pages end when Datto says there's no next page (or sends an empty one); only then is the count checked,
            // so neither a totalCount that's too low nor one that's missing can cut the list short
            if (($pd['nextPageUrl'] ?? null) === null || $list === []) {
                if ($total === null && count($j[$key]) >= self::PAGE) {
                    throw new \RuntimeException("Datto RMM's list of $key stopped on a full page without a total; nothing was changed and the next sync tries again.");
                }
                if ($total !== null && $have < $total) {
                    throw new \RuntimeException("Datto RMM's list of $key ended early ($have of $total); nothing was changed and the next sync tries again.");
                }
                return array_values($byId);
            }
        }
        throw new \RuntimeException("Datto RMM returned more than " . (self::MAX_PAGES * self::PAGE) . " $key; the read was stopped.");
    }

    /** Checks the keys with the sites list (admin, Integrations page; a 429 fails at once: a person is waiting). */
    public function test(): string
    {
        $this->interactive = true;
        $n = count($this->organizations());
        return "Connected. $n site" . ($n === 1 ? '' : 's') . ' readable.';
    }

    // ---- Value helpers ----

    /** A Datto uid (letters, digits and dashes, at most 64: the devices column), or null. */
    private static function uid(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $v) ? $v : null;
    }

    /** Remote text on one line, cut to $max (2.17.1: the shared Clean::text()). */
    private static function text(mixed $v, int $max): ?string
    {
        return \Align\Providers\Clean::text($v, $max);
    }

    /** A Datto time (epoch milliseconds, or seconds, or an ISO date) in this server's timezone (Y-m-d H:i:s), or null. */
    private static function time(mixed $v): ?string
    {
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{9,14}$/', $v))) {
            $n = (float) $v;
            $n = $n > 1e11 ? $n / 1000 : $n;
            return $n > 0 && $n < 253402300800 ? date('Y-m-d H:i:s', (int) $n) : null;
        }
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $v)) {
            try {
                return (new \DateTimeImmutable($v, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /** A Datto epoch time in UTC (Y-m-d H:i:s) for comparing audit times (no daylight-saving or timezone shifts), or null. */
    private static function utc(mixed $v): ?string
    {
        if (!(is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{9,14}$/', $v)))) {
            return null;
        }
        $n = (float) $v;
        $n = $n > 1e11 ? $n / 1000 : $n;
        return $n > 0 && $n < 253402300800 ? gmdate('Y-m-d H:i:s', (int) $n) : null;
    }

    /** Datto's "Microsoft Windows 11 Pro 10.0.22631" as [Windows 11 Pro, 22631] (the build when there is one). */
    public static function os(mixed $v): array
    {
        $s = self::text($v, 255);
        if ($s === null) {
            return [null, null];
        }
        $s = (string) preg_replace('/^Microsoft\s+/i', '', $s);
        if (preg_match('/^(.*?)[\s,]+(?:\d+\.\d+\.)(\d{4,6})(?:\.\d+)?$/', $s, $m) && trim($m[1]) !== '') {
            return [self::text($m[1], 255), $m[2]];
        }
        return [self::text($s, 255), null];
    }

    // ---- Organizations and devices ----

    /** Datto's sites as read, once per run. */
    private function sites(): array
    {
        return $this->siteRows ??= $this->all('/account/sites', 'sites');
    }

    /** Every Datto site but the built-in "Deleted Devices" one. The description notes the device count. */
    public function organizations(): array
    {
        $out = [];
        foreach ($this->sites() as $s) {
            $uid = self::uid($s['uid'] ?? null);
            $name = self::text($s['name'] ?? null, 255);
            if ($uid === null || strcasecmp((string) $name, 'Deleted Devices') === 0) {
                continue;
            }
            $count = is_array($s['devicesStatus'] ?? null) && is_int($s['devicesStatus']['numberOfDevices'] ?? null) ? $s['devicesStatus']['numberOfDevices'] : null;
            $out[] = ['id' => $uid, 'name' => $name ?? 'Site ' . $uid,
                'description' => self::text($s['description'] ?? null, 2000) ?? ($count !== null ? "$count device" . ($count === 1 ? '' : 's') . ' in Datto RMM' : null)];
        }
        return $out;
    }

    /**
     * Every device that isn't deleted, as a neutral device, with make, model and serial from the audit cache (see the
     * class note; audits() brings it up to date first). Its organization is its site (none for a device in a site that
     * isn't listed, such as Deleted Devices).
     */
    public function devices(): array
    {
        $orgs = array_column($this->organizations(), 'id', 'id');
        $rows = [];
        foreach ($this->all('/account/devices', 'devices') as $d) {
            $uid = self::uid($d['uid'] ?? null);
            if ($uid !== null && empty($d['deleted'])) {
                $rows[$uid] = $d;
            }
        }
        $hw = $this->audits($rows);
        $out = [];
        foreach ($rows as $uid => $d) {
            $h = $hw[$uid] ?? [];
            $make = self::text($h['manufacturer'] ?? null, 190);
            $model = self::text($h['model'] ?? null, 190);
            $host = self::text($d['hostname'] ?? null, 255);
            [$osName, $osBuild] = self::os($d['operatingSystem'] ?? null);
            $type = is_array($d['deviceType'] ?? null) ? strtolower((string) self::text($d['deviceType']['category'] ?? null, 40)) : '';
            $class = strtolower((string) self::text($d['deviceClass'] ?? null, 40));
            $isVirtual = preg_match('/vmware|virtualbox|kvm|qemu|xen|virtual machine|hyper-v|parallels|innotek/i', "$make $model") === 1;
            // Datto's class and category onto the node classes Align's type rules already know (NinjaOne's)
            [$nodeClass, $chassis] = match (true) {
                $class === 'esxihost' => ['VMWARE_VM_HOST', ''],
                $class === 'printer' => ['NMS_PRINTER', ''],
                $class === 'rmmnetworkdevice' => ['NMS_NETWORK', ''],
                $class === 'unknown' => ['NMS_OTHER', ''],
                $type === 'server' => ['WINDOWS_SERVER', ''],
                $type === 'laptop' => [preg_match('/mac ?os|os x|darwin/i', (string) $osName) ? 'MAC' : 'WINDOWS_WORKSTATION', 'LAPTOP'],
                $type === 'desktop' => [preg_match('/mac ?os|os x|darwin/i', (string) $osName) ? 'MAC' : 'WINDOWS_WORKSTATION', 'DESKTOP'],
                default => ['WINDOWS_WORKSTATION', ''],
            };
            $site = self::uid($d['siteUid'] ?? null);
            $out[] = [
                'id' => $uid,
                'org_id' => $site !== null && isset($orgs[$site]) ? $site : null,
                'display_name' => $host,
                'system_name' => $host,
                'node_class' => self::text(trim("$class $type"), 60),
                'device_type' => \Align\Integrations\NinjaOne::type($nodeClass, $chassis, (string) $make, (string) $model, (string) $host, $isVirtual, (string) $osName),
                'manufacturer' => $make,
                'model' => $model,
                'serial' => self::text($h['serial'] ?? null, 190),
                'chassis' => null,
                'is_virtual' => $isVirtual,
                'os_name' => $osName,
                'os_build' => $osBuild,
                'os_release_id' => null,
                'last_contact' => self::time($d['lastSeen'] ?? null),
                'created_at' => self::time($d['creationDate'] ?? null),
                'last_user' => self::text($d['lastLoggedInUser'] ?? null, 190),
                'offline' => ($d['online'] ?? null) !== true,
            ];
        }
        return $out;
    }

    /**
     * Brings the hardware cache up to date for $rows (uid => Datto device) and returns uid => [manufacturer, model,
     * serial]. Audits computers (deviceClass "device") that were never read, then ones Datto audited again since,
     * at most MAX_AUDITS tries per run, AUDIT_GAP_MS apart. A device that can't be audited keeps what was known; a 429,
     * a 403 or a failed sign-in ends this run's audits (Datto pauses a caller that keeps going), and so do MAX_FAILS
     * failures in a row. A device's type can change once its hardware arrives (a VMware machine becomes virtual). Cache rows of devices Datto no longer lists
     * are deleted (the list was complete, or all() would have thrown).
     */
    private function audits(array $rows): array
    {
        $cache = [];
        foreach (DB::all("SELECT device_id, audit_at, manufacturer, model, serial, checked_at FROM rmm_hardware WHERE provider = 'dattormm'") as $c) {
            $cache[(string) $c['device_id']] = $c;
        }
        $due = [];
        foreach ($rows as $uid => $d) {
            if (strtolower((string) ($d['deviceClass'] ?? 'device')) !== 'device') {
                continue; // printers, network devices and ESXi hosts have no computer audit
            }
            $audited = self::utc($d['lastAuditDate'] ?? null); // stored and compared in UTC
            $c = $cache[$uid] ?? null;
            if (!$c) {
                $due[$uid] = 0; // never read: first
            } elseif ($audited !== null && ($c['audit_at'] === null || $audited > $c['audit_at'])) {
                $due[$uid] = 1;
            } elseif ($c['manufacturer'] === null && $c['model'] === null && $c['serial'] === null && strtotime((string) $c['checked_at']) < time() - 7 * 86400) {
                $due[$uid] = 2; // 2.15.2: no hardware known yet (no audit, or it couldn't be read): tried again weekly, after the others
            }
        }
        asort($due);
        $tried = $done = $fails = 0;
        $stopped = '';
        $now = date('Y-m-d H:i:s');
        foreach (array_keys($due) as $uid) {
            if ($tried >= self::MAX_AUDITS) {
                break;
            }
            if ($tried > 0) {
                usleep(self::AUDIT_GAP_MS * 1000); // before every request but the first, failed ones too
            }
            $tried++;
            try {
                $a = $this->get('/audit/device/' . $uid, [], false);
            } catch (\RuntimeException $e) {
                if (in_array($e->getCode(), [403, 429, self::SIGN_IN_FAILED], true)) {
                    $stopped = $e->getCode() === self::SIGN_IN_FAILED ? ' (signing in again failed; the rest waits for the next sync)' : ' (Datto asked Align to slow down; the rest waits for the next sync)';
                    break;
                }
                $this->noted($uid, $rows[$uid], $cache, $now);
                if (++$fails >= self::MAX_FAILS) {
                    $stopped = ' (Datto\'s hardware audits keep failing: ' . mb_substr($e->getMessage(), 0, 120) . ')';
                    break;
                }
                continue; // one device's audit failing doesn't stop the others
            }
            if (!is_array($a)) { // a 200 that isn't Datto's JSON: not "no hardware"
                $this->noted($uid, $rows[$uid], $cache, $now); // 2.15.2: recorded, so it isn't first in the queue every run
                if (++$fails >= self::MAX_FAILS) {
                    $stopped = ' (Datto\'s hardware audits keep coming back unreadable)';
                    break;
                }
                continue;
            }
            $fails = 0;
            $done++;
            $sys = is_array($a['systemInfo'] ?? null) ? $a['systemInfo'] : [];
            $bios = is_array($a['bios'] ?? null) ? $a['bios'] : [];
            $serial = self::text($bios['serialNumber'] ?? null, 190);
            $row = [
                'provider' => 'dattormm',
                'device_id' => (string) $uid,
                'audit_at' => self::utc($rows[$uid]['lastAuditDate'] ?? null),
                'manufacturer' => self::text($sys['manufacturer'] ?? null, 190),
                'model' => self::text($sys['model'] ?? null, 190),
                'serial' => $serial !== null && normalize_serial($serial) !== null ? $serial : null,
                'checked_at' => $now,
            ];
            DB::upsertMany('rmm_hardware', [$row], ['provider', 'device_id']);
            $cache[$uid] = $row;
        }
        if ($cache && $rows) {
            $gone = array_diff(array_keys($cache), array_map('strval', array_keys($rows)));
            foreach (array_chunk(array_values($gone), 500) as $chunk) {
                DB::run("DELETE FROM rmm_hardware WHERE provider = 'dattormm' AND device_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
            }
        }
        $left = count($due) - $tried;
        $failed = $tried - $done;
        $this->auditNote = $due ? "hardware read for $done" . ($failed ? ", $failed couldn't be read" : '') . ($left > 0 ? ", $left still to read" : '') . $stopped : '';
        return $cache;
    }

    /**
     * Records a device whose audit failed, when Align knows nothing about its hardware yet, as audited at Datto's current
     * audit time, so it is tried again after Datto audits it again (or weekly) rather than first in every run. Known
     * hardware is left as it is; only the time of the try is updated.
     */
    private function noted(int|string $uid, array $device, array &$cache, string $now): void
    {
        if (isset($cache[$uid])) {
            // 2.15.2: the try is recorded, so a device without hardware is tried weekly rather than every run
            DB::run("UPDATE rmm_hardware SET checked_at = ? WHERE provider = 'dattormm' AND device_id = ?", [$now, (string) $uid]);
            $cache[$uid]['checked_at'] = $now;
            return;
        }
        $row = ['provider' => 'dattormm', 'device_id' => (string) $uid, 'audit_at' => self::utc($device['lastAuditDate'] ?? null),
            'manufacturer' => null, 'model' => null, 'serial' => null, 'checked_at' => $now];
        DB::upsertMany('rmm_hardware', [$row], ['provider', 'device_id']);
        $cache[$uid] = $row;
    }

    /** A note for the sync log after devices() ("hardware read for 12, 40 still to read"), or ''. */
    public function syncNote(): string
    {
        return $this->auditNote;
    }

    /** Datto's device page addresses aren't documented: no link. */
    public function deviceUrl(string $deviceId): ?string
    {
        return null;
    }
}
