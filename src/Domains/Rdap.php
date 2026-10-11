<?php
declare(strict_types=1);

namespace Align\Domains;

use Align\Config;
use Align\DB;
use Align\Settings;

/**
 * 2.10.0 Domain registrations from public RDAP (the registration data protocol that replaced WHOIS): for each client
 * domain, its registrar and expiry date. The domains are the client's email domains (EmailAuth::targets: the ones
 * Microsoft 365 or Google Workspace bring in, and the ones added by hand) plus its website's domain. IANA's bootstrap
 * file says which registry answers for each ending (.com, .org...); it's kept for a week. Each domain is read at
 * most every REFRESH_DAYS days (daily once it expires within SOON_DAYS), at most MAX_PER_RUN per sync.
 *
 * SECURITY: RDAP answers are untrusted: only the expiry date (a real date) and the registrar's name (cleaned text,
 * cut to size) are kept. Requests go only to https registry addresses from the bootstrap file (http ones are skipped)
 * through HttpClient, which refuses private and local addresses; redirects aren't followed. Domain names are checked
 * (EmailAuth::domainOk) before they go into a URL. The bootstrap address can be changed only on a test install
 * (allow_insecure_integrations, setting rdap_bootstrap_url).
 */
final class Rdap
{
    public const BOOTSTRAP = 'https://data.iana.org/rdap/dns.json';
    public const REFRESH_DAYS = 3;
    public const SOON_DAYS = 45;
    public const MAX_PER_RUN = 150;

    /** Registries that couldn't be reached in this run (base address => true): not asked again until the next one. */
    private static array $down = [];

    /** Server errors per registry in this run: three in a row and it waits for the next run. */
    private static array $errors = [];

    /** True when IANA's bootstrap file couldn't be read and there's no earlier copy (the endings are all unknown). */
    private static bool $noBootstrap = false;

