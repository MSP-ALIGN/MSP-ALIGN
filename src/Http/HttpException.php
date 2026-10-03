<?php
declare(strict_types=1);

namespace Align\Http;

/**
 * A refused or failed outside request (see HttpClient). The message is safe to show an admin: it names the host,
 * never the path or query. $body is the start of the remote response (up to 1000 characters): untrusted text that
 * may echo what was sent, so callers show at most a short, checked piece of it and never log it whole.
 */
final class HttpException extends \RuntimeException
{
    /** $status: the HTTP status (0 when no response came back). */
    public function __construct(string $message, public readonly int $status = 0, public readonly string $body = '')
    {
        parent::__construct($message, $status);
    }
}
