<?php
declare(strict_types=1);

namespace Align\Providers;

use Align\Integrations\PsaConnector;
use Align\Integrations\Registry;
use Align\Providers\Psa\PsaProvider;
use Align\Settings;

/**
 * Which provider fills each data area. An install has exactly one PSA (the source of truth for
 * clients): the one named in the psa_provider setting, or else the only PSA connector set up.
 */
final class Providers
{
    /** @return array<string, PsaConnector> */
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

    /** The PSA provider, ready to call. Throws when no PSA is set up. */
    public static function psa(bool $interactive = false): PsaProvider
    {
        $c = self::psaConnector();
        if (!$c || !$c->configured()) {
            throw new \RuntimeException('No PSA is connected (Integrations).');
        }
        return $c->provider($interactive);
    }

    /** The PSA's display name ("ITFlow"), or "PSA" when none is chosen yet. */
    public static function psaName(): string
    {
        return self::psaConnector()?->name() ?? 'PSA';
    }

    public static function psaKey(): ?string
    {
        return self::psaConnector()?->key();
    }

    /** Whether the PSA in use can do something (see PsaProvider::CAPABILITIES); false when there's none. */
    public static function psaSupports(string $capability): bool
    {
        $c = self::psaConnector();
        return $c && $c->configured() && in_array($capability, $c->capabilities(), true);
    }

    /** A link into the PSA's own screens, or null. $kind: client, asset, ticket. */
    public static function psaLink(string $kind, int ...$ids): ?string
    {
        static $p = false;
        try {
            if ($p === false) {
                $p = self::psaConfigured() ? self::psa(true) : null;
            }
            if (!$p || !($kind === 'asset' ? ($ids[1] ?? 0) : ($ids[0] ?? 0))) {
                return null;
            }
            return match ($kind) {
                'client' => $p->clientUrl($ids[0]),
                'asset' => $p->assetUrl($ids[0], $ids[1] ?? 0),
                'ticket' => $p->ticketUrl($ids[0]),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }
}
