<?php
declare(strict_types=1);

namespace Align\Domains;

use Align\Config;
use Align\DB;
use Align\Settings;

/**
 * 2.6.3 Email authentication for a client's domain, read from public DNS once a day: SPF (one record, ending in -all
 * or ~all), DKIM (a key published at a selector) and DMARC (a policy of quarantine or reject). The result counts in the
 * health score's Security area and suggests answers for the controls and standards linked to these checks.
 *
 * 2.7.4, against false negatives:
 *  - Records are read through public resolvers over HTTPS (DNS-over-HTTPS: Cloudflare, then Google), the way online
 *    checkers like MxToolbox see them, rather than only through the server's own resolver: a record either one finds
 *    counts, and a lookup that fails at both falls back to the server's resolver (servers that can't reach them).
 *  - DKIM is looked for at the mail service's selector (google; selector1/selector2 for Microsoft 365), any selector
 *    set for the domain, then the selectors common mail services use (COMMON_SELECTORS).
 *  - Any client can be checked: domains added by hand (client_email_domains) besides the one its Microsoft 365 or
 *    Google Workspace connection brings in (which can be skipped). Each domain is checked on its own; the client's
 *    result per check is the worst of its domains.
 *
 * Security assumptions: only public DNS is read (TXT records), by name, through fixed resolver addresses over HTTPS.
 * Domain names come from the client's connection or from a tech (checked against a strict host-name pattern before
 * they're stored or looked up, fully qualified), selectors against a stricter one. DNS answers are remote text: only
 * a few words of them are kept in the detail (cut), and views escape them. The test overrides (dns_mock_url,
 * dns_doh_urls) apply only with allow_insecure_integrations.
 */
final class EmailAuth
{
    /** The checks: key => label (automatic checks in compliance and alignment, like the Microsoft 365 ones). */
    public const CHECKS = [
        'email_spf' => 'SPF record for the email domain (ending in -all or ~all)',
        'email_dkim' => 'DKIM signing set up for the email domain',
        'email_dmarc' => 'DMARC policy of quarantine or reject',
    ];

    /** DKIM selectors to look for first, by where the mail is: google, m365. */
    private const SELECTORS = ['google' => ['google'], 'm365' => ['selector1', 'selector2']];
    /**
     * 2.7.4 Then these, which common mail services use (Google, Microsoft 365, generic, Mailchimp/Mandrill, SendGrid,
     * Zoho, Fastmail, Proton, Mailgun, Postmark-style and hosting control panels), looked up through one resolver only.
     */
    public const COMMON_SELECTORS = ['google', 'selector1', 'selector2', 'default', 'dkim', 'mail', 'k1', 'k2', 's1', 's2', 'smtp', 'zoho', 'zmail',
        'fm1', 'fm2', 'fm3', 'protonmail', 'protonmail2', 'mx', 'pm', 'mandrill', 'everlytickey1', 'mxvault', 'key1'];
    /** Public DNS-over-HTTPS resolvers (JSON API), asked in this order. */
    private const DOH = ['https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve'];
    /** Resolvers that couldn't be reached in this run: url => true. */
    private static array $down = [];

    /** Read again after this many hours; a result older than KEEP_HOURS is ignored. */
    public const REFRESH_HOURS = 20;
    public const KEEP_HOURS = 48;

    /** Whether $s is a DKIM selector name worth looking up (letters, digits, dots, dashes and underscores). */
    public static function selectorOk(string $s): bool
    {
        // dot-separated labels of 1 to 63 characters, each starting and ending with a letter or digit (or a leading _)
        return strlen($s) <= 100 && preg_match('/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?)*$/i', $s) === 1;
    }

