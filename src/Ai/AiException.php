<?php
declare(strict_types=1);

namespace Align\Ai;

/**
 * 2.29.0 An AI request that couldn't be made or failed: not set up, turned off, over the monthly limit, or the
 * provider refused it. The message is written for people (shown in flash messages and kept in the usage table) and
 * never contains the API key. $limit is true when the monthly limit stopped it.
 */
final class AiException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $limit = false)
    {
        parent::__construct($message);
    }
}
