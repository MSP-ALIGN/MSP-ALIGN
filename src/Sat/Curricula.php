<?php
declare(strict_types=1);

namespace Align\Sat;

use Align\Config;
use Align\DB;
use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Providers\ClientLinks;
use Align\Settings;

/**
 * 2.7.2 Huntress SAT results from the Curricula API (mycurricula.com/api/v1, JSON:API), instead of uploads: each
 * client's Curricula account (matched through its Huntress organization, else by name, fixed on Client mapping),
 * its training assignments with how many learners completed them, its phishing campaigns with their totals, and its
 * account summary reports. Read once a day (REFRESH_HOURS) on the hourly sync, and on Test / Sync now.
 *
 * Signing in: an OAuth application registered with Curricula (client ID and secret, Client Credentials grant, which
 * Curricula's Authentication page documents), asking only for read scopes (SCOPES). The token lasts an hour and is
 * kept for this process only. Its access is that of the Curricula user who owns the application, so registered under
 * the MSP's partner admin it sees every client.
 *
 * What's kept: per client, one 'training' row and one 'phishing' row per campaign (sent, unique clicks, reported,
 * compromised), as sat_results with source 'api', replaced on every read. Training counts the assignments of the
 * last MONTHS months that are due (completed, or past their end date; the running ones only while none is due, so a
 * newly launched assignment doesn't fail everyone on its first day): its learners are the active learners enrolled
 * in them (or, if the application can't read learners, those with activity), done when they completed every one of
 * theirs. Learner ids are used in memory to count people once and never stored; the learner list carries names and
 * emails, which are dropped as read. Summary reports keep their dates only: their PDF links are temporary, so the
 * card's link asks Curricula for a fresh one (reportUrl()).
 *
 * Security assumptions: the client secret is a secret (encrypted, never shown again); the client ID is stored as a
 * secret too. Requests go to Curricula's fixed host over HTTPS, GET only after the token request; the test override
 * (curricula_base) applies only with allow_insecure_integrations. Everything returned is remote data: ids checked,
 * text cleaned and cut, counts clamped, the PDF link kept only when https.
 */
final class Curricula
{
    /** Provider key in client_links and sat_accounts. */
    public const PROVIDER = 'curricula';
    /** Read scopes only. */
    public const SCOPES = 'account:read assignments:read assignments:learner-activity learners:read phishing-campaigns:read';
    /** Read again after this many hours (results change slowly; each account costs several requests). */
    public const REFRESH_HOURS = 20;
    /** Assignments and campaigns that started within this many months are read. */
    public const MONTHS = 13;
    /** Largest page Align asks for. */
    private const PAGE = 100;

    /** Token for this process: [token, expires]. */
    private static ?array $token = null;

    /** Whether the client ID and secret are saved. */
    public static function configured(): bool
    {
        return Settings::hasSecret('curricula_client_id') && Settings::hasSecret('curricula_client_secret');
    }

    /** Curricula's address (the test override only with allow_insecure_integrations). */
    private static function base(): string
    {
        $o = Config::get('allow_insecure_integrations', false) ? Settings::get('curricula_base') : null;
        return rtrim((string) ($o ?: 'https://mycurricula.com'), '/');
    }

    /** An access token (Client Credentials grant), cached for this process until a few minutes before it expires. */
    private static function token(): string
    {
        if (self::$token && self::$token[1] > time() + 120) {
            return self::$token[0];
        }
        try {
            $r = (new HttpClient(30, 2))->request('POST', self::base() . '/oauth/token', ['Accept' => 'application/json'],
                ['grant_type' => 'client_credentials', 'client_id' => (string) Settings::secret('curricula_client_id'),
                    'client_secret' => (string) Settings::secret('curricula_client_secret'), 'scope' => self::SCOPES], true);
        } catch (HttpException $e) {
            throw new \RuntimeException(in_array($e->status, [400, 401], true)
                ? 'Curricula refused the client ID and secret (' . $e->status . '). Check them, and that the application may use the read scopes.'
                : $e->getMessage());
        }
        $t = $r['json']['access_token'] ?? null;
        if (!is_string($t) || $t === '') {
            throw new \RuntimeException('Curricula didn\'t return an access token.');
        }
        self::$token = [$t, time() + max(60, min(3600, (int) ($r['json']['expires_in'] ?? 3600)))];
        return $t;
    }

