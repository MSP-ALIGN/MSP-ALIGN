<?php
declare(strict_types=1);

namespace Align\M365;

use Align\Http\HttpClient;
use Align\Http\HttpException;

/**
 * 2.23.0 What Align's two self-made Entra apps share: the Microsoft 365 (clients) app (App) and the email and
 * calendars app (Mail\M365Setup). The device code sign-in an admin creates an app with (Microsoft's "Microsoft Graph
 * Command Line Tools" public client, so nothing has to exist first), a self-signed certificate whose key never
 * leaves Align, the signed JWTs Entra wants (client assertions, the proof addKey/removeKey need), and plain calls to
 * the login and Graph hosts. Moved here from App unchanged in behaviour.
 *
 * Security assumptions: callers pass fixed Microsoft hosts (or a test install's mock); keys and certificates come
 * from encrypted settings and are only used to sign locally. Everything Microsoft returns is remote data: callers
 * check types and shapes. Errors become M365Exception, which never quotes a token, secret or request body.
 */
final class Entra
{
    /** Microsoft Graph Command Line Tools: Microsoft's public client for admins' own tools, used for setup sign-ins. */
    public const SETUP_CLIENT = '14d82eec-204b-4c2f-b7e8-296a70dab67e';
    /** Microsoft Graph's own application id. */
    public const GRAPH_APP = '00000003-0000-0000-c000-000000000000';
    /** Application.ReadWrite.OwnedBy (Graph app role): an app may change its own registration (certificate rotation). */
    public const ROLE_OWNED_BY = '18a4783c-866b-4cc7-a460-3d5e5662c884';
    /** GUID shape for ids from Microsoft and from forms. */
    public const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /**
     * Starts a device code sign-in at $loginBase for $scopes: [user_code, verification_uri, expires_in, interval,
     * device_code]. The caller keeps device_code in the admin's session and shows the rest.
     */
    public static function deviceStart(string $loginBase, string $scopes): array
    {
        $r = self::post($loginBase . '/organizations/oauth2/v2.0/devicecode', ['client_id' => self::SETUP_CLIENT, 'scope' => $scopes]);
        foreach (['device_code', 'user_code', 'verification_uri'] as $k) {
            if (!is_string($r[$k] ?? null) || $r[$k] === '') {
                throw new \RuntimeException('Microsoft did not start the sign-in. Try again.');
            }
        }
        return ['user_code' => mb_substr($r['user_code'], 0, 20), 'verification_uri' => self::https($r['verification_uri']),
            'expires_in' => max(60, min(1800, (int) ($r['expires_in'] ?? 900))), 'interval' => max(2, min(30, (int) ($r['interval'] ?? 5))),
            'device_code' => $r['device_code']];
    }

