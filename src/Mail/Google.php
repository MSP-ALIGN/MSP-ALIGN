<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * Google Workspace: sends through the Gmail API and makes meeting invitations with Google Calendar.
 *
 * Two ways to sign in (Integrations → Email):
 *  - app:       a Google Cloud service account with domain-wide delegation. A Workspace super admin
 *               authorizes its client ID for the gmail.send and calendar.events scopes; Align then signs
 *               a JWT (RS256) to act as the sending mailbox (or the meeting owner for invitations).
 *  - delegated: an OAuth web client; an admin clicks "Connect with Google" and signs in as the sending
 *               mailbox (authorization code + PKCE, offline access). The refresh token is stored encrypted.
 */
final class Google
{
    public const SCOPES = ['https://www.googleapis.com/auth/gmail.send', 'https://www.googleapis.com/auth/calendar.events'];

    private HttpClient $http;
    /** @var array<string, array{0:string,1:int}> access token per impersonated user ('' = delegated) */
    private array $tokens = [];

    public function __construct(private string $mode)
    {
        $this->http = new HttpClient(30, 3);
    }

    public static function ready(): bool
    {
        $mode = Mail::mode();
        if ($mode === 'app') {
            return (string) Settings::get('mail_from') !== '' && self::serviceAccount() !== null;
        }
        if ($mode === 'delegated') {
            return (string) Settings::get('g_client_id') !== '' && Settings::hasSecret('g_client_secret') && Settings::hasSecret('g_refresh_token');
        }
        return false;
    }

    /** The service account key (JSON downloaded from Google Cloud), or null if missing/invalid. */
    public static function serviceAccount(): ?array
    {
        $j = json_decode((string) Settings::secret('g_sa_json'), true);
        return is_array($j) && ($j['type'] ?? '') === 'service_account' && !empty($j['client_email']) && !empty($j['private_key']) ? $j : null;
    }

    /** Checks a pasted key file; returns an error message or null. */
    public static function validateServiceAccount(string $json): ?string
    {
        $j = json_decode($json, true);
        if (!is_array($j) || ($j['type'] ?? '') !== 'service_account') {
            return 'Paste the whole JSON key file for the service account (it contains "type": "service_account").';
        }
        if (empty($j['client_email']) || empty($j['client_id']) || empty($j['private_key']) || !@openssl_pkey_get_private((string) $j['private_key'])) {
            return 'That key file is missing its client email, client ID or private key.';
        }
        return null;
    }

    // ---- Endpoints (overridable for testing) -----------------------------------------------------

    private static function base(string $key, string $default): string
    {
        return rtrim((string) (Settings::get($key) ?: $default), '/');
    }

    private static function tokenUrl(): string
    {
        return self::base('g_token_url', 'https://oauth2.googleapis.com/token');
    }

    // ---- Tokens -------------------------------------------------------------------------------

