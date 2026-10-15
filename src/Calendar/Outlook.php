<?php
declare(strict_types=1);

namespace Align\Calendar;

use Align\Crypto;
use Align\DB;
use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Mail\Graph;
use Align\Mail\M365Setup;
use Align\Providers\Clean;
use Align\Settings;

/**
 * 2.23.0 Staff Outlook calendars, two-way. Each staff member connects their own calendar once (Account, or the
 * Calendar page): they sign in to Align's email and calendars app (Mail\M365Setup, or an app registered by hand with
 * the calendar redirect URI) with only Calendars.ReadWrite on their own calendar. Then, every few minutes (the mail
 * timer, syncAll()):
 *  - Outlook → Align: their events from PAST_DAYS ago to FUTURE_DAYS ahead are read and shown on Align's calendar
 *    (subject, time, location, organizer and attendees; a private event only as Busy, nothing else kept);
 *  - Align → Outlook: the Align meetings they own are put in their calendar as their own events and kept up to date;
 *    a meeting cancelled, deleted or given to someone else is removed again. Align is where meetings are edited: a
 *    copy changed in Outlook is set back. A meeting whose invitation is already in their calendar (sent through
 *    Outlook, or an .ics invitation they accepted) isn't copied a second time.
 *
 * Security assumptions: a person connects and disconnects only their own calendar (CalendarController uses the
 * signed-in user's id, never one from the request); admins can disconnect anyone's. Refresh tokens are encrypted
 * (Crypto) and sent only to the Microsoft sign-in endpoint; access tokens live in memory only. The OAuth state and
 * PKCE verifier are kept in the person's session (single use, 15 minutes). The app is single tenant: only accounts of
 * the MSP's own Microsoft 365 can sign in; one Outlook account can be connected to one Align user only. Everything
 * Graph returns is remote data: ids are checked, text cleaned and cut, times parsed, links kept only when they're
 * https to Outlook on the web. Paging links are followed only on the Graph host itself.
 */
final class Outlook
{
    /** Delegated permissions a staff member grants (only their own calendar; offline_access for the refresh token). */
    public const SCOPES = 'offline_access openid profile email User.Read Calendars.ReadWrite';
    /** The window read from Outlook and the one Align meetings are copied in. */
    public const PAST_DAYS = 30;
    public const FUTURE_DAYS = 180;
    public const PUSH_DAYS = 365;
    /** A full two-way sync per person at most this often (Align meetings changed in between go out within a minute). */
    public const SYNC_MINUTES = 5;
    /** Pages of events read at most (100 each) before giving up rather than storing a partial calendar. */
    public const MAX_PAGES = 50;
    /** The Outlook category on Align's copies of meetings (so a copy Align no longer tracks is recognised as Align's). */
    public const CATEGORY = 'Align meeting';
    /** Seconds one timer run spends on calendars before leaving the rest for the next minute. */
    public const BUDGET = 40;
    /** Colours for people's Outlook events on Align's calendar, by user id. */
    public const COLORS = ['#5a6b85', '#7a5c99', '#2f7f72', '#9a6b3c', '#4f6fa8', '#8a4f6a', '#557a3a', '#3f7c8f'];

    /** @var array<int, array{0:string,1:int}> access tokens by user id, this process only */
    private static array $tokens = [];

    /**
     * Whether staff can connect: email uses Microsoft 365 with an app Align can sign people in to (Align's own app, or
     * one registered by hand with a secret or certificate; that one also needs calendarRedirectUri() registered).
     */
    public static function available(): bool
    {
        // The tenant must be the MSP's own directory id (a GUID): staff sign in to a single-tenant app only
        if (\Align\Mail\Mail::provider() !== 'microsoft' || !preg_match(\Align\M365\Entra::GUID, (string) Settings::get('m365_tenant')) || !Settings::get('m365_client_id')) {
            return false;
        }
        return M365Setup::auto() || Settings::hasSecret('m365_client_secret') || (Settings::hasSecret('m365_key_pem') && Settings::hasSecret('m365_cert_pem'));
    }

    /** The person's connection (account, status, error, connected_at, synced_at), or null. */
    public static function connection(int $userId): ?array
    {
        return DB::one('SELECT user_id, account, account_name, status, error, connected_at, synced_at FROM user_calendars WHERE user_id = ?', [$userId]) ?: null;
    }