    /** Selectors from a comma or space separated list, cleaned (invalid ones dropped), at most 10. */
    public static function selectors(?string $list): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtolower((string) $list)) ?: [] as $sel) {
            if ($sel !== '' && self::selectorOk($sel) && !in_array($sel, $out, true)) {
                $out[] = $sel;
            }
        }
        return array_slice($out, 0, 10);
    }

    /** Whether $d is a plain domain name worth checking (not Microsoft's own onmicrosoft.com address). */
    public static function domainOk(?string $d): bool
    {
        return is_string($d) && strlen($d) <= 253 && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $d) === 1
            && !preg_match('/\.onmicrosoft\.com$/i', $d);
    }

    /**
     * The TXT records at $name (each one joined, as DNS splits long ones), [] when there are none, null when the
     * lookup itself failed (the check is then unknown rather than failed). 2.7.4: through the public resolvers first
     * (DOH): the first answer with records wins; with $confirm, "none" from the first is asked of the second too
     * before it counts (so one resolver's bad moment isn't a false "No record"). If no resolver could answer, the
     * server's own resolver is asked. A resolver that can't be reached at all (no connection, a firewall) is left out
     * for the rest of the run, so a server without access to them isn't slowed down on every name.
     */
    public static function txt(string $name, bool $confirm = true): ?array
    {
        $insecure = Config::get('allow_insecure_integrations', false);
        $mock = $insecure ? Settings::get('dns_mock_url') : null;
        $doh = $insecure && Settings::get('dns_doh_urls') ? array_values(array_filter(array_map('trim', explode(',', (string) Settings::get('dns_doh_urls')))))
            : ($mock ? [] : self::DOH); // tests with only dns_mock_url read it alone, as before 2.7.4
        $none = false;
        foreach ($doh as $url) {
            if (isset(self::$down[$url])) {
                continue;
            }
            $r = self::doh($url, $name);
            if ($r) {
                return $r;
            }
            if ($r === []) {
                $none = true;
                if (!$confirm) {
                    return [];
                }
            }
        }
        if ($none) {
            return [];
        }
        return $mock ? self::mockTxt((string) $mock, $name) : self::localTxt($name);
    }

    /**
     * One resolver's answer for TXT at $name: the records ([] for NOERROR without TXT or NXDOMAIN), or null when it
     * couldn't answer (unreachable, SERVFAIL, refused). CNAMEs are followed by the resolver; only TXT answers count.
     */
    private static function doh(string $url, string $name): ?array
    {
        static $http = null;
        $http ??= new \Align\Http\HttpClient(6, 1);
        try {
            $r = $http->request('GET', $url . '?' . http_build_query(['name' => rtrim($name, '.') . '.', 'type' => 'TXT']), ['Accept' => 'application/dns-json'], null, true);
        } catch (\Align\Http\HttpException $e) {
            if (!$e->status || $e->status >= 500) {
                self::$down[$url] = true; // unreachable or out of service: not asked again this run
            }
            return null;
        } catch (\Throwable) {
            self::$down[$url] = true;
            return null;
        }
        $j = $r['json'] ?? null;
        if (!is_array($j) || !isset($j['Status']) || !in_array((int) $j['Status'], [0, 3], true)) {
            return null; // 2 SERVFAIL, 5 REFUSED, or not a DNS answer
        }
        $out = [];
        foreach ((array) ($j['Answer'] ?? []) as $a) {
            if (is_array($a) && (int) ($a['type'] ?? 0) === 16 && is_string($a['data'] ?? null)) {
                $out[] = self::txtData($a['data']);
            }
        }
        return $out;
    }

    /** A TXT record's presentation form ("part one" "part two", with \" and \DDD escapes) as one string. */
    public static function txtData(string $data): string
    {
        if (!preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/s', $data, $m)) {
            return trim($data);
        }
        return implode('', array_map(fn($p) => preg_replace_callback('/\\\\(\d{3}|.)/s', fn($e) => ctype_digit($e[1]) ? chr(min(255, (int) $e[1])) : $e[1], $p) ?? $p, $m[1]));
    }

    /**
     * The server's own resolver (fully qualified, so its search domains are never appended); null when it failed.
     * PHP gives an empty list both for "no records" and for some failures (no network, no resolver), so an empty
     * answer only counts once the resolver has shown it works at all (it can find a well-known domain's addresses):
     * otherwise a server without DNS would report every domain as missing its records.
     */
    private static function localTxt(string $name): ?array
    {
        static $works = null;
        $rows = @dns_get_record(rtrim($name, '.') . '.', DNS_TXT);
        if ($rows === false) {
            return null;
        }
        if ($rows === []) {
            $works ??= (bool) @dns_get_record('one.one.one.one.', DNS_A) || (bool) @dns_get_record('dns.google.', DNS_A);
            if (!$works) {
                return null;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = isset($r['entries']) && is_array($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? '');
        }
        return $out;
    }

    /** The test DNS (dns_mock_url): {"txt": [...]}, an error status for a failed lookup. */
    private static function mockTxt(string $mock, string $name): ?array
    {
        try {
            $r = (new \Align\Http\HttpClient(10, 1))->request('GET', rtrim($mock, '/') . '?name=' . rawurlencode($name), ['Accept' => 'application/json'], null, true);
        } catch (\Throwable) {
            return null;
        }
        return array_values(array_filter((array) ($r['json']['txt'] ?? []), 'is_string'));
    }

    /**
     * The three checks for $domain ($provider: google, m365 or null; $selectors: DKIM selectors set for the domain):
     * ['checks' => [key => [status, detail]], 'domain', 'provider', 'at'].
     */
    public static function check(string $domain, ?string $provider, array $selectors = []): array
    {
        $checks = [];
        $set = function (string $k, ?bool $pass, string $detail) use (&$checks) {
            $checks[$k] = ['status' => $pass === null ? 'unknown' : ($pass ? 'pass' : 'fail'), 'detail' => mb_substr($detail, 0, 300)];
        };
        $short = fn(string $s) => mb_strimwidth(preg_replace('/[\x00-\x1F\x7F]/', ' ', $s) ?? '', 0, 120, '…');

        // SPF: exactly one v=spf1 record, ending in a hard or soft fail
        $txt = self::txt($domain);
        if ($txt === null) {
            $set('email_spf', null, "Couldn't look up DNS for $domain.");
        } else {
            $spf = array_values(array_filter($txt, fn($t) => preg_match('/^v=spf1(\s|$)/i', trim($t)) === 1));
            if (!$spf) {
                $set('email_spf', false, "No SPF record on $domain.");
            } elseif (count($spf) > 1) {
                $set('email_spf', false, "$domain has " . count($spf) . ' SPF records: only one is allowed, so receivers ignore them.');
            } elseif (preg_match('/[-~]all\s*$/i', trim($spf[0]))) {
                $set('email_spf', true, 'SPF: ' . $short(trim($spf[0])));
            } elseif (preg_match('/\sredirect=([a-z0-9._-]+)/i', $spf[0], $rm)) {
                // Another domain's policy applies (not followed here, to keep to a few lookups)
                $set('email_spf', true, 'SPF redirects to ' . $short($rm[1]) . ' (that domain\'s policy applies): ' . $short(trim($spf[0])));
            } else {
                $set('email_spf', false, 'SPF doesn\'t end in -all or ~all, so it doesn\'t stop others sending as ' . $domain . ': ' . $short(trim($spf[0])));
            }
        }

        // DMARC: a policy at _dmarc, quarantine or reject (none only reports)
        $txt = self::txt('_dmarc.' . $domain);
        if ($txt === null) {
            $set('email_dmarc', null, "Couldn't look up DNS for _dmarc.$domain.");
        } else {
            $rec = array_values(array_filter($txt, fn($t) => preg_match('/^v=DMARC1\s*;/i', trim($t)) === 1));
            $p = $rec && preg_match('/(?:^|;)\s*p\s*=\s*(none|quarantine|reject)\b/i', $rec[0], $m) ? strtolower($m[1]) : null;
            if (!$rec) {
                $set('email_dmarc', false, "No DMARC record on $domain.");
            } elseif (count($rec) > 1) {
                $set('email_dmarc', false, "$domain has " . count($rec) . ' DMARC records: only one is allowed, so receivers ignore them.');
            } elseif ($p === null) {
                $set('email_dmarc', false, 'The DMARC record has no valid policy (p=): ' . $short(trim($rec[0])));
            } else {
                $set('email_dmarc', $p !== 'none', "DMARC policy p=$p" . ($p === 'none' ? ' (monitoring only: nothing is quarantined or rejected)' : ''));
            }
        }

        // DKIM: a public key at a selector: the mail service's and the domain's own first (asked of both resolvers),
        // then the common ones (one resolver each, as there are many)
        $found = null;
        $failed = false;
        $first = array_values(array_unique(array_merge(self::SELECTORS[$provider] ?? [], array_filter($selectors, [self::class, 'selectorOk']))));
        $tried = array_values(array_unique(array_merge($first, self::COMMON_SELECTORS)));
        foreach ($tried as $sel) {
            $txt = self::txt("$sel._domainkey.$domain", in_array($sel, $first, true));
            if ($txt === null) {
                $failed = true;
                continue;
            }
            foreach ($txt as $t) {
                if (preg_match('/(?:^|;)\s*p\s*=\s*[A-Za-z0-9+\/=]{20,}/', $t)) {
                    $found = $sel;
                    break 2;
                }
            }
        }
        if ($found !== null) {
            $set('email_dkim', true, "DKIM key published (selector $found)");
        } elseif ($failed) {
            $set('email_dkim', null, "Couldn't look up DKIM for $domain.");
        } else {
            $set('email_dkim', false, "No DKIM key found for $domain at " . ($first ? implode(', ', $first) . ' or ' : '') . count(self::COMMON_SELECTORS) . ' common selectors'
                . ($provider === 'google' ? ' (turn on DKIM in the Admin console → Apps → Gmail → Authenticate email)' : ($provider === 'm365' ? ' (turn on DKIM in Microsoft Defender → Email authentication)' : ''))
                . '. If it signs with another selector, add it to the domain.');
        }
        return ['checks' => $checks, 'domain' => $domain, 'provider' => $provider, 'at' => date('Y-m-d H:i:s')];
    }

    /**
     * The domains connected clients' Microsoft 365 and Google Workspace bring in: Microsoft 365's default domain
     * (skipped when it's an onmicrosoft.com address) and Google Workspace's primary domain; Google's wins when both are
     * the same domain. [client id => [domain => provider]]. $clientId: one client only (archived ones included).
     */
    public static function domains(?int $clientId = null): array
    {
        $out = [];
        $where = $clientId === null ? 'c.is_archived = 0' : 'c.id = ' . (int) $clientId;
        foreach (DB::all("SELECT m.client_id, m.tenant_domain FROM client_m365 m JOIN clients c ON c.id = m.client_id WHERE m.status = 'connected' AND $where") as $r) {
            if (self::domainOk($r['tenant_domain'])) {
                $out[(int) $r['client_id']][strtolower($r['tenant_domain'])] = 'm365';
            }
        }
        foreach (DB::all("SELECT g.client_id, g.domain FROM client_gws g JOIN clients c ON c.id = g.client_id WHERE g.status = 'connected' AND $where") as $r) {
            if (self::domainOk($r['domain'])) {
                $out[(int) $r['client_id']][strtolower($r['domain'])] = 'google';
            }
        }
        return $out;
    }

    /**
     * 2.7.4 Every domain to check: [client id => [domain => ['provider' => google|m365|null, 'selectors' => [...],
     * 'source' => 'm365'|'google'|'manual']]], from the connections (unless skipped) and the domains added by hand.
     * $clientId: one client only (archived ones included, for its own page).
     */
    public static function targets(?int $clientId = null): array
    {
        $out = [];
        $conn = self::domains($clientId);
        $rows = DB::all('SELECT d.client_id, d.domain, d.dkim_selectors, d.skip, d.origin FROM client_email_domains d JOIN clients c ON c.id = d.client_id WHERE '
            . ($clientId === null ? 'c.is_archived = 0' : 'd.client_id = ?') . ' ORDER BY d.created_at, d.domain', $clientId === null ? [] : [$clientId]);
        $manual = [];
        foreach ($rows as $r) {
            $manual[(int) $r['client_id']][strtolower($r['domain'])] = $r;
        }
        foreach ($conn as $cid => $list) {
            foreach ($list as $domain => $provider) {
                $m = $manual[$cid][$domain] ?? null;
                if (!$m || !(int) $m['skip']) {
                    $out[$cid][$domain] = ['provider' => $provider, 'selectors' => self::selectors($m['dkim_selectors'] ?? null), 'source' => $provider];
                }
            }
        }
        foreach ($manual as $cid => $list) {
            foreach ($list as $domain => $m) {
                // a connection's row (its selectors, or left out) counts only while the connection brings that domain in
                if (!(int) $m['skip'] && $m['origin'] === 'manual' && !isset($out[$cid][$domain]) && !isset($conn[$cid][$domain]) && self::domainOk($domain)) {
                    $out[$cid][$domain] = ['provider' => null, 'selectors' => self::selectors($m['dkim_selectors']), 'source' => 'manual'];
                }
            }
        }
        return $out;
    }

    /**
     * Checks every domain whose result is missing or older than REFRESH_HOURS ($force: all of them), and drops results
     * for domains no longer checked. Returns a line for the sync log.
     */
    public static function refreshDue(bool $force = false): string
    {
        $targets = self::targets();
        $have = [];
        foreach (DB::all('SELECT client_id, domain, checked_at FROM client_email_auth') as $r) {
            $have[(int) $r['client_id'] . ' ' . $r['domain']] = $r;
        }
        $n = 0;
        foreach ($targets as $cid => $list) {
            foreach ($list as $domain => $t) {
                $h = $have["$cid $domain"] ?? null;
                unset($have["$cid $domain"]);
                if (!$force && $h && $h['checked_at'] && strtotime($h['checked_at']) > time() - self::REFRESH_HOURS * 3600) {
                    continue;
                }
                self::refresh($cid, $domain, $t['provider'], $t['selectors']);
                $n++;
            }
        }
        foreach ($have as $h) {
            DB::run('DELETE FROM client_email_auth WHERE client_id = ? AND domain = ?', [$h['client_id'], $h['domain']]);
        }
        // A connection's selectors or "left out" for a domain it no longer brings in: forgotten
        $conn = self::domains();
        foreach (DB::all("SELECT d.client_id, d.domain FROM client_email_domains d JOIN clients c ON c.id = d.client_id WHERE d.origin = 'connection' AND c.is_archived = 0") as $r) {
            if (!isset($conn[(int) $r['client_id']][$r['domain']])) {
                DB::run('DELETE FROM client_email_domains WHERE client_id = ? AND domain = ?', [$r['client_id'], $r['domain']]);
            }
        }
        return $n ? "$n domain" . ($n === 1 ? '' : 's') . ' checked' : 'nothing due';
    }

    /**
     * Forgets the client's results for domains it no longer checks (called on disconnecting Google Workspace or
     * Microsoft 365, and when a domain is removed or skipped; the hourly sync would drop them too), so a stale result
     * doesn't count in the meantime.
     */
    public static function forgetUnconnected(int $clientId): void
    {
        $keep = array_keys(self::targets($clientId)[$clientId] ?? []);
        DB::run('DELETE FROM client_email_auth WHERE client_id = ?' . ($keep ? ' AND domain NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')' : ''), [$clientId, ...$keep]);
    }

    /** Checks every domain of one client now (Check now); returns their results by domain. */
    public static function refreshClient(int $clientId): array
    {
        $out = [];
        foreach (self::targets($clientId)[$clientId] ?? [] as $domain => $t) {
            $out[$domain] = self::refresh($clientId, $domain, $t['provider'], $t['selectors']);
        }
        self::forgetUnconnected($clientId);
        return $out;
    }

    /** Checks one client domain now and stores the result. */
    public static function refresh(int $clientId, string $domain, ?string $provider, array $selectors = []): array
    {
        $r = self::check($domain, $provider, $selectors);
        DB::run('INSERT INTO client_email_auth (client_id, domain, provider, result_json, checked_at) VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE provider = VALUES(provider), result_json = VALUES(result_json), checked_at = VALUES(checked_at)',
            [$clientId, $domain, $provider, json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)]);
        return $r;
    }

    /**
     * The client's stored results, or null when none was checked in the last KEEP_HOURS: ['checks' => the worst of its
     * domains for each check (any fail fails, else any unknown is unknown), 'domain' => its domains joined, 'domains'
     * => [domain => that domain's result], 'at' => the oldest check]. One domain: its own result, as before 2.7.4.
     */
    public static function stored(int $clientId): ?array
    {
        $per = [];
        $fresh = false;
        foreach (DB::all('SELECT domain, result_json FROM client_email_auth WHERE client_id = ? ORDER BY domain', [$clientId]) as $row) {
            $j = json_decode((string) $row['result_json'], true);
            $at = is_array($j) && is_string($j['at'] ?? null) ? strtotime($j['at']) : false;
            if (!is_array($j) || !is_array($j['checks'] ?? null) || $at === false) {
                continue;
            }
            if ($at < time() - self::KEEP_HOURS * 3600) {
                // too old to judge by, but not left out either (it could be the domain that fails): unknown
                $j['checks'] = array_map(fn() => ['status' => 'unknown', 'detail' => 'Not checked in the last two days.'], self::CHECKS);
            } else {
                $fresh = true;
            }
            $per[(string) $row['domain']] = $j;
        }
        if (!$fresh) {
            return null;
        }
        if (count($per) === 1) {
            $one = reset($per);
            return $one + ['domains' => $per];
        }
        $checks = [];
        foreach (array_keys(self::CHECKS) as $k) {
            $st = array_map(fn($j) => (string) ($j['checks'][$k]['status'] ?? 'unknown'), $per);
            $worst = in_array('fail', $st, true) ? 'fail' : (in_array('unknown', $st, true) ? 'unknown' : 'pass');
            $show = array_filter($per, fn($j) => ($j['checks'][$k]['status'] ?? 'unknown') === $worst);
            $checks[$k] = ['status' => $worst, 'detail' => mb_substr($worst === 'pass' ? 'All ' . count($per) . ' domains pass (' . implode(', ', array_keys($per)) . ').'
                : implode(' ', array_map(fn($d, $j) => "$d: " . ($j['checks'][$k]['detail'] ?? ''), array_keys($show), $show)), 0, 600)];
        }
        return ['checks' => $checks, 'domain' => implode(', ', array_keys($per)), 'provider' => null, 'domains' => $per,
            'at' => min(array_map(fn($j) => (string) $j['at'], $per))];
    }

    /**
     * A domain to suggest for a client without any (2.7.4): its website's, else its main contact's email domain,
     * when that's a plain domain and not a webmail address.
     */
    public static function suggest(array $client): ?string
    {
        $free = '/^(gmail|googlemail|yahoo|ymail|outlook|hotmail|live|msn|aol|icloud|me|mac|proton|protonmail|gmx|zoho|yandex|comcast|att|sbcglobal|verizon)\./i';
        foreach ([parse_url((string) (preg_match('#^https?://#i', (string) ($client['website'] ?? '')) ? $client['website'] : 'http://' . ($client['website'] ?? '')), PHP_URL_HOST),
                     substr(strrchr((string) ($client['contact_email'] ?? ''), '@') ?: '', 1)] as $d) {
            $d = strtolower(preg_replace('/^www\./i', '', (string) $d));
            if (self::domainOk($d) && !preg_match($free, $d)) {
                return $d;
            }
        }
        return null;
    }

    /** Compliance-style indicators for every check (as M365\Security::indicators), unknown without a result. */
    public static function indicators(?array $stored): array
    {
        $out = [];
        foreach (self::CHECKS as $k => $label) {
            $c = $stored['checks'][$k] ?? null;
            $st = is_array($c) ? (string) ($c['status'] ?? 'unknown') : 'unknown';
            $out[$k] = ['label' => $label, 'ok' => $st === 'pass', 'unknown' => $st === 'unknown',
                'text' => is_array($c) ? (string) ($c['detail'] ?? '') : 'Not checked: add the client\'s email domain (Connectors page), or connect its Microsoft 365 or Google Workspace.',
                'suggest' => $st === 'pass' ? 'met' : ($st === 'fail' ? 'not_met' : null)];
        }
        return $out;
    }
}
