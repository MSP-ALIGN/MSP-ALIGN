<?php
declare(strict_types=1);

namespace Align\Sat;

use Align\Config;
use Align\Crypto;
use Align\DB;
use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * 2.14.0 KnowBe4 security awareness training results per client, read-only, from KnowBe4's Reporting API
 * (https://<region>.api.knowbe4.com/v1). KnowBe4's Reporting API token belongs to one customer console, so each client
 * has its own, entered on its training card (client_knowbe4, encrypted). The Reporting API comes with KnowBe4's
 * Platinum, Diamond, SAT Foundations and SAT Advanced subscriptions.
 *
 * Read once a day (REFRESH_HOURS) on the hourly sync, and on Refresh: the account (name, subscription, seats, risk
 * score), the training campaigns and enrollments (training completion), and the phishing security tests (sent,
 * clicked, reported, data entered). Stored as sat_results with source 'api' and provider 'knowbe4', replaced on every
 * read, and counted by the same two checks as Curricula and uploads. A client with KnowBe4 doesn't also read Curricula
 * (one training platform per client).
 *
 * Training counts the enrollments of the last MONTHS months in the campaigns that are due (ended, or with an enrollment
 * past due), every one of them, finished or not; the running campaigns only while none is due, so a newly launched
 * campaign doesn't fail everyone on its first day. Policy acknowledgements are left out. A learner is done when every
 * one of their counted enrollments is complete. User ids are
 * used in memory to count people once and never stored; names and emails in the replies are dropped as read.
 *
 * KnowBe4 allows 4 requests a second and 50 a minute (going over locks the token out for 5 minutes), and a daily
 * count, so requests are spaced GAP_MS apart and a 429 ends that client's read until the next sync.
 *
 * Security assumptions: the token is encrypted (Crypto) and only sent, in the Authorization header, to KnowBe4's host
 * for the region saved with it (a fixed list); the test override (kb4_base) applies only with
 * allow_insecure_integrations. Saving a token checks it with one read first. Everything returned is remote data:
 * ids checked, text cleaned and cut, counts clamped, dates must be real; a reply that isn't a list is an error, never
 * "no results".
 */
final class KnowBe4
{
    /** Provider name in sat_results. */
    public const PROVIDER = 'knowbe4';
    /** KnowBe4's regions: the start of the console's address (eu.knowbe4.com…; training.knowbe4.com is US). */
    public const REGIONS = ['us' => 'US (training.knowbe4.com)', 'eu' => 'EU (eu.knowbe4.com)', 'ca' => 'Canada (ca.knowbe4.com)',
        'uk' => 'UK (uk.knowbe4.com)', 'de' => 'Germany (de.knowbe4.com)'];
    /** Read again after this many hours. */
    public const REFRESH_HOURS = 20;
    /** Enrollments and phishing tests from this many months are read. */
    public const MONTHS = 13;
    /** Rows per page (KnowBe4's most). */
    private const PAGE = 500;
    /** Pages read from one list before giving up. */
    private const MAX_PAGES = 200;
    /** Pause between requests (KnowBe4 allows 50 a minute). */
    private const GAP_MS = 1300;

    /** When the last request was sent (microtime), to space them. */
    private static float $last = 0.0;

    /** Whether KnowBe4 is offered on the clients' training cards (Integrations → KnowBe4). */
    public static function enabled(): bool
    {
        return Settings::get('knowbe4_enabled', '0') === '1';
    }

    /** The client's KnowBe4 row without the token, or null when it has none. */
    public static function forClient(int $clientId): ?array
    {
        $r = DB::one('SELECT client_id, region, account_name, subscription, seats, risk_score, synced_at, error, added_at FROM client_knowbe4 WHERE client_id = ?', [$clientId]);
        return $r ?: null;
    }

    /** Whether the client has a KnowBe4 token saved. */
    public static function connected(int $clientId): bool
    {
        return (bool) DB::value('SELECT 1 FROM client_knowbe4 WHERE client_id = ?', [$clientId]);
    }

    /** KnowBe4's API address for a region (the test override only with allow_insecure_integrations). */
    private static function base(string $region): string
    {
        $o = Config::get('allow_insecure_integrations', false) ? Settings::get('kb4_base') : null;
        return rtrim((string) ($o ?: 'https://' . (isset(self::REGIONS[$region]) ? $region : 'us') . '.api.knowbe4.com'), '/');
    }

    /**
     * One GET; the decoded JSON or an explained error (the exception code is the HTTP status). Requests are spaced
     * GAP_MS apart; nothing is retried (a burst after a 429 locks the token out).
     */
    private static function get(string $region, string $token, string $path, array $query = [], int $timeout = 60): mixed
    {
        $wait = (int) ((self::$last + self::GAP_MS / 1000 - microtime(true)) * 1e6);
        if ($wait > 0) {
            usleep($wait);
        }
        self::$last = microtime(true);
        try {
            return (new HttpClient($timeout, 1))->request('GET', self::base($region) . '/v1' . $path . ($query ? '?' . http_build_query($query) : ''),
                ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'])['json'];
        } catch (HttpException $e) {
            throw new \RuntimeException(match (true) {
                $e->status === 401 => 'KnowBe4 refused the token (HTTP 401). Make a new Reporting API token in that client\'s KnowBe4 console (Account Settings → Account Integrations → API) and check the region.',
                $e->status === 403 => 'KnowBe4 refused ' . $path . ' (HTTP 403): the Reporting API needs a Platinum, Diamond or SAT Foundations/Advanced subscription.',
                $e->status === 404 => 'KnowBe4 has no ' . $path . ' in that region (HTTP 404). Check the region: it is the start of the console\'s address.',
                $e->status === 429 => 'KnowBe4\'s request limit for this account was reached; it is read again at the next sync.',
                default => 'KnowBe4 couldn\'t be read: ' . safe_error($e),
            }, $e->status, $e);
        }
    }

    /** Every row of a list, PAGE at a time, until a page comes back short. Throws when a page isn't a list or repeats the one before. */
    private static function all(string $region, string $token, string $path, array $query = []): array
    {
        $rows = [];
        $prev = null;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $j = self::get($region, $token, $path, $query + ['page' => $page, 'per_page' => self::PAGE]);
            if (!is_array($j) || !array_is_list($j)) {
                throw new \RuntimeException("KnowBe4 sent something other than a list for $path.");
            }
            $list = array_values(array_filter($j, 'is_array'));
            $first = $list ? md5((string) json_encode($list)) : null; // the whole page: one that repeats means page is ignored
            if ($first !== null && $first === $prev) {
                throw new \RuntimeException("KnowBe4 returned the same page of $path again; the read was stopped.");
            }
            $prev = $first;
            array_push($rows, ...$list);
            if (count($j) < self::PAGE) {
                return $rows;
            }
        }
        throw new \RuntimeException("KnowBe4 returned more than " . (self::MAX_PAGES * self::PAGE) . " rows for $path; the read was stopped.");
    }

    // ---- Value helpers ----

    /** A KnowBe4 id (a positive whole number, or letters, digits and dashes) as text, or null. */
    private static function id(mixed $v): ?string
    {
        if (is_int($v)) {
            return $v > 0 ? (string) $v : null;
        }
        return is_string($v) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $v) ? $v : null;
    }

    /** Text on one line without control characters, cut to $n; null when empty or not text. */
    private static function text(mixed $v, int $n): ?string
    {
        if (!is_scalar($v) || is_bool($v)) {
            return null;
        }
        $s = trim(mb_substr(preg_replace(['/[\t\r\n]/', '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'], [' ', ''], (string) $v) ?? '', 0, $n));
        return $s !== '' ? $s : null;
    }

    /** A date (Y-m-d, in this server's timezone for a time) from KnowBe4's ISO text, or null. */
    private static function day(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || (int) $m[1] < 2000 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        if (strlen($v) > 10) {
            try {
                return (new \DateTimeImmutable($v, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
            } catch (\Throwable) {
                return $m[0];
            }
        }
        return $m[0];
    }

    /** A count: a whole number from 0 to 10,000,000 (anything else is 0). */
    private static function n(mixed $v): int
    {
        return is_int($v) || (is_string($v) && ctype_digit($v)) ? max(0, min(10_000_000, (int) $v)) : 0;
    }

    // ---- Saving a client's token ----

    /**
     * Saves a client's region and Reporting API token after checking it with one read (the account). Returns the
     * account's name. Throws \InvalidArgumentException for a bad region or token shape and \RuntimeException when
     * KnowBe4 refuses it; nothing is saved then. The caller checks the admin role, CSRF and the client.
     */
    public static function save(int $clientId, string $region, string $token, ?int $userId): string
    {
        $token = trim($token);
        if (!isset(self::REGIONS[$region])) {
            throw new \InvalidArgumentException('Choose the KnowBe4 region.');
        }
        if (!preg_match('/^[A-Za-z0-9._~+\/=-]{20,4096}$/', $token)) {
            throw new \InvalidArgumentException('That doesn\'t look like a KnowBe4 Reporting API token. Copy it again from the client\'s KnowBe4 console.');
        }
        $a = self::get($region, $token, '/account', [], 20);
        if (!is_array($a) || array_is_list($a)) {
            throw new \RuntimeException('KnowBe4 sent something other than an account. Check the region.');
        }
        $name = self::text($a['name'] ?? null, 255) ?? 'KnowBe4 account';
        DB::run('INSERT INTO client_knowbe4 (client_id, region, token_enc, account_name, subscription, seats, risk_score, synced_at, error, added_by, added_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, NOW()) ON DUPLICATE KEY UPDATE region = VALUES(region), token_enc = VALUES(token_enc),
            account_name = VALUES(account_name), subscription = VALUES(subscription), seats = VALUES(seats), risk_score = VALUES(risk_score),
            synced_at = NULL, error = NULL, added_by = VALUES(added_by), added_at = NOW()',
            [$clientId, $region, Crypto::encrypt($token), $name, self::text($a['subscription_level'] ?? null, 60), self::seats($a), self::risk($a), $userId]);
        // The client now reads KnowBe4: Curricula's results for it go now rather than at Curricula's next clean-up
        DB::run("DELETE FROM sat_results WHERE client_id = ? AND source = 'api' AND (provider = 'curricula' OR provider IS NULL)", [$clientId]);
        return $name;
    }

    /** Removes a client's token and the results read with it (its uploads, or Curricula, count again). */
    public static function remove(int $clientId): void
    {
        DB::transaction(function () use ($clientId) {
            DB::run('DELETE FROM client_knowbe4 WHERE client_id = ?', [$clientId]);
            DB::run("DELETE FROM sat_results WHERE client_id = ? AND source = 'api' AND provider = ?", [$clientId, self::PROVIDER]);
            // a Curricula account linked to the client is read again at the next sync instead of waiting a day
            DB::run("UPDATE sat_accounts a JOIN client_links l ON l.provider = a.provider AND l.external_id = a.account_id SET a.synced_at = NULL
                WHERE l.client_id = ? AND a.provider = 'curricula'", [$clientId]);
        });
    }

    /** The account's seat count, or null. */
    private static function seats(array $a): ?int
    {
        $s = $a['number_of_seats'] ?? $a['number_of_seat'] ?? null;
        return is_int($s) && $s >= 0 ? min($s, 10_000_000) : null;
    }

    /** The account's risk score (0 to 100), or null. */
    private static function risk(array $a): ?float
    {
        $r = $a['current_risk_score'] ?? null;
        return (is_int($r) || is_float($r)) && $r >= 0 && $r <= 100 ? round((float) $r, 2) : null;
    }

    // ---- Reading ----

    /**
     * The sync step: each connected client that is due ($force: all) and not archived. Returns a line for the sync log
     * ("3 read, 1 failed"); one client's failure is saved on its card and doesn't stop the others.
     */
    public static function run(bool $force = false): string
    {
        $read = $failed = $fresh = 0;
        // Archived clients aren't read, and their KnowBe4 results go (as Curricula's do); the token stays for a restore
        DB::run("DELETE s FROM sat_results s JOIN clients c ON c.id = s.client_id WHERE s.source = 'api' AND s.provider = ? AND c.is_archived = 1", [self::PROVIDER]);
        foreach (DB::all('SELECT k.client_id, k.synced_at FROM client_knowbe4 k JOIN clients c ON c.id = k.client_id WHERE c.is_archived = 0') as $k) {
            if (!$force && $k['synced_at'] && strtotime($k['synced_at']) > time() - self::REFRESH_HOURS * 3600) {
                $fresh++;
                continue;
            }
            try {
                self::refresh((int) $k['client_id']);
                $read++;
            } catch (\Throwable) {
                $failed++; // saved on the client's row by refresh()
            }
        }
        return "$read read" . ($fresh ? ", $fresh up to date" : '') . ($failed ? ", $failed failed (see the client's training card)" : '');
    }

    /** Reads one client now (the sync, and Refresh on its card). False when it has no token; throws after saving the error. */
    public static function refresh(int $clientId): bool
    {
        $k = DB::one('SELECT region, token_enc FROM client_knowbe4 WHERE client_id = ?', [$clientId]);
        if (!$k) {
            return false;
        }
        try {
            try {
                $token = (string) Crypto::decrypt((string) $k['token_enc']);
            } catch (\Throwable) {
                $token = '';
            }
            if ($token === '') {
                throw new \RuntimeException('The saved token can\'t be read on this server (the encryption key changed). Replace it on this card.');
            }
            self::read($clientId, (string) $k['region'], $token);
        } catch (\Throwable $e) {
            DB::run('UPDATE client_knowbe4 SET error = ? WHERE client_id = ?', [mb_substr(safe_error($e), 0, 500), $clientId]);
            throw $e;
        }
        return true;
    }

    /** Reads the account, training and phishing for one client and replaces its API results (see the class note). */
    private static function read(int $clientId, string $region, string $token): void
    {
        $now = date('Y-m-d H:i:s');
        $since = date('Y-m-d', strtotime('-' . self::MONTHS . ' months'));
        $today = date('Y-m-d');
        $a = self::get($region, $token, '/account');
        if (!is_array($a) || array_is_list($a)) {
            throw new \RuntimeException('KnowBe4 sent something other than an account.');
        }

        // Training campaigns: which have ended (all their enrollments are due), by id and by name
        $ended = $names = [];
        foreach (self::all($region, $token, '/training/campaigns') as $c) {
            if (($cid = self::id($c['campaign_id'] ?? null)) !== null) {
                $end = self::day($c['end_date'] ?? null);
                $ended[$cid] = in_array(strtolower((string) ($c['status'] ?? '')), ['completed', 'closed', 'ended'], true) || ($end !== null && $end < $today);
                if (($n = self::text($c['name'] ?? null, 190)) !== null) {
                    $names[$n] = $cid;
                }
            }
        }
        // Enrollments of the period, grouped by campaign (policies left out). A campaign is due when it has ended, or
        // when KnowBe4 marks one of its enrollments past due; then all of its enrollments count, finished or not.
        // Enrollments outside a campaign form one group, due when one is past due.
        $groups = []; // campaign => ['due' => bool, 'rows' => [...]]
        foreach (self::all($region, $token, '/training/enrollments', ['exclude_archived_users' => 'true', 'include_campaign_id' => 'true']) as $e) {
            $uid = self::id(is_array($e['user'] ?? null) ? ($e['user']['id'] ?? null) : null);
            $enrolled = self::day($e['enrollment_date'] ?? null) ?? self::day($e['start_date'] ?? null);
            $type = strtolower((string) ($e['content_type'] ?? ''));
            if ($uid === null || $enrolled === null || $enrolled < $since || str_contains($type, 'policy')) {
                continue;
            }
            $completed = self::day($e['completion_date'] ?? null);
            $status = strtolower((string) ($e['status'] ?? ''));
            $campaign = self::id($e['campaign_id'] ?? null) ?? ($names[(string) self::text($e['campaign_name'] ?? null, 190)] ?? null);
            $key = $campaign ?? '-';
            $groups[$key] ??= ['due' => $campaign !== null && ($ended[$campaign] ?? false), 'rows' => []];
            $groups[$key]['due'] = $groups[$key]['due'] || in_array($status, ['past due', 'past_due', 'overdue'], true);
            $groups[$key]['rows'][] = ['user' => $uid, 'done' => $completed !== null || in_array($status, ['passed', 'completed'], true), 'enrolled' => $enrolled, 'completed' => $completed];
        }
        $dueGroups = array_filter($groups, fn($g) => $g['due']);
        $counted = $dueGroups ?: $groups; // the running campaigns only while none is due yet
        $learners = []; // user id => done every counted enrollment (in memory only)
        $first = $last = null;
        foreach ($counted as $g) {
            foreach ($g['rows'] as $r) {
                $learners[$r['user']] = ($learners[$r['user']] ?? true) && $r['done'];
                $first = !$first || $r['enrolled'] < $first ? $r['enrolled'] : $first;
                $last = $r['completed'] && (!$last || $r['completed'] > $last) ? $r['completed'] : $last;
            }
        }
        $campaigns = $counted;

        // Phishing: each security test of the period that delivered something
        $tests = [];
        foreach (self::all($region, $token, '/phishing/security_tests') as $t) {
            $started = self::day($t['started_at'] ?? null);
            $sent = self::n($t['delivered_count'] ?? 0);
            if ($started === null || $started < $since || $sent === 0) {
                continue;
            }
            $tests[] = ['sent' => $sent, 'clicked' => min($sent, self::n($t['clicked_count'] ?? 0)), 'reported' => min($sent, self::n($t['reported_count'] ?? 0)),
                'compromised' => min($sent, self::n($t['data_entered_count'] ?? 0)), 'from' => $started, 'title' => self::text($t['name'] ?? null, 200)];
        }

        DB::transaction(function () use ($clientId, $a, $learners, $campaigns, $first, $last, $tests, $now) {
            // KnowBe4 replaces every API row for this client (a client with KnowBe4 doesn't read Curricula)
            DB::run("DELETE FROM sat_results WHERE client_id = ? AND source = 'api'", [$clientId]);
            if ($learners) {
                DB::insert('sat_results', ['client_id' => $clientId, 'kind' => 'training', 'source' => 'api', 'provider' => self::PROVIDER, 'report' => 'KnowBe4: training',
                    'learners' => count($learners), 'completed' => count(array_filter($learners)), 'assignments' => count($campaigns),
                    'covers_from' => $first, 'covers_to' => $last ?? $first ?? date('Y-m-d'), 'uploaded_at' => $now]);
            }
            foreach ($tests as $t) {
                DB::insert('sat_results', ['client_id' => $clientId, 'kind' => 'phishing', 'source' => 'api', 'provider' => self::PROVIDER,
                    'report' => mb_substr('KnowBe4: ' . ($t['title'] ?? 'phishing test'), 0, 60), 'sent' => $t['sent'], 'clicked' => $t['clicked'], 'reported' => $t['reported'],
                    'compromised' => $t['compromised'], 'campaigns' => 1, 'covers_from' => $t['from'], 'covers_to' => $t['from'], 'uploaded_at' => $now]);
            }
            DB::run('UPDATE client_knowbe4 SET account_name = ?, subscription = ?, seats = ?, risk_score = ?, synced_at = ?, error = NULL WHERE client_id = ?',
                [self::text($a['name'] ?? null, 255) ?? 'KnowBe4 account', self::text($a['subscription_level'] ?? null, 60), self::seats($a), self::risk($a), $now, $clientId]);
        });
    }
}