    /** One GET under /api/v1 ($path fixed by the caller, ids checked). Returns the JSON:API document; throws with the HTTP status as the code. */
    private static function get(string $path, array $query = []): array
    {
        try {
            $r = (new HttpClient(60, 4))->request('GET', self::base() . '/api/v1' . $path . ($query ? '?' . http_build_query($query) : ''),
                ['Authorization' => 'Bearer ' . self::token(), 'Accept' => 'application/vnd.api+json']);
        } catch (HttpException $e) {
            throw new \RuntimeException(match (true) {
                $e->status === 401 => 'Curricula refused the token (401).',
                $e->status === 403 => 'Curricula says the application isn\'t allowed to read that (403): it needs the scopes ' . self::SCOPES . '.',
                default => $e->getMessage(),
            }, (int) $e->status, $e); // the HTTP status as the code (readAccount() tolerates a 403 on the learner list)
        }
        return is_array($r['json'] ?? null) ? $r['json'] : throw new \RuntimeException('Curricula didn\'t answer with JSON.');
    }

    /** Every item of a list, page by page (meta.page.lastPage), up to $max pages. */
    private static function all(string $path, array $query = [], int $max = 50): array
    {
        $out = [];
        for ($p = 1; $p <= $max; $p++) {
            $r = self::get($path, $query + ['page' => ['number' => $p, 'size' => self::PAGE]]);
            array_push($out, ...array_values(array_filter((array) ($r['data'] ?? []), 'is_array')));
            $last = (int) ($r['meta']['page']['lastPage'] ?? 1);
            if ($p >= $last || !($r['data'] ?? [])) {
                return $out;
            }
        }
        throw new \RuntimeException("Curricula returned more of $path than Align reads at once.");
    }