    /**
     * Why staff can't connect, for the Outlook card ('' when they can): email isn't Microsoft 365, or the Directory
     * (tenant) ID saved for an app registered by hand is a domain rather than the GUID.
     */
    public static function unavailableReason(): string
    {
        if (self::available()) {
            return '';
        }
        if (\Align\Mail\Mail::provider() === 'microsoft' && Settings::get('m365_client_id') && Settings::get('m365_tenant')
            && !preg_match(\Align\M365\Entra::GUID, (string) Settings::get('m365_tenant'))) {
            return 'Outlook calendars need the Directory (tenant) ID under Integrations → Email saved as its ID (a GUID such as 11111111-2222-…), not a domain. An admin can change it there.';
        }
        return 'Connecting Outlook needs email set up with Microsoft 365 first. An admin does that under Integrations → Email (Set up with Microsoft).';
    }

    /** Everyone connected whose Align account is active (admins' overview, the calendar's person list): [user id => row with the user's name]. */
    public static function connections(): array
    {
        $out = [];
        foreach (DB::all('SELECT c.user_id, c.account, c.status, c.error, c.synced_at, u.name FROM user_calendars c JOIN users u ON u.id = c.user_id AND u.is_active = 1 ORDER BY u.name') as $r) {
            $out[(int) $r['user_id']] = $r;
        }
        return $out;
    }

    // ---- Connecting ---------------------------------------------------------------------------

    /**
     * The sign-in address for a staff member: 128-bit state, PKCE (S256), the calendar redirect URI, their email as a
     * hint. @return array{0:string,1:string,2:string} [URL, state, verifier] (the caller keeps the last two in the session)
     */
    public static function authorizeUrl(string $loginHint): array
    {
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $q = http_build_query(array_filter([
            'client_id' => trim((string) Settings::get('m365_client_id')),
            'response_type' => 'code',
            'redirect_uri' => M365Setup::calendarRedirectUri(),
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
            'login_hint' => filter_var($loginHint, FILTER_VALIDATE_EMAIL) ? $loginHint : null,
        ]));
        return [preg_replace('#/token$#', '/authorize', Graph::tokenUrl()) . '?' . $q, $state, $verifier];
    }

