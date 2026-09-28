<?php
declare(strict_types=1);

namespace Align\Api;

/** An API error: HTTP status, a stable machine-readable code, a message and optional per-field problems. */
final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message,
        public readonly array $fields = [], public readonly array $headers = [])
    {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self(404, 'not_found', "$what not found.");
    }

    public static function invalid(array $fields, string $message = 'Some fields are not valid.'): self
    {
        return new self(422, 'validation_failed', $message, $fields);
    }
}
