<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * Microsoft 365 through Microsoft Graph: sends mail and manages meeting invitations.
 *
 * Two ways to sign in (Integrations → Email):
 *  - app:       Entra app registration, application permissions Mail.Send (+ Calendars.ReadWrite for
 *               Outlook invitations), client secret or certificate. Runs unattended; limit the app to
 *               the sending mailbox with Exchange RBAC for Applications.
 *  - delegated: an admin clicks "Connect with Microsoft" and signs in as the sending mailbox
 *               (authorization code + PKCE). Align keeps the refresh token (encrypted) and renews it.
 *
 * Security: tokens and secrets are stored encrypted (Settings::setSecret) and only ever sent to the login and
 * Graph endpoints (fixed Microsoft addresses; the *_base settings exist only for the test suite and have no form
 * field). Error text comes from friendly(), which quotes Microsoft's error description but never a token.
 * Values in Graph's answers are remote data: their types are checked before use.
 */
final class Graph
{
    public const MODES = ['off' => 'Off', 'app' => 'App-only (recommended for servers)', 'delegated' => 'Sign in as a mailbox'];
    public const DELEGATED_SCOPES = 'offline_access openid profile email User.Read Mail.Send Mail.Send.Shared Calendars.ReadWrite Calendars.ReadWrite.Shared';

    private HttpClient $http;
    private ?string $token = null;
    private int $tokenExp = 0;

    /** $mode: 'app' or 'delegated' (Mail::mode()). */
    public function __construct(private string $mode)
    {
        $this->http = new HttpClient(30, 3);
    }

    /** The configured sign-in mode (see Mail::mode()). */
    public static function mode(): string
    {
        return Mail::mode();
    }

    /** True when mail can actually be sent (mode chosen and credentials present). */
    public static function ready(): bool
    {
        $mode = self::mode();
        if ($mode === 'off' || !Settings::get('m365_tenant') || !Settings::get('m365_client_id')) {
            return false;
        }
        if ($mode === 'app') {
            return (string) Settings::get('mail_from') !== ''
                && (Settings::get('m365_auth', 'secret') === 'certificate' ? Settings::hasSecret('m365_key_pem') && Settings::hasSecret('m365_cert_pem') : Settings::hasSecret('m365_client_secret'));
        }
        return Settings::hasSecret('m365_refresh_token') && Settings::hasSecret('m365_client_secret');
    }

    /** A client for the configured mode; throws when not ready. Doesn't apply the test-server wrapper: use Mail::client(). */
    public static function fromSettings(): self
    {
        if (!self::ready()) {
            throw new \RuntimeException('Email is not set up (Integrations → Email).');
        }
        return new self(self::mode());
    }

    // ---- Endpoints ----------------------------------------------------------------------------

    /** The Entra sign-in endpoint (overridable only from the database, for the test suite). */
    private static function loginBase(): string
    {
        return rtrim((string) (Settings::get('m365_login_base') ?: 'https://login.microsoftonline.com'), '/');
    }

    /** The Graph endpoint (overridable only from the database, for the test suite). */
    private static function graphBase(): string
    {
        return rtrim((string) (Settings::get('m365_graph_base') ?: 'https://graph.microsoft.com/v1.0'), '/');
    }

    /** The tenant as a URL path segment (validated on save, encoded here as well). */
    private static function tenant(): string
    {
        return rawurlencode(trim((string) Settings::get('m365_tenant')));
    }

    /** The OAuth token endpoint for the tenant (also the audience of the certificate assertion). */
    public static function tokenUrl(): string
    {
        return self::loginBase() . '/' . self::tenant() . '/oauth2/v2.0/token';
    }

    /** See Mail::redirectUri(). */
    public static function redirectUri(): string
    {
        return Mail::redirectUri();
    }

    /** Mailbox Graph calls act on: app → the From mailbox; delegated → "me", or a shared mailbox to send as. */
    public function mailboxPath(?string $mailbox = null): string
    {
        $from = $mailbox ?? trim((string) Settings::get('mail_from'));
        if ($this->mode === 'delegated' && ($from === '' || strcasecmp($from, (string) Settings::get('m365_connected_as')) === 0)) {
            return '/me';
        }
        if ($from === '') {
            throw new \RuntimeException('Set the From mailbox under Integrations → Email.');
        }
        return '/users/' . rawurlencode($from);
    }

