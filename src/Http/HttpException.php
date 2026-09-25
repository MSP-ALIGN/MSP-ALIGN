<?php
declare(strict_types=1);

namespace Align\Http;

final class HttpException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $body = '')
    {
        parent::__construct($message, $status);
    }
}
