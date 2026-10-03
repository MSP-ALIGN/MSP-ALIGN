<?php
declare(strict_types=1);

namespace Align\Http;

use Align\Config;

/**
 * Minimal curl wrapper for calls to outside services (ITFlow, NinjaOne, Veeam, Dell, Lenovo, Microsoft, Google),
 * with JSON decoding and retries on 429 / 5xx.
 *
 * Security assumptions: URLs are built by code from admin-entered settings (base URLs, keys), never from a request.
 * The admin chooses the server, but must not be able to reach this server itself or the cloud it runs in, so every
 * URL is checked against the address policy below before it is sent, and the address curl actually connected to is
 * checked again (a DNS name that changes between lookup and connect can't slip through). Responses are untrusted:
 * callers check the type of everything in 'json'.
 *
 * Address policy (2.2.1):
 * - https only; http only with allow_insecure_integrations (a development copy talking to the local mocks).
 * - Never: link-local 169.254.0.0/16 and fe80::/10 (the cloud metadata service 169.254.169.254 lives there), the AWS
 *   IPv6 metadata address fd00:ec2::254, Alibaba's 100.100.100.200, 0.0.0.0/8, ::, multicast and reserved ranges.
 * - Loopback (127.0.0.0/8, ::1, localhost, and a name such as Debian's own host name that resolves there): only with
 *   allow_local_integrations (an ITFlow or Veeam console on the same machine; https still required) or
 *   allow_insecure_integrations (a development copy).
 * - Private ranges (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7) are allowed: an on-premises ITFlow or Veeam
 *   console is normal, and only an admin can set the address. Admins can probe them with Test (accepted).
 * - IPv4 in any spelling curl accepts (2130706433, 0x7f.1, 0177.0.0.1) and IPv4 inside IPv6 (::ffff:a.b.c.d,
 *   64:ff9b::a.b.c.d) is judged as that IPv4 address.
 * - Behind an outbound proxy (http(s)_proxy / all_proxy set, and the host not in no_proxy) curl connects to the
 *   proxy, so the connected-address check is skipped and the name is looked up here instead (IPv4 and IPv6); a name
 *   that changes between that lookup and the proxy's own lookup isn't caught, and the proxy's policy applies.
 * - The server's own LAN address and a Docker host's gateway are private addresses, so they are allowed.
 * Redirects are never followed (a 3xx is an error), TLS 1.2+ with certificate and host name checks is always on,
 * and a header with a line break is refused, so a pasted key can't add headers of its own.
 */
final class HttpClient
{
    /** Largest response accepted (1.45): a broken or hostile server can't fill the memory of a sync that has no memory limit. */
    public const MAX_BYTES = 128 * 1024 * 1024;

    /** Methods retried after a 5xx or a dropped connection: sending them twice can't create a second record or email. */
    private const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    /** curl errors where nothing reached the server, so any method can be retried. */
    private const NOT_SENT = [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR];

    /**
     * $timeout: seconds for one attempt (connect, send and read). $retries: attempts in all (1 = no retry).
     */
    public function __construct(
        private int $timeout = 60,
        private int $retries = 3,
    ) {
    }