    /**
     * Redeems a device code: ['status' => 'pending' | 'error', 'message'] while the admin hasn't finished (or it
     * failed), or ['status' => 'token', 'token' => the admin's access token] once they have.
     */
    public static function devicePoll(string $loginBase, string $deviceCode): array
    {
        try {
            $r = self::post($loginBase . '/organizations/oauth2/v2.0/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'client_id' => self::SETUP_CLIENT, 'device_code' => $deviceCode], false);
        } catch (M365Exception $e) {
            return match ($e->oauthError) {
                'authorization_pending', 'slow_down' => ['status' => 'pending', 'message' => 'Waiting for you to sign in…'],
                'authorization_declined' => ['status' => 'error', 'message' => 'The sign-in was declined. Start again.'],
                'expired_token', 'bad_verification_code' => ['status' => 'error', 'message' => 'The code expired. Start again.'],
                default => ['status' => 'error', 'message' => $e->getMessage()],
            };
        }
        if (!is_string($r['access_token'] ?? null) || $r['access_token'] === '') {
            return ['status' => 'error', 'message' => 'Microsoft did not return a sign-in. Start again.'];
        }
        return ['status' => 'token', 'token' => $r['access_token']];
    }

    /**
     * A new RSA-2048 key and a self-signed certificate for it named $cn, valid $days days:
     * [private key PEM, certificate PEM, certificate DER as base64 (what Entra takes), expiry Y-m-d].
     */
    public static function makeCertificate(string $cn, int $days): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = $key ? openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']) : false;
        $x509 = $csr ? openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX)) : false;
        if (!$x509 || !openssl_x509_export($x509, $certPem) || !openssl_pkey_export($key, $keyPem)) {
            throw new \RuntimeException('Couldn\'t make a certificate (OpenSSL). Check the server\'s PHP OpenSSL extension.');
        }
        $info = openssl_x509_parse($x509) ?: [];
        $der = preg_replace('/-----[^-]+-----|\s+/', '', $certPem) ?? '';
        return [$keyPem, $certPem, $der, date('Y-m-d', (int) ($info['validTo_time_t'] ?? strtotime("+$days days")))];
    }

    /** RS256 client assertion (RFC 7523) for the token endpoint $aud, signed with an app's certificate key. */
    public static function assertion(string $aud, string $appId, string $keyPem, string $certPem): string
    {
        $now = time();
        return self::signJwt(['aud' => $aud, 'iss' => $appId, 'sub' => $appId, 'jti' => bin2hex(random_bytes(16)), 'nbf' => $now - 60, 'iat' => $now - 60, 'exp' => $now + 540], $keyPem, $certPem);
    }

    /**
     * The proof of possession addKey and removeKey need: a JWT signed with a certificate the app has now, issued by
     * the app's object id for the Azure AD Graph audience, valid exactly ten minutes (as Microsoft requires).
     */
    public static function proof(string $objectId, string $keyPem, string $certPem): string
    {
        $now = time();
        return self::signJwt(['aud' => '00000002-0000-0000-c000-000000000000', 'iss' => $objectId, 'nbf' => $now, 'exp' => $now + 600], $keyPem, $certPem);
    }

    /** A JWT with $claims, signed RS256 with $keyPem; the header names the certificate (x5t, x5t#S256) as Entra expects. */
    public static function signJwt(array $claims, string $keyPem, string $certPem): string
    {
        $key = openssl_pkey_get_private($keyPem);
        if (!$key || !preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $certPem, $m)) {
            throw new M365Exception('The stored certificate or key is not valid. Set the app up again.');
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '');
        $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'x5t' => $b64(sha1($der, true)), 'x5t#S256' => $b64(hash('sha256', $der, true))]));
        $body = $b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        if (!openssl_sign("$head.$body", $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new M365Exception('Could not sign with the certificate key.');
        }
        return "$head.$body." . $b64($sig);
    }

    /** One Graph request at $graphBase with a given token. Returns the JSON object (or null); throws M365Exception. */
    public static function graphWith(string $graphBase, string $token, string $method, string $path, ?array $body = null): ?array
    {
        $headers = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $r = (new HttpClient(30, 2))->request($method, $graphBase . $path, $headers,
                $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null, $method === 'GET');
        } catch (HttpException $e) {
            throw M365Exception::from($e);
        }
        return is_array($r['json'] ?? null) ? $r['json'] : null;
    }

    /** POSTs a form to a Microsoft login endpoint; returns the JSON answer, throws M365Exception (with the OAuth error code) otherwise. */
    public static function post(string $url, array $form, bool $retry = true): array
    {
        try {
            $r = (new HttpClient(30, $retry ? 2 : 1))->request('POST', $url, ['Accept' => 'application/json'], $form, $retry);
        } catch (HttpException $e) {
            throw M365Exception::from($e);
        }
        return is_array($r['json'] ?? null) ? $r['json'] : [];
    }

    /** Runs $fn, trying again (up to 5 times, a few seconds apart) when Entra hasn't caught up with a new object yet. */
    public static function retry(callable $fn): mixed
    {
        for ($i = 0; ; $i++) {
            try {
                return $fn();
            } catch (M365Exception $e) {
                if ($i >= 4 || !in_array($e->status, [400, 404], true)) {
                    throw $e;
                }
                sleep(2 + $i);
            }
        }
    }

    /** $url if it's an https:// address (shown as a link), else Microsoft's usual device login page. */
    public static function https(string $url): string
    {
        return preg_match('#^https://[A-Za-z0-9.-]+(/[A-Za-z0-9._~/-]*)?\z#', $url) ? $url : 'https://microsoft.com/devicelogin';
    }
}