    /** A Curricula id: letters and digits (hashed ids), or null. */
    private static function id(mixed $v): ?string
    {
        return is_scalar($v) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $v) ? (string) $v : null;
    }

    /** Remote text on one line, cut to $n, or null. */
    private static function text(mixed $v, int $n): ?string
    {
        $t = is_string($v) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v) ?? ''), 0, $n) : '';
        return $t === '' ? null : $t;
    }

    /** An RFC 3339 time as Y-m-d (local), or null. */
    private static function day(mixed $v): ?string
    {
        $t = is_string($v) && $v !== '' ? strtotime($v) : false;
        return $t === false ? null : date('Y-m-d', $t);
    }

    /** A count clamped to 0..10^7. */
    private static function n(mixed $v): int
    {
        return is_numeric($v) ? (int) max(0, min(10000000, (float) $v)) : 0;
    }

    /** The Test button: the token, then the accounts the application can see. */
    public static function test(): string
    {
        self::$token = null;
        $n = count(self::all('/accounts', [], 5));
        return "Connected to Curricula: $n account" . ($n === 1 ? '' : 's') . ' visible.';
    }

    /**
     * The sync step: accounts every run (matched to clients), and each linked client's results when due ($force: all
     * of them, Sync now). Returns a line for the sync log; throws when the accounts can't be read.
     */
    public static function run(bool $force = false): string
    {
        $now = date('Y-m-d H:i:s');
        $accounts = self::all('/accounts');
        $ids = [];
        foreach ($accounts as $a) {
            $id = self::id($a['id'] ?? null);
            $name = self::text($a['attributes']['name'] ?? null, 255);
            // sandbox and demo accounts aren't clients (and could share a real account's Huntress organization)
            if ($id === null || $name === null || in_array($a['attributes']['type'] ?? null, ['sandbox', 'demo'], true)) {
                continue;
            }
            $ids[] = $id;
            DB::run('INSERT INTO sat_accounts (provider, account_id, name, status, io_org_id, licenses) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), io_org_id = VALUES(io_org_id), licenses = VALUES(licenses)',
                [self::PROVIDER, $id, $name, self::text($a['attributes']['status'] ?? null, 20), self::id($a['attributes']['ioOrgId'] ?? null),
                    isset($a['attributes']['licenses']) ? self::n($a['attributes']['licenses']) : null]);
        }
        if (!$ids) {
            if (DB::value('SELECT 1 FROM sat_accounts LIMIT 1')) {
                throw new \RuntimeException('Curricula returned no accounts (Align has some). Nothing was changed.');
            }
            return 'no accounts';
        }
        DB::run('DELETE FROM sat_accounts WHERE provider = ? AND account_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [self::PROVIDER, ...$ids]);
        ClientLinks::prune(self::PROVIDER, $ids);
        $matched = self::matchByHuntress() + ClientLinks::autoMatch(self::PROVIDER, true);
        // Results that are no longer read go, so the client's uploads count again: clients no longer linked, archived,
        // or whose account isn't active; and reports of accounts that are gone
        DB::run("DELETE FROM sat_results WHERE source = 'api' AND client_id NOT IN (SELECT l.client_id FROM client_links l
            JOIN sat_accounts a ON a.provider = l.provider AND a.account_id = l.external_id JOIN clients c ON c.id = l.client_id
            WHERE l.provider = ? AND c.is_archived = 0 AND COALESCE(a.status, 'active') = 'active')", [self::PROVIDER]);
        DB::run('DELETE FROM sat_reports WHERE account_id NOT IN (SELECT account_id FROM sat_accounts WHERE provider = ?)', [self::PROVIDER]);

        // Results for linked, active accounts that are due
        $read = $failed = 0;
        $due = DB::all("SELECT a.account_id, a.synced_at, l.client_id FROM sat_accounts a JOIN client_links l ON l.provider = a.provider AND l.external_id = a.account_id
            JOIN clients c ON c.id = l.client_id WHERE a.provider = ? AND c.is_archived = 0 AND COALESCE(a.status, 'active') = 'active'", [self::PROVIDER]);
        foreach ($due as $d) {
            if (!$force && $d['synced_at'] && strtotime($d['synced_at']) > time() - self::REFRESH_HOURS * 3600) {
                continue;
            }
            try {
                self::readAccount((string) $d['account_id'], (int) $d['client_id'], $now);
                DB::run('UPDATE sat_accounts SET synced_at = ?, error = NULL WHERE provider = ? AND account_id = ?', [$now, self::PROVIDER, $d['account_id']]);
                $read++;
            } catch (\Throwable $e) {
                DB::run('UPDATE sat_accounts SET error = ? WHERE provider = ? AND account_id = ?', [mb_substr($e->getMessage(), 0, 500), self::PROVIDER, $d['account_id']]);
                $failed++;
            }
        }
        return count($ids) . ' account' . (count($ids) === 1 ? '' : 's') . ($matched ? ", $matched matched to clients" : '') . ", $read read"
            . ($failed ? ", $failed failed (see the client's training card)" : '');
    }

    /**
     * Links unlinked accounts to the client already linked to the same Huntress organization (Curricula's ioOrgId),
     * unless that client has a Curricula link or a "kept unlinked" decision. Each client gets one account per run (the
     * first by id), so two accounts naming the same organization don't take turns. Returns how many were linked.
     */
    private static function matchByHuntress(): int
    {
        $n = 0;
        $done = [];
        foreach (DB::all("SELECT a.account_id, hl.client_id FROM sat_accounts a
                JOIN client_links hl ON hl.provider = ? AND hl.external_id = a.io_org_id
                LEFT JOIN client_links cl ON cl.provider = a.provider AND cl.external_id = a.account_id
                LEFT JOIN client_links own ON own.provider = a.provider AND own.client_id = hl.client_id
                WHERE a.provider = ? AND a.io_org_id IS NOT NULL AND cl.client_id IS NULL AND own.client_id IS NULL ORDER BY a.account_id", [\Align\Huntress\Sync::PROVIDER, self::PROVIDER]) as $r) {
            if (isset($done[(int) $r['client_id']])) {
                continue;
            }
            ClientLinks::set((int) $r['client_id'], self::PROVIDER, (string) $r['account_id'], 'auto');
            $done[(int) $r['client_id']] = true;
            $n++;
        }
        return $n;
    }

    /**
     * Reads one account's results and replaces the client's API results: training (see the class comment), each
     * phishing campaign's totals (child campaigns of an "over time" campaign rather than the parent, so nothing counts
     * twice), and the summary reports' dates.
     */
    private static function readAccount(string $account, int $clientId, string $now): void
    {
        $sinceTs = strtotime('-' . self::MONTHS . ' months');
        $since = date(DATE_RFC3339, $sinceTs);
        // Training. Assignments of the period: started in it, ending in it, or still running (long-running ones such
        // as new-hire training may have started earlier); drafts and canceled ones left out
        $due = $running = [];
        foreach (self::all('/accounts/' . rawurlencode($account) . '/assignments', [], 20) as $as) {
            $aid = self::id($as['id'] ?? null);
            $at = (array) ($as['attributes'] ?? []);
            $st = (string) ($at['status'] ?? '');
            $start = is_string($at['startsAt'] ?? null) ? strtotime($at['startsAt']) : false;
            $end = is_string($at['endsAt'] ?? null) ? strtotime($at['endsAt']) : false;
            $live = in_array($st, ['launching', 'in-progress'], true);
            if ($aid === null || in_array($st, ['draft', 'staged', 'canceled'], true) || !($live || ($start && $start >= $sinceTs) || ($end && $end >= $sinceTs))) {
                continue;
            }
            // due: completed, or its end date has passed
            if ($st === 'completed' || ($end && $end < time())) {
                $due[$aid] = $start ? date('Y-m-d', $start) : null;
            } else {
                $running[$aid] = $start ? date('Y-m-d', $start) : null;
            }
        }
        $counted = $due ?: $running; // the running ones only while nothing is due yet
        $learners = []; // hashed learner id => done every counted assignment they're in (in memory only)
        $last = null;
        foreach ($counted as $aid => $start) {
            // who's enrolled: active learners in the assignment (names and emails in the reply are dropped here)
            $enrolled = null;
            try {
                $enrolled = [];
                foreach (self::all('/assignments/' . rawurlencode((string) $aid) . '/learners', [], 50) as $l) {
                    if (($lid = self::id($l['id'] ?? null)) !== null && ($l['attributes']['status'] ?? 'active') === 'active') {
                        $enrolled[$lid] = false;
                    }
                }
            } catch (\RuntimeException $e) {
                if ($e->getCode() !== 403) {
                    throw $e;
                }
                $enrolled = null; // no learners:read scope: count the learners with activity instead
            }
            // who completed it
            $done = [];
            foreach (self::all('/assignments/' . rawurlencode((string) $aid) . '/learner-activity', [], 50) as $act) {
                $lid = self::id($act['attributes']['learnerId'] ?? ($act['relationships']['learner']['data'][0]['id'] ?? null));
                if ($lid === null) {
                    continue;
                }
                $d = self::day($act['attributes']['completedAt'] ?? null);
                $done[$lid] = ($done[$lid] ?? false) || $d !== null;
                $last = $d && (!$last || $d > $last) ? $d : $last;
            }
            foreach ($enrolled === null ? $done : array_intersect_key($done, $enrolled) + $enrolled as $lid => $ok) {
                $learners[$lid] = ($learners[$lid] ?? true) && $ok;
            }
        }
        $starts = array_filter($counted);
        $first = $starts ? min($starts) : null;
        $assignments = count($counted);
        // Phishing: each campaign that has sent something
        $campaigns = [];
        foreach (self::all('/accounts/' . rawurlencode($account) . '/phishing-campaigns', ['filter' => ['startsAfter' => $since]], 20) as $c) {
            $at = (array) ($c['attributes'] ?? []);
            if (!empty($at['isParent']) || in_array($at['status'] ?? '', ['draft', 'scheduled', 'building', 'canceled'], true)) {
                continue;
            }
            $s = (array) ($at['attemptStats'] ?? []);
            $sent = self::n($s['sent'] ?? 0);
            if ($sent === 0) {
                continue;
            }
            $when = self::day($at['firstSentAt'] ?? null) ?? self::day($at['launchedAt'] ?? null) ?? self::day($at['campaignStartsAt'] ?? null);
            $campaigns[] = ['sent' => $sent, 'clicked' => min($sent, self::n($s['uniqueClicks'] ?? 0)), 'reported' => min($sent, self::n($s['reported'] ?? 0)),
                'compromised' => min($sent, self::n($s['compromised'] ?? 0)), 'from' => $when, 'to' => self::day($at['campaignEndsAt'] ?? null) ?? $when,
                'title' => self::text($at['title'] ?? null, 200)];
        }
        // Summary reports: dates only (their PDF links are temporary; see reportUrl())
        $reports = self::all('/accounts/' . rawurlencode($account) . '/account-summary-reports', ['sort' => '-endDate'], 2);

        DB::transaction(function () use ($clientId, $account, $learners, $assignments, $first, $last, $campaigns, $reports, $now) {
            DB::run("DELETE FROM sat_results WHERE client_id = ? AND source = 'api'", [$clientId]);
            if ($learners) {
                DB::insert('sat_results', ['client_id' => $clientId, 'kind' => 'training', 'source' => 'api', 'report' => 'Curricula: training',
                    'learners' => count($learners), 'completed' => count(array_filter($learners)), 'assignments' => $assignments,
                    'covers_from' => $first, 'covers_to' => $last ?? $first ?? date('Y-m-d'), 'uploaded_at' => $now]);
            }
            foreach ($campaigns as $c) {
                DB::insert('sat_results', ['client_id' => $clientId, 'kind' => 'phishing', 'source' => 'api', 'report' => 'Curricula: ' . ($c['title'] ?? 'phishing campaign'),
                    'sent' => $c['sent'], 'clicked' => $c['clicked'], 'reported' => $c['reported'], 'compromised' => $c['compromised'], 'campaigns' => 1,
                    'covers_from' => $c['from'], 'covers_to' => $c['from'] ?? $c['to'], 'uploaded_at' => $now]);
            }
            DB::run('DELETE FROM sat_reports WHERE account_id = ?', [$account]);
            foreach (array_slice($reports, 0, 12) as $r) {
                $id = self::id($r['id'] ?? null);
                if ($id !== null) {
                    DB::run('REPLACE INTO sat_reports (report_id, account_id, start_date, end_date, has_pdf, generated_at, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$id, $account, self::day($r['attributes']['startDate'] ?? null), self::day($r['attributes']['endDate'] ?? null), (int) !empty($r['attributes']['pdf']),
                            ($t = strtotime((string) ($r['attributes']['generatedAt'] ?? ''))) ? date('Y-m-d H:i:s', $t) : null, $now]);
                }
            }
        });
    }

    /**
     * Reads one client's account now (the Refresh button on its training card). Returns false when the client has no
     * linked account; throws when Curricula can't be read, after saving the error on the account.
     */
    public static function refresh(int $clientId): bool
    {
        $a = self::account($clientId);
        if ($a === null) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        try {
            self::readAccount((string) $a['account_id'], $clientId, $now);
        } catch (\Throwable $e) {
            DB::run('UPDATE sat_accounts SET error = ? WHERE provider = ? AND account_id = ?', [mb_substr($e->getMessage(), 0, 500), self::PROVIDER, $a['account_id']]);
            throw $e;
        }
        DB::run('UPDATE sat_accounts SET synced_at = ?, error = NULL WHERE provider = ? AND account_id = ?', [$now, self::PROVIDER, $a['account_id']]);
        return true;
    }

    /** The client's Curricula account row (with its sync error, if any), or null when not linked or not set up. */
    public static function account(int $clientId): ?array
    {
        if (!self::configured()) {
            return null;
        }
        $id = ClientLinks::externalId($clientId, self::PROVIDER);
        return $id === null ? null : DB::one('SELECT * FROM sat_accounts WHERE provider = ? AND account_id = ?', [self::PROVIDER, $id]);
    }

    /**
     * A fresh link to one of the client's summary report PDFs (the link Curricula gives is temporary), or null when the
     * report isn't the client's or has no PDF. Only an https link is returned. Throws when Curricula can't be read.
     */
    public static function reportUrl(int $clientId, string $reportId): ?string
    {
        $a = self::account($clientId);
        if ($a === null || self::id($reportId) === null
            || !DB::value('SELECT 1 FROM sat_reports WHERE report_id = ? AND account_id = ?', [$reportId, $a['account_id']])) {
            return null;
        }
        $r = self::get('/account-summary-reports/' . rawurlencode($reportId));
        $data = isset($r['data']['id']) ? $r['data'] : ($r['data'][0] ?? []);
        $pdf = $data['attributes']['pdf'] ?? null;
        return is_string($pdf) && strlen($pdf) <= 4000 && preg_match('#^https://[^\s"<>\\\\]+$#', $pdf) ? $pdf : null;
    }

    /** The account's summary reports, newest first. */
    public static function reports(string $account): array
    {
        return DB::all('SELECT * FROM sat_reports WHERE account_id = ? ORDER BY end_date DESC LIMIT 6', [$account]);
    }
}
