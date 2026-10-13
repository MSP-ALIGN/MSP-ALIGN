<?php
declare(strict_types=1);

namespace Align\Compliance;

use Align\DB;
use Align\Health\SecurityChecks;

/**
 * 2.18.0 Evidence checks: the Phase 4 gate for the automatic security checks (Huntress, Microsoft 365, Google
 * Workspace, email authentication, security awareness training). A tech answers each check for a client from the
 * client's own systems without seeing Align's result ("blind"); the answer is stored with the result Align had at
 * that moment (evidence_checks), and the report compares the two per check and per system. A check whose answers
 * agree on enough clients can be trusted as compliance evidence; one that disagrees shows what to fix.
 *
 * Security assumptions: callers check the role (techs) and that the client exists. Answers are fixed values;
 * notes are plain text (escaped where shown) and never leave in the anonymized export, which also replaces the
 * client's and its tenants' names, its device and agent names, domains, email addresses, web addresses, IDs and IP
 * addresses in Align's result text (redact()), and leaves the text out when Align had no result.
 */
final class Evidence
{
    /** Check key prefix => system, in the order the roadmap gates them. */
    public const SYSTEMS = ['huntress_' => 'Huntress', 'm365_' => 'Microsoft 365', 'gws_' => 'Google Workspace', 'email_' => 'Email authentication', 'sat_' => 'Security awareness training'];
    /** A tech's answers: value => label. */
    public const ANSWERS = ['pass' => 'Yes, true', 'fail' => 'No, not true', 'unsure' => 'Can\'t tell'];
    /** How many clients a system's checks need answers from (with a result from Align) before its gate can pass. */
    public const GATE_CLIENTS = 2;

    /** The system a check belongs to ('Other' for a key with no known prefix). */
    public static function system(string $key): string
    {
        foreach (self::SYSTEMS as $p => $name) {
            if (str_starts_with($key, $p)) {
                return $name;
            }
        }
        return 'Other';
    }

    /**
     * Align's current result of every security check for a client: [key => ['label', 'system', 'status' (pass|fail|
     * unknown), 'detail']], in SYSTEMS order. $indicators: SecurityChecks::indicators() when the caller has them.
     */
    public static function current(int $clientId, ?array $indicators = null): array
    {
        $ind = $indicators ?? SecurityChecks::indicators($clientId);
        $out = [];
        foreach (array_keys(self::SYSTEMS) as $p) {
            foreach (SecurityChecks::labels() as $k => $label) {
                if (!str_starts_with($k, $p)) {
                    continue;
                }
                $i = $ind[$k] ?? null;
                $out[$k] = ['label' => $label, 'system' => self::system($k),
                    'status' => !$i || $i['unknown'] ? 'unknown' : ($i['ok'] ? 'pass' : 'fail'), 'detail' => (string) ($i['text'] ?? '')];
            }
        }
        return $out;
    }

