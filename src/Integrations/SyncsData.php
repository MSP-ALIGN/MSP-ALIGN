<?php
declare(strict_types=1);

namespace Align\Integrations;

/**
 * 2.22.0 A connector with its own data to read during the hourly sync (a security or SaaS product). SyncRunner
 * runs sync() for every registered connector that implements this and is configured(), as one step named
 * syncStepName(), after the built-in integrations. PSA, RMM and backup connectors don't need this: their
 * providers are read by the sync already.
 *
 * Contract: sync() reads the product, stores what it read and returns a one-line summary for the sync log
 * ("12 organizations, 340 results"); it throws (with a message safe to show, never a secret) when the product
 * can't be read, and must not delete stored data because of an empty or failed answer.
 */
interface SyncsData
{
    /** The sync step's name; it must start with one of the connector's syncSteps() so its status badge follows it. */
    public function syncStepName(): string;

    /** Reads the product and stores it; returns the summary line for the sync log, or throws. */
    public function sync(): string;
}
