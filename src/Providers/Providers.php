<?php
declare(strict_types=1);

namespace Align\Providers;

use Align\Integrations\BackupConnector;
use Align\Integrations\LinksClients;
use Align\Integrations\PsaConnector;
use Align\Integrations\Registry;
use Align\Integrations\RmmConnector;
use Align\Providers\Backup\BackupProvider;
use Align\Providers\Psa\PsaProvider;
use Align\Providers\Rmm\RmmProvider;
use Align\Settings;

/**
 * Which provider fills each data area. An install has exactly one PSA (the source of truth for
 * clients): the one named in the psa_provider setting, or else the only PSA connector set up. It can
 * have any number of RMMs; each device records the RMM it came from (devices.rmm_provider). It can
 * also have any number of backup products; every stored backup record notes its provider.
 *
 * SECURITY: psa() is the only way to get a PSA provider. On a test server it always returns the provider
 * wrapped in StagingPsa, so nothing is ever written to the PSA there; psaSupports() also reports every
 * writing capability as off there, so screens hide those actions. None of these methods check the user's
 * role: callers do. Links built here point at the admin's saved PSA/RMM address and are null on any error.
 */
final class Providers
{
    /** Every PSA connector Align has, set up or not. @return array<string, PsaConnector> */
    public static function psaConnectors(): array
    {
        return array_filter(Registry::all(), fn($c) => $c instanceof PsaConnector);
    }

    /** The PSA connector in use (set up or not), or null when the install has none. */
    public static function psaConnector(): ?PsaConnector
    {
        $all = self::psaConnectors();
        $key = (string) Settings::get('psa_provider', '');
        if ($key !== '' && isset($all[$key])) {
            return $all[$key];
        }
        foreach ($all as $c) {
            if ($c->configured()) {
                return $c;
            }
        }
        return null;
    }

    /** True when a PSA is connected (its connection settings are filled in). */
    public static function psaConfigured(): bool
    {
        return (bool) self::psaConnector()?->configured();
    }

    /**
     * The PSA provider, ready to call. Throws when no PSA is set up. $interactive: a person is waiting (short
     * timeouts). Callers that write must also check psaSupports() for the capability and the user's role.
     */
    public static function psa(bool $interactive = false): PsaProvider
    {
        $c = self::psaConnector();
        if (!$c || !$c->configured()) {
            throw new \RuntimeException('No PSA is connected (Integrations).');
        }
        $p = $c->provider($interactive);
        return \Align\Staging::on() ? new \Align\Providers\Psa\StagingPsa($p) : $p; // a test server reads but never writes
    }

    /** The PSA's display name ("ITFlow"), or "PSA" when none is chosen yet. */
    public static function psaName(): string
    {
        return self::psaConnector()?->name() ?? 'PSA';
    }

    /** The PSA connector's key ("itflow"), or null when there's none. */
    public static function psaKey(): ?string
    {
        return self::psaConnector()?->key();
    }

    /** Whether the PSA in use can do something (see PsaProvider::CAPABILITIES); false when there's none. */
    public static function psaSupports(string $capability): bool
    {
        $c = self::psaConnector();
        return $c && $c->configured() && in_array($capability, $c->capabilities(), true) && !\Align\Staging::blocks($capability);
    }