    /**
     * Where each check is used for this client: [key => ['Framework: ref title', 'Alignment: title', ...]], from the
     * client's assigned frameworks and the active alignment standards.
     */
    public static function usedBy(int $clientId): array
    {
        $out = [];
        foreach (DB::all('SELECT c.auto_check, f.name, c.ref, c.title FROM compliance_controls c JOIN client_frameworks cf ON cf.framework_id = c.framework_id AND cf.client_id = ?
                JOIN compliance_frameworks f ON f.id = c.framework_id WHERE c.auto_check IS NOT NULL ORDER BY f.name, c.sort, c.id', [$clientId]) as $r) {
            $out[$r['auto_check']][] = $r['name'] . ': ' . trim($r['ref'] . ' ' . $r['title']);
        }
        foreach (DB::all('SELECT auto_check, title FROM alignment_standards WHERE auto_check IS NOT NULL AND is_active = 1 ORDER BY title') as $r) {
            $out[$r['auto_check']][] = 'Alignment: ' . $r['title'];
        }
        return $out;
    }

    /**
     * Stores one round of answers for a client: $answers [key => pass|fail|unsure] (others and unknown keys are
     * skipped), $notes [key => text]. Each answer is kept with Align's result at this moment. Returns how many.
     */
    public static function save(int $clientId, array $answers, array $notes, ?int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $cur = self::current($clientId);
        $rows = [];
        foreach ($answers as $k => $a) {
            if (!is_string($k) || !isset($cur[$k]) || !is_string($a) || !isset(self::ANSWERS[$a])) {
                continue;
            }
            $note = is_string($notes[$k] ?? null) ? trim(mb_substr($notes[$k], 0, 1000)) : '';
            $rows[] = ['client_id' => $clientId, 'check_key' => $k, 'manual' => $a, 'auto_status' => $cur[$k]['status'],
                'auto_detail' => mb_substr($cur[$k]['detail'], 0, 500) ?: null, 'note' => $note !== '' ? $note : null, 'user_id' => $userId, 'created_at' => $now];
        }
        if ($rows) {
            DB::transaction(function () use ($rows) {
                foreach ($rows as $r) {
                    DB::insert('evidence_checks', $r);
                }
            });
        }
        return count($rows);
    }

    /**
     * The newest answer per client and check (active clients that aren't left out of planning; $clientId limits it to one client), with the
     * client's name and who answered: rows of evidence_checks + 'client_name', 'user_name', 'outcome' (see outcome()).
     */
    public static function latest(?int $clientId = null): array
    {
        $where = $clientId !== null ? 'AND e.client_id = ?' : '';
        $rows = DB::all("SELECT e.*, c.name AS client_name, u.name AS user_name FROM evidence_checks e
            JOIN (SELECT client_id, check_key, MAX(id) AS id FROM evidence_checks GROUP BY client_id, check_key) x ON x.id = e.id
            JOIN clients c ON c.id = e.client_id AND c.is_archived = 0 AND c.planning_excluded = 0
            LEFT JOIN users u ON u.id = e.user_id
            WHERE 1 = 1 $where ORDER BY c.name, e.check_key", $clientId !== null ? [$clientId] : []);
        foreach ($rows as &$r) {
            $r['outcome'] = self::outcome($r['manual'], $r['auto_status']);
        }
        return $rows;
    }

    /**
     * How an answer compares with Align's result: 'agree', 'disagree', 'unsure' (the tech couldn't tell) or
     * 'no_result' (Align had no result to compare).
     */
    public static function outcome(string $manual, string $auto): string
    {
        if ($manual === 'unsure') {
            return 'unsure';
        }
        if ($auto === 'unknown') {
            return 'no_result';
        }
        return $manual === $auto ? 'agree' : 'disagree';
    }

    /**
     * The gate per check and per system from latest() rows: [system => ['clients' (answered), 'compared' (clients with
     * at least one answer to compare), 'agree', 'disagree', 'unsure', 'no_result', 'checks' (every check of the
     * system, key => same counts plus 'compared' and 'state'), 'total', 'trusted' (checks), 'passed' (bool)]]. A check
     * is 'trusted' with answers to compare from at least GATE_CLIENTS clients and no disagreement, 'disagree' with any,
     * 'needs' with fewer clients, 'none' when no answer could be compared (only "can't tell" or no result from Align)
     * and 'unanswered' with no answers. A system passes only when every one of its checks is trusted.
     */
    public static function summary(array $rows): array
    {
        $blank = ['agree' => 0, 'disagree' => 0, 'unsure' => 0, 'no_result' => 0];
        $out = [];
        foreach (self::SYSTEMS as $name) {
            $out[$name] = ['clients' => [], 'compared' => [], 'checks' => []] + $blank;
        }
        foreach (array_keys(SecurityChecks::labels()) as $k) {
            if (self::system($k) !== 'Other') {
                $out[self::system($k)]['checks'][$k] = $blank + ['compared' => [], 'answered' => 0];
            }
        }
        foreach ($rows as $r) {
            $s = self::system($r['check_key']);
            $cid = (int) $r['client_id'];
            $out[$s] ??= ['clients' => [], 'compared' => [], 'checks' => []] + $blank;
            $out[$s][$r['outcome']]++;
            $out[$s]['clients'][$cid] = true;
            $out[$s]['checks'][$r['check_key']] ??= $blank + ['compared' => [], 'answered' => 0];
            $out[$s]['checks'][$r['check_key']][$r['outcome']]++;
            $out[$s]['checks'][$r['check_key']]['answered']++;
            if (in_array($r['outcome'], ['agree', 'disagree'], true)) {
                $out[$s]['compared'][$cid] = true;
                $out[$s]['checks'][$r['check_key']]['compared'][$cid] = true;
            }
        }
        foreach ($out as &$s) {
            $s['clients'] = count($s['clients']);
            $s['compared'] = count($s['compared']);
            $s['trusted'] = 0;
            foreach ($s['checks'] as &$c) {
                $c['compared'] = count($c['compared']);
                $c['state'] = !$c['answered'] ? 'unanswered' : (!$c['compared'] ? 'none'
                    : ($c['disagree'] ? 'disagree' : ($c['compared'] >= self::GATE_CLIENTS ? 'trusted' : 'needs')));
                $s['trusted'] += $c['state'] === 'trusted' ? 1 : 0;
            }
            unset($c);
            $s['total'] = count($s['checks']);
            $s['passed'] = $s['total'] > 0 && $s['trusted'] === $s['total'];
        }
        unset($s);
        return $out;
    }

    /** Provider domains that say nothing about the client and stay in the export's text (exactly these; a subdomain could name the client). */
    private const PROVIDER_DOMAINS = ['google.com', 'googlemail.com', 'outlook.com', 'protection.outlook.com', 'spf.protection.outlook.com', 'microsoft.com',
        'office365.com', 'office.com', 'onmicrosoft.com', 'huntress.io', 'knowbe4.com'];

    /**
     * Align's result text with what could identify the client taken out, for the anonymized export. In order: the
     * parts of a check's text that are always names (Huntress agents missing or offline, antivirus agents, an
     * incident's subject, DKIM selectors, SPF and DMARC records); then web and email addresses, IDs (GUIDs), IP
     * addresses and domains (provider domains such as outlook.com stay); then, as whole words only, the names in
     * names(); last, whatever is left of an address or a word cut short ("…"). Text that isn't valid UTF-8 is
     * cleaned first.
     */
    public static function redact(string $text, int $clientId, string $key = ''): string
    {
        $text = mb_scrub($text, 'UTF-8');
        // 1. Structure: lists of names in a check's own wording
        $text = match ($key) {
            'huntress_incidents' => preg_match('/^(\d+ open critical or high incidents?):/', $text, $m) ? $m[1] . ': [incident subject]' : $text,
            'huntress_agents' => preg_replace(['/(; missing on )[^;]*/', '/(; not checked in for \d+ days: ).*$/s'], ['$1[names]', '$1[names]'], $text) ?? '',
            'huntress_av' => preg_replace('/(?<=: |, )[^,:()]+(?= \()/', '[name]', $text) ?? '',
            'email_dkim' => preg_replace(['/\(selector [^)]*\)/', '/( at ).*?( or \d+ common selectors)/s'], ['(selector [selector])', '$1[selectors]$2'], $text) ?? '',
            'email_spf', 'email_dmarc' => preg_replace('/v=(?:spf1|DMARC1)\b(?:(?!\s\S+\.[a-z]{2,}: ).)*/is', '[record]', $text) ?? '',
            default => $text,
        };
        // 2. Addresses, IDs and domains (before names, so a name inside an address can't leave the rest of it behind)
        $text = preg_replace([
            '#\bhttps?://\S+#i',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            '/\b(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}\b|(?<![\w:])(?=[0-9a-f:]*\d)(?:[0-9a-f]{1,4}:){1,6}:(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4})*)?/i',
        ], ['[web address]', '[email]', '[ID]', '[IP address]', '[IP address]'], $text) ?? '';
        $text = preg_replace_callback('/\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}\b/i', function ($m) {
            return in_array(strtolower($m[0]), self::PROVIDER_DOMAINS, true) ? $m[0] : '[domain]';
        }, $text) ?? '';
        // 3. Names, as whole words (longest first, so a name inside another isn't half-replaced)
        foreach (array_chunk(self::names($clientId), 200) as $chunk) {
            $re = '/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn($n) => preg_quote($n, '/'), $chunk)) . ')(?![\p{L}\p{N}])/iu';
            $text = preg_replace($re, '[name]', $text) ?? '';
        }
        // 4. What's left of an address or a word cut short by a length limit
        return preg_replace(['/[^\s@\[\]]+@\S*/u', '/[^\s\[\]…]+…/u'], ['[email]', '[cut]…'], $text) ?? '';
    }

    /**
     * The names that could identify a client (3 characters or more, valid UTF-8, longest first; kept per request):
     * the client's, its devices', its Huntress organization's (name and key) and agents', its Microsoft 365 tenant's
     * name and domain, and its Google Workspace organization name, admin email and domain.
     */
    private static function names(int $clientId): array
    {
        static $cache = [];
        if (isset($cache[$clientId])) {
            return $cache[$clientId];
        }
        $names = [(string) DB::value('SELECT name FROM clients WHERE id = ?', [$clientId])];
        foreach (DB::all('SELECT display_name, system_name FROM devices WHERE client_id = ?', [$clientId]) as $d) {
            array_push($names, (string) $d['display_name'], (string) $d['system_name']);
        }
        $org = DB::value('SELECT external_id FROM client_links WHERE client_id = ? AND provider = ?', [$clientId, \Align\Huntress\Sync::PROVIDER]);
        if ($org !== null && $org !== false) {
            foreach (DB::all('SELECT name, org_key FROM huntress_orgs WHERE provider = ? AND org_id = ?', [\Align\Huntress\Sync::PROVIDER, (string) $org]) as $o) {
                array_push($names, (string) $o['name'], (string) $o['org_key']);
            }
            foreach (DB::all('SELECT hostname FROM huntress_agents WHERE org_id = ?', [(string) $org]) as $a) {
                $names[] = (string) $a['hostname'];
            }
        }
        foreach (DB::all('SELECT tenant_name, tenant_domain FROM client_m365 WHERE client_id = ?', [$clientId]) as $t) {
            array_push($names, (string) $t['tenant_name'], (string) $t['tenant_domain']);
        }
        foreach (DB::all('SELECT org_name, admin_email, domain FROM client_gws WHERE client_id = ?', [$clientId]) as $g) {
            array_push($names, (string) $g['org_name'], (string) $g['admin_email'], (string) $g['domain']);
        }
        $names = array_values(array_unique(array_filter(array_map('trim', $names), fn($n) => mb_check_encoding($n, 'UTF-8') && mb_strlen($n) >= 3)));
        usort($names, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return $cache[$clientId] = $names;
    }

    /**
     * latest() as rows for the anonymized export: clients become "Client A", "Client B"… in an order keyed to this
     * install (an HMAC of the id), so the letters say nothing about names or when a client was added; no notes, user
     * names or dates finer than the day. Align's text is redacted (redact()) and left out when Align had no result.
     */
    public static function exportRows(array $rows): array
    {
        $ids = array_values(array_unique(array_map(fn($r) => (int) $r['client_id'], $rows)));
        $key = \Align\Crypto::key();
        usort($ids, fn($a, $b) => strcmp(hash_hmac('sha256', 'evidence:' . $a, $key), hash_hmac('sha256', 'evidence:' . $b, $key)));
        $letters = [];
        foreach ($ids as $i => $cid) {
            $letters[$cid] = 'Client ' . self::letter($i);
        }
        $out = [];
        $labels = SecurityChecks::labels();
        foreach ($rows as $r) {
            $cid = (int) $r['client_id'];
            $detail = $r['auto_status'] === 'unknown' ? '' : self::redact((string) $r['auto_detail'], $cid, $r['check_key']);
            $out[] = [$letters[$cid], self::system($r['check_key']), $r['check_key'], $labels[$r['check_key']] ?? $r['check_key'], $r['auto_status'],
                $detail, $r['manual'], $r['outcome'], substr((string) $r['created_at'], 0, 10)];
        }
        return $out;
    }

    /** 0 => A, 25 => Z, 26 => AA… */
    private static function letter(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }
}