    /**
     * Sends one request and returns the status, raw body and decoded JSON (null when the body isn't JSON).
     * An array $body is sent form-encoded. Throws HttpException when the URL or a header is refused, on a
     * connection failure, a 3xx/4xx/5xx status (after retries) or a response over MAX_BYTES. Exception messages
     * name the host only, never the path or query (ITFlow's key is in the query).
     *
     * Retries: 429 always (honoring Retry-After up to 30 seconds); 5xx and dropped connections only for idempotent
     * methods, so a POST that ITFlow or Microsoft may already have acted on isn't sent twice. $retrySafe: the caller
     * knows this POST can be repeated (a client-credentials or refresh token request), so it is retried like a GET.
     *
     * @return array{status:int, body:string, json:mixed}
     */
    public function request(string $method, string $url, array $headers = [], string|array|null $body = null, bool $retrySafe = false): array
    {
        $method = strtoupper($method);
        $insecure = (bool) Config::get('allow_insecure_integrations', false);
        $local = self::loopbackOk();
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $checkConnected = defined('CURLOPT_PREREQFUNCTION') && !self::viaProxy($scheme, strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]')));
        $host = self::check($url, !$checkConnected);

        $hdrs = [];
        foreach ($headers as $k => $v) {
            $k = (string) $k;
            $v = (string) $v;
            if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $k) || preg_match('/[\r\n\0]/', $v)) {
                throw new HttpException("A header for $host has a line break or an invalid name, so nothing was sent. Check the saved keys.");
            }
            $hdrs[] = "$k: $v";
        }
        // Built once, outside the retry loop: a retried form POST still says what it carries
        if (is_array($body)) {
            $body = http_build_query($body);
            $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $blocked = null;
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'MSP-ALIGN/' . APP_VERSION,
            CURLOPT_PROTOCOLS => $insecure ? CURLPROTO_HTTPS | CURLPROTO_HTTP : CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_MAXFILESIZE_LARGE => self::MAX_BYTES,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => fn($c, $downTotal, $down) => $down > self::MAX_BYTES ? 1 : 0, // no Content-Length: stop anyway
        ];
        if ($checkConnected) {
            // Runs after connecting, before anything is sent: judges the address really connected to
            $opts[CURLOPT_PREREQFUNCTION] = function ($c, string $ip) use (&$blocked, $local): int {
                $blocked = self::refusedIp($ip, $local);
                return $blocked === null ? CURL_PREREQFUNC_OK : CURL_PREREQFUNC_ABORT;
            };
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $blocked = null;
            $ch = curl_init();
            if (!curl_setopt_array($ch, $opts)) {
                // a refused option (e.g. the connected-address check) must never mean a request without it
                curl_close($ch);
                throw new HttpException("This server's curl refused a security option, so nothing was sent to $host.");
            }
            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $retryAfter = defined('CURLINFO_RETRY_AFTER') ? (int) curl_getinfo($ch, CURLINFO_RETRY_AFTER) : 0;
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($blocked !== null) {
                throw new HttpException("Align won't connect to $host: it is $blocked.");
            }
            $tooBig = $resp === false && in_array($errno, [CURLE_FILESIZE_EXCEEDED, CURLE_ABORTED_BY_CALLBACK], true);
            $idempotent = $retrySafe || in_array($method, self::IDEMPOTENT, true);
            $retryable = !$tooBig && (
                ($resp === false && ($idempotent || in_array($errno, self::NOT_SENT, true)))
                || $status === 429
                || ($status >= 500 && $idempotent)
            );
            if ($retryable && $attempt < $this->retries) {
                sleep(max(min(30, 2 ** $attempt), min(30, $retryAfter)));
                continue;
            }
            if ($resp === false) {
                throw new HttpException($tooBig
                    ? 'The response from ' . $host . ' was larger than ' . (self::MAX_BYTES >> 20) . ' MB, so it was refused.'
                    : "Connection failed: $err");
            }
            $json = json_decode((string) $resp, true);
            if ($status >= 300) {
                // Redirects aren't followed: one would otherwise read as an empty, "successful" answer
                throw new HttpException("HTTP $status from $host" . ($status < 400 ? ' (a redirect: check the address)' : ''), $status, mb_substr((string) $resp, 0, 1000));
            }
            return ['status' => $status, 'body' => (string) $resp, 'json' => $json];
        }
    }

    /** GET with Accept: application/json; returns the decoded JSON (untrusted: check its type). Throws like request(). */
    public function getJson(string $url, array $headers = []): mixed
    {
        return $this->request('GET', $url, $headers + ['Accept' => 'application/json'])['json'];
    }

    /**
     * Checks a URL against the address policy (see the class comment) without connecting. Returns its host for
     * messages, or throws HttpException with a plain reason that names the host only. $resolve: also look a name
     * up in DNS (IPv4); without it only literal addresses and localhost are judged here and request() judges the
     * connected address. Also used by Connector::save() to refuse an address as it is typed.
     */
    public static function check(string $url, bool $resolve = false): string
    {
        $p = parse_url($url) ?: [];
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower((string) ($p['host'] ?? ''));
        $insecure = (bool) Config::get('allow_insecure_integrations', false);
        $local = self::loopbackOk();
        if ($scheme !== 'https' && !($insecure && $scheme === 'http')) {
            throw new HttpException('Only https:// addresses can be used' . ($host !== '' ? " ($host)" : '') . '.');
        }
        // Spaces, controls and backslashes are read differently by parse_url and curl, and a user name or password
        // in the address would be sent as a login: refuse them instead of guessing which server is meant
        if ($host === '' || isset($p['user']) || isset($p['pass']) || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            throw new HttpException('The address must be a plain https:// address, without spaces, a user name or a password.');
        }
        $name = rtrim(trim($host, '[]'), '.');
        $ips = [];
        if (str_starts_with($host, '[')) {
            if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new HttpException("$host is not a valid address.");
            }
            $ips = [$name];
        } elseif (($v4 = self::numericIpv4($name)) !== null) {
            if ($v4 === '') {
                throw new HttpException("$host is not a valid address.");
            }
            $ips = [$v4];
        } elseif ($name === 'localhost' || str_ends_with($name, '.localhost')) {
            $ips = ['127.0.0.1']; // loopback by definition (RFC 6761), and curl treats it so without asking DNS
        } elseif ($resolve) {
            // IPv4 and IPv6 (a name with only an AAAA record for ::1 must not slip through); a name that doesn't
            // resolve fails in curl with a clear message
            $ips = gethostbynamel($name) ?: [];
            foreach (@dns_get_record($name, DNS_AAAA) ?: [] as $r) {
                if (isset($r['ipv6']) && is_string($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        foreach ($ips as $ip) {
            if (($why = self::refusedIp($ip, $local)) !== null) {
                throw new HttpException("Align won't connect to $host: it is $why.");
            }
        }
        return $host;
    }

    /**
     * Why an IP address is refused (a phrase for "it is ..."), or null when it may be used. $allowLoopback: see
     * loopbackOk(). Private ranges are allowed on purpose (see the class comment).
     */
    public static function refusedIp(string $ip, bool $allowLoopback): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'not a valid address';
        }
        $loopback = "this server (loopback). If the system really runs on this machine, add 'allow_local_integrations' => true to config.php";
        $metadata = 'a link-local or cloud metadata address';
        $bin = (string) inet_pton($ip);
        if (strlen($bin) === 16) {
            if ($bin === inet_pton('::1')) {
                return $allowLoopback ? null : $loopback;
            }
            if ($bin === inet_pton('::')) {
                return 'not a usable address';
            }
            // IPv4 inside IPv6 (mapped, NAT64, the old compatible form) is judged as the IPv4 address
            foreach (['::ffff:0:0', '64:ff9b::', '::'] as $prefix) {
                if (self::inNet($bin, $prefix, 96)) {
                    return self::refusedIp((string) inet_ntop(substr($bin, 12)), $allowLoopback);
                }
            }
            return match (true) {
                $bin === inet_pton('fd00:ec2::254'), self::inNet($bin, 'fe80::', 10) => $metadata,
                self::inNet($bin, 'ff00::', 8) => 'not a usable address',
                default => null,
            };
        }
        return match (true) {
            self::inNet($bin, '127.0.0.0', 8) => $allowLoopback ? null : $loopback,
            self::inNet($bin, '169.254.0.0', 16), $bin === inet_pton('100.100.100.200') => $metadata,
            self::inNet($bin, '0.0.0.0', 8), self::inNet($bin, '224.0.0.0', 3) => 'not a usable address', // 224/4 multicast + 240/4 reserved
            default => null,
        };
    }

    /** Whether a packed address ($bin, from inet_pton) is inside $net/$bits. Different families never match. */
    private static function inNet(string $bin, string $net, int $bits): bool
    {
        $n = (string) inet_pton($net);
        if (strlen($n) !== strlen($bin)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (strncmp($bin, $n, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        return $rest === 0 || ((ord($bin[$bytes]) ^ ord($n[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
    }

    /**
     * A host written as an IPv4 number the way curl reads it (1 to 4 parts, each decimal, 0-octal or 0x-hex, the
     * last filling the remaining bytes), as dotted quad. Null when the host isn't numeric (a name); '' when it looks
     * numeric but isn't a valid address.
     */
    private static function numericIpv4(string $host): ?string
    {
        if (!preg_match('/^(?:0x[0-9a-f]*|\d+)(?:\.(?:0x[0-9a-f]*|\d+)){0,3}$/i', $host)) {
            return null;
        }
        $parts = [];
        foreach (explode('.', $host) as $p) {
            $v = match (true) {
                stripos($p, '0x') === 0 => strlen($p) <= 10 ? (int) hexdec(substr($p, 2) ?: '0') : -1,
                strlen($p) > 1 && $p[0] === '0' => preg_match('/^[0-7]{1,12}$/', $p) ? (int) octdec($p) : -1,
                default => strlen($p) <= 10 ? (int) $p : -1,
            };
            if ($v < 0) {
                return '';
            }
            $parts[] = $v;
        }
        $last = array_pop($parts);
        $n = 0;
        foreach ($parts as $i => $v) {
            if ($v > 255) {
                return '';
            }
            $n += $v << (8 * (3 - $i));
        }
        if ($last >= 256 ** (4 - count($parts))) {
            return '';
        }
        return long2ip($n + $last);
    }

    /** Whether loopback addresses may be used: allow_local_integrations (https still required) or allow_insecure_integrations. */
    private static function loopbackOk(): bool
    {
        return (bool) Config::get('allow_local_integrations', false) || (bool) Config::get('allow_insecure_integrations', false);
    }

    /**
     * Whether curl will go through a proxy from the environment for this scheme and host (it reads the same
     * variables): a host matching no_proxy ("*", a name, or a domain with or without a leading dot) goes direct.
     */
    private static function viaProxy(string $scheme, string $host = ''): bool
    {
        $no = strtolower((string) (getenv('no_proxy') ?: getenv('NO_PROXY')));
        foreach (array_filter(array_map('trim', explode(',', $no))) as $n) {
            $n = ltrim($n, '.');
            if ($n === '*' || ($host !== '' && ($host === $n || str_ends_with($host, '.' . $n)))) {
                return false;
            }
        }
        $vars = ['all_proxy', 'ALL_PROXY', $scheme === 'https' ? 'https_proxy' : 'http_proxy'];
        if ($scheme === 'https') {
            $vars[] = 'HTTPS_PROXY';
        }
        foreach ($vars as $v) {
            if ((string) getenv($v) !== '') {
                return true;
            }
        }
        return false;
    }
}