    /** Access token acting as $user (service account) or the connected account (delegated). */
    private function token(?string $user = null): string
    {
        $key = $this->mode === 'app' ? strtolower($user ?: trim((string) Settings::get('mail_from'))) : '';
        if (isset($this->tokens[$key]) && time() < $this->tokens[$key][1] - 120) {
            return $this->tokens[$key][0];
        }
        $cache = json_decode((string) Settings::secret('g_token_cache'), true) ?: [];
        $ck = $this->mode . ':' . $key;
        if (($cache[$ck]['exp'] ?? 0) > time() + 120) {
            $this->tokens[$key] = [$cache[$ck]['token'], (int) $cache[$ck]['exp']];
            return $cache[$ck]['token'];
        }
        if ($this->mode === 'app') {
            $sa = self::serviceAccount() ?? throw new GraphException('The service account key is missing.', 401);
            $form = ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => self::jwt($sa, $key)];
        } else {
            $form = ['grant_type' => 'refresh_token', 'refresh_token' => (string) Settings::secret('g_refresh_token'),
                'client_id' => trim((string) Settings::get('g_client_id')), 'client_secret' => (string) Settings::secret('g_client_secret')];
        }
        $r = $this->tokenRequest($form, $key);
        if (!empty($r['refresh_token']) && $this->mode === 'delegated') {
            Settings::setSecret('g_refresh_token', $r['refresh_token']);
        }
        $exp = time() + (int) ($r['expires_in'] ?? 3600);
        $this->tokens[$key] = [(string) $r['access_token'], $exp];
        $cache = array_filter($cache, fn($c) => ($c['exp'] ?? 0) > time()); // drop expired
        $cache[$ck] = ['token' => (string) $r['access_token'], 'exp' => $exp];
        Settings::setSecret('g_token_cache', json_encode($cache));
        return (string) $r['access_token'];
    }

    /** RS256 JWT for the service account, impersonating $sub (domain-wide delegation). */
    public static function jwt(array $sa, string $sub): string
    {
        $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $head = $b64(json_encode(array_filter(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $sa['private_key_id'] ?? null])));
        $claims = $b64(json_encode(['iss' => $sa['client_email'], 'sub' => $sub, 'scope' => implode(' ', self::SCOPES),
            'aud' => $sa['token_uri'] ?? self::tokenUrl(), 'iat' => $now - 30, 'exp' => $now + 3300]));
        $key = openssl_pkey_get_private((string) $sa['private_key']);
        if (!$key || !openssl_sign("$head.$claims", $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new GraphException('Could not sign with the service account key.', 401);
        }
        return "$head.$claims." . $b64($sig);
    }

    private function tokenRequest(array $form, string $who = ''): array
    {
        try {
            $r = $this->http->request('POST', self::tokenUrl(), ['Accept' => 'application/json'], $form);
        } catch (HttpException $e) {
            throw new GraphException(self::friendly($e, $who), $e->status === 400 ? 401 : $e->status);
        }
        if (empty($r['json']['access_token'])) {
            throw new GraphException('Google did not return an access token.', 401);
        }
        return $r['json'];
    }

    // ---- Connect with Google (authorization code + PKCE) -------------------------------------------

    /** @return array{0:string,1:string,2:string} [authorize URL, state, PKCE verifier] */
    public static function authorizeUrl(): array
    {
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $q = http_build_query([
            'client_id' => trim((string) Settings::get('g_client_id')),
            'redirect_uri' => Mail::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile ' . implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent select_account', // always returns a refresh token
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        return [self::base('g_auth_url', 'https://accounts.google.com/o/oauth2/v2/auth') . '?' . $q, $state, $verifier];
    }

    public static function completeSignIn(string $code, string $verifier): array
    {
        $g = new self('delegated');
        $r = $g->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => Mail::redirectUri(), 'code_verifier' => $verifier,
            'client_id' => trim((string) Settings::get('g_client_id')), 'client_secret' => (string) Settings::secret('g_client_secret')]);
        if (empty($r['refresh_token'])) {
            throw new \RuntimeException('Google did not return a refresh token. Remove Align\'s access at myaccount.google.com → Security → Third-party access, then connect again.');
        }
        $granted = explode(' ', (string) ($r['scope'] ?? implode(' ', self::SCOPES)));
        if (!in_array(self::SCOPES[0], $granted, true)) {
            throw new \RuntimeException('Permission to send email was not granted. Connect again and tick "Send email on your behalf".');
        }
        // The ID token comes straight from Google's token endpoint over TLS, so its claims can be read directly
        $claims = json_decode(base64_decode(strtr(explode('.', (string) ($r['id_token'] ?? '') . '..')[1], '-_', '+/')) ?: '', true) ?: [];
        $addr = strtolower((string) ($claims['email'] ?? ''));
        Settings::setSecret('g_refresh_token', $r['refresh_token']);
        Settings::setSecret('g_token_cache', json_encode(['delegated:' => ['token' => $r['access_token'], 'exp' => time() + (int) ($r['expires_in'] ?? 3600)]]));
        Settings::set('g_connected_as', $addr);
        Settings::set('g_connected_name', (string) ($claims['name'] ?? ''));
        Settings::set('g_connected_at', date('Y-m-d H:i:s'));
        Settings::set('g_calendar_granted', in_array(self::SCOPES[1], $granted, true) ? '1' : '0');
        return ['address' => $addr, 'name' => (string) ($claims['name'] ?? '')];
    }

    public static function disconnect(): void
    {
        $rt = Settings::secret('g_refresh_token');
        if ($rt && !\Align\Staging::on()) { // on a test server the token is production's: revoking it would cut production off
            try { // tell Google too, so the grant disappears from the account's third-party access list
                (new HttpClient(10, 1))->request('POST', self::base('g_revoke_url', 'https://oauth2.googleapis.com/revoke'), [], ['token' => $rt]);
            } catch (\Throwable) {
            }
        }
        foreach (['g_refresh_token', 'g_token_cache'] as $k) {
            Settings::clearSecret($k);
        }
        foreach (['g_connected_as', 'g_connected_name', 'g_connected_at', 'g_calendar_granted'] as $k) {
            Settings::set($k, null);
        }
    }

    // ---- API calls ------------------------------------------------------------------------------

    private function call(string $method, string $url, ?array $body = null, ?string $as = null): ?array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token($as), 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $r = $this->http->request($method, $url, $headers, $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
        } catch (HttpException $e) {
            if ($e->status === 401) {
                Settings::clearSecret('g_token_cache');
            }
            throw new GraphException(self::friendly($e, (string) $as), $e->status);
        }
        return is_array($r['json']) ? $r['json'] : null;
    }

    /** Same signature as Graph::sendMail. Builds an RFC 5322 message and sends it with the Gmail API. */
    public function sendMail(array $to, string $subject, string $html, array $cc = [], array $attachments = [], ?string $replyTo = null): void
    {
        $from = Mail::fromAddress();
        $raw = self::mime($from, trim((string) Settings::get('mail_from_name')), $to, $cc, $replyTo ?? ((string) Settings::get('mail_reply_to') ?: null), $subject, $html, $attachments);
        $this->call('POST', self::base('g_gmail_base', 'https://gmail.googleapis.com') . '/gmail/v1/users/me/messages/send',
            ['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')]);
    }

    /** RFC 5322 / MIME message (see Mime::build). */
    public static function mime(string $from, string $fromName, array $to, array $cc, ?string $replyTo, string $subject, string $html, array $attachments): string
    {
        return Mime::build($from, $fromName, $to, $cc, $replyTo, $subject, $html, $attachments);
    }

    // ---- Meeting invitations (same shape as Graph::calendar*) ----------------------------------

    public function calendarLabel(): string
    {
        return 'Google Calendar';
    }

    public function meetingLabel(): string
    {
        return 'Google Meet';
    }

    private static function calBase(): string
    {
        return self::base('g_calendar_base', 'https://www.googleapis.com/calendar/v3');
    }

    private function eventBody(array $i, array $to): array
    {
        $ev = [
            'summary' => $i['subject'],
            'description' => $i['text'],
            'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime($i['start'])), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', strtotime($i['end'])), 'timeZone' => 'UTC'],
            'attendees' => array_map(fn($r) => array_filter(['email' => $r['address'], 'displayName' => $r['name'] ?: null]), $to),
            'guestsCanModify' => false,
        ];
        if ($i['location']) {
            $ev['location'] = $i['location'];
        }
        return $ev;
    }

    /** Creates the event in $organizer's calendar (null = the sending mailbox); Google emails the guests. */
    public function calendarCreate(?string $organizer, array $info, array $to): array
    {
        $as = $this->mode === 'app' ? ($organizer ?: trim((string) Settings::get('mail_from'))) : null;
        if ($this->mode === 'delegated' && $organizer) {
            throw new GraphException('Only the connected account can organize meetings.', 403);
        }
        $body = $this->eventBody($info, $to);
        if ($info['online']) {
            $body['conferenceData'] = ['createRequest' => ['requestId' => substr(hash('sha256', $info['uid'] . microtime()), 0, 32), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
        }
        $r = $this->call('POST', self::calBase() . '/calendars/primary/events?sendUpdates=all&conferenceDataVersion=1', $body, $as) ?? [];
        return ['id' => $r['id'] ?? null, 'mailbox' => 'google:' . ($as ?? ''), 'join' => $r['hangoutLink'] ?? null];
    }

    public function calendarUpdate(string $mailbox, string $id, array $info, array $to): array
    {
        $as = $this->mode === 'app' && $mailbox !== '' ? $mailbox : null;
        $r = $this->call('PATCH', self::calBase() . '/calendars/primary/events/' . rawurlencode($id) . '?sendUpdates=all&conferenceDataVersion=1', $this->eventBody($info, $to), $as) ?? [];
        return ['id' => $id, 'mailbox' => 'google:' . $mailbox, 'join' => $r['hangoutLink'] ?? null];
    }

    public function calendarCancel(string $mailbox, string $id, string $comment): void
    {
        $this->call('DELETE', self::calBase() . '/calendars/primary/events/' . rawurlencode($id) . '?sendUpdates=all', null, $this->mode === 'app' && $mailbox !== '' ? $mailbox : null);
    }

    /** Google's error responses in words an admin can act on. */
    public static function friendly(HttpException $e, string $who = ''): string
    {
        $j = json_decode($e->body, true) ?: [];
        $err = is_string($j['error'] ?? null) ? $j['error'] : '';
        $desc = (string) ($j['error_description'] ?? ($j['error']['message'] ?? ''));
        $reason = (string) ($j['error']['errors'][0]['reason'] ?? ($j['error']['status'] ?? ''));
        $sa = self::serviceAccount();
        $hint = match (true) {
            $err === 'unauthorized_client' => 'Domain-wide delegation is not set up for this service account. In the Google Admin console → Security → API controls → Domain-wide delegation, add client ID ' . ($sa['client_id'] ?? '(from the key file)') . ' with the scopes ' . implode(',', self::SCOPES) . '. It can take a few minutes to apply.',
            $err === 'invalid_grant' && str_contains($desc, 'Invalid email') => 'Google doesn\'t recognise ' . ($who ?: 'the From address') . ' as a user in your Workspace. Use a real user mailbox (service accounts can\'t send as groups or aliases).',
            $err === 'invalid_grant' && Mail::mode() === 'delegated' => 'The Google connection has expired or was removed. Click Connect with Google again. (If the OAuth consent screen is in "Testing", tokens expire after 7 days: set it to Internal.)',
            $err === 'invalid_grant' => 'Google rejected the service account sign-in. Check the server clock is correct and the key hasn\'t been deleted in Google Cloud.',
            $err === 'invalid_client' => 'The OAuth client ID or secret is wrong. Copy them again from Google Cloud → APIs & Services → Credentials.',
            $err === 'access_denied' || $err === 'invalid_scope' => 'The requested permissions weren\'t allowed. Authorize these scopes: ' . implode(', ', self::SCOPES) . '.',
            in_array($reason, ['accessNotConfigured', 'SERVICE_DISABLED'], true) || str_contains($desc, 'has not been used in project') || str_contains($desc, 'is disabled') => 'The ' . (str_contains($desc, 'Calendar') ? 'Google Calendar' : 'Gmail') . ' API is not enabled in your Google Cloud project. Enable it under APIs & Services → Library.',
            in_array($reason, ['insufficientPermissions', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'], true) => 'Align is missing a permission (scope). Add ' . implode(' and ', self::SCOPES) . ' and connect or authorize again.',
            $reason === 'failedPrecondition' || str_contains($desc, 'Mail service not enabled') => 'Gmail is not turned on for ' . ($who ?: 'this mailbox') . '. Check its Workspace license and that Gmail is enabled for its organizational unit.',
            str_contains($desc, 'Invalid From header') || str_contains($desc, 'send-as') => 'Gmail won\'t send as that From address. Add it as a verified "Send mail as" alias of the connected account, or leave From empty.',
            $e->status === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true) => 'Google is rate-limiting requests. Mail will retry automatically.',
            $e->status === 404 => 'Not found in Google Calendar (the event may have been deleted there).',
            default => '',
        };
        $detail = trim($desc);
        return trim(($hint ?: 'Google returned an error') . ($detail !== '' ? ' (' . mb_strimwidth($detail, 0, 220, '…') . ')' : " (HTTP {$e->status})"));
    }
}