    // ---- Tokens -------------------------------------------------------------------------------

    /**
     * A valid access token: this object's, the encrypted cache's (per mode; cleared when email settings change), or
     * a new one from the token endpoint. In delegated mode Microsoft may rotate the refresh token; the new one is
     * kept only while one is still stored, so a refresh that finishes after Disconnect doesn't reconnect the
     * mailbox (2.2.1). Two runs refreshing at once is harmless: Microsoft keeps both refresh tokens valid.
     */
    private function token(): string
    {
        if ($this->token && time() < $this->tokenExp - 120) {
            return $this->token;
        }
        $cache = json_decode((string) Settings::secret('m365_token_cache'), true);
        if (is_array($cache) && ($cache['mode'] ?? '') === $this->mode && ($cache['exp'] ?? 0) > time() + 120 && !empty($cache['token'])) {
            [$this->token, $this->tokenExp] = [$cache['token'], (int) $cache['exp']];
            return $this->token;
        }
        $form = ['client_id' => trim((string) Settings::get('m365_client_id'))];
        if ($this->mode === 'app') {
            $form += ['grant_type' => 'client_credentials', 'scope' => 'https://graph.microsoft.com/.default'] + self::clientAuth();
        } else {
            $form += ['grant_type' => 'refresh_token', 'refresh_token' => (string) Settings::secret('m365_refresh_token'), 'scope' => self::DELEGATED_SCOPES] + self::clientAuth();
        }
        $r = $this->tokenRequest($form);
        if ($this->mode === 'delegated' && is_string($r['refresh_token'] ?? null) && $r['refresh_token'] !== '' && Settings::hasSecret('m365_refresh_token')) {
            Settings::setSecret('m365_refresh_token', $r['refresh_token']); // Microsoft rotates refresh tokens
        }
        $this->token = (string) $r['access_token'];
        $this->tokenExp = time() + (int) ($r['expires_in'] ?? 3600);
        Settings::setSecret('m365_token_cache', json_encode(['mode' => $this->mode, 'token' => $this->token, 'exp' => $this->tokenExp]));
        return $this->token;
    }

