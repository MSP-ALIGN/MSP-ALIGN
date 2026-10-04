<?php
declare(strict_types=1);

namespace Align\Api;

/**
 * The key making the current request, what it may do, and the request itself.
 * Set by Kernel; read by the resources (and by Audit::log to name the key).
 *
 * Security: Kernel resets every field at the start of each request and sets $key only after Keys::authenticate
 * accepted the token. Resources trust $key for scopes and the client limit; $body is untrusted input that must go
 * through Input::clean (or equally strict checks) before use.
 */
final class Context
{
    /** The authenticated key row from Keys::decode (with scope_list and client_list), or null before authentication. */
    public static ?array $key = null;
    public static string $requestId = '';
    /** Decoded JSON body of a POST, PATCH or PUT (untrusted). */
    public static array $body = [];

    /** True while an API request with a valid key is being handled. */
    public static function active(): bool
    {
        return self::$key !== null;
    }

    /** True when the key has this scope (exact match, e.g. "projects:write"). False without a key. */
    public static function can(string $scope): bool
    {
        return self::$key !== null && in_array($scope, self::$key['scope_list'], true);
    }

    /** Throws 403 insufficient_scope (naming the scope in X-Required-Scope) unless the key has $scope. */
    public static function require(string $scope): void
    {
        if (!self::can($scope)) {
            throw new ApiError(403, 'insufficient_scope', "This key doesn't have the $scope permission.", [], ['X-Required-Scope' => $scope]);
        }
    }

    /**
     * Client ids the key is limited to, or null for all clients.
     * Without a key the answer is "no clients", never "all of them", so code reached outside an authenticated
     * request can't widen access by mistake.
     */
    public static function clients(): ?array
    {
        if (self::$key === null) {
            return [];
        }
        return self::$key['client_list'] ?? null;
    }

    /** True when the key may see this client. A null client (internal records) is allowed only for all-clients keys. */
    public static function allowsClient(?int $clientId): bool
    {
        $c = self::clients();
        return $c === null || ($clientId !== null && in_array($clientId, $c, true));
    }

    /** Throws 404 (not 403, so a limited key can't probe which ids exist) when the key may not see this client. */
    public static function requireClient(?int $clientId, string $what = 'Resource'): void
    {
        if (!self::allowsClient($clientId)) {
            throw ApiError::notFound($what);
        }
    }

    /**
     * SQL fragment limiting a client id column to the key's clients: ['AND col IN (...)', params].
     * $col is spliced into the SQL, so it must be a column name from code, never from the request.
     * Rows with a NULL client never match for a limited key.
     */
    public static function clientSql(string $col): array
    {
        $c = self::clients();
        if ($c === null) {
            return ['', []];
        }
        if (!$c) {
            return [' AND 1 = 0', []];
        }
        return [" AND $col IN (" . implode(',', array_fill(0, count($c), '?')) . ')', $c];
    }

    /** 'API key "name" (#id)' for audit entries, or '' outside an API request. The name is admin-set text. */
    public static function label(): string
    {
        return self::$key ? 'API key "' . self::$key['name'] . '" (#' . self::$key['id'] . ')' : '';
    }
}