    /**
     * A link into the PSA's own screens, or null. $kind: client, asset (client id, asset id), ticket. Ids as stored (text).
     * The provider checks the ids (a non-PSA id gives null). Escape the link where it's shown.
     */
    public static function psaLink(string $kind, int|string|null ...$ids): ?string
    {
        static $p = false; // built once per request: a page can show hundreds of links
        try {
            if ($p === false) {
                $p = self::psaConfigured() ? self::psa(true) : null;
            }
            $ids = array_map(fn($i) => (string) ($i ?? ''), $ids);
            if (!$p || ($kind === 'asset' ? ($ids[1] ?? '') : ($ids[0] ?? '')) === '') {
                return null;
            }
            return match ($kind) {
                'client' => $p->clientUrl($ids[0]),
                'asset' => $p->assetUrl($ids[0], $ids[1]),
                'ticket' => $p->ticketUrl($ids[0]),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    // ---- Client links ----

    /** Connectors whose records are linked to clients (Client mapping columns), RMMs first. @return array<string, LinksClients> */
    public static function linkConnectors(): array
    {
        $all = array_filter(Registry::all(), fn($c) => $c instanceof LinksClients);
        uasort($all, fn($a, $b) => ($a instanceof RmmConnector ? 0 : 1) <=> ($b instanceof RmmConnector ? 0 : 1));
        return $all;
    }

    // ---- RMM ----

    /** Every RMM connector Align has, set up or not. @return array<string, RmmConnector> */
    public static function rmmConnectors(): array
    {
        return array_filter(Registry::all(), fn($c) => $c instanceof RmmConnector);
    }

    /** RMM connectors that are set up. @return array<string, RmmConnector> */
    public static function rmmConfigured(): array
    {
        return array_filter(self::rmmConnectors(), fn(RmmConnector $c) => $c->configured());
    }

    /** Whether any RMM is set up. */
    public static function anyRmm(): bool
    {
        return (bool) self::rmmConfigured();
    }

    /** An RMM provider by key, ready to call. Throws when it isn't set up. */
    public static function rmm(string $key): RmmProvider
    {
        $c = self::rmmConnectors()[$key] ?? null;
        if (!$c || !$c->configured()) {
            throw new \RuntimeException(($c ? $c->name() : "The RMM \"$key\"") . ' is not configured (Integrations).');
        }
        return $c->provider();
    }

    /** An RMM's display name ("NinjaOne"); "RMM" for an unknown key. */
    public static function rmmName(?string $key): string
    {
        return $key !== null && isset(self::rmmConnectors()[$key]) ? self::rmmConnectors()[$key]->name() : 'RMM';
    }

    /** An RMM's icon classes ("fas fa-user-ninja"). */
    public static function rmmIcon(?string $key): string
    {
        return $key !== null && isset(self::rmmConnectors()[$key]) ? self::rmmConnectors()[$key]->icon() : 'fas fa-satellite-dish';
    }

    /** Names of the RMMs in use, for sentences: "NinjaOne", "NinjaOne or Datto RMM"; the only RMM Align supports when none is set up; else "your RMM". */
    public static function rmmNames(string $join = ' or '): string
    {
        $n = array_map(fn($c) => $c->name(), array_values(self::rmmConfigured()));
        if (!$n && count(self::rmmConnectors()) === 1) {
            $n = [array_values(self::rmmConnectors())[0]->name()]; // the only RMM Align supports, even before it's set up
        }
        return $n ? implode($join, $n) : 'your RMM';
    }

    /** Link to a device in its RMM's console, or null (no credentials needed; built from the saved instance). */
    public static function rmmDeviceLink(?string $provider, ?string $deviceId): ?string
    {
        $c = $provider !== null && $deviceId !== null && $deviceId !== '' ? (self::rmmConnectors()[$provider] ?? null) : null;
        if (!$c) {
            return null;
        }
        try {
            return $c->provider(true)->deviceUrl($deviceId);
        } catch (\Throwable) {
            return null;
        }
    }

    // ---- Backup ----

    /** Every backup connector Align has, set up or not. @return array<string, BackupConnector> */
    public static function backupConnectors(): array
    {
        return array_filter(Registry::all(), fn($c) => $c instanceof BackupConnector);
    }

    /** Backup connectors that are set up. @return array<string, BackupConnector> */
    public static function backupConfigured(): array
    {
        return array_filter(self::backupConnectors(), fn(BackupConnector $c) => $c->configured());
    }

    /** Whether any backup product is set up. */
    public static function anyBackup(): bool
    {
        return (bool) self::backupConfigured();
    }

    /** A backup provider by key, ready to call. Throws when it isn't set up. */
    public static function backup(string $key): BackupProvider
    {
        $c = self::backupConnectors()[$key] ?? null;
        if (!$c || !$c->configured()) {
            throw new \RuntimeException(($c ? $c->name() : "The backup provider \"$key\"") . ' is not configured (Integrations).');
        }
        return $c->provider();
    }

    /** A backup provider's short name ("Veeam"); "Backup" for an unknown key. */
    public static function backupName(?string $key): string
    {
        return $key !== null && isset(self::backupConnectors()[$key]) ? self::backupConnectors()[$key]->shortName() : 'Backup';
    }

    /** A backup provider's full name ("Veeam Service Provider Console"); "Backup" for an unknown key. */
    public static function backupFullName(?string $key): string
    {
        return $key !== null && isset(self::backupConnectors()[$key]) ? self::backupConnectors()[$key]->name() : 'Backup';
    }

    /** Names of the backup products in use, for sentences; the only one Align supports when none is set up; else "your backup product". */
    public static function backupNames(string $join = ' or '): string
    {
        $n = array_map(fn($c) => $c->shortName(), array_values(self::backupConfigured()));
        if (!$n && count(self::backupConnectors()) === 1) {
            $n = [array_values(self::backupConnectors())[0]->shortName()];
        }
        return $n ? implode($join, $n) : 'your backup product';
    }
}