    /** Client secret, or a signed certificate assertion when the app uses a certificate. */
    private static function clientAuth(): array
    {
        if (Settings::get('m365_auth', 'secret') === 'certificate' && Settings::hasSecret('m365_key_pem')) {
            return ['client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => self::assertion()];
        }
        return ['client_secret' => (string) Settings::secret('m365_client_secret')];
    }

    /** RS256 client assertion (RFC 7523) signed with the app's certificate key. */
    public static function assertion(): string
    {
        $cert = (string) Settings::secret('m365_cert_pem');
        $key = openssl_pkey_get_private((string) Settings::secret('m365_key_pem'));
        if (!$key || !preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $cert, $m)) {
            throw new \RuntimeException('The certificate or private key is not valid PEM.');
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '');
        $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $clientId = trim((string) Settings::get('m365_client_id'));
        $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'x5t' => $b64(sha1($der, true)), 'x5t#S256' => $b64(hash('sha256', $der, true))]));
        $body = $b64(json_encode(['aud' => self::tokenUrl(), 'iss' => $clientId, 'sub' => $clientId, 'jti' => bin2hex(random_bytes(16)),
            'nbf' => $now - 60, 'iat' => $now - 60, 'exp' => $now + 540]));
        if (!openssl_sign("$head.$body", $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign with the certificate key.');
        }
        return "$head.$body." . $b64($sig);
    }

    /**
     * POSTs to the token endpoint. $form holds the client secret or assertion. Returns the decoded answer, which
     * has a non-empty string access_token; throws with friendly() text otherwise.
     */
    private function tokenRequest(array $form): array
    {
        try {
            // repeatable unless it spends a one-time authorization code
            $r = $this->http->request('POST', self::tokenUrl(), ['Accept' => 'application/json'], $form, ($form['grant_type'] ?? '') !== 'authorization_code');
        } catch (HttpException $e) {
            throw new \RuntimeException(self::friendly($e));
        }
        if (!is_array($r['json']) || !is_string($r['json']['access_token'] ?? null) || $r['json']['access_token'] === '') {
            throw new \RuntimeException('Microsoft did not return an access token.');
        }
        return $r['json'];
    }

    // ---- Delegated sign-in (authorization code + PKCE) ----------------------------------------

    /**
     * Starts the sign-in: 128-bit state and a 384-bit PKCE verifier (S256). The caller keeps state and verifier in
     * the admin's session and checks the state on return (EmailController::callback()).
     * @return array{0:string,1:string,2:string} [authorize URL, state, PKCE verifier]
     */
    public static function authorizeUrl(): array
    {
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $q = http_build_query([
            'client_id' => trim((string) Settings::get('m365_client_id')),
            'response_type' => 'code',
            'redirect_uri' => self::redirectUri(),
            'response_mode' => 'query',
            'scope' => self::DELEGATED_SCOPES,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
        return [self::loginBase() . '/' . self::tenant() . '/oauth2/v2.0/authorize?' . $q, $state, $verifier];
    }

    /**
     * Exchanges the code for tokens, stores the refresh token and returns the signed-in account.
     * Security: only after the caller matched the state to this session; $code is from the redirect (untrusted)
     * and only works with our PKCE verifier and client credentials.
     */
    public static function completeSignIn(string $code, string $verifier): array
    {
        $g = new self('delegated');
        $r = $g->tokenRequest(['client_id' => trim((string) Settings::get('m365_client_id')), 'grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => self::redirectUri(), 'code_verifier' => $verifier, 'scope' => self::DELEGATED_SCOPES] + self::clientAuth());
        if (empty($r['refresh_token'])) {
            throw new \RuntimeException('Microsoft did not return a refresh token (the offline_access permission is needed).');
        }
        $g->token = (string) $r['access_token'];
        $g->tokenExp = time() + (int) ($r['expires_in'] ?? 3600);
        $me = $g->call('GET', '/me?$select=displayName,mail,userPrincipalName');
        Settings::setSecret('m365_refresh_token', (string) $r['refresh_token']);
        Settings::setSecret('m365_token_cache', json_encode(['mode' => 'delegated', 'token' => $g->token, 'exp' => $g->tokenExp]));
        $str = fn($v) => is_string($v) ? mb_substr($v, 0, 255) : ''; // remote values: a string or nothing
        $addr = $str($me['mail'] ?? null) ?: $str($me['userPrincipalName'] ?? null);
        Settings::set('m365_connected_as', $addr);
        Settings::set('m365_connected_name', $str($me['displayName'] ?? null));
        Settings::set('m365_connected_at', date('Y-m-d H:i:s'));
        return ['address' => $addr, 'name' => $str($me['displayName'] ?? null)];
    }

    /** Forgets the refresh token, cached token and connected account (Microsoft keeps its consent record). Admins only. */
    public static function disconnect(): void
    {
        foreach (['m365_refresh_token', 'm365_token_cache'] as $k) {
            Settings::clearSecret($k);
        }
        foreach (['m365_connected_as', 'm365_connected_name', 'm365_connected_at'] as $k) {
            Settings::set($k, null);
        }
    }

    // ---- Graph calls --------------------------------------------------------------------------

    /**
     * One Graph request. $path is built by this class from encoded parts. Returns the JSON object or null; throws
     * GraphException (code = HTTP status) with friendly() text. A 401 clears the cached token.
     */
    public function call(string $method, string $path, ?array $body = null): ?array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $r = $this->http->request($method, self::graphBase() . $path, $headers, $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
        } catch (HttpException $e) {
            if ($e->status === 401) {
                Settings::clearSecret('m365_token_cache');
            }
            throw new GraphException(self::friendly($e), $e->status);
        }
        return is_array($r['json']) ? $r['json'] : null;
    }

    /**
     * Sends one message. $to/$cc: list of ['address' => , 'name' => ]; $attachments: list of
     * ['name', 'type', 'content' (raw bytes), 'inline_id' (optional contentId for cid: images)].
     * Security: everything goes as JSON, so names and the subject can't add headers. The caller (the queue) has
     * already limited the recipients.
     */
    public function sendMail(array $to, string $subject, string $html, array $cc = [], array $attachments = [], ?string $replyTo = null): void
    {
        $rcpt = fn(array $list) => array_map(fn($r) => ['emailAddress' => array_filter(['address' => $r['address'], 'name' => $r['name'] ?? null])], $list);
        $msg = [
            'subject' => mb_substr($subject, 0, 255),
            'body' => ['contentType' => 'HTML', 'content' => $html],
            'toRecipients' => $rcpt($to),
        ];
        if ($cc) {
            $msg['ccRecipients'] = $rcpt($cc);
        }
        $replyTo ??= (string) Settings::get('mail_reply_to') ?: null;
        if ($replyTo) {
            $msg['replyTo'] = $rcpt([['address' => $replyTo]]);
        }
        $from = trim((string) Settings::get('mail_from'));
        $fromName = trim((string) Settings::get('mail_from_name'));
        if ($from !== '' && $fromName !== '') {
            $msg['from'] = ['emailAddress' => ['address' => $from, 'name' => $fromName]];
        }
        foreach ($attachments as $a) {
            $att = ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $a['name'], 'contentType' => $a['type'], 'contentBytes' => base64_encode($a['content'])];
            if (!empty($a['inline_id'])) {
                $att += ['isInline' => true, 'contentId' => $a['inline_id']];
            }
            $msg['attachments'][] = $att;
        }
        $this->call('POST', $this->mailboxPath() . '/sendMail', ['message' => $msg, 'saveToSentItems' => Settings::get('mail_save_sent', '1') === '1']);
    }

    /** Creates an Outlook calendar event; Exchange sends the invitations. Returns the event (id, onlineMeeting.joinUrl): remote data. */
    public function createEvent(string $mailboxPath, array $event): array
    {
        return $this->call('POST', $mailboxPath . '/events', $event) ?? [];
    }

    /** Changes an event; $id (stored from Graph) is URL-encoded. Returns the event (remote data). */
    public function updateEvent(string $mailboxPath, string $id, array $event): array
    {
        return $this->call('PATCH', $mailboxPath . '/events/' . rawurlencode($id), $event) ?? [];
    }

    /** Cancels an event; Exchange tells the attendees. */
    public function cancelEvent(string $mailboxPath, string $id, string $comment = ''): void
    {
        $this->call('POST', $mailboxPath . '/events/' . rawurlencode($id) . '/cancel', ['comment' => $comment]);
    }

    // ---- Meeting invitations (same shape as Google::calendar*) ---------------------------------

    /** The calendar's name, for messages. */
    public function calendarLabel(): string
    {
        return 'Outlook';
    }

    /** The online-meeting service's name, for messages. */
    public function meetingLabel(): string
    {
        return 'Teams';
    }

    /** The Graph event for an invitation ($i from Invites::viaCalendar(); its html is already escaped). Times in UTC. */
    private function eventBody(array $i, array $to): array
    {
        $ev = [
            'subject' => $i['subject'],
            'body' => ['contentType' => 'HTML', 'content' => $i['html']],
            'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s', strtotime($i['start'])), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s', strtotime($i['end'])), 'timeZone' => 'UTC'],
            'attendees' => array_map(fn($r) => ['emailAddress' => array_filter(['address' => $r['address'], 'name' => $r['name']]), 'type' => 'required'], $to),
        ];
        if ($i['location']) {
            $ev['location'] = ['displayName' => $i['location']];
        }
        if ($i['online']) {
            $ev += ['isOnlineMeeting' => true, 'onlineMeetingProvider' => 'teamsForBusiness'];
        }
        return $ev;
    }

    /**
     * Creates the meeting in $organizer's calendar (null = the sending mailbox). Exchange sends the invitations.
     * Security: $organizer is the meeting owner's staff email (Invites decides; never from a client). The event id
     * and join link from the answer are kept only when they are a string and an https:// link.
     */
    public function calendarCreate(?string $organizer, array $info, array $to): array
    {
        $path = $organizer ? '/users/' . rawurlencode($organizer) : $this->mailboxPath();
        $r = $this->createEvent($path, $this->eventBody($info, $to) + ['transactionId' => $info['uid']]);
        return ['id' => self::str($r['id'] ?? null), 'mailbox' => $path, 'join' => self::joinUrl($r)];
    }

    /** Updates the event in the mailbox it was made in ($mailbox as stored by calendarCreate). */
    public function calendarUpdate(string $mailbox, string $id, array $info, array $to): array
    {
        $r = $this->updateEvent($mailbox ?: $this->mailboxPath(), $id, $this->eventBody($info, $to));
        return ['id' => $id, 'mailbox' => $mailbox, 'join' => self::joinUrl($r)];
    }

    /** Cancels the event in the mailbox it was made in. */
    public function calendarCancel(string $mailbox, string $id, string $comment): void
    {
        $this->cancelEvent($mailbox ?: $this->mailboxPath(), $id, $comment);
    }

    /** A non-empty string of at most 1000 characters from a remote value, else null. */
    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? mb_substr($v, 0, 1000) : null;
    }

    /** The Teams link from an event, only when it is an https:// address (it ends up in emails and pages as a link). */
    private static function joinUrl(array $r): ?string
    {
        $u = is_array($r['onlineMeeting'] ?? null) ? self::str($r['onlineMeeting']['joinUrl'] ?? null) : null;
        return $u !== null && preg_match('#^https://#i', $u) ? $u : null;
    }

    /**
     * Turns Microsoft's error responses into something an admin can act on. The response body is remote data: only
     * its error code and description are used (description cut to 220 characters, trace ids dropped).
     */
    public static function friendly(HttpException $e): string
    {
        $j = json_decode($e->body, true) ?: [];
        $desc = (string) ($j['error_description'] ?? ($j['error']['message'] ?? ''));
        $code = is_string($j['error'] ?? null) ? $j['error'] : (string) ($j['error']['code'] ?? '');
        $aad = preg_match('/AADSTS(\d+)/', $desc, $m) ? (int) $m[1] : 0;
        $hint = match (true) {
            $aad === 7000215 => 'The client secret is wrong. Copy the secret Value (not the Secret ID) from Entra ID → App registrations → Certificates & secrets.',
            $aad === 7000222 => 'The client secret has expired. Create a new one in Entra ID and paste it under Integrations → Email.',
            $aad === 700016 => 'No app with this Application (client) ID exists in this tenant. Check the client ID and tenant.',
            in_array($aad, [90002, 900023, 90013], true) => 'The tenant was not found. Use the Directory (tenant) ID or your domain, e.g. contoso.onmicrosoft.com.',
            in_array($aad, [700027, 700024, 50012], true) => 'The certificate was rejected. Upload the same certificate (.cer) to the app registration and check the private key matches.',
            in_array($aad, [65001, 65004], true) => 'Permissions have not been consented. In Entra ID grant admin consent for the app\'s API permissions.',
            $aad === 50011 => 'The redirect URI does not match. Add ' . self::redirectUri() . ' as a Web redirect URI on the app registration.',
            in_array($aad, [70008, 700082, 50173, 50076, 50079], true) || $code === 'invalid_grant' => 'The Microsoft 365 connection has expired or needs to sign in again. Click Connect with Microsoft.',
            in_array($code, ['ErrorAccessDenied', 'Authorization_RequestDenied', 'AccessDenied'], true) || $e->status === 403 => 'Access denied. Check the app has Mail.Send (and Calendars.ReadWrite for invitations) with admin consent, and that its RBAC scope includes this mailbox.',
            in_array($code, ['ErrorInvalidUser', 'MailboxNotEnabledForRESTAPI', 'ResourceNotFound', 'ErrorNonExistentMailbox'], true) || $e->status === 404 => 'Mailbox not found. Check the From address is an Exchange Online mailbox (user or shared) in this tenant.',
            $code === 'ErrorSendAsDenied' => 'The signed-in account is not allowed to send as this mailbox. Give it Send As permission in Exchange.',
            $e->status === 429 => 'Microsoft is throttling requests. Mail will retry automatically.',
            default => '',
        };
        $detail = trim(preg_replace('/\s*Trace ID:.*$/s', '', $desc) ?? $desc);
        return trim(($hint ?: 'Microsoft 365 returned an error') . ($detail !== '' ? ' (' . mb_strimwidth($detail, 0, 220, '…') . ')' : " (HTTP {$e->status})"));
    }
}
