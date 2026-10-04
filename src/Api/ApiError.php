<?php
declare(strict_types=1);

namespace Align\Api;

/**
 * An API error: HTTP status, a stable machine-readable code, a message and optional per-field problems.
 *
 * Security: Kernel sends the message, fields and headers to the caller as they are, so they must never hold
 * internal details (SQL, paths, stack traces) or anything from another client. Header names and values come from
 * code, never from the request, so they can't inject headers.
 */
final class ApiError extends \RuntimeException
{
    /**
     * @param array<string, string> $fields  field => problem, shown as error.fields
     * @param array<string, string> $headers extra response headers (Retry-After, Allow, X-Required-Scope ...)
     */
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message,
        public readonly array $fields = [], public readonly array $headers = [])
    {
        parent::__construct($message);
    }

    /** 404 not_found. Also used for records outside a key's clients, so a limited key can't tell them apart. */
    public static function notFound(string $what = 'Resource'): self
    {
        return new self(404, 'not_found', "$what not found.");
    }

    /** 422 validation_failed with a problem per field. */
    public static function invalid(array $fields, string $message = 'Some fields are not valid.'): self
    {
        return new self(422, 'validation_failed', $message, $fields);
    }
}