    /** The second-level labels under which a country's registrable names sit (example.co.uk, example.com.au). */
    private const SECOND_LEVEL = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'ltd', 'plc', 'me', 'or', 'ne', 'go', 'gob', 'nic'];

    /**
     * The registrable domain of a host or URL ("https://www.shop.example.com/x" => example.com, "example.co.uk" stays),
     * or null when it isn't a domain name. A heuristic without the full public suffix list: the last two labels, or
     * three when the second-to-last is a common second level under a two-letter ending.
     */
    public static function registrable(?string $hostOrUrl): ?string
    {
        $h = strtolower(trim((string) $hostOrUrl));
        if ($h === '') {
            return null;
        }
        if (str_contains($h, '/') || str_contains($h, ':')) {
            $h = (string) parse_url(str_contains($h, '://') ? $h : "https://$h", PHP_URL_HOST);
        }
        $labels = explode('.', rtrim($h, '.'));
        $n = count($labels);
        if ($n < 2) {
            return null;
        }
        // .us locality names (co.lake.ca.us, k12.ca.us): not registered names with a record of their own; left out
        if ($labels[$n - 1] === 'us' && $n >= 2 && strlen($labels[$n - 2]) === 2) {
            return null;
        }
        $take = $n >= 3 && strlen($labels[$n - 1]) === 2 && in_array($labels[$n - 2], self::SECOND_LEVEL, true) ? 3 : 2;
        $d = implode('.', array_slice($labels, -$take));
        return EmailAuth::domainOk($d) ? $d : null;
    }

    /** A client's domains to read: its email domains and its website's, registrable form, unique, sorted. */
    public static function domainsFor(int $clientId): array
    {
        return self::domainsAll($clientId)[$clientId] ?? [];
    }

    /**
     * Every client's domains (as domainsFor), in a few queries whatever the number of clients: [client id => [domain]].
     * $clientId: one client only (archived included, for its own page); null: every client that isn't archived.
     */
    public static function domainsAll(?int $clientId = null): array
    {
        $out = [];
        foreach (EmailAuth::targets($clientId) as $cid => $list) {
            foreach (array_keys($list) as $d) {
                if ($r = self::registrable($d)) {
                    $out[(int) $cid][$r] = true;
                }
            }
        }
        $sites = $clientId === null ? DB::all("SELECT id, website FROM clients WHERE is_archived = 0 AND website IS NOT NULL AND website <> ''")
            : DB::all('SELECT id, website FROM clients WHERE id = ?', [$clientId]);
        foreach ($sites as $c) {
            if ($r = self::registrable((string) $c['website'])) {
                $out[(int) $c['id']][$r] = true;
            }
        }
        return array_map(function ($set) {
            ksort($set);
            return array_keys($set);
        }, $out);
    }

    /**
     * A client's domains with what RDAP said: [domain, registrar, expires_on, status, detail, checked_at, vendor_id
     * (the client's vendor whose name matches the registrar, or null), vendor (its name)]. Not read yet = status null.
     * $vendors: the client's Vendors::forClient() when the caller has it already.
     */
    public static function forClient(int $clientId, ?array $vendors = null): array
    {
        $domains = self::domainsFor($clientId);
        if (!$domains) {
            return [];
        }
        $have = [];
        foreach (DB::all('SELECT * FROM domain_rdap WHERE domain IN (' . implode(',', array_fill(0, count($domains), '?')) . ')', $domains) as $r) {
            $have[strtolower($r['domain'])] = $r;
        }
        $vendors ??= \Align\Vendors\Vendors::forClient($clientId);
        $out = [];
        foreach ($domains as $d) {
            $r = $have[$d] ?? ['registrar' => null, 'expires_on' => null, 'status' => null, 'detail' => null, 'checked_at' => null];
            $v = self::matchVendor($r['registrar'], $vendors);
            $out[] = ['domain' => $d, 'registrar' => $r['registrar'], 'expires_on' => $r['expires_on'], 'status' => $r['status'], 'detail' => $r['detail'],
                'checked_at' => $r['checked_at'], 'vendor_id' => $v ? (int) $v['id'] : null, 'vendor' => $v['name'] ?? null];
        }
        return $out;
    }

    /**
     * Domain expiry dates as contract dates (Contracts::upcoming's shape, kind 'domain'), for every client in planning
     * (or one), from $from (default 30 days ago) to $days ahead. Who it's with: the matching client vendor, else the
     * registrar's name. auto_renew is null: RDAP doesn't say whether a domain renews by itself.
     */
    public static function upcoming(?int $clientId = null, int $days = 365, ?string $from = null): array
    {
        $from ??= date('Y-m-d', strtotime('-30 days'));
        $to = date('Y-m-d', strtotime("+$days days"));
        $out = [];
        // only clients with a domain expiring in the window get the full look (with their vendors)
        $due = array_flip(array_map('strtolower', array_column(DB::all('SELECT domain FROM domain_rdap WHERE expires_on BETWEEN ? AND ?', [$from, $to]), 'domain')));
        if (!$due) {
            return [];
        }
        $clients = DB::all('SELECT id, name FROM clients WHERE ' . ($clientId === null ? 'is_archived = 0 AND planning_excluded = 0' : 'id = ?'), $clientId === null ? [] : [$clientId]);
        $all = self::domainsAll($clientId);
        foreach ($clients as $c) {
            if (!array_intersect_key(array_flip($all[(int) $c['id']] ?? []), $due)) {
                continue;
            }
            foreach (self::forClient((int) $c['id']) as $d) {
                if (!$d['expires_on'] || $d['expires_on'] < $from || $d['expires_on'] > $to) {
                    continue;
                }
                $vendor = $d['vendor'] ?? $d['registrar'];
                $out[] = ['date' => $d['expires_on'], 'kind' => 'domain', 'label' => 'Domain expires', 'name' => $d['domain'], 'client_id' => (int) $c['id'],
                    'client_name' => $c['name'], 'link' => '/clients/' . (int) $c['id'] . '/vendors#domains', 'annual' => 0.0,
                    'urgency' => \Align\Budget\Contracts::urgency($d['expires_on']), 'auto_renew' => null, 'term' => '',
                    'vendor' => $vendor, 'vendor_key' => $vendor ? \Align\Vendors\Vendors::key($vendor) : ''];
            }
        }
        return $out;
    }

    /** A registrar's name without its company suffix ("GoDaddy.com, LLC" => "GoDaddy.com"), for a new vendor's name. */
    public static function shortName(string $registrar): string
    {
        return trim((string) preg_replace('/[,\s]+(llc|l\.l\.c\.|inc\.?|ltd\.?|limited|corp\.?|corporation|gmbh|s\.?a\.?|b\.?v\.?|pty\.? ltd\.?|co\.?)$/i', '', trim($registrar))) ?: $registrar;
    }

    /**
     * The client vendor a registrar name stands for: both reduced to their distinctive part (no company suffix, ".com"
     * or generic words), then the same, or one the start of the other followed by a digit. Registrar vendors first.
     */
    public static function matchVendor(?string $registrar, array $vendors): ?array
    {
        // without company suffixes, a ".com" in the name and generic words ("GoDaddy.com, LLC" => godaddy,
        // "Tucows Domains Inc." => tucows), letters and digits only
        $norm = fn(?string $s) => preg_replace('/[^a-z0-9]+/', '', (string) preg_replace(['/\.(com|net|org|biz|info|io|co)\b/i', '/\b(domains?|registrar|registry|services?|online|internet|group|the)\b/i'], '',
            strtolower(self::shortName((string) $s)))) ?? '';
        $reg = $norm($registrar);
        if (strlen($reg) < 4) {
            return null;
        }
        usort($vendors, fn($a, $b) => ($b['category'] === 'registrar') <=> ($a['category'] === 'registrar'));
        foreach ($vendors as $v) {
            $vn = $norm($v['name']);
            // the whole name, one the start of the other ("GoDaddy" and "GoDaddy.com, LLC"); a shared first word isn't
            // enough ("Name.com" isn't Namecheap, "Google" isn't "Google Workspace")
            if (strlen($vn) >= 4 && ($vn === $reg || str_starts_with($reg, $vn) && !ctype_alpha($reg[strlen($vn)] ?? '') || str_starts_with($vn, $reg) && !ctype_alpha($vn[strlen($reg)] ?? ''))) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Reads every client domain that is due (archived clients left out), at most MAX_PER_RUN, and forgets results for
     * domains no client has had for a month. Called by the sync. Returns a short summary.
     */
    public static function refreshDue(bool $force = false): string
    {
        $all = [];
        foreach (self::domainsAll() as $list) {
            foreach ($list as $d) {
                $all[$d] = true;
            }
        }
        $have = array_column(DB::all('SELECT domain, expires_on, checked_at FROM domain_rdap'), null, 'domain');
        $n = 0;
        foreach (array_keys($all) as $d) {
            $h = $have[$d] ?? null;
            $age = $h ? time() - strtotime($h['checked_at']) : PHP_INT_MAX;
            $soon = $h && $h['expires_on'] && $h['expires_on'] <= date('Y-m-d', strtotime('+' . self::SOON_DAYS . ' days'));
            if (!$force && $age < ($soon ? 86400 : self::REFRESH_DAYS * 86400)) {
                continue;
            }
            if ($n >= self::MAX_PER_RUN) {
                break; // the rest on the next run
            }
            self::refresh($d);
            $n++;
        }
        // A domain no client has any more is forgotten after a month (a connection that drops for a while keeps its last answer)
        $gone = array_values(array_filter(array_diff(array_keys($have), array_keys($all)), fn($d) => strtotime($have[$d]['checked_at']) < time() - 30 * 86400));
        if ($gone) {
            DB::run('DELETE FROM domain_rdap WHERE domain IN (' . implode(',', array_fill(0, count($gone), '?')) . ')', array_values($gone));
        }
        return $n ? "$n domain" . ($n === 1 ? '' : 's') . ' read' : 'nothing due';
    }

    /** Reads one client's domains now (the Check now button). Returns how many were read. */
    public static function refreshClient(int $clientId): int
    {
        $n = 0;
        foreach (self::domainsFor($clientId) as $d) {
            self::refresh($d);
            $n++;
        }
        return $n;
    }

    /** Reads one domain and stores the result (see lookup()). */
    public static function refresh(string $domain): array
    {
        $r = self::lookup($domain);
        DB::run('INSERT INTO domain_rdap (domain, registrar, expires_on, status, detail, checked_at) VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE registrar = VALUES(registrar), expires_on = VALUES(expires_on), status = VALUES(status), detail = VALUES(detail), checked_at = NOW()',
            [$domain, $r['registrar'], $r['expires_on'], $r['status'], $r['detail']]);
        return $r;
    }

    /**
     * What RDAP says about a domain: ['status' => ok|unknown|missing, 'registrar', 'expires_on', 'detail']. A lookup
     * that fails keeps the last good registrar and date (a registry's bad moment doesn't blank them).
     */
    public static function lookup(string $domain): array
    {
        $domain = strtolower($domain);
        $last = DB::one('SELECT registrar, expires_on FROM domain_rdap WHERE domain = ?', [$domain]) ?? ['registrar' => null, 'expires_on' => null];
        $keep = fn(string $detail) => ['status' => 'unknown', 'registrar' => $last['registrar'], 'expires_on' => $last['expires_on'], 'detail' => $detail];
        if (!EmailAuth::domainOk($domain)) {
            return ['status' => 'unknown', 'registrar' => null, 'expires_on' => null, 'detail' => 'Not a domain name.'];
        }
        $tld = substr($domain, strrpos($domain, '.') + 1);
        $base = self::services()[$tld] ?? null;
        if ($base === null) {
            return $keep(self::$noBootstrap ? 'The list of registration services couldn\'t be read; it\'s tried again on the next sync.' : "No public registration service for .$tld domains.");
        }
        if (isset(self::$down[$base])) {
            return $keep('The registry couldn\'t be reached.');
        }
        try {
            $r = self::http()->request('GET', rtrim($base, '/') . '/domain/' . rawurlencode($domain), ['Accept' => 'application/rdap+json, application/json']);
        } catch (\Align\Http\HttpException $e) {
            if ($e->status === 404) {
                return ['status' => 'missing', 'registrar' => null, 'expires_on' => null, 'detail' => 'The registry has no registration for this domain.'];
            }
            // No answer at all, or "slow down" (429): its other domains wait for the next run. A server error for one
            // domain doesn't, unless the registry keeps answering with them.
            if (!$e->status || $e->status === 429 || ($e->status >= 500 && (self::$errors[$base] = (self::$errors[$base] ?? 0) + 1) >= 3)) {
                self::$down[$base] = true;
            }
            return $keep('The registry couldn\'t be reached' . ($e->status ? " (HTTP {$e->status})" : '') . '.');
        } catch (\Throwable) {
            self::$down[$base] = true;
            return $keep('The registry couldn\'t be reached.');
        }
        self::$errors[$base] = 0;
        $j = is_array($r['json'] ?? null) ? $r['json'] : [];
        $exp = null;
        foreach (is_array($j['events'] ?? null) ? $j['events'] : [] as $ev) {
            if (is_array($ev) && ($ev['eventAction'] ?? '') === 'expiration' && is_string($ev['eventDate'] ?? null)
                && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ev['eventDate'], $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $exp = $m[0];
            }
        }
        return ['status' => 'ok', 'registrar' => self::registrar($j), 'expires_on' => $exp, 'detail' => $exp ? null : 'The registry doesn\'t publish an expiry date for it.'];
    }

    /** The registrar's name from an RDAP answer (the entity with the registrar role, its vCard fn), cleaned; or null. */
    private static function registrar(array $j): ?string
    {
        foreach (is_array($j['entities'] ?? null) ? $j['entities'] : [] as $en) {
            if (!is_array($en) || !in_array('registrar', is_array($en['roles'] ?? null) ? $en['roles'] : [], true)) {
                continue;
            }
            foreach (is_array($en['vcardArray'][1] ?? null) ? $en['vcardArray'][1] : [] as $prop) {
                if (is_array($prop) && ($prop[0] ?? '') === 'fn' && is_string($prop[3] ?? null)) {
                    $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $prop[3]));
                    return $name !== '' ? mb_substr($name, 0, 190) : null;
                }
            }
        }
        return null;
    }

    /**
     * Ending => registry RDAP base address, from IANA's bootstrap file (kept a week in rdap_services; the copy there is
     * used when the file can't be read). Only https addresses are kept (http too on a test install).
     */
    private static function services(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $rows = DB::all('SELECT tld, base_url, fetched_at FROM rdap_services');
        $have = array_column($rows, 'base_url', 'tld');
        $fresh = $rows && min(array_map(fn($r) => strtotime($r['fetched_at']), $rows)) > time() - 7 * 86400;
        if ($fresh) {
            return $map = $have;
        }
        $insecure = (bool) Config::get('allow_insecure_integrations', false);
        $url = $insecure && Settings::get('rdap_bootstrap_url') ? (string) Settings::get('rdap_bootstrap_url') : self::BOOTSTRAP;
        $new = [];
        try {
            $j = self::http()->getJson($url);
            foreach (is_array($j['services'] ?? null) ? $j['services'] : [] as $svc) {
                $urls = array_values(array_filter(is_array($svc[1] ?? null) ? $svc[1] : [], fn($u) => is_string($u) && strlen($u) <= 255
                    && (str_starts_with($u, 'https://') || ($insecure && str_starts_with($u, 'http://')))));
                foreach ($urls ? (is_array($svc[0] ?? null) ? $svc[0] : []) : [] as $tld) {
                    if (is_string($tld) && preg_match('/^[a-z0-9-]{2,63}$/i', $tld)) {
                        $new[strtolower($tld)] = $urls[0];
                    }
                }
            }
        } catch (\Throwable) {
            $new = []; // keep the copy we have, however old
        }
        if (!$new) {
            self::$noBootstrap = !$have;
            return $map = $have;
        }
        DB::transaction(function () use ($new) {
            DB::run('DELETE FROM rdap_services');
            $now = date('Y-m-d H:i:s');
            foreach (array_chunk($new, 300, true) as $chunk) {
                DB::run('INSERT INTO rdap_services (tld, base_url, fetched_at) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?)')),
                    array_merge(...array_map(fn($t, $u) => [$t, $u, $now], array_keys($chunk), $chunk)));
            }
        });
        return $map = $new;
    }

    /** The HTTP client for RDAP: short timeouts, two tries. */
    private static function http(): \Align\Http\HttpClient
    {
        static $h = null;
        return $h ??= new \Align\Http\HttpClient(10, 2);
    }
}
