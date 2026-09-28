<?php
declare(strict_types=1);

namespace Align\Api;

/**
 * The key making the current request, what it may do, and the request itself.
 * Set by Kernel; read by the resources (and by Audit::log to name the key).
 */
final class Context
{
    public static ?array $key = null;
    public static string $requestId = '';
    public static array $body = [];

    public static function active(): bool
    {
        return self::$key !== null;
    }

    public static function can(string $scope): bool
    {
        return self::$key !== null && in_array($scope, self::$key['scope_list'], true);
    }

    public static function require(string $scope): void
    {
        if (!self::can($scope)) {
            throw new ApiError(403, 'insufficient_scope', "This key doesn't have the $scope permission.", [], ['X-Required-Scope' => $scope]);
        }
    }

    /** Client ids the key is limited to, or null for all clients. */
    public static function clients(): ?array
    {
        return self::$key['client_list'] ?? null;
    }

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

    /** SQL fragment limiting a client id column to the key's clients: ['AND col IN (...)', params]. */
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

    public static function label(): string
    {
        return self::$key ? 'API key "' . self::$key['name'] . '" (#' . self::$key['id'] . ')' : '';
    }
}
