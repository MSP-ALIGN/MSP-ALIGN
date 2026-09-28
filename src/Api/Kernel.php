<?php
declare(strict_types=1);

namespace Align\Api;

use Align\DB;

/**
 * Handles every /api/* request: no session, no cookies, no CSRF (keys come in a header, so a browser
 * can't be tricked into sending them). JSON in and out.
 *
 * Order: API switched on -> route exists -> key valid -> rate limit -> scope -> body -> idempotency -> handler.
 */
final class Kernel
{
    public const VERSION = 1;
    private static bool $reserved = false;
    private static bool $quiet = false;      // over the failed-request limit: don't fill the request log
    public const MAX_FAILED_PER_MINUTE = 30; // requests without a valid key, per IP
    public const MAX_BODY = 1048576; // 1 MB

    public static function handle(string $method, string $path): void
    {
        $t0 = microtime(true);
        Context::$requestId = bin2hex(random_bytes(8));
        Context::$key = null;
        Context::$body = [];
        self::$reserved = false;
        self::$quiet = false;
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . Context::$requestId);
        header('Cache-Control: no-store');
        header_remove('Content-Security-Policy');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        $status = 200;
        $body = null;
        $error = null;
        try {
            if (!Keys::enabled()) {
                throw new ApiError(404, 'api_disabled', 'The API is turned off. An admin can turn it on under Settings → API.');
            }
            $rel = '/' . trim(substr($path, strlen('/api')), '/');
            if ($rel === '/') {
                [$status, $body] = [200, ['name' => \Align\Branding::name() . ' API', 'versions' => ['v1' => '/api/v1'], 'openapi' => '/api/v1/openapi.json']];
                throw new Done();
            }
            if ($rel === '/v1/openapi.json' && $method === 'GET') {
                [$status, $body] = [200, Spec::build()];
                throw new Done();
            }
            if (!str_starts_with($rel . '/', '/v1/')) {
                throw new ApiError(404, 'not_found', 'Unknown API version. Use /api/v1.');
            }
            self::authenticate();
            self::rateLimit();
            $route = self::match($method, substr($rel, 3) ?: '/');
            if ($route['scope']) {
                Context::require($route['scope']);
            }
            if (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
                Context::$body = self::readBody();
            }
            $replay = $method === 'POST' ? self::idempotencyReserve() : null;
            if ($replay) {
                header('Idempotent-Replayed: true');
                [$status, $body] = $replay;
            } else {
                [$status, $body] = ($route['handler'])(...$route['params']);
                if (self::$reserved && $status < 300) {
                    self::idempotencyStore($status, $body);
                }
            }
        } catch (Done) {
            // response already set
        } catch (ApiError $e) {
            $status = $e->status;
            $error = $e->errorCode;
            foreach ($e->headers as $h => $v) {
                header("$h: $v");
            }
            $body = ['error' => array_filter(['code' => $e->errorCode, 'message' => $e->getMessage(), 'fields' => $e->fields ?: null]), 'request_id' => Context::$requestId];
        } catch (\Throwable $e) {
            error_log('[mountaineer-align] API ' . Context::$requestId . ' ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $status = 500;
            $error = 'internal_error';
            $body = ['error' => ['code' => 'internal_error', 'message' => 'Something went wrong. The error was logged with this request id.'], 'request_id' => Context::$requestId];
        }
        if (self::$reserved && ($status >= 300 || $error !== null)) {
            self::idempotencyRelease(); // failed: the same key may be used again
        }
        http_response_code($status);
        if ($body !== null) {
            echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | (($_GET['pretty'] ?? '') === '1' ? JSON_PRETTY_PRINT : 0));
        }
        self::log($method, $path, $status, $error, (int) round((microtime(true) - $t0) * 1000));
    }

    /** @return array{handler: callable, params: array, scope: ?string} */
    private static function match(string $method, string $path): array
    {
        $allowed = [];
        foreach (Routes::all() as $r) {
            $regex = '#^' . preg_replace(['#\{(\w+):str\}#', '#\{(\w+)\}#'], ['(?P<$1>[^/]+)', '(?P<$1>[0-9]+)'], $r['path']) . '$#';
            if (!preg_match($regex, $path, $m)) {
                continue;
            }
            if ($r['method'] !== $method) {
                $allowed[] = $r['method'];
                continue;
            }
            $params = [];
            foreach (array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY) as $k => $v) {
                $params[$k] = str_contains($r['path'], '{' . $k . ':str}') ? rawurldecode($v) : (int) $v;
            }
            return ['handler' => $r['handler'], 'params' => $params, 'scope' => $r['scope']];
        }
        if ($allowed) {
            throw new ApiError(405, 'method_not_allowed', "Use " . implode(' or ', array_unique($allowed)) . ' here.', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new ApiError(404, 'not_found', 'No such endpoint. See /api/v1/openapi.json.');
    }

    private static function bearer(): string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('apache_request_headers')) {
            $h = (apache_request_headers()['Authorization'] ?? '');
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
            return $m[1];
        }
        return trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    }

    private static function authenticate(): void
    {
        $token = self::bearer();
        $sentAuth = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')) !== '';
        if ($token === '' && !$sentAuth) {
            self::failedAttempt(false);
            throw new ApiError(401, 'missing_key', 'Send your API key as "Authorization: Bearer <key>".', [], ['WWW-Authenticate' => 'Bearer realm="api"']);
        }
        [$key, $err] = $token !== '' ? Keys::authenticate($token) : [null, 'invalid_key'];
        if (!$key) {
            self::failedAttempt(true);
            $msg = ['key_revoked' => 'This API key was revoked.', 'key_expired' => 'This API key has expired.',
                'key_owner_inactive' => 'The staff account that created this API key is disabled, so the key no longer works. An admin can create a new one.'][$err] ?? 'The API key is not valid.';
            throw new ApiError(401, $err, $msg, [], ['WWW-Authenticate' => 'Bearer realm="api", error="invalid_token"']);
        }
        Context::$key = $key;
        if (!$key['last_used_at'] || strtotime($key['last_used_at']) < time() - 60 || $key['last_ip'] !== client_ip()) {
            DB::run('UPDATE api_keys SET last_used_at = NOW(), last_ip = ? WHERE id = ?', [client_ip(), $key['id']]);
        }
    }

    /**
     * Counts a request without a valid key against its IP. Past the limit: 429, and it isn't logged
     * (so a flood can't push real entries out of the request log). Bad keys are also reported for fail2ban.
     */
    private static function failedAttempt(bool $badKey): void
    {
        if ($badKey) {
            \Align\Security::logAuthFailure('api');
        }
        $window = intdiv(time(), 60);
        $ip = mb_substr(client_ip(), 0, 64);
        DB::run('INSERT INTO api_ip_rate (ip, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$ip, $window]);
        $hits = (int) DB::value('SELECT hits FROM api_ip_rate WHERE ip = ? AND window_start = ?', [$ip, $window]);
        if ($hits > self::MAX_FAILED_PER_MINUTE) {
            self::$quiet = $hits > self::MAX_FAILED_PER_MINUTE + 1; // log the first refusal, then stay quiet
            throw new ApiError(429, 'too_many_failed_requests', 'Too many requests without a valid API key from this address. Wait a minute.', [], ['Retry-After' => (string) max(1, ($window + 1) * 60 - time())]);
        }
    }

    private static function rateLimit(): void
    {
        $k = Context::$key;
        $window = intdiv(time(), 60);
        DB::run('INSERT INTO api_rate (key_id, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$k['id'], $window]);
        $hits = (int) DB::value('SELECT hits FROM api_rate WHERE key_id = ? AND window_start = ?', [$k['id'], $window]);
        $limit = (int) $k['rate_limit'];
        $reset = ($window + 1) * 60;
        header("X-RateLimit-Limit: $limit");
        header('X-RateLimit-Remaining: ' . max(0, $limit - $hits));
        header("X-RateLimit-Reset: $reset");
        if ($hits > $limit) {
            throw new ApiError(429, 'rate_limited', "Too many requests: this key allows $limit per minute.", [], ['Retry-After' => (string) max(1, $reset - time())]);
        }
    }

    private static function readBody(): array
    {
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > self::MAX_BODY) {
            throw new ApiError(413, 'body_too_large', 'Request body is larger than 1 MB.');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            throw new ApiError(413, 'body_too_large', 'Request body is larger than 1 MB.');
        }
        if (trim($raw) === '') {
            return [];
        }
        $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!preg_match('#^application/json\s*(;|$)#', $ct)) {
            throw new ApiError(415, 'unsupported_media_type', 'Send the body as JSON with "Content-Type: application/json".');
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiError(400, 'invalid_json', 'The body is not valid JSON: ' . $e->getMessage() . '.');
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new ApiError(400, 'invalid_json', 'The body must be a JSON object.');
        }
        return $data;
    }

    private static function idemKey(): ?string
    {
        $k = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if ($k === '') {
            return null;
        }
        if (strlen($k) > 100 || !preg_match('/^[\x21-\x7e]+$/', $k)) {
            throw new ApiError(400, 'invalid_idempotency_key', 'Idempotency-Key must be 1-100 visible ASCII characters.');
        }
        return $k;
    }

    private static function requestHash(): string
    {
        return hash('sha256', ($_SERVER['REQUEST_URI'] ?? '') . "\n" . json_encode(Context::$body));
    }

    /**
     * Claims the Idempotency-Key before the handler runs, so two concurrent retries can't both create.
     * Returns the stored response to replay, or null to go ahead.
     */
    private static function idempotencyReserve(): ?array
    {
        $k = self::idemKey();
        if ($k === null) {
            return null;
        }
        $keyId = Context::$key['id'];
        DB::run('DELETE FROM api_idempotency WHERE key_id = ? AND idem_key = ? AND created_at <= ?', [$keyId, $k, date('Y-m-d H:i:s', time() - 86400)]);
        $claimed = DB::run("INSERT IGNORE INTO api_idempotency (key_id, idem_key, request_hash, status, body) VALUES (?, ?, ?, 0, '')", [$keyId, $k, self::requestHash()])->rowCount() > 0;
        if ($claimed) {
            self::$reserved = true;
            return null;
        }
        $row = DB::one('SELECT * FROM api_idempotency WHERE key_id = ? AND idem_key = ?', [$keyId, $k]);
        if (!$row || !hash_equals($row['request_hash'], self::requestHash())) {
            throw new ApiError(409, 'idempotency_conflict', 'This Idempotency-Key was already used for a different request.');
        }
        if ((int) $row['status'] === 0) {
            throw new ApiError(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed. Retry in a moment.', [], ['Retry-After' => '2']);
        }
        $body = json_decode($row['body'], true);
        // The key may have lost access to that client since the first request (null = internal: all-clients keys only)
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (array_key_exists('client_id', $data) && !($data['client_id'] === null ? Context::clients() === null : Context::allowsClient((int) $data['client_id']))) {
            throw ApiError::notFound();
        }
        return [(int) $row['status'], $body];
    }

    private static function idempotencyStore(int $status, ?array $body): void
    {
        DB::run('UPDATE api_idempotency SET status = ?, body = ? WHERE key_id = ? AND idem_key = ?', [$status, json_encode($body), Context::$key['id'], self::idemKey()]);
    }

    private static function idempotencyRelease(): void
    {
        try {
            DB::run('DELETE FROM api_idempotency WHERE key_id = ? AND idem_key = ? AND status = 0', [Context::$key['id'], self::idemKey()]);
        } catch (\Throwable) {
        }
    }

    private static function log(string $method, string $path, int $status, ?string $error, int $ms): void
    {
        if (self::$quiet) {
            return;
        }
        try {
            DB::run('INSERT INTO api_requests (key_id, request_id, method, path, status, ms, ip, error_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                Context::$key['id'] ?? null, Context::$requestId, mb_substr($method, 0, 8), mb_substr($path, 0, 255), $status, $ms, client_ip(), $error,
            ]);
            if (random_int(1, 200) === 1) {
                DB::run('DELETE FROM api_requests WHERE created_at < ?', [date('Y-m-d H:i:s', strtotime('-30 days'))]);
                DB::run('DELETE FROM api_rate WHERE window_start < ?', [intdiv(time(), 60) - 10]);
                DB::run('DELETE FROM api_ip_rate WHERE window_start < ?', [intdiv(time(), 60) - 10]);
                DB::run('DELETE FROM api_idempotency WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
            }
        } catch (\Throwable) {
            // logging must never break the response
        }
    }
}

/** @internal Short-circuits the kernel once a response is ready. */
final class Done extends \Exception
{
}
