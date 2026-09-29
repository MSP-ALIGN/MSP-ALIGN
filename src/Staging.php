<?php
declare(strict_types=1);

namespace Align;

/**
 * Staging mode, for a test server running on a copy of production data. Set in config.php (so it can't be
 * turned off from the web pages):
 *
 *   'staging' => true,
 *   'staging_mail_to' => 'align-test@example.com',   // every email goes here instead (none: email isn't sent)
 *
 * Then nothing leaves the server that could reach a real client or change a connected system:
 * - reads from the PSA, RMMs and backup products still work; writes to the PSA are blocked
 *   (StagingPsa), so two-way sync, asset creation, warranty write-back, contact push and tickets are off
 * - every email and meeting invitation goes to staging_mail_to only, marked [TEST] (StagingMail);
 *   calendar events from the copied data (the real ones) are never changed or cancelled
 * - the client portal (and its onboarding pages) and the REST API are off
 * - every page shows a "Test server" banner
 */
final class Staging
{
    public static function on(): bool
    {
        return (bool) Config::get('staging', false);
    }

    /** Where all email goes on a test server, or null (then email isn't sent at all). */
    public static function mailTo(): ?string
    {
        $m = trim((string) Config::get('staging_mail_to', ''));
        return $m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL) ? $m : null;
    }

    /** Every PSA capability that changes data in the PSA. A new writing capability must be added here (StagingPsa refuses the call too). */
    public const WRITES = ['contacts.write', 'contacts.create', 'assets.write', 'assets.create', 'tickets.create'];

    /** PSA capabilities that change data are off on a test server. */
    public static function blocks(string $capability): bool
    {
        return self::on() && (in_array($capability, self::WRITES, true) || str_ends_with($capability, '.write') || str_ends_with($capability, '.create'));
    }
}
