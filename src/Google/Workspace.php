<?php
declare(strict_types=1);

namespace Align\Google;

use Align\Config;
use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * 2.6.3 Google Workspace for clients: the service account Align signs in with, and its calls to Google's APIs.
 *
 * Google has no "approve this app" page for outside apps like Microsoft's. Instead a client's super admin allows a
 * service account to act in their domain (Admin console → Security → Access and data control → API controls →
 * Domain-wide delegation) for a fixed list of scopes, and Align then signs in as one of their admin accounts (the
 * JWT's "sub"). The MSP's own service account (its JSON key, saved once under Integrations) serves every client; a
 * client that won't allow an outside service account can make its own, whose key is kept on that client only.
 *
 * Every scope is read-only except Enterprise License Manager's, for which Google offers no read-only scope: Align
 * only ever sends GET requests (get() is the only call, and it sends GET).
 *
 * Security assumptions: keys are secrets (Settings::setSecret for the MSP's, Crypto on the client row for an own
 * key) and never shown back; only their client_email and client_id are. Domains and admin emails are checked by the
 * caller (Clients). Every request goes to Google's fixed hosts over HTTPS through HttpClient; the test overrides
 * (gwc_token_url, gwc_api_base) apply only with allow_insecure_integrations, like the Microsoft 365 ones. Answers are
 * remote data: callers reduce them to counts, booleans and cleaned names.
 */
final class Workspace
{
    /** What a client's super admin allows for the service account (domain-wide delegation), in this order. */
    public const SCOPES = [
        'https://www.googleapis.com/auth/admin.directory.customer.readonly',      // the organization's name and id
        'https://www.googleapis.com/auth/admin.directory.user.readonly',          // users: 2-Step Verification, admins, last sign-in
        'https://www.googleapis.com/auth/apps.licensing',                         // editions and seats (Google has no read-only scope)
        'https://www.googleapis.com/auth/cloud-identity.policies.readonly',       // sharing, third-party apps, less secure apps
    ];

    /** Google's hosts, by what they serve. */
    private const HOSTS = ['directory' => 'admin.googleapis.com', 'licensing' => 'licensing.googleapis.com', 'policy' => 'cloudidentity.googleapis.com'];

    /** Access tokens for this process: "client_email|sub" => [token, expires]. */
    private static array $tokens = [];

    // ---- The MSP's service account --------------------------------------------------------------

    /** The MSP's service account key (decoded), or null when none is saved. */
    public static function serviceAccount(): ?array
    {
        return self::parseKey((string) Settings::secret('gwc_sa_json'));
    }

    /** Whether the MSP's service account is saved (clients can connect with it). */
    public static function ready(): bool
    {
        return self::serviceAccount() !== null;
    }

    /**
     * A key file (JSON from Google Cloud → IAM → Service accounts → Keys) decoded, or null when it isn't a usable
     * service account key: type service_account, an email, a numeric client id and a private key OpenSSL can read.
     */
    public static function parseKey(string $json): ?array
    {
        $j = json_decode($json, true);
        if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || !is_string($j['client_email'] ?? null) || !filter_var($j['client_email'], FILTER_VALIDATE_EMAIL)
            || !is_string($j['client_id'] ?? null) || !preg_match('/^\d{5,30}$/', $j['client_id']) || !is_string($j['private_key'] ?? null) || !@openssl_pkey_get_private($j['private_key'])) {
            return null;
        }
        return ['client_email' => $j['client_email'], 'client_id' => $j['client_id'], 'private_key' => $j['private_key'], 'private_key_id' => is_string($j['private_key_id'] ?? null) ? $j['private_key_id'] : null];
    }

    /** Saves the MSP's key (already checked by parseKey()). */
    public static function saveKey(string $json): void
    {
        Settings::setSecret('gwc_sa_json', $json);
        Settings::set('gwc_saved_at', date('Y-m-d H:i:s'));
    }

    /** Removes the MSP's key (clients connected with it stop syncing until a key is saved again). */
    public static function forgetKey(): void
    {
        Settings::clearSecret('gwc_sa_json');
        Settings::set('gwc_saved_at', null);
    }

    /** What the integration page shows about the MSP's key: its email and client id (never the key), when saved. */
    public static function info(): ?array
    {
        $sa = self::serviceAccount();
        return $sa ? ['client_email' => $sa['client_email'], 'client_id' => $sa['client_id'], 'saved_at' => Settings::get('gwc_saved_at')] : null;
    }

    // ---- Calls --------------------------------------------------------------------------------

    /** The token endpoint (Google's; the test override only with allow_insecure_integrations). */
    private static function tokenUrl(): string
    {
        $o = Config::get('allow_insecure_integrations', false) ? Settings::get('gwc_token_url') : null;
        return rtrim((string) ($o ?: 'https://oauth2.googleapis.com/token'), '/');
    }

    /** The base address of one of Google's API hosts (HOSTS key); the test override serves them all, by host name. */
    public static function base(string $api): string
    {
        $host = self::HOSTS[$api] ?? throw new \InvalidArgumentException('Unknown Google API.');
        $o = Config::get('allow_insecure_integrations', false) ? Settings::get('gwc_api_base') : null;
        return $o ? rtrim((string) $o, '/') . '/' . $host : 'https://' . $host;
    }

    /**
     * An access token acting as $admin with $sa (the MSP's key, or a client's own), for every scope in SCOPES.
     * Cached for this process. Throws GwsException with words an admin can act on (e.g. delegation not set up).
     */
    public static function token(array $sa, string $admin): string
    {
        $ck = $sa['client_email'] . '|' . strtolower($admin);
        if (isset(self::$tokens[$ck]) && self::$tokens[$ck][1] > time() + 120) {
            return self::$tokens[$ck][0];
        }
        $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $head = $b64((string) json_encode(array_filter(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $sa['private_key_id'] ?? null])));
        $claims = $b64((string) json_encode(['iss' => $sa['client_email'], 'sub' => $admin, 'scope' => implode(' ', self::SCOPES),
            'aud' => self::tokenUrl(), 'iat' => $now - 30, 'exp' => $now + 3300]));
        $key = openssl_pkey_get_private((string) $sa['private_key']);
        if (!$key || !openssl_sign("$head.$claims", $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new GwsException('Couldn\'t sign with the service account key.');
        }
        try {
            $r = (new HttpClient(30, 2))->request('POST', self::tokenUrl(), ['Accept' => 'application/json'],
                ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$claims." . $b64($sig)], true);
        } catch (HttpException $e) {
            throw GwsException::from($e, $sa, $admin);
        }
        $tok = $r['json']['access_token'] ?? null;
        if (!is_string($tok) || $tok === '') {
            throw new GwsException('Google didn\'t return an access token.');
        }
        self::$tokens[$ck] = [$tok, time() + (int) ($r['json']['expires_in'] ?? 3600)];
        return $tok;
    }

    /**
     * One GET on a Google API ($api: HOSTS key; $path from fixed or encoded parts) as $admin. Returns the JSON object
     * (or null). Only GET is ever sent, so the licensing scope (which Google only offers read-write) is used read-only.
     */
    public static function get(array $sa, string $admin, string $api, string $path): ?array
    {
        try {
            $r = (new HttpClient(30, 2))->request('GET', self::base($api) . $path, ['Authorization' => 'Bearer ' . self::token($sa, $admin), 'Accept' => 'application/json'], null, true);
        } catch (HttpException $e) {
            throw GwsException::from($e, $sa, $admin);
        }
        return is_array($r['json'] ?? null) ? $r['json'] : null;
    }

    /**
     * Every item of a paged list ($key: the list's field, e.g. "users"), following nextPageToken on the same path,
     * up to $max pages. Throws when there are more (the caller leaves its check unknown). $pauseMs: wait this long
     * before each further page (the Policy API allows one request a second per customer).
     */
    public static function pages(array $sa, string $admin, string $api, string $path, string $key, int $max = 20, int $pauseMs = 0): array
    {
        $out = [];
        $token = null;
        for ($i = 0; $i < $max; $i++) {
            if ($i > 0 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
            $r = self::get($sa, $admin, $api, $path . ($token !== null ? (str_contains($path, '?') ? '&' : '?') . 'pageToken=' . rawurlencode($token) : ''));
            foreach ((array) ($r[$key] ?? []) as $item) {
                if (is_array($item)) {
                    $out[] = $item;
                }
            }
            $token = is_string($r['nextPageToken'] ?? null) && $r['nextPageToken'] !== '' ? $r['nextPageToken'] : null;
            if ($token === null) {
                return $out;
            }
        }
        throw new GwsException('Too many entries to read.');
    }
}
