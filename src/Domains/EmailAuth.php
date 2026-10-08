<?php
declare(strict_types=1);

namespace Align\Domains;

use Align\Config;
use Align\DB;
use Align\Settings;

/**
 * 2.6.3 Email authentication for a client's domain, read from public DNS once a day: SPF (one record, ending in -all
 * or ~all), DKIM (a key published for the mail service's selector: "google" for Google Workspace, "selector1" or
 * "selector2" for Microsoft 365) and DMARC (a policy of quarantine or reject). Works for any client whose Microsoft
 * 365 or Google Workspace is connected (that's where the domain comes from); the result counts in the health score's
 * Security area and suggests answers for the controls and standards linked to these checks.
 *
 * Security assumptions: only public DNS is read (TXT records). Domain names come from the client's connection
 * (Microsoft's or Google's answer), are looked up fully qualified, are checked against a strict host-name pattern before any lookup, and are never
 * taken from a request. DNS answers are remote text: only a few words of them are kept in the detail (cut), and
 * views escape them. The test override (dns_mock_url) applies only with allow_insecure_integrations.
 */
final class EmailAuth
{
    /** The checks: key => label (automatic checks in compliance and alignment, like the Microsoft 365 ones). */
    public const CHECKS = [
        'email_spf' => 'SPF record for the email domain (ending in -all or ~all)',
        'email_dkim' => 'DKIM signing set up for the email domain',
        'email_dmarc' => 'DMARC policy of quarantine or reject',
    ];

    /** DKIM selectors to look for, by where the mail is: google, m365, or anything else (all of them). */
    private const SELECTORS = ['google' => ['google'], 'm365' => ['selector1', 'selector2']];

    /** Read again after this many hours; a result older than KEEP_HOURS is ignored. */
    public const REFRESH_HOURS = 20;
    public const KEEP_HOURS = 48;

