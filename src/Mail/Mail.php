<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\Settings;

/**
 * The configured mail provider: Microsoft 365 (Microsoft Graph) or Google Workspace (Gmail +
 * Google Calendar APIs). Both sign in with OAuth, either unattended (Entra app / Google service
 * account with domain-wide delegation) or by an admin signing in as the sending mailbox.
 */
final class Mail
{
    public const PROVIDERS = ['microsoft' => 'Microsoft 365', 'google' => 'Google Workspace'];
    public const MODES = ['off' => 'Off', 'app' => 'Unattended (app)', 'delegated' => 'Sign in as a mailbox'];

    public static function provider(): string
    {
        $p = (string) Settings::get('mail_provider', 'microsoft');
        return isset(self::PROVIDERS[$p]) ? $p : 'microsoft';
    }

    public static function providerName(): string
    {
        return self::PROVIDERS[self::provider()];
    }

    public static function mode(): string
    {
        $m = (string) Settings::get('mail_mode', 'off');
        return isset(self::MODES[$m]) ? $m : 'off';
    }

    public static function on(): bool
    {
        return self::mode() !== 'off';
    }

    /** Mode chosen and credentials present, so mail can actually be sent. */
    public static function ready(): bool
    {
        if (!self::on()) {
            return false;
        }
        return self::provider() === 'google' ? Google::ready() : Graph::ready();
    }

    /** @return Graph|Google */
    public static function client(): object
    {
        if (!self::ready()) {
            throw new \RuntimeException('Email is not set up (Integrations → Microsoft 365 / Google Workspace).');
        }
        return self::provider() === 'google' ? new Google(self::mode()) : new Graph(self::mode());
    }

    public static function redirectUri(): string
    {
        return \Align\Portal\PortalAuth::baseUrl() . '/settings/email/callback';
    }

    /** The account the admin connected (delegated mode), if any. */
    public static function connectedAs(): ?string
    {
        $v = Settings::get(self::provider() === 'google' ? 'g_connected_as' : 'm365_connected_as');
        return $v !== null && $v !== '' ? $v : null;
    }

    /** Address mail is sent from: the From setting, else the connected account. */
    public static function fromAddress(): string
    {
        return trim((string) Settings::get('mail_from')) ?: (string) self::connectedAs();
    }
}
