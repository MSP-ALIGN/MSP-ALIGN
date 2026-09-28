<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\Providers\Backup\BackupProvider;

/** An integration that supplies the backup data area. An install can have several. */
abstract class BackupConnector extends Connector
{
    abstract public function provider(): BackupProvider;

    public function category(): string
    {
        return 'Backup';
    }

    public function areas(): array
    {
        return ['backup'];
    }

    /** Short name for sentences and columns ("Veeam"); the card name can be longer. */
    public function shortName(): string
    {
        return $this->name();
    }

    public function syncSteps(): array
    {
        return [$this->shortName()];
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