    /** Whether $d is a plain domain name worth checking (not Microsoft's own onmicrosoft.com address). */
    public static function domainOk(?string $d): bool
    {
        return is_string($d) && strlen($d) <= 253 && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $d) === 1
            && !preg_match('/\.onmicrosoft\.com$/i', $d);
    }

    /**
     * The TXT records at $name (each one joined, as DNS splits long ones), [] when there are none, null when the
     * lookup itself failed (the check is then unknown rather than failed).
     */
    public static function txt(string $name): ?array
    {
        $mock = Config::get('allow_insecure_integrations', false) ? Settings::get('dns_mock_url') : null;
        if ($mock) {
            try {
                $r = (new \Align\Http\HttpClient(10, 1))->request('GET', rtrim((string) $mock, '/') . '?name=' . rawurlencode($name), ['Accept' => 'application/json'], null, true);
            } catch (\Throwable) {
                return null;
            }
            return array_values(array_filter((array) ($r['json']['txt'] ?? []), 'is_string'));
        }
        // Fully qualified (trailing dot), so the resolver's search domains are never appended
        $rows = @dns_get_record(rtrim($name, '.') . '.', DNS_TXT);
        if ($rows === false) {
            return null;
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = isset($r['entries']) && is_array($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? '');
        }
        return $out;
    }

    /** The three checks for $domain ($provider: google, m365 or null): ['checks' => [key => [status, detail]], 'domain', 'at']. */
    public static function check(string $domain, ?string $provider): array
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

        // DKIM: a public key at one of the mail service's selectors
        $found = null;
        $failed = false;
        $tried = self::SELECTORS[$provider] ?? array_merge(...array_values(self::SELECTORS));
        foreach ($tried as $sel) {
            $txt = self::txt("$sel._domainkey.$domain");
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
            $set('email_dkim', false, 'No DKIM key at ' . implode(' or ', array_map(fn($s) => "$s._domainkey", $tried)) . " for $domain"
                . ($provider === 'google' ? ' (turn on DKIM in the Admin console → Apps → Gmail → Authenticate email; a custom selector prefix other than "google" isn\'t checked)' : ($provider === 'm365' ? ' (turn on DKIM in Microsoft Defender → Email authentication)' : '')) . '.');
        }
        return ['checks' => $checks, 'domain' => $domain, 'provider' => $provider, 'at' => date('Y-m-d H:i:s')];
    }

    /**
     * The domain to check for each client with a connection: Google Workspace's primary domain, else Microsoft 365's
     * default domain (skipped when it's an onmicrosoft.com address). [client id => [domain, provider]].
     */
    public static function domains(): array
    {
        $out = [];
        foreach (DB::all("SELECT m.client_id, m.tenant_domain FROM client_m365 m JOIN clients c ON c.id = m.client_id WHERE m.status = 'connected' AND c.is_archived = 0") as $r) {
            if (self::domainOk($r['tenant_domain'])) {
                $out[(int) $r['client_id']] = [strtolower($r['tenant_domain']), 'm365'];
            }
        }
        foreach (DB::all("SELECT g.client_id, g.domain FROM client_gws g JOIN clients c ON c.id = g.client_id WHERE g.status = 'connected' AND c.is_archived = 0") as $r) {
            if (self::domainOk($r['domain'])) {
                $out[(int) $r['client_id']] = [strtolower($r['domain']), 'google'];
            }
        }
        return $out;
    }

    /**
     * Checks every client whose result is missing, older than REFRESH_HOURS or for another domain ($force: all of
     * them), and drops results for clients no longer connected. Returns a line for the sync log.
     */
    public static function refreshDue(bool $force = false): string
    {
        $doms = self::domains();
        $have = [];
        foreach (DB::all('SELECT client_id, domain, checked_at FROM client_email_auth') as $r) {
            $have[(int) $r['client_id']] = $r;
        }
        $n = 0;
        foreach ($doms as $cid => [$domain, $provider]) {
            $h = $have[$cid] ?? null;
            if (!$force && $h && $h['domain'] === $domain && $h['checked_at'] && strtotime($h['checked_at']) > time() - self::REFRESH_HOURS * 3600) {
                continue;
            }
            self::refresh($cid, $domain, $provider);
            $n++;
        }
        $gone = array_diff(array_keys($have), array_keys($doms));
        if ($gone) {
            DB::run('DELETE FROM client_email_auth WHERE client_id IN (' . implode(',', array_map('intval', $gone)) . ')');
        }
        return $n ? "$n domain" . ($n === 1 ? '' : 's') . ' checked' : 'nothing due';
    }

    /**
     * Forgets the client's result when neither Google Workspace nor Microsoft 365 is connected any more (called on
     * disconnecting; the hourly sync would drop it too), so a stale result doesn't count in the meantime.
     */
    public static function forgetUnconnected(int $clientId): void
    {
        DB::run("DELETE FROM client_email_auth WHERE client_id = ? AND NOT EXISTS (SELECT 1 FROM client_m365 WHERE client_id = ? AND status = 'connected')
            AND NOT EXISTS (SELECT 1 FROM client_gws WHERE client_id = ? AND status = 'connected')", [$clientId, $clientId, $clientId]);
    }

    /** Checks one client's domain now and stores the result. */
    public static function refresh(int $clientId, string $domain, ?string $provider): array
    {
        $r = self::check($domain, $provider);
        DB::run('INSERT INTO client_email_auth (client_id, domain, provider, result_json, checked_at) VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE domain = VALUES(domain), provider = VALUES(provider), result_json = VALUES(result_json), checked_at = VALUES(checked_at)',
            [$clientId, $domain, $provider, json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)]);
        return $r;
    }

    /** The client's stored result, or null when never checked or checked more than KEEP_HOURS ago. */
    public static function stored(int $clientId): ?array
    {
        $j = json_decode((string) DB::value('SELECT result_json FROM client_email_auth WHERE client_id = ?', [$clientId]), true);
        if (!is_array($j) || !is_array($j['checks'] ?? null)) {
            return null;
        }
        $at = is_string($j['at'] ?? null) ? strtotime($j['at']) : false;
        return $at !== false && $at >= time() - self::KEEP_HOURS * 3600 ? $j : null;
    }

    /** Compliance-style indicators for every check (as M365\Security::indicators), unknown without a result. */
    public static function indicators(?array $stored): array
    {
        $out = [];
        foreach (self::CHECKS as $k => $label) {
            $c = $stored['checks'][$k] ?? null;
            $st = is_array($c) ? (string) ($c['status'] ?? 'unknown') : 'unknown';
            $out[$k] = ['label' => $label, 'ok' => $st === 'pass', 'unknown' => $st === 'unknown',
                'text' => is_array($c) ? (string) ($c['detail'] ?? '') : 'Not checked: email authentication is read for clients with Microsoft 365 or Google Workspace connected.',
                'suggest' => $st === 'pass' ? 'met' : ($st === 'fail' ? 'not_met' : null)];
        }
        return $out;
    }
}
