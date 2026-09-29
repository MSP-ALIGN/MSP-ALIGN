<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\Settings;

/**
 * The configured mail provider: Microsoft 365 (Microsoft Graph), Google Workspace (Gmail + Google
 * Calendar APIs) or any SMTP server (1.37). Microsoft and Google sign in with OAuth, either unattended
 * (Entra app / Google service account with domain-wide delegation) or by an admin signing in as the
 * sending mailbox. SMTP is on or off (stored as mode 'app'), with an optional user name and password.
 */
final class Mail
{
    public const PROVIDERS = ['microsoft' => 'Microsoft 365', 'google' => 'Google Workspace', 'smtp' => 'SMTP server'];
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
        $m = isset(self::MODES[$m]) ? $m : 'off';
        return $m !== 'off' && self::provider() === 'smtp' ? 'app' : $m; // SMTP has no sign-in page: on is on
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
        return match (self::provider()) { 'google' => Google::ready(), 'smtp' => Smtp::ready(), default => Graph::ready() };
    }

    /** Whether meeting invitations can be real calendar events (not with SMTP: there's no calendar). */
    public static function hasCalendar(): bool
    {
        return self::provider() !== 'smtp';
    }

    /** @return Graph|Google|Smtp|StagingMail */
    public static function client(): object
    {
        if (!self::ready()) {
            throw new \RuntimeException('Email is not set up (Integrations → Email).');
        }
        $c = match (self::provider()) { 'google' => new Google(self::mode()), 'smtp' => new Smtp(), default => new Graph(self::mode()) };
        return \Align\Staging::on() ? new StagingMail($c) : $c; // a test server sends only to its test mailbox
    }

    public static function redirectUri(): string
    {
        return \Align\Portal\PortalAuth::baseUrl() . '/settings/email/callback';
    }

    /** The account the admin connected (delegated mode), if any. */
    public static function connectedAs(): ?string
    {
        if (self::provider() === 'smtp') {
            return null;
        }
        $v = Settings::get(self::provider() === 'google' ? 'g_connected_as' : 'm365_connected_as');
        return $v !== null && $v !== '' ? $v : null;
    }

    /** Address mail is sent from: the From setting, else the connected account. */
    public static function fromAddress(): string
    {
        return trim((string) Settings::get('mail_from')) ?: (string) self::connectedAs();
    }
}