    /**
     * Finishes the sign-in for $userId (the signed-in person; the caller matched the state to their session): redeems
     * the code, reads who signed in, and stores the refresh token encrypted. Refused when that Outlook account is
     * already connected to someone else in Align. Returns ['account', 'name'].
     */
    public static function complete(int $userId, string $code, string $verifier): array
    {
        $r = self::tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => M365Setup::calendarRedirectUri(),
            'code_verifier' => $verifier, 'scope' => self::SCOPES]);
        if (!is_string($r['refresh_token'] ?? null) || $r['refresh_token'] === '') {
            throw new \RuntimeException('Microsoft did not return a refresh token (the offline_access permission is needed).');
        }
        // The sign-in must be from the MSP's own tenant (the id token straight from Microsoft's sign-in over HTTPS)
        $claims = self::idClaims((string) ($r['id_token'] ?? ''));
        if (!is_string($claims['tid'] ?? null) || strcasecmp($claims['tid'], (string) Settings::get('m365_tenant')) !== 0) {
            throw new \RuntimeException('That account isn\'t from your organization\'s Microsoft 365. Sign in with your work account.');
        }
        $me = self::call((string) $r['access_token'], 'GET', '/me?$select=displayName,mail,userPrincipalName');
        $account = strtolower((string) (Clean::text($me['mail'] ?? null, 255) ?? Clean::text($me['userPrincipalName'] ?? null, 255) ?? ''));
        if (!filter_var($account, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Microsoft didn\'t say which account signed in. Try again.');
        }
        // Your own calendar only: the account must be the email address of your Align account
        $mine = strtolower((string) DB::value('SELECT email FROM users WHERE id = ?', [$userId]));
        $upn = strtolower((string) Clean::text($me['userPrincipalName'] ?? null, 255));
        if ($account !== $mine && $upn !== $mine) {
            throw new \RuntimeException("You signed in as $account, but your Align account is $mine. Sign in with your own account (or ask an admin to change your email in Align).");
        }
        $other = DB::value('SELECT u.name FROM user_calendars c JOIN users u ON u.id = c.user_id WHERE c.account = ? AND c.user_id <> ?', [$account, $userId]);
        if ($other) {
            throw new \RuntimeException("$account is already connected to $other's Align account. Disconnect it there first.");
        }
        DB::run('INSERT INTO user_calendars (user_id, provider, account, account_name, token_enc, status, error, connected_at, synced_at)
            VALUES (?, \'microsoft\', ?, ?, ?, \'connected\', NULL, NOW(), NULL)
            ON DUPLICATE KEY UPDATE account = VALUES(account), account_name = VALUES(account_name), token_enc = VALUES(token_enc), status = \'connected\',
                error = NULL, connected_at = NOW(), synced_at = NULL',
            [$userId, $account, Clean::text($me['displayName'] ?? null, 255), Crypto::encrypt((string) $r['refresh_token'])]);
        self::$tokens[$userId] = [(string) $r['access_token'], time() + (int) ($r['expires_in'] ?? 3600)];
        return ['account' => $account, 'name' => (string) Clean::text($me['displayName'] ?? null, 255)];
    }

    /** The claims of an id token (not verified: it comes straight from the token endpoint over HTTPS), or []. */
    private static function idClaims(string $jwt): array
    {
        $p = explode('.', $jwt);
        $c = count($p) === 3 ? json_decode((string) base64_decode(strtr($p[1], '-_', '+/')), true) : null;
        return is_array($c) ? $c : [];
    }

    /**
     * Disconnects $userId: removes the copies of Align meetings from their Outlook calendar when it can still sign in
     * ($removeCopies, best effort), and forgets the token, the copies and their events. Microsoft keeps its consent.
     */
    public static function disconnect(int $userId, bool $removeCopies = true): void
    {
        // Not while a sync of this person is running (it could make copies again after they're removed); a sync
        // still running after 15 seconds is left to finish and its copies are removed below with the rest
        $lock = 'msp_align_calendar_' . $userId;
        $locked = (int) DB::value('SELECT GET_LOCK(?, 15)', [$lock]) === 1;
        try {
            self::disconnectNow($userId, $removeCopies);
        } finally {
            if ($locked) {
                DB::value('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
    }

    /** disconnect() once the person's sync lock is held. */
    private static function disconnectNow(int $userId, bool $removeCopies): void
    {
        if ($removeCopies) {
            try {
                $token = self::token($userId);
                foreach (DB::all('SELECT event_id FROM calendar_pushes WHERE user_id = ?', [$userId]) as $p) {
                    try {
                        self::call($token, 'DELETE', '/me/events/' . rawurlencode((string) $p['event_id']));
                    } catch (\Throwable) {
                        // already gone, or Outlook unreachable: the copy stays in their calendar
                    }
                }
            } catch (\Throwable) {
                // can't sign in any more: nothing to remove with
            }
        }
        DB::run('DELETE FROM calendar_pushes WHERE user_id = ?', [$userId]);
        DB::run('DELETE FROM calendar_events WHERE user_id = ?', [$userId]);
        DB::run('DELETE FROM user_calendars WHERE user_id = ?', [$userId]);
        unset(self::$tokens[$userId]);
    }

    /**
     * Disconnects everyone (the app they signed in to is being removed or replaced), removing Align's copies from their
     * calendars while the app can still sign in (best effort).
     */
    public static function disconnectAll(): int
    {
        $n = 0;
        foreach (DB::all('SELECT user_id FROM user_calendars') as $r) {
            self::disconnect((int) $r['user_id'], true);
            $n++;
        }
        return $n;
    }

    // ---- Tokens and calls ---------------------------------------------------------------------

    /** POSTs to the tenant's token endpoint with the app's credential; returns the answer with an access token or throws. */
    private static function tokenRequest(array $form): array
    {
        $form += ['client_id' => trim((string) Settings::get('m365_client_id'))] + Graph::clientAuth();
        try {
            $r = (new HttpClient(30, 1))->request('POST', Graph::tokenUrl(), ['Accept' => 'application/json'], $form, false);
        } catch (HttpException $e) {
            throw new CalendarException(self::friendly($e), $e->status, self::oauthError($e));
        }
        if (!is_array($r['json']) || !is_string($r['json']['access_token'] ?? null) || $r['json']['access_token'] === '') {
            throw new CalendarException('Microsoft did not return an access token.');
        }
        return $r['json'];
    }

    /**
     * An access token for $userId from their stored refresh token (Microsoft may hand back a new refresh token, which
     * replaces the old one). A refused refresh token marks the connection "sign in again".
     */
    private static function token(int $userId): string
    {
        if (isset(self::$tokens[$userId]) && self::$tokens[$userId][1] > time() + 120) {
            return self::$tokens[$userId][0];
        }
        $rt = Crypto::decrypt((string) DB::value('SELECT token_enc FROM user_calendars WHERE user_id = ?', [$userId]));
        if (!$rt) {
            throw new CalendarException('Not connected.');
        }
        try {
            $r = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $rt, 'scope' => self::SCOPES]);
        } catch (CalendarException $e) {
            if (in_array($e->oauthError, ['invalid_grant', 'interaction_required', 'unauthorized_client', 'invalid_client'], true)) {
                $msg = 'Microsoft no longer accepts the sign-in (' . mb_strimwidth(preg_replace('/^Microsoft answered: /', '', $e->getMessage()) ?? '', 0, 120, '…') . '). Connect your calendar again.';
                DB::run("UPDATE user_calendars SET status = 'error', error = ? WHERE user_id = ?", [mb_substr($msg, 0, 300), $userId]);
                throw new CalendarException($msg, $e->status, $e->oauthError);
            }
            throw $e;
        }
        if (is_string($r['refresh_token'] ?? null) && $r['refresh_token'] !== '') {
            DB::run('UPDATE user_calendars SET token_enc = ? WHERE user_id = ?', [Crypto::encrypt($r['refresh_token']), $userId]);
        }
        self::$tokens[$userId] = [(string) $r['access_token'], time() + (int) ($r['expires_in'] ?? 3600)];
        return self::$tokens[$userId][0];
    }

    /** One Graph call with a person's token ($path built here from fixed or encoded parts, or a checked paging link). */
    private static function call(string $token, string $method, string $path, ?array $body = null, array $headers = []): ?array
    {
        $h = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'] + $headers;
        if ($body !== null) {
            $h['Content-Type'] = 'application/json';
        }
        $url = str_starts_with($path, 'http') ? $path : Graph::graphBase() . $path;
        try {
            $r = (new HttpClient(30, 2))->request($method, $url, $h, $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null, $method === 'GET');
        } catch (HttpException $e) {
            throw new CalendarException(self::friendly($e), $e->status);
        }
        return is_array($r['json'] ?? null) ? $r['json'] : null;
    }

    /** Microsoft's error, short and without tokens. */
    private static function friendly(HttpException $e): string
    {
        $j = json_decode((string) $e->body, true) ?: [];
        $desc = (string) ($j['error_description'] ?? ($j['error']['message'] ?? ''));
        $desc = trim((string) preg_replace('/\s+Trace ID:.*$/s', '', $desc));
        return 'Microsoft answered: ' . ($desc !== '' ? mb_strimwidth($desc, 0, 250, '…') : 'HTTP ' . $e->status);
    }

    /** The OAuth error code of a failed token request ('' when none). */
    private static function oauthError(HttpException $e): string
    {
        $j = json_decode((string) $e->body, true);
        return is_array($j) && is_string($j['error'] ?? null) ? $j['error'] : '';
    }

    // ---- Sync ---------------------------------------------------------------------------------

    /**
     * Every minute from the mail timer: a full two-way sync for each connected person whose last one is SYNC_MINUTES
     * old (or everyone, $force), and Align → Outlook for those whose meetings changed since. One run at a time (a
     * named lock). Returns log lines.
     */
    public static function syncAll(bool $force = false): array
    {
        if (!DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_calendars'")
            || !DB::value("SELECT 1 FROM user_calendars WHERE status = 'connected' LIMIT 1") || !self::available()) {
            return [];
        }
        if ((int) DB::value("SELECT GET_LOCK('msp_align_calendars', 0)") !== 1) {
            return ['calendars: already running'];
        }
        $out = [];
        $until = time() + self::BUDGET;
        try {
            // Someone whose Align account was switched off: disconnected (their copies removed while it still signs in)
            foreach (DB::all('SELECT c.user_id FROM user_calendars c JOIN users u ON u.id = c.user_id WHERE u.is_active = 0') as $r) {
                self::disconnect((int) $r['user_id']);
                $out[] = 'calendar ' . $r['user_id'] . ': disconnected (account switched off)';
            }
            $due = date('Y-m-d H:i:s', time() - self::SYNC_MINUTES * 60);
            foreach (DB::all("SELECT user_id, synced_at FROM user_calendars WHERE status = 'connected' ORDER BY synced_at IS NOT NULL, synced_at") as $r) {
                if (time() > $until) {
                    $out[] = 'calendars: time budget used, the rest next minute';
                    break;
                }
                $uid = (int) $r['user_id'];
                $full = $force || !$r['synced_at'] || $r['synced_at'] <= $due;
                if (!$full && !self::pushDue($uid, (string) $r['synced_at'])) {
                    continue;
                }
                $out[] = 'calendar ' . $uid . ': ' . self::syncUser($uid, $full);
            }
        } finally {
            DB::value("SELECT RELEASE_LOCK('msp_align_calendars')");
        }
        return $out;
    }

    /** Whether $userId's meetings changed since $since (edited, cancelled, given to someone else, deleted). */
    private static function pushDue(int $userId, string $since): bool
    {
        return (bool) DB::value('SELECT 1 FROM meetings WHERE owner_id = ? AND updated_at > ? LIMIT 1', [$userId, $since])
            || (bool) DB::value('SELECT 1 FROM calendar_pushes p LEFT JOIN meetings m ON m.id = p.meeting_id
                WHERE p.user_id = ? AND (m.id IS NULL OR m.owner_id IS NULL OR m.owner_id <> p.user_id OR m.updated_at > ?) LIMIT 1', [$userId, $since]);
    }

    /**
     * One person: Outlook → Align ($full), then Align → Outlook. A failure is kept on their connection (shown to them)
     * and the stored events stay as they were. Returns a short summary.
     */
    public static function syncUser(int $userId, bool $full = true): string
    {
        $row = DB::one('SELECT * FROM user_calendars WHERE user_id = ?', [$userId]);
        if (!$row) {
            return 'not connected';
        }
        // One sync per person at a time (the timer, Sync now and the first sync after connecting can meet)
        $lock = 'msp_align_calendar_' . $userId;
        if ((int) DB::value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return 'already syncing';
        }
        $now = date('Y-m-d H:i:s');
        try {
            $token = self::token($userId);
            $pulled = $full ? self::pull($userId, $token) : null;
            $pushed = self::push($userId, (string) $row['account'], $token);
            DB::run("UPDATE user_calendars SET status = 'connected', error = NULL, synced_at = ? WHERE user_id = ?", [$now, $userId]);
            return ($pulled !== null ? "$pulled events read, " : '') . $pushed;
        } catch (\Throwable $e) {
            $msg = mb_substr($e instanceof CalendarException ? $e->getMessage() : safe_error($e), 0, 300);
            DB::run('UPDATE user_calendars SET error = ?, synced_at = ? WHERE user_id = ?', [$msg, $now, $userId]);
            return 'failed: ' . $msg;
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * Outlook → Align: reads the person's calendar view (all pages, in UTC) and replaces their stored events in one
     * transaction, only once every page was read. Each event that is an Align meeting is marked with the meeting.
     */
    private static function pull(int $userId, string $token): int
    {
        $from = gmdate('Y-m-d\TH:i:s\Z', strtotime('-' . self::PAST_DAYS . ' days'));
        $to = gmdate('Y-m-d\TH:i:s\Z', strtotime('+' . self::FUTURE_DAYS . ' days'));
        $path = '/me/calendarView?' . http_build_query(['startDateTime' => $from, 'endDateTime' => $to, '$top' => 100,
            '$select' => 'id,subject,start,end,isAllDay,location,showAs,sensitivity,organizer,attendees,webLink,isCancelled,iCalUId,categories']);
        $items = [];
        for ($page = 0; ; $page++) {
            if ($page >= self::MAX_PAGES) {
                throw new CalendarException('Outlook sent more than ' . self::MAX_PAGES . ' pages of events; nothing was changed.');
            }
            $r = self::call($token, 'GET', $path, null, ['Prefer' => 'outlook.timezone="UTC"']);
            if (!is_array($r) || !is_array($r['value'] ?? null) || !array_is_list($r['value'])) {
                throw new CalendarException('Outlook sent something other than a list of events; nothing was changed.');
            }
            array_push($items, ...array_filter($r['value'], 'is_array'));
            $next = $r['@odata.nextLink'] ?? null;
            if (!is_string($next) || $next === '') {
                break;
            }
            // Only Graph's own paging link for this list (never somewhere else with the person's token)
            if (!str_starts_with($next, Graph::graphBase() . '/me/calendarView?')) {
                throw new CalendarException('Outlook sent a paging link to somewhere unexpected; nothing was changed.');
            }
            $path = $next;
        }

        // Which events are Align meetings: Align's own copies, the invitation event, the .ics invitation's UID, or an
        // invitation at the same time with the meeting's title
        $pushes = [];
        foreach (DB::all('SELECT meeting_id, event_id FROM calendar_pushes WHERE user_id = ?', [$userId]) as $p) {
            $pushes[(string) $p['event_id']] = (int) $p['meeting_id'];
        }
        $meetings = DB::all('SELECT m.id, m.uid, m.title, m.starts_at, m.ends_at, m.graph_event_id, c.name AS client_name FROM meetings m LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.ends_at > ? AND m.starts_at < ?',
            [date('Y-m-d H:i:s', strtotime($from)), date('Y-m-d H:i:s', strtotime($to))]);
        $byEvent = $byUid = $byStart = [];
        foreach ($meetings as $m) {
            if ($m['graph_event_id']) {
                $byEvent[(string) $m['graph_event_id']] = (int) $m['id'];
            }
            // An .ics invitation's UID is the meeting's uid @ this server's host (Meetings\Ics)
            $byUid[strtolower((string) $m['uid'])] = (int) $m['id'];
            $byUid[strtolower($m['uid'] . '@' . \Align\Meetings\Ics::host())] = (int) $m['id'];
            $byStart[substr((string) $m['starts_at'], 0, 16) . '|' . substr((string) $m['ends_at'], 0, 16)][] = $m;
        }

        $rows = [];
        $now = date('Y-m-d H:i:s');
        foreach ($items as $e) {
            $id = is_string($e['id'] ?? null) && $e['id'] !== '' && strlen($e['id']) <= 512 && !preg_match('/[\x00-\x20\x7F]/', $e['id']) ? $e['id'] : null;
            $allDay = !empty($e['isAllDay']);
            $start = self::time($e['start'] ?? null, $allDay);
            $end = self::time($e['end'] ?? null, $allDay);
            if ($id === null || $start === null || $end === null || !empty($e['isCancelled'])) {
                continue;
            }
            $private = in_array(strtolower((string) ($e['sensitivity'] ?? '')), ['private', 'confidential'], true);
            $subject = Clean::text($e['subject'] ?? null, 255);
            $ical = strtolower((string) Clean::text($e['iCalUId'] ?? null, 255));
            $meeting = $pushes[$id] ?? $byEvent[$id] ?? ($ical !== '' ? $byUid[$ical] ?? null : null);
            // An invitation Exchange re-coded (its UID unrecognisable): the same start and end, and exactly the
            // meeting's title (with or without "Client: " before it). Align's own untracked copies (category) aren't one
            $alignCopy = is_array($e['categories'] ?? null) && in_array(self::CATEGORY, $e['categories'], true);
            if ($meeting === null && $subject !== null && !$alignCopy) {
                $norm = fn(?string $s) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $s)));
                foreach ($byStart[substr($start, 0, 16) . '|' . substr($end, 0, 16)] ?? [] as $m) {
                    if (in_array($norm($subject), [$norm($m['title']), $norm($m['client_name'] . ': ' . $m['title'])], true)) {
                        $meeting = (int) $m['id'];
                        break;
                    }
                }
            }
            $attendees = [];
            foreach (array_slice(is_array($e['attendees'] ?? null) ? $e['attendees'] : [], 0, 50) as $a) {
                $addr = is_array($a) ? ($a['emailAddress'] ?? null) : null;
                $email = is_array($addr) ? Clean::text($addr['address'] ?? null, 190) : null;
                $name = is_array($addr) ? Clean::text($addr['name'] ?? null, 120) : null;
                if ($email !== null || $name !== null) {
                    $attendees[] = array_filter(['name' => $name, 'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                        'response' => is_array($a['status'] ?? null) ? Clean::text($a['status']['response'] ?? null, 20) : null]);
                }
            }
            $org = is_array($e['organizer']['emailAddress'] ?? null) ? $e['organizer']['emailAddress'] : [];
            $link = is_string($e['webLink'] ?? null) && preg_match('#^https://(outlook\.office(365)?\.com|outlook\.live\.com)/#', $e['webLink']) ? mb_substr($e['webLink'], 0, 1000) : null;
            if ($alignCopy && $meeting === null) {
                continue; // a copy Align made that it no longer tracks (e.g. from an earlier connection): not shown as theirs
            }
            $rows[sha1($id)] = [
                'user_id' => $userId, 'event_key' => sha1($id), 'event_id' => $id, 'meeting_id' => $meeting,
                'subject' => $private ? null : $subject,
                'location' => $private ? null : (is_array($e['location'] ?? null) ? Clean::text($e['location']['displayName'] ?? null, 255) : null),
                'starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay ? 1 : 0,
                'show_as' => Clean::text($e['showAs'] ?? null, 20), 'private' => $private ? 1 : 0,
                'organizer' => $private ? null : (Clean::text($org['name'] ?? null, 120) ?? Clean::text($org['address'] ?? null, 190)),
                'attendees' => $private || !$attendees ? null : json_encode($attendees, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'web_link' => $link, 'synced_at' => $now,
            ];
        }
        DB::transaction(function () use ($userId, $rows) {
            DB::run('DELETE FROM calendar_events WHERE user_id = ?', [$userId]);
            if ($rows) {
                DB::upsertMany('calendar_events', array_values($rows), ['user_id', 'event_key']);
            }
        });
        return count($rows);
    }

    /**
     * A Graph dateTimeTimeZone in UTC (as asked with Prefer) as this server's local Y-m-d H:i:s, or null. An all-day
     * event's start and end are dates, not moments: kept as the date given (midnight), never moved by the timezone.
     */
    private static function time(mixed $v, bool $allDay = false): ?string
    {
        $dt = is_array($v) && is_string($v['dateTime'] ?? null) ? $v['dateTime'] : null;
        if ($dt === null || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?/', $dt)) {
            return null;
        }
        if ($allDay) {
            return checkdate((int) substr($dt, 5, 2), (int) substr($dt, 8, 2), (int) substr($dt, 0, 4)) ? substr($dt, 0, 10) . ' 00:00:00' : null;
        }
        $tz = is_string($v['timeZone'] ?? null) && $v['timeZone'] !== '' ? $v['timeZone'] : 'UTC';
        try {
            $d = new \DateTimeImmutable(substr($dt, 0, 19), new \DateTimeZone(in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC'));
        } catch (\Throwable) {
            return null;
        }
        return $d->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /**
     * Align → Outlook: the person's own meetings (not cancelled) from PAST_DAYS ago to PUSH_DAYS ahead get a copy in
     * their calendar, made or changed when it differs; copies of meetings that are cancelled, deleted or now owned by
     * someone else are removed; a meeting whose invitation already is in their calendar has no copy.
     */
    private static function push(int $userId, string $account, string $token): string
    {
        $made = $changed = $removed = 0;
        $connectedAs = strtolower((string) Settings::get('m365_connected_as'));
        $want = [];
        foreach (DB::all("SELECT m.*, c.name AS client_name FROM meetings m LEFT JOIN clients c ON c.id = m.client_id
                WHERE m.owner_id = ? AND m.status <> 'cancelled' AND m.ends_at > ? AND m.starts_at < ?",
                [$userId, date('Y-m-d H:i:s', strtotime('-' . self::PAST_DAYS . ' days')), date('Y-m-d H:i:s', strtotime('+' . self::PUSH_DAYS . ' days'))]) as $m) {
            $mb = strtolower(rawurldecode((string) $m['graph_mailbox']));
            $invitedHere = $m['graph_event_id'] && ($mb === '/users/' . $account || ($mb === '/me' && $connectedAs === $account));
            $inCalendar = (bool) DB::value('SELECT 1 FROM calendar_events e LEFT JOIN calendar_pushes p ON p.user_id = e.user_id AND p.meeting_id = e.meeting_id
                WHERE e.user_id = ? AND e.meeting_id = ? AND (p.event_id IS NULL OR p.event_id <> e.event_id) LIMIT 1', [$userId, $m['id']]);
            if (!$invitedHere && !$inCalendar) {
                $want[(int) $m['id']] = $m;
            }
        }
        $have = [];
        foreach (DB::all('SELECT meeting_id, event_id, hash FROM calendar_pushes WHERE user_id = ?', [$userId]) as $p) {
            $have[(int) $p['meeting_id']] = $p;
        }
        // Copies no longer wanted: removed from Outlook, except a meeting simply in the past (its copy stays as history)
        foreach (array_diff_key($have, $want) as $mid => $p) {
            $m = DB::one('SELECT owner_id, status, ends_at FROM meetings WHERE id = ?', [$mid]);
            $past = $m && (int) $m['owner_id'] === $userId && $m['status'] !== 'cancelled' && $m['ends_at'] <= date('Y-m-d H:i:s', strtotime('-' . self::PAST_DAYS . ' days'));
            if (!$past) {
                try {
                    self::call($token, 'DELETE', '/me/events/' . rawurlencode((string) $p['event_id']));
                } catch (CalendarException $e) {
                    if ($e->status !== 404) {
                        throw $e;
                    }
                }
                $removed++;
            }
            DB::run('DELETE FROM calendar_pushes WHERE meeting_id = ? AND user_id = ?', [$mid, $userId]);
        }
        foreach ($want as $mid => $m) {
            $ev = self::eventFor($m);
            $hash = sha1(json_encode($ev));
            $p = $have[$mid] ?? null;
            if ($p && $p['hash'] === $hash) {
                // Unchanged in Align: still set back when the copy was moved or renamed in Outlook (as last read)
                $seen = DB::one('SELECT subject, private, starts_at, ends_at FROM calendar_events WHERE user_id = ? AND event_key = ?', [$userId, sha1((string) $p['event_id'])]);
                if (!$seen || ($seen['starts_at'] === $m['starts_at'] && $seen['ends_at'] === $m['ends_at'] && ($seen['private'] || $seen['subject'] === Clean::text($ev['subject'], 255)))) {
                    continue;
                }
            }
            $id = null;
            if ($p) {
                try {
                    self::call($token, 'PATCH', '/me/events/' . rawurlencode((string) $p['event_id']), $ev);
                    $id = (string) $p['event_id'];
                    $changed++;
                } catch (CalendarException $e) {
                    if ($e->status !== 404) {
                        throw $e;
                    }
                }
            }
            if ($id === null) { // new, or gone from Outlook when Align changed it: made (again)
                $r = self::call($token, 'POST', '/me/events', $ev + ['transactionId' => 'align-' . $m['uid'] . '-' . substr($hash, 0, 8)]);
                $id = is_string($r['id'] ?? null) && $r['id'] !== '' && strlen($r['id']) <= 512 ? $r['id'] : null;
                if ($id === null) {
                    throw new CalendarException('Outlook didn\'t return the new event.');
                }
                $made++;
            }
            DB::run('INSERT INTO calendar_pushes (meeting_id, user_id, event_id, hash, pushed_at) VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), hash = VALUES(hash), pushed_at = NOW()', [$mid, $userId, $id, $hash]);
        }
        return "$made added, $changed updated, $removed removed in Outlook";
    }

    /** The Outlook copy of an Align meeting: title (with the client), time in UTC, place, a link back, busy. */
    private static function eventFor(array $m): array
    {
        $link = \Align\Mail\Notifications::url('/meetings/' . (int) $m['id']);
        $ev = [
            'subject' => mb_substr(($m['client_name'] ? $m['client_name'] . ': ' : '') . $m['title'], 0, 255),
            'body' => ['contentType' => 'HTML', 'content' => '<p>Align meeting' . ($m['client_name'] ? ' with ' . e((string) $m['client_name']) : '')
                . '. Change it in Align: <a href="' . e($link) . '">' . e($link) . '</a></p>'
                . ($m['video_url'] ? '<p>Join: <a href="' . e((string) $m['video_url']) . '">' . e((string) $m['video_url']) . '</a></p>' : '')],
            'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s', strtotime((string) $m['starts_at'])), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s', strtotime((string) $m['ends_at'])), 'timeZone' => 'UTC'],
            'showAs' => 'busy',
            'categories' => [self::CATEGORY],
        ];
        if ($m['location']) {
            $ev['location'] = ['displayName' => mb_substr((string) $m['location'], 0, 255)];
        }
        return $ev;
    }

    // ---- Align's calendar -----------------------------------------------------------------------

    /**
     * People's Outlook events overlapping $from..$to for Align's calendar (FullCalendar's shape), leaving out events
     * that are Align meetings (shown as meetings already). $userId: only that person's. Text is plain (shown as text).
     */
    public static function calendarEvents(string $from, string $to, ?int $userId = null): array
    {
        if (!self::available()) {
            return []; // email moved away from Microsoft 365: what was read earlier isn't shown
        }
        $sql = 'SELECT e.*, u.name AS user_name FROM calendar_events e JOIN users u ON u.id = e.user_id AND u.is_active = 1
            WHERE e.meeting_id IS NULL AND e.starts_at < ? AND e.ends_at > ?';
        $params = [$to, $from];
        if ($userId) {
            $sql .= ' AND e.user_id = ?';
            $params[] = $userId;
        }
        $out = [];
        foreach (DB::all($sql . ' ORDER BY e.starts_at LIMIT 2000', $params) as $e) {
            $color = self::COLORS[(int) $e['user_id'] % count(self::COLORS)];
            $first = strtok((string) $e['user_name'], ' ') ?: (string) $e['user_name'];
            $what = $e['private'] ? 'Busy' : ((string) $e['subject'] ?: '(no subject)');
            $attendees = json_decode((string) $e['attendees'], true) ?: [];
            $out[] = [
                'id' => 'o-' . $e['id'],
                'title' => "$first: $what",
                'start' => $e['all_day'] ? substr((string) $e['starts_at'], 0, 10) : date('c', strtotime((string) $e['starts_at'])),
                'end' => $e['all_day'] ? substr((string) $e['ends_at'], 0, 10) : date('c', strtotime((string) $e['ends_at'])),
                'allDay' => (bool) $e['all_day'],
                'backgroundColor' => $color, 'borderColor' => $color,
                'classNames' => ['outlook-event'],
                'extendedProps' => ['outlook' => [
                    'person' => (string) $e['user_name'],
                    'subject' => $what,
                    'location' => $e['private'] ? null : $e['location'],
                    'organizer' => $e['private'] ? null : $e['organizer'],
                    'attendees' => $e['private'] ? [] : array_map(fn($a) => trim(($a['name'] ?? '') . (isset($a['email']) ? ' <' . $a['email'] . '>' : '')), $attendees),
                    'showAs' => $e['show_as'],
                    'link' => $e['private'] ? null : $e['web_link'],
                ]],
            ];
        }
        return $out;
    }
}
