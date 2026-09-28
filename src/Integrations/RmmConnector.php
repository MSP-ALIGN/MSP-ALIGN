<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\Providers\Rmm\RmmProvider;

/** An integration that supplies the RMM data area (devices and organizations). An install can have several. */
abstract class RmmConnector extends Connector
{
    /** $forLinks: only building console links (no credentials needed). */
    abstract public function provider(bool $forLinks = false): RmmProvider;

    public function category(): string
    {
        return 'RMM';
    }

    public function areas(): array
    {
        return ['rmm'];
    }

    public function syncSteps(): array
    {
        return [$this->name(), 'Match clients'];
    }

    public function hasTest(): bool
    {
        return true;
    }

    public function test(): string
    {
        return $this->provider()->test();
    }
}
